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
 *                    entity stop landing (silently).
 *
 * ⚠ 'unmodified' ONLY BEHAVES THAT WAY FOR APIv4 ManagedEntity TYPES.
 * CRM_Core_BAO_Managed::on_hook_civicrm_post() stamps entity_modified_date only
 * when CoreUtil::getInfoItem(<entity>, 'type') contains 'ManagedEntity'. It does
 * NOT for MessageTemplate (a plain DAOEntity), so that column is always NULL for
 * templates and the policy degrades to always-update: the DECLARATION wins
 * whenever its checksum changes. Section 2 below is therefore structurally
 * blind to MessageTemplate, and on 2026-09-23 it reported clean while production
 * was 322 and 370 bytes ahead of the repo in two of them. Section 3 exists
 * because of that: it content-diffs template bodies instead of trusting a flag.
 *
 * Read-only. Usage:
 *   cv scr scripts/check-managed-drift.php --user=<admin>
 */

// 1. Read every .mgd.php ONCE. Declaration files are plain PHP that runs on
//    include (some resolve helper classes), so including them twice doubles
//    that work and doubles the blast radius of a file that is not idempotent.
$managedDir = \CRM_Mascode_ExtensionUtil::path() . '/Civi/Mascode/Managed';
$declarations = [];
$policyByName = [];
foreach (glob($managedDir . '/*.mgd.php') as $file) {
    $decls = include $file;
    if (!is_array($decls)) {
        continue;
    }
    foreach ($decls as $d) {
        $d['__file'] = basename($file);
        $declarations[] = $d;
        if (!empty($d['name'])) {
            $policyByName[$d['name']] = $d['update'] ?? '(unset)';
        }
    }
}

// 2. Pull mascode-managed entities that CiviCRM has flagged as locally modified.
//    NOTE: cannot surface MessageTemplate drift — see the header. Section 3 does.
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

// 3. MessageTemplate drift, by CONTENT rather than by a flag that is never set.
//
//    ⚠ RESOLVED THROUGH THE MANAGED BINDING, NOT THE TITLE. createPlan() keys on
//    (module, name, entity_type) and never looks at msg_title, so the live row a
//    declaration governs is the one civicrm_managed.entity_id points at. Matching
//    on title instead gets the 2026-09-17 case exactly backwards: a template
//    renamed in the production UI has no row under the declared title, but the
//    managed row still points at it, so the next reconcile RENAMES IT BACK — it
//    does not create a duplicate. Three distinct states, three distinct remedies.
$managedRows = [];
foreach (
    \Civi\Api4\Managed::get(FALSE)
        ->addSelect('name', 'entity_id')
        ->addWhere('module', '=', 'mascode')
        ->addWhere('entity_type', '=', 'MessageTemplate')
        ->execute() as $mr
) {
    // Keep NULL distinct from absent: a managed row with a NULL entity_id is
    // not the same as no managed row, even though core treats both as CREATE
    // (createPlan() tests empty($entity_id)). Casting to int would collapse
    // them and mislabel the first as the second.
    $managedRows[$mr['name']] = $mr['entity_id'] === NULL ? NULL : (int) $mr['entity_id'];
}

$templateDrift = [];
foreach ($declarations as $d) {
    if (($d['entity'] ?? NULL) !== 'MessageTemplate') {
        continue;
    }
    $declared = $d['params']['values'] ?? [];
    $title = $declared['msg_title'] ?? NULL;
    $name = $d['name'] ?? $d['__file'];
    if ($title === NULL) {
        continue;
    }

    $hasManagedRow = array_key_exists($d['name'] ?? '', $managedRows);
    $boundId = $hasManagedRow ? $managedRows[$d['name']] : NULL;
    try {
        $get = \Civi\Api4\MessageTemplate::get(FALSE)
            ->addSelect('id', 'msg_title', 'msg_subject', 'msg_html');
        $live = $boundId
            ? $get->addWhere('id', '=', $boundId)->execute()
            : $get->addWhere('msg_title', '=', $title)->execute();
    }
    catch (\Throwable $e) {
        $templateDrift[] = ['declaration' => $name, 'error' => $e->getMessage()];
        continue;
    }

    if (count($live) === 0) {
        $templateDrift[] = [
            'declaration' => $name,
            'msg_title' => $title,
            'issue' => $boundId
                // The managed row survives a UI delete: the nulling half of
                // on_hook_civicrm_post() is gated on isApi4ManagedType() too.
                // And the failure is QUIET, not loud: updateExistingEntity()
                // calls MessageTemplate.update with values['id'] and no select,
                // so AbstractUpdateAction takes the batch path, matches nothing
                // and returns empty. No exception, no onApiError.
                ? "managed row points at template $boundId, which DOES NOT EXIST - the next deploy that changes this declaration will attempt an update matching nothing and SILENTLY SUCCEED, leaving the template missing. Clear the stale civicrm_managed row."
                : 'no managed row and no template under this title - the next reconcile will CREATE one (creates are never optimized out)',
        ];
        continue;
    }

    foreach ($live as $row) {
        $issues = [];
        if ($boundId && (string) $row['msg_title'] !== (string) $title) {
            $issues['msg_title'] = [
                'declared' => $title,
                'live' => $row['msg_title'],
                // NOT "the next reconcile": optimizePlan() drops the update
                // while declaration_checksum === checksum, so a title changed
                // in the UI alone is NOT renamed back by cv flush.
                'consequence' => 'the next deploy that CHANGES THIS DECLARATION renames the live row back to the declared title (it does not create a second template). cv flush alone will not, while the declaration checksum is unchanged.',
            ];
        }
        foreach (['msg_subject', 'msg_html'] as $f) {
            if (!array_key_exists($f, $declared)) {
                continue;
            }
            if ((string) $declared[$f] !== (string) ($row[$f] ?? '')) {
                $issues[$f] = [
                    'declared_bytes' => strlen((string) $declared[$f]),
                    'live_bytes' => strlen((string) ($row[$f] ?? '')),
                ];
            }
        }
        if ($issues) {
            $templateDrift[] = [
                'declaration' => $name,
                'msg_title' => $title,
                'template_id' => $row['id'],
                'resolved_by' => $boundId ? 'civicrm_managed.entity_id' : 'msg_title (no managed row)',
                'differs' => $issues,
            ];
        }
    }
}

echo json_encode([
    'summary' => [
        'template_content_drift_count' => count($templateDrift),
        'managed_ignored_count' => count($ignored),
        'managed_overwritten_on_reconcile_count' => count($overwritten),
        'managed_unknown_policy_count' => count($unknown),
        'afform_overridden_count' => count($afformOverridden),
    ],
    'TEMPLATE CONTENT DRIFT (declaration differs from the live row - the next deploy touching that declaration OVERWRITES live; sync live -> repo first)' => $templateDrift,
    'IGNORED managed (UI-edited + update=unmodified - code changes will NOT land; NOTE: never includes MessageTemplate, see header)' => $ignored,
    'overwritten managed on reconcile (UI-edited but update=always — code still wins, no action needed)' => $overwritten,
    'unknown-policy managed (edited; declaration not in Managed/ — inspect manually)' => $unknown,
    'OVERRIDDEN afforms (prod FormBuilder edit shadows the shipped ang/ file; revert the local override to let code show)' => $afformOverridden,
], JSON_PRETTY_PRINT) . "\n";
