<?php

declare(strict_types=1);

// Activity type recorded when the VC submits the Project Definition form
// (afformMASProjectDefinitionVC). `value` intentionally omitted — auto-assigned
// per environment; everything references this type by NAME.
return [
  [
    'name' => 'OptionValue_activity_type_Project_Definition',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'activity_type',
        'name' => 'Project Definition',
        'label' => 'Project Definition',
        'is_active' => TRUE,
        'is_default' => FALSE,
        'is_reserved' => FALSE,
        'icon' => 'fa-file-signature',
      ],
      'match' => ['name', 'option_group_id'],
    ],
  ],
];
