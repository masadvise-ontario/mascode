<?php

declare(strict_types=1);

/**
 * Cases Dashboard — Service Requests block (mas-lifecycle-dashboard-spec,
 * Vidula/ED pipeline matrix). For each bucket (open / closed this quarter /
 * closed this year) there is:
 *   - a grouped-count search + tile (status, count), rows ordered by the
 *     case_status option WEIGHT via an INNER JOIN to the status OptionValue
 *     (SearchKit otherwise sorts grouped option rows by value, not weight);
 *   - a "_List" detail search + display listing the underlying cases. The
 *     count cell links to the matching detail display, passing the clicked
 *     row's status_id as a URL filter (civicrm/search#/display/...?status_id=)
 *     — the single-param drill pattern core uses (?batch=[id]).
 *
 * "Closed this quarter/year" = end_date within SearchKit's this.quarter /
 * this.year relative tokens (board-metric period convention; fiscal = calendar).
 * Status sets matched by :label (unique) because case_status names
 * "Closed"/"closed" collide case-insensitively.
 *
 * Counts verified live 2026-06-09.
 */

$svJoin = [
  'OptionValue AS sv', 'INNER',
  ['sv.value', '=', 'status_id'],
  ['sv.option_group_id:name', '=', '"case_status"'],
];
$ccJoin = [
  'Contact AS Case_CaseContact_Contact_01', 'LEFT', 'CaseContact',
  ['id', '=', 'Case_CaseContact_Contact_01.case_id'],
];
$caseLink = [
  'path' => 'civicrm/contact/view/case?reset=1&action=view&id=[id]&cid=[Case_CaseContact_Contact_01.id]',
  'entity' => '', 'action' => '', 'join' => '', 'target' => '_blank', 'task' => '',
];

$countSearch = function (string $ssName, string $label, array $statusLabels, array $extra) use ($svJoin): array {
  return [
    'name' => 'SavedSearch_' . $ssName, 'entity' => 'SavedSearch',
    'cleanup' => 'unused', 'update' => 'unmodified',
    'params' => ['version' => 4, 'values' => [
      'name' => $ssName, 'label' => $label, 'api_entity' => 'Case',
      'api_params' => [
        'version' => 4,
        'select' => ['status_id', 'status_id:label', 'COUNT(id) AS c', 'sv.weight'],
        'orderBy' => ['sv.weight' => 'ASC'],
        'where' => array_merge([['case_type_id:name', '=', 'service_request'], ['status_id:label', 'IN', $statusLabels]], $extra),
        'groupBy' => ['status_id', 'sv.weight'], 'join' => [$svJoin], 'having' => [],
      ],
    ], 'match' => ['name']],
  ];
};
$countDisplay = function (string $ssName, string $countLabel, string $listName) : array {
  return [
    'name' => 'SearchDisplay_' . $ssName, 'entity' => 'SearchDisplay',
    'cleanup' => 'unused', 'update' => 'unmodified',
    'params' => ['version' => 4, 'values' => [
      'name' => $ssName . '_Tile', 'label' => str_replace('_', ' ', $ssName) . ' Tile',
      'saved_search_id.name' => $ssName, 'type' => 'table',
      'settings' => [
        'description' => NULL, 'sort' => [['sv.weight', 'ASC']], 'limit' => 50, 'pager' => FALSE,
        'columns' => [
          ['type' => 'field', 'key' => 'status_id:label', 'label' => 'Status'],
          ['type' => 'field', 'key' => 'c', 'label' => $countLabel,
            'link' => ['path' => 'civicrm/search#/display/' . $listName . '/' . $listName . '?status_id=[status_id]',
              'entity' => '', 'action' => '', 'join' => '', 'target' => '_blank', 'task' => '']],
        ],
        'actions' => FALSE, 'classes' => ['table', 'table-striped'],
      ],
    ], 'match' => ['name']],
  ];
};
$listSearch = function (string $ssName, string $label, array $statusLabels, array $extra) use ($ccJoin): array {
  return [
    'name' => 'SavedSearch_' . $ssName, 'entity' => 'SavedSearch',
    'cleanup' => 'unused', 'update' => 'unmodified',
    'params' => ['version' => 4, 'values' => [
      'name' => $ssName, 'label' => $label, 'api_entity' => 'Case',
      'api_params' => [
        'version' => 4,
        'select' => ['id', 'Cases_SR_Projects_.MAS_SR_Case_Code', 'Case_CaseContact_Contact_01.id',
          'Case_CaseContact_Contact_01.sort_name', 'subject', 'status_id:label', 'start_date', 'end_date'],
        'orderBy' => [],
        'where' => array_merge([['case_type_id:name', '=', 'service_request'], ['status_id:label', 'IN', $statusLabels]], $extra),
        'groupBy' => [], 'join' => [$ccJoin], 'having' => [],
      ],
    ], 'match' => ['name']],
  ];
};
$listDisplay = function (string $ssName) use ($caseLink): array {
  return [
    'name' => 'SearchDisplay_' . $ssName, 'entity' => 'SearchDisplay',
    'cleanup' => 'unused', 'update' => 'unmodified',
    'params' => ['version' => 4, 'values' => [
      'name' => $ssName, 'label' => str_replace('_', ' ', $ssName),
      'saved_search_id.name' => $ssName, 'type' => 'table',
      'settings' => [
        'description' => NULL, 'sort' => [['start_date', 'ASC']], 'limit' => 50,
        'pager' => ['hide_single' => TRUE],
        'columns' => [
          ['type' => 'field', 'key' => 'Cases_SR_Projects_.MAS_SR_Case_Code', 'label' => 'MAS Code', 'link' => $caseLink],
          ['type' => 'field', 'key' => 'Case_CaseContact_Contact_01.sort_name', 'label' => 'Client'],
          ['type' => 'field', 'key' => 'subject', 'label' => 'Subject'],
          ['type' => 'field', 'key' => 'status_id:label', 'label' => 'Status'],
          ['type' => 'field', 'key' => 'start_date', 'label' => 'Received'],
          ['type' => 'field', 'key' => 'end_date', 'label' => 'Closed'],
        ],
        'actions' => FALSE, 'classes' => ['table', 'table-striped'],
      ],
    ], 'match' => ['name']],
  ];
};

