<?php

declare(strict_types=1);

// file: Civi/Mascode/Service/VcDigestRunner.php

namespace Civi\Mascode\Service;

/**
 * Selects the projects the monthly VC digest asks about, and groups them by
 * the volunteer who would be asked.
 *
 * Spec: BrianPKM 3-Resources/mascode-vc-monthly-donation-digest-spec.md,
 * decisions D1, D2, D3 and D12. Ticket: docs/plans/completion-signoff-tickets.md
 * P1-3.
 *
 * THE FAILURE MODE THIS CLASS IS SHAPED AROUND
 * ---------------------------------------------------------------------------
 * The spec names selectEligibleProjects() as the place "where a wrong answer
 * silently drops a VC", and that asymmetry drives every judgement call below.
 * A project wrongly INCLUDED costs one volunteer one unnecessary line in one
 * email, and they will tell us. A project wrongly EXCLUDED is invisible: the
 * VC is never asked, the backlog never shrinks, and the feature looks like it
 * is working. So wherever the rule is ambiguous this class includes and
 * REPORTS, rather than excluding quietly.
 *
 * Sending is NOT here. This class plans; VcDigestMailer (P1-4) sends. They are
 * separate because the plan is the part that can be checked against reality
 * before anything reaches 62 volunteers, which is the whole point of running
 * `dry_run=1` first.
 */
final class VcDigestRunner
{
    /** D1: only Project cases, and only this status. */
    public const CASE_TYPE = 'project';

    /**
     * D1: *Active* only.
     *
     * Deliberately NOT *On Hold* (paused on purpose, handled separately) and
     * NOT either *Awaiting …Form* status — those already carry the 30/90/150
     * chase, so including them would nag a VC about a project they have
     * already declared finished.
     */
    public const ELIGIBLE_STATUS = 'Active';

    /** D2: a project started within this many days is not asked about. */
    public const SUPPRESS_DAYS = 30;

    /**
     * The case role that names the VC.
     *
     * On `Case Coordinator is` the VC is `contact_id_a` — `near_contact_id` in
     * RelationshipCache — and the CLIENT ORGANISATION is `contact_id_b`.
     * Getting this backwards does not error: it returns a plausible number of
     * the wrong contacts (mascode memory reference_mas_case_role_direction).
     * Verified on the 2026-09-21 dev clone: the near side is 89 distinct
     * Individuals, all `MAS_Rep`; the far side is 317 Organizations.
     *
     * ⚠ THE ROLE MUST BE TESTED WITH `is_current`, NOT `is_active`, and the
     * first version of this class used the latter. `is_active` is a flag
     * somebody sets; `is_current` is core's
     * `is_active = 1 AND (start_date <= today OR IS NULL) AND
     * (end_date >= today OR IS NULL)`.
     *
     * There are two ways to end a case role and only one clears the flag:
     * `CRM_Case_BAO_Case::endCaseRole()` (the case-roles UI) sets both, while
     * an end date set on the Relationships tab, an import, a bulk data fix, or
     * the *Disable expired relationships* job not having run leaves
     * `is_active = 1`. On the 2026-09-21 clone **299 of 482** active
     * coordinator rows are ended, some since March 2025.
     *
     * For the digest the consequence is a wrong email rather than a leak: a
     * volunteer asked to confirm a project they handed over months ago. It
     * also hides the real problem, because that project stops appearing in the
     * coordinator-less exception report the office works from. Under
     * `is_current`, exactly one project on that clone moves from a VC's digest
     * into that report — which is where a project whose coordinator has left
     * belongs (Goal 9).
     *
     * ⚠ AND IT MOVES A NUMBER P2-3 ASSERTS ON: that ticket says the no-VC row
     * should show "1 project, not 8". Under `is_current` today's answer is 2.
     */
    public const COORDINATOR_RELATION = 'Case Coordinator is';

