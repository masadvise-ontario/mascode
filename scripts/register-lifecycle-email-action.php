<?php

/**
 * Registers the `mas_lifecycle_email` CiviRules ACTION (class
 * Civi\Mascode\CiviRules\Action\LifecycleEmail) that the lifecycle rules
 * depend on.
 *
 * Fresh-environment bootstrap only — existing installs get this via
 * CRM_Mascode_Upgrader::upgrade_5003() (cv ext:upgrade-db). Thin wrapper
 * around LifecycleRuleProvisioner; idempotent.
 *
 * Usage: cv scr scripts/register-lifecycle-email-action.php --user=<admin>
 */

echo json_encode(\Civi\Mascode\Service\LifecycleRuleProvisioner::ensureLifecycleEmailAction()) . "\n";
