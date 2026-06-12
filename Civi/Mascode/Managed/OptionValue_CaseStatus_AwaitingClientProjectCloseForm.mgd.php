<?php

declare(strict_types=1);

/**
 * Third of the three close-path statuses that replaced "Awaiting Close Form"
 * (2026-06-12). Entered automatically when the client close-request email
 * ("MAS Project Close - Client Template") is sent on a Project case — see
 * ProjectCloseStatusSubscriber. Arms the mas_lifecycle_close_chase rule
 * (client chased at 30/90/150 days, propose mode).
 *
 * Existing cases at the retired "Awaiting Close Form" status were migrated
 * here by upgrade_5002 (that status meant "client has been asked").
 */
return [
  [
    'name' => 'OptionValue_case_status_Awaiting_Client_Project_Close_Form',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'case_status',
        'name' => 'Awaiting Client Project Close Form',
        'label' => 'Awaiting Client Project Close Form',
        'value' => 22,
        'grouping' => 'Opened',
        'weight' => 15,
        'is_active' => TRUE,
        'is_default' => FALSE,
        'is_reserved' => FALSE,
      ],
      'match' => ['name', 'option_group_id'],
    ],
  ],
];
