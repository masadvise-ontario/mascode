<?php

declare(strict_types=1);

// MAS-custom activity type recorded by afformMASRCSForm on submission (created in
// AfformSubmitSubscriber, not the form's layout). Brought under mascode management
// so the NAME stays stable across dev/prod — the subscriber references it by name
// (activity_type_id:name). `value` is intentionally omitted for cross-env safety
// (see the other OptionValue_ActivityType_* managed files).
return [
  [
    'name' => 'OptionValue_activity_type_Request_for_Consulting_Services_RCS',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'activity_type',
        'name' => 'Request for Consulting Services (RCS)',
        'label' => 'Request for Consulting Services (RCS)',
        'weight' => 69,
        'is_active' => TRUE,
        'is_default' => FALSE,
        'is_reserved' => FALSE,
      ],
      'match' => ['name', 'option_group_id'],
    ],
  ],
];
