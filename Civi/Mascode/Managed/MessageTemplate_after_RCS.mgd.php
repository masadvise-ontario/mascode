<?php

declare(strict_types=1);

/**
 * Client notice that their request has been circulated to the VC pool
 * ("after RCS", template id 76 on dev and production).
 *
 * Trigger: Service Request transitions to status "Sent for Assignment",
 * fired by the CiviRule `mas_lifecycle_rcs_circulated` (added 2026-09-24) in
 * auto mode, recipient `client_rep`. The VC-facing half of the same event,
 * mas_lifecycle_vc_assignment_offer__vc, is still unwired.
 *
 * ⚠ IT WAS SENT BY HAND UNTIL NOW, and heavily edited each time — read from
 * PRODUCTION on 2026-09-23: 58 sends across 29 distinct days in 2026 with 53
 * DISTINCT bodies. Automating it means those per-send edits stop happening,
 * so the template body now has to carry on its own what Nina used to add by
 * hand. That is a content question for her, not a wiring one.
 *
 * DECIDED 2026-09-24 (Nina): this becomes AUTOMATIC. The CiviRule
 * `mas_lifecycle_rcs_circulated` fires it when a Service Request enters
 * "Sent for Assignment", so the `mas_lifecycle_` prefix now states a live
 * mechanism rather than an intended one, and the title moved to
 * `mas_lifecycle_rcs_circulated__client` (upgrade_5017). The send figures that
 * argued for keeping it manual are what changed: they described the old
 * process, not the one Nina wants. `PENDING_DECISION` in
 * tests/Unit/Managed/MessageTemplateNamingTest.php is cleared accordingly.
 *
 * ⚠ THE BODY IS SYNCED FROM PRODUCTION (2026-09-23) AND PRODUCTION WINS.
 * The repo copy was stale by 370 bytes — it still carried a PS advertising a
 * seminar held on 4 June 2026. A UI edit to a message template is NOT
 * protected from this declaration, which is the opposite of what an earlier
 * version of this docblock claimed: `MessageTemplate` is not an APIv4
 * ManagedEntity (`CoreUtil::getInfoItem('MessageTemplate','type')` is
 * `['DAOEntity']`), so `civicrm_managed.entity_modified_date` is NEVER
 * stamped for it and `update => 'unmodified'` degrades to always-update —
 * core logs that fallback on every reconcile — it is in this site's own
 * ConfigAndLog and the count grows with every flush. `optimizePlan()` spares the row only while this declaration's
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
 * — the token the sibling MAS RCS Template already uses. ⚠ It resolves EMPTY
 * ("Hello ,") for an ORGANISATION recipient rather than an individual, and
 * fails silently, so glance at the first send after deploy. This repo is PUBLIC
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
