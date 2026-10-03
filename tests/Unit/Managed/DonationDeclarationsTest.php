<?php

namespace Civi\Mascode\Test\Unit\Managed;

use Civi\Mascode\Test\TestCase;

/**
 * Decisions in the donation declarations that a well-meaning edit would undo
 * (spec BrianPKM 3-Resources/mas-donation-process.md §4).
 *
 *  - The VC notification carries NO amount (Q4). Its activity is filed on the
 *    Project case, and the VC Portal lists every case activity subject.
 *    Trips on: any amount, fee or net placeholder, or a core
 *    {contribution.*_amount} token, in the VC template's subject or body.
 *  - There is no membership fee (D-B). Trips on: Member Dues declared with
 *    anything but is_active FALSE, or the declaration removed.
 *
 * @coversNothing
 */
class DonationDeclarationsTest extends TestCase
{
    private const DIR = __DIR__ . '/../../../Civi/Mascode/Managed/';

    public function testVcNotificationNeverShowsTheAmount(): void
    {
        $values = (require self::DIR . 'MessageTemplate_donation_notify__vc.mgd.php')[0]['params']['values'];
        foreach (['msg_subject', 'msg_html', 'msg_text'] as $part) {
            $text = (string) ($values[$part] ?? '');
            $this->assertDoesNotMatchRegularExpression(
                '/%%mas_donation\.(amount|fee|net)%%|\{contribution\.[a-z_]*amount/',
                $text,
                "$part of the VC donation notice must not carry the amount (spec §4 Q4)"
            );
        }
    }

    public function testTheVcRuleIsNotVacuous(): void
    {
        // The ED notice DOES carry the amount, so the regex above can match.
        $values = (require self::DIR . 'MessageTemplate_donation_notify__ed.mgd.php')[0]['params']['values'];
        $this->assertMatchesRegularExpression('/%%mas_donation\.amount%%/', $values['msg_html']);
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
}
