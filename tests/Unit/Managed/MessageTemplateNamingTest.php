<?php

namespace Civi\Mascode\Test\Unit\Managed;

use Civi\Mascode\Test\TestCase;

/**
 * Guards the message-template naming convention documented in
 * Civi/Mascode/Managed/README.md § "Message template naming".
 *
 * THE CONVENTION. A `msg_title` is the only thing staff see in the CiviCRM
 * dropdown and it is also this directory's match key, so it has to answer one
 * question on sight — does a human send this, or does the system?
 *
 *   `MAS <Title Case>`   a person composes and sends it from the case
 *   `mas_*__recipient`   the system sends it unattended
 *
 * WHAT THIS TEST DOES NOT CHECK, AND CANNOT. Two things, both worth stating
 * because the docblock is the only place a reader learns the limits:
 *
 *  1. **The `_lifecycle_` infix.** It usually means a CiviRules rule fires the
 *     template through LifecycleMailer, but the Phase 4 donation trio
 *     (`mas_lifecycle_donation_notify__ed`/`__treasurer`/`__vc`) carry it while
 *     their docblocks describe a Symfony subscriber on Contribution.create. So
 *     the infix is a sub-namespace for the engagement lifecycle, not a
 *     guarantee about the mechanism, and asserting it would either fail on
 *     three unbuilt skeletons or encode a rule the data does not keep.
 *  2. **Who actually sends a template.** These assertions see the *shape of a
 *     string*, nothing more. A new machine-sent template titled
 *     "MAS Weekly Ops Digest" is exactly the violation GRANDFATHERED exists to
 *     mark, and every test here would stay green, because nothing in a title
 *     encodes its sender. Catching that needs a human reading the PR.
 *
 * WHY A TEST AND NOT A COMMENT. The convention was real practice for months —
 * the README's inventory table already noted twice that a template is sent by
 * a subscriber and so carries no `mas_lifecycle_` prefix — but it was never
 * stated as a rule anywhere, so nothing noticed that `after RCS` (template 76,
 * managed since May) matched neither tier.
 *
 * NAMING THE INPUT THAT TRIPS IT. This repo has shipped seven guards that
 * asserted less than they claimed, so, per test: the input that trips
 * testEveryDeclaredTitleMatchesATier() is the literal string `after RCS`,
 * which was in this directory until the commit that added this file, and
 * testTheMatcherRejectsTheTitlesItIsSupposedTo() proves the matcher rejects it
 * rather than trusting that it would.
 *
 * @coversNothing
 */
class MessageTemplateNamingTest extends TestCase
{
    /**
     * A person sends it: "MAS " then ordinary words.
     *
     * End-anchored on purpose. An unanchored version accepted
     * "MAS A1 !!!@@@" — it only ever looked at the first two characters after
     * the prefix, which is the shape of guard this epic keeps finding.
     *
     * DELIBERATELY DOES NOT POLICE CAPITALISATION. An earlier version required
     * the first word to start `[A-Z]`, which rejected `MAS eMail Template` —
     * a real production template (id 69), plainly human-sent, not yet
     * snapshotted into this directory. Whoever snapshotted it would have been
     * told to "pick the tier by who sends it" about a title already in the
     * right tier. The load-bearing distinction is `MAS ` versus `mas_`;
     * enforcing title case beyond that buys no safety and costs false
     * rejections. Punctuation common in real subject-like titles is allowed
     * for the same reason — `MAS Donor Thank-You (Annual)`, `MAS Board
     * Update, Q1`.
     */
    private const HUMAN_SENT = "/^MAS [A-Za-z0-9][A-Za-z0-9&\\-.,()']*( [A-Za-z0-9&\\-.,()']+)*$/";

    /**
     * The system sends it: mas_, lowercase/underscore throughout, and a
     * `__recipient` suffix so the two halves of one event sort together.
     *
     * The recipient list is closed. A template for a recipient not named here
     * — `__staff`, `__board` — is a deliberate decision about who MAS emails,
     * so it should be made by editing this constant, not worked around.
     */
    private const MACHINE_SENT = '/^mas_[a-z0-9]+(_[a-z0-9]+)*__(client|vc|ed|treasurer)$/';

