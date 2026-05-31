<?php

declare(strict_types=1);

/**
 * Snapshot — existing CiviCRM template "MAS Project Close - Client Template"
 * (id 75 in dev as of 2026-05-31).
 *
 * Trigger: Project closure — client-facing close request. Today sent
 * manually by Nina; Phase 2 wires this to fire automatically when a
 * Project case enters "Awaiting Close Form" status. Replaces the
 * WordPress expiring-link pattern.
 *
 * Includes a `{form.afformProjectCloseClientFeedbackLink}` merge tag for
 * the close-feedback Afform.
 *
 * See sibling .body.html for current body content. update='unmodified'.
 */
return [
  [
    'name' => 'MessageTemplate_MAS_Project_Close_Client_Template',
    'entity' => 'MessageTemplate',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'msg_title' => 'MAS Project Close - Client Template',
        'msg_subject' => 'MAS Project Close - Client',
        'msg_html' => file_get_contents(__DIR__ . '/MessageTemplate_MAS_Project_Close_Client_Template.body.html'),
        'is_active' => TRUE,
        'is_default' => TRUE,
      ],
      'match' => ['msg_title'],
    ],
  ],
];
