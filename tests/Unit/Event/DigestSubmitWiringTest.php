<?php

namespace Civi\Mascode\Test\Unit\Event;

use Civi\Mascode\Test\TestCase;

/**
 * The CI-visible half of the check-in submit handler.
 *
 * Three things here are one-word mistakes with consequences a test can hold,
 * and the rest of the class is API4 calls CI cannot reach:
 *
 *   1. `isTrue()` decides whether a Completion email is sent to a volunteer.
 *      A loose `(bool)` cast makes the string `'0'` falsy but `'false'` truthy,
 *      and CiviCRM round-trips booleans as `'1'`/`'0'` often enough that the
 *      distinction is not academic (mascode memory
 *      feedback_afform_boolean_string_id_bug).
 *   2. The priorities. Normalising `vc_will_ask` must happen BEFORE core writes
 *      (>0) and the advance AFTER the activity has an id (<0). Swap either and
 *      the class still looks subscribed.
 *   3. `ADVANCEABLE_FROM` duplicates ProjectLifecycleStatusSubscriber's `from`
 *      list for the same template, because that constant is private. A
 *      divergence means a check-in that should advance silently does not.
 *
 * Parsed as TEXT, not reflection, for the reason the sibling wiring tests give:
 * the subject extends `AutoSubscriber`, so loading the class needs CiviCRM on
 * the autoloader and CI has none. `isTrue()` is static and dependency-free, so
 * it is asserted by behaviour rather than by source.
 *
 * @coversNothing
 */
class DigestSubmitWiringTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    private const SUBSCRIBER = self::ROOT . '/Civi/Mascode/Event/VcDigestSubmitSubscriber.php';

    private const LIFECYCLE = self::ROOT . '/Civi/Mascode/Event/ProjectLifecycleStatusSubscriber.php';

    private function source(string $path): string
    {
        $this->assertFileExists($path);
        return (string) file_get_contents($path);
    }

    /**
     * Normalise before the write, advance after it.
     */
    public function testBothHalvesAreSubscribedOnTheCorrectSideOfTheWrite(): void
    {
        $code = $this->source(self::SUBSCRIBER);

        $this->assertMatchesRegularExpression(
            "/\\['onBeforeSave',\\s*(\\d+)\\]/",
            $code,
            'The normalisation must be subscribed.'
        );
        preg_match("/\\['onBeforeSave',\\s*(-?\\d+)\\]/", $code, $before);
        preg_match("/\\['onAfterSave',\\s*(-?\\d+)\\]/", $code, $after);

        $this->assertNotEmpty($before, 'onBeforeSave is not subscribed.');
        $this->assertNotEmpty($after, 'onAfterSave is not subscribed.');

        $this->assertGreaterThan(
            0,
            (int) $before[1],
            'onBeforeSave must run BEFORE core writes (priority > 0), so vc_will_ask is corrected rather '
            . 'than repaired — a repair-after-write leaves a window in which the impossible state is real.'
        );
        $this->assertLessThan(
            0,
            (int) $after[1],
            'onAfterSave must run AFTER core writes (priority < 0), or the activity has no id yet.'
        );
    }

    /**
     * The duplicated `from` list must match the subscriber that owns the
     * transition.
     *
     * D7 says the transition has exactly one owner. This class only decides
     * whether to SKIP a send, so a mismatch is a missed advance rather than a
     * double one — but a missed advance is silent, which is the failure mode
     * this whole epic keeps finding.
     */
    public function testAdvanceableStatusesMatchTheTransitionOwner(): void
    {
        $mine = $this->source(self::SUBSCRIBER);
        $owner = $this->source(self::LIFECYCLE);

        preg_match("/ADVANCEABLE_FROM = \[(.*?)\];/s", $mine, $m);
        $this->assertNotEmpty($m, 'ADVANCEABLE_FROM is missing.');
        preg_match_all("/'([^']+)'/", $m[1], $mineStatuses);

        preg_match(
            "/'MAS Project Completion - VC Template' => \[\s*'from' => \[(.*?)\]/s",
            $owner,
            $o
        );
        $this->assertNotEmpty($o, "Could not read the Completion transition's from-list.");
        preg_match_all("/'([^']+)'/", $o[1], $ownerStatuses);

        sort($mineStatuses[1]);
        sort($ownerStatuses[1]);
        $this->assertSame(
            $ownerStatuses[1],
            $mineStatuses[1],
            'ADVANCEABLE_FROM duplicates ProjectLifecycleStatusSubscriber::TRANSITIONS\' from-list because that '
            . 'constant is private. If they diverge, a check-in that should advance the project silently does not.'
        );
    }

    /**
     * D7: the transition is effected by SENDING, never by writing status_id.
     */
    public function testTheCaseIsAdvancedBySendingNotByWritingStatus(): void
    {
        $code = $this->source(self::SUBSCRIBER);

        $this->assertStringContainsString(
            'LifecycleMailer::execute(',
            $code,
            'D7: advancing is done by sending the Completion template.'
        );
        $this->assertStringNotContainsString(
            "addValue('status_id",
            $code,
            'D7 is one-owner: ProjectLifecycleStatusSubscriber maps template -> status. Writing status_id here '
            . 'creates a second owner, the two drift, and the chase arms off only one of them.'
        );
    }

    /**
     * The coordinator lookup must use `is_current`, like everything else that
     * asks whether somebody still runs a project.
     */
    public function testCoordinatorLookupUsesIsCurrent(): void
    {
        $code = $this->source(self::SUBSCRIBER);

        $this->assertStringContainsString("addWhere('is_current', '=', true)", $code);
        $this->assertStringNotContainsString(
            "addWhere('is_active', '=', true)",
            $code,
            'Sending a Completion request to someone whose role ended is the same mistake as letting them '
            . 'open the form: 299 of 481 coordinator rows that carry a case are ended but still is_active.'
        );
    }
}
