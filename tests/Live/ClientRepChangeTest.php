<?php

/**
 * VC project forms — client-representative change behaviour.
 *
 * WHY THIS IS A `cv scr` SCRIPT, NOT A PHPUnit TEST:
 * The same reason as tests/Security/ (see docs/TESTING.md). The behaviour under
 * test is an Afform submission end to end — core's ContactDedupe behavior, the
 * entity sort, processGenericEntity(), saveJoins() and this extension's
 * AfformSubmitSubscriber all have to run in one live, fully-bootstrapped
 * CiviCRM. CI has no CiviCRM at all, and the PHPUnit Integration suite
 * self-skips in this WP-buildkit site.
 *
 * RUN (from anywhere inside the buildkit site, so cv can find the settings file):
 *   cd /home/brian/buildkit/build/masdemo/web/wp-content/uploads/civicrm/ext/mascode
 *   cv scr tests/Live/ClientRepChangeTest.php --user=brian.flett@masadvise.org
 * Exit code 0 = all pass; non-zero = at least one failure (red).
 *
 * Run `cv flush` first after changing the forms or the subscriber, or the old
 * definitions are still cached and this tests the previous behaviour.
 *
 * WHAT IT GUARDS
 * On afformMASProjectDefinitionVC and afformProjectCloseVCFeedback the VC may
 * maintain the client representative. MAS's rule:
 *
 *   - email changed, name unchanged  -> update that person's contact in place;
 *   - first or last name changed     -> a different human now holds the role, so
 *                                       create a new contact, end the outgoing
 *                                       rep's "Case Client Rep is" role on this
 *                                       case, and give the incoming one that
 *                                       role plus an "Employee of" link to the
 *                                       client organisation.
 *
 * THE ASSERTION MOST LIKELY TO BE BROKEN BY A REFACTOR is neither of those. It
 * is "outgoing rep KEPT their own email row". The browser echoes the prefilled
 * Email join back with the OUTGOING rep's email-row id in it, and
 * Afform::saveJoins() only replaces that id when loadJoins() finds an existing
 * row — which for a brand-new contact it never does. The id then reaches
 * Email::replace(), whose BasicReplaceAction merges the where clause
 * (contact_id = the NEW contact) into the record as a default and MOVES the row.
 * Measured on dev: the outgoing rep is left with zero email addresses.
 * AfformSubmitSubscriber::onClientRepPreProcess() strips join ids whenever it
 * strips the contact id, and that pairing is what this file pins.
 *
 * SCENARIOS (each on its own independent case, so none can mask another):
 *   A  email changes, name does not      -> in-place update, role unchanged
 *   B  last name changes                 -> new contact, role moves, emails split
 *   C  name kept, email cleared          -> existing address survives
 *   D  case has no rep, fieldset blank   -> nothing created
 *   E  case has no rep, VC supplies one  -> associated, plus Employee of
 *   F  first name only changes           -> new contact, role moves
 *
 * C, D, E and F exist because every failure mode in this feature is a silent
 * `return` rather than an exception, so an untested path has no other detector.
 * D and E are not hypothetical: 23 of 154 Active project cases on the 2026-05-30
 * dev clone carry no active client rep, which is why the fields are optional.
 *
 * FIXTURES: builds four independent throwaway project cases (org + VC + case,
 * with or without a client rep) and hard-deletes everything at the end. It also
 * sweeps anything a previously crashed run left behind, matched on the
 * 'clireptest' name marker. It does NOT touch existing data. Run it against dev,
 * where outbound mail is caught by MailHog — submitting these forms sends
 * confirmation email.
 *
 * NOTE the contact_sub_type on the VC fixture: 'Case Coordinator is' declares
 * contact_sub_type_a = MAS_Rep, so a plain Individual is rejected with
 * "Invalid Relationship".
 */

/**
 * Static tracker — a plain `$failures` variable does NOT work here: under
 * `cv scr` the script body runs inside a method, so `global` in a helper
 * function refers to a different (always empty) variable and the summary line
 * reports success no matter what the assertions did.
 */
class T
{
    public static array $failures = [];
    public static int $passes = 0;
}

function note(string $s): void
{
    echo $s . "\n";
}

function pass(string $label): void
{
    T::$passes++;
    note("  [PASS] $label");
}

