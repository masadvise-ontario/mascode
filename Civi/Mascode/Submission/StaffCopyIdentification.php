<?php

declare(strict_types=1);

namespace Civi\Mascode\Submission;

/**
 * Renders the identification block that distinguishes the MAS staff copy of a
 * form-submission confirmation from the client's copy.
 *
 * WHY THIS EXISTS
 *
 * AfformSubmitSubscriber sends one rendered confirmation to two recipients: the
 * person who filled the form, and info@masadvise.org. The message is addressed
 * to the submitter and carries only their own name, which is sufficient for
 * them and insufficient for the Client Services Manager — "Dear <first last>"
 * names neither the client organization nor the project, so staff receiving a
 * completed project-close form were looking the individual up in CiviCRM to
 * find out which client had responded.
 *
 * This class produces the prefix that fixes that. The client's copy is
 * deliberately left exactly as it is: it is MAS-branded, client-facing and
 * signed, and the client already knows who they are.
 *
 * Deliberately free of every CiviCRM dependency — no API4, no CRM_Utils_System,
 * no \Civi. It takes an already-resolved context and an already-built URL and
 * returns strings. That is what lets the escaping, the graceful degradation and
 * the subject-suffix rule be pinned by a unit test that runs in CI, where no
 * CiviCRM is bootstrapped.
 */
class StaffCopyIdentification
{
    /**
     * Build the subject suffix and the HTML/text identification block.
     *
     * The subject is APPENDED to, never replaced. "MAS Form Submission
     * Confirmation" is currently the entire subject line of all seven MAS
     * forms, so any Outlook rule filing these at MAS is matching on that exact
     * string. A suffix keeps a "contains" rule working; a rewritten subject
     * would silently stop matching and file nothing, which is a worse failure
     * than the one being fixed because it is invisible.
     *
     * @param array{client_name?: string, case_subject?: string, form_title?: string} $context
     *   Resolved by AfformSubmitSubscriber::resolveSubmissionContext().
     * @param string $submittedBy
     *   Display name of the person who filled the form.
     * @param string $caseUrl
     *   Absolute, RAW (un-entity-encoded) CiviCRM case URL, or '' when the
     *   submission has no case. Raw because this method escapes it for the HTML
     *   part and emits it verbatim in the plain-text part; a pre-encoded URL
     *   would be double-escaped in one and broken on paste in the other.
     *
     * @return array{subject_suffix: string, html: string, text: string}
     *   All three are '' when nothing could be identified, so the caller sends
     *   today's unmodified message rather than an empty table above it.
     */
    public function render(array $context, string $submittedBy, string $caseUrl = ''): array
    {
        $clientName = $this->oneLine($context['client_name'] ?? '');
        $caseSubject = $this->oneLine($context['case_subject'] ?? '');
        $formTitle = $this->oneLine($context['form_title'] ?? '');
        $submittedBy = $this->oneLine($submittedBy);

        $rows = array_filter([
            'Client' => $clientName,
            'Project' => $caseSubject,
            'Form' => $formTitle,
            'Submitted by' => $submittedBy,
        ], static function ($value) {
            return $value !== '';
        });

        // "Submitted by" ALONE is not identification: the message already opens
        // "Dear <that same name>". The block has to add something the greeting
        // does not, or it is noise on every staff copy.
        $identifying = array_intersect_key($rows, array_flip(['Client', 'Project', 'Form']));
        if (!$identifying) {
            return ['subject_suffix' => '', 'html' => '', 'text' => ''];
        }

        return [
            'subject_suffix' => $this->subjectSuffix($clientName, $caseSubject),
            'html' => $this->htmlBlock($rows, $caseUrl),
            'text' => $this->textBlock($rows, $caseUrl),
        ];
    }

