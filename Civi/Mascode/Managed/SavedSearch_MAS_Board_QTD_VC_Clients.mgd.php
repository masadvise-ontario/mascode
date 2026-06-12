<?php

declare(strict_types=1);

/**
 * Quarterly Board Dashboard — QTD metrics, VC + Client Organizations sections
 * (mas-lifecycle-dashboard-spec, Phase C board-metric pass / Vidula board
 * prep). Companion to SavedSearch_MAS_Board_QTD.mgd.php (rows 15-21); same
 * count-tile -> _List drill-down pattern.
 *
 * Covers board rows 1, 4, 7, 8, 9, 10, 11 and 13/14. Rows 2, 3, 6, 12 are
 * manually collected stats (per Brian 2026-06-10) and row 5's legacy search
 * was deleted from dev — recover from prod or recreate at the definition
 * pass. Row 14 rides the same Contribution search as 13 (SUM alongside
 * COUNT), reporting what is in CiviCRM; known data-sync gaps get fixed by
 * the data cleanup, not the search.
 *
 * Unlike the legacy 0N) searches these don't ride the period-parameterized
 * SK_*InASpecificPeriod / SK_HistoricalClients DB-entity plumbing — the
 * "new client" / "historical client" set logic is inlined as direct
 * INNER/EXCLUDE Case joins with relative-date tokens (the legacy plumbing's
 * own conditions, QTD-fixed). Intentional deviations from the legacy
 * definitions, all consequences of live-QTD vs run-after-quarter-end:
 *   - 01 drops the enrollment-before-reporting-quarter clause (live total);
 *   - 04 uses Enrollment_Date = this.quarter (legacy: previous.quarter);
 *   - 07 counts clients with a project open NOW (legacy: open at end of
 *     the reporting quarter);
 *   - "historical" = non-cancelled case started before this quarter within
 *     the legacy's now-2000-day lookback;
 *   - 09/10/11 count DISTINCT new projects (the board line is a project
 *     count; legacy row shapes counted contact/case groupings);
 *   - joined cases add an is_deleted guard (bridge joins bypass the
 *     trashed-case default; legacy only guarded 08).
 */

$contactLink = [
  'path' => 'civicrm/contact/view?reset=1&cid=[id]',
  'entity' => '', 'action' => '', 'join' => '', 'target' => '_blank', 'task' => '',
];

// Joined-case aliases: pj/sr = the new case this quarter; hist = prior history.
$srNew = [
  'Case AS Contact_Case_sr', 'INNER', 'CaseContact',
  ['id', '=', 'Contact_Case_sr.contact_id'],
  ['Contact_Case_sr.case_type_id:name', '=', '"service_request"'],
  ['Contact_Case_sr.start_date', '=', '"this.quarter"'],
  ['Contact_Case_sr.is_deleted', '!=', TRUE],
];
$pjNew = [
  'Case AS Contact_Case_pj', 'INNER', 'CaseContact',
  ['id', '=', 'Contact_Case_pj.contact_id'],
  ['Contact_Case_pj.case_type_id:name', '=', '"project"'],
  ['Contact_Case_pj.start_date', '=', '"this.quarter"'],
  ['Contact_Case_pj.status_id:name', '!=', '"Cancelled"'],
  ['Contact_Case_pj.is_deleted', '!=', TRUE],
];
// Prior-history conditions mirror the legacy SK_HistoricalClients plumbing
// (start before this quarter, not cancelled, now-2000-day lookback).
$histAny = [
  'Case AS Contact_Case_hist', 'EXCLUDE', 'CaseContact',
  ['id', '=', 'Contact_Case_hist.contact_id'],
  ['Contact_Case_hist.start_date', '!=', '"this.quarter"'],
  ['Contact_Case_hist.start_date', '>=', '"now - 2000 day"'],
  ['Contact_Case_hist.status_id:name', '!=', '"Cancelled"'],
  ['Contact_Case_hist.is_deleted', '!=', TRUE],
];
$histProjectConds = [
  ['id', '=', 'Contact_Case_hist.contact_id'],
  ['Contact_Case_hist.case_type_id:name', '=', '"project"'],
  ['Contact_Case_hist.start_date', '!=', '"this.quarter"'],
  ['Contact_Case_hist.start_date', '>=', '"now - 2000 day"'],
  ['Contact_Case_hist.status_id:name', '!=', '"Cancelled"'],
  ['Contact_Case_hist.is_deleted', '!=', TRUE],
];
$histProjectExclude = array_merge(['Case AS Contact_Case_hist', 'EXCLUDE', 'CaseContact'], $histProjectConds);
$histProjectInner = array_merge(['Case AS Contact_Case_hist', 'INNER', 'CaseContact'], $histProjectConds);