function fail(string $label, string $detail): void
{
    T::$failures[] = "$label — $detail";
    note("  [FAIL] $label — $detail");
}

function check(string $label, $got, $want): void
{
    if ($got === $want) {
        pass($label);
        return;
    }
    fail($label, sprintf('got %s, want %s', var_export($got, true), var_export($want, true)));
}

// Lowercase: CiviCRM normalises email addresses on save, so a mixed-case marker
// makes every email assertion fail for a reason that has nothing to do with the
// behaviour under test.
$stamp = 'clireptest' . time();

// --- Sweep fixtures stranded by an earlier crashed run -----------------------

foreach (
    \Civi\Api4\Contact::get(false)
        ->addSelect('id')
        ->addClause('OR', ['last_name', 'LIKE', '%clireptest%'], ['organization_name', 'LIKE', '%clireptest%'])
        ->execute() as $stale
) {
    \Civi\Api4\Contact::delete(false)->addWhere('id', '=', $stale['id'])->setUseTrash(false)->execute();
}
foreach (
    \Civi\Api4\CiviCase::get(false)
        ->addSelect('id')->addWhere('subject', 'LIKE', '%clireptest%')->execute() as $stale
) {
    \Civi\Api4\CiviCase::delete(false)->addWhere('id', '=', $stale['id'])->execute();
}

// --- Fixtures ----------------------------------------------------------------

$createdContacts = [];
$createdCases = [];

$mk = function (string $type, array $vals) use (&$createdContacts): int {
    $create = \Civi\Api4\Contact::create(false)->addValue('contact_type', $type);
    foreach ($vals as $k => $v) {
        $create->addValue($k, $v);
    }
    $id = (int) $create->execute()->first()['id'];
    $createdContacts[] = $id;
    return $id;
};

$relTypes = \Civi\Api4\RelationshipType::get(false)
    ->addSelect('id', 'name_a_b')
    ->addWhere('name_a_b', 'IN', ['Case Client Rep is', 'Case Coordinator is', 'Employee of'])
    ->execute()
    ->indexBy('name_a_b');
$repType = (int) $relTypes['Case Client Rep is']['id'];
$coordType = (int) $relTypes['Case Coordinator is']['id'];
$employeeType = (int) $relTypes['Employee of']['id'];

/**
 * Build one independent project case. $withRep controls whether it starts with
 * an active client rep — cases WITHOUT one are ~15% of Active projects on the
 * 2026-05-30 dev clone, and every failure mode in this feature is a silent
 * return, so that path needs its own fixture rather than an assumption.
 *
 * @return array{case:int, org:int, rep:?int, email:?int}
 */
$makeCase = function (string $tag, bool $withRep) use ($mk, $stamp, &$createdCases, $repType, $coordType): array {
    $orgId = $mk('Organization', ['organization_name' => "Org $tag $stamp"]);
    // 'Case Coordinator is' declares contact_sub_type_a = MAS_Rep; a plain
    // Individual is rejected with "Invalid Relationship".
    $vcId = $mk('Individual', ['first_name' => 'Vee', 'last_name' => "Cee$tag$stamp", 'contact_sub_type' => ['MAS_Rep']]);

    $caseId = (int) \Civi\Api4\CiviCase::create(false)
        ->addValue('case_type_id:name', 'project')
        ->addValue('subject', "Case $tag $stamp")
        ->addValue('status_id:name', 'Active')
        ->addValue('start_date', date('Y-m-d'))
        ->addValue('contact_id', $orgId)
        ->execute()->first()['id'];
    $createdCases[] = $caseId;

    \Civi\Api4\Relationship::create(false)
        ->addValue('contact_id_a', $vcId)->addValue('contact_id_b', $orgId)
        ->addValue('relationship_type_id', $coordType)->addValue('case_id', $caseId)
        ->addValue('is_active', true)->execute();

    $repId = null;
    $emailId = null;
    if ($withRep) {
        $repId = $mk('Individual', ['first_name' => 'Olive', 'last_name' => "Outgoing$tag$stamp"]);
        $emailId = (int) \Civi\Api4\Email::create(false)
            // strtolower: CiviCRM normalises addresses on save, so an upper-case
            // tag here makes every email assertion fail for a reason unrelated to
            // the behaviour under test.
            ->addValue('contact_id', $repId)->addValue('email', strtolower("olive.$tag.$stamp") . '@example.org')
            ->addValue('is_primary', true)->addValue('location_type_id', 1)
            ->execute()->first()['id'];
        \Civi\Api4\Relationship::create(false)
            ->addValue('contact_id_a', $repId)->addValue('contact_id_b', $orgId)
            ->addValue('relationship_type_id', $repType)->addValue('case_id', $caseId)
            ->addValue('is_active', true)->execute();
    }

    return ['case' => $caseId, 'org' => $orgId, 'vc' => $vcId, 'rep' => $repId, 'email' => $emailId];
};

