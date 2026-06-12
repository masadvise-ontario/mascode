<?php

/**
 * Creates the three Project Definition flow rules:
 *   - mas_lifecycle_vc_pd_chase      (VC chased while Awaiting VC Project Definition)
 *   - mas_lifecycle_client_pd_chase  (client chased while Awaiting Client Project Definition)
 *   - mas_lifecycle_pd_client_propose (VC PD submitted -> draft client authorization email)
 *
 * Fresh-environment bootstrap only — existing installs get these via
 * CRM_Mascode_Upgrader::upgrade_5005() (cv ext:upgrade-db). Thin wrapper
 * around LifecycleRuleProvisioner; idempotent. Run
 * register-lifecycle-email-action.php first on a brand-new environment.
 *
 * Usage: cv scr scripts/create-pd-rules.php --user=<admin>
 */

$p = \Civi\Mascode\Service\LifecycleRuleProvisioner::class;
$out = [];
foreach (['ensureVcPdChaseRule', 'ensureClientPdChaseRule', 'ensureClientPdProposeRule'] as $method) {
  $out[$method] = $p::$method();
}
echo json_encode($out, JSON_PRETTY_PRINT) . "\n";
