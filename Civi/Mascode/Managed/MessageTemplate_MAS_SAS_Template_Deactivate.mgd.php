<?php

declare(strict_types=1);

/**
 * Pin — deactivate the legacy "MAS SAS Template" (id 72 in dev as of
 * 2026-05-31).
 *
 * Per Brian: "likely not required". The RCS template (id 70) already
 * includes both SAS-F and SAS-S form links, so a separate SAS-only ask
 * isn't needed in the current lifecycle.
 *
 * Body intentionally NOT included in `values` — leaves the existing
 * body untouched in case we ever want to reactivate. Only is_active is
 * pinned. cleanup='unused' allows automatic cleanup if/when nothing
 * references it.
 *
 * Match by msg_title only — uniquely identifies the row.
 */
return [
  [
    'name' => 'MessageTemplate_MAS_SAS_Template_Deactivate',
    'entity' => 'MessageTemplate',
    'cleanup' => 'unused',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'msg_title' => 'MAS SAS Template',
        'is_active' => FALSE,
      ],
      'match' => ['msg_title'],
    ],
  ],
];
