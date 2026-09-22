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
    /**
     * A RAW row, as the API4 case query returns it — keyed `id`.
     *
     * Input to applyStartDateSuppression(). Kept distinct from project()
     * below because conflating the two is what review caught: the fixtures
     * used one shape throughout, so the `id` -> `case_id` contract between the
     * two halves of the class was never exercised and renaming the key left
     * the suite green.
     */
    private function rawCase(int $id, ?string $startDate): array
    {
        return ['id' => $id, 'subject' => "Project {$id}", 'start_date' => $startDate];
    }

    /**
     * A POST-suppression row, as applyStartDateSuppression() emits it — keyed
     * `case_id`. Input to assignProjectsToCoordinators().
     */
    private function project(int $id, ?string $startDate): array
    {
        return ['case_id' => $id, 'subject' => "Project {$id}", 'start_date' => $startDate];
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
            [$this->rawCase(1, null), $this->rawCase(2, '')],
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
            [$this->rawCase(1, '2026-09-20'), $this->rawCase(2, '2026-01-01')],
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
        $result = VcDigestRunner::applyStartDateSuppression([$this->rawCase(1, '2026-08-23')], '2026-08-23');

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
        $this->assertSame(2, $result['without_vc'][0]['case_id']);
        $this->assertSame(
            [1],
            array_column($result['by_vc'][77]['projects'], 'case_id'),
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
     * case has two. Without the collapse the project appears twice in their
     * own digest and is reported as multi-coordinator — a plausible wrong
     * number rather than an error, which is this domain's house style of bug.
     *
     * ⚠ THIS TEST IS WEAKER THAN IT LOOKS, AND AN EARLIER VERSION WAS VACUOUS.
     * The collapse itself lives in groupByCoordinator(), in the loop that
     * builds `$coordinatorsByCase[$caseId][$contactId] = true` from a query
     * result — and that method issues API4 calls, so CI cannot reach it.
     * Passing an already-deduplicated fixture to assignProjectsToCoordinators()
     * tested nothing: review measured that removing the collapse left the
     * suite green.
     *
     * So this asserts two separate things honestly: that the keying idiom is
     * still present in the source (below), and that the pure half treats one
     * contact id as one person however many times the case appears.
     */
    public function testDuplicateRowsForTheSameCoordinatorCollapse(): void
    {
        $source = $this->runnerSource();
        $this->assertStringContainsString(
            '$coordinatorsByCase[$caseId][$contactId] = true;',
            $source,
            'The collapse is the keying: indexing by contact id is what makes two cache rows for one '
            . 'person one coordinator. Appending instead would double every re-added VC and report the '
            . 'project as multi-coordinator.'
        );

        $result = VcDigestRunner::assignProjectsToCoordinators(
            [1 => $this->project(1, '2026-01-01')],
            [1 => [77 => true]]
        );
        $this->assertCount(1, $result['by_vc'][77]['projects']);
        $this->assertSame([], $result['with_multiple_vcs'], 'One person is not two coordinators.');
    }

    /**
     * The key the two halves of this class agree on is `case_id`.
     *
     * Untested until review found it: the fixtures used `id`, and renaming the
     * emitted key from `case_id` to `id` left the whole suite green — while
     * `countDistinctProjects()` reads `case_id` and would silently have counted
     * nothing. A contract between two functions in the same class, relied on by
     * the headline number, with no test on it.
     */
    public function testSuppressionEmitsTheCaseIdKeyTheRestOfTheClassReads(): void
    {
        $result = VcDigestRunner::applyStartDateSuppression([$this->rawCase(7, '2026-01-01')], '2026-08-23');

        $this->assertSame(
            ['case_id', 'subject', 'start_date'],
            array_keys($result['projects'][7]),
            'Rename this key and countDistinctProjects() silently counts nothing.'
        );
        $this->assertSame(7, $result['projects'][7]['case_id']);
    }

    /**
     * Counting distinct projects must survive a run that selects nobody.
     *
     * THE CRASH THIS EXISTS FOR was live: the count was an inline expression
     * with an `array_values($byVc) ?: [[]]` "guard" that made `$byVc = []`
     * iterate once with `$vc = []`, so array_column() fatalled on NULL. It
     * took down two paths that matter — the D12 pilot (a mistyped id, or a
     * pilot whose projects all sit inside the 30-day window) and a month with
     * no eligible projects, which is this feature SUCCEEDING and exactly the
     * case the run summary exists to distinguish from a job that never ran.
     *
     * `run()` cannot be unit-tested (it issues API4 calls), which is why the
     * count is now a separate pure function: so the empty case is one line
     * here rather than something only a real invocation can discover.
     */
    public function testDistinctProjectCountSurvivesAnEmptyRun(): void
    {
        $this->assertSame(0, VcDigestRunner::countDistinctProjects([]));
        $this->assertSame(0, VcDigestRunner::countDistinctProjects([77 => ['vc_id' => 77, 'projects' => []]]));
    }

    /**
     * The count must key on `case_id`, not on anything else that happens to
     * look unique.
     *
     * ROUND-1's M4 REGENERATED ONE LAYER OUT, which is the point of this test.
     * That round pinned the key the PRODUCER emits; nothing pinned the column
     * the CONSUMER reads, and review measured that swapping
     * `array_column(..., 'case_id')` to `'subject'` left the whole suite green.
     * The two existing count tests could not tell the difference: the empty
     * case gives 0 either way, and the shared-project fixture used one
     * identical row twice, so `subject` deduplicated exactly as `case_id` did.
     *
     * On real data the consequence is `projects_included` counting distinct
     * SUBJECTS — an undercount whenever two projects share one, in the headline
     * number the deploy notes tell an operator to compare against production.
     */
    public function testDistinctProjectCountKeysOnCaseIdNotSomeOtherColumn(): void
    {
        $byVc = [
            // Same subject AND same start_date on purpose: with different
            // dates, keying on `start_date` would also have yielded 2 and the
            // mutation would have survived. Only `case_id` distinguishes these.
            77 => ['vc_id' => 77, 'projects' => [
                ['case_id' => 5, 'subject' => 'Strategic plan', 'start_date' => '2026-01-01'],
                ['case_id' => 6, 'subject' => 'Strategic plan', 'start_date' => '2026-01-01'],
            ]],
        ];

        $this->assertSame(
            2,
            VcDigestRunner::countDistinctProjects($byVc),
            'Two different projects that happen to share a subject are two projects.'
        );
    }

    /**
     * Zero is refused as a pilot id on its own.
     *
     * Split from the negative case because `'0,-4'` threw on the `-4` alone, so
     * the zero rode along untested — and under a `>= 0` mutation `'0'` would be
     * accepted, intersect to nothing, and hand the operator a silent
     * "0 VCs to mail" instead of a refusal.
     */
    public function testZeroAloneIsRefusedAsAPilotId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        VcDigestRunner::normalisePilotIds('0');
    }

    /**
     * An id too large for PHP's int is refused, not silently clamped.
     *
     * `(int)` SATURATES at PHP_INT_MAX rather than failing, so
     * '99999999999999999999' would become a different number — the one
     * remaining instance of the class this method was rewritten to close.
     */
    public function testAnOverlargeIdIsRefusedRatherThanClamped(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        VcDigestRunner::normalisePilotIds('99999999999999999999');
    }

    /**
     * Leading zeros and surrounding whitespace are legitimate and must pass —
     * a scheduled Job's parameter field is a free-text string.
     */
    public function testPaddedAndSpacedIdsAreAccepted(): void
    {
        $this->assertSame([12], VcDigestRunner::normalisePilotIds('0012'));
        $this->assertSame([12, 34], VcDigestRunner::normalisePilotIds(' 12 , 34 '));
        $this->assertSame([12], VcDigestRunner::normalisePilotIds('12,'));
    }

    /**
     * A project with two coordinators counts ONCE, which is the whole reason
     * there are two numbers in the summary.
     */
    public function testDistinctProjectCountDoesNotDoubleCountASharedProject(): void
    {
        $shared = ['case_id' => 5, 'subject' => 'Shared', 'start_date' => '2026-01-01'];
        $byVc = [
            77 => ['vc_id' => 77, 'projects' => [$shared]],
            88 => ['vc_id' => 88, 'projects' => [$shared]],
        ];

        $this->assertSame(1, VcDigestRunner::countDistinctProjects($byVc), 'One project, two digests.');
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

    /**
     * A PARTLY readable pilot list is refused whole.
     *
     * This is the case that mattered and had no test. An earlier version kept
     * whatever parsed and dropped the rest:
     *   '1,abc' -> [1]   — a chosen VC silently dropped
     *   '12.9'  -> [12]  — a DIFFERENT VC silently substituted
     * The second is the worse kind. It is not an omission but a misdelivery,
     * to somebody nobody chose, and both are the "silently drops a VC" failure
     * this class is shaped against — moved from selection into delivery, where
     * it is harder to notice.
     *
     * @dataProvider partlyUnreadablePilotLists
     */
    public function testPartlyUnreadablePilotListIsRefusedWhole($pilot): void
    {
        $this->expectException(\InvalidArgumentException::class);
        VcDigestRunner::normalisePilotIds($pilot);
    }

    public function partlyUnreadablePilotLists(): array
    {
        return [
            'one unreadable element' => ['1,abc'],
            'a decimal that would silently retarget' => ['12.9'],
            'scientific notation' => ['1e3'],
            'a nested array' => [[123, [456]]],
            'a float' => [[123, 4.9]],
            'a bool among ids' => [[123, true]],
        ];
    }

    public function testZeroAndNegativeIdsAreNotAcceptedAsAPilot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        VcDigestRunner::normalisePilotIds('0,-4');
    }

    // --- The coordinator predicate -------------------------------------

    /**
     * The query must test `is_current`, never `is_active`.
     *
     * Asserted over SOURCE because the predicate lives in an API4 call and CI
     * has no CiviCRM. That makes this a weak test of a strong fact, which is
     * the right trade here: the fact is one word, the consequence of getting
     * it wrong is invisible, and the same mistake has already been made once
     * in this feature — the P1-2 entitlement guard shipped `is_active` and
     * review caught it.
     *
     * `is_active` is a flag somebody sets; `is_current` additionally honours
     * the relationship's dates. On the 2026-09-21 clone 299 of 481 active
     * coordinator rows are ENDED. For the digest that means emailing a
     * volunteer about a project they handed over months ago, and — worse —
     * hiding that project from the coordinator-less exception report the
     * office works from.
     */
    /**
     * The runner's source, comments stripped.
     *
     * Comments are removed because this file necessarily DISCUSSES the things
     * being asserted against — `is_active` at length, for instance — so a raw
     * substring check would either always fail or be defeated by rewording
     * prose. Same technique as FrozenMachineNamesTest's codeOnly().
     */
    private function runnerSource(): string
    {
        $source = file_get_contents(__DIR__ . '/../../../Civi/Mascode/Service/VcDigestRunner.php');
        $this->assertNotFalse($source, 'VcDigestRunner is missing.');
        $code = '';
        foreach (token_get_all((string) $source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }
        return $code;
    }

    /**
     * D1's four query filters, none of which any behavioural test can reach.
     *
     * They live inside an API4 call, and CI has no CiviCRM. Review measured
     * that all four mutate freely with the suite green — including
     * `is_deleted`, which on this data is the difference between 146 and 138
     * Active project cases. A source assertion is a weak test of a strong
     * fact; the alternative here is no test at all.
     */
    public function testEligibilityQueryKeepsItsFourFilters(): void
    {
        $code = $this->runnerSource();

        foreach ([
            "addWhere('case_type_id:name', '=', self::CASE_TYPE)" => 'D1 is Project cases only.',
            "addWhere('status_id:name', '=', self::ELIGIBLE_STATUS)" => 'D1 is Active only — not On Hold, not the awaiting-form statuses.',
            "addWhere('is_deleted', '=', false)" => 'A deleted case is not a project anyone should be asked about.',
        ] as $needle => $why) {
            $this->assertStringContainsString($needle, $code, $why);
        }

        $this->assertSame(30, VcDigestRunner::SUPPRESS_DAYS, 'D2 is 30 days.');
        $this->assertSame('project', VcDigestRunner::CASE_TYPE);
        $this->assertSame('Active', VcDigestRunner::ELIGIBLE_STATUS);
    }

    /**
     * The trashed-contact condition the comment calls the fix must exist.
     *
     * `unmailableVcs()` is private and issues API4 calls, so behavioural
     * coverage is impossible — and review measured that BOTH its clauses mutate
     * freely with the suite green. That is exactly the situation this file
     * already answers with a source assertion for the D1 filters, and the
     * comment now says in capitals which half is load-bearing, so the half it
     * names should not be the untested one.
     */
    public function testTrashedCoordinatorsAreReported(): void
    {
        $code = $this->runnerSource();

        $this->assertStringContainsString(
            "['is_deleted', '=', true]",
            $code,
            'The fourth OR condition is what reports a trashed coordinator. Without it a trashed VC with a '
            . 'valid email matches none of the other conditions, stays in $byVc, and is counted as mailable.'
        );
        $this->assertStringContainsString(
            "addWhere('is_deleted', 'IN', [true, false])",
            $code,
            'Insurance against API4\'s default live-only filter, which does apply to an unfiltered '
            . 'Contact::get even though it did not suppress the id-filtered one.'
        );
    }

    public function testCoordinatorPredicateUsesIsCurrentNotIsActive(): void
    {
        $code = $this->runnerSource();

        $this->assertStringContainsString(
            "addWhere('is_current', '=', true)",
            $code,
            'The coordinator lookup must filter on is_current.'
        );
        $this->assertStringNotContainsString(
            "addWhere('is_active', '=', true)",
            $code,
            'is_active stays TRUE on an ENDED case role — 299 of 481 such rows that carry a case on the 2026-09-21 clone. '
            . 'Using it emails volunteers about projects they no longer run, and hides those projects from '
            . 'the coordinator-less exception report.'
        );
    }

    // --- The round -----------------------------------------------------

    public function testRoundIsTheCalendarMonthOfTheRun(): void
    {
        $this->assertSame('2026-09', VcDigestRunner::round('2026-09-22'));
        $this->assertSame('2026-01', VcDigestRunner::round('2026-01-31'));
    }
}
