<?php

declare(strict_types=1);

namespace Civi\Mascode\Service;

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
 *  - "Completed" = Project case with status Completed and an end_date in range.
 *    Its quarter is the end_date's quarter (spec §4 Q9: the client signoff
 *    closes the project; TBC).
 *  - A project "has a donation" when any live donation links to it via
 *    Donation_Link.Linked_Project. Several donations to one project count once
 *    in the % and are summed in the total, as the workbook did.
 *  - One cheque, several projects (R10): its net is split EVENLY across the
 *    projects it links (Brian, 2026-10-08), so each counts as having a
 *    donation and the quarter totals still add up to the money received.
 *  - Amounts are NET (spec §4 Q12: the board sees net).
 *  - Live = not test, and status Completed, Pending, In Progress or Partially
 *    paid. A cheque entered Pending was received; it simply is not banked yet.
 *  - Rolling 4Q = the four quarters ENDING with the row's quarter. The workbook's
 *    rolling column appears to lag one quarter; confirm with the Treasurer.
 *
 * Linked-donation data only starts with what DN-5 could backfill (about 2025),
 * so earlier quarters understate donations until DN-9 loads the workbook's
 * history. The page says so.
 */
final class DonationReport
{
    public const LIVE_STATUSES = ['Completed', 'Pending', 'In Progress', 'Partially paid'];
    public const DEFAULT_FROM = '2025-01-01';

    /** A wider range is refused, rather than looping through centuries of quarters. */
    public const MAX_QUARTERS = 100;

    /**
     * @return array{from:string, to:string, quarters:array<int,array>,
     *   open_project_donations:array, not_completed_donations:array,
     *   completed_without_close_date:string[],
     *   unlinked_client_donations:array{count:int,net:float}}
     */
    public static function quarterly(?string $from = null, ?string $to = null): array
    {
        $from = self::date($from) ?? self::DEFAULT_FROM;
        $to = self::date($to) ?? date('Y-m-d');

        $projects = \Civi\Api4\CiviCase::get(false)
            ->addSelect('id', 'end_date', 'status_id:name')
            ->addWhere('case_type_id:name', '=', 'project')
            ->addWhere('is_deleted', '=', false)
            ->addWhere('status_id:name', '=', 'Completed')
            ->addWhere('end_date', 'BETWEEN', [$from, $to])
            ->execute()
            ->indexBy('id')
            ->getArrayCopy();

        $netByProject = self::splitEvenly(self::linkedDonations());

        // One row per quarter in range, empty quarters included, so the
        // rolling window counts calendar quarters rather than non-empty ones.
        $rows = [];
        foreach (self::quartersBetween($from, $to) as $q) {
            $rows[$q] = ['quarter' => $q, 'completed' => 0, 'with_donation' => 0, 'total' => 0.0];
        }
        foreach ($projects as $pid => $p) {
            $q = self::quarterOf((string) $p['end_date']);
            if (!isset($rows[$q])) {
                continue;
            }
            $rows[$q]['completed']++;
            if (isset($netByProject[$pid])) {
                $rows[$q]['with_donation']++;
                $rows[$q]['total'] += $netByProject[$pid];
            }
        }

        return [
            'from' => $from,
            'to' => $to,
            'quarters' => self::summarise(array_values($rows)),
            // Status classes come from the Project CaseType definition, not a list
            // kept here. "Open" is NOT IN the closed class rather than IN the opened
            // one, so a project left in a status outside the definition (a legacy
            // value still in the option group) still shows instead of vanishing.
            'open_project_donations' => self::donationsOnProjects(['NOT IN', CaseStatusSet::names('project', 'Closed')]),
            'not_completed_donations' => self::donationsOnProjects(['IN', array_values(array_diff(CaseStatusSet::names('project', 'Closed'), ['Completed']))]),
            'completed_without_close_date' => self::completedWithoutCloseDate(),
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
     * Completed Project cases with NO end_date. They cannot be placed in a
     * quarter, so they are listed rather than silently dropped (10 on the
     * 2026-09-21 dev clone). Setting the case's end date fixes each one.
     *
     * @return string[] "Pxxxxx subject" labels
     */
    private static function completedWithoutCloseDate(): array
    {
        $rows = \Civi\Api4\CiviCase::get(false)
            ->addSelect('subject', 'Projects.MAS_Project_Case_Code')
            ->addWhere('case_type_id:name', '=', 'project')
            ->addWhere('is_deleted', '=', false)
            ->addWhere('status_id:name', '=', 'Completed')
            ->addWhere('end_date', 'IS NULL')
            ->addOrderBy('id')
            ->execute();
        $out = [];
        foreach ($rows as $r) {
            $out[] = trim(($r['Projects.MAS_Project_Case_Code'] ?? '') . ' ' . ($r['subject'] ?? ''));
        }
        return $out;
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
