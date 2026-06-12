<?php

declare(strict_types=1);

/**
 * First stop on the project lifecycle (2026-06-12 rework, split into VC +
 * client stages later the same day). A new project starts here: the VC must
 * submit the Project Definition form before the client authorizes it and the
 * project becomes Active.
 *
 * The managed key keeps the original Awaiting_Project_Definition name so the
 * rename (name/label only) applies in place to the tracked entity (value 20)
 * without a delete/create cycle.
 *
 * Weight 10 places it between "closed" (SR block) and "Awaiting Client
 * Project Definition" — see the full workflow-order map in
 * CRM_Mascode_Upgrader::upgrade_5004().
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
        'name' => 'Awaiting VC Project Definition',
        'label' => 'Awaiting VC Project Definition',
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
