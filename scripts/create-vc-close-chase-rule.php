<?php

/**
 * Creates the mas_lifecycle_vc_close_chase rule: project enters
 * "Awaiting VC Project Close Form" → the VC (Case Coordinator) chased in
 * propose mode at 30/90/150 days using mas_lifecycle_close_chase__vc.
 *
 * Fresh-environment bootstrap only — existing installs get this via
 * CRM_Mascode_Upgrader::upgrade_5003() (cv ext:upgrade-db). Thin wrapper
 * around LifecycleRuleProvisioner; idempotent. Run
 * register-lifecycle-email-action.php first on a brand-new environment.
 *
 * Usage: cv scr scripts/create-vc-close-chase-rule.php --user=<admin>
 */

echo json_encode(\Civi\Mascode\Service\LifecycleRuleProvisioner::ensureVcCloseChaseRule(), JSON_PRETTY_PRINT) . "\n";
