<?php

namespace Civi\Mascode\Mcp\Tests\Live;

use Civi\Api4\Relationship;
use Civi\Api4\RelationshipCache;
use Civi\Mascode\Mcp\Vc\Api4VcScopeSource;
use Civi\Mascode\Mcp\Vc\VcScopeResolver;
use PHPUnit\Framework\TestCase;

/**
 * Level 3 for T5 scope resolution (VC access spec D19): the Cases and Employees sets derived in PHP
 * equal mascode's MAS_VC_Scope_Cases / _Employees searches, which the portal runs. Read-only; prints
 * and asserts counts only.
 *
 * VCs checked: the WordPress login `test.vc`, plus any contact IDs in MCP_LIVE_VC_IDS (comma list).
 *
 * @group live
 */
class LiveVcScopeTest extends TestCase {

  protected function setUp(): void {
    if (!getenv('MCP_LIVE_USER') || !class_exists('Civi')) {
      $this->markTestSkipped('Set MCP_LIVE_USER and run inside a CiviCRM site.');
    }
  }

  /** @return int[] */
  private function vcIds(): array {
    $ids = array_filter(array_map('intval', explode(',', (string) getenv('MCP_LIVE_VC_IDS'))));
    $user = function_exists('get_user_by') ? get_user_by('login', 'test.vc') : FALSE;
    if ($user) {
      $match = \Civi\Api4\UFMatch::get(FALSE)->addSelect('contact_id')->addWhere('uf_id', '=', $user->ID)->execute()->first();
      if ($match) {
        $ids[] = (int) $match['contact_id'];
      }
    }
    if (!$ids) {
      $this->markTestSkipped('No test.vc user and no MCP_LIVE_VC_IDS.');
    }
    return array_values(array_unique($ids));
  }

  /** The stored search with the placeholder replaced, run as the resolver runs its own three. */
  private function stored(string $name, int $me): array {
    $source = new Api4VcScopeSource();
    $search = $source->storedSearches([$name])[$name];
    $params = json_decode(str_replace('"user_contact_id"', (string) $me, json_encode($search['api_params'])), TRUE);
    unset($params['version']);
    $params['checkPermissions'] = FALSE;
    $ids = $source->runSearch($search['api_entity'], $params);
    sort($ids);
    return $ids;
  }

  public function testDerivedSetsEqualTheMascodeSearches(): void {
    foreach ($this->vcIds() as $n => $me) {
      $scope = (new VcScopeResolver(new Api4VcScopeSource()))->resolve($me);
      $this->assertSame($this->stored('MAS_VC_Scope_Cases', $me), $scope->cases, "VC #$n: derived cases == MAS_VC_Scope_Cases");
      $this->assertSame($this->stored('MAS_VC_Scope_Employees', $me), $scope->employees, "VC #$n: derived employees == MAS_VC_Scope_Employees");
      $this->assertSame([], array_intersect($scope->contacts, $scope->named), "VC #$n: a named contact is never also a full contact");
      $this->assertContains($me, $scope->contacts);
    }
  }

  /**
   * The scope searches read RelationshipCache; the resolver cross-checks own cases against
   * Relationship at runtime, and this gate covers the rest (mascode 1.1.36 CHANGELOG, PR #61 and
   * PR #10 reviews). Counts every cache row of the two scope types — matched by the cache's own
   * relationship_type_id AND by the relationship's, so a type change on either side is caught —
   * that is orphaned or out of step on orientation, near_relation, contacts, is_active or case_id,
   * and every relationship missing one of its two rows. Gate on zero.
   */
  public function testRelationshipCacheIsInStepForScopeTypes(): void {
    $typeIds = array_map('intval', array_column(\Civi\Api4\RelationshipType::get(FALSE)->addSelect('id')
      ->addWhere('name_a_b', 'IN', ['Case Coordinator is', 'Employee of'])->execute()->getArrayCopy(), 'id'));
    $this->assertCount(2, $typeIds, 'both scope relationship types resolve by name');
    $rels = [];
    foreach (Relationship::get(FALSE)->addSelect('id', 'relationship_type_id', 'relationship_type_id.name_a_b', 'relationship_type_id.name_b_a', 'contact_id_a', 'contact_id_b', 'is_active', 'case_id')
      ->addWhere('relationship_type_id', 'IN', $typeIds)->execute() as $r) {
      $rels[(int) $r['id']] = $r;
    }
    $cache = RelationshipCache::get(FALSE)
      ->addSelect('relationship_id', 'relationship_type_id', 'orientation', 'near_relation', 'near_contact_id', 'far_contact_id', 'is_active', 'case_id')
      ->addClause('OR', ['relationship_type_id', 'IN', $typeIds], ['relationship_id', 'IN', array_keys($rels) ?: [0]])
      ->execute();
    $problems = ['orphaned' => 0, 'type' => 0, 'orientation' => 0, 'near_relation' => 0, 'contacts' => 0, 'is_active' => 0, 'case_id' => 0];
    foreach ($cache as $c) {
      $r = $rels[(int) $c['relationship_id']] ?? NULL;
      if ($r === NULL) {
        // Deleted, or re-typed to another type while the cache still says a scope type.
        $problems['orphaned']++;
        continue;
      }
      // The searches match near_relation by name, so a stale name is drift as much as a stale id.
      $problems['orientation'] += (int) !in_array($c['orientation'], ['a_b', 'b_a'], TRUE);
      $problems['near_relation'] += (int) ($c['near_relation'] !== $r[$c['orientation'] === 'a_b' ? 'relationship_type_id.name_a_b' : 'relationship_type_id.name_b_a']);
      [$near, $far] = $c['orientation'] === 'a_b' ? [$r['contact_id_a'], $r['contact_id_b']] : [$r['contact_id_b'], $r['contact_id_a']];
      $problems['type'] += (int) ((int) $c['relationship_type_id'] !== (int) $r['relationship_type_id']);
      $problems['contacts'] += (int) ((int) $c['near_contact_id'] !== (int) $near || (int) $c['far_contact_id'] !== (int) $far);
      $problems['is_active'] += (int) ((bool) $c['is_active'] !== (bool) $r['is_active']);
      $problems['case_id'] += (int) ((int) $c['case_id'] !== (int) $r['case_id']);
    }
    // Every scope relationship has both cache rows (a missing row narrows, but signals the same fault).
    $perRel = array_count_values(array_map(fn($c) => (int) $c['relationship_id'], (array) $cache->getArrayCopy()));
    $problems['missing'] = count(array_filter(array_keys($rels), fn($id) => ($perRel[$id] ?? 0) !== 2));
    $this->assertSame(0, array_sum($problems), 'RelationshipCache out of step for scope types: ' . json_encode($problems));
  }

}
