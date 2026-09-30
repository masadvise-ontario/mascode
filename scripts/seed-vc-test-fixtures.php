<?php

/**
 * DEV ONLY. Seeds the synthetic VC test fixtures T9 asks for (mas-civicrm-mcp-server
 * docs/plans/vc-access-tickets.md T9), which the T10 adversarial suite runs against: a SECOND
 * test VC ("VC B") whose scope is disjoint from test.vc's, and the cases that exercise D23 and
 * D25. Unlike scripts/test-vc-scope-searches.php it COMMITS the data, so the MCP live suites can
 * sign in as VC B. Re-run it after every masdemo refresh from production (the refresh drops it).
 *
 *   cv scr scripts/seed-vc-test-fixtures.php --user=<staff login>
 *
 * What it creates (all names start "T9", every email is @example.invalid, no real data):
 *   VC B            Individual, sub-type MAS_Rep, VC_Status Test, plus a WordPress Subscriber login
 *                   t9.vc-b@example.invalid with a random password that is never printed or stored
 *                   (set one with `wp user update <login> --user_pass=…` when needed).
 *   Org B           organisation client of VC B's own case, with one employee (email + phone).
 *   own_org         Open case, client Org B, coordinated by VC B.
 *   own_internal    Open case, client the domain organisation, coordinated by VC B (D25: brings the
 *                   domain organisation, and so every internal case, into VC B's scope).
 *   pool_internal   internal case Sent for Assignment, coordinated by nobody.
 *   own_individual  Open case, individual client, coordinated by VC B (D23: returned, client visible).
 *                   "Case Coordinator is" needs an Organization on side B, so this case's coordinator
 *                   row points at a placeholder org (as test-vc-scope-searches.php does); the scope
 *                   derives orgs from case CLIENTS, and a check confirms the placeholder stays out.
 *   pool_individual individual-client case Sent for Assignment (D23: returned, client name only).
 *
 * Idempotent: contacts are keyed on external_identifier "T9-…", cases on a subject ending "T9 synthetic: …";
 * anything already present is reused, never duplicated. Then it runs the scope searches from this
 * checkout's DECLARATION as VC B and prints PASS/FAIL per rule, and the env lines for the live
 * suites. Prints record ids only, never contact data. Refuses where the environment is Production.
 * Creating a case can send mail; dev mail goes to MailHog. Exit 1 on any failure.
 */

if (\Civi::settings()->get('environment') === 'Production') {
  fwrite(STDERR, "Refusing: this script writes synthetic data; dev only.\n");
  exit(2);
}

use Civi\Api4\CiviCase;
use Civi\Api4\Contact;
use Civi\Api4\Relationship;

const T9_LOGIN = 't9.vc-b@example.invalid';

$creator = (int) CRM_Core_Session::getLoggedInContactID();
if ($creator <= 0 || !CRM_Core_Permission::check([['administer CiviCRM', 'edit all contacts']])) {
  fwrite(STDERR, "Run with --user=<staff login>.\n");
  exit(2);
}
$DOM = (int) \Civi\Api4\Domain::get(FALSE)->addSelect('contact_id')->execute()->first()['contact_id'];

/** Find a contact by its T9 key, or create it. */
$contact = function (string $key, array $values, ?string $email = NULL, ?string $phone = NULL): int {
  $found = Contact::get(FALSE)->addSelect('id')->addWhere('external_identifier', '=', "T9-$key")
    ->addWhere('is_deleted', '=', FALSE)->execute()->first();
  if ($found) {
    return (int) $found['id'];
  }
  $c = Contact::create(FALSE)->setValues($values + ['external_identifier' => "T9-$key"]);
  if ($email) {
    $c->addChain('email', \Civi\Api4\Email::create(FALSE)->setValues(['contact_id' => '$id', 'email' => $email, 'is_primary' => TRUE, 'location_type_id:name' => 'Work']));
  }
  if ($phone) {
    $c->addChain('phone', \Civi\Api4\Phone::create(FALSE)->setValues(['contact_id' => '$id', 'phone' => $phone, 'is_primary' => TRUE, 'location_type_id:name' => 'Work']));
  }
  return (int) $c->execute()->first()['id'];
};
$ind = fn(string $key, string $last, array $extra = [], ?string $email = NULL, ?string $phone = NULL) =>
  $contact($key, ['contact_type' => 'Individual', 'first_name' => 'T9', 'last_name' => $last] + $extra, $email, $phone);

