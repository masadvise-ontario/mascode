<?php

/**
 * D10 for afformMASProjectCheckin — the live half.
 *
 * Guard: Civi/Mascode/Event/CheckinCaseEntitlementSubscriber.php
 * Ticket: docs/plans/completion-signoff-tickets.md P1-2.
 * Spec: BrianPKM 3-Resources/mascode-vc-monthly-donation-digest-spec.md, D10.
 *
 * WHY THIS EXISTS SEPARATELY FROM AfformPublicArgGuardTest
 * ---------------------------------------------------------------------------
 * That script asserts the guard on CALLER-SUPPLIED args. This one asserts the
 * case that guard deliberately does not cover: an id arriving from the SIGNED
 * TOKEN. Core copies `afformArgs` out of the authx session inside
 * AbstractProcessor::_run(), which is after `civi.api.prepare` where the other
 * guard lives — so a token-supplied `case_id` reaches the form completely
 * unexamined by it, by design.
 *
 * That is fine for the seven Phase 0 forms, where the token is minted per
 * lifecycle event for the one case the email is about. It is not fine here,
 * and not because tokens can be forged — they cannot, the signature holds.
 * Because a minted link OUTLIVES the entitlement it was minted under: the
 * digest's TTL is `checksum_timeout` (60 days, D11), case roles change inside
 * that window, and a JWT attests only to what was true when it was signed.
 *
 * HOW THE TOKEN PATH IS SIMULATED
 * ---------------------------------------------------------------------------
 * By seeding `authx` on the session — which is precisely where core reads it
 * from — rather than by minting a JWT. Same injection point, same code path,
 * no crypto in the test. A test that minted a real token would additionally
 * exercise authx's signature check, which is core's and is not what is at risk
 * here.
 *
 * RUN (must be a non-staff VC login — the guard exempts staff, so a staff run
 * would pass vacuously and this script aborts rather than allowing that):
 *   cv scr .../ext/mascode/tests/Security/CheckinEntitlementTest.php \
 *      --user=<a VC's WordPress user_login>
 *
 * Discover a usable login — one line, cv will not accept a wrapped argument:
 *   cv api4 UFMatch.get '{"select":["uf_name"],"join":[["RelationshipCache AS rc","INNER",["rc.near_contact_id","=","contact_id"]]],"where":[["rc.near_relation:name","=","Case Coordinator is"],["rc.is_current","=",true],["rc.case_id","IS NOT NULL"]],"groupBy":["uf_name"],"limit":15}'
 * That list includes staff; pick one that is not. Guessing wrong is cheap —
 * this script aborts with "running as a STAFF user" rather than going green.
 *
 * ⚠ RUN THIS AFTER `cv flush`, ESPECIALLY AFTER A DEPLOY OR BRANCH SWITCH.
 *
 * What the compiled container caches is the WIRING — which subscribers exist,
 * on which events, at which priorities — not the code they run. Class bodies
 * come off disk at call time, and opcache is off under the CLI that `cv scr`
 * uses. So a stale container cannot execute an old guard BODY; it can execute
 * an old SUBSCRIPTION, and that fails in both directions:
 *
 *   - Wiring MISSING — the guard class is new to this branch, so it is absent
 *     from the cached map, the guard never fires, and you get a **false RED**
 *     with real-looking leaks. That happened on 2026-09-22: three convincing
 *     failures against a guard that was correct.
 *   - Wiring STALE BUT PRESENT — this branch changed `PRIORITY`, or removed or
 *     renamed a subscription, and the cached map still carries the old one. The
 *     script then exercises wiring that will not exist after a flush: a
 *     **false GREEN**, which is the direction that matters for a security
 *     check.
 *
 * (An earlier version of this note said a stale container could run "an old,
 * weaker guard". It cannot, and the distinction is worth keeping because an
 * operator told to suspect old code will open the guard, find it correct, and
 * conclude the warning was noise.)
 *
 * This script cannot detect either case for you; the flush is on you. A stale
 * PRIORITY specifically IS caught in CI, because
 * tests/Unit/Event/CheckinEntitlementWiringTest.php reads that constant out of
 * the source file.
 *
 * WRITES: the submit assertion runs inside a transaction that is ALWAYS rolled
 * back, so a guard that wrongly ALLOWS the write does not leave a real check-in
 * activity on a real case. The ended-role assertion does the same. Everything
 * else is read-only.
 *
 * Exit code 0 = all pass; non-zero = at least one failure.
 */

use Civi\Api4\RelationshipCache;

const FORM = 'afformMASProjectCheckin';

class C
{
    public static array $failures = [];
    public static int $passes = 0;
}

function note(string $msg): void
{
    echo $msg . "\n";
}

function fail(string $name, string $why): void
{
    C::$failures[] = "$name — $why";
    echo "  FAIL: $name — $why\n";
}

function pass(string $name): void
{
    C::$passes++;
    echo "  pass: $name\n";
}

/**
 * Put a case id where core reads a token's afformArgs from, or clear it.
 */
