<?php

declare(strict_types=1);

/**
 * Q2 of the CSM action-queue page (mas-lifecycle-dashboard-spec): the
 * propose-mode draft-review queue.
 *
 * SavedSearch "MAS Ops - Drafts Awaiting Review" + its tile display: all
 * "Draft Email - Needs Review" activities still Scheduled, oldest first,
 * with case code/subject, recipient, and queued date. The display enables
 * row selection so the "Send draft email (MAS lifecycle)" SearchKit task
 * (Activity.sendLifecycleDraft) is available from the task bar.
 *
 * Embeddable as a tile (crm-search-display-table) on afformMASOpsHome per
 * the dashboard spec's naming/placement contract.
 */
return [
  [
    'name' => 'SavedSearch_MAS_Ops_Drafts_Awaiting_Review',
    'entity' => 'SavedSearch',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'MAS_Ops_Drafts_Awaiting_Review',
        'label' => 'MAS Ops - Drafts Awaiting Review',
        'api_entity' => 'Activity',
        'api_params' => [
          'version' => 4,
          'select' => [
            'id',
            'subject',
            'activity_date_time',
            'case_id',
            'case_id.subject',
            'case_id.Cases_SR_Projects_.MAS_SR_Case_Code',
            'case_id.Projects.MAS_Project_Case_Code',
            'Activity_ActivityContact_Contact_01.display_name',
            'details',
          ],
          'orderBy' => [],
          'where' => [
            ['activity_type_id:name', '=', 'Draft Email - Needs Review'],
            ['status_id:name', '=', 'Scheduled'],
          ],
          'groupBy' => [],
          'join' => [
            [
              'Contact AS Activity_ActivityContact_Contact_01',
              'INNER',
              'ActivityContact',
              ['id', '=', 'Activity_ActivityContact_Contact_01.activity_id'],
              ['Activity_ActivityContact_Contact_01.record_type_id:name', '=', '"Activity Targets"'],
            ],
          ],
          'having' => [],
        ],
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'SearchDisplay_MAS_Ops_Drafts_Awaiting_Review_Tile',
    'entity' => 'SearchDisplay',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'MAS_Ops_Drafts_Awaiting_Review_Tile',
        'label' => 'MAS Ops - Drafts Awaiting Review Tile',
        'saved_search_id.name' => 'MAS_Ops_Drafts_Awaiting_Review',
        'type' => 'table',
        'settings' => [
          'description' => NULL,
          'sort' => [
            ['activity_date_time', 'ASC'],
          ],
          'limit' => 25,
          'pager' => ['hide_single' => TRUE],
          'placeholder' => 5,
          'columns' => [
            [
              'type' => 'field',
              'key' => 'case_id.Cases_SR_Projects_.MAS_SR_Case_Code',
              'label' => 'Case',
              'rewrite' => '[case_id.Cases_SR_Projects_.MAS_SR_Case_Code][case_id.Projects.MAS_Project_Case_Code]',
              // Manage Case screen. Core's CaseView form derives the client
              // contact from the case id when cid is absent (uses the first
              // case client), so id alone is sufficient — matches the path in
              // Case::getLinks(). The old 'civicrm/case/a/#/case/list' route
              // only opened the case-list dashboard, not the case itself.
              'link' => [
                'path' => 'civicrm/contact/view/case?action=view&reset=1&id=[case_id]',
                'entity' => '',
                'action' => '',
                'join' => '',
                'target' => '_blank',
              ],
            ],
            [
              'type' => 'field',
              'key' => 'case_id.subject',
              'label' => 'Case Subject',
            ],
            [
              'type' => 'field',
              'key' => 'Activity_ActivityContact_Contact_01.display_name',
              'label' => 'Recipient',
            ],
            [
              'type' => 'field',
              'key' => 'subject',
              'label' => 'Draft Subject',
            ],
            [
              'type' => 'field',
              'key' => 'activity_date_time',
              'label' => 'Queued',
            ],
            // Inline rendered draft body. type=html → server purifies the
            // value (also strips the <!--mas-lifecycle--> meta comment) and
            // the SPA renders it with ng-bind-html. Nina reads the full email
            // in the row; no popup / core case-activity-view (which needs a
            // cid SearchKit won't supply) required.
            [
              'type' => 'html',
              'key' => 'details',
              'label' => 'Draft Preview',
            ],
          ],
          'actions' => TRUE,
          'classes' => ['table', 'table-striped'],
        ],
      ],
      'match' => ['name'],
    ],
  ],
];
