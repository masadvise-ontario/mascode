<?php

declare(strict_types=1);

// file: Civi/Mascode/Service/LifecycleRuleProvisioner.php

namespace Civi\Mascode\Service;

/**
 * Idempotent provisioning of the lifecycle CiviRules rule assemblies
 * (rule + conditions + actions rows), so rules ship as code instead of
 * being hand-built in the UI per environment.
 *
 * Called from CRM_Mascode_Upgrader upgrade_NNNN steps (existing installs,
 * zero-touch via `cv ext:upgrade-db`) and from thin scripts/create-*.php
 * wrappers (fresh-environment bootstrap, where upgrade steps don't run).
 * Every method short-circuits when its target already exists.
 *
 * See docs/CONFIGURATION-AS-CODE.md ("CiviRules rule" authoring flow).
 */
final class LifecycleRuleProvisioner
{
    /**
     * The mas_lifecycle_email ACTION the lifecycle rules depend on.
     * (Originally UI-created in dev — see scripts/register-lifecycle-email-action.php history.)
     */
    public static function ensureLifecycleEmailAction(): array
    {
        $existing = \CRM_Core_DAO::singleValueQuery(
            "SELECT id FROM civirule_action WHERE name = 'mas_lifecycle_email'"
        );
        if ($existing) {
            return ['already_exists' => (int) $existing];
        }
        $action = \CRM_Civirules_BAO_CiviRulesAction::writeRecord([
            'name' => 'mas_lifecycle_email',
            'label' => 'mas: Lifecycle email (propose/auto)',
            'class_name' => 'Civi\\Mascode\\CiviRules\\Action\\LifecycleEmail',
            'is_active' => 1,
        ]);
        return ['created' => (int) $action->id, 'name' => 'mas_lifecycle_email'];
    }

    /**
     * Client close-form chase: project enters "Awaiting Client Project Close
     * Form"; client_rep chased in auto mode at 30/90/150 days.
     */
    public static function ensureClientCloseChaseRule(): array
    {
        return self::ensureStatusChaseRule(
            'mas_lifecycle_close_chase',
            'mas: Lifecycle close-form chase (client)',
            'Project enters Awaiting Client Project Close Form; client is chased in auto-mode (sent immediately) at 30/90/150 days unless the case has left the status (conditions re-checked with fresh data at each delayed firing).',
            'Awaiting Client Project Close Form',
            'mas_lifecycle_close_chase__client',
            'client_rep'
        );
    }

    /**
     * VC close-report chase: project enters "Awaiting VC Project Close Form";
     * the VC (Case Coordinator) chased in auto mode at 30/90/150 days.
     */
    public static function ensureVcCloseChaseRule(): array
    {
        return self::ensureStatusChaseRule(
            'mas_lifecycle_vc_close_chase',
            'mas: Lifecycle close-report chase (VC)',
            'Project enters Awaiting VC Project Close Form; the VC is chased in auto-mode (sent immediately) at 30/90/150 days unless the case has left the status (conditions re-checked with fresh data at each delayed firing).',
            'Awaiting VC Project Close Form',
            'mas_lifecycle_close_chase__vc',
            'coordinator'
        );
    }

