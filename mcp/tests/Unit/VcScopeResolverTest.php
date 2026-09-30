<?php

namespace Civi\Mascode\Mcp\Tests\Unit;

use Civi\Mascode\Mcp\Vc\ScopeRefused;
use Civi\Mascode\Mcp\Vc\VcScopeResolver;
use Civi\Mascode\Mcp\Vc\VcScopeSource;
use PHPUnit\Framework\TestCase;

/**
 * T5 scope resolution (VC access spec D19, D23, D25): the set rules VcScopeResolver derives in PHP,
 * and every fail-closed path. A small in-memory site stands in for CiviCRM; its three scope
 * "searches" follow mascode's Own / Pool / Orgs definitions. Synthetic IDs only.
 *
 * @group unit
 */
class VcScopeResolverTest extends TestCase {

  private const ME = 3;
  private const OTHER_VC = 4;
  private const DOMAIN = 1;

  /** @var array<int, array{type: string, sub: string[], deleted: bool}> */
  private array $contacts = [];

  /** @var array<int, array{clients: int[], pool: bool, deleted: bool}> */
  private array $cases = [];

  /** @var list<array{a: int, b: int, type: string, active: bool, case: ?int}> */
  private array $rels = [];

  /** @var list<array{0: string, 1: array}> searches the resolver ran */
  private array $ran = [];

  /** @var int[] cases a stale RelationshipCache row adds to the own-cases search only */
  private array $staleCacheCases = [];

  /** @var int[] cases a stale RelationshipCache is missing from the own-cases search */
  private array $missingCacheCases = [];

  private bool $explode = FALSE;

  /** @var array<string, mixed> overrides for the fake's declarations / stored searches */
  private array $decl = [];
  private array $stored = [];

  protected function setUp(): void {
    // Contacts: 1 = the domain organisation; 3 = me; 4 = another VC.
    $ind = fn(array $sub = []) => ['type' => 'Individual', 'sub' => $sub, 'deleted' => FALSE];
    $org = ['type' => 'Organization', 'sub' => [], 'deleted' => FALSE];
    $this->contacts = [
      1 => $org, 3 => $ind(['MAS_Rep']), 4 => $ind(['MAS_Rep']),
      10 => $org,              // X: client of my own case
      11 => $org,              // Y: client of a pool case
      12 => $org,              // Z: out of scope
      13 => ['type' => 'Organization', 'sub' => [], 'deleted' => TRUE],   // trashed org on my case
      20 => $ind(),            // employee of X
      21 => $ind(),            // employee of X and of the domain org
      22 => $ind(['MAS_Rep']), // a VC employed by X
      23 => $ind(),            // employee of Y
      24 => $ind(),            // employee of Z
      25 => $ind(),            // employee of the domain org only
      26 => ['type' => 'Individual', 'sub' => [], 'deleted' => TRUE],     // trashed employee of X
      27 => $ind(),            // former (inactive) employee of X
      30 => $ind(),            // individual client of my own case
      31 => $ind(),            // individual client of a pool case
      32 => $ind(),            // individual client of Z's case
      33 => $ind(),            // domain employee filed as client of my own case
      34 => $ind(['MAS_Rep']), // a VC filed as client of my own case
    ];
    $this->cases = [
      100 => ['clients' => [10], 'pool' => FALSE, 'deleted' => FALSE],       // mine, org X
      101 => ['clients' => [10], 'pool' => FALSE, 'deleted' => FALSE],       // X, other VC's
      102 => ['clients' => [11], 'pool' => TRUE, 'deleted' => FALSE],        // pool, org Y
      103 => ['clients' => [1], 'pool' => TRUE, 'deleted' => FALSE],         // pooled internal request
      104 => ['clients' => [1], 'pool' => FALSE, 'deleted' => FALSE],        // other internal case
      105 => ['clients' => [31], 'pool' => TRUE, 'deleted' => FALSE],        // pool, individual
      106 => ['clients' => [30, 33, 34], 'pool' => FALSE, 'deleted' => FALSE], // mine, individuals
      107 => ['clients' => [20], 'pool' => FALSE, 'deleted' => FALSE],       // X's employee as client
      108 => ['clients' => [12, 32], 'pool' => FALSE, 'deleted' => FALSE],   // Z, out of scope
      109 => ['clients' => [10], 'pool' => FALSE, 'deleted' => TRUE],        // trashed case of X
      110 => ['clients' => [13], 'pool' => FALSE, 'deleted' => FALSE],       // mine, trashed org
    ];
    $emp = fn(int $a, int $b, bool $active = TRUE) => ['a' => $a, 'b' => $b, 'type' => 'Employee of', 'active' => $active, 'case' => NULL];
    $coord = fn(int $vc, int $case, bool $active = TRUE) => ['a' => $vc, 'b' => 10, 'type' => 'Case Coordinator is', 'active' => $active, 'case' => $case];
    $this->rels = [
      $coord(self::ME, 100), $coord(self::OTHER_VC, 101), $coord(self::ME, 106), $coord(self::ME, 110),
      $coord(self::ME, 108, FALSE), // a corrected mis-assignment: does not count (D5)
      $emp(20, 10), $emp(21, 10), $emp(21, self::DOMAIN), $emp(22, 10), $emp(23, 11), $emp(24, 12),
      $emp(25, self::DOMAIN), $emp(26, 10), $emp(27, 10, FALSE), $emp(33, self::DOMAIN),
      $emp(self::ME, self::DOMAIN), $emp(self::OTHER_VC, self::DOMAIN),
    ];
  }

