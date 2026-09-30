<?php

namespace Civi\Mascode\Mcp\Tests\Unit;

use Civi\Mascode\Mcp\Vc\ScopeRefused;
use Civi\Mascode\Mcp\Vc\TextSanitiser;
use Civi\Mascode\Mcp\Vc\VcScope;
use Civi\Mascode\Mcp\Vc\VcScopePolicy;
use Civi\Mcp\Protocol\ToolException;
use Civi\Mcp\Scoped\ScopedQuery;
use Civi\Mcp\Tools\ToolContext;
use PHPUnit\Framework\TestCase;

/**
 * T5b-2: the VC entity policies (VC access spec § vc_query entity policy; D14, D15, D22, D23).
 * A fake getFields offers every listed field, so each test can take one away; a fake executor
 * returns chosen rows, so the per-row rules (share gate, name-only demotion, sanitiser) are
 * asserted on what a VC would receive. Synthetic IDs and values only.
 *
 * @group unit
 */
class VcScopePolicyTest extends TestCase {

  private const ME = 3;

  /** @var array<string, string[]> fields to leave out of the fake getFields, per entity */
  private array $missing = [];

  /** @var string[] custom groups to leave out */
  private array $missingGroups = [];

  /** @var list<array> rows the fake executor returns */
  private array $rows = [];

  /** @var list<array{0: string, 1: array}> */
  private array $executed = [];

  private function scope(): VcScope {
    return new VcScope(self::ME, [100], [102], [10, 11], [100, 101, 102], [20], [self::ME, 10, 11, 20, 30], [1, 4, 31]);
  }

  /** @var array<int, array{type: string, source_record_id: ?int, case_ids: int[]}> source activities for copies */
  private array $sources = [];

  /** @var string[]|null activity types a foreign custom group extends; NULL = every type */
  private ?array $foreign = ['Project Definition'];

  private function policy(?\Closure $scope = NULL): VcScopePolicy {
    return new VcScopePolicy(
      $scope ?? fn(int $cid) => $this->scope(),
      function (string $entity): array {
        $out = [];
        $names = $entity === 'Activity' ? VcScopePolicy::ACTIVITY_FIELDS
          : array_merge(VcScopePolicy::ENTITIES[$entity]['fields'], $entity === 'Case' ? array_merge(VcScopePolicy::CLIENT_FEEDBACK, [VcScopePolicy::SHARE_FIELD, 'Project_Close_Client.use_in_marketing']) : []);
        foreach ($names as $name) {
          [$base, $suffix] = array_pad(explode(':', $name, 2), 2, NULL);
          // A missing base name takes its suffixed forms with it; a missing suffix only itself.
          if (in_array($name, $this->missing[$entity] ?? [], TRUE) || in_array($base, $this->missing[$entity] ?? [], TRUE)) {
            continue;
          }
          $type = preg_match('/(^id$|_id$|_id_[ab]$|^_?[A-Z]?[a-z]*_Employees$)/', $base) ? 'Integer'
            : (preg_match('/date/i', $base) ? 'Date' : (str_starts_with($base, 'is_') ? 'Boolean' : 'String'));
          $out[$base] ??= ['data_type' => $type, 'suffixes' => []];
          if ($suffix !== NULL) {
            $out[$base]['suffixes'][] = $suffix;
          }
        }
        return $out;
      },
      fn(string $entity) => array_values(array_diff($entity === 'Activity' ? VcScopePolicy::ACTIVITY_GROUPS : (VcScopePolicy::ENTITIES[$entity]['groups'] ?? []), $this->missingGroups)),
      fn(array $ids) => array_intersect_key($this->sources, array_flip($ids)),
      fn() => $this->foreign,
    );
  }

  private function query(array $args, ?VcScopePolicy $policy = NULL): array {
    $exec = function (string $entity, array $params): array {
      $this->executed[] = [$entity, $params];
      return [$this->rows, count($this->rows)];
    };
    return (new ScopedQuery($policy ?? $this->policy(), $exec))->run($args, new ToolContext(self::ME, 50));
  }

  public function testEntities(): void {
    $this->assertSame(['Case', 'CaseContact', 'Contact', 'Email', 'Phone', 'Address', 'Relationship', 'Activity'], $this->policy()->entities());
    $this->assertNull($this->policy()->entityPolicy('Contribution', self::ME));
  }

