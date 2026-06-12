<?php

/**
 * Creates the mas_lifecycle_close_chase rule: project enters
 * "Awaiting Client Project Close Form" → client_rep chased in propose mode
 * at 30/90/150 days.
 *
 * Fresh-environment bootstrap only — existing installs get this (and the
 * retarget from the retired "Awaiting Close Form" status) via
 * CRM_Mascode_Upgrader::upgrade_5003() (cv ext:upgrade-db). Thin wrapper
 * around LifecycleRuleProvisioner; idempotent. Run
 * register-lifecycle-email-action.php first on a brand-new environment.
 *
 * Usage: cv scr scripts/create-close-chase-rule.php --user=<admin>
 */

echo json_encode(\Civi\Mascode\Service\LifecycleRuleProvisioner::ensureClientCloseChaseRule(), JSON_PRETTY_PRINT) . "\n";
