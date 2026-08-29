<?php

/**
 * Switch every mas_lifecycle_email CiviRules action between propose mode
 * (queue a "Draft Email - Needs Review" activity for CSM click-send) and auto
 * mode (send immediately).
 *
 * Why both this and CRM_Mascode_Upgrader::upgrade_5010(): the upgrade step is
 * what lands the change on prod via the normal deploy ritual (`cv upgrade:db`
 * DOES run it — prod's schema_version reached 5010 that way on 2026-08-20). This
 * runner exists for re-running, rolling back, and printing verifiable end-state
 * output. Both are idempotent, so running them in sequence is safe — the second
 * one reports "already correct". Run it per environment with:
 *
 *   cv scr <ext path>/tools/set_lifecycle_email_mode.php --user=brian.flett@masadvise.org
 *
 * Defaults to 'auto'. To roll back, set MASCODE_LIFECYCLE_MODE=propose:
 *
 *   MASCODE_LIFECYCLE_MODE=propose cv scr <ext path>/tools/set_lifecycle_email_mode.php --user=...
 *
 * Idempotent — rows already at the target mode are skipped. Does NOT touch the
 * SR→Project PD request, whose mode lives in ServiceRequestToProject.php.
 *
 * Already-queued DELAYED actions need no second pass. They carry a snapshot of
 * the rule action taken when they were scheduled, but
 * LifecycleEmail::resolveLiveMode() re-reads the mode from civirule_rule_action
 * at execution time, so they follow whatever this script sets. View that
 * backlog with tools/inspect_lifecycle_email_queue.php (read-only) — and do not
 * try to rewrite the queued payload: a queued task holds two copies of the rule
 * action and only the action object's is ever read.
 */

$mode = getenv('MASCODE_LIFECYCLE_MODE') ?: 'auto';

$result = \Civi\Mascode\Service\LifecycleRuleProvisioner::setLifecycleEmailMode($mode);

printf(
    "lifecycle email mode => %s\n  action rows updated: %d %s\n  already correct: %d\n  rule descriptions updated: %d\n",
    $result['mode'],
    count($result['updated_action_rows']),
    $result['updated_action_rows'] ? '(' . implode(', ', $result['updated_action_rows']) . ')' : '',
    $result['skipped'],
    count($result['descriptions_updated'])
);

// Show the resulting configuration so the change is verifiable in one pass.
$dao = \CRM_Core_DAO::executeQuery(
    "SELECT r.name AS rule_name, ra.id AS row_id, ra.action_params
       FROM civirule_rule_action ra
       JOIN civirule_rule r ON r.id = ra.rule_id
       JOIN civirule_action a ON a.id = ra.action_id
      WHERE a.name = 'mas_lifecycle_email'
      ORDER BY r.name, ra.id"
);
echo "\ncurrent configuration:\n";
while ($dao->fetch()) {
    $p = unserialize((string) $dao->action_params) ?: [];
    printf(
        "  %-34s row %-5d %-36s %s\n",
        $dao->rule_name,
        (int) $dao->row_id,
        $p['template'] ?? '?',
        $p['mode'] ?? 'propose (default)'
    );
}
