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
 *   VC C            (T10, D25) Individual, sub-type MAS_Rep, VC_Status Test, NO login and no own case:
 *                   the VC for "a pooled internal case adds only itself" (test.vc coordinates an
 *                   internal case on masdemo, so the domain organisation is in its scope).
 *   Org B           organisation client of VC B's own case, with one employee (email + phone).
 *   own_org         Open case, client Org B, coordinated by VC B.
 *   own_internal    Open case, client the domain organisation, coordinated by VC B (D25: brings the
 *                   domain organisation, and so every internal case, into VC B's scope).
 *   pool_internal   internal case Sent for Assignment, coordinated by nobody.
 *   own_individual  Open case, individual client, coordinated by VC B (D23: in scope; T10 checks the
 *                   client is visible).
 *                   "Case Coordinator is" needs an Organization on side B, so this case's coordinator
 *                   row points at a placeholder org (as test-vc-scope-searches.php does); the scope
 *                   derives orgs from case CLIENTS, and a check confirms the placeholder stays out.
 *   pool_individual individual-client case Sent for Assignment (D23: in scope; T10 checks the client
 *                   is a name only). Pool cases are shared by EVERY VC, test.vc included.
 *   ended_coord     (T10, D5) Closed case, client Org C, whose coordinator row for VC B is ENDED
 *                   (end_date in the past) but still active: past coordination counts, so the case and
 *                   Org C are in VC B's scope.
 *   deactivated_coord (T10, D5) Closed case, client Org D, whose coordinator row for VC B is
 *                   DEACTIVATED (is_active 0, a corrected mis-assignment): out of scope, Org D too.
 *                   Also carries a "Case Client Rep is" role between Org B's employee and Org B —
 *                   two contacts VC B sees — which must stay hidden (D9, D23).
 *   fb_*            (T10, D22) Completed project cases, client Org B (so in VC B's scope), each with
 *                   client-feedback answers and one share answer as stored: Yes (the control), No,
 *                   none, empty, "yes", "Yes " (trailing space), "YES", "Y" (typed by hand), and
 *                   Yes changed to No. Only the first may be returned.
 *   T10 activities  on fb_no (no consent): the close form's own activity carrying the answers, an
 *                   Email copy of it, an Email copy of a Project Definition (a type a legacy custom
 *                   group extends, S6), and an Email copy of a Follow up (the control, returned).
 * The pool individual client also gets a synthetic address (D23: withheld with the email and phone).
 *
 * Idempotent: contacts are keyed on external_identifier "T9-…", cases on a subject ending "T9 synthetic: …";
 * anything already present is reused, never duplicated. Then it runs the scope searches from this
 * checkout's DECLARATION as VC B and as test.vc (CHECK_VC_ID, default 3), prints PASS/FAIL per
 * rule, and the env line for the live suites. Prints record ids only, never contact data. Refuses where the environment is Production.
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
  // external_identifier is unique, so a trashed T9 contact is restored rather than re-created.
  $found = Contact::get(FALSE)->addSelect('id', 'is_deleted')->addWhere('external_identifier', '=', "T9-$key")
    ->addWhere('is_deleted', 'IN', [TRUE, FALSE])->execute()->first();
  if ($found) {
    if ($found['is_deleted']) {
      Contact::update(FALSE)->addWhere('id', '=', $found['id'])->addValue('is_deleted', FALSE)->execute();
    }
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
$case = function (string $key, array $clients, string $status, string $type = 'service_request') use ($creator): int {
  $subject = "T9 synthetic: $key";
  // mascode prefixes every case subject with its reference ("R123: …"), so match the ending
  // ("_" escaped: it is a LIKE wildcard).
  $found = CiviCase::get(FALSE)->addSelect('id', 'status_id:name')->addWhere('subject', 'LIKE', '%' . str_replace('_', '\\_', $subject))->addWhere('is_deleted', '=', FALSE)->execute()->first();
  if ($found) {
    // The status is part of the fixture (pool = Sent for Assignment), so a hand edit is undone.
    if ($found['status_id:name'] !== $status) {
      CiviCase::update(FALSE)->addWhere('id', '=', $found['id'])->addValue('status_id:name', $status)->execute();
    }
    return (int) $found['id'];
  }
  return (int) CiviCase::create(FALSE)->setValues([
    'case_type_id:name' => $type, 'status_id:name' => $status, 'subject' => $subject,
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

/**
 * D5: a coordinator row in a fixed state — ended but active, or deactivated — set on every run, so
 * a hand edit or a refresh cannot leave the fixture proving the wrong thing.
 */
$coordState = function (int $a, int $b, int $caseId, bool $active, ?string $end): void {
  $values = ['is_active' => $active, 'start_date' => '2024-10-01', 'end_date' => $end];
  $found = Relationship::get(FALSE)->addSelect('id')->addWhere('contact_id_a', '=', $a)->addWhere('contact_id_b', '=', $b)
    ->addWhere('relationship_type_id:name', '=', 'Case Coordinator is')->addWhere('case_id', '=', $caseId)->execute()->first();
  if ($found) {
    Relationship::update(FALSE)->addWhere('id', '=', $found['id'])->setValues($values)->execute();
  }
  else {
    Relationship::create(FALSE)->setValues(['contact_id_a' => $a, 'contact_id_b' => $b, 'relationship_type_id:name' => 'Case Coordinator is',
      'case_id' => $caseId] + $values)->execute();
  }
};

// --- contacts
$VCB = $ind('VC-B', 'Test VC B', ['contact_sub_type' => ['MAS_Rep'], 'MAS_Rep.VC_Status' => 'Test'], T9_LOGIN);
$VCC = $ind('VC-C', 'Test VC C', ['contact_sub_type' => ['MAS_Rep'], 'MAS_Rep.VC_Status' => 'Test']);
$ORG = $contact('ORG-B', ['contact_type' => 'Organization', 'organization_name' => 'T9 Synthetic Org B'], 't9.org-b@example.invalid', '555-0100');
$EMP = $ind('ORG-B-EMP', 'Org B Employee', [], 't9.org-b-employee@example.invalid', '555-0101');
$OWNI = $ind('OWN-CLIENT', 'Own Individual Client', [], 't9.own-client@example.invalid', '555-0102');
$POOLI = $ind('POOL-CLIENT', 'Pool Individual Client', [], 't9.pool-client@example.invalid', '555-0103');
$ORGC = $contact('ORG-C', ['contact_type' => 'Organization', 'organization_name' => 'T9 Synthetic Org C (ended coordination)']);
$ORGD = $contact('ORG-D', ['contact_type' => 'Organization', 'organization_name' => 'T9 Synthetic Org D (deactivated coordination)']);
$PH = $contact('PLACEHOLDER-ORG', ['contact_type' => 'Organization', 'organization_name' => 'T9 Coordinator Placeholder Org']);
$rel($EMP, $ORG, 'Employee of');
if (!\Civi\Api4\Address::get(FALSE)->addWhere('contact_id', '=', $POOLI)->execute()->count()) {
  \Civi\Api4\Address::create(FALSE)->setValues(['contact_id' => $POOLI, 'street_address' => '1 T9 Synthetic Street', 'city' => 'Testville',
    'location_type_id:name' => 'Home', 'is_primary' => TRUE])->execute();
}

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
elseif (!in_array('subscriber', (array) $user->roles, TRUE) || count((array) $user->roles) !== 1) {
  $user->set_role('subscriber');
}
$match = \Civi\Api4\UFMatch::get(FALSE)->addWhere('uf_id', '=', $user->ID)->execute()->first();
if ($match && (int) $match['contact_id'] !== $VCB) {
  // Re-link only the synthetic login's own row: after a partial refresh this WordPress user id
  // could belong to a real person's link, which must never be moved.
  if (($match['uf_name'] ?? '') !== T9_LOGIN) {
    fwrite(STDERR, "Refusing: UFMatch for WordPress user {$user->ID} is not the T9 login's.\n");
    exit(1);
  }
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
  'ended_coord' => $case('ended_coord', [$ORGC], 'Closed'),
  'deactivated_coord' => $case('deactivated_coord', [$ORGD], 'Closed'),
];
$rel($VCB, $ORG, 'Case Coordinator is', $C['own_org']);
$rel($VCB, $DOM, 'Case Coordinator is', $C['own_internal']);
$rel($VCB, $PH, 'Case Coordinator is', $C['own_individual']);
$coordState($VCB, $ORGC, $C['ended_coord'], TRUE, '2025-01-31');
$coordState($VCB, $ORGD, $C['deactivated_coord'], FALSE, NULL);
$rel($EMP, $ORG, 'Case Client Rep is', $C['deactivated_coord']);

// --- T10 (D22): one feedback case per share answer, set on every run
const T10_SHARE = [
  'fb_yes' => 'Yes', 'fb_no' => 'No', 'fb_blank' => NULL, 'fb_empty' => '', 'fb_yes_lower' => 'yes',
  'fb_yes_space' => 'Yes ', 'fb_yes_upper' => 'YES', 'fb_handtyped' => 'Y', 'fb_yes_to_no' => 'No',
];
foreach (T10_SHARE as $key => $share) {
  $C[$key] = $case($key, [$ORG], 'Completed', 'project');
  $answers = [
    'Project_Close_Client.satisfaction' => 'satisfied',
    'Project_Close_Client.would_use_mas_again' => 'yes',
    'Project_Close_Client.would_work_with_vc_again' => 'yes',
    'Project_Close_Client.would_recommend_mas' => 'yes',
    'Project_Close_Client.satisfaction_comment' => "T10 synthetic answer $key: the planning sessions clarified our board priorities",
    'Project_Close_Client.reuse_comment' => "T10 synthetic answer $key: we would ask again for help with fundraising",
    'Project_Close_Client.benefits_realized' => "T10 synthetic answer $key: a written three year plan adopted",
    'Project_Close_Client.use_in_marketing' => 'No',
  ];
  if ($key === 'fb_yes_to_no') {
    CiviCase::update(FALSE)->addWhere('id', '=', $C[$key])->setValues($answers + ['Project_Close_Client.share_with_vc' => 'Yes'])->execute();
  }
  CiviCase::update(FALSE)->addWhere('id', '=', $C[$key])->setValues($answers + ['Project_Close_Client.share_with_vc' => $share])->execute();
}

// --- T10 (S4, S6): activities on the no-consent case, found by subject, set on every run
$act = function (string $key, string $type, string $details, ?int $source = NULL) use ($creator, $C): int {
  $subject = "T10 synthetic: $key";
  $values = ['activity_type_id:name' => $type, 'details' => $details, 'source_record_id' => $source, 'status_id:name' => 'Completed'];
  $found = \Civi\Api4\Activity::get(FALSE)->addSelect('id')->addWhere('subject', '=', $subject)->addWhere('is_deleted', '=', FALSE)->execute()->first();
  if ($found) {
    \Civi\Api4\Activity::update(FALSE)->addWhere('id', '=', $found['id'])->setValues($values)->execute();
    return (int) $found['id'];
  }
  return (int) \Civi\Api4\Activity::create(FALSE)->setValues($values + ['subject' => $subject, 'source_contact_id' => $creator, 'case_id' => $C['fb_no']])->execute()->first()['id'];
};
$fbNo = CiviCase::get(FALSE)->addSelect('Project_Close_Client.satisfaction_comment', 'Project_Close_Client.reuse_comment', 'Project_Close_Client.benefits_realized')
  ->addWhere('id', '=', $C['fb_no'])->execute()->first();
$answerText = implode("\n", [$fbNo['Project_Close_Client.satisfaction_comment'], $fbNo['Project_Close_Client.reuse_comment'], $fbNo['Project_Close_Client.benefits_realized']]);
$A = [];
$A['feedback_form'] = $act('feedback_form', 'Project Close - Client Feedback', $answerText);
$A['copy_of_feedback'] = $act('copy_of_feedback', 'Email', "Client feedback received:\n$answerText", $A['feedback_form']);
// A Project Definition on a project case arms mascode's lifecycle rule mas_lifecycle_pd_client_send,
// which mails the case's Client Rep: never give an fb_* case a Client Rep (#72 L5).
$A['legacy_source'] = $act('legacy_source', 'Project Definition', 'T10 synthetic project definition note');
$A['copy_of_legacy'] = $act('copy_of_legacy', 'Email', 'T10 synthetic copy of a project definition note', $A['legacy_source']);
$A['followup_source'] = $act('followup_source', 'Follow up', 'T10 synthetic follow-up note');
$A['copy_of_followup'] = $act('copy_of_followup', 'Email', 'T10 synthetic copy of a follow-up note', $A['followup_source']);

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
$vcbDomainEmp = in_array($VCB, array_map('intval', array_column(Relationship::get(FALSE)->addSelect('contact_id_a')->addWhere('contact_id_b', '=', $DOM)->addWhere('relationship_type_id:name', '=', 'Employee of')->addWhere('is_active', '=', TRUE)->execute()->getArrayCopy(), 'contact_id_a')), TRUE);
$testVcIsVc = in_array('MAS_Rep', (array) (Contact::get(FALSE)->addSelect('contact_sub_type')->addWhere('id', '=', $testVc)->execute()->first()['contact_sub_type'] ?? []), TRUE);
// test.vc's own sets, from the same declared searches.
$t = [];
try {
  CRM_Core_Session::singleton()->set('userID', $testVc);
  foreach (['Own_Cases', 'Orgs', 'Cases', 'Employees'] as $k) {
    $p = $decls[$k]['api_params'];
    $p['checkPermissions'] = FALSE;
    $t[$k] = array_map('intval', array_column(civicrm_api4($decls[$k]['api_entity'], 'get', $p)->getArrayCopy(), 'id'));
  }
}
finally {
  CRM_Core_Session::singleton()->set('userID', $original);
}

// VC C's sets, from the same declared searches.
$c = [];
try {
  CRM_Core_Session::singleton()->set('userID', $VCC);
  foreach (['Own_Cases', 'Orgs', 'Cases'] as $k) {
    $p = $decls[$k]['api_params'];
    $p['checkPermissions'] = FALSE;
    $c[$k] = array_map('intval', array_column(civicrm_api4($decls[$k]['api_entity'], 'get', $p)->getArrayCopy(), 'id'));
  }
}
finally {
  CRM_Core_Session::singleton()->set('userID', $original);
}

echo "VC B scope (declared searches):\n";
$own = $s['Own_Cases'];
$want = [$C['own_org'], $C['own_internal'], $C['own_individual'], $C['ended_coord']];
sort($own);
sort($want);
$check('own cases are exactly the T9 own cases, ended coordination included (D5)', $own === $want);
$ended = Relationship::get(FALSE)->addSelect('is_active', 'end_date')->addWhere('contact_id_a', '=', $VCB)
  ->addWhere('case_id', '=', $C['ended_coord'])->execute()->first();
$check('D5 fixture: ended coordinator row is active with a past end date', $ended && $ended['is_active'] && $ended['end_date'] && $ended['end_date'] < date('Y-m-d'));
$check('D5 ended coordination: Org C in Orgs', in_array($ORGC, $s['Orgs'], TRUE));
$check('D5 deactivated coordination: case NOT in Cases, Org D NOT in Orgs', !in_array($C['deactivated_coord'], $s['Cases'], TRUE) && !in_array($ORGD, $s['Orgs'], TRUE));
$check('both T9 pool cases in Pool_Cases', !array_diff([$C['pool_internal'], $C['pool_individual']], $s['Pool_Cases']));
$check('Org B and (D25, own internal case) the domain org in Orgs', in_array($ORG, $s['Orgs'], TRUE) && in_array($DOM, $s['Orgs'], TRUE));
$check('placeholder org (coordinator side B only) NOT in Orgs', !in_array($PH, $s['Orgs'], TRUE));
$check('D23 own individual-client case in Cases', in_array($C['own_individual'], $s['Cases'], TRUE));
$check('D23 pool individual-client case in Cases', in_array($C['pool_individual'], $s['Cases'], TRUE));
$check('Org B employee in Employees', in_array($EMP, $s['Employees'], TRUE));
$check('VC B and the individual clients NOT in Employees', !array_intersect([$VCB, $OWNI, $POOLI], $s['Employees']));
$check('D25 VC B is not an employee of the domain org (fixture sanity)', !$vcbDomainEmp);
$check("test.vc (contact $testVc) is a VC (else the disjointness checks prove nothing)", $testVcIsVc);
$check('disjoint: no test.vc own case in VC B own cases', !array_intersect($t['Own_Cases'], $s['Own_Cases']));
$check('disjoint: VC B own cases (bar the shared internal one) NOT in test.vc Cases', !array_intersect([$C['own_org'], $C['own_individual'], $C['ended_coord'], $C['deactivated_coord']], $t['Cases']));
$check('disjoint: Orgs B, C and D NOT in test.vc Orgs', !array_intersect([$ORG, $ORGC, $ORGD], $t['Orgs']));
$check('disjoint: Org B employee and own client NOT in test.vc Employees', !array_intersect([$EMP, $OWNI], $t['Employees']));
echo "T10 feedback and activity fixtures:\n";
$stored = CiviCase::get(FALSE)->addSelect('id', 'Project_Close_Client.share_with_vc')->addWhere('id', 'IN', array_map(fn($k) => $C[$k], array_keys(T10_SHARE)))->execute()->indexBy('id');
foreach (T10_SHARE as $key => $share) {
  $got = $stored[$C[$key]]['Project_Close_Client.share_with_vc'] ?? NULL;
  // NULL and '' may both read back as NULL; every other value must be stored exactly.
  $check("D22 $key share answer stored as intended", $share === NULL || $share === '' ? ($got === NULL || $got === '') : $got === $share);
  $check("D22 $key case in VC B Cases", in_array($C[$key], $s['Cases'], TRUE));
}
$src = \Civi\Api4\Activity::get(FALSE)->addSelect('id', 'source_record_id', 'case_id')->addWhere('id', 'IN', array_values($A))->execute()->indexBy('id');
$check('S4/S6 activities on the no-consent case', count(array_filter((array) $src->getArrayCopy(), fn($r) => in_array($C['fb_no'], (array) $r['case_id']))) === count($A));
$check('S6 copies point at their sources', (int) $src[$A['copy_of_feedback']]['source_record_id'] === $A['feedback_form']
  && (int) $src[$A['copy_of_legacy']]['source_record_id'] === $A['legacy_source'] && (int) $src[$A['copy_of_followup']]['source_record_id'] === $A['followup_source']);
echo "VC C scope (declared searches):\n";
$check('VC C has no own case', $c['Own_Cases'] === []);
$check('D25 pooled internal case in VC C Cases', in_array($C['pool_internal'], $c['Cases'], TRUE));
$check('D25 ... but not the domain org, nor VC B own internal case', !in_array($DOM, $c['Orgs'], TRUE) && !in_array($C['own_internal'], $c['Cases'], TRUE));

echo "\nids: VC B $VCB (uid {$user->ID}), Org B $ORG, employee $EMP, own client $OWNI, pool client $POOLI, VC C $VCC, placeholder org $PH, Org C $ORGC, Org D $ORGD\n";
echo 'cases: ' . json_encode($C) . "\n";
echo 'activities: ' . json_encode($A) . "\n";
echo "live suites: MCP_LIVE_VC_USER=" . T9_LOGIN . " MCP_LIVE_VC_IDS=$testVc,$VCB\n";
echo $fail ? "FAILURES: $fail\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
