<?php

declare(strict_types=1);

// file: Civi/Mascode/Util/CaseStatusSet.php

namespace Civi\Mascode\Util;

use Civi\Api4\CaseType;
use Civi\Api4\OptionValue;

/**
 * Resolves a case type's statuses, split by class (Opened/Closed) and ordered
 * by workflow weight, from the single source of truth: the CaseType definition
 * (which statuses belong to the type) intersected with the case_status option
 * group (which carries each status's class in `grouping` and its sequence in
 * `weight`).
 *
 * Used by the Cases Dashboard managed searches so that adding a new case status
 * (a managed OptionValue + adding it to the CaseType definition) automatically
 * flows into the dashboards on the next `cv flush` — no dashboard edit needed.
 *
 * Resolved at managed-reconcile (build) time, with a hardcoded fallback for the
 * one moment it can't read the DB (a brand-new install where these entities
 * aren't created yet); the next flush, once they exist, regenerates correctly.
 */
final class CaseStatusSet
{
    /** Fallback status sets, by case type — used only if the DB read yields nothing. */
    private const FALLBACK = [
        'service_request' => [
            'Opened' => ['Ongoing', 'Request RCS', 'RCS Completed', 'Sent for Assignment'],
            'Closed' => ['Project Created', 'Help provided - no project', 'No VC Response', 'No Client Response', 'Closed'],
        ],
        'project' => [
            'Opened' => [
                'Awaiting VC Project Definition', 'Awaiting Client Project Definition',
                'Active', 'On Hold',
                'Awaiting VC Project Close Form', 'Awaiting Client Project Close Form',
            ],
            'Closed' => ['Completed', 'Closed - Not Completed', 'Cancelled'],
        ],
    ];

    /**
     * Status NAMES for a case type + class ('Opened'|'Closed'), in weight order.
     * Names are stable identifiers and (within the Opened class) collision-free.
     *
     * @return string[]
     */
    public static function names(string $caseType, string $class): array
    {
        return array_column(self::rows($caseType, $class), 'name');
    }

    /**
     * Status LABELS for a case type + class, in weight order. Labels are used
     * where matching must avoid the case_status name collision ("Closed" vs
     * "closed", distinct labels "Resolved" / "Closed").
     *
     * @return string[]
     */
    public static function labels(string $caseType, string $class): array
    {
        return array_column(self::rows($caseType, $class), 'label');
    }

    /**
     * Rich rows [['name','value','label','weight'], ...] for a case type +
     * class, weight-ordered.
     *
     * @return array<int,array{name:string,value:string,label:string,weight:int}>
     */
    public static function rows(string $caseType, string $class): array
    {
        try {
            $ct = CaseType::get(false)
                ->addSelect('definition')
                ->addWhere('name', '=', $caseType)
                ->execute()
                ->first();
            $names = $ct['definition']['statuses'] ?? [];
            if ($names) {
                $rows = (array) OptionValue::get(false)
                    ->addSelect('name', 'value', 'label', 'weight')
                    ->addWhere('option_group_id:name', '=', 'case_status')
                    ->addWhere('grouping', '=', $class)
                    ->addWhere('name', 'IN', $names)
                    ->addOrderBy('weight', 'ASC')
                    ->execute()
                    ->getArrayCopy();
                if ($rows) {
                    return $rows;
                }
            }
        } catch (\Throwable $e) {
            // Fall through to the fallback (e.g. fresh install before these
            // entities exist, or API unavailable during early bootstrap).
        }

        $fallbackNames = self::FALLBACK[$caseType][$class] ?? [];
        return array_map(
            static fn($n) => ['name' => $n, 'value' => '', 'label' => $n, 'weight' => 0],
            $fallbackNames
        );
    }
}
