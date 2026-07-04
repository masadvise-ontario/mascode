<?php

declare(strict_types=1);

/**
 * Quarterly Board Dashboard — QTD + previous-quarter (PQ) metrics
 * (mas-lifecycle-dashboard-spec, Phase C board-metric pass / Vidula board prep).
 *
 * Live quarter-to-date counterparts of the legacy "NN) ..." MAS_Dashboard
 * board-metric searches (ids 22-41). The legacy searches stay untouched —
 * they ride the SK_Cases*InASpecificPeriod DB-entity plumbing and remain the
 * manual historical-quarter mechanism. These clones bake the period in as
 * relative dates so the board page is zero-config.
 *
 * Two families are emitted from one metric spec (see $families / $buildMetrics
 * below): QTD → this.quarter (names MAS_Board_QTD_*, unchanged) and PQ →
 * previous.quarter (names MAS_Board_PQ_*). The dashboard page shows the PQ
 * tiles as a second "Previous Quarter" column beside the QTD tiles.
 *
 * Point-in-time rule (per Brian): a metric that reports a "Now" snapshot in
 * QTD must, in PQ, report the count AS AT THE END of the previous quarter —
 * not a this→previous token swap. Row 20 (open projects) is such a metric:
 *   QTD = projects whose status is currently open;
 *   PQ  = projects started on/before the end of the previous quarter that had
 *         not yet closed by then (date reconstruction, status-agnostic since
 *         current status does not describe the historical point in time).
 * Relative-date boundary identities this relies on (dev-verified 2026-07-04):
 *   x <= previous.quarter  ==  x <  this.quarter   (end of previous quarter)
 *   x >  previous.quarter  ==  x >= this.quarter   (start of this quarter)
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
$clientLink = [
  'path' => 'civicrm/contact/view?reset=1&cid=[Case_CaseContact_Contact_01.id]',
  'entity' => '', 'action' => '', 'join' => '', 'target' => '_blank', 'task' => '',
];
// MAS Rep = the contact with the "Case Coordinator for" role on the case
// (RelationshipCache b_a "Case Coordinator" row -> far_contact is the rep).
$masRepJoin = [
  'RelationshipCache AS masrep', 'LEFT',
  ['id', '=', 'masrep.case_id'],
  ['masrep.near_relation:name', '=', '"Case Coordinator"'],
];
$srCode = 'Cases_SR_Projects_.MAS_SR_Case_Code';
$pjCode = 'Projects.MAS_Project_Case_Code';
$notMas = ['Case_CaseContact_Contact_01.id', '!=', 1];

$countSearch = function (string $ssName, string $label, array $where, bool $hours) use ($ccJoin): array {
  $select = ['COUNT(DISTINCT id) AS c'];
  if ($hours) {
    $select[] = 'SUM(Project_Close_VC.hours_worked) AS hours_total';
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
$countDisplay = function (string $ssName, string $countLabel, bool $hours, string $hoursLabel = 'Hours (QTD)'): array {
  $columns = [
    ['type' => 'field', 'key' => 'c', 'label' => $countLabel,
      'link' => ['path' => 'civicrm/search#/display/' . $ssName . '_List/' . $ssName . '_List',
        'entity' => '', 'action' => '', 'join' => '', 'target' => '_blank', 'task' => '']],
  ];
  if ($hours) {
    $columns[] = ['type' => 'field', 'key' => 'hours_total', 'label' => $hoursLabel];
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
$listSearch = function (string $ssName, string $label, array $where, string $codeField, bool $hours) use ($ccJoin, $masRepJoin): array {
  $select = ['id', $codeField, 'Case_CaseContact_Contact_01.id',
    'Case_CaseContact_Contact_01.sort_name', 'subject', 'status_id:label', 'start_date', 'end_date',
    'GROUP_CONCAT(DISTINCT masrep.far_contact_id.sort_name) AS mas_rep'];
  if ($hours) {
    $select[] = 'Project_Close_VC.hours_worked';
  }
  return [
    'name' => 'SavedSearch_' . $ssName, 'entity' => 'SavedSearch',
    'cleanup' => 'unused', 'update' => 'unmodified',
    'params' => ['version' => 4, 'values' => [
      'name' => $ssName, 'label' => $label, 'api_entity' => 'Case',
      'api_params' => [
        // groupBy id collapses the CaseContact fan-out and lets the MAS Rep
        // relationship GROUP_CONCAT to one row per case.
        'version' => 4, 'select' => $select, 'orderBy' => [],
        'where' => $where, 'groupBy' => ['id'], 'join' => [$ccJoin, $masRepJoin], 'having' => [],
      ],
    ], 'match' => ['name']],
  ];
};
$listDisplay = function (string $ssName, string $codeField, bool $hours, string $subjectLabel) use ($caseLink, $clientLink): array {
  $columns = [
    ['type' => 'field', 'key' => $codeField, 'label' => 'MAS Code', 'link' => $caseLink],
    ['type' => 'field', 'key' => 'Case_CaseContact_Contact_01.sort_name', 'label' => 'Client', 'link' => $clientLink],
    ['type' => 'field', 'key' => 'subject', 'label' => $subjectLabel],
    ['type' => 'field', 'key' => 'status_id:label', 'label' => 'Status'],
    ['type' => 'field', 'key' => 'start_date', 'label' => 'Start Date'],
    ['type' => 'field', 'key' => 'end_date', 'label' => 'End Date'],
    ['type' => 'field', 'key' => 'mas_rep', 'label' => 'MAS Rep'],
  ];
  if ($hours) {
    $columns[] = ['type' => 'field', 'key' => 'Project_Close_VC.hours_worked', 'label' => 'Hours'];
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
        'actions' => ['download'], 'classes' => ['table', 'table-striped'],
      ],
    ], 'match' => ['name']],
  ];
};

$openSet = ['Active', 'On Hold', 'Awaiting VC Project Definition', 'Awaiting Client Project Definition', 'Awaiting VC Project Close Form', 'Awaiting Client Project Close Form'];

// Build the 7-metric spec for one period family.
//   $fam    'QTD' or 'PQ'  — drives entity names and column labels
//   $period the relative-date token for the QTD-style swap metrics
//           ('this.quarter' for QTD, 'previous.quarter' for PQ)
// Row => [ssName, label, where, codeField, hours, countLabel]. Parity sources:
// legacy SavedSearch ids 31 (15), 32 (16), 37 (17), 38 (18), 39 (19), 40 (20),
// 41 (21) — definitions read from dev 2026-06-10. With $fam = 'QTD' this
// reproduces the original definitions byte-for-byte.
$buildMetrics = function (string $fam, string $period) use ($srCode, $pjCode, $notMas, $openSet): array {
  $cl = $fam === 'QTD' ? 'QTD' : 'Prev Q';
  // Row 20 "open projects" is a Now-snapshot metric: QTD = open right now (by
  // status); PQ = open at the END of the previous quarter (by date, and
  // status-agnostic — current status does not describe that historical point).
  if ($fam === 'QTD') {
    $openLabel = 'MAS Board - 20) Open projects incl. new (now)';
    $openWhere = [['case_type_id:name', '=', 'project'], ['status_id:label', 'IN', $openSet], $notMas];
    $openCol = 'Now';
  }
  else {
    $openLabel = 'MAS Board - 20) Open projects incl. new (end of prev Q)';
    $openWhere = [
      ['case_type_id:name', '=', 'project'],
      ['start_date', '<=', 'previous.quarter'],
      ['OR', [['end_date', '>', 'previous.quarter'], ['end_date', 'IS EMPTY']]],
      $notMas,
    ];
    $openCol = 'End of prev Q';
  }
  return [
    ["MAS_Board_{$fam}_15_HelpNoProject", "MAS Board - 15) Help given - no project ({$fam})",
      [['case_type_id:name', '=', 'service_request'], ['status_id:name', '=', 'Help Provided - No Project'], ['end_date', '=', $period]],
      $srCode, FALSE, $cl],
    ["MAS_Board_{$fam}_16_NewServiceRequests", "MAS Board - 16) New Service Requests ({$fam})",
      [['case_type_id:name', '=', 'service_request'], ['start_date', '=', $period]],
      $srCode, FALSE, $cl],
    ["MAS_Board_{$fam}_17_UnableToAssignVC", "MAS Board - 17) Unable to assign VC ({$fam})",
      [['case_type_id:name', '=', 'service_request'], ['status_id:name', '=', 'No VC Response'], ['end_date', '=', $period]],
      $srCode, FALSE, $cl],
    ["MAS_Board_{$fam}_18_ProjectsInitiated", "MAS Board - 18) Projects initiated ({$fam})",
      [['case_type_id:name', '=', 'project'], ['start_date', '=', $period], ['status_id:name', '!=', 'Cancelled'], $notMas],
      $pjCode, FALSE, $cl],
    ["MAS_Board_{$fam}_19_CompletedProjects", "MAS Board - 19) Completed projects ({$fam})",
      [['case_type_id:name', '=', 'project'], ['status_id:name', '=', 'Completed'], ['end_date', '=', $period], $notMas],
      $pjCode, TRUE, $cl],
    ["MAS_Board_{$fam}_20_OpenProjects", $openLabel, $openWhere, $pjCode, FALSE, $openCol],
    ["MAS_Board_{$fam}_21_HoursOfService", "MAS Board - 21) Hours of service - projects closed ({$fam})",
      [['case_type_id:name', '=', 'project'], ['end_date', '=', $period], $notMas],
      $pjCode, TRUE, $fam === 'QTD' ? 'Projects closed (QTD)' : 'Projects closed (Prev Q)'],
  ];
};

$families = ['QTD' => 'this.quarter', 'PQ' => 'previous.quarter'];
$entities = [];
foreach ($families as $fam => $period) {
  $hoursLabel = $fam === 'QTD' ? 'Hours (QTD)' : 'Hours (Prev Q)';
  foreach ($buildMetrics($fam, $period) as [$name, $label, $where, $codeField, $hours, $countLabel]) {
    $entities[] = $countSearch($name, $label, $where, $hours);
    $entities[] = $countDisplay($name, $countLabel, $hours, $hoursLabel);
    $subjectLabel = $codeField === $srCode ? 'Service Request' : 'Project';
    $entities[] = $listSearch($name . '_List', $label . ' (list)', $where, $codeField, $hours);
    $entities[] = $listDisplay($name . '_List', $codeField, $hours, $subjectLabel);
  }
}
return $entities;
