<?php

namespace Civi\Mascode\Mcp\Tests\Live;

use Civi\Api4\CiviCase;
use Civi\Api4\Contact;
use Civi\Api4\Relationship;
use Civi\Mascode\Mcp\Vc\Api4VcScopeSource;
use Civi\Mascode\Mcp\Vc\VcScope;
use Civi\Mascode\Mcp\Vc\VcScopePolicy;
use Civi\Mascode\Mcp\Vc\VcScopeResolver;
use Civi\Mcp\Scoped\Api4Executor;
use Civi\Mcp\Scoped\ScopedQuery;
use Civi\Mcp\Tools\ToolContext;
use PHPUnit\Framework\TestCase;

/**
 * T10 gaps (2), (6) and (8): scope rules asserted on the synthetic fixtures that
 * scripts/seed-vc-test-fixtures.php commits on masdemo (VC B and its cases; test.vc as the
 * disjoint second VC), through the resolver AND through ScopedQuery, the engine `vc_query` runs.
 *
 *  - D5: an ended coordinator row that is still active counts (case and its org in scope); a
 *    deactivated row does not. The core job that would deactivate ended rows stays off.
 *  - D23: the own individual-client case's client is a full contact; the pool individual-client
 *    case's client is a name only, for every VC.
 *  - D25: a pooled internal case adds only itself — not the domain organisation, not the other
 *    internal cases — for VC C, who coordinates nothing (test.vc coordinates an internal case on
 *    masdemo, so the domain organisation is legitimately in its scope).
 *  - Other VCs' Email / Phone / Address rows are never returned, checked independently of the
 *    resolver against every MAS_Rep contact on the site.
 *
 * The fixture tests skip where the fixtures are absent (the seeder refuses Production), except the
 * job check and the other-VC check, which hold on any site. Read-only; asserts ids and counts only.
 *
 * @group live
 */
class LiveVcFixtureScopeTest extends TestCase {

  protected function setUp(): void {
    if (!getenv('MCP_LIVE_USER') || !class_exists('Civi')) {
      $this->markTestSkipped('Set MCP_LIVE_USER and run inside a CiviCRM site.');
    }
  }

  private static function contact(string $key): ?int {
    $id = Contact::get(FALSE)->addSelect('id')->addWhere('external_identifier', '=', "T9-$key")->execute()->first()['id'] ?? NULL;
    return $id === NULL ? NULL : (int) $id;
  }

  private static function case(string $key): ?int {
    // Subjects carry mascode's reference prefix ("R123: …"); `_` escaped, it is a LIKE wildcard.
    $id = CiviCase::get(FALSE)->addSelect('id')->addWhere('subject', 'LIKE', '%' . str_replace('_', '\\_', "T9 synthetic: $key"))
      ->addWhere('is_deleted', '=', FALSE)->execute()->first()['id'] ?? NULL;
    return $id === NULL ? NULL : (int) $id;
  }

  /** @return array<string, int> the fixture ids, or skip */
  private function fixtures(): array {
    $f = [
      'vcb' => self::contact('VC-B'), 'vcc' => self::contact('VC-C'), 'org_c' => self::contact('ORG-C'), 'org_d' => self::contact('ORG-D'),
      'own_client' => self::contact('OWN-CLIENT'), 'pool_client' => self::contact('POOL-CLIENT'),
      'own_internal' => self::case('own_internal'), 'pool_internal' => self::case('pool_internal'),
      'own_individual' => self::case('own_individual'), 'pool_individual' => self::case('pool_individual'),
      'ended_coord' => self::case('ended_coord'), 'deactivated_coord' => self::case('deactivated_coord'),
    ];
    if (in_array(NULL, $f, TRUE)) {
      $this->markTestSkipped('T9/T10 fixtures missing: run scripts/seed-vc-test-fixtures.php (dev only).');
    }
    return $f;
  }

  private static function scope(int $me): VcScope {
    return (new VcScopeResolver(new Api4VcScopeSource()))->resolve($me);
  }

