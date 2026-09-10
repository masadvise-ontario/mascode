<?php

namespace Civi\Mascode\Test\Unit\Submission;

use Civi\Mascode\Submission\StaffCopyIdentification;
use Civi\Mascode\Test\TestCase;

/**
 * Behavioural tests for the staff-copy identification block.
 *
 * Unlike the wiring tripwires elsewhere in tests/Unit, this executes the real
 * class: StaffCopyIdentification touches no API4, no CRM_Utils_System and no
 * \Civi, so it loads and runs in CI where no CiviCRM is bootstrapped — the same
 * terms tests/Unit/Security/AfformArgPolicyTest.php runs on, and the reason the
 * formatting was extracted out of AfformSubmitSubscriber rather than left as a
 * protected method on a class that extends AutoSubscriber.
 *
 * What is worth pinning here, in order of how quietly it would fail:
 *
 *   1. GRACEFUL DEGRADATION. A context that resolves nothing must return three
 *      empty strings, so the caller sends today's unmodified confirmation. The
 *      failure mode being avoided is an email that reaches the client's own
 *      inbox carrying an empty "Client:" table — worse than the defect.
 *   2. ESCAPING. Client names are free text typed by staff and by clients.
 *      "Smith & Jones <Consulting>" must not break the message or inject markup.
 *   3. THE SUBJECT IS A SUFFIX. Appended, never replaced, because MAS's Outlook
 *      rules match on the current subject string. A test that only asserted the
 *      suffix appeared somewhere would pass on a rewritten subject too, so the
 *      caller-side composition is asserted explicitly.
 *   4. CODE EXTRACTION. Only a real MAS code (R##### / P#####) reaches the
 *      subject; a case subject without one must not contribute a fragment.
 *
 * @covers \Civi\Mascode\Submission\StaffCopyIdentification
 */
class StaffCopyIdentificationTest extends TestCase
{
    private StaffCopyIdentification $identification;

    protected function setUp(): void
    {
        parent::setUp();
        $this->identification = new StaffCopyIdentification();
    }

    private function projectContext(): array
    {
        return [
            'client_name' => 'Example Foundation',
            'case_subject' => 'P29001: Governance review',
            'form_title' => 'Project Close - Client Feedback',
        ];
    }

    public function testIdentifiesTheClientOrganizationNotJustTheSubmitter(): void
    {
        $result = $this->identification->render($this->projectContext(), 'Riley Chen');

        // The whole point: the organization is present, which is what the
        // submitter's name alone never told the Client Services Manager.
        $this->assertStringContainsString('Example Foundation', $result['html']);
        // The row, not the bare word: the form title "Project Close - Client
        // Feedback" contains "Client", so asserting the word alone would pass
        // with the Client row deleted entirely.
        $this->assertMatchesRegularExpression(
            '#>Client</td>\s*<td[^>]*><strong>Example Foundation</strong>#',
            $result['html']
        );
        $this->assertStringContainsString('P29001: Governance review', $result['html']);
        $this->assertStringContainsString('Project Close - Client Feedback', $result['html']);
        $this->assertStringContainsString('Riley Chen', $result['html']);
    }

    public function testSubjectSuffixCarriesClientAndMasCodeOnly(): void
    {
        $result = $this->identification->render($this->projectContext(), 'Riley Chen');

        $this->assertSame(' - Example Foundation - P29001', $result['subject_suffix']);
        // The descriptive remainder belongs in the body, not the inbox list.
        $this->assertStringNotContainsString('Governance review', $result['subject_suffix']);
    }

    public function testSubjectIsAppendedToTheExistingSubjectNotReplacingIt(): void
    {
        $result = $this->identification->render($this->projectContext(), 'Riley Chen');

        // Composed the way AfformSubmitSubscriber composes it. Asserted here
        // because MAS Outlook rules match on the original string; a suffix that
        // silently became a replacement would stop filing these mails.
        $composed = 'MAS Form Submission Confirmation' . $result['subject_suffix'];
        $this->assertStringStartsWith('MAS Form Submission Confirmation', $composed);
        $this->assertSame(
            'MAS Form Submission Confirmation - Example Foundation - P29001',
            $composed
        );
    }

    public function testServiceRequestCodesAreRecognisedToo(): void
    {
        $context = $this->projectContext();
        $context['case_subject'] = 'R29001: Facilitation (strategic planning)';

        $result = $this->identification->render($context, 'Someone');

        $this->assertSame(' - Example Foundation - R29001', $result['subject_suffix']);
    }

