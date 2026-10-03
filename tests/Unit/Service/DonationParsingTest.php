<?php

namespace Civi\Mascode\Test\Unit\Service;

use Civi\Mascode\Service\DonationLinker;
use Civi\Mascode\Service\DonationReport;
use Civi\Mascode\Test\TestCase;

/**
 * The pure parts of the donations work (docs/plans/donations-tickets.md):
 * reading project codes out of free-text Source (DN-5's backfill), and the
 * quarter arithmetic the Treasurer's report rests on (DN-4).
 *
 * Inputs that trip each guard, named because a guard that asserts nothing is
 * worse than none:
 *  - a parser that accepts six digits links "P252107" (a real typo on prod) to
 *    a guessed project;
 *  - a parser that keeps only the first code hides the two-project cheque
 *    from the backfill's hand-review list;
 *  - quartersBetween() that skips empty quarters or stops at the year boundary
 *    makes the rolling 4Q window span the wrong calendar quarters.
 *
 * @coversNothing
 */
class DonationParsingTest extends TestCase
{
    /**
     * @dataProvider sources
     */
    public function testProjectCodesIn(?string $source, array $expected): void
    {
        $this->assertSame($expected, DonationLinker::projectCodesIn($source));
    }

    public function sources(): array
    {
        return [
            'typical' => ['P26035 - Board presentation - VC A.', ['P26035']],
            'two projects, in order' => ['FIRST P26050 - Board 101 - VC A. - and SECOND P26057 - Strat Plan - VC B. via CH', ['P26050', 'P26057']],
            'split cheque' => ['$X for P25119 (VC A.) Governance AND $X for P25118 (VC B.) HR', ['P25119', 'P25118']],
            'lowercase' => ['p25054 - hr', ['P25054']],
            'repeated code once' => ['P25054 / P25054 again', ['P25054']],
            'six-digit typo is not a code' => ['P252107 - VC A. - Board presentation', []],
            'legacy text' => ['membership and private donation', []],
            'empty' => ['', []],
            'null' => [null, []],
        ];
    }

    /**
     * @dataProvider dates
     */
    public function testQuarterOf(string $date, string $expected): void
    {
        $this->assertSame($expected, DonationReport::quarterOf($date));
    }

    public function dates(): array
    {
        return [
            ['2026-01-01', '2026 Q1'],
            ['2026-03-31', '2026 Q1'],
            ['2026-04-01', '2026 Q2'],
            ['2026-09-30', '2026 Q3'],
            ['2026-12-31', '2026 Q4'],
        ];
    }

    public function testQuartersBetweenCrossesYearsAndKeepsEmptyQuarters(): void
    {
        $this->assertSame(
            ['2025 Q4', '2026 Q1', '2026 Q2', '2026 Q3'],
            DonationReport::quartersBetween('2025-11-15', '2026-07-01')
        );
        $this->assertSame(['2026 Q2'], DonationReport::quartersBetween('2026-04-01', '2026-06-30'));
        $this->assertSame([], DonationReport::quartersBetween('2026-07-01', '2026-01-01'));
    }
}
