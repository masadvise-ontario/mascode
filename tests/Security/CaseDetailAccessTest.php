<?php

/**
 * VC Portal — case-detail access-control assertion test.
 *
 * Spec: ~/gdrive-brianpkm/3-Resources/mascode-vc-portal-security-spec.md
 * Handoff theme: vc-portal-security (#608)
 *
 * WHY THIS IS A `cv scr` SCRIPT, NOT A PHPUnit TEST:
 * The PHPUnit Integration suite self-skips in this WP-buildkit site — there is
 * no CiviCRM test-bootstrap.php and civicrm_initialize() never loads, so every
 * Integration test markTestSkipped()s ("CiviCRM not available"). Wiring \Civi\Test
 * headless under WP-buildkit is tracked separately (handoff #570). Until then,
 * security-critical behaviour is verified inside fully-bootstrapped live CiviCRM
 * via `cv scr`, the same channel the scripts/ deploy helpers use.
 *
 * RUN:
 *   cd /home/brian/buildkit/build/masdemo/web
 *   cv scr .../ext/mascode/tests/Security/CaseDetailAccessTest.php
 * Exit code 0 = all pass; non-zero = at least one failure (red).
 *
 * WHAT IT GUARDS (the security boundary — see spec ## Data Model):
 * The case-detail SearchDisplay must return a case ONLY when, for the logged-in
 * VC, the case is (a) in the Sent-for-Assignment pool OR (b) coordinated by that
 * VC. Supplying an arbitrary case id via the page filter must NOT widen access.
 * Every assertion runs the display under a specific session contact and supplies
 * the case-id filter exactly as the front-end afform does.
 */

use Civi\Api4\RelationshipCache;

const SAVED_SEARCH = 'Case_Details_VC';
const DISPLAY      = 'Case_Details_VC_Table_1';
const VC_CONTACT   = 3; // Test VC (WP Subscriber)
const ADMIN_CONTACT = 2; // Brian / admin — coordinates a different case set

/**
 * Static tracker — avoids `global`, which does NOT reach the script's
 * top-level scope under `cv scr` (the body runs inside a method).
 */
class T {
  public static array $failures = [];
  public static int $passes = 0;
}

function note(string $msg): void { echo $msg . "\n"; }

function fail(string $name, string $why): void {
  T::$failures[] = "$name — $why";
  echo "  FAIL: $name — $why\n";
}

function pass(string $name): void {
  T::$passes++;
  echo "  pass: $name\n";
}

/**
 * Run the case-detail gate display as $contactId with a case-id filter,
 * return the number of rows returned (0 = denied / not found).
 * Throws if the search/display does not exist — that is a legitimate red.
 */
function runGate(int $caseId, int $contactId): int {
  return runDisplay(SAVED_SEARCH, DISPLAY, $caseId, $contactId);
}

/**
 * Run any case-detail SearchDisplay as $contactId, filtered to one case id,
 * exactly as the front-end afform does. Returns row count (0 = denied).
 */
function runDisplay(string $search, string $display, int $caseId, int $contactId): int {
  \CRM_Core_Session::singleton()->set('userID', $contactId);
  $result = civicrm_api4('SearchDisplay', 'run', [
    'savedSearch' => $search,
    'display' => $display,
    'filters' => ['id' => $caseId],
    'checkPermissions' => FALSE,
  ]);
  return count($result);
}

// --- Discover fixtures dynamically (robust to data churn) -------------------

function coordinatedCaseIds(int $contactId): array {
  $rows = RelationshipCache::get(FALSE)
    ->addSelect('case_id')
    ->addWhere('near_contact_id', '=', $contactId)
    ->addWhere('near_relation', '=', 'Case Coordinator is')
    ->addWhere('is_active', '=', TRUE)
    ->addWhere('case_id', 'IS NOT NULL')
    ->execute()->getArrayCopy();
  return array_values(array_unique(array_column($rows, 'case_id')));
}

function poolCaseIds(): array {
  $rows = \Civi\Api4\CiviCase::get(FALSE)
    ->addSelect('id')
    ->addWhere('status_id:name', '=', 'Sent for Assignment')
    ->addWhere('is_deleted', '=', FALSE)
    ->execute()->getArrayCopy();
  return array_column($rows, 'id');
}

$vcCases    = coordinatedCaseIds(VC_CONTACT);
$adminCases = coordinatedCaseIds(ADMIN_CONTACT);
$pool       = poolCaseIds();

// own case for the VC that is NOT in the pool (isolate the coordinator branch)
$ownCase = NULL;
foreach ($vcCases as $cid) { if (!in_array($cid, $pool, TRUE)) { $ownCase = $cid; break; } }