    public function testCaseSubjectWithoutAMasCodeContributesNothingToTheSubject(): void
    {
        $context = $this->projectContext();
        $context['case_subject'] = 'Strategic planning engagement';

        $result = $this->identification->render($context, 'Someone');

        $this->assertSame(' - Example Foundation', $result['subject_suffix']);
        // …but the full subject is still in the body, where there is room.
        $this->assertStringContainsString('Strategic planning engagement', $result['html']);
    }

    public function testSurveyWithNoCaseStillIdentifiesTheOrganization(): void
    {
        $result = $this->identification->render([
            'client_name' => 'Example Foundation',
            'case_subject' => '',
            'form_title' => 'Short Self Assessment Survey',
        ], 'Riley Chen');

        $this->assertSame(' - Example Foundation', $result['subject_suffix']);
        $this->assertStringContainsString('Example Foundation', $result['html']);
        // No case means no Project ROW and no case link. Asserted as the row,
        // not as the absent word — three of the seven MAS form titles contain
        // "Project", so the word form would fail spuriously on those.
        $this->assertDoesNotMatchRegularExpression('#>Project</td>#', $result['html']);
        $this->assertStringNotContainsString('View this case in CiviCRM', $result['html']);
    }

    public function testNothingResolvedDegradesToTheUnmodifiedMessage(): void
    {
        $result = $this->identification->render([], '');

        $this->assertSame('', $result['subject_suffix']);
        $this->assertSame('', $result['html']);
        $this->assertSame('', $result['text']);
    }

    public function testWhitespaceOnlyValuesCountAsAbsent(): void
    {
        $result = $this->identification->render([
            'client_name' => '   ',
            'case_subject' => '',
            'form_title' => '',
        ], '  ');

        $this->assertSame('', $result['html']);
        $this->assertSame('', $result['subject_suffix']);
    }

    public function testSubmitterNameAloneIsNotEnoughToEarnABlock(): void
    {
        // The confirmation already opens "Dear Riley Chen". A block whose
        // only row repeats that name adds nothing and would appear on every
        // staff copy where resolution failed.
        $result = $this->identification->render([
            'client_name' => '',
            'case_subject' => '',
            'form_title' => '',
        ], 'Riley Chen');

        $this->assertSame('', $result['html']);
        $this->assertSame('', $result['text']);
        $this->assertSame('', $result['subject_suffix']);
    }

    public function testFormTitleAloneDoesEarnABlock(): void
    {
        // Which of the seven MAS forms arrived is information the greeting
        // does not carry, so it is worth a block on its own.
        $result = $this->identification->render([
            'form_title' => 'Short Self Assessment Survey',
        ], 'Riley Chen');

        $this->assertStringContainsString('Short Self Assessment Survey', $result['html']);
        $this->assertStringContainsString('Riley Chen', $result['html']);
        // No client resolved, so nothing useful to add to the subject.
        $this->assertSame('', $result['subject_suffix']);
    }

    public function testClientNameIsHtmlEscaped(): void
    {
        $context = $this->projectContext();
        $context['client_name'] = 'Smith & Jones <Consulting> "Ltd"';

        $result = $this->identification->render($context, 'Someone');

        $this->assertStringContainsString('Smith &amp; Jones &lt;Consulting&gt;', $result['html']);
        $this->assertStringNotContainsString('<Consulting>', $result['html']);
        // The plain-text part is not markup and must NOT be escaped.
        $this->assertStringContainsString('Smith & Jones <Consulting> "Ltd"', $result['text']);
        // The subject line is a header value, likewise not markup.
        $this->assertStringContainsString('Smith & Jones <Consulting> "Ltd"', $result['subject_suffix']);
    }

    public function testAVeryLongClientNameIsCappedInTheSubjectOnly(): void
    {
        $long = str_repeat('Ontario Community Health Partnership ', 4);
        $result = $this->identification->render([
            'client_name' => $long,
            'case_subject' => 'P29001: Governance review',
            'form_title' => 'Project Close - Client Feedback',
        ], 'Riley Chen');

        // The MAS code must survive — it is the other half of what makes the
        // subject identifiable in an inbox list.
        $this->assertStringEndsWith(' - P29001', $result['subject_suffix']);
        $this->assertLessThan(80, mb_strlen($result['subject_suffix']));
        $this->assertStringContainsString('…', $result['subject_suffix']);

        // The body has room, so it is NOT capped.
        $this->assertStringContainsString(trim($long), $result['html']);
    }

