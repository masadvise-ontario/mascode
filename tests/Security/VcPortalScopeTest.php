<?php

/**
 * VC portal on the MCP's access rules (ticket T12; VC access spec D2, D13, D14, D15, D22, D37) —
 * live adversarial test, run as the T9 synthetic VC B.
 *
 * RUN (masdemo, after scripts/seed-vc-test-fixtures.php; dev only — it refuses on Production):
 *   cd /home/brian/buildkit/build/masdemo/web/wp-content/uploads/civicrm/ext/mascode
 *   cv scr tests/Security/VcPortalScopeTest.php --user=brian.flett@masadvise.org
 * Exit 0 = all pass. Prints record ids and counts only, never contact data.
 *
 * WHAT IT CHECKS, each by running the portal's SearchDisplays exactly as the pages do:
 *   1. Every portal list returns exactly the resolver's set for the VC (own / pool / cases), and
 *      the case-detail header returns a case iff it is in S_cases — including an organisation's
 *      case the VC does not coordinate (D2), never a case outside it, whatever the filter.
 *   2. Every case-detail section and card is empty for a case outside S_cases.
 *   3. Roles and Client show contact details only for S_contacts (D14): another VC on the case,
 *      added inside a rolled-back transaction, shows by name with no phone or email; the client's
 *      employee and the VC themself keep theirs; a pooled internal request's client (the domain
 *      organisation, D25) shows by name only to a VC who never coordinated an internal case.
 *   4. Client feedback (D22): of the nine fb_* share answers, only the exact "Yes" shows the card.
 *   5. Drift: an edited portal search and an undeclared display are refused, not run (rolled back).
 *   6. Fail closed: with no signed-in contact every list is empty.
 *   7. Static rules over every portal declaration: no activity `details` selected (D37); no
 *      editable column, add-row or drag-sort on an acl_bypass display (T11); every search carries
 *      a scope placeholder in an AND position.
 *
 * Caveat: the transactions create relationships and edit a saved search, then roll back; creating
 * a relationship can fire CiviRules. Dev mail goes to MailHog.
 */

use Civi\Api4\CiviCase;
use Civi\Api4\Contact;
use Civi\Mascode\Event\VcPortalScopeSubscriber;
use Civi\Mascode\Mcp\Vc\Api4VcScopeSource;
use Civi\Mascode\Mcp\Vc\VcScopeResolver;
use Civi\Mascode\Security\VcPortalScope;

if (\Civi::settings()->get('environment') === 'Production') {
  fwrite(STDERR, "Refusing: dev only (rolled-back writes).\n");
  exit(2);
}

class P {
  public static array $failures = [];
  public static int $passes = 0;
}

function check(string $name, bool $ok, string $why = ''): void {
  if ($ok) {
    P::$passes++;
    echo "  pass: $name\n";
  }
  else {
    P::$failures[] = $name . ($why ? " — $why" : '');
    echo "  FAIL: $name" . ($why ? " — $why" : '') . "\n";
  }
}

function asContact(int $cid): void {
  \CRM_Core_Session::singleton()->set('userID', $cid ?: NULL);
}

/** Rows of a portal display as the current session contact, as the page runs it. */
function rows(string $search, string $display, array $filters = []): array {
  return (array) civicrm_api4('SearchDisplay', 'run', [
    'savedSearch' => $search, 'display' => $display, 'filters' => $filters, 'checkPermissions' => FALSE,
  ])->getArrayCopy();
}

/** The case ids a display returned (its `id` key). */
function caseIds(array $rows): array {
  $ids = array_map(fn($r) => (int) ($r['key'] ?? $r['data']['id'] ?? 0), $rows);
  sort($ids);
  return array_values(array_unique($ids));
}

function scopeOf(int $cid) {
  return (new VcScopeResolver(new Api4VcScopeSource()))->resolve($cid);
}

function t9(string $key): int {
  return (int) Contact::get(FALSE)->addSelect('id')->addWhere('external_identifier', '=', "T9-$key")->execute()->first()['id'];
}

function t9case(string $key): int {
  $subject = '%T9 synthetic: ' . str_replace('_', '\\_', $key);
  return (int) (CiviCase::get(FALSE)->addSelect('id')->addWhere('subject', 'LIKE', $subject)
    ->addWhere('is_deleted', '=', FALSE)->execute()->first()['id'] ?? 0);
}

/** Run $fn inside a transaction that is always rolled back. */
function rolledBack(callable $fn): void {
  $tx = new \CRM_Core_Transaction();
  try {
    $fn();
  }
  finally {
    $tx->rollback();
    $tx->commit();
  }
}

