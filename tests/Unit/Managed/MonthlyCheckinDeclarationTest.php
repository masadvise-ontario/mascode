<?php

namespace Civi\Mascode\Test\Unit\Managed;

use Civi\Mascode\Test\TestCase;

/**
 * Guards the declaration order that makes the Monthly Project Check-in custom
 * group actually scoped to its activity type.
 *
 * THE DEFECT THIS EXISTS FOR, which was live on this branch before review.
 * `extends_entity_column_value:name` is resolved by
 * CRM_Core_BAO_CustomGroup::getExtendsEntityColumnValueOptions() against the
 * LIVE activity_type option list, at write time. When the named option value
 * does not exist yet, core writes NULL and says nothing — no exception, no log
 * line, no failed reconcile. NULL there does not mean "scoped to nothing"; it
 * means scoped to EVERY activity type, so the three check-in fields would have
 * appeared on every activity form in CiviCRM.
 *
 * Core's create order is deterministic and was working against us: the
 * `mgd-php@1` mixin (the version `info.xml` declares) collects every
 * `*.mgd.php` under the extension, `sort()`s the full paths and appends each
 * file's array in order, and ManagedEntities::reconcileEntities() walks the
 * `create` plan in that same order. Under the directory's own one-entity-per-file convention the
 * two files were `CustomGroup_MonthlyProjectCheckin.mgd.php` and
 * `OptionValue_ActivityType_MonthlyProjectCheckin.mgd.php` — "C" before "O", so
 * on every clean environment the group was created before the option value
 * existed. Reproduced on dev 2026-09-22, and fixed by putting both in one
 * array with the option value first.
 *
 * WHY A TEST AND NOT JUST A COMMENT. A bad create is PERMANENT, not transient.
 * ManagedEntities::optimizePlan() drops any `update` whose stored checksum
 * still matches the declaration's, so a record written wrong stays wrong
 * through every `cv flush` — only `cv upgrade:db` (which sets upgrade mode) or
 * a changed declaration re-writes it. Measured on dev 2026-09-22, both ways.
 * And the only environment that can show the bug is a CLEAN one, which in
 * practice means production, once, in front of everybody. Nothing else in the
 * repo would notice a well-meaning tidy-up that split this file back into two.
 *
 * WHAT IT CANNOT DO. It reads the declaration, not the database, so it proves
 * the *inputs* to core's resolution are right, not that resolution succeeded.
 * The live half was verified by hand on dev: delete both records and their
 * civicrm_managed rows, `cv flush` once, confirm
 * `extends_entity_column_value` is populated. There is no CiviCRM in CI
 * (docs/TESTING.md), so that half cannot live here.
 *
 * @coversNothing
 */
class MonthlyCheckinDeclarationTest extends TestCase
{
    /**
     * The activity type's machine name, as DECLARED.
     *
     * Note this is not the spelling the spec's Data Model table uses
     * (`Monthly_Project_Check_in`); the deviation and its reason are recorded
     * at the declaration and in the ticket slice. Asserted here so the two
     * cannot drift apart silently — whichever spelling wins, the group's
     * scoping must name the same string the option value does.
     */
    private const ACTIVITY_TYPE_NAME = 'Monthly Project Check-in';

    private const CUSTOM_GROUP_NAME = 'Monthly_Project_Checkin';

    private const MANAGED_DIR = __DIR__ . '/../../../Civi/Mascode/Managed';

    private const DECLARATION_FILE = self::MANAGED_DIR . '/ActivityType_MonthlyProjectCheckin.mgd.php';

    /** The `name` of the managed RECORD, which is what a duplicate would reuse. */
    private const MANAGED_OPTION_VALUE_NAME = 'OptionValue_activity_type_Monthly_Project_Check_in';

    private const MANAGED_CUSTOM_GROUP_NAME = 'CustomGroup_Monthly_Project_Checkin';

    private const EXTENSION_ROOT = __DIR__ . '/../../..';