  /** Every entity's rows are bound to the caller's scope sets, ANDed above the caller's where. */
  public function testScopeClauses(): void {
    $p = $this->policy();
    $this->assertSame([['id', 'IN', [100, 101, 102]]], $p->entityPolicy('Case', self::ME)->scope);
    $this->assertSame([['case_id', 'IN', [100, 101, 102]]], $p->entityPolicy('CaseContact', self::ME)->scope);
    // D15: named contacts are in Contact's rows (names only), never in Email / Phone / Address (D14).
    $this->assertSame([['id', 'IN', [self::ME, 10, 11, 20, 30, 1, 4, 31]]], $p->entityPolicy('Contact', self::ME)->scope);
    foreach (['Email', 'Phone', 'Address'] as $e) {
      $this->assertSame([['contact_id', 'IN', [self::ME, 10, 11, 20, 30]]], $p->entityPolicy($e, self::ME)->scope, $e);
    }
    $this->assertSame([['OR', [['case_id', 'IN', [100, 101, 102]],
      ['AND', [['contact_id_a', 'IN', [self::ME, 10, 11, 20, 30]], ['contact_id_b', 'IN', [self::ME, 10, 11, 20, 30]], ['case_id', 'IS NULL']]]]]],
      $p->entityPolicy('Relationship', self::ME)->scope);
  }

  /** D22: every feedback field, ratings too, only where the raw share answer is exactly "Yes". */
  public function testClientFeedbackGate(): void {
    foreach (['Yes' => 'Great', 'yes' => NULL, 'Yes ' => NULL, 'YES' => NULL, 'No' => NULL, '' => NULL, 'none' => NULL] as $share => $expected) {
      $this->rows = [['id' => 100, 'Project_Close_Client.satisfaction' => 'Great', 'Project_Close_Client.satisfaction_comment' => 'Great',
        VcScopePolicy::SHARE_FIELD => $share === 'none' ? NULL : $share]];
      $out = $this->query(['entity' => 'Case', 'select' => ['id', 'Project_Close_Client.satisfaction', 'Project_Close_Client.satisfaction_comment']]);
      $this->assertSame($expected, $out['rows'][0]['Project_Close_Client.satisfaction'], json_encode($share));
      $this->assertSame($expected, $out['rows'][0]['Project_Close_Client.satisfaction_comment'], json_encode($share));
      $this->assertArrayNotHasKey(VcScopePolicy::SHARE_FIELD, $out['rows'][0], 'the gate is fetched, never returned');
    }
  }

  public function testShareAndMarketingAnswersAreNeverReturnedOrFiltered(): void {
    foreach ([VcScopePolicy::SHARE_FIELD, 'Project_Close_Client.use_in_marketing'] as $f) {
      try {
        $this->query(['entity' => 'Case', 'select' => ['id', $f]]);
        $this->fail("$f selectable");
      }
      catch (ToolException $e) {
        $this->addToAssertionCount(1);
      }
    }
    // A feedback field is never filterable or sortable (no oracle on hidden answers).
    $this->expectException(ToolException::class);
    $this->query(['entity' => 'Case', 'select' => ['id'], 'where' => [['Project_Close_Client.satisfaction', '=', 'x']]]);
  }

  /** D15: a named contact comes back as id and display name only; a scope contact in full. */
  public function testNamedContactsAreNameOnly(): void {
    $this->rows = [
      ['id' => 30, 'display_name' => 'Client Person', 'first_name' => 'Client', 'job_title' => 'ED', 'employer_id' => 10],
      ['id' => 4, 'display_name' => 'Other VC', 'first_name' => 'Other', 'job_title' => 'Consultant', 'employer_id' => 1],
    ];
    $out = $this->query(['entity' => 'Contact', 'select' => ['display_name', 'first_name', 'job_title', 'employer_id']]);
    $this->assertSame(['display_name' => 'Client Person', 'first_name' => 'Client', 'job_title' => 'ED', 'employer_id' => 10], $out['rows'][0]);
    $this->assertSame(['display_name' => 'Other VC', 'first_name' => NULL, 'job_title' => NULL, 'employer_id' => NULL], $out['rows'][1]);
    $this->assertContains('id', $this->executed[0][1]['select'], 'id is fetched for the rule even when not selected');
  }

