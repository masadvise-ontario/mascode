<?php

declare(strict_types=1);

/**
 * Pins the legacy duplicate "Open" case_status to is_active=false.
 *
 * CiviCRM ships with a case_status named "Open" (label "Ongoing", value 1).
 * A second row with the same name "Open" but label "Open" (value 17) was
 * added later via the admin UI — likely an accidental duplicate. Both
 * showed up in the Service Request status dropdown because the case-type
 * definition references statuses by name, and both rows shared the name
 * "Open".
 *
 * Cleanup applied 2026-05-31 (mas-lifecycle Phase 1):
 *   - 2 SR cases on status_id=17 migrated to status_id=1
 *   - The duplicate row pinned to is_active=false here
 *
 * Row is not deleted — kept for audit and to absorb any straggling
 * references (CiviRules conditions, old Searchkit filters) that may
 * reference value=17. cleanup='unused' means it can be cleaned up
 * automatically if/when nothing references it.
 *
 * Match deliberately includes `label` because two rows share name +
 * option_group_id; label is what disambiguates them.
 */
return [
  [
    'name' => 'OptionValue_case_status_Open_Duplicate_Deactivate',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'case_status',
        'name' => 'Open',
        'label' => 'Open',
        'value' => 17,
        'is_active' => FALSE,
        'is_reserved' => FALSE,
        'grouping' => 'Opened',
      ],
      'match' => ['name', 'option_group_id', 'label'],
    ],
  ],
];
