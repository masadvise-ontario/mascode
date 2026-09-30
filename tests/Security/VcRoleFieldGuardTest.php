<?php

/**
 * VC role-field guard — live assertions (Civi/Mascode/Event/VcRoleFieldGuardSubscriber.php,
 * rules in Civi/Mascode/Security/VcRoleFieldPolicy.php; unit tests in
 * tests/Unit/Security/VcRoleFieldPolicyTest.php).
 *
 * A `cv scr` script, not PHPUnit, for the same reason as CaseDetailAccessTest.php: the Integration
 * suite self-skips under WP-buildkit, and the guard's point is how real CiviCRM saves behave.
 *
 * WHAT IT PROVES, signed in as a real non-staff VC (a WordPress Subscriber):
 *   - APIv4 Contact.update of their OWN VC_Status, Admin flag, or MAS_Rep sub-type is refused with
 *     the policy's message (the self-promotion route found in mascode PR #64 review M1);
 *   - the custom-data route (CustomValueTable, i.e. customPre) is refused too;
 *   - what a VC legitimately does still works: their job title, their own Skills, and a save that
 *     re-sends their sub-type and status unchanged (as the backend contact form does);
 *   - staff (STAFF_USER) may still change VC_Status;
 *   - optionally (NON_VC_USER), a non-VC subscriber cannot give themselves the MAS_Rep sub-type.
 * Every write happens inside a CRM_Core_Transaction that is always rolled back, and the stored
 * values are compared before and after. Prints field names and pass/fail only — no contact data.
 *
 * RUN (masdemo):
 *   STAFF_USER=<staff wp login> [NON_VC_USER=<non-VC subscriber login>] \
 *     cv scr .../ext/mascode/tests/Security/VcRoleFieldGuardTest.php --user=<a VC's wp login>
 * Exit 0 = all pass; 1 = at least one failure.
 */

use Civi\Api4\Contact;
use Civi\Mascode\Security\VcRoleFieldPolicy;

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

function isStaff(): bool {
  return \CRM_Core_Permission::check([VcRoleFieldPolicy::STAFF_PERMISSIONS]);
}

/** Run $fn; pass if it is refused with the guard's message, fail otherwise. */
function expectRefused(string $name, callable $fn): void {
  try {
    $fn();
    fail($name, 'the save went through');
  }
  catch (\Throwable $e) {
    str_contains($e->getMessage(), VcRoleFieldPolicy::MESSAGE)
      ? pass("$name — refused")
      : fail($name, 'refused, but not by the guard: ' . get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 160));
  }
}

function expectAllowed(string $name, callable $fn): void {
  try {
    $fn();
    pass("$name — allowed");
  }
  catch (\Throwable $e) {
    fail($name, get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 160));
  }
}

function snapshot(int $cid): array {
  $row = Contact::get(FALSE)
    ->addSelect('contact_sub_type', 'job_title', 'MAS_Rep.VC_Status', 'MAS_Rep.Admin', 'MAS_Rep.Skills')
    ->addWhere('id', '=', $cid)->execute()->first();
  return array_map(fn($v) => json_encode($v), $row ?? []);
}

function login(string $login): int {
  if (!\CRM_Core_Config::singleton()->userSystem->loadUser($login)) {
    throw new \RuntimeException('could not sign in as the given login');
  }
  return (int) \CRM_Core_Session::getLoggedInContactID();
}

$me = (int) \CRM_Core_Session::getLoggedInContactID();
$vcLogin = wp_get_current_user()->user_login ?? '';
note("VC role-field guard — signed-in contact $me");
if ($me <= 0 || isStaff()) {
  fail('setup', 'run with --user=<a NON-staff VC login>; this user is staff or not signed in');
}
$before = snapshot($me);
if (!in_array(VcRoleFieldPolicy::SUB_TYPE, (array) json_decode($before['contact_sub_type'] ?? 'null', TRUE), TRUE)) {
  fail('setup', 'the signed-in contact is not a VC (no MAS_Rep sub-type)');
}
$status = json_decode($before['MAS_Rep.VC_Status'] ?? 'null', TRUE);
$other = $status === 'Active' ? 'Test' : 'Active';
$vcStatusId = (int) \Civi\Api4\CustomField::get(FALSE)->addSelect('id')
  ->addWhere('custom_group_id:name', '=', 'MAS_Rep')->addWhere('name', '=', 'VC_Status')->execute()->first()['id'];

