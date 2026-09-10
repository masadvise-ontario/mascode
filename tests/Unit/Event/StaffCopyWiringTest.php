<?php

namespace Civi\Mascode\Test\Unit\Event;

use Civi\Mascode\Test\TestCase;

/**
 * A narrow source-level guard on the two staff-copy methods CI cannot execute:
 * AfformSubmitSubscriber::buildCaseUrl() and resolveSubmissionContext().
 *
 * WHAT THIS CAN AND CANNOT DO — read this before adding to it.
 *
 * It reads the subscriber as TEXT and asserts that certain expressions are
 * present. That detects DELETION and RENAMING. It does NOT verify behaviour,
 * and it cannot: round 3 of this PR's review built six wrong implementations
 * that an earlier, more confident version of this file accepted, because every
 * one of them APPENDED TO, WRAPPED or GATED the pinned text without altering
 * it. Two rounds of tightening substring assertions closed only the specific
 * mutants each round happened to imagine, which is not convergence.
 *
 * The response was not a third tightening. The decision that actually mattered
 * — which contact names the client — moved out into
 * Civi\Mascode\Submission\CaseClientResolver, a class with no CiviCRM
 * dependency, and is now covered BEHAVIOURALLY in
 * tests/Unit/Submission/CaseClientResolverTest.php. That is where the
 * preference order is guaranteed. Do not re-assert it here.
 *
 * What is left here is only what genuinely cannot be reached any other way:
 * the POSITIONAL ARGUMENTS of a CRM_Utils_System::url() call, and the presence
 * of two API4 clauses whose absence is invisible at runtime. Those are worth
 * having because each has already regressed once in this PR and each fails
 * SILENTLY — the email still sends and still looks right.
 *
 * If you find yourself wanting to assert behaviour here, that is the signal to
 * extract another seam, not to write another substring.
 *
 * @coversNothing
 */
