<?php

namespace Civi\Mascode\Mcp\Tests\Live;

use Civi\Mascode\Mcp\Vc\Api4VcScopeSource;
use Civi\Mascode\Mcp\Vc\TextSanitiser;
use Civi\Mascode\Mcp\Vc\VcScopePolicy;
use Civi\Mascode\Mcp\Vc\VcScopeResolver;
use Civi\Mcp\Scoped\Api4Executor;
use Civi\Mcp\Scoped\ScopedQuery;
use Civi\Mcp\Tools\ToolContext;
use PHPUnit\Framework\TestCase;

/**
 * T10 gap (1), vc-activity-policy.md §5 first bullet: the credential sweep. With source removal
 * dropped (D37) the sanitiser is the MCP's only control on working login links, so this runs it
 * over the WHOLE database, not only the test VCs' scope, and checks it with a detector that shares
 * none of its patterns: raw credential values are extracted from the unsanitised text (any `eyJ…`
 * run, `_aff` / `_authx` / `cs` / `h` / `key` / `api_key` values, `Bearer` values, checksum hashes,
 * and password values after `password` / `passwd` / `pwd` with any tags, quotes or whitespace
 * between), and none of them — normalised, HTML-entity-encoded or percent-encoded — may appear in
 * the sanitised output. Matching is in PHP, never SQL LIKE (`_` is a wildcard there).
 *
 * Two sweeps: every string field of every §3 entity through TextSanitiser::forVc (the function
 * the policy installs), and the same check end to end on what ScopedQuery returns to each test VC,
 * so a field the policy failed to filter is caught too.
 *
 * Read-only. Never prints or asserts on a credential value: failures name the entity, record id,
 * field, the detector kind and the value's length. Counts go to STDERR for review (policy §5:
 * "report how many fields came back null").
 *
 * @group live
 */
class LiveVcCredentialSweepTest extends TestCase {

  /** Shorter extracted values are too common as substrings to test for (e.g. `h=1`). */
  private const MIN_VALUE = 8;

  /** A value this short that is all letters is a word ("Password: pending"), not a secret. */
  private const MIN_WORDLIKE = 12;

  private const PAGE = 500;

  protected function setUp(): void {
    if (!getenv('MCP_LIVE_USER') || !class_exists('Civi')) {
      $this->markTestSkipped('Set MCP_LIVE_USER and run inside a CiviCRM site.');
    }
  }

  /** The sanitiser's own step-1 normaliser (policy §5: each extracted value is normalised the same way). */
  private static function normalise(string $text): ?string {
    static $fn = NULL;
    $fn ??= (new \ReflectionMethod(TextSanitiser::class, 'normalise'))->getClosure();
    return $fn($text);
  }

  /**
   * The independent detector: [kind, value] pairs found in the raw text or its normalised form.
   * Deliberately broader than the sanitiser, and written from the policy text, not from its code.
   *
   * @return list<array{0: string, 1: string}>
   */
  public static function extract(string $raw): array {
    $found = [];
    $norm = self::normalise($raw);
    foreach (array_unique(array_filter([$raw, $norm], 'is_string')) as $text) {
      // Any JWT-looking run, whole and by segment (a leaked signature alone is still a leak).
      if (preg_match_all('/eyJ[\w-]{6,}(?:\.[\w-]+)*/i', $text, $m)) {
        foreach ($m[0] as $run) {
          $found[] = ['jwt', $run];
          foreach (explode('.', $run) as $seg) {
            $found[] = ['jwt-segment', $seg];
          }
        }
      }
      // Credential parameters anywhere a value can follow; any case, any spacing around `=`.
      if (preg_match_all('/(?:^|[?&;\s"\'>(])(_aff|_authx|cs|h|key|api_key)\s*=\s*([^&#\s"\'<>]+)/im', $text, $m, PREG_SET_ORDER)) {
        foreach ($m as $hit) {
          $found[] = ['param:' . strtolower($hit[1]), $hit[2]];
          // `Bearer+eyJ…`: the token after the scheme word is the secret.
          if (preg_match('/^Bearer[+:\s]*(.+)$/i', $hit[2], $b)) {
            $found[] = ['param-bearer', $b[1]];
          }
        }
      }
      if (preg_match_all('/Bearer[+:\s]+([\w.~+\/=-]{16,})/i', $text, $m)) {
        foreach ($m[1] as $v) {
          $found[] = ['bearer', $v];
        }
      }
      if (preg_match_all('/([0-9a-f]{32})_\d+_(?:\d+|inf)/i', $text, $m)) {
        foreach ($m[1] as $v) {
          $found[] = ['checksum', $v];
        }
      }
      // A password value: the keyword, then any mix of tags, quotes and whitespace around a separator.
      $gap = '(?:[\s\p{Z}"\'“”‘’«»*_]|<[^<>]*+>)*+';
      if (preg_match_all('/(?:password|passwd|pwd)' . $gap . '[:=：]' . $gap . '([^\s\p{Z}<>"\'“”‘’«»]+)/iu', $text, $m)) {
        foreach ($m[1] as $v) {
          $found[] = ['password', $v];
        }
      }
    }
    // Keep only values specific enough to search for; drop trailing sentence punctuation.
    $out = [];
    foreach ($found as [$kind, $v]) {
      $v = rtrim($v, '.,;:!?)]');
      $wordlike = preg_match('/^[A-Za-z]+$/', $v) === 1;
      if (strlen($v) >= self::MIN_VALUE && !($wordlike && strlen($v) < self::MIN_WORDLIKE)) {
        $out[$kind . "\0" . $v] = [$kind, $v];
      }
    }
    return array_values($out);
  }

