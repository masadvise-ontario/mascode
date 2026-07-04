<?php

declare(strict_types=1);

/**
 * Quarterly Board Dashboard — QTD + previous-quarter (PQ) metrics, VC + Client
 * Organizations sections (mas-lifecycle-dashboard-spec, Phase C board-metric
 * pass / Vidula board prep). Companion to SavedSearch_MAS_Board_QTD.mgd.php
 * (rows 15-21); same count-tile -> _List drill-down pattern.
 *
 * Covers board rows 1, 4, 5, 7, 8, 9, 10, 11 and 13/14. Rows 2, 3, 6, 12 are
 * manually collected stats (per Brian 2026-06-10). Row 5 (VCs with an open
 * project during the quarter) is rebuilt here as a clean generalization of the
 * legacy 05) Active VC's search (its SK_CasesActiveInASpecificPeriod-based
 * definition was deleted from dev). Row 14 rides the same Contribution search
 * as 13 (SUM alongside COUNT), reporting what is in CiviCRM; known data-sync
 * gaps get fixed by the data cleanup, not the search.
 *
 * Two families are emitted from one $buildFamily spec: QTD -> this.quarter
 * (names MAS_Board_QTD_*, unchanged) and PQ -> previous.quarter (names
 * MAS_Board_PQ_*). The dashboard page (afformMASBoardDashboard) shows the PQ
 * tiles as a second "Previous Quarter" column beside the QTD tiles.
 *
 * Unlike the legacy 0N) searches these don't ride the period-parameterized
 * SK_*InASpecificPeriod / SK_HistoricalClients DB-entity plumbing — the
 * "new client" / "historical client" set logic is inlined as direct
 * INNER/EXCLUDE Case joins with relative-date tokens. Intentional deviations
 * from the legacy definitions, all consequences of live-QTD vs
 * run-after-quarter-end:
 *   - 01 drops the enrollment-before-reporting-quarter clause (live total);
 *   - 04 uses Enrollment_Date = <period> (legacy: previous.quarter);
 *   - 05/07 count VCs / clients with a project active at any point DURING the
 *     quarter (interval overlap — see $activeInPeriod), not a Now snapshot;
 *   - "historical" = non-cancelled case started before the reporting quarter
 *     within the legacy's now-2000-day lookback;
 *   - 09/10/11 count DISTINCT new projects (the board line is a project
 *     count; legacy row shapes counted contact/case groupings);
 *   - joined cases add an is_deleted guard (bridge joins bypass the
 *     trashed-case default; legacy only guarded 08).
 *
 * Point-in-time rule (per Brian): a metric reporting a "Now" snapshot in QTD
 * reports, in PQ, the count AS AT THE END of the previous quarter — not a
 * this->previous token swap. In this file only row 01 is such a metric (row 20
 * lives in SavedSearch_MAS_Board_QTD.mgd.php):
 *   - 01 VCs registered as active: a TRUE point-in-time count is impossible —
 *     MAS_Rep.VC_Status is current-state only, with no effective-dated history.
 *     PQ uses the best available PROXY: currently-active VCs enrolled on/before
 *     the end of the previous quarter. It over-counts VCs deactivated since and
 *     under-counts VCs active-then-but-departed. Footnoted on the page. Fix
 *     properly only if VC status gains history (e.g. a status-change log).
 * Rows 05 and 07 are NOT Now-snapshots: they count VCs / clients whose project
 * was active at any point during the reporting quarter (interval overlap), so
 * QTD and PQ differ only by the period token.
 * Relative-date boundary identities used (dev-verified 2026-07-04):
 *   x <= previous.quarter  ==  x <  this.quarter   (end of previous quarter)
 *   x >  previous.quarter  ==  x >= this.quarter   (start of this quarter)
 * The new-vs-existing-client history test therefore uses "< previous.quarter"
 * in PQ (a case started AFTER the reporting quarter must NOT count as prior
 * history) versus "!= this.quarter" in QTD (no future cases exist).
 */

