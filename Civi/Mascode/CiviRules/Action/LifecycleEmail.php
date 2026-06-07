<?php

// file: Civi/Mascode/CiviRules/Action/LifecycleEmail.php

namespace Civi\Mascode\CiviRules\Action;

use Civi\Mascode\Service\LifecycleMailer;

/**
 * CiviRules action: draft (propose-mode) or send (auto-mode) a lifecycle
 * email for a case. Thin wrapper around LifecycleMailer — see that class
 * for the runtime behaviour.
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

            LifecycleMailer::execute([
                'case_id' => $caseId,
                'template' => $params['template'],
                'recipient_contact_id' => $recipientId,
                'source_contact_id' => $params['source_contact_id'] ?? null,
                'mode' => $params['mode'] ?? 'propose',
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