class StaffCopyWiringTest extends TestCase
{
    private static string $source = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $path = __DIR__ . '/../../../Civi/Mascode/Event/AfformSubmitSubscriber.php';
        self::$source = (string) file_get_contents($path);
    }

    /**
     * Isolate one method's body, signature to the start of the next method.
     *
     * Every visibility/static form is listed deliberately. A sibling test in
     * this suite once omitted `protected static` and silently matched nothing,
     * so its "method body" ran from the signature to EOF and its assertions
     * passed on text from five OTHER methods. assertNotFalse below is what
     * makes that failure loud instead of silent.
     */
    private function methodBody(string $name): string
    {
        $start = strpos(self::$source, ' function ' . $name . '(');
        $this->assertNotFalse($start, "method {$name}() not found — has it been renamed?");

        // A REGEX rather than a list of literal prefixes. The list form is what
        // hollowed out a sibling tripwire on PR #30: it omitted `protected
        // static`, matched nothing, and every assertion then ran against 890
        // lines of unrelated code. This covers every legal shape — bare
        // `function`, any visibility, `static`, `final`, `abstract`, and their
        // combinations — so a new one cannot silently slip past it.
        $found = preg_match(
            '/\n    (?:(?:final|abstract)\s+)?(?:(?:public|protected|private)\s+)?(?:static\s+)?function\s/',
            self::$source,
            $m,
            PREG_OFFSET_CAPTURE,
            $start + 1
        );
        $this->assertSame(
            1,
            $found,
            "methodBody({$name}) found no following method — it would run to EOF "
            . 'and assert against unrelated code.'
        );
        $end = $m[0][1];

        // Wind back to this method's OWN closing brace, the fix
        // ClientRepWiringTest already carries and this file previously lacked.
        // Without it the extracted body carries the next method's docblock
        // prose, and an assertion can be satisfied by a COMMENT — which is the
        // PR #30 hole re-opened one level down. buildCaseUrl()'s docblock, for
        // instance, contains the literal string "resolveSubmissionContext()".
        $close = strrpos(substr(self::$source, $start, $end - $start), "\n    }");
        $this->assertNotFalse($close, "methodBody({$name}) found no closing brace");
        $end = $start + $close + strlen("\n    }");

        return substr(self::$source, $start, $end - $start);
    }

    /** Strip comments and collapse whitespace, so assertions survive reformatting. */
    private function normalise(string $php): string
    {
        $php = preg_replace('#//[^\n]*#', '', $php);
        $php = preg_replace('#/\*.*?\*/#s', '', (string) $php);

        return (string) preg_replace('/\s+/', '', (string) $php);
    }

    public function testMethodBodyHelperActuallyIsolates(): void
    {
        // Guards the helper itself. If this fails, every other assertion in
        // this file is hollow.
        $body = $this->methodBody('buildCaseUrl');

        $this->assertStringContainsString('CRM_Utils_System::url', $body);
        $this->assertStringNotContainsString('sendConfirmationEmail', $body);

        // The decisive one: buildCaseUrl()'s own docblock does NOT mention
        // resolveSubmissionContext, but the docblock of the method that
        // FOLLOWS it does. Seeing that string here would mean the body has
        // over-captured into the next method's comment — the failure mode the
        // wind-back exists to prevent.
        $this->assertStringNotContainsString('resolveSubmissionContext', $body);

        // And the body must end at its own closing brace, not mid-docblock.
        $this->assertStringEndsWith("\n    }", $body);
    }

    public function testCaseUrlIsBuiltForTheBackendAndUnencoded(): void
    {
        $body = $this->normalise($this->methodBody('buildCaseUrl'));

        // The seven positional arguments, asserted as the WHOLE returned
        // expression rather than as "contains true". Two things this buys:
        // the argument that matters is identified by POSITION, and wrapping
        // the call (htmlspecialchars(...), say — which is this PR's original
        // headline bug one level up) no longer leaves the assertion passing.
        $this->assertStringContainsString(
            "return\\CRM_Utils_System::url('civicrm/contact/view/case',\$query,true,null,false,false,true);",
            $body,
            'buildCaseUrl() must RETURN the url() call directly, with htmlize = FALSE '
            . '(5th) and forceBackend = TRUE (7th)'
        );
    }

    public function testNoCaseLinkIsBuiltWithoutACid(): void
    {
        $body = $this->normalise($this->methodBody('buildCaseUrl'));

        // CiviCRM's case view resolves the case through the contact tab a cid
        // names, so a cid-less URL does not render. Emitting one would put a
        // dead link in the staff copy rather than simply omitting it.
        $this->assertStringContainsString(
            "if(empty(\$context['case_id'])||empty(\$context['client_id'])){return'';}",
            $body
        );
    }

    public function testTheQueryStringCarriesBothIdAndCid(): void
    {
        $body = $this->normalise($this->methodBody('buildCaseUrl'));

        // Asserted as the whole assignment. Dropping `&cid=` here is invisible
        // at runtime — the guard above still passes, url() still returns a
        // plausible absolute URL, and the link simply does not resolve.
        $this->assertStringContainsString(
            "\$query='reset=1&action=view&id='.\$context['case_id'].'&cid='.\$context['client_id'];",
            $body
        );
    }

    public function testTheClientQueryFetchesWhatTheResolverNeeds(): void
    {
        $body = $this->normalise($this->methodBody('resolveSubmissionContext'));

        // CaseClientResolver sorts on deletion state, so it must be SELECTED.
        // API4 will not supply it: the is_deleted default applies only to a
        // get's BASE entity, and CaseContact has no such field, so a joined
        // Contact is never filtered or flagged for free. Absent, every client
        // reads as live and rung 3 becomes unreachable — silently.
        $this->assertStringContainsString("'contact_id.is_deleted'", $body);

        // The resolver takes the FIRST match at each rung, so row order is
        // part of its contract and MySQL guarantees none without ORDER BY.
        $this->assertStringContainsString("addOrderBy('id','ASC')", $body);

        // A limit would hide rows from the preference order. The resolver
        // cannot detect rows it was never handed.
        $this->assertStringNotContainsString('setLimit', $body);
    }

    public function testTheRung2FallbackAlsoExcludesDeletedContacts(): void
    {
        $body = $this->normalise($this->methodBody('resolveSubmissionContext'));

        // A different reason from the query above: setDefaultWhereClause()
        // skips the is_deleted default entirely for a fetch by unique
        // identifier, which a get by id is.
        $this->assertStringContainsString(
            "addWhere('id','=',\$submissionData['organization_id'])->addWhere('is_deleted','=',false)",
            $body,
            'the rung-2 fallback is a get-by-id, so API4 applies no is_deleted default'
        );
    }

    public function testIdentificationCannotCostTheStaffCopy(): void
    {
        $body = $this->normalise($this->methodBody('sendConfirmationEmail'));

        // Asserted THROUGH the catch block's closing brace, not just its
        // opening. The token form passed a mutant that narrowed the inner
        // catch while widening the outer one; the opening-plus-log form still
        // passed a mutant that appended `throw $e;` inside the block, which
        // defeats the guard entirely. Requiring `);}` means nothing can be
        // added after the log call.
        $this->assertStringContainsString(
            "catch(\\Throwable\$e){\\Civi::log()->warning("
            . "'AfformSubmitSubscriber.php-Staffcopyidentificationfailed;sendingitunlabelled',"
            . "['form_route'=>\$formRoute,'error'=>\$e->getMessage(),]);}",
            $body,
            'the identification guard must catch \Throwable and do nothing but log'
        );

        // Pre-seeded, so no path can leave it undefined.
        $this->assertStringContainsString(
            "\$identification=['subject_suffix'=>'','html'=>'','text'=>'']",
            $body
        );

        // The pre-existing outer handler must still be \Exception-scoped; if a
        // mutant moved \Throwable outwards instead of inwards, this catches it.
        $this->assertStringContainsString("catch(\\Exception\$e){", $body);

        // Both sends stay OUTSIDE the new guard, so a real mail failure is
        // never swallowed by it.
        $this->assertStringContainsString("\\CRM_Utils_Mail::send(\$adminMailParams);", $body);
    }
}
