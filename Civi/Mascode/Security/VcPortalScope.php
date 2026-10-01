<?php

declare(strict_types=1);

namespace Civi\Mascode\Security;

/**
 * The VC portal's scope clauses: how a portal saved search says "only the cases this VC may see"
 * without a second copy of that rule (ticket T12; VC access spec D7, D13, D19).
 *
 * WHY A PLACEHOLDER. The rule lives once, in mascode's scope searches
 * (SavedSearch_MAS_VC_Scope_Sets.mgd.php), which the MAS CiviCRM MCP already runs through
 * Civi\Mascode\Mcp\Vc\VcScopeResolver. SearchKit cannot filter one search by another, so a portal
 * search carries a placeholder clause — `['id', 'IN', [self::SENTINELS['cases']]]` — and
 * VcPortalScopeSubscriber replaces it, on the search's own APIv4 query, with the ids the resolver
 * returns for the signed-in contact. The portal and the MCP therefore read the same sets from the
 * same code: own, pool, cases and contacts are the resolver's S_own_cases, S_pool_cases, S_cases and
 * S_contacts; consented is the D22 subset of cases (see the subscriber).
 *
 * WHY IT FAILS CLOSED. A sentinel is a negative id, which matches no row. If the subscriber does not
 * run, the resolver refuses (no contact, a drifted scope search, a stale relationship cache), or a
 * set is empty, the clause is left as it is and the display returns nothing. Only a clause in an
 * AND position is replaced — a top-level `where` clause or a top-level join condition — so a
 * placeholder under OR or NOT, or one written with any operator but IN, stays inert. Every clause a
 * caller can add (a SearchKit filter, an af-field) is ANDed beside it, so it can only narrow.
 *
 * Pure functions over arrays; the subscriber supplies the sets. Unit-tested in
 * tests/Unit/Security/VcPortalScopeTest.php.
 */
final class VcPortalScope
{
    /** Set name => placeholder id. Negative, so an unreplaced placeholder matches nothing. */
    public const SENTINELS = [
        'own' => -7000001,
        'pool' => -7000002,
        'cases' => -7000003,
        'contacts' => -7000004,
        'consented' => -7000005,
    ];

    /**
     * Declaration files of the portal's saved searches and displays. Each stored copy must equal its
     * declaration before it runs (VcPortalScopeSubscriber; scripts/check-vc-portal.php).
     */
    public const DECLARATION_FILES = [
        'Civi/Mascode/Managed/SavedSearch_My_Cases.mgd.php',
        'Civi/Mascode/Managed/SavedSearch_Service_Requests_Send_for_Assignment.mgd.php',
        'Civi/Mascode/Managed/SavedSearch_MAS_VC_Org_Cases.mgd.php',
        'Civi/Mascode/Managed/SavedSearch_Case_Details_VC.mgd.php',
        'Civi/Mascode/Managed/SavedSearch_Case_Details_VC_Sections.mgd.php',
        'Civi/Mascode/Managed/SavedSearch_Case_Details_VC_Fields.mgd.php',
    ];

    /**
     * The placeholder clause for $set on $field (default: the base entity's id).
     *
     * @return array{0: string, 1: string, 2: int[]}
     */
    public static function clause(string $set, string $field = 'id'): array
    {
        if (!isset(self::SENTINELS[$set])) {
            throw new \InvalidArgumentException("Unknown VC portal scope set: $set");
        }
        return [$field, 'IN', [self::SENTINELS[$set]]];
    }

    /**
     * The set named by $clause if it is exactly a placeholder clause, else NULL.
     */
    public static function setOf(mixed $clause): ?string
    {
        if (
            !is_array($clause) || !array_is_list($clause) || count($clause) !== 3
            || !is_string($clause[0]) || $clause[1] !== 'IN'
            || !is_array($clause[2]) || !array_is_list($clause[2]) || count($clause[2]) !== 1
            || !is_int($clause[2][0])
        ) {
            return null;
        }
        $set = array_search($clause[2][0], self::SENTINELS, true);
        return $set === false ? null : $set;
    }

    /**
     * The sets named by placeholders in AND positions of $where and $join.
     *
     * @return string[]
     */
    public static function setsIn(array $where, array $join): array
    {
        $sets = [];
        foreach (self::andPositions($where, $join) as $clause) {
            $set = self::setOf($clause);
            if ($set !== null) {
                $sets[$set] = true;
            }
        }
        return array_keys($sets);
    }

    /**
     * Replace each placeholder in an AND position whose set is non-empty in $sets.
     *
     * @param array<string, int[]> $sets set name => ids (positive); a missing or empty set leaves its
     *   placeholder in place, which matches nothing
     * @return array{0: array, 1: array} [$where, $join]
     */
    public static function substitute(array $where, array $join, array $sets): array
    {
        foreach ($where as $i => $clause) {
            $where[$i] = self::replace($clause, $sets);
        }
        foreach ($join as $j => $spec) {
            if (!is_array($spec)) {
                continue;
            }
            // [entity AS alias, side, (bridge,) condition, condition ...]: conditions are the arrays.
            foreach ($spec as $k => $part) {
                if (is_int($k) && $k >= 2 && is_array($part)) {
                    $join[$j][$k] = self::replace($part, $sets);
                }
            }
        }
        return [$where, $join];
    }

    /**
     * Canonical form for a drift comparison: string-keyed maps key-sorted, lists kept in order.
     * NULL (always drift) for an array with any integer key that is not a list — APIv4 reads some
     * arrays by position (an explicit join's entity and side), so sorting such an array back into
     * order would hide a changed meaning. Same rule as VcScopeResolver::canon (PR #10 review H1).
     */
    public static function canon(mixed $v): mixed
    {
        if (!is_array($v)) {
            return $v;
        }
        if (!array_is_list($v)) {
            foreach (array_keys($v) as $k) {
                if (!is_string($k)) {
                    return null;
                }
            }
            ksort($v);
        }
        $out = [];
        foreach ($v as $k => $item) {
            $c = self::canon($item);
            if ($c === null && $item !== null) {
                return null;
            }
            $out[$k] = $c;
        }
        return $out;
    }

    /** TRUE when $stored equals $declared after canonicalising, and neither is malformed. */
    public static function same(mixed $stored, mixed $declared): bool
    {
        $a = self::canon($stored);
        $b = self::canon($declared);
        if (($a === null && $stored !== null) || ($b === null && $declared !== null)) {
            return false;
        }
        return json_encode($a) === json_encode($b);
    }

    /** @param array<string, int[]> $sets */
    private static function replace(mixed $clause, array $sets): mixed
    {
        $set = self::setOf($clause);
        if ($set === null || empty($sets[$set])) {
            return $clause;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $sets[$set]), fn(int $i) => $i > 0)));
        return $ids ? [$clause[0], 'IN', $ids] : $clause;
    }

    /** @return iterable<mixed> top-level where clauses and top-level join conditions */
    private static function andPositions(array $where, array $join): iterable
    {
        yield from $where;
        foreach ($join as $spec) {
            if (is_array($spec)) {
                foreach ($spec as $k => $part) {
                    if (is_int($k) && $k >= 2 && is_array($part)) {
                        yield $part;
                    }
                }
            }
        }
    }
}
