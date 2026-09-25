<?php

namespace Civi\Mascode\Test\Unit\Util;

use Civi\Mascode\Test\TestCase;
use Civi\Mascode\Util\SystemContext;

/**
 * The scope that makes activities the system's. Getting it wrong in either
 * direction mislabels people's work as the system's, or the reverse.
 *
 * @coversNothing
 */
class SystemContextTest extends TestCase
{
    public function testScopeIsOpenOnlyInsideRun(): void
    {
        $this->assertFalse(SystemContext::isActive());
        $inside = SystemContext::run(fn() => SystemContext::isActive());
        $this->assertTrue($inside);
        $this->assertFalse(SystemContext::isActive());
    }

    public function testInnerScopeDoesNotCloseOuter(): void
    {
        SystemContext::run(function () {
            SystemContext::run(fn() => null);
            $this->assertTrue(SystemContext::isActive(), 'a nested run closed the outer scope');
        });
        $this->assertFalse(SystemContext::isActive());
    }

    /** An exception inside must not leave the scope open for the rest of the request. */
    public function testScopeClosesWhenWorkThrows(): void
    {
        try {
            SystemContext::run(function () {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException $e) {
        }
        $this->assertFalse(SystemContext::isActive());
    }

    /** An unmatched leave must not make a later scope close early. */
    public function testExtraLeaveDoesNotGoNegative(): void
    {
        SystemContext::leave();
        SystemContext::enter();
        $this->assertTrue(SystemContext::isActive());
        SystemContext::leave();
        $this->assertFalse(SystemContext::isActive());
    }

    public function testRunReturnsTheWorkResult(): void
    {
        $this->assertSame(42, SystemContext::run(fn() => 42));
    }
}