  private function source(): VcScopeSource {
    $t = $this;
    return new class($t) implements VcScopeSource {

      public function __construct(private readonly VcScopeResolverTest $t) {}

      public function declarations(): array {
        return $this->t->declared();
      }

      public function storedSearches(array $names): array {
        return $this->t->storedFor($names);
      }

      public function runSearch(string $entity, array $params): array {
        return $this->t->search($entity, $params);
      }

      public function coordinatedCaseIds(int $contactId): array {
        return $this->t->coordinated($contactId);
      }

      public function isLiveContact(int $contactId): bool {
        return isset($this->t->contactsFor()[$contactId]) && !$this->t->contactsFor()[$contactId]['deleted'];
      }

      public function domainOrgIds(): array {
        return [VcScopeResolverTest::domainId()];
      }

      public function casesOfClients(array $orgIds): array {
        return array_keys(array_filter($this->t->casesFor(), fn($c) => !$c['deleted'] && array_intersect($c['clients'], $orgIds)));
      }

      public function caseClients(array $caseIds): array {
        $out = [];
        foreach ($caseIds as $id) {
          foreach ($this->t->casesFor()[$id]['clients'] ?? [] as $cid) {
            $c = $this->t->contactsFor()[$cid];
            if (!$c['deleted']) {
              $out[] = ['case_id' => $id, 'contact_id' => $cid, 'contact_type' => $c['type'], 'sub_types' => $c['sub']];
            }
          }
        }
        return $out;
      }

      public function activeEmployeeIds(array $orgIds): array {
        return array_values(array_unique(array_column(array_filter($this->t->relsFor(),
          fn($r) => $r['type'] === 'Employee of' && $r['active'] && in_array($r['b'], $orgIds, TRUE)), 'a')));
      }

      public function liveIndividuals(array $contactIds): array {
        $out = [];
        foreach ($contactIds as $id) {
          $c = $this->t->contactsFor()[$id] ?? NULL;
          if ($c && $c['type'] === 'Individual' && !$c['deleted']) {
            $out[$id] = $c['sub'];
          }
        }
        return $out;
      }

      public function caseRelationshipContacts(array $caseIds): array {
        $ids = [];
        foreach ($this->t->relsFor() as $r) {
          if (in_array($r['case'], $caseIds, TRUE)) {
            array_push($ids, $r['a'], $r['b']);
          }
        }
        return array_values(array_filter(array_unique($ids), fn($id) => !$this->t->contactsFor()[$id]['deleted']));
      }

    };
  }

  /** Own cases as Relationship records them (the fake's rels, which the cache normally mirrors). */
  public function coordinated(int $me): array {
    $own = [];
    foreach ($this->rels as $r) {
      if ($r['type'] === 'Case Coordinator is' && $r['active'] && $r['a'] === $me && !$this->cases[$r['case']]['deleted']) {
        $own[] = $r['case'];
      }
    }
    return $own;
  }

