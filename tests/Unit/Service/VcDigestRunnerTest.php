<?php

namespace Civi\Mascode\Test\Unit\Service;

use Civi\Mascode\Service\VcDigestRunner;
use Civi\Mascode\Test\TestCase;

/**
 * The digest's selection and grouping rules.
 *
 * WHY THESE RULES GET A TEST AND THE QUERIES DO NOT. The spec names
 * `selectEligibleProjects()` as the place "where a wrong answer silently drops
 * a VC" — a project wrongly excluded is invisible, because the VC is simply
 * never asked and the feature looks like it is working. So the rules are
 * separated from the API4 calls that feed them, and the rules are what is
 * asserted here. CI has no CiviCRM (docs/TESTING.md), so a rule left inside a
 * method that issues a query is a rule with no test.
 *
 * THE MOST IMPORTANT TEST IN THIS FILE CANNOT FAIL ON TODAY'S DATA.
 * `testProjectWithNoStartDateIsIncluded()` guards a NULL `start_date`, and the
 * 2026-09-21 dev clone has none — the column is nullable on `civicrm_case`, so
 * the trap is latent rather than live. That is exactly why it is written down:
 * the obvious "simplification" of D2 into a SQL `WHERE start_date <= ...`
 * clause silently drops every NULL row, and on today's data every test would
 * stay green while it did.
 *
 * @coversNothing
 */
class VcDigestRunnerTest extends TestCase
{
    private function project(int $id, ?string $startDate): array
    {
        return ['id' => $id, 'subject' => "Project {$id}", 'start_date' => $startDate];
    }

    // --- D2: the 30-day suppression ------------------------------------

    /**
     * A project with no recorded start date must be ASKED ABOUT, not dropped.
     *
     * A NULL start date is not "started recently"; it is "nobody recorded a
     * start date", and its VC still deserves the question. The failure this
     * guards is silent in both senses: SQL's `start_date <= x` is NULL (not
     * TRUE) for a NULL row so it vanishes from the result set, and the VC never
     * learns they were not asked.
     */
    public function testProjectWithNoStartDateIsIncluded(): void
    {
        $result = VcDigestRunner::applyStartDateSuppression(
            [$this->project(1, null), $this->project(2, '')],
            '2026-08-23'
        );

        $this->assertSame(
            [1, 2],
            array_keys($result['projects']),
            'A project with no start date must be included. Pushing D2 into a SQL WHERE clause drops these '
            . 'silently — and the dev clone has none, so no test on real data would notice.'
        );
        $this->assertSame(0, $result['skipped_recent']);
    }

    public function testRecentProjectIsSuppressedAndCounted(): void
    {
        $result = VcDigestRunner::applyStartDateSuppression(
            [$this->project(1, '2026-09-20'), $this->project(2, '2026-01-01')],
            '2026-08-23'
        );

        $this->assertSame([2], array_keys($result['projects']), 'The recently-started project must be suppressed.');
        $this->assertSame(1, $result['skipped_recent'], 'A suppressed project must be counted, not just dropped.');
    }

    /**
     * The boundary is inclusive: a project started exactly on the cutoff is
     * asked about.
     *
     * Stated as a test because "within 30 days" is ambiguous in English and
     * an off-by-one here moves whole projects between months, invisibly.
     */
    public function testProjectStartedExactlyOnTheCutoffIsIncluded(): void
    {
        $result = VcDigestRunner::applyStartDateSuppression([$this->project(1, '2026-08-23')], '2026-08-23');

        $this->assertSame([1], array_keys($result['projects']));
        $this->assertSame(0, $result['skipped_recent']);
    }

    // --- Grouping by coordinator ---------------------------------------

