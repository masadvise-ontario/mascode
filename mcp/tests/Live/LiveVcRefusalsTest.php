<?php

namespace Civi\Mascode\Mcp\Tests\Live;

use Civi\Mascode\Mcp\Vc\Api4VcScopeSource;
use Civi\Mascode\Mcp\Vc\VcScope;
use Civi\Mascode\Mcp\Vc\VcScopePolicy;
use Civi\Mascode\Mcp\Vc\VcScopeResolver;
use Civi\Mcp\Protocol\ToolException;
use Civi\Mcp\Scoped\Api4Executor;
use Civi\Mcp\Scoped\ScopedQuery;
use Civi\Mcp\Tools\ToolContext;
use PHPUnit\Framework\TestCase;

/**
 * T10 gaps (9) and (10) on the real site, per entity, as each test VC, through ScopedQuery (the
 * engine `vc_query` runs):
 *
 *  - Oracle (vc-activity-policy.md §4): `where` and `orderBy` on any text field, any `:label` /
 *    `:name` form and any client-feedback field are refused, for every entity.
 *  - Join paths: a dotted path through any listed foreign key is refused in select and in where.
 *  - Forged ids: rows outside the caller's scope come back empty when named by id, also inside an
 *    OR with an in-scope id.
 *  - S3: an activity type the policy does not list is never returned, even when named in where or
 *    combined with an OR, including activities of such types on the caller's own cases.
 *
 * VCs: the login `test.vc` plus MCP_LIVE_VC_IDS. Read-only; asserts ids and counts only.
 *
 * @group live
 */
class LiveVcRefusalsTest extends TestCase {

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

  private static function engine(): ScopedQuery {
    return new ScopedQuery(VcScopePolicy::forSite(), \Closure::fromCallable(new Api4Executor()));
  }

  private static function scope(int $me): VcScope {
    return (new VcScopeResolver(new Api4VcScopeSource()))->resolve($me);
  }

  /** @return array<string, string[]> entity => every field name the policy lists for it */
  private static function listed(): array {
    $out = [];
    foreach (VcScopePolicy::ENTITIES as $entity => $spec) {
      $out[$entity] = $spec['fields'];
    }
    $out['Case'] = array_merge($out['Case'], VcScopePolicy::CLIENT_FEEDBACK, [VcScopePolicy::SHARE_FIELD, 'Project_Close_Client.use_in_marketing']);
    $out['Activity'] = VcScopePolicy::ACTIVITY_FIELDS;
    return $out;
  }

  /** @return array<string, string> field => APIv4 data type */
  private static function types(string $entity): array {
    return array_column(civicrm_api4($entity, 'getFields', ['checkPermissions' => FALSE, 'action' => 'get', 'select' => ['name', 'data_type']])->getArrayCopy(), 'data_type', 'name');
  }

  private function refused(ScopedQuery $q, int $me, array $args, string $label): void {
    try {
      $q->run($args + ['limit' => 1], new ToolContext($me, 5));
      $this->fail("not refused: $label");
    }
    catch (ToolException) {
      $this->addToAssertionCount(1);
    }
  }

  /** §4: no text field, no suffix form and no gated field is filterable or sortable, for every entity. */
  public function testNoOracleOnTextOrGatedFields(): void {
    $me = $this->vcIds()[0];
    $q = self::engine();
    $tried = 0;
    foreach (self::listed() as $entity => $fields) {
      $types = self::types($entity);
      $gated = $entity === 'Case' ? array_merge(VcScopePolicy::CLIENT_FEEDBACK, [VcScopePolicy::SHARE_FIELD, 'Project_Close_Client.use_in_marketing']) : [];
      $demotable = $entity === 'Activity' ? VcScopePolicy::ACTIVITY_DEMOTABLE : ($entity === 'Contact' ? array_diff($fields, VcScopePolicy::NAMED_FIELDS) : []);
      foreach ($fields as $f) {
        $text = in_array($types[explode(':', $f)[0]] ?? '', ['String', 'Text', 'Memo'], TRUE);
        if (!$text && !str_contains($f, ':') && !in_array($f, $gated, TRUE) && !in_array($f, $demotable, TRUE)) {
          continue;
        }
        $this->refused($q, $me, ['entity' => $entity, 'select' => ['id'], 'where' => [[$f, '=', 'x']]], "$entity where $f");
        $this->refused($q, $me, ['entity' => $entity, 'select' => ['id'], 'where' => [['OR', [['id', '>', 0], [$f, 'LIKE', '%x%']]]]], "$entity where OR $f");
        $this->refused($q, $me, ['entity' => $entity, 'select' => ['id'], 'orderBy' => [$f => 'ASC']], "$entity orderBy $f");
        $tried++;
      }
    }
    // Activity subject/details and Case subject at least.
    $this->assertGreaterThanOrEqual(3, $tried);
  }

  /** No dotted path through a listed foreign key, in select or where, for every entity. */
  public function testJoinPathsAreRefused(): void {
    $me = $this->vcIds()[0];
    $q = self::engine();
    $tried = 0;
    foreach (self::listed() as $entity => $fields) {
      $fks = array_filter($fields, fn($f) => !str_contains($f, ':') && !str_contains($f, '.') && ($f === 'id' || str_ends_with($f, '_id') || str_ends_with($f, '_id_a') || str_ends_with($f, '_id_b')));
      foreach ($fks as $fk) {
        foreach (['id', 'display_name', 'subject', 'details'] as $far) {
          $this->refused($q, $me, ['entity' => $entity, 'select' => ['id', "$fk.$far"]], "$entity select $fk.$far");
          $this->refused($q, $me, ['entity' => $entity, 'select' => ['id'], 'where' => [["$fk.$far", 'IS NOT NULL']]], "$entity where $fk.$far");
        }
        $tried++;
      }
    }
    $this->assertGreaterThan(5, $tried);
  }

