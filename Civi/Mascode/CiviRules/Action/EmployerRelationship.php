<?php

// file: Civi/Mascode/CiviRules/Action/EmployerRelationship.php

namespace Civi\Mascode\CiviRules\Action;

use CRM_Mascode_ExtensionUtil as E;

/**
 * Action to create relationships with the Individual's employer
 *
 * It does its own work in API4 rather than through the parent's generic call.
 * The parent's processAction() always calls the API, whatever alterApiParameters()
 * returns, so the old "return [] to skip" ran api3 Relationship.create with no
 * contact ids. That threw on every edit of someone who ALREADY had the
 * relationship — the common case — and logged it as a rule failure
 * (handoff #1018, CiviRule "Create Executive Director Relationship when
 * Individual is changed"). Skipping is now a plain return before any API call.
 *
 * It still extends the generic API action so existing rule_action rows, the
 * actions.json registration and the extra-data form keep working unchanged.
 */
class EmployerRelationship extends \CRM_CivirulesActions_Generic_Api
{
    /**
     * Runs as the system: every activity created while this action runs — including
     * core's side effects — is recorded against SystemContact (SystemContext).
     */
    public function processAction(\CRM_Civirules_TriggerData_TriggerData $triggerData)
    {
        \Civi\Mascode\Util\SystemContext::run(fn() => $this->processActionAsSystem($triggerData));
    }

    /**
     * Create the relationship if, and only if, there is an employer and no active
     * relationship of this type to it yet. Never calls the parent.
     *
     * @param \CRM_Civirules_TriggerData_TriggerData $triggerData
     */
    private function processActionAsSystem(\CRM_Civirules_TriggerData_TriggerData $triggerData)
    {
        $actionParams = $this->getActionParameters();
        if (empty($actionParams['relationship_type_id'])) {
            throw new \Exception("Relationship type ID not configured");
        }
        $relationshipTypeId = (int) $actionParams['relationship_type_id'];

        $contactId = $triggerData->getContactId();
        if (empty($contactId)) {
            \Civi::log()->info('EmployerRelationship.php - No contact in trigger data, skipping action');
            return;
        }

        $employerId = $this->getEmployerId($contactId);
        if (empty($employerId)) {
            \Civi::log()->info('EmployerRelationship.php - No employer found, skipping action', [
                'contact_id' => $contactId
            ]);
            return;
        }

        $existingRelationship = \Civi\Api4\Relationship::get(false)
            ->addSelect('id')
            ->addWhere('contact_id_a', '=', $contactId)
            ->addWhere('contact_id_b', '=', $employerId)
            ->addWhere('relationship_type_id', '=', $relationshipTypeId)
            ->addWhere('is_active', '=', true)
            ->setLimit(1)
            ->execute()
            ->first();

        if ($existingRelationship) {
            \Civi::log()->info('EmployerRelationship.php - Active relationship already exists, skipping', [
                'relationship_id' => $existingRelationship['id'],
                'contact_id' => $contactId,
                'employer_id' => $employerId,
                'relationship_type_id' => $relationshipTypeId
            ]);
            return;
        }

        \Civi\Api4\Relationship::create(false)
            ->addValue('contact_id_a', $contactId)   // Individual
            ->addValue('contact_id_b', $employerId)  // Employer (Organization)
            ->addValue('relationship_type_id', $relationshipTypeId)
            ->addValue('is_active', true)
            ->addValue('description', 'Created by CiviRules: Individual to Employer relationship')
            ->execute();
    }

    /**
     * Required by the parent; unused because processAction() no longer calls it.
     *
     * @return string
     */
    protected function getApiEntity()
    {
        return 'Relationship';
    }

    /**
     * Required by the parent; unused because processAction() no longer calls it.
     *
     * @return string
     */
    protected function getApiAction()
    {
        return 'create';
    }

    /**
     * Get the employer ID for the given contact
     *
     * @param int $contactId
     * @return int|null
     */
    protected function getEmployerId($contactId)
    {
        try {
            $contact = \Civi\Api4\Contact::get(false)
                ->addSelect('employer_id')
                ->addWhere('id', '=', $contactId)
                ->execute()
                ->first();

            return $contact['employer_id'] ?? null;
        } catch (\Exception $e) {
            \Civi::log()->error('EmployerRelationship.php - Error getting employer ID', [
                'contact_id' => $contactId,
                'message' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Returns a user friendly text explaining the action
     *
     * @return string
     * @access public
     */
    public function userFriendlyConditionParams()
    {
        $params = $this->getActionParameters();
        $relationshipType = 'Unknown';

        if (!empty($params['relationship_type_id'])) {
            try {
                $relType = \Civi\Api4\RelationshipType::get(false)
                    ->addSelect('label_a_b')
                    ->addWhere('id', '=', $params['relationship_type_id'])
                    ->execute()
                    ->first();

                $relationshipType = $relType['label_a_b'] ?? 'Unknown';
            } catch (\Exception $e) {
                // Keep default 'Unknown'
            }
        }

        return E::ts('Create "%1" relationship between Individual and their Employer', [
            1 => $relationshipType
        ]);
    }

    /**
     * Method to return the url for additional form processing
     *
     * @param int $ruleActionId
     * @return string
     * @access public
     */
    public function getExtraDataInputUrl($ruleActionId)
    {
        // Use a simple form that only collects relationship type
        return \CRM_Utils_System::url(
            'civicrm/mascode/civirule/form/action/employerrelationship',
            'rule_action_id=' . $ruleActionId
        );
    }
}
