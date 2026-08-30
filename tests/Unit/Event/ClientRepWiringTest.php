<?php

namespace Civi\Mascode\Test\Unit\Event;

use Civi\Mascode\Test\TestCase;

/**
 * A tripwire for the client-representative change on the two VC project forms.
 *
 * WHAT THIS IS AND IS NOT
 * This is NOT a behavioural test — it reads AfformSubmitSubscriber as TEXT.
 * It cannot be anything else: the subscriber extends
 * \Civi\Core\Service\AutoSubscriber and calls \Civi::log() and half a dozen API4
 * entities, none of which exist in CI, where the pipeline installs Composer
 * packages only and no CiviCRM. The real behavioural proof is
 * tests/Live/ClientRepChangeTest.php, which drives two whole Afform submissions
 * against a live site — but that cannot run in CI, so without this file CI has
 * no view of the feature at all.
 *
 * Source-matching is a blunt instrument and normally a smell. It earns its place
 * here on the same terms as tests/Unit/Security/AfformArgGuardWiringTest.php:
 * the alternative is no CI coverage, and the specific regression it catches is
 * SILENT. Nothing errors, nothing looks wrong, and a real client contact quietly
 * loses their email address.
 *
 * NOTE the annotation: @coversNothing, NOT @covers. The @covers form makes
 * PHPUnit resolve the named class to build a coverage map, which autoloads the
 * subscriber, which extends AutoSubscriber — a class CI does not have. That
 * aborts the WHOLE suite before a test runs, and it does NOT reproduce locally,
 * where buildkit has CiviCRM bootstrapped. See docs/TESTING.md.
 *
 * @coversNothing
 */
class ClientRepWiringTest extends TestCase
{
    private const SUBSCRIBER = __DIR__ . '/../../../Civi/Mascode/Event/AfformSubmitSubscriber.php';

    private function source(): string
    {
        $path = self::SUBSCRIBER;
        $this->assertFileExists(
            $path,
            'AfformSubmitSubscriber is gone. If it moved, it must stay under '
            . 'Civi/Mascode/Event/ to be auto-registered by the scan-classes mixin.'
        );
        return (string) file_get_contents($path);
    }

    /**
     * Extract one method body, so an assertion about a specific behaviour cannot
     * be satisfied by a coincidentally similar line elsewhere in a 1900-line file.
     * Whole-file greps are the main way a source tripwire silently stops testing
     * what its message claims — `addWhere('case_id', '=', $caseId)` alone appears
     * seven times in this subscriber.
     */
    private function methodBody(string $signature, string $missingMessage): string
    {
        $source = $this->source();
        $start = strpos($source, $signature);
        $this->assertNotFalse($start, $missingMessage);
        // Up to the next method declaration at class-body indentation. Accepts
        // `protected`/`private` too, so making a method non-public does not
        // silently swallow the rest of the class into one "body".
        $end = false;
        foreach (["\n    public function ", "\n    protected function ", "\n    private function "] as $next) {
            $at = strpos($source, $next, $start + 1);
            if ($at !== false && ($end === false || $at < $end)) {
                $end = $at;
            }
        }
        // Wind back to this method's own closing brace. Without this the extracted
        // "body" carries the NEXT method's docblock prose, which re-opens the very
        // hole this scoping exists to close — an assertion satisfied by a comment.
        //
        // Anchored on the closing brace rather than on the last "\n    /**" in the
        // span: that earlier form took whichever docblock came last, so a
        // docblocked constant or property sitting between two methods would leave
        // the preceding docblock's prose inside the extracted body.
        if ($end !== false) {
            $close = strrpos(substr($source, $start, $end - $start), "\n    }");
            if ($close !== false) {
                $end = $start + $close + strlen("\n    }");
            }
        }
        return substr($source, $start, $end === false ? null : $end - $start);
    }

    private function clientRepPreProcessBody(): string
    {
        return $this->methodBody(
            'public function onClientRepPreProcess(',
            'onClientRepPreProcess() is gone: the VC forms can no longer tell a renamed '
            . 'client rep (a different person) from an edited one.'
        );
    }