  public function testOnlyIdIsFilterableOnContact(): void {
    $this->query(['entity' => 'Contact', 'select' => ['id'], 'where' => [['id', '=', 30]]]);
    foreach (['employer_id', 'contact_type', 'first_name', 'display_name'] as $f) {
      try {
        $this->query(['entity' => 'Contact', 'select' => ['id'], 'where' => [[$f, '=', 1]]]);
        $this->fail("$f filterable");
      }
      catch (ToolException $e) {
        $this->addToAssertionCount(1);
      }
    }
  }

  public function testEveryStringIsSanitised(): void {
    $this->rows = [['id' => 100, 'subject' => 'see https://example.org/x?_aff=Bearer+eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJleHAiOjF9.c2ln', 'Cases_SR_Projects_.Notes' => 'Password: hunter2 ok']];
    $out = $this->query(['entity' => 'Case', 'select' => ['id', 'subject', 'Cases_SR_Projects_.Notes']]);
    $this->assertNull($out['rows'][0]['subject'], 'a form-login token withholds the field');
    $this->assertSame(TextSanitiser::REDACTED . ' ok', $out['rows'][0]['Cases_SR_Projects_.Notes']);
  }

  /** Link-type custom fields, inactive fields and anything not listed are not returned. */
  public function testUnlistedFieldsAreRefused(): void {
    foreach (['Cases_SR_Projects_.Link_to_RCS_Document', 'Projects.End_Date', 'Contact.hash', 'contact_id.display_name', 'email_primary.email'] as $f) {
      try {
        $this->query(['entity' => str_starts_with($f, 'Contact.') ? 'Contact' : 'Case', 'select' => ['id', str_replace('Contact.', '', $f)]]);
        $this->fail("$f selectable");
      }
      catch (ToolException $e) {
        $this->addToAssertionCount(1);
      }
    }
  }

  /** Fail closed: a listed field, suffix, feedback field or group that the site no longer offers. */
  public function testMissingFieldsRefuseTheEntity(): void {
    $cases = [
      ['Case', 'Projects.Notes'],
      ['Case', 'status_id:label'],
      ['Case', 'Project_Close_Client.benefits_realized'],
      ['Case', VcScopePolicy::SHARE_FIELD],
      ['Contact', 'Organization.Industry:label'],
      ['Address', 'country_id'],
    ];
    foreach ($cases as [$entity, $field]) {
      $this->missing = [$entity => [$field]];
      try {
        $this->policy()->entityPolicy($entity, self::ME);
        $this->fail("missing $entity.$field accepted");
      }
      catch (ScopeRefused $e) {
        $this->assertStringContainsString('field not offered', $e->reason);
      }
    }
    $this->missing = [];
    $this->missingGroups = ['Project_Close_VC'];
    $this->expectException(ScopeRefused::class);
    $this->policy()->entityPolicy('Case', self::ME);
  }

  public function testScopeRefusalPropagates(): void {
    $p = $this->policy(fn(int $cid) => throw new ScopeRefused('saved search drifted'));
    $this->expectException(ScopeRefused::class);
    $p->entityPolicy('Case', self::ME);
  }

  /** The contact asked about is always the one in the ToolContext. */
  public function testScopeIsResolvedForTheAuthenticatedContact(): void {
    $asked = [];
    $p = $this->policy(function (int $cid) use (&$asked) {
      $asked[] = $cid;
      return $this->scope();
    });
    $this->query(['entity' => 'Email', 'select' => ['id']], $p);
    $this->assertSame([self::ME], $asked);
  }


  /** vc-activity-policy.md §1: the closed type list is a clause of the query, above the caller's where. */
  public function testActivityScopeIsAClosedTypeList(): void {
    $scope = $this->policy()->entityPolicy('Activity', self::ME)->scope;
    $this->assertSame(['case_id', 'IN', [100, 101, 102]], $scope[0]);
    $this->assertSame('activity_type_id:name', $scope[1][0]);
    $this->assertSame(array_merge(VcScopePolicy::ACTIVITY_FULL, VcScopePolicy::ACTIVITY_TYPE_DATE), $scope[1][2]);
    foreach (['Change Custom Data', 'Draft Email - Needs Review', 'Full Self Assessment Survey (SAS)', 'Inbound Email'] as $never) {
      $this->assertNotContains($never, $scope[1][2]);
    }
    $this->assertSame([['is_deleted', '=', FALSE], ['is_current_revision', '=', TRUE]], [$scope[2], $scope[3]]);
    // An OR in the caller's where cannot reach round the type clause.
    $this->rows = [];
    $this->query(['entity' => 'Activity', 'select' => ['id'], 'where' => [['OR', [['activity_type_id', '=', 99], ['id', '>', 0]]]]]);
    $this->assertSame(array_slice($scope, 0, 4), array_slice($this->executed[0][1]['where'], 0, 4));
  }

