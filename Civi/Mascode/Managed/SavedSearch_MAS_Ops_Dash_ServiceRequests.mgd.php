<?php

declare(strict_types=1);

/**
 * Cases Dashboard — Service Requests block (mas-lifecycle-dashboard-spec,
 * Vidula/ED pipeline matrix). Three grouped-count searches + tile displays:
 * open SRs by status, SRs closed this quarter by status, SRs closed this year
 * by status. Rows are ordered by the case_status option WEIGHT (Brian's
 * approved sequence) via an INNER JOIN to the status OptionValue — SearchKit
 * otherwise sorts grouped option rows by value, not weight.
 *
 * "Closed this quarter/year" = end_date within SearchKit's this.quarter /
 * this.year relative tokens — the same convention as the board-metric period
 * searches (Cases_closed_in_a_specific_period). Fiscal year is default Jan 1,
 * so calendar = fiscal.
 *
 * Status sets are matched by :label (unique) rather than :name because the
 * case_status machine names "Closed" (value 2, label "Resolved") and "closed"
 * (value 15, label "Closed") collide case-insensitively.
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
            ['case_type_id:name', '=', 'service_request'],
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

$srOpen = ['Ongoing', 'Request RCS', 'RCS Completed', 'Sent for Assignment'];
$srClosed = ['Project Created', 'Help provided - no project', 'No VC Response', 'No Client Response', 'Closed'];

return [
  $search('SavedSearch_MAS_Ops_Dash_SR_Open', 'MAS_Ops_Dash_SR_Open', 'MAS Cases Dash - Open Service Requests', $srOpen),
  $display('SearchDisplay_MAS_Ops_Dash_SR_Open', 'MAS_Ops_Dash_SR_Open', 'Open'),

  $search('SavedSearch_MAS_Ops_Dash_SR_ClosedQ', 'MAS_Ops_Dash_SR_ClosedQ', 'MAS Cases Dash - Service Requests Closed this Quarter', $srClosed, [['end_date', '=', 'this.quarter']]),
  $display('SearchDisplay_MAS_Ops_Dash_SR_ClosedQ', 'MAS_Ops_Dash_SR_ClosedQ', 'Closed this quarter'),

  $search('SavedSearch_MAS_Ops_Dash_SR_ClosedY', 'MAS_Ops_Dash_SR_ClosedY', 'MAS Cases Dash - Service Requests Closed this Year', $srClosed, [['end_date', '=', 'this.year']]),
  $display('SearchDisplay_MAS_Ops_Dash_SR_ClosedY', 'MAS_Ops_Dash_SR_ClosedY', 'Closed this year'),
];
