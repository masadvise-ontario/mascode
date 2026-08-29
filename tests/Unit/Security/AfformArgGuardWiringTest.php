<?php

namespace Civi\Mascode\Test\Unit\Security;

use Civi\Mascode\Test\TestCase;

/**
 * A tripwire for the guard that actually enforces task #159.
 *
 * WHAT THIS IS AND IS NOT
 * This is NOT a behavioural test. It cannot be: AfformPublicArgGuardSubscriber
 * extends \Civi\Core\Service\AutoSubscriber and calls \Civi::log(),
 * CRM_Core_Permission and four API4 entities, none of which exist in CI — the
 * pipeline installs Composer packages only, no CiviCRM. Loading the class there
 * is impossible, which is why the enforceable RULES were split out into
 * AfformArgPolicy (unit tested next door, for real) and only the Civi-dependent
 * plumbing was left in the subscriber.
 *
 * The gap that left is worth naming: every assertion in AfformArgPolicyTest
 * passes with the subscriber file deleted. The policy is the rulebook; the
 * subscriber is the thing that consults it. Nothing in CI noticed if the latter
 * disappeared.
 *
 * So this reads the subscriber as TEXT and asserts the load-bearing wiring is
 * still present. It will not catch a subtle logic regression — the `cv scr` and
 * HTTP tests in tests/Security/ do that, against a live site — but it does catch
 * the failure that silently removes the whole control: the file being deleted,
 * renamed, moved out of the auto-registered Event directory, unhooked from
 * civi.api.prepare, or quietly stopped calling the policy.
 *
 * Source-matching is a blunt instrument and normally a smell. It earns its place
 * here only because the alternative is no CI coverage of the control at all.
 *
 * @covers \Civi\Mascode\Event\AfformPublicArgGuardSubscriber
 */
class AfformArgGuardWiringTest extends TestCase
{
    private const SUBSCRIBER = __DIR__
        . '/../../../Civi/Mascode/Event/AfformPublicArgGuardSubscriber.php';

    private function source(): string
    {
        $path = self::SUBSCRIBER;
        $this->assertFileExists(
            $path,
            'The Afform prefill guard is gone. Task #159 (anonymous prefill leaked '
            . 'case and contact data) is reopened unless it moved — and if it moved, '
            . 'it must stay under Civi/Mascode/Event/ to be auto-registered.'
        );
        return (string) file_get_contents($path);
    }

    /**
     * The directory is the registration mechanism: mascode's scan-classes mixin
     * auto-registers AutoSubscriber classes under Civi/Mascode/Event/. A file
     * moved elsewhere still parses, still passes review, and never runs.
     */
    public function testGuardLivesWhereItGetsAutoRegistered(): void
    {
        $this->assertStringContainsString(
            'namespace Civi\Mascode\Event;',
            $this->source(),
            'Guard must stay in the Civi\Mascode\Event namespace to be auto-registered.'
        );
        $this->assertStringContainsString(
            'extends AutoSubscriber',
            $this->source(),
            'Guard must remain an AutoSubscriber or it is never wired up.'
        );
    }

    /**
     * civi.api.prepare is the only event that works. It fires before
     * AbstractProcessor::_run(), which is the last moment args can still be
     * edited; civi.afform.prefill is dispatched per entity AFTER that entity has
     * been loaded, so a guard moved there would run too late to prevent
     * anything while still looking plausible.
     */
    public function testGuardIsHookedToApiPrepare(): void
    {
        $this->assertStringContainsString(
            "'civi.api.prepare'",
            $this->source(),
            'Guard must subscribe to civi.api.prepare — civi.afform.prefill is too late.'
        );
    }

    /**
     * The subscriber must still consult the policy and still act on the answer.
     * A guard that computes a verdict and never calls setArgs() enforces nothing.
     */
    public function testGuardStillConsultsThePolicyAndActsOnIt(): void
    {
        $source = $this->source();

        $this->assertStringContainsString(
            'AfformArgPolicy::isGuardedForm',
            $source,
            'Guard must still ask the policy which forms are ungated.'
        );
        $this->assertStringContainsString(
            'AfformArgPolicy::sanitize',
            $source,
            'Guard must still filter args through the policy.'
        );
        $this->assertStringContainsString(
            'setArgs',
            $source,
            'Guard must still write the filtered args back onto the request.'
        );
    }

    /**
     * The second disclosure: `entity` and `join` load a record from arbitrary
     * caller-supplied field values, so key filtering cannot see them and they
     * have to be blocked wholesale.
     */
    public function testGuardStillBlocksTheRecordFillModes(): void
    {
        $this->assertStringContainsString(
            'AfformArgPolicy::isBlockedFillMode',
            $this->source(),
            'Guard must still block entity/join fill modes on a guarded form.'
        );
    }
}