    /**
     * The priority is bounded on BOTH sides, and both bounds are load-bearing.
     *
     * Lower bound (> 0): at zero or below the handler runs after core's
     * processGenericEntity(), by which point the contact is already saved and
     * removing its id changes nothing — a rename would silently overwrite the
     * outgoing person's record instead of creating a new contact.
     *
     * Upper bound (< 10): the handler is written to run AFTER core's
     * ContactDedupe (101) and preprocessContact (10). Pinning the record id back
     * to the case role holder is only meaningful once dedupe has had its say, and
     * the blank-fieldset branch reasons explicitly from preprocessContact having
     * already nulled a nameless, emailless record. Moving this to, say, 150 would
     * silently un-do both while every other assertion in this file stayed green.
     */
    public function testPreProcessRunsAfterCoreBehaviorsButBeforeTheSave(): void
    {
        $this->assertMatchesRegularExpression(
            "/\[\s*['\"]onClientRepPreProcess['\"]\s*,\s*([1-9]\d*)\s*,?\s*\]/",
            $this->source(),
            'onClientRepPreProcess must stay registered at a positive priority, so it runs '
            . 'before Afform saves the contact.'
        );

        preg_match(
            "/\[\s*['\"]onClientRepPreProcess['\"]\s*,\s*(\d+)\s*,?\s*\]/",
            $this->source(),
            $m
        );
        $this->assertLessThan(
            10,
            (int) ($m[1] ?? 0),
            'onClientRepPreProcess must stay below core preprocessContact (10) and '
            . 'ContactDedupe (101): the id pin and the blank-fieldset branch both assume '
            . 'those have already run.'
        );
    }

    /**
     * The load-bearing pairing, and the reason this file exists.
     *
     * When the contact id is stripped to force a new contact, the submitted Email
     * join's id must be stripped with it. That id belongs to the OUTGOING rep's
     * email row, echoed back by the browser from the prefill. Afform::saveJoins()
     * only overwrites it when loadJoins() finds an existing row, which a
     * brand-new contact never has, so it survives into Email::replace(), whose
     * BasicReplaceAction merges the where clause (contact_id = the NEW contact)
     * into the record as a default and MOVES the row. Measured on dev: the
     * outgoing rep is left with zero email addresses, with no error anywhere.
     */
    public function testStrippingTheContactIdAlsoStripsJoinIds(): void
    {
        $body = $this->clientRepPreProcessBody();

        // Tolerant of `unset($a, $b)` and of extra whitespace, so merging the two
        // unsets into one statement — a correct and arguably tidier refactor —
        // does not turn the suite red for no reason.
        $this->assertMatchesRegularExpression(
            '/unset\(\s*\$records\[0\]\[.fields.\]\[.id.\]/',
            $body,
            'The contact id must still be removed to force creation of a new contact.'
        );
        // Must match the STRIP LOOP specifically — indexed by variable all the way
        // down to the id field. Two looser forms were tried and both were satisfied
        // by unrelated lines in this same method: a bare "['joins']" by the
        // blank-fieldset branch's `$records[0]['joins'] = []`, and an unset on
        // `['joins'][` by the in-place-edit branch's
        // `unset($records[0]['joins']['Email'])`. Either would have stayed green
        // with the whole loop deleted.
        $this->assertMatchesRegularExpression(
            '/unset\(\s*\$records\[0\]\[.joins.\]\[\$\w+\]\[\$\w+\]\[\$\w+\]/',
            $body,
            'Join ids must still be unset alongside the contact id. Removing that loop '
            . 'breaks no other test but silently steals a client contact\'s email address.'
        );
    }

    /**
     * "Who is the client rep on file" must be read from the CASE, never from the
     * submitted record's id.
     *
     * Core's ContactDedupe behavior subscribes to the same event at priority 101,
     * so it has already run and may have rewritten the record id to a contact it
     * matched. Comparing the submitted name against that id then finds them equal
     * and concludes nothing changed, so the case role never moves — the one thing
     * this feature exists to do, failing silently.
     */
    public function testCurrentRepIsReadFromTheCase(): void
    {
        $this->assertStringContainsString(
            'getCaseClientRepIds',
            $this->clientRepPreProcessBody(),
            'The current client rep must still be read from the case role, not from the '
            . 'submitted record id, which core ContactDedupe may already have rewritten.'
        );
        // Scoped to the method, like every other assertion in this file.
        $this->assertStringContainsString(
            "addWhere('near_relation:name', '=', self::CLIENT_REP_REL_NAME)",
            $this->methodBody(
                'protected function getCaseClientRepIds(',
                'getCaseClientRepIds() is gone; the incumbent rep is no longer read from the case.'
            ),
            'getCaseClientRepIds() must still resolve the rep through the case role.'
        );
    }

