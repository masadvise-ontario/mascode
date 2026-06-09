<?php

/**
 * Idempotent reorder of case_status option-value weights to the MAS lifecycle
 * sequence (mas-lifecycle-dashboard-spec, Cases Dashboard). Display-only.
 *
 * Why a standalone script: CRM_Mascode_Upgrader::upgrade_5001() holds the same
 * logic, but CiviCRM's extension-upgrade baseline treats already-installed
 * extensions as current (hasPending() == false, schema_version NULL), so the
 * upgrader never fires on an existing install. Run this once per environment
 * to apply / re-apply the order:
 *
 *   cv scr <ext path>/tools/reorder_case_status_weights.php
 *
 * Keyed by VALUE, not name — case_status machine names "Closed" (value 2 /
 * label "Resolved") and "closed" (value 15 / label "Closed") collide
 * case-insensitively.
 */

// value => weight: open SR, closed SR, open project, closed project; "Resolved" last.
$seq = [
  1 => 1, 6 => 2, 18 => 3, 7 => 4,
  10 => 5, 5 => 6, 8 => 7, 9 => 8, 15 => 9,
  16 => 10, 14 => 11, 19 => 12,
  13 => 13, 12 => 14, 11 => 15,
  2 => 16,
];

foreach ($seq as $value => $weight) {
  \Civi\Api4\OptionValue::update(FALSE)
    ->addWhere('option_group_id:name', '=', 'case_status')
    ->addWhere('value', '=', (string) $value)
    ->addValue('weight', $weight)
    ->execute();
}

echo 'case_status weights reordered to MAS lifecycle sequence (' . count($seq) . " values)\n";
