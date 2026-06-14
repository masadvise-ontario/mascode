<?php

/**
 * Report mascode-managed entities that have been edited OUTSIDE the code
 * (in the CiviCRM UI), so you can see which ones the managed-entity reconcile
 * will or won't overwrite on the next `cv flush`.
 *
 * WHY: managed entities declare an update policy in their .mgd.php:
 *   - 'always'     → code always wins; UI edits are overwritten on reconcile.
 *   - 'unmodified' → code wins ONLY until someone edits the entity in the UI;
 *                    after that the reconcile SKIPS it and code changes to that
 *                    entity stop landing (silently). This is the trap: a subject
 *                    fix committed to mascode never reaches an entity Nina (or
 *                    you, in dev) has hand-edited.
 *
 * CiviCRM records UI edits as a non-null civicrm_managed.entity_modified_date.
 * This script joins that flag with each entity's declared update policy (read
 * from the .mgd.php sources) and flags the ones that are being IGNORED.
 *
 * Read-only. Usage:
 *   cv scr scripts/check-managed-drift.php --user=<admin>
 */

// 1. Build name => update-policy map from the .mgd.php declarations.
$managedDir = \CRM_Mascode_ExtensionUtil::path() . '/Civi/Mascode/Managed';
$policyByName = [];
foreach (glob($managedDir . '/*.mgd.php') as $file) {
    $decls = include $file;
    if (!is_array($decls)) {
        continue;
    }
    foreach ($decls as $d) {
        if (!empty($d['name'])) {
            $policyByName[$d['name']] = $d['update'] ?? '(unset)';
        }
    }
}

// 2. Pull mascode-managed entities that CiviCRM has flagged as locally modified.
$dao = \CRM_Core_DAO::executeQuery(
    "SELECT name, entity_type, entity_id, entity_modified_date
       FROM civicrm_managed
      WHERE module = 'mascode' AND entity_modified_date IS NOT NULL
      ORDER BY entity_type, name"
);

$ignored = [];   // unmodified-policy + edited => code changes are being skipped
$overwritten = []; // always-policy + edited => harmless (code re-wins on reconcile)
$unknown = [];   // edited but no policy found (entity declared elsewhere)

while ($dao->fetch()) {
    $policy = $policyByName[$dao->name] ?? null;
    $row = [
        'name' => $dao->name,
        'entity_type' => $dao->entity_type,
        'entity_id' => (int) $dao->entity_id,
        'modified' => $dao->entity_modified_date,
        'policy' => $policy ?? '(not found in Managed/)',
    ];
    if ($policy === 'unmodified') {
        $ignored[] = $row;
    } elseif ($policy === 'always') {
        $overwritten[] = $row;
    } else {
        $unknown[] = $row;
    }
}

echo json_encode([
    'summary' => [
        'ignored_count' => count($ignored),
        'overwritten_on_reconcile_count' => count($overwritten),
        'unknown_policy_count' => count($unknown),
    ],
    'IGNORED (UI-edited + update=unmodified — code changes will NOT land; fix the UI edit or force via upgrade step)' => $ignored,
    'overwritten on reconcile (UI-edited but update=always — code still wins, no action needed)' => $overwritten,
    'unknown policy (edited; declaration not in Managed/, e.g. afform/elsewhere — inspect manually)' => $unknown,
], JSON_PRETTY_PRINT) . "\n";