    /**
     * Send the client close email when the VC close report arrives: a
     * "Project Close - VC Report" activity is added to a project case → the
     * client close-request email goes out immediately (auto mode). The
     * resulting "Sent Automated Email" activity flips the case to "Awaiting
     * Client Project Close Form" via ProjectLifecycleStatusSubscriber.
     */
    public static function ensureVcCloseProposeRule(): array
    {
        $existing = \CRM_Core_DAO::singleValueQuery(
            "SELECT id FROM civirule_rule WHERE name = 'mas_lifecycle_vc_close_propose'"
        );
        if ($existing) {
            return ['already_exists' => (int) $existing];
        }

        $triggerId = self::requireId("SELECT id FROM civirule_trigger WHERE name = 'added_case_activity'", 'trigger added_case_activity');
        $actionId = self::requireId("SELECT id FROM civirule_action WHERE name = 'mas_lifecycle_email'", 'action mas_lifecycle_email');
        $caseTypeCondId = self::requireId("SELECT id FROM civirule_condition WHERE name = 'case_type'", 'condition case_type');
        $activityTypeCondId = self::requireId("SELECT id FROM civirule_condition WHERE name = 'activity_of_type'", 'condition activity_of_type');

        $vcReportTypeValue = (int) \Civi\Api4\OptionValue::get(false)
            ->addWhere('option_group_id:name', '=', 'activity_type')
            ->addWhere('name', '=', 'Project Close - VC Report')
            ->execute()->first()['value'];

        $rule = \CRM_Civirules_BAO_CiviRulesRule::writeRecord([
            'name' => 'mas_lifecycle_vc_close_propose',
            'label' => 'mas: Propose client close email on VC close report',
            'trigger_id' => $triggerId,
            'is_active' => 1,
            'description' => 'VC close report received on a project; the client close-request email is sent immediately (auto mode). Sending advances the case to Awaiting Client Project Close Form.',
        ]);
        $ruleId = (int) $rule->id;

        $conds = [
            [$caseTypeCondId, serialize(['operator' => 0, 'case_type_id' => [self::projectCaseTypeId()]]), null],
            [$activityTypeCondId, serialize(['operator' => 0, 'activity_type_id' => [$vcReportTypeValue]]), 'AND'],
        ];
        $condRows = self::writeConditions($ruleId, $conds);

        $row = \CRM_Civirules_BAO_CiviRulesRuleAction::writeRecord([
            'rule_id' => $ruleId,
            'action_id' => $actionId,
            'action_params' => serialize([
                'template' => 'MAS Project Close - Client Template',
                'recipient' => 'client_rep',
                'mode' => 'auto',
            ]),
            'ignore_condition_with_delay' => 0,
            'is_active' => 1,
        ]);

        return [
            'rule_id' => $ruleId,
            'condition_rows' => $condRows,
            'action_rows' => [(int) $row->id],
        ];
    }

    /**
     * One-time migration: repoint the existing mas_lifecycle_close_chase
     * rule's conditions from the retired "Awaiting Close Form" status to
     * "Awaiting Client Project Close Form". No-op when the rule is absent
     * or already retargeted.
     */
    public static function retargetClientCloseChaseRule(): array
    {
        $ruleId = (int) \CRM_Core_DAO::singleValueQuery(
            "SELECT id FROM civirule_rule WHERE name = 'mas_lifecycle_close_chase'"
        );
        if (!$ruleId) {
            return ['rule_absent' => true];
        }

        $newValue = self::caseStatusValue('Awaiting Client Project Close Form');
        $updated = [];

        $dao = \CRM_Core_DAO::executeQuery(
            "SELECT rc.id, rc.condition_params, c.name
               FROM civirule_rule_condition rc
               JOIN civirule_condition c ON c.id = rc.condition_id
              WHERE rc.rule_id = %1 AND c.name IN ('case_status_changed', 'case_status')",
            [1 => [$ruleId, 'Integer']]
        );
        while ($dao->fetch()) {
            $params = unserialize($dao->condition_params) ?: [];
            if ($dao->name === 'case_status_changed') {
                $params['original_value'] = $newValue;
                $params['value'] = $newValue;
            } else {
                $params['status_id'] = [$newValue];
            }
            \CRM_Core_DAO::executeQuery(
                "UPDATE civirule_rule_condition SET condition_params = %1 WHERE id = %2",
                [1 => [serialize($params), 'String'], 2 => [(int) $dao->id, 'Integer']]
            );
            $updated[] = (int) $dao->id;
        }

        \CRM_Core_DAO::executeQuery(
            "UPDATE civirule_rule SET description = %1 WHERE id = %2",
            [
                1 => ['Project enters Awaiting Client Project Close Form; client is chased in auto-mode (sent immediately) at 30/90/150 days unless the case has left the status (conditions re-checked with fresh data at each delayed firing).', 'String'],
                2 => [$ruleId, 'Integer'],
            ]
        );

        return ['rule_id' => $ruleId, 'updated_condition_rows' => $updated, 'new_status_value' => $newValue];
    }

    /**
     * VC Project Definition chase: project enters "Awaiting VC Project
     * Definition" (set at SR→Project conversion); the VC chased in auto
     * mode at 30/90/150 days until the PD form arrives.
     */
    public static function ensureVcPdChaseRule(): array
    {
        return self::ensureStatusChaseRule(
            'mas_lifecycle_vc_pd_chase',
            'mas: Lifecycle project-definition chase (VC)',
            'Project enters Awaiting VC Project Definition; the VC is chased in auto-mode (sent immediately) at 30/90/150 days unless the case has left the status (conditions re-checked with fresh data at each delayed firing).',
            'Awaiting VC Project Definition',
            'mas_lifecycle_pd_chase__vc',
            'coordinator'
        );
    }

