<?php

namespace Civi\Mascode\Mcp\Vc;

/**
 * Resolves one VC's scope sets (VC access spec § Data Model; D19, D23, D25; ticket T5).
 *
 * D19: the three cheap sets come from mascode's scope saved searches, the single definition the
 * portal shares (D7, D13); the two multi-hop sets are derived here in PHP and must equal mascode's
 * Cases / Employees searches (tests/Live/LiveVcScopeTest). Before any search runs, all five stored
 * searches must equal their mascode declaration: a Search Kit UI edit survives `cv flush`, and a
 * drifted boundary fails closed.
 *
 * The contact is ALWAYS the one authenticated in this request (ToolContext::$contactId). The searches'
 * `user_contact_id` placeholder is replaced by that ID before they run, so scope never depends on a
 * CMS session or cookie.
 *
 * Fails closed (ScopeRefused) on: no or trashed contact, mascode missing, a search missing or
 * drifted, a declaration without the placeholder, own cases that disagree with Relationship (a
 * stale cache), any error from the source, or any set above MAX_SET. After the drift check the
 * DECLARATION runs, never the stored copy.
 */
final class VcScopeResolver {

  public const SEARCHES = [
    'own' => 'MAS_VC_Scope_Own_Cases',
    'pool' => 'MAS_VC_Scope_Pool_Cases',
    'orgs' => 'MAS_VC_Scope_Orgs',
    'cases' => 'MAS_VC_Scope_Cases',
    'employees' => 'MAS_VC_Scope_Employees',
  ];

  /** VC access spec § VcScopePolicy: refuse rather than silently truncate a scope. */
  public const MAX_SET = 5000;

  public const VC_SUB_TYPE = 'MAS_Rep';

  private const PLACEHOLDER = 'user_contact_id';

  /** @var array<int, VcScope> */
  private array $memo = [];

  public function __construct(private readonly VcScopeSource $source) {}

  /** Memoised per contact for the life of this resolver (one request). */
  public function resolve(int $contactId): VcScope {
    if (!isset($this->memo[$contactId])) {
      try {
        $this->memo[$contactId] = $this->build($contactId);
      }
      catch (ScopeRefused $e) {
        throw $e;
      }
      catch (\Throwable $e) {
        // One message whatever broke, so a malformed search or a core error is no oracle on the
        // boundary's structure (PR #10 review L1). The original goes to the server log only.
        if (class_exists('Civi', FALSE)) {
          try {
            \Civi::log()->error('VC scope refused after an error', ['exception' => $e]);
          }
          catch (\Throwable) {
            // A failing logger must not replace the generic refusal with its own message.
          }
        }
        throw new ScopeRefused('source error: ' . get_class($e), $e);
      }
    }
    return $this->memo[$contactId];
  }

  private function build(int $me): VcScope {
    if ($me < 1) {
      throw new ScopeRefused('no authenticated contact');
    }
    if (!$this->source->isLiveContact($me)) {
      throw new ScopeRefused('authenticated contact missing or trashed');
    }
    $searches = $this->verifiedSearches();

    $own = $this->run($searches['own'], $me, TRUE);
    // The searches read RelationshipCache; a stale or orphaned cache row would widen every set
    // derived from own cases. Check them against Relationship itself (PR #10 review M1).
    if ($own !== self::ids($this->source->coordinatedCaseIds($me))) {
      throw new ScopeRefused('own cases disagree with Relationship (stale relationship cache)');
    }
    $pool = $this->run($searches['pool'], $me, FALSE);
    $orgs = $this->run($searches['orgs'], $me, TRUE);

    $cases = self::ids(array_merge($this->source->casesOfClients($orgs), $own, $pool));

    $domainEmployees = $this->source->activeEmployeeIds($this->source->domainOrgIds());
    $employees = [];
    foreach ($this->source->liveIndividuals(array_diff($this->source->activeEmployeeIds($orgs), $domainEmployees)) as $id => $subTypes) {
      if (!in_array(self::VC_SUB_TYPE, $subTypes, TRUE)) {
        $employees[] = $id;
      }
    }

    // D23: an own case's individual client is a visible contact, unless that person is a VC or an
    // employee of the domain organisation (D25 — those are reached only through the directory).
    $ownClients = [];
    foreach ($this->source->caseClients($own) as $c) {
      if ($c['contact_type'] === 'Individual' && !in_array(self::VC_SUB_TYPE, $c['sub_types'], TRUE)
        && !in_array($c['contact_id'], $domainEmployees, TRUE)) {
        $ownClients[] = $c['contact_id'];
      }
    }
    $contacts = self::ids(array_merge($orgs, $employees, [$me], $ownClients));

    // D15: everyone else on an in-scope case — other clients (a pool case's individual, D23),
    // coordinators, staff in case roles — resolves to a display name only.
    $onCases = array_merge(
      array_column($this->source->caseClients($cases), 'contact_id'),
      $this->source->caseRelationshipContacts($cases),
    );
    $named = self::ids(array_diff($onCases, $contacts));

    $scope = new VcScope($me, self::ids($own), self::ids($pool), self::ids($orgs), $cases,
      self::ids($employees), $contacts, $named);
    foreach (['ownCases', 'poolCases', 'orgs', 'cases', 'employees', 'contacts', 'named'] as $set) {
      if (count($scope->$set) > self::MAX_SET) {
        throw new ScopeRefused("scope set $set above " . self::MAX_SET);
      }
    }
    return $scope;
  }

