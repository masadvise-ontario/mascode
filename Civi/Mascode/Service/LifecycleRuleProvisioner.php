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
     * Report the lifecycle emails sitting in the delayed-action queue: what
     * mode each will run under, and when it releases. READ-ONLY.
     *
     * There is deliberately no write counterpart. An earlier version of this
     * class rewrote the queued mode in place; it was wrong, in a way worth
     * recording so it is not reinvented. A queued CRM_Queue_Task holds TWO
     * copies of the rule action, because RuleActionEngine::__construct()
     * stores it on itself AND passes it to the action object via
     * setRuleActionData(). Arrays serialize by value, so they are independent
     * blobs. Execution reads the SECOND one: execute() calls
     * $this->actionClass->processAction(), and CRM_Civirules_Action
     * ::getActionParameters() reads the action object's copy. A rewrite that
     * reflects on the engine mutates the copy nothing reads — and then reports
     * success from that same copy.
     *
     * It is unnecessary as well as risky: LifecycleEmail::resolveLiveMode()
     * re-reads the mode from civirule_rule_action at execution time, so every
     * queued item already honours the current config whatever mode is baked
     * into it. Nothing needs to touch a queue that cannot be rebuilt.
     *
     * Accordingly this reads the ACTION OBJECT's copy — what will actually
     * run — not the engine's.
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
            "SELECT id, release_time, data FROM civicrm_queue_item WHERE queue_name = %1 ORDER BY release_time",
            [1 => [self::DELAY_QUEUE, 'String']]
        );
        while ($dao->fetch()) {
            $ruleAction = self::queuedRuleAction((string) $dao->data, $actionId);
            if ($ruleAction === null) {
                continue;
            }
            $params = self::decodeActionParams($ruleAction['action_params'] ?? null) ?? [];
            $mode = $params['mode'] ?? 'propose (default)';
            $template = $params['template'] ?? '(none set)';
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
     * Read a queued rule action's params, whichever shape they arrive in.
     *
     * civirule_rule_action.action_params is a serialized string in the DB and
     * CiviRules copies the row into the queue payload verbatim, so inside a
     * queued task the params are a serialized string nested in an
     * already-serialized graph. An array is accepted too — CiviRules' own
     * getActionParameters() handles both, so both shapes are legitimate.
     *
     * Params are pure scalars and arrays, so object instantiation is refused.
     *
     * @param mixed $raw
     * @return array|null null only when the value cannot be parsed; an absent
     *   or empty value is a well-formed "no params" and returns [].
     */
    private static function decodeActionParams($raw): ?array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if ($raw === null || $raw === '') {
            return [];
        }
        if (!is_string($raw)) {
            return null;
        }
        $decoded = @unserialize($raw, ['allowed_classes' => false]);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Unwrap a queued CRM_Queue_Task down to the rule action row that will
     * actually be executed — the ACTION OBJECT's copy, not the engine's.
     * See describeQueuedLifecycleEmails() for why the distinction matters.
     *
     * @return array|null null when the payload is unreadable, or belongs to
     *   another extension's action sharing the queue.
     */
    private static function queuedRuleAction(string $payload, int $actionId): ?array
    {
        $task = @unserialize($payload);
        $engine = is_object($task) ? ($task->arguments[0] ?? null) : null;
        if (!is_object($engine)) {
            return null;
        }
        try {
            $actionProp = (new \ReflectionObject($engine))->getProperty('actionClass');
            $action = $actionProp->getValue($engine);
            if (!is_object($action)) {
                return null;
            }
            $ruleAction = (new \ReflectionObject($action))->getProperty('ruleAction')->getValue($action);
        } catch (\ReflectionException $e) {
            return null;
        }
        if (!is_array($ruleAction) || (int) ($ruleAction['action_id'] ?? 0) !== $actionId) {
            return null;
        }
        return $ruleAction;
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
