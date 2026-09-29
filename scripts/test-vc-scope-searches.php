<?php

/**
 * DEV ONLY. Synthetic test of the D23/D25 rules in the VC scope searches
 * (Civi/Mascode/Managed/SavedSearch_MAS_VC_Scope_Sets.mgd.php, T32): the
 * branches masdemo's real data cannot exercise — a pooled internal request, a
 * pooled or own case with an individual client, a case filed under an
 * in-scope employee, an employee of both a client and the domain
 * organisation, a VC employed by a client.
 *
 * It creates synthetic contacts, relationships and cases INSIDE a transaction
 * and rolls it back, then confirms nothing is left. It runs the searches from
 * this checkout's DECLARATION (not the stored SavedSearch), so it tests the
 * file before a flush and works from a worktree. Prints PASS/FAIL per rule
 * only. Refuses to run where the CiviCRM environment is Production.
 *
 *   cv scr scripts/test-vc-scope-searches.php --user=<staff login>
 *
 * Runs as test.vc (contact id in CHECK_VC_ID, default 3), who must coordinate
 * no internal case. Exit code 1 on any failure.
 */

if (\Civi::settings()->get('environment') === 'Production') {
  fwrite(STDERR, "Refusing: this script writes (and rolls back) synthetic data; dev only.\n");
  exit(2);
}
use Civi\Api4\Contact; use Civi\Api4\CiviCase; use Civi\Api4\Relationship;
$decls = [];
foreach (include __DIR__ . '/../Civi/Mascode/Managed/SavedSearch_MAS_VC_Scope_Sets.mgd.php' as $d) { $decls[substr($d['params']['values']['name'], 13)] = $d['params']['values']; }
$run = function ($set) use ($decls) { $v = $decls[$set]; $p = $v['api_params']; $p['checkPermissions'] = FALSE;
  return array_map('intval', array_column(civicrm_api4($v['api_entity'], 'get', $p)->getArrayCopy(), 'id')); };
