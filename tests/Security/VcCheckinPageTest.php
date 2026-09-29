<?php

/**
 * The per-VC check-in page (afformMASVcCheckin) — the live half.
 *
 * Subscriber: Civi/Mascode/Event/VcCheckinPageSubscriber.php
 * Ticket: docs/plans/completion-signoff-tickets.md P1-8.
 * Plan: docs/plans/completion-signoff-p1-8-per-vc-checkin-page.md.
 *
 * RUN AS A NON-STAFF VC who currently coordinates at least one eligible
 * project (see ang/README.md for how to find one):
 *
 *   cv scr tests/Security/VcCheckinPageTest.php --user=<a VC login>
 *
 * Staff pass D10 for every case, so the refusal assertions would pass
 * vacuously; the script aborts rather than allowing that. Run it after
 * `cv flush` (a stale container runs old subscriptions).
 *
 * WRITES: every submit runs inside a transaction that is ALWAYS rolled back,
 * including the one expected to succeed, so no check-in and no Completion email
 * survives the run (the "allowed" case answers "not complete", which sends
 * nothing anyway). Everything else is read-only.
 *
 * Exit code 0 = all pass; non-zero = at least one failure.
 */

use Civi\Mascode\Service\VcDigestRunner;

const FORM = 'afformMASVcCheckin';
const COMPLETE = 'Monthly_Project_Checkin.is_complete';

class R
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
    R::$failures[] = "$name — $why";
    echo "  FAIL: $name — $why\n";
}

function pass(string $name): void
{
    R::$passes++;
    echo "  pass: $name\n";
}

function checkinCount(array $caseIds): int
{
    return \Civi\Api4\Activity::get(false)
        ->addSelect('id')
        ->addWhere('case_id', 'IN', $caseIds ?: [0])
        ->addWhere('activity_type_id:name', '=', 'Monthly Project Check-in')
        ->execute()
        ->count();
}

/**
 * Submit inside an always-rolled-back transaction.
 *
 * @return array{threw:?string, created:int}
 */
function submitRolledBack(array $rows, array $watchCases): array
{
    $tx = new \CRM_Core_Transaction();
    $before = checkinCount($watchCases);
    $threw = null;
    try {
        civicrm_api4('Afform', 'submit', ['name' => FORM, 'args' => [], 'values' => ['Activity1' => $rows]]);
    } catch (\Throwable $e) {
        $threw = get_class($e) . ': ' . $e->getMessage();
    }
    $created = checkinCount($watchCases) - $before;
    $tx->rollback();
    $tx->commit();
    return ['threw' => $threw, 'created' => $created];
}

// --- Fixtures ----------------------------------------------------------------

$me = (int) (\CRM_Core_Session::getLoggedInContactID() ?: 0);
if (!$me) {
    note('ABORT: no logged-in contact. Run with --user=<a VC login>.');
    exit(2);
}
foreach (['administer CiviCRM', 'edit all contacts'] as $permission) {
    if (\CRM_Core_Permission::check($permission)) {
        note("ABORT: running as a STAFF user ($permission). Staff pass D10 for every case, so this would pass vacuously.");
        exit(2);
    }
}

$eligible = VcDigestRunner::groupByCoordinator(
    VcDigestRunner::selectEligibleProjects(date('Y-m-d'))['projects']
);
$mine = array_map(static fn($p) => (int) $p['case_id'], $eligible['by_vc'][$me]['projects'] ?? []);
$others = [];
foreach ($eligible['by_vc'] as $vc => $group) {
    if ((int) $vc === $me) {
        continue;
    }
    foreach ($group['projects'] as $p) {
        if (!in_array((int) $p['case_id'], $mine, true)) {
            $others[] = (int) $p['case_id'];
        }
    }
}
if (!$mine || !$others) {
    note('ABORT: could not discover both an eligible project this VC coordinates and one they do not.');
    exit(2);
}
$own = $mine[0];
$other = $others[0];
note(sprintf('VC #%d: %d eligible project(s); using own #%d and uncoordinated #%d.', $me, count($mine), $own, $other));

// --- Prefill -------------------------------------------------------------------

note('');
note('PREFILL — only my projects, never an id:');
$result = civicrm_api4('Afform', 'prefill', ['name' => FORM, 'fillMode' => 'form', 'args' => []]);
$seeded = [];
$hasId = false;
foreach ($result as $item) {
    foreach (in_array($item['name'] ?? '', ['Activity1', 'Activity2'], true) ? ($item['values'] ?? []) : [] as $row) {
        $seeded[] = (int) ($row['fields']['case_id'] ?? 0);
        $hasId = $hasId || array_key_exists('id', $row['fields'] ?? []);
    }
}
sort($seeded);
$expected = $mine;
sort($expected);
$seeded === $expected
    ? pass('prefill seeds exactly my eligible projects')
    : fail('prefill seeds exactly my eligible projects', 'got ' . json_encode($seeded) . ', expected ' . json_encode($expected));
$hasId ? fail('no seeded row carries an id', 'an id was seeded') : pass('no seeded row carries an id');

$result = civicrm_api4('Afform', 'prefill', ['name' => FORM, 'fillMode' => 'entity', 'args' => []]);
$leaked = 0;
foreach ($result as $item) {
    $leaked += count($item['values'] ?? []);
}
$leaked
    ? fail('a non-form fillMode seeds nothing', "$leaked row(s)")
    : pass('a non-form fillMode seeds nothing');

// --- Submits -------------------------------------------------------------------

note('');
note('SUBMIT — refused writes leave nothing; stale unanswered rows do not block:');
$watch = [$own, $other];

$r = submitRolledBack([
    ['fields' => ['case_id' => $own, COMPLETE => false]],
    ['fields' => ['case_id' => $other, COMPLETE => false]],
], $watch);
($r['threw'] && $r['created'] === 0)
    ? pass('an answered row tampered to an uncoordinated case refuses the whole submit')
    : fail('an answered row tampered to an uncoordinated case refuses the whole submit', json_encode($r));

$r = submitRolledBack([['fields' => [COMPLETE => false]]], $watch);
($r['threw'] && $r['created'] === 0)
    ? pass('an answered row with no case is refused')
    : fail('an answered row with no case is refused', json_encode($r));

$r = submitRolledBack([
    ['fields' => ['case_id' => $own, COMPLETE => false]],
    ['fields' => ['case_id' => $other]],
], $watch);
(!$r['threw'] && $r['created'] === 1)
    ? pass('a stale UNANSWERED row does not block my answer, and is not saved')
    : fail('a stale UNANSWERED row does not block my answer, and is not saved', json_encode($r));

$r = submitRolledBack([
    ['fields' => ['case_id' => $other, 'Monthly_Project_Checkin.vc_will_ask' => true]],
], $watch);
(!$r['threw'] && $r['created'] === 0)
    ? pass('a crafted row with vc_will_ask and no is_complete saves nothing')
    : fail('a crafted row with vc_will_ask and no is_complete saves nothing', json_encode($r));

// --- Report ----------------------------------------------------------------------

note('');
if (R::$failures) {
    note(sprintf('RED — %d passed, %d FAILED', R::$passes, count(R::$failures)));
    foreach (R::$failures as $f) {
        note('  * ' . $f);
    }
    exit(1);
}
note(sprintf('GREEN — %d assertions passed.', R::$passes));
exit(0);
