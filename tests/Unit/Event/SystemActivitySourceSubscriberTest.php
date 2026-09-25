<?php

namespace Civi\Mascode\Test\Unit\Event;

use Civi\Mascode\Test\TestCase;
use Civi\Mascode\Util\SystemContext;

/**
 * Which writes the subscriber stamps, and which API calls open a scope.
 *
 * The logic lives in SystemContext, not the subscriber, because the subscriber
 * extends AutoSubscriber and CI has no CiviCRM to load it (see the sibling
 * wiring tests). The subscriber's own wiring is asserted as text below.
 *
 * @coversNothing
 */
class SystemActivitySourceSubscriberTest extends TestCase
{
    private const SUBSCRIBER = __DIR__ . '/../../../Civi/Mascode/Event/SystemActivitySourceSubscriber.php';

    public function testOnlyActivityCreateInsideScopeIsStamped(): void
    {
        $this->assertFalse(SystemContext::shouldStamp('Activity', 'create'), 'stamped outside a scope: a person\'s activity would become the system\'s');
        SystemContext::run(function () {
            $this->assertTrue(SystemContext::shouldStamp('Activity', 'create'));
            $this->assertFalse(SystemContext::shouldStamp('Activity', 'edit'), 'an edit re-attributed (e.g. a draft marked Completed)');
            $this->assertFalse(SystemContext::shouldStamp('Case', 'create'));
        });
    }

    public function testFormProcessorRunIsSystem(): void
    {
        $this->assertTrue(SystemContext::isFormProcessorRun(['entity' => 'FormProcessor', 'action' => 'request_for_assistance_form']));
    }

    public function testFormProcessorConfigEntitiesAreNot(): void
    {
        $this->assertFalse(SystemContext::isFormProcessorRun(['entity' => 'FormProcessorInstance', 'action' => 'create']));
        $this->assertFalse(SystemContext::isFormProcessorRun(['entity' => 'Activity', 'action' => 'create']));
    }

    public function testFormProcessorCallOpensAndClosesItsScope(): void
    {
        $req = ['id' => 9001, 'entity' => 'FormProcessor', 'action' => 'x'];
        SystemContext::openForApiRequest($req);
        $this->assertTrue(SystemContext::isActive());
        // A nested, unrelated API call finishing must not close it.
        SystemContext::closeForApiRequest(['id' => 9002, 'entity' => 'Contact', 'action' => 'get']);
        $this->assertTrue(SystemContext::isActive());
        SystemContext::closeForApiRequest($req);
        $this->assertFalse(SystemContext::isActive());
    }

    public function testRepeatedPrepareOpensOneScope(): void
    {
        $req = ['id' => 9003, 'entity' => 'FormProcessor', 'action' => 'x'];
        SystemContext::openForApiRequest($req);
        SystemContext::openForApiRequest($req);
        SystemContext::closeForApiRequest($req);
        $this->assertFalse(SystemContext::isActive());
    }

    public function testNonFormProcessorCallOpensNothing(): void
    {
        SystemContext::openForApiRequest(['id' => 9004, 'entity' => 'Contact', 'action' => 'create']);
        $this->assertFalse(SystemContext::isActive());
    }

    /** Wiring: both API end events close the scope, and the pre hook delegates. */
    public function testSubscriberWiring(): void
    {
        $src = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', file_get_contents(self::SUBSCRIBER));
        foreach (["'hook_civicrm_pre' => 'onPre'", "'civi.api.prepare' => 'onApiPrepare'", "'civi.api.respond' => 'onApiDone'", "'civi.api.exception' => 'onApiDone'"] as $wire) {
            $this->assertStringContainsString($wire, $src);
        }
        $this->assertStringContainsString('SystemContext::shouldStamp(', $src);
    }
}
