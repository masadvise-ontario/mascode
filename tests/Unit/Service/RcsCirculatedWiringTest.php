<?php

namespace Civi\Mascode\Test\Unit\Service;

use Civi\Mascode\Test\TestCase;

/**
 * Guards the two ways ensureRcsCirculatedRule() can ship broken without
 * anything going red, both found by review of PR #47 rather than by a test.
 *
 * Neither is hypothetical and neither raises an error at runtime — that is
 * exactly why they need pinning here.
 *
 * @coversNothing
 */
class RcsCirculatedWiringTest extends TestCase
{
    private const PROVISIONER = '/Civi/Mascode/Service/LifecycleRuleProvisioner.php';

    /**
     * The exact auto-mode phrase MODE_PHRASES flips on.
     *
     * The SHORT pair, deliberately. The long pair ends "Sending advances",
     * and this rule must never claim that — it does not advance the case.
     */
    private const AUTO_PHRASE = 'auto-mode (sent immediately)';

    private function stripComments(string $source): string
    {
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

    private function provisionerSource(): string
    {
        $path = dirname(__DIR__, 3) . self::PROVISIONER;
        $source = $this->stripComments((string) file_get_contents($path));
        $this->assertNotSame('', $source, "Could not read $path");
        return $source;
    }

    /**
     * The rule description must carry the mode phrase verbatim.
     *
     * WHY: setLifecycleEmailMode() rewrites descriptions with str_replace on
     * an exact substring. A description that carries neither MODE_PHRASES
     * shape is silently skipped — the rule's action_params flip to propose
     * while the CiviRules UI goes on advertising auto mode. This shipped
     * wrong the first time ("immediately and in auto mode", which matches
     * neither), so the input that trips this test is any rewording that drops
     * the parenthesised phrase.
     */
    public function testTheRuleDescriptionCarriesTheModeFlipPhrase(): void
    {
        $source = $this->provisionerSource();

        $start = strpos($source, 'mas_lifecycle_rcs_circulated');
        $this->assertNotFalse($start, 'ensureRcsCirculatedRule() no longer names its own rule.');
        $window = substr($source, $start, 2000);

        $this->assertStringContainsString(
            self::AUTO_PHRASE,
            $window,
            "The mas_lifecycle_rcs_circulated description must contain '" . self::AUTO_PHRASE . "' verbatim.\n"
            . 'MODE_PHRASES flips descriptions by exact substring match, so any other wording means '
            . 'setLifecycleEmailMode() cannot rewrite it and the CiviRules UI will advertise the wrong '
            . 'send mode after a flip. Use the SHORT phrase pair — the long one ends "Sending advances", '
            . 'which this rule must not claim because it does not advance the case.'
        );
    }

    /**
     * Both provisioning entry points must call the provisioner.
     *
     * WHY: `cv ext:enable` stamps schema_version to the newest revision, so
     * upgrade_5017 never runs on a clean install. Without the bootstrap
     * script a fresh environment gets every other lifecycle rule and silently
     * not this one. The input that trips this test is deleting either caller.
     */
    public function testBothProvisioningEntryPointsCallIt(): void
    {
        $root = dirname(__DIR__, 3);

        foreach (
            [
                '/CRM/Mascode/Upgrader.php' => 'the upgrade step (existing installs)',
                '/scripts/create-rcs-circulated-rule.php' => 'the bootstrap script (fresh installs)',
            ] as $rel => $why
        ) {
            $path = $root . $rel;
            $this->assertFileExists($path, "$rel is missing — that is $why.");
            $this->assertStringContainsString(
                'ensureRcsCirculatedRule',
                $this->stripComments((string) file_get_contents($path)),
                "$rel no longer calls ensureRcsCirculatedRule(). That entry point is $why; without it "
                . 'the rule is simply absent there, with no error.'
            );
        }
    }
}
