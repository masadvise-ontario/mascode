<?php

namespace Civi\Mascode\Mcp\Tests\Live;

use Civi\Api4\Activity;
use Civi\Api4\CiviCase;
use Civi\Api4\Contact;
use Civi\Api4\Relationship;
use Civi\Mascode\Mcp\Vc\Api4VcScopeSource;
use Civi\Mascode\Mcp\Vc\VcScopePolicy;
use Civi\Mascode\Mcp\Vc\VcScopeResolver;
use Civi\Mcp\Scoped\Api4Executor;
use Civi\Mcp\Scoped\ScopedQuery;
use Civi\Mcp\Tools\ToolContext;
use PHPUnit\Framework\TestCase;

/**
 * T10 gaps (3), (5), (7) and the S6 half of (9), through ScopedQuery (the engine `vc_query` runs),
 * on the fixtures scripts/seed-vc-test-fixtures.php commits on masdemo:
 *
 *  - D22: every client-feedback field, ratings included, is null unless the share answer is exactly
 *    "Yes" — checked on No, none, empty, "yes", "Yes ", "YES", a hand-typed "Y" and Yes-then-No, with
 *    the "Yes" case as the control that feedback does come back.
 *  - S4: no returned activity on a case without consent carries the client's close-form answers,
 *    matched by the answer text (never the question wording, which staff notes legitimately paste).
 *    Checked on every such case in each test VC's scope — real cases as well as the fixture's form
 *    activity and its Email copy.
 *  - S6: a copied Email follows its source — a copy of the close form or of a type a legacy custom
 *    group extends comes back as type and date only; a copy of a Follow up comes back in full.
 *  - Scope follows relationship changes: a coordinator row added, then deactivated, inside a
 *    transaction that is always rolled back, moves the case and the org's employees in and out.
 *
 * Fixture tests skip where the fixtures are absent (the seeder refuses Production); the
 * relationship-change test writes (rolled back), so it refuses Production itself.
 *
 * @group live
 */
class LiveVcFeedbackTest extends TestCase {

  /** Share answer per fixture case, as the seeder stores it. */
  private const SHARE = [
    // fb_empty is written as '' and CiviCRM stores it as none, so it repeats fb_blank; fb_yes_to_no
    // ends as No, as fb_no does, reached through a Yes (#72 L3).
    'fb_yes' => 'Yes', 'fb_no' => 'No', 'fb_blank' => NULL, 'fb_empty' => '', 'fb_yes_lower' => 'yes',
    'fb_yes_space' => 'Yes ', 'fb_yes_upper' => 'YES', 'fb_handtyped' => 'Y', 'fb_yes_to_no' => 'No',
  ];

