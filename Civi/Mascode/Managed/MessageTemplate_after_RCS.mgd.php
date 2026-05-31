<?php

declare(strict_types=1);

/**
 * Snapshot — existing CiviCRM template "after RCS" / subject
 * "your request got circulated" (id 76 in dev as of 2026-05-31).
 *
 * Trigger: Service Request transitions to status "Sent for Assignment".
 * Client receives this notification that their RCS has been circulated
 * to the VC pool.
 *
 * Note: title and subject are informal lowercase ("after RCS",
 * "your request got circulated"). Preserved as-is in this snapshot.
 * Renaming to the `mas_lifecycle_*` convention would be a coordinated
 * DB rename + .mgd.php rename — separate change.
 *
 * See sibling .body.html for current body content. update='unmodified'.
 */
return [
  [
    'name' => 'MessageTemplate_after_RCS',
    'entity' => 'MessageTemplate',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'msg_title' => 'after RCS',
        'msg_subject' => 'your request got circulated',
        'msg_html' => file_get_contents(__DIR__ . '/MessageTemplate_after_RCS.body.html'),
        'is_active' => TRUE,
        'is_default' => TRUE,
      ],
      'match' => ['msg_title'],
    ],
  ],
];
