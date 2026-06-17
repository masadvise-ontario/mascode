<?php

declare(strict_types=1);

/**
 * VC portal "My Cases" report — the VC's active cases (RelationshipCache
 * of their case relationships) with case code, subject, type and status.
 * Two table displays.
 *
 * Migrated from a DB-only SearchKit search to a managed entity (2026-06-17)
 * so it version-controls and deploys with mascode. Generated via
 * SavedSearch.export; update=unmodified preserves in-UI tweaks (re-export to
 * capture them in code). Tagged "VC Menu" in the SearchKit admin.
 */
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
        'api_entity' => 'RelationshipCache',
        'api_params' => [
          'version' => 4,
          'select' => [
            'case_id',
            'RelationshipCache_Case_case_id_01.case_type_id:label',
            'RelationshipCache_Case_case_id_01.status_id:label',
            'case_id.subject',
            'RelationshipCache_Case_case_id_01.Cases_SR_Projects_.MAS_SR_Case_Code',
            'RelationshipCache_Case_case_id_01.Projects.MAS_Project_Case_Code',
            'near_contact_id',
            'near_contact_id.sort_name',
            'near_relation:label',
            'far_contact_id',
            'far_contact_id.sort_name',
            'start_date',
            'end_date',
            'is_active',
          ],
          'orderBy' => [],
          'where' => [
            [
              'near_contact_id',
              '=',
              'user_contact_id',
            ],
            [
              'relationship_type_id.label_a_b',
              '=',
              'Case Coordinator is (MAS Rep)',
            ],
            [
              'near_relation',
              '=',
              'Case Coordinator is',
            ],
            [
              'is_active',
              '=',
              TRUE,
            ],
          ],
          'groupBy' => [],
          'join' => [
            [
              'Case AS RelationshipCache_Case_case_id_01',
              'INNER',
              [
                'case_id',
                '=',
                'RelationshipCache_Case_case_id_01.id',
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
        'settings' => [
          'description' => NULL,
          'sort' => [],
          'limit' => 50,
          'pager' => [],
          'placeholder' => 5,
          'columns' => [
            [
              'type' => 'field',
              'key' => 'case_id',
              'label' => 'Case ID',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'RelationshipCache_Case_case_id_01.case_type_id:label',
              'label' => 'Case Type',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'RelationshipCache_Case_case_id_01.status_id:label',
              'label' => 'Case Status',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'case_id.subject',
              'label' => 'Case Subject',
              'sortable' => TRUE,
              'link' => [
                'path' => '',
                'entity' => 'Case',
                'action' => 'view',
                'join' => 'case_id',
                'target' => '',
                'task' => '',
              ],
              'title' => 'View Case',
            ],
            [
              'type' => 'field',
              'key' => 'RelationshipCache_Case_case_id_01.Cases_SR_Projects_.MAS_SR_Case_Code',
              'label' => 'MAS SR Case Code',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'RelationshipCache_Case_case_id_01.Projects.MAS_Project_Case_Code',
              'label' => 'MAS Project Case Code',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'near_contact_id',
              'label' => 'My ID',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'near_contact_id.sort_name',
              'label' => 'My Name',
              'sortable' => TRUE,
              'link' => [
                'path' => '',
                'entity' => 'Contact',
                'action' => 'view',
                'join' => 'near_contact_id',
                'target' => '',
                'task' => '',
              ],
              'title' => 'View Contact (Near side)',
            ],
            [
              'type' => 'field',
              'key' => 'near_relation:label',
              'label' => 'Relationship',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'far_contact_id',
              'label' => 'Client ID',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'far_contact_id.sort_name',
              'label' => 'Client Name',
              'sortable' => TRUE,
              'link' => [
                'path' => '',
                'entity' => 'Contact',
                'action' => 'view',
                'join' => 'far_contact_id',
                'target' => '',
                'task' => '',
              ],
              'title' => 'View Contact (Far side)',
            ],
            [
              'type' => 'field',
              'key' => 'start_date',
              'label' => 'Relationship Start Date',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'end_date',
              'label' => 'Relationship End Date',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'is_active',
              'label' => 'Enabled',
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
        'settings' => [
          'description' => NULL,
          'sort' => [],
          'limit' => 50,
          'pager' => [],
          'placeholder' => 5,
          'columns' => [
            [
              'type' => 'field',
              'key' => 'case_id',
              'label' => 'Case ID',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'RelationshipCache_Case_case_id_01.case_type_id:label',
              'label' => 'Case Type',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'RelationshipCache_Case_case_id_01.status_id:label',
              'label' => 'Case Status',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'case_id.subject',
              'label' => 'Case Subject',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'RelationshipCache_Case_case_id_01.Cases_SR_Projects_.MAS_SR_Case_Code',
              'label' => 'MAS SR Case Code',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'RelationshipCache_Case_case_id_01.Projects.MAS_Project_Case_Code',
              'label' => 'MAS Project Case Code',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'near_contact_id',
              'label' => 'My ID',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'near_contact_id.sort_name',
              'label' => 'My Name',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'near_relation:label',
              'label' => 'Relationship',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'far_contact_id',
              'label' => 'Client ID',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'far_contact_id.sort_name',
              'label' => 'Client Name',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'start_date',
              'label' => 'Relationship Start Date',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'end_date',
              'label' => 'Relationship End Date',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'is_active',
              'label' => 'Enabled',
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
