<?php

/**
 * Afform public-form prefill guard — authenticated entitlement assertions.
 *
 * Task: #159 (anonymous Afform.prefill leaked case/contact data).
 * Guard: Civi/Mascode/Event/AfformPublicArgGuardSubscriber.php
 * Policy: Civi/Mascode/Security/AfformArgPolicy.php
 *
 * WHY THIS IS A `cv scr` SCRIPT, NOT A PHPUnit TEST:
 * Same reason as CaseDetailAccessTest.php in this directory — the PHPUnit
 * Integration suite self-skips under WP-buildkit because civicrm_initialize()
 * never loads, so security-critical behaviour is asserted inside fully
 * bootstrapped live CiviCRM instead. The pure filtering rules ARE unit tested
 * and do run in CI: tests/Unit/Security/AfformArgPolicyTest.php.
 *
 * WHAT IT GUARDS:
 * On the seven `is_public` + `*always allow*` MAS client forms, every entity is
 * declared security="FBAC", so reads run with checkPermissions => FALSE and the
 * form's configuration is the only limit. Whoever chooses `args.case_id` therefore
 * chooses which case is returned. The guard requires each caller-supplied id to
 * be justified, reusing the VC Portal's documented entitlement predicate: a case
 * is entitled when it is in the Sent-for-Assignment pool, or when the visitor is
 * one of its active Case Coordinators (SavedSearch_Case_Details_VC.mgd.php).
 *
 * This script asserts the AUTHENTICATED half — that an entitled VC keeps the
 * access the VC Portal depends on, and an unentitled one is refused. The
 * ANONYMOUS half cannot be asserted in-process, because `cv scr` always runs as
 * some user; it is asserted over real HTTP by the sibling script
 * afform-prefill-anon-probe.sh, which reproduces the original exploit.
 *
 * RUN (must be a VC / non-staff login — see the staff check below):
 *   cv scr .../ext/mascode/tests/Security/AfformPublicArgGuardTest.php \
 *      --user=<a VC's WordPress user_login>
 *
 * Discover a usable VC login:
 *   cv api4 RelationshipCache.get '{"select":["near_contact_id","case_id"], \
 *     "where":[["near_relation:name","=","Case Coordinator is"], \
 *              ["is_active","=",true],["case_id","IS NOT EMPTY"]],"limit":5}'
 *   then look up UFMatch.uf_name for that contact.
 *
 * Exit code 0 = all pass; non-zero = at least one failure (red).
 *
 * READ-ONLY: this script only calls Afform.prefill, which loads and returns
 * data. It creates, updates and deletes nothing, so unlike CaseDetailAccessTest
 * it needs no transaction and can be run safely against any environment.
 */

use Civi\Api4\RelationshipCache;

/** A guarded form whose Case1 fieldset autofills from `case_id`. */
const FORM_CASE = 'afformProjectCloseVCFeedback';

/** A guarded form whose Individual1 fieldset autofills from `contact_id`. */
const FORM_CONTACT = 'afformProjectCloseClientFeedback';

/**
 * Static tracker — `global` does NOT reach the script's top-level scope under
 * `cv scr`, because the body runs inside a method.
 */
class G
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
    G::$failures[] = "$name — $why";
    echo "  FAIL: $name — $why\n";
}

function pass(string $name): void
{
    G::$passes++;
    echo "  pass: $name\n";
}

/**
 * Run a whole-form prefill exactly as the browser's AJAX call does, and report
 * which fieldsets came back carrying a real record.
 *
 * @return array<string,int> afform entity name => loaded record id
 */
