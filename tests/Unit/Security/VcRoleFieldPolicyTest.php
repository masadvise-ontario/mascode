<?php

namespace Civi\Mascode\Test\Unit\Security;

use Civi\Mascode\Security\VcRoleFieldPolicy;
use Civi\Mascode\Test\TestCase;

/**
 * The rules of the VC role-field guard: which saves change a volunteer consultant's standing.
 * Pure functions over arrays — no CiviCRM. The live half is tests/Security/VcRoleFieldGuardTest.php.
 *
 * @covers \Civi\Mascode\Security\VcRoleFieldPolicy
 */
class VcRoleFieldPolicyTest extends TestCase
{
    public function testSubTypesAcceptEveryFormCiviCrmPasses(): void
    {
        $this->assertSame(['MAS_Rep'], VcRoleFieldPolicy::subTypes(['MAS_Rep']));
        $this->assertSame(['MAS_Rep'], VcRoleFieldPolicy::subTypes('MAS_Rep'));
        $this->assertSame(['A', 'MAS_Rep'], VcRoleFieldPolicy::subTypes("\x01A\x01MAS_Rep\x01"));
        $this->assertSame(['A', 'MAS_Rep'], VcRoleFieldPolicy::subTypes('A,MAS_Rep'));
        foreach ([null, '', 'null', [], false] as $none) {
            $this->assertSame([], VcRoleFieldPolicy::subTypes($none));
        }
    }

    public function testASubTypeChangeIsOnlyAddingOrRemovingMasRep(): void
    {
        $vc = ['MAS_Rep'];
        $this->assertFalse(VcRoleFieldPolicy::changesVcSubType([], $vc), 'no key = unchanged');
        $this->assertFalse(VcRoleFieldPolicy::changesVcSubType(['contact_sub_type' => ['MAS_Rep']], $vc));
        $this->assertFalse(VcRoleFieldPolicy::changesVcSubType(['contact_sub_type' => "\x01MAS_Rep\x01"], $vc), 'the backend form re-sends it');
        $this->assertFalse(VcRoleFieldPolicy::changesVcSubType(['contact_sub_type' => ['MAS_Rep', 'Other']], $vc), 'another sub-type is not guarded');
        $this->assertTrue(VcRoleFieldPolicy::changesVcSubType(['contact_sub_type' => []], $vc), 'removal');
        $this->assertTrue(VcRoleFieldPolicy::changesVcSubType(['contact_sub_type' => 'null'], $vc), 'removal as "null"');
        $this->assertTrue(VcRoleFieldPolicy::changesVcSubType(['contact_sub_type' => ['Other']], $vc), 'replacement');
        $this->assertTrue(VcRoleFieldPolicy::changesVcSubType(['contact_sub_type' => ['MAS_Rep']], null), 'adding on a new or non-VC contact');
        $this->assertTrue(VcRoleFieldPolicy::changesVcSubType(['contact_sub_type' => 'MAS_Rep'], ['Other']));
        $this->assertFalse(VcRoleFieldPolicy::changesVcSubType(['contact_sub_type' => ''], null));
    }

    public function testOnlyChangedStaffOnlyFieldsAreReported(): void
    {
        $stored = ['VC_Status' => 'Test', 'Admin' => false, 'Board_Member' => null, 'Enrollment_Date' => '2024-03-01', 'End_Date' => null];
        $this->assertSame([], VcRoleFieldPolicy::changedFields([], $stored));
        $this->assertSame([], VcRoleFieldPolicy::changedFields(['Skills' => 'x', 'Share_Email_with_VC_s' => 1], $stored), 'self-service fields are not guarded');
        $this->assertSame([], VcRoleFieldPolicy::changedFields([
            'VC_Status' => 'Test', 'Admin' => '0', 'Board_Member' => '', 'Enrollment_Date' => '20240301000000', 'End_Date' => null,
        ], $stored), 'the same values in form formats are not a change');
        $this->assertSame(['VC_Status'], VcRoleFieldPolicy::changedFields(['VC_Status' => 'Active'], $stored));
        $this->assertSame(['VC_Status'], VcRoleFieldPolicy::changedFields(['VC_Status' => 'test'], $stored), 'case matters (option values are exact)');
        $this->assertSame(['VC_Status'], VcRoleFieldPolicy::changedFields(['VC_Status' => null], $stored), 'clearing is a change');
        $this->assertSame(['Admin'], VcRoleFieldPolicy::changedFields(['Admin' => 1], $stored));
        $this->assertSame(['Board_Member'], VcRoleFieldPolicy::changedFields(['Board_Member' => true], $stored));
        $this->assertSame(['Enrollment_Date'], VcRoleFieldPolicy::changedFields(['Enrollment_Date' => '2024-03-02'], $stored));
        $this->assertSame(['End_Date'], VcRoleFieldPolicy::changedFields(['End_Date' => '2026-09-30'], $stored));
        $this->assertSame(['VC_Status'], VcRoleFieldPolicy::changedFields(['VC_Status' => 'Active'], []), 'a new contact: anything set is a change');
    }

    public function testNormaliseFailsClosedOnOddFormats(): void
    {
        $this->assertSame('1', VcRoleFieldPolicy::normalise('Boolean', true));
        $this->assertSame('0', VcRoleFieldPolicy::normalise('Boolean', '0'));
        $this->assertSame('0', VcRoleFieldPolicy::normalise('Boolean', false), 'APIv4 returns a stored No as FALSE');
        $this->assertNull(VcRoleFieldPolicy::normalise('Boolean', null), 'never set is distinct from No');
        $this->assertNull(VcRoleFieldPolicy::normalise('Boolean', ''));
        $this->assertSame('maybe', VcRoleFieldPolicy::normalise('Boolean', 'maybe'), 'unrecognised stays distinct, so it reads as a change');
        $this->assertSame('2024-03-01', VcRoleFieldPolicy::normalise('Date', '2024-03-01 00:00:00'));
        $this->assertSame('2024-03-01', VcRoleFieldPolicy::normalise('Date', '20240301'));
        $this->assertSame('Test', VcRoleFieldPolicy::normalise('String', ' Test '));
    }

    public function testTheGuardedFieldsAndStaffTestAreTheOnesTheAccessRulesRead(): void
    {
        // The VC directory and the MCP's D30 check read VC_Status and the MAS_Rep sub-type.
        $this->assertArrayHasKey('VC_Status', VcRoleFieldPolicy::STAFF_ONLY_FIELDS);
        $this->assertSame('MAS_Rep', VcRoleFieldPolicy::SUB_TYPE);
        $this->assertSame(['view all contacts', 'edit all contacts', 'administer CiviCRM'], VcRoleFieldPolicy::STAFF_PERMISSIONS);
        foreach (['Skills', 'Areas_of_Expertise', 'Primary_Area_of_Expertise', 'Share_Email_with_VC_s'] as $selfService) {
            $this->assertArrayNotHasKey($selfService, VcRoleFieldPolicy::STAFF_ONLY_FIELDS, "$selfService is the VC's own to edit");
        }
    }
}
