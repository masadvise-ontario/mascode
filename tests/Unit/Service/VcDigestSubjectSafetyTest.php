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