  /** @return array<int, array> ScopedQuery rows for $me, keyed by id */
  private static function query(int $me, string $entity, array $select, array $where = []): array {
    $q = new ScopedQuery(VcScopePolicy::forSite(), \Closure::fromCallable(new Api4Executor()));
    $out = $q->run(['entity' => $entity, 'select' => $select, 'where' => $where, 'limit' => 200], new ToolContext($me, 200));
    $rows = [];
    foreach ($out['rows'] as $row) {
      $rows[(int) $row['id']] = $row;
    }
    return $rows;
  }

  private static function testVc(): ?int {
    $user = function_exists('get_user_by') ? get_user_by('login', 'test.vc') : FALSE;
    if (!$user) {
      return NULL;
    }
    $id = \Civi\Api4\UFMatch::get(FALSE)->addSelect('contact_id')->addWhere('uf_id', '=', $user->ID)->execute()->first()['contact_id'] ?? NULL;
    return $id === NULL ? NULL : (int) $id;
  }

  /** D5 depends on ended rows staying active; the core job that deactivates them must stay off (any site). */
  public function testExpiredRelationshipJobIsOff(): void {
    $jobs = \Civi\Api4\Job::get(FALSE)->addSelect('is_active')->addWhere('api_action', '=', 'disable_expired_relationships')->execute();
    foreach ($jobs as $job) {
      $this->assertFalse((bool) $job['is_active'], 'disable_expired_relationships is on: it would deactivate ended coordinator rows (D5)');
    }
    $this->addToAssertionCount(1);
  }

  public function testEndedCoordinationCountsAndDeactivatedDoesNot(): void {
    $f = $this->fixtures();
    $row = Relationship::get(FALSE)->addSelect('is_active', 'end_date')->addWhere('contact_id_a', '=', $f['vcb'])
      ->addWhere('case_id', '=', $f['ended_coord'])->addWhere('relationship_type_id:name', '=', 'Case Coordinator is')->execute()->first();
    $this->assertTrue($row && $row['is_active'] && $row['end_date'] && $row['end_date'] < date('Y-m-d'), 'fixture: the ended row must be active with a past end date');

    $s = self::scope($f['vcb']);
    $this->assertContains($f['ended_coord'], $s->ownCases, 'D5: ended coordination is an own case');
    $this->assertContains($f['org_c'], $s->orgs, 'D5: its client org is in scope');
    $this->assertNotContains($f['deactivated_coord'], $s->cases, 'D5: a deactivated row does not count');
    $this->assertNotContains($f['org_d'], $s->orgs);
    $this->assertNotContains($f['org_d'], array_merge($s->contacts, $s->named));

    $cases = self::query($f['vcb'], 'Case', ['id'], [['id', 'IN', [$f['ended_coord'], $f['deactivated_coord']]]]);
    $this->assertSame([$f['ended_coord']], array_keys($cases), 'vc_query: ended case returned, deactivated case not');
    $this->assertSame([], self::query($f['vcb'], 'Contact', ['id'], [['id', '=', $f['org_d']]]), 'vc_query: Org D not returned');
  }

  public function testIndividualClientsFollowD23(): void {
    $f = $this->fixtures();
    $s = self::scope($f['vcb']);
    $this->assertContains($f['own_client'], $s->contacts, 'D23: own individual client is a full contact');
    $own = self::query($f['vcb'], 'Contact', ['id', 'first_name', 'display_name'], [['id', '=', $f['own_client']]]);
    $this->assertNotNull($own[$f['own_client']]['first_name'] ?? NULL, 'vc_query: own individual client in full');
    $this->assertNotEmpty(self::query($f['vcb'], 'Email', ['id', 'contact_id'], [['contact_id', '=', $f['own_client']]]), "vc_query: own individual client's email returned");

    foreach (array_filter([$f['vcb'], self::testVc()]) as $me) {
      $s = self::scope($me);
      $this->assertContains($f['pool_individual'], $s->cases, "VC $me: pool individual-client case in scope");
      $this->assertContains($f['pool_client'], $s->named, "VC $me: pool individual client is a name only");
      $this->assertNotContains($f['pool_client'], $s->contacts);
      $row = self::query($me, 'Contact', ['id', 'first_name', 'display_name'], [['id', '=', $f['pool_client']]])[$f['pool_client']] ?? NULL;
      $this->assertNotNull($row, "VC $me: pool client returned as a name");
      $this->assertNotNull($row['display_name']);
      $this->assertNull($row['first_name'], "VC $me: pool client name only");
      $this->assertSame([], self::query($me, 'Email', ['id'], [['contact_id', '=', $f['pool_client']]]), "VC $me: pool client's email withheld");
      $this->assertSame([], self::query($me, 'Phone', ['id'], [['contact_id', '=', $f['pool_client']]]), "VC $me: pool client's phone withheld");
    }
  }