$contactLink = [
  'path' => 'civicrm/contact/view?reset=1&cid=[id]',
  'entity' => '', 'action' => '', 'join' => '', 'target' => '_blank', 'task' => '',
];

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
$openSet = ['Active', 'On Hold', 'Awaiting VC Project Definition', 'Awaiting Client Project Definition', 'Awaiting VC Project Close Form', 'Awaiting Client Project Close Form'];

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
        'columns' => $columns, 'actions' => ['download'], 'classes' => ['table', 'table-striped'],
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

// --- Standardized drill-down list templates (Brian's column spec) ---
$caseLinkFor = function (string $alias): array {
  return ['path' => 'civicrm/contact/view/case?reset=1&action=view&id=[' . $alias . '.id]&cid=[id]',
    'entity' => '', 'action' => '', 'join' => '', 'target' => '_blank', 'task' => ''];
};
// MAS Rep = the contact with the "Case Coordinator for" role on the case
// (RelationshipCache b_a "Case Coordinator" row -> far_contact is the rep).
$masRepJoinFor = function (string $alias): array {
  return ['RelationshipCache AS masrep', 'LEFT',
    [$alias . '.id', '=', 'masrep.case_id'],
    ['masrep.near_relation:name', '=', '"Case Coordinator"']];
};
// Project / Service-Request drill-down list: one row per case, standard columns
// (MAS Code, Client, subject, Status, Start, End, MAS Rep). Base entity is
// Contact (= the client); $alias is the joined project/SR case and $baseJoins
// carries the metric's own filter joins so the drilled records match the count.
$stdCaseList = function (string $ssName, string $label, string $alias, string $codeField, string $subjectLabel, array $where, array $baseJoins)
  use ($search, $display, $contactLink, $caseLinkFor, $masRepJoinFor): array {
  $codeKey = $alias . '.' . $codeField;
  return [
    $search($ssName, $label, 'Contact', [
      'select' => ['id', 'sort_name', $alias . '.id', $codeKey, $alias . '.subject',
        $alias . '.status_id:label', $alias . '.start_date', $alias . '.end_date',
        'GROUP_CONCAT(DISTINCT masrep.far_contact_id.sort_name) AS mas_rep'],
      'where' => $where, 'groupBy' => [$alias . '.id'],
      'join' => array_merge($baseJoins, [$masRepJoinFor($alias)])]),
    $display($ssName, $ssName, [
      ['type' => 'field', 'key' => $codeKey, 'label' => 'MAS Code', 'link' => $caseLinkFor($alias)],
      ['type' => 'field', 'key' => 'sort_name', 'label' => 'Client', 'link' => $contactLink],
      ['type' => 'field', 'key' => $alias . '.subject', 'label' => $subjectLabel],
      ['type' => 'field', 'key' => $alias . '.status_id:label', 'label' => 'Status'],
      ['type' => 'field', 'key' => $alias . '.start_date', 'label' => 'Start Date'],
      ['type' => 'field', 'key' => $alias . '.end_date', 'label' => 'End Date'],
      ['type' => 'field', 'key' => 'mas_rep', 'label' => 'MAS Rep'],
    ], ['sort' => [[$alias . '.start_date', 'ASC']]]),
  ];
};

/**
 * Build the full entity list for one period family.
 *   $fam    'QTD' or 'PQ'
 *   $period the in-period relative-date token ('this.quarter' | 'previous.quarter')
 *   $histSelf the self-exclusion condition on the prior-history join alias
 *     (QTD: != this.quarter; PQ: < previous.quarter — see file header)
 * With $fam = 'QTD' this reproduces the original definitions byte-for-byte.
 */