function seedToken(?int $caseId): void
{
    $session = \CRM_Core_Session::singleton();
    if ($caseId === null) {
        $session->set('authx', null);
        return;
    }
    $session->set('authx', ['jwt' => ['afformArgs' => ['case_id' => $caseId]]]);
}

/**
 * Whole-form prefill, as the browser's AJAX call makes it.
 *
 * @return array<string,int> afform entity name => loaded record id
 */
function prefilled(array $args): array
{
    $result = civicrm_api4('Afform', 'prefill', [
        'name' => FORM,
        'fillMode' => 'form',
        'args' => $args,
    ]);
    $loaded = [];
    foreach ($result as $item) {
        foreach ($item['values'] ?? [] as $row) {
            $id = $row['fields']['id'] ?? null;
            if ($id) {
                $loaded[$item['name']] = (int) $id;
            }
        }
    }
    return $loaded;
}

function assertLoaded(string $name, array $args, int $expectedId): void
{
    $loaded = prefilled($args);
    if (($loaded['Case1'] ?? null) === $expectedId) {
        pass($name);
        return;
    }
    fail($name, sprintf('expected Case1 to load #%d, got %s', $expectedId, $loaded ? json_encode($loaded) : '(nothing)'));
}

function assertBlocked(string $name, array $args): void
{
    $loaded = prefilled($args);
    if (!isset($loaded['Case1'])) {
        pass($name);
        return;
    }
    fail($name, sprintf('LEAK — Case1 loaded #%d', $loaded['Case1']));
}

// --- Who is running this? --------------------------------------------------

$me = (int) (\CRM_Core_Session::getLoggedInContactID() ?: 0);
if (!$me) {
    note('ABORT: no logged-in contact. Pass --user=<a VC WordPress user_login>.');
    exit(1);
}

$staffPermissions = ['administer CiviCRM', 'edit all contacts'];
$held = array_values(array_filter($staffPermissions, fn($p) => \CRM_Core_Permission::check($p)));
if ($held) {
    // Not a soft skip. The guard exempts staff, so every assertion below would
    // pass without exercising anything — the exact shape of a test that looks
    // green and guards nothing.
    note('ABORT: running as a STAFF user (holds: ' . implode(', ', $held) . ').');
    note('The guard exempts staff, so this run would pass vacuously.');
    note('Re-run with --user=<a non-staff VC WordPress user_login>.');
    exit(1);
}

note("Running as contact #$me (non-staff). Discovering fixtures…");

// --- Fixtures, discovered rather than hard-coded ---------------------------

// `is_current`, matching the GUARD. Using `is_active` here — which this
// script did until review caught it — picks a case whose coordinator role has
// already ended about seven times in ten on real data (299 of 481 active
// coordinator rows are ended), so the ENTITLED assertion below fails against a
// guard that is working perfectly. An operator reads that as "the guard is
// broken", and the deploy notes send them here to run it on PRODUCTION. A
// security check that is red most of the time gets switched off, and then the
// predicate has nothing holding it.
$rows = RelationshipCache::get(false)
    ->addSelect('case_id')
    ->addWhere('near_contact_id', '=', $me)
    ->addWhere('near_relation:name', '=', 'Case Coordinator is')
    ->addWhere('is_current', '=', true)
    ->addWhere('case_id', 'IS NOT EMPTY')
    ->execute()->getArrayCopy();
$mine = array_values(array_unique(array_column($rows, 'case_id')));

$ownCase = $mine ? (int) $mine[0] : null;

$othersCase = null;
$all = \Civi\Api4\CiviCase::get(false)
    ->addSelect('id')
    ->addWhere('is_deleted', '=', false)
    ->setLimit(0)->execute()->getArrayCopy();
foreach (array_column($all, 'id') as $cid) {
    if (!in_array($cid, $mine, true)) {
        $othersCase = (int) $cid;
        break;
    }
}

if (!$ownCase || !$othersCase) {
    note('ABORT: could not discover both a coordinated case and an uncoordinated one.');
    note(sprintf('  own=%s others=%s', $ownCase ?? '-', $othersCase ?? '-'));
    note('Without both, the entitled and refused paths cannot be told apart.');
    exit(1);
}

note(sprintf('  own=%d others=%d', $ownCase, $othersCase));
note('');

// --- The entitled path: this MUST keep working -----------------------------

note('ENTITLED — the VC opening their own project from a digest link:');
seedToken($ownCase);
assertLoaded('token-supplied case_id for a project I coordinate loads it', [], $ownCase);
seedToken(null);

note('');
note('REFUSED — a link that outlived its entitlement, or was forwarded:');

// THE assertion this file exists for. The other guard cannot make it, because
// it never sees a token-supplied id.
seedToken($othersCase);
assertBlocked('token-supplied case_id for a project I do NOT coordinate is dropped', []);
seedToken(null);

// Belt and braces: the caller-supplied form of the same attack. Covered by
// AfformPublicArgGuardSubscriber too, asserted here so that removing either
// guard fails something.
assertBlocked('caller-supplied case_id for a project I do NOT coordinate is dropped', ['case_id' => $othersCase]);

