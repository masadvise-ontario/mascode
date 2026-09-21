<?php

declare(strict_types=1);

/**
 * Second of the three close-path statuses that replaced "Awaiting Close Form"
 * (2026-06-12). Entered automatically when the VC close-request email
 * ("MAS Project Close - VC Template") is sent on a Project case — see
 * ProjectLifecycleStatusSubscriber. Arms the mas_lifecycle_vc_close_chase rule.
 */
return [
  [
    'name' => 'OptionValue_case_status_Awaiting_VC_Project_Close_Form',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'case_status',
        'name' => 'Awaiting VC Project Close Form',
        // ⚠ `name` is the FROZEN machine key and must not be renamed with the
        // label. Every match on this option value goes through the name —
        // `match` below, `status_id:name` / `activity_type_id:name` filters in
        // the SavedSearch declarations, ProjectLifecycleStatusSubscriber's
        // TRANSITIONS, CaseStatusSet's FALLBACK, and the SERIALISED
        // civirule_rule_condition params that no deploy ever touches. Staff
        // read the label; nothing reads the name but code. Renaming the label
        // alone is therefore the whole user-visible rename at none of the risk.
        // Brian's call, 2026-09-21, per the same reasoning D13 used to decline
        // renaming the Afform machine names.
        'label' => 'Awaiting VC Project Completion Form',
        'value' => 21,
        'grouping' => 'Opened',
        'weight' => 14,
        'is_active' => TRUE,
        'is_default' => FALSE,
        'is_reserved' => FALSE,
      ],
      'match' => ['name', 'option_group_id'],
    ],
  ],
];
