<?php

/**
 * DEV-ONLY test helper: make all queued delayed CiviRules actions due NOW
 * and process them. Lets you test the 30/90/150-day chase cadence without
 * waiting — set a project to "Awaiting Close Form", run this, and the
 * propose-mode chase draft appears on the case immediately (or is skipped
 * if the case has since left the status — that's the self-cancel working).
 *
 * Usage: cv scr scripts/fast-forward-chases.php --user=<admin>
 */

$baseUrl = defined('CIVICRM_UF_BASEURL') ? CIVICRM_UF_BASEURL : (string) \Civi::paths()->getUrl('[cms.root]/');
if (strpos($baseUrl, 'masdemo.localhost') === false) {
    echo "REFUSED: this helper rewrites queue release times and is dev-only (base URL: {$baseUrl})\n";
    return;
}

$queueName = \CRM_Civirules_Engine::QUEUE_NAME;
$pending = (int) \CRM_Core_DAO::singleValueQuery(
    "SELECT COUNT(*) FROM civicrm_queue_item WHERE queue_name = %1",
    [1 => [$queueName, 'String']]
);
\CRM_Core_DAO::executeQuery(
    "UPDATE civicrm_queue_item SET release_time = NOW() WHERE queue_name = %1",
    [1 => [$queueName, 'String']]
);
$results = \CRM_Civirules_Engine::processDelayedActions(120);

echo json_encode([
    'items_pending_before' => $pending,
    'items_processed' => count($results),
    'note' => 'Check the case for new "Draft Email - Needs Review" activities; duplicates/left-status items are skipped (see CiviCRM log).',
], JSON_PRETTY_PRINT) . "\n";
