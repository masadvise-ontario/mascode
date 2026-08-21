<?php

/**
 * Creates the mas_lifecycle_vc_close_propose rule: a "Project Close - VC
 * Report" activity added to a project case → the client close-request email
 * ("MAS Project Close - Client Template") goes out.
 *
 * What advances the case to "Awaiting Client Project Close Form": the send
 * itself. ProjectLifecycleStatusSubscriber watches for the resulting Email /
 * "Sent Automated Email" activity and matches its subject against the known
 * lifecycle templates — so the status moves when the email is sent, whether
 * that send was automatic or a human click-sending a queued draft. There is no
 * separate status-setting step, and nothing writes status_id directly.
 *
 * ⚠ The "_propose" in the rule name is now a historical misnomer — it dates
 * from when every lifecycle email queued a draft for review. Renaming it would
 * be a data migration against civirule_rule, so it stays.
 *
 * Send mode (immediate vs. a reviewable draft queued for click-send) is NOT
 * restated in this docblock on purpose — it lives in the action_params
 * LifecycleRuleProvisioner writes, and moves per environment via
 * tools/set_lifecycle_email_mode.php or the CiviRules action-config form. A
 * comment duplicating it just goes stale: this one claimed "propose mode" for
 * months after the default became immediate send.
 *
 * Fresh-environment bootstrap only — existing installs get this via
 * CRM_Mascode_Upgrader::upgrade_5003() (cv ext:upgrade-db). Thin wrapper
 * around LifecycleRuleProvisioner; idempotent. Run
 * register-lifecycle-email-action.php first on a brand-new environment.
 *
 * Usage: cv scr scripts/create-vc-close-propose-rule.php --user=<admin>
 */

echo json_encode(\Civi\Mascode\Service\LifecycleRuleProvisioner::ensureVcCloseProposeRule(), JSON_PRETTY_PRINT) . "\n";
