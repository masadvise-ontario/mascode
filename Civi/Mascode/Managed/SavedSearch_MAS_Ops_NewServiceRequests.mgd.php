<?php

declare(strict_types=1);

/**
 * Q1 of the CSM action-queue page (mas-lifecycle-dashboard-spec): new service
 * requests awaiting triage.
 *
 * SavedSearch "MAS Ops - New Service Requests" + its tile display: every
 * service_request Case still at status Open (label "Ongoing", value 1),
 * oldest first, with R-code, client org, subject, practice area, and the
 * received date (start_date). A read-only navigation queue (no row task
 * actions) — the MAS-code cell links into the case.
 *
 * Filter verified against dev DB 2026-06-08 (19 rows). Embeddable as a tile on
 * afformMASOpsHome per the dashboard spec's naming/placement contract.
 */
return [
  [
    'name' => 'SavedSearch_MAS_Ops_New_Service_Requests',
    'entity' => 'SavedSearch',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'MAS_Ops_New_Service_Requests',
        'label' => 'MAS Ops - New Service Requests',
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
            'start_date',
          ],
          'orderBy' => [],
          'where' => [
            ['case_type_id:name', '=', 'service_request'],
            ['status_id:name', '=', 'Open'],
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
    'name' => 'SearchDisplay_MAS_Ops_New_Service_Requests_Tile',
    'entity' => 'SearchDisplay',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'MAS_Ops_New_Service_Requests_Tile',
        'label' => 'MAS Ops - New Service Requests Tile',
        'saved_search_id.name' => 'MAS_Ops_New_Service_Requests',
        'type' => 'table',
        'settings' => [
          'description' => NULL,
          'sort' => [
            ['start_date', 'ASC'],
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
              'key' => 'start_date',
              'label' => 'Received',
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
