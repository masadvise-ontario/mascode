<?php

namespace Civi\Mascode\Test\Unit\Managed;

use Civi\Mascode\Test\TestCase;

/**
 * Guards the labels-only decision behind the Completion/Signoff rename (P0-2).
 *
 * THE DECISION. The spec's rename table said to rename the case statuses and
 * activity types. Staff only ever see an OptionValue's `label` — CiviCRM shows
 * labels in dropdowns, SearchKit and dashboards, and this extension uses
 * `:name` exclusively in WHERE filters. So on 2026-09-21 the labels were
 * renamed and the `name`s were deliberately FROZEN at their "Close"-era
 * spelling. That is the whole user-visible rename at none of the migration
 * risk, and it is the same reasoning D13 used to decline renaming the Afform
 * machine names: the benefit would be a string no user ever reads.
 *
 * WHAT MAKES THIS FRAGILE, and why a test rather than a comment. The frozen
 * names look wrong. Every one of them says "Close" while the whole rest of the
 * system says Completion or Signoff, so the natural next edit — by a person or
 * a model, months from now, in a tidy-up — is to "finish the job" and rename
 * them. Doing that silently breaks things:
 *
 *   - ProjectLifecycleStatusSubscriber::TRANSITIONS matches `from`/`to` by name,
 *     so the case stops advancing. No exception, no log line.
 *   - CaseStatusSet's FALLBACK stops recognising the close-path statuses.
 *   - The SavedSearch `status_id:name` filters silently return nothing, so an
 *     ops dashboard row goes quietly empty rather than erroring.
 *   - Worst, civirule_rule_condition params hold these names SERIALISED, and no
 *     deploy touches them — the same class of fault that stopped the client
 *     close email being sent in September.
 *
 * So this file asserts the frozen names are still spelled the old way AND that
 * every consumer still spells them identically. Rename one side and it goes
 * red, naming the other side.
 *
 * If you genuinely need to rename a name: it is a data migration, not an edit.
 * Change the declaration, every consumer listed here, and add an upgrade step
 * that rewrites the serialised CiviRules condition params — then update this
 * test. Do not just update this test.
 *
 * @coversNothing
 */
class FrozenMachineNamesTest extends TestCase
{
    /** Frozen OptionValue `name` => the declaration that must still carry it. */
    private const FROZEN_NAMES = [
        'Awaiting VC Project Close Form' => 'OptionValue_CaseStatus_AwaitingVCProjectCloseForm',
        'Awaiting Client Project Close Form' => 'OptionValue_CaseStatus_AwaitingClientProjectCloseForm',
        'Project Close - VC Report' => 'OptionValue_ActivityType_ProjectCloseVCReport',
        'Project Close - Client Feedback' => 'OptionValue_ActivityType_ProjectCloseClientFeedback',
    ];

    /**
     * Frozen name => the files that match on it in CODE and must keep spelling
     * it identically.
     *
     * NOT maintained by hand any more: testTheDeclaredConsumerListIsComplete()
     * re-runs the comment-stripped sweep this list was originally built from
     * and fails if the list and the repo disagree in either direction. Adding a
     * file here without a real reference fails just as loudly as forgetting one.
     *
     * One entry is weaker than the rest and is listed knowingly:
     * SavedSearch_Case_Details_VC_Fields.mgd.php names both activity types as
     * SearchKit *admin labels*, not as match values — the heading a VC actually
     * reads lives in ang/afsearchMASCaseDetailsVC.aff.html and was renamed in
     * 1.1.17. It stays on the list because the file matches on status names
     * elsewhere and so must stay under the forbidden-label check; if a red test
     * ever points at those two lines, renaming them is safe.
     */
    private const CONSUMERS = [
        'Awaiting VC Project Close Form' => [
            'Civi/Mascode/Event/ProjectLifecycleStatusSubscriber.php',
            'Civi/Mascode/Util/CaseStatusSet.php',
            'Civi/Mascode/Managed/CaseType_Project.mgd.php',
            'Civi/Mascode/Managed/SavedSearch_MAS_Board_QTD.mgd.php',
            'Civi/Mascode/Managed/SavedSearch_MAS_Ops_ProjectsAwaitingCloseForm.mgd.php',
            'Civi/Mascode/Service/LifecycleRuleProvisioner.php',
            'scripts/cleanup-orphaned-chase-queue.php',
            'tests/Integration/Managed/CaseTypeSmokeTest.php',
        ],
        'Awaiting Client Project Close Form' => [
            'Civi/Mascode/Event/ProjectLifecycleStatusSubscriber.php',
            'Civi/Mascode/Util/CaseStatusSet.php',
            'Civi/Mascode/Managed/CaseType_Project.mgd.php',
            'Civi/Mascode/Managed/SavedSearch_MAS_Board_QTD.mgd.php',
            'Civi/Mascode/Managed/SavedSearch_MAS_Ops_ProjectsAwaitingCloseForm.mgd.php',
            'Civi/Mascode/Service/LifecycleRuleProvisioner.php',
            'scripts/cleanup-orphaned-chase-queue.php',
            'tests/Integration/Managed/CaseTypeSmokeTest.php',
        ],
        'Project Close - VC Report' => [
            'Civi/Mascode/Managed/SavedSearch_Case_Details_VC_Fields.mgd.php',
            'Civi/Mascode/Service/LifecycleRuleProvisioner.php',
            'ang/afformProjectCloseVCFeedback.aff.html',
        ],
        'Project Close - Client Feedback' => [
            'Civi/Mascode/Event/AfformSubmitSubscriber.php',
            'Civi/Mascode/Managed/SavedSearch_Case_Details_VC_Fields.mgd.php',
            'ang/afformProjectCloseClientFeedback.aff.html',
        ],
    ];