$VCB = t9('VC-B');
$VCC = t9('VC-C');
$EMP = t9('ORG-B-EMP');
$TESTVC = (int) (getenv('CHECK_VC_ID') ?: 3);
$C = [];
foreach (['own_org', 'own_internal', 'pool_internal', 'fb_yes', 'deactivated_coord'] as $k) {
  $C[$k] = t9case($k);
}
foreach (['VC B' => $VCB, 'VC C' => $VCC, 'Org B employee' => $EMP] + $C as $label => $id) {
  if (!$id) {
    fwrite(STDERR, "Missing fixture $label — run scripts/seed-vc-test-fixtures.php first.\n");
    exit(1);
  }
}
$original = \CRM_Core_Session::singleton()->get('userID');

$LISTS = [
  'own' => ['My_Cases', 'My_Cases_Table_1'],
  'pool' => ['Service_Requests_Send_for_Assignment', 'Service_Requests_Send_for_Assignment_Table_1'],
  'cases' => ['MAS_VC_Org_Cases', 'MAS_VC_Org_Cases_Table'],
];
$DETAIL = [
  ['Case_Details_VC', 'Case_Details_VC_Table_1'],
  ['Case_Details_VC_Activities', 'Case_Details_VC_Activities_Table_1'],
  ['Case_Details_VC_Roles', 'Case_Details_VC_Roles_Table_1'],
  ['Case_Details_VC_Client', 'Case_Details_VC_Client_Table_1'],
  ['Case_Details_VC_SR_Fields', 'Case_Details_VC_SR_Fields_Card_1'],
  ['Case_Details_VC_Proj', 'Case_Details_VC_Proj_Card_1'],
  ['Case_Details_VC_ProjDef', 'Case_Details_VC_ProjDef_Card_1'],
  ['Case_Details_VC_ProjAuth', 'Case_Details_VC_ProjAuth_Card_1'],
  ['Case_Details_VC_ProjCloseVC', 'Case_Details_VC_ProjCloseVC_Card_1'],
  ['Case_Details_VC_ProjCloseClient', 'Case_Details_VC_ProjCloseClient_Card_1'],
];