    /**
     * Plan one digest run.
     *
     * @param array $params
     *   - as_of (string Y-m-d, default today) — the date the run is reckoned
     *     from. Exists so a run can be reproduced, and so tests are not
     *     hostage to the calendar.
     *     ⚠ It reproduces D2 ONLY. `is_current` is a core pseudo-field whose
     *     SQL hardcodes `date('Ymd')` at query time, so a replay with a past
     *     `as_of` still uses TODAY's relationship currency. A run cannot be
     *     reproduced exactly once roles have moved, and nothing here can change
     *     that without reimplementing core's predicate.
     *   - pilot_vc_ids (int[]|string) — restrict to these VC contact ids
     *     (D12). Empty means every VC. A CSV string is accepted because that
     *     is what a scheduled-job parameter field holds.
     *   - dry_run (bool, default TRUE) — plan only.
     *
     * @return array{
     *   round:string, as_of:string, dry_run:bool,
     *   vcs:array<int,array>, vcs_to_mail:int,
     *   projects_included:int, digest_rows:int,
     *   pilot_vc_ids:int[], projects_without_vc:array, skipped_recent:int,
     *   projects_with_multiple_vcs:array, unmailable_vcs:array
     * }
     *
     * `projects_included` counts DISTINCT projects; `digest_rows` counts the
     * project lines that will be sent across all digests. They differ by the
     * number of projects with more than one active coordinator.
     */
    public static function run(array $params = []): array
    {
        $asOf = self::normaliseAsOf($params['as_of'] ?? null);
        $dryRun = !array_key_exists('dry_run', $params) || (bool) $params['dry_run'];
        $pilot = self::normalisePilotIds($params['pilot_vc_ids'] ?? null);

        $selection = self::selectEligibleProjects($asOf);
        $grouped = self::groupByCoordinator($selection['projects']);

        $byVc = $grouped['by_vc'];
        if ($pilot) {
            // Restrict AFTER grouping, never inside the query. The pilot is a
            // delivery restriction, not a change to what is eligible — so the
            // counts a dry run reports stay comparable between a pilot run and
            // a full one, and the exception report below still names every
            // coordinator-less project rather than only the pilot's.
            $byVc = array_intersect_key($byVc, array_flip($pilot));
        }

        $summary = [
            'round' => self::round($asOf),
            'as_of' => $asOf,
            'dry_run' => $dryRun,
            'pilot_vc_ids' => $pilot,
            'vcs' => $byVc,
            // `vcs_to_mail`, not `vcs_mailed`. The spec's output list names the
            // latter, but nothing here sends, so a `vcs_mailed` key would be
            // structurally 0 on every run — a field that always reports
            // success-with-nothing-done. P1-4 adds the real one alongside this.
            'vcs_to_mail' => count($byVc),
            // TWO counts, because one number here is a lie in the plausible
            // direction. A project with two active coordinators appears in two
            // VCs' digests, so summing the per-VC lists gives MORE rows than
            // there are projects — on the 2026-09-21 dev clone, 136 rows
            // against 132 distinct projects. That reads as a selection bug to
            // anyone checking the arithmetic: "we included 4 extra projects"
            // rather than "4 projects are being asked about twice".
            //
            // `projects_included` is therefore DISTINCT projects, and
            // `digest_rows` is how many project lines will be sent in total.
            // They differ by exactly the multi-coordinator count.
            // No `?: [[]]` fallback. An earlier version had one, and it was
            // the bug rather than the guard: `array_values([]) ?: [[]]` is
            // `[[]]`, which iterates ONCE with `$vc = []`, so `$vc['projects']`
            // is undefined and array_column() fatals on NULL. array_merge()
            // has accepted zero arguments since PHP 7.4, so nothing was needed.
            //
            // It fatalled on two paths that matter: the D12 pilot (a mistyped
            // id, or a pilot whose projects all sit inside the 30-day window),
            // and a month with NO eligible projects — which is this feature
            // SUCCEEDING, and precisely the case the run summary exists to
            // distinguish from a job that never ran.
            'projects_included' => self::countDistinctProjects($byVc),
            'digest_rows' => array_sum(array_map(static fn($vc) => count($vc['projects']), $byVc)),
            'skipped_recent' => $selection['skipped_recent'],
            // Goal 9: never silently skipped. An Active project nobody
            // coordinates is a thing for the office to fix, not a row to drop.
            'projects_without_vc' => $grouped['without_vc'],
            // The spec does not decide this case; see groupByCoordinator().
            'projects_with_multiple_vcs' => $grouped['with_multiple_vcs'],
            // Reported, and deliberately NOT removed from $byVc or from
            // vcs_to_mail. The two numbers therefore overlap, which is worth
            // stating because it looks like an inconsistency: an unmailable VC
            // is a data problem for the office to fix, not a VC to quietly
            // forget. P1-4, which actually sends, is where they get skipped —
            // and it must subtract them from what it reports as mailed.
            'unmailable_vcs' => self::unmailableVcs(array_keys($byVc)),
            // No `errors` key: nothing here can partially fail. A per-VC send
            // can, so P1-4 adds it when there is something to put in it. An
            // always-empty errors list reads as "checked, none found".
        ];

        if (!$dryRun) {
            // Deliberate hard stop rather than a quiet no-op. A runner that
            // returned a tidy summary having sent nothing is indistinguishable
            // from a successful run, and that is exactly the "silently did
            // nothing" failure this feature exists to remove from the office.
            throw new \RuntimeException(
                'VcDigestRunner can plan but not send: VcDigestMailer lands in P1-4. '
                . 'Re-run with dry_run=1.'
            );
        }

        \Civi::log()->info('VcDigestRunner.php - Planned VC digest run', [
            'round' => $summary['round'],
            'as_of' => $asOf,
            'vcs' => count($byVc),
            'projects_included' => $summary['projects_included'],
            'digest_rows' => $summary['digest_rows'],
            'skipped_recent' => $summary['skipped_recent'],
            'projects_without_vc' => count($summary['projects_without_vc']),
            'projects_with_multiple_vcs' => count($summary['projects_with_multiple_vcs']),
            'unmailable_vcs' => count($summary['unmailable_vcs']),
            'dry_run' => $dryRun,
        ]);

        return $summary;
    }