    public function testAMultibyteNameIsCappedWithoutSplittingACharacter(): void
    {
        $result = $this->identification->render([
            'client_name' => str_repeat('Société Générale Montréal ', 4),
            'case_subject' => 'P29001: x',
        ], 'Riley Chen');

        // Valid UTF-8 in, valid UTF-8 out — mb_strimwidth cannot split a
        // character the way a byte-wise substr would.
        $this->assertSame(
            $result['subject_suffix'],
            mb_convert_encoding($result['subject_suffix'], 'UTF-8', 'UTF-8')
        );
    }

    public function testCarriageReturnsAreStrippedFromEveryPart(): void
    {
        // Free text from an anonymous public submitter. CR/LF in an email
        // subject is the header-injection primitive; in the text block it could
        // forge an extra "Label: value" row.
        $result = $this->identification->render([
            'client_name' => "Acme\r\nBcc: attacker@example.com",
            'case_subject' => "P29001: Line one\nFake: value",
            'form_title' => 'Project Close - Client Feedback',
        ], "Riley\nChen");

        foreach (['subject_suffix', 'html', 'text'] as $part) {
            $this->assertStringNotContainsString("\r", $result[$part], $part . ' kept a CR');
        }
        $this->assertStringNotContainsString("\n", $result['subject_suffix']);
        $this->assertSame(
            ' - Acme Bcc: attacker@example.com - P29001',
            $result['subject_suffix']
        );
        // Exactly four rows in the text block — the forged one did not survive.
        $this->assertSame(4, substr_count(rtrim($result['text']), "\n") + 1);
    }

    public function testCaseUrlRendersAsAnEscapedLink(): void
    {
        // The backend shape AfformSubmitSubscriber::buildCaseUrl() actually
        // produces, copied from a live `cv scr` run rather than written from
        // memory: wp-admin because it passes forceBackend = TRUE, and the q
        // path rawurlencoded because CRM_Utils_System::url() encodes it.
        //
        // Verbatim on purpose. This fixture is the clearest record of the
        // shape the mail carries, and TWO successive versions of it described
        // a URL the code does not produce — first the front-end route, then
        // an unencoded path. Both were caught in review, not here.
        $url = 'https://www.masadvise.org/wp-admin/admin.php?page=CiviCRM'
            . '&q=civicrm%2Fcontact%2Fview%2Fcase&reset=1&action=view&id=99001&cid=99002';

        $result = $this->identification->render($this->projectContext(), 'Someone', $url);

        $this->assertStringContainsString('View this case in CiviCRM', $result['html']);
        // Ampersands in the query string must be entity-encoded inside href…
        $this->assertStringContainsString('&amp;q=civicrm%2Fcontact%2Fview%2Fcase', $result['html']);
        // …exactly once. CRM_Utils_System::url() entity-encodes by default, so
        // the caller must ask for a raw URL; if it ever stops doing so this
        // becomes "&amp;amp;" and every link in the block 404s.
        $this->assertStringNotContainsString('&amp;amp;', $result['html']);
        // The text part carries the URL raw, so it stays clickable in a plain
        // reader and copy-pastes correctly.
        $this->assertStringContainsString('Case: ' . $url, $result['text']);
    }

    public function testTextBlockMirrorsTheRowsAndEndsWithABlankLine(): void
    {
        $result = $this->identification->render($this->projectContext(), 'Riley Chen');

        $this->assertStringContainsString("Client: Example Foundation\n", $result['text']);
        $this->assertStringContainsString("Project: P29001: Governance review\n", $result['text']);
        $this->assertStringContainsString("Form: Project Close - Client Feedback\n", $result['text']);
        $this->assertStringContainsString("Submitted by: Riley Chen\n", $result['text']);
        // Separates the block from the template body it is prefixed to.
        $this->assertStringEndsWith("\n\n", $result['text']);
    }

    public function testHtmlBlockEndsWithADividerSoItReadsAsAPrefix(): void
    {
        $result = $this->identification->render($this->projectContext(), 'Someone');

        $this->assertStringEndsWith(
            '<hr style="border:none;border-top:1px solid #dddddd;margin:0 0 24px 0;">',
            $result['html']
        );
    }
}
