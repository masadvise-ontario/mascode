<?php

declare(strict_types=1);

/**
 * Client notice that their request has been circulated to the VC pool
 * (template id 76 in dev, formerly titled "after RCS").
 *
 * Trigger: Service Request transitions to status "Sent for Assignment".
 * The client-facing half of that event; the VC-facing half is
 * mas_lifecycle_vc_assignment_offer__vc. NEITHER IS WIRED YET — no CiviRule
 * names this template in dev or production, so today it goes out by hand if
 * it goes out at all. The `mas_lifecycle_` prefix states the intended
 * mechanism (a CiviRules rule firing it through LifecycleMailer), which is
 * what the convention encodes; it does not claim the rule exists.
 *
 * Renamed from "after RCS" per the naming convention: `MAS <Title Case>` is a
 * template staff compose and send by hand; `mas_*` is one the system sends
 * unattended, with a `__recipient` suffix. The old title said neither, and read
 * as a developer's note rather than something staff would recognise in a
 * dropdown. The `_lifecycle_` infix marks the engagement lifecycle (Service
 * Request → Project → close) — usually CiviRules-driven but not reliably so,
 * since the Phase 4 donation trio carry it while being subscriber-fired.
 *
 * THE MANAGED `name` IS DELIBERATELY NOT RENAMED, and neither is this file.
 * `match` is on msg_title, but CiviCRM reconciles by
 * (module, name, entity_type) — changing `name` orphans civicrm_managed row
 * 312 (cleanup='never', so it would persist) and creates a second managed row
 * for the same template. The P0-2 rename of the VC completion template set the
 * same precedent: msg_title moved to "MAS Project Completion - VC Template"
 * while the declaration kept `MessageTemplate_MAS_Project_Close_VC_Template`.
 * The file name follows the managed name, not the title, for that reason.
 *
 * upgrade_5016 performs the rename on sites where this declaration cannot,
 * for the same reason upgrade_5015 exists: `update => 'unmodified'` will not
 * rewrite a template anyone has edited in the CiviCRM UI.
 *
 * ⚠ The body in the sibling .body.html is unchanged and has two known defects,
 * both pre-existing and both out of scope here: it opens with a hard-coded
 * first name instead of a token, and its reimbursement sentence is garbled and
 * contradicts the RCS form, which no longer collects expenses. Those are
 * item 0 in docs/plans/completion-signoff-tickets.md, awaiting a decision.
 *
 * update='unmodified'.
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
        'msg_title' => 'mas_lifecycle_rcs_circulated__client',
        'msg_subject' => 'your request got circulated',
        'msg_html' => file_get_contents(__DIR__ . '/MessageTemplate_after_RCS.body.html'),
        'is_active' => TRUE,
        'is_default' => TRUE,
      ],
      'match' => ['msg_title'],
    ],
  ],
];
