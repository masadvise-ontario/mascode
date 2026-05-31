<?php

declare(strict_types=1);

return [
  [
    'name' => 'CaseType_service_request',
    'entity' => 'CaseType',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'service_request',
        'title' => 'Service Request',
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
            'Open',
            'Help Provided - No Project',
            'Request RCS',
            'RCS Completed',
            'Sent for Assignment',
            'No VC Response',
            'No Client Response',
            'Project Created',
            'closed',
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
            ['name' => 'Case Coordinator is', 'creator' => '0', 'manager' => '1'],
            ['name' => 'Case Client Rep is', 'manager' => '0'],
          ],
          'restrictActivityAsgmtToCmsUser' => 0,
          'activityAsgmtGrps' => [],
        ],
      ],
      'match' => ['name'],
    ],
  ],
];
