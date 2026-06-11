<?php

/**
 * Registers the `mas_lifecycle_email` CiviRules ACTION (class
 * Civi\Mascode\CiviRules\Action\LifecycleEmail) that the lifecycle chase rules
 * depend on (create-rcs-chase-rule.php / create-close-chase-rule.php).
 *
 * Why this script exists: the action was originally added through the CiviRules
 * UI in dev and never versioned, so it is NOT created by git pull + cv flush and
 * is absent on a fresh environment (e.g. prod). The chase-rule scripts look the
 * action up by name in civirule_action and fail without it. Run this FIRST on a
 * new environment, then the two create-*-chase-rule.php scripts.
 *
 * Idempotent — skips if the action name already exists. Not dev-gated; it is
 * meant to run in any environment.
 *
 * Usage: cv scr scripts/register-lifecycle-email-action.php --user=<admin>
 */

$existing = \CRM_Core_DAO::singleValueQuery(
  "SELECT id FROM civirule_action WHERE name = 'mas_lifecycle_email'"
);
if ($existing) {
  echo json_encode(['already_exists' => (int) $existing]) . "\n";
  return;
}

$action = \CRM_Civirules_BAO_CiviRulesAction::writeRecord([
  'name' => 'mas_lifecycle_email',
  'label' => 'mas: Lifecycle email (propose/auto)',
  'class_name' => 'Civi\\Mascode\\CiviRules\\Action\\LifecycleEmail',
  'is_active' => 1,
]);

echo json_encode(['created' => (int) $action->id, 'name' => 'mas_lifecycle_email']) . "\n";
