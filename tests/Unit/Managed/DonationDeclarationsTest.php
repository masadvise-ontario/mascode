<?php

namespace Civi\Mascode\Test\Unit\Managed;

use Civi\Mascode\Service\DonationLinker;
use Civi\Mascode\Service\DonationNotifier;
use Civi\Mascode\Test\TestCase;

/**
 * Decisions in the donation declarations that a well-meaning edit would undo
 * (spec BrianPKM 3-Resources/mas-donation-process.md §4).
 *
 *  - The VC notification shows the amount in its BODY only (R2, Treasurer
 *    demo 2026-10-06). Its activity is filed on the Project case, and the VC
 *    Portal lists every case activity subject. Trips on: an amount
 *    placeholder in the VC subject, fee/net/source anywhere in it, or the
 *    amount dropped from the body.
 *  - CAF Donation (R7) is a non-deductible type that is NOT a donation, and
 *    the legacy "Donation" type is never redeclared (renaming or disabling
 *    it would relabel or blank 1,473 historical gifts). Trips on: CAF added
 *    to DONATION_TYPES, or a declaration naming "Donation".
 *  - There is no membership fee (D-B). Trips on: Member Dues declared with
 *    anything but is_active FALSE, or the declaration removed.
 *
 * @coversNothing
 */
class DonationDeclarationsTest extends TestCase
{
    private const DIR = __DIR__ . '/../../../Civi/Mascode/Managed/';

    public function testVcNotificationShowsTheAmountInTheBodyOnly(): void
    {
        $values = (require self::DIR . 'MessageTemplate_donation_notify__vc.mgd.php')[0]['params']['values'];
        $this->assertSame([], DonationNotifier::vcTemplateViolations($values['msg_subject'], DonationNotifier::VC_SUBJECT_SAFE_PLACEHOLDERS),
            'the VC subject uses a placeholder outside the subject allowlist');
        $this->assertFalse(DonationNotifier::showsAmount($values['msg_subject']), 'the VC subject shows the amount');
        foreach (['msg_html', 'msg_text'] as $part) {
            $this->assertSame([], DonationNotifier::vcTemplateViolations((string) ($values[$part] ?? '')),
                "$part of the VC donation notice uses a placeholder outside the allowlist");
        }
        $this->assertStringContainsString('%%mas_donation.amount%%', $values['msg_html'], 'R2: the VC body shows the amount');
        $this->assertStringContainsString('%%mas_donation.split%%', $values['msg_html'], 'R2: the VC body shows a split gift');
    }

    /**
     * No SUBJECT shows the amount. Core's activity ACL shows an activity to
     * anyone who can view one of its contacts, and lists show the subject.
     * Trips on: an amount placeholder put back into the ED or Treasurer subject.
     */
    public function testNoNotificationSubjectShowsTheAmount(): void
    {
        foreach (['ed', 'treasurer', 'vc'] as $who) {
            $values = (require self::DIR . "MessageTemplate_donation_notify__$who.mgd.php")[0]['params']['values'];
            $this->assertFalse(DonationNotifier::showsAmount($values['msg_subject']), "$who subject shows the amount");
        }
    }

    public function testTheVcRuleIsNotVacuous(): void
    {
        // The ED notice DOES carry the amount, so the regex above can match.
        $values = (require self::DIR . 'MessageTemplate_donation_notify__ed.mgd.php')[0]['params']['values'];
        $this->assertTrue(DonationNotifier::showsAmount($values['msg_html']));
    }

    public function testMemberDuesStaysDisabled(): void
    {
        $byName = [];
        foreach (require self::DIR . 'FinancialType_Donations.mgd.php' as $decl) {
            $byName[$decl['params']['values']['name']] = $decl['params']['values'];
        }
        $this->assertArrayHasKey('Member Dues', $byName, 'Member Dues must stay declared, so the deploy keeps it disabled');
        $this->assertFalse($byName['Member Dues']['is_active']);
        $this->assertTrue($byName['Client Donation']['is_deductible']);
        $this->assertTrue($byName['Private Donation']['is_deductible']);
    }

    public function testCafIsInTotalsButNotADonation(): void
    {
        $byName = [];
        foreach (require self::DIR . 'FinancialType_Donations.mgd.php' as $decl) {
            $byName[$decl['params']['values']['name']] = $decl['params']['values'];
        }
        $this->assertArrayHasKey('CAF Donation', $byName);
        $this->assertFalse($byName['CAF Donation']['is_deductible'], 'MAS issues no receipt for CAF funds');
        $this->assertTrue($byName['CAF Donation']['is_active']);
        $this->assertNotContains('CAF Donation', DonationLinker::DONATION_TYPES, 'CAF is not counted as a donation');
        $this->assertArrayNotHasKey('Donation', $byName, 'the legacy type must not be renamed or disabled by a declaration');

        $contact = (require self::DIR . 'Contact_CommunityActionFoundation.mgd.php')[0];
        $this->assertSame('Organization', $contact['params']['values']['contact_type']);
        $this->assertSame('unmodified', $contact['update'], 'a UI correction of the name must survive a deploy');
        $this->assertSame('never', $contact['cleanup']);
    }
}
