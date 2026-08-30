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
     * The handler must stay hooked to civi.afform.submit at a POSITIVE priority.
     * At zero or below it runs after core's processGenericEntity(), by which
     * point the contact has already been saved and removing its id changes
     * nothing — the rename would silently overwrite the outgoing person's record
     * instead of creating a new contact.
     */
    public function testPreProcessRunsBeforeTheSave(): void
    {
        $this->assertMatchesRegularExpression(
            "/\[\s*['\"]onClientRepPreProcess['\"]\s*,\s*([1-9]\d*)\s*,?\s*\]/",
            $this->source(),
            'onClientRepPreProcess must stay registered at a positive priority, so it runs '
            . 'before Afform saves the contact.'
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
            'getCurrentClientRepId',
            $this->clientRepPreProcessBody(),
            'The current client rep must still be read from the case role, not from the '
            . 'submitted record id, which core ContactDedupe may already have rewritten.'
        );
        $this->assertStringContainsString(
            "addWhere('near_relation:name', '=', self::CLIENT_REP_REL_NAME)",
            $this->source(),
            'getCurrentClientRepId() must still resolve the rep through the case role.'
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

        foreach (['afformMASProjectDefinitionVC', 'afformProjectCloseVCFeedback'] as $form) {
            $this->assertStringContainsString(
                $form,
                $block,
                "$form must stay in VC_CLIENT_REP_FORMS or its client-rep fieldset does nothing."
            );
        }
    }
}
