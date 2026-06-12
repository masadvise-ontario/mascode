<?php

declare(strict_types=1);

/**
 * Quarterly Board Dashboard — QTD metrics (mas-lifecycle-dashboard-spec,
 * Phase C board-metric pass / Vidula board prep).
 *
 * Live quarter-to-date counterparts of the legacy "NN) ..." MAS_Dashboard
 * board-metric searches (ids 22-41). The legacy searches stay untouched —
 * they ride the SK_Cases*InASpecificPeriod DB-entity plumbing and remain the
 * manual historical-quarter mechanism. These clones bake the period in as
 * this.quarter relative dates so the board page is zero-config.
 *
 * Covers board rows 15-21 (the "Consulting and other Services" section) —
 * the rows whose definitions are unambiguous against the board pack. Rows
 * 1-14 (VC + Client Organizations sections) wait on the definition pass with
 * Vidula; rows 22-24 are not CiviCRM data.
 *
 * Per metric: a count search (+ tile display whose count drills into the
 * list) and a _List detail search/display — same pattern as
 * SavedSearch_MAS_Ops_Dash_*.mgd.php. COUNT(DISTINCT id) because the client
 * join can fan out multi-contact cases. Definition parity notes per metric
 * inline below; intentional deviations from the legacy searches:
 *   - 15/17 add an explicit service_request case-type filter (the status
 *     alone is unique to SRs today, but cheap insurance);
 *   - 20 "Open Projects at end of quarter" becomes open-projects-right-now
 *     (Active / On Hold / the three Awaiting statuses) — on a live QTD page "end of
 *     quarter" and "now" coincide.
 */

$ccJoin = [
  'Contact AS Case_CaseContact_Contact_01', 'LEFT', 'CaseContact',
  ['id', '=', 'Case_CaseContact_Contact_01.case_id'],
];
$caseLink = [
  'path' => 'civicrm/contact/view/case?reset=1&action=view&id=[id]&cid=[Case_CaseContact_Contact_01.id]',
  'entity' => '', 'action' => '', 'join' => '', 'target' => '_blank', 'task' => '',
];
$srCode = 'Cases_SR_Projects_.MAS_SR_Case_Code';
$pjCode = 'Projects.MAS_Project_Case_Code';
$notMas = ['Case_CaseContact_Contact_01.id', '!=', 1];

$countSearch = function (string $ssName, string $label, array $where, bool $hours) use ($ccJoin): array {
  $select = ['COUNT(DISTINCT id) AS c'];
  if ($hours) {
    $select[] = 'SUM(Projects.Hours) AS hours_total';
  }
  return [
    'name' => 'SavedSearch_' . $ssName, 'entity' => 'SavedSearch',
    'cleanup' => 'unused', 'update' => 'unmodified',
    'params' => ['version' => 4, 'values' => [
      'name' => $ssName, 'label' => $label, 'api_entity' => 'Case',
      'api_params' => [
        'version' => 4, 'select' => $select, 'orderBy' => [],
        'where' => $where, 'groupBy' => [], 'join' => [$ccJoin], 'having' => [],
      ],
    ], 'match' => ['name']],
  ];
};
$countDisplay = function (string $ssName, string $countLabel, bool $hours): array {
  $columns = [
    ['type' => 'field', 'key' => 'c', 'label' => $countLabel,
      'link' => ['path' => 'civicrm/search#/display/' . $ssName . '_List/' . $ssName . '_List',
        'entity' => '', 'action' => '', 'join' => '', 'target' => '_blank', 'task' => '']],
  ];
  if ($hours) {
    $columns[] = ['type' => 'field', 'key' => 'hours_total', 'label' => 'Hours (QTD)'];
  }
  return [
    'name' => 'SearchDisplay_' . $ssName, 'entity' => 'SearchDisplay',
    'cleanup' => 'unused', 'update' => 'unmodified',
    'params' => ['version' => 4, 'values' => [
      'name' => $ssName . '_Tile', 'label' => str_replace('_', ' ', $ssName) . ' Tile',
      'saved_search_id.name' => $ssName, 'type' => 'table',
      'settings' => [
        'description' => NULL, 'sort' => [], 'limit' => 50, 'pager' => FALSE,
        'columns' => $columns,
        'actions' => FALSE, 'classes' => ['table', 'table-striped'],
      ],
    ], 'match' => ['name']],
  ];
};
$listSearch = function (string $ssName, string $label, array $where, string $codeField, bool $hours) use ($ccJoin): array {
  $select = ['id', $codeField, 'Case_CaseContact_Contact_01.id',
    'Case_CaseContact_Contact_01.sort_name', 'subject', 'status_id:label', 'start_date', 'end_date'];
  if ($hours) {
    $select[] = 'Projects.Hours';
  }
  return [
    'name' => 'SavedSearch_' . $ssName, 'entity' => 'SavedSearch',
    'cleanup' => 'unused', 'update' => 'unmodified',
    'params' => ['version' => 4, 'values' => [
      'name' => $ssName, 'label' => $label, 'api_entity' => 'Case',
      'api_params' => [
        'version' => 4, 'select' => $select, 'orderBy' => [],
        'where' => $where, 'groupBy' => [], 'join' => [$ccJoin], 'having' => [],
      ],
    ], 'match' => ['name']],
  ];
};
$listDisplay = function (string $ssName, string $codeField, bool $hours) use ($caseLink): array {
  $columns = [
    ['type' => 'field', 'key' => $codeField, 'label' => 'MAS Code', 'link' => $caseLink],
    ['type' => 'field', 'key' => 'Case_CaseContact_Contact_01.sort_name', 'label' => 'Client'],
    ['type' => 'field', 'key' => 'subject', 'label' => 'Subject'],
    ['type' => 'field', 'key' => 'status_id:label', 'label' => 'Status'],
    ['type' => 'field', 'key' => 'start_date', 'label' => 'Started'],
    ['type' => 'field', 'key' => 'end_date', 'label' => 'Closed'],
  ];
  if ($hours) {
    $columns[] = ['type' => 'field', 'key' => 'Projects.Hours', 'label' => 'Hours'];
  }
  return [
    'name' => 'SearchDisplay_' . $ssName, 'entity' => 'SearchDisplay',
    'cleanup' => 'unused', 'update' => 'unmodified',
    'params' => ['version' => 4, 'values' => [
      'name' => $ssName, 'label' => str_replace('_', ' ', $ssName),
      'saved_search_id.name' => $ssName, 'type' => 'table',
      'settings' => [
        'description' => NULL, 'sort' => [['start_date', 'ASC']], 'limit' => 50,
        'pager' => ['hide_single' => TRUE],
        'columns' => $columns,
        'actions' => FALSE, 'classes' => ['table', 'table-striped'],
      ],
    ], 'match' => ['name']],
  ];
};