    /**
     * Every file core would load a declaration from.
     *
     * MIRRORS `mgd-php@1`, WHICH IS THE VERSION `info.xml` DECLARES. An earlier
     * version of this helper mirrored `mgd-php@2` — five named roots — and was
     * therefore NARROWER than what core actually loads here, which is the one
     * thing a guard like this must never be. Caught in review, and worth the
     * comment: the v1 and v2 mixins differ precisely in how they search, so
     * citing the wrong one produces a helper that looks rigorous and is not.
     *
     * v1 is `CRM_Utils_File::findFiles($path, '*.mgd.php')` — the WHOLE
     * extension tree, recursively.
     *
     * The dot-directory exclusion is core's, and it is here because this
     * repository can contain a **complete second copy of itself** under
     * `.claude/worktrees/`. A worktree of a branch that carries these
     * declarations would otherwise be reported as a duplicate of itself. Note
     * what that claim is NOT: the worktree present while this was written
     * predates this branch and contains neither record name, so the exclusion
     * is not currently load-bearing on this tree — it is guarded by
     * testDeclarationsInsideDotDirectoriesAreIgnored() below rather than by the worktree
     * happening to exist.
     *
     * THREE DELIBERATE DIVERGENCES FROM v1, so that "mirrors" is true as
     * written rather than true in spirit:
     *
     *   - **Directory symlinks are not followed.** Core's
     *     `glob(..., GLOB_ONLYDIR)` matches a symlink to a directory and
     *     recurses into it; `RecursiveDirectoryIterator` defaults
     *     `$allowLinks = false`. So a declaration reachable only through a
     *     symlinked directory would be loaded by core and missed here. Left
     *     diverging on purpose: `FilesystemIterator::FOLLOW_SYMLINKS` would
     *     match core exactly and inherit core's own symlink-cycle hang, and
     *     there are no directory symlinks in this extension. It is the unsafe
     *     direction, so it is written down rather than left to be discovered.
     *   - **Dot-FILES are found here and skipped by core** — but NOT for the
     *     reason an earlier version of this comment gave. Core's exclusion
     *     regex is applied only to SUBDIRECTORIES (`CRM/Utils/File.php`); the
     *     files themselves come from `glob("$subdir/*.mgd.php")`, and glob's
     *     `*` simply does not match a leading dot. Same outcome, different
     *     mechanism, and the wrong one would mislead anyone reasoning about a
     *     related case. Safe direction either way: at worst this flags
     *     something core would ignore.
     *   - **`CIVICRM_EXCLUDE_DIRS_PATTERN`**, which a site may define to
     *     override core's default exclusion, is not honoured here. Verified
     *     undefined on dev, where CiviCRM ships it commented out in
     *     `civicrm.settings.php`; production was not checked from here, so
     *     this is stated as the default rather than as a fact about prod.
     */
    private function allManagedFiles(): array
    {
        $root = realpath(self::EXTENSION_ROOT);
        $this->assertNotFalse($root, 'Could not resolve the extension root.');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                // Core's rule, applied at the directory level so whole subtrees
                // are pruned rather than walked and discarded.
                static fn($current) => !($current->isDir() && str_starts_with($current->getFilename(), '.'))
            ),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        // CATCH_GET_CHILD so an unreadable directory is SKIPPED rather than
        // throwing, which is what core does. This matters more since the walk
        // was widened from four curated directories to the whole tree: the
        // surface now includes vendor/, build output and whatever a deploy or
        // a worktree leaves behind, and a permissions accident anywhere under
        // the extension would otherwise error this test rather than fail it.
        $iterator->setFlags(\RecursiveIteratorIterator::CATCH_GET_CHILD);

        $files = [];
        foreach ($iterator as $file) {
            if (str_ends_with($file->getFilename(), '.mgd.php')) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        return $files;
    }

