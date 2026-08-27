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

$summary = \Civi\Mascode\Service\LifecycleRuleProvisioner::describeQueuedLifecycleEmails();

$live = \CRM_Core_DAO::executeQuery(
    "SELECT DISTINCT ra.action_params
       FROM civirule_rule_action ra
       JOIN civirule_action a ON a.id = ra.action_id
      WHERE a.name = 'mas_lifecycle_email'"
);
$liveModes = [];
while ($live->fetch()) {
    $p = @unserialize((string) $live->action_params, ['allowed_classes' => false]);
    $liveModes[is_array($p) ? ($p['mode'] ?? 'propose (default)') : '(unreadable)'] = TRUE;
}
printf("live rule config sends as: %s\n", implode(', ', array_keys($liveModes)) ?: '(no rule actions found)');
echo "that is what every queued item below will actually do — the mode column is only what was baked in when it was scheduled.\n\n";

printf("  %-18s %-34s %5s  %s\n", 'mode when queued', 'template', 'count', 'releasing');
echo '  ' . str_repeat('-', 86) . "\n";
foreach ($summary as $row) {
    printf(
        "  %-18s %-34s %5d  %s .. %s\n",
        $row['mode'],
        $row['template'],
        $row['count'],
        $row['first_release'],
        $row['last_release']
    );
}
if (!$summary) {
    echo "  (none queued)\n";
}
