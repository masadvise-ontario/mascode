<?php

declare(strict_types=1);

return [
  [
    'name' => 'OptionValue_activity_type_Draft_Email_Needs_Review',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'activity_type',
        'name' => 'Draft Email - Needs Review',
        'label' => 'Draft Email - Needs Review',
        'value' => 77,
        'weight' => 78,
        'is_active' => TRUE,
        'is_default' => FALSE,
        'is_reserved' => FALSE,
      ],
      'match' => ['name', 'option_group_id'],
    ],
  ],
];