  public static function domainId(): int {
    return self::DOMAIN;
  }

  public function contactsFor(): array {
    return $this->contacts;
  }

  public function casesFor(): array {
    return $this->cases;
  }

  public function relsFor(): array {
    return $this->rels;
  }

  /** A declaration per search; `_set` tells the fake which set to compute. */
  public function declared(): array {
    $out = [];
    foreach (VcScopeResolver::SEARCHES as $key => $name) {
      $where = in_array($key, ['own', 'orgs', 'cases', 'employees'], TRUE) ? [['near_contact_id', '=', 'user_contact_id']] : [['status', '=', 'pool']];
      $out[$name] = ['api_entity' => in_array($key, ['orgs', 'employees'], TRUE) ? 'Contact' : 'Case',
        'api_params' => ['version' => 4, 'select' => ['id'], 'where' => $where, '_set' => $key]];
    }
    return array_replace($out, $this->decl);
  }

  public function storedFor(array $names): array {
    return array_intersect_key(array_replace($this->declared(), $this->stored), array_flip($names));
  }

  /** The fake site's Own / Pool / Orgs searches, for the contact the params name. */
  public function search(string $entity, array $params): array {
    $this->ran[] = [$entity, $params];
    if ($this->explode) {
      throw new \RuntimeException('Illegal value for join side');
    }
    $me = $params['where'][0][2] ?? NULL;
    $own = array_values(array_diff(array_merge(is_int($me) ? $this->coordinated($me) : [], $this->staleCacheCases), $this->missingCacheCases));
    $pool = array_keys(array_filter($this->cases, fn($c) => $c['pool'] && !$c['deleted']));
    if ($params['_set'] === 'own') {
      return $own;
    }
    if ($params['_set'] === 'pool') {
      return $pool;
    }
    $orgs = [];
    foreach ($this->cases as $id => $c) {
      $mine = in_array($id, $own, TRUE);
      foreach ($c['clients'] as $cid) {
        $ct = $this->contacts[$cid];
        if (!$c['deleted'] && $ct['type'] === 'Organization' && !$ct['deleted']
          && ($mine || (in_array($id, $pool, TRUE) && $cid !== self::DOMAIN))) {
          $orgs[] = $cid;
        }
      }
    }
    return array_values(array_unique($orgs));
  }

  private function resolve(int $me = self::ME) {
    return (new VcScopeResolver($this->source()))->resolve($me);
  }

  public function testSets(): void {
    $s = $this->resolve();
    $this->assertSame([100, 106, 110], $s->ownCases);
    $this->assertSame([102, 103, 105], $s->poolCases);
    // The domain org only via own cases (D25): the pooled internal request 103 does not bring it in.
    $this->assertSame([10, 11], $s->orgs);
    // D23: org-client cases of X and Y, plus own and pool. Not 104 (other internal case), 107
    // (filed under X's employee), 108 (Z), 109 (trashed).
    $this->assertSame([100, 101, 102, 103, 105, 106, 110], $s->cases);
    // D25: not 21 (also a domain employee), 22 (a VC), 25; not trashed 26 or former 27; not 24 (Z).
    $this->assertSame([20, 23], $s->employees);
    // D23: 30, my own case's individual client, is visible; 33 (domain employee) and 34 (a VC) are not.
    $this->assertSame([self::ME, 10, 11, 20, 23, 30], $s->contacts);
    // D15: other contacts on in-scope cases are names only — the pooled internal request's client (1),
    // the pool case's individual client (31), 33 and 34, the other VC coordinating case 101.
    $this->assertSame([1, self::OTHER_VC, 31, 33, 34], $s->named);
  }

  public function testCoordinatingAnInternalCaseBringsInTheDomainOrgButNoMasEmployees(): void {
    $this->rels[] = ['a' => self::ME, 'b' => self::DOMAIN, 'type' => 'Case Coordinator is', 'active' => TRUE, 'case' => 104];
    $s = $this->resolve();
    $this->assertContains(self::DOMAIN, $s->orgs);
    $this->assertContains(103, $s->cases);
    $this->assertContains(104, $s->cases);
    $this->assertSame([20, 23], $s->employees, 'no employee of the domain organisation, whatever the route');
    $this->assertNotContains(25, $s->contacts);
    $this->assertNotContains(self::OTHER_VC, $s->contacts);
  }