  private function activityRow(int $id, string $type, ?int $source = NULL): array {
    return ['id' => $id, 'activity_type_id:name' => $type, 'source_record_id' => $source, 'subject' => "Subject $id", 'details' => "Details $id",
      'activity_date_time' => '2026-09-01 10:00:00'];
  }

  private function activities(array $rows): array {
    $this->rows = $rows;
    return $this->query(['entity' => 'Activity', 'select' => ['id', 'subject', 'details', 'activity_date_time']])['rows'];
  }

  public function testTypeAndDateOnlyTypes(): void {
    $out = $this->activities([
      $this->activityRow(1, 'Case Status Update'),
      $this->activityRow(2, 'Sent Automated Email'),
      $this->activityRow(3, 'Project Close - Client Feedback'),
      $this->activityRow(4, 'Merge Case'),
      $this->activityRow(5, 'Reassigned Case'),
      $this->activityRow(6, 'Something Unlisted'),
    ]);
    $this->assertSame('Subject 1', $out[0]['subject']);
    $this->assertSame('Details 1', $out[0]['details']);
    for ($i = 1; $i < 6; $i++) {
      $this->assertNull($out[$i]['subject'], "row $i");
      $this->assertNull($out[$i]['details'], "row $i");
      $this->assertSame('2026-09-01 10:00:00', $out[$i]['activity_date_time'], 'type and date are kept');
    }
    $this->assertSame([], VcScopePolicy::SAE_SUBJECT_TEMPLATES, 'no automated-email subjects until a template is checked');
  }

  /** §2: a copied Email keeps its details only when its source may itself be read in full. */
  public function testCopiedEmailRule(): void {
    $this->sources = [
      50 => ['type' => 'Case Status Update', 'source_record_id' => NULL, 'case_ids' => [101]],
      51 => ['type' => 'Project Definition', 'source_record_id' => NULL, 'case_ids' => [101]],   // foreign group
      52 => ['type' => 'Email', 'source_record_id' => 50, 'case_ids' => [101]],                   // copy of a copy
      53 => ['type' => 'Case Status Update', 'source_record_id' => NULL, 'case_ids' => [999]],    // out of scope
      54 => ['type' => 'Project Close - Client Feedback', 'source_record_id' => NULL, 'case_ids' => [101]],
      55 => ['type' => 'Email', 'source_record_id' => NULL, 'case_ids' => [100, 999]],            // plain email, one case in scope
    ];
    $out = $this->activities([
      $this->activityRow(1, 'Email', 50), $this->activityRow(2, 'Email', 51), $this->activityRow(3, 'Email', 52),
      $this->activityRow(4, 'Email', 53), $this->activityRow(5, 'Email', 54), $this->activityRow(6, 'Email', 404),
      $this->activityRow(7, 'Email', 55), $this->activityRow(8, 'Email'),
    ]);
    $kept = array_map(fn($r) => $r['details'] !== NULL, $out);
    $this->assertSame([TRUE, FALSE, FALSE, FALSE, FALSE, FALSE, TRUE, TRUE], $kept);
    // A group extending every activity type demotes every copy, and a plain Email is unaffected.
    $this->foreign = NULL;
    $out = $this->activities([$this->activityRow(1, 'Email', 50), $this->activityRow(8, 'Email')]);
    $this->assertSame([FALSE, TRUE], array_map(fn($r) => $r['details'] !== NULL, $out));
  }