    /**
     * Distinct projects across every VC's list.
     *
     * A named function rather than an expression inline in the summary,
     * because the inline version FATALLED on an empty `$byVc` and nothing in
     * CI could see it: `run()` issues API4 calls, so it has no unit test, and
     * the crash only appeared when a real invocation happened to select
     * nobody. Pulled out so the empty case is a one-line assertion.
     *
     * @param array<int,array> $byVc
     */
    public static function countDistinctProjects(array $byVc): int
    {
        return count(array_unique(array_merge(
            ...array_map(
                static fn($vc) => array_column($vc['projects'] ?? [], 'case_id'),
                array_values($byVc)
            )
        )));
    }

    /**
     * The projects eligible under D1 and D2.
     *
     * @return array{projects:array<int,array>, skipped_recent:int}
     */
    public static function selectEligibleProjects(string $asOf): array
    {
        $cutoff = date('Y-m-d', strtotime($asOf . ' -' . self::SUPPRESS_DAYS . ' days'));

        $all = \Civi\Api4\CiviCase::get(false)
            ->addSelect('id', 'subject', 'start_date', 'case_type_id:name', 'status_id:name')
            ->addWhere('case_type_id:name', '=', self::CASE_TYPE)
            ->addWhere('status_id:name', '=', self::ELIGIBLE_STATUS)
            ->addWhere('is_deleted', '=', false)
            ->setLimit(0)
            ->execute()
            ->getArrayCopy();

        return self::applyStartDateSuppression($all, $cutoff);
    }

    /**
     * D2, as a pure function of the rows and the cutoff.
     *
     * Separated from the query so CI can test it. There is no CiviCRM in CI
     * (docs/TESTING.md), so a rule left inside a method that issues an API4
     * call is a rule with no test — and this is the rule the spec singles out
     * as the one whose failure is silent.
     *
     * @param array $cases Rows with at least `id`, `subject`, `start_date`.
     * @param string $cutoff `Y-m-d`; a project started AFTER this is suppressed.
     * @return array{projects:array<int,array>, skipped_recent:int}
     */
    public static function applyStartDateSuppression(array $cases, string $cutoff): array
    {
        // D2 is applied HERE, in PHP, rather than as a WHERE clause, and that
        // is the single most important decision in this class.
        //
        // `start_date` is nullable on civicrm_case. In SQL, `start_date <=
        // '...'` is NULL for a NULL start_date, which is not TRUE, so the row
        // is DROPPED — silently, invisibly, and in exactly the direction this
        // class must never fail. A project with no start date is not a project
        // that started recently; it is a project whose start date nobody
        // recorded, and its VC still deserves to be asked.
        //
        // The dev clone of 2026-09-21 has ZERO such projects, so this is a
        // LATENT trap rather than a live one — which is precisely why it is
        // written down: a test on today's data cannot fail, and the next
        // person to "simplify" this into a WHERE clause would see every test
        // stay green.
        $projects = [];
        $skippedRecent = 0;
        foreach ($cases as $case) {
            $startDate = $case['start_date'] ?? null;
            if ($startDate && $startDate > $cutoff) {
                $skippedRecent++;
                continue;
            }
            $projects[(int) $case['id']] = [
                'case_id' => (int) $case['id'],
                'subject' => $case['subject'] ?? '',
                'start_date' => $startDate,
            ];
        }

        return ['projects' => $projects, 'skipped_recent' => $skippedRecent];
    }

