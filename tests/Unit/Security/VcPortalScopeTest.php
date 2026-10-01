<?php

namespace Civi\Mascode\Test\Unit\Security;

use Civi\Mascode\Security\VcPortalScope;
use Civi\Mascode\Test\TestCase;

/**
 * The VC portal's scope placeholders (ticket T12): which clauses are filled, which stay inert, and
 * the drift comparison. Pure functions over arrays — no CiviCRM. Also reads every portal
 * declaration file and checks the rules the live test checks (tests/Security/VcPortalScopeTest.php),
 * so a search that loses its placeholder fails CI, not only masdemo.
 *
 * @covers \Civi\Mascode\Security\VcPortalScope
 */
class VcPortalScopeTest extends TestCase
{
    private const SETS = ['cases' => [5, 7], 'contacts' => [9], 'own' => []];

    public function testTopLevelWherePlaceholderIsFilled(): void
    {
        [$where] = VcPortalScope::substitute([VcPortalScope::clause('cases'), ['status_id', '=', 1]], [], self::SETS);
        $this->assertSame([['id', 'IN', [5, 7]], ['status_id', '=', 1]], $where);
    }

    public function testJoinConditionPlaceholderIsFilled(): void
    {
        $join = [['Contact AS shown', 'LEFT', ['a.contact_id', '=', 'shown.id'], VcPortalScope::clause('contacts', 'shown.id')]];
        [, $out] = VcPortalScope::substitute([], $join, self::SETS);
        $this->assertSame(['shown.id', 'IN', [9]], $out[0][3]);
        // The entity, side and the other condition are untouched.
        $this->assertSame(array_slice($join[0], 0, 3), array_slice($out[0], 0, 3));
    }

    public function testBridgeJoinPositionsAreSkipped(): void
    {
        $join = [['Contact AS c', 'LEFT', 'CaseContact', ['id', '=', 'c.case_id'], VcPortalScope::clause('contacts', 'c.id')]];
        [, $out] = VcPortalScope::substitute([], $join, self::SETS);
        $this->assertSame('CaseContact', $out[0][2]);
        $this->assertSame(['c.id', 'IN', [9]], $out[0][4]);
    }

    public function testEmptyOrMissingSetLeavesThePlaceholderWhichMatchesNothing(): void
    {
        $own = VcPortalScope::clause('own');
        $pool = VcPortalScope::clause('pool');
        [$where] = VcPortalScope::substitute([$own, $pool], [], self::SETS);
        $this->assertSame([$own, $pool], $where);
        $this->assertLessThan(0, $own[2][0]);
        $this->assertLessThan(0, $pool[2][0]);
    }

    public function testNonPositiveIdsAreDropped(): void
    {
        [$where] = VcPortalScope::substitute([VcPortalScope::clause('cases')], [], ['cases' => [0, -3, 4, 4]]);
        $this->assertSame([['id', 'IN', [4]]], $where);
        [$where] = VcPortalScope::substitute([VcPortalScope::clause('cases')], [], ['cases' => [0, -3]]);
        $this->assertSame([VcPortalScope::clause('cases')], $where);
    }

    /** A placeholder anywhere but an AND position stays inert: under OR or NOT it would widen. */
    public function testNestedOrOtherOperatorPlaceholdersAreNotFilled(): void
    {
        $s = VcPortalScope::SENTINELS['cases'];
        $where = [
            ['OR', [VcPortalScope::clause('cases'), ['status_id', '=', 1]]],
            ['NOT', [VcPortalScope::clause('cases')]],
            ['id', 'NOT IN', [$s]],
            ['id', '=', $s],
            ['id', 'IN', [$s, 5]],
            ['id', 'IN', ["$s"]],
        ];
        [$out] = VcPortalScope::substitute($where, [], self::SETS);
        $this->assertSame($where, $out);
        $this->assertSame([], VcPortalScope::setsIn($where, []));
    }

