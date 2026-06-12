<?php

declare(strict_types=1);

/**
 * VC-stage Project Definition fields (paper PD form, page 1), stored on the
 * "Project Definition" activity created by afformMASProjectDefinitionVC.
 * Start Date lives on the Case (start_date); the client-stage answers live
 * in Project_Definition_Client_Fields.
 */
return [
  [
    'name' => 'CustomGroup_Project_Definition_Fields',
    'entity' => 'CustomGroup',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Project_Definition_Fields',
        'title' => 'Project Definition - VC Fields',
        'extends' => 'Activity',
        'extends_entity_column_value:name' => ['Project Definition'],
        'style' => 'Inline',
        'collapse_display' => FALSE,
        'is_active' => TRUE,
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'CustomField_PD_Estimated_Duration',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'Project_Definition_Fields',
        'name' => 'estimated_duration',
        'label' => 'Estimated Duration',
        'data_type' => 'String',
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
    'name' => 'CustomField_PD_Assistance_Provided',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'Project_Definition_Fields',
        'name' => 'assistance_provided',
        'label' => 'Assistance the Volunteer Consultant has agreed to provide',
        'data_type' => 'Memo',
        'html_type' => 'TextArea',
        'is_required' => FALSE,
        'is_searchable' => FALSE,
        'is_active' => TRUE,
        'weight' => 2,
      ],
      'match' => ['name', 'custom_group_id'],
    ],
  ],
  [
    'name' => 'CustomField_PD_Expected_Benefits',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'Project_Definition_Fields',
        'name' => 'expected_benefits',
        'label' => 'Expected project benefits, impact, consequences',
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