    /**
     * Group eligible projects by the VC who coordinates them.
     *
     * @param array<int,array> $projects Keyed by case id.
     * @return array{by_vc:array<int,array>, without_vc:array, with_multiple_vcs:array}
     */
    public static function groupByCoordinator(array $projects): array
    {
        if (!$projects) {
            return ['by_vc' => [], 'without_vc' => [], 'with_multiple_vcs' => []];
        }

        $rows = \Civi\Api4\RelationshipCache::get(false)
            ->addSelect('case_id', 'near_contact_id')
            ->addWhere('case_id', 'IN', array_keys($projects))
            ->addWhere('near_relation:name', '=', self::COORDINATOR_RELATION)
            // `is_current`, NOT `is_active` — see COORDINATOR_RELATION's note.
            ->addWhere('is_current', '=', true)
            ->setLimit(0)
            ->execute()
            ->getArrayCopy();

        $coordinatorsByCase = [];
        foreach ($rows as $row) {
            $caseId = (int) $row['case_id'];
            $contactId = (int) $row['near_contact_id'];
            if ($caseId && $contactId) {
                // Keyed by contact id: one VC holding two coordinator rows on
                // the same case is a duplicate, not a second person.
                $coordinatorsByCase[$caseId][$contactId] = true;
            }
        }

        return self::assignProjectsToCoordinators($projects, $coordinatorsByCase);
    }

    /**
     * The grouping rule, as a pure function.
     *
     * Separated from its query for the same reason as
     * applyStartDateSuppression(): CI has no CiviCRM, and the three behaviours
     * that matter here — never drop a coordinator-less project, ask every
     * coordinator when there is more than one, collapse duplicate rows for the
     * same person — are rules, not queries.
     *
     * @param array<int,array> $projects Keyed by case id.
     * @param array<int,array<int,bool>> $coordinatorsByCase case id => contact id => TRUE.
     * @return array{by_vc:array<int,array>, without_vc:array, with_multiple_vcs:array}
     */
    public static function assignProjectsToCoordinators(array $projects, array $coordinatorsByCase): array
    {
        $byVc = [];
        $withoutVc = [];
        $withMultiple = [];

        foreach ($projects as $caseId => $project) {
            $coordinators = array_keys($coordinatorsByCase[$caseId] ?? []);

            if (!$coordinators) {
                // Goal 9. Reported, never dropped — 2 such projects on the
                // 2026-09-21 dev clone under `is_current`. (The spec's
                // production reading of 1 predates that predicate; see
                // COORDINATOR_RELATION above.)
                $withoutVc[] = $project;
                continue;
            }

            if (count($coordinators) > 1) {
                // THE SPEC DOES NOT DECIDE THIS CASE, and 4 Active projects on
                // the 2026-09-21 dev clone are in it. Every coordinator is
                // asked, because the alternative is picking one and silently
                // not asking the other — the failure this class is shaped to
                // avoid. The cost is that two VCs may each answer about one
                // project; P1-5's handleComplete() is required to be idempotent
                // anyway, so the second answer records an activity and does not
                // send a second Completion email.
                //
                // Surfaced in the summary so the office can see it rather than
                // discovering it from a confused volunteer. Flagged for Brian:
                // if MAS would rather ask only one, this is where that rule
                // goes, and it needs to say WHICH one.
                $withMultiple[] = $project + ['coordinator_ids' => $coordinators];
            }

            foreach ($coordinators as $contactId) {
                $byVc[$contactId]['vc_id'] = $contactId;
                $byVc[$contactId]['projects'][] = $project;
            }
        }

        ksort($byVc);
        return ['by_vc' => $byVc, 'without_vc' => $withoutVc, 'with_multiple_vcs' => $withMultiple];
    }

    /**
     * The digest round: `YYYY-MM` of the run.
     *
     * A month, not a date, because that is the unit the whole feature reasons
     * in — the idempotency guard, the repeat-answer history, and
     * `Monthly_Project_Checkin.digest_round` all compare rounds, and a date
     * would invite someone to express "same month" as a range.
     */
    public static function round(string $asOf): string
    {
        return date('Y-m', strtotime($asOf));
    }

