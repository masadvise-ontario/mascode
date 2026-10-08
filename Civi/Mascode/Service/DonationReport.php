<?php

declare(strict_types=1);

namespace Civi\Mascode\Service;

use Civi\Mascode\Event\ProjectLifecycleStatusSubscriber;
use Civi\Mascode\Util\CaseStatusSet;

/**
 * The Treasurer's quarterly donations report, computed from CiviCRM
 * (donations ticket DN-4; spec BrianPKM 3-Resources/mas-donation-process.md
 * §3d).
 *
 * It reproduces sheet 2 of his "Quarterly Donations report" workbook (last run
 * 2022-12-31): donations summarised by the quarter in which the PROJECT CLOSED,
 * not the quarter the money arrived. Per quarter:
 *   completed projects · projects with a donation · donation % · rolling 4Q % ·
 *   total donated · average per donation · average per completed project ·
 *   rolling 4Q average per completed project
 * plus his footnotes, as lists: donations on projects not yet closed, and on
 * projects closed but not completed.
 *
 * Definitions, each a decision rather than an accident:
 *  - "Completed" (R6, spec §7) = a Project now in Awaiting VC Project
 *    Completion Form, Awaiting Client Project Signoff Form or Completed
 *    (COMPLETED_STATUSES). Its date is when it ENTERED the first of these
 *    (completedOn()), the earliest of: a core "Change Case Status" activity
 *    into one of them (a status change made on the case screen); the
 *    lifecycle email whose sending moved it into one of them
 *    (ProjectLifecycleStatusSubscriber, whose API write logs no such
 *    activity and sets no end date); its end_date. The end_date covers
 *    history: the 2010-2022 projects predate both activities.
 *  - A quarter is provisional while it is open, ended under PROVISIONAL_DAYS
 *    ago (donations arrive after the project closes), or cut short by the
 *    report's end date.
 *  - CAF Donation (R7): counted in the quarter's total, by the quarter it was
 *    RECEIVED (it has no project), and never as a donation: not in "with
 *    donation", the % or the averages.
 *  - A project "has a donation" when any live donation links to it via
 *    Donation_Link.Linked_Project. Several donations to one project count once
 *    in the % and are summed in the total, as the workbook did.
 *  - One cheque, several projects (R10): its net is split EVENLY across the
 *    projects it links (Brian, 2026-10-08), so each counts as having a
 *    donation. A share that falls on a link that is not a live Project (only
 *    possible by API) is in no row, as a whole donation on such a link was
 *    before R10.
 *  - Amounts are NET (spec §4 Q12: the board sees net).
 *  - Live = not test, and status Completed, Pending, In Progress or Partially
 *    paid. A cheque entered Pending was received; it simply is not banked yet.
 *  - Rolling 4Q = the four quarters ENDING with the row's quarter. The workbook's
 *    rolling column appears to lag one quarter; confirm with the Treasurer.
 *
 * Linked-donation data only starts with what DN-5 could backfill (about 2025),
 * and R4 (2026-10-08) loaded the Treasurer's workbook history back to 2010.
 * About 50 workbook donations have no gift in CiviCRM, so those quarters
 * read slightly low. The page says so.
 */
final class DonationReport
{
    public const LIVE_STATUSES = ['Completed', 'Pending', 'In Progress', 'Partially paid'];
    public const DEFAULT_FROM = '2025-01-01';

    /** R6: a project counts as completed in any of these (machine names). */
    public const COMPLETED_STATUSES = ['Awaiting VC Project Close Form', 'Awaiting Client Project Close Form', 'Completed'];

    /**
     * Subjects the two close-path lifecycle templates used before they were
     * renamed (2026-09), and the status sending them moved a case into. The
     * subscriber matches only the CURRENT subjects, but a project moved by an
     * email sent before the rename is dated by that email: it has no
     * status-change activity and no end date. History only; never extend this
     * for a live template (ProjectLifecycleStatusSubscriber owns those).
     */
    public const FORMER_TRANSITION_SUBJECTS = [
        'Project Close - VC' => 'Awaiting VC Project Close Form',
        'MAS Project Close - Client' => 'Awaiting Client Project Close Form',
    ];

    /** The latest quarter stays provisional until this long after it ends. */
    public const PROVISIONAL_DAYS = 90;

    /** A wider range is refused, rather than looping through centuries of quarters. */
    public const MAX_QUARTERS = 100;

