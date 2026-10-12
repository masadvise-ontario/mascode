<?php

/**
 * CiviRules action "mas: Add Relationship to Employer" (EmployerRelationship).
 *
 * WHY THIS IS A `cv scr` SCRIPT, NOT A PHPUnit TEST:
 * Same reason as the rest of tests/Live/ (docs/TESTING.md): the action runs
 * inside CiviRules and API4, which need a live, fully-bootstrapped CiviCRM.
 * CI has none, and the PHPUnit Integration suite self-skips in this site.
 *
 * RUN (from the site-side symlink so cv finds the settings file):
 *   cd /home/brian/buildkit/build/masdemo/web/wp-content/uploads/civicrm/ext/mascode
 *   cv flush
 *   cv scr tests/Live/EmployerRelationshipTest.php --user=brian.flett@masadvise.org
 * Exit code 0 = all pass; non-zero = at least one failure (red).
 * DEV ONLY: it creates and then deletes its own test contacts.
 *
 * WHAT IT GUARDS (handoff #1018)
 * The parent CRM_CivirulesActions_Generic_Api::processAction() always calls the
 * API, whatever alterApiParameters() returns. The old action returned [] to
 * "skip" when the relationship already existed, so CiviRules ran api3
 * Relationship.create with no contact ids and logged
 *   "Mandatory key(s) missing from params array: contact_id_a, contact_id_b"
 * on every edit of someone who already had the relationship. Scenario B is the
 * one that was red: it must neither throw nor create a duplicate.
 *
 * WHY SCENARIOS A-C USE A NON-ED JOB TITLE: on this site the live ED rules (5 on
 * add, 6 on change-to-ED) fire when makeContact() saves an ED-titled contact, so
 * the rule hook — not runAction() — would create the relationship and A would only
 * ever exercise the skip path. The action itself does not check the job title, so a
 * title the rules ignore lets runAction() do the create. The ED-titled contact in
 * scenario E is the regression for the other old effect: its employer was lost.
 *
 * SCENARIOS (each on its own contacts, so none can mask another):
 *   A  employer, no relationship yet   -> runAction creates exactly one (0 before it)
 *   B  run again on the same contact   -> no exception, still exactly one
 *   C  no employer                     -> no exception, nothing created
 *   D  relationship type not configured -> throws
 *   E  ED-titled contact saved with an employer -> employer kept, one ED relationship
 */

use Civi\Mascode\CiviRules\Action\EmployerRelationship;

// State lives in a class, not in globals: `cv scr` runs this file inside a
// function, so a top-level $failures is a LOCAL and `global $failures` in a
// helper silently counts into a different variable (first draft printed
// ALL PASS over three FAILs).
final class ErTest {
  public static int $failures = 0;
  public static array $created = [];
}

function check(bool $ok, string $label): void {
  echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
  if (!$ok) {
    ErTest::$failures++;
  }
}

function makeContact(array $values): int {
  $id = \Civi\Api4\Contact::create(FALSE)->setValues($values)->execute()->first()['id'];
  ErTest::$created[] = $id;
  return $id;
}

function runAction(int $contactId, array $actionParams): ?string {
  $action = new EmployerRelationship();
  $action->setRuleActionData(['id' => 0, 'rule_id' => 0, 'action_params' => serialize($actionParams)]);
  $triggerData = new CRM_Civirules_TriggerData_Post('Contact', $contactId, ['id' => $contactId]);
  $triggerData->setContactId($contactId);
  try {
    $action->processAction($triggerData);
    return NULL;
  }
  catch (\Throwable $e) {
    return $e->getMessage();
  }
}

function countRelationships(int $a, int $b, int $typeId): int {
  return \Civi\Api4\Relationship::get(FALSE)
    ->addWhere('contact_id_a', '=', $a)
    ->addWhere('contact_id_b', '=', $b)
    ->addWhere('relationship_type_id', '=', $typeId)
    ->addWhere('is_active', '=', TRUE)
    ->execute()
    ->count();
}

$typeId = \Civi\Api4\RelationshipType::get(FALSE)
  ->addSelect('id')
  ->addWhere('name_a_b', '=', 'Executive Director of')
  ->execute()
  ->first()['id'] ?? NULL;

if (!$typeId) {
  echo "FAIL relationship type 'Executive Director of' not found on this site\n";
  exit(1);
}
$params = ['relationship_type_id' => $typeId];
$tag = 'ZZ-ERTest-' . bin2hex(random_bytes(3));

try {
  // A — employer, no relationship yet
  $orgA = makeContact(['contact_type' => 'Organization', 'organization_name' => "$tag Org A"]);
  $edA = makeContact([
    'contact_type' => 'Individual', 'first_name' => 'Ed', 'last_name' => "$tag A",
    'job_title' => 'Test Role', 'employer_id' => $orgA,
  ]);
  check(countRelationships($edA, $orgA, $typeId) === 0, 'A: setup — no relationship before the action runs');
  $err = runAction($edA, $params);
  check($err === NULL, "A: no exception (got: " . ($err ?? 'none') . ")");
  check(countRelationships($edA, $orgA, $typeId) === 1, 'A: exactly one active relationship created');

  // B — the old bug: the relationship already exists
  $err = runAction($edA, $params);
  check($err === NULL, "B: re-run on an existing relationship does not throw (got: " . ($err ?? 'none') . ")");
  check(countRelationships($edA, $orgA, $typeId) === 1, 'B: still exactly one — no duplicate');

  // C — no employer
  $edC = makeContact([
    'contact_type' => 'Individual', 'first_name' => 'Ed', 'last_name' => "$tag C",
    'job_title' => 'Test Role',
  ]);
  $err = runAction($edC, $params);
  check($err === NULL, "C: no employer does not throw (got: " . ($err ?? 'none') . ")");
  $anyC = \Civi\Api4\Relationship::get(FALSE)
    ->addWhere('contact_id_a', '=', $edC)
    ->addWhere('relationship_type_id', '=', $typeId)
    ->execute()->count();
  check($anyC === 0, 'C: nothing created');

  // D — misconfigured action
  $err = runAction($edA, []);
  check($err !== NULL && str_contains($err, 'Relationship type ID not configured'), 'D: missing relationship type throws');

  // E — the old throw, inside the live rule hook, lost the employer on save
  $orgE = makeContact(['contact_type' => 'Organization', 'organization_name' => "$tag Org E"]);
  $edE = makeContact([
    'contact_type' => 'Individual', 'first_name' => 'Ed', 'last_name' => "$tag E",
    'job_title' => 'Executive Director', 'employer_id' => $orgE,
  ]);
  $savedEmployer = \Civi\Api4\Contact::get(FALSE)->addSelect('employer_id')
    ->addWhere('id', '=', $edE)->execute()->first()['employer_id'] ?? NULL;
  check((int) $savedEmployer === $orgE, 'E: ED saved with an employer keeps it');
  check(countRelationships($edE, $orgE, $typeId) === 1, 'E: the live rule left exactly one ED relationship');
}
finally {
  foreach (array_reverse(ErTest::$created) as $id) {
    \Civi\Api4\Contact::delete(FALSE)->addWhere('id', '=', $id)->setUseTrash(FALSE)->execute();
  }
}

echo ErTest::$failures ? "\n" . ErTest::$failures . " FAILURE(S)\n" : "\nALL PASS\n";
exit(ErTest::$failures ? 1 : 0);
