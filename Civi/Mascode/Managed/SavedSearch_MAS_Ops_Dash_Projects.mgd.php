<?php

declare(strict_types=1);

/**
 * Cases Dashboard — Projects block (mas-lifecycle-dashboard-spec, Vidula/ED
 * pipeline matrix). Same structure as the Service Requests block
 * (SavedSearch_MAS_Ops_Dash_ServiceRequests.mgd.php): per bucket a grouped
 * count tile (rows ordered by status weight via the OptionValue join) plus a
 * "_List" detail search/display the count drills into, passing status_id via
 * URL. "Closed this quarter/year" = end_date in this.quarter / this.year.
 * Status sets matched by :label.
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
// MAS Rep (Case Coordinator) for the project — joined by case, NOT filtered on
// is_active so both current and former coordinators show, each annotated. The
// GROUP_CONCAT keeps it to one row per case (requires groupBy on id).
$repJoin = [
  'RelationshipCache AS cc', 'LEFT',
  ['id', '=', 'cc.case_id'],
  ['cc.near_relation:name', '=', '"Case Coordinator is"'],
];
$repSelect = "GROUP_CONCAT(DISTINCT CONCAT(cc.near_contact_id.display_name, IF(cc.is_active, ' (active)', ' (inactive)'))) AS mas_rep";

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
        'where' => array_merge([['case_type_id:name', '=', 'project'], ['status_id:label', 'IN', $statusLabels]], $extra),
        'groupBy' => ['status_id', 'sv.weight'], 'join' => [$svJoin], 'having' => [],
      ],
    ], 'match' => ['name']],
  ];
};
$countDisplay = function (string $ssName, string $countLabel, string $listName): array {
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
        'actions' => ['download'], 'classes' => ['table', 'table-striped'],
      ],
    ], 'match' => ['name']],
  ];
};
$listSearch = function (string $ssName, string $label, array $statusLabels, array $extra) use ($ccJoin, $repJoin, $repSelect): array {
  return [
    'name' => 'SavedSearch_' . $ssName, 'entity' => 'SavedSearch',
    'cleanup' => 'unused', 'update' => 'unmodified',
    'params' => ['version' => 4, 'values' => [
      'name' => $ssName, 'label' => $label, 'api_entity' => 'Case',
      'api_params' => [
        'version' => 4,
        'select' => ['id', 'Projects.MAS_Project_Case_Code', 'Case_CaseContact_Contact_01.id',
          'Case_CaseContact_Contact_01.sort_name', 'subject', 'status_id:label', 'start_date', 'end_date', $repSelect],
        'orderBy' => [],
        'where' => array_merge([['case_type_id:name', '=', 'project'], ['status_id:label', 'IN', $statusLabels]], $extra),
        // groupBy id keeps one row per case for the GROUP_CONCAT'd MAS Rep; it
        // also dedupes the CaseContact join (a case with >1 client contact).
        'groupBy' => ['id'], 'join' => [$ccJoin, $repJoin], 'having' => [],
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
          ['type' => 'field', 'key' => 'Projects.MAS_Project_Case_Code', 'label' => 'MAS Code', 'link' => $caseLink],
          ['type' => 'field', 'key' => 'Case_CaseContact_Contact_01.sort_name', 'label' => 'Client'],
          ['type' => 'field', 'key' => 'mas_rep', 'label' => 'MAS Rep'],
          ['type' => 'field', 'key' => 'subject', 'label' => 'Subject'],
          ['type' => 'field', 'key' => 'status_id:label', 'label' => 'Status'],
          ['type' => 'field', 'key' => 'start_date', 'label' => 'Started'],
          ['type' => 'field', 'key' => 'end_date', 'label' => 'Closed'],
        ],
        'actions' => ['download'], 'classes' => ['table', 'table-striped'],
      ],
    ], 'match' => ['name']],
  ];
};

// Status sets are derived from the case type definition (not hardcoded) so a
// newly added status flows into the dashboard automatically on the next
// reconcile — see Civi\Mascode\Util\CaseStatusSet.
$openNames  = \Civi\Mascode\Util\CaseStatusSet::names('project', 'Opened');
$openLabels = \Civi\Mascode\Util\CaseStatusSet::labels('project', 'Opened');
$closed     = \Civi\Mascode\Util\CaseStatusSet::labels('project', 'Closed');
$thisQ = [['end_date', '=', 'this.quarter']];
$thisY = [['end_date', '=', 'this.year']];

// The Open tile is STATUS-driven: base on the open-class status OptionValues
// and LEFT JOIN the project cases, so every open status appears in weight
// order with its count — including statuses that currently have zero cases.
$openCountSearch = [
  'name' => 'SavedSearch_MAS_Ops_Dash_PJ_Open', 'entity' => 'SavedSearch',
  'cleanup' => 'unused', 'update' => 'unmodified',
  'params' => ['version' => 4, 'values' => [
    'name' => 'MAS_Ops_Dash_PJ_Open', 'label' => 'MAS Cases Dash - Open Projects',
    'api_entity' => 'OptionValue',
    'api_params' => [
      'version' => 4,
      'select' => ['value', 'label', 'weight', 'COUNT(case_open.id) AS c'],
      'orderBy' => ['weight' => 'ASC'],
      'where' => [
        ['option_group_id:name', '=', 'case_status'],
        ['grouping', '=', 'Opened'],
        ['name', 'IN', $openNames],
      ],
      'groupBy' => ['value', 'label', 'weight'],
      'join' => [[
        'Case AS case_open', 'LEFT',
        ['value', '=', 'case_open.status_id'],
        ['case_open.case_type_id:name', '=', '"project"'],
        ['case_open.is_deleted', '=', FALSE],
      ]],
      'having' => [],
    ],
  ], 'match' => ['name']],
];
$openCountDisplay = [
  'name' => 'SearchDisplay_MAS_Ops_Dash_PJ_Open', 'entity' => 'SearchDisplay',
  'cleanup' => 'unused', 'update' => 'unmodified',
  'params' => ['version' => 4, 'values' => [
    'name' => 'MAS_Ops_Dash_PJ_Open_Tile', 'label' => 'MAS Ops Dash PJ Open Tile',
    'saved_search_id.name' => 'MAS_Ops_Dash_PJ_Open', 'type' => 'table',
    'settings' => [
      'description' => NULL, 'sort' => [['weight', 'ASC']], 'limit' => 50, 'pager' => FALSE,
      'columns' => [
        ['type' => 'field', 'key' => 'label', 'label' => 'Status'],
        ['type' => 'field', 'key' => 'c', 'label' => 'Open',
          'link' => ['path' => 'civicrm/search#/display/MAS_Ops_Dash_PJ_Open_List/MAS_Ops_Dash_PJ_Open_List?status_id=[value]',
            'entity' => '', 'action' => '', 'join' => '', 'target' => '_blank', 'task' => '']],
      ],
      'actions' => ['download'], 'classes' => ['table', 'table-striped'],
    ],
  ], 'match' => ['name']],
];

return [
  $openCountSearch,
  $openCountDisplay,
  $listSearch('MAS_Ops_Dash_PJ_Open_List', 'MAS Cases Dash - Open Projects (list)', $openLabels, []),
  $listDisplay('MAS_Ops_Dash_PJ_Open_List'),

  $countSearch('MAS_Ops_Dash_PJ_ClosedQ', 'MAS Cases Dash - Projects Closed this Quarter', $closed, $thisQ),
  $countDisplay('MAS_Ops_Dash_PJ_ClosedQ', 'Closed this quarter', 'MAS_Ops_Dash_PJ_ClosedQ_List'),
  $listSearch('MAS_Ops_Dash_PJ_ClosedQ_List', 'MAS Cases Dash - Projects Closed this Quarter (list)', $closed, $thisQ),
  $listDisplay('MAS_Ops_Dash_PJ_ClosedQ_List'),

  $countSearch('MAS_Ops_Dash_PJ_ClosedY', 'MAS Cases Dash - Projects Closed this Year', $closed, $thisY),
  $countDisplay('MAS_Ops_Dash_PJ_ClosedY', 'Closed this year', 'MAS_Ops_Dash_PJ_ClosedY_List'),
  $listSearch('MAS_Ops_Dash_PJ_ClosedY_List', 'MAS Cases Dash - Projects Closed this Year (list)', $closed, $thisY),
  $listDisplay('MAS_Ops_Dash_PJ_ClosedY_List'),
  // Outcome pie (on the Closed-this-Year search): completion-vs-not mix.
  [
    'name' => 'SearchDisplay_MAS_Ops_Dash_PJ_ClosedY_Pie', 'entity' => 'SearchDisplay',
    'cleanup' => 'unused', 'update' => 'unmodified',
    'params' => ['version' => 4, 'values' => [
      'name' => 'MAS_Ops_Dash_PJ_ClosedY_Pie', 'label' => 'MAS Cases Dash - Projects Closed this Year (pie)',
      'saved_search_id.name' => 'MAS_Ops_Dash_PJ_ClosedY', 'type' => 'chart-kit',
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
