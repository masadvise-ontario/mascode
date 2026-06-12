<?php

declare(strict_types=1);

/**
 * First of the three close-path statuses that replaced "Awaiting Close Form"
 * (2026-06-12). A new project starts here: the VC must complete the Project
 * Definition form before the project becomes Active.
 *
 * Weight 10 places it between "closed" (SR block) and "Active" — see the
 * full workflow-order map in CRM_Mascode_Upgrader::upgrade_5002().
 */
return [
  [
    'name' => 'OptionValue_case_status_Awaiting_Project_Definition',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'case_status',
        'name' => 'Awaiting Project Definition',
        'label' => 'Awaiting Project Definition',
        'value' => 20,
        'grouping' => 'Opened',
        'weight' => 10,
        'is_active' => TRUE,
        'is_default' => FALSE,
        'is_reserved' => FALSE,
      ],
      'match' => ['name', 'option_group_id'],
    ],
  ],
];
