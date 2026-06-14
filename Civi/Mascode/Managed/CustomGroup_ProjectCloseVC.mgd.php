<?php

declare(strict_types=1);

/**
 * VC project-close report — stored on the PROJECT CASE (2026-06-14 data-model
 * decision: form answers live on the case). Replaces the legacy (unmanaged)
 * activity custom group Project_Close_VC_Fields. The "Project Close - VC
 * Report" activity remains as the event/trigger marker. Scoped to the project
 * case type.
 *
 * Note: hours_worked is what the VC reports at close; the board "hours of
 * service" metric reads the separate Projects.Hours field — kept distinct to
 * match prior behaviour (consolidating them is a future cleanup).
 */
return [
  [
    'name' => 'CustomGroup_Project_Close_VC',
    'entity' => 'CustomGroup',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Project_Close_VC',
        'title' => 'Project Close - VC Report',
        'extends' => 'Case',
        'extends_entity_column_value:name' => ['project'],
        'style' => 'Inline',
        'collapse_display' => FALSE,
        'is_active' => TRUE,
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'CustomField_PCVC_Hours_Worked',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'Project_Close_VC',
        'name' => 'hours_worked',
        'label' => 'Hours Worked by Volunteer Consultant',
        'data_type' => 'Float',
        'html_type' => 'Text',
        'is_required' => FALSE,
        'is_searchable' => FALSE,
        'is_active' => TRUE,
        'weight' => 1,
      ],
      'match' => ['name', 'custom_group_id'],
    ],
  ],
  [
    'name' => 'CustomField_PCVC_Expenses_Incurred',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'Project_Close_VC',
        'name' => 'expenses_incurred',
        'label' => 'Expenses Incurred',
        'data_type' => 'Money',
        'html_type' => 'Text',
        'is_required' => FALSE,
        'is_searchable' => FALSE,
        'is_active' => TRUE,
        'weight' => 2,
      ],
      'match' => ['name', 'custom_group_id'],
    ],
  ],
  [
    'name' => 'CustomField_PCVC_Services_Delivered',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'Project_Close_VC',
        'name' => 'services_delivered',
        'label' => 'Description of Services Delivered',
        'data_type' => 'Memo',
        'html_type' => 'TextArea',
        'is_required' => FALSE,
        'is_searchable' => FALSE,
        'is_active' => TRUE,
        'weight' => 3,
      ],
      'match' => ['name', 'custom_group_id'],
    ],
  ],
];