  /** The forms of an extracted value to look for in sanitised output. @return string[] */
  private static function forms(string $value): array {
    $n = self::normalise($value) ?? $value;
    return array_values(array_unique(array_filter(
      [$value, $n, htmlspecialchars($n, ENT_QUOTES | ENT_HTML5), rawurlencode($n), urlencode($n)],
      fn($f) => strlen($f) >= self::MIN_VALUE,
    )));
  }

  /** NULL when clean, else a description (never the value) of the first credential that survives. */
  public static function leak(string $raw, ?string $clean): ?string {
    if ($clean === NULL || $clean === '') {
      return NULL;
    }
    foreach (self::extract($raw) as [$kind, $value]) {
      foreach (self::forms($value) as $form) {
        $hit = $kind === 'checksum' ? stripos($clean, $form) !== FALSE : str_contains($clean, $form);
        if ($hit) {
          return "$kind (length " . strlen($value) . ')';
        }
      }
    }
    return NULL;
  }

  /** @return array<string, string[]> entity => string field names the policy returns */
  private static function stringFields(): array {
    $lists = [];
    foreach (VcScopePolicy::ENTITIES as $entity => $spec) {
      $lists[$entity] = $spec['fields'];
    }
    $lists['Case'] = array_merge($lists['Case'], VcScopePolicy::CLIENT_FEEDBACK);
    $lists['Activity'] = VcScopePolicy::ACTIVITY_FIELDS;
    $out = [];
    foreach ($lists as $entity => $names) {
      $types = array_column(civicrm_api4($entity, 'getFields', ['checkPermissions' => FALSE, 'action' => 'get', 'select' => ['name', 'data_type']])->getArrayCopy(), 'data_type', 'name');
      // Suffixed forms (`:label`) are option labels, fixed by staff config, not free text.
      $out[$entity] = array_values(array_filter($names, fn($n) => !str_contains($n, ':') && in_array($types[$n] ?? '', ['String', 'Text', 'Memo'], TRUE)));
    }
    return $out;
  }

  /** Self-check: the detector finds planted credentials, so a broken detector cannot pass the sweep. */
  public function testTheDetectorFindsPlantedCredentials(): void {
    $jwt = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJjaWQ6MTIzIn0.' . str_repeat('Ab3dE', 9);
    $cases = [
      'jwt' => "see <a href=\"https://x.example/civicrm/?_aff=Bearer+$jwt\">link</a>",
      'param:_authx' => 'go to https://x.example/?_authx=Bearer+abcdef123456XYZ789',
      'checksum' => 'https://x.example/?cid=3&cs=0123456789abcdef0123456789abcdef_1727740800_168',
      'password' => '<p><strong>Password</strong>: &quot;Tr0ub4dor&amp;3&quot;</p>',
      'param:key' => 'key=Zq8vLm2Np4Rs6Tu8',
    ];
    foreach ($cases as $kind => $text) {
      $kinds = array_column(self::extract($text), 0);
      $this->assertContains($kind, $kinds, "detector misses $kind");
      $this->assertNotNull(self::leak($text, $text), "leak() misses $kind in unsanitised text");
    }
    // Percent-encoded in the output still counts.
    $this->assertNotNull(self::leak("password: s3cr3t-Value!", 'x s3cr3t-Value%21 y'));
    // Words after "password" are not secrets.
    $this->assertSame([], self::extract('Password: pending. Reset password = requested'));
  }