// A token for one case plus a caller-supplied id for another. Core's copy loop
// OVERWRITES caller args with token args for the same key, so this must end up
// as the token's (entitled) case rather than the caller's.
seedToken($ownCase);
assertLoaded('token id wins over a caller-supplied id for a different case', ['case_id' => $othersCase], $ownCase);
seedToken(null);

note('');
note('REFUSED — a coordinator role that has ENDED but is still flagged active:');

// THE ASSERTION THIS FILE EXISTS FOR, second only to the stale-link one.
//
// There are two ways to end a case role and only one clears `is_active`:
// endCaseRole() (the case-roles UI) sets is_active = 0 AND end_date; setting
// an end date on the Relationships tab, an import, a bulk fix, or the
// "Disable expired relationships" job not having run leaves is_active = 1. On
// the 2026-09-21 dev clone, 299 of 481 active coordinator rows are in exactly
// that state — 62% — some ended since March 2025.
//
// So this ends the running VC's OWN role on their OWN case the second way,
// inside a transaction that is always rolled back, and asserts they are then
// refused. Testing `is_active` passes this only by admitting an ex-coordinator.
$tx = new \CRM_Core_Transaction();
try {
    // `is_current`, not merely `is_active`: picking an ALREADY-ended role would
    // make the "end it" step a no-op, the fixture guard would pass because the
    // row was already in the target state, and assertBlocked would pass while
    // demonstrating nothing about ENDING a role. The assertion has to start
    // from a role that is genuinely current.
    $rel = \Civi\Api4\Relationship::get(FALSE)
        ->addSelect('id')
        ->addWhere('case_id', '=', $ownCase)
        ->addWhere('relationship_type_id:name', '=', 'Case Coordinator is')
        ->addWhere('contact_id_a', '=', $me)
        ->addWhere('is_current', '=', TRUE)
        ->setLimit(1)->execute()->first();

    if (!$rel) {
        fail('an ended coordinator role is refused', 'could not find a CURRENT coordinator relationship of mine to end');
    } else {
        // End it WITHOUT clearing is_active — the state 299 rows are in.
        \Civi\Api4\Relationship::update(FALSE)
            ->addWhere('id', '=', $rel['id'])
            ->addValue('end_date', date('Y-m-d', strtotime('-1 day')))
            ->addValue('is_active', TRUE)
            ->execute();

        $check = \Civi\Api4\RelationshipCache::get(FALSE)
            ->addSelect('is_active', 'is_current')
            ->addWhere('case_id', '=', $ownCase)
            ->addWhere('near_contact_id', '=', $me)
            ->addWhere('near_relation:name', '=', 'Case Coordinator is')
            ->setLimit(1)->execute()->first();

        if (!$check || empty($check['is_active']) || !empty($check['is_current'])) {
            // If the fixture did not land in the intended state the assertion
            // below would pass or fail for the wrong reason, which is worse
            // than not running it.
            fail(
                'an ended coordinator role is refused',
                'fixture did not reach is_active=TRUE + is_current=FALSE: ' . json_encode($check)
            );
        } else {
            seedToken($ownCase);
            assertBlocked('an ENDED (but still is_active) coordinator role is refused', []);
            seedToken(null);
        }
    }
} finally {
    $tx->rollback();
    $tx->commit();
}

note('');
note('REFUSED WRITE — a submit must throw, not file the answer against nothing:');

// Inside a transaction that is always rolled back: if the guard wrongly ALLOWS
// this, the activity it creates must not survive the test run.
$tx = new \CRM_Core_Transaction();
try {
    seedToken($othersCase);
    $threw = false;
    $created = null;
    try {
        civicrm_api4('Afform', 'submit', [
            'name' => FORM,
            'args' => [],
            'values' => [
                'Case1' => [],
                'Activity1' => [
                    ['fields' => ['Monthly_Project_Checkin.is_complete' => true]],
                ],
            ],
        ]);
    } catch (\Civi\API\Exception\UnauthorizedException $e) {
        $threw = true;
    } catch (\Throwable $e) {
        // Any other exception also stops the write, but it is not the refusal
        // this guard is supposed to produce, and the VC would see a server
        // error rather than the sentence written for them. Report it as a
        // failure so the difference is visible rather than assumed.
        fail(
            'submit against an uncoordinated project is refused',
            'stopped, but with ' . get_class($e) . ' rather than UnauthorizedException: ' . $e->getMessage()
        );
        $threw = null;
    }

    if ($threw === true) {
        pass('submit against an uncoordinated project is refused');
    } elseif ($threw === false) {
        fail('submit against an uncoordinated project is refused', 'the submit was ALLOWED');
    }
} finally {
    seedToken(null);
    $tx->rollback();
    $tx->commit();
}

// --- Report ----------------------------------------------------------------

note('');
if (C::$failures) {
    note(sprintf('RED — %d passed, %d FAILED', C::$passes, count(C::$failures)));
    foreach (C::$failures as $f) {
        note('  * ' . $f);
    }
    exit(1);
}
note(sprintf('GREEN — %d assertions passed.', C::$passes));
exit(0);
