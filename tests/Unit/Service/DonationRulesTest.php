<?php

namespace Civi\Mascode\Test\Unit\Service;

use Civi\Mascode\Service\DonationLinker;
use Civi\Mascode\Service\DonationNotifier;
use Civi\Mascode\Service\DonationReport;
use Civi\Mascode\Test\TestCase;

/**
 * The decision rules of the donations work, as pure functions
 * (docs/plans/donations-tickets.md DN-2/3/4). Each test names the input that
 * trips it:
 *
 *  - vcRecipients: a PRIVATE donation with a project and VC must not notify the
 *    VC (it would put a private donor's name on the case, which the VC Portal
 *    lists). Found by the adversarial review of PR #76.
 *  - caseIdFor: only the VC notice is filed on a case. Filing the ED or
 *    Treasurer notice there would show the amount in the VC Portal.
 *  - showsAmount: every amount placeholder shape, and the core token.
 *  - pickCoordinator: a completed project credits the coordinator who ended
 *    LAST, not the one who started last.
 *  - summarise: the rolling window is exactly the four quarters ending at the
 *    row, and starts at the fourth row.
 *
 * @coversNothing
 */
class DonationRulesTest extends TestCase
{
    private const P = DonationLinker::FIELD_PROJECT;
    private const V = DonationLinker::FIELD_VC;

    public function testVcRecipients(): void
    {
        // R10: one cheque, projects 10 and 11 of this donor; VC 7 led 10, VC 8 led 11.
        $client = ['financial_type_id:name' => 'Client Donation', 'contact_id.contact_type' => 'Organization',
            'vc_ids' => [7, 8], 'client_projects' => [10, 11], 'coordinators' => [10 => [7], 11 => [8, 9]]];
        $this->assertSame([7, 8], DonationNotifier::vcRecipients($client), 'each VC gets their own notice');
        // Keeps the Organization donor, so only the TYPE check can stop it.
        $this->assertSame([], DonationNotifier::vcRecipients(['financial_type_id:name' => 'Private Donation'] + $client), 'private donation');
        $this->assertSame([7, 8], DonationNotifier::vcRecipients(['financial_type_id:name' => 'Donation'] + $client), 'legacy type, organization donor');
        $this->assertSame([], DonationNotifier::vcRecipients(['financial_type_id:name' => 'Donation', 'contact_id.contact_type' => 'Individual'] + $client), 'legacy type, individual donor');
        $this->assertSame([], DonationNotifier::vcRecipients(['contact_id.contact_type' => 'Individual'] + $client), 'individual mis-typed as Client Donation');
        $this->assertSame([], DonationNotifier::vcRecipients(['vc_ids' => []] + $client), 'no VC');
        $this->assertSame([], DonationNotifier::vcRecipients(['client_projects' => []] + $client), 'no believable project');
        // Round 7 of PR #76: since R2 the email has the amount, so a mistaken link must not send it.
        $this->assertSame([7], DonationNotifier::vcRecipients(['vc_ids' => [7, 12]] + $client), 'VC 12 coordinates neither project');
        $this->assertSame([7], DonationNotifier::vcRecipients(['client_projects' => [10]] + $client), "VC 8's project is not this donor's");

        // Which linked projects count: live Projects of this donor only, in link order.
        $projects = [11 => ['live_project' => true], 10 => ['live_project' => true], 12 => ['live_project' => false], 13 => ['live_project' => true]];
        $this->assertSame([11, 10], DonationNotifier::clientProjects($projects, [10, 11, 12]),
            '12 is trashed or not a Project; 13 belongs to another client');
    }

