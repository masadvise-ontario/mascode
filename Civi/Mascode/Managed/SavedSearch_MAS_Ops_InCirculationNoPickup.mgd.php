<?php

declare(strict_types=1);

/**
 * Q4 of the CSM action-queue page (mas-lifecycle-dashboard-spec): service
 * requests circulating to VCs that have not yet been picked up.
 *
 * SavedSearch "MAS Ops - In Circulation No Pickup" + tile display: every
 * service_request Case at status "Sent for Assignment" (value 7), oldest
 * first, with R-code, client org, practice area, the sent-to-VCs date, and
 * subject.
 *
 * NOTE on "Sent to VCs" column: modified_date is the status-entry proxy (the
 * case was last touched when it moved to Sent for Assignment). Validated
 * against Nina's "sent to VCs" column at the Phase A sit-with. The VC-no-pickup
 * chase rule (parent spec Phase 3) will eventually write drafts off this same
 * state; this tile is useful before that rule exists.
 *
 * Filter verified against dev DB 2026-06-08 (9 rows).
 */
return [
  [
    'name' => 'SavedSearch_MAS_Ops_In_Circulation_No_Pickup',
    'entity' => 'SavedSearch',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'MAS_Ops_In_Circulation_No_Pickup',
        'label' => 'MAS Ops - In Circulation No Pickup',
        'api_entity' => 'Case',
        'api_params' => [
          'version' => 4,
          'select' => [
            'id',
            'Cases_SR_Projects_.MAS_SR_Case_Code',
            'Case_CaseContact_Contact_01.id',
            'Case_CaseContact_Contact_01.sort_name',
            'Cases_SR_Projects_.Practice_Area:label',
            'modified_date',
            'subject',
          ],
          'orderBy' => [],
          'where' => [
            ['case_type_id:name', '=', 'service_request'],
            ['status_id:name', '=', 'Sent for Assignment'],
          ],
          'groupBy' => [],
          'join' => [
            [
              'Contact AS Case_CaseContact_Contact_01',
              'LEFT',
              'CaseContact',
              ['id', '=', 'Case_CaseContact_Contact_01.case_id'],
            ],
          ],
          'having' => [],
        ],
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'SearchDisplay_MAS_Ops_In_Circulation_No_Pickup_Tile',
    'entity' => 'SearchDisplay',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'MAS_Ops_In_Circulation_No_Pickup_Tile',
        'label' => 'MAS Ops - In Circulation No Pickup Tile',
        'saved_search_id.name' => 'MAS_Ops_In_Circulation_No_Pickup',
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
              'key' => 'Cases_SR_Projects_.MAS_SR_Case_Code',
              'label' => 'MAS Code',
              'link' => [
                'path' => 'civicrm/case/a/#/case/list?caseId=[id]&cid=[Case_CaseContact_Contact_01.id]',
                'entity' => '',
                'action' => '',
                'join' => '',
                'target' => '_blank',
              ],
            ],
            [
              'type' => 'field',
              'key' => 'Case_CaseContact_Contact_01.sort_name',
              'label' => 'Client',
            ],
            [
              'type' => 'field',
              'key' => 'Cases_SR_Projects_.Practice_Area:label',
              'label' => 'Practice Area',
            ],
            [
              'type' => 'field',
              'key' => 'modified_date',
              'label' => 'Sent to VCs',
            ],
            [
              'type' => 'field',
              'key' => 'subject',
              'label' => 'Subject',
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