/** Find a case by its T9 subject, or create it. */
$case = function (string $key, array $clients, string $status) use ($creator): int {
  $subject = "T9 synthetic: $key";
  // mascode prefixes every case subject with its reference ("R123: …"), so match the ending.
  $found = CiviCase::get(FALSE)->addSelect('id')->addWhere('subject', 'LIKE', "%$subject")->addWhere('is_deleted', '=', FALSE)->execute()->first();
  if ($found) {
    return (int) $found['id'];
  }
  return (int) CiviCase::create(FALSE)->setValues([
    'case_type_id:name' => 'service_request', 'status_id:name' => $status, 'subject' => $subject,
    'creator_id' => $creator, 'contact_id' => $clients,
  ])->execute()->first()['id'];
};

/** An active relationship, created only if an identical active one is missing. */
$rel = function (int $a, int $b, string $type, ?int $caseId = NULL): void {
  $get = Relationship::get(FALSE)->addWhere('contact_id_a', '=', $a)->addWhere('contact_id_b', '=', $b)
    ->addWhere('relationship_type_id:name', '=', $type)->addWhere('is_active', '=', TRUE);
  $caseId ? $get->addWhere('case_id', '=', $caseId) : $get->addWhere('case_id', 'IS NULL');
  if (!$get->execute()->count()) {
    Relationship::create(FALSE)->setValues(['contact_id_a' => $a, 'contact_id_b' => $b, 'relationship_type_id:name' => $type,
      'is_active' => TRUE] + ($caseId ? ['case_id' => $caseId] : []))->execute();
  }
};

// --- contacts
$VCB = $ind('VC-B', 'Test VC B', ['contact_sub_type' => ['MAS_Rep'], 'MAS_Rep.VC_Status' => 'Test'], T9_LOGIN);
$ORG = $contact('ORG-B', ['contact_type' => 'Organization', 'organization_name' => 'T9 Synthetic Org B'], 't9.org-b@example.invalid', '555-0100');
$EMP = $ind('ORG-B-EMP', 'Org B Employee', [], 't9.org-b-employee@example.invalid', '555-0101');
$OWNI = $ind('OWN-CLIENT', 'Own Individual Client', [], 't9.own-client@example.invalid', '555-0102');
$POOLI = $ind('POOL-CLIENT', 'Pool Individual Client', [], 't9.pool-client@example.invalid', '555-0103');
$PH = $contact('PLACEHOLDER-ORG', ['contact_type' => 'Organization', 'organization_name' => 'T9 Coordinator Placeholder Org']);
$rel($EMP, $ORG, 'Employee of');

// --- WordPress login for VC B, linked to VC B's contact (never a stray contact the sync made)
$user = get_user_by('login', T9_LOGIN);
if (!$user) {
  $uid = wp_insert_user(['user_login' => T9_LOGIN, 'user_email' => T9_LOGIN, 'user_pass' => wp_generate_password(32), 'role' => 'subscriber',
    'first_name' => 'T9', 'last_name' => 'Test VC B']);
  if (is_wp_error($uid)) {
    fwrite(STDERR, 'WordPress user not created: ' . $uid->get_error_message() . "\n");
    exit(1);
  }
  $user = get_user_by('id', $uid);
}
$match = \Civi\Api4\UFMatch::get(FALSE)->addWhere('uf_id', '=', $user->ID)->execute()->first();
if ($match && (int) $match['contact_id'] !== $VCB) {
  $stray = (int) $match['contact_id'];
  \Civi\Api4\UFMatch::update(FALSE)->addWhere('id', '=', $match['id'])->addValue('contact_id', $VCB)->execute();
  // The CMS sync made its own contact for the new login: remove it only if it is ours to remove.
  $s = Contact::get(FALSE)->addSelect('external_identifier', 'created_date')->addWhere('id', '=', $stray)->execute()->first();
  $emails = array_column(\Civi\Api4\Email::get(FALSE)->addSelect('email')->addWhere('contact_id', '=', $stray)->execute()->getArrayCopy(), 'email');
  if ($s && empty($s['external_identifier']) && $emails === [T9_LOGIN]) {
    Contact::delete(FALSE)->addWhere('id', '=', $stray)->setUseTrash(FALSE)->execute();
  }
}
elseif (!$match) {
  \Civi\Api4\UFMatch::create(FALSE)->setValues(['uf_id' => $user->ID, 'uf_name' => T9_LOGIN, 'contact_id' => $VCB])->execute();
}