  /**
   * Up to $n ids of $entity rows OUTSIDE the scope, read as staff.
   *
   * @return int[]
   */
  private static function outside(string $entity, VcScope $s, int $n = 100): array {
    $where = match ($entity) {
      'Case' => [['id', 'NOT IN', $s->cases ?: [0]]],
      'CaseContact' => [['case_id', 'NOT IN', $s->cases ?: [0]]],
      'Contact' => [['id', 'NOT IN', array_merge($s->contacts, $s->named) ?: [0]]],
      'Email', 'Phone', 'Address' => [['contact_id', 'NOT IN', $s->contacts ?: [0]]],
      'Relationship' => [['OR', [['case_id', 'NOT IN', $s->cases ?: [0]], ['AND', [['case_id', 'IS NULL'], ['OR', [['contact_id_a', 'NOT IN', $s->contacts ?: [0]], ['contact_id_b', 'NOT IN', $s->contacts ?: [0]]]]]]]]],
      'Activity' => [['case_id', 'IS NOT NULL'], ['case_id', 'NOT IN', $s->cases ?: [0]]],
    };
    if ($entity === 'Relationship') {
      // A case role on an in-scope case is in scope whatever its contacts: exclude those.
      $where = [['OR', [['case_id', 'NOT IN', $s->cases ?: [0]], ['case_id', 'IS NULL']]], ...$where];
    }
    $rows = civicrm_api4($entity, 'get', ['checkPermissions' => FALSE, 'select' => ['id'], 'where' => $where, 'orderBy' => ['id' => 'DESC'], 'limit' => $n]);
    return array_map('intval', array_column($rows->getArrayCopy(), 'id'));
  }

  public function testForgedIdsComeBackEmpty(): void {
    foreach ($this->vcIds() as $me) {
      $s = self::scope($me);
      $q = self::engine();
      foreach (array_keys(self::listed()) as $entity) {
        $forged = self::outside($entity, $s);
        if (!$forged) {
          continue;
        }
        $out = $q->run(['entity' => $entity, 'select' => ['id'], 'where' => [['id', 'IN', $forged]], 'limit' => 200], new ToolContext($me, 200));
        $this->assertSame(0, $out['matched'], "VC $me: $entity forged ids returned");
        $out = $q->run(['entity' => $entity, 'select' => ['id'], 'where' => [['OR', [['id', 'IN', $forged], ['id', '>', 0]]]], 'limit' => 200], new ToolContext($me, 200));
        $got = array_map(fn($r) => (int) $r['id'], $out['rows']);
        $this->assertSame([], array_values(array_intersect($got, $forged)), "VC $me: $entity forged ids returned through OR");
      }
      // Case and Contact always have rows outside any VC's scope on a real site.
      $this->assertNotEmpty(self::outside('Case', $s), 'no out-of-scope case to forge');
    }
  }

  /** S3: an unlisted activity type never comes back, even named in where or in an OR. */
  public function testUnlistedActivityTypesNeverReturn(): void {
    $listed = array_merge(VcScopePolicy::ACTIVITY_FULL, VcScopePolicy::ACTIVITY_TYPE_DATE);
    $unlistedTypes = array_map('intval', array_column(\Civi\Api4\OptionValue::get(FALSE)->addSelect('value')
      ->addWhere('option_group_id:name', '=', 'activity_type')->addWhere('name', 'NOT IN', $listed)->execute()->getArrayCopy(), 'value'));
    $this->assertNotEmpty($unlistedTypes);
    $onOwnCases = 0;
    foreach ($this->vcIds() as $me) {
      $s = self::scope($me);
      $q = self::engine();
      // Activities of unlisted types on the VC's OWN in-scope cases: the scope alone would admit them.
      $hidden = $s->cases ? array_map('intval', array_column(civicrm_api4('Activity', 'get', ['checkPermissions' => FALSE, 'select' => ['id'],
        'where' => [['case_id', 'IN', $s->cases], ['activity_type_id', 'IN', $unlistedTypes], ['is_deleted', '=', FALSE]], 'limit' => 200])->getArrayCopy(), 'id')) : [];
      $onOwnCases += count($hidden);
      $attempts = [
        [['activity_type_id', 'IN', array_slice($unlistedTypes, 0, 200)]],
        [['OR', [['activity_type_id', 'IN', array_slice($unlistedTypes, 0, 200)], ['id', '>', 0]]]],
      ];
      if ($hidden) {
        $attempts[] = [['id', 'IN', $hidden]];
        $attempts[] = [['OR', [['id', 'IN', $hidden], ['activity_type_id', 'IN', array_slice($unlistedTypes, 0, 200)]]]];
        $attempts[] = [['NOT', [['activity_type_id', 'NOT IN', array_slice($unlistedTypes, 0, 200)]]]];
      }
      foreach ($attempts as $i => $where) {
        $offset = 0;
        do {
          $out = $q->run(['entity' => 'Activity', 'select' => ['id', 'activity_type_id'], 'where' => $where, 'orderBy' => ['id' => 'ASC'], 'limit' => 200, 'offset' => $offset], new ToolContext($me, 200));
          foreach ($out['rows'] as $row) {
            $this->assertNotContains((int) $row['activity_type_id'], $unlistedTypes, "VC $me attempt $i: unlisted type returned");
            $this->assertNotContains((int) $row['id'], $hidden, "VC $me attempt $i: hidden activity returned");
          }
          $offset += count($out['rows']);
        } while ($out['truncated'] && $out['rows']);
      }
    }
    // Otherwise the in-scope half of the test proves nothing.
    $this->assertGreaterThan(0, $onOwnCases, 'no unlisted-type activity on any test VC case');
  }

}
