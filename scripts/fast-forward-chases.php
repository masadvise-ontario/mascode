<?php

/**
 * DEV-ONLY test helper: make queued delayed CiviRules actions due NOW and
 * process them, so the chase cadence (RCS 21/42d, close 30/90/150d) can be
 * tested without waiting.
 *
 * Modes:
 *   cv scr scripts/fast-forward-chases.php --user=<admin>
 *       Releases ALL queued items at once and processes them. Because several
 *       chase steps for one case fire together, the idempotency guard collapses
 *       them to a single draft — fine for a quick "did it fire" check, but it
 *       can't show the 2nd/3rd follow-up.
 *
 *   cv scr scripts/fast-forward-chases.php --user=<admin> -- --step
 *       Releases only the EARLIEST cadence step — all items sharing the
 *       earliest release time (within a 1-hour window) — and processes them.
 *       This matches production, where one chase step's items (incl. the
 *       duplicates from CiviRules' trigger multi-fire) all come due together
 *       and dedup to a single draft, while later steps stay queued. Step the
 *       real cadence: --step (draft #1) -> send it from the review tile ->
 *       --step (draft #2, the next delay) -> ...  (--one is a back-compat
 *       alias for --step.)
 *
 *   cv scr scripts/fast-forward-chases.php --user=<admin> -- --case=R26156
 *       Like --step, but scoped to ONE case (by MAS code R/P##### or numeric
 *       case id). Advances just that case's next chase step, ignoring every
 *       other case's queued items. Use this to test a specific case without
 *       wading through unrelated (or stale) queue entries. Combine freely:
 *       --case=R26156 implies step semantics for that case.
 *
 * Note on stale items: orphaned chase items (case deleted or moved off its
 * arming status) self-clear at their release_time via the "Process delayed
 * civirule actions" job's condition re-check; scripts/cleanup-orphaned-chase-
 * queue.php trims them early if the queue table gets noisy from testing.
 *
 * Dev-only: refuses to run unless the base URL is masdemo.localhost.
 */

$baseUrl = defined('CIVICRM_UF_BASEURL') ? CIVICRM_UF_BASEURL : (string) \Civi::paths()->getUrl('[cms.root]/');
if (strpos($baseUrl, 'masdemo.localhost') === false) {
    echo "REFUSED: this helper rewrites queue release times and is dev-only (base URL: {$baseUrl})\n";
    return;
}

$argvArr = $argv ?? [];
$step = in_array('--step', $argvArr, true) || in_array('--one', $argvArr, true);

// --case=<MAS code or numeric case id>: scope to one case (implies step).
$caseFilter = null;
foreach ($argvArr as $arg) {
    if (strpos($arg, '--case=') === 0) {
        $caseFilter = substr($arg, strlen('--case='));
    }
}

$queueName = \CRM_Civirules_Engine::QUEUE_NAME;

// Resolve --case to a case id (numeric passes through; MAS code is looked up
// on the SR or Project custom field).
$caseId = null;
if ($caseFilter !== null && $caseFilter !== '') {
    $step = true; // case scope is always step-wise
    if (ctype_digit($caseFilter)) {
        $caseId = (int) $caseFilter;
    } else {
        $caseId = (int) (\Civi\Api4\CiviCase::get(false)
            ->addSelect('id')
            ->addClause('OR',
                ['Cases_SR_Projects_.MAS_SR_Case_Code', '=', $caseFilter],
                ['Projects.MAS_Project_Case_Code', '=', $caseFilter]
            )
            ->addWhere('is_deleted', 'IN', [0, 1])
            ->execute()
            ->first()['id'] ?? 0);
    }
    if (!$caseId) {
        echo json_encode(['error' => "No case found for --case={$caseFilter}"], JSON_PRETTY_PRINT) . "\n";
        return;
    }
}

