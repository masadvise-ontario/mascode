<?php

declare(strict_types=1);

/**
 * VC-stage Project Definition fields — stored on the PROJECT CASE (2026-06-14
 * data-model decision: form answers live on the case, single value per case;
 * activities remain only as event/trigger markers). Replaces the activity
 * custom group Project_Definition_Fields. Mirrors the paper PD form, page 1.
 *
 * Scoped to the project case type so the fields don't appear on service
 * requests. The client authorization form shows these read-only (what the
 * client signs off on); the VC definition form writes them.
 */
return [
  [
    'name' => 'CustomGroup_Project_Definition',
    'entity' => 'CustomGroup',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Project_Definition',
        'title' => 'Project Definition',
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
    'name' => 'CustomField_ProjectDef_Estimated_Duration',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'Project_Definition',
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
    'name' => 'CustomField_ProjectDef_Assistance_Provided',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'Project_Definition',
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
    'name' => 'CustomField_ProjectDef_Expected_Benefits',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'Project_Definition',
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