$a = $makeCase('A', true);   // scenarios A and B
$c = $makeCase('C', true);   // scenario C — email cleared
$d = $makeCase('D', false);  // scenarios D and E — case starts with no rep
$f = $makeCase('F', true);   // scenario F — first name only

note("Fixtures: A(case={$a['case']} rep={$a['rep']}) C(case={$c['case']}) D(case={$d['case']}, no rep) F(case={$f['case']})");

// --- Helpers -----------------------------------------------------------------

$currentRep = function (int $caseId) use ($repType): ?int {
    $r = \Civi\Api4\Relationship::get(false)
        ->addSelect('contact_id_a')
        ->addWhere('case_id', '=', $caseId)
        ->addWhere('relationship_type_id', '=', $repType)
        ->addWhere('is_active', '=', true)
        ->execute()->first();
    return $r ? (int) $r['contact_id_a'] : null;
};

$primaryEmail = function (int $contactId): ?array {
    return \Civi\Api4\Email::get(false)
        ->addSelect('id', 'email')
        ->addWhere('contact_id', '=', $contactId)
        ->addWhere('is_primary', '=', true)
        ->execute()->first() ?: null;
};

/**
 * Submit one of the two VC forms against a case.
 *
 * NOTE what the 'id' keys here do and do not do. A caller-supplied
 * Individual*.fields.id is DISCARDED by AbstractProcessor::preprocessSubmittedValues(),
 * which intersects submitted fields against the form's declared af-fields, and
 * `id` is not one of them. The ids that actually matter are resolved server-side:
 * the case from the guarded `case_id` arg, and both contacts from that case's
 * roles via autofill. They are passed anyway because that is what the real
 * browser payload contains, and the test should submit what the browser submits.
 *
 * This runs as a staff user, whom AfformPublicArgGuardSubscriber::isStaff()
 * exempts, so the arg-entitlement path is NOT exercised here. That is deliberate:
 * tests/Security/AfformPublicArgGuardTest.php covers it as a VC, and duplicating
 * it here would only produce a second, weaker copy.
 */
$submit = function (string $formName, int $caseId, ?int $vcId, array $repFields, ?array $repEmail) {
    return \Civi\Api4\Afform::submit(false)
        ->setName($formName)
        ->setArgs(['case_id' => $caseId])
        ->setValues([
            'Individual1' => [['fields' => ['id' => $vcId, 'first_name' => 'Vee', 'last_name' => 'Cee'], 'joins' => []]],
            'Individual2' => [['fields' => $repFields, 'joins' => $repEmail === null ? [] : ['Email' => [$repEmail]]]],
            'Activity1' => [['fields' => [], 'joins' => []]],
            'Case1' => [['fields' => [
                'id' => $caseId,
                // Required on the PD form; ignored by the close form.
                'Project_Definition.assistance_provided' => 'Fixture assistance',
                'Project_Definition.project_completion' => 'Fixture completion criteria',
            ], 'joins' => []]],
        ])
        ->execute();
};