$buildFamily = function (string $fam, string $period, array $histSelf)
  use ($contactLink, $marginalized, $vcBase, $openSet, $search, $display, $countCol, $caseCol, $stdCaseList): array {

  // Count-column labels: swap metrics use QTD/Prev Q; Now-snapshot metrics
  // (01, 07) use Now / End of prev Q.
  $cl = $fam === 'QTD' ? 'QTD' : 'Prev Q';
  $nowCl = $fam === 'QTD' ? 'Now' : 'End of prev Q';
  $lab = $fam;                                  // "(QTD)" / "(PQ)"
  $nowLab = $fam === 'QTD' ? 'now' : 'end of prev Q';

  // Joined-case aliases: pj/sr = the new case this period; hist = prior history.
  $srNew = [
    'Case AS Contact_Case_sr', 'INNER', 'CaseContact',
    ['id', '=', 'Contact_Case_sr.contact_id'],
    ['Contact_Case_sr.case_type_id:name', '=', '"service_request"'],
    ['Contact_Case_sr.start_date', '=', '"' . $period . '"'],
    ['Contact_Case_sr.is_deleted', '!=', TRUE],
  ];
  $pjNew = [
    'Case AS Contact_Case_pj', 'INNER', 'CaseContact',
    ['id', '=', 'Contact_Case_pj.contact_id'],
    ['Contact_Case_pj.case_type_id:name', '=', '"project"'],
    ['Contact_Case_pj.start_date', '=', '"' . $period . '"'],
    ['Contact_Case_pj.status_id:name', '!=', '"Cancelled"'],
    ['Contact_Case_pj.is_deleted', '!=', TRUE],
  ];
  // Prior-history conditions mirror the legacy SK_HistoricalClients plumbing
  // (started before the reporting quarter, not cancelled, now-2000-day lookback).
  $histAny = [
    'Case AS Contact_Case_hist', 'EXCLUDE', 'CaseContact',
    ['id', '=', 'Contact_Case_hist.contact_id'],
    $histSelf,
    ['Contact_Case_hist.start_date', '>=', '"now - 2000 day"'],
    ['Contact_Case_hist.status_id:name', '!=', '"Cancelled"'],
    ['Contact_Case_hist.is_deleted', '!=', TRUE],
  ];
  $histProjectConds = [
    ['id', '=', 'Contact_Case_hist.contact_id'],
    ['Contact_Case_hist.case_type_id:name', '=', '"project"'],
    $histSelf,
    ['Contact_Case_hist.start_date', '>=', '"now - 2000 day"'],
    ['Contact_Case_hist.status_id:name', '!=', '"Cancelled"'],
    ['Contact_Case_hist.is_deleted', '!=', TRUE],
  ];
  $histProjectExclude = array_merge(['Case AS Contact_Case_hist', 'EXCLUDE', 'CaseContact'], $histProjectConds);
  $histProjectInner = array_merge(['Case AS Contact_Case_hist', 'INNER', 'CaseContact'], $histProjectConds);

  // Row 01 "VCs registered as active" (a Now-snapshot metric): QTD = live
  // total; PQ = proxy (active now AND enrolled by end of previous quarter —
  // see file header caveat).
  $vc01Where = $vcBase;
  if ($fam !== 'QTD') {
    $vc01Where = array_merge($vcBase, [['MAS_Rep.Enrollment_Date', '<=', 'previous.quarter']]);
  }

  // "Active during the period" = the case interval overlaps the reporting
  // quarter: started on/before the quarter end AND not closed before the
  // quarter start (i.e. still open at some point during the quarter). Both
  // comparisons resolve against $period. Used by rows 5 and 7 — which are NOT
  // Now-snapshots (only rows 1 and 20 are).
  $activeInPeriod = function (string $alias) use ($period): array {
    $p = $alias === '' ? '' : $alias . '.';
    return [
      [$p . 'start_date', '<=', $period],
      ['OR', [[$p . 'end_date', '>=', $period], [$p . 'end_date', 'IS EMPTY']]],
    ];
  };

  // Row 05 "VCs with an open project during the quarter" — DISTINCT case
  // coordinators (the "Case Coordinator" role contact; the coordinator IS the
  // VC in this model — see file header) on project cases active during the
  // period.
  //
  // NB: unlike every other search here this one's BASE entity is Case, not
  // Contact. The RelationshipCache *bridge* from Contact to Case shadows
  // Case.start_date / Case.end_date with RelationshipCache's own (usually NULL)
  // start/end columns, so a date filter on a bridged Case alias silently
  // matches nothing (dev-confirmed: even an absolute date returned 0). Basing
  // the search on Case keeps the interval filter on real Case date fields; the
  // VC is then rc.far_contact_id and we COUNT DISTINCT it. This matches the
  // intent of the legacy 05) Active VC's search (no sub_type/VC_Status filter);
  // it is NOT identical to it — the legacy search rode a period-drifted
  // SK_CasesActiveInASpecificPeriod DB-entity, so it both over- and
  // under-counts vs a true previous-quarter interval.
  $vc05Join = [[
    'RelationshipCache AS rc', 'INNER',
    ['id', '=', 'rc.case_id'],
    ['rc.near_relation:name', '=', '"Case Coordinator"'],
  ]];
  $vc05Where = array_merge(
    [['case_type_id:name', '=', 'project']],
    $activeInPeriod(''),
    [['is_deleted', '=', FALSE], ['rc.far_contact_id', '!=', 1]]
  );

  // Row 07 "Clients with an open project during the quarter" — client contacts
  // on project cases active during the period (interval overlap, not a Now
  // snapshot).
  $pj07Join = [[
    'Case AS Contact_Case_pj', 'INNER', 'CaseContact',
    ['id', '=', 'Contact_Case_pj.contact_id'],
    ['Contact_Case_pj.case_type_id:name', '=', '"project"'],
    ['Contact_Case_pj.is_deleted', '!=', TRUE],
  ]];
  $open07Where = array_merge($activeInPeriod('Contact_Case_pj'), [['id', '!=', 1]]);

  return [
    // 01) VC's registered as active.
    $search("MAS_Board_{$fam}_01_ActiveVCs", "MAS Board - 01) VCs registered as active ({$nowLab})", 'Contact',
      ['select' => ['COUNT(DISTINCT id) AS c'], 'where' => $vc01Where, 'groupBy' => [], 'join' => []]),
    $display("MAS_Board_{$fam}_01_ActiveVCs", "MAS_Board_{$fam}_01_ActiveVCs_Tile",
      [$countCol("MAS_Board_{$fam}_01_ActiveVCs_List", $nowCl)], ['pager' => FALSE, 'actions' => FALSE]),
    $search("MAS_Board_{$fam}_01_ActiveVCs_List", "MAS Board - 01) VCs registered as active (list)", 'Contact',
      ['select' => ['id', 'sort_name', 'MAS_Rep.VC_Status:label', 'MAS_Rep.Enrollment_Date'],
        'where' => $vc01Where, 'groupBy' => [], 'join' => []]),
    $display("MAS_Board_{$fam}_01_ActiveVCs_List", "MAS_Board_{$fam}_01_ActiveVCs_List", [
      ['type' => 'field', 'key' => 'sort_name', 'label' => 'VC', 'link' => $contactLink],
      ['type' => 'field', 'key' => 'MAS_Rep.VC_Status:label', 'label' => 'Status'],
      ['type' => 'field', 'key' => 'MAS_Rep.Enrollment_Date', 'label' => 'Enrolled Date'],
    ], ['sort' => [['sort_name', 'ASC']]]),

    // 04) New VC's — enrolled in the period.
    $search("MAS_Board_{$fam}_04_NewVCs", "MAS Board - 04) New VCs ({$lab})", 'Contact',
      ['select' => ['COUNT(DISTINCT id) AS c'],
        'where' => array_merge($vcBase, [['MAS_Rep.Enrollment_Date', '=', $period]]),
        'groupBy' => [], 'join' => []]),
    $display("MAS_Board_{$fam}_04_NewVCs", "MAS_Board_{$fam}_04_NewVCs_Tile",
      [$countCol("MAS_Board_{$fam}_04_NewVCs_List", $cl)], ['pager' => FALSE, 'actions' => FALSE]),
    $search("MAS_Board_{$fam}_04_NewVCs_List", "MAS Board - 04) New VCs (list)", 'Contact',
      ['select' => ['id', 'sort_name', 'MAS_Rep.VC_Status:label', 'MAS_Rep.Enrollment_Date'],
        'where' => array_merge($vcBase, [['MAS_Rep.Enrollment_Date', '=', $period]]),
        'groupBy' => [], 'join' => []]),
    $display("MAS_Board_{$fam}_04_NewVCs_List", "MAS_Board_{$fam}_04_NewVCs_List", [
      ['type' => 'field', 'key' => 'sort_name', 'label' => 'VC', 'link' => $contactLink],
      ['type' => 'field', 'key' => 'MAS_Rep.VC_Status:label', 'label' => 'Status'],
      ['type' => 'field', 'key' => 'MAS_Rep.Enrollment_Date', 'label' => 'Enrolled Date'],
    ], ['sort' => [['MAS_Rep.Enrollment_Date', 'ASC']]]),

    // 05) VCs with an open project at any point during the quarter. Base entity
    // is Case (see $vc05Join note); the VC is rc.far_contact_id.
    $search("MAS_Board_{$fam}_05_VCsWithOpenProject", "MAS Board - 05) VCs with an open project during the quarter ({$lab})", 'Case',
      ['select' => ['COUNT(DISTINCT rc.far_contact_id) AS c'], 'where' => $vc05Where, 'groupBy' => [], 'join' => $vc05Join]),
    $display("MAS_Board_{$fam}_05_VCsWithOpenProject", "MAS_Board_{$fam}_05_VCsWithOpenProject_Tile",
      [$countCol("MAS_Board_{$fam}_05_VCsWithOpenProject_List", $cl)], ['pager' => FALSE, 'actions' => FALSE]),
    $search("MAS_Board_{$fam}_05_VCsWithOpenProject_List", "MAS Board - 05) VCs with an open project during the quarter (list)", 'Case',
      ['select' => ['rc.far_contact_id', 'rc.far_contact_id.sort_name',
        'rc.far_contact_id.MAS_Rep.VC_Status:label', 'rc.far_contact_id.MAS_Rep.Enrollment_Date'],
        'where' => $vc05Where, 'groupBy' => ['rc.far_contact_id'], 'join' => $vc05Join]),
    $display("MAS_Board_{$fam}_05_VCsWithOpenProject_List", "MAS_Board_{$fam}_05_VCsWithOpenProject_List", [
      ['type' => 'field', 'key' => 'rc.far_contact_id.sort_name', 'label' => 'VC',
        'link' => ['path' => 'civicrm/contact/view?reset=1&cid=[rc.far_contact_id]', 'entity' => '', 'action' => '', 'join' => '', 'target' => '_blank', 'task' => '']],
      ['type' => 'field', 'key' => 'rc.far_contact_id.MAS_Rep.VC_Status:label', 'label' => 'Status'],
      ['type' => 'field', 'key' => 'rc.far_contact_id.MAS_Rep.Enrollment_Date', 'label' => 'Enrolled Date'],
    ], ['sort' => [['rc.far_contact_id.sort_name', 'ASC']]]),

    // 07) Clients with an open project at any point during the quarter.
    $search("MAS_Board_{$fam}_07_ClientsOpenProject", "MAS Board - 07) Clients with an open project during the quarter ({$lab})", 'Contact',
      ['select' => ['COUNT(DISTINCT id) AS c'], 'where' => $open07Where, 'groupBy' => [], 'join' => $pj07Join]),
    $display("MAS_Board_{$fam}_07_ClientsOpenProject", "MAS_Board_{$fam}_07_ClientsOpenProject_Tile",
      [$countCol("MAS_Board_{$fam}_07_ClientsOpenProject_List", $cl)], ['pager' => FALSE, 'actions' => FALSE]),
    // Drill-down lists the active projects (project template). The tile counts
    // DISTINCT clients, so the list can hold more rows than the count.
    ...$stdCaseList("MAS_Board_{$fam}_07_ClientsOpenProject_List", "MAS Board - 07) Clients with an open project during the quarter (list)",
      'Contact_Case_pj', 'Projects.MAS_Project_Case_Code', 'Project', $open07Where, $pj07Join),

    // 08) New Service Requests from new clients (no prior case history).
    $search("MAS_Board_{$fam}_08_NewSRsNewClients", "MAS Board - 08) New SRs from new clients ({$lab})", 'Contact',
      ['select' => ['COUNT(DISTINCT Contact_Case_sr.id) AS c'],
        'where' => [], 'groupBy' => [], 'join' => [$srNew, $histAny]]),
    $display("MAS_Board_{$fam}_08_NewSRsNewClients", "MAS_Board_{$fam}_08_NewSRsNewClients_Tile",
      [$countCol("MAS_Board_{$fam}_08_NewSRsNewClients_List", $cl)], ['pager' => FALSE, 'actions' => FALSE]),
    ...$stdCaseList("MAS_Board_{$fam}_08_NewSRsNewClients_List", "MAS Board - 08) New SRs from new clients (list)",
      'Contact_Case_sr', 'Cases_SR_Projects_.MAS_SR_Case_Code', 'Service Request', [], [$srNew, $histAny]),

    // 09) New Projects for clients we have not done a project for.
    $search("MAS_Board_{$fam}_09_NewProjectsNewClients", "MAS Board - 09) New projects for new clients ({$lab})", 'Contact',
      ['select' => ['COUNT(DISTINCT Contact_Case_pj.id) AS c'],
        'where' => [['id', '!=', 1]], 'groupBy' => [], 'join' => [$pjNew, $histProjectExclude]]),
    $display("MAS_Board_{$fam}_09_NewProjectsNewClients", "MAS_Board_{$fam}_09_NewProjectsNewClients_Tile",
      [$countCol("MAS_Board_{$fam}_09_NewProjectsNewClients_List", $cl)], ['pager' => FALSE, 'actions' => FALSE]),
    ...$stdCaseList("MAS_Board_{$fam}_09_NewProjectsNewClients_List", "MAS Board - 09) New projects for new clients (list)",
      'Contact_Case_pj', 'Projects.MAS_Project_Case_Code', 'Project', [['id', '!=', 1]], [$pjNew, $histProjectExclude]),

    // 10) New Projects for existing clients (prior project history).
    $search("MAS_Board_{$fam}_10_NewProjectsExistingClients", "MAS Board - 10) New projects for existing clients ({$lab})", 'Contact',
      ['select' => ['COUNT(DISTINCT Contact_Case_pj.id) AS c'],
        'where' => [['id', '!=', 1]], 'groupBy' => [], 'join' => [$pjNew, $histProjectInner]]),
    $display("MAS_Board_{$fam}_10_NewProjectsExistingClients", "MAS_Board_{$fam}_10_NewProjectsExistingClients_Tile",
      [$countCol("MAS_Board_{$fam}_10_NewProjectsExistingClients_List", $cl)], ['pager' => FALSE, 'actions' => FALSE]),
    ...$stdCaseList("MAS_Board_{$fam}_10_NewProjectsExistingClients_List", "MAS Board - 10) New projects for existing clients (list)",
      'Contact_Case_pj', 'Projects.MAS_Project_Case_Code', 'Project', [['id', '!=', 1]], [$pjNew, $histProjectInner]),

    // 11) New Projects for marginalized-serving/BIPOC clients.
    $search("MAS_Board_{$fam}_11_NewProjectsMarginalized", "MAS Board - 11) New projects for marginalized-serving clients ({$lab})", 'Contact',
      ['select' => ['COUNT(DISTINCT Contact_Case_pj.id) AS c'],
        'where' => [$marginalized, ['id', '!=', 1]], 'groupBy' => [], 'join' => [$pjNew]]),
    $display("MAS_Board_{$fam}_11_NewProjectsMarginalized", "MAS_Board_{$fam}_11_NewProjectsMarginalized_Tile",
      [$countCol("MAS_Board_{$fam}_11_NewProjectsMarginalized_List", $cl)], ['pager' => FALSE, 'actions' => FALSE]),
    ...$stdCaseList("MAS_Board_{$fam}_11_NewProjectsMarginalized_List", "MAS Board - 11) New projects for marginalized-serving clients (list)",
      'Contact_Case_pj', 'Projects.MAS_Project_Case_Code', 'Project', [$marginalized, ['id', '!=', 1]], [$pjNew]),

    // 13) # of Client Donations + 14) Amount of Client Donations — one search.
    // Reports what is in CiviCRM; board pack flags data sync, cleanup pending.
    $search("MAS_Board_{$fam}_13_14_ClientDonations", "MAS Board - 13/14) Client donations count + amount ({$lab})", 'Contribution',
      ['select' => ['COUNT(id) AS c', 'SUM(total_amount) AS amount_total'],
        'where' => [['receive_date', '=', $period], ['financial_type_id:name', '=', 'Donation']],
        'groupBy' => [], 'join' => []]),
    $display("MAS_Board_{$fam}_13_14_ClientDonations", "MAS_Board_{$fam}_13_14_ClientDonations_Tile", [
      $countCol("MAS_Board_{$fam}_13_14_ClientDonations_List", "Donations ({$cl})"),
      ['type' => 'field', 'key' => 'amount_total', 'label' => "Amount ({$cl})"],
    ], ['pager' => FALSE, 'actions' => FALSE]),
    $search("MAS_Board_{$fam}_13_14_ClientDonations_List", "MAS Board - 13/14) Client donations (list)", 'Contribution',
      ['select' => ['id', 'receive_date', 'contact_id', 'contact_id.sort_name', 'total_amount', 'financial_type_id:label'],
        'where' => [['receive_date', '=', $period], ['financial_type_id:name', '=', 'Donation']],
        'groupBy' => [], 'join' => []]),
    $display("MAS_Board_{$fam}_13_14_ClientDonations_List", "MAS_Board_{$fam}_13_14_ClientDonations_List", [
      ['type' => 'field', 'key' => 'contact_id.sort_name', 'label' => 'Donor',
        'link' => ['path' => 'civicrm/contact/view?reset=1&cid=[contact_id]',
          'entity' => '', 'action' => '', 'join' => '', 'target' => '_blank', 'task' => '']],
      ['type' => 'field', 'key' => 'receive_date', 'label' => 'Received Date'],
      ['type' => 'field', 'key' => 'total_amount', 'label' => 'Amount',
        'link' => ['path' => 'civicrm/contact/view/contribution?reset=1&action=view&id=[id]&cid=[contact_id]',
          'entity' => '', 'action' => '', 'join' => '', 'target' => '_blank', 'task' => '']],
    ], ['sort' => [['receive_date', 'ASC']]]),
  ];
};

return array_merge(
  // QTD keeps != this.quarter (no future cases exist, so equivalent to
  // < this.quarter but byte-identical to the original definitions).
  $buildFamily('QTD', 'this.quarter', ['Contact_Case_hist.start_date', '!=', '"this.quarter"']),
  // PQ uses < previous.quarter so a case started in a LATER quarter is not
  // mistaken for prior client history.
  $buildFamily('PQ', 'previous.quarter', ['Contact_Case_hist.start_date', '<', '"previous.quarter"']),
);
