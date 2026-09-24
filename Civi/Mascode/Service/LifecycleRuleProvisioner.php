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
 * zero-touch via `cv upgrade:db`) and from thin scripts/create-*.php
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
    /**
     * RCS chase: a Service Request enters "Request RCS"; the client is chased
     * in auto mode at 21/42 days until the RCS form returns (which moves the
     * SR to "RCS Completed" and cancels the pending chases, because the engine
     * re-checks conditions with fresh data at each delayed firing).
     *
     * The only lifecycle chase on the service_request case type rather than
     * project, and the only one whose provisioning used to live solely in a
     * hand-run script (scripts/create-rcs-chase-rule.php) with no ensure*()
     * method and no upgrade_NNNN caller — so it was silently missing wherever
     * that script had not been run. upgrade_5011 now provisions it like every
     * sibling. Keep the phrase "auto-mode (sent immediately)" in the
     * description below — setLifecycleEmailMode() rewrites it by exact-phrase
     * match on each flip (see MODE_PHRASES); neutral wording stops the flip
     * recognising this rule.
     */
    public static function ensureRcsChaseRule(): array
    {
        return self::ensureStatusChaseRule(
            'mas_lifecycle_rcs_chase',
            'mas: Lifecycle RCS chase (client)',
            'SR enters Request RCS; client is chased in auto-mode (sent immediately) at 21/42 days unless the case has left the status (form return moves it to RCS Completed, which cancels pending chases).',
            'Request RCS',
            'mas_lifecycle_rcs_chase__client',
            'client_rep',
            [21, 42],
            self::serviceRequestCaseTypeId()
        );
    }

    /**
     * RCS chase for a Service Request CREATED directly at "Request RCS" — the
     * manual-intake counterpart to ensureRcsChaseRule().
     *
     * WHY A SECOND RULE RATHER THAN A WIDER FIRST ONE
     * The chase has always armed off changed_case + case_status_changed, so it
     * needs a status TRANSITION. Only one of the two intake paths produces one:
     *
     *   - Web form: the request_for_assistance_form FormProcessor creates the
     *     case at case_status 1 ("Open"/"Ongoing"), and sending the ask email
     *     later makes RcsRequestStatusSubscriber advance it — a real
     *     transition. Armed 19 of 19 on production.
     *   - CiviCRM "New Case" UI: CRM_Case_Form_Activity_OpenCase has NO default
     *     status (no case_status option value carries is_default = 1, so the
     *     select renders a "- select Case Status -" placeholder). The
     *     coordinator picks "Request RCS" because that is where the case is
     *     going — they send the ask email in the same sitting. The case is
     *     created AT the arming status, never transitions into it, and armed
     *     0 of 23 on production. Manual is the MAJORITY intake path.
     *
     * So this rule arms on CREATION AT the status, using the mas_new_case
     * trigger that Civi/Mascode/CiviRules/triggers.json already registers (and
     * which, until now, no rule used). It carries case_type + case_status and
     * deliberately NOT case_status_changed: at creation there is no previous
     * value for that condition to compare against.
     *
     * THE TWO RULES CANNOT DOUBLE-ARM ONE ENTRY, and this is measured, not
     * assumed (tests/Live/RcsChaseArmingTest.php, scenarios A4 and B2):
     *   - created AT "Request RCS"  -> this rule fires; there is no transition
     *     for the changed_case rule to match.
     *   - created at "Ongoing"      -> this rule does not match on create, and
     *     does not match when the case later transitions in either, because
     *     mas_new_case only fires on op = create. The changed_case rule arms
     *     that one, exactly as it does today.
     * A later RE-entry (RCS form returns -> "RCS Completed" -> asked again) is
     * a genuine second entry and arms via the changed_case rule; the create
     * event happens once per case, ever, so this rule cannot contribute to a
     * re-entry and cannot be re-armed by a re-sent ask email.
     *
     * HOW MANY TIMES THIS RULE FIRES PER CASE — NOT ONCE
     * The create EVENT happens once, but CRM_CivirulesPostTrigger_Case::
     * triggerTrigger() fires the RULE once for the base event, then once per
     * case client, then once per case role, all from that one event. How many
     * of those exist yet depends on whether a transaction is open:
     *
     *   - API4 CiviCase::create() writes the CaseContact row AFTER the post
     *     hook and holds no transaction, so the trigger runs inline with no
     *     clients visible: 1 firing.
     *   - The CiviCRM "New Case" UI — the path this rule exists for — wraps
     *     postProcess() in a transaction, so the trigger is deferred to
     *     PHASE_POST_COMMIT and by then the client row and the coordinator
     *     role both exist: 3 firings.
     *
     * Measured on the dev clone against rule generate_a_mas_case_code, which
     * sits on this same trigger with only a case_type condition and so samples
     * the multiplicity directly: 384 cases at 1 firing, 223 at 2, 126 at 3, and
     * a tail at 4 and 6. Every recent UI-created service_request shows 3.
     *
     * So expect 3 rule-log rows and 6 queue items per manually-created SR.
     * That is not a defect and not new: the changed_case sibling fires twice
     * per entry for the same reason (once per client, once per role), and the
     * duplicate SEND is collapsed by LifecycleMailer::findDuplicate() — same
     * case + same template within 23 hours — which is why fully-chased cases
     * on production show two "Sent Automated Email" activities and not four.
     * Per-entry idempotency therefore rests on findDuplicate(), exactly as it
     * already does for the transition rule; what is structural here is only
     * that creation cannot recur, so this rule can never stack a SECOND
     * cadence onto a case that is already being chased.
     *
     * WHY NOT MODIFY RULE 9 INSTEAD
     * Because the change would never reach production. ensureStatusChaseRule()
     * short-circuits on "SELECT id FROM civirule_rule WHERE name = ..." and
     * returns already_exists, so an edit to an existing rule's trigger or
     * conditions is silently skipped wherever that rule exists — which is
     * production. upgrade_5011 already hit exactly that and was a no-op for the
     * RCS chase. A NEW name makes the same idempotency key work FOR us: it does
     * not exist on production, so upgrade_5012 creates it.
     *
     * Keep the phrase "auto-mode (sent immediately)" in the description below —
     * setLifecycleEmailMode() rewrites it by exact-phrase match on each flip
     * (see MODE_PHRASES); neutral wording stops the flip recognising this rule
     * and the CiviRules UI then advertises the wrong send mode.
     */
    public static function ensureRcsChaseOnCreateRule(): array
    {
        return self::ensureCreatedAtStatusChaseRule(
            'mas_lifecycle_rcs_chase_on_create',
            'mas: Lifecycle RCS chase (client, opened at status)',
            'SR CREATED directly at Request RCS — manual intake, which produces no status transition for the changed_case chase to see; client is chased in auto-mode (sent immediately) at 21/42 days unless the case has left the status (form return moves it to RCS Completed, which cancels pending chases).',
            'Request RCS',
            'mas_lifecycle_rcs_chase__client',
            'client_rep',
            [21, 42],
            self::serviceRequestCaseTypeId()
        );
    }

    /**
     * Migrate the two auto-send rules off their fossil "_propose" names, which
     * date from when every lifecycle email queued a draft for review. Both have
     * sent immediately since 2026-08-20, so "propose" describes the opposite of
     * what they do. Existing environments carry the old name; a fresh install
     * created the rule with the new name directly (the ensure*() methods now use
     * it), so this is a no-op there.
     *
     * The rule NAME is the idempotency key the ensure*() methods short-circuit
     * on, so this rename MUST reach existing environments as a data migration —
     * changing only the literal would make the provisioner create a SECOND rule
     * beside the first. Called from upgrade_5011, before nothing else depends on
     * it. Guarded so it only renames when the old name exists and the new one
     * does not, which makes it idempotent and safe on a fresh install.
     *
     * Queued delayed actions reference civirule_rule_action.id, not the rule
     * name, so the in-flight chase queue is unaffected by the rename. The rule
     * description is left untouched: MODE_PHRASES keys the mode-flip sync on the
     * description text, and these descriptions already carry the correct phrase.
     */
    public static function renameLegacyProposeRules(): array
    {
        $renames = [
            'mas_lifecycle_vc_close_propose' => [
                'name' => 'mas_lifecycle_vc_close_send',
                'label' => 'mas: Send client close email on VC close report',
            ],
            'mas_lifecycle_pd_client_propose' => [
                'name' => 'mas_lifecycle_pd_client_send',
                'label' => 'mas: Send client PD authorization on VC definition',
            ],
        ];

        $result = [];
        foreach ($renames as $old => $new) {
            $oldId = \CRM_Core_DAO::singleValueQuery(
                "SELECT id FROM civirule_rule WHERE name = %1",
                [1 => [$old, 'String']]
            );
            $newId = \CRM_Core_DAO::singleValueQuery(
                "SELECT id FROM civirule_rule WHERE name = %1",
                [1 => [$new['name'], 'String']]
            );
            if (!$oldId || $newId) {
                // Nothing to migrate: either absent, or already on the new name.
                $result[$old] = $newId ? ['already_renamed' => (int) $newId] : ['absent' => true];
                continue;
            }
            \CRM_Core_DAO::executeQuery(
                "UPDATE civirule_rule SET name = %1, label = %2 WHERE id = %3",
                [
                    1 => [$new['name'], 'String'],
                    2 => [$new['label'], 'String'],
                    3 => [(int) $oldId, 'Integer'],
                ]
            );
            $result[$old] = ['renamed_to' => $new['name'], 'rule_id' => (int) $oldId];
        }
        return $result;
    }

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
    public static function ensureVcCloseSendRule(): array
    {
        // Match the legacy name too: on an install that still holds
        // mas_lifecycle_vc_close_propose (upgrade_5011 not yet applied), this
        // short-circuits rather than creating a duplicate active rule.
        $existing = \CRM_Core_DAO::singleValueQuery(
            "SELECT id FROM civirule_rule WHERE name IN ('mas_lifecycle_vc_close_send', 'mas_lifecycle_vc_close_propose')"
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
            'name' => 'mas_lifecycle_vc_close_send',
            'label' => 'mas: Send client close email on VC close report',
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
                'template' => 'MAS Project Signoff - Client Template',
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
     * Repoint any CiviRules action that still names the client lifecycle
     * template by its retired "Close" title.
     *
     * WHY THIS IS SEPARATE FROM THE TEMPLATE RENAME. The title is stored in
     * TWO unrelated places, and fixing one does not fix the other:
     *
     *   - ProjectLifecycleStatusSubscriber::TRANSITIONS, which is source, and
     *   - civirule_rule_action.action_params, which is SERIALISED DATA in the
     *     database and therefore survives any deploy untouched.
     *
     * The second one is the more damaging of the two. mas_lifecycle_vc_close_send
     * fires when a VC close report arrives and calls LifecycleMailer with this
     * title; loadTemplate() resolves it with `WHERE msg_title = ...` and THROWS
     * \InvalidArgumentException when nothing matches. So a stale title here does
     * not merely fail to advance the case — the client close email is never sent
     * at all. Confirmed present on production and dev, rule 12 / action row 33,
     * both active, 2026-09-17.
     *
     * Idempotent: after the first run nothing matches the old title.
     *
     * @return array<string,mixed> a report of what was changed
     */
    public static function repointClientCloseTemplate(): array
    {
        // Kept as a named wrapper rather than folded into the generic method
        // below. upgrade_5014 calls this and has already run everywhere, so its
        // behaviour is history and must not drift; and the two literals stay
        // visible to testMigrationConstantsNameTheRightTitles, which is the only
        // CI guard over the titles this feature WRITES.
        $oldTitle = 'MAS Project Close - Client Template';
        $newTitle = 'MAS Project Signoff - Client Template';

        return self::repointRuleActionTemplate($oldTitle, $newTitle);
    }

    /**
     * Repoint every CiviRules action naming $oldTitle to $newTitle.
     *
     * Extracted from repointClientCloseTemplate() when the VC template was
     * renamed in P0-2 and needed exactly the same migration. The care taken
     * here is not incidental — see the comments inline; this is the only code
     * in the extension that rewrites serialised data in place.
     *
     * Idempotent: after a run, nothing matches $oldTitle.
     *
     * @return array{updated: array, skipped: array}
     */
    public static function repointRuleActionTemplate(string $oldTitle, string $newTitle): array
    {
        // Narrow with LIKE, then decide on the UNSERIALISED value. A str_replace
        // over the serialised blob would corrupt it: PHP serialisation records a
        // byte length before each string ("s:35:"), so whenever the two titles
        // differ in length — they do in both renames this has been used for — a
        // textual swap leaves a length prefix that no longer matches its
        // payload, unserialize() returns false, and the action's whole
        // parameter set is silently emptied.
        $dao = \CRM_Core_DAO::executeQuery(
            "SELECT ra.id, ra.action_params, r.name AS rule_name
               FROM civirule_rule_action ra
               JOIN civirule_rule r ON r.id = ra.rule_id
              WHERE ra.action_params LIKE %1",
            [1 => ['%' . $oldTitle . '%', 'String']]
        );

        $updated = [];
        $skipped = [];
        while ($dao->fetch()) {
            $params = unserialize($dao->action_params);
            if (!is_array($params)) {
                // Leave it alone and say so. Rewriting a row we cannot parse is
                // how a recoverable problem becomes an unrecoverable one.
                $skipped[] = ['id' => (int) $dao->id, 'rule' => $dao->rule_name, 'reason' => 'action_params did not unserialise'];
                continue;
            }
            if (($params['template'] ?? null) !== $oldTitle) {
                // The title appeared somewhere else in the blob — another key,
                // or a substring. Not ours to rewrite.
                $skipped[] = ['id' => (int) $dao->id, 'rule' => $dao->rule_name, 'reason' => "matched LIKE but 'template' is not the old title"];
                continue;
            }
            // serialize() only round-trips faithfully for scalars here. An
            // object whose class is not loadable comes back as
            // __PHP_Incomplete_Class and re-serialises to a DIFFERENT string,
            // so writing it back would corrupt the row — and passing
            // ['allowed_classes' => false] would cause that rather than prevent
            // it, since it produces incomplete-class objects too. CiviRules
            // action params are flat scalars in practice (row 33 is three
            // strings), so this is a guard against a shape we do not expect
            // rather than one we have seen; it skips instead of risking the row.
            $nonScalar = array_filter($params, fn($v) => $v !== null && !is_scalar($v));
            if ($nonScalar) {
                $skipped[] = [
                    'id' => (int) $dao->id,
                    'rule' => $dao->rule_name,
                    'reason' => 'action_params holds non-scalar value(s) at key(s) ' . implode(', ', array_keys($nonScalar))
                        . ' — re-serialising is not guaranteed to round-trip, so the row was left alone',
                ];
                continue;
            }
            $params['template'] = $newTitle;
            \CRM_Core_DAO::executeQuery(
                "UPDATE civirule_rule_action SET action_params = %1 WHERE id = %2",
                [1 => [serialize($params), 'String'], 2 => [(int) $dao->id, 'Integer']]
            );
            $updated[] = ['id' => (int) $dao->id, 'rule' => $dao->rule_name];
        }

        return ['updated' => $updated, 'skipped' => $skipped];
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
    public static function ensureClientPdSendRule(): array
    {
        // Match the legacy name too: on an install that still holds
        // mas_lifecycle_pd_client_propose (upgrade_5011 not yet applied), this
        // short-circuits rather than creating a duplicate active rule.
        $existing = \CRM_Core_DAO::singleValueQuery(
            "SELECT id FROM civirule_rule WHERE name IN ('mas_lifecycle_pd_client_send', 'mas_lifecycle_pd_client_propose')"
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
            'name' => 'mas_lifecycle_pd_client_send',
            'label' => 'mas: Send client PD authorization on VC definition',
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
     * Tell the client their request has been circulated: a Service Request
     * enters "Sent for Assignment" and the client rep is emailed immediately
     * (auto mode, no delay).
     *
     * Decided by Nina on 2026-09-24. Until then this email was sent by hand —
     * 58 times across 29 days in 2026, with 53 distinct bodies, i.e. edited
     * almost every send. ⚠ Automating it ENDS that editing, so the template
     * body has to stand on its own; that is a content question for Nina, not a
     * wiring one, and it is why the template keeps a `{contact.first_name}`
     * greeting rather than the hard-coded name it used to carry.
     *
     * Immediate rather than delayed, so it is the sibling of
     * ensureClientPdSendRule() rather than of the chase builders. Nothing
     * advances the case off "Sent for Assignment" as a result — unlike the PD
     * and close sends, this template is not a key in
     * ProjectLifecycleStatusSubscriber::TRANSITIONS, and it must not become
     * one: the CSM moves the case on manually when a VC is assigned.
     *
     * Idempotent: returns early if the rule already exists.
     */
    public static function ensureRcsCirculatedRule(): array
    {
        $name = 'mas_lifecycle_rcs_circulated';
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
        $statusValue = self::caseStatusValue('Sent for Assignment');

        $rule = \CRM_Civirules_BAO_CiviRulesRule::writeRecord([
            'name' => $name,
            'label' => 'mas: Tell client the request was circulated',
            'trigger_id' => $triggerId,
            'is_active' => 1,
            'description' => 'Service Request enters Sent for Assignment; the client rep is told the request has been circulated to the VC pool, immediately and in auto mode. Does not change the case status.',
        ]);
        $ruleId = (int) $rule->id;

        // Same three conditions the chase builders use, and for the same
        // reason: case_status_changed pins the transition, case_status is
        // re-evaluated with fresh data if the action is ever delayed.
        $condRows = self::writeConditions($ruleId, [
            [$condIds['case_type'], serialize(['operator' => 0, 'case_type_id' => [self::serviceRequestCaseTypeId()]]), null],
            [$condIds['case_status_changed'], serialize([
                'original_operator' => '!=', 'original_value' => $statusValue,
                'operator' => '=', 'value' => $statusValue,
            ]), 'AND'],
            [$condIds['case_status'], serialize(['operator' => 0, 'status_id' => [$statusValue]]), 'AND'],
        ]);

        $row = \CRM_Civirules_BAO_CiviRulesRuleAction::writeRecord([
            'rule_id' => $ruleId,
            'action_id' => $actionId,
            'action_params' => serialize([
                'template' => 'mas_lifecycle_rcs_circulated__client',
                'recipient' => 'client_rep',
                'mode' => 'auto',
            ]),
            'ignore_condition_with_delay' => 0,
            'is_active' => 1,
        ]);

        return [
            'rule_id' => $ruleId,
            'status_value' => $statusValue,
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
     * @return array{groups: array<string, array{queued_mode:string, live_mode:string,
     *   effective_mode:string, template:string, count:int, first_release:string,
     *   last_release:string}>, unparsed:int}
     */
    public static function describeQueuedLifecycleEmails(): array
    {
        // Read-only inspection must not fatal on an environment where the
        // lifecycle action was never provisioned — report nothing instead.
        $actionId = (int) \CRM_Core_DAO::singleValueQuery(
            "SELECT id FROM civirule_action WHERE name = 'mas_lifecycle_email'"
        );
        if (!$actionId) {
            return ['groups' => [], 'unparsed' => 0];
        }

        // Live mode per rule action, so the report does not have to assume
        // every rule shares one mode. Two rules CAN differ, and this tool is
        // the only visibility into the backlog — an aggregate would quietly
        // mis-describe every row of a mixed queue.
        $liveModes = [];
        $liveDao = \CRM_Core_DAO::executeQuery(
            "SELECT id, action_params FROM civirule_rule_action WHERE action_id = %1",
            [1 => [$actionId, 'Integer']]
        );
        while ($liveDao->fetch()) {
            $liveModes[(int) $liveDao->id] = self::liveModeLabel($liveDao->action_params);
        }

        $summary = [];
        $unparsed = 0;
        $dao = \CRM_Core_DAO::executeQuery(
            "SELECT id, release_time, data FROM civicrm_queue_item WHERE queue_name = %1 ORDER BY release_time",
            [1 => [self::DELAY_QUEUE, 'String']]
        );
        while ($dao->fetch()) {
            $ruleAction = self::queuedRuleAction((string) $dao->data, $actionId, $isOurs);
            if ($ruleAction === null) {
                if ($isOurs !== false) {
                    $unparsed++;
                }
                continue;
            }
            $params = self::decodeActionParams($ruleAction['action_params'] ?? null) ?? [];
            $raId = (int) ($ruleAction['id'] ?? 0);
            // Same non-string hazard the live side is guarded against, on a
            // snapshot of the same column. strict_types means a non-string
            // here is a TypeError into effectiveMode(), fatalling the
            // inspector rather than merely mislabelling a row.
            $rawQueued = $params['mode'] ?? null;
            $queuedMode = is_string($rawQueued) ? $rawQueued : 'propose (default)';
            $liveMode = $liveModes[$raId] ?? '(rule action gone)';
            $effective = self::effectiveMode($queuedMode, $liveMode);
            $key = $queuedMode . '|' . $liveMode . '|' . $effective . '|'
                . ($params['template'] ?? '(none set)');
            $release = substr((string) $dao->release_time, 0, 10);
            if (!isset($summary[$key])) {
                $summary[$key] = [
                    'queued_mode' => $queuedMode,
                    'live_mode' => $liveMode,
                    'effective_mode' => $effective,
                    'template' => $params['template'] ?? '(none set)',
                    'count' => 0,
                    'first_release' => $release, 'last_release' => $release,
                ];
            }
            $summary[$key]['count']++;
            $summary[$key]['last_release'] = $release;
        }
        ksort($summary);
        return ['groups' => $summary, 'unparsed' => $unparsed];
    }

    /**
     * Label the live mode of one rule action row for the report.
     *
     * Preserves ABSENCE rather than defaulting it. Collapsing a missing mode
     * to 'propose' would make it look like a recognised mode to
     * effectiveMode(), so the fallback branch could never fire and the report
     * would claim "drafts" for rows the code will actually SEND. That bug
     * shipped once and was caught in round-4 review.
     *
     * resolveLiveMode() treats all three of these — no params, unparsable
     * params, params without a mode — as "keep the queued mode".
     *
     * @param mixed $raw civirule_rule_action.action_params
     */
    private static function liveModeLabel($raw): string
    {
        if ($raw === null || $raw === '') {
            return '(no params)';
        }
        $params = self::decodeActionParams($raw);
        if (!is_array($params)) {
            return '(unreadable)';
        }
        $mode = $params['mode'] ?? null;
        // A non-string mode is neither usable nor printable, and returning it
        // would TypeError against this method's return type and fatal a
        // read-only inspector. resolveLiveMode() rejects it too.
        return is_string($mode) ? $mode : '(no mode set)';
    }

    /**
     * What a queued item will ACTUALLY send as — mirroring
     * LifecycleEmail::resolveLiveMode() exactly.
     *
     * A recognised live mode wins. Anything else means resolveLiveMode() keeps
     * the queued mode, normalised the way that method normalises its snapshot,
     * and the report marks it so the reader can see a fallback happened.
     *
     * Extracted so it can be exercised directly: an earlier check
     * re-implemented this rule in the verifier and so agreed with itself while
     * both were wrong.
     */
    private static function effectiveMode(string $queuedMode, string $liveMode): string
    {
        if (in_array($liveMode, ['propose', 'auto'], true)) {
            return $liveMode;
        }
        $queuedEffective = in_array($queuedMode, ['propose', 'auto'], true) ? $queuedMode : 'propose';
        return $queuedEffective . ' (fallback)';
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
     * @param bool|null $isOurs Set to false when the payload belongs to
     *   another extension's action sharing the queue — which is expected and
     *   not a parse failure. Lets the caller count only genuine failures.
     * @return array|null null when the payload is unreadable, or belongs to
     *   another extension's action sharing the queue.
     */
    private static function queuedRuleAction(string $payload, int $actionId, ?bool &$isOurs = null): ?array
    {
        $isOurs = null;
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
        if (!is_array($ruleAction)) {
            return null;
        }
        if ((int) ($ruleAction['action_id'] ?? 0) !== $actionId
            && !($action instanceof \Civi\Mascode\CiviRules\Action\LifecycleEmail)
        ) {
            $isOurs = false;
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
        array $delaysDays = [30, 90, 150],
        ?int $caseTypeId = null
    ): array {
        // Chase rules default to the Project case type; the RCS chase passes the
        // Service Request type instead. Every other parameter already varies per
        // rule, so the case type is the last thing this helper hardcoded.
        $caseTypeId = $caseTypeId ?? self::projectCaseTypeId();
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
            [$condIds['case_type'], serialize(['operator' => 0, 'case_type_id' => [$caseTypeId]]), null],
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

    /**
     * Shared builder: mas_new_case rule chasing a case role with delayed
     * lifecycle emails when the case is CREATED already sitting at one status.
     *
     * The transition-based sibling is ensureStatusChaseRule(). The differences
     * are exactly two, and both follow from there being no previous value at
     * creation time: the trigger is mas_new_case (op = create) rather than
     * changed_case, and the condition set omits case_status_changed.
     *
     * Condition ORDER is load-bearing. The condition whose condition_link is
     * NULL must sort FIRST. CRM_Civirules_Engine::areConditionsValid() ignores
     * the first condition's link and switches on it for every later one, so a
     * NULL link arriving second falls to the switch's default: branch, which
     * logs "invalid condition_link operator" and forces the result FALSE — the
     * rule then silently never matches. writeConditions() assigns ascending
     * weights in array order, so the array order below IS the guarantee.
     * (Live example of getting this wrong: on the dev clone
     * mas_lifecycle_vc_close_chase carries its NULL-link case_type at weight
     * 24, after both AND conditions, and is therefore dead there. Production's
     * copy is correctly ordered — checked 2026-09-09.)
     */
    private static function ensureCreatedAtStatusChaseRule(
        string $name,
        string $label,
        string $description,
        string $statusName,
        string $template,
        string $recipient,
        array $delaysDays = [30, 90, 150],
        ?int $caseTypeId = null
    ): array {
        $caseTypeId = $caseTypeId ?? self::projectCaseTypeId();
        $existing = \CRM_Core_DAO::singleValueQuery(
            "SELECT id FROM civirule_rule WHERE name = %1",
            [1 => [$name, 'String']]
        );
        if ($existing) {
            return ['already_exists' => (int) $existing];
        }

        // Registered by Civi/Mascode/CiviRules/triggers.json. That registration
        // does NOT happen on `cv flush`, so on an environment where the trigger
        // row is missing this throws rather than building a rule pointing at
        // nothing — run `cv upgrade:db` (or reinstall the extension) first.
        $triggerId = self::requireId(
            "SELECT id FROM civirule_trigger WHERE name = 'mas_new_case'",
            'trigger mas_new_case'
        );
        $actionId = self::requireId(
            "SELECT id FROM civirule_action WHERE name = 'mas_lifecycle_email'",
            'action mas_lifecycle_email'
        );
        $condIds = [];
        foreach (['case_type', 'case_status'] as $n) {
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
            [$condIds['case_type'], serialize(['operator' => 0, 'case_type_id' => [$caseTypeId]]), null],
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

    private static function serviceRequestCaseTypeId(): int
    {
        return (int) \Civi\Api4\CaseType::get(false)
            ->addWhere('name', '=', 'service_request')
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
