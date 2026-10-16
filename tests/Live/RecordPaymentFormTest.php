<?php

/**
 * Record Payment form fixes (donations DN-11, handoff #1174), in
 * Civi\Mascode\Event\DonationSubscriber (onBuildForm, onPostProcess).
 *
 * WHY THIS IS A `cv scr` SCRIPT, NOT A PHPUnit TEST:
 * Same reason as the rest of tests/Live/ (docs/TESTING.md): it renders core's
 * QuickForm, which needs a live CiviCRM.
 *
 * RUN (from the site-side symlink so cv finds the settings file):
 *   cd /home/brian/buildkit/build/masdemo/web/wp-content/uploads/civicrm/ext/mascode
 *   cv flush
 *   cv scr tests/Live/RecordPaymentFormTest.php --user=brian.flett@masadvise.org
 * Exit 0 = all pass; 1 = a failure; 2 = skipped (no Pending donation on this site).
 * READ-ONLY: it renders the form and never submits it.
 *
 * SCENARIOS:
 *   A  the form opts into core's submit-once guard (data-submit-once). Without
 *      it a double click sends two POSTs and the second dies with "Could not
 *      find valid value for id" after the first has saved.
 *   B  the post-save redirect hook is subscribed. Its effect (landing on the
 *      contact's Contributions tab, not "We could not find a contact id.")
 *      needs a real submit, so it was checked in a browser: see the PR.
 */

use Civi\Mascode\Event\DonationSubscriber;

final class RpTest {
  public static int $failures = 0;
}

function check(bool $ok, string $label): void {
  echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
  if (!$ok) {
    RpTest::$failures++;
  }
}

$pending = \Civi\Api4\Contribution::get(FALSE)
  ->addSelect('id')
  ->addWhere('contribution_status_id:name', '=', 'Pending')
  ->addWhere('is_test', '=', FALSE)
  ->setLimit(1)->execute()->first();
if (!$pending) {
  echo "SKIP: no Pending contribution on this site\n";
  exit(2);
}

$_GET = $_REQUEST = [
  'reset' => 1, 'action' => 'add', 'id' => $pending['id'], 'is_refund' => 0,
  'q' => 'civicrm/payment',
];
ob_start();
try {
  CRM_Core_Invoke::invoke(['civicrm', 'payment']);
  $html = ob_get_clean();
}
catch (\Throwable $e) {
  ob_end_clean();
  $html = '';
  check(FALSE, 'A Record Payment threw ' . $e->getMessage());
}
preg_match('/<form[^>]*id="AdditionalPayment"[^>]*>/', $html, $m);
check(
  isset($m[0]) && strpos($m[0], 'data-submit-once="true"') !== FALSE,
  "A Record Payment for contribution {$pending['id']} submits once"
);

$events = DonationSubscriber::getSubscribedEvents();
check(
  ($events['hook_civicrm_postProcess'] ?? null) === 'onPostProcess',
  'B post-save redirect hook is subscribed'
);

if (RpTest::$failures) {
  echo "\n" . RpTest::$failures . " FAILED\n";
  exit(1);
}
echo "\nALL PASS\n";
exit(0);
