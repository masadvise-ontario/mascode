<?php

/**
 * SerializedEntityRefDisplaySubscriber (donations DN-11, handoff #1174).
 *
 * WHY THIS IS A `cv scr` SCRIPT, NOT A PHPUnit TEST:
 * Same reason as the rest of tests/Live/ (docs/TESTING.md): it drives core's
 * custom-field display and APIv4 events, which need a live CiviCRM.
 *
 * RUN (from the site-side symlink so cv finds the settings file):
 *   cd /home/brian/buildkit/build/masdemo/web/wp-content/uploads/civicrm/ext/mascode
 *   cv flush
 *   cv scr tests/Live/SerializedEntityRefDisplayTest.php --user=brian.flett@masadvise.org
 * Exit code 0 = all pass; non-zero = at least one failure (red).
 * READ-ONLY: it writes nothing.
 *
 * Red without the subscriber: A and B throw core's TypeError
 * ("mysqli_real_escape_string(): Argument #2 ... array given").
 *
 * SCENARIOS:
 *   A  one Linked_Project id       -> its label, no throw
 *   B  two Linked_Project ids      -> both labels (core alone: blank)
 *   C  two Linked_VC ids, padded   -> both labels
 *   D  a permission-checked autocomplete with nested ids is NOT flattened
 *   E  View Contribution renders without error for a linked donation
 *      (B and C cover the values; E only proves the page no longer fatals)
 * Exit 2 = SKIP (missing fixture data), so a skip never reads as a pass.
 */

final class SerTest {
  public static int $failures = 0;
}

function check(bool $ok, string $label): void {
  echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
  if (!$ok) {
    SerTest::$failures++;
  }
}

function fieldId(string $name): int {
  return (int) \Civi\Api4\CustomField::get(FALSE)
    ->addWhere('custom_group_id:name', '=', 'Donation_Link')
    ->addWhere('name', '=', $name)
    ->execute()->single()['id'];
}

function display($value, int $fieldId): string {
  try {
    return (string) CRM_Core_BAO_CustomField::displayValue($value, $fieldId);
  }
  catch (\Throwable $e) {
    return 'THREW ' . get_class($e) . ': ' . $e->getMessage();
  }
}

$projects = \Civi\Api4\CiviCase::get(FALSE)
  ->addSelect('id')
  ->addWhere('case_type_id:name', '=', 'project')
  ->addWhere('is_deleted', '=', FALSE)
  ->setLimit(2)->execute()->column('id');
$contacts = \Civi\Api4\Contact::get(FALSE)
  ->addSelect('id')
  ->addWhere('contact_type', '=', 'Individual')
  ->addWhere('is_deleted', '=', FALSE)
  ->setLimit(2)->execute()->column('id');
if (count($projects) < 2 || count($contacts) < 2) {
  echo "SKIP: needs two projects and two individuals\n";
  exit(2);
}
$label = fn(string $entity, int $id): string => (string) civicrm_api4($entity, 'autocomplete', [
  'checkPermissions' => FALSE,
  'ids' => [$id],
])->single()['label'];

$project = fieldId('Linked_Project');
$vc = fieldId('Linked_VC');

$a = display([$projects[0]], $project);
check($a === $label('Case', $projects[0]), "A one project -> its label ($a)");

$b = display($projects, $project);
check(
  $b === $label('Case', $projects[0]) . ', ' . $label('Case', $projects[1]),
  "B two projects -> both labels ($b)"
);

$sep = CRM_Core_DAO::VALUE_SEPARATOR;
$c = display($sep . implode($sep, $contacts) . $sep, $vc);
check(
  $c === $label('Contact', $contacts[0]) . ', ' . $label('Contact', $contacts[1]),
  "C two VCs, padded -> both labels ($c)"
);

$threw = '';
try {
  civicrm_api4('Case', 'autocomplete', ['checkPermissions' => TRUE, 'ids' => [$projects]]);
}
catch (\Throwable $e) {
  $threw = get_class($e) . ': ' . $e->getMessage();
}
// Core's own TypeError, not some other failure that would also "throw".
check(
  strpos($threw, 'TypeError') === 0 && strpos($threw, 'mysqli_real_escape_string') !== FALSE,
  'D permission-checked nested ids are left to fail as before'
);

$skipE = FALSE;
$linked = \Civi\Api4\Contribution::get(FALSE)
  ->addSelect('id', 'contact_id')
  ->addWhere('Donation_Link.Linked_Project', 'IS NOT EMPTY')
  ->setLimit(1)->execute()->first();
if (!$linked) {
  echo "SKIP E: no linked donation on this site\n";
  $skipE = TRUE;
}
else {
  $_GET = $_REQUEST = [
    'reset' => 1, 'action' => 'view', 'id' => $linked['id'], 'cid' => $linked['contact_id'],
    'q' => 'civicrm/contact/view/contribution',
  ];
  ob_start();
  try {
    CRM_Core_Invoke::invoke(['civicrm', 'contact', 'view', 'contribution']);
    $html = ob_get_clean();
    check(strpos($html, 'Project') !== FALSE && strpos($html, 'TypeError') === FALSE, "E View Contribution {$linked['id']} renders without error");
  }
  catch (\Throwable $e) {
    ob_end_clean();
    check(FALSE, 'E View Contribution threw ' . $e->getMessage());
  }
}

if (SerTest::$failures) {
  echo "\n" . SerTest::$failures . " FAILED\n";
  exit(1);
}
echo $skipE ? "\nPASS, E SKIPPED\n" : "\nALL PASS\n";
exit($skipE ? 2 : 0);
