<?php

declare(strict_types=1);

/**
 * Second of the three close-path statuses that replaced "Awaiting Close Form"
 * (2026-06-12). Entered automatically when the VC close-request email
 * ("MAS Project Close - VC Template") is sent on a Project case — see
 * ProjectLifecycleStatusSubscriber. Arms the mas_lifecycle_vc_close_chase rule.
 */
return [
  [
    'name' => 'OptionValue_case_status_Awaiting_VC_Project_Close_Form',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'case_status',
        'name' => 'Awaiting VC Project Close Form',
        'label' => 'Awaiting VC Project Close Form',
        'value' => 21,
        'grouping' => 'Opened',
        'weight' => 14,
        'is_active' => TRUE,
        'is_default' => FALSE,
        'is_reserved' => FALSE,
      ],
      'match' => ['name', 'option_group_id'],
    ],
  ],
];