    public function testOnlyTheVcNoticeIsFiledOnTheCase(): void
    {
        $d = ['client_projects' => [10, 11], 'coordinators' => [10 => [7], 11 => [8, 7]]];
        $this->assertSame(10, DonationNotifier::caseIdFor(DonationNotifier::TEMPLATE_VC, $d, 7), "the first of the VC's projects");
        $this->assertSame(11, DonationNotifier::caseIdFor(DonationNotifier::TEMPLATE_VC, $d, 8));
        $this->assertSame([10, 11], DonationNotifier::vcProjects($d, 7));
        $this->assertNull(DonationNotifier::caseIdFor(DonationNotifier::TEMPLATE_VC, $d, 9), 'not a coordinator');
        $this->assertNull(DonationNotifier::caseIdFor(DonationNotifier::TEMPLATE_VC, $d), 'no VC');
        $this->assertNull(DonationNotifier::caseIdFor(DonationNotifier::TEMPLATE_ED, $d, 7));
        $this->assertNull(DonationNotifier::caseIdFor(DonationNotifier::TEMPLATE_TREASURER, $d, 7));
    }

    /** R10: ids from every shape a serialized field or a map cell arrives in. */
    public function testIds(): void
    {
        $this->assertSame([12, 34], DonationLinker::ids([12, '34', 12]));
        $this->assertSame([12, 34], DonationLinker::ids("\x0112\x0134\x01"), 'value-separated, as stored');
        $this->assertSame([12, 34], DonationLinker::ids('12,34'), 'the form posts commas');
        $this->assertSame([12, 34], DonationLinker::ids(' 12 ; 34 '), 'R4 map cells');
        $this->assertSame([7], DonationLinker::ids(7));
        $this->assertSame([], DonationLinker::ids(null));
        $this->assertSame([], DonationLinker::ids(''));
        $this->assertSame([], DonationLinker::ids(true));
        $this->assertSame([5], DonationLinker::ids(['0', '-3', 'x', '5', 1.5]), 'non-ids dropped');
    }

    public function testCodeLabel(): void
    {
        $this->assertSame('P26101', DonationLinker::codeLabel(' p26101 ', 'anything', 1));
        $this->assertSame('P16148', DonationLinker::codeLabel(null, '16148 Strategic plan', 1), 'pre-2020 subject');
        $this->assertSame('#9', DonationLinker::codeLabel('', 'Board retreat', 9));
    }

    /** DN-5 after R10: add the other codes only to a link upgrade_5019 wrote and nobody changed. */
    public function testBackfillWrite(): void
    {
        $this->assertSame([10, 11], DonationLinker::backfillWrite([], [10, 11]), 'unlinked: every code');
        $this->assertSame([10, 11], DonationLinker::backfillWrite([10], [10, 11]), "5019's first-code link: add the rest");
        $this->assertNull(DonationLinker::backfillWrite([11], [10, 11]), 'set by hand to another project: left alone');
        $this->assertNull(DonationLinker::backfillWrite([10, 11], [10, 11]), 'second run');
        $this->assertNull(DonationLinker::backfillWrite([10], [10]), 'single code, already linked');
        $this->assertNull(DonationLinker::backfillWrite([], []), 'no matching project');
    }

    /** R10 review: fill per project, never remove; coverage counts EVERY past coordinator. */
    public function testMissingVcs(): void
    {
        $this->assertSame([7, 8], DonationLinker::missingVcs([10 => [7], 11 => [8]], [], []), 'two single-coordinator projects');
        $this->assertSame([8], DonationLinker::missingVcs([10 => [7], 11 => [8]], [], [7]), 'P1 covered: only P2 is added');
        $this->assertSame([7], DonationLinker::missingVcs([10 => [7], 11 => [8, 9]], [], []), 'P2 has several: the CSM picks');
        $this->assertSame([], DonationLinker::missingVcs([10 => [7], 11 => [8, 9]], [], [7, 9]), 'everything covered');
        $this->assertSame([7], DonationLinker::missingVcs([10 => [7], 11 => [7]], [], []), 'one VC led both projects');
        $this->assertSame([], DonationLinker::missingVcs([10 => []], [], []), 'no coordinator');
        // Round 2: A was credited, then A's role ended and B became the current
        // coordinator. A still covers P1, so a later save must not add (and email) B.
        $this->assertSame([], DonationLinker::missingVcs([10 => [12]], [10 => [12, 7]], [7]), 'successor not added');
        $this->assertSame([12], DonationLinker::missingVcs([10 => [12]], [10 => [12, 7]], []), 'empty list still fills the current one');
        // Same pass: coverage is judged against the STORED list, so a first
        // save of P1 (sole A) and P2 (once A, now sole B) credits both.
        $this->assertSame([7, 12], DonationLinker::missingVcs([10 => [7], 11 => [12]], [10 => [7], 11 => [12, 7]], []), 'first save credits both');
    }