    public function testSetsInNamesEachSetOnce(): void
    {
        $join = [['Contact AS shown', 'LEFT', VcPortalScope::clause('contacts', 'shown.id')]];
        $sets = VcPortalScope::setsIn([VcPortalScope::clause('cases'), VcPortalScope::clause('cases')], $join);
        sort($sets);
        $this->assertSame(['cases', 'contacts'], $sets);
    }

    public function testUnknownSetIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        VcPortalScope::clause('everyone');
    }

    public function testSentinelsAreDistinctAndNegative(): void
    {
        $values = array_values(VcPortalScope::SENTINELS);
        $this->assertSame($values, array_values(array_unique($values)));
        foreach ($values as $v) {
            $this->assertLessThan(0, $v);
        }
    }

    public function testDriftComparisonSortsMapsButNotPositionalArrays(): void
    {
        $declared = ['select' => ['id', 'subject'], 'join' => [['Contact AS c', 'LEFT', ['id', '=', 'c.id']]], 'where' => []];
        $this->assertTrue(VcPortalScope::same(['where' => [], 'join' => $declared['join'], 'select' => ['id', 'subject']], $declared));
        $this->assertFalse(VcPortalScope::same(['select' => ['subject', 'id']] + $declared, $declared));
        // An integer-keyed array out of list order is drift, even when sorting would restore it:
        // APIv4 reads a join's entity and side by position (PR #10 review H1).
        $shuffled = $declared;
        $shuffled['join'][0] = [1 => 'LEFT', 0 => 'Contact AS c', 2 => ['id', '=', 'c.id']];
        $this->assertFalse(VcPortalScope::same($shuffled, $declared));
        $this->assertFalse(VcPortalScope::same(['where' => [VcPortalScope::clause('cases')]] + $declared, $declared));
    }

    /**
     * Every portal declaration: a placeholder in an AND position, no activity details, no edit.
     * Includes the .mgd.php files, which is code outside the class under test: hence
     * @coversNothing, or strict coverage marks it risky and fails CI.
     *
     * @coversNothing
     */
    public function testEveryPortalDeclarationKeepsTheRules(): void
    {
        $root = dirname(__DIR__, 3);
        $searches = 0;
        foreach (VcPortalScope::DECLARATION_FILES as $file) {
            $this->assertFileExists("$root/$file");
            foreach ((array) (include "$root/$file") as $decl) {
                $values = $decl['params']['values'];
                $this->assertSame('always', $decl['update'], "{$decl['name']}: update must be 'always'");
                if ($decl['entity'] === 'SavedSearch') {
                    $searches++;
                    $p = $values['api_params'];
                    $this->assertNotSame([], VcPortalScope::setsIn($p['where'], $p['join'] ?? []), "{$values['name']}: no scope placeholder");
                    $this->assertStringNotContainsString('user_contact_id', json_encode($p), "{$values['name']}: hand-written gate");
                    // Only the base Case's own `details`; never `details` at the end of a joined or
                    // implicit path, however it reaches an activity (D37).
                    foreach ($p['select'] as $f) {
                        $this->assertDoesNotMatchRegularExpression('/\.details\b/', $f, "{$values['name']}: selects $f (D37)");
                        if ($values['api_entity'] !== 'Case') {
                            $this->assertDoesNotMatchRegularExpression('/(^|\W)details\b/', $f, "{$values['name']}: selects $f (D37)");
                        }
                    }
                }
                else {
                    $this->assertTrue($values['acl_bypass'], "{$values['name']}: portal displays are acl_bypass");
                    $json = json_encode($values['settings']);
                    foreach (['"editable"', '"editableRow"', '"draggable"'] as $bad) {
                        $this->assertStringNotContainsString($bad, $json, "{$values['name']}: $bad on an acl_bypass display");
                    }
                }
            }
        }
        $this->assertSame(13, $searches, 'portal search count changed: review the new one, then update this number');
    }
}