$open = ['Ongoing', 'Request RCS', 'RCS Completed', 'Sent for Assignment'];
$closed = ['Project Created', 'Help provided - no project', 'No VC Response', 'No Client Response', 'Closed'];
$thisQ = [['end_date', '=', 'this.quarter']];
$thisY = [['end_date', '=', 'this.year']];

return [
  // Open
  $countSearch('MAS_Ops_Dash_SR_Open', 'MAS Cases Dash - Open Service Requests', $open, []),
  $countDisplay('MAS_Ops_Dash_SR_Open', 'Open', 'MAS_Ops_Dash_SR_Open_List'),
  $listSearch('MAS_Ops_Dash_SR_Open_List', 'MAS Cases Dash - Open Service Requests (list)', $open, []),
  $listDisplay('MAS_Ops_Dash_SR_Open_List'),
  // Closed this quarter
  $countSearch('MAS_Ops_Dash_SR_ClosedQ', 'MAS Cases Dash - Service Requests Closed this Quarter', $closed, $thisQ),
  $countDisplay('MAS_Ops_Dash_SR_ClosedQ', 'Closed this quarter', 'MAS_Ops_Dash_SR_ClosedQ_List'),
  $listSearch('MAS_Ops_Dash_SR_ClosedQ_List', 'MAS Cases Dash - SRs Closed this Quarter (list)', $closed, $thisQ),
  $listDisplay('MAS_Ops_Dash_SR_ClosedQ_List'),
  // Closed this year
  $countSearch('MAS_Ops_Dash_SR_ClosedY', 'MAS Cases Dash - Service Requests Closed this Year', $closed, $thisY),
  $countDisplay('MAS_Ops_Dash_SR_ClosedY', 'Closed this year', 'MAS_Ops_Dash_SR_ClosedY_List'),
  $listSearch('MAS_Ops_Dash_SR_ClosedY_List', 'MAS Cases Dash - SRs Closed this Year (list)', $closed, $thisY),
  $listDisplay('MAS_Ops_Dash_SR_ClosedY_List'),
  // Outcome pie (on the Closed-this-Year search): conversion/drop-off mix.
  [
    'name' => 'SearchDisplay_MAS_Ops_Dash_SR_ClosedY_Pie', 'entity' => 'SearchDisplay',
    'cleanup' => 'unused', 'update' => 'unmodified',
    'params' => ['version' => 4, 'values' => [
      'name' => 'MAS_Ops_Dash_SR_ClosedY_Pie', 'label' => 'MAS Cases Dash - SRs Closed this Year (pie)',
      'saved_search_id.name' => 'MAS_Ops_Dash_SR_ClosedY', 'type' => 'chart-kit',
      'settings' => [
        'columns' => [
          ['axis' => 'w', 'key' => 'status_id:label', 'index' => 0, 'label' => 'Status', 'sourceDataType' => 'Option', 'scaleType' => 'categorical', 'datePrecision' => NULL, 'reduceType' => 'list', 'seriesType' => NULL, 'dataLabelType' => 'label', 'dataLabelFormatter' => 'none', 'reducer' => ['key' => 'list', 'label' => 'List']],
          ['axis' => 'y', 'key' => 'c', 'index' => 1, 'label' => 'Cases', 'sourceDataType' => 'Integer', 'scaleType' => 'numeric', 'datePrecision' => NULL, 'reduceType' => 'sum', 'seriesType' => NULL, 'dataLabelType' => 'label', 'dataLabelFormatter' => 'none', 'reducer' => ['key' => 'sum', 'label' => 'Sum']],
        ],
        'format' => ['labelColor' => '#000000', 'backgroundColor' => '#f2f2ed', 'height' => 320, 'width' => 420, 'padding' => ['outer' => 10, 'clip' => 20, 'top' => 30, 'bottom' => 30, 'left' => 30, 'right' => 30]],
        'showLegend' => 'left', 'maxSegments' => 6, 'chartType' => 'pie',
        'sort' => [['sv.weight', 'ASC']],
      ],
    ], 'match' => ['name']],
  ],
];
