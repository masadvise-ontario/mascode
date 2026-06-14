<?php

/**
 * Add a "Case Status" column to the My_Cases SearchKit report.
 *
 * SearchKit needs the field in two places: the saved search's `select`
 * (so the SQL returns it) and each display's `columns` (so it renders).
 * This adds RelationshipCache_Case_case_id_01.status_id:label to the
 * My_Cases select if absent, and inserts a "Case Status" column right
 * after the Case Type column in every display of My_Cases (both
 * My_Cases_Table_1 and My_Cases_Table_2). Idempotent.
 *
 *   cv scr <ext path>/tools/add_case_status_column_my_cases.php
 */

$statusField = 'RelationshipCache_Case_case_id_01.status_id:label';
$typeField   = 'RelationshipCache_Case_case_id_01.case_type_id:label';

$search = \Civi\Api4\SavedSearch::get(FALSE)
  ->addSelect('id', 'api_params')
  ->addWhere('name', '=', 'My_Cases')
  ->execute();
if ($search->count() !== 1) {
  echo "ABORT: expected one SavedSearch named My_Cases, got " . $search->count() . "\n";
  exit(1);
}
$ss = $search[0];
$params = $ss['api_params'];
$select = $params['select'] ?? [];

if (!in_array($statusField, $select, TRUE)) {
  $pos = array_search($typeField, $select, TRUE);
  if ($pos === FALSE) {
    $select[] = $statusField;
  }
  else {
    array_splice($select, $pos + 1, 0, [$statusField]);
  }
  $params['select'] = $select;
  \Civi\Api4\SavedSearch::update(FALSE)
    ->addWhere('id', '=', $ss['id'])
    ->addValue('api_params', $params)
    ->execute();
  echo "SavedSearch My_Cases: added status field to select.\n";
}
else {
  echo "SavedSearch My_Cases: status field already in select.\n";
}

$displays = \Civi\Api4\SearchDisplay::get(FALSE)
  ->addSelect('id', 'name', 'settings')
  ->addWhere('saved_search_id.name', '=', 'My_Cases')
  ->execute();

foreach ($displays as $d) {
  $settings = $d['settings'];
  $cols = $settings['columns'] ?? [];
  $has = FALSE;
  $typeIdx = NULL;
  foreach ($cols as $i => $c) {
    if (($c['key'] ?? NULL) === $statusField) {
      $has = TRUE;
      break;
    }
    if (($c['key'] ?? NULL) === $typeField) {
      $typeIdx = $i;
    }
  }
  if ($has) {
    echo "Display {$d['name']}: Case Status column already present.\n";
    continue;
  }
  $newCol = ['type' => 'field', 'key' => $statusField, 'label' => 'Case Status', 'sortable' => TRUE];
  if ($typeIdx === NULL) {
    $cols[] = $newCol;
  }
  else {
    array_splice($cols, $typeIdx + 1, 0, [$newCol]);
  }
  $settings['columns'] = $cols;
  \Civi\Api4\SearchDisplay::update(FALSE)
    ->addWhere('id', '=', $d['id'])
    ->addValue('settings', $settings)
    ->execute();
  echo "Display {$d['name']}: inserted Case Status column after Case Type.\n";
}

echo "Done.\n";
