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
 *  - vcRecipient: a PRIVATE donation with a project and VC must not notify the
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

    public function testVcRecipient(): void
    {
        $client = ['financial_type_id:name' => 'Client Donation', 'contact_id.contact_type' => 'Organization', self::P => 10, self::P . '.case_type_id:name' => 'project', self::V => 7];
        $this->assertSame(7, DonationNotifier::vcRecipient($client));
        // Keeps the Organization donor, so only the TYPE check can stop it.
        $this->assertSame(0, DonationNotifier::vcRecipient(['financial_type_id:name' => 'Private Donation'] + $client), 'private donation');
        $this->assertSame(0, DonationNotifier::vcRecipient([self::P . '.is_deleted' => true] + $client), 'trashed project');
        $this->assertSame(7, DonationNotifier::vcRecipient(['financial_type_id:name' => 'Donation'] + $client), 'legacy type, organization donor');
        $this->assertSame(0, DonationNotifier::vcRecipient(['financial_type_id:name' => 'Donation', 'contact_id.contact_type' => 'Individual'] + $client), 'legacy type, individual donor');
        $this->assertSame(0, DonationNotifier::vcRecipient([self::P => null] + $client), 'no project');
        $this->assertSame(0, DonationNotifier::vcRecipient([self::V => null] + $client), 'no VC');
        $this->assertSame(0, DonationNotifier::vcRecipient(['contact_id.contact_type' => 'Individual'] + $client), 'individual mis-typed as Client Donation');
        $this->assertSame(0, DonationNotifier::vcRecipient([self::P . '.case_type_id:name' => 'service_request'] + $client), 'linked to a non-Project case');
    }

    public function testOnlyTheVcNoticeIsFiledOnTheCase(): void
    {
        $d = [self::P => 10, self::P . '.case_type_id:name' => 'project'];
        $this->assertSame(10, DonationNotifier::caseIdFor(DonationNotifier::TEMPLATE_VC, $d));
        $this->assertNull(DonationNotifier::caseIdFor(DonationNotifier::TEMPLATE_VC, [self::P . '.case_type_id:name' => 'service_request'] + $d), 'non-Project case');
        $this->assertNull(DonationNotifier::caseIdFor(DonationNotifier::TEMPLATE_ED, $d));
        $this->assertNull(DonationNotifier::caseIdFor(DonationNotifier::TEMPLATE_TREASURER, $d));
        $this->assertNull(DonationNotifier::caseIdFor(DonationNotifier::TEMPLATE_VC, [self::P => null]));
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
    }

    /**
     * R2: only a real cheque number groups a split gift. Trips on: a shared
     * reference like "EFT" or "0", or a non-Check payment, telling one
     * project's VC about another project's gift (round 6 of PR #76).
     */
    public function testIsChequeNumber(): void
    {
        $this->assertTrue(DonationNotifier::isChequeNumber('1234', 'Check'));
        foreach ([['EFT', 'Check'], ['0', 'Check'], ['12', 'Check'], ['', 'Check'], ['1234', 'EFT'], ['1234', 'CanadaHelps'], ['12a4', 'Check']] as [$n, $pi]) {
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
}