    /**
     * Drop comments so a file that merely MENTIONS a record is not mistaken for
     * one that declares it. Same technique, same reason, as
     * FrozenMachineNamesTest's codeOnly().
     */
    private function stripComments(string $source): string
    {
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }
        return $out;
    }

    /**
     * The declarations, as core's mixin would load them from the one file.
     *
     * A plain `include`: this .mgd.php is a bare `return [...]` with no Civi
     * calls, so it loads in CI where there is no CiviCRM. Parsing it as text
     * (the approach LifecycleTransitionTemplateWiringTest has to take, because
     * its subject extends an AutoSubscriber) would be strictly weaker here.
     */
    private function declarations(): array
    {
        $this->assertFileExists(
            self::DECLARATION_FILE,
            'The check-in declarations must stay in one file. If this moved, read the '
            . 'ordering rationale in its docblock before re-pointing this test.'
        );
        return include self::DECLARATION_FILE;
    }

    private function indexOf(array $declarations, string $entity, string $name): ?int
    {
        foreach ($declarations as $i => $declaration) {
            if (($declaration['entity'] ?? null) === $entity
                && ($declaration['params']['values']['name'] ?? null) === $name) {
                return $i;
            }
        }
        return null;
    }

    /**
     * The option value must be declared BEFORE the group that names it.
     *
     * This is the whole fix. Core creates in array order, and the group's
     * scoping resolves by name against option values that already exist.
     */
    public function testOptionValueIsDeclaredBeforeTheCustomGroup(): void
    {
        $declarations = $this->declarations();

        $optionValueIndex = $this->indexOf($declarations, 'OptionValue', self::ACTIVITY_TYPE_NAME);
        $customGroupIndex = $this->indexOf($declarations, 'CustomGroup', self::CUSTOM_GROUP_NAME);

        $this->assertNotNull($optionValueIndex, 'The activity-type OptionValue declaration is missing.');
        $this->assertNotNull($customGroupIndex, 'The Monthly_Project_Checkin CustomGroup declaration is missing.');

        $this->assertLessThan(
            $customGroupIndex,
            $optionValueIndex,
            'The activity-type OptionValue must be declared before the CustomGroup that scopes itself to it. '
            . 'Core creates managed records in array order, and extends_entity_column_value:name resolves '
            . 'against option values that already exist — reversed, the group is created UNSCOPED and its '
            . 'fields appear on every activity in CiviCRM, silently.'
        );
    }

    /**
     * Files that declare either record, other than the one that should.
     *
     * A helper rather than inline, so the dot-directory test can assert on the
     * same collection without calling another test method. Round 4 caught that
     * arrangement: the nested call was load-bearing, read as a redundant
     * re-check, and a future reader removing it would have taken the only real
     * assertion with it.
     *
     * @return string[] Root-relative "<path> declares <record>" descriptions.
     */
    private function collectOffenders(): array
    {
        $offenders = [];
        $root = realpath(self::EXTENSION_ROOT);
        foreach ($this->allManagedFiles() as $file) {
            if (realpath($file) === realpath(self::DECLARATION_FILE)) {
                continue;
            }
            $source = $this->stripComments((string) file_get_contents($file));
            foreach ([self::MANAGED_OPTION_VALUE_NAME, self::MANAGED_CUSTOM_GROUP_NAME] as $managedName) {
                // Both quote styles: this repo uses single quotes throughout,
                // but a guard a double-quoted duplicate walks straight past is
                // not worth the line it is written on.
                if (str_contains($source, "'" . $managedName . "'")
                    || str_contains($source, '"' . $managedName . '"')) {
                    // The PATH, not the basename: with a whole-tree walk the
                    // directory is what the reader needs in order to act.
                    $offenders[] = str_replace($root . '/', '', $file) . " declares {$managedName}";
                }
            }
        }
        return $offenders;
    }

    /**
     * Both declarations must live in one file, so no filename sort can separate
     * them again.
     *
     * TWO THINGS THIS GETS RIGHT THAT THE OBVIOUS VERSION DOES NOT, both caught
     * in review:
     *
     * It strips comments before matching. The first version compared raw file
     * contents, so any future `.mgd.php` that merely MENTIONS the activity type
     * would have failed it — and one is planned: P2-3's Ops dashboard rows are
     * naturally a SavedSearch filtering `activity_type_id:name` on exactly this
     * string. A guard that goes red when someone does the right thing gets
     * deleted, not obeyed. The sibling FrozenMachineNamesTest strips comments
     * for the mirror-image reason.
     *
     * It scans the whole tree, not one directory — core's `mgd-php@1` mixin
     * (the version `info.xml` declares) recursively collects every
     * `*.mgd.php` under the extension, so a duplicate declaration outside
     * `Civi/Mascode/Managed/` is just as real and was previously invisible.
     *
     * WHAT IT MATCHES, stated exactly, because an earlier docblock here
     * promised a structural match this does not perform: the managed RECORD
     * name as a quoted string literal, in either quote style, anywhere in the
     * comment-stripped source. It does not require an adjacent `'entity' =>`
     * key, and a record name assembled by concatenation would slip past. That
     * is a deliberate floor rather than a ceiling — the ordering test is the
     * primary catch, and a stricter parser here would be more code than the
     * risk justifies. Do not read more strictness into it than this paragraph
     * claims.
     */
    public function testNoOtherManagedFileDeclaresTheseRecords(): void
    {
        $offenders = $this->collectOffenders();
        $this->assertSame(
            [],
            $offenders,
            'The check-in activity type and its custom group must be declared in one file, in order. '
            . 'Splitting them across files reintroduces the filename-sort dependency, and a bad create is '
            . 'permanent: ' . implode('; ', $offenders)
        );
    }

    /**
     * A declaration inside a dot-directory is core's business, not ours.
     *
     * THIS EXISTS BECAUSE THE CLAIMED GUARD WAS VACUOUS. The pruning in
     * allManagedFiles() was justified by "the `.claude/worktrees/` copy would
     * otherwise be flagged" — and review measured that the worktree present at
     * the time contains neither record name, so deleting the pruning entirely
     * left every test green. An exclusion asserted by nothing is the same
     * shape as the option-group gap this file was extended to close.
     *
     * So the fixture is built rather than borrowed: a dot-directory holding a
     * real-looking duplicate declaration, which core would not load and this
     * guard must not flag.
     */
    public function testDeclarationsInsideDotDirectoriesAreIgnored(): void
    {
        // uniqid() because this checkout routinely has more than one session
        // working in it. A fixed path means two concurrent runs race, and one
        // run's cleanup unlinks the other's fixture mid-assertion — a failure
        // that reads exactly like a real defect.
        $base = self::EXTENSION_ROOT . '/.mascode-test-' . uniqid();
        $dir = $base . '/Civi/Mascode/Managed';
        $file = $dir . '/ActivityType_MonthlyProjectCheckin.mgd.php';

        // Setup INSIDE the try, so a failed assertion during setup still runs
        // the cleanup below. Outside it, a failing assertFileExists() leaves
        // the directory behind — a stray in a shared checkout, which this
        // repo's CLAUDE.md has a whole section about.
        try {
            $this->assertTrue(mkdir($dir, 0777, true), 'Could not create the fixture directory.');
            file_put_contents($file, "<?php\nreturn [[ 'name' => '" . self::MANAGED_CUSTOM_GROUP_NAME
                . "', 'entity' => 'CustomGroup', 'params' => ['version' => 4, 'values' => []] ]];\n");
            // A silently failed write would make this test pass while proving
            // nothing — the exact failure it was written to replace.
            $this->assertFileExists($file, 'The fixture declaration was not written.');

            // realpath() on BOTH sides. Round 4 measured that the raw $file
            // carries EXTENSION_ROOT's literal '../../..' while
            // allManagedFiles() returns normalised paths, so an unnormalised
            // needle could never match the haystack and this assertion passed
            // with or without the pruning it was written to guard.
            $this->assertNotContains(
                realpath($file),
                $this->allManagedFiles(),
                'A .mgd.php inside a dot-directory must be ignored, because core ignores it. This repository '
                . 'can hold a complete second copy of itself under .claude/worktrees/, and a worktree of a '
                . 'branch carrying these declarations would otherwise be reported as a duplicate of itself.'
            );

            $this->assertSame(
                [],
                $this->collectOffenders(),
                'A declaration inside a dot-directory must not be reported as a duplicate.'
            );
        } finally {
            @unlink($file);
            foreach (['/Civi/Mascode/Managed', '/Civi/Mascode', '/Civi', ''] as $suffix) {
                @rmdir($base . $suffix);
            }
        }
    }

    /**
     * The option value must be in the activity_type group, and active.
     *
     * NEITHER IS COSMETIC, and the first version of this file asserted neither
     * — which left the mutation closest to the actual bug green. The group's
     * scoping resolves `extends_entity_column_value:name` against the
     * activity_type option list. Move this declaration to a different option
     * group and the lookup finds nothing and writes NULL — the exact defect
     * this file exists to prevent — while every other assertion here still
     * passes, because they match the option value on its `name` alone.
     *
     * `is_active` matters for the same reason: core resolves the option list
     * through getFieldOptions() with `$includeDisabled = FALSE`, so a disabled
     * option value is not in the list either and resolves to NULL just as
     * quietly.
     */
    public function testOptionValueIsAnActiveActivityType(): void
    {
        $declarations = $this->declarations();
        $index = $this->indexOf($declarations, 'OptionValue', self::ACTIVITY_TYPE_NAME);
        $this->assertNotNull($index, 'The activity-type OptionValue declaration is missing.');

        $values = $declarations[$index]['params']['values'];

        $this->assertSame(
            'activity_type',
            $values['option_group_id.name'] ?? null,
            'The option value must live in the activity_type group. Anywhere else and the custom group\'s '
            . '`:name` scoping resolves against a list this value is not in, and lands NULL.'
        );
        // Truthy rather than identical-to-TRUE: CiviCRM treats 1 and TRUE
        // alike here, and a declaration written `'is_active' => 1` is correct
        // config that a strict-identity assertion would call a failure.
        $this->assertTrue(
            (bool) ($values['is_active'] ?? false),
            'A disabled option value is absent from the list core searches (getFieldOptions passes '
            . '$includeDisabled = FALSE), so the scoping would resolve to NULL.'
        );
    }

    /**
     * `extends` must sit in the same values array as the scoping.
     *
     * Core's option loader needs `extends` — or an `id`/`name` it can look
     * `extends` up from — to know WHICH option list to search. On the managed
     * UPDATE path a `name` is present in `values` and core injects the `id`
     * too, so omitting `extends` there would still resolve. It is CREATE that
     * breaks: the row does not exist yet, both fallbacks miss, and the write
     * lands NULL as silently as the ordering bug did. Create is also the only
     * case that matters, because a bad create is permanent (see the class
     * docblock).
     */
    public function testCustomGroupDeclaresExtendsAlongsideItsScoping(): void
    {
        $declarations = $this->declarations();
        $index = $this->indexOf($declarations, 'CustomGroup', self::CUSTOM_GROUP_NAME);
        $this->assertNotNull($index, 'The Monthly_Project_Checkin CustomGroup declaration is missing.');

        $values = $declarations[$index]['params']['values'];

        $this->assertSame('Activity', $values['extends'] ?? null, 'The group must extend Activity.');
        $this->assertSame(
            [self::ACTIVITY_TYPE_NAME],
            $values['extends_entity_column_value:name'] ?? null,
            'The group must scope itself to the check-in activity type BY NAME — a numeric value would not '
            . 'port from dev to production, where the option value gets a different number.'
        );
    }

    /**
     * The two answers are real Booleans, and only one of them may be required.
     *
     * `vc_will_ask` being nullable is load-bearing rather than lenient, and the
     * spec mandates it directly — §Data Model: "Boolean, nullable | Q2. NULL
     * when Q1 = No". NULL means the question was never put to them, because
     * answering No to is_complete hides it, and that is a different fact from
     * an answered No the moment anyone reports on the second question.
     *
     * (An earlier version of this docblock attributed the nullability to D8.
     * D8 governs when the OFFICE FOLLOW-UP fires — on (no VC ask) AND (signoff
     * returned with no donation) — and draws no NULL-vs-FALSE distinction at
     * all. The field is right; the citation was not.)
     */
    public function testAnswerFieldsAreNullableBooleans(): void
    {
        $declarations = $this->declarations();

        $fields = [];
        foreach ($declarations as $declaration) {
            if (($declaration['entity'] ?? null) === 'CustomField'
                && ($declaration['params']['values']['custom_group_id.name'] ?? null) === self::CUSTOM_GROUP_NAME) {
                $fields[$declaration['params']['values']['name']] = $declaration['params']['values'];
            }
        }

        $this->assertSame(
            ['is_complete', 'vc_will_ask', 'digest_round'],
            array_keys($fields),
            'The check-in records exactly three things; see the spec\'s Data Model table.'
        );

        foreach (['is_complete', 'vc_will_ask'] as $name) {
            $this->assertSame(
                'Boolean',
                $fields[$name]['data_type'],
                "{$name} must be a real Boolean. An option group would hand P1-5's send decision a string "
                . 'whose truthiness depends on the option value — the trap recorded in the mascode memory '
                . 'note feedback_afform_boolean_string_id_bug.'
            );
        }

        $this->assertFalse(
            $fields['vc_will_ask']['is_required'],
            'vc_will_ask must stay nullable: NULL is "not asked", which is a different fact from "answered No".'
        );
    }
}
