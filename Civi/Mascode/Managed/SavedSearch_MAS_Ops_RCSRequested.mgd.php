<?php

declare(strict_types=1);

/**
 * CSM action-queue tile (mas-lifecycle-dashboard-spec): service requests where
 * the RCS has been requested and we are waiting on the client to return it.
 *
 * SavedSearch "MAS Ops - RCS Requested" + tile display: every service_request
 * Case at status "Request RCS" (value 6), oldest first, with R-code, client
 * org, subject, practice area, and the RCS-requested date.
 *
 * Like "Projects Awaiting Close Form", an automated chase already follows up on
 * these (the RCS-chase CiviRule), but Nina needs standing visibility into which
 * RCS requests are outstanding regardless of the automation.
 *
 * "RCS requested" date uses modified_date as the status-entry proxy (the case
 * was last touched when it moved to Request RCS) — validate at the Nina sit-with.
 *
 * Filter verified against dev DB 2026-06-08 (50 rows).
 */
return [
  [
    'name' => 'SavedSearch_MAS_Ops_RCS_Requested',
    'entity' => 'SavedSearch',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'MAS_Ops_RCS_Requested',
        'label' => 'MAS Ops - RCS Requested',
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
            ['status_id:name', '=', 'Request RCS'],
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
    'name' => 'SearchDisplay_MAS_Ops_RCS_Requested_Tile',
    'entity' => 'SearchDisplay',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'MAS_Ops_RCS_Requested_Tile',
        'label' => 'MAS Ops - RCS Requested Tile',
        'saved_search_id.name' => 'MAS_Ops_RCS_Requested',
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
              'label' => 'RCS Requested',
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