    /**
     * Live titles that break the convention. Both are machine-sent but
     * `MAS `-prefixed, and both are named as exceptions in the README.
     *
     * WHAT THIS LIST DOES, EXACTLY: it freezes these two titles. Rename either
     * in the declarations without updating this constant and
     * testGrandfatheredExceptionsAreStillDeclaredUnderTheirOldTitles goes red.
     * Phase 2 is expected to trip it, which is the point.
     *
     * WHAT IT DOES NOT DO: it is not consulted by matchesATier(). Both titles
     * pass HUMAN_SENT on their own, so adding an entry here has never made
     * anything pass and removing both would not turn the tier test red. It
     * cannot stop a NEW `MAS `-prefixed machine-sent template either, because
     * a title does not encode its sender. Treat it as a record of two known
     * exceptions, not as a gate.
     *
     * Why they are not simply renamed: each has a live send path keyed to the
     * literal string. Renaming template 75 in the production UI on 2026-09-17
     * silently stopped the client transition — that is the cost being avoided,
     * and it does not apply to something new, which can just be named right.
     */
    private const GRANDFATHERED = [
        // Sent by AfformSubmitSubscriber on every client Afform submission;
        // seven server_route values map to this literal.
        'MAS Form Submission Confirmation',
        // Fired automatically by the mas_lifecycle_vc_close_send CiviRule, and
        // also a key in ProjectLifecycleStatusSubscriber::TRANSITIONS, a
        // VcDigestMailer subject guard, and civirule_rule_action.action_params.
        'MAS Project Signoff - Client Template',
    ];

    /**
     * Titles that match no tier because the tier is BLOCKED ON A DECISION,
     * not because anyone got the naming wrong.
     *
     * This is not a second grandfather list. A grandfathered title is one we
     * have decided to leave alone forever; a pending one is one nobody can
     * name yet, because naming it would assert something not yet true.
     *
     * "after RCS" is here because `mas_*` asserts the system sends a template
     * and `MAS <Title Case>` asserts a person does — and which is true is
     * exactly what Nina is deciding for this one. It was renamed to
     * `mas_lifecycle_rcs_circulated__client` in PR #43 and reverted, because
     * the send data says a person sends it: 56 sends across 28 days in 2026,
     * 52 of them with distinct bodies.
     *
     * testPendingDecisionTitlesAreStillDeclared() goes red the moment the
     * title changes, which is the prompt to delete the entry. If you are here
     * because it went red: the decision landed, so remove the entry — do not
     * update it to the new title.
     */
    private const PENDING_DECISION = [
        'after RCS' => 'manual vs automatic send is undecided; the tier follows that decision',
    ];

    private function managedDir(): string
    {
        return dirname(__DIR__, 3) . '/Civi/Mascode/Managed';
    }

    /**
     * Every declared MessageTemplate title, as a LIST.
     *
     * Deliberately not keyed by file. An earlier version was, which meant a
     * file returning several declarations contributed only its LAST title —
     * so re-declaring `after RCS` as the first element of
     * MessageTemplate_after_RCS.mgd.php (the "both titles exist" state
     * upgrade_5016 warns about) left every test green. The guard's coverage
     * silently depended on array order.
     *
     * @return list<array{file: string, index: int, title: string}>
     */
    private function declaredTitles(): array
    {
        $files = glob($this->managedDir() . '/MessageTemplate_*.mgd.php');
        $this->assertNotEmpty($files, 'No MessageTemplate declarations found under ' . $this->managedDir());

        $titles = [];
        foreach ($files as $file) {
            $declarations = include $file;
            $this->assertIsArray($declarations, basename($file) . ' did not return an array');
            foreach ($declarations as $index => $declaration) {
                $title = $declaration['params']['values']['msg_title'] ?? null;
                $this->assertNotNull(
                    $title,
                    basename($file) . " declaration #$index declares no msg_title"
                );
                $titles[] = [
                    'file' => basename($file),
                    'index' => (int) $index,
                    'title' => (string) $title,
                ];
            }
        }
        return $titles;
    }

    /** @return string[] */
    private function justTitles(): array
    {
        return array_column($this->declaredTitles(), 'title');
    }

    private function matchesATier(string $title): bool
    {
        return (bool) preg_match(self::HUMAN_SENT, $title)
            || (bool) preg_match(self::MACHINE_SENT, $title);
    }