    /** R10 review round 3: tag merges fire the same 'sqls' hook with TAG ids and must not move VC credit. */
    public function testIsContactMerge(): void
    {
        $this->assertFalse(DonationLinker::isContactMerge(['civicrm_entity_tag', 'civicrm_tag']), 'CRM_Core_BAO_EntityTag::mergeTags()');
        $this->assertTrue(DonationLinker::isContactMerge(null), 'contact merge, all tables');
        $this->assertTrue(DonationLinker::isContactMerge([]), 'contact merge, all tables');
        $this->assertTrue(DonationLinker::isContactMerge(['civicrm_contribution', 'civicrm_entity_tag']), 'contact merge moving tags');
    }

    /** R10 review: a duplicate-VC merge moves the credit to the survivor; 7 must not match 17 or 71; no duplicates. */
    public function testMergeSql(): void
    {
        [$dropWhereBoth, $rename] = DonationLinker::mergeSql('civicrm_value_x', 'vc_col', 5, 7);
        $this->assertStringStartsWith('UPDATE `civicrm_value_x`', $dropWhereBoth);
        $this->assertStringContainsString("REPLACE(`vc_col`, '\x017\x01', '\x01')", $dropWhereBoth);
        $this->assertStringContainsString("LIKE '%\x015\x01%'", $dropWhereBoth, 'only where the survivor is already credited');
        $this->assertStringContainsString("REPLACE(`vc_col`, '\x017\x01', '\x015\x01')", $rename);
        $this->assertStringContainsString("LIKE '%\x017\x01%'", $rename);
        // The two REPLACEs, applied in order to stored values, give each id once.
        $apply = static function (string $v) {
            if (strpos($v, "\x017\x01") !== false && strpos($v, "\x015\x01") !== false) {
                $v = str_replace("\x017\x01", "\x01", $v);
            }
            return str_replace("\x017\x01", "\x015\x01", $v);
        };
        $this->assertSame("\x015\x01", $apply("\x017\x01"));
        $this->assertSame("\x015\x01", $apply("\x015\x017\x01"));
        $this->assertSame("\x015\x01", $apply("\x017\x015\x01"));
        $this->assertSame("\x0117\x0171\x01", $apply("\x0117\x0171\x01"), '17 and 71 untouched');
    }

    /** R10: a cheque's net is split evenly across its projects, and the total is preserved. */
    public function testSplitEvenly(): void
    {
        $net = \Civi\Mascode\Service\DonationReport::splitEvenly([
            ['net_amount' => '1500', self::P => [10, 11]],
            ['net_amount' => '100', self::P => [10]],
            ['net_amount' => '90', self::P => [12, 13, 14]],
            ['net_amount' => '50', self::P => []],
        ]);
        $this->assertEqualsWithDelta(850.0, $net[10], 0.001);
        $this->assertEqualsWithDelta(750.0, $net[11], 0.001);
        $this->assertEqualsWithDelta(30.0, $net[14], 0.001);
        $this->assertEqualsWithDelta(1690.0, array_sum($net), 0.001, 'unlinked money is not counted, linked money is not lost');
    }

    public function testShowsAmount(): void
    {
        foreach (['%%mas_donation.amount%%', '%%mas_donation.fee%%', '%%mas_donation.net%%', '{contribution.total_amount}', '{contribution.net_amount}'] as $t) {
            $this->assertTrue(DonationNotifier::showsAmount("x $t y"), $t);
        }
        foreach (['%%mas_donation.donor%%', '%%mas_donation.project%%', '{contact.first_name}'] as $t) {
            $this->assertFalse(DonationNotifier::showsAmount($t), $t);
        }
    }

