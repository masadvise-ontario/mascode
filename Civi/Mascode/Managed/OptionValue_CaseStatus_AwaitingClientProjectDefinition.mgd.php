<?php

declare(strict_types=1);

/**
 * Second stop on the project lifecycle (2026-06-12 rework): the VC has
 * submitted the Project Definition; the client must review and authorize it
 * (T&C acceptance) before the project becomes Active. Entered automatically
 * when the client definition-authorization email is sent — see
 * ProjectLifecycleStatusSubscriber transitions.
 */
return [
  [
    'name' => 'OptionValue_case_status_Awaiting_Client_Project_Definition',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'case_status',
        'name' => 'Awaiting Client Project Definition',
        'label' => 'Awaiting Client Project Definition',
        'value' => 23,
        'grouping' => 'Opened',
        'weight' => 11,
        'is_active' => TRUE,
        'is_default' => FALSE,
        'is_reserved' => FALSE,
      ],
      'match' => ['name', 'option_group_id'],
    ],
  ],
];
