<?php

declare(strict_types=1);

/**
 * Client notice that their request has been circulated to the VC pool
 * ("after RCS", template id 76 on dev and production).
 *
 * Trigger: Service Request transitions to status "Sent for Assignment".
 * The client-facing half of that event; the VC-facing half is
 * mas_lifecycle_vc_assignment_offer__vc. NEITHER IS WIRED — no CiviRule names
 * either template in dev or production, and no PHP in this extension
 * references them. Today this one goes out by hand — read from PRODUCTION on
 * 2026-09-23: 58 sends across 29 distinct days in 2026, with 53 DISTINCT
 * bodies. It is edited almost every time it is sent.
 *
 * ⚠ THE TITLE IS AN OPEN DECISION — DO NOT RENAME IT.
 * It was briefly renamed to `mas_lifecycle_rcs_circulated__client` (PR #43)
 * and reverted here, because that prefix asserts the system sends it and the
 * send data above says a person does. Nina is deciding whether this becomes
 * automatic on the status change or stays manual. Those answers need
 * different names — `mas_lifecycle_*` if automatic, `MAS <Title Case>` if
 * manual — so the name follows the decision, not the other way round.
 * `tests/Unit/Managed/MessageTemplateNamingTest.php` carries "after RCS" in
 * PENDING_DECISION and will go red when the decision lands, which is the
 * reminder to finish the job. See Civi/Mascode/Managed/README.md
 * § "Message template naming".
 *
 * ⚠ THE BODY IS SYNCED FROM PRODUCTION (2026-09-23) AND PRODUCTION WINS.
 * The repo copy was stale by 370 bytes — it still carried a PS advertising a
 * seminar held on 4 June 2026. A UI edit to a message template is NOT
 * protected from this declaration, which is the opposite of what an earlier
 * version of this docblock claimed: `MessageTemplate` is not an APIv4
 * ManagedEntity (`CoreUtil::getInfoItem('MessageTemplate','type')` is
 * `['DAOEntity']`), so `civicrm_managed.entity_modified_date` is NEVER
 * stamped for it and `update => 'unmodified'` degrades to always-update —
 * core logs that fallback on every reconcile, 60 times in this site's own
 * ConfigAndLog. `optimizePlan()` spares the row only while this declaration's
 * checksum is unchanged. So **editing this file or its .body.html and
 * deploying will overwrite whatever production is carrying.** Content-diff
 * against production first, every time.
 *
 * THE MANAGED `name` AND THIS FILE NAME ARE FROZEN. CiviCRM reconciles by
 * (module, name, entity_type); changing `name` orphans civicrm_managed row 312
 * (cleanup='never', so it persists) and inserts a second managed row for one
 * template. The supported alternative is `replaces`, which
 * createPlan()/migrateManagedRecord() honour by renaming the managed row in
 * place and keeping entity_id. Note this is a per-declaration choice, not a
 * directory rule — siblings such as
 * MessageTemplate_anniversary_checkin__client.mgd.php do embed the title.
 *
 * ONE DELIBERATE DIVERGENCE FROM PRODUCTION. The body is production's except
 * that its hard-coded client first name is replaced with `{contact.first_name}`
 * — the token the sibling MAS RCS Template already uses. This repo is PUBLIC
 * and a real client's given name has no business in its permanent history; that
 * is not a copy decision anyone needs to weigh in on. The next deploy carries
 * the substitution TO production, which is the point.
 *
 * ⚠ One body defect survives, for item 0 in
 * docs/plans/completion-signoff-tickets.md: the reimbursement sentence is
 * garbled and contradicts the RCS form, which no longer collects expenses.
 * That one is a wording decision and waits for Nina. Note it needs no separate
 * production edit — fix it here and deploying delivers it, per the paragraph
 * above. An earlier version of this docblock said a repo-only fix would be
 * "reverted by the next deploy"; that was the disproven model surviving
 * fifteen lines below its own correction.
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
