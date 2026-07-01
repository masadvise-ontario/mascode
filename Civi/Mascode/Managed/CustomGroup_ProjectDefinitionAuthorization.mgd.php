<?php

declare(strict_types=1);

/**
 * Client-stage Project Definition authorization fields — stored on the PROJECT
 * CASE (2026-06-14 data-model decision). Replaces the activity custom group
 * Project_Definition_Client_Fields. The client authorization form writes these
 * after reviewing the VC's definition (the Project_Definition case group).
 * Scoped to the project case type.
 */
return [
  [
    'name' => 'CustomGroup_Project_Definition_Authorization',
    'entity' => 'CustomGroup',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Project_Definition_Authorization',
        'title' => 'Project Definition - Authorization',
        'extends' => 'Case',
        'extends_entity_column_value:name' => ['project'],
        'style' => 'Inline',
        'collapse_display' => FALSE,
        'is_active' => TRUE,
      ],
      'match' => ['name'],
    ],
  ],
  // Weights mirror the PD-Client authorization form flow (afformMASProjectDefinitionClient):
  // agree-with-VC-definition → the client's own input (expected benefits) →
  // the authorization block (capacity, signature, title, certification). Keeping
  // weights aligned to the form makes the manage-case display, the activity
  // summary (SubmissionSummaryService), and the VC-portal SearchKit all read in
  // the same order the client filled it in.
  [
    'name' => 'CustomField_PDA_Agreed_With_Description',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'Project_Definition_Authorization',
        'name' => 'agreed_with_description',
        'label' => 'I agree with the project description provided by the Volunteer Consultant',
        'data_type' => 'Boolean',
        'html_type' => 'Radio',
        'is_required' => FALSE,
        'is_searchable' => FALSE,
        'is_active' => TRUE,
        'weight' => 1,
      ],
      'match' => ['name', 'custom_group_id'],
    ],
  ],
  [
    // Authored by the CLIENT on the PD-Client form ("Your input" section).
    // Moved here from the Project_Definition (VC) group 2026-07-01 (upgrade_5009)
    // so it lives with the authorization answers the client actually enters.
    'name' => 'CustomField_PDA_Expected_Benefits',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'Project_Definition_Authorization',
        'name' => 'expected_benefits',
        'label' => 'Expected project benefits, impact, consequences',
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
    'name' => 'CustomField_PDA_Capacity_Increase',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'Project_Definition_Authorization',
        'name' => 'capacity_increase',
        'label' => 'How should this project increase your capacity to serve your clients?',
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
  [
    'name' => 'CustomField_PDA_Client_Signature',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'Project_Definition_Authorization',
        'name' => 'client_signature',
        'label' => 'Client Contact Signature (type your full name)',
        'data_type' => 'String',
        'html_type' => 'Text',
        'is_required' => FALSE,
        'is_searchable' => FALSE,
        'is_active' => TRUE,
        'weight' => 4,
      ],
      'match' => ['name', 'custom_group_id'],
    ],
  ],
  [
    'name' => 'CustomField_PDA_Client_Title',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'Project_Definition_Authorization',
        'name' => 'client_title',
        'label' => 'Title',
        'data_type' => 'String',
        'html_type' => 'Text',
        'is_required' => FALSE,
        'is_searchable' => FALSE,
        'is_active' => TRUE,
        'weight' => 5,
      ],
      'match' => ['name', 'custom_group_id'],
    ],
  ],
  [
    'name' => 'CustomField_PDA_Authorized_Certification',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'Project_Definition_Authorization',
        'name' => 'authorized_certification',
        'label' => 'I certify that I am authorized to sign for this agency',
        'data_type' => 'Boolean',
        'html_type' => 'Radio',
        'is_required' => FALSE,
        'is_searchable' => FALSE,
        'is_active' => TRUE,
        'weight' => 6,
      ],
      'match' => ['name', 'custom_group_id'],
    ],
  ],
];