    /**
     * The VC template allowlist. Trips on: Source or reference (which hold
     * amounts and names) slipping through, or the allowlist rejecting a
     * placeholder the shipped template needs.
     */
    public function testVcTemplateViolations(): void
    {
        $this->assertSame([], DonationNotifier::vcTemplateViolations('%%mas_donation.donor%% %%mas_donation.project%% %%mas_donation.project_code%% {contact.first_name}'));
        // R2: the body may show the amount and the split; the subject may not.
        $this->assertSame([], DonationNotifier::vcTemplateViolations('%%mas_donation.amount%%%%mas_donation.split%%'));
        $subject = DonationNotifier::VC_SUBJECT_SAFE_PLACEHOLDERS;
        $this->assertSame(['%%mas_donation.amount%%'], DonationNotifier::vcTemplateViolations('Gift of %%mas_donation.amount%%', $subject));
        $this->assertSame(['%%mas_donation.split%%'], DonationNotifier::vcTemplateViolations('Gift%%mas_donation.split%%', $subject));
        $this->assertContains('%%mas_donation.amount%%', DonationNotifier::vcTemplateViolations('%%mas_donation.donor%%mas_donation.amount%%', $subject), 'adjacent, subject');
        $this->assertNotSame([], DonationNotifier::vcTemplateViolations('%%mas_donation.{contact.x|default:"amount"}%%', $subject), 'token-built, subject');
        foreach (['%%mas_donation.fee%%', '%%mas_donation.net%%', '%%mas_donation.source%%', '%%mas_donation.reference%%', '%%mas_donation.link%%', '{contribution.total_amount}', '{contribution.source}'] as $t) {
            $this->assertSame([$t], DonationNotifier::vcTemplateViolations("Hi $t."), $t);
        }
        // Unknown names are refused too (they would never fill, but a refusal is clearer).
        $this->assertNotSame([], DonationNotifier::vcTemplateViolations('%%mas_donation.AMOUNT%%'));
        // Round-3 bypass: adjacent placeholders share a %%. A raw-text regex missed this.
        $this->assertContains('%%mas_donation.fee%%', DonationNotifier::vcTemplateViolations('%%mas_donation.donor%%mas_donation.fee%%'));
        $this->assertContains('%%mas_donation.net%%', DonationNotifier::vcTemplateViolations('%%mas_donation.%%mas_donation.net%%'));
        // Round-4 bypass: the name is completed by a core token at render time.
        foreach (['Gift %%mas_donation.{contact.is_deleted|default:"fee"}%%',
                  '<p>%%mas_donation.{contact.is_deleted|default:&quot;source&quot;}%%</p>',
                  '<p>%%mas_donation.fee{contact.nick_name}%%</p>'] as $tpl) {
            $this->assertNotSame([], DonationNotifier::vcTemplateViolations($tpl), $tpl);
        }
        // A literal sentinel byte is refused outright.
        $this->assertContains('control character U+001E', DonationNotifier::vcTemplateViolations("x \x1Emas_donation.amount%% y"));
    }