  /** @return array<string, array{api_entity: string, api_params: array}> keyed like SEARCHES */
  private function verifiedSearches(): array {
    $declared = $this->source->declarations();
    $stored = $this->source->storedSearches(array_values(self::SEARCHES));
    $out = [];
    foreach (self::SEARCHES as $key => $name) {
      if (!isset($declared[$name])) {
        throw new ScopeRefused("declaration missing: $name");
      }
      if (!isset($stored[$name])) {
        throw new ScopeRefused("saved search missing: $name");
      }
      $storedCanon = self::canon($stored[$name]['api_params']);
      if ($stored[$name]['api_entity'] !== $declared[$name]['api_entity'] || $storedCanon === NULL
        || json_encode($storedCanon) !== json_encode(self::canon($declared[$name]['api_params']))) {
        throw new ScopeRefused("saved search drifted from its declaration: $name");
      }
      // Run the declaration, never the stored copy: equal after canonicalising is not the same as
      // identical, and APIv4 reads some arrays (explicit joins) by position (PR #10 review H1).
      $out[$key] = $declared[$name];
    }
    return $out;
  }

  /**
   * @param array{api_entity: string, api_params: array} $search
   * @return int[]
   */
  private function run(array $search, int $me, bool $mustBePersonal): array {
    $count = 0;
    $params = self::substitute($search['api_params'], $me, $count);
    if ($mustBePersonal && $count === 0) {
      // Without the placeholder the search would return the same set for every VC.
      throw new ScopeRefused('scope search has no contact placeholder');
    }
    unset($params['version']);
    $params['checkPermissions'] = FALSE;
    return self::ids($this->source->runSearch($search['api_entity'], $params));
  }

  /** Replace every value equal to the placeholder with the contact ID. */
  private static function substitute(mixed $v, int $me, int &$count): mixed {
    if ($v === self::PLACEHOLDER) {
      $count++;
      return $me;
    }
    if (is_array($v)) {
      foreach ($v as $k => $item) {
        $v[$k] = self::substitute($item, $me, $count);
      }
    }
    return $v;
  }

  /**
   * Canonical form for the drift comparison: string-keyed maps key-sorted, lists kept in order.
   * NULL (always drift) for an array with any integer key that is not a list — APIv4 reads some
   * arrays by position (an explicit join's entity and side are shifted off the front), so sorting
   * such an array back into order would hide a changed meaning (PR #10 review H1).
   */
  private static function canon(mixed $v): mixed {
    if (!is_array($v)) {
      return $v;
    }
    if (!array_is_list($v)) {
      foreach (array_keys($v) as $k) {
        if (!is_string($k)) {
          return NULL;
        }
      }
      ksort($v);
    }
    $out = [];
    foreach ($v as $k => $item) {
      $c = self::canon($item);
      if ($c === NULL && $item !== NULL) {
        return NULL;
      }
      $out[$k] = $c;
    }
    return $out;
  }

  /**
   * @param array<mixed> $ids
   * @return int[] positive, unique, sorted
   */
  private static function ids(array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn(int $i) => $i > 0)));
    sort($ids);
    return $ids;
  }

}