$VC = (int) (getenv('CHECK_VC_ID') ?: 3); $DOM = (int) \Civi\Api4\Domain::get(FALSE)->addSelect('contact_id')->execute()->first()['contact_id'];
$fail = 0;
$check = function ($label, $ok) use (&$fail) { $fail += $ok ? 0 : 1; printf("  %-72s %s\n", $label, $ok ? 'PASS' : 'FAIL'); };
$tx = new CRM_Core_Transaction();
$original = CRM_Core_Session::singleton()->get('userID');
$creator = CRM_Core_Session::getLoggedInContactID();
try {
  $ind = fn($n, $sub = NULL) => Contact::create(FALSE)->setValues(['contact_type' => 'Individual', 'first_name' => 'T32', 'last_name' => $n] + ($sub ? ['contact_sub_type' => [$sub]] : []))->execute()->first()['id'];
  $X = Contact::create(FALSE)->setValues(['contact_type' => 'Organization', 'organization_name' => 'T32 Synthetic Org'])->execute()->first()['id'];
  $Y = Contact::create(FALSE)->setValues(['contact_type' => 'Organization', 'organization_name' => 'T32 Out Of Scope Org'])->execute()->first()['id'];
  $E1 = $ind('Plain'); $E2 = $ind('AlsoDomain'); $E3 = $ind('Vc', 'MAS_Rep'); $I1 = $ind('PoolClient'); $I2 = $ind('OwnClient'); $I3 = $ind('Outsider');
  $emp = fn($a, $b) => Relationship::create(FALSE)->setValues(['contact_id_a' => $a, 'contact_id_b' => $b, 'relationship_type_id:name' => 'Employee of', 'is_active' => TRUE])->execute();
  $emp($E1, $X); $emp($E2, $X); $emp($E2, $DOM); $emp($E3, $X);
  $case = fn($clients, $status) => CiviCase::create(FALSE)->setValues(['case_type_id:name' => 'service_request', 'status_id:name' => $status, 'subject' => 'T32 synthetic', 'creator_id' => $creator, 'contact_id' => $clients])->execute()->first()['id'];
  $P1 = $case([$DOM], 'Sent for Assignment');   // pooled internal request
  $P2 = $case([$X], 'Sent for Assignment');     // pooled org request
  $P3 = $case([$I1], 'Sent for Assignment');    // pooled individual request
  $O1 = $case([$I2], 'Open');                   // own case, individual client
  Relationship::create(FALSE)->setValues(['contact_id_a' => $VC, 'contact_id_b' => $Y, 'relationship_type_id:name' => 'Case Coordinator is', 'case_id' => $O1, 'is_active' => TRUE])->execute();
  $N1 = $case([$E1], 'Open');                   // individual employee of in-scope org, not own/pool
  $M1 = $case([$X, $I3], 'Open');              // in-scope org + out-of-scope individual
  $Z1 = $case([$Y], 'Open');                    // unrelated org
  CRM_Core_Session::singleton()->set('userID', $VC);
  $s = []; foreach (array_keys($decls) as $k) { $s[$k] = $run($k); }
  $internal = array_map('intval', array_column(\Civi\Api4\CaseContact::get(FALSE)->addSelect('case_id')->addWhere('contact_id', '=', $DOM)->execute()->getArrayCopy(), 'case_id'));
  echo "synthetic (test.vc, coordinates no internal case):\n";
  $check('D25 pooled internal request: domain org NOT in Orgs', !in_array($DOM, $s['Orgs'], TRUE));
  $check('D25 pooled internal request adds only itself to Cases', array_values(array_intersect($s['Cases'], $internal)) === [$P1]);
  $check('pooled org request: org in Orgs', in_array($X, $s['Orgs'], TRUE));
  $check('D23 pool case with individual client in Cases', in_array($P3, $s['Cases'], TRUE));
  $check('D23 own case with individual client in Cases', in_array($O1, $s['Cases'], TRUE));
  $check('D23 individual-client case of an in-scope employee NOT in Cases', !in_array($N1, $s['Cases'], TRUE));
  $check('D23 in-scope org + out-of-scope individual: case in Cases', in_array($M1, $s['Cases'], TRUE));
  $check('unrelated org case NOT in Cases; unrelated org NOT in Orgs', !in_array($Z1, $s['Cases'], TRUE) && !in_array($Y, $s['Orgs'], TRUE));
  $check('plain employee of in-scope org in Employees', in_array($E1, $s['Employees'], TRUE));
  $check('D25 employee of in-scope org AND domain org NOT in Employees', !in_array($E2, $s['Employees'], TRUE));
  $check('D25 MAS_Rep employee of in-scope org NOT in Employees', !in_array($E3, $s['Employees'], TRUE));
  $check('individual case clients are not Employees', !array_intersect([$I1, $I2, $I3], $s['Employees']));
  $check('no domain-org employee at all in Employees', !array_intersect($s['Employees'], array_map('intval', array_column(Relationship::get(FALSE)->addSelect('contact_id_a')->addWhere('contact_id_b', '=', $DOM)->addWhere('relationship_type_id.name_a_b', '=', 'Employee of')->addWhere('is_active', '=', TRUE)->execute()->getArrayCopy(), 'contact_id_a'))));
  // Internal coordinator: own route DOES bring the domain org in.
  Relationship::create(FALSE)->setValues(['contact_id_a' => $VC, 'contact_id_b' => $DOM, 'relationship_type_id:name' => 'Case Coordinator is', 'case_id' => $internal[0] === $P1 ? $internal[1] : $internal[0], 'is_active' => TRUE])->execute();
  $s2 = []; foreach (['Orgs', 'Cases', 'Employees'] as $k) { $s2[$k] = $run($k); }
  echo "synthetic (test.vc after coordinating one internal case):\n";
  $check('own internal case brings domain org into Orgs', in_array($DOM, $s2['Orgs'], TRUE));
  $check('... and every live internal case into Cases', !array_diff(array_map('intval', array_column(CiviCase::get(FALSE)->addSelect('id')->addWhere('id', 'IN', $internal)->addWhere('is_deleted', '=', FALSE)->execute()->getArrayCopy(), 'id')), $s2['Cases']));
  $check('... but still no domain-org employee or VC in Employees', !in_array($E2, $s2['Employees'], TRUE) && !in_array($E3, $s2['Employees'], TRUE) && count($s2['Employees']) === count($s['Employees']));
}
finally {
  $tx->rollback()->commit();
  CRM_Core_Session::singleton()->set('userID', $original);
}
echo $fail ? "FAILURES: $fail\n" : "ALL PASS\n";
$left = Contact::get(FALSE)->addWhere('organization_name', '=', 'T32 Synthetic Org')->execute()->count();
echo "rolled back: synthetic org rows left = $left\n";
exit(($fail || $left) ? 1 : 0);