    /**
     * No notice's activity keeps the amount or the donor. Trips on: the email
     * HTML being stored on an activity again, which for the VC notice (filed
     * on the Project case, amount in the body since R2) reaches the Portal.
     */
    public function testActivitiesKeepOnlyAPointer(): void
    {
        $d = ['id' => 42];
        $html = '<p>Donor: Jane Private. Amount: $500.00</p>';
        foreach ([DonationNotifier::TEMPLATE_VC, DonationNotifier::TEMPLATE_ED, DonationNotifier::TEMPLATE_TREASURER] as $t) {
            $this->assertStringNotContainsString('500', DonationNotifier::activityBody($t, $html, $d), $t);
            $this->assertStringContainsString('#42', DonationNotifier::activityBody($t, $html, $d), $t);
        }
        foreach ([DonationNotifier::TEMPLATE_ED, DonationNotifier::TEMPLATE_TREASURER] as $t) {
            $body = DonationNotifier::activityBody($t, $html, $d);
            $subject = DonationNotifier::activitySubject($t, 'Donation received: Jane Private', $d);
            foreach ([$body, $subject] as $text) {
                $this->assertStringNotContainsString('500', $text, $t);
                $this->assertStringNotContainsString('Jane', $text, $t);
                $this->assertStringContainsString('#42', $text, $t);
            }
        }
        $this->assertSame('VC subject', DonationNotifier::activitySubject(DonationNotifier::TEMPLATE_VC, 'VC subject', $d));
    }

    /**
     * fill() is the structural guarantee: with the VC allowlist, an unsafe
     * value is not even AVAILABLE. Simulates what core's token pass produced
     * in the round-4 attack ({contact.x|default:"amount"} → "amount"), i.e. a
     * sentinel followed by "amount%%" that no template check could see.
     * Trips on: fill() ignoring $onlyKeys, or not restoring leftovers.
     */
    public function testFillOnlyUsesAllowedValues(): void
    {
        $s = "\x1Eabc123.";
        $values = ['donor' => 'Org & Co', 'amount' => '$500.00', 'fee' => '$18.75', 'project' => 'P99001: Plan'];
        $out = DonationNotifier::fill("Hi {$s}donor%% - {$s}fee%% - {$s}project%%", $values, $s, true, DonationNotifier::VC_SAFE_PLACEHOLDERS);
        $this->assertStringNotContainsString('18.75', $out);
        $this->assertStringContainsString('Org &amp; Co', $out, 'safe values fill, escaped for html');
        $this->assertStringContainsString('%%mas_donation.fee%%', $out, 'unfilled goes back to visible text');
        $this->assertStringNotContainsString("\x1E", $out);
        // R2: the subject's allowlist has no amount, whatever the token pass assembled.
        $subject = DonationNotifier::fill("Gift {$s}amount%% for {$s}project%%", $values, $s, false, DonationNotifier::VC_SUBJECT_SAFE_PLACEHOLDERS);
        $this->assertStringNotContainsString('500', $subject);
        $this->assertStringContainsString('$500.00', DonationNotifier::fill("{$s}amount%%", $values, $s, true, DonationNotifier::VC_SAFE_PLACEHOLDERS), 'R2: the body fills the amount');
        // Without a restriction (ED/Treasurer) everything fills.
        $this->assertStringContainsString('$500.00', DonationNotifier::fill("{$s}amount%%", $values, $s, false));
    }

    /**
     * R2: a split gift names the whole gift and the number of parts. Trips
     * on: a note for an unsplit gift, or the whole-gift total missing.
     */
    public function testSplitNote(): void
    {
        $money = static fn(float $v) => '$' . number_format($v, 2);
        $this->assertSame('', DonationNotifier::splitNote(500.0, 1, $money));
        $note = DonationNotifier::splitNote(800.0, 2, $money);
        $this->assertStringContainsString('$800.00', $note);
        $this->assertStringContainsString('2 contributions', $note);
        $this->assertSame(' (one gift covering 2 projects)', DonationNotifier::splitNote(1500.0, 1, $money, 2), 'R10: one cheque, two projects');
        $this->assertSame('', DonationNotifier::splitNote(1500.0, 1, $money, 1));
    }

    /**
     * R2: only a real cheque number groups a split gift. Trips on: a shared
     * reference like "EFT" or "0", or a non-Check payment, telling one
     * project's VC about another project's gift (round 6 of PR #76).
     */
    public function testIsChequeNumber(): void
    {
        $this->assertTrue(DonationNotifier::isChequeNumber('1234', 'Check'));
        foreach ([['EFT', 'Check'], ['0', 'Check'], ['12', 'Check'], ['', 'Check'], ['1234', 'EFT'], ['1234', 'CanadaHelps'], ['12a4', 'Check'], ['000', 'Check'], ['0000', 'Check']] as [$n, $pi]) {
            $this->assertFalse(DonationNotifier::isChequeNumber($n, $pi), "$n / $pi");
        }
    }