  public function testPlaceholderIsReplacedByTheAuthenticatedContact(): void {
    $this->resolve();
    $this->assertCount(3, $this->ran, 'runs own, pool and orgs; derives cases and employees');
    foreach ($this->ran as [, $params]) {
      $this->assertFalse($params['checkPermissions']);
      $this->assertArrayNotHasKey('version', $params);
      $this->assertStringNotContainsString('user_contact_id', json_encode($params));
    }
    $this->assertSame(self::ME, $this->ran[0][1]['where'][0][2]);
    // A different VC gets their own sets.
    $this->ran = [];
    $this->assertSame([101], $this->resolve(self::OTHER_VC)->ownCases);
  }

  public function testMemoisedPerContact(): void {
    $r = new VcScopeResolver($this->source());
    $r->resolve(self::ME);
    $r->resolve(self::ME);
    $this->assertCount(3, $this->ran);
  }

  /** @dataProvider refusals */
  public function testFailsClosed(callable $arrange, int $me, string $reason): void {
    $arrange($this);
    try {
      $this->resolve($me);
      $this->fail('expected ScopeRefused');
    }
    catch (ScopeRefused $e) {
      $this->assertStringContainsString($reason, $e->reason);
      $this->assertSame(ScopeRefused::MESSAGE, $e->getMessage(), 'one message for every cause');
      $this->assertSame([], $this->ran, 'no search runs before the checks pass');
    }
  }

  public function refusals(): array {
    $name = VcScopeResolver::SEARCHES['orgs'];
    return [
      'no contact' => [fn() => NULL, 0, 'no authenticated contact'],
      'unknown contact' => [fn() => NULL, 999, 'missing or trashed'],
      'trashed contact' => [fn(self $t) => $t->trash(self::ME), self::ME, 'missing or trashed'],
      'mascode missing' => [fn(self $t) => $t->setDecl(array_fill_keys(VcScopeResolver::SEARCHES, NULL)), self::ME, 'declaration missing'],
      'search missing' => [fn(self $t) => $t->setStored([VcScopeResolver::SEARCHES['employees'] => NULL]), self::ME, 'saved search missing'],
      'search drifted' => [fn(self $t) => $t->editStored($name, fn($s) => ['api_entity' => $s['api_entity'], 'api_params' => ['where' => []] + $s['api_params']]), self::ME, 'drifted'],
      'entity drifted' => [fn(self $t) => $t->editStored($name, fn($s) => ['api_entity' => 'Case'] + $s), self::ME, 'drifted'],
    ];
  }

  public function testKeyOrderIsNotDrift(): void {
    $name = VcScopeResolver::SEARCHES['own'];
    $this->editStored($name, fn($s) => ['api_entity' => $s['api_entity'], 'api_params' => array_reverse($s['api_params'], TRUE)]);
    $this->assertSame([100, 106, 110], $this->resolve()->ownCases);
  }

  /**
   * PR #10 review H1: APIv4 reads an explicit join by position, so an int-keyed join stored out of
   * order must be drift, not sorted back into agreement.
   */
  public function testPermutedJoinIsDrift(): void {
    $name = VcScopeResolver::SEARCHES['own'];
    $join = ['RelationshipCache AS mine', 'INNER', ['id', '=', 'mine.case_id'], ['mine.is_active', '=', TRUE]];
    $this->decl[$name] = $this->declared()[$name];
    $this->decl[$name]['api_params']['join'] = [$join];
    $permuted = [0 => $join[0], 3 => $join[3], 2 => $join[2], 1 => $join[1]];
    $this->editStored($name, fn($s) => ['api_entity' => $s['api_entity'], 'api_params' => ['join' => [$permuted]] + $s['api_params']]);
    try {
      $this->resolve();
      $this->fail('expected ScopeRefused');
    }
    catch (ScopeRefused $e) {
      $this->assertStringContainsString('drifted', $e->reason);
    }
  }

