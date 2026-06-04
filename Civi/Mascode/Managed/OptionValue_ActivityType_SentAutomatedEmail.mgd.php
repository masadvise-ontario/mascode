<?php

declare(strict_types=1);

return [
  [
    'name' => 'OptionValue_activity_type_Sent_Automated_Email',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'activity_type',
        'name' => 'Sent Automated Email',
        'label' => 'Sent Automated Email',
        'value' => 78,
        'weight' => 79,
        'is_active' => TRUE,
        'is_default' => FALSE,
        'is_reserved' => FALSE,
      ],
      'match' => ['name', 'option_group_id'],
    ],
  ],
];