  /** The free-text answers; the ratings are option values, too short to match on. */
  private const ANSWERS = ['Project_Close_Client.satisfaction_comment', 'Project_Close_Client.reuse_comment', 'Project_Close_Client.benefits_realized'];

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
    $id = CiviCase::get(FALSE)->addSelect('id')->addWhere('subject', 'LIKE', '%' . str_replace('_', '\\_', "T9 synthetic: $key"))
      ->addWhere('is_deleted', '=', FALSE)->execute()->first()['id'] ?? NULL;
    return $id === NULL ? NULL : (int) $id;
  }

  private static function activity(string $key): ?int {
    $id = Activity::get(FALSE)->addSelect('id')->addWhere('subject', '=', "T10 synthetic: $key")->addWhere('is_deleted', '=', FALSE)->execute()->first()['id'] ?? NULL;
    return $id === NULL ? NULL : (int) $id;
  }

  private function need(?int ...$ids): void {
    if (in_array(NULL, $ids, TRUE)) {
      $this->markTestSkipped('T10 fixtures missing: run scripts/seed-vc-test-fixtures.php (dev only).');
    }
  }

  private static function engine(): ScopedQuery {
    return new ScopedQuery(VcScopePolicy::forSite(), \Closure::fromCallable(new Api4Executor()));
  }

  /** @return array<int, array> rows keyed by id, all pages */
  private static function rows(int $me, string $entity, array $select, array $where = []): array {
    $q = self::engine();
    $rows = [];
    $offset = 0;
    do {
      $out = $q->run(['entity' => $entity, 'select' => $select, 'where' => $where, 'orderBy' => ['id' => 'ASC'], 'limit' => 200, 'offset' => $offset], new ToolContext($me, 200));
      foreach ($out['rows'] as $row) {
        $rows[(int) $row['id']] = $row;
      }
      $offset += count($out['rows']);
    } while ($out['truncated'] && $out['rows']);
    return $rows;
  }

  /** Lower case, each tag a space, whitespace collapsed: how answers and returned text are compared. */
  private static function flat(?string $text): string {
    $text = html_entity_decode((string) preg_replace('/<[^<>]*+>/', ' ', (string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($text)));
  }

  /**
   * The pieces of an answer to look for (#72 review M2): its lines and sentences, split at links too
   * (the sanitiser replaces those), each taken whole when short and as 8-word windows when long, so
   * a copy that wraps, re-tags, cuts at 1,000 characters or loses a link still matches.
   *
   * @return string[]
   */
  private static function pieces(?string $answer): array {
    $out = [];
    foreach (preg_split('/[\r\n]+|<br\s*\/?>|<\/p>|(?<=[.!?])\s+|\S*(?:https?:|www\.)\S*/i', (string) $answer) as $part) {
      $words = preg_split('/\s+/u', self::flat($part), -1, PREG_SPLIT_NO_EMPTY);
      $chunks = count($words) <= 8 ? [$words] : array_map(fn($i) => array_slice($words, $i, 8), range(0, count($words) - 8));
      foreach ($chunks as $chunk) {
        $piece = implode(' ', $chunk);
        // Short pieces ("n/a", "none", "thank you") are common words, not the client's words.
        if (mb_strlen($piece) >= 20) {
          $out[$piece] = TRUE;
        }
      }
    }
    return array_keys($out);
  }

  /** The T9/T10 fixtures are seeded here (masdemo), so data-dependent guards must bite. */
  private static function seeded(): bool {
    return self::contact('ORG-B') !== NULL;
  }

  /** D22 over every stored variant, every listed feedback field. */
  public function testFeedbackGateOverEveryShareVariant(): void {
    $vcb = self::contact('VC-B');
    $ids = array_map(fn($k) => self::case($k), array_combine(array_keys(self::SHARE), array_keys(self::SHARE)));
    $this->need($vcb, ...array_values($ids));

    $fields = array_values(array_unique(VcScopePolicy::CLIENT_FEEDBACK));
    $bases = array_values(array_filter($fields, fn($f) => !str_contains($f, ':')));
    $raw = CiviCase::get(FALSE)->addSelect('id', VcScopePolicy::SHARE_FIELD, ...$bases)->addWhere('id', 'IN', array_values($ids))->execute()->indexBy('id');
    $rows = self::rows($vcb, 'Case', array_merge(['id'], $fields), [['id', 'IN', array_values($ids)]]);
    $this->assertSame(count($ids), count($rows), 'every fixture case is in VC B scope');
    foreach ($ids as $key => $id) {
      $share = $raw[$id][VcScopePolicy::SHARE_FIELD];
      // The fixture must hold what it claims, or the variant proves nothing.
      if (self::SHARE[$key] === NULL || self::SHARE[$key] === '') {
        $this->assertTrue($share === NULL || $share === '', "fixture $key: share answer");
      }
      else {
        $this->assertSame(self::SHARE[$key], $share, "fixture $key: share answer");
      }
      // Every field holds a value, or its null check below would prove nothing (#72 L4).
      foreach ($bases as $b) {
        $this->assertNotEmpty($raw[$id][$b], "fixture $key: $b has a value");
      }
      foreach ($fields as $f) {
        if ($key === 'fb_yes') {
          $this->assertNotNull($rows[$id][$f], "control fb_yes: $f is returned on Yes");
        }
        else {
          $this->assertNull($rows[$id][$f], "D22 $key: $f withheld");
        }
      }
    }
  }

  /**
   * S4: on every case in scope without consent, no returned activity's subject or details holds any
   * of the client's free-text answers — as each test VC, real cases and fixtures alike.
   */
  public function testNoCloseFormAnswersWithoutConsent(): void {
    $vcs = array_values(array_unique(array_filter(array_merge(
      array_map('intval', explode(',', (string) getenv('MCP_LIVE_VC_IDS'))), [self::contact('VC-B')]))));
    if (!$vcs) {
      $this->markTestSkipped('Set MCP_LIVE_VC_IDS.');
    }
    $cases = 0;
    $rowsChecked = [];
    foreach ($vcs as $me) {
      $scope = (new VcScopeResolver(new Api4VcScopeSource()))->resolve($me);
      if (!$scope->cases) {
        continue;
      }
      $answers = [];
      foreach (array_chunk($scope->cases, 500) as $chunk) {
        foreach (CiviCase::get(FALSE)->addSelect('id', VcScopePolicy::SHARE_FIELD, ...self::ANSWERS)->addWhere('id', 'IN', $chunk)->execute() as $c) {
          if ($c[VcScopePolicy::SHARE_FIELD] === VcScopePolicy::SHARE_YES) {
            continue;
          }
          foreach (self::ANSWERS as $f) {
            foreach (self::pieces($c[$f] ?? NULL) as $p) {
              $answers[(int) $c['id']][] = $p;
            }
          }
        }
      }
      foreach ($answers as $caseId => $list) {
        foreach (self::rows($me, 'Activity', ['id', 'subject', 'details'], [['case_id', '=', $caseId]]) as $row) {
          $text = self::flat(($row['subject'] ?? '') . ' ' . ($row['details'] ?? ''));
          foreach ($list as $p) {
            // Never assertStringNotContainsString: it would print client text on failure (#72 review M1).
            $this->assertFalse(str_contains($text, $p), "VC $me: activity #{$row['id']} on case $caseId carries a " . mb_strlen($p) . '-character piece of a client answer without consent');
          }
          $rowsChecked[(int) $row['id']] = TRUE;
        }
        $cases++;
      }
    }
    if (!$rowsChecked && !self::seeded()) {
      $this->markTestSkipped('No case without consent carries answers in the test VCs\' scope here, and no fixtures (prod).');
    }
    $this->assertGreaterThan(0, count($rowsChecked), "$cases case(s) without consent carried answers, but no activity was checked");
    if (self::seeded()) {
      // The fixture's close-form activity and its Email copy were among what was checked (#72 L1).
      foreach (['feedback_form', 'copy_of_feedback'] as $k) {
        $this->assertArrayHasKey((int) self::activity($k), $rowsChecked, "fixture $k was not checked");
      }
    }
  }

  /** S6: a copied Email follows its source's rule. */
  public function testCopiedEmailFollowsItsSource(): void {
    $vcb = self::contact('VC-B');
    $a = [];
    foreach (['feedback_form', 'copy_of_feedback', 'legacy_source', 'copy_of_legacy', 'followup_source', 'copy_of_followup'] as $k) {
      $a[$k] = self::activity($k);
    }
    $this->need($vcb, ...array_values($a));
    $rows = self::rows($vcb, 'Activity', ['id', 'activity_type_id:name', 'details'], [['id', 'IN', array_values($a)]]);
    $this->assertSame(count($a), count($rows), 'every fixture activity is returned (as a row)');
    $expect = [
      // §1: the close form is type and date only; a copy of it too (§2).
      'feedback_form' => FALSE, 'copy_of_feedback' => FALSE,
      // A Project Definition is returned in full, but a legacy custom group extends its type, so a copy is not (§2).
      'legacy_source' => TRUE, 'copy_of_legacy' => FALSE,
      // The control: a copy of a Follow up comes back in full.
      'followup_source' => TRUE, 'copy_of_followup' => TRUE,
    ];
    foreach ($expect as $k => $full) {
      $this->assertSame($full, $rows[$a[$k]]['details'] !== NULL, "S6 $k: details " . ($full ? 'returned' : 'withheld'));
    }
  }

  /**
   * Scope follows relationship changes, end to end: VC C (no own case) gains Org B's case and its
   * employee when made its coordinator, and loses them when the row is deactivated. All inside a
   * transaction that is always rolled back.
   */
  public function testScopeFollowsRelationshipChanges(): void {
    if (\Civi::settings()->get('environment') === 'Production') {
      $this->markTestSkipped('Writes (rolled back): dev only.');
    }
    $vcc = self::contact('VC-C');
    $org = self::contact('ORG-B');
    $emp = self::contact('ORG-B-EMP');
    $case = self::case('own_org');
    $this->need($vcc, $org, $emp, $case);
    $sees = function () use ($vcc, $case, $emp): array {
      $c = self::rows($vcc, 'Case', ['id'], [['id', '=', $case]]);
      $e = self::rows($vcc, 'Contact', ['id', 'first_name'], [['id', '=', $emp]]);
      return ['case' => isset($c[$case]), 'employee' => ($e[$emp]['first_name'] ?? NULL) !== NULL];
    };
    $this->assertSame(['case' => FALSE, 'employee' => FALSE], $sees(), 'before: VC C sees neither');

    $tx = new \CRM_Core_Transaction();
    try {
      $rel = (int) Relationship::create(FALSE)->setValues(['contact_id_a' => $vcc, 'contact_id_b' => $org, 'relationship_type_id:name' => 'Case Coordinator is',
        'case_id' => $case, 'is_active' => TRUE])->execute()->first()['id'];
      $this->assertSame(['case' => TRUE, 'employee' => TRUE], $sees(), 'coordinator: VC C sees the case and the org employee in full');
      Relationship::update(FALSE)->addWhere('id', '=', $rel)->addValue('is_active', FALSE)->execute();
      $this->assertSame(['case' => FALSE, 'employee' => FALSE], $sees(), 'deactivated: VC C sees neither again');
    }
    finally {
      $tx->rollback()->commit();
      // Checked here too, so a failed assertion above cannot hide a rollback that did not happen (#72 L7).
      if (Relationship::get(FALSE)->addWhere('contact_id_a', '=', $vcc)->addWhere('case_id', '=', $case)->execute()->count()) {
        throw new \RuntimeException('the coordinator row was not rolled back: delete it by hand');
      }
    }
    $this->assertSame(0, Relationship::get(FALSE)->addWhere('contact_id_a', '=', $vcc)->addWhere('case_id', '=', $case)->execute()->count(), 'rolled back');
    $this->assertSame(['case' => FALSE, 'employee' => FALSE], $sees(), 'after rollback: VC C sees neither');
  }

}
