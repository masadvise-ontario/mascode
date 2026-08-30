<?php

/**
 * Creates the mas_lifecycle_rcs_chase rule: a Service Request enters
 * "Request RCS" → client_rep chased at 21/42 days until the RCS form returns
 * (which moves the SR to "RCS Completed" and cancels the pending chases).
 *
 * The only lifecycle chase on the service_request case type; all its siblings
 * are on project. Until task #931 this script BUILT the rule inline, with no
 * ensure*() method and no upgrade_NNNN caller, so `cv upgrade:db` never
 * provisioned it and it reached an environment only when someone ran this file.
 * It is now a thin wrapper like its siblings, and CRM_Mascode_Upgrader::
 * upgrade_5011() provisions it on existing installs.
 *
 * Send mode (immediate vs. a reviewable draft queued for click-send) is NOT
 * restated in this docblock on purpose — it lives in the action_params
 * LifecycleRuleProvisioner writes, and moves per environment via
 * tools/set_lifecycle_email_mode.php or the CiviRules action-config form.
 *
 * Fresh-environment bootstrap only. Thin wrapper around LifecycleRuleProvisioner;
 * idempotent. Run register-lifecycle-email-action.php first on a brand-new
 * environment.
 *
 * Usage: cv scr scripts/create-rcs-chase-rule.php --user=<admin>
 */

echo json_encode(\Civi\Mascode\Service\LifecycleRuleProvisioner::ensureRcsChaseRule(), JSON_PRETTY_PRINT) . "\n";