    /**
     * @return array{from:string, to:string, quarters:array<int,array>,
     *   open_project_donations:array, not_completed_donations:array,
     *   completed_without_close_date:string[], caf_total:float,
     *   unlinked_client_donations:array{count:int,net:float}}
     */
    public static function quarterly(?string $from = null, ?string $to = null): array
    {
        $from = self::date($from) ?? self::DEFAULT_FROM;
        $to = self::date($to) ?? date('Y-m-d');

        [$completedOn, $undated] = self::completedProjects();

        $netByProject = self::splitEvenly(self::linkedDonations());

        // One row per quarter in range, empty quarters included, so the
        // rolling window counts calendar quarters rather than non-empty ones.
        $rows = [];
        foreach (self::quartersBetween($from, $to) as $q) {
            $rows[$q] = ['quarter' => $q, 'completed' => 0, 'with_donation' => 0, 'total' => 0.0, 'caf' => 0.0];
        }
        foreach ($completedOn as $pid => $date) {
            if ($date < $from || $date > $to) {
                continue;
            }
            $q = self::quarterOf($date);
            if (!isset($rows[$q])) {
                continue;
            }
            $rows[$q]['completed']++;
            if (isset($netByProject[$pid])) {
                $rows[$q]['with_donation']++;
                $rows[$q]['total'] += $netByProject[$pid];
            }
        }

        foreach (self::cafDonations($from, $to) as $c) {
            $q = self::quarterOf((string) $c['receive_date']);
            if (isset($rows[$q])) {
                $rows[$q]['caf'] += (float) $c['net_amount'];
            }
        }

        return [
            'from' => $from,
            'to' => $to,
            'quarters' => self::markProvisional(self::summarise(array_values($rows)), date('Y-m-d'), $to),
            'caf_total' => array_sum(array_column($rows, 'caf')),
            // Status classes come from the Project CaseType definition, not a list
            // kept here. "Not yet completed" is NOT IN the closed class or the
            // completed set rather than IN the opened one, so a project left in a
            // status outside the definition (a legacy value still in the option
            // group) still shows instead of vanishing.
            'open_project_donations' => self::donationsOnProjects(['NOT IN', array_values(array_unique(array_merge(CaseStatusSet::names('project', 'Closed'), self::COMPLETED_STATUSES)))]),
            'not_completed_donations' => self::donationsOnProjects(['IN', array_values(array_diff(CaseStatusSet::names('project', 'Closed'), ['Completed']))]),
            'completed_without_close_date' => $undated,
            'unlinked_client_donations' => self::unlinkedClientDonations($from, $to),
        ];
    }

    /**
     * Add the derived columns to per-quarter base counts. Pure, so the rolling
     * window is pinned by DonationRulesTest.
     *
     * @param array<int,array{quarter:string,completed:int,with_donation:int,total:float}> $rows
     *   consecutive calendar quarters, oldest first
     */
    public static function summarise(array $rows): array
    {
        foreach ($rows as $i => &$r) {
            $r['total_with_caf'] = $r['total'] + ($r['caf'] ?? 0.0);
            $r['pct'] = $r['completed'] ? $r['with_donation'] / $r['completed'] : null;
            $r['avg_per_donation'] = $r['with_donation'] ? $r['total'] / $r['with_donation'] : null;
            $r['avg_per_completed'] = $r['completed'] ? $r['total'] / $r['completed'] : null;
            $r['rolling_pct'] = null;
            $r['rolling_avg_per_completed'] = null;
            if ($i >= 3) {
                $window = array_slice($rows, $i - 3, 4);
                $c = array_sum(array_column($window, 'completed'));
                $w = array_sum(array_column($window, 'with_donation'));
                $t = array_sum(array_column($window, 'total'));
                $r['rolling_pct'] = $c ? $w / $c : null;
                $r['rolling_avg_per_completed'] = $c ? $t / $c : null;
            }
        }
        unset($r);
        return $rows;
    }

    /**
     * Mark a row provisional when its quarter has not ended, ended under
     * PROVISIONAL_DAYS before $today (donations arrive after the project
     * closes, so its numbers are still growing), or ends after the report's
     * own end date $to (a partial quarter). Pure, so DonationRulesTest pins it.
     *
     * @param array<int,array> $rows summarise() output, oldest first
     */
    public static function markProvisional(array $rows, string $today, string $to): array
    {
        $cutoff = date('Y-m-d', strtotime($today . ' -' . self::PROVISIONAL_DAYS . ' days'));
        foreach ($rows as &$r) {
            [$y, $q] = sscanf((string) $r['quarter'], '%d Q%d');
            $end = date('Y-m-d', mktime(0, 0, 0, $q * 3 + 1, 0, $y));
            $r['provisional'] = $end >= $cutoff || $end > $to;
        }
        unset($r);
        return $rows;
    }

