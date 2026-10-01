<?php

declare(strict_types=1);

/**
 * VC portal "Cases of My Organizations" — every case the logged-in VC may see:
 * their S_cases (T12, VC access spec D2, D13, D23). That is the cases they
 * coordinate (D5: any end date), the cases in Sent for Assignment, and every
 * case with an organisation client that one of those brings into scope —
 * including cases other VCs coordinate. The domain organisation (MAS itself)
 * comes in only through a case the VC coordinated, never through the pool
 * (D25). The page is afsearchMASVcOrgCases (civicrm/mas/org-cases); each row
 * links to the case-detail page, which applies the same set.
 *
 * ACCESS: the placeholder clause is filled at run time by
 * VcPortalScopeSubscriber with the set the MCP uses (scope searches in
 * SavedSearch_MAS_VC_Scope_Sets.mgd.php, resolved by VcScopeResolver). It
 * matches no case until filled, so a failure shows an empty list. The
 * coordinator column is a name only (D15). acl_bypass: every selected field
 * reaches the browser, so select nothing a VC may not read, and no editable
 * column, add-row or drag-sort (docs/api.md in civicrm_mcp, T11).
 *
 * update=always plus the subscriber's drift check: a stored copy that differs
 * from this file is refused. Do not edit it in the Search Kit UI.
 */

use Civi\Mascode\Security\VcPortalScope;

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
  ['type' => 'field', 'key' => 'coordinator', 'label' => 'MAS Rep', 'sortable' => FALSE],
];

return [
  [
    'name' => 'SavedSearch_MAS_VC_Org_Cases',
    'entity' => 'SavedSearch',
    'cleanup' => 'unused',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'MAS_VC_Org_Cases',
        'label' => 'Cases of My Organizations',
        'api_entity' => 'Case',
        'description' => 'VC portal list (mascode SavedSearch_MAS_VC_Org_Cases.mgd.php). Access boundary — do not edit in the UI; an edited copy is refused.',
        'api_params' => [
          'version' => 4,
          'select' => [
            'id',
            'Projects.MAS_Project_Case_Code',
            'Cases_SR_Projects_.MAS_SR_Case_Code',
            'subject',
            'case_type_id:label',
            'status_id:label',
            'start_date',
            'end_date',
            'Case_CaseContact_Contact_01.sort_name',
            'GROUP_CONCAT(DISTINCT cc.near_contact_id.display_name) AS coordinator',
          ],
          'orderBy' => [],
          'where' => [
            VcPortalScope::clause('cases'),
          ],
          'groupBy' => ['id'],
          'join' => [
            [
              'Contact AS Case_CaseContact_Contact_01', 'LEFT', 'CaseContact',
              ['id', '=', 'Case_CaseContact_Contact_01.case_id'],
            ],
            // Display only, not a gate: the case's current coordinators by name.
            [
              'RelationshipCache AS cc', 'LEFT',
              ['id', '=', 'cc.case_id'],
              ['cc.near_relation:name', '=', '"Case Coordinator is"'],
              ['cc.orientation', '=', '"a_b"'],
              ['cc.is_active', '=', TRUE],
            ],
          ],
          'having' => [],
        ],
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'SavedSearch_MAS_VC_Org_Cases_SearchDisplay_MAS_VC_Org_Cases_Table',
    'entity' => 'SearchDisplay',
    'cleanup' => 'unused',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'MAS_VC_Org_Cases_Table',
        'label' => 'Cases of My Organizations Table',
        'saved_search_id.name' => 'MAS_VC_Org_Cases',
        'type' => 'table',
        'settings' => [
          'description' => NULL,
          'sort' => [['start_date', 'DESC']],
          'limit' => 50,
          'pager' => [],
          'placeholder' => 5,
          'columns' => $columns,
          'actions' => FALSE,
          'classes' => ['table', 'table-striped'],
        ],
        'acl_bypass' => TRUE,
      ],
      'match' => ['saved_search_id', 'name'],
    ],
  ],
];
