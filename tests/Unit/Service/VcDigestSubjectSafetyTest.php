<?php

namespace Civi\Mascode\Test\Unit\Service;

use Civi\Mascode\Digest\DigestRowRenderer;
use Civi\Mascode\Service\VcDigestMailer;
use Civi\Mascode\Test\TestCase;

/**
 * The single most expensive mistake available in the digest, and the rows it
 * renders.
 *
 * WHY THE SUBJECT IS A SECURITY-GRADE CONCERN.
 * ProjectLifecycleStatusSubscriber fires on *Sent Automated Email* activities —
 * exactly what VcDigestMailer writes, one per project — and its
 * matchTransition() does `str_contains($activitySubject, $prefix)` against the
 * STATIC part of each lifecycle template's subject. On this install those
 * prefixes are the bare strings "Project Completion" and "MAS Project Signoff".
 *
 * So a digest subject containing either string would advance EVERY listed
 * project to an awaiting-form status the moment the digest went out — 132
 * projects on the 2026-09-21 clone — silently, with no error, behind a
 * perfectly normal-looking email. The chases would arm, the Ops dashboard would
 * fill, and the only symptom would be a status change nobody made.
 *
 * It is a substring match, not a prefix match, so "our Project Completion
 * process" is as fatal as a subject that starts with it. That is the property
 * these tests exist to keep visible.
 *
 * @coversNothing
 */
class VcDigestSubjectSafetyTest extends TestCase
{
    /**
     * The live prefixes as measured on dev, 2026-09-22.
     *
     * Hard-coded HERE only — the mailer reads them from the live templates at
     * run time, precisely so a subject renamed in the CiviCRM UI cannot
     * silently stop being guarded. That rename is not hypothetical: it is what
     * broke the client transition on production in September.
     */
    private const LIVE_PREFIXES = [
        'MAS Project Completion - VC Template' => 'Project Completion',
        'MAS Project Signoff - Client Template' => 'MAS Project Signoff',
        'mas_lifecycle_pd_authorize__client' => 'Please review and authorize your MAS Project Definition (',
    ];

    public function testTheShippedActivitySubjectIsSafe(): void
    {
        $subject = VcDigestMailer::activitySubject('2026-09');

        $this->assertNull(
            VcDigestMailer::subjectTriggersTransition($subject, self::LIVE_PREFIXES),
            "The per-project activity subject '{$subject}' contains a lifecycle transition prefix. "
            . 'Sending the digest would advance every project in it.'
        );
    }

    public function testTheShippedTemplateSubjectIsSafe(): void
    {
        $declarations = include __DIR__
            . '/../../../Civi/Mascode/Managed/MessageTemplate_mas_vc_monthly_digest__vc.mgd.php';
        $subject = $declarations[0]['params']['values']['msg_subject'];

        $this->assertNull(
            VcDigestMailer::subjectTriggersTransition($subject, self::LIVE_PREFIXES),
            "The digest template subject '{$subject}' contains a lifecycle transition prefix."
        );
    }

    /**
     * A substring anywhere is a hit. This is the property people get wrong.
     */
    public function testATransitionStringAnywhereInTheSubjectIsCaught(): void
    {
        foreach ([
            'Project Completion',
            'Your MAS projects: Project Completion check-in',
            'Reminder about MAS Project Signoff forms',
            'MAS Project Signoff',
        ] as $dangerous) {
            $this->assertNotNull(
                VcDigestMailer::subjectTriggersTransition($dangerous, self::LIVE_PREFIXES),
                "'{$dangerous}' must be refused: it would advance every project in the digest."
            );
        }
    }

    public function testAnEmptyPrefixDoesNotMatchEverything(): void
    {
        $this->assertNull(
            VcDigestMailer::subjectTriggersTransition('anything at all', ['Some Template' => '']),
            'A template with no static subject part has nothing to match on, and must not match all.'
        );
    }

    // --- The WIRING, which is what review broke four ways ---------------