    /**
     * Every Project now in COMPLETED_STATUSES with the date it became completed
     * (completedOn()), and the ones with no date at all, which cannot be placed
     * in a quarter and are listed rather than silently dropped.
     *
     * @return array{0:array<int,string>,1:string[]} [project id => Y-m-d, "Pxxxxx subject" labels]
     */
    private static function completedProjects(): array
    {
        $projects = \Civi\Api4\CiviCase::get(false)
            ->addSelect('id', 'end_date', 'subject', 'Projects.MAS_Project_Case_Code')
            ->addWhere('case_type_id:name', '=', 'project')
            ->addWhere('is_deleted', '=', false)
            ->addWhere('status_id:name', 'IN', self::COMPLETED_STATUSES)
            ->addOrderBy('id')
            ->execute()
            ->indexBy('id')
            ->getArrayCopy();
        // Core writes the subject with the status LABELS current at the time;
        // accept names too. Earlier labels of these statuses equalled their
        // names until 2026-09-21, so both spellings in use are covered.
        $targets = [];
        foreach (\Civi\Api4\OptionValue::get(false)
            ->addSelect('name', 'label')
            ->addWhere('option_group_id:name', '=', 'case_status')
            ->addWhere('name', 'IN', self::COMPLETED_STATUSES)
            ->execute() as $o) {
            $targets[] = $o['name'];
            $targets[] = $o['label'];
        }
        $changes = [];
        foreach (array_chunk(array_keys($projects), 500) as $chunk) {
            foreach (\Civi\Api4\Activity::get(false)
                ->addSelect('case_id', 'subject', 'activity_date_time')
                ->addWhere('case_id', 'IN', $chunk)
                ->addWhere('activity_type_id:name', '=', 'Change Case Status')
                ->addWhere('is_deleted', '=', false)
                ->addWhere('is_current_revision', '=', true)
                ->execute() as $a) {
                foreach ((array) $a['case_id'] as $cid) {
                    if (isset($projects[$cid])) {
                        $changes[$cid][] = [(string) $a['subject'], (string) $a['activity_date_time']];
                    }
                }
            }
        }
        $lifecycle = self::lifecycleEntryDates(array_keys($projects));
        $dated = [];
        $undated = [];
        foreach ($projects as $pid => $p) {
            $d = self::completedOn($changes[$pid] ?? [], $p['end_date'] ?? null, $targets, $lifecycle[$pid] ?? []);
            if ($d === null) {
                $undated[] = trim(($p['Projects.MAS_Project_Case_Code'] ?? '') . ' ' . ($p['subject'] ?? ''));
            }
            else {
                $dated[$pid] = $d;
            }
        }
        return [$dated, $undated];
    }

    /**
     * Per project, the dates of the lifecycle emails whose sending moves a
     * case INTO a completed status (ProjectLifecycleStatusSubscriber::
     * matchTransition(), the same rule that made the move), or that did so
     * under a former subject (FORMER_TRANSITION_SUBJECTS). Only emails the
     * subscriber acts on count: Email and Sent Automated Email, completed.
     *
     * @param int[] $projectIds
     * @return array<int,string[]> project id => Y-m-d dates
     */
    private static function lifecycleEntryDates(array $projectIds): array
    {
        $prefixes = [];
        foreach (ProjectLifecycleStatusSubscriber::transitionSubjectPrefixes() as $prefix) {
            $t = ProjectLifecycleStatusSubscriber::matchTransition($prefix);
            if ($prefix !== '' && $t && in_array($t['to'], self::COMPLETED_STATUSES, true)) {
                $prefixes[] = $prefix;
            }
        }
        $prefixes = array_merge($prefixes, array_keys(self::FORMER_TRANSITION_SUBJECTS));
        $out = [];
        $wanted = array_flip($projectIds);
        foreach (array_chunk($projectIds, 500) as $chunk) {
            $q = \Civi\Api4\Activity::get(false)
                ->addSelect('case_id', 'subject', 'activity_date_time')
                ->addWhere('case_id', 'IN', $chunk)
                ->addWhere('activity_type_id:name', 'IN', ['Email', LifecycleMailer::TYPE_SENT])
                ->addWhere('status_id:name', '=', 'Completed')
                ->addWhere('is_deleted', '=', false)
                ->addWhere('is_current_revision', '=', true)
                ->addClause('OR', ...array_map(static fn($p) => ['subject', 'CONTAINS', $p], $prefixes));
            foreach ($q->execute() as $a) {
                if (!self::movesIntoCompleted((string) $a['subject'])) {
                    continue;
                }
                foreach ((array) $a['case_id'] as $cid) {
                    if (isset($wanted[$cid])) {
                        $out[$cid][] = substr((string) $a['activity_date_time'], 0, 10);
                    }
                }
            }
        }
        return $out;
    }

