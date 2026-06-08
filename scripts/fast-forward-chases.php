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
 *   cv scr scripts/fast-forward-chases.php --user=<admin> -- --one
 *       Releases only the EARLIEST-due item and processes just that one. Step
 *       through the real cadence: --one (draft #1) -> send it from the review
 *       tile -> --one (draft #2, since #1 is no longer an unsent draft) -> ...
 *
 * Dev-only: refuses to run unless the base URL is masdemo.localhost.
 */

$baseUrl = defined('CIVICRM_UF_BASEURL') ? CIVICRM_UF_BASEURL : (string) \Civi::paths()->getUrl('[cms.root]/');
if (strpos($baseUrl, 'masdemo.localhost') === false) {
    echo "REFUSED: this helper rewrites queue release times and is dev-only (base URL: {$baseUrl})\n";
    return;
}

$one = in_array('--one', $argv ?? [], true);
$queueName = \CRM_Civirules_Engine::QUEUE_NAME;
$pending = (int) \CRM_Core_DAO::singleValueQuery(
    "SELECT COUNT(*) FROM civicrm_queue_item WHERE queue_name = %1",
    [1 => [$queueName, 'String']]
);

if ($one) {
    // Release just the earliest-due item (lowest release_time, then lowest id).
    $itemId = \CRM_Core_DAO::singleValueQuery(
        "SELECT id FROM civicrm_queue_item WHERE queue_name = %1 ORDER BY release_time ASC, id ASC LIMIT 1",
        [1 => [$queueName, 'String']]
    );
    if (!$itemId) {
        echo json_encode(['items_pending_before' => 0, 'items_processed' => 0,
            'note' => 'Queue empty — nothing to release.'], JSON_PRETTY_PRINT) . "\n";
        return;
    }
    \CRM_Core_DAO::executeQuery(
        "UPDATE civicrm_queue_item SET release_time = NOW() WHERE id = %1",
        [1 => [(int) $itemId, 'Integer']]
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
    'mode' => $one ? 'one (earliest-due only)' : 'all',
    'items_pending_before' => $pending,
    'items_processed' => count($results),
    'items_remaining' => $remaining,
    'note' => 'Check the case / review tile for new "Draft Email - Needs Review" activities; duplicates and left-status items are skipped (see CiviCRM log). In --one mode, send the draft before the next run to see the following chase step.',
], JSON_PRETTY_PRINT) . "\n";
