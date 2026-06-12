<?php

declare(strict_types=1);

return [
  [
    'name' => 'CaseType_project',
    'entity' => 'CaseType',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'project',
        'title' => 'Project',
        'is_active' => TRUE,
        'definition' => [
          'activityTypes' => [
            ['name' => 'Open Case', 'max_instances' => '1'],
            ['name' => 'Case Status Update'],
            ['name' => 'Email'],
            ['name' => 'Follow up'],
            ['name' => 'Meeting'],
            ['name' => 'Phone Call'],
            ['name' => 'Link Cases'],
          ],
          'statuses' => [
            'Active',
            'Awaiting Client Project Close Form',
            'Awaiting Client Project Definition',
            'Awaiting VC Project Close Form',
            'Awaiting VC Project Definition',
            'Cancelled',
            'Closed - Not Completed',
            'Completed',
            'On Hold',
          ],
          'activitySets' => [
            [
              'name' => 'standard_timeline',
              'label' => 'Standard Timeline',
              'timeline' => 1,
              'activityTypes' => [
                [
                  'name' => 'Open Case',
                  'status' => 'Completed',
                  'label' => 'Open Case',
                  'default_assignee_type' => '1',
                ],
              ],
            ],
          ],
          'timelineActivityTypes' => [
            [
              'name' => 'Open Case',
              'status' => 'Completed',
              'label' => 'Open Case',
              'default_assignee_type' => '1',
            ],
          ],
          'caseRoles' => [
            ['name' => 'Case Client Rep is', 'manager' => '0'],
            ['name' => 'Case Coordinator is', 'manager' => '1', 'creator' => '0'],
          ],
          'restrictActivityAsgmtToCmsUser' => 0,
          'activityAsgmtGrps' => [],
        ],
      ],
      'match' => ['name'],
    ],
  ],
];