    /** Whether sending an email with this subject moved (or once moved) a case into a completed status. */
    public static function movesIntoCompleted(string $subject): bool
    {
        $t = ProjectLifecycleStatusSubscriber::matchTransition($subject);
        if ($t) {
            return in_array($t['to'], self::COMPLETED_STATUSES, true);
        }
        foreach (self::FORMER_TRANSITION_SUBJECTS as $former => $to) {
            if (str_contains($subject, $former)) {
                return in_array($to, self::COMPLETED_STATUSES, true);
            }
        }
        return false;
    }

    /**
     * When a project became completed: the earliest "Case status changed from
     * X to Y" whose Y is a completed status, lifecycle email that moved it
     * into one ($lifecycleDates), or its end_date (a backdated close; history
     * from before these activities). The
     * subject is split on its LAST " to ", so "from Awaiting VC Project
     * Completion Form to Active" does not count. Pure, so DonationRulesTest pins it.
     *
     * @param array<int,array{0:string,1:string}> $changes [subject, activity_date_time]
     * @param string[] $targets completed statuses' names and labels
     * @param string[] $lifecycleDates Y-m-d
     * @return string|null Y-m-d
     */
    public static function completedOn(array $changes, ?string $endDate, array $targets, array $lifecycleDates = []): ?string
    {
        $dates = array_values(array_filter($lifecycleDates));
        foreach ($changes as [$subject, $when]) {
            $at = strrpos($subject, ' to ');
            if ($at !== false && in_array(trim(substr($subject, $at + 4)), $targets, true) && $when !== '') {
                $dates[] = substr($when, 0, 10);
            }
        }
        if ($endDate) {
            $dates[] = substr($endDate, 0, 10);
        }
        return $dates ? min($dates) : null;
    }

    /**
     * Net per project, each donation's net split evenly across the projects
     * it links (R10). Pure, so DonationRulesTest pins it.
     *
     * @param array<int,array> $donations rows with net_amount and Linked_Project
     * @return array<int,float> project id => net
     */
    public static function splitEvenly(array $donations): array
    {
        $out = [];
        foreach ($donations as $d) {
            $ids = DonationLinker::ids($d[DonationLinker::FIELD_PROJECT] ?? null);
            foreach ($ids as $pid) {
                $out[$pid] = ($out[$pid] ?? 0.0) + (float) $d['net_amount'] / count($ids);
            }
        }
        return $out;
    }

    /** Every live donation that links to a project. */
    private static function linkedDonations(): array
    {
        return \Civi\Api4\Contribution::get(false)
            ->addSelect('id', 'net_amount', DonationLinker::FIELD_PROJECT)
            ->addWhere('is_test', '=', false)
            ->addWhere('financial_type_id:name', 'IN', DonationLinker::DONATION_TYPES)
            ->addWhere('contribution_status_id:name', 'IN', self::LIVE_STATUSES)
            ->addWhere(DonationLinker::FIELD_PROJECT, 'IS NOT EMPTY')
            ->execute()
            ->getArrayCopy();
    }

