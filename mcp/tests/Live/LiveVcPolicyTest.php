<?php

namespace Civi\Mascode\Mcp\Tests\Live;

use Civi\Api4\CiviCase;
use Civi\Api4\CustomField;
use Civi\Mascode\Mcp\Vc\Api4VcScopeSource;
use Civi\Mascode\Mcp\Vc\VcScopePolicy;
use Civi\Mascode\Mcp\Vc\VcScopeResolver;
use Civi\Mcp\Scoped\Api4Executor;
use Civi\Mcp\Scoped\ScopedQuery;
use Civi\Mcp\Tools\ToolContext;
use PHPUnit\Framework\TestCase;

/**
 * Level 3 for T5b-2: the VC entity policies against real APIv4 (masdemo). Every allowlisted field
 * is accepted, every row stays inside the caller's scope, named contacts are names only, and the
 * client-feedback gate follows the RAW share answer. Read-only; asserts counts and ids only.
 *
 * VCs: the WordPress login `test.vc`, plus contact IDs in MCP_LIVE_VC_IDS.
 *
 * @group live
 */
class LiveVcPolicyTest extends TestCase {

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

  /** Run one entity with every returnable field, in chunks under the select cap, paging through every row. */
  private function all(ScopedQuery $q, string $entity, int $me): array {
    $names = $entity === 'Activity' ? VcScopePolicy::ACTIVITY_FIELDS : VcScopePolicy::ENTITIES[$entity]['fields'];
    if ($entity === 'Case') {
      $names = array_merge($names, VcScopePolicy::CLIENT_FEEDBACK);
    }
    $rows = [];
    foreach (array_chunk($names, ScopedQuery::MAX_SELECT - 1) as $chunk) {
      $offset = 0;
      do {
        $out = $q->run(['entity' => $entity, 'select' => array_values(array_unique(array_merge(['id'], $chunk))), 'orderBy' => ['id' => 'ASC'], 'limit' => 200, 'offset' => $offset], new ToolContext($me, 200));
        foreach ($out['rows'] as $i => $row) {
          $rows[$offset + $i] = ($rows[$offset + $i] ?? []) + $row;
        }
        // Step by what came back, not by the limit asked for (PR #12 review L).
        if ($out['truncated']) {
          $this->assertNotEmpty($out['rows'], 'a truncated page returned no rows');
        }
        $offset += count($out['rows']);
      } while ($out['truncated'] && $out['rows']);
    }
    return $rows;
  }

  public function testEveryEntityStaysInScope(): void {
    foreach ($this->vcIds() as $n => $me) {
      $scope = (new VcScopeResolver(new Api4VcScopeSource()))->resolve($me);
      $q = new ScopedQuery(VcScopePolicy::forSite(), \Closure::fromCallable(new Api4Executor()));

      foreach ($this->all($q, 'Case', $me) as $row) {
        $this->assertContains((int) $row['id'], $scope->cases, "VC #$n Case");
      }
      foreach ($this->all($q, 'CaseContact', $me) as $row) {
        $this->assertContains((int) $row['case_id'], $scope->cases, "VC #$n CaseContact");
      }
      $named = 0;
      foreach ($this->all($q, 'Contact', $me) as $row) {
        $id = (int) $row['id'];
        $this->assertTrue(in_array($id, $scope->contacts, TRUE) || in_array($id, $scope->named, TRUE), "VC #$n Contact");
        if (!in_array($id, $scope->contacts, TRUE)) {
          $named++;
          foreach ($row as $f => $v) {
            if (!in_array($f, VcScopePolicy::NAMED_FIELDS, TRUE)) {
              $this->assertNull($v, "VC #$n named contact field $f");
            }
          }
        }
      }
      foreach (['Email', 'Phone', 'Address'] as $e) {
        foreach ($this->all($q, $e, $me) as $row) {
          $this->assertContains((int) $row['contact_id'], $scope->contacts, "VC #$n $e");
        }
      }
      foreach ($this->all($q, 'Relationship', $me) as $row) {
        // A case role only through an in-scope case; otherwise both ends visible and no case at all.
        $ok = $row['case_id'] !== NULL
          ? in_array((int) $row['case_id'], $scope->cases, TRUE)
          : (in_array((int) $row['contact_id_a'], $scope->contacts, TRUE) && in_array((int) $row['contact_id_b'], $scope->contacts, TRUE));
        $this->assertTrue($ok, "VC #$n Relationship");
      }
      // The own contact is always there in full.
      $mine = $q->run(['entity' => 'Contact', 'select' => ['id', 'first_name'], 'where' => [['id', '=', $me]]], new ToolContext($me, 5));
      $this->assertSame(1, $mine['returned']);
      $this->addToAssertionCount($named);
    }
  }

