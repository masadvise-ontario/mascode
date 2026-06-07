<?php

declare(strict_types=1);

/**
 * "MAS_Ops" tag for the lifecycle operational saved searches
 * (mas-lifecycle-dashboard-spec naming taxonomy). Sibling of the existing
 * MAS_Dashboard tag (board metrics); MAS_Ops marks the CSM action-queue
 * searches.
 */
return [
  [
    'name' => 'Tag_MAS_Ops',
    'entity' => 'Tag',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'MAS_Ops',
        'label' => 'MAS_Ops',
        'description' => 'MAS lifecycle operational queue searches (CSM action-queue page)',
        'used_for' => ['civicrm_saved_search', 'Afform'],
        'is_selectable' => TRUE,
        'is_reserved' => TRUE,
      ],
      'match' => ['name'],
    ],
  ],
];