    /**
     * The role move itself must still happen, and must still end the outgoing
     * rep's role rather than only adding the incoming one — otherwise the case
     * accumulates two active client reps and the form starts autofilling an
     * arbitrary one of them.
     */
    public function testTheRoleIsMovedNotJustAdded(): void
    {
        $source = $this->source();

        $this->assertStringContainsString(
            'applyClientRepChange',
            $source,
            'The post-save handler that moves the case role is gone.'
        );
        $this->assertStringContainsString(
            'endCaseClientRepRelationship',
            $source,
            'The outgoing rep\'s case role must still be ended, not left active alongside '
            . 'the incoming one.'
        );
        // Scoped to the method, not the file: this exact clause appears seven times
        // in the subscriber, so a whole-file grep stayed green with the one that
        // matters deleted — the precise regression the message describes.
        $this->assertStringContainsString(
            "addWhere('case_id', '=', \$caseId)",
            $this->methodBody(
                'protected function endCaseClientRepRelationship(',
                'endCaseClientRepRelationship() is gone; the outgoing role is no longer stood down.'
            ),
            'Ending the outgoing role must stay scoped to one case, or the person loses '
            . 'their client-rep role on every other project too.'
        );
    }

    /**
     * A blank email must mean "leave the address alone" — unconditionally.
     *
     * Round 2 found this guard originally read
     * `if ($submittedEmail === '' && !empty($records[0]['joins']['Email']))`, which
     * is false for the present-but-EMPTY-array shape that
     * preprocessSubmittedValues() preserves. That shape still reached saveJoins()
     * and fell to its delete branch — `Email::delete` over the client rep's whole
     * address set. Reverting to the two-condition form leaves every other
     * assertion in this file green and every live scenario green (scenario C sends
     * a NON-empty array, which the buggy guard also caught), so without this
     * assertion the regression has no detector at all.
     */
    public function testBlankEmailGuardIsUnconditional(): void
    {
        $this->assertMatchesRegularExpression(
            "/if \\(\\\$submittedEmail === ''\\)\\s*\\{(?:\\s*\\/\\/[^\\n]*\\n)*\\s*unset\\(\\\$records\\[0\\]\\['joins'\\]\\['Email'\\]\\)/",
            $this->clientRepPreProcessBody(),
            'A blank email must drop the Email join unconditionally. Re-adding an '
            . '!empty() check re-opens Email::delete over the rep\'s whole address set '
            . 'for the empty-array join shape.'
        );
    }

