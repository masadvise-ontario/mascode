<?php

// file: Civi/Mascode/CiviRules/Action/LifecycleEmail.php

namespace Civi\Mascode\CiviRules\Action;

use Civi\Mascode\Service\LifecycleMailer;

/**
 * CiviRules action: draft or send a lifecycle email for a case. Thin wrapper
 * around LifecycleMailer — see that class for the runtime behaviour.
 *
 * The send mode is re-read from the rule action row at execution time rather
 * than taken from the action params handed in, because a delayed action's
 * params are a snapshot taken when it was queued. See resolveLiveMode().
 *
 * Action params (set programmatically on the rule_action row for now —
 * a config form is a follow-up; per GenerateMasCode precedent forms have
 * been painful):
 *   - template (int id or string msg_title, required)
 *   - recipient ('client_rep' | 'coordinator' | int contact id, required)
 *   - mode ('propose' | 'auto', default 'propose')
 *   - source_contact_id (int, optional — defaults to mascode_admin_contact_id)
 *
 * Recipient roles resolve from the case's active case-role relationships:
 *   client_rep  → 'Case Client Rep is'  (type 17, contact_id_a)
 *   coordinator → 'Case Coordinator is' (type 9,  contact_id_a — the assigned VC)
 */
class LifecycleEmail extends \CRM_Civirules_Action
{
    private const ROLE_MAP = [
        'client_rep' => 'Case Client Rep is',
        'coordinator' => 'Case Coordinator is',
    ];

    /**
     * @param \CRM_Civirules_TriggerData_TriggerData $triggerData
     */
    public function processAction(\CRM_Civirules_TriggerData_TriggerData $triggerData)
    {
        try {
            $case = $triggerData->getEntityData('Case');
            $caseId = (int) ($case['id'] ?? 0);
            if (!$caseId) {
                \Civi::log()->warning('LifecycleEmail.php - No case in trigger data, skipping');
                return;
            }

            $params = $this->getActionParameters() ?: [];
            if (empty($params['template']) || empty($params['recipient'])) {
                \Civi::log()->error('LifecycleEmail.php - Action params missing template/recipient', [
                    'rule_action_id' => $this->ruleAction['id'] ?? null,
                    'params' => $params,
                ]);
                return;
            }

            $recipientId = $this->resolveRecipient($caseId, $params['recipient']);
            if (!$recipientId) {
                \Civi::log()->warning('LifecycleEmail.php - No recipient resolved for case, skipping', [
                    'case_id' => $caseId,
                    'recipient' => $params['recipient'],
                ]);
                return;
            }

            // Activity-based triggers (added_case_activity) carry the
            // triggering activity — pass it through so templates can render
            // its custom fields (e.g. the PD authorization email embeds the
            // VC's Project Definition answers).
            $activity = $triggerData->getEntityData('Activity');

            LifecycleMailer::execute([
                'case_id' => $caseId,
                'template' => $params['template'],
                'recipient_contact_id' => $recipientId,
                'source_contact_id' => $params['source_contact_id'] ?? null,
                'mode' => $this->resolveLiveMode($params),
                'activity_id' => $activity['id'] ?? null,
            ]);
        } catch (\Throwable $e) {
            // CiviRules actions must not fatal the triggering transaction.
            \Civi::log()->error('LifecycleEmail.php - Failed: ' . $e->getMessage(), [
                'case_id' => $caseId ?? null,
                'exception' => $e,
            ]);
        }
    }

