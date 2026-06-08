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
 * Dev-only: refuses to run unless the base URL is masdemo.localhost.
 */

$baseUrl = defined('CIVICRM_UF_BASEURL') ? CIVICRM_UF_BASEURL : (string) \Civi::paths()->getUrl('[cms.root]/');
if (strpos($baseUrl, 'masdemo.localhost') === false) {
    echo "REFUSED: this helper rewrites queue release times and is dev-only (base URL: {$baseUrl})\n";
    return;
}

$argvArr = $argv ?? [];
$step = in_array('--step', $argvArr, true) || in_array('--one', $argvArr, true);
$queueName = \CRM_Civirules_Engine::QUEUE_NAME;
$pending = (int) \CRM_Core_DAO::singleValueQuery(
    "SELECT COUNT(*) FROM civicrm_queue_item WHERE queue_name = %1",
    [1 => [$queueName, 'String']]
);

if ($step) {
    // Release the earliest cadence STEP: all items within a 1-hour window of
    // the earliest release time. Distinct chase delays (e.g. 21d vs 42d) sit
    // ~weeks apart so this isolates one step, while same-step duplicates from
    // CiviRules' trigger multi-fire (queued together) all release at once —
    // exactly as they'd come due in production.
    $minRelease = \CRM_Core_DAO::singleValueQuery(
        "SELECT MIN(release_time) FROM civicrm_queue_item WHERE queue_name = %1",
        [1 => [$queueName, 'String']]
    );
    if (!$minRelease) {
        echo json_encode(['items_pending_before' => 0, 'items_processed' => 0,
            'note' => 'Queue empty — nothing to release.'], JSON_PRETTY_PRINT) . "\n";
        return;
    }
    \CRM_Core_DAO::executeQuery(
        "UPDATE civicrm_queue_item SET release_time = NOW()
         WHERE queue_name = %1 AND release_time <= (%2 + INTERVAL 1 HOUR)",
        [1 => [$queueName, 'String'], 2 => [$minRelease, 'String']]
    );
} else {
    \CRM_Core_DAO::executeQuery(
        "UPDATE civicrm_queue_item SET release_time = NOW() WHERE queue_name = %1",
        [1 => [$queueName, 'String']]
    );
}

$results = \CRM_Civirules_Engine::processDelayedActions(120);
$remaining = (int) \CRM_Core_DAO::singleValueQuery(
    "SELECT COUNT(*) FROM civicrm_queue_item WHERE queue_name = %1",
    [1 => [$queueName, 'String']]
);

echo json_encode([
    'mode' => $step ? 'step (earliest cadence step only)' : 'all',
    'items_pending_before' => $pending,
    'items_processed' => count($results),
    'items_remaining' => $remaining,
    'note' => 'Check the case / review tile for new "Draft Email - Needs Review" activities; duplicates and left-status items are skipped (see CiviCRM log). In --one mode, send the draft before the next run to see the following chase step.',
], JSON_PRETTY_PRINT) . "\n";
