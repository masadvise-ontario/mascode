<?php

/**
 * Rewrite the send mode inside ALREADY-QUEUED delayed lifecycle emails.
 *
 * Companion to tools/set_lifecycle_email_mode.php, which fixes the live
 * civirule_rule_action rows. That is not enough on its own: CiviRules
 * snapshots the whole rule_action row into its delayed-action queue when an
 * action is SCHEDULED, then executes that snapshot when the delay expires.
 * A mode flip therefore does not reach anything already in the queue — on
 * prod the 2026-08-20 switch left 294 queued chases still drafting for
 * review, releasing 2026-08-28 through 2027-01-16.
 *
 * LifecycleEmail::processAction() now re-reads the live mode at execution
 * time, so new drift cannot accumulate. This runner is the one-time cleanup
 * of items queued before that fix shipped, and stays useful as a way to see
 * what is sitting in the queue.
 *
 * DRY RUN BY DEFAULT — it reports and writes nothing. To apply:
 *
 *   MASCODE_QUEUE_REWRITE_APPLY=1 cv scr <ext path>/tools/requeue_lifecycle_email_mode.php --user=brian.flett@masadvise.org
 *
 * Target mode defaults to 'auto'; override with MASCODE_LIFECYCLE_MODE=propose
 * to roll queued items back. Idempotent — items already at the target mode
 * are counted and skipped.
 *
 * @see \Civi\Mascode\Service\LifecycleRuleProvisioner::rewriteQueuedLifecycleEmailMode()
 * @see CRM_Mascode_Upgrader::upgrade_5011()
 */

$mode = getenv('MASCODE_LIFECYCLE_MODE') ?: 'auto';
$apply = (bool) getenv('MASCODE_QUEUE_REWRITE_APPLY');

$result = \Civi\Mascode\Service\LifecycleRuleProvisioner::rewriteQueuedLifecycleEmailMode($mode, $apply);

printf(
    "%s queued lifecycle emails => %s\n"
    . "  delayed actions in the queue: %d (of which lifecycle emails: %d)\n"
    . "  %s: %d %s\n"
    . "  already at target mode: %d\n"
    . "  unreadable, left untouched: %d %s\n",
    $apply ? 'REWROTE' : 'DRY RUN —  would rewrite',
    $result['mode'],
    $result['queued_total'],
    $result['lifecycle'],
    $apply ? 'rewritten' : 'would rewrite',
    count($result['rewritten']),
    $result['rewritten'] ? '(queue_item ids: ' . implode(', ', $result['rewritten']) . ')' : '',
    $result['already'],
    count($result['unreadable']),
    $result['unreadable'] ? '(queue_item ids: ' . implode(', ', $result['unreadable']) . ')' : ''
);

if (!$apply) {
    echo "\nNothing was written. Re-run with MASCODE_QUEUE_REWRITE_APPLY=1 to apply.\n";
}

// Show the resulting queue so the change is verifiable in one pass: what is
// still due to go out, under which mode, and when. Shares its queue walk with
// the rewrite above, so this cannot report a different picture than was acted on.
$summary = \Civi\Mascode\Service\LifecycleRuleProvisioner::describeQueuedLifecycleEmails();

echo "\nqueued lifecycle emails still to run:\n";
foreach ($summary as $row) {
    printf(
        "  %-18s %-34s %4d  releasing %s .. %s\n",
        $row['mode'],
        $row['template'],
        $row['count'],
        $row['first_release'],
        $row['last_release']
    );
}
if (!$summary) {
    echo "  (none)\n";
}