try {
  // ------------------------------------------------------------------ 1. lists = the resolver's sets
  echo "1. Lists and header follow the scope sets\n";
  foreach ([$VCB => 'VC B', $TESTVC => 'test VC'] as $cid => $who) {
    asContact($cid);
    $scope = scopeOf($cid);
    $want = ['own' => $scope->ownCases, 'pool' => $scope->poolCases, 'cases' => $scope->cases];
    foreach ($LISTS as $set => [$s, $d]) {
      $got = caseIds(rows($s, $d));
      // The lists page at 50; compare what came back against the set, and the count separately.
      $count = (int) civicrm_api4('SearchDisplay', 'run', ['savedSearch' => $s, 'display' => $d, 'return' => 'row_count', 'checkPermissions' => FALSE])->count();
      check("$who: $s returns only $set", array_diff($got, $want[$set]) === [], count(array_diff($got, $want[$set])) . ' row(s) outside the set');
      check("$who: $s count equals $set", $count === count($want[$set]), "$count vs " . count($want[$set]));
    }
  }

  asContact($VCB);
  $scopeB = scopeOf($VCB);
  $orgCase = $C['fb_yes'];
  check('fixture: fb_yes is an organisation case VC B does not coordinate',
    in_array($orgCase, $scopeB->cases, TRUE) && !in_array($orgCase, $scopeB->ownCases, TRUE) && !in_array($orgCase, $scopeB->poolCases, TRUE));
  check('D2: VC B opens an organisation case they do not coordinate',
    count(rows('Case_Details_VC', 'Case_Details_VC_Table_1', ['id' => $orgCase])) === 1);
  $outside = (int) (CiviCase::get(FALSE)->addSelect('id')->addWhere('id', 'NOT IN', $scopeB->cases)
    ->addWhere('is_deleted', '=', FALSE)->setLimit(1)->execute()->first()['id'] ?? 0);
  check('fixture: a case outside VC B scope exists', $outside > 0);
  check('deactivated coordination stays out (D5)', !in_array($C['deactivated_coord'], $scopeB->cases, TRUE));

  asContact($TESTVC);
  $scopeT = scopeOf($TESTVC);
  if (!in_array($orgCase, $scopeT->cases, TRUE)) {
    check('per-VC: test VC cannot open VC B\'s organisation case',
      count(rows('Case_Details_VC', 'Case_Details_VC_Table_1', ['id' => $orgCase])) === 0);
  }

  // ------------------------------------------------------------------ 2. forged ids and filters
  echo "2. Forged ids and filters\n";
  asContact($VCB);
  foreach ([$outside, $C['deactivated_coord']] as $forged) {
    foreach ($DETAIL as [$s, $d]) {
      $n = count(rows($s, $d, ['id' => $forged]));
      check("forged case: $s empty", $n === 0, "$n row(s) — DATA LEAK");
    }
  }
  [$s, $d] = $LISTS['cases'];
  $n = count(rows($s, $d, ['id' => ['NOT IN' => [VcPortalScope::SENTINELS['cases']]]]));
  check('a NOT IN filter on the placeholder value widens nothing', $n <= min(50, count($scopeB->cases)));
  $n = count(rows($s, $d, ['id' => ['IN' => [$outside, VcPortalScope::SENTINELS['cases']]]]));
  check('an IN filter naming an outside case returns nothing', $n === 0, "$n row(s)");

  // ------------------------------------------------------------------ 3. contact details (D14, D15)
  echo "3. Roles and Client contact details\n";
  rolledBack(function () use ($VCB, $VCC, $EMP, $C) {
    $org = t9('ORG-B');
    \Civi\Api4\Phone::create(FALSE)->setValues(['contact_id' => $VCC, 'phone' => '555-0199', 'is_primary' => TRUE, 'location_type_id:name' => 'Work'])->execute();
    \Civi\Api4\Email::create(FALSE)->setValues(['contact_id' => $VCC, 'email' => 't9.vc-c@example.invalid', 'is_primary' => TRUE, 'location_type_id:name' => 'Work'])->execute();
    foreach ([[$VCC, 'Case Coordinator is'], [$EMP, 'Case Client Rep is']] as [$a, $type]) {
      \Civi\Api4\Relationship::create(FALSE)->setValues([
        'contact_id_a' => $a, 'contact_id_b' => $org, 'case_id' => $C['own_org'], 'is_active' => TRUE,
        'relationship_type_id:name' => $type,
      ])->execute();
    }
    asContact($VCB);
    $byContact = [];
    foreach (rows('Case_Details_VC_Roles', 'Case_Details_VC_Roles_Table_1', ['id' => $C['own_org']]) as $row) {
      $rid = (int) $row['data']['Case_Relationship_case_id_01.id'];
      $a = (int) \Civi\Api4\Relationship::get(FALSE)->addSelect('contact_id_a')->addWhere('id', '=', $rid)->execute()->first()['contact_id_a'];
      $byContact[$a] = $row['data'];
    }
    $vcc = $byContact[$VCC] ?? NULL;
    check('another VC on the case is listed by name', $vcc !== NULL && !empty($vcc['Case_Relationship_case_id_01.contact_id_a.sort_name']));
    check('another VC: no phone, no email (D14)', $vcc !== NULL && empty($vcc['Roles_shown.phone_primary.phone']) && empty($vcc['Roles_shown.email_primary.email']));
    check('the client\'s employee keeps their phone and email', !empty($byContact[$EMP]['Roles_shown.phone_primary.phone']) && !empty($byContact[$EMP]['Roles_shown.email_primary.email']));
    check('the VC keeps their own email (D29b)', !empty($byContact[$VCB]['Roles_shown.email_primary.email']));
  });

  asContact($VCB);
  $client = rows('Case_Details_VC_Client', 'Case_Details_VC_Client_Table_1', ['id' => $C['own_org']]);
  check('Client: an in-scope organisation shows its phone', count($client) === 1 && !empty($client[0]['data']['Client_shown.phone_primary.phone']));
  asContact($TESTVC);
  $scopeT = scopeOf($TESTVC);
  $dom = (int) \Civi\Api4\Domain::get(FALSE)->addSelect('contact_id')->execute()->first()['contact_id'];
  if (!in_array($dom, $scopeT->orgs, TRUE)) {
    $client = rows('Case_Details_VC_Client', 'Case_Details_VC_Client_Table_1', ['id' => $C['pool_internal']]);
    $d0 = $client[0]['data'] ?? [];
    check('D25: a pooled internal request\'s client shows by name only', count($client) === 1
      && empty($d0['Client_shown.phone_primary.phone']) && empty($d0['Client_shown.email_primary.email'])
      && empty($d0['Client_shown.address_primary.street_address']) && empty($d0['Case_Client_Website_01.url']));
  }
  else {
    echo "  (test VC coordinates an internal case — D25 name-only check skipped)\n";
  }

  // ------------------------------------------------------------------ 4. client feedback (D22)
  echo "4. Client feedback share answers\n";
  asContact($VCB);
  foreach (['fb_yes' => 1, 'fb_no' => 0, 'fb_blank' => 0, 'fb_empty' => 0, 'fb_yes_lower' => 0,
    'fb_yes_space' => 0, 'fb_yes_upper' => 0, 'fb_handtyped' => 0, 'fb_yes_to_no' => 0] as $key => $want) {
    $id = t9case($key);
    if (!$id) {
      check("fixture $key", FALSE, 'missing — re-run the seeder');
      continue;
    }
    $n = count(rows('Case_Details_VC_ProjCloseClient', 'Case_Details_VC_ProjCloseClient_Card_1', ['id' => $id]));
    check("$key: feedback card " . ($want ? 'shown' : 'hidden'), $n === $want, "$n row(s)");
  }
  if (interface_exists('Civi\Mcp\Policy\ScopePolicy') && class_exists('Civi\Mascode\Mcp\Vc\VcScopePolicy')) {
    check('portal and MCP share one Yes constant', VcPortalScopeSubscriber::SHARE_YES === \Civi\Mascode\Mcp\Vc\VcScopePolicy::SHARE_YES
      && VcPortalScopeSubscriber::SHARE_FIELD === \Civi\Mascode\Mcp\Vc\VcScopePolicy::SHARE_FIELD);
  }

  // ------------------------------------------------------------------ 5. drift is refused
  echo "5. Drift\n";
  asContact($VCB);
  rolledBack(function () use ($LISTS) {
    [$s, $d] = $LISTS['cases'];
    $params = \Civi\Api4\SavedSearch::get(FALSE)->addSelect('api_params')->addWhere('name', '=', $s)->execute()->first()['api_params'];
    $params['where'] = [];
    \Civi\Api4\SavedSearch::update(FALSE)->addWhere('name', '=', $s)->addValue('api_params', $params)->execute();
    try {
      rows($s, $d);
      check('an edited portal search is refused', FALSE, 'it ran');
    }
    catch (\Civi\API\Exception\UnauthorizedException $e) {
      check('an edited portal search is refused', TRUE);
    }
  });
  rolledBack(function () {
    \Civi\Api4\SearchDisplay::create(FALSE)->setValues([
      'name' => 'T12_Rogue', 'label' => 'T12 rogue', 'saved_search_id.name' => 'My_Cases', 'type' => 'table',
      'acl_bypass' => TRUE, 'settings' => ['columns' => [['type' => 'field', 'key' => 'id']]],
    ])->execute();
    try {
      rows('My_Cases', 'T12_Rogue');
      check('an undeclared display of a portal search is refused', FALSE, 'it ran');
    }
    catch (\Civi\API\Exception\UnauthorizedException $e) {
      check('an undeclared display of a portal search is refused', TRUE);
    }
  });
  [$s, $d] = $LISTS['cases'];
  check('after rollback the list runs again', count(rows($s, $d)) > 0);

  // ------------------------------------------------------------------ 6. no contact
  echo "6. Fail closed\n";
  asContact(0);
  foreach ($LISTS as $set => [$s, $d]) {
    $n = count(rows($s, $d));
    check("no signed-in contact: $s empty", $n === 0, "$n row(s)");
  }

  // ------------------------------------------------------------------ 7. static rules
  echo "7. Declarations\n";
  $decl = VcPortalScopeSubscriber::declarations();
  foreach ($decl['searches'] as $name => $search) {
    $p = $search['api_params'];
    $activityAliases = [];
    foreach ((array) ($p['join'] ?? []) as $j) {
      if (preg_match('/^Activity AS (\w+)/', (string) ($j[0] ?? ''), $m)) {
        $activityAliases[] = $m[1];
      }
    }
    $bad = array_filter((array) $p['select'], function ($f) use ($search, $activityAliases) {
      foreach ($activityAliases as $a) {
        if (str_contains((string) $f, "$a.details")) {
          return TRUE;
        }
      }
      return $search['api_entity'] === 'Activity' && preg_match('/(^|\W)details\b/', (string) $f);
    });
    check("$name selects no activity details (D37)", $bad === []);
    check("$name carries a scope placeholder", VcPortalScope::setsIn((array) $p['where'], (array) ($p['join'] ?? [])) !== []);
  }
  foreach ($decl['displays'] as $search => $displays) {
    foreach ($displays as $name => $display) {
      $json = json_encode($display['settings']);
      check("$search/$name: no editable column, add-row or drag-sort (T11)", !str_contains($json, '"editable"')
        && !str_contains($json, '"editableRow"') && !str_contains($json, '"draggable"'));
    }
  }
}
catch (\Throwable $e) {
  check('test run', FALSE, get_class($e) . ': ' . $e->getMessage());
}
finally {
  \CRM_Core_Session::singleton()->set('userID', $original);
}

echo "\n";
if (P::$failures) {
  echo 'RESULT: RED — ' . count(P::$failures) . ' failure(s), ' . P::$passes . " pass(es)\n";
  foreach (P::$failures as $f) {
    echo "  - $f\n";
  }
  exit(1);
}
echo 'RESULT: GREEN — all ' . P::$passes . " assertions passed\n";
exit(0);