    /**
     * One blank name half is an incomplete edit, not a handover.
     *
     * Without this guard, a VC who clears the rep's first name to retype it and
     * submits gets a brand-new contact with an empty first name, the case role
     * moved to it, and the real rep's role end-dated — while the form's own blurb
     * promises that a blank field means no change.
     */
    public function testIncompleteNameIsNotTreatedAsAHandover(): void
    {
        $body = $this->clientRepPreProcessBody();

        $this->assertStringContainsString(
            '$nameIncomplete',
            $body,
            'A partially blank name must still be distinguished from a real handover.'
        );
        // The flag must actually gate $nameChanged; computing it and ignoring it
        // would satisfy a bare mention.
        $this->assertMatchesRegularExpression(
            '/\$nameChanged\s*=\s*!\$nameIncomplete/',
            $body,
            '$nameIncomplete must gate $nameChanged, not merely be computed.'
        );

        // The OR is load-bearing: flipping it to && makes "incomplete" mean BOTH
        // halves blank, which restores the original defect for the one-half case.
        // Round 4 mutation-tested exactly this and found nothing caught it.
        // Parens optional and order-free: this test exists to catch `&&` replacing
        // `||`, not to freeze the formatting. Dropping redundant parens or swapping
        // the operands changes nothing and must not turn the suite red — the same
        // standard testStrippingTheContactIdAlsoStripsJoinIds sets for itself.
        // Alternation of the two ORDERS, not two independent alternations: the
        // looser form accepted `($firstBlank || $firstBlank)`, which ignores the
        // last half entirely and turns `first='X' last=''` back into a handover
        // that creates a contact with no surname.
        $this->assertMatchesRegularExpression(
            '/\$nameIncomplete\s*=\s*\(?\s*(?:\$firstBlank\s*\|\|\s*\$lastBlank|\$lastBlank\s*\|\|\s*\$firstBlank)\s*\)?\s*;/',
            $body,
            '$nameIncomplete must be OR of the two halves — && would only catch an '
            . 'all-blank name and re-open the partial-name defect.'
        );

        // And the drop itself: an incomplete name must write NEITHER half.
        // Deleting this loop was the other mutation nothing caught, and it is the
        // difference between "no change" and silently renaming the incumbent.
        // Accepts one merged unset() or two adjacent ones — both halves must go,
        // but whether that is one statement or two is style.
        foreach (['first_name', 'last_name'] as $half) {
            $this->assertMatchesRegularExpression(
                "/unset\\([^;]*\\\$records\\[0\\]\\['fields'\\]\\['" . $half . "'\\]/",
                $body,
                "An incomplete name must drop the $half field too. Dropping only the blank "
                . 'half renames the incumbent instead of leaving them alone.'
            );
        }

        // And the email must go with them when the incomplete name was an attempted
        // handover — otherwise the incoming person's address lands on the incumbent,
        // who keeps the role. Round 5 found the name suppressed and the email not.
        //
        // Scoped to the gate, NOT a bare search for an Email unset: this method
        // contains a second one (the blank-email guard further down), which
        // satisfied the loose form even with this branch's unset deleted. That is
        // the third time in this file a whole-body grep has been satisfied by an
        // unrelated line, so it is checked by mutation every time now.
        // The gate must compare each non-blank half against what is ON FILE, and
        // must NOT be built from $firstChanged/$lastChanged. Those carry Rule 2's
        // `$currentX !== ''` term, which exists to avoid creating a duplicate
        // contact — borrowing it here scored lastChanged=false for an incumbent
        // whose surname is blank, so the incoming person's email landed on them.
        // That is the round-5 harm reappearing in the one cell Rule 2 protects.
        // EXACT SHAPE, order-free — not a bare `[^;]*` search for each strcasecmp.
        // The loose form was green through four mutations that break the gate,
        // including `||` -> `&&`, which makes it ALWAYS false inside this branch
        // (one half is blank by definition here) and so fully restores the harm
        // rounds 5, 6 and 7 have each chased. That is the same looseness this file
        // tempered for the Rule-2 flags in the very commit that introduced it here.
        $firstTerm = '!\$firstBlank\s*&&\s*strcasecmp\(\s*\$submittedFirst\s*,\s*\$currentFirst\s*\)\s*!==\s*0';
        $lastTerm = '!\$lastBlank\s*&&\s*strcasecmp\(\s*\$submittedLast\s*,\s*\$currentLast\s*\)\s*!==\s*0';
        $join = '\s*\)?\s*\|\|\s*\(?\s*';
        $this->assertMatchesRegularExpression(
            '/\$attemptedHandover\s*=\s*\(?\s*(?:'
                . $firstTerm . $join . $lastTerm . '|' . $lastTerm . $join . $firstTerm
                . ')\s*\)?\s*;/s',
            $body,
            'The unfinishable-handover gate must compare each NON-BLANK half against what '
            . 'is on file, ORed together. Any other shape either misses the blank-half-on-'
            . 'file cell or disables the gate entirely.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\$attemptedHandover\s*=[^;]*\$(first|last)Changed/s',
            $body,
            'The unfinishable-handover gate must NOT be built from $firstChanged/$lastChanged: '
            . "their \$currentX !== '' term is about duplicate contacts, not about whether "
            . 'this submission is too ambiguous to write an email.'
        );
        // EVERY join, not a named one. An earlier version of this assertion also
        // accepted `unset($records[0]['joins'][<any key>])`, which deletes the whole
        // point of the runtime code it guards: someone adding a Phone block to this
        // fieldset and narrowing the clearing to `unset(...['joins']['Email'])` kept
        // the suite green while the incoming person's phone number landed on the
        // incumbent — the round-5 harm, a third time, in the one place the tripwire
        // had been widened. `unset($records[0]['joins'])` (the whole key) is the only
        // genuinely equivalent alternative, so that is the only one accepted.
        $this->assertMatchesRegularExpression(
            "/if\\s*\\(\\\$attemptedHandover\\)\\s*\\{(?:\\s*\\/\\/[^\\n]*\\n)*\\s*"
            . "(?:\\\$records\\[0\\]\\['joins'\\]\\s*=\\s*\\[\\]|unset\\(\\s*\\\$records\\[0\\]\\['joins'\\]\\s*\\))/",
            $body,
            'An unfinishable handover must suppress EVERY submitted join, not a named one: '
            . 'a second join block added to this fieldset would otherwise still be written '
            . 'to the incumbent.'
        );
    }