    /**
     * Client Project Definition authorization chase: project enters
     * "Awaiting Client Project Definition"; the client chased in auto
     * mode at 30/90/150 days until they authorize.
     */
    public static function ensureClientPdChaseRule(): array
    {
        return self::ensureStatusChaseRule(
            'mas_lifecycle_client_pd_chase',
            'mas: Lifecycle project-definition chase (client)',
            'Project enters Awaiting Client Project Definition; the client is chased in auto-mode (sent immediately) at 30/90/150 days unless the case has left the status (conditions re-checked with fresh data at each delayed firing).',
            'Awaiting Client Project Definition',
            'mas_lifecycle_pd_chase__client',
            'client_rep'
        );
    }

    /**
     * Send the client PD authorization email when the VC submits the Project
     * Definition form: a "Project Definition" activity is added to a project
     * case → the authorization email (with the VC's answers rendered inline
     * from the case's Project_Definition group) goes out immediately (auto
     * mode). Sending advances the case to "Awaiting Client Project
     * Definition" via ProjectLifecycleStatusSubscriber.
     *
     * Afform saves Case1 before Activity1 (Activity1's case_id references it),
     * so the definition values are committed by the time this rule fires —
     * LifecycleMailer's final placeholder pass resolves them.
     */
    public static function ensureClientPdProposeRule(): array
    {
        $existing = \CRM_Core_DAO::singleValueQuery(
            "SELECT id FROM civirule_rule WHERE name = 'mas_lifecycle_pd_client_propose'"
        );
        if ($existing) {
            return ['already_exists' => (int) $existing];
        }

        $triggerId = self::requireId("SELECT id FROM civirule_trigger WHERE name = 'added_case_activity'", 'trigger added_case_activity');
        $actionId = self::requireId("SELECT id FROM civirule_action WHERE name = 'mas_lifecycle_email'", 'action mas_lifecycle_email');
        $caseTypeCondId = self::requireId("SELECT id FROM civirule_condition WHERE name = 'case_type'", 'condition case_type');
        $activityTypeCondId = self::requireId("SELECT id FROM civirule_condition WHERE name = 'activity_of_type'", 'condition activity_of_type');

        $pdTypeValue = (int) \Civi\Api4\OptionValue::get(false)
            ->addWhere('option_group_id:name', '=', 'activity_type')
            ->addWhere('name', '=', 'Project Definition')
            ->execute()->first()['value'];

        $rule = \CRM_Civirules_BAO_CiviRulesRule::writeRecord([
            'name' => 'mas_lifecycle_pd_client_propose',
            'label' => 'mas: Propose client PD authorization on VC definition',
            'trigger_id' => $triggerId,
            'is_active' => 1,
            'description' => 'VC Project Definition received on a project; the client authorization email (with the definition rendered inline) is sent immediately (auto mode). Sending advances the case to Awaiting Client Project Definition.',
        ]);
        $ruleId = (int) $rule->id;

        $condRows = self::writeConditions($ruleId, [
            [$caseTypeCondId, serialize(['operator' => 0, 'case_type_id' => [self::projectCaseTypeId()]]), null],
            [$activityTypeCondId, serialize(['operator' => 0, 'activity_type_id' => [$pdTypeValue]]), 'AND'],
        ]);

        $row = \CRM_Civirules_BAO_CiviRulesRuleAction::writeRecord([
            'rule_id' => $ruleId,
            'action_id' => $actionId,
            'action_params' => serialize([
                'template' => 'mas_lifecycle_pd_authorize__client',
                'recipient' => 'client_rep',
                'mode' => 'auto',
            ]),
            'ignore_condition_with_delay' => 0,
            'is_active' => 1,
        ]);

        return [
            'rule_id' => $ruleId,
            'condition_rows' => $condRows,
            'action_rows' => [(int) $row->id],
        ];
    }