    /**
     * Resolve the send mode from the rule action row as it stands NOW.
     *
     * $params comes from getActionParameters(). For an immediate action that
     * is the live row, but for a DELAYED action CiviRules serialized the whole
     * rule_action — action_params included — into the queue when the action
     * was scheduled, and hands that snapshot back at execution time, possibly
     * months later. Trusting it means a mode change does not take effect until
     * the queue has fully drained: the 2026-08-20 propose->auto switch left 294
     * queued chases still drafting, the last of them releasing 2027-01-16.
     *
     * Re-reading civirule_rule_action keeps a delayed action honest to the
     * current config, in both directions. Falls back to the snapshot when the
     * row is unreadable or gone (a deleted rule action still has queued items),
     * because a stale mode beats no email at all.
     */
    private function resolveLiveMode(array $params): string
    {
        // Normalise the snapshot once: every fallback path below returns it,
        // and an unrecognised value would TypeError against the return type
        // and swallow the email into processAction()'s catch.
        $snapshot = $params['mode'] ?? 'propose';
        if (!is_string($snapshot) || !in_array($snapshot, ['propose', 'auto'], true)) {
            $snapshot = 'propose';
        }
        $ruleActionId = (int) ($this->ruleAction['id'] ?? 0);
        if (!$ruleActionId) {
            return $snapshot;
        }

        // Constrain to OUR action. The id can be months old by the time a
        // delayed action runs; if that row was deleted and its id reused, an
        // unconstrained lookup would read a foreign action's params.
        $stored = \CRM_Core_DAO::singleValueQuery(
            'SELECT ra.action_params FROM civirule_rule_action ra
               JOIN civirule_action a ON a.id = ra.action_id
              WHERE ra.id = %1 AND a.name = %2',
            [1 => [$ruleActionId, 'Integer'], 2 => ['mas_lifecycle_email', 'String']]
        );
        if ($stored === null || $stored === '') {
            return $snapshot;
        }
        $live = @unserialize((string) $stored, ['allowed_classes' => false]);
        if (!is_array($live)) {
            \Civi::log()->warning('LifecycleEmail.php - Unreadable action_params, using queued mode', [
                'rule_action_id' => $ruleActionId,
                'mode' => $snapshot,
            ]);
            return $snapshot;
        }

        // An absent mode on the live row must NOT invent 'propose' and thereby
        // downgrade an explicit queued 'auto' — keep the explicit value.
        $mode = $live['mode'] ?? $snapshot;
        // Guard the return type and the contract: anything unrecognised must
        // not reach LifecycleMailer, which sends on 'auto' and drafts on
        // everything else. Fall back rather than let a bad value decide.
        if (!is_string($mode) || !in_array($mode, ['propose', 'auto'], true)) {
            \Civi::log()->warning('LifecycleEmail.php - Unrecognised mode on rule action, using queued mode', [
                'rule_action_id' => $ruleActionId,
                'live_mode' => $mode,
                'mode' => $snapshot,
            ]);
            return $snapshot;
        }
        if ($mode !== $snapshot) {
            \Civi::log()->info('LifecycleEmail.php - Queued mode is stale, using live rule config', [
                'rule_action_id' => $ruleActionId,
                'queued_mode' => $snapshot,
                'live_mode' => $mode,
            ]);
        }
        return $mode;
    }

    /**
     * Resolve the recipient: a case-role keyword or an explicit contact id.
     */
    private function resolveRecipient(int $caseId, $recipient): ?int
    {
        if (is_numeric($recipient)) {
            return (int) $recipient;
        }
        $roleName = self::ROLE_MAP[$recipient] ?? null;
        if (!$roleName) {
            \Civi::log()->error('LifecycleEmail.php - Unknown recipient keyword', ['recipient' => $recipient]);
            return null;
        }
        $rel = \Civi\Api4\Relationship::get(false)
            ->addSelect('contact_id_a')
            ->addWhere('case_id', '=', $caseId)
            ->addWhere('relationship_type_id:name', '=', $roleName)
            ->addWhere('is_active', '=', true)
            ->addOrderBy('id', 'DESC')
            ->setLimit(1)
            ->execute()
            ->first();
        return $rel ? (int) $rel['contact_id_a'] : null;
    }

    /**
     * Config form: template / recipient / mode / source contact / delay days.
     *
     * @param int $ruleActionId
     * @return bool|string
     */
    public function getExtraDataInputUrl($ruleActionId)
    {
        return $this->getFormattedExtraDataInputUrl(
            'civicrm/mascode/civirule/form/action/lifecycleemail',
            (int) $ruleActionId
        );
    }

    /**
     * Show the configured params in the CiviRules UI.
     *
     * @return string
     */
    public function userFriendlyConditionParams()
    {
        $params = $this->getActionParameters() ?: [];
        if (!$params) {
            return '(no parameters set)';
        }
        return sprintf(
            'Template: %s | Recipient: %s | Mode: %s',
            $params['template'] ?? '?',
            $params['recipient'] ?? '?',
            $params['mode'] ?? 'propose'
        );
    }
}
