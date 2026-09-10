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

        $delimiters = [
            "\n    public function ", "\n    protected function ", "\n    private function ",
            "\n    public static function ", "\n    protected static function ",
            "\n    private static function ",
        ];
        $end = strlen(self::$source);
        foreach ($delimiters as $delimiter) {
            $next = strpos(self::$source, $delimiter, $start + 1);
            if ($next !== false && $next < $end) {
                $end = $next;
            }
        }
        $this->assertLessThan(
            strlen(self::$source),
            $end,
            "methodBody({$name}) found no following method — it would run to EOF and "
            . 'assert against unrelated code. Fix the delimiter list.'
        );

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
        // Guards the helper itself: buildCaseUrl() must not drag in the next
        // method's text. If this fails, every other assertion here is hollow.
        $body = $this->methodBody('buildCaseUrl');

        $this->assertStringContainsString('CRM_Utils_System::url', $body);
        $this->assertStringNotContainsString('sendConfirmationEmail', $body);
        $this->assertStringNotContainsString('resolveSubmissionContext', $body);
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

    public function testClientQueryExcludesDeletedContactsAndIsOrdered(): void
    {
        $body = $this->normalise($this->methodBody('resolveSubmissionContext'));

        $this->assertStringContainsString(
            "addWhere('contact_id.is_deleted','=',false)",
            $body,
            'the client query must filter deleted contacts explicitly — API4 will not'
        );
        $this->assertStringContainsString(
            "addOrderBy('id','ASC')",
            $body,
            'the client query must be ordered — a case may have several clients'
        );
    }

    public function testOrganizationIsStillWhatNamesTheClient(): void
    {
        $body = $this->normalise($this->methodBody('resolveSubmissionContext'));

        // The whole point of the feature: the ORGANIZATION names the client,
        // not the submitting individual.
        $this->assertStringContainsString("==='Organization'", $body);
    }

    public function testIdentificationCannotCostTheStaffCopy(): void
    {
        $body = $this->normalise($this->methodBody('sendConfirmationEmail'));

        // The three identification calls sit between the two sends. They must
        // be guarded with Throwable, not Exception, and must pre-seed an empty
        // block so a failure degrades to an unlabelled email rather than none.
        $this->assertStringContainsString("catch(\\Throwable", $body);
        $this->assertStringContainsString(
            "\$identification=['subject_suffix'=>'','html'=>'','text'=>'']",
            $body
        );
    }
}
