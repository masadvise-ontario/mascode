<?php

namespace Civi\Mascode\Test\Unit\Event;

use Civi\Mascode\Test\TestCase;

/**
 * A CI-visible tripwire for the two staff-copy methods that CANNOT be unit
 * tested: AfformSubmitSubscriber::buildCaseUrl() and resolveSubmissionContext().
 *
 * WHAT THIS IS AND IS NOT
 * This is NOT a behavioural test — it reads AfformSubmitSubscriber as TEXT. It
 * cannot be anything else: one method calls CRM_Utils_System::url() and the
 * other issues API4 queries, neither of which exists in CI, where the pipeline
 * installs Composer packages only and no CiviCRM. The behavioural proof is
 * tests/Unit/Submission/StaffCopyIdentificationTest.php, which runs the real
 * formatter — but the formatter is the half that was already correct.
 *
 * WHY IT EARNS ITS PLACE, on the same terms as ClientRepWiringTest and
 * RcsChaseOnCreateWiringTest: PR #31's review found TWO defects, and BOTH were
 * in these two methods, precisely because they had no coverage of any kind
 * while the pure formatter beside them had fourteen tests. Every regression
 * pinned below is SILENT — the email still sends, still looks right, and is
 * simply wrong about which client it concerns or links somewhere a staff member
 * cannot use.
 *
 * THE FOUR SILENT FAILURES PINNED HERE
 *   1. forceBackend dropped from the url() call. CRM_Utils_System_WordPress
 *      picks the backend base only when `is_admin() || $forceBackend`, and this
 *      code runs during an ANONYMOUS public submission where is_admin() is
 *      FALSE. The link silently becomes a front-end ?civiwp= route.
 *   2. htmlize left at its TRUE default. The URL arrives entity-encoded, so the
 *      HTML part double-escapes it (the link 404s) and the text part carries a
 *      literal "&amp;" that breaks on paste.
 *   3. The is_deleted filter dropped from the client query. API4 applies its
 *      default only to a get's BASE entity, and CaseContact has no is_deleted
 *      field, so a joined Contact is not filtered for free. A trashed
 *      organization then resolves AND suppresses the organization_id fallback,
 *      so the staff copy names a dead contact in preference to a live one.
 *      185 cases in the dev clone have exactly this shape.
 *   4. The ordering dropped from the client query. A case may carry more than
 *      one client, and an unordered pick can name a different organization on
 *      different rows.
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

        // The seven positional arguments, in order: path, query, absolute,
        // fragment, htmlize, frontend, forceBackend. Asserted as the whole
        // sequence rather than as "contains true" — the argument that matters
        // is identified by its POSITION, and a partial match would pass on a
        // call that had them in the wrong order.
        $this->assertStringContainsString(
            "CRM_Utils_System::url('civicrm/contact/view/case',\$query,true,null,false,false,true)",
            $body,
            'buildCaseUrl() must pass htmlize = FALSE (5th) and forceBackend = TRUE (7th)'
        );
    }

    public function testClientQueryKnowsWhichContactsAreDeletedAndIsOrdered(): void
    {
        $body = $this->normalise($this->methodBody('resolveSubmissionContext'));

        // Deletion state must be SELECTED — the three-rung preference sorts on
        // it in PHP. API4 will not supply it: its is_deleted default applies
        // only to a get's BASE entity, and CaseContact has no such field, so a
        // joined Contact is never filtered or flagged for free.
        $this->assertStringContainsString("'contact_id.is_deleted'", $body);
        $this->assertStringContainsString("\$isTrashed=!empty(\$client['contact_id.is_deleted'])", $body);
        $this->assertStringContainsString("addOrderBy('id','ASC')", $body);
    }

    public function testOrganizationFallbackAlsoExcludesDeletedContacts(): void
    {
        $body = $this->normalise($this->methodBody('resolveSubmissionContext'));

        // A separate reason from the query above, and separately assertable:
        // setDefaultWhereClause() skips the is_deleted default entirely for a
        // fetch by unique identifier, which a get by id is.
        $this->assertStringContainsString(
            "addWhere('id','=',\$submissionData['organization_id'])->addWhere('is_deleted','=',false)",
            $body,
            'the organization_id fallback is a get-by-id, so API4 applies no is_deleted default'
        );
    }

    public function testOrganizationIsStillWhatNamesTheClient(): void
    {
        $body = $this->normalise($this->methodBody('resolveSubmissionContext'));

        // Anchored to the CONSEQUENT, not to the token "==='Organization'".
        // The token form passed a mutation that inverted the branch to
        // `if (… === 'Organization') { continue; }` and named the submitting
        // INDIVIDUAL instead — the precise defect this feature removes.
        $this->assertStringContainsString(
            "\$isOrg=(\$client['contact_id.contact_type']??'')==='Organization'",
            $body
        );
        $this->assertStringContainsString(
            "if(!\$isTrashed&&\$isOrg&&\$liveOrg===null){\$liveOrg=\$client;}",
            $body
        );
        $this->assertStringContainsString(
            "if(\$liveOrg!==null){\$context['client_name']=",
            $body,
            'a live Organization client must be what supplies the displayed name'
        );
    }

    public function testCidIsNeverDisplacedByAContactOffTheCase(): void
    {
        $body = $this->normalise($this->methodBody('resolveSubmissionContext'));

        // organization_id need not be a client of this case. A cid that is not
        // on the case yields a link the case tab cannot render, so it must only
        // ever fill a cid that is still empty.
        $this->assertStringContainsString(
            "if(\$org&&!\$context['client_id']){\$context['client_id']=(int)\$submissionData['organization_id'];}",
            $body
        );
    }

    public function testIdentificationCannotCostTheStaffCopy(): void
    {
        $body = $this->normalise($this->methodBody('sendConfirmationEmail'));

        // Anchored to the WHOLE guard, not to the token "catch(\Throwable".
        // That token form passed a mutation which narrowed the INNER catch to
        // \Exception while widening the outer one to \Throwable — leaving the
        // body still containing the token and M3 completely undone.
        $this->assertStringContainsString(
            "catch(\\Throwable\$e){\\Civi::log()->warning('AfformSubmitSubscriber.php"
            . "-Staffcopyidentificationfailed",
            $body,
            'the identification block must be guarded by its OWN catch (\Throwable)'
        );

        // Pre-seeded, so no path can leave it undefined.
        $this->assertStringContainsString(
            "\$identification=['subject_suffix'=>'','html'=>'','text'=>'']",
            $body
        );

        // The pre-existing outer handler must still be \Exception-scoped; if a
        // mutation moved \Throwable outwards instead of inwards, this catches it.
        $this->assertStringContainsString("catch(\\Exception\$e){", $body);

        // Both sends stay OUTSIDE the new guard, so a real mail failure is
        // never swallowed by it.
        $this->assertStringContainsString(
            "\\CRM_Utils_Mail::send(\$adminMailParams);",
            $body
        );
    }
}