// a pool case the VC does NOT coordinate (isolate the pool branch)
$poolCase = NULL;
foreach ($pool as $cid) { if (!in_array($cid, $vcCases, TRUE)) { $poolCase = $cid; break; } }

// a case the admin coordinates but the VC does not, not in pool (per-user scoping)
$adminOnlyCase = NULL;
foreach ($adminCases as $cid) {
  if (!in_array($cid, $vcCases, TRUE) && !in_array($cid, $pool, TRUE)) { $adminOnlyCase = $cid; break; }
}

// an unrelated case: not coordinated by VC, not in pool, not coordinated by admin
$allCases = array_column(
  \Civi\Api4\CiviCase::get(FALSE)->addSelect('id')->addWhere('is_deleted', '=', FALSE)
    ->setLimit(0)->execute()->getArrayCopy(),
  'id'
);
$unrelatedCase = NULL;
foreach ($allCases as $cid) {
  if (!in_array($cid, $vcCases, TRUE) && !in_array($cid, $pool, TRUE) && !in_array($cid, $adminCases, TRUE)) {
    $unrelatedCase = $cid; break;
  }
}

note("Fixtures: ownCase=$ownCase poolCase=$poolCase adminOnlyCase=$adminOnlyCase unrelatedCase=$unrelatedCase");
note("(VC coordinates " . count($vcCases) . " cases; pool has " . count($pool) . " cases)");
note('');

foreach (['ownCase' => $ownCase, 'poolCase' => $poolCase, 'adminOnlyCase' => $adminOnlyCase, 'unrelatedCase' => $unrelatedCase] as $k => $v) {
  if ($v === NULL) { fail("fixture:$k", "could not find a suitable case in dev data — cannot run the matrix"); }
}

// --- The access matrix ------------------------------------------------------

note('Running access matrix against ' . SAVED_SEARCH . '/' . DISPLAY . ' ...');
try {
  // 1. VC opens their own case → ALLOW (exactly 1 row)
  if ($ownCase !== NULL) {
    $n = runGate($ownCase, VC_CONTACT);
    $n === 1 ? pass("VC sees own coordinated case ($ownCase)") : fail("VC own case", "expected 1 row, got $n");
  }
  // 2. VC opens a pool case they don't coordinate → ALLOW
  if ($poolCase !== NULL) {
    $n = runGate($poolCase, VC_CONTACT);
    $n === 1 ? pass("VC sees Sent-for-Assignment pool case ($poolCase)") : fail("VC pool case", "expected 1 row, got $n");
  }
  // 3. VC opens an unrelated case → DENY (0 rows) — THE core security assertion
  if ($unrelatedCase !== NULL) {
    $n = runGate($unrelatedCase, VC_CONTACT);
    $n === 0 ? pass("VC DENIED unrelated case ($unrelatedCase)") : fail("VC unrelated case", "expected 0 rows (URL tamper), got $n — DATA LEAK");
  }
  // 4. VC opens a case only the admin coordinates → DENY (per-user scoping)
  if ($adminOnlyCase !== NULL) {
    $n = runGate($adminOnlyCase, VC_CONTACT);
    $n === 0 ? pass("VC DENIED admin-only case ($adminOnlyCase)") : fail("VC admin-only case", "expected 0 rows, got $n — cross-VC leak");
    // 5. ...but the admin themselves CAN see it → predicate is per-user, not broken-open
    $n2 = runGate($adminOnlyCase, ADMIN_CONTACT);
    $n2 === 1 ? pass("Admin sees own coordinated case ($adminOnlyCase)") : fail("Admin own case", "expected 1 row, got $n2");
  }
} catch (\Throwable $e) {
  fail("display run", get_class($e) . ': ' . $e->getMessage());
}

// --- Sub-section gates (risk #2: each content block independently gated) -----

