<?php

namespace Civi\Mascode\Mcp\Tests\Live;

use Civi\Mascode\Mcp\Vc\Api4VcScopeSource;
use Civi\Mascode\Mcp\Vc\VcScope;
use Civi\Mascode\Mcp\Vc\VcScopePolicy;
use Civi\Mascode\Mcp\Vc\VcScopeResolver;
use Civi\Mcp\Protocol\ToolException;
use Civi\Mcp\Scoped\Api4Executor;
use Civi\Mcp\Scoped\EntityPolicy;
use Civi\Mcp\Scoped\ScopedQuery;
use Civi\Mcp\Tools\ToolContext;
use PHPUnit\Framework\TestCase;

/**
 * T10 gaps (9) and (10) on the real site, per entity, as each test VC, through ScopedQuery (the
 * engine `vc_query` runs):
 *
 *  - Oracle (vc-activity-policy.md §4): of EVERY field getFields offers — listed or not, custom
 *    fields included — only the expected ones (listed, a bare name, never gated or demoted, of a
 *    non-text type) are accepted in `where` / `orderBy`; every other is refused, for the field's
 *    sake (the refusal message is checked, so a malformed probe cannot pass for a refusal).
 *  - Join paths: a dotted path through any listed foreign key is refused in select and in where.
 *  - Forged ids: out-of-scope rows — sampled per scope branch, trashed and contact-less rows too —
 *    come back empty when named by id, and naming them inside an OR adds nothing.
 *  - S3: an activity type the policy does not list is never returned, even when named in where or
 *    combined with an OR, including activities of such types on the caller's own cases.
 *
 * VCs: the contact ids in MCP_LIVE_VC_IDS (test.vc and VC B). Read-only; asserts ids and counts only.
 *
 * @group live
 */
class LiveVcRefusalsTest extends TestCase {

  private const REFUSED_FIELD = 'Field cannot be used in where or orderBy';
  private const REFUSED_SELECT = 'Field not available';

  protected function setUp(): void {
    if (!getenv('MCP_LIVE_USER') || !class_exists('Civi')) {
      $this->markTestSkipped('Set MCP_LIVE_USER and run inside a CiviCRM site.');
    }
  }

