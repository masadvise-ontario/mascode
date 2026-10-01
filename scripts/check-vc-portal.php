<?php

/**
 * Read-only check of the VC portal's access rules (ticket T12). Safe on masdemo and on prod: it
 * writes nothing and prints COUNTS and record names only — never contact data, subjects or ids.
 *
 * Usage:
 *   cv scr scripts/check-vc-portal.php --user=<staff login>
 *   CHECK_CONTACT_IDS=3,1234 cv scr scripts/check-vc-portal.php --user=<staff login>
 *
 * Checks, exit 1 on any failure:
 *   1. Drift: every portal saved search and display (VcPortalScope::DECLARATION_FILES) equals its
 *      declaration — the same comparison VcPortalScopeSubscriber makes before a display runs.
 *   2. Declarations: each portal search has a scope placeholder in an AND position and selects no
 *      activity `details` (D37); no portal display has an editable column, add-row or drag-sort.
 *   3. Activity subjects (D37 review check): the portal timeline shows the subject of every
 *      activity on an in-scope case. Counts case activities whose subject holds a form-login or
 *      checksum link (`_aff=`, `_authx=`, `?cs=` / `&cs=`) — must be 0 — and, for information, any URL.
 *   4. Relationship cache (T32 gate): the scope searches read RelationshipCache; a stale row widens
 *      a set. Counts "Case Coordinator is" / "Employee of" cache rows that disagree with
 *      Relationship — must be 0. (VcScopeResolver also refuses a VC whose own cases disagree.)
 *   5. With CHECK_CONTACT_IDS: as each contact, every portal list returns exactly as many rows as
 *      that contact's scope set.
 */

use Civi\Api4\Activity;
use Civi\Api4\Relationship;
use Civi\Api4\RelationshipCache;
use Civi\Mascode\Event\VcPortalScopeSubscriber;
use Civi\Mascode\Mcp\Vc\Api4VcScopeSource;
use Civi\Mascode\Mcp\Vc\VcScopeResolver;
use Civi\Mascode\Security\VcPortalScope;

$failed = FALSE;
$report = function (string $label, bool $ok, string $detail = '') use (&$failed): void {
  $failed = $failed || !$ok;
  printf("  %-4s %s%s\n", $ok ? 'ok' : 'FAIL', $label, $detail === '' ? '' : " ($detail)");
};

// 1 + 2. Declarations and drift.
echo "1-2. Portal searches and displays\n";
$decl = VcPortalScopeSubscriber::declarations();
foreach ($decl['searches'] as $name => $search) {
  $p = $search['api_params'];
  $report("$name: scope placeholder", VcPortalScope::setsIn((array) $p['where'], (array) ($p['join'] ?? [])) !== []);
  // Only the base Case's own `details`; never `details` at the end of a joined or implicit path.
  $details = array_filter((array) $p['select'], fn($f) => preg_match('/\.details\b/', (string) $f)
    || ($search['api_entity'] !== 'Case' && preg_match('/(^|\W)details\b/', (string) $f)));
  $report("$name: no activity details (D37)", $details === []);
  foreach ($decl['displays'][$name] ?? [] as $display => $settings) {
    $json = json_encode($settings['settings']);
    $report("$name/$display: no edit, add-row or drag-sort", !str_contains($json, '"editable"')
      && !str_contains($json, '"editableRow"') && !str_contains($json, '"draggable"'));
    $problem = VcPortalScopeSubscriber::driftOf($name, $display, $decl);
    $report("$name/$display: matches declaration", $problem === NULL, (string) $problem);
  }
}

