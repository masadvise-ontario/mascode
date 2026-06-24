<?php

declare(strict_types=1);

/**
 * Q5 of the CSM action-queue page (mas-lifecycle-dashboard-spec): projects
 * sitting in the close path — a close-request email has gone out and we are
 * waiting on the VC or client close form. Covers both close-wait statuses
 * introduced 2026-06-12 ("Awaiting VC Project Close Form" value 21,
 * "Awaiting Client Project Close Form" value 22, replacing the retired
 * "Awaiting Close Form" value 19); the Status column shows which form is
 * outstanding.
 *
 * SavedSearch "MAS Ops - Projects Awaiting Close Forms" + tile display:
 * oldest first, with P-code, client org, the assigned VC, subject, status,
 * and the entered-status date. Entity names keep the original
 * *_Awaiting_Close_Form keys so the Ops home Afform embed is untouched.
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
        'label' => 'MAS Ops - Projects Awaiting Close Forms',
        'api_entity' => 'Case',
        'api_params' => [
          'version' => 4,
          'select' => [
            'id',
            'Projects.MAS_Project_Case_Code',
            'Case_CaseContact_Contact_01.id',
            'Case_CaseContact_Contact_01.sort_name',
            "GROUP_CONCAT(DISTINCT CONCAT(cc.near_contact_id.display_name, IF(cc.is_active, ' (active)', ' (inactive)'))) AS mas_rep",
            'subject',
            'status_id:label',
            'modified_date',
          ],
          'orderBy' => [],
          'where' => [
            ['case_type_id:name', '=', 'project'],
            ['status_id:name', 'IN', ['Awaiting VC Project Close Form', 'Awaiting Client Project Close Form']],
          ],
          // groupBy id: one row per project for the GROUP_CONCAT'd MAS Rep, and
          // dedupes the CaseContact join when a case has >1 client contact.
          'groupBy' => ['id'],
          'join' => [
            [
              'Contact AS Case_CaseContact_Contact_01',
              'LEFT',
              'CaseContact',
              ['id', '=', 'Case_CaseContact_Contact_01.case_id'],
            ],
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
        'label' => 'MAS Ops - Projects Awaiting Close Forms Tile',
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
              'key' => 'mas_rep',
              'label' => 'MAS Rep',
            ],
            [
              'type' => 'field',
              'key' => 'subject',
              'label' => 'Subject',
            ],
            [
              'type' => 'field',
              'key' => 'status_id:label',
              'label' => 'Status',
            ],
            [
              'type' => 'field',
              'key' => 'modified_date',
              'label' => 'Entered Status',
            ],
          ],
          'actions' => ['download'],
          'classes' => ['table', 'table-striped'],
        ],
      ],
      'match' => ['name'],
    ],
  ],
];