    /**
     * Flip every existing mas_lifecycle_email rule_action to a given mode.
     *
     * The ensure*() methods above short-circuit on rules that already exist,
     * so changing their 'mode' literal only affects fresh provisioning —
     * environments with the rules already built need this migration. Rewrites
     * the serialized action_params in place (mode absent counts as 'propose',
     * matching the runtime default) and refreshes rule descriptions that still
     * advertise propose-mode.
     *
     * Idempotent: rows already at the target mode are left untouched.
     *
     * @param string $mode 'propose' | 'auto'
     * @return array{mode:string, updated_action_rows:int[], skipped:int, descriptions_updated:int[]}
     */
    public static function setLifecycleEmailMode(string $mode): array
    {
        if (!in_array($mode, ['propose', 'auto'], true)) {
            throw new \InvalidArgumentException("Mode must be 'propose' or 'auto', got '{$mode}'");
        }

        $actionId = self::requireId(
            "SELECT id FROM civirule_action WHERE name = 'mas_lifecycle_email'",
            'action mas_lifecycle_email'
        );

        $updated = [];
        $skipped = 0;
        $dao = \CRM_Core_DAO::executeQuery(
            "SELECT id, action_params FROM civirule_rule_action WHERE action_id = %1",
            [1 => [$actionId, 'Integer']]
        );
        while ($dao->fetch()) {
            $params = unserialize((string) $dao->action_params);
            if (!is_array($params)) {
                \Civi::log()->warning('LifecycleRuleProvisioner - Unreadable action_params, skipping', [
                    'rule_action_id' => (int) $dao->id,
                ]);
                continue;
            }
            // An absent mode runs as 'propose' (LifecycleMailer's default), so
            // it still needs rewriting when the target is 'auto'.
            if (($params['mode'] ?? 'propose') === $mode) {
                $skipped++;
                continue;
            }
            $params['mode'] = $mode;
            \CRM_Core_DAO::executeQuery(
                "UPDATE civirule_rule_action SET action_params = %1 WHERE id = %2",
                [1 => [serialize($params), 'String'], 2 => [(int) $dao->id, 'Integer']]
            );
            $updated[] = (int) $dao->id;
        }

        return [
            'mode' => $mode,
            'updated_action_rows' => $updated,
            'skipped' => $skipped,
            'descriptions_updated' => self::refreshModeInDescriptions($mode, $actionId),
        ];
    }

    /**
     * Keep the CiviRules UI honest: rule descriptions name the mode, so a
     * mode switch has to rewrite them or the UI describes the old behaviour.
     */
    /**
     * propose-phrasing => auto-phrasing. Two shapes exist: the delayed chase
     * rules say "chased in propose-mode", the immediate-trigger rules describe
     * the click-send step. Both have to flip or the CiviRules UI lies.
     */
    private const MODE_PHRASES = [
        'propose-mode' => 'auto-mode (sent immediately)',
        'is drafted in propose mode. Click-sending the draft advances'
            => 'is sent immediately (auto mode). Sending advances',
    ];

    private static function refreshModeInDescriptions(string $mode, int $actionId): array
    {
        $phrases = $mode === 'auto'
            ? self::MODE_PHRASES
            : array_flip(self::MODE_PHRASES);

        $dao = \CRM_Core_DAO::executeQuery(
            "SELECT DISTINCT r.id, r.description
               FROM civirule_rule r
               JOIN civirule_rule_action ra ON ra.rule_id = r.id
              WHERE ra.action_id = %1",
            [1 => [$actionId, 'Integer']]
        );
        $rows = [];
        while ($dao->fetch()) {
            $rows[(int) $dao->id] = (string) $dao->description;
        }

        $updated = [];
        foreach ($rows as $ruleId => $description) {
            $new = str_replace(array_keys($phrases), array_values($phrases), $description);
            if ($new === $description) {
                continue;
            }
            \CRM_Core_DAO::executeQuery(
                "UPDATE civirule_rule SET description = %1 WHERE id = %2",
                [1 => [$new, 'String'], 2 => [$ruleId, 'Integer']]
            );
            $updated[] = $ruleId;
        }
        return $updated;
    }

    /** CiviRules' delayed-action queue. */
    private const DELAY_QUEUE = 'org.civicoop.civirules.action';

