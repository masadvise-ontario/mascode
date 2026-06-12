<?php

/**
 * Creates the mas_lifecycle_vc_close_propose rule: a "Project Close - VC
 * Report" activity added to a project case → the client close-request email
 * ("MAS Project Close - Client Template") is drafted in propose mode.
 * Click-sending the draft advances the case to "Awaiting Client Project
 * Close Form" via ProjectLifecycleStatusSubscriber.
 *
 * Fresh-environment bootstrap only — existing installs get this via
 * CRM_Mascode_Upgrader::upgrade_5003() (cv ext:upgrade-db). Thin wrapper
 * around LifecycleRuleProvisioner; idempotent. Run
 * register-lifecycle-email-action.php first on a brand-new environment.
 *
 * Usage: cv scr scripts/create-vc-close-propose-rule.php --user=<admin>
 */

echo json_encode(\Civi\Mascode\Service\LifecycleRuleProvisioner::ensureVcCloseProposeRule(), JSON_PRETTY_PRINT) . "\n";