// Non-conditional sections — present on every entitled case regardless of type.
$sections = [
  'Activities' => ['Case_Details_VC_Activities', 'Case_Details_VC_Activities_Table_1'],
  'Roles'      => ['Case_Details_VC_Roles', 'Case_Details_VC_Roles_Table_1'],
  'Client'     => ['Case_Details_VC_Client', 'Case_Details_VC_Client_Table_1'],
];
// Case-type-conditional custom-field cards — only render for the matching type,
// so we assert the DENY guarantee on all of them (the security property) and add
// type-appropriate positive checks below.
$cards = [
  'SR Fields'       => ['Case_Details_VC_SR_Fields', 'Case_Details_VC_SR_Fields_Card_1'],
  'Proj Fields'     => ['Case_Details_VC_Proj', 'Case_Details_VC_Proj_Card_1'],
  'Proj Def'        => ['Case_Details_VC_ProjDef', 'Case_Details_VC_ProjDef_Card_1'],
  'Proj Auth'       => ['Case_Details_VC_ProjAuth', 'Case_Details_VC_ProjAuth_Card_1'],
  'Proj Close VC'   => ['Case_Details_VC_ProjCloseVC', 'Case_Details_VC_ProjCloseVC_Card_1'],
  'Proj Close Cli'  => ['Case_Details_VC_ProjCloseClient', 'Case_Details_VC_ProjCloseClient_Card_1'],
];
note('');
note('Running sub-section gates ...');
foreach ($sections as $label => [$search, $display]) {
  try {
    if ($ownCase !== NULL) {
      $n = runDisplay($search, $display, $ownCase, VC_CONTACT);
      $n > 0 ? pass("$label visible on own case ($ownCase): $n row(s)")
             : fail("$label own case", "expected >0 rows on an authorized case, got 0");
    }
    if ($unrelatedCase !== NULL) {
      $n = runDisplay($search, $display, $unrelatedCase, VC_CONTACT);
      $n === 0 ? pass("$label DENIED on unrelated case ($unrelatedCase)")
               : fail("$label unrelated case", "expected 0 rows, got $n — SECTION DATA LEAK");
    }
    if ($adminOnlyCase !== NULL) {
      $n = runDisplay($search, $display, $adminOnlyCase, VC_CONTACT);
      $n === 0 ? pass("$label DENIED on admin-only case ($adminOnlyCase)")
               : fail("$label admin-only case", "expected 0 rows, got $n — cross-VC section leak");
    }
  } catch (\Throwable $e) {
    fail("$label run", get_class($e) . ': ' . $e->getMessage());
  }
}

note('');
note('Running custom-field-card gates (deny matrix) ...');
foreach ($cards as $label => [$search, $display]) {
  try {
    if ($unrelatedCase !== NULL) {
      $n = runDisplay($search, $display, $unrelatedCase, VC_CONTACT);
      $n === 0 ? pass("$label DENIED on unrelated case ($unrelatedCase)")
               : fail("$label unrelated case", "expected 0 rows, got $n — CARD DATA LEAK");
    }
    if ($adminOnlyCase !== NULL) {
      $n = runDisplay($search, $display, $adminOnlyCase, VC_CONTACT);
      $n === 0 ? pass("$label DENIED on admin-only case ($adminOnlyCase)")
               : fail("$label admin-only case", "expected 0 rows, got $n — cross-VC card leak");
    }
  } catch (\Throwable $e) {
    fail("$label run", get_class($e) . ': ' . $e->getMessage());
  }
}

// Type-appropriate positive: the SR card renders for a pool (SR) case the VC can see.
note('');
note('Running type-conditional positive checks ...');
try {
  if ($poolCase !== NULL) {
    $n = runDisplay('Case_Details_VC_SR_Fields', 'Case_Details_VC_SR_Fields_Card_1', $poolCase, VC_CONTACT);
    $n > 0 ? pass("SR Fields visible on pool SR case ($poolCase)")
           : fail("SR Fields pool case", "expected >0 rows on a pool SR, got $n");
  }
  // Find a project the VC coordinates, to confirm the Project card renders.
  $vcProject = NULL;
  foreach ($vcCases as $cid) {
    $ct = \Civi\Api4\CiviCase::get(FALSE)->addSelect('case_type_id:name')->addWhere('id', '=', $cid)
      ->execute()->first();
    if (($ct['case_type_id:name'] ?? '') === 'project') { $vcProject = $cid; break; }
  }
  if ($vcProject !== NULL) {
    $n = runDisplay('Case_Details_VC_Proj', 'Case_Details_VC_Proj_Card_1', $vcProject, VC_CONTACT);
    $n > 0 ? pass("Proj Fields visible on own project case ($vcProject)")
           : fail("Proj Fields own project", "expected >0 rows on an own project, got $n");
  } else {
    note("  (no own project case found for VC — skipped Project-card positive)");
  }
} catch (\Throwable $e) {
  fail("positive checks", get_class($e) . ': ' . $e->getMessage());
}

// --- Summary ----------------------------------------------------------------

note('');
if (T::$failures) {
  note("RESULT: RED — " . count(T::$failures) . " failure(s), " . T::$passes . " pass(es)");
  foreach (T::$failures as $f) { note("  - $f"); }
  exit(1);
}
note("RESULT: GREEN — all " . T::$passes . " assertions passed");
exit(0);