    /**
     * Rewrite the mode inside ALREADY-QUEUED delayed lifecycle emails.
     *
     * setLifecycleEmailMode() fixes civirule_rule_action — the live config —
     * but CiviRules snapshots the whole rule_action row (action_params
     * included) into the queue item at the moment a delayed action is
     * SCHEDULED, and executes that snapshot months later. So a mode flip
     * leaves every already-queued chase running under the old mode: on prod,
     * the 2026-08-20 propose->auto switch still had 294 queued items drafting
     * for review, releasing 2026-08-28 through 2027-01-16.
     *
     * LifecycleEmail::processAction() now re-reads the live mode at execution
     * time, which fixes this going forward. This method is the one-time
     * cleanup for items queued before that fix shipped; on an environment
     * with both, it is belt and braces and safely a no-op.
     *
     * Unserialize/mutate/re-serialize rather than string surgery on the blob:
     * the queue payload is a serialized CRM_Queue_Task whose argument holds
     * the rule action in a PROTECTED property, and byte-level edits to a
     * serialized graph are exactly the kind of thing that silently corrupts
     * a queue you cannot easily rebuild.
     *
     * @param string $mode 'propose' | 'auto'
     * @param bool $apply FALSE reports what would change without writing.
     * @return array{mode:string, apply:bool, queued_total:int, lifecycle:int, rewritten:int[], already:int, unreadable:int[]}
     */
    public static function rewriteQueuedLifecycleEmailMode(string $mode, bool $apply = true): array
    {
        if (!in_array($mode, ['propose', 'auto'], true)) {
            throw new \InvalidArgumentException("Mode must be 'propose' or 'auto', got '{$mode}'");
        }

        $actionId = self::requireId(
            "SELECT id FROM civirule_action WHERE name = 'mas_lifecycle_email'",
            'action mas_lifecycle_email'
        );

        $rewritten = [];
        $unreadable = [];
        $already = 0;
        $lifecycle = 0;
        $total = 0;

        $dao = \CRM_Core_DAO::executeQuery(
            "SELECT id, CAST(data AS CHAR) AS payload FROM civicrm_queue_item WHERE queue_name = %1",
            [1 => [self::DELAY_QUEUE, 'String']]
        );
        while ($dao->fetch()) {
            $total++;
            $itemId = (int) $dao->id;

            // A queue payload is version-fragile: an item written by a
            // CiviRules version we can no longer unserialize must be counted
            // and skipped, never guessed at.
            $found = self::queuedRuleAction((string) $dao->payload, $actionId);
            if ($found['status'] === 'unreadable') {
                $unreadable[] = $itemId;
                continue;
            }
            if ($found['status'] !== 'ok') {
                // Some other extension's delayed action sharing the queue.
                continue;
            }
            $lifecycle++;
            $task = $found['task'];
            $prop = $found['prop'];
            $ruleAction = $found['rule_action'];

            $raw = $ruleAction['action_params'] ?? null;
            $params = self::decodeActionParams($raw);
            if ($params === null) {
                $unreadable[] = $itemId;
                continue;
            }
            // Absent mode runs as 'propose' (LifecycleMailer's default), so it
            // still needs rewriting when the target is 'auto'.
            if (($params['mode'] ?? 'propose') === $mode) {
                $already++;
                continue;
            }

            $params['mode'] = $mode;
            $ruleAction['action_params'] = self::encodeActionParams($raw, $params);
            $prop->setValue($found['engine'], $ruleAction);
            $rewritten[] = $itemId;

            if ($apply) {
                \CRM_Core_DAO::executeQuery(
                    "UPDATE civicrm_queue_item SET data = %1 WHERE id = %2",
                    [1 => [serialize($task), 'String'], 2 => [$itemId, 'Integer']]
                );
            }
        }

        if ($unreadable) {
            \Civi::log()->warning('LifecycleRuleProvisioner - Unreadable delayed-action queue items, left untouched', [
                'queue_item_ids' => $unreadable,
            ]);
        }

        return [
            'mode' => $mode,
            'apply' => $apply,
            'queued_total' => $total,
            'lifecycle' => $lifecycle,
            'rewritten' => $rewritten,
            'already' => $already,
            'unreadable' => $unreadable,
        ];
    }

