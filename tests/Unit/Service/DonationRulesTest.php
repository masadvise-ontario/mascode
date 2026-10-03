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
        $client = ['financial_type_id:name' => 'Client Donation', 'contact_id.contact_type' => 'Organization', self::P => 10, self::V => 7];
        $this->assertSame(7, DonationNotifier::vcRecipient($client));
        $this->assertSame(0, DonationNotifier::vcRecipient(['financial_type_id:name' => 'Private Donation', 'contact_id.contact_type' => 'Individual'] + $client), 'private donation');
        $this->assertSame(7, DonationNotifier::vcRecipient(['financial_type_id:name' => 'Donation'] + $client), 'legacy type, organization donor');
        $this->assertSame(0, DonationNotifier::vcRecipient(['financial_type_id:name' => 'Donation', 'contact_id.contact_type' => 'Individual'] + $client), 'legacy type, individual donor');
        $this->assertSame(0, DonationNotifier::vcRecipient([self::P => null] + $client), 'no project');
        $this->assertSame(0, DonationNotifier::vcRecipient([self::V => null] + $client), 'no VC');
    }

    public function testOnlyTheVcNoticeIsFiledOnTheCase(): void
    {
        $d = [self::P => 10];
        $this->assertSame(10, DonationNotifier::caseIdFor(DonationNotifier::TEMPLATE_VC, $d));
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

    public function testQuarterRangeIsCapped(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DonationReport::quartersBetween('1900-01-01', '2026-01-01');
    }
}
