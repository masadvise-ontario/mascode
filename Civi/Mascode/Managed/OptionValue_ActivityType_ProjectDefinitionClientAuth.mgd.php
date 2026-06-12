<?php

declare(strict_types=1);

// Activity type recorded when the client reviews and authorizes the Project
// Definition (afformMASProjectDefinitionClient). `value` intentionally
// omitted — auto-assigned per environment; referenced by NAME.
return [
  [
    'name' => 'OptionValue_activity_type_Project_Definition_Client_Authorization',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'activity_type',
        'name' => 'Project Definition - Client Authorization',
        'label' => 'Project Definition - Client Authorization',
        'is_active' => TRUE,
        'is_default' => FALSE,
        'is_reserved' => FALSE,
        'icon' => 'fa-stamp',
      ],
      'match' => ['name', 'option_group_id'],
    ],
  ],
];
