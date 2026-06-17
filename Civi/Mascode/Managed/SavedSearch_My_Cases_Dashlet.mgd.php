<?php

declare(strict_types=1);

/**
 * VC home dashlet variant of "My Cases" — single compact table display.
 *
 * Migrated from a DB-only SearchKit search to a managed entity (2026-06-17)
 * so it version-controls and deploys with mascode. Generated via
 * SavedSearch.export; update=unmodified preserves in-UI tweaks (re-export to
 * capture them in code). Tagged "VC Menu" in the SearchKit admin.
 */
return [
  [
    'name' => 'SavedSearch_My_Cases_Dashlet',
    'entity' => 'SavedSearch',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'My_Cases_Dashlet',
        'label' => 'My Cases Dashlet',
        'api_entity' => 'RelationshipCache',
        'api_params' => [
          'version' => 4,
          'select' => [
            'case_id',
            'RelationshipCache_Case_case_id_01.case_type_id:label',
            'case_id.subject',
            'far_contact_id',
            'far_contact_id.sort_name',
            'RelationshipCache_Case_case_id_01.Cases_SR_Projects_.MAS_SR_Case_Code',
            'RelationshipCache_Case_case_id_01.Projects.MAS_Project_Case_Code',
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
    'name' => 'SavedSearch_My_Cases_Dashlet_SearchDisplay_My_Cases_Dashlet_Table_1',
    'entity' => 'SearchDisplay',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'My_Cases_Dashlet_Table_1',
        'label' => 'My Cases Dashlet Table 1',
        'saved_search_id.name' => 'My_Cases_Dashlet',
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
              'key' => 'is_active',
              'label' => 'Active',
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
];
