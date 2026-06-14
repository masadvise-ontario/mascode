<?php

/**
 * Trim ORPHANED MAS lifecycle chase items from the CiviRules delayed-action
 * queue — items whose case has been deleted or has moved off the status that
 * armed the chase, so they would only be skipped (never sent) at release time.
 *
 * WHY THIS IS OPTIONAL HYGIENE, NOT CORRECTNESS:
 *   CiviRules already protects against wrong sends. Each chase action has
 *   ignore_condition_with_delay = 0, so when the scheduled job "Process
 *   delayed civirule actions" reaches an item at its release_time it RE-CHECKS
 *   the rule conditions; if the case has moved on (or is gone) the action is
 *   skipped and the item is consumed. So orphaned items self-clear at their
 *   release_time (≤ the longest chase delay away) and never produce a stray
 *   email. This script only keeps the queue TABLE lean in the meantime — handy
 *   after heavy testing (fast-forward-chases.php manufactures many short-lived
 *   cases) and as a belt-and-suspenders ops trim. Eagerly deleting on case
 *   status-change/delete was rejected: it would couple case lifecycle hooks to
 *   CiviRules queue internals (serialized blobs, no public cancel API) to solve
 *   a problem the release-time re-check already solves.
 *
 * Live items (case still in an arming status) are always preserved.
 *
 * Usage:
 *   cv scr scripts/cleanup-orphaned-chase-queue.php --user=<admin>            # dry-run (report only)
 *   cv scr scripts/cleanup-orphaned-chase-queue.php --user=<admin> -- --delete # actually delete orphans
 */

// Statuses that ARM each chase (the case_status a chase fires from / waits on).
$ARMING_STATUSES = [
    'Request RCS',
    'Awaiting VC Project Definition',
    'Awaiting Client Project Definition',
    'Awaiting VC Project Close Form',
    'Awaiting Client Project Close Form',
];

$argvArr = $argv ?? [];
$doDelete = in_array('--delete', $argvArr, true);

$queueName = \CRM_Civirules_Engine::QUEUE_NAME;
$dao = \CRM_Core_DAO::executeQuery(
    "SELECT id, data FROM civicrm_queue_item WHERE queue_name = %1",
    [1 => [$queueName, 'String']]
);

/** Best-effort case id extraction from a serialized CiviRules queue task. */
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

$orphanIds = [];
$live = 0;
while ($dao->fetch()) {
    $caseId = $extractCaseId($dao->data);
    $isLive = false;
    if ($caseId) {
        $case = \Civi\Api4\CiviCase::get(false)
            ->addSelect('status_id:name', 'is_deleted')
            ->addWhere('id', '=', $caseId)
            ->addWhere('is_deleted', 'IN', [0, 1])
            ->execute()
            ->first();
        $isLive = $case
            && empty($case['is_deleted'])
            && in_array($case['status_id:name'] ?? '', $ARMING_STATUSES, true);
    }
    if ($isLive) {
        $live++;
    } else {
        $orphanIds[] = (int) $dao->id;
    }
}

$deleted = 0;
if ($doDelete && $orphanIds) {
    $in = implode(',', array_map('intval', $orphanIds));
    \CRM_Core_DAO::executeQuery("DELETE FROM civicrm_queue_item WHERE id IN ($in)");
    $deleted = count($orphanIds);
}

echo json_encode([
    'mode' => $doDelete ? 'delete' : 'dry-run',
    'live_preserved' => $live,
    'orphaned_found' => count($orphanIds),
    'orphaned_deleted' => $deleted,
    'orphan_queue_ids' => $orphanIds,
    'note' => $doDelete
        ? 'Orphaned chase queue items removed; live (case still in an arming status) preserved.'
        : 'Dry run — re-run with `-- --delete` to remove the orphaned items listed.',
], JSON_PRETTY_PRINT) . "\n";
