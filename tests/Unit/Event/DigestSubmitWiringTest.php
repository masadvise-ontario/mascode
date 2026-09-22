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

    /**
     * Source, COMMENTS ALWAYS STRIPPED. There is deliberately no other way to
     * read a file in this class.
     *
     * ⚠ THE RULE, and it is stronger than "strip comments before matching":
     * **a helper that strips comments should be the only way a test can read
     * source at all.** Anything else leaves a judgement call about which
     * assertions need stripping — and that call has now been got wrong twice,
     * in two files, by two different sessions, within an hour.
     *
     * Here it was got wrong the second time: `codeOnly()` was applied inside
     * `methodBody()` and not here, so three assertions still matched raw text.
     * Review demonstrated all three green, the worst being
     * `['onBeforeSave', -5]` with `// was ['onBeforeSave', 10],` left above it
     * — the normalisation moved to AFTER the write, which is the
     * repair-after-write window this file exists to prevent, and a mutation
     * the PR body had claimed red since round 1. `preg_match` takes the FIRST
     * hit, so the comment satisfied it.
     */
    private function source(string $path): string
    {
        $this->assertFileExists($path);
        return $this->codeOnly((string) file_get_contents($path));
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
            'CheckinAnswer::normaliseRecords($event->getRecords())',
            $body,
            'onBeforeSave() must call the rule. Subscribing without calling it is a no-op that looks wired. '
            . 'That the rule is APPLIED is asserted behaviourally in CheckinAnswerTest.'
        );
        $this->assertStringContainsString(
            '$event->setRecords($result[\'records\']);',
            $body,
            'and it must write the corrected records back, or core saves the submitted value unchanged.'
        );
        $this->assertStringContainsString(
            "\$result['changed']",
            $body,
            'and act on whether anything actually changed.'
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
        // The SENSE, not just the membership. Dropping the `!` is a
        // one-character edit that survived the old assertion and produces the
        // worst available behaviour: the Completion email is never sent on the
        // legitimate path, and IS sent on every project that has already moved
        // on — the exact double-send to a volunteer this guard prevents.
        // A plain containment check, not a regex: the class name is
        // backslash-heavy and the escaping maze is itself a way for an
        // assertion to quietly stop matching.
        $this->assertStringContainsString(
            'if (!\Civi\Mascode\Digest\CheckinAnswer::shouldAdvance(',
            $body,
            'The guard must return EARLY when the status is NOT advanceable. Dropping the negation '
            . 'inverts it into a double-send: never sending on the legitimate path, and sending on '
            . 'every project that has already moved on.'
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
     * One method's body, comments stripped, so an assertion cannot be satisfied
     * by prose or by a neighbour.
     *
     * ⚠ THE FIRST VERSION OF THIS HELPER WAS SATISFIABLE THREE WAYS, and review
     * demonstrated each. It is worth listing them because the repo already
     * contained the solved version one directory away — `FrozenMachineNamesTest`
     * ships `codeOnly()` for exactly this, and its docblock says "strip the
     * comments, or the guard guards the comments".
     *
     *   1. **Comments.** Commenting out `onBeforeSave()`'s body and leaving the
     *      lines in place made the normalisation entirely dead while all three
     *      assertions passed, because the strings they look for sat two lines
     *      above in a `//`. Commenting code out and leaving it is an ordinary
     *      thing a developer does.
     *   2. **The next method's DOCBLOCK.** The old anchor sat after it, so every
     *      slice already ran through it — which let `answeringVc()` be gutted to
     *      `return $this->coordinatorOf($caseId);` with the required strings
     *      moved into the following docblock. That mutation reintroduces the
     *      original H1 defect in full, with every assertion green.
     *   3. **Visibilities it did not know about.** The marker list named three
     *      of five, so a `protected function` or `private static function`
     *      sibling was invisible to it.
     *
     * Now: comments stripped first, and the slice ends at the next method's
     * DOCBLOCK or declaration, whichever comes first, for any visibility.
     */
    private function methodBody(string $code, string $method): string
    {
        $code = $this->codeOnly($code);

        // preg_match_all, not preg_match: the message below claims to detect a
        // duplicate declaration, and preg_match returns 0 or 1 and stops at
        // the first hit — it can never report two. An assertion whose message
        // describes a check it cannot perform is this epic's own failure mode
        // in miniature.
        $found = preg_match_all(
            '/\n\s*(?:public|protected|private)(?:\s+static)?\s+function\s+'
            . preg_quote($method, '/') . '\s*\(/',
            $code,
            $m,
            PREG_OFFSET_CAPTURE
        );
        $this->assertSame(1, $found, "Method {$method}() is missing, or declared more than once.");
        $start = $m[0][0][1];
        $after = $start + strlen($m[0][0][0]);

        // End at the next DECLARATION, or at the class's closing brace for the
        // last method in the file. No `/**` alternative: codeOnly() has already
        // removed every docblock, so that branch could never fire — dead code
        // describing a mechanism that does not exist, in a helper whose whole
        // subject is guards that read better than they are.
        //
        // The closing-brace fallback matters: without it the LAST method in a
        // class runs to EOF, which is the unbounded slice this helper was
        // written to fix, reintroduced by position rather than by visibility.
        $end = strlen($code);
        foreach ([
            '/\n\s*(?:public|protected|private)(?:\s+static)?\s+function\s/',
            '/\n\}/',
        ] as $pattern) {
            if (preg_match($pattern, substr($code, $after), $n, PREG_OFFSET_CAPTURE)) {
                $end = min($end, $after + $n[0][1]);
            }
        }

        return substr($code, $start, $end - $start);
    }

    /**
     * Source with comments removed.
     *
     * The same technique, and the same reason, as
     * tests/Unit/Managed/FrozenMachineNamesTest.php's codeOnly(): these files
     * necessarily DISCUSS the strings being asserted, at length, so a raw
     * match tests the prose rather than the code.
     */
    private function codeOnly(string $source): string
    {
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                // Keep the newlines a docblock spanned, so the anchors below
                // still see line starts.
                $out .= str_repeat("\n", substr_count($token[1], "\n"));
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }
        return $out;
    }

    /**
     * The coordinator lookup must use `is_current`, like everything else that
     * asks whether somebody still runs a project.
     */
    public function testCoordinatorLookupUsesIsCurrent(): void
    {
        // PER METHOD, because there are now two sites carrying this predicate.
        // A file-scoped assertion let one of them lose `is_current` while the
        // other kept the test green — review measured it.
        $code = $this->source(self::SUBSCRIBER);

        foreach (['coordinatorOf', 'isCurrentCoordinator'] as $method) {
            $body = $this->methodBody($code, $method);
            $this->assertStringContainsString(
                "addWhere('is_current', '=', true)",
                $body,
                "{$method}() must test is_current."
            );
            $this->assertDoesNotMatchRegularExpression(
                '/addWhere\(\s*[\'"]is_active/',
                $body,
                "{$method}(): sending a Completion request to someone whose role ended is the same mistake "
                . 'as letting them open the form — 299 of 481 coordinator rows that carry a case are ended '
                . 'but still is_active.'
            );
        }
    }
}