  /** @return int[] the test VCs in MCP_LIVE_VC_IDS (test.vc is found by contact id: its login is its email) */
  private function vcIds(): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) getenv('MCP_LIVE_VC_IDS'))))));
    if (!$ids) {
      $this->markTestSkipped('Set MCP_LIVE_VC_IDS (test.vc and VC B).');
    }
    return $ids;
  }

  private static function engine(): ScopedQuery {
    return new ScopedQuery(VcScopePolicy::forSite(), \Closure::fromCallable(new Api4Executor()));
  }

  private static function scope(int $me): VcScope {
    return (new VcScopeResolver(new Api4VcScopeSource()))->resolve($me);
  }

  /** @return array<string, string[]> entity => the fields the policy returns unconditionally */
  private static function returned(): array {
    $out = [];
    foreach (VcScopePolicy::ENTITIES as $entity => $spec) {
      $out[$entity] = $spec['fields'];
    }
    $out['Activity'] = VcScopePolicy::ACTIVITY_FIELDS;
    return $out;
  }

  /** @return string[] fields a row may lose (never filterable) */
  private static function demotable(string $entity): array {
    return match ($entity) {
      'Activity' => VcScopePolicy::ACTIVITY_DEMOTABLE,
      'Contact' => array_values(array_diff(VcScopePolicy::ENTITIES['Contact']['fields'], VcScopePolicy::NAMED_FIELDS)),
      default => [],
    };
  }

  /** @return array<string, string> every field getFields offers => its APIv4 data type */
  private static function types(string $entity): array {
    return array_column(civicrm_api4($entity, 'getFields', ['checkPermissions' => FALSE, 'action' => 'get', 'select' => ['name', 'data_type']])->getArrayCopy(), 'data_type', 'name');
  }

  /** Assert the query is refused with $prefix (the reason, not just any error). */
  private function refused(ScopedQuery $q, int $me, array $args, string $prefix, string $label): void {
    try {
      $q->run($args + ['limit' => 1], new ToolContext($me, 5));
      $this->fail("not refused: $label");
    }
    catch (ToolException $e) {
      $this->assertStringStartsWith($prefix, $e->getMessage(), "refused for another reason: $label");
    }
  }

  /** §4: of every field on offer, exactly the expected ones are filterable and sortable. */
  public function testNoOracleOnAnyFieldButTheExpectedOnes(): void {
    $me = $this->vcIds()[0];
    $q = self::engine();
    $refused = 0;
    foreach (self::returned() as $entity => $listed) {
      $types = self::types($entity);
      // Compared by base name, as EntityPolicy does (`x` and `x:label` are one field).
      $demotable = array_map(fn($d) => explode(':', $d)[0], self::demotable($entity));
      $accepted = 0;
      foreach (array_keys($types) as $f) {
        $expected = in_array($f, $listed, TRUE) && !in_array($f, $demotable, TRUE)
          && in_array($types[$f], EntityPolicy::FILTERABLE_TYPES, TRUE);
        if ($expected) {
          // Positive control: the probe itself is well-formed.
          $q->run(['entity' => $entity, 'select' => ['id'], 'where' => [[$f, 'IS NOT NULL']], 'orderBy' => [$f => 'ASC'], 'limit' => 1], new ToolContext($me, 5));
          $accepted++;
          continue;
        }
        $this->refused($q, $me, ['entity' => $entity, 'select' => ['id'], 'where' => [[$f, 'IS NOT NULL']]], self::REFUSED_FIELD, "$entity where $f");
        $this->refused($q, $me, ['entity' => $entity, 'select' => ['id'], 'where' => [['OR', [['id', '>', 0], [$f, 'IS NOT NULL']]]]], self::REFUSED_FIELD, "$entity where OR $f");
        $this->refused($q, $me, ['entity' => $entity, 'select' => ['id'], 'orderBy' => [$f => 'ASC']], self::REFUSED_FIELD, "$entity orderBy $f");
        $refused++;
      }
      // The suffix forms, gated feedback and share answers of the listed fields.
      $extra = array_filter($listed, fn($f) => str_contains($f, ':'));
      if ($entity === 'Case') {
        $extra = array_merge($extra, VcScopePolicy::CLIENT_FEEDBACK, [VcScopePolicy::SHARE_FIELD, 'Project_Close_Client.use_in_marketing']);
      }
      foreach (array_unique($extra) as $f) {
        $this->refused($q, $me, ['entity' => $entity, 'select' => ['id'], 'where' => [[$f, 'IS NOT NULL']]], self::REFUSED_FIELD, "$entity where $f");
        $this->refused($q, $me, ['entity' => $entity, 'select' => ['id'], 'orderBy' => [$f => 'ASC']], self::REFUSED_FIELD, "$entity orderBy $f");
        $refused++;
      }
      $this->assertGreaterThan(0, $accepted, "$entity: not even id is filterable (probe broken?)");
    }
    $this->assertGreaterThan(100, $refused);
  }

  /** No dotted path through a listed foreign key, in select or where, for every entity. */
  public function testJoinPathsAreRefused(): void {
    $me = $this->vcIds()[0];
    $q = self::engine();
    $tried = 0;
    foreach (self::returned() as $entity => $fields) {
      $fks = array_filter($fields, fn($f) => !str_contains($f, ':') && !str_contains($f, '.') && ($f === 'id' || str_ends_with($f, '_id') || str_ends_with($f, '_id_a') || str_ends_with($f, '_id_b')));
      foreach ($fks as $fk) {
        foreach (['id', 'display_name', 'subject', 'details'] as $far) {
          $this->refused($q, $me, ['entity' => $entity, 'select' => ['id', "$fk.$far"]], self::REFUSED_SELECT, "$entity select $fk.$far");
          $this->refused($q, $me, ['entity' => $entity, 'select' => ['id'], 'where' => [["$fk.$far", 'IS NOT NULL']]], self::REFUSED_FIELD, "$entity where $fk.$far");
        }
        $tried++;
      }
    }
    $this->assertGreaterThan(5, $tried);
  }

  /** @return int[] ids of $entity matching $where, read as staff (newest first) */
  private static function ids(string $entity, array $where, int $n = 100): array {
    $rows = civicrm_api4($entity, 'get', ['checkPermissions' => FALSE, 'select' => ['id'], 'where' => $where, 'orderBy' => ['id' => 'DESC'], 'limit' => $n]);
    return array_map('intval', array_column($rows->getArrayCopy(), 'id'));
  }

  /**
   * Out-of-scope ids of $entity, sampled per branch of the policy's scope so each way out is tried.
   *
   * @return array<string, int[]> branch => ids
   */
  private static function outside(string $entity, VcScope $s): array {
    $cases = $s->cases ?: [0];
    $contacts = $s->contacts ?: [0];
    $visible = array_merge($s->contacts, $s->named) ?: [0];
    $both = ['is_deleted', 'IN', [TRUE, FALSE]];
    $reps = array_map('intval', array_column(\Civi\Api4\Contact::get(FALSE)->addSelect('id')->addWhere('contact_sub_type', 'CONTAINS', 'MAS_Rep')->execute()->getArrayCopy(), 'id'));
    $otherReps = array_values(array_diff($reps, [$s->contactId])) ?: [0];
    return match ($entity) {
      'Case' => ['outside' => self::ids('Case', [['id', 'NOT IN', $cases], $both])],
      'CaseContact' => ['outside' => self::ids('CaseContact', [['case_id', 'NOT IN', $cases]])],
      'Contact' => [
        'outside' => self::ids('Contact', [['id', 'NOT IN', $visible], $both]),
        'other VCs' => self::ids('Contact', [['id', 'NOT IN', $visible], ['id', 'IN', $otherReps], $both]),
      ],
      'Email', 'Phone', 'Address' => [
        'outside' => self::ids($entity, [['contact_id', 'NOT IN', $contacts]]),
        'other VCs' => self::ids($entity, [['contact_id', 'NOT IN', $contacts], ['contact_id', 'IN', $otherReps]]),
        // NOT IN never matches NULL: the contact-less (event venue) rows, sampled on their own.
        'no contact' => self::ids($entity, [['contact_id', 'IS NULL']]),
      ],
      'Relationship' => [
        'case role, case outside' => self::ids('Relationship', [['case_id', 'IS NOT NULL'], ['case_id', 'NOT IN', $cases]]),
        // D9/D23: two visible contacts do not open a role on an out-of-scope case.
        'case role outside, both contacts visible' => self::ids('Relationship', [['case_id', 'IS NOT NULL'], ['case_id', 'NOT IN', $cases], ['contact_id_a', 'IN', $contacts], ['contact_id_b', 'IN', $contacts]]),
        'no case, a contact outside' => self::ids('Relationship', [['case_id', 'IS NULL'], ['OR', [['contact_id_a', 'NOT IN', $contacts], ['contact_id_b', 'NOT IN', $contacts]]]]),
      ],
      'Activity' => [
        'case outside' => self::ids('Activity', [['case_id', 'IS NOT NULL'], ['case_id', 'NOT IN', $cases]]),
        'no case' => self::ids('Activity', [['case_id', 'IS NULL']]),
        'trashed, case inside' => self::ids('Activity', [['case_id', 'IN', $cases], ['is_deleted', '=', TRUE]]),
        'old revision, case inside' => self::ids('Activity', [['case_id', 'IN', $cases], ['is_current_revision', '=', FALSE]]),
      ],
    };
  }

  public function testForgedIdsComeBackEmpty(): void {
    $sampled = [];
    foreach ($this->vcIds() as $me) {
      $s = self::scope($me);
      $q = self::engine();
      foreach (array_keys(self::returned()) as $entity) {
        foreach (self::outside($entity, $s) as $branch => $forged) {
          $sampled["$entity: $branch"] = ($sampled["$entity: $branch"] ?? 0) + count($forged);
          foreach (array_chunk($forged, ScopedQuery::MAX_VALUES - 1) as $chunk) {
            $out = $q->run(['entity' => $entity, 'select' => ['id'], 'where' => [['id', 'IN', $chunk]], 'limit' => 200], new ToolContext($me, 200));
            $this->assertSame(0, $out['matched'], "VC $me: $entity forged ids returned ($branch)");
            // Inside an OR whose other branch matches nothing, they still match nothing.
            $or = $q->run(['entity' => $entity, 'select' => ['id'], 'where' => [['OR', [['id', 'IN', $chunk], ['id', '<', 0]]]], 'limit' => 1], new ToolContext($me, 5));
            $this->assertSame(0, $or['matched'], "VC $me: $entity forged ids returned through OR ($branch)");
          }
        }
      }
    }
    // Every branch must have had something to forge, or it proved nothing — except these, which
    // depend on the site's data: venue rows need an event location block, trashed or old-revision
    // activities need a VC's case to have one (CiviCRM no longer writes revisions), and the case
    // role between two visible contacts is a seeded fixture (absent on prod).
    $mayBeEmpty = ['Email: no contact', 'Phone: no contact', 'Address: no contact', 'Activity: trashed, case inside',
      'Activity: old revision, case inside'];
    // When VC B is one of the VCs checked, its fixture's hidden case role must be sampled (#72 L6).
    $vcb = \Civi\Api4\Contact::get(FALSE)->addSelect('id')->addWhere('external_identifier', '=', 'T9-VC-B')->execute()->first()['id'] ?? NULL;
    if ($vcb === NULL || !in_array((int) $vcb, $this->vcIds(), TRUE)) {
      $mayBeEmpty[] = 'Relationship: case role outside, both contacts visible';
    }
    foreach ($sampled as $branch => $n) {
      if (!in_array($branch, $mayBeEmpty, TRUE)) {
        $this->assertGreaterThan(0, $n, "no out-of-scope row to forge for $branch");
      }
    }
    fwrite(STDERR, "\nforged ids tried per branch: " . json_encode($sampled) . "\n");
  }

  /** S3: an unlisted activity type never comes back, even named in where or in an OR. */
  public function testUnlistedActivityTypesNeverReturn(): void {
    $listed = array_merge(VcScopePolicy::ACTIVITY_FULL, VcScopePolicy::ACTIVITY_TYPE_DATE);
    $unlistedTypes = array_map('intval', array_column(\Civi\Api4\OptionValue::get(FALSE)->addSelect('value')
      ->addWhere('option_group_id:name', '=', 'activity_type')->addWhere('name', 'NOT IN', $listed)->execute()->getArrayCopy(), 'value'));
    $this->assertNotEmpty($unlistedTypes);
    $this->assertLessThan(ScopedQuery::MAX_VALUES, count($unlistedTypes), 'too many unlisted types for one IN list');
    $onOwnCases = 0;
    foreach ($this->vcIds() as $me) {
      $s = self::scope($me);
      $q = self::engine();
      // Activities of unlisted types on the VC's OWN in-scope cases: the scope alone would admit them.
      $hidden = $s->cases ? self::ids('Activity', [['case_id', 'IN', $s->cases], ['activity_type_id', 'IN', $unlistedTypes], ['is_deleted', '=', FALSE]], 199) : [];
      $onOwnCases += count($hidden);
      $attempts = [
        [['activity_type_id', 'IN', $unlistedTypes]],
        [['OR', [['activity_type_id', 'IN', $unlistedTypes], ['id', '>', 0]]]],
      ];
      if ($hidden) {
        $attempts[] = [['id', 'IN', $hidden]];
        $attempts[] = [['OR', [['id', 'IN', $hidden], ['activity_type_id', 'IN', $unlistedTypes]]]];
        $attempts[] = [['NOT', [['activity_type_id', 'NOT IN', $unlistedTypes]]]];
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
    // Otherwise the in-scope half of the test proves nothing — required where the fixtures are
    // seeded (masdemo); on prod the test VC may have none, and the out-of-scope half still ran.
    if (!$onOwnCases && !\Civi\Api4\Contact::get(FALSE)->addWhere('external_identifier', '=', 'T9-ORG-B')->execute()->count()) {
      $this->markTestIncomplete('no unlisted-type activity on a test VC case here: only the where/OR half ran');
    }
    $this->assertGreaterThan(0, $onOwnCases, 'no unlisted-type activity on any test VC case');
  }

}
