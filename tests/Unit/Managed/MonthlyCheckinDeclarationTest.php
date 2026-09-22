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
 * `mgd-php@2` mixin does `sort($mgdFiles)` and appends each file's array in
 * order, and ManagedEntities::reconcileEntities() walks the `create` plan in
 * that same order. Under the directory's own one-entity-per-file convention the
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
     * Mirrors mixin/mgd-php@2: the extension root, then `managed/`, `api/`,
     * `CRM/` and `Civi/` recursively. Scanning only one directory is how a
     * duplicate declaration stays invisible.
     */
    private function allManagedFiles(): array
    {
        $files = glob(self::EXTENSION_ROOT . '/*.mgd.php') ?: [];
        foreach (['managed', 'api', 'CRM', 'Civi'] as $dir) {
            $path = self::EXTENSION_ROOT . '/' . $dir;
            if (!is_dir($path)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));
            foreach ($iterator as $file) {
                if (str_ends_with($file->getFilename(), '.mgd.php')) {
                    $files[] = $file->getPathname();
                }
            }
        }
        return array_values(array_unique($files));
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
     * It scans the whole tree, not one directory. Core's `mgd-php@2` mixin
     * collects `$path/*.mgd.php` plus everything under `managed/`, `api/`,
     * `CRM/` and `Civi/` recursively, so a duplicate declaration outside
     * `Civi/Mascode/Managed/` is just as real and was previously invisible.
     *
     * What it looks for is therefore a DECLARATION of these records elsewhere —
     * `'name' => '<record>'` next to an `'entity' =>` key — not a mention.
     */
    public function testNoOtherManagedFileDeclaresTheseRecords(): void
    {
        $offenders = [];
        foreach ($this->allManagedFiles() as $file) {
            if (realpath($file) === realpath(self::DECLARATION_FILE)) {
                continue;
            }
            $source = $this->stripComments(file_get_contents($file));
            foreach ([self::MANAGED_OPTION_VALUE_NAME, self::MANAGED_CUSTOM_GROUP_NAME] as $managedName) {
                if (str_contains($source, "'" . $managedName . "'")) {
                    $offenders[] = basename($file) . " declares {$managedName}";
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'The check-in activity type and its custom group must be declared in one file, in order. '
            . 'Splitting them across files reintroduces the filename-sort dependency, and a bad create is '
            . 'permanent: ' . implode('; ', $offenders)
        );
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
        $this->assertTrue(
            $values['is_active'] ?? false,
            'A disabled option value is absent from the list core searches (getFieldOptions passes '
            . '$includeDisabled = FALSE), so the scoping would resolve to NULL.'
        );
    }

    /**
     * `extends` must sit in the same values array as the scoping.
     *
     * Core's option loader needs `extends` (or an id/name it can look one up
     * from) to know WHICH option list to search. A values array carrying
     * `extends_entity_column_value:name` without `extends` resolves to NULL
     * just as silently as the ordering bug did — verified on dev by issuing
     * both updates and reading the column back.
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
     * `vc_will_ask` being nullable is load-bearing rather than lenient: NULL
     * means "the question was never put to them", because answering No to
     * is_complete hides it. D8 queues the office follow-up on a client nobody
     * asked, so making this required would collapse "not asked" into "answered
     * No" and manufacture work items.
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
