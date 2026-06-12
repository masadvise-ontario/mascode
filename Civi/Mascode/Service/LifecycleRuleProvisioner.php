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
     * Form"; client_rep chased in propose mode at 30/90/150 days.
     */
    public static function ensureClientCloseChaseRule(): array
    {
        return self::ensureStatusChaseRule(
            'mas_lifecycle_close_chase',
            'mas: Lifecycle close-form chase (client)',
            'Project enters Awaiting Client Project Close Form; client is chased in propose-mode at 30/90/150 days unless the case has left the status (conditions re-checked with fresh data at each delayed firing).',
            'Awaiting Client Project Close Form',
            'mas_lifecycle_close_chase__client',
            'client_rep'
        );
    }

    /**
     * VC close-report chase: project enters "Awaiting VC Project Close Form";
     * the VC (Case Coordinator) chased in propose mode at 30/90/150 days.
     */
    public static function ensureVcCloseChaseRule(): array
    {
        return self::ensureStatusChaseRule(
            'mas_lifecycle_vc_close_chase',
            'mas: Lifecycle close-report chase (VC)',
            'Project enters Awaiting VC Project Close Form; the VC is chased in propose-mode at 30/90/150 days unless the case has left the status (conditions re-checked with fresh data at each delayed firing).',
            'Awaiting VC Project Close Form',
            'mas_lifecycle_close_chase__vc',
            'coordinator'
        );
    }

    /**
     * Auto-propose the client close email when the VC close report arrives:
     * a "Project Close - VC Report" activity is added to a project case →
     * draft the client close-request email (propose mode) for CSM review.
     * Click-sending that draft flips the case to "Awaiting Client Project
     * Close Form" via ProjectCloseStatusSubscriber.
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
            'description' => 'VC close report received on a project; the client close-request email is drafted in propose mode. Click-sending the draft advances the case to Awaiting Client Project Close Form.',
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
                'mode' => 'propose',
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
                1 => ['Project enters Awaiting Client Project Close Form; client is chased in propose-mode at 30/90/150 days unless the case has left the status (conditions re-checked with fresh data at each delayed firing).', 'String'],
                2 => [$ruleId, 'Integer'],
            ]
        );

        return ['rule_id' => $ruleId, 'updated_condition_rows' => $updated, 'new_status_value' => $newValue];
    }

    // ---------------------------------------------------------------------

    /**
     * Shared builder: changed_case rule chasing a case role with delayed
     * propose-mode lifecycle emails while the case sits at one status.
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
            'mode' => 'propose',
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