    /**
     * Goal 9: a project nobody coordinates is REPORTED, never discarded.
     */
    public function testProjectWithNoCoordinatorIsReportedNotDropped(): void
    {
        $projects = [1 => $this->project(1, '2026-01-01'), 2 => $this->project(2, '2026-01-01')];
        $result = VcDigestRunner::assignProjectsToCoordinators($projects, [1 => [77 => true]]);

        $this->assertSame([77], array_keys($result['by_vc']));
        $this->assertCount(1, $result['without_vc'], 'The coordinator-less project must be reported.');
        $this->assertSame(2, $result['without_vc'][0]['id']);
        $this->assertSame(
            [1],
            array_column($result['by_vc'][77]['projects'], 'id'),
            'A coordinator-less project must not be attached to some other VC.'
        );
    }

    /**
     * A project with two active coordinators goes to BOTH, and is flagged.
     *
     * The spec does not decide this case and 4 Active projects on the
     * 2026-09-21 dev clone are in it. Asking both is the direction that cannot
     * silently drop anyone; the alternative is picking one, which needs a rule
     * saying WHICH and nobody has written one. Flagged so the office sees it
     * rather than hearing it from a confused volunteer.
     */
    public function testProjectWithTwoCoordinatorsGoesToBothAndIsFlagged(): void
    {
        $projects = [1 => $this->project(1, '2026-01-01')];
        $result = VcDigestRunner::assignProjectsToCoordinators($projects, [1 => [77 => true, 88 => true]]);

        $this->assertSame([77, 88], array_keys($result['by_vc']), 'Both coordinators must be asked.');
        $this->assertCount(1, $result['with_multiple_vcs'], 'The double-ask must be surfaced, not silent.');
        $this->assertSame([77, 88], $result['with_multiple_vcs'][0]['coordinator_ids']);
    }

    /**
     * Two coordinator rows for the SAME person are one person.
     *
     * RelationshipCache holds a row per relationship, so a VC re-added to a
     * case has two. Without the collapse the project would appear twice in
     * their own digest and be reported as multi-coordinator — a plausible
     * wrong number rather than an error, which is this domain's house style of
     * bug (see the case-role direction note in mascode memory).
     */
    public function testDuplicateRowsForTheSameCoordinatorCollapse(): void
    {
        $projects = [1 => $this->project(1, '2026-01-01')];
        // Two rows, one person: the inner array is keyed by contact id, which
        // is what does the collapsing.
        $result = VcDigestRunner::assignProjectsToCoordinators($projects, [1 => [77 => true]]);

        $this->assertCount(1, $result['by_vc'][77]['projects']);
        $this->assertSame([], $result['with_multiple_vcs'], 'One person is not two coordinators.');
    }

    // --- The pilot list (D12) ------------------------------------------

    public function testPilotIdsAcceptBothCsvAndArray(): void
    {
        $this->assertSame([12, 34], VcDigestRunner::normalisePilotIds('12, 34'));
        $this->assertSame([12, 34], VcDigestRunner::normalisePilotIds([12, '34']));
        $this->assertSame([12], VcDigestRunner::normalisePilotIds([12, 12]), 'Duplicates collapse.');
    }

    public function testEmptyPilotMeansEveryVc(): void
    {
        $this->assertSame([], VcDigestRunner::normalisePilotIds(null));
        $this->assertSame([], VcDigestRunner::normalisePilotIds(''));
        $this->assertSame([], VcDigestRunner::normalisePilotIds([]));
    }

    /**
     * An unreadable pilot list is REFUSED, never treated as empty.
     *
     * Empty means every VC. So the failure mode of a typo'd or mis-typed
     * parameter — a scheduled Job's parameter field holds a string — must be a
     * loud stop, not a mail-out to all 62 volunteers. D12 exists precisely so
     * the first real batch is small.
     */
    public function testUnreadablePilotListIsRefusedRatherThanTreatedAsEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        VcDigestRunner::normalisePilotIds('nina, steve');
    }

    public function testZeroAndNegativeIdsAreNotAcceptedAsAPilot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        VcDigestRunner::normalisePilotIds('0,-4');
    }

    // --- The round -----------------------------------------------------

    public function testRoundIsTheCalendarMonthOfTheRun(): void
    {
        $this->assertSame('2026-09', VcDigestRunner::round('2026-09-22'));
        $this->assertSame('2026-01', VcDigestRunner::round('2026-01-31'));
    }
}