$marginalized = ['OR', [
  ['Organization.Client_Type:name', '=', 'BIPOC_Serving'],
  ['Organization.Client_Type:name', '=', 'Black_Serving'],
  ['Organization.Client_Type:name', '=', 'Indigenous_Serving'],
  ['Organization.Client_Type:name', '=', 'LGBTQ_Serving'],
  ['Organization.Client_Type:name', '=', 'Poverty_Serving'],
]];
$vcBase = [
  ['contact_sub_type:name', 'CONTAINS', 'MAS_Rep'],
  ['MAS_Rep.VC_Status:name', '=', 'Active'],
];

$search = function (string $ssName, string $label, string $entity, array $params): array {
  return [
    'name' => 'SavedSearch_' . $ssName, 'entity' => 'SavedSearch',
    'cleanup' => 'unused', 'update' => 'unmodified',
    'params' => ['version' => 4, 'values' => [
      'name' => $ssName, 'label' => $label, 'api_entity' => $entity,
      'api_params' => array_merge(['version' => 4, 'orderBy' => [], 'having' => []], $params),
    ], 'match' => ['name']],
  ];
};
$display = function (string $ssName, string $dispName, array $columns, array $settings = []): array {
  return [
    'name' => 'SearchDisplay_' . $dispName, 'entity' => 'SearchDisplay',
    'cleanup' => 'unused', 'update' => 'unmodified',
    'params' => ['version' => 4, 'values' => [
      'name' => $dispName, 'label' => str_replace('_', ' ', $dispName),
      'saved_search_id.name' => $ssName, 'type' => 'table',
      'settings' => array_merge([
        'description' => NULL, 'sort' => [], 'limit' => 50, 'pager' => ['hide_single' => TRUE],
        'columns' => $columns, 'actions' => FALSE, 'classes' => ['table', 'table-striped'],
      ], $settings),
    ], 'match' => ['name']],
  ];
};
$countCol = function (string $listName, string $label = 'QTD'): array {
  return ['type' => 'field', 'key' => 'c', 'label' => $label,
    'link' => ['path' => 'civicrm/search#/display/' . $listName . '/' . $listName,
      'entity' => '', 'action' => '', 'join' => '', 'target' => '_blank', 'task' => '']];
};
$caseCol = function (string $alias, string $key, string $label, bool $link = FALSE): array {
  $col = ['type' => 'field', 'key' => $alias . '.' . $key, 'label' => $label];
  if ($link) {
    $col['link'] = [
      'path' => 'civicrm/contact/view/case?reset=1&action=view&id=[' . $alias . '.id]&cid=[id]',
      'entity' => '', 'action' => '', 'join' => '', 'target' => '_blank', 'task' => '',
    ];
  }
  return $col;
};