    /**
     * VCs who cannot be emailed, reported rather than allowed to throw.
     *
     * LifecycleMailer::loadRecipient() throws when a contact has no primary
     * email. In a per-case send that is right — it is one rule, one case, and
     * somebody should look. In a 62-recipient loop it is not: one unmailable
     * volunteer would abort the run and the other 61 would never be asked.
     *
     * Zero on the 2026-09-21 dev clone, so this too is latent. It is here
     * because "the run died on contact 4 of 62" is the kind of thing that is
     * only obvious after it happens.
     *
     * @param int[] $vcIds
     */
    private static function unmailableVcs(array $vcIds): array
    {
        if (!$vcIds) {
            return [];
        }

        // ⚠ `is_deleted` is explicit in BOTH directions, and that is the point.
        // API4 Contact::get excludes trashed contacts by DEFAULT, so without
        // this clause a trashed coordinator could never appear here — while
        // still sitting in $byVc, because civicrm_relationship_cache rows
        // survive their contact being trashed and groupByCoordinator() filters
        // on the cache, not the contact. The VC would be counted as mailable
        // and be invisible to the one report meant to catch that. Zero such
        // contacts on the 2026-09-21 clone, so this is latent.
        $rows = \Civi\Api4\Contact::get(false)
            ->addSelect('id', 'display_name', 'do_not_email', 'is_deceased', 'is_deleted', 'email_primary.email')
            ->addWhere('id', 'IN', $vcIds)
            ->addWhere('is_deleted', 'IN', [true, false])
            ->addClause(
                'OR',
                ['email_primary.email', 'IS EMPTY'],
                ['do_not_email', '=', true],
                ['is_deceased', '=', true],
                ['is_deleted', '=', true]
            )
            ->setLimit(0)
            ->execute()
            ->getArrayCopy();

        return array_map(static function ($row) {
            if (!empty($row['is_deleted'])) {
                $reason = 'contact is in the trash';
            } elseif (empty($row['email_primary.email'])) {
                $reason = 'no primary email';
            } elseif (!empty($row['is_deceased'])) {
                $reason = 'deceased';
            } else {
                $reason = 'do_not_email';
            }
            return [
                'vc_id' => (int) $row['id'],
                'display_name' => $row['display_name'] ?? '',
                'reason' => $reason,
            ];
        }, $rows);
    }

    private static function normaliseAsOf($asOf): string
    {
        if (!$asOf) {
            return date('Y-m-d');
        }
        $time = strtotime((string) $asOf);
        if ($time === false) {
            throw new \InvalidArgumentException("as_of is not a date this system understands: " . (string) $asOf);
        }
        return date('Y-m-d', $time);
    }

    /**
     * Read the pilot list (D12).
     *
     * Public because of what it REFUSES, which is the part worth a test of its
     * own: an unparseable value must not degrade to "no pilot", because no
     * pilot means all 62 volunteers.
     *
     * @return int[]
     */
    public static function normalisePilotIds($pilot): array
    {
        if ($pilot === null || $pilot === '' || $pilot === []) {
            return [];
        }
        if (is_string($pilot)) {
            $pilot = preg_split('/\s*,\s*/', trim($pilot), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        $ids = [];
        $rejected = [];
        foreach ((array) $pilot as $value) {
            // EVERY element must be a clean positive integer id. An earlier
            // version kept whatever parsed and dropped the rest, which was the
            // stated contract's opposite in two ways review measured:
            //   '1,abc'      -> [1]   — a VC silently dropped from the pilot
            //   '12.9'       -> [12]  — a DIFFERENT VC silently substituted
            // The second is the worse kind: not an omission but a
            // misdelivery, to somebody who was never chosen. This class exists
            // to avoid silently dropping a VC; doing it in the delivery step
            // instead of the selection step is the same failure.
            //
            // `is_numeric` alone accepts '12.9', '1e3' and ' 12', so the test
            // is the string form round-tripping through (int) unchanged.
            if (is_int($value) || (is_string($value) && ctype_digit(trim($value)))) {
                $id = (int) trim((string) $value);
                if ($id > 0) {
                    $ids[] = $id;
                    continue;
                }
            }
            $rejected[] = is_scalar($value) ? (string) $value : gettype($value);
        }

        if ($rejected) {
            throw new \InvalidArgumentException(
                'pilot_vc_ids contains entries that are not contact ids: '
                . implode(', ', array_map(static fn($r) => "'{$r}'", $rejected))
                . '. Refusing the whole list rather than quietly mailing a different set of '
                . 'volunteers than the one that was chosen.'
            );
        }

        if (!$ids) {
            // Reached when the value was non-empty but produced nothing at all
            // (e.g. an empty nested array). Must not read as "no pilot", which
            // means every VC.
            throw new \InvalidArgumentException(
                'pilot_vc_ids was supplied but no contact id could be read from it. '
                . 'Refusing, because treating it as empty would mean every VC.'
            );
        }
        return array_values(array_unique($ids));
    }
}