    /**
     * PHP source with comments and docblocks removed.
     *
     * The only way this file reads source. A plain string search over raw
     * source is satisfiable by a comment or an adjacent docblock — this repo
     * has shipped that defect more than once.
     */
    private function stripComments(string $source): string
    {
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }
        return $out;
    }

    public function testEveryDeclaredTitleMatchesATier(): void
    {
        $offenders = [];
        foreach ($this->declaredTitles() as $declared) {
            if (array_key_exists($declared['title'], self::PENDING_DECISION)) {
                continue;
            }
            if (!$this->matchesATier($declared['title'])) {
                $offenders[] = sprintf(
                    '%s declaration #%d declares "%s"',
                    $declared['file'],
                    $declared['index'],
                    $declared['title']
                );
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['These msg_titles match neither naming tier:'],
            $offenders,
            [
                '',
                'Pick the tier by WHO SENDS IT, not by what reads nicely:',
                '  MAS <Title Case>    a person sends it from the case',
                '  mas_*__recipient    the system sends it unattended',
                '',
                'The recipient suffix is one of __client, __vc, __ed, __treasurer;',
                'widening that set means editing MACHINE_SENT in this file.',
                '',
                'If the tier genuinely cannot be chosen yet because a decision is',
                'outstanding, PENDING_DECISION is the place for it — with the reason.',
                'See Civi/Mascode/Managed/README.md § "Message template naming".',
            ]
        )));
    }

    /**
     * The guard is only worth having if it rejects things, so prove it does —
     * starting with the exact string that was in this directory until the
     * commit that added this test.
     */
    public function testTheMatcherRejectsTheTitlesItIsSupposedTo(): void
    {
        $mustFail = [
            'after RCS' => 'the real historical offender: no tier, reads as a developer note',
            'after_RCS' => 'lowercase but no mas_ prefix, so it claims no mechanism',
            'mas lifecycle rcs chase' => 'spaces where underscores belong',
            'mas_lifecycle_rcs_chase' => 'no __recipient suffix, so the halves of an event do not pair',
            'mas_Lifecycle_Rcs_Chase__client' => 'capitals inside a machine name',
            'mas_a_b__vc__vc' => 'a doubled recipient suffix',
            'mas_lifecycle_rcs_chase__staff' => 'a recipient MACHINE_SENT does not name',
            'MASRCSTemplate' => 'no space after MAS, so it is not the human-sent shape',
            'mas rcs circulated__client' => 'spaces in a machine name',
            'MAS A1 !!!@@@' => 'punctuation past the prefix — the input that showed HUMAN_SENT needed an end anchor',
            'MASSIVE Template' => 'MAS not followed by a space, so it is not the human-sent shape',
            'mas_' => 'prefix with no name and no recipient',
            '' => 'empty',
        ];

        foreach ($mustFail as $title => $why) {
            $this->assertFalse(
                $this->matchesATier((string) $title),
                "Matcher accepted \"$title\" but should not have — $why"
            );
        }
    }

    public function testTheMatcherAcceptsOneTitleFromEachTier(): void
    {
        foreach (
            [
                'MAS RCS Template' => 'human-sent',
                'MAS Project Signoff - Client Template' => 'human-sent shape with a hyphen word',
                'MAS eMail Template' => 'human-sent, lowercase first letter (real prod template id 69)',
                'MAS Donor Thank-You (Annual)' => 'human-sent with parentheses',
                'mas_lifecycle_rcs_circulated__client' => 'machine-sent, lifecycle',
                'mas_vc_monthly_digest__vc' => 'machine-sent, no lifecycle infix',
                'mas_lifecycle_donation_notify__treasurer' => 'machine-sent, non-client/vc recipient',
            ] as $title => $tier
        ) {
            $this->assertTrue($this->matchesATier($title), "Matcher rejected $tier title \"$title\"");
        }
    }

    /**
     * Every PENDING_DECISION title must still be declared under that exact
     * spelling.
     *
     * This is the half that makes the list self-clearing. The entry exists
     * because a name is blocked on a decision; the moment the decision lands
     * the title changes, this goes red, and whoever changed it is told to
     * remove the entry rather than carry a stale exemption forever. A list of
     * exemptions nobody is forced to revisit is how the original `after RCS`
     * survived from May to September unnoticed.
     */
    public function testPendingDecisionTitlesAreStillDeclared(): void
    {
        $declared = $this->justTitles();
        foreach (self::PENDING_DECISION as $title => $why) {
            $this->assertContains(
                $title,
                $declared,
                "\"$title\" is exempt from the naming convention pending a decision ($why), "
                . 'but is no longer declared under that title. If the decision has landed, DELETE its '
                . 'entry from PENDING_DECISION and from the README — do not update it to the new name, '
                . 'because a named title needs no exemption.'
            );
        }
    }

    /**
     * The glob above only sees `MessageTemplate_*.mgd.php`, so a MessageTemplate
     * declared in a differently-named file would be invisible to every
     * assertion in this class.
     *
     * Source-scanned rather than included: two SavedSearch declarations in this
     * directory reference `Civi\Mascode\Util\CaseStatusSet`, which fatals
     * outside a bootstrapped CiviCRM — and CI has no CiviCRM.
     */
    public function testNoMessageTemplateIsDeclaredOutsideAMessageTemplateFile(): void
    {
        $offenders = [];
        foreach (glob($this->managedDir() . '/*.mgd.php') ?: [] as $file) {
            if (str_starts_with(basename($file), 'MessageTemplate_')) {
                continue;
            }
            $source = $this->stripComments((string) file_get_contents($file));
            // Both quote styles. PHP accepts "entity" => "MessageTemplate" and
            // nothing in this repo enforces single quotes, so a single-quote-only
            // pattern is a false negative that hides the file from every other
            // assertion in this class.
            if (preg_match('/([\'"])entity\\1\s*=>\s*([\'"])MessageTemplate\\2/', $source)) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['These files declare a MessageTemplate but are not named MessageTemplate_*.mgd.php,'],
            ['so every naming assertion in this class silently skips them:'],
            $offenders,
            ['', 'Rename the file, or widen the glob in declaredTitles().']
        )));
    }

    /**
     * The grandfathered list is a freeze, not a waiting room. If a title here
     * has been renamed onto the convention, delete its entry.
     */
    public function testGrandfatheredExceptionsAreStillDeclaredUnderTheirOldTitles(): void
    {
        $declared = $this->justTitles();
        foreach (self::GRANDFATHERED as $title) {
            $this->assertContains(
                $title,
                $declared,
                "\"$title\" is listed as a grandfathered naming exception but is no longer declared. "
                . 'If it was renamed onto the convention, remove it from GRANDFATHERED and from the '
                . 'exceptions section of Civi/Mascode/Managed/README.md.'
            );
        }
    }

    /**
     * The exceptions in the test and the exceptions in the README are two
     * copies of one list, which is how they drift.
     *
     * Scoped to the exceptions SECTION, not the whole README. Unscoped, this
     * passed on a mention anywhere — the inventory table, an HTML comment, or a
     * "formerly an exception" note — which is the same defect as reading
     * un-stripped source.
     */
    public function testTheReadmeNamesTheSameExceptions(): void
    {
        $readme = (string) file_get_contents($this->managedDir() . '/README.md');
        $this->assertNotSame('', $readme, 'Could not read Civi/Mascode/Managed/README.md');

        $heading = '### Two live templates do not obey this, deliberately';
        $start = strpos($readme, $heading);
        $this->assertNotFalse(
            $start,
            'The README section this test guards is missing. Expected a heading: ' . $heading
        );

        // Terminate at the EARLIER of the next `## ` and the next `### `.
        // Matching only `\n## ` meant a new `###` subsection did not end the
        // slice, silently widening the searched region through it — so a title
        // moved OUT of the exceptions list into that subsection would still
        // satisfy this test.
        $rest = substr($readme, $start + strlen($heading));
        $ends = array_filter(
            [strpos($rest, "\n## "), strpos($rest, "\n### ")],
            static fn($pos) => $pos !== false
        );
        $section = $ends === [] ? $rest : substr($rest, 0, min($ends));

        foreach (self::GRANDFATHERED as $title) {
            $this->assertStringContainsString(
                $title,
                $section,
                "GRANDFATHERED lists \"$title\" but the README's exceptions section does not name it."
            );
        }
        foreach (array_keys(self::PENDING_DECISION) as $title) {
            $this->assertStringContainsString(
                $title,
                $section,
                "PENDING_DECISION lists \"$title\" but the README's exceptions section does not name it. "
                . 'An undecided name that is only recorded in a test is invisible to whoever makes the decision.'
            );
        }
    }
}