    /**
     * Source with comments removed.
     *
     * ⚠ THIS IS THE POINT OF THE FILE, not a detail. Review round 1 of PR #34
     * demonstrated two mutants that broke the invariant and left the suite
     * GREEN: renaming TRANSITIONS' from/to values, and renaming the ops
     * dashboard's status filter. Both survived because the assertions were raw
     * substring matches over the whole file, and every one of these files ALSO
     * names the frozen strings in its docblock — including this feature's own
     * explanation of why they are frozen. The prose kept the test passing while
     * the code moved out from under it.
     *
     * That is precisely the hollow-guard failure tests/Unit/Event/
     * StaffCopyWiringTest.php's docblock warns about, reproduced here by
     * someone who had read that warning. Strip the comments, or the guard
     * guards the comments.
     */
    private function codeOnly(string $source): string
    {
        $source = (string) preg_replace('#/\*.*?\*/#s', '', $source);
        $source = (string) preg_replace('#<!--.*?-->#s', '', $source);
        $source = (string) preg_replace('#(?m)^\s*//.*$#', '', $source);

        return (string) preg_replace('#(?m)^\s*\*.*$#', '', $source);
    }


    /**
     * Status LABELS that must never appear in a consumer's code.
     *
     * Closes the hole the positive assertions cannot: they ask whether a file
     * mentions the frozen name *somewhere*, so a file with two occurrences
     * stays green when only one is renamed. Review round 2 demonstrated exactly
     * that — renaming just the client transition's `from` gate, leaving the
     * file's other occurrence intact, passed the whole suite while a Project in
     * *Awaiting VC Project Completion Form* silently stopped advancing when the
     * client signoff email was sent. The September failure in a new costume,
     * which is the one thing this file exists to prevent.
     *
     * Scoped to the two STATUS labels deliberately. A blanket ban on
     * "Project Signoff" or "Project Completion Report" would false-positive on
     * legitimate code — 'MAS Project Signoff - Client Template' in the
     * subscriber and the provisioner, and the afforms' `subject:` values are
     * all correct uses of the new wording.
     */
    private const FORBIDDEN_IN_CONSUMERS = [
        'Awaiting VC Project Completion Form',
        'Awaiting Client Project Signoff Form',
    ];

    private function repoPath(string $relative): string
    {
        return __DIR__ . '/../../../' . $relative;
    }

    private function declaration(string $basename): string
    {
        $path = $this->repoPath('Civi/Mascode/Managed/' . $basename . '.mgd.php');
        $source = (string) file_get_contents($path);
        $this->assertNotSame('', $source, "could not read $basename.mgd.php");

        return $source;
    }

    public function testEveryFrozenNameIsStillDeclaredUnchanged(): void
    {
        foreach (self::FROZEN_NAMES as $name => $basename) {
            $this->assertStringContainsString(
                "'name' => '" . $name . "',",
                $this->codeOnly($this->declaration($basename)),
                "$basename no longer declares the frozen machine name \"$name\".\n"
                . 'This name is matched on by code, by SavedSearch filters and by SERIALISED '
                . 'CiviRules condition params that no deploy rewrites. Renaming it is a data '
                . 'migration, not an edit — see this class docblock before changing this test.'
            );
        }
    }

