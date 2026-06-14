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

// 3. Afforms: not managed entities — they're file-backed in ang/ — but they
// have the same trap. A prod FormBuilder edit writes a site-level LOCAL
// override (has_local) that shadows the extension's shipped BASE file
// (has_base), so committed ang/ changes silently stop showing. base_module
// identifies the owning extension. has_local + has_base = overridden.
$afformOverridden = [];
try {
    $afforms = \Civi\Api4\Afform::get(false)
        ->addSelect('name', 'title', 'has_base', 'has_local', 'base_module')
        ->addWhere('base_module', '=', 'mascode')
        ->execute();
    foreach ($afforms as $a) {
        if (!empty($a['has_local']) && !empty($a['has_base'])) {
            $afformOverridden[] = [
                'name' => $a['name'],
                'title' => $a['title'] ?? '',
                'note' => 'site override shadows the shipped ang/ file',
            ];
        }
    }
} catch (\Throwable $e) {
    $afformOverridden[] = ['error' => 'Afform.get failed: ' . $e->getMessage()];
}

echo json_encode([
    'summary' => [
        'managed_ignored_count' => count($ignored),
        'managed_overwritten_on_reconcile_count' => count($overwritten),
        'managed_unknown_policy_count' => count($unknown),
        'afform_overridden_count' => count($afformOverridden),
    ],
    'IGNORED managed (UI-edited + update=unmodified — code changes will NOT land; fix the UI edit or force via upgrade step)' => $ignored,
    'overwritten managed on reconcile (UI-edited but update=always — code still wins, no action needed)' => $overwritten,
    'unknown-policy managed (edited; declaration not in Managed/ — inspect manually)' => $unknown,
    'OVERRIDDEN afforms (prod FormBuilder edit shadows the shipped ang/ file; revert the local override to let code show)' => $afformOverridden,
], JSON_PRETTY_PRINT) . "\n";
