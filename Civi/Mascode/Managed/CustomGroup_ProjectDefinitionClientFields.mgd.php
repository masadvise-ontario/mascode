<?php

declare(strict_types=1);

/**
 * Client-stage Project Definition fields (paper PD form, authorization
 * section + capacity question), stored on the "Project Definition - Client
 * Authorization" activity created by afformMASProjectDefinitionClient.
 */
return [
  [
    'name' => 'CustomGroup_Project_Definition_Client_Fields',
    'entity' => 'CustomGroup',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Project_Definition_Client_Fields',
        'title' => 'Project Definition - Client Authorization',
        'extends' => 'Activity',
        'extends_entity_column_value:name' => ['Project Definition - Client Authorization'],
        'style' => 'Inline',
        'collapse_display' => FALSE,
        'is_active' => TRUE,
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'CustomField_PDC_Capacity_Increase',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'Project_Definition_Client_Fields',
        'name' => 'capacity_increase',
        'label' => 'How should this project increase your capacity to serve your clients?',
        'data_type' => 'Memo',
        'html_type' => 'TextArea',
        'is_required' => FALSE,
        'is_searchable' => FALSE,
        'is_active' => TRUE,
        'weight' => 1,
      ],
      'match' => ['name', 'custom_group_id'],
    ],
  ],
  [
    'name' => 'CustomField_PDC_Client_Signature',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'Project_Definition_Client_Fields',
        'name' => 'client_signature',
        'label' => 'Client Contact Signature (type your full name)',
        'data_type' => 'String',
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
    'name' => 'CustomField_PDC_Client_Title',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'Project_Definition_Client_Fields',
        'name' => 'client_title',
        'label' => 'Title',
        'data_type' => 'String',
        'html_type' => 'Text',
        'is_required' => FALSE,
        'is_searchable' => FALSE,
        'is_active' => TRUE,
        'weight' => 3,
      ],
      'match' => ['name', 'custom_group_id'],
    ],
  ],
  [
    'name' => 'CustomField_PDC_Authorized_Certification',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'Project_Definition_Client_Fields',
        'name' => 'authorized_certification',
        'label' => 'I certify that I am authorized to sign for this agency',
        'data_type' => 'Boolean',
        'html_type' => 'Radio',
        'is_required' => FALSE,
        'is_searchable' => FALSE,
        'is_active' => TRUE,
        'weight' => 4,
      ],
      'match' => ['name', 'custom_group_id'],
    ],
  ],
];