// When scoped to a case, restrict the candidate queue items to that case by
// extracting the case id from each serialized task.
$candidateIds = null; // null = all items
if ($caseId) {
    $extractCaseId = static function (string $data): ?int {
        if (preg_match('/civicrm_case.*?"id";s:\d+:"(\d+)"/s', $data, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/s:7:"case_id";s:\d+:"(\d+)"/', $data, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/s:7:"case_id";i:(\d+)/', $data, $m)) {
            return (int) $m[1];
        }
        return null;
    };
    $candidateIds = [];
    $scan = \CRM_Core_DAO::executeQuery(
        "SELECT id, data FROM civicrm_queue_item WHERE queue_name = %1",
        [1 => [$queueName, 'String']]
    );
    while ($scan->fetch()) {
        if ($extractCaseId($scan->data) === $caseId) {
            $candidateIds[] = (int) $scan->id;
        }
    }
    if (!$candidateIds) {
        echo json_encode([
            'mode' => "case ({$caseFilter} -> id {$caseId})",
            'items_processed' => 0,
            'note' => 'No queued chase items for this case. It may not have entered an arming status, or its chase already drafted/sent.',
        ], JSON_PRETTY_PRINT) . "\n";
        return;
    }
}

// Count pending (within scope) for reporting.
$pendingSql = "SELECT COUNT(*) FROM civicrm_queue_item WHERE queue_name = %1";
$pendingParams = [1 => [$queueName, 'String']];
if ($candidateIds) {
    $pendingSql .= ' AND id IN (' . implode(',', $candidateIds) . ')';
}
$pending = (int) \CRM_Core_DAO::singleValueQuery($pendingSql, $pendingParams);

$scopeClause = $candidateIds ? ' AND id IN (' . implode(',', $candidateIds) . ')' : '';

if ($step) {
    // Release the earliest cadence STEP within scope: all items within a
    // 1-hour window of the earliest release time. Distinct chase delays sit
    // days/weeks apart so this isolates one step, while same-step duplicates
    // from CiviRules' trigger multi-fire (queued together) all release at once
    // — exactly as they'd come due in production.
    $minRelease = \CRM_Core_DAO::singleValueQuery(
        "SELECT MIN(release_time) FROM civicrm_queue_item WHERE queue_name = %1{$scopeClause}",
        [1 => [$queueName, 'String']]
    );
    if (!$minRelease) {
        echo json_encode(['items_pending_before' => 0, 'items_processed' => 0,
            'note' => 'Queue empty (in scope) — nothing to release.'], JSON_PRETTY_PRINT) . "\n";
        return;
    }
    \CRM_Core_DAO::executeQuery(
        "UPDATE civicrm_queue_item SET release_time = NOW()
         WHERE queue_name = %1 AND release_time <= (%2 + INTERVAL 1 HOUR){$scopeClause}",
        [1 => [$queueName, 'String'], 2 => [$minRelease, 'String']]
    );
} else {
    \CRM_Core_DAO::executeQuery(
        "UPDATE civicrm_queue_item SET release_time = NOW() WHERE queue_name = %1{$scopeClause}",
        [1 => [$queueName, 'String']]
    );
}

$results = \CRM_Civirules_Engine::processDelayedActions(120);
$remainingSql = "SELECT COUNT(*) FROM civicrm_queue_item WHERE queue_name = %1";
if ($candidateIds) {
    $remainingSql .= ' AND id IN (' . implode(',', $candidateIds) . ')';
}
$remaining = (int) \CRM_Core_DAO::singleValueQuery($remainingSql, [1 => [$queueName, 'String']]);

$mode = $caseId
    ? "case {$caseFilter} (id {$caseId}, earliest step only)"
    : ($step ? 'step (earliest cadence step only)' : 'all');

echo json_encode([
    'mode' => $mode,
    'items_pending_before' => $pending,
    'items_processed' => count($results),
    'items_remaining' => $remaining,
    'note' => 'Check the case / review tile for new "Draft Email - Needs Review" activities; duplicates and left-status items are skipped (see CiviCRM log). Send the draft before the next run to see the following chase step.',
], JSON_PRETTY_PRINT) . "\n";
