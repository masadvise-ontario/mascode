<?php

declare(strict_types=1);

// file: Civi/Mascode/Service/StaleServiceRequestCloser.php

namespace Civi\Mascode\Service;

/**
 * Closes Service Requests that have sat in "Request RCS" too long after a
 * reminder went out.
 *
 * Nina's decision, 2026-09-24, in Brian's words: "The system should
 * automatically close service requests in \"Request RCS\" status that have
 * been opened for more than 64 days and have had at least one reminder email
 * go out." Brian settled the three open points the same day:
 *
 *   - the 64 days run from the case's START DATE, not from the status change
 *     (the Lifecycle doc says "64 days after RCS requested"; Brian's words say
 *     "opened", and start_date is the auditable one);
 *   - the target status is "No Client Response" (value 9) — NOT either of the
 *     two statuses labelled/named "Closed" (value 15 name `closed`, value 2
 *     name `Closed` label "Resolved"). It says why the case closed;
 *   - a stale SR with NO reminder on file is SKIPPED and REPORTED, never
 *     closed. On production 2026-09-24 that was 25 of the 30 stale SRs, so the
 *     report is most of the output, and it is for Brian and Nina to act on.
 *
 * WHY A SWEEP AND NOT A CIVIRULE
 * ---------------------------------------------------------------------------
 * CiviRules fires on a status CHANGE. The backlog is already sitting in the
 * status, so no trigger will ever fire for it: a rule would close none of the
 * 30 stale SRs on production. A sweep reads the current state instead.
 *
 * THE FAILURE MODE THIS CLASS IS SHAPED AROUND
 * ---------------------------------------------------------------------------
 * The opposite asymmetry to VcDigestRunner. There, a wrongly excluded row is
 * the invisible failure. Here the write is the destructive part: a case
 * wrongly CLOSED drops a live client out of every open-case view, and nobody
 * is looking for it. A case wrongly SKIPPED stays in "Request RCS" and appears
 * in the report. So wherever the rule is ambiguous, this class SKIPS and
 * REPORTS:
 *   - no start date → skipped (reported), never treated as old;
 *   - a DRAFTED reminder ("Draft Email - Needs Review") does NOT count — it
 *     may never have been sent;
 *   - a reminder whose marker comment was stripped does not count either (see
 *     CHASE_TEMPLATE). That under-counts, which is the safe direction.
 *
 * Dry run is the default, and the first live run should pass `case_ids` — the
 * list Brian approved from the dry run — so the sweep closes exactly those and
 * not a case that crossed 64 days overnight.
 */
final class StaleServiceRequestCloser
{
    public const CASE_TYPE = 'service_request';

    public const FROM_STATUS = 'Request RCS';

    /** Brian, 2026-09-24. Value 9, grouping Closed. Matched by NAME. */
    public const TO_STATUS = 'No Client Response';

    /** "More than 64 days": a case exactly 64 days old is NOT closed. */
    public const MIN_AGE_DAYS = 64;

    /**
     * The reminder that counts.
     *
     * Matched against the `<!--mas-lifecycle {json} -->` marker
     * LifecycleMailer::createActivity() writes into `details`, exactly as
     * LifecycleMailer::findDuplicate() does — not by subject, which a template
     * edit changes. The JSON-encoded title ends in a quote, so the LIKE cannot
     * prefix-match a longer title.
     *
     * ⚠ A reminder can be SENT and still carry no title. sendDraft() reads the
     * title back out of the draft's marker, and HTML Purifier strips HTML
     * comments when a draft is edited in the activity form — so an edited,
     * then-sent draft yields a "Sent Automated Email" with an empty title. The
     * rcs_chase rules are auto mode (no draft) today, so this cannot happen for
     * them yet. If they are ever switched to propose mode, it will, and those
     * cases will be SKIPPED rather than closed.
     */
    public const CHASE_TEMPLATE = 'mas_lifecycle_rcs_chase__client';

    /** Actually sent. "Draft Email - Needs Review" deliberately excluded. */
    public const CHASE_ACTIVITY_TYPE = LifecycleMailer::TYPE_SENT;