    /**
     * Live donations whose project status matches, for the footnote lists.
     * One row per project, with the code and its share of the donations
     * (splitEvenly()). The projects are read separately: APIv4 cannot join a
     * case's custom fields through the serialized Linked_Project.
     *
     * @param array{0:string,1:string[]} $statusClause operator and status names
     */
    private static function donationsOnProjects(array $statusClause): array
    {
        $p = DonationLinker::FIELD_PROJECT;
        $donations = \Civi\Api4\Contribution::get(false)
            ->addSelect($p, 'net_amount', 'receive_date')
            ->addWhere('is_test', '=', false)
            ->addWhere('financial_type_id:name', 'IN', DonationLinker::DONATION_TYPES)
            ->addWhere('contribution_status_id:name', 'IN', self::LIVE_STATUSES)
            ->addWhere($p, 'IS NOT EMPTY')
            ->addOrderBy('receive_date')
            ->execute()
            ->getArrayCopy();
        $ids = [];
        foreach ($donations as $d) {
            array_push($ids, ...DonationLinker::ids($d[$p] ?? null));
        }
        if (!$ids) {
            return [];
        }
        $cases = \Civi\Api4\CiviCase::get(false)
            ->addSelect('id', 'subject', 'Projects.MAS_Project_Case_Code', 'status_id:label')
            ->addWhere('id', 'IN', array_values(array_unique($ids)))
            ->addWhere('case_type_id:name', '=', 'project')
            ->addWhere('is_deleted', '=', false)
            ->addWhere('status_id:name', $statusClause[0], $statusClause[1])
            ->execute()
            ->indexBy('id');
        $out = [];
        foreach ($donations as $d) {
            $links = DonationLinker::ids($d[$p] ?? null);
            foreach ($links as $pid) {
                $c = $cases[$pid] ?? null;
                if (!$c) {
                    continue;
                }
                // Lead with the case code: pre-2024 subjects omit it.
                $label = trim(DonationLinker::codeLabel($c['Projects.MAS_Project_Case_Code'] ?? null, $c['subject'] ?? null, $pid) . ' ' . ($c['subject'] ?? ''));
                $out[$pid] ??= ['project' => $label, 'status' => (string) $c['status_id:label'], 'net' => 0.0, 'first_received' => substr((string) $d['receive_date'], 0, 10)];
                $out[$pid]['net'] += (float) $d['net_amount'] / count($links);
            }
        }
        return array_values($out);
    }

    /** Live CAF Donation gifts received in range (R7: in totals, not a donation). */
    private static function cafDonations(string $from, string $to): array
    {
        return \Civi\Api4\Contribution::get(false)
            ->addSelect('net_amount', 'receive_date')
            ->addWhere('is_test', '=', false)
            ->addWhere('financial_type_id:name', '=', 'CAF Donation')
            ->addWhere('contribution_status_id:name', 'IN', self::LIVE_STATUSES)
            ->addWhere('receive_date', 'BETWEEN', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->execute()
            ->getArrayCopy();
    }

    /**
     * Client donations received in range with no project link: the gap the CSM
     * closes by setting Linked Project. A legacy "Donation" counts as client
     * when the donor is an Organization (history is not re-typed; see
     * FinancialType_Donations.mgd.php).
     *
     * @return array{count:int, net:float}
     */
    private static function unlinkedClientDonations(string $from, string $to): array
    {
        $rows = \Civi\Api4\Contribution::get(false)
            ->addSelect('net_amount')
            ->addWhere('is_test', '=', false)
            ->addWhere('contribution_status_id:name', 'IN', self::LIVE_STATUSES)
            ->addWhere('receive_date', 'BETWEEN', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->addWhere(DonationLinker::FIELD_PROJECT, 'IS EMPTY')
            ->addClause('OR',
                ['financial_type_id:name', '=', 'Client Donation'],
                ['AND', [['financial_type_id:name', '=', 'Donation'], ['contact_id.contact_type', '=', 'Organization']]]
            )
            ->execute();
        return ['count' => $rows->count(), 'net' => array_sum(array_map('floatval', array_column($rows->getArrayCopy(), 'net_amount')))];
    }

    /** "2026-05-21" → "2026 Q2". */
    public static function quarterOf(string $date): string
    {
        $ts = strtotime($date);
        return date('Y', $ts) . ' Q' . (int) ceil(((int) date('n', $ts)) / 3);
    }

    /** @return string[] every quarter label from $from's quarter to $to's, inclusive */
    public static function quartersBetween(string $from, string $to): array
    {
        $y = (int) substr($from, 0, 4);
        $q = (int) ceil(((int) substr($from, 5, 2)) / 3);
        $endY = (int) substr($to, 0, 4);
        $endQ = (int) ceil(((int) substr($to, 5, 2)) / 3);
        $out = [];
        if (($endY - $y) * 4 + ($endQ - $q) + 1 > self::MAX_QUARTERS) {
            throw new \InvalidArgumentException('Date range too wide: at most ' . self::MAX_QUARTERS . ' quarters');
        }
        while ($y < $endY || ($y === $endY && $q <= $endQ)) {
            $out[] = "$y Q$q";
            if (++$q > 4) {
                $q = 1;
                $y++;
            }
        }
        return $out;
    }

    private static function date(?string $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $d = \DateTime::createFromFormat('!Y-m-d', $v);
        if (!$d || $d->format('Y-m-d') !== $v) {
            throw new \InvalidArgumentException("Expected a Y-m-d date, got '$v'");
        }
        return $v;
    }
}
