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
//    Compares each declaration's msg_title / msg_subject / msg_html against the
//    live row it is matched to. This is the only drift check that works for
//    templates, and a difference here means the next deploy touching that
//    declaration OVERWRITES the live copy - so sync live -> repo first.
$templateDrift = [];
foreach (glob($managedDir . '/*.mgd.php') as $file) {
    $decls = include $file;
    if (!is_array($decls)) {
        continue;
    }
    foreach ($decls as $d) {
        if (($d['entity'] ?? NULL) !== 'MessageTemplate') {
            continue;
        }
        $declared = $d['params']['values'] ?? [];
        $title = $declared['msg_title'] ?? NULL;
        if ($title === NULL) {
            continue;
        }
        try {
            $live = \Civi\Api4\MessageTemplate::get(FALSE)
                ->addSelect('id', 'msg_title', 'msg_subject', 'msg_html')
                ->addWhere('msg_title', '=', $title)
                ->execute();
        }
        catch (\Throwable $e) {
            $templateDrift[] = ['declaration' => $d['name'] ?? basename($file), 'error' => $e->getMessage()];
            continue;
        }
        if (count($live) === 0) {
            $templateDrift[] = [
                'declaration' => $d['name'] ?? basename($file),
                'msg_title' => $title,
                'issue' => 'NO LIVE ROW under this title - the next reconcile will CREATE one',
            ];
            continue;
        }
        foreach ($live as $row) {
            $fields = [];
            foreach (['msg_subject', 'msg_html'] as $f) {
                if (!array_key_exists($f, $declared)) {
                    continue;
                }
                if ((string) $declared[$f] !== (string) ($row[$f] ?? '')) {
                    $fields[$f] = [
                        'declared_bytes' => strlen((string) $declared[$f]),
                        'live_bytes' => strlen((string) ($row[$f] ?? '')),
                    ];
                }
            }
            if ($fields) {
                $templateDrift[] = [
                    'declaration' => $d['name'] ?? basename($file),
                    'msg_title' => $title,
                    'template_id' => $row['id'],
                    'differs' => $fields,
                ];
            }
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