  /** D22 on real data: feedback returned exactly where the raw share answer is the string "Yes". */
  public function testClientFeedbackFollowsTheRawShareAnswer(): void {
    $raw = CiviCase::get(FALSE)->addSelect('id', VcScopePolicy::SHARE_FIELD, 'Project_Close_Client.satisfaction')
      ->addWhere('Project_Close_Client.satisfaction', 'IS NOT EMPTY')->execute()->indexBy('id');
    if (!count($raw)) {
      $this->markTestSkipped('No case with client feedback on this site.');
    }
    foreach ($raw as $r) {
      $share = $r[VcScopePolicy::SHARE_FIELD];
      $this->assertTrue($share === NULL || is_string($share), 'share_with_vc comes back as a plain string, as the Gate compares');
    }
    $checked = 0;
    foreach ($this->vcIds() as $me) {
      $q = new ScopedQuery(VcScopePolicy::forSite(), \Closure::fromCallable(new Api4Executor()));
      foreach ($this->all($q, 'Case', $me) as $row) {
        if (!isset($raw[(int) $row['id']])) {
          continue;
        }
        $shared = $raw[(int) $row['id']][VcScopePolicy::SHARE_FIELD] === VcScopePolicy::SHARE_YES;
        $this->assertSame($shared, $row['Project_Close_Client.satisfaction'] !== NULL, 'case feedback gate');
        $checked++;
      }
    }
    $this->addToAssertionCount($checked);
  }

  /** D22: the listed feedback fields are the group's fields minus the two never returned, so a new field fails here. */
  public function testFeedbackListMatchesTheGroup(): void {
    $group = array_map(fn($n) => 'Project_Close_Client.' . $n, array_column(CustomField::get(FALSE)->addSelect('name')
      ->addWhere('custom_group_id:name', '=', 'Project_Close_Client')->addWhere('is_active', '=', TRUE)->execute()->getArrayCopy(), 'name'));
    $listed = array_values(array_unique(array_map(fn($n) => explode(':', $n)[0], VcScopePolicy::CLIENT_FEEDBACK)));
    $expected = array_values(array_diff($group, [VcScopePolicy::SHARE_FIELD, 'Project_Close_Client.use_in_marketing']));
    sort($listed);
    sort($expected);
    $this->assertSame($expected, $listed);
  }


  /** vc-activity-policy.md §1: every listed type name resolves to exactly one activity type. */
  public function testEveryListedActivityTypeResolves(): void {
    foreach (array_merge(VcScopePolicy::ACTIVITY_FULL, VcScopePolicy::ACTIVITY_TYPE_DATE) as $name) {
      $n = \Civi\Api4\OptionValue::get(FALSE)->addWhere('option_group_id:name', '=', 'activity_type')->addWhere('name', '=', $name)->execute()->count();
      $this->assertSame(1, $n, $name);
    }
  }

  /** §1-§2 on real data: in-scope cases, listed types, type-and-date rows empty, copies per their source. */
  public function testActivitiesFollowThePolicy(): void {
    $checked = 0;
    foreach ($this->vcIds() as $n => $me) {
      $scope = (new VcScopeResolver(new Api4VcScopeSource()))->resolve($me);
      $q = new ScopedQuery(VcScopePolicy::forSite(), \Closure::fromCallable(new Api4Executor()));
      foreach ($this->all($q, 'Activity', $me) as $row) {
        $cases = array_map('intval', (array) $row['case_id']);
        $this->assertNotSame([], array_intersect($cases, $scope->cases), "VC #$n activity case");
        $type = $row['activity_type_id:name'];
        $this->assertContains($type, array_merge(VcScopePolicy::ACTIVITY_FULL, VcScopePolicy::ACTIVITY_TYPE_DATE), "VC #$n type");
        if (in_array($type, VcScopePolicy::ACTIVITY_TYPE_DATE, TRUE)) {
          foreach (VcScopePolicy::ACTIVITY_DEMOTABLE as $f) {
            $this->assertNull($row[$f], "VC #$n $type $f");
          }
        }
        $checked++;
      }
    }
    $this->assertGreaterThan(0, $checked);
  }

}
