<?php

declare(strict_types=1);

/**
 * RETIRED 2026-06-12 — replaced by the three-step close path:
 * Awaiting Project Definition → Awaiting VC Project Close Form →
 * Awaiting Client Project Close Form. Existing cases were migrated to
 * "Awaiting Client Project Close Form" by upgrade_5002.
 *
 * Kept (deactivated, not deleted) so any historical references still
 * resolve. update => 'always' holds it inactive against UI edits.
 */
return [
  [
    'name' => 'OptionValue_case_status_Awaiting_Close_Form',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'case_status',
        'name' => 'Awaiting Close Form',
        'label' => 'Awaiting Close Form',
        'value' => 19,
        'grouping' => 'Opened',
        'weight' => 20,
        'is_active' => FALSE,
        'is_default' => FALSE,
        'is_reserved' => FALSE,
      ],
      'match' => ['name', 'option_group_id'],
    ],
  ],
];
