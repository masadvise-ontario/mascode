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

    /** Frozen name => the files that match on it and must keep spelling it the same. */
    private const CONSUMERS = [
        'Awaiting VC Project Close Form' => [
            'Civi/Mascode/Event/ProjectLifecycleStatusSubscriber.php',
            'Civi/Mascode/Util/CaseStatusSet.php',
            'Civi/Mascode/Managed/SavedSearch_MAS_Ops_ProjectsAwaitingCloseForm.mgd.php',
        ],
        'Awaiting Client Project Close Form' => [
            'Civi/Mascode/Event/ProjectLifecycleStatusSubscriber.php',
            'Civi/Mascode/Util/CaseStatusSet.php',
            'Civi/Mascode/Managed/SavedSearch_MAS_Ops_ProjectsAwaitingCloseForm.mgd.php',
        ],
        'Project Close - VC Report' => [
            'ang/afformProjectCloseVCFeedback.aff.html',
        ],
        'Project Close - Client Feedback' => [
            'ang/afformProjectCloseClientFeedback.aff.html',
        ],
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
                $this->declaration($basename),
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
                $this->declaration($basename),
                "$basename has a label identical to its frozen name (\"$name\"), so the "
                . 'Completion/Signoff rename has been reverted for this entity. Staff read the '
                . 'label; that is the entire point of the rename.'
            );
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
                    $source,
                    "$relative no longer refers to \"$name\".\n"
                    . 'Either it was renamed here and not in the managed declaration (the match '
                    . 'now fails silently — a case stops advancing, or a dashboard row quietly '
                    . 'empties), or this guard needs repointing because the reference legitimately '
                    . 'moved. Check which before editing this list.'
                );
            }
        }
    }
}