    /**
     * Collapse a value to a single line before it is used anywhere.
     *
     * Organization and contact names are free text, and on the public RCS form
     * they are typed by an anonymous submitter — so an embedded CR/LF is
     * attacker-reachable. It reaches an email SUBJECT (where CR/LF is the
     * header-injection primitive) and the plain-text block (where it could
     * forge an extra "Label: value" row).
     *
     * PEAR's Mail::_sanitizeHeaders() does strip CR/LF downstream, so this is
     * defence in depth rather than the only guard — but a formatter that hands
     * a multi-line string to a header field is relying on someone else to be
     * careful, and the truncation that results is a real (if cosmetic) bug in
     * its own right.
     *
     * The class is widened to every C0 control plus DEL, not just CR/LF/TAB:
     * only CRLF is a header-injection primitive, but a NUL can truncate a
     * header in some MTAs and the rest simply mangle the subject.
     *
     * NOT the /u modifier, deliberately. UTF-8 lead and continuation bytes are
     * all >= 0x80, so byte-wise matching of ASCII controls cannot split a
     * multibyte character — while /u makes preg_replace() return NULL on
     * invalid UTF-8, which the (string) cast would silently turn into an empty
     * value. Byte-safe is the stronger choice here, not the lazier one.
     *
     * @param mixed $value
     */
    private function oneLine($value): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) $value));
    }

    /**
     * Client name and MAS code only — the two things staff quote to each other.
     *
     * The descriptive remainder of a case subject ("P29001: Governance review")
     * stays in the body; putting it in the subject would push the client name
     * past the width an inbox list shows, which is the one place the fix has to
     * work without opening the message.
     */
    private function subjectSuffix(string $clientName, string $caseSubject): string
    {
        $caseCode = '';
        // Matches both MAS code families: R##### service requests, P##### projects.
        if ($caseSubject !== '' && preg_match('/^([A-Z]\d{5})\b/', $caseSubject, $m) === 1) {
            $caseCode = $m[1];
        }

        $parts = array_values(array_filter([$clientName, $caseCode], static function ($value) {
            return $value !== '';
        }));

        return $parts ? ' - ' . implode(' - ', $parts) : '';
    }

    /**
     * @param array<string,string> $rows
     */
    private function htmlBlock(array $rows, string $caseUrl): string
    {
        // Table rather than styled divs, and inline styles rather than a class:
        // Outlook's word-processor renderer is what these are read in.
        //
        // ENT_SUBSTITUTE: without it htmlspecialchars() returns '' on invalid
        // UTF-8, which would render a labelled row with an EMPTY value — the
        // exact thing this class's degradation rule exists to prevent.
        // Unreachable while CiviCRM stores utf8mb4; one token to make it so.
        $html = '<table style="border-collapse:collapse;font-family:Calibri,sans-serif;'
            . 'font-size:11pt;margin:0 0 16px 0;">';
        foreach ($rows as $label => $value) {
            $html .= '<tr>'
                . '<td style="padding:2px 12px 2px 0;color:#666666;vertical-align:top;'
                . 'white-space:nowrap;">'
                . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</td>'
                . '<td style="padding:2px 0;"><strong>'
                . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</strong></td>'
                . '</tr>';
        }
        $html .= '</table>';

        if ($caseUrl !== '') {
            $escaped = htmlspecialchars($caseUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html .= '<p style="font-family:Calibri,sans-serif;font-size:11pt;margin:0 0 16px 0;">'
                . '<a href="' . $escaped . '">View this case in CiviCRM</a></p>';
        }

        return $html . '<hr style="border:none;border-top:1px solid #dddddd;margin:0 0 24px 0;">';
    }

    /**
     * @param array<string,string> $rows
     */
    private function textBlock(array $rows, string $caseUrl): string
    {
        $text = '';
        foreach ($rows as $label => $value) {
            $text .= $label . ': ' . $value . "\n";
        }
        if ($caseUrl !== '') {
            $text .= 'Case: ' . $caseUrl . "\n";
        }

        return $text . "\n";
    }
}
