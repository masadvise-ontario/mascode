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
 * WHAT THIS TEST DOES NOT CHECK. The `_lifecycle_` infix. It usually means a
 * CiviRules rule fires the template through LifecycleMailer — three
 * declarations say exactly that — but the Phase 4 donation trio
 * (`mas_lifecycle_donation_notify__ed`/`__treasurer`/`__vc`) carry the infix
 * while their docblocks describe a Symfony subscriber on Contribution.create.
 * So the infix is a sub-namespace for the engagement lifecycle, not a
 * guarantee about the mechanism, and asserting it here would either fail on
 * three unbuilt skeletons or encode a rule the data does not keep.
 *
 * WHY A TEST AND NOT A COMMENT. The convention was real practice for months —
 * two declarations already say "not a CiviRules rule — hence no
 * `mas_lifecycle_` prefix" — but it lived only in docblocks, so nothing
 * noticed that `after RCS` (template 76, managed since May) matched neither
 * tier. It was renamed to `mas_lifecycle_rcs_circulated__client`
 * on 2026-09-23. Nothing would have caught the next one either.
 *
 * NAMING THE INPUT THAT TRIPS IT. This repo has shipped seven guards that
 * asserted less than they claimed, so: the input that trips
 * testEveryDeclaredTitleMatchesATier() is the literal string `after RCS`,
 * which was in this directory until the commit that added this file, and
 * testTheMatcherRejectsTheTitlesItIsSupposedTo() proves the matcher rejects it
 * rather than trusting that it would.
 */
class MessageTemplateNamingTest extends TestCase
{
    /**
     * A person sends it: "MAS " then a capitalised word.
     */
    private const HUMAN_SENT = '/^MAS [A-Z][A-Za-z0-9]/';

    /**
     * The system sends it: mas_, lowercase/underscore throughout, and a
     * `__recipient` suffix so the two halves of one event sort together.
     */
    private const MACHINE_SENT = '/^mas_[a-z0-9]+(_[a-z0-9]+)*__(client|vc|ed|treasurer)$/';

    /**
     * Live titles that break the convention on purpose. Both are machine-sent
     * but `MAS `-prefixed, and both are named as exceptions in the README.
     *
     * DO NOT ADD TO THIS LIST to make a new template pass. A new template has
     * no live send path yet, so it can simply be named correctly; the entries
     * here are grandfathered because renaming them means editing code literals
     * that a running send path depends on. Renaming template 75 in the
     * production UI on 2026-09-17 silently stopped the client transition — that
     * is the cost being avoided, and it does not apply to something new.
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
     * @return array<string,string> file basename => msg_title
     */
    private function declaredTitles(): array
    {
        $dir = dirname(__DIR__, 3) . '/Civi/Mascode/Managed';
        $files = glob($dir . '/MessageTemplate_*.mgd.php');
        $this->assertNotEmpty($files, "No MessageTemplate declarations found under $dir");

        $titles = [];
        foreach ($files as $file) {
            $declarations = include $file;
            $this->assertIsArray($declarations, basename($file) . ' did not return an array');
            foreach ($declarations as $declaration) {
                $title = $declaration['params']['values']['msg_title'] ?? null;
                $this->assertNotNull($title, basename($file) . ' declares no msg_title');
                $titles[basename($file)] = (string) $title;
            }
        }
        return $titles;
    }

    private function matchesATier(string $title): bool
    {
        return (bool) preg_match(self::HUMAN_SENT, $title)
            || (bool) preg_match(self::MACHINE_SENT, $title);
    }

    public function testEveryDeclaredTitleMatchesATier(): void
    {
        $offenders = [];
        foreach ($this->declaredTitles() as $file => $title) {
            if (!$this->matchesATier($title)) {
                $offenders[] = "$file declares \"$title\"";
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
                'The recipient suffix is one of __client, __vc, __ed, __treasurer.',
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
            'MASRCSTemplate' => 'no space after MAS, so it is not the human-sent shape',
            'mas rcs circulated__client' => 'spaces in a machine name',
            '' => 'empty',
        ];

        foreach ($mustFail as $title => $why) {
            $this->assertFalse(
                $this->matchesATier($title),
                "Matcher accepted \"$title\" but should not have — $why"
            );
        }
    }

    public function testTheMatcherAcceptsOneTitleFromEachTier(): void
    {
        foreach (
            [
                'MAS RCS Template' => 'human-sent',
                'mas_lifecycle_rcs_circulated__client' => 'CiviRules-fired',
                'mas_vc_monthly_digest__vc' => 'PHP-fired',
                'mas_lifecycle_donation_notify__treasurer' => 'PHP-fired, non-client/vc recipient',
            ] as $title => $tier
        ) {
            $this->assertTrue($this->matchesATier($title), "Matcher rejected $tier title \"$title\"");
        }
    }

    /**
     * `after RCS` is gone and must not come back — including via a new
     * declaration that reintroduces the old title alongside the new one, which
     * is what upgrade_5016 logs a warning about.
     */
    public function testTheRetiredTitleIsNotDeclaredAnywhere(): void
    {
        $this->assertNotContains(
            'after RCS',
            array_values($this->declaredTitles()),
            'The retired title "after RCS" is declared again. It was renamed to '
            . '"mas_lifecycle_rcs_circulated__client" by upgrade_5016; declaring both '
            . 'creates two templates, because `match` is on msg_title.'
        );
    }

    /**
     * The grandfathered list is a freeze, not a waiting room. If a title here
     * has been renamed onto the convention, delete its entry; if a NEW
     * `MAS `-prefixed template is machine-sent, name it correctly instead of
     * adding it here.
     */
    public function testGrandfatheredExceptionsAreStillDeclaredUnderTheirOldTitles(): void
    {
        $declared = array_values($this->declaredTitles());
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
     */
    public function testTheReadmeNamesTheSameExceptions(): void
    {
        $readme = file_get_contents(dirname(__DIR__, 3) . '/Civi/Mascode/Managed/README.md');
        $this->assertIsString($readme, 'Could not read Civi/Mascode/Managed/README.md');

        foreach (self::GRANDFATHERED as $title) {
            $this->assertStringContainsString(
                $title,
                $readme,
                "GRANDFATHERED lists \"$title\" but the README does not mention it."
            );
        }
        $this->assertStringContainsString(
            'Message template naming',
            $readme,
            'The README section this test guards is missing.'
        );
    }
}
