<?php

declare(strict_types=1);

/**
 * VC portal "Service Requests Sent for Assignment" report —
 * service-request cases at the assignment stage. Two table displays.
 *
 * Migrated from a DB-only SearchKit search to a managed entity (2026-06-17)
 * so it version-controls and deploys with mascode. Generated via
 * SavedSearch.export; update=unmodified preserves in-UI tweaks (re-export to
 * capture them in code). Tagged "VC Menu" in the SearchKit admin.
 */
return [
  [
    'name' => 'SavedSearch_Service_Requests_Send_for_Assignment',
    'entity' => 'SavedSearch',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Service_Requests_Send_for_Assignment',
        'label' => 'Service Requests Sent for Assignment',
        'api_entity' => 'Case',
        'api_params' => [
          'version' => 4,
          'select' => [
            'id',
            'Cases_SR_Projects_.MAS_SR_Case_Code',
            'Case_CaseContact_Contact_01.sort_name',
            'subject',
            'case_type_id:label',
            'start_date',
            'end_date',
            'details',
            'Cases_SR_Projects_.Notes',
            'Cases_SR_Projects_.Practice_Area:label',
            'Cases_SR_Projects_.Requested_Start_Date',
            'Cases_SR_Projects_.Referral:label',
            'Cases_SR_Projects_.Virtual_Work:label',
            'Case_CaseContact_Contact_01_Contact_RelationshipCache_Contact_01.near_contact_id.sort_name',
          ],
          'orderBy' => [],
          'where' => [
            [
              'status_id:name',
              '=',
              'Sent for Assignment',
            ],
          ],
          'groupBy' => [],
          'join' => [
            [
              'Contact AS Case_CaseContact_Contact_01',
              'LEFT',
              'CaseContact',
              [
                'id',
                '=',
                'Case_CaseContact_Contact_01.case_id',
              ],
            ],
            [
              'Contact AS Case_CaseContact_Contact_01_Contact_RelationshipCache_Contact_01',
              'LEFT',
              'RelationshipCache',
              [
                'Case_CaseContact_Contact_01.id',
                '=',
                'Case_CaseContact_Contact_01_Contact_RelationshipCache_Contact_01.far_contact_id',
              ],
              [
                'Case_CaseContact_Contact_01_Contact_RelationshipCache_Contact_01.near_relation:name',
                '=',
                '"Case Coordinator is"',
              ],
              [
                'Case_CaseContact_Contact_01_Contact_RelationshipCache_Contact_01.case_id',
                '=',
                'id',
              ],
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
    'name' => 'SavedSearch_Service_Requests_Send_for_Assignment_SearchDisplay_Service_Requests_Send_for_Assignment_Table_1',
    'entity' => 'SearchDisplay',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Service_Requests_Send_for_Assignment_Table_1',
        'label' => 'Service Requests Send for Assignment Table 1',
        'saved_search_id.name' => 'Service_Requests_Send_for_Assignment',
        'type' => 'table',
        'settings' => [
          'description' => NULL,
          'sort' => [],
          'limit' => 50,
          'pager' => [],
          'placeholder' => 5,
          'columns' => [
            [
              'type' => 'field',
              'key' => 'id',
              'label' => 'Case ID',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'Cases_SR_Projects_.MAS_SR_Case_Code',
              'label' => 'MAS SR Case Code',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'Case_CaseContact_Contact_01.sort_name',
              'label' => 'Case Client',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'subject',
              'label' => 'Case Subject',
              'sortable' => TRUE,
              'link' => [
                'path' => 'civicrm/mas/case-details#?id=[id]',
                'entity' => '',
                'action' => '',
                'join' => '',
                'target' => '',
                'task' => '',
              ],
              'title' => 'View Case Details',
            ],
            [
              'type' => 'field',
              'key' => 'case_type_id:label',
              'label' => 'Case Type',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'start_date',
              'label' => 'Case Start Date',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'end_date',
              'label' => 'Case End Date',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'Cases_SR_Projects_.Notes',
              'label' => 'Notes',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'Cases_SR_Projects_.Practice_Area:label',
              'label' => 'Practice Area',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'Cases_SR_Projects_.Requested_Start_Date',
              'label' => 'Requested Start Date',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'Cases_SR_Projects_.Referral:label',
              'label' => 'Source of Interest',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'Cases_SR_Projects_.Virtual_Work:label',
              'label' => 'Virtual Work',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'Case_CaseContact_Contact_01_Contact_RelationshipCache_Contact_01.near_contact_id.sort_name',
              'label' => 'MAS Rep',
              'sortable' => TRUE,
            ],
          ],
          'actions' => TRUE,
          'classes' => [
            'table',
            'table-striped',
          ],
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
  [
    'name' => 'SavedSearch_Service_Requests_Send_for_Assignment_SearchDisplay_Service_Requests_Send_for_Assignment_Table_2',
    'entity' => 'SearchDisplay',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Service_Requests_Send_for_Assignment_Table_2',
        'label' => 'Service Requests Send for Assignment Table 2',
        'saved_search_id.name' => 'Service_Requests_Send_for_Assignment',
        'type' => 'table',
        'settings' => [
          'description' => NULL,
          'sort' => [],
          'limit' => 50,
          'pager' => [],
          'placeholder' => 5,
          'columns' => [
            [
              'type' => 'field',
              'key' => 'id',
              'label' => 'Case ID',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'Cases_SR_Projects_.MAS_SR_Case_Code',
              'label' => 'MAS SR Case Code',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'Case_CaseContact_Contact_01.sort_name',
              'label' => 'Case Client',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'subject',
              'label' => 'Case Subject',
              'sortable' => TRUE,
              'link' => [
                'path' => 'civicrm/mas/case-details#?id=[id]',
                'entity' => '',
                'action' => '',
                'join' => '',
                'target' => '',
                'task' => '',
              ],
            ],
            [
              'type' => 'field',
              'key' => 'case_type_id:label',
              'label' => 'Case Type',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'start_date',
              'label' => 'Case Start Date',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'end_date',
              'label' => 'Case End Date',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'Cases_SR_Projects_.Notes',
              'label' => 'Notes',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'Cases_SR_Projects_.Practice_Area:label',
              'label' => 'Practice Area',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'Cases_SR_Projects_.Requested_Start_Date',
              'label' => 'Requested Start Date',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'Cases_SR_Projects_.Referral:label',
              'label' => 'Source of Interest',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'Cases_SR_Projects_.Virtual_Work:label',
              'label' => 'Virtual Work',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'Case_CaseContact_Contact_01_Contact_RelationshipCache_Contact_01.near_contact_id.sort_name',
              'label' => 'MAS Rep',
              'sortable' => TRUE,
            ],
          ],
          'actions' => [
            'download',
          ],
          'classes' => [
            'table',
            'table-striped',
          ],
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