    /**
     * A handover must be detectable only from a field that HAD a value.
     *
     * On the 2026-05-30 dev clone, 4 of the 382 people holding an active
     * "Case Client Rep is" role have an empty last_name — 1 of the 195 whose role
     * is CURRENT, the predicate getCaseClientRepIds() actually uses. Without the
     * `$currentX !== ''` terms, a VC filling one of those in reads as a handover: a
     * duplicate contact is created and the real rep is unseated, for what is
     * plainly a data correction. Nothing else in CI sees this.
     */
    public function testAHandoverRequiresTheFieldToHaveHadAValue(): void
    {
        $body = $this->clientRepPreProcessBody();

        // Anchored as a CONJUNCT of the assignment, not merely present somewhere in
        // it. Round 5 showed the looser `![^;]*` form stayed green for two
        // mutations that restore the defect — `($currentFirst !== '' || true)`, and
        // moving the term behind an `||` so $firstChanged becomes true for every
        // incumbent with a first name.
        $pairs = [
            'firstChanged' => ['firstBlank', 'currentFirst'],
            'lastChanged' => ['lastBlank', 'currentLast'],
        ];
        foreach ($pairs as $flag => [$blank, $current]) {
            // Tempered to stop at `||` or `?`, so the term must be a TOP-LEVEL
            // conjunct — but the conjuncts may appear in any order. The stricter
            // fixed-order form was red for two semantically identical reorderings,
            // and the looser `[^;]*` form was green for two mutations that
            // neutralise the term (`|| true`, and moving it behind an `||`).
            $this->assertMatchesRegularExpression(
                '/\$' . $flag . '\s*=\s*(?:(?!\|\||\?)[^;])*\$' . $current . "\s*!==\s*''(?:(?!\|\||\?)[^;])*;/s",
                $body,
                "\$$flag must AND \$$current !== '' as a top-level conjunct, or filling in "
                . 'a missing name half is misread as a handover to a different person.'
            );
            $this->assertMatchesRegularExpression(
                '/\$' . $flag . '\s*=\s*(?:(?!\|\||\?)[^;])*!\$' . $blank . '(?:(?!\|\||\?)[^;])*;/s',
                $body,
                "\$$flag must also require \$$blank to be false."
            );
        }
    }

    /**
     * An ambiguous case must write to NOBODY.
     *
     * When a case carries more than one distinct client rep, which one the form
     * autofilled from is not knowable server-side — core issues that query with no
     * ORDER BY. Merely declining to move the case role is not enough: returning
     * early leaves Afform to update whichever contact it autofilled, so "we cannot
     * tell who the VC was editing" still renames one of them. The record has to be
     * cleared. Verified end to end by scenario H of
     * tests/Live/ClientRepChangeTest.php, which fails loudly without this.
     */
    public function testAmbiguousCaseWritesToNobody(): void
    {
        $body = $this->clientRepPreProcessBody();

        $this->assertMatchesRegularExpression(
            '/count\(\$repIds\)\s*>\s*1/',
            $body,
            'The multiple-client-rep case must still be detected.'
        );
        // The clearing, not just the detection: an early `return` on this branch
        // passes any assertion about the warning while still renaming a stranger.
        $this->assertMatchesRegularExpression(
            '/count\(\$repIds\)\s*>\s*1.*?\$records\[0\]\[.fields.\]\s*=\s*\[\];/s',
            $body,
            'An ambiguous case must CLEAR the record, not merely return — otherwise '
            . 'Afform still updates whichever contact it autofilled.'
        );
    }

    /**
     * Both VC forms must stay in scope. The feature was asked for on the close
     * form and the project-definition form; dropping either is a silent
     * half-delivery.
     */
    public function testBothVcProjectFormsAreCovered(): void
    {
        $source = $this->source();

        // Both names appear in docblocks too, so a whole-file grep passes with the
        // constant emptied. Match the const block itself.
        $start = strpos($source, 'private const VC_CLIENT_REP_FORMS = [');
        $this->assertNotFalse($start, 'VC_CLIENT_REP_FORMS is gone; no form is in scope.');
        $block = substr($source, $start, strpos($source, '];', $start) - $start);

        // Routes as well as names: onFormSubmit() and onClientRepPreProcess() both
        // gate on the ROUTE key, so a correct name under a wrong route silently
        // disables the feature.
        $expected = [
            'civicrm/mas-pdef-vc' => 'afformMASProjectDefinitionVC',
            'civicrm/mas-pclose-vc' => 'afformProjectCloseVCFeedback',
        ];
        foreach ($expected as $route => $form) {
            $this->assertStringContainsString(
                $form,
                $block,
                "$form must stay in VC_CLIENT_REP_FORMS or its client-rep fieldset does nothing."
            );
            $this->assertStringContainsString(
                $route,
                $block,
                "Route $route must stay in VC_CLIENT_REP_FORMS — both handlers gate on the route key."
            );
        }
    }
}
