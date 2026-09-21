<?php

namespace Civi\Mascode\Test\Unit\Managed;

use Civi\Mascode\Test\TestCase;

/**
 * Guards D17: ONE canonical donation ask, reused in three places.
 *
 * THE DECISION. The same donation wording appears on the RCS form, on the
 * Project Signoff form and in the Signoff email. D17's reasoning is that three
 * near-identical copies drifting apart is a maintenance trap — they are one
 * text with one owner.
 *
 * WHY A TEST. "One owner" was, until this file, an intention rather than a
 * mechanism: three hand-maintained copies in three unrelated files, two of them
 * editable through the FormBuilder UI, and nothing anywhere comparing them. A
 * reviewer diffed all three by hand for PR #36 and found them consistent — which
 * is the last moment anyone was going to do that. Drift here is silent and
 * client-facing: a client reads one wording on the intake form and a different
 * one at signoff, and MAS finds out from a client.
 *
 * HOW. The canonical fragments are declared ONCE below and asserted into all
 * three files. That inverts the maintenance burden the right way round: editing
 * the ask means editing this constant, and the test then names every file that
 * has not caught up.
 *
 * THE ONE DOCUMENTED EXCEPTION is the RCS form's second paragraph, and it is
 * asserted rather than merely allowed — see testTheRcsTenseExceptionIsExactlyWhereItShouldBe().
 *
 * @coversNothing
 */
class CanonicalDonationCopyTest extends TestCase
{
    private const RCS_FORM = 'ang/afformMASRCSForm.aff.html';
    private const SIGNOFF_FORM = 'ang/afformProjectCloseClientFeedback.aff.html';
    private const SIGNOFF_EMAIL = 'Civi/Mascode/Managed/MessageTemplate_MAS_Project_Close_Client_Template.body.html';

    private const ALL_THREE = [self::RCS_FORM, self::SIGNOFF_FORM, self::SIGNOFF_EMAIL];

    /**
     * The canonical text (spec D17), as it must read everywhere.
     *
     * Paragraph 2 is deliberately absent: it is the one fragment that differs,
     * because the RCS form is the INTAKE form and the canonical past tense
     * would thank a client for work that has not started.
     */
    private const CANONICAL = [
        'paragraph 1' =>
            'MAS does not charge for its services and receives no external funding. Our ability to '
            . 'help other non-profit organizations depends on donations from clients who have '
            . 'benefited from our work.',
        'paragraph 3' =>
            'Organizations choose a donation amount that reflects both their budget and the value '
            . 'they received from the consulting. Every donation, regardless of size, is sincerely '
            . 'appreciated.',
        'the lead-in to the methods' => 'There are three ways you can donate:',
        'the e-Transfer method' => 'e-Transfer to info@masadvise.org',
        'the cheque method' => 'Mail a cheque to P.O. Box 75373, Toronto RPO Leslie Street, ON, M4M 1B3',
        'the CanadaHelps method, fee stated' =>
            'Donate online through CanadaHelps — an administration fee is applied, so we prefer '
            . 'one of the first two options.',
    ];

    /** The canonical paragraph 2, used verbatim everywhere EXCEPT the RCS form. */
    private const CANONICAL_PARAGRAPH_2 =
        'We hope that you are happy with the results of the project our volunteer consultant did '
        . 'for your organization, and ask that you consider a donation to MAS. Your support enables '
        . 'us to continue providing expert consulting to other non-profit organizations that '
        . 'otherwise could not afford it.';

    /** The RCS form's future-tense substitute for it. */
    private const RCS_PARAGRAPH_2 =
        'When your project is complete, we hope you will be happy with the results, and we ask '
        . 'that you consider a donation to MAS at that point.';

    private const DONATE_URL = 'https://www.canadahelps.org/dn/9753';

    /**
     * Rendered prose, so a comparison survives the markup differing.
     *
     * The three copies are NOT markup-identical and are not meant to be: the
     * email needs a table-based button to render in Outlook, the forms use a CSS
     * class, and `<strong>` sits in different places. What must not drift is the
     * text a client reads, so everything is compared after tags and entities are
     * removed and whitespace is collapsed.
     */
    private function prose(string $relative): string
    {
        $path = __DIR__ . '/../../../' . $relative;
        $raw = (string) file_get_contents($path);
        $this->assertNotSame('', $raw, "could not read $relative");

        $text = (string) preg_replace('#<[^>]+>#', ' ', $raw);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // &mdash; and &rsquo; decode to characters that differ from the ASCII a
        // human would type; normalise the two that appear in this copy.
        $text = str_replace(["\u{2014}", "\u{2019}", "\u{00a0}"], ['—', "'", ' '], $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    public function testEveryCanonicalFragmentAppearsInAllThreePlaces(): void
    {
        foreach (self::ALL_THREE as $relative) {
            $prose = $this->prose($relative);
            foreach (self::CANONICAL as $label => $fragment) {
                $this->assertStringContainsString(
                    $fragment,
                    $prose,
                    "$relative no longer carries $label of the canonical donation ask (D17).\n"
                    . 'This text is ONE text with one owner, reused on the RCS form, the Signoff '
                    . 'form and in the Signoff email. If the wording is genuinely being changed, '
                    . 'change the constant in this test and all three files together — do not '
                    . 'edit one copy. If it moved to a template or partial, repoint this guard.'
                );
            }
        }
    }

    public function testTheDonateButtonPointsAtCanadaHelpsEverywhere(): void
    {
        foreach (self::ALL_THREE as $relative) {
            $this->assertStringContainsString(
                self::DONATE_URL,
                (string) file_get_contents(__DIR__ . '/../../../' . $relative),
                "$relative no longer links to the MAS CanadaHelps page. The donation ask without "
                . 'a working way to give is the one failure mode that costs money.'
            );
        }
    }

    public function testTheRcsTenseExceptionIsExactlyWhereItShouldBe(): void
    {
        // Asserted in BOTH directions, because the interesting failure is not
        // "the exception vanished" but "the exception spread". The RCS form is
        // the INTAKE form: verbatim, the canonical paragraph 2 would thank a
        // client for a project that has not started. The reverse is just as
        // wrong — the intake wording on the Signoff form would ask a client to
        // donate "when your project is complete" about a project that just
        // finished.
        $rcs = $this->prose(self::RCS_FORM);
        $this->assertStringContainsString(self::RCS_PARAGRAPH_2, $rcs,
            self::RCS_FORM . ' has lost the intake-tense donation sentence.');
        $this->assertStringNotContainsString(self::CANONICAL_PARAGRAPH_2, $rcs,
            self::RCS_FORM . ' carries the past-tense canonical paragraph 2, which thanks the '
            . 'client for a project that has not started — this is the intake form.');

        foreach ([self::SIGNOFF_FORM, self::SIGNOFF_EMAIL] as $relative) {
            $prose = $this->prose($relative);
            $this->assertStringContainsString(self::CANONICAL_PARAGRAPH_2, $prose,
                "$relative has lost the canonical paragraph 2 of the donation ask.");
            $this->assertStringNotContainsString(self::RCS_PARAGRAPH_2, $prose,
                "$relative carries the RCS intake wording, which asks a client to donate \"when "
                . 'your project is complete\" about a project that has just completed.');
        }
    }
}