try {
    // --- Scenario A: email only ----------------------------------------------

    note('');
    note('SCENARIO A — email changes, name does not (expect in-place update, role unchanged)');

    $submit(
        'afformMASProjectDefinitionVC',
        $a['case'],
        $a['vc'],
        ['id' => $a['rep'], 'first_name' => 'Olive', 'last_name' => "OutgoingA$stamp"],
        ['id' => $a['email'], 'email' => "olive.new.$stamp@example.org", 'is_primary' => true, 'location_type_id' => 1]
    );

    check('A: case rep is still the original contact', $currentRep($a['case']), $a['rep']);
    $email = $primaryEmail($a['rep']);
    check('A: email updated in place', $email['email'] ?? null, "olive.new.$stamp@example.org");
    check('A: the same email row was reused', (int) ($email['id'] ?? 0), $a['email']);
    check(
        'A: no second contact was created',
        \Civi\Api4\Contact::get(false)->addWhere('last_name', '=', "OutgoingA$stamp")
            ->selectRowCount()->execute()->count(),
        1
    );

    // --- Scenario B: last name change ----------------------------------------

    note('');
    note('SCENARIO B — last name changes (expect a NEW contact and the role to move)');

    $submit(
        'afformProjectCloseVCFeedback',
        $a['case'],
        $a['vc'],
        ['id' => $a['rep'], 'first_name' => 'Ingrid', 'last_name' => "Incoming$stamp"],
        ['id' => $a['email'], 'email' => "ingrid.$stamp@example.org", 'is_primary' => true, 'location_type_id' => 1]
    );

    $newRepId = $currentRep($a['case']);
    if ($newRepId) {
        $createdContacts[] = $newRepId;
    }
    note('  new active rep contact id: ' . var_export($newRepId, true));

    check('B: a different contact now holds the role', ($newRepId !== null && $newRepId !== $a['rep']), true);

    $incoming = \Civi\Api4\Contact::get(false)
        ->addSelect('first_name', 'last_name')->addWhere('id', '=', (int) $newRepId)->execute()->first();
    check('B: incoming contact first name', $incoming['first_name'] ?? null, 'Ingrid');
    check('B: incoming contact last name', $incoming['last_name'] ?? null, "Incoming$stamp");

    $outgoingRole = \Civi\Api4\Relationship::get(false)
        ->addSelect('is_active', 'end_date')
        ->addWhere('contact_id_a', '=', $a['rep'])
        ->addWhere('case_id', '=', $a['case'])
        ->addWhere('relationship_type_id', '=', $repType)
        ->execute()->first();
    check('B: outgoing rep role deactivated', (bool) ($outgoingRole['is_active'] ?? true), false);
    check('B: outgoing rep role end-dated', ($outgoingRole['end_date'] ?? null) !== null, true);

    check(
        'B: incoming rep has Employee of the client organisation',
        \Civi\Api4\Relationship::get(false)
            ->addWhere('contact_id_a', '=', (int) $newRepId)
            ->addWhere('contact_id_b', '=', $a['org'])
            ->addWhere('relationship_type_id', '=', $employeeType)
            ->addWhere('is_active', '=', true)
            ->selectRowCount()->execute()->count(),
        1
    );

    // The join-id assertions. See the file docblock — without the strip in
    // onClientRepPreProcess() the outgoing rep ends up with zero email rows.
    $outgoingEmail = $primaryEmail($a['rep']);
    check('B: outgoing rep KEPT their own email row', (int) ($outgoingEmail['id'] ?? 0), $a['email']);
    check('B: outgoing rep email address untouched', $outgoingEmail['email'] ?? null, "olive.new.$stamp@example.org");

    $incomingEmail = $primaryEmail((int) $newRepId);
    check(
        'B: incoming rep got a NEW email row',
        ($incomingEmail !== null && (int) $incomingEmail['id'] !== $a['email']),
        true
    );
    check('B: incoming rep email address', $incomingEmail['email'] ?? null, "ingrid.$stamp@example.org");

    // --- Scenario C: name kept, email cleared --------------------------------
    // A VC who does not know the rep's address, or who reads blank as "no
    // change". The join allows update AND delete, so an unguarded empty value
    // either blanks the row or triggers Email::delete over the where clause.
    // Blank must mean "leave it alone".

    note('');
    note('SCENARIO C — name kept, email cleared (expect the existing address to survive)');

    $submit(
        'afformMASProjectDefinitionVC',
        $c['case'],
        $c['vc'],
        ['id' => $c['rep'], 'first_name' => 'Olive', 'last_name' => "OutgoingC$stamp"],
        ['id' => $c['email'], 'email' => '', 'is_primary' => true, 'location_type_id' => 1]
    );

    check('C: case rep unchanged', $currentRep($c['case']), $c['rep']);
    $cEmail = $primaryEmail($c['rep']);
    check('C: email row still exists', (int) ($cEmail['id'] ?? 0), $c['email']);
    check('C: email address NOT blanked', $cEmail['email'] ?? null, strtolower("olive.C.$stamp") . '@example.org');

    // --- Scenario D: case with no rep, blank fieldset -------------------------

    note('');
    note('SCENARIO D — case has no client rep and the VC leaves the fieldset blank (expect nothing created)');

    $contactsBefore = \Civi\Api4\Contact::get(false)->selectRowCount()->execute()->count();

    $submit(
        'afformMASProjectDefinitionVC',
        $d['case'],
        $d['vc'],
        ['first_name' => '', 'last_name' => ''],
        ['email' => '', 'is_primary' => true, 'location_type_id' => 1]
    );

    check('D: still no client rep on the case', $currentRep($d['case']), null);
    check(
        'D: no contact was created',
        \Civi\Api4\Contact::get(false)->selectRowCount()->execute()->count(),
        $contactsBefore
    );

    // --- Scenario E: case with no rep, VC fills one in ------------------------

    note('');
    note('SCENARIO E — case has no client rep and the VC supplies one (expect it associated)');

    $submit(
        'afformProjectCloseVCFeedback',
        $d['case'],
        $d['vc'],
        ['first_name' => 'Nora', 'last_name' => "New$stamp"],
        ['email' => "nora.$stamp@example.org", 'is_primary' => true, 'location_type_id' => 1]
    );

    $eRepId = $currentRep($d['case']);
    if ($eRepId) {
        $createdContacts[] = $eRepId;
    }
    check('E: the case now has a client rep', $eRepId !== null, true);
    $eContact = \Civi\Api4\Contact::get(false)
        ->addSelect('last_name')->addWhere('id', '=', (int) $eRepId)->execute()->first();
    check('E: it is the submitted person', $eContact['last_name'] ?? null, "New$stamp");
    check(
        'E: new rep has Employee of the client organisation',
        \Civi\Api4\Relationship::get(false)
            ->addWhere('contact_id_a', '=', (int) $eRepId)
            ->addWhere('contact_id_b', '=', $d['org'])
            ->addWhere('relationship_type_id', '=', $employeeType)
            ->addWhere('is_active', '=', true)
            ->selectRowCount()->execute()->count(),
        1
    );

    // --- Scenario F: first name only -----------------------------------------
    // The rule is "first OR last", and only last was covered above.

    note('');
    note('SCENARIO F — first name only changes (expect a NEW contact and the role to move)');

    $submit(
        'afformMASProjectDefinitionVC',
        $f['case'],
        $f['vc'],
        ['id' => $f['rep'], 'first_name' => 'Fiona', 'last_name' => "OutgoingF$stamp"],
        ['id' => $f['email'], 'email' => "fiona.$stamp@example.org", 'is_primary' => true, 'location_type_id' => 1]
    );

    $fRepId = $currentRep($f['case']);
    if ($fRepId) {
        $createdContacts[] = $fRepId;
    }
    check('F: a different contact now holds the role', ($fRepId !== null && $fRepId !== $f['rep']), true);
    $fContact = \Civi\Api4\Contact::get(false)
        ->addSelect('first_name')->addWhere('id', '=', (int) $fRepId)->execute()->first();
    check('F: incoming contact first name', $fContact['first_name'] ?? null, 'Fiona');
    check('F: outgoing rep kept their email row', (int) ($primaryEmail($f['rep'])['id'] ?? 0), $f['email']);

} catch (\Throwable $e) {
    fail('client rep change', get_class($e) . ': ' . $e->getMessage());
} finally {
    note('');
    note('Cleanup...');
    foreach ($createdCases as $id) {
        \Civi\Api4\CiviCase::delete(false)->addWhere('id', '=', $id)->execute();
    }
    foreach (array_unique(array_filter($createdContacts)) as $id) {
        \Civi\Api4\Contact::delete(false)->addWhere('id', '=', $id)->setUseTrash(false)->execute();
    }
}

// --- Summary -----------------------------------------------------------------

note('');
if (T::$failures) {
    note('RESULT: RED — ' . count(T::$failures) . ' failure(s), ' . T::$passes . ' pass(es)');
    foreach (T::$failures as $f) {
        note("  - $f");
    }
    exit(1);
}
note('RESULT: GREEN — all ' . T::$passes . ' assertions passed');
exit(0);