    /**
     * R1: browser-supplied `values` are digits above zero or nothing. Trips
     * on: "0", "1e3", " 5", "-1", true, an array, or "1 OR 1" being taken as an id.
     */
    public function testPositiveInt(): void
    {
        $this->assertSame(42, DonationLinker::positiveInt('42'));
        $this->assertSame(42, DonationLinker::positiveInt(42));
        foreach (['0', '1e3', ' 5', '-1', true, [5], '1 OR 1', null, ''] as $bad) {
            $this->assertNull(DonationLinker::positiveInt($bad), var_export($bad, true));
        }
    }

    /**
     * R7: the legacy type is removed from the New Contribution select and
     * nothing else is. Trips on: the wrong option removed, or the list re-keyed wrongly.
     */
    public function testWithoutOption(): void
    {
        $opts = [['text' => '- select -', 'attr' => ['value' => '']], ['text' => 'Donation', 'attr' => ['value' => '1']], ['text' => 'Client Donation', 'attr' => ['value' => '5']]];
        $this->assertSame(['- select -', 'Client Donation'], array_column(DonationLinker::withoutOption($opts, 1), 'text'));
        $this->assertCount(3, DonationLinker::withoutOption($opts, 99));
    }

    /**
     * Rounds 8-9: who a donation may credit (the picker list, the VC notice).
     * Trips on: an ended VC offered while a current one exists, several current
     * VCs collapsed to one, a past VC dropped from a completed project's list,
     * or one VC listed twice.
     */
    public function testCreditableCoordinators(): void
    {
        $this->assertSame([], DonationLinker::creditableCoordinators([]));
        $ended = ['id' => 1, 'contact_id_a' => 101, 'is_current' => false, 'start_date' => '2025-01-01', 'end_date' => '2026-05-01'];
        $earlier = ['id' => 2, 'contact_id_a' => 102, 'is_current' => false, 'start_date' => '2025-01-01', 'end_date' => '2025-06-01'];
        $again = ['id' => 3, 'contact_id_a' => 101, 'is_current' => false, 'start_date' => null, 'end_date' => null];
        $this->assertSame([101, 102], DonationLinker::creditableCoordinators([$earlier, $ended, $again]), 'completed project: everyone, most recently ended first, once each');
        $cur1 = ['id' => 4, 'contact_id_a' => 104, 'is_current' => true, 'start_date' => '2026-02-01', 'end_date' => null];
        $cur2 = ['id' => 5, 'contact_id_a' => 105, 'is_current' => true, 'start_date' => '2026-01-01', 'end_date' => null];
        $this->assertSame([105, 104], DonationLinker::creditableCoordinators([$ended, $cur1, $cur2]), 'current only, earliest first');
        // Auto-fill only for one person (round 10). Trips on: a guess between two.
        $this->assertNull(DonationLinker::soleCoordinator(DonationLinker::creditableCoordinators([$cur1, $cur2])), 'two current: the CSM picks');
        $this->assertNull(DonationLinker::soleCoordinator(DonationLinker::creditableCoordinators([$ended, $earlier])), 'two past: the CSM picks');
        $this->assertSame(101, DonationLinker::soleCoordinator(DonationLinker::creditableCoordinators([$ended, $again])), 'one person with two roles: filled');
        $this->assertNull(DonationLinker::soleCoordinator([]));
    }