    public function testTheLabelsWereActuallyRenamed(): void
    {
        // The other half of the decision. If a label reverts to matching its
        // frozen name, the user-visible rename has been silently undone — which
        // is easy to do by copying the name line when adding a field nearby.
        foreach (self::FROZEN_NAMES as $name => $basename) {
            $this->assertStringNotContainsString(
                "'label' => '" . $name . "',",
                $this->codeOnly($this->declaration($basename)),
                "$basename has a label identical to its frozen name (\"$name\"), so the "
                . 'Completion/Signoff rename has been reverted for this entity. Staff read the '
                . 'label; that is the entire point of the rename.'
            );
        }
    }

    public function testNoConsumerMatchesOnARenamedStatusLabel(): void
    {
        // A consumer must match on the frozen NAME. Finding a renamed LABEL in
        // one means somebody "finished the rename" there — and because these
        // files match on names, that comparison now silently never succeeds.
        $files = [];
        foreach (self::CONSUMERS as $list) {
            foreach ($list as $relative) {
                $files[$relative] = true;
            }
        }

        $this->assertNotEmpty($files, 'no consumer files to check — has CONSUMERS been emptied?');

        foreach (array_keys($files) as $relative) {
            $code = $this->codeOnly((string) file_get_contents($this->repoPath($relative)));
            foreach (self::FORBIDDEN_IN_CONSUMERS as $label) {
                $this->assertStringNotContainsString(
                    $label,
                    $code,
                    "$relative contains the status LABEL \"$label\".\n"
                    . 'Consumers match on the frozen machine name, never the label, so this '
                    . 'comparison can never succeed — a case silently stops advancing, or a '
                    . 'dashboard row quietly empties. If this is a display string rather than a '
                    . 'match, it does not belong in a file on the CONSUMERS list.'
                );
            }
        }
    }

    public function testEveryConsumerStillSpellsTheFrozenNameIdentically(): void
    {
        // The functional half: a name is only useful if the things matching on
        // it agree. Renaming either side alone fails here and names the other.
        foreach (self::CONSUMERS as $name => $files) {
            foreach ($files as $relative) {
                $source = (string) file_get_contents($this->repoPath($relative));
                $this->assertNotSame('', $source, "could not read $relative");
                $this->assertStringContainsString(
                    $name,
                    $this->codeOnly($source),
                    "$relative no longer refers to \"$name\".\n"
                    . 'Either it was renamed here and not in the managed declaration (the match '
                    . 'now fails silently — a case stops advancing, or a dashboard row quietly '
                    . 'empties), or this guard needs repointing because the reference legitimately '
                    . 'moved. Check which before editing this list.'
                );
            }
        }
    }

    /**
     * Files the sweep finds that are deliberately NOT consumers, and why.
     *
     * Every exclusion is a claim that renaming a frozen name would NOT require
     * changing that file. Each is stated so a future reader can check it rather
     * than assume it — an undocumented exclusion is how a real consumer gets
     * quietly parked here to turn a red test green.
     */
    private const NOT_CONSUMERS = [
        'CRM/Mascode/Upgrader.php' =>
            'append-only migration history: each step must keep the spelling that was '
            . 'current when it ran, so a rename must NOT rewrite it',
        'tests/Unit/Managed/FrozenMachineNamesTest.php' =>
            'this guard, which names every frozen string by construction',
        'tests/Unit/Submission/StaffCopyIdentificationTest.php' =>
            'uses "Project Close - Client Feedback" as a fixture form_title — a display '
            . 'string flowing through the staff-copy summary, never matched against the '
            . 'activity type',
    ];

    /**
     * Directories the sweep does not walk, and why.
     *
     * Deliberately a short skip list rather than an allow-list of source roots:
     * a new top-level directory must be scanned BY DEFAULT, because the whole
     * point of deriving the list is that a consumer appearing somewhere nobody
     * predicted still fails loudly.
     */
    private const UNSCANNED_DIRS = [
        '.git' => 'version-control metadata',
        '.claude' => 'gitignored; holds detached worktrees carrying whole second copies of the tree',
        'vendor' => 'third-party code, not ours to keep in step',
        'node_modules' => 'third-party code, not ours to keep in step',
        'zz_delete' => 'untracked scratch copies of retired files, absent from a fresh checkout',
    ];

