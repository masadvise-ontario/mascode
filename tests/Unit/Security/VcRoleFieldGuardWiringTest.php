<?php

namespace Civi\Mascode\Test\Unit\Security;

use Civi\Mascode\Test\TestCase;

/**
 * A tripwire for the VC role-field guard's WIRING, read as text — the same device, and the same
 * reason, as AfformArgGuardWiringTest: the subscriber extends AutoSubscriber, which CI does not
 * have, so only its presence and hookup can be checked here. Behaviour is proved live by
 * tests/Security/VcRoleFieldGuardTest.php. `@coversNothing`, not `@covers` (see that test's note).
 *
 * @coversNothing
 */
class VcRoleFieldGuardWiringTest extends TestCase
{
    private const SUBSCRIBER = __DIR__ . '/../../../Civi/Mascode/Event/VcRoleFieldGuardSubscriber.php';

    private function source(): string
    {
        $this->assertFileExists(self::SUBSCRIBER, 'The VC role-field guard is gone: a VC can again edit their own VC_Status or sub-type.');
        return (string) file_get_contents(self::SUBSCRIBER);
    }

    public function testGuardIsAnAutoRegisteredSubscriber(): void
    {
        $src = $this->source();
        $this->assertStringContainsString('namespace Civi\Mascode\Event;', $src);
        $this->assertStringContainsString('extends AutoSubscriber', $src);
    }

    public function testGuardListensOnBothRoutes(): void
    {
        $src = $this->source();
        $this->assertStringContainsString("'hook_civicrm_pre' => 'onPre'", $src);
        $this->assertStringContainsString("'hook_civicrm_customPre' => 'onCustomPre'", $src);
        // hook_civicrm_pre is a PreEvent (action / entity), not op / objectName: reading the
        // generic names returns NULL and silently skips every contact save (found in testing).
        $this->assertStringContainsString('onPre(PreEvent $event)', $src);
        $this->assertStringContainsString('$event->entity', $src);
        $this->assertStringContainsString('$event->action', $src);
    }

    public function testGuardConsultsThePolicyAndThrows(): void
    {
        $src = $this->source();
        $this->assertStringContainsString('VcRoleFieldPolicy::changesVcSubType(', $src);
        $this->assertStringContainsString('VcRoleFieldPolicy::changedFields(', $src);
        $this->assertStringContainsString('throw new \CRM_Core_Exception(VcRoleFieldPolicy::MESSAGE)', $src);
    }
}