    /**
     * Read a queued rule action's params, whichever shape they arrive in.
     *
     * civirule_rule_action.action_params is a serialized string in the DB, and
     * CiviRules copies the row into the queue payload verbatim — so inside a
     * queued CRM_Queue_Task the params are a serialized string nested in an
     * already-serialized graph. Dev and prod both store it that way (checked
     * 2026-08-27). An array is accepted too: the shape is CiviRules' to change,
     * and guessing wrong here silently mis-sends mail.
     *
     * @param mixed $raw
     * @return array|null NULL when the params cannot be read at all.
     */
    private static function decodeActionParams($raw): ?array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = @unserialize($raw);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Put params back in the shape they were read in, so the rewritten queue
     * item stays byte-compatible with what CiviRules expects to find.
     *
     * @param mixed $raw The original value, used only for its shape.
     * @return array|string
     */
    private static function encodeActionParams($raw, array $params)
    {
        return is_array($raw) ? $params : serialize($params);
    }

    /**
     * Summarise the lifecycle emails sitting in the delayed-action queue:
     * what mode each will run under, and when it releases.
     *
     * Shares the queue walk with rewriteQueuedLifecycleEmailMode() so the
     * verification output cannot disagree with what the rewrite actually did.
     *
     * @return array<string, array{mode:string, template:string, count:int, first_release:string, last_release:string}>
     */
    public static function describeQueuedLifecycleEmails(): array
    {
        $actionId = self::requireId(
            "SELECT id FROM civirule_action WHERE name = 'mas_lifecycle_email'",
            'action mas_lifecycle_email'
        );

        $summary = [];
        $dao = \CRM_Core_DAO::executeQuery(
            "SELECT id, release_time, CAST(data AS CHAR) AS payload
               FROM civicrm_queue_item WHERE queue_name = %1 ORDER BY release_time",
            [1 => [self::DELAY_QUEUE, 'String']]
        );
        while ($dao->fetch()) {
            $found = self::queuedRuleAction((string) $dao->payload, $actionId);
            if ($found['status'] !== 'ok') {
                continue;
            }
            $params = self::decodeActionParams($found['rule_action']['action_params'] ?? null) ?? [];
            $mode = $params['mode'] ?? 'propose (default)';
            $template = $params['template'] ?? '(unreadable)';
            $key = $mode . '|' . $template;
            $release = substr((string) $dao->release_time, 0, 10);
            if (!isset($summary[$key])) {
                $summary[$key] = [
                    'mode' => $mode, 'template' => $template, 'count' => 0,
                    'first_release' => $release, 'last_release' => $release,
                ];
            }
            $summary[$key]['count']++;
            $summary[$key]['last_release'] = $release;
        }
        ksort($summary);
        return $summary;
    }

    /**
     * Unwrap a queued CRM_Queue_Task down to its rule action row.
     *
     * Returns the outcome alongside the row, because the two ways of finding
     * nothing mean different things: 'foreign' is another extension sharing
     * the queue and is expected, while 'unreadable' is a payload we could not
     * parse and must be surfaced rather than counted as a clean skip.
     *
     * The $task is returned too — the rewrite mutates the engine in place and
     * re-serializes that same object graph.
     *
     * @return array{status:string, rule_action:?array, task:mixed, engine:mixed, prop:?\ReflectionProperty}
     */
    private static function queuedRuleAction(string $payload, int $actionId): array
    {
        $miss = ['status' => 'unreadable', 'rule_action' => NULL, 'task' => NULL, 'engine' => NULL, 'prop' => NULL];

        $task = @unserialize($payload);
        $engine = is_object($task) ? ($task->arguments[0] ?? null) : null;
        if (!is_object($engine)) {
            return $miss;
        }
        try {
            $prop = (new \ReflectionObject($engine))->getProperty('ruleAction');
        } catch (\ReflectionException $e) {
            return $miss;
        }
        $prop->setAccessible(true);
        $ruleAction = $prop->getValue($engine);
        if (!is_array($ruleAction)) {
            return $miss;
        }
        if ((int) ($ruleAction['action_id'] ?? 0) !== $actionId) {
            return ['status' => 'foreign', 'rule_action' => NULL, 'task' => NULL, 'engine' => NULL, 'prop' => NULL];
        }
        return ['status' => 'ok', 'rule_action' => $ruleAction, 'task' => $task, 'engine' => $engine, 'prop' => $prop];
    }

    // ---------------------------------------------------------------------