function prefilled(string $formName, array $args): array
{
    $result = civicrm_api4('Afform', 'prefill', [
        'name' => $formName,
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

/** Assert the id was honoured — the VC Portal path must keep working. */
function assertLoaded(string $name, string $form, array $args, string $entity, int $expectedId): void
{
    $loaded = prefilled($form, $args);
    if (($loaded[$entity] ?? null) === $expectedId) {
        pass($name);
        return;
    }
    fail($name, sprintf(
        'expected %s to load #%d, got %s',
        $entity,
        $expectedId,
        $loaded ? json_encode($loaded) : '(nothing)'
    ));
}

/** Assert the id was dropped — nothing at all may come back for that entity. */
function assertBlocked(string $name, string $form, array $args, string $entity): void
{
    $loaded = prefilled($form, $args);
    if (!isset($loaded[$entity])) {
        pass($name);
        return;
    }
    fail($name, sprintf('LEAK — %s loaded #%d', $entity, $loaded[$entity]));
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
    // Not a soft skip. The guard deliberately exempts staff, so running as staff
    // makes every assertion below pass without exercising anything — the exact
    // shape of a test that looks green and guards nothing.
    note('ABORT: running as a STAFF user (holds: ' . implode(', ', $held) . ').');
    note('The guard exempts staff, so this run would pass vacuously.');
    note('Re-run with --user=<a VC WordPress user_login>.');
    exit(1);
}

note("Running as contact #$me (non-staff). Discovering fixtures…");

// --- Discover fixtures dynamically (robust to data churn) ------------------

function coordinatedCaseIds(int $contactId): array
{
    $rows = RelationshipCache::get(false)
        ->addSelect('case_id')
        ->addWhere('near_contact_id', '=', $contactId)
        ->addWhere('near_relation:name', '=', 'Case Coordinator is')
        ->addWhere('is_active', '=', true)
        ->addWhere('case_id', 'IS NOT EMPTY')
        ->execute()->getArrayCopy();
    return array_values(array_unique(array_column($rows, 'case_id')));
}

function poolCaseIds(): array
{
    $rows = \Civi\Api4\CiviCase::get(false)
        ->addSelect('id')
        ->addWhere('status_id:name', '=', 'Sent for Assignment')
        ->addWhere('is_deleted', '=', false)
        ->execute()->getArrayCopy();
    return array_column($rows, 'id');
}

$mine = coordinatedCaseIds($me);
$pool = poolCaseIds();

// A case I coordinate that is NOT in the pool — isolates the coordinator branch.
$ownCase = null;
foreach ($mine as $cid) {
    if (!in_array($cid, $pool, true)) {
        $ownCase = (int) $cid;
        break;
    }
}

// A pool case I do NOT coordinate — isolates the pool branch.
$poolCase = null;
foreach ($pool as $cid) {
    if (!in_array($cid, $mine, true)) {
        $poolCase = (int) $cid;
        break;
    }
}

// Somebody else's case: not mine, not pooled. This is the enumeration target.
$othersCase = null;
$rows = \Civi\Api4\CiviCase::get(false)
    ->addSelect('id')
    ->addWhere('is_deleted', '=', false)
    ->setLimit(0)->execute()->getArrayCopy();
foreach (array_column($rows, 'id') as $cid) {
    if (!in_array($cid, $mine, true) && !in_array($cid, $pool, true)) {
        $othersCase = (int) $cid;
        break;
    }
}

// Any contact that is not me — the contact_id enumeration target.
$otherContact = null;
$contacts = \Civi\Api4\Contact::get(false)
    ->addSelect('id')
    ->addWhere('is_deleted', '=', false)
    ->addWhere('contact_type', '=', 'Individual')
    ->addWhere('id', '!=', $me)
    ->setLimit(1)->execute()->first();
$otherContact = $contacts['id'] ?? null;

note(sprintf(
    '  own=%s pool=%s others=%s otherContact=%s',
    $ownCase ?? '-',
    $poolCase ?? '-',
    $othersCase ?? '-',
    $otherContact ?? '-'
));
note('');

// --- The entitled paths: these MUST keep working ---------------------------

note('ENTITLED (VC Portal case-details buttons pass #?case_id=N in the URL):');

if ($ownCase) {
    assertLoaded(
        "case I coordinate (#$ownCase) still prefills",
        FORM_CASE,
        ['case_id' => $ownCase],
        'Case1',
        $ownCase
    );
} else {
    fail('case I coordinate still prefills', 'no non-pool coordinated case found for this contact');
}

if ($poolCase) {
    assertLoaded(
        "Sent-for-Assignment pool case (#$poolCase) still prefills",
        FORM_CASE,
        ['case_id' => $poolCase],
        'Case1',
        $poolCase
    );
} else {
    note('  skip: no pool case available that this contact does not coordinate');
}

// contact_id is refused even for your OWN record. It looks like a harmless
// exception and is not: on afformMASRCSForm the `relationship:` autofills walk
// self -> employer organisation -> that organisation's President and Executive
// Director, so a self id yields other people's contact details. Nothing in MAS
// passes contact_id in a URL, and token-supplied values bypass the guard
// entirely, so refusing it costs nothing.
assertBlocked(
    "my own contact record (#$me) is refused — no URL flow needs it",
    FORM_CONTACT,
    ['contact_id' => $me],
    'Individual1'
);

// --- The unentitled paths: these MUST be refused ---------------------------

note('');
note('UNENTITLED (the enumeration the guard exists to stop):');

if ($othersCase) {
    assertBlocked(
        "someone else's case (#$othersCase) is refused",
        FORM_CASE,
        ['case_id' => $othersCase],
        'Case1'
    );
} else {
    note('  skip: every case is either coordinated by this contact or pooled');
}

if ($otherContact) {
    assertBlocked(
        "another contact (#$otherContact) is refused",
        FORM_CONTACT,
        ['contact_id' => $otherContact],
        'Individual1'
    );

    // The relationship autofills are the amplifier: on afformMASRCSForm one
    // contact id walked out to the employer organisation and then to that
    // organisation's President and Executive Director. Nothing may come back.
    $loaded = prefilled('afformMASRCSForm', ['contact_id' => $otherContact]);
    if ($loaded) {
        fail(
            'RCS relationship walk is refused',
            'LEAK — ' . json_encode($loaded)
        );
    } else {
        pass('RCS relationship walk is refused');
    }
} else {
    note('  skip: no other Individual contact found');
}

// A non-numeric / array id must be refused rather than coerced.
assertBlocked(
    'array-valued case_id is refused',
    FORM_CASE,
    ['case_id' => [$othersCase ?? 1, $ownCase ?? 2]],
    'Case1'
);

// --- The join/entity fill modes: no legitimate caller on these forms -------
//
// These load a record from arbitrary caller-supplied field values with no id
// and no scoping to a parent record, so none of the five guarded names appears
// and key filtering cannot see them. Anonymously this returned a real client
// street address. Blocked wholesale, which is safe because no MAS public form
// carries an autocomplete widget to drive them.
note('');
note('BLOCKED FILL MODES (arbitrary field match, no id):');

foreach (
    [
        'join Address.city' => ['join', ['Organization1' => [['joins' => ['Address' => [['city' => 'Toronto']]]]]]],
        'join Email.is_primary' => ['join', ['Individual1' => [['joins' => ['Email' => [['is_primary' => true]]]]]]],
        'entity Case1.id' => ['entity', ['Case1' => [['id' => $othersCase ?? 1]]]],
    ] as $label => [$mode, $args]
) {
    $result = civicrm_api4('Afform', 'prefill', [
        'name' => 'afformMASRCSForm',
        'fillMode' => $mode,
        'args' => $args,
    ]);

    // Join records come back under `joins`, not `fields` — checking only
    // `fields` is how a join-mode leak reads as a clean pass.
    $found = [];
    foreach ($result as $item) {
        foreach ($item['values'] ?? [] as $row) {
            if (!empty($row['fields']['id'])) {
                $found[] = $item['name'] . '#' . $row['fields']['id'];
            }
            foreach ($row['joins'] ?? [] as $joinName => $joinRows) {
                foreach ($joinRows ?? [] as $joinRow) {
                    if (!empty($joinRow['id'])) {
                        $found[] = $item['name'] . '.' . $joinName . '#' . $joinRow['id'];
                    }
                }
            }
        }
    }

    if ($found) {
        fail("$label is refused", 'LEAK — ' . implode(', ', $found));
    } else {
        pass("$label is refused");
    }
}

// --- Result ---------------------------------------------------------------

note('');
if (G::$failures) {
    note(sprintf('FAILED — %d passed, %d failed:', G::$passes, count(G::$failures)));
    foreach (G::$failures as $f) {
        note("  - $f");
    }
    exit(1);
}
note(sprintf('OK — %d assertions passed.', G::$passes));
exit(0);
