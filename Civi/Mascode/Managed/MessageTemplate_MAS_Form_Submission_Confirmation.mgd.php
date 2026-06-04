<?php

declare(strict_types=1);

/**
 * Snapshot — existing CiviCRM template "MAS Form Submission Confirmation"
 * (id 71 in dev as of 2026-05-31).
 *
 * Trigger: auto-sent by the system when a client fills out any MAS Afform
 * (RCS, SAS-F, SAS-S). The submission confirmation acknowledges receipt
 * before the longer RCS template arrives.
 *
 * See sibling .body.html for current body content. update='unmodified'.
 */
return [
  [
    'name' => 'MessageTemplate_MAS_Form_Submission_Confirmation',
    'entity' => 'MessageTemplate',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'msg_title' => 'MAS Form Submission Confirmation',
        'msg_subject' => 'Request for Consulting Services - Submission Confirmed',
        'msg_html' => file_get_contents(__DIR__ . '/MessageTemplate_MAS_Form_Submission_Confirmation.body.html'),
        'is_active' => TRUE,
        'is_default' => TRUE,
      ],
      'match' => ['msg_title'],
    ],
  ],
];
