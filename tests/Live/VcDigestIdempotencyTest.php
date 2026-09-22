<?php

/**
 * The digest's re-send guard, against a real database.
 *
 * WHY THIS IS A `cv scr` SCRIPT AND NOT A UNIT TEST.
 * `VcDigestMailer::alreadySentThisRound()` issues an API4 query, and CI has no
 * CiviCRM (docs/TESTING.md). The unit suite pins the *ingredients* — that
 * `markerFragmentsFor()` produces both the round and the contact id, that the
 * method's source applies every fragment — but review demonstrated the limit of
 * that: replacing the method body with an early `return false;` leaves the
 * query present as dead code, so no source assertion can see it. Reachability
 * needs a database.
 *
 * WHAT IT GUARDS, and why each case is here rather than being obvious:
 *
 *   1. A VC who has had this round is skipped.       The point of the guard.
 *   2. A DIFFERENT round is not skipped.             Or the digest sends once, ever.
 *   3. A DIFFERENT VC is not skipped.                THE CRITICAL ONE. The first
 *      version of this guard took the VC id and never used it, so on a project
 *      with two coordinators the second VC was skipped ENTIRELY — every project
 *      they hold. Measured on the 2026-09-21 clone: 3 of 62 VCs would have
 *      received nothing, every month, deterministically.
 *   4. A LONGER contact id does not match a shorter one's fragment.
 *      `"recipient_contact_id":941` is a LIKE-prefix of `…:9411`. Those two
 *      ids are arbitrary — they only ever appear inside a marker string, and
 *      neither has to exist on the environment. The property is id-shaped, not
 *      contact-shaped.
 *
 * RUN:
 *   cv scr .../ext/mascode/tests/Live/VcDigestIdempotencyTest.php \
 *      --user=<a user with a civicrm_uf_match row>
 *
 * ⚠ Run after `cv flush` — a stale container is the usual reason a `cv scr`
 * result disagrees with the source. See tests/Security/CheckinEntitlementTest.php.
 *
 * WRITES: every activity this creates is made inside a transaction that is
 * ALWAYS rolled back. Nothing survives the run, which is what makes it safe
 * against production. It sends no email — it exercises the CHECK, never the
 * mailer.
 *
 * Exit 0 = all pass; non-zero = at least one failure.
 */

use Civi\Mascode\Service\VcDigestMailer;

class D
{
    public static array $failures = [];
    public static int $passes = 0;
}

function note(string $m): void
{
    echo $m . "\n";
}

function check(string $name, bool $got, bool $want): void
{
    if ($got === $want) {
        D::$passes++;
        echo "  pass: {$name}\n";
        return;
    }
    D::$failures[] = $name;
    echo "  FAIL: {$name} — expected " . var_export($want, true) . ", got " . var_export($got, true) . "\n";
}

$case = \Civi\Api4\CiviCase::get(false)
    ->addSelect('id')
    ->addWhere('case_type_id:name', '=', 'project')
    ->addWhere('is_deleted', '=', false)
    ->setLimit(1)->execute()->first();

if (!$case) {
    note('ABORT: no project case to test against.');
    exit(1);
}

$caseId = (int) $case['id'];
$round = '2099-01';   // A round no real digest will ever have used.

// The two ids the ASSERTIONS are about are arbitrary and never have to exist —
// they only ever appear inside a marker string. 941 / 9411 are chosen for the
// LIKE-prefix relationship between them, which is the property check 5 tests.
$vc = 941;
$otherVc = 9411;

// The one id that must be REAL is the activity's source contact, because
// Activity::create writes a foreign key. Resolved at run time rather than
// hard-coded, so this script is portable to production — where a literal id
// may be a different contact, or none, and the create would fatal.
$sourceContact = (int) (\CRM_Core_Session::getLoggedInContactID() ?: 0);
if (!$sourceContact) {
    note('ABORT: no logged-in contact to own the fixture activity. Pass --user=<a login with a uf_match row>.');
    exit(1);
}

note("Using case #{$caseId}, round {$round}, marker contacts {$vc} / {$otherVc}, source contact #{$sourceContact}");
note('');

check('before anything, not already sent', VcDigestMailer::alreadySentThisRound($vc, [$caseId], $round), false);

$tx = new \CRM_Core_Transaction();
try {
    \Civi\Api4\Activity::create(false)
        ->addValue('activity_type_id:name', 'Sent Automated Email')
        ->addValue('status_id:name', 'Completed')
        ->addValue('case_id', $caseId)
        ->addValue('source_contact_id', $sourceContact)
        // target_contact_id too, so the fixture is shaped like what
        // recordOnCase() actually writes rather than merely close to it.
        ->addValue('target_contact_id', [$sourceContact])
        ->addValue('subject', VcDigestMailer::activitySubject($round))
        ->addValue('details', VcDigestMailer::marker($vc, $round) . "\nbody")
        ->execute();

    check('same VC, same round -> skipped', VcDigestMailer::alreadySentThisRound($vc, [$caseId], $round), true);
    check('same VC, different round -> NOT skipped', VcDigestMailer::alreadySentThisRound($vc, [$caseId], '2099-02'), false);
    // THE one the first version got wrong.
    check('DIFFERENT VC, same round -> NOT skipped', VcDigestMailer::alreadySentThisRound($otherVc, [$caseId], $round), false);
    check('different case -> NOT skipped', VcDigestMailer::alreadySentThisRound($vc, [$caseId + 999999], $round), false);
    check('empty case list -> NOT skipped', VcDigestMailer::alreadySentThisRound($vc, [], $round), false);
} finally {
    $tx->rollback();
    $tx->commit();
}

check('after rollback, not already sent', VcDigestMailer::alreadySentThisRound($vc, [$caseId], $round), false);

note('');
if (D::$failures) {
    note(sprintf('RED — %d passed, %d FAILED', D::$passes, count(D::$failures)));
    foreach (D::$failures as $f) {
        note('  * ' . $f);
    }
    exit(1);
}
note(sprintf('GREEN — %d assertions passed.', D::$passes));
exit(0);
