<?php

namespace Civi\Mascode\Test\Unit\CiviRules;

use Civi\Mascode\Test\TestCase;

/**
 * Source tripwire for the EmployerRelationship CiviRules action (handoff #1018).
 *
 * The real proof is tests/Live/EmployerRelationshipTest.php, which CI cannot
 * run. This pins the one thing whose silent return would bring the bug back:
 * the action must never hand control to CRM_CivirulesActions_Generic_Api::
 * processAction(), because that ALWAYS calls the API — so any "skip" expressed
 * as an empty parameter array becomes an api3 Relationship.create with no
 * contact ids, which throws inside the CiviRules hook.
 *
 * @coversNothing
 */
class EmployerRelationshipWiringTest extends TestCase
{
    private const ACTION = '/Civi/Mascode/CiviRules/Action/EmployerRelationship.php';

    private function codeOnly(): string
    {
        $source = file_get_contents(dirname(__DIR__, 3) . self::ACTION);
        $this->assertNotFalse($source, 'action source must be readable');
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }
        return $out;
    }

    public function testNeverDelegatesToTheGenericApiCall(): void
    {
        $code = $this->codeOnly();
        $this->assertStringNotContainsString('parent::processAction', $code);
        $this->assertStringNotContainsString('alterApiParameters', $code);
        $this->assertStringNotContainsString('civicrm_api3', $code);
        $this->assertStringNotContainsString('executeApiAction', $code);
    }

    public function testChecksForAnExistingRelationshipBeforeCreating(): void
    {
        $code = $this->codeOnly();
        $get = strpos($code, 'Relationship::get(');
        $create = strpos($code, 'Relationship::create(');
        $this->assertNotFalse($get, 'must look for an existing relationship');
        $this->assertNotFalse($create, 'must create via API4');
        $this->assertLessThan($create, $get, 'the existence check must come before the create');
    }
}