// Board row => [ssName, label, where, codeField, hours, countLabel]
// Parity sources: legacy SavedSearch ids 31 (15), 32 (16), 37 (17), 38 (18),
// 39 (19), 40 (20), 41 (21) — definitions read from dev 2026-06-10.
$metrics = [
  ['MAS_Board_QTD_15_HelpNoProject', 'MAS Board - 15) Help given - no project (QTD)',
    [['case_type_id:name', '=', 'service_request'], ['status_id:name', '=', 'Help Provided - No Project'], ['end_date', '=', 'this.quarter']],
    $srCode, FALSE, 'QTD'],
  ['MAS_Board_QTD_16_NewServiceRequests', 'MAS Board - 16) New Service Requests (QTD)',
    [['case_type_id:name', '=', 'service_request'], ['start_date', '=', 'this.quarter']],
    $srCode, FALSE, 'QTD'],
  ['MAS_Board_QTD_17_UnableToAssignVC', 'MAS Board - 17) Unable to assign VC (QTD)',
    [['case_type_id:name', '=', 'service_request'], ['status_id:name', '=', 'No VC Response'], ['end_date', '=', 'this.quarter']],
    $srCode, FALSE, 'QTD'],
  ['MAS_Board_QTD_18_ProjectsInitiated', 'MAS Board - 18) Projects initiated (QTD)',
    [['case_type_id:name', '=', 'project'], ['start_date', '=', 'this.quarter'], ['status_id:name', '!=', 'Cancelled'], $notMas],
    $pjCode, FALSE, 'QTD'],
  ['MAS_Board_QTD_19_CompletedProjects', 'MAS Board - 19) Completed projects (QTD)',
    [['case_type_id:name', '=', 'project'], ['status_id:name', '=', 'Completed'], ['end_date', '=', 'this.quarter'], $notMas],
    $pjCode, TRUE, 'QTD'],
  ['MAS_Board_QTD_20_OpenProjects', 'MAS Board - 20) Open projects incl. new (now)',
    [['case_type_id:name', '=', 'project'], ['status_id:label', 'IN', ['Active', 'On Hold', 'Awaiting VC Project Definition', 'Awaiting Client Project Definition', 'Awaiting VC Project Close Form', 'Awaiting Client Project Close Form']], $notMas],
    $pjCode, FALSE, 'Now'],
  ['MAS_Board_QTD_21_HoursOfService', 'MAS Board - 21) Hours of service - projects closed (QTD)',
    [['case_type_id:name', '=', 'project'], ['end_date', '=', 'this.quarter'], $notMas],
    $pjCode, TRUE, 'Projects closed (QTD)'],
];

$entities = [];
foreach ($metrics as [$name, $label, $where, $codeField, $hours, $countLabel]) {
  $entities[] = $countSearch($name, $label, $where, $hours);
  $entities[] = $countDisplay($name, $countLabel, $hours);
  $entities[] = $listSearch($name . '_List', $label . ' (list)', $where, $codeField, $hours);
  $entities[] = $listDisplay($name . '_List', $codeField, $hours);
}
return $entities;