return [
  // 01) VC's registered as active — live total.
  $search('MAS_Board_QTD_01_ActiveVCs', 'MAS Board - 01) VCs registered as active (now)', 'Contact',
    ['select' => ['COUNT(DISTINCT id) AS c'], 'where' => $vcBase, 'groupBy' => [], 'join' => []]),
  $display('MAS_Board_QTD_01_ActiveVCs', 'MAS_Board_QTD_01_ActiveVCs_Tile',
    [$countCol('MAS_Board_QTD_01_ActiveVCs_List', 'Now')], ['pager' => FALSE]),
  $search('MAS_Board_QTD_01_ActiveVCs_List', 'MAS Board - 01) VCs registered as active (list)', 'Contact',
    ['select' => ['id', 'sort_name', 'MAS_Rep.VC_Status:label', 'MAS_Rep.Enrollment_Date'],
      'where' => $vcBase, 'groupBy' => [], 'join' => []]),
  $display('MAS_Board_QTD_01_ActiveVCs_List', 'MAS_Board_QTD_01_ActiveVCs_List', [
    ['type' => 'field', 'key' => 'sort_name', 'label' => 'VC', 'link' => $contactLink],
    ['type' => 'field', 'key' => 'MAS_Rep.VC_Status:label', 'label' => 'Status'],
    ['type' => 'field', 'key' => 'MAS_Rep.Enrollment_Date', 'label' => 'Enrolled'],
  ], ['sort' => [['sort_name', 'ASC']]]),

  // 04) New VC's — enrolled this quarter.
  $search('MAS_Board_QTD_04_NewVCs', 'MAS Board - 04) New VCs (QTD)', 'Contact',
    ['select' => ['COUNT(DISTINCT id) AS c'],
      'where' => array_merge($vcBase, [['MAS_Rep.Enrollment_Date', '=', 'this.quarter']]),
      'groupBy' => [], 'join' => []]),
  $display('MAS_Board_QTD_04_NewVCs', 'MAS_Board_QTD_04_NewVCs_Tile',
    [$countCol('MAS_Board_QTD_04_NewVCs_List')], ['pager' => FALSE]),
  $search('MAS_Board_QTD_04_NewVCs_List', 'MAS Board - 04) New VCs (list)', 'Contact',
    ['select' => ['id', 'sort_name', 'MAS_Rep.VC_Status:label', 'MAS_Rep.Enrollment_Date'],
      'where' => array_merge($vcBase, [['MAS_Rep.Enrollment_Date', '=', 'this.quarter']]),
      'groupBy' => [], 'join' => []]),
  $display('MAS_Board_QTD_04_NewVCs_List', 'MAS_Board_QTD_04_NewVCs_List', [
    ['type' => 'field', 'key' => 'sort_name', 'label' => 'VC', 'link' => $contactLink],
    ['type' => 'field', 'key' => 'MAS_Rep.Enrollment_Date', 'label' => 'Enrolled'],
  ], ['sort' => [['MAS_Rep.Enrollment_Date', 'ASC']]]),

  // 07) Clients with an open project — now (legacy: at end of quarter).
  $search('MAS_Board_QTD_07_ClientsOpenProject', 'MAS Board - 07) Clients with an open project (now)', 'Contact',
    ['select' => ['COUNT(DISTINCT id) AS c'],
      'where' => [
        ['Contact_Case_pj.status_id:label', 'IN', ['Active', 'On Hold', 'Awaiting Project Definition', 'Awaiting VC Project Close Form', 'Awaiting Client Project Close Form']],
        ['id', '!=', 1],
      ],
      'groupBy' => [],
      'join' => [[
        'Case AS Contact_Case_pj', 'INNER', 'CaseContact',
        ['id', '=', 'Contact_Case_pj.contact_id'],
        ['Contact_Case_pj.case_type_id:name', '=', '"project"'],
        ['Contact_Case_pj.is_deleted', '!=', TRUE],
      ]]]),
  $display('MAS_Board_QTD_07_ClientsOpenProject', 'MAS_Board_QTD_07_ClientsOpenProject_Tile',
    [$countCol('MAS_Board_QTD_07_ClientsOpenProject_List', 'Now')], ['pager' => FALSE]),
  $search('MAS_Board_QTD_07_ClientsOpenProject_List', 'MAS Board - 07) Clients with an open project (list)', 'Contact',
    ['select' => ['id', 'sort_name', 'GROUP_CONCAT(DISTINCT Contact_Case_pj.subject) AS projects'],
      'where' => [
        ['Contact_Case_pj.status_id:label', 'IN', ['Active', 'On Hold', 'Awaiting Project Definition', 'Awaiting VC Project Close Form', 'Awaiting Client Project Close Form']],
        ['id', '!=', 1],
      ],
      'groupBy' => ['id'],
      'join' => [[
        'Case AS Contact_Case_pj', 'INNER', 'CaseContact',
        ['id', '=', 'Contact_Case_pj.contact_id'],
        ['Contact_Case_pj.case_type_id:name', '=', '"project"'],
        ['Contact_Case_pj.is_deleted', '!=', TRUE],
      ]]]),
  $display('MAS_Board_QTD_07_ClientsOpenProject_List', 'MAS_Board_QTD_07_ClientsOpenProject_List', [
    ['type' => 'field', 'key' => 'sort_name', 'label' => 'Client', 'link' => $contactLink],
    ['type' => 'field', 'key' => 'projects', 'label' => 'Open projects'],
  ], ['sort' => [['sort_name', 'ASC']]]),

  // 08) New Service Requests from new clients (no prior case history).
  $search('MAS_Board_QTD_08_NewSRsNewClients', 'MAS Board - 08) New SRs from new clients (QTD)', 'Contact',
    ['select' => ['COUNT(DISTINCT Contact_Case_sr.id) AS c'],
      'where' => [], 'groupBy' => [], 'join' => [$srNew, $histAny]]),
  $display('MAS_Board_QTD_08_NewSRsNewClients', 'MAS_Board_QTD_08_NewSRsNewClients_Tile',
    [$countCol('MAS_Board_QTD_08_NewSRsNewClients_List')], ['pager' => FALSE]),
  $search('MAS_Board_QTD_08_NewSRsNewClients_List', 'MAS Board - 08) New SRs from new clients (list)', 'Contact',
    ['select' => ['id', 'sort_name', 'Contact_Case_sr.id', 'Contact_Case_sr.subject', 'Contact_Case_sr.start_date'],
      'where' => [], 'groupBy' => [], 'join' => [$srNew, $histAny]]),
  $display('MAS_Board_QTD_08_NewSRsNewClients_List', 'MAS_Board_QTD_08_NewSRsNewClients_List', [
    ['type' => 'field', 'key' => 'sort_name', 'label' => 'Client', 'link' => $contactLink],
    $caseCol('Contact_Case_sr', 'subject', 'Subject', TRUE),
    $caseCol('Contact_Case_sr', 'start_date', 'Received'),
  ], ['sort' => [['Contact_Case_sr.start_date', 'ASC']]]),

  // 09) New Projects for clients we have not done a project for.
  $search('MAS_Board_QTD_09_NewProjectsNewClients', 'MAS Board - 09) New projects for new clients (QTD)', 'Contact',
    ['select' => ['COUNT(DISTINCT Contact_Case_pj.id) AS c'],
      'where' => [['id', '!=', 1]], 'groupBy' => [], 'join' => [$pjNew, $histProjectExclude]]),
  $display('MAS_Board_QTD_09_NewProjectsNewClients', 'MAS_Board_QTD_09_NewProjectsNewClients_Tile',
    [$countCol('MAS_Board_QTD_09_NewProjectsNewClients_List')], ['pager' => FALSE]),
  $search('MAS_Board_QTD_09_NewProjectsNewClients_List', 'MAS Board - 09) New projects for new clients (list)', 'Contact',
    ['select' => ['id', 'sort_name', 'Contact_Case_pj.id', 'Contact_Case_pj.subject', 'Contact_Case_pj.start_date', 'Contact_Case_pj.status_id:label'],
      'where' => [['id', '!=', 1]], 'groupBy' => [], 'join' => [$pjNew, $histProjectExclude]]),
  $display('MAS_Board_QTD_09_NewProjectsNewClients_List', 'MAS_Board_QTD_09_NewProjectsNewClients_List', [
    ['type' => 'field', 'key' => 'sort_name', 'label' => 'Client', 'link' => $contactLink],
    $caseCol('Contact_Case_pj', 'subject', 'Project', TRUE),
    $caseCol('Contact_Case_pj', 'status_id:label', 'Status'),
    $caseCol('Contact_Case_pj', 'start_date', 'Started'),
  ], ['sort' => [['Contact_Case_pj.start_date', 'ASC']]]),

  // 10) New Projects for existing clients (prior project history).
  $search('MAS_Board_QTD_10_NewProjectsExistingClients', 'MAS Board - 10) New projects for existing clients (QTD)', 'Contact',
    ['select' => ['COUNT(DISTINCT Contact_Case_pj.id) AS c'],
      'where' => [['id', '!=', 1]], 'groupBy' => [], 'join' => [$pjNew, $histProjectInner]]),
  $display('MAS_Board_QTD_10_NewProjectsExistingClients', 'MAS_Board_QTD_10_NewProjectsExistingClients_Tile',
    [$countCol('MAS_Board_QTD_10_NewProjectsExistingClients_List')], ['pager' => FALSE]),
  $search('MAS_Board_QTD_10_NewProjectsExistingClients_List', 'MAS Board - 10) New projects for existing clients (list)', 'Contact',
    ['select' => ['id', 'sort_name', 'GROUP_CONCAT(DISTINCT Contact_Case_pj.id) AS pj_ids', 'GROUP_CONCAT(DISTINCT Contact_Case_pj.subject) AS pj_subjects', 'GROUP_CONCAT(DISTINCT Contact_Case_pj.start_date) AS pj_started'],
      'where' => [['id', '!=', 1]], 'groupBy' => ['id'], 'join' => [$pjNew, $histProjectInner]]),
  $display('MAS_Board_QTD_10_NewProjectsExistingClients_List', 'MAS_Board_QTD_10_NewProjectsExistingClients_List', [
    ['type' => 'field', 'key' => 'sort_name', 'label' => 'Client', 'link' => $contactLink],
    ['type' => 'field', 'key' => 'pj_subjects', 'label' => 'New projects'],
    ['type' => 'field', 'key' => 'pj_started', 'label' => 'Started'],
  ], ['sort' => [['sort_name', 'ASC']]]),

  // 11) New Projects for marginalized-serving/BIPOC clients.
  $search('MAS_Board_QTD_11_NewProjectsMarginalized', 'MAS Board - 11) New projects for marginalized-serving clients (QTD)', 'Contact',
    ['select' => ['COUNT(DISTINCT Contact_Case_pj.id) AS c'],
      'where' => [$marginalized, ['id', '!=', 1]], 'groupBy' => [], 'join' => [$pjNew]]),
  $display('MAS_Board_QTD_11_NewProjectsMarginalized', 'MAS_Board_QTD_11_NewProjectsMarginalized_Tile',
    [$countCol('MAS_Board_QTD_11_NewProjectsMarginalized_List')], ['pager' => FALSE]),
  $search('MAS_Board_QTD_11_NewProjectsMarginalized_List', 'MAS Board - 11) New projects for marginalized-serving clients (list)', 'Contact',
    ['select' => ['id', 'sort_name', 'Organization.Client_Type:label', 'Contact_Case_pj.id', 'Contact_Case_pj.subject', 'Contact_Case_pj.start_date'],
      'where' => [$marginalized, ['id', '!=', 1]], 'groupBy' => [], 'join' => [$pjNew]]),
  $display('MAS_Board_QTD_11_NewProjectsMarginalized_List', 'MAS_Board_QTD_11_NewProjectsMarginalized_List', [
    ['type' => 'field', 'key' => 'sort_name', 'label' => 'Client', 'link' => $contactLink],
    ['type' => 'field', 'key' => 'Organization.Client_Type:label', 'label' => 'Client type'],
    $caseCol('Contact_Case_pj', 'subject', 'Project', TRUE),
    $caseCol('Contact_Case_pj', 'start_date', 'Started'),
  ], ['sort' => [['Contact_Case_pj.start_date', 'ASC']]]),

  // 13) # of Client Donations + 14) Amount of Client Donations — one search.
  // Reports what is in CiviCRM; board pack flags data sync, cleanup pending.
  $search('MAS_Board_QTD_13_14_ClientDonations', 'MAS Board - 13/14) Client donations count + amount (QTD)', 'Contribution',
    ['select' => ['COUNT(id) AS c', 'SUM(total_amount) AS amount_total'],
      'where' => [['receive_date', '=', 'this.quarter'], ['financial_type_id:name', '=', 'Donation']],
      'groupBy' => [], 'join' => []]),
  $display('MAS_Board_QTD_13_14_ClientDonations', 'MAS_Board_QTD_13_14_ClientDonations_Tile', [
    $countCol('MAS_Board_QTD_13_14_ClientDonations_List', 'Donations (QTD)'),
    ['type' => 'field', 'key' => 'amount_total', 'label' => 'Amount (QTD)'],
  ], ['pager' => FALSE]),
  $search('MAS_Board_QTD_13_14_ClientDonations_List', 'MAS Board - 13/14) Client donations (list)', 'Contribution',
    ['select' => ['id', 'receive_date', 'contact_id', 'contact_id.sort_name', 'total_amount', 'financial_type_id:label'],
      'where' => [['receive_date', '=', 'this.quarter'], ['financial_type_id:name', '=', 'Donation']],
      'groupBy' => [], 'join' => []]),
  $display('MAS_Board_QTD_13_14_ClientDonations_List', 'MAS_Board_QTD_13_14_ClientDonations_List', [
    ['type' => 'field', 'key' => 'contact_id.sort_name', 'label' => 'Donor',
      'link' => ['path' => 'civicrm/contact/view?reset=1&cid=[contact_id]',
        'entity' => '', 'action' => '', 'join' => '', 'target' => '_blank', 'task' => '']],
    ['type' => 'field', 'key' => 'receive_date', 'label' => 'Received'],
    ['type' => 'field', 'key' => 'total_amount', 'label' => 'Amount'],
  ], ['sort' => [['receive_date', 'ASC']]]),
];