    public function testPickCoordinator(): void
    {
        $this->assertNull(DonationLinker::pickCoordinator([]));
        $ended = [
            ['id' => 1, 'contact_id_a' => 101, 'is_current' => false, 'start_date' => '2025-01-01', 'end_date' => '2026-05-01'],
            ['id' => 2, 'contact_id_a' => 102, 'is_current' => false, 'start_date' => '2025-06-01', 'end_date' => '2025-09-01'],
            ['id' => 3, 'contact_id_a' => 103, 'is_current' => false, 'start_date' => null, 'end_date' => null],
        ];
        $this->assertSame(101, DonationLinker::pickCoordinator($ended), 'ended last beats started last and beats undated');
        $withCurrent = array_merge($ended, [
            ['id' => 4, 'contact_id_a' => 104, 'is_current' => true, 'start_date' => '2026-02-01', 'end_date' => null],
            ['id' => 5, 'contact_id_a' => 105, 'is_current' => true, 'start_date' => '2026-01-01', 'end_date' => null],
        ]);
        $this->assertSame(105, DonationLinker::pickCoordinator($withCurrent), 'current wins; earliest-started current');
    }

    public function testSummariseRollingWindow(): void
    {
        $base = [];
        foreach ([[10, 1, 100.0], [10, 2, 200.0], [10, 3, 300.0], [10, 4, 400.0], [0, 0, 0.0]] as $i => [$c, $w, $t]) {
            $base[] = ['quarter' => "Q$i", 'completed' => $c, 'with_donation' => $w, 'total' => $t];
        }
        $rows = DonationReport::summarise($base);
        $this->assertNull($rows[2]['rolling_pct'], 'no rolling value before four quarters exist');
        $this->assertEqualsWithDelta(10 / 40, $rows[3]['rolling_pct'], 1e-9, 'Q0..Q3');
        $this->assertEqualsWithDelta(1000 / 40, $rows[3]['rolling_avg_per_completed'], 1e-9);
        $this->assertEqualsWithDelta(9 / 30, $rows[4]['rolling_pct'], 1e-9, 'Q1..Q4: window moved, empty quarter counted');
        $this->assertNull($rows[4]['pct'], 'no completed projects: no %');
        $this->assertEqualsWithDelta(0.4, $rows[3]['pct'], 1e-9);
        $this->assertEqualsWithDelta(100.0, $rows[3]['avg_per_donation'], 1e-9);
    }

    public function testQuarterRangeCapBoundary(): void
    {
        // 2001 Q1 .. 2025 Q4 is exactly 100 quarters: allowed.
        $this->assertCount(DonationReport::MAX_QUARTERS, DonationReport::quartersBetween('2001-01-01', '2025-12-31'));
        // One more quarter is refused.
        $this->expectException(\InvalidArgumentException::class);
        DonationReport::quartersBetween('2000-10-01', '2025-12-31');
    }

