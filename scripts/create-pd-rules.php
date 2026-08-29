<?php

/**
 * Creates the three Project Definition flow rules:
 *   - mas_lifecycle_vc_pd_chase      (VC chased while Awaiting VC Project Definition)
 *   - mas_lifecycle_client_pd_chase  (client chased while Awaiting Client Project Definition)
 *   - mas_lifecycle_pd_client_propose (VC PD submitted -> client authorization email)
 *
 * Fresh-environment bootstrap only — existing installs get these via
 * CRM_Mascode_Upgrader::upgrade_5005() (cv upgrade:db). Thin wrapper
 * around LifecycleRuleProvisioner; idempotent. Run
 * register-lifecycle-email-action.php first on a brand-new environment.
 *
 * Send mode (immediate vs. a reviewable draft queued for click-send) is NOT
 * restated in this docblock on purpose — it lives in the action_params
 * LifecycleRuleProvisioner writes, and moves per environment via
 * tools/set_lifecycle_email_mode.php or the CiviRules action-config form. A
 * comment duplicating it just goes stale: this one claimed "propose mode" for
 * months after the default became immediate send.
 *
 * Usage: cv scr scripts/create-pd-rules.php --user=<admin>
 */

$p = \Civi\Mascode\Service\LifecycleRuleProvisioner::class;
$out = [];
foreach (['ensureVcPdChaseRule', 'ensureClientPdChaseRule', 'ensureClientPdProposeRule'] as $method) {
  $out[$method] = $p::$method();
}
echo json_encode($out, JSON_PRETTY_PRINT) . "\n";
