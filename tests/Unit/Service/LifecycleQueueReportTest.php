<?php

namespace Civi\Mascode\Test\Unit\Service;

use Civi\Mascode\Test\TestCase;
use Civi\Mascode\Service\LifecycleRuleProvisioner;

/**
 * The queued-email report must describe what LifecycleEmail::resolveLiveMode()
 * will actually do — never claim a queued item "drafts" when it will send.
 *
 * These two decisions were extracted from describeQueuedLifecycleEmails() so
 * they could be tested rather than re-implemented by whatever was checking
 * them. Three times on this change a check re-stated the rule instead of
 * calling it, and so agreed with a broken implementation.
 *
 * Pure functions over strings — no CiviCRM bootstrap, no database.
 *
 * @covers \Civi\Mascode\Service\LifecycleRuleProvisioner
 */
class LifecycleQueueReportTest extends TestCase
{
    private function liveModeLabel($raw): string
    {
        $m = (new \ReflectionClass(LifecycleRuleProvisioner::class))->getMethod('liveModeLabel');
        $m->setAccessible(true);
        return $m->invoke(null, $raw);
    }

    private function effectiveMode(string $queued, string $live): string
    {
        $m = (new \ReflectionClass(LifecycleRuleProvisioner::class))->getMethod('effectiveMode');
        $m->setAccessible(true);
        return $m->invoke(null, $queued, $live);
    }

    public function liveParamsProvider(): array
    {
        // [raw action_params, does it genuinely carry a usable mode?]
        return [
            'mode auto'        => [serialize(['template' => 't', 'mode' => 'auto']), true],
            'mode propose'     => [serialize(['template' => 't', 'mode' => 'propose']), true],
            'mode absent'      => [serialize(['template' => 't']), false],
            'mode null'        => [serialize(['template' => 't', 'mode' => null]), false],
            'mode wrong case'  => [serialize(['mode' => 'AUTO']), false],
            'mode int'         => [serialize(['mode' => 5]), false],
            'mode bool'        => [serialize(['mode' => true]), false],
            'mode array'       => [serialize(['mode' => ['auto']]), false],
            'params empty'     => ['', false],
            'params null'      => [null, false],
            'params garbage'   => ['not-serialized', false],
            'params scalar'    => [serialize('just-a-string'), false],
        ];
    }

    /**
     * The invariant: a recognised label is returned IFF the params genuinely
     * carry a usable mode. resolveLiveMode() prefers the live mode under
     * exactly that condition and otherwise keeps the queued one, so this is
     * what makes the report's fallback fire when — and only when — the code
     * falls back.
     *
     * The bug this guards: defaulting an absent mode to 'propose' produced a
     * label that LOOKED recognised, so the report claimed "drafts" for items
     * the code sends.
     *
     * @dataProvider liveParamsProvider
     */
    public function testLabelIsRecognisedOnlyWhenParamsCarryAUsableMode($raw, bool $carriesMode): void
    {
        $label = $this->liveModeLabel($raw);
        $this->assertSame(
            $carriesMode,
            in_array($label, ['propose', 'auto'], true),
            sprintf('liveModeLabel() returned "%s"; params carry a usable mode: %s', $label, var_export($carriesMode, true))
        );
    }

    /**
     * A recognised live mode wins; anything else keeps the queued mode and is
     * marked, mirroring resolveLiveMode()'s fallback.
     */
    public function testEffectiveModePrefersLiveAndMarksFallbacks(): void
    {
        $this->assertSame('auto', $this->effectiveMode('propose', 'auto'));
        $this->assertSame('propose', $this->effectiveMode('auto', 'propose'));

        foreach (['(no params)', '(no mode set)', '(unreadable)', '(rule action gone)', 'AUTO'] as $unusable) {
            $this->assertSame('auto (fallback)', $this->effectiveMode('auto', $unusable), "live={$unusable}");
            $this->assertSame('propose (fallback)', $this->effectiveMode('propose', $unusable), "live={$unusable}");
        }
    }

    /**
     * resolveLiveMode() normalises an unrecognised snapshot to 'propose', so a
     * fallback must be labelled with the mode that will really be used.
     */
    public function testUnrecognisedQueuedModeIsNormalisedInFallbacks(): void
    {
        foreach (['propose (default)', 'AUTO', '5', ''] as $odd) {
            $this->assertSame('propose (fallback)', $this->effectiveMode($odd, '(no params)'), "queued={$odd}");
        }
    }
}
