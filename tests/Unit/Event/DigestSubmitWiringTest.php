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
     *
     * The negative assertion matches ANY spelling. An earlier version looked
     * for `addValue('status_id` with a single quote, and review walked past it
     * with a double-quoted call — so the guard covered the way I happened to
     * type it rather than the thing it forbids.
     */
    public function testTheCaseIsAdvancedBySendingNotByWritingStatus(): void
    {
        $code = $this->source(self::SUBSCRIBER);

        $this->assertStringContainsString(
            'LifecycleMailer::execute(',
            $code,
            'D7: advancing is done by sending the Completion template.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/addValue\(\s*[\'"]status_id/',
            $code,
            'D7 is one-owner: ProjectLifecycleStatusSubscriber maps template -> status. Writing status_id here '
            . 'creates a second owner, the two drift, and the chase arms off only one of them.'
        );
    }

    /**
     * The normalisation must actually RUN, not merely be subscribed.
     *
     * ⚠ REVIEW REPLACED onBeforeSave()'s ENTIRE BODY WITH `return;` AND THE
     * SUITE DID NOT MOVE. The priority assertion above pins where it is
     * subscribed; `CheckinAnswerTest` pins what the rule computes; nothing
     * pinned that the subscriber ever calls the rule or ever writes the result
     * back. That is the ticket's headline done-when — the item carried from
     * PR #39's review — and it was the sixth guard in this epic to assert less
     * than it claimed.
     */
    public function testTheNormalisationIsActuallyWired(): void
    {
        $body = $this->methodBody($this->source(self::SUBSCRIBER), 'onBeforeSave');

        $this->assertStringContainsString(
            'CheckinAnswer::normaliseWillAsk(',
            $body,
            'onBeforeSave() must call the rule. Subscribing without calling it is a no-op that looks wired.'
        );
        $this->assertStringContainsString(
            '$event->setRecords($records);',
            $body,
            'and it must write the corrected records back, or core saves the submitted value unchanged.'
        );
        $this->assertStringContainsString(
            'Monthly_Project_Checkin.vc_will_ask',
            $body,
            'on the field the whole guard is about.'
        );
    }

    /**
     * The re-send guard must be USED, not merely declared.
     *
     * `testAdvanceableStatusesMatchTheTransitionOwner` asserts the constant's
     * CONTENTS. Review measured that replacing the `in_array` check with
     * `if (false)` — deleting the entire re-send guard — left the suite green.
     */
    public function testTheResendGuardIsActuallyUsed(): void
    {
        $body = $this->methodBody($this->source(self::SUBSCRIBER), 'handleComplete');

        $this->assertStringContainsString(
            'self::ADVANCEABLE_FROM',
            $body,
            'handleComplete() must consult the from-list, or a project already moved on gets a second '
            . 'Completion email.'
        );
        $this->assertMatchesRegularExpression(
            '/in_array\(.*ADVANCEABLE_FROM.*\)/s',
            $body,
            'and consult it as a membership test.'
        );
    }

    /**
     * `digest_round` must be written.
     *
     * The class docblock calls it "the key P2-1's idempotency guard will match
     * on and the field that makes months-stuck countable (Goal 8)". Review
     * measured that deleting the write left the suite green.
     */
    public function testTheDigestRoundIsStamped(): void
    {
        $body = $this->methodBody($this->source(self::SUBSCRIBER), 'recordAnswers');

        $this->assertStringContainsString(
            "addValue('Monthly_Project_Checkin.digest_round', \$round)",
            $body,
            'Without this, Goal 8 cannot count distinct rounds and P2-1 has no key to match on.'
        );
    }

    /**
     * The Completion request goes to the VC who ANSWERED.
     *
     * On the 4 Active projects with two current coordinators, picking "a"
     * coordinator sends an authenticated close-form link to someone who did
     * not report the work finished, while the VC who did hears nothing.
     */
    public function testTheCompletionRequestGoesToTheAnsweringVc(): void
    {
        $body = $this->methodBody($this->source(self::SUBSCRIBER), 'answeringVc');

        $this->assertStringContainsString(
            'CRM_Core_Session::getLoggedInContactID()',
            $body,
            'The token-authenticated submitter is the VC who answered, and the entitlement guard has '
            . 'already verified they coordinate this case.'
        );
        $this->assertStringContainsString(
            '$this->isCurrentCoordinator($caseId, $contactId)',
            $body,
            'confirmed still current before use.'
        );

        // And that handleComplete() actually CALLS it. Asserting the method's
        // body while nothing pins the call site is the same gap that let four
        // other mutations through this file — the ingredient, not the dish.
        $caller = $this->methodBody($this->source(self::SUBSCRIBER), 'handleComplete');
        $this->assertStringContainsString(
            '$this->answeringVc($caseId)',
            $caller,
            'handleComplete() must ask who answered, not who happens to coordinate.'
        );
        $this->assertStringNotContainsString(
            '$this->coordinatorOf($caseId)',
            $caller,
            'coordinatorOf() is the staff fallback inside answeringVc(). Calling it directly from '
            . 'handleComplete() sends an authenticated close-form link to an arbitrary coordinator — '
            . 'measured on 4 live projects with two coordinators each.'
        );
    }

    /**
     * One method's body, so an assertion cannot be satisfied by a sibling.
     */
    private function methodBody(string $code, string $method): string
    {
        $start = strpos($code, "function {$method}(");
        $this->assertNotFalse($start, "Method {$method}() is missing.");
        $next = false;
        foreach (["\n    public function ", "\n    private function ", "\n    public static function "] as $marker) {
            $at = strpos($code, $marker, $start + 1);
            if ($at !== false && ($next === false || $at < $next)) {
                $next = $at;
            }
        }
        return $next === false ? substr($code, $start) : substr($code, $start, $next - $start);
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
