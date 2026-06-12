<?php

// file: Civi/Mascode/CiviRules/Action/GenerateMasCode.php

namespace Civi\Mascode\CiviRules\Action;

use Civi\Mascode\Util\CodeGenerator;

class GenerateMasCode extends \CRM_Civirules_Action
{
    /** Case-type name => custom field holding the MAS code */
    private const CODE_FIELDS = [
        'service_request' => 'Cases_SR_Projects_.MAS_SR_Case_Code',
        'project' => 'Projects.MAS_Project_Case_Code',
    ];

    /** Matches an existing "R26012: " / "P26123: " subject prefix */
    public const CODE_PREFIX_PATTERN = '/^[RP]\d{5}:\s*/';

    /**
     * Method to execute the action
     *
     * @param \CRM_Civirules_TriggerData_TriggerData $triggerData
     * @access public
     */
    public function processAction(\CRM_Civirules_TriggerData_TriggerData $triggerData)
    {
        $case = $triggerData->getEntityData('Case');

        $caseId = $case['id'];
        $caseTypeId = $case['case_type_id'];

        // Get case type
        $caseType = \Civi\Api4\CaseType::get(false)
            ->addSelect('name')
            ->addWhere('id', '=', $caseTypeId)
            ->execute()
            ->first()['name'] ?? null;

        $codeField = self::CODE_FIELDS[$caseType] ?? null;
        if (!$codeField) {
            return;
        }

        $row = \Civi\Api4\CiviCase::get(false)
            ->addSelect($codeField, 'subject')
            ->addWhere('id', '=', $caseId)
            ->execute()
            ->first();

        // If the MAS code is already set, this action has run before
        if (!empty($row[$codeField])) {
            return;
        }

        $masCode = CodeGenerator::generate($caseType);

        $update = \Civi\Api4\CiviCase::update(false)
            ->addValue($codeField, $masCode)
            ->addWhere('id', '=', $caseId);

        // The MAS code leads the subject: "R26012: Strategic Plan"
        $subject = (string) ($row['subject'] ?? '');
        if (!preg_match(self::CODE_PREFIX_PATTERN, $subject)) {
            $update->addValue('subject', $masCode . ': ' . $subject);
        }

        $update->execute();
    }

    /**
     * Method to return extra form elements for action
     *
     * @param int $ruleActionId
     * @return bool
     * @access public
     */
    public function getExtraDataInputUrl($ruleActionId)
    {
        return false;
    }
}