    /**
     * Re-run the comment-stripped sweep the CONSUMERS list was built from.
     *
     * @return array<string, list<string>> frozen name => sorted relative paths
     */
    private function deriveConsumers(): array
    {
        $root = realpath($this->repoPath('')) ?: '';
        $this->assertNotSame('', $root, 'could not resolve the extension root');

        $found = array_fill_keys(array_keys(self::FROZEN_NAMES), []);

        $tree = new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            static fn(\SplFileInfo $f): bool
                => !$f->isDir() || !isset(self::UNSCANNED_DIRS[$f->getFilename()])
        );

        foreach (new \RecursiveIteratorIterator($tree) as $file) {
            /** @var \SplFileInfo $file */
            $path = $file->getPathname();
            if (!preg_match('#\.php$|\.aff\.html$#', $path)) {
                continue;
            }
            $relative = str_replace(
                DIRECTORY_SEPARATOR,
                '/',
                ltrim(substr($path, strlen($root)), DIRECTORY_SEPARATOR)
            );

            $code = $this->codeOnly((string) file_get_contents($path));
            foreach (array_keys(self::FROZEN_NAMES) as $name) {
                if (str_contains($code, $name)) {
                    $found[$name][] = $relative;
                }
            }
        }

        foreach ($found as &$list) {
            sort($list);
        }

        return $found;
    }

    public function testTheDeclaredConsumerListIsComplete(): void
    {
        // THE POINT OF THIS TEST. CONSUMERS was built by hand from a sweep,
        // which makes it accurate on the day it was written and steadily less
        // so afterwards — a new file matching on a frozen name joins the
        // invariant silently and is then guarded by nothing. Re-deriving the
        // same sweep here turns that silence into a failure: the day a
        // reference appears anywhere in the tree this goes red, names the file,
        // and asks the author to classify it.
        //
        // Carried into P0-3 from PR #34's round-3 review, which preferred this
        // over hoisting the strings into a shared constant: the two afforms are
        // Angular markup with no import mechanism, so a constant would have
        // covered six of ten references and left two guard mechanisms where
        // there is now one.
        foreach ($this->deriveConsumers() as $name => $foundIn) {
            $declared = self::CONSUMERS[$name] ?? [];

            $accounted = array_merge(
                $declared,
                // A frozen name's own declaration is not a consumer of itself.
                ['Civi/Mascode/Managed/' . self::FROZEN_NAMES[$name] . '.mgd.php'],
                array_keys(self::NOT_CONSUMERS)
            );

            $this->assertSame(
                [],
                array_values(array_diff($foundIn, $accounted)),
                "These files refer to the frozen name \"$name\" but nothing accounts for them.\n\n"
                . 'Classify each. If it MATCHES on the name — a WHERE filter, a TRANSITIONS key, '
                . 'an afform activity_type_id:name, a CiviRules param — add it to CONSUMERS, so a '
                . 'rename fails here rather than in production. If it merely displays the string, '
                . 'add it to NOT_CONSUMERS with the reason. Do not delete this assertion.'
            );

            $this->assertSame(
                [],
                array_values(array_diff($declared, $foundIn)),
                "CONSUMERS claims these files refer to \"$name\", but the sweep no longer finds "
                . "it in them.\n\n"
                . 'Either the reference was legitimately removed (drop the entry) or it was '
                . 'RENAMED — which is the silent failure this whole file exists to catch. Check '
                . 'which before editing the list.'
            );
        }
    }

    /**
     * An exclusion list naming a file that no longer mentions the string is a
     * stale excuse nobody will re-examine. Fail when one goes dead.
     */
    public function testEveryExclusionIsStillEarned(): void
    {
        $everywhere = array_unique(array_merge(...array_values($this->deriveConsumers())));

        foreach (self::NOT_CONSUMERS as $relative => $reason) {
            $this->assertContains(
                $relative,
                $everywhere,
                "NOT_CONSUMERS excludes $relative (\"$reason\"), but no frozen name appears in it "
                . 'any more. Drop the exclusion — leaving it behind hides the next file that '
                . 'genuinely needs classifying.'
            );
        }
    }
}
