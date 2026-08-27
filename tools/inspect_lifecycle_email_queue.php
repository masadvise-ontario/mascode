<?php

/**
 * Report the lifecycle emails sitting in CiviRules' delayed-action queue:
 * which mode each will run under, and when it releases. READ-ONLY — this
 * script writes nothing, by design.
 *
 *   cv scr <ext path>/tools/inspect_lifecycle_email_queue.php --user=brian.flett@masadvise.org
 *
 * Why there is no write counterpart. CiviRules snapshots the rule_action row
 * into the queue when a delayed action is SCHEDULED, so a mode flip via
 * tools/set_lifecycle_email_mode.php does not reach anything already queued —
 * on prod the 2026-08-20 propose->auto switch left 294 of 330 queued items
 * carrying the old mode. The obvious fix, rewriting the queued payload, is
 * both wrong and unnecessary:
 *
 *   - Wrong: a queued task holds TWO copies of the rule action, and the one
 *     execution reads is the action object's, not the engine's. A rewrite
 *     that reflects on the engine mutates a copy nothing reads, then reports
 *     success from it. (Written, caught in review, deleted — 2026-08-27.)
 *   - Unnecessary: LifecycleEmail::resolveLiveMode() re-reads the mode from
 *     civirule_rule_action at execution time, so a queued item already honours
 *     the current config whatever mode is baked into it.
 *
 * So the mode column below is what was baked in at schedule time; it is NOT
 * what will be sent. What will be sent is whatever the rule action row says
 * when the item releases. Use tools/set_lifecycle_email_mode.php to see and
 * change that.
 *
 * @see \Civi\Mascode\Service\LifecycleRuleProvisioner::describeQueuedLifecycleEmails()
 */

$result = \Civi\Mascode\Service\LifecycleRuleProvisioner::describeQueuedLifecycleEmails();

echo "'queued as' is the mode baked in when the item was scheduled. 'will send as' is the mode\n"
   . "on its rule action row right now — resolveLiveMode() reads that at execution time, so it\n"
   . "is what actually happens. They differ whenever the mode changed after the item was queued.\n\n";

printf("  %-18s %-14s %-34s %5s  %s\n", 'queued as', 'will send as', 'template', 'count', 'releasing');
echo '  ' . str_repeat('-', 100) . "\n";
foreach ($result['groups'] as $row) {
    printf(
        "  %-18s %-14s %-34s %5d  %s .. %s\n",
        $row['queued_mode'],
        $row['live_mode'],
        $row['template'],
        $row['count'],
        $row['first_release'],
        $row['last_release']
    );
}
if (!$result['groups']) {
    echo "  (none queued)\n";
}
if ($result['unparsed']) {
    printf("\n  WARNING: %d queue item(s) could not be parsed and are not counted above.\n", $result['unparsed']);
}
