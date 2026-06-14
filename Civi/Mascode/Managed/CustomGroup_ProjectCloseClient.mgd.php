<?php

declare(strict_types=1);

/**
 * Client project-close feedback — stored on the PROJECT CASE (2026-06-14
 * data-model decision). Replaces the legacy (unmanaged) activity custom group
 * Project_Close_Client_Fields. The "Project Close - Client Feedback" activity
 * remains as the event/trigger marker. Scoped to the project case type.
 *
 * Radio fields reference existing (shared) option groups by name:
 * satisfaction, yes_no_unsure_nc, yes_no_na, yes_no.
 */

$field = static function (
    string $managedName,
    string $name,
    string $label,
    string $dataType,
    string $htmlType,
    int $weight,
    ?string $optionGroup = null
): array {
    $values = [
        'custom_group_id.name' => 'Project_Close_Client',
        'name' => $name,
        'label' => $label,
        'data_type' => $dataType,
        'html_type' => $htmlType,
        'is_required' => FALSE,
        'is_searchable' => FALSE,
        'is_active' => TRUE,
        'weight' => $weight,
    ];
    if ($optionGroup !== null) {
        $values['option_group_id.name'] = $optionGroup;
    }
    return [
        'name' => $managedName,
        'entity' => 'CustomField',
        'cleanup' => 'never',
        'update' => 'always',
        'params' => ['version' => 4, 'values' => $values, 'match' => ['name', 'custom_group_id']],
    ];
};

return [
  [
    'name' => 'CustomGroup_Project_Close_Client',
    'entity' => 'CustomGroup',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Project_Close_Client',
        'title' => 'Project Close - Client Feedback',
        'extends' => 'Case',
        'extends_entity_column_value:name' => ['project'],
        'style' => 'Inline',
        'collapse_display' => FALSE,
        'is_active' => TRUE,
      ],
      'match' => ['name'],
    ],
  ],
  $field('CustomField_PCC_Satisfaction', 'satisfaction', 'How satisfied are you with the work done by MAS?', 'String', 'Radio', 1, 'satisfaction'),
  $field('CustomField_PCC_Satisfaction_Comment', 'satisfaction_comment', 'Satisfaction Comment', 'Memo', 'TextArea', 2),
  $field('CustomField_PCC_Would_Use_Again', 'would_use_mas_again', 'Would you use MAS again?', 'String', 'Radio', 3, 'yes_no_unsure_nc'),
  $field('CustomField_PCC_Reuse_Comment', 'reuse_comment', 'Reuse Comment', 'Memo', 'TextArea', 4),
  $field('CustomField_PCC_Work_With_VC_Again', 'would_work_with_vc_again', 'Would you consider working with the MAS Volunteer Consultant again?', 'String', 'Radio', 5, 'yes_no_unsure_nc'),
  $field('CustomField_PCC_Would_Recommend', 'would_recommend_mas', 'Would you recommend MAS to another not for profit organization?', 'String', 'Radio', 6, 'yes_no_unsure_nc'),
  $field('CustomField_PCC_Benefits_Realized', 'benefits_realized', 'Please describe the benefits realized and any other additional comments', 'Memo', 'TextArea', 7),
  $field('CustomField_PCC_Use_In_Marketing', 'use_in_marketing', 'Could we use your comments in our marketing materials?', 'String', 'Radio', 8, 'yes_no_na'),
  $field('CustomField_PCC_Share_With_VC', 'share_with_vc', 'Could we share your comments with the Volunteer Consultant who worked with you?', 'String', 'Radio', 9, 'yes_no'),
];
