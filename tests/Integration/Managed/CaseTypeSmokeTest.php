<?php

namespace Civi\Mascode\Test\Integration\Managed;

use Civi\Mascode\Test\TestCase;

/**
 * Smoke test for mascode-managed CaseType definitions.
 *
 * Asserts that mas-lifecycle Phase 1 invariants hold in the live DB after
 * Managed.reconcile runs:
 *   - Both service_request and project CaseTypes load
 *   - Project case type includes the "Awaiting Close Form" status
 *   - The 3 new OptionValues (1 status, 2 activity types) exist
 *   - The Estimated Completion Date custom field is wired to Projects group
 *
 * Run before deploying mascode to prod, and after any CiviCRM major upgrade
 * to catch CaseType schema drift.
 *
 * @group case_type
 * @group integration
 */
class CaseTypeSmokeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfNoCiviCRM();
        $this->skipIfNoDatabase();
    }

    public function testServiceRequestCaseTypeLoads(): void
    {
        $caseType = \Civi\Api4\CaseType::get(FALSE)
            ->addWhere('name', '=', 'service_request')
            ->execute()
            ->first();

        $this->assertNotEmpty($caseType, 'service_request CaseType must exist');
        $this->assertTrue((bool) $caseType['is_active']);
        $this->assertEquals('Service Request', $caseType['title']);

        $statuses = $caseType['definition']['statuses'] ?? [];
        $this->assertContains('Request RCS', $statuses);
        $this->assertContains('Sent for Assignment', $statuses);
        $this->assertContains('Project Created', $statuses);
        $this->assertContains('No Client Response', $statuses);
    }

    public function testProjectCaseTypeIncludesAwaitingCloseForm(): void
    {
        $caseType = \Civi\Api4\CaseType::get(FALSE)
            ->addWhere('name', '=', 'project')
            ->execute()
            ->first();

        $this->assertNotEmpty($caseType, 'project CaseType must exist');
        $this->assertTrue((bool) $caseType['is_active']);

        $statuses = $caseType['definition']['statuses'] ?? [];
        $this->assertContains(
            'Awaiting Close Form',
            $statuses,
            'Project case type must include "Awaiting Close Form" status (mas-lifecycle Phase 1)'
        );
        $this->assertContains('Active', $statuses);
        $this->assertContains('Completed', $statuses);
        $this->assertContains('On Hold', $statuses);
    }

    public function testAwaitingCloseFormOptionValueExists(): void
    {
        $optionValue = \Civi\Api4\OptionValue::get(FALSE)
            ->addWhere('option_group_id:name', '=', 'case_status')
            ->addWhere('name', '=', 'Awaiting Close Form')
            ->execute()
            ->first();

        $this->assertNotEmpty($optionValue);
        $this->assertEquals('Opened', $optionValue['grouping']);
        $this->assertTrue((bool) $optionValue['is_active']);
    }

    public function testActivityTypesForProposeModeExist(): void
    {
        $expected = ['Draft Email - Needs Review', 'Sent Automated Email'];
        foreach ($expected as $name) {
            $optionValue = \Civi\Api4\OptionValue::get(FALSE)
                ->addWhere('option_group_id:name', '=', 'activity_type')
                ->addWhere('name', '=', $name)
                ->execute()
                ->first();
            $this->assertNotEmpty(
                $optionValue,
                "Activity type '$name' must exist (mas-lifecycle Phase 1)"
            );
            $this->assertTrue((bool) $optionValue['is_active']);
        }
    }

    public function testEstimatedCompletionDateFieldOnProjects(): void
    {
        $field = \Civi\Api4\CustomField::get(FALSE)
            ->addWhere('name', '=', 'Estimated_Completion_Date')
            ->addWhere('custom_group_id.name', '=', 'Projects')
            ->addSelect('name', 'label', 'data_type', 'html_type', 'is_active', 'custom_group_id.extends')
            ->execute()
            ->first();

        $this->assertNotEmpty($field);
        $this->assertEquals('Date', $field['data_type']);
        $this->assertEquals('Select Date', $field['html_type']);
        $this->assertEquals('Case', $field['custom_group_id.extends']);
        $this->assertTrue((bool) $field['is_active']);
    }

    public function testManagedRecordsTrackPhase1Entities(): void
    {
        $managed = \Civi\Api4\Managed::get(FALSE)
            ->addWhere('module', '=', 'mascode')
            ->addSelect('name', 'entity_type', 'entity_id')
            ->execute()
            ->indexBy('name')
            ->getArrayCopy();

        $expectedNames = [
            'OptionValue_case_status_Awaiting_Close_Form',
            'OptionValue_activity_type_Draft_Email_Needs_Review',
            'OptionValue_activity_type_Sent_Automated_Email',
            'CustomField_Projects_Estimated_Completion_Date',
            'CaseType_service_request',
            'CaseType_project',
        ];
        foreach ($expectedNames as $name) {
            $this->assertArrayHasKey(
                $name,
                $managed,
                "Managed record '$name' must exist after reconcile"
            );
        }
    }
}
