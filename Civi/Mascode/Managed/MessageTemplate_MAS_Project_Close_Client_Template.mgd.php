<?php

declare(strict_types=1);

/**
 * Snapshot — existing CiviCRM template "MAS Project Signoff - Client Template"
 * (id 75 in both dev and production).
 *
 * ⚠ The msg_title here is load-bearing TWICE over, and the two uses are
 * independent:
 *
 *  1. `ProjectLifecycleStatusSubscriber::TRANSITIONS` keys the client
 *     transition on this exact string. They must be changed together.
 *  2. ⚠ It used to say here that a hand-edited template is NEVER rewritten by
 *     a deploy. That is FALSE for MessageTemplate — see the note below.
 *     upgrade_5013 still converges a drifted environment explicitly and is
 *     harmless and idempotent, so it stays.
 *
 * The managed `name` is deliberately NOT renamed alongside the title. The
 * civicrm_managed row is keyed on (module, name), so renaming it makes
 * createPlan() see an undeclared old row plus a new declaration — and the
 * supported way to express that is core's `replaces` key, not a bare rename.
 * A bare rename on an environment whose msg_title has NOT yet been migrated
 * would fall through to `match` and CREATE A SECOND TEMPLATE.
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
 *
 * ⚠ `update => 'unmodified'` DOES NOT PROTECT THIS TEMPLATE. MessageTemplate is
 * not an APIv4 ManagedEntity, so `entity_modified_date` is never stamped and the
 * policy degrades to always-update; a UI edit survives only while THIS
 * declaration's checksum is unchanged (and not at all across an ext
 * disable/enable, which forces full evaluation). Edit this file or its sidecar,
 * deploy, and production is overwritten — so content-diff production first.
 * Conversely a repo-side fix DOES reach production on deploy; there is no
 * separate production edit to make. Full note: Civi/Mascode/Managed/README.md.
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
        'msg_title' => 'MAS Project Signoff - Client Template',
        'msg_subject' => 'MAS Project Signoff',
        'msg_html' => file_get_contents(__DIR__ . '/MessageTemplate_MAS_Project_Close_Client_Template.body.html'),
        'is_active' => TRUE,
        'is_default' => TRUE,
      ],
      'match' => ['msg_title'],
    ],
  ],
];
