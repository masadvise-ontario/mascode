<?php

declare(strict_types=1);

/**
 * Snapshot — existing CiviCRM template "MAS Project Close - VC Template"
 * (id 74 in dev as of 2026-05-31).
 *
 * Trigger: Project closure — VC-facing close request. Today sent manually
 * by Nina; Phase 2 wires this to fire automatically when a Project case
 * enters "Awaiting Close Form" status.
 *
 * Includes a `{form.afformProjectCloseVCFeedbackLink}` merge tag that
 * generates a per-VC link to the close-feedback Afform.
 *
 * See sibling .body.html for current body content. update='unmodified'.
 */
return [
  [
    'name' => 'MessageTemplate_MAS_Project_Close_VC_Template',
    'entity' => 'MessageTemplate',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'msg_title' => 'MAS Project Close - VC Template',
        'msg_subject' => 'Project Close - VC',
        'msg_html' => file_get_contents(__DIR__ . '/MessageTemplate_MAS_Project_Close_VC_Template.body.html'),
        'is_active' => TRUE,
        'is_default' => TRUE,
      ],
      'match' => ['msg_title'],
    ],
  ],
];
