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
        // The prefix computation appears in matchTransition() and in the
        // accessor. Two copies of a substring rule that must agree is the
        // defect shape this codebase keeps producing, so they are asserted to
        // use the same idiom.
        $this->assertSame(
            2,
            substr_count($owner, "strpos(\$subject, '{')"),
            'matchTransition() and transitionSubjectPrefixes() must compute the prefix identically.'
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
        $this->assertStringContainsString(
            '\'"digest_round":\' . json_encode($round)',
            $code,
            'The check keys on the marker this class writes, not on a subject somebody else owns.'
        );
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
