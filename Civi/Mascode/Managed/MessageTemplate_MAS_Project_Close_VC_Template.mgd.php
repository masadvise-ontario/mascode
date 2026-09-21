<?php

declare(strict_types=1);

/**
 * Snapshot — existing CiviCRM template "MAS Project Completion - VC Template"
 * (id 74 in both dev and production).
 *
 * ⚠ Same two-way coupling as the client template, and the same reason it is
 * dangerous: `msg_title` is BOTH the key of this transition in
 * ProjectLifecycleStatusSubscriber::TRANSITIONS and the `match` field below.
 * Change one without the other and the case silently stops advancing — no
 * exception, no log line, the email still sending. That is not hypothetical;
 * it is what a UI rename of the CLIENT template did on 2026-09-17.
 *
 * upgrade_5015 converges any environment whose copy this declaration cannot
 * reach, because `update => 'unmodified'` declines to rewrite a template that
 * has been hand-edited in the UI.
 *
 * The managed `name` is deliberately NOT renamed with the title — see the
 * client template's declaration for why (civicrm_managed is keyed on
 * (module, name, entity_type), and a bare rename can create a duplicate).
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
        'msg_title' => 'MAS Project Completion - VC Template',
        'msg_subject' => 'Project Completion',
        'msg_html' => file_get_contents(__DIR__ . '/MessageTemplate_MAS_Project_Close_VC_Template.body.html'),
        'is_active' => TRUE,
        'is_default' => TRUE,
      ],
      'match' => ['msg_title'],
    ],
  ],
];