    /**
     * Plan (and, unless dry_run, carry out) one sweep.
     *
     * @param array $params
     *   - as_of (string Y-m-d, default today) — the date ages are reckoned from.
     *   - dry_run (bool, default TRUE) — plan only.
     *   - case_ids (int[]|string CSV, optional) — restrict the CLOSE to these
     *     cases. They must still qualify; a listed case that no longer does is
     *     reported in `not_eligible`, not closed. Restricts only what is closed,
     *     never what is reported.
     *
     * @return array{
     *   as_of:string, dry_run:bool, case_ids:int[],
     *   in_status:int, to_close:array, closed:array,
     *   stale_without_reminder:array, no_start_date:array,
     *   not_yet_stale:int, not_eligible:int[], errors:array
     * }
     */
    public static function run(array $params = []): array
    {
        $asOf = self::normaliseAsOf($params['as_of'] ?? null);
        $dryRun = !array_key_exists('dry_run', $params) || (bool) $params['dry_run'];
        $caseIds = self::normaliseCaseIds($params['case_ids'] ?? null);

        $cases = self::fetchCasesInStatus();
        $chases = self::fetchLatestChases(array_column($cases, 'id'));
        $plan = self::classify($cases, $chases, $asOf);

        $notEligible = [];
        $toClose = $plan['to_close'];
        if ($caseIds) {
            $eligibleIds = array_column($toClose, 'case_id');
            $notEligible = array_values(array_diff($caseIds, $eligibleIds));
            $toClose = array_values(array_filter(
                $toClose,
                static fn(array $c) => in_array($c['case_id'], $caseIds, true)
            ));
        }

        $summary = [
            'as_of' => $asOf,
            'dry_run' => $dryRun,
            'case_ids' => $caseIds,
            'in_status' => count($cases),
            'to_close' => $toClose,
            'closed' => [],
            'stale_without_reminder' => $plan['stale_without_reminder'],
            'no_start_date' => $plan['no_start_date'],
            'not_yet_stale' => $plan['not_yet_stale'],
            'not_eligible' => $notEligible,
            'errors' => [],
        ];

        if (!$dryRun) {
            foreach ($toClose as $case) {
                try {
                    if (self::closeCase($case, $asOf)) {
                        $summary['closed'][] = $case['case_id'];
                    } else {
                        $summary['not_eligible'][] = $case['case_id'];
                    }
                } catch (\Throwable $e) {
                    // One bad case must not stop the rest; each close is
                    // independent and already in its own transaction.
                    $summary['errors'][] = ['case_id' => $case['case_id'], 'error' => $e->getMessage()];
                }
            }
        }

        \Civi::log()->info('StaleServiceRequestCloser.php - Sweep of stale Request RCS service requests', [
            'as_of' => $asOf,
            'dry_run' => $dryRun,
            'in_status' => $summary['in_status'],
            'to_close' => count($toClose),
            'closed' => count($summary['closed']),
            'stale_without_reminder' => count($summary['stale_without_reminder']),
            'no_start_date' => count($summary['no_start_date']),
            'errors' => count($summary['errors']),
        ]);

        return $summary;
    }

    // --- rules (pure; unit-tested) -----------------------------------------

    /**
     * Whole days from start_date to as_of, or NULL when there is no start date.
     */
    public static function ageInDays(?string $startDate, string $asOf): ?int
    {
        if ($startDate === null || trim($startDate) === '') {
            return null;
        }
        $start = new \DateTimeImmutable(substr($startDate, 0, 10));
        $end = new \DateTimeImmutable($asOf);
        return (int) $start->diff($end)->format('%r%a');
    }

    /**
     * Sort the cases currently in "Request RCS" into the buckets the report
     * shows.
     *
     * @param array $cases  rows keyed `id`, `subject`, `start_date`
     * @param array $chases latest sent chase date keyed by case id
     */
    public static function classify(array $cases, array $chases, string $asOf): array
    {
        $out = ['to_close' => [], 'stale_without_reminder' => [], 'no_start_date' => [], 'not_yet_stale' => 0];

        foreach ($cases as $case) {
            $id = (int) $case['id'];
            $age = self::ageInDays($case['start_date'] ?? null, $asOf);
            $row = [
                'case_id' => $id,
                'subject' => $case['subject'] ?? '',
                'start_date' => $case['start_date'] ?? null,
                'age_days' => $age,
                'last_reminder' => $chases[$id] ?? null,
            ];

            if ($age === null) {
                $out['no_start_date'][] = $row;
            } elseif ($age <= self::MIN_AGE_DAYS) {
                $out['not_yet_stale']++;
            } elseif ($row['last_reminder'] === null) {
                $out['stale_without_reminder'][] = $row;
            } else {
                $out['to_close'][] = $row;
            }
        }

        // Oldest first, so the report reads as a backlog.
        foreach (['to_close', 'stale_without_reminder'] as $bucket) {
            usort($out[$bucket], static fn($a, $b) => $b['age_days'] <=> $a['age_days'] ?: $a['case_id'] <=> $b['case_id']);
        }

        return $out;
    }

