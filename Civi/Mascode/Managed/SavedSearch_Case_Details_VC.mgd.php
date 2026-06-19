<?php

declare(strict_types=1);

/**
 * VC portal "Case Details" gate search — the security boundary for the
 * front-end case-detail page (route civicrm/mas/case-details) that replaces
 * native civicrm/contact/view/case for Volunteer Consultants.
 *
 * Spec: ~/gdrive-brianpkm/3-Resources/mascode-vc-portal-security-spec.md
 * Tests: tests/Security/CaseDetailAccessTest.php (run via `cv scr`)
 *
 * SECURITY MODEL (filter-as-security; the display runs acl_bypass=TRUE):
 * A Case is returned ONLY when, for the logged-in contact (user_contact_id),
 * it is either
 *   (a) in the Sent-for-Assignment pool  (status_id:name = 'Sent for Assignment'), OR
 *   (b) coordinated by that contact      (active "Case Coordinator is" RelationshipCache row).
 * The page supplies the case id as a runtime filter; because the entitlement
 * predicate lives in THIS search, an arbitrary/forged case id cannot widen
 * access — an unentitled id simply returns zero rows.
 *
 * The "mine" branch is a LEFT join to RelationshipCache scoped to
 * user_contact_id in the JOIN condition (not WHERE), so pool-only cases (no
 * matching join row) still pass via the OR. groupBy id dedupes.
 */
return [
  [
    'name' => 'SavedSearch_Case_Details_VC',
    'entity' => 'SavedSearch',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Case_Details_VC',
        'label' => 'VC Case Details (access gate)',
        'api_entity' => 'Case',
        'api_params' => [
          'version' => 4,
          'select' => [
            'id',
            'subject',
            'case_type_id:label',
            'status_id:label',
            'start_date',
            'end_date',
            'Cases_SR_Projects_.MAS_SR_Case_Code',
            'Projects.MAS_Project_Case_Code',
          ],
          'orderBy' => [],
          'where' => [
            [
              'OR',
              [
                ['status_id:name', '=', 'Sent for Assignment'],
                ['Case_RelationshipCache_mine_01.near_contact_id', '=', 'user_contact_id'],
              ],
            ],
          ],
          'groupBy' => [
            'id',
          ],
          'join' => [
            [
              'RelationshipCache AS Case_RelationshipCache_mine_01',
              'LEFT',
              ['id', '=', 'Case_RelationshipCache_mine_01.case_id'],
              ['Case_RelationshipCache_mine_01.near_relation:name', '=', '"Case Coordinator is"'],
              ['Case_RelationshipCache_mine_01.is_active', '=', TRUE],
            ],
          ],
          'having' => [],
        ],
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'SavedSearch_Case_Details_VC_SearchDisplay_Case_Details_VC_Table_1',
    'entity' => 'SearchDisplay',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Case_Details_VC_Table_1',
        'label' => 'VC Case Details Table 1',
        'saved_search_id.name' => 'Case_Details_VC',
        'type' => 'table',
        'settings' => [
          'description' => NULL,
          'sort' => [],
          'limit' => 50,
          'pager' => [],
          'placeholder' => 5,
          'columns' => [
            ['type' => 'field', 'key' => 'id', 'label' => 'Case ID', 'sortable' => TRUE],
            ['type' => 'field', 'key' => 'case_type_id:label', 'label' => 'Case Type', 'sortable' => TRUE],
            ['type' => 'field', 'key' => 'status_id:label', 'label' => 'Status', 'sortable' => TRUE],
            ['type' => 'field', 'key' => 'subject', 'label' => 'Subject', 'sortable' => TRUE],
            ['type' => 'field', 'key' => 'Cases_SR_Projects_.MAS_SR_Case_Code', 'label' => 'SR Code', 'sortable' => TRUE],
            ['type' => 'field', 'key' => 'Projects.MAS_Project_Case_Code', 'label' => 'Project Code', 'sortable' => TRUE],
            ['type' => 'field', 'key' => 'start_date', 'label' => 'Start Date', 'sortable' => TRUE],
            ['type' => 'field', 'key' => 'end_date', 'label' => 'End Date', 'sortable' => TRUE],
          ],
          'actions' => FALSE,
          'classes' => ['table', 'table-striped'],
          'actions_display_mode' => 'menu',
        ],
        'acl_bypass' => TRUE,
      ],
      'match' => [
        'saved_search_id',
        'name',
      ],
    ],
  ],
];
