<?php

declare(strict_types=1);

/**
 * VC portal "My Cases" report — the logged-in VC's cases (Cases they coordinate
 * as "Case Coordinator is (MAS Rep)"), with code, subject, type, status, start
 * date and client.
 *
 * Base entity is **Case** (not RelationshipCache) so the case status_id is a
 * native field: the report afform (afsearchMyCasesReport) can default a
 * multi-select status filter to the "Opened" status class and let the VC change
 * it. Access is scoped by an INNER join to the user's active Case-Coordinator
 * RelationshipCache row (near_contact_id = user_contact_id); groupBy id dedupes.
 *
 * Migrated RelationshipCache -> Case 2026-06-24 to enable the status filter.
 * Managed entity (update=unmodified); tagged "VC Menu" in the SearchKit admin.
 * Two displays: Table_1 (report, used by the afform) and Table_2 (download).
 */

$select = [
  'id',
  'Projects.MAS_Project_Case_Code',
  'Cases_SR_Projects_.MAS_SR_Case_Code',
  'subject',
  'case_type_id:label',
  'status_id:label',
  'start_date',
  'end_date',
  'Case_CaseContact_Contact_01.sort_name',
];

// Scope to the logged-in VC's coordinated cases.
$mineJoin = [
  'RelationshipCache AS mine', 'INNER',
  ['id', '=', 'mine.case_id'],
  ['mine.near_relation:name', '=', '"Case Coordinator is"'],
  ['mine.is_active', '=', TRUE],
];
// Client org (case client contact) for display.
$clientJoin = [
  'Contact AS Case_CaseContact_Contact_01', 'LEFT', 'CaseContact',
  ['id', '=', 'Case_CaseContact_Contact_01.case_id'],
];

$columns = [
  ['type' => 'field', 'key' => 'Projects.MAS_Project_Case_Code', 'label' => 'MAS Project Case Code', 'sortable' => TRUE],
  ['type' => 'field', 'key' => 'Cases_SR_Projects_.MAS_SR_Case_Code', 'label' => 'MAS SR Case Code', 'sortable' => TRUE],
  [
    'type' => 'field', 'key' => 'subject', 'label' => 'Case Subject', 'sortable' => TRUE,
    'link' => ['path' => 'civicrm/mas/case-details#?id=[id]', 'entity' => '', 'action' => '', 'join' => '', 'target' => '', 'task' => ''],
    'title' => 'View Case Details',
  ],
  ['type' => 'field', 'key' => 'case_type_id:label', 'label' => 'Case Type', 'sortable' => TRUE],
  ['type' => 'field', 'key' => 'status_id:label', 'label' => 'Case Status', 'sortable' => TRUE],
  ['type' => 'field', 'key' => 'start_date', 'label' => 'Case Start Date', 'sortable' => TRUE],
  ['type' => 'field', 'key' => 'end_date', 'label' => 'Case End Date', 'sortable' => TRUE],
  ['type' => 'field', 'key' => 'Case_CaseContact_Contact_01.sort_name', 'label' => 'Client', 'sortable' => TRUE],
];

$displaySettings = function (array $columns, $actions) {
  return [
    'description' => NULL,
    'sort' => [['start_date', 'ASC']],
    'limit' => 50,
    'pager' => [],
    'placeholder' => 5,
    'columns' => $columns,
    'actions' => $actions,
    'classes' => ['table', 'table-striped'],
    'actions_display_mode' => 'menu',
  ];
};

return [
  [
    'name' => 'SavedSearch_My_Cases',
    'entity' => 'SavedSearch',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'My_Cases',
        'label' => 'My Cases',
        'api_entity' => 'Case',
        'api_params' => [
          'version' => 4,
          'select' => $select,
          'orderBy' => [],
          'where' => [
            ['mine.near_contact_id', '=', 'user_contact_id'],
          ],
          'groupBy' => ['id'],
          'join' => [$mineJoin, $clientJoin],
          'having' => [],
        ],
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'SavedSearch_My_Cases_SearchDisplay_My_Cases_Table_1',
    'entity' => 'SearchDisplay',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'My_Cases_Table_1',
        'label' => 'My Cases Table 1',
        'saved_search_id.name' => 'My_Cases',
        'type' => 'table',
        'settings' => $displaySettings($columns, TRUE),
        'acl_bypass' => TRUE,
      ],
      'match' => ['saved_search_id', 'name'],
    ],
  ],
  [
    'name' => 'SavedSearch_My_Cases_SearchDisplay_My_Cases_Table_2',
    'entity' => 'SearchDisplay',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'My_Cases_Table_2',
        'label' => 'My Cases Table 2',
        'saved_search_id.name' => 'My_Cases',
        'type' => 'table',
        'settings' => $displaySettings($columns, ['download']),
        'acl_bypass' => TRUE,
      ],
      'match' => ['saved_search_id', 'name'],
    ],
  ],
];