  public function testPooledInternalCaseAddsOnlyItself(): void {
    $f = $this->fixtures();
    $me = $f['vcc'];
    $domain = (int) \Civi\Api4\Domain::get(FALSE)->addSelect('contact_id')->execute()->first()['contact_id'];
    $s = self::scope($me);
    $this->assertSame([], $s->ownCases, 'fixture: VC C coordinates nothing');
    $this->assertContains($f['pool_internal'], $s->cases, 'D25: the pooled internal case is in scope');
    $this->assertNotContains($domain, $s->orgs, 'D25: a pooled internal case does not bring the domain org');
    $this->assertNotContains($f['own_internal'], $s->cases, "D25: nor another VC's internal case");
    $cases = self::query($me, 'Case', ['id'], [['id', 'IN', [$f['pool_internal'], $f['own_internal']]]]);
    $this->assertSame([$f['pool_internal']], array_keys($cases), 'vc_query: pool internal returned, the other internal case not');
  }

  /**
   * No VC ever gets another VC's email, phone or address — including VC B, whose own internal case
   * brings the domain organisation into scope (D25). Independent of the resolver: the forbidden set
   * is every MAS_Rep contact on the site but the caller.
   */
  public function testNoOtherVcContactDetails(): void {
    $reps = array_map('intval', array_column(Contact::get(FALSE)->addSelect('id')->addWhere('contact_sub_type', 'CONTAINS', 'MAS_Rep')
      ->addWhere('is_deleted', 'IN', [TRUE, FALSE])->execute()->getArrayCopy(), 'id'));
    $this->assertNotEmpty($reps);
    $vcs = array_values(array_unique(array_filter(array_merge(
      array_map('intval', explode(',', (string) getenv('MCP_LIVE_VC_IDS'))), [self::testVc(), self::contact('VC-B')]))));
    if (!$vcs) {
      $this->markTestSkipped('No test VC.');
    }
    $checked = 0;
    foreach ($vcs as $me) {
      $forbidden = array_flip(array_diff($reps, [$me]));
      $q = new ScopedQuery(VcScopePolicy::forSite(), \Closure::fromCallable(new Api4Executor()));
      foreach (['Email', 'Phone', 'Address'] as $entity) {
        $offset = 0;
        do {
          $out = $q->run(['entity' => $entity, 'select' => ['id', 'contact_id'], 'orderBy' => ['id' => 'ASC'], 'limit' => 200, 'offset' => $offset], new ToolContext($me, 200));
          foreach ($out['rows'] as $row) {
            $this->assertArrayNotHasKey((int) $row['contact_id'], $forbidden, "VC $me: $entity #{$row['id']} belongs to another VC");
            $checked++;
          }
          $offset += count($out['rows']);
        } while ($out['truncated'] && $out['rows']);
      }
      // The VC's own details come back: the check is not passing on an empty result.
      $this->assertNotEmpty($q->run(['entity' => 'Email', 'select' => ['id'], 'where' => [['contact_id', '=', $me]]], new ToolContext($me, 5))['rows'], "VC $me: own email returned");
    }
    $this->assertGreaterThan(0, $checked);
  }

}
