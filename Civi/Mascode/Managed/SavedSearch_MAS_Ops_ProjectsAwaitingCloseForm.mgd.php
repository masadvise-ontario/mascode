<?php

declare(strict_types=1);

/**
 * Q5 of the CSM action-queue page (mas-lifecycle-dashboard-spec): projects
 * sitting in "Awaiting Close Form" — the close email has gone out and we are
 * waiting on the client/VC close forms.
 *
 * SavedSearch "MAS Ops - Projects Awaiting Close Form" + tile display: every
 * project Case at status "Awaiting Close Form" (value 19), oldest first, with
 * P-code, client org, the assigned VC, subject, and the entered-status date.
 *
 * NOTES:
 * - VC column: joined via RelationshipCache on the "Case Coordinator is"
 *   relationship (same pattern as the existing "Service Requests Sent for
 *   Assignment" search). If MAS projects record the VC under a different case
 *   role, this cell renders empty — confirm at the Phase A sit-with once
 *   projects exist in this status (dev count is 0 today, a brand-new status).
 * - "Entered status" uses modified_date as the status-entry proxy.
 * - Chase-count column (count of Draft/Sent close-chase activities) from the
 *   spec is deferred: it needs a GROUP BY that conflicts with the per-row VC/
 *   org columns, and there is no data to verify against yet. Add as a second
 *   display or a correlated count once close-chase activity accrues.
 *
 * Filter verified against dev DB 2026-06-08 (0 rows — new status).
 */
return [
  [
    'name' => 'SavedSearch_MAS_Ops_Projects_Awaiting_Close_Form',
    'entity' => 'SavedSearch',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'MAS_Ops_Projects_Awaiting_Close_Form',
        'label' => 'MAS Ops - Projects Awaiting Close Form',
        'api_entity' => 'Case',
        'api_params' => [
          'version' => 4,
          'select' => [
            'id',
            'Projects.MAS_Project_Case_Code',
            'Case_CaseContact_Contact_01.id',
            'Case_CaseContact_Contact_01.sort_name',
            'Case_CaseContact_Contact_01_Contact_RelationshipCache_Contact_01.near_contact_id.sort_name',
            'subject',
            'modified_date',
          ],
          'orderBy' => [],
          'where' => [
            ['case_type_id:name', '=', 'project'],
            ['status_id:name', '=', 'Awaiting Close Form'],
          ],
          'groupBy' => [],
          'join' => [
            [
              'Contact AS Case_CaseContact_Contact_01',
              'LEFT',
              'CaseContact',
              ['id', '=', 'Case_CaseContact_Contact_01.case_id'],
            ],
            [
              'Contact AS Case_CaseContact_Contact_01_Contact_RelationshipCache_Contact_01',
              'LEFT',
              'RelationshipCache',
              ['Case_CaseContact_Contact_01.id', '=', 'Case_CaseContact_Contact_01_Contact_RelationshipCache_Contact_01.far_contact_id'],
              ['Case_CaseContact_Contact_01_Contact_RelationshipCache_Contact_01.near_relation:name', '=', '"Case Coordinator is"'],
              ['Case_CaseContact_Contact_01_Contact_RelationshipCache_Contact_01.case_id', '=', 'id'],
            ],
          ],
          'having' => [],
        ],
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'SearchDisplay_MAS_Ops_Projects_Awaiting_Close_Form_Tile',
    'entity' => 'SearchDisplay',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'MAS_Ops_Projects_Awaiting_Close_Form_Tile',
        'label' => 'MAS Ops - Projects Awaiting Close Form Tile',
        'saved_search_id.name' => 'MAS_Ops_Projects_Awaiting_Close_Form',
        'type' => 'table',
        'settings' => [
          'description' => NULL,
          'sort' => [
            ['modified_date', 'ASC'],
          ],
          'limit' => 50,
          'pager' => ['hide_single' => TRUE],
          'placeholder' => 5,
          'columns' => [
            [
              'type' => 'field',
              'key' => 'Projects.MAS_Project_Case_Code',
              'label' => 'MAS Code',
              'link' => [
                'path' => 'civicrm/contact/view/case?reset=1&action=view&id=[id]&cid=[Case_CaseContact_Contact_01.id]',
                'entity' => '',
                'action' => '',
                'join' => '',
                'target' => '_blank',
                'task' => '',
              ],
            ],
            [
              'type' => 'field',
              'key' => 'Case_CaseContact_Contact_01.sort_name',
              'label' => 'Client',
            ],
            [
              'type' => 'field',
              'key' => 'Case_CaseContact_Contact_01_Contact_RelationshipCache_Contact_01.near_contact_id.sort_name',
              'label' => 'VC',
            ],
            [
              'type' => 'field',
              'key' => 'subject',
              'label' => 'Subject',
            ],
            [
              'type' => 'field',
              'key' => 'modified_date',
              'label' => 'Entered Status',
            ],
          ],
          'actions' => FALSE,
          'classes' => ['table', 'table-striped'],
        ],
      ],
      'match' => ['name'],
    ],
  ],
];