  /** Sweep 1: every string field the policy returns, over the whole database, through forVc(). */
  public function testNoCredentialSurvivesTheSanitiserAnywhereInTheDatabase(): void {
    $stats = [];
    $kinds = [];
    $leaks = [];
    foreach (self::stringFields() as $entity => $fields) {
      if (!$fields) {
        continue;
      }
      $s = ['fields' => 0, 'with_credential' => 0, 'null' => 0, 'null_with_credential' => 0];
      $last = 0;
      do {
        $params = ['checkPermissions' => FALSE, 'select' => array_merge(['id'], $fields), 'where' => [['id', '>', $last]], 'orderBy' => ['id' => 'ASC'], 'limit' => self::PAGE];
        if ($entity === 'Activity') {
          // Every type the policy returns, trashed or not (stricter than what a VC can reach).
          $params['where'][] = ['activity_type_id:name', 'IN', array_merge(VcScopePolicy::ACTIVITY_FULL, VcScopePolicy::ACTIVITY_TYPE_DATE)];
          $params['where'][] = ['is_deleted', 'IN', [TRUE, FALSE]];
        }
        if ($entity === 'Case' || $entity === 'Contact') {
          $params['where'][] = ['is_deleted', 'IN', [TRUE, FALSE]];
        }
        $rows = civicrm_api4($entity, 'get', $params);
        foreach ($rows as $row) {
          $last = (int) $row['id'];
          foreach ($fields as $f) {
            $raw = $row[$f] ?? NULL;
            if (!is_string($raw) || $raw === '') {
              continue;
            }
            $s['fields']++;
            $found = self::extract($raw);
            $has = $found !== [];
            foreach (array_unique(array_column($found, 0)) as $k) {
              $kinds[$k] = ($kinds[$k] ?? 0) + 1;
            }
            $clean = TextSanitiser::forVc($raw);
            $s['with_credential'] += (int) $has;
            $s['null'] += (int) ($clean === NULL);
            $s['null_with_credential'] += (int) ($clean === NULL && $has);
            if ($why = self::leak($raw, $clean)) {
              $leaks[] = "$entity #$last $f: $why";
            }
          }
        }
      } while (count($rows) === self::PAGE);
      $stats[$entity] = $s;
    }
    fwrite(STDERR, "\ncredential sweep (whole database): " . json_encode($stats) . "\nfields per detector kind: " . json_encode($kinds) . "\n");
    $this->assertSame([], $leaks, count($leaks) . ' field(s) leak a credential');
    // Both masdemo and prod hold mascode emails with form-login links: a sweep that extracts none
    // is broken, not clean.
    $this->assertGreaterThan(0, $stats['Activity']['with_credential'] ?? 0, 'the detector found no credential in any activity');
  }

  /** @return int[] test VCs: the login test.vc plus MCP_LIVE_VC_IDS */
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

  /** Sweep 2: what ScopedQuery actually returns to each test VC, against the raw row. */
  public function testNoCredentialReachesATestVc(): void {
    $checked = 0;
    $withCredential = 0;
    $leaks = [];
    foreach ($this->vcIds() as $n => $me) {
      $q = new ScopedQuery(VcScopePolicy::forSite(), \Closure::fromCallable(new Api4Executor()));
      $scope = (new VcScopeResolver(new Api4VcScopeSource()))->resolve($me);
      $this->assertNotEmpty($scope->cases, "VC #$n has no cases: the sweep would check nothing");
      foreach (self::stringFields() as $entity => $fields) {
        if (!$fields) {
          continue;
        }
        $names = $entity === 'Case' ? array_values(array_diff($fields, VcScopePolicy::CLIENT_FEEDBACK)) : $fields;
        $gated = $entity === 'Case' ? array_values(array_intersect($fields, VcScopePolicy::CLIENT_FEEDBACK)) : [];
        foreach (array_filter([$names, $gated]) as $chunkSet) {
          foreach (array_chunk($chunkSet, ScopedQuery::MAX_SELECT - 1) as $chunk) {
            $offset = 0;
            do {
              $out = $q->run(['entity' => $entity, 'select' => array_merge(['id'], $chunk), 'orderBy' => ['id' => 'ASC'], 'limit' => 200, 'offset' => $offset], new ToolContext($me, 200));
              $ids = array_map(fn($r) => (int) $r['id'], $out['rows']);
              $raw = $ids ? civicrm_api4($entity, 'get', ['checkPermissions' => FALSE, 'select' => array_merge(['id'], $chunk), 'where' => [['id', 'IN', $ids]]])->indexBy('id') : [];
              foreach ($out['rows'] as $row) {
                foreach ($chunk as $f) {
                  $r = $raw[(int) $row['id']][$f] ?? NULL;
                  if (!is_string($r) || $r === '') {
                    continue;
                  }
                  $checked++;
                  $withCredential += (int) (self::extract($r) !== []);
                  $v = $row[$f] ?? NULL;
                  if (is_string($v) && ($why = self::leak($r, $v))) {
                    $leaks[] = "VC #$n $entity #{$row['id']} $f: $why";
                  }
                }
              }
              $offset += count($out['rows']);
            } while ($out['truncated'] && $out['rows']);
          }
        }
      }
    }
    fwrite(STDERR, "\ncredential sweep (as the test VCs): $checked returned strings, $withCredential with a credential in the raw value\n");
    $this->assertSame([], $leaks, count($leaks) . ' returned field(s) leak a credential');
    $this->assertGreaterThan(0, $checked, 'no returned string was checked');
  }

}