    /**
     * Shared builder: changed_case rule chasing a case role with delayed
     * lifecycle emails while the case sits at one status.
     */
    private static function ensureStatusChaseRule(
        string $name,
        string $label,
        string $description,
        string $statusName,
        string $template,
        string $recipient,
        array $delaysDays = [30, 90, 150]
    ): array {
        $existing = \CRM_Core_DAO::singleValueQuery(
            "SELECT id FROM civirule_rule WHERE name = %1",
            [1 => [$name, 'String']]
        );
        if ($existing) {
            return ['already_exists' => (int) $existing];
        }

        $triggerId = self::requireId("SELECT id FROM civirule_trigger WHERE name = 'changed_case'", 'trigger changed_case');
        $actionId = self::requireId("SELECT id FROM civirule_action WHERE name = 'mas_lifecycle_email'", 'action mas_lifecycle_email');
        $condIds = [];
        foreach (['case_type', 'case_status_changed', 'case_status'] as $n) {
            $condIds[$n] = self::requireId("SELECT id FROM civirule_condition WHERE name = '$n'", "condition $n");
        }
        $statusValue = self::caseStatusValue($statusName);

        $rule = \CRM_Civirules_BAO_CiviRulesRule::writeRecord([
            'name' => $name,
            'label' => $label,
            'trigger_id' => $triggerId,
            'is_active' => 1,
            'description' => $description,
        ]);
        $ruleId = (int) $rule->id;

        $condRows = self::writeConditions($ruleId, [
            [$condIds['case_type'], serialize(['operator' => 0, 'case_type_id' => [self::projectCaseTypeId()]]), null],
            [$condIds['case_status_changed'], serialize([
                'original_operator' => '!=', 'original_value' => $statusValue,
                'operator' => '=', 'value' => $statusValue,
            ]), 'AND'],
            [$condIds['case_status'], serialize(['operator' => 0, 'status_id' => [$statusValue]]), 'AND'],
        ]);

        $actionParams = serialize([
            'template' => $template,
            'recipient' => $recipient,
            'mode' => 'auto',
        ]);
        $actionRows = [];
        foreach ($delaysDays as $days) {
            $row = \CRM_Civirules_BAO_CiviRulesRuleAction::writeRecord([
                'rule_id' => $ruleId,
                'action_id' => $actionId,
                'action_params' => $actionParams,
                'delay' => self::serializedXDaysDelay($days),
                'ignore_condition_with_delay' => 0,
                'is_active' => 1,
            ]);
            $actionRows[] = (int) $row->id;
        }

        return [
            'rule_id' => $ruleId,
            'status_value' => $statusValue,
            'condition_rows' => $condRows,
            'action_rows' => $actionRows,
        ];
    }

    private static function writeConditions(int $ruleId, array $conds): array
    {
        $rows = [];
        $weight = 0;
        foreach ($conds as [$conditionId, $params, $link]) {
            $weight++;
            $row = \CRM_Civirules_BAO_CiviRulesRuleCondition::writeRecord([
                'rule_id' => $ruleId,
                'condition_id' => $conditionId,
                'condition_params' => $params,
                'condition_link' => $link,
                'weight' => $weight,
                'is_active' => 1,
            ]);
            $rows[] = (int) $row->id;
        }
        return $rows;
    }

    private static function serializedXDaysDelay(int $days): string
    {
        $delay = new \CRM_Civirules_Delay_XDays();
        $prop = new \ReflectionProperty($delay, 'dayOffset');
        $prop->setAccessible(true);
        $prop->setValue($delay, $days);
        return serialize($delay);
    }

    private static function caseStatusValue(string $name): int
    {
        $value = \Civi\Api4\OptionValue::get(false)
            ->addWhere('option_group_id:name', '=', 'case_status')
            ->addWhere('name', '=', $name)
            ->execute()->first()['value'] ?? null;
        if ($value === null) {
            throw new \RuntimeException("case_status '$name' not found — run managed reconcile (cv flush) first");
        }
        return (int) $value;
    }

    private static function projectCaseTypeId(): int
    {
        return (int) \Civi\Api4\CaseType::get(false)
            ->addWhere('name', '=', 'project')
            ->execute()->first()['id'];
    }

    private static function requireId(string $sql, string $what): int
    {
        $id = (int) \CRM_Core_DAO::singleValueQuery($sql);
        if (!$id) {
            throw new \RuntimeException("LifecycleRuleProvisioner: $what not found");
        }
        return $id;
    }
}
