<?php

declare(strict_types=1);

/**
 * Snapshot — existing CiviCRM template "MAS RCS Template" (id 70 in dev as of 2026-05-31).
 *
 * Trigger: Nina sends manually today, after receiving an intake/RCS request.
 * Phase 3 wires this to fire automatically when a Service Request is created.
 *
 * Body lives in the sidecar .body.html file alongside this declaration.
 * update='unmodified' means the first reconcile sets the baseline hash;
 * subsequent UI edits to the body survive future reconciles. The sidecar
 * file is refreshed before deploy via the diff-before-deploy workflow.
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
