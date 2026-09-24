<?php

/**
 * Creates the mas_lifecycle_rcs_circulated rule: a Service Request enters
 * "Sent for Assignment" → the client rep is told the request has been
 * circulated to the VC pool, using mas_lifecycle_rcs_circulated__client.
 *
 * Send mode is NOT restated here on purpose — it lives in the action_params
 * LifecycleRuleProvisioner writes and moves per environment via
 * tools/set_lifecycle_email_mode.php. A comment duplicating it goes stale.
 *
 * FRESH-ENVIRONMENT BOOTSTRAP ONLY, and it is not optional. Existing installs
 * get this rule from CRM_Mascode_Upgrader::upgrade_5017() via `cv upgrade:db`,
 * but a brand-new `cv ext:enable` stamps schema_version straight to the newest
 * revision, so upgrade_5017 NEVER RUNS on a clean environment. Without this
 * script such a site would get all the other lifecycle rules and silently not
 * this one — no error, just clients who never hear their request was
 * circulated. Thin wrapper around the provisioner; idempotent. Run
 * register-lifecycle-email-action.php first on a brand-new environment.
 *
 * Usage: cv scr scripts/create-rcs-circulated-rule.php --user=<admin>
 */

echo json_encode(\Civi\Mascode\Service\LifecycleRuleProvisioner::ensureRcsCirculatedRule(), JSON_PRETTY_PRINT) . "\n";
