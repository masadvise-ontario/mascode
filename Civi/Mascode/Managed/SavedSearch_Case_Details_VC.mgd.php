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
 * A Case is returned ONLY when it is in the logged-in contact's S_cases (T12,
 * VC access spec D2, D13, D23): a case they coordinate (active row, any end
 * date, D5), a case in Sent for Assignment, or any case with an organisation
 * client that one of those brings into scope (the domain organisation only
 * through a case they coordinate, D25). The set comes from the scope searches
 * (SavedSearch_MAS_VC_Scope_Sets.mgd.php) through the same resolver the MCP
 * uses; VcPortalScopeSubscriber fills the placeholder clause at run time. The
 * page supplies the case id as a runtime filter, which is ANDed beside it, so
 * a forged id returns zero rows. An unfilled placeholder matches nothing.
 *
 * update=always plus the subscriber's drift check: the stored copy must equal
 * this file or the display is refused. Do not edit it in the Search Kit UI.
 */

use Civi\Mascode\Security\VcPortalScope;

return [
  [
    'name' => 'SavedSearch_Case_Details_VC',
    'entity' => 'SavedSearch',
    'cleanup' => 'unused',
    'update' => 'always',
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
            // MAS Rep (Case Coordinator), active or inactive, by name only (D15)
            // — via the `cc` join below, which is display-only, not a gate.
            "GROUP_CONCAT(DISTINCT CONCAT(cc.near_contact_id.display_name, IF(cc.is_active, ' (active)', ' (inactive)'))) AS mas_rep",
          ],
          'orderBy' => [],
          'where' => [
            VcPortalScope::clause('cases'),
          ],
          'groupBy' => [
            'id',
          ],
          'join' => [
            [
              'RelationshipCache AS cc',
              'LEFT',
              ['id', '=', 'cc.case_id'],
              ['cc.near_relation:name', '=', '"Case Coordinator is"'],
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
    'update' => 'always',
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
            ['type' => 'field', 'key' => 'mas_rep', 'label' => 'MAS Rep', 'sortable' => TRUE],
            ['type' => 'field', 'key' => 'start_date', 'label' => 'Start Date', 'sortable' => TRUE],
            ['type' => 'field', 'key' => 'end_date', 'label' => 'End Date', 'sortable' => TRUE],
          ],
          'actions' => ['download'],
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
