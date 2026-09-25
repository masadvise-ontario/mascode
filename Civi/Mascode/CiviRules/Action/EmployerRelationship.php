<?php

// file: Civi/Mascode/CiviRules/Action/SimpleEmployerRelationship.php

namespace Civi\Mascode\CiviRules\Action;

use CRM_Mascode_ExtensionUtil as E;

/**
 * Action to create relationships with the Individual's employer
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
     * Override processAction to skip execution when no employer is found
     *
     * @param \CRM_Civirules_TriggerData_TriggerData $triggerData
     */
    private function processActionAsSystem(\CRM_Civirules_TriggerData_TriggerData $triggerData)
    {
        $contactId = $triggerData->getContactId();
        $employerId = $this->getEmployerId($contactId);

        // Skip execution if no employer found
        if (empty($employerId)) {
            \Civi::log()->info('EmployerRelationship.php - No employer found, skipping action', [
                'contact_id' => $contactId
            ]);
            return;
        }

        // Proceed with normal execution
        parent::processAction($triggerData);
    }

    /**
     * Method to set the api entity
     *
     * @return string
     * @access protected
     */
    protected function getApiEntity()
    {
        return 'Relationship';
    }

    /**
     * Method to set the api action
     *
     * @return string
     * @access protected
     */
    protected function getApiAction()
    {
        return 'create';
    }

    /**
     * Returns an array with parameters used for processing an action
     *
     * @param array $params
     * @param \CRM_Civirules_TriggerData_TriggerData $triggerData
     * @return array
     * @access protected
     */
    protected function alterApiParameters($params, \CRM_Civirules_TriggerData_TriggerData $triggerData)
    {
        $contactId = $triggerData->getContactId();
        $actionParams = $this->getActionParameters();

        if (empty($actionParams['relationship_type_id'])) {
            throw new \Exception("Relationship type ID not configured");
        }

        // Get the employer ID (we know it exists because processAction already checked)
        $employerId = $this->getEmployerId($contactId);

        // Check if there is already an active relationship of this type
        $existingRelationship = \Civi\Api4\Relationship::get(false)
            ->addSelect('id', 'is_active')
            ->addWhere('contact_id_a', '=', $contactId)
            ->addWhere('contact_id_b', '=', $employerId)
            ->addWhere('relationship_type_id', '=', $actionParams['relationship_type_id'])
            ->addWhere('is_active', '=', true)
            ->setLimit(1)
            ->execute()
            ->first();

        if ($existingRelationship) {
            \Civi::log()->info('EmployerRelationship.php - Active relationship already exists, skipping', [
                'relationship_id' => $existingRelationship['id'],
                'contact_id' => $contactId,
                'employer_id' => $employerId,
                'relationship_type_id' => $actionParams['relationship_type_id']
            ]);
            // Return empty params to signal CiviRules to skip this action
            return [];
        }

        // Set up the relationship parameters
        $params['contact_id_a'] = $contactId;  // Individual
        $params['contact_id_b'] = $employerId; // Employer (Organization)
        $params['relationship_type_id'] = $actionParams['relationship_type_id'];
        $params['is_active'] = 1;
        $params['description'] = 'Created by CiviRules: Individual to Employer relationship';

        return $params;
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