    /**
     * The guard must be CALLED, on both subjects, before anything is sent.
     *
     * REVIEW BROKE THIS FOUR WAYS WITHOUT THE SUITE GOING RED. Every mutation
     * the first version checked exercised the pure predicate; nothing
     * exercised the call site or the list it is handed. Making
     * `assertSubjectCannotTriggerATransition()` a no-op, or deleting either of
     * its two call sites, left every test green — on the highest-consequence
     * guard in this feature.
     *
     * Asserted over source because `VcDigestMailer::send()` is an API4-and-mail
     * path CI cannot execute. Weak, and said so — but a guard whose wiring
     * nothing holds is the shape this epic keeps finding.
     */
    public function testTheGuardIsActuallyCalledOnBothSubjects(): void
    {
        $code = $this->mailerSource();

        $this->assertStringContainsString(
            'self::assertSubjectCannotTriggerATransition($subject);',
            $code,
            'The rendered EMAIL subject must be checked — it can carry a transition string through a token.'
        );
        $this->assertStringContainsString(
            'self::assertSubjectCannotTriggerATransition($activitySubject);',
            $code,
            'The per-project ACTIVITY subject must be checked: that is the value '
            . 'ProjectLifecycleStatusSubscriber actually matches on.'
        );
        $this->assertMatchesRegularExpression(
            '/assertSubjectCannotTriggerATransition\(\$activitySubject\);\s*
\s*self::sendMail\(/',
            $code,
            'Both checks must precede sendMail(). A guard that runs after the email has left is decoration.'
        );
        $this->assertStringNotContainsString(
            '$hit = null;',
            $code,
            'The guard must not be short-circuited.'
        );
    }

    /**
     * The guard's TEMPLATE LIST must come from the transition's owner.
     *
     * This is the half the PR originally got wrong while claiming otherwise.
     * The subject PREFIXES were read live; the set of `msg_title`s was
     * hard-coded — a private-const copy 200 lines and two directories from
     * `ProjectLifecycleStatusSubscriber::TRANSITIONS`. A `msg_title` rename is
     * exactly what broke the client transition on production in September, and
     * a copy would have gone on guarding a title nobody uses while the
     * subscriber armed on one this guard had never heard of. Adding a fourth
     * transition would have done the same, silently.
     */
    public function testThePrefixListComesFromTheTransitionOwner(): void
    {
        $code = $this->mailerSource();

        $this->assertStringContainsString(
            'ProjectLifecycleStatusSubscriber::transitionSubjectPrefixes()',
            $code,
            'The guard must ask the class that owns the transitions which templates advance a case.'
        );
        foreach ([
            "'MAS Project Completion - VC Template'",
            "'MAS Project Signoff - Client Template'",
            "'mas_lifecycle_pd_authorize__client'",
        ] as $title) {
            $this->assertStringNotContainsString(
                $title,
                $code,
                "The mailer must not carry its own copy of {$title}. TRANSITIONS is the one owner; a copy "
                . 'drifts the moment a template is renamed or a fourth transition is added.'
            );
        }
    }

    /**
     * The owner exposes the list, and computes the prefix the one way.
     */
    public function testTheOwnerExposesTheListAndOneImplementationOfThePrefixRule(): void
    {
        $owner = (string) file_get_contents(
            __DIR__ . '/../../../Civi/Mascode/Event/ProjectLifecycleStatusSubscriber.php'
        );

        $this->assertStringContainsString('public static function transitionSubjectPrefixes(): array', $owner);
        $this->assertStringContainsString('public static function transitionTemplateTitles(): array', $owner);
        // ONE implementation of the prefix computation. The first version of
        // this assertion required exactly TWO — so unifying them, which is the
        // correct fix, turned the suite red: a test that punishes the right
        // change. Review also measured that the count guarded nothing anyway,
        // since changing matchTransition()'s semantics while leaving the
        // counted literal untouched kept it green. So the count is now the
        // property (one rule), and the regex below is what holds agreement.
        $this->assertSame(
            1,
            substr_count($owner, "strpos(\$subject, '{')"),
            'The prefix rule must exist exactly once. Two copies of a substring match that must agree is '
            . 'the defect shape this codebase keeps producing.'
        );
        $this->assertStringContainsString(
            'foreach (self::transitionSubjectPrefixes() as $title => $prefix)',
            $owner,
            'matchTransition() must iterate the prefixes the accessor hands out, not recompute them.'
        );
    }

    /**
     * A digest must not be sent twice to the same VC in the same round.
     *
     * "62 volunteers getting a duplicate is not recoverable" is the spec's own
     * phrasing, on P2-1's ticket — but this is the path the P1-6 pilot uses,
     * and `deliver()` reports per-VC failures, which makes re-running the
     * natural response. Without this the re-run also re-sends to everyone who
     * succeeded.
     */
    public function testASecondSendInTheSameRoundIsRefused(): void
    {
        $code = $this->mailerSource();

        $this->assertStringContainsString(
            'self::alreadySentThisRound($vcContactId, $caseIds, $round)',
            $code,
            'send() must check before it sends.'
        );
        $this->assertMatchesRegularExpression(
            "/alreadySentThisRound.*
(.|
)*?'skipped' => true,/",
            $code,
            'A skip must report itself, so deliver() can count it apart from a fresh send.'
        );
        $this->assertMatchesRegularExpression(
            '/alreadySentThisRound\(\$vcContactId, \$caseIds, \$round\)(.|\n)*?self::sendMail\(/',
            $code,
            'The idempotency check must precede sendMail(). Checking afterwards is decoration.'
        );

        // ⚠ THE QUERY MUST CONSUME EVERY FRAGMENT. Without this, applying only
        // `$fragments[0]` is byte-for-byte the Critical defect review found —
        // no contact scoping, the same 3 of 62 VCs dropped every month — and
        // the property assertions below all stay green, because they test the
        // ingredient rather than the dish. This is the second time a defect
        // has landed in this exact unasserted seam.
        $this->assertStringContainsString(
            'foreach (self::markerFragmentsFor($vcContactId, $round) as $fragment)',
            $code,
            'EVERY fragment must be applied. Applying only the first silently removes the contact scoping.'
        );
        $this->assertStringContainsString(
            "->addWhere('details', 'LIKE', '%' . \$fragment . '%')",
            $code,
            'and each one as a LIKE against the details column.'
        );
        // Scoped to the METHOD, not the file. An earlier version asserted the
        // query appeared in `$code` — the whole class — where
        // `Activity::get(false)` occurs in other methods anyway, so an early
        // `return false;` in this one passed. Review measured it.
        $body = $this->methodBody($code, 'alreadySentThisRound');
        $this->assertStringContainsString(
            '\\Civi\\Api4\\Activity::get(false)',
            $body,
            'The check must actually query. An early return cannot coexist with a query in the same method.'
        );
        $this->assertStringContainsString(
            'foreach (self::markerFragmentsFor($vcContactId, $round) as $fragment)',
            $body,
            'and the loop must be inside it.'
        );

        // A PROPERTY, not a grep for the string I happened to type. Review
        // measured that every grep-style assertion here PASSED while
        // alreadySentThisRound() ignored its $vcContactId argument entirely —
        // the test written to protect the idempotency check certified the bug
        // as correct.
        $fragments = VcDigestMailer::markerFragmentsFor(7634, '2026-09');

        $this->assertCount(2, $fragments, 'A digest is identified by BOTH its round and its recipient.');
        $this->assertContains('"digest_round":"2026-09"', $fragments, 'The round must be part of the key.');
        $this->assertContains(
            '"recipient_contact_id":7634}',
            $fragments,
            'THE CRITICAL ONE. Without the contact id the check asks "has anyone been mailed about any of '
            . 'these cases this round", so for a project with two coordinators the second VC is skipped '
            . 'ENTIRELY — every project they hold. Measured: 3 of 62 VCs would have received nothing, '
            . 'every month, deterministically.'
        );
    }

    /**
     * The contact-id fragment must not LIKE-match a longer id.
     *
     * `"recipient_contact_id":763` is a prefix of `…:7634`, so without the
     * closing brace contact 763 would be treated as already-mailed because
     * 7634 was — silently dropping a real VC's digest. Both ids exist on the
     * 2026-09-21 clone.
     */
    public function testEveryFragmentMatchesTheMarkerThisClassActuallyWrites(): void
    {
        // Against the REAL marker, not a hand-built copy. This is what makes
        // the key order a property: `recipient_contact_id` must be last for
        // the closing-brace fragment to match, and an earlier version asserted
        // that only in a comment which claimed a test existed. It did not, and
        // review measured the reorder passing.
        $mine = VcDigestMailer::marker(9411, '2026-09');

        foreach (VcDigestMailer::markerFragmentsFor(9411, '2026-09') as $fragment) {
            $this->assertStringContainsString(
                $fragment,
                $mine,
                'Every fragment must match the marker recordOnCase() writes — key order included. '
                . 'If it does not, alreadySentThisRound() never matches and EVERY VC is re-mailed on a '
                . 're-run, which is the unrecoverable direction.'
            );
        }
    }

    /**
     * The contact-id fragment must not LIKE-match a longer id.
     *
     * `"recipient_contact_id":941` is a prefix of `…:9411`, so without the
     * closing brace the shorter contact would be treated as already-mailed
     * because the longer one was — silently dropping a real VC's digest.
     */
    public function testTheContactFragmentCannotMatchALongerId(): void
    {
        $marker = static fn(int $id): string => VcDigestMailer::marker($id, '2026-09');

        foreach (VcDigestMailer::markerFragmentsFor(941, '2026-09') as $fragment) {
            // Its own marker must match — otherwise this passes by matching
            // nothing, which is the failure mode it exists to prevent.
            $this->assertStringContainsString($fragment, $marker(941));
            if (str_starts_with($fragment, '"recipient_contact_id"')) {
                $this->assertStringNotContainsString(
                    $fragment,
                    $marker(9411),
                    'Contact 941 must not be treated as already-mailed because 9411 was.'
                );
            }
        }
    }

    /**
     * A failed activity write after a successful send is NOT a failed send.
     *
     * Reporting it as one invites the re-run that duplicates the email — the
     * unrecoverable direction. A missing case-timeline entry is a gap somebody
     * can fill.
     */
    public function testAFailedActivityWriteDoesNotMasqueradeAsAFailedSend(): void
    {
        $code = $this->mailerSource();

        $this->assertStringContainsString(
            "'activity_errors' => \$activityErrors,",
            $code,
            'Activity-write failures are reported separately from send failures.'
        );
        $this->assertMatchesRegularExpression(
            '/foreach \(\$rows as \$row\) \{\s*
\s*try \{/',
            $code,
            'Each activity write is caught individually — one failure must not discard the rest, and must '
            . 'not discard the fact that the email went.'
        );
    }

    /**
     * One method's body, so an assertion cannot be satisfied by a sibling.
     *
     * Added because an assertion scoped to the whole file passed while the
     * method it described had been replaced with an early return — the query
     * it looked for existed elsewhere in the class.
     */
    private function methodBody(string $code, string $method): string
    {
        // ⚠ THIS HELPER FAILED OPEN IN TWO WAYS, both found in review.
        //
        // It ended the slice at the next DECLARATION, which sits after that
        // method's docblock — so every slice already ran through a
        // neighbour's prose, and an assertion could be satisfied by text in
        // it. Demonstrated: alreadySentThisRound() replaced by a bare
        // `return false;`, with the required strings added to marker()'s
        // docblock, and every scoped assertion passed. The docblocks in this
        // codebase routinely quote the code they describe, so that is an
        // ordinary edit rather than a contrived one.
        //
        // And its marker list named two visibilities of five, so if neither
        // followed, it returned THE REST OF THE FILE — silently restoring the
        // unscoped behaviour it was added to fix.
        //
        // Now: comments stripped first, and the slice ends at the method's
        // own closing brace, which cannot run into anything.
        $code = $this->codeOnly($code);

        $start = strpos($code, "function {$method}(");
        $this->assertNotFalse($start, "Method {$method}() is missing.");
        $end = strpos($code, "\n    }\n", $start);
        $this->assertNotFalse(
            $end,
            "Could not find the end of {$method}(). Returning the rest of the file is the unscoped "
            . 'behaviour that let the defect through, so this fails loudly instead.'
        );
        return substr($code, $start, $end - $start);
    }

    /**
     * Source with comments removed — the same technique, and the same reason,
     * as tests/Unit/Managed/FrozenMachineNamesTest.php's codeOnly(): these
     * files necessarily DISCUSS the strings being asserted, so a raw match
     * tests the prose rather than the code.
     */
    private function codeOnly(string $source): string
    {
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $out .= str_repeat("\n", substr_count($token[1], "\n"));
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }
        return $out;
    }

    private function mailerSource(): string
    {
        $path = __DIR__ . '/../../../Civi/Mascode/Service/VcDigestMailer.php';
        $this->assertFileExists($path);
        return (string) file_get_contents($path);
    }

    // --- The rendered rows ---------------------------------------------

    public function testEachProjectGetsItsOwnLink(): void
    {
        $html = DigestRowRenderer::renderRows([
            ['case_id' => 1, 'mas_code' => 'P2501', 'subject' => 'Strategic plan', 'start_date' => '2026-01-04', 'checkin_url' => 'https://example.org/a'],
            ['case_id' => 2, 'mas_code' => 'P2502', 'subject' => 'Board review', 'start_date' => '2026-02-11', 'checkin_url' => 'https://example.org/b'],
        ]);

        $this->assertStringContainsString('https://example.org/a', $html);
        $this->assertStringContainsString('https://example.org/b', $html);
        $this->assertStringContainsString('P2501', $html);
        $this->assertSame(2, substr_count($html, '<tr>'), 'One row per project.');
        $this->assertStringContainsString('4 January 2026', $html, 'Dates are rendered for a human, not as ISO.');
    }

    /**
     * A project with no start date omits the clause rather than printing a gap.
     *
     * `start_date` is nullable, and "started —" in an email about the
     * recipient's own project reads as a system fault. Same judgement as the
     * empty-report block on the Signoff form (CHANGELOG 1.1.18).
     */
    public function testAProjectWithNoStartDateOmitsTheClause(): void
    {
        $html = DigestRowRenderer::renderRows([
            ['case_id' => 1, 'mas_code' => 'P2501', 'subject' => 'Strategic plan', 'start_date' => null, 'checkin_url' => 'https://example.org/a'],
        ]);

        $this->assertStringNotContainsString('started', $html);
        $this->assertStringContainsString('Strategic plan', $html);
    }

    /**
     * Case subjects are client-entered text and end up in an email.
     */
    public function testProjectTextIsEscaped(): void
    {
        $html = DigestRowRenderer::renderRows([
            [
                'case_id' => 1,
                'mas_code' => 'P2501',
                'subject' => 'Tom & Jerry <script>alert(1)</script>',
                'start_date' => '2026-01-04',
                'checkin_url' => 'https://example.org/a?x=1&y="2"',
            ],
        ]);

        $this->assertStringNotContainsString('<script>', $html, 'A case subject must not become markup.');
        $this->assertStringContainsString('Tom &amp; Jerry', $html);
        // The URL is ours, but a bare `"` would still break out of the href.
        $this->assertStringNotContainsString('href="https://example.org/a?x=1&y="2""', $html);
        $this->assertStringContainsString('&quot;2&quot;', $html);
    }
}
