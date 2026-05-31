<?php

declare(strict_types=1);

return [
  [
    'name' => 'OptionValue_case_status_Awaiting_Close_Form',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'case_status',
        'name' => 'Awaiting Close Form',
        'label' => 'Awaiting Close Form',
        'value' => 19,
        'grouping' => 'Opened',
        'weight' => 13,
        'is_active' => TRUE,
        'is_default' => FALSE,
        'is_reserved' => FALSE,
      ],
      'match' => ['name', 'option_group_id'],
    ],
  ],
];
