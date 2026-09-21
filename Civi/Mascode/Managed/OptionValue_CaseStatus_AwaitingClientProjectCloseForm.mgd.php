<?php

declare(strict_types=1);

/**
 * Third of the three close-path statuses that replaced "Awaiting Close Form"
 * (2026-06-12). Entered automatically when the client close-request email
 * ("MAS Project Signoff - Client Template") is sent on a Project case — see
 * ProjectLifecycleStatusSubscriber. Arms the mas_lifecycle_close_chase rule
 * (client chased at 30/90/150 days, propose mode).
 *
 * Existing cases at the retired "Awaiting Close Form" status were migrated
 * here by upgrade_5002 (that status meant "client has been asked").
 */
return [
  [
    'name' => 'OptionValue_case_status_Awaiting_Client_Project_Close_Form',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'case_status',
        'name' => 'Awaiting Client Project Close Form',
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
        'label' => 'Awaiting Client Project Signoff Form',
        'value' => 22,
        'grouping' => 'Opened',
        'weight' => 15,
        'is_active' => TRUE,
        'is_default' => FALSE,
        'is_reserved' => FALSE,
      ],
      'match' => ['name', 'option_group_id'],
    ],
  ],
];