    /** R4: a reviewed history map row is written only onto a live donation of the projects' own client. */
    public function testHistoryVerdict(): void
    {
        $live = ['case_type_id:name' => 'project', 'is_deleted' => false];
        $ok = [
            'contribution' => ['contact_id' => 5, 'financial_type_id:name' => 'Donation', 'is_test' => false, self::P => null, self::V => null],
            'cases' => [10 => $live, 11 => $live],
            'clients' => [10 => [5], 11 => [5, 6]],
            'coordinators' => [7, 8],
        ];
        $v = DonationLinker::historyVerdict($ok, [10, 11], [7, 8]);
        $this->assertNull($v['refuse']);
        $this->assertTrue($v['project'] && $v['vc'], 'one cheque, two projects and two VCs');
        $this->assertFalse(DonationLinker::historyVerdict($ok, [10], [])['vc'], 'no VC in the map writes no VC');

        $with = static fn(array $c) => ['contribution' => $c + $ok['contribution']] + $ok;
        $refuses = [
            'missing contribution' => [null, [10], [7]],
            'donor is not a client of one of the projects' => [['clients' => [10 => [5], 11 => [6]]] + $ok, [10, 11], [7]],
            'test contribution' => [$with(['is_test' => true]), [10], [7]],
            'event fee' => [$with(['financial_type_id:name' => 'Event Fee']), [10], [7]],
            'trashed project' => [['cases' => [10 => ['is_deleted' => true] + $live, 11 => $live]] + $ok, [10, 11], [7]],
            'service request, not a project' => [['cases' => [10 => ['case_type_id:name' => 'service_request'] + $live]] + $ok, [10], [7]],
            'case that does not exist' => [$ok, [10, 99], [7]],
            'VC not a coordinator of any of them' => [$ok, [10], [9]],
            'inside the notification window' => [['recent' => true] + $ok, [10], [7]],
        ];
        foreach ($refuses as $label => [$state, $cases, $vcs]) {
            $v = DonationLinker::historyVerdict($state, $cases, $vcs);
            $this->assertNotNull($v['refuse'], $label);
            $this->assertFalse($v['project'] || $v['vc'], $label);
        }

        $other = DonationLinker::historyVerdict($with([self::P => [11]]), [10, 11], [7]);
        $this->assertFalse($other['project'] || $other['vc'], 'linked to a different set of projects: left alone, VC too');
        $this->assertNotEmpty($other['conflicts']);

        $same = DonationLinker::historyVerdict($with([self::P => [11, 10], self::V => [8, 7]]), [10, 11], [7, 8]);
        $this->assertFalse($same['project'] || $same['vc'], 'second run changes nothing, whatever the order');
        $this->assertSame([], $same['conflicts']);

        $vc = DonationLinker::historyVerdict($with([self::P => null, self::V => [8]]), [10], [7]);
        $this->assertFalse($vc['project'] || $vc['vc'], 'a VC already credited is never overwritten, and the row writes nothing');
        $this->assertNotEmpty($vc['conflicts']);

        $fill = DonationLinker::historyVerdict($with([self::P => [10], self::V => null]), [10], [7]);
        $this->assertTrue(!$fill['project'] && $fill['vc'], 'a run that stopped after the project write fills the VC next time');

        $stray = DonationLinker::historyVerdict($with([self::P => null, self::V => [8]]), [10], []);
        $this->assertFalse($stray['project'], 'a credited VC the map does not name blocks the project link');
        $this->assertNotEmpty($stray['conflicts']);
    }

    /** R4: a contribution named twice makes the dry run and the apply disagree, so both rows are refused before any read. */
    public function testLinkHistoryRefusesARepeatedContribution(): void
    {
        $r = DonationLinker::linkHistory([
            ['contribution_id' => '5', 'case_id' => '10', 'vc_id' => '7'],
            ['contribution_id' => ' 5', 'case_id' => '11', 'vc_id' => ''],
            ['contribution_id' => '5x', 'case_id' => '10', 'vc_id' => ''],
            ['contribution_id' => '6', 'case_id' => '10', 'vc_id' => 'seven'],
            ['contribution_id' => '07', 'case_id' => '10', 'vc_id' => ''],
            ['contribution_id' => '7', 'case_id' => '10;11', 'vc_id' => ''],
            ['contribution_id' => '8', 'case_id' => '10,11', 'vc_id' => ''],
        ]);
        $this->assertCount(7, $r['refused']);
        $this->assertStringContainsString('more than once', $r['refused'][4], '07 and 7 are the same contribution');
        $this->assertStringContainsString('malformed', $r['refused'][6], 'map cells separate ids with ";" only');
        $this->assertStringContainsString('more than once', $r['refused'][0]);
        $this->assertStringContainsString('more than once', $r['refused'][1]);
        $this->assertSame(0, $r['project_written'] + $r['vc_written']);
    }

    /** The notify window, shared by DonationNotifier and R4's refusal: BOTH dates must be recent. */
    public function testInsideWindow(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->assertTrue(DonationNotifier::insideWindow($now, $now));
        $this->assertFalse(DonationNotifier::insideWindow($now, '2019-05-01'), 'old gift entered today (an import)');
        $this->assertFalse(DonationNotifier::insideWindow(date('Y-m-d', strtotime('-200 days')), $now), 'created long ago');
        $this->assertFalse(DonationNotifier::insideWindow(null, $now), 'no created date');
    }
}