    /**
     * @return int[] unique positive ids; empty means "no restriction".
     */
    public static function normaliseCaseIds($value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }
        $parts = is_array($value) ? $value : explode(',', (string) $value);
        $ids = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            // REFUSED, not dropped: an unreadable list would otherwise shrink
            // to empty, and empty means "every eligible case".
            if (!ctype_digit($part) || (int) $part < 1) {
                throw new \InvalidArgumentException("case_ids: '{$part}' is not a case id");
            }
            $ids[] = (int) $part;
        }
        return array_values(array_unique($ids));
    }

    public static function normaliseAsOf(?string $asOf): string
    {
        if ($asOf === null || $asOf === '') {
            return date('Y-m-d');
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $asOf);
        if (!$d || $d->format('Y-m-d') !== $asOf) {
            throw new \InvalidArgumentException("as_of must be Y-m-d, got '{$asOf}'");
        }
        return $asOf;
    }

    // --- queries and writes ------------------------------------------------

    private static function fetchCasesInStatus(): array
    {
        return (array) \Civi\Api4\CiviCase::get(false)
            ->addSelect('id', 'subject', 'start_date')
            ->addWhere('case_type_id:name', '=', self::CASE_TYPE)
            ->addWhere('status_id:name', '=', self::FROM_STATUS)
            ->addWhere('is_deleted', '=', false)
            ->addOrderBy('id')
            ->execute()
            ->getArrayCopy();
    }

    /**
     * Latest SENT chase per case.
     *
     * @return array<int,string> case id => activity_date_time
     */
    private static function fetchLatestChases(array $caseIds): array
    {
        if (!$caseIds) {
            return [];
        }
        $rows = \Civi\Api4\Activity::get(false)
            ->addSelect('case_id', 'activity_date_time')
            ->addWhere('case_id', 'IN', $caseIds)
            ->addWhere('activity_type_id:name', '=', self::CHASE_ACTIVITY_TYPE)
            ->addWhere('details', 'LIKE', '%"template_title":' . json_encode(self::CHASE_TEMPLATE) . '%')
            ->addWhere('is_deleted', '=', false)
            ->execute();

        $latest = [];
        foreach ($rows as $row) {
            // case_id comes back as an array when an activity is filed on
            // more than one case; credit each of them.
            foreach ((array) $row['case_id'] as $caseId) {
                $caseId = (int) $caseId;
                if (!in_array($caseId, $caseIds, true)) {
                    continue;
                }
                if (!isset($latest[$caseId]) || $row['activity_date_time'] > $latest[$caseId]) {
                    $latest[$caseId] = $row['activity_date_time'];
                }
            }
        }
        return $latest;
    }

    /**
     * Close one case, re-checking it at write time.
     *
     * @return bool FALSE if the case moved on since the plan was made.
     */
    private static function closeCase(array $case, string $asOf): bool
    {
        $caseId = $case['case_id'];
        $tx = new \CRM_Core_Transaction();
        try {
            // The plan may be minutes (or, with case_ids from an approved dry
            // run, days) old. Someone may have moved the case since.
            $current = \Civi\Api4\CiviCase::get(false)
                ->addSelect('status_id:name', 'is_deleted')
                ->addWhere('id', '=', $caseId)
                ->execute()
                ->first();
            if (!$current || $current['is_deleted'] || $current['status_id:name'] !== self::FROM_STATUS) {
                $tx->commit();
                return false;
            }

            \Civi\Api4\CiviCase::update(false)
                ->addWhere('id', '=', $caseId)
                ->addValue('status_id:name', self::TO_STATUS)
                // Core sets end_date only for status name 'Closed' (value 2,
                // "Resolved") — CRM_Case_BAO_Case::create(). Without this, the
                // case would be closed with no end date.
                ->addValue('end_date', date('Y-m-d'))
                ->execute();

            // The audit trail a manual close from Manage Case leaves.
            $sourceId = (int) \CRM_Core_Session::getLoggedInContactID()
                ?: (int) \Civi::settings()->get('mascode_admin_contact_id');
            \Civi\Api4\Activity::create(false)
                ->addValue('activity_type_id:name', 'Change Case Status')
                ->addValue('status_id:name', 'Completed')
                ->addValue('case_id', $caseId)
                ->addValue('source_contact_id', $sourceId)
                ->addValue('subject', 'Case status changed from ' . self::FROM_STATUS . ' to ' . self::TO_STATUS)
                ->addValue('details', sprintf(
                    'Closed automatically: in %s for %d days since the case opened (more than %d), and the RCS reminder was last sent %s. Mascode.closeStaleServiceRequests, as of %s.',
                    self::FROM_STATUS,
                    $case['age_days'],
                    self::MIN_AGE_DAYS,
                    $case['last_reminder'],
                    $asOf
                ))
                ->execute();

            $tx->commit();
            return true;
        } catch (\Throwable $e) {
            $tx->rollback();
            throw $e;
        }
    }
}
