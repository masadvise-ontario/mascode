<?php

/**
 * Switch every mas_lifecycle_email CiviRules action between propose mode
 * (queue a "Draft Email - Needs Review" activity for CSM click-send) and auto
 * mode (send immediately).
 *
 * Why a standalone script: CRM_Mascode_Upgrader::upgrade_5010() holds the same
 * call, but CiviCRM's extension-upgrade baseline treats already-installed
 * extensions as current (hasPending() == false, schema_version NULL), so the
 * upgrader never fires on an existing install. Run this once per environment:
 *
 *   cv scr <ext path>/tools/set_lifecycle_email_mode.php --user=brian.flett@masadvise.org
 *
 * Defaults to 'auto'. To roll back, set MASCODE_LIFECYCLE_MODE=propose:
 *
 *   MASCODE_LIFECYCLE_MODE=propose cv scr <ext path>/tools/set_lifecycle_email_mode.php --user=...
 *
 * Idempotent — rows already at the target mode are skipped. Does NOT touch the
 * SR→Project PD request, whose mode lives in ServiceRequestToProject.php.
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
