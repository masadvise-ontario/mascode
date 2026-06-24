<?php

declare(strict_types=1);

/**
 * Q3 of the CSM action-queue page (mas-lifecycle-dashboard-spec): service
 * requests whose RCS/SAS forms have come back and are ready to circulate to
 * VCs.
 *
 * SavedSearch "MAS Ops - Returned Ready to Circulate" + tile display: every
 * service_request Case at status "RCS Completed" (value 18), oldest first,
 * with R-code, client org, subject, practice area, and the return date.
 *
 * NOTE on "Returned" column: there is no first-class "forms returned" field on
 * the case; modified_date is used as the status-entry proxy (the case was last
 * touched when it moved to RCS Completed). The Phase A sit-with with Nina is
 * where this proxy gets validated against her spreadsheet's "back from client"
 * column; if it drifts, swap to the max RCS/SAS return-activity date (a join
 * on activity types 68/73/74).
 *
 * Filter verified against dev DB 2026-06-08 (4 rows; spec baseline 2026-06-07
 * was 5 — one day of state drift, filter confirmed correct).
 */
return [
  [
    'name' => 'SavedSearch_MAS_Ops_Returned_Ready_To_Circulate',
    'entity' => 'SavedSearch',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'MAS_Ops_Returned_Ready_To_Circulate',
        'label' => 'MAS Ops - Returned Ready to Circulate',
        'api_entity' => 'Case',
        'api_params' => [
          'version' => 4,
          'select' => [
            'id',
            'Cases_SR_Projects_.MAS_SR_Case_Code',
            'Case_CaseContact_Contact_01.id',
            'Case_CaseContact_Contact_01.sort_name',
            'subject',
            'Cases_SR_Projects_.Practice_Area:label',
            'modified_date',
          ],
          'orderBy' => [],
          'where' => [
            ['case_type_id:name', '=', 'service_request'],
            ['status_id:name', '=', 'RCS Completed'],
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
    'name' => 'SearchDisplay_MAS_Ops_Returned_Ready_To_Circulate_Tile',
    'entity' => 'SearchDisplay',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'MAS_Ops_Returned_Ready_To_Circulate_Tile',
        'label' => 'MAS Ops - Returned Ready to Circulate Tile',
        'saved_search_id.name' => 'MAS_Ops_Returned_Ready_To_Circulate',
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
              'key' => 'subject',
              'label' => 'Subject',
            ],
            [
              'type' => 'field',
              'key' => 'Cases_SR_Projects_.Practice_Area:label',
              'label' => 'Practice Area',
            ],
            [
              'type' => 'field',
              'key' => 'modified_date',
              'label' => 'Returned',
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