  public function testTheDeclarationRunsNotTheStoredCopy(): void {
    // Same content, string keys in another order: not drift, and what runs is the declaration.
    $name = VcScopeResolver::SEARCHES['own'];
    $this->editStored($name, fn($s) => ['api_entity' => $s['api_entity'], 'api_params' => array_reverse($s['api_params'], TRUE)]);
    $this->resolve();
    $declared = $this->declared()[$name]['api_params'];
    unset($declared['version']);
    $this->assertSame(array_keys($declared), array_values(array_diff(array_keys($this->ran[0][1]), ['checkPermissions'])));
  }

  /** PR #10 review M1: a stale cache row that adds an own case refuses the whole scope. */
  public function testStaleCacheCoordinatorRowIsRefused(): void {
    $this->staleCacheCases = [104];
    try {
      $this->resolve();
      $this->fail('expected ScopeRefused');
    }
    catch (ScopeRefused $e) {
      $this->assertStringContainsString('stale relationship cache', $e->reason);
    }
  }

  /** PR #10 review L1: a core error becomes the one generic refusal, never its own message. */
  public function testSourceErrorsBecomeTheGenericRefusal(): void {
    $this->explode = TRUE;
    try {
      $this->resolve();
      $this->fail('expected ScopeRefused');
    }
    catch (ScopeRefused $e) {
      $this->assertSame('source error: RuntimeException', $e->reason);
      $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious(), 'the original is kept for the server log');
      $this->assertSame(ScopeRefused::MESSAGE, $e->getMessage());
    }
  }

  public function testPersonalSearchWithoutPlaceholderIsRefused(): void {
    // A declaration and stored copy that agree but have lost the placeholder would give every VC
    // the same set: refused, though nothing drifted.
    $name = VcScopeResolver::SEARCHES['own'];
    $params = $this->declared()[$name];
    $params['api_params']['where'] = [['near_contact_id', '=', 3]];
    $this->decl[$name] = $params;
    try {
      $this->resolve();
      $this->fail('expected ScopeRefused');
    }
    catch (ScopeRefused $e) {
      $this->assertSame('scope search has no contact placeholder', $e->reason);
    }
  }

  /** PR #10 round 2 L1: a permuted int-keyed array is drift even when BOTH sides carry it. */
  public function testIntKeyedNonListIsDriftEvenWhenBothSidesAgree(): void {
    $name = VcScopeResolver::SEARCHES['own'];
    $params = $this->declared()[$name];
    $params['api_params']['join'] = [[0 => 'RelationshipCache AS mine', 2 => ['id', '=', 'mine.case_id'], 1 => 'INNER']];
    $this->decl[$name] = $params;
    try {
      $this->resolve();
      $this->fail('expected ScopeRefused');
    }
    catch (ScopeRefused $e) {
      $this->assertStringContainsString('drifted', $e->reason);
    }
  }

  /** PR #10 round 2 L2: a cache MISSING a coordinator row also refuses (the check is two-way). */
  public function testMissingCacheCoordinatorRowIsRefused(): void {
    $this->missingCacheCases = [106];
    try {
      $this->resolve();
      $this->fail('expected ScopeRefused');
    }
    catch (ScopeRefused $e) {
      $this->assertStringContainsString('stale relationship cache', $e->reason);
    }
  }

  public function testOversizedSetIsRefusedNotTruncated(): void {
    for ($i = 1000; $i < 1000 + VcScopeResolver::MAX_SET + 1; $i++) {
      $this->cases[$i] = ['clients' => [11], 'pool' => FALSE, 'deleted' => FALSE];
    }
    try {
      $this->resolve();
      $this->fail('expected ScopeRefused');
    }
    catch (ScopeRefused $e) {
      $this->assertStringContainsString('scope set cases above', $e->reason);
    }
  }

  public function trash(int $id): void {
    $this->contacts[$id]['deleted'] = TRUE;
  }

  public function setDecl(array $d): void {
    $this->decl = $d;
  }

  public function setStored(array $s): void {
    $this->stored = $s;
  }

  public function editStored(string $name, callable $edit): void {
    $this->stored[$name] = $edit($this->declared()[$name]);
  }

}