// 3. Activity subjects on cases.
echo "3. Case activity subjects (D37)\n";
$count = function (string $like): int {
  return Activity::get(FALSE)->selectRowCount()
    ->addJoin('CaseActivity AS ca', 'INNER', ['id', '=', 'ca.activity_id'])
    ->addWhere('is_deleted', '=', FALSE)
    ->addWhere('is_current_revision', '=', TRUE)
    ->addWhere('subject', 'LIKE', $like)
    ->execute()->count();
};
$cred = $count('%\_aff=%') + $count('%\_authx=%') + $count('%?cs=%') + $count('%&cs=%') + $count('%&amp;cs=%');
$report('subjects with a form-login or checksum link', $cred === 0, "$cred");
$urls = $count('%http://%') + $count('%https://%') + $count('%www.%');
printf("  info subjects with any other link: %d (shown unsanitised on the portal; not credentials)\n", $urls);

// 4. Relationship cache vs Relationship for the two scope types.
echo "4. Relationship cache (T32)\n";
$stale = 0;
$cached = RelationshipCache::get(FALSE)
  ->addSelect('relationship_id', 'near_contact_id', 'far_contact_id', 'case_id', 'is_active')
  ->addWhere('near_relation:name', 'IN', ['Case Coordinator is', 'Employee of'])
  ->addWhere('orientation', '=', 'a_b')
  ->execute();
$byRel = [];
foreach ($cached as $row) {
  $byRel[(int) $row['relationship_id']] = $row;
}
$live = [];
foreach (array_chunk(array_keys($byRel), 500) as $chunk) {
  foreach (Relationship::get(FALSE)->addSelect('id', 'contact_id_a', 'contact_id_b', 'case_id', 'is_active', 'relationship_type_id.name_a_b')
    ->addWhere('id', 'IN', $chunk)->execute() as $r) {
    $live[(int) $r['id']] = $r;
  }
}
foreach ($byRel as $id => $c) {
  $r = $live[$id] ?? NULL;
  if (!$r || (int) $r['contact_id_a'] !== (int) $c['near_contact_id'] || (int) $r['contact_id_b'] !== (int) $c['far_contact_id']
    || (int) ($r['case_id'] ?? 0) !== (int) ($c['case_id'] ?? 0) || (bool) $r['is_active'] !== (bool) $c['is_active']
    || !in_array($r['relationship_type_id.name_a_b'], ['Case Coordinator is', 'Employee of'], TRUE)) {
    $stale++;
  }
}
$report('stale cache rows for the scope types', $stale === 0, "$stale of " . count($byRel));

// 5. Per-contact list counts.
$ids = array_filter(array_map('intval', explode(',', (string) getenv('CHECK_CONTACT_IDS'))));
if ($ids) {
  echo "5. Lists per contact\n";
  $lists = [
    'own' => ['My_Cases', 'My_Cases_Table_1'],
    'pool' => ['Service_Requests_Send_for_Assignment', 'Service_Requests_Send_for_Assignment_Table_1'],
    'cases' => ['MAS_VC_Org_Cases', 'MAS_VC_Org_Cases_Table'],
  ];
  $session = CRM_Core_Session::singleton();
  $original = $session->get('userID');
  try {
    foreach ($ids as $n => $cid) {
      $session->set('userID', $cid);
      try {
        $scope = (new VcScopeResolver(new Api4VcScopeSource()))->resolve($cid);
      }
      catch (\Throwable $e) {
        $report(sprintf('contact #%d: scope', $n + 1), FALSE, 'refused: ' . $e->getMessage());
        continue;
      }
      $want = ['own' => count($scope->ownCases), 'pool' => count($scope->poolCases), 'cases' => count($scope->cases)];
      foreach ($lists as $set => [$s, $d]) {
        $got = civicrm_api4('SearchDisplay', 'run', ['savedSearch' => $s, 'display' => $d, 'return' => 'row_count', 'checkPermissions' => FALSE])->count();
        $report(sprintf('contact #%d: %s rows = %s set', $n + 1, $s, $set), $got === $want[$set], "$got vs {$want[$set]}");
      }
    }
  }
  finally {
    $session->set('userID', $original);
  }
}

exit($failed ? 1 : 0);
