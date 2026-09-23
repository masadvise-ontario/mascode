<?php

declare(strict_types=1);

/**
 * Snapshot — existing CiviCRM template "MAS RCS Template" (id 70 in dev as of 2026-05-31).
 *
 * Trigger: Nina sends manually today, after receiving an intake/RCS request.
 * Phase 3 wires this to fire automatically when a Service Request is created.
 *
 * Body lives in the sidecar .body.html file alongside this declaration.
 * ⚠ `update => 'unmodified'` DOES NOT PROTECT THIS TEMPLATE. MessageTemplate is
 * not an APIv4 ManagedEntity, so `entity_modified_date` is never stamped and the
 * policy degrades to always-update; a UI edit survives only while THIS
 * declaration's checksum is unchanged (and not at all across an ext
 * disable/enable, which forces full evaluation). Edit this file or its sidecar,
 * deploy, and production is overwritten — so content-diff production first.
 * Conversely a repo-side fix DOES reach production on deploy; there is no
 * separate production edit to make. Full note: Civi/Mascode/Managed/README.md
 * § "Message template naming" / the update-policy paragraph.
 *
 * The sidecar was resynced from production on 2026-09-23, which was 322 bytes
 * ahead of the repo — a rewritten donation ask and the removal of a PS
 * advertising a seminar held in June 2026.
 */
return [
  [
    'name' => 'MessageTemplate_MAS_RCS_Template',
    'entity' => 'MessageTemplate',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'msg_title' => 'MAS RCS Template',
        'msg_subject' => 'Please complete RCS and SAS form',
        'msg_html' => file_get_contents(__DIR__ . '/MessageTemplate_MAS_RCS_Template.body.html'),
        'is_active' => TRUE,
        'is_default' => TRUE,
      ],
      'match' => ['msg_title'],
    ],
  ],
];