if (!T::$failures) {
  $tx = new \CRM_Core_Transaction();
  try {
    note('As the VC:');
    expectRefused('own VC_Status via APIv4', fn() => Contact::update(TRUE)->addWhere('id', '=', $me)
      ->addValue('MAS_Rep.VC_Status', $other)->execute());
    expectRefused('own Admin flag via APIv4', fn() => Contact::update(TRUE)->addWhere('id', '=', $me)
      ->addValue('MAS_Rep.Admin', json_decode($before['MAS_Rep.Admin'], TRUE) ? FALSE : TRUE)->execute());
    expectRefused('remove own MAS_Rep sub-type via APIv4', fn() => Contact::update(TRUE)->addWhere('id', '=', $me)
      ->addValue('contact_sub_type', [])->execute());
    expectRefused('own VC_Status via custom-data save (customPre)', function () use ($me, $vcStatusId, $other) {
      $values = ['entityID' => $me, "custom_$vcStatusId" => $other];
      $r = \CRM_Core_BAO_CustomValueTable::setValues($values);
      if (!empty($r['is_error'])) {
        throw new \CRM_Core_Exception((string) ($r['error_message'] ?? 'setValues error'));
      }
    });

    expectAllowed('own job title', fn() => Contact::update(TRUE)->addWhere('id', '=', $me)
      ->addValue('job_title', 'Guard probe')->execute());
    expectAllowed('own Skills (self-service field)', fn() => Contact::update(TRUE)->addWhere('id', '=', $me)
      ->addValue('MAS_Rep.Skills', 'Guard probe')->execute());
    expectAllowed('re-send own sub-type and status unchanged', fn() => Contact::update(TRUE)->addWhere('id', '=', $me)
      ->addValue('contact_sub_type', json_decode($before['contact_sub_type'], TRUE))
      ->addValue('MAS_Rep.VC_Status', $status)->execute());

    $nonVc = getenv('NON_VC_USER');
    if ($nonVc) {
      note('As a non-VC subscriber:');
      $cid = login($nonVc);
      if ($cid === $me || isStaff()) {
        fail('non-VC setup', 'NON_VC_USER must be a different, non-staff login');
      }
      else {
        expectRefused('give self the MAS_Rep sub-type', fn() => Contact::update(TRUE)->addWhere('id', '=', $cid)
          ->addValue('contact_sub_type', ['MAS_Rep'])->execute());
      }
    }
    else {
      note('  (NON_VC_USER not set — non-VC case skipped)');
    }

    $staff = getenv('STAFF_USER');
    if ($staff) {
      note('As staff:');
      login($staff);
      isStaff()
        ? expectAllowed('staff changes the VC\'s VC_Status', fn() => Contact::update(TRUE)->addWhere('id', '=', $me)
          ->addValue('MAS_Rep.VC_Status', $other)->execute())
        : fail('staff setup', 'STAFF_USER is not staff');
    }
    else {
      fail('staff setup', 'set STAFF_USER — the staff path is part of the proof');
    }
  }
  catch (\Throwable $e) {
    fail('probe', get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 200));
  }
  finally {
    $tx->rollback();
    $tx->commit();
    if ($vcLogin !== '') {
      \CRM_Core_Config::singleton()->userSystem->loadUser($vcLogin);
    }
  }

  $after = snapshot($me);
  $drift = array_keys(array_diff_assoc($before, $after) + array_diff_assoc($after, $before));
  $drift === []
    ? pass('probe rolled back cleanly (VC contact unchanged)')
    : fail('rollback', 'these fields differ after rollback: ' . implode(', ', $drift));
}

note('');
if (T::$failures) {
  note('RESULT: RED — ' . count(T::$failures) . ' failure(s), ' . T::$passes . ' pass(es)');
  foreach (T::$failures as $f) { note("  - $f"); }
  exit(1);
}
note('RESULT: GREEN — all ' . T::$passes . ' assertions passed');
exit(0);
