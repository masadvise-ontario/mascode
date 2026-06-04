<?php

declare(strict_types=1);

return [
  [
    'name' => 'CustomField_Projects_Estimated_Completion_Date',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'Projects',
        'name' => 'Estimated_Completion_Date',
        'label' => 'Estimated Completion Date',
        'data_type' => 'Date',
        'html_type' => 'Select Date',
        'date_format' => 'yy-mm-dd',
        'is_required' => FALSE,
        'is_searchable' => FALSE,
        'is_active' => TRUE,
        'weight' => 59,
      ],
      'match' => ['name', 'custom_group_id'],
    ],
  ],
];
