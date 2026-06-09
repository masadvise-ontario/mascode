<?php

declare(strict_types=1);

/**
 * Cases Dashboard — Projects block (mas-lifecycle-dashboard-spec, Vidula/ED
 * pipeline matrix). Three grouped-count searches + tile displays: open projects
 * by status, projects closed this quarter by status, projects closed this year
 * by status. Same mechanics as the Service Requests block
 * (SavedSearch_MAS_Ops_Dash_ServiceRequests.mgd.php): rows ordered by the
 * case_status option WEIGHT via an INNER JOIN to the status OptionValue;
 * "closed this quarter/year" = end_date within this.quarter / this.year;
 * status sets matched by :label (unique).
 *
 * Counts verified live 2026-06-09. Drill-down links are added in a follow-up.
 */

$svJoin = [
  'OptionValue AS sv',
  'INNER',
  ['sv.value', '=', 'status_id'],
  ['sv.option_group_id:name', '=', '"case_status"'],
];

$display = function (string $name, string $ssName, string $countLabel): array {
  return [
    'name' => $name,
    'entity' => 'SearchDisplay',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => $ssName . '_Tile',
        'label' => str_replace('_', ' ', $ssName) . ' Tile',
        'saved_search_id.name' => $ssName,
        'type' => 'table',
        'settings' => [
          'description' => NULL,
          'sort' => [['sv.weight', 'ASC']],
          'limit' => 50,
          'pager' => FALSE,
          'columns' => [
            ['type' => 'field', 'key' => 'status_id:label', 'label' => 'Status'],
            ['type' => 'field', 'key' => 'c', 'label' => $countLabel],
          ],
          'actions' => FALSE,
          'classes' => ['table', 'table-striped'],
        ],
      ],
      'match' => ['name'],
    ],
  ];
};

$search = function (string $name, string $ssName, string $label, array $statusLabels, array $extraWhere = []) use ($svJoin): array {
  return [
    'name' => $name,
    'entity' => 'SavedSearch',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => $ssName,
        'label' => $label,
        'api_entity' => 'Case',
        'api_params' => [
          'version' => 4,
          'select' => ['status_id:label', 'COUNT(id) AS c', 'sv.weight'],
          'orderBy' => ['sv.weight' => 'ASC'],
          'where' => array_merge([
            ['case_type_id:name', '=', 'project'],
            ['status_id:label', 'IN', $statusLabels],
          ], $extraWhere),
          'groupBy' => ['status_id', 'sv.weight'],
          'join' => [$svJoin],
          'having' => [],
        ],
      ],
      'match' => ['name'],
    ],
  ];
};

$pjOpen = ['Active', 'On Hold', 'Awaiting Close Form'];
$pjClosed = ['Completed', 'Closed - Not Completed', 'Cancelled'];

return [
  $search('SavedSearch_MAS_Ops_Dash_PJ_Open', 'MAS_Ops_Dash_PJ_Open', 'MAS Cases Dash - Open Projects', $pjOpen),
  $display('SearchDisplay_MAS_Ops_Dash_PJ_Open', 'MAS_Ops_Dash_PJ_Open', 'Open'),

  $search('SavedSearch_MAS_Ops_Dash_PJ_ClosedQ', 'MAS_Ops_Dash_PJ_ClosedQ', 'MAS Cases Dash - Projects Closed this Quarter', $pjClosed, [['end_date', '=', 'this.quarter']]),
  $display('SearchDisplay_MAS_Ops_Dash_PJ_ClosedQ', 'MAS_Ops_Dash_PJ_ClosedQ', 'Closed this quarter'),

  $search('SavedSearch_MAS_Ops_Dash_PJ_ClosedY', 'MAS_Ops_Dash_PJ_ClosedY', 'MAS Cases Dash - Projects Closed this Year', $pjClosed, [['end_date', '=', 'this.year']]),
  $display('SearchDisplay_MAS_Ops_Dash_PJ_ClosedY', 'MAS_Ops_Dash_PJ_ClosedY', 'Closed this year'),
];
