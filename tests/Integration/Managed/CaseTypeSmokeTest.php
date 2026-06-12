<?php

namespace Civi\Mascode\Test\Integration\Managed;

use Civi\Mascode\Test\TestCase;

/**
 * Smoke test for mascode-managed CaseType definitions.
 *
 * Asserts that mas-lifecycle invariants hold in the live DB after
 * Managed.reconcile runs:
 *   - Both service_request and project CaseTypes load
 *   - Project case type includes the three close-path statuses
 *     (2026-06-12 rework; "Awaiting Close Form" is retired/inactive)
 *   - The propose-mode activity types exist
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

    public function testProjectCaseTypeIncludesClosePathStatuses(): void
    {
        $caseType = \Civi\Api4\CaseType::get(FALSE)
            ->addWhere('name', '=', 'project')
            ->execute()
            ->first();

        $this->assertNotEmpty($caseType, 'project CaseType must exist');
        $this->assertTrue((bool) $caseType['is_active']);

        $statuses = $caseType['definition']['statuses'] ?? [];
        foreach (
            [
                'Awaiting Project Definition',
                'Awaiting VC Project Close Form',
                'Awaiting Client Project Close Form',
            ] as $status
        ) {
            $this->assertContains(
                $status,
                $statuses,
                "Project case type must include \"$status\" (close-path rework 2026-06-12)"
            );
        }
        $this->assertNotContains(
            'Awaiting Close Form',
            $statuses,
            'Retired "Awaiting Close Form" must no longer be a project status'
        );
        $this->assertContains('Active', $statuses);
        $this->assertContains('Completed', $statuses);
        $this->assertContains('On Hold', $statuses);
    }

    public function testClosePathStatusOptionValues(): void
    {
        foreach (
            [
                'Awaiting Project Definition' => 20,
                'Awaiting VC Project Close Form' => 21,
                'Awaiting Client Project Close Form' => 22,
            ] as $name => $value
        ) {
            $optionValue = \Civi\Api4\OptionValue::get(FALSE)
                ->addWhere('option_group_id:name', '=', 'case_status')
                ->addWhere('name', '=', $name)
                ->execute()
                ->first();

            $this->assertNotEmpty($optionValue, "case_status '$name' must exist");
            $this->assertEquals($value, $optionValue['value']);
            $this->assertEquals('Opened', $optionValue['grouping']);
            $this->assertTrue((bool) $optionValue['is_active']);
        }

        // The retired status stays present (historical references) but inactive.
        $retired = \Civi\Api4\OptionValue::get(FALSE)
            ->addWhere('option_group_id:name', '=', 'case_status')
            ->addWhere('name', '=', 'Awaiting Close Form')
            ->execute()
            ->first();
        $this->assertNotEmpty($retired);
        $this->assertFalse((bool) $retired['is_active'], 'Awaiting Close Form must be deactivated');
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
            'OptionValue_case_status_Awaiting_Project_Definition',
            'OptionValue_case_status_Awaiting_VC_Project_Close_Form',
            'OptionValue_case_status_Awaiting_Client_Project_Close_Form',
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
