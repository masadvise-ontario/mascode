<?php

declare(strict_types=1);

// MAS-custom activity type used by afformProjectCloseClientFeedback (client close feedback).
// Brought under mascode management so the NAME stays stable across dev/prod.
// `value` is intentionally omitted: the option value already exists in dev and
// will be auto-assigned on first prod reconcile. Afforms reference this type by
// name (activity_type_id:name), so the numeric value is functionally irrelevant
// and forcing it risks colliding with / clobbering an existing prod value.
return [
  [
    'name' => 'OptionValue_activity_type_Project_Close_Client_Feedback',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'activity_type',
        'name' => 'Project Close - Client Feedback',
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
        'label' => 'Project Signoff',
        'weight' => 77,
        'is_active' => TRUE,
        'is_default' => FALSE,
        'is_reserved' => FALSE,
      ],
      'match' => ['name', 'option_group_id'],
    ],
  ],
];