  public function testActivityTextIsNeverFilterable(): void {
    foreach (['subject', 'details', 'activity_type_id:name', 'Monthly_Project_Checkin.digest_round'] as $f) {
      try {
        $this->query(['entity' => 'Activity', 'select' => ['id'], 'where' => [[$f, '=', 'x']]]);
        $this->fail("$f filterable");
      }
      catch (ToolException $e) {
        $this->addToAssertionCount(1);
      }
    }
    $this->query(['entity' => 'Activity', 'select' => ['id'], 'where' => [['activity_type_id', 'IN', [1, 2]], ['activity_date_time', '>', '2026-01-01']]]);
    foreach (['source_record_id', 'location', 'result', 'engagement_level', 'target_contact_id', 'source_contact_id', 'Project_Close_Client_Fields.x'] as $f) {
      try {
        $this->query(['entity' => 'Activity', 'select' => ['id', $f]]);
        $this->fail("$f selectable");
      }
      catch (ToolException $e) {
        $this->addToAssertionCount(1);
      }
    }
  }

  public function testActivityDetailsAreSanitised(): void {
    $row = $this->activityRow(1, 'Email');
    $row['details'] = '<a href="https://example.org/civicrm/mas-checkin?_aff=Bearer+eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJleHAiOjF9.Xk3Qa7Lm2c2lnbmF0dXJlaGVyZWxvbmdlcg9Z_w-8vT">Open</a>';
    // Brian, 2026-09-30: the message is shown; the login link inside it is removed.
    $this->assertSame('<a ' . TextSanitiser::LINK . '>Open</a>', $this->activities([$row])[0]['details']);
    $row['details'] = 'Paste this: https://example.org/civicrm/mas-checkin?_aff=Bearer+eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJleHAiOjF9.c2ln';
    $this->assertNull($this->activities([$row])[0]['details'], 'a token outside a link still withholds the field');
  }


  /** The site's foreign-group rule, now pure (PR #13 review L: no test of the unlisted-field branch). */
  public function testAnAllowlistedGroupWithAnUnlistedFieldIsForeign(): void {
    $listed = array_map(fn($n) => ['group' => 'Monthly_Project_Checkin', 'name' => $n], VcScopePolicy::listedActivityFields()['Monthly_Project_Checkin']);
    $this->assertNotEmpty($listed);
    $this->assertSame(['Monthly_Project_Checkin'], VcScopePolicy::trustedActivityGroups($listed), 'every field listed: trusted');
    $this->assertSame(['Monthly_Project_Checkin'], VcScopePolicy::trustedActivityGroups([]), 'no fields yet: trusted');
    $this->assertSame([], VcScopePolicy::trustedActivityGroups(array_merge($listed, [['group' => 'Monthly_Project_Checkin', 'name' => 'client_comment']])),
      'one unlisted field makes the whole group foreign');
    // A name listed for this group says nothing about a group that is not allowlisted.
    $this->assertSame(['Monthly_Project_Checkin'], VcScopePolicy::trustedActivityGroups(array_merge($listed, [['group' => 'Other_Group', 'name' => $listed[0]['name']]])));
  }

  public function testForeignTypeIds(): void {
    $this->assertSame([], VcScopePolicy::foreignTypeIds([]));
    $this->assertSame([3, 7], VcScopePolicy::foreignTypeIds([['extends_entity_column_value' => ['3', '7']], ['extends_entity_column_value' => [7]]]));
    $this->assertNull(VcScopePolicy::foreignTypeIds([['extends_entity_column_value' => [3]], ['extends_entity_column_value' => NULL]]), 'a group on every type');
    $this->assertNull(VcScopePolicy::foreignTypeIds([['extends_entity_column_value' => ['']]]));
  }

  /** T7: the audit row's scope sizes are counts of the resolved sets, and only once one resolved. */
  public function testResolvedSizesAreCountsOfTheLastResolvedScope(): void {
    $policy = $this->policy();
    $this->assertNull($policy->resolvedSizes());
    $policy->entityPolicy('Case', self::ME);
    $this->assertSame(['own_cases' => 1, 'pool_cases' => 1, 'orgs' => 2, 'cases' => 3, 'employees' => 1, 'contacts' => 5, 'named' => 3], $policy->resolvedSizes());
    // A second resolution replaces the first (round 2 L-f).
    $other = $this->policy(fn(int $cid) => $cid === self::ME ? $this->scope() : new VcScope($cid, [], [102], [11], [102], [], [$cid, 11], []));
    $other->entityPolicy('Case', self::ME);
    $other->entityPolicy('Case', self::ME + 1);
    $this->assertSame(['own_cases' => 0, 'pool_cases' => 1, 'orgs' => 1, 'cases' => 1, 'employees' => 0, 'contacts' => 2, 'named' => 0], $other->resolvedSizes());
  }

}