// --- cases
$C = [
  'own_org' => $case('own_org', [$ORG], 'Open'),
  'own_internal' => $case('own_internal', [$DOM], 'Open'),
  'pool_internal' => $case('pool_internal', [$DOM], 'Sent for Assignment'),
  'own_individual' => $case('own_individual', [$OWNI], 'Open'),
  'pool_individual' => $case('pool_individual', [$POOLI], 'Sent for Assignment'),
];
$rel($VCB, $ORG, 'Case Coordinator is', $C['own_org']);
$rel($VCB, $DOM, 'Case Coordinator is', $C['own_internal']);
$rel($VCB, $PH, 'Case Coordinator is', $C['own_individual']);

// --- check VC B's scope from the declared searches
$decls = [];
foreach (include __DIR__ . '/../Civi/Mascode/Managed/SavedSearch_MAS_VC_Scope_Sets.mgd.php' as $d) {
  $decls[substr($d['params']['values']['name'], 13)] = $d['params']['values'];
}
$original = CRM_Core_Session::singleton()->get('userID');
$s = [];
try {
  CRM_Core_Session::singleton()->set('userID', $VCB);
  foreach ($decls as $k => $v) {
    $p = $v['api_params'];
    $p['checkPermissions'] = FALSE;
    $s[$k] = array_map('intval', array_column(civicrm_api4($v['api_entity'], 'get', $p)->getArrayCopy(), 'id'));
  }
}
finally {
  CRM_Core_Session::singleton()->set('userID', $original);
}
$fail = 0;
$check = function (string $label, bool $ok) use (&$fail) {
  $fail += $ok ? 0 : 1;
  printf("  %-72s %s\n", $label, $ok ? 'PASS' : 'FAIL');
};
$testVc = (int) (getenv('CHECK_VC_ID') ?: 3);
$testVcOwn = array_map('intval', array_column(Relationship::get(FALSE)->addSelect('case_id')->addWhere('contact_id_a', '=', $testVc)
  ->addWhere('relationship_type_id:name', '=', 'Case Coordinator is')->addWhere('is_active', '=', TRUE)->execute()->getArrayCopy(), 'case_id'));
$testVcClients = array_map('intval', array_column(\Civi\Api4\CaseContact::get(FALSE)->addSelect('contact_id')->addWhere('case_id', 'IN', $testVcOwn ?: [0])->execute()->getArrayCopy(), 'contact_id'));
$vcbDomainEmp = in_array($VCB, array_map('intval', array_column(Relationship::get(FALSE)->addSelect('contact_id_a')->addWhere('contact_id_b', '=', $DOM)->addWhere('relationship_type_id:name', '=', 'Employee of')->addWhere('is_active', '=', TRUE)->execute()->getArrayCopy(), 'contact_id_a')), TRUE);

echo "VC B scope (declared searches):\n";
$own = $s['Own_Cases'];
$want = [$C['own_org'], $C['own_internal'], $C['own_individual']];
sort($own);
sort($want);
$check('own cases are exactly the three T9 own cases', $own === $want);
$check('both T9 pool cases in Pool_Cases', !array_diff([$C['pool_internal'], $C['pool_individual']], $s['Pool_Cases']));
$check('Org B and (D25, own internal case) the domain org in Orgs', in_array($ORG, $s['Orgs'], TRUE) && in_array($DOM, $s['Orgs'], TRUE));
$check('placeholder org (coordinator side B only) NOT in Orgs', !in_array($PH, $s['Orgs'], TRUE));
$check('D23 own individual-client case in Cases', in_array($C['own_individual'], $s['Cases'], TRUE));
$check('D23 pool individual-client case in Cases', in_array($C['pool_individual'], $s['Cases'], TRUE));
$check('Org B employee in Employees', in_array($EMP, $s['Employees'], TRUE));
$check('D25 VC B is not an employee of the domain org (fixture sanity)', !$vcbDomainEmp);
$check('disjoint: no test.vc own case in VC B own cases', !array_intersect($testVcOwn, $s['Own_Cases']));
$check('disjoint: Org B is not a client of any test.vc own case', !in_array($ORG, $testVcClients, TRUE));

echo "\nids: VC B $VCB (uid {$user->ID}), Org B $ORG, employee $EMP, own client $OWNI, pool client $POOLI, placeholder org $PH\n";
echo 'cases: ' . json_encode($C) . "\n";
echo "live suites: MCP_LIVE_VC_USER=" . T9_LOGIN . "  (VC B)\n";
echo $fail ? "FAILURES: $fail\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
