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
 * NAMING THE INPUT THAT TRIPS EACH ONE. This repo has shipped guards that
 * asserted less than they claimed, so, per test:
 *
 *  - testEveryDeclaredTitleMatchesATier() trips on any NEW declared title
 *    matching neither tier. As of 2026-09-24 every declared title matches one:
 *    `after RCS` was the last offender and Nina's decision let it be renamed
 *    to `mas_lifecycle_rcs_circulated__client`, so PENDING_DECISION is empty.
 *    The matcher's rejection of the old title is still proved by
 *    testTheMatcherRejectsTheTitlesItIsSupposedTo().
 *  - testTheMatcherRejectsTheTitlesItIsSupposedTo() trips on any edit
 *    loosening either regex; it asserts twelve near-miss shapes are rejected.
 *  - testPendingDecisionTitlesAreStillDeclared() trips when a pending title is
 *    renamed — i.e. when the decision lands — and says to delete the entry.
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
     * the send data says a person sends it — read from PRODUCTION on
     * 2026-09-23: 58 sends across 29 distinct days in 2026, 53 of them with
     * distinct bodies.
     *
     * testPendingDecisionTitlesAreStillDeclared() goes red the moment the
     * title changes, which is the prompt to delete the entry. If you are here
     * because it went red: the decision landed, so remove the entry — do not
     * update it to the new title.
     *
     * ⚠ TWO HONEST LIMITS, because this is a REAL BYPASS and GRANDFATHERED is
     * not. GRANDFATHERED is never consulted by matchesATier() — both its
     * entries pass HUMAN_SENT on their own — so adding to it has never made
     * anything pass. This list IS consulted: an entry here makes
     * testEveryDeclaredTitleMatchesATier() skip that title outright. So:
     *
     *  1. A genuine naming mistake CAN be silenced by adding an entry. Nothing
     *     caps the size of this list or expires an entry. Adding to it is a
     *     claim that a DECISION is outstanding, and reviewers should treat a
     *     new entry as exactly that claim.
     *  2. It clears on a RENAME, not on NEGLECT — and neglect is the
     *     documented history. `after RCS` sat unnamed from May to September
     *     because nobody looked; in that scenario the title never changes,
     *     this test never goes red, and the entry persists with an exemption
     *     now blessing the silence. Nothing here will tell you. The forcing
     *     function has to be a human revisiting the decision.
     */
    private const PENDING_DECISION = [
        // Empty as of 2026-09-24: Nina decided the RCS-circulated email becomes
        // automatic, so `after RCS` was renamed onto the convention and its
        // entry removed here — which is the list working as designed. Leave the
        // mechanism in place for the next title that is genuinely undecided.
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
     * MessageTemplate_after_RCS.mgd.php — a duplicate-title state — left every
     * test green. The guard's coverage silently depended on array order.
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
     *
     * ⚠ WITH PENDING_DECISION EMPTY THIS TEST ASSERTS NOTHING, which is the
     * correct state and is stated here so nobody mistakes a green run for
     * coverage. It did its job on 2026-09-24: Nina's decision landed, the
     * rename followed, and the entry went. While the list is empty,
     * testEveryDeclaredTitleMatchesATier() is doing all of the work.
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
     * Titles retired from the provisioner but still named by it.
     *
     * `repointClientCloseTemplate()` names the OLD title so it can migrate
     * rows off it; nothing declares it and nothing should. An exclusion rather
     * than a widened pattern, so the next retired title has to be added here
     * deliberately instead of slipping through.
     */
    private const PROVISIONER_RETIRED_TITLES = [
        'MAS Project Close - Client Template',
    ];

    /**
     * Every template title the provisioner names, retired ones included.
     *
     * Pinned as a set so a title going MISSING is as visible as one appearing
     * — see the comment in the test for why a count was not enough.
     */
    private const PROVISIONER_TITLES = [
        'MAS Project Close - Client Template',
        'MAS Project Signoff - Client Template',
        'mas_lifecycle_close_chase__client',
        'mas_lifecycle_close_chase__vc',
        'mas_lifecycle_pd_authorize__client',
        'mas_lifecycle_pd_chase__client',
        'mas_lifecycle_pd_chase__vc',
        'mas_lifecycle_rcs_chase__client',
        'mas_lifecycle_rcs_circulated__client',
    ];

    /**
     * Every template title LifecycleRuleProvisioner names must be declared.
     *
     * ONE FACT STORED TWICE, which is the shape this repo keeps getting wrong.
     * The provisioner writes a title into `civirule_rule_action.action_params`
     * as a SERIALISED string; LifecycleEmail resolves it by title at send
     * time. A typo, or a rename that moves the declaration and not the
     * provisioner, produces a rule that looks healthy in the UI and fails
     * silently at send — and because the params are serialised, no deploy
     * rewrites them. Same class of fault that stopped the client lifecycle
     * transition on 2026-09-17.
     *
     * ⚠ MATCHED BY SHAPE, NOT BY POSITION, and that was a correction. The
     * first version keyed on `'template' => '<title>'`, which is only how the
     * array-literal builders write it — the five chase titles are passed
     * POSITIONALLY into ensureStatusChaseRule() and were invisible to it. It
     * covered three of eight while its name claimed all of them, and its
     * failsafe only fired if EVERY literal vanished, so converting one builder
     * would have left it green and watching nothing. Now it takes any string
     * shaped like a template title, wherever it appears.
     *
     * WHAT TRIPS IT, VERIFIED BY MUTATION RATHER THAN CLAIMED:
     *  - renaming a title at EVERY call site to a well-formed title nothing
     *    declares (e.g. `mas_lifecycle_rcs_nudge__client`) → red, naming it;
     *  - renaming only ONE of two call sites the same way → red, because the
     *    new title appears as unexpected;
     *  - a title disappearing from the provisioner entirely → red, as missing.
     *
     * ⚠ WHAT IT CANNOT SEE — TWO CASES, stated at full width because a guard
     * that understates its own limit is the defect this file exists to
     * prevent. An earlier version of this block named one duplicated title and
     * one failure mode; review found both counts low.
     *
     *  1. A typo that breaks the title SHAPE (`..._client` → `..._clientX`) at
     *     one of two DUPLICATE call sites. The pattern stops matching the
     *     broken string and the other occurrence keeps the set intact.
     *     TWO titles are currently named twice, not one:
     *     `mas_lifecycle_rcs_chase__client` (the two RCS chase builders) and
     *     `MAS Project Signoff - Client Template` (ensureVcCloseSendRule and
     *     repointClientCloseTemplate) — and the second is both a live send
     *     path and a ProjectLifecycleStatusSubscriber::TRANSITIONS key.
     *  2. PERMUTATION. Swap two declared titles between call sites — say
     *     `mas_lifecycle_pd_chase__vc` and `mas_lifecycle_pd_chase__client` —
     *     and the SET is unchanged and both are declared, so everything stays
     *     green while every VC receives the client's email and vice versa.
     *     This is WORSE than case 1: case 1 sends nothing (loadTemplate()
     *     throws, processAction() swallows, log line), whereas a permutation
     *     sends a plausible WRONG email to a real person. It is a realistic
     *     copy-paste error, because the two builder calls are near-identical
     *     eight-argument invocations differing in two strings.
     *
     * Neither is closable by shape-matching: a set is blind to permutation by
     * construction. Catching them needs argument-position parsing, which is
     * not worth the fragility — but a reader has to know that, which is why
     * this block exists.
     */
    public function testEveryTemplateTitleTheProvisionerNamesIsDeclared(): void
    {
        $file = dirname(__DIR__, 3) . '/Civi/Mascode/Service/LifecycleRuleProvisioner.php';
        $source = $this->stripComments((string) file_get_contents($file));
        $this->assertNotSame('', $source, "Could not read $file");

        // Any single-quoted string shaped like either naming tier.
        preg_match_all(
            // `MAS [A-Za-z0-9]`, matching this file's own HUMAN_SENT rather
            // than a narrower `MAS [A-Z]` — otherwise a net-new call site
            // naming a real title like `MAS eMail Template` (production id 69,
            // which testTheMatcherAcceptsOneTitleFromEachTier asserts is valid)
            // would be invisible to this guard AND to the declared-check.
            // Verified: widening yields the identical nine titles today.
            "/'(mas_[a-z0-9]+(?:_[a-z0-9]+)*__(?:client|vc|ed|treasurer)|MAS [A-Za-z0-9][^']*)'/",
            $source,
            $m
        );
        $referenced = array_values(array_unique($m[1]));

        // ⚠ PINNED AS AN EXACT SET, NOT A MINIMUM. A minimum was the second
        // version of this guard and it was still vacuous in a new place:
        // mutating `mas_lifecycle_rcs_chase__client` to `..._clientX` breaks
        // the title SHAPE, so the pattern stopped matching it, the count only
        // dropped by one, and the test stayed green while the provisioner
        // named a template nothing declares. A typo has to be VISIBLE, and the
        // only way a shape-matcher makes it visible is by noticing the title
        // went missing. Adding or retiring a template means editing this list,
        // which is the intended cost.
        $expected = self::PROVISIONER_TITLES;
        sort($expected);
        $actual = $referenced;
        sort($actual);

        $this->assertSame($expected, $actual, implode("\n", [
            'The set of template titles LifecycleRuleProvisioner names has changed.',
            '',
            'Missing (named before, not now — often a typo that broke the title shape,',
            'which is exactly the case a shape-matching pattern cannot see any other way):',
            '  ' . (implode(', ', array_diff($expected, $actual)) ?: '(none)'),
            'Unexpected (named now, not before):',
            '  ' . (implode(', ', array_diff($actual, $expected)) ?: '(none)'),
            '',
            'If you added or retired a template, update PROVISIONER_TITLES. If you did not,',
            'you have probably half-finished a rename.',
        ]));

        $missing = array_values(array_diff($referenced, $this->justTitles(), self::PROVISIONER_RETIRED_TITLES));

        $this->assertSame([], $missing, implode("\n", array_merge(
            ['LifecycleRuleProvisioner names these template titles, but no .mgd.php declares them:'],
            $missing,
            [
                '',
                'A CiviRules action naming a title nothing declares looks fine in the UI and fails',
                'silently at send, and its action_params are serialised so no deploy corrects them.',
                'Declare the template, fix the title — or, if it is deliberately retired, add it to',
                'PROVISIONER_RETIRED_TITLES with the reason.',
            ]
        )));
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

        $heading = '### Live templates that do not obey this';
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
