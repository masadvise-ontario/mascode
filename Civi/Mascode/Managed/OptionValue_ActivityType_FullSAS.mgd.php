<?php

declare(strict_types=1);

// MAS-custom activity type used by afformMASSASF (Full Self Assessment Survey).
// Brought under mascode management so the NAME stays stable across dev/prod.
// `value` is intentionally omitted: the option value already exists in dev and
// will be auto-assigned on first prod reconcile. Afforms reference this type by
// name (activity_type_id:name), so the numeric value is functionally irrelevant
// and forcing it risks colliding with / clobbering an existing prod value.
return [
  [
    'name' => 'OptionValue_activity_type_Full_Self_Assessment_Survey_SAS',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'activity_type',
        'name' => 'Full Self Assessment Survey (SAS)',
        'label' => 'Full Self Assessment Survey (SAS)',
        'weight' => 75,
        'is_active' => TRUE,
        'is_default' => FALSE,
        'is_reserved' => FALSE,
      ],
      'match' => ['name', 'option_group_id'],
    ],
  ],
];
