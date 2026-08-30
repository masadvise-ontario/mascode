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
 *   G  VC empties a PREFILLED fieldset    -> existing rep left entirely alone
 *   H  case has two distinct reps         -> handler refuses to act at all
 *   I  one name half cleared              -> incomplete edit, not a handover
 *   J  incumbent has no last name          -> filling it in completes, not replaces
 *   K  first cleared AND last changed      -> unfinishable handover: nothing written,
 *                                            name AND email both suppressed
 *   L  blank surname on file + handover    -> same, in the cell where the incumbent's
 *                                            other half is empty
 *
 * C to H exist because every failure mode in this feature is a silent `return`
 * rather than an exception, so an untested path has no other detector.
 *
 * G and D look alike and are not: only G reaches the extension's own blank
 * branch. With no rep on file (D) core's preprocessContact nulls the record at
 * priority 10 and the handler returns at its isset() guard, so D passes on CORE's
 * behaviour — which is worth having, but is not the same assertion.
 * D and E are not hypothetical: 23 of 154 Active project cases on the 2026-05-30
 * dev clone carry no active client rep, which is why the fields are optional.
 *
 * FIXTURES: builds ten independent throwaway project cases (org + VC + case,
 * with or without a client rep, one of them with two reps and one whose rep has
 * no last name) and hard-deletes everything at the end. It also
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
$g = $makeCase('G', true);   // scenario G — VC empties a PREFILLED fieldset
$h = $makeCase('H', true);   // scenario H — case with two distinct client reps
$i = $makeCase('I', true);   // scenario I — one name half cleared
$j = $makeCase('J', true);   // scenario J — incumbent has NO last name
$k = $makeCase('K', true);   // scenario K — first cleared AND last changed
$l = $makeCase('L', true);   // scenario L — blank surname on file, handover attempted

// Case J's rep starts with NO last name. Not hypothetical: 5 of the 535 active
// client reps on the 2026-05-30 dev clone have an empty last_name, and a VC
// filling one in must read as completing the record, not replacing the person.
\Civi\Api4\Contact::update(false)
    ->addWhere('id', '=', $j['rep'])->addValue('last_name', '')->execute();
// Case L's rep has the same shape as J's — blank last_name on file — but is used
// for the opposite submission: an attempted handover rather than a completion.
\Civi\Api4\Contact::update(false)
    ->addWhere('id', '=', $l['rep'])->addValue('last_name', '')->execute();

// Give case H a SECOND, distinct client rep so the ambiguity guard has something
// to refuse. No project case on the 2026-05-30 dev clone looks like this, which is
// exactly why it needs a fixture rather than an assumption.
$hSecondRep = $mk('Individual', ['first_name' => 'Second', 'last_name' => "RepH$stamp"]);
\Civi\Api4\Relationship::create(false)
    ->addValue('contact_id_a', $hSecondRep)->addValue('contact_id_b', $h['org'])
    ->addValue('relationship_type_id', $repType)->addValue('case_id', $h['case'])
    ->addValue('is_active', true)->execute();

note("Fixtures: A(case={$a['case']} rep={$a['rep']}) C(case={$c['case']}) D(case={$d['case']}, no rep) "
    . "F(case={$f['case']}) G(case={$g['case']}) H(case={$h['case']}, 2 reps) I(case={$i['case']}) "
    . "J(case={$j['case']}, rep has no last name) K(case={$k['case']}) "
    . "L(case={$l['case']}, rep has no last name)");

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
    // Send the VC's REAL name back. last_name is a submittable field on
    // Individual1, so a hard-coded 'Cee' renames the VC fixture and strips the
    // 'clireptest' marker the stranded-fixture sweep at the top of this file
    // matches on — a crashed run would then leave un-sweepable contacts behind.
    $vc = $vcId ? \Civi\Api4\Contact::get(false)
        ->addSelect('first_name', 'last_name')->addWhere('id', '=', $vcId)->execute()->first() : [];

    return \Civi\Api4\Afform::submit(false)
        ->setName($formName)
        ->setArgs(['case_id' => $caseId])
        ->setValues([
            'Individual1' => [['fields' => [
                'id' => $vcId,
                'first_name' => $vc['first_name'] ?? 'Vee',
                'last_name' => $vc['last_name'] ?? 'Cee',
            ], 'joins' => []]],
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

    // Scoped to this run's marker rather than a whole-table count, which is only
    // reliable on a quiet box and brittle by construction.
    $countOurs = function () use ($stamp): int {
        return \Civi\Api4\Contact::get(false)
            ->addClause('OR', ['last_name', 'LIKE', "%$stamp%"], ['organization_name', 'LIKE', "%$stamp%"])
            ->selectRowCount()->execute()->count();
    };
    $contactsBefore = $countOurs();

    $submit(
        'afformMASProjectDefinitionVC',
        $d['case'],
        $d['vc'],
        ['first_name' => '', 'last_name' => ''],
        ['email' => '', 'is_primary' => true, 'location_type_id' => 1]
    );

    check('D: still no client rep on the case', $currentRep($d['case']), null);
    check('D: no contact was created', $countOurs(), $contactsBefore);

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

    // --- Scenario G: VC empties a PREFILLED fieldset --------------------------
    // This is the one that reaches the extension's own blank-fieldset branch.
    // Scenario D does NOT: with no rep on file, core's preprocessContact (priority
    // 10) nulls the record first and the handler's isset() guard returns, so D
    // passes on CORE's behaviour. Here the record carries an id, preprocessContact
    // `continue`s, and the branch has to do the work.

    note('');
    note('SCENARIO G — VC empties a prefilled fieldset (expect the existing rep left entirely alone)');

    $gContactsBefore = \Civi\Api4\Contact::get(false)
        ->addWhere('last_name', 'LIKE', "%$stamp%")->selectRowCount()->execute()->count();

    $submit(
        'afformProjectCloseVCFeedback',
        $g['case'],
        $g['vc'],
        ['id' => $g['rep'], 'first_name' => '', 'last_name' => ''],
        ['id' => $g['email'], 'email' => '', 'is_primary' => true, 'location_type_id' => 1]
    );

    check('G: case rep unchanged', $currentRep($g['case']), $g['rep']);
    $gContact = \Civi\Api4\Contact::get(false)
        ->addSelect('first_name', 'last_name')->addWhere('id', '=', $g['rep'])->execute()->first();
    check('G: rep first name not blanked', $gContact['first_name'] ?? null, 'Olive');
    check('G: rep last name not blanked', $gContact['last_name'] ?? null, "OutgoingG$stamp");
    check('G: rep email row survived', (int) ($primaryEmail($g['rep'])['id'] ?? 0), $g['email']);
    check(
        'G: no contact was created',
        \Civi\Api4\Contact::get(false)->addWhere('last_name', 'LIKE', "%$stamp%")
            ->selectRowCount()->execute()->count(),
        $gContactsBefore
    );

    // --- Scenario H: a case with two distinct client reps ---------------------
    // Which of the two the form autofilled from is not knowable server-side (core
    // issues that query with no ORDER BY), so the handler must refuse to act
    // rather than guess and edit — or unseat — someone the VC was never shown.

    note('');
    note('SCENARIO H — case has two client reps (expect the handler to do nothing at all)');

    $submit(
        'afformMASProjectDefinitionVC',
        $h['case'],
        $h['vc'],
        ['id' => $h['rep'], 'first_name' => 'Renamed', 'last_name' => "RenamedH$stamp"],
        ['id' => $h['email'], 'email' => "renamed.h.$stamp@example.org", 'is_primary' => true, 'location_type_id' => 1]
    );

    $hActive = \Civi\Api4\Relationship::get(false)
        ->addWhere('case_id', '=', $h['case'])
        ->addWhere('relationship_type_id', '=', $repType)
        ->addWhere('is_active', '=', true)
        ->selectRowCount()->execute()->count();
    check('H: both client rep roles still active (neither was ended)', $hActive, 2);

    $hOriginal = \Civi\Api4\Contact::get(false)
        ->addSelect('last_name')->addWhere('id', '=', $h['rep'])->execute()->first();
    check('H: the first rep was not renamed away', $hOriginal['last_name'] ?? null, "OutgoingH$stamp");
    $hSecond = \Civi\Api4\Contact::get(false)
        ->addSelect('last_name')->addWhere('id', '=', $hSecondRep)->execute()->first();
    check('H: the second rep was untouched', $hSecond['last_name'] ?? null, "RepH$stamp");

    // --- Scenario I: one name half cleared -----------------------------------
    // A VC clears the first name to retype it and submits. The form's blurb says
    // a blank field means no change, so this must NOT read as a handover — which
    // it did until round 3: it produced a new contact with an empty first name,
    // moved the case role to it, and end-dated the real rep.

    note('');
    note('SCENARIO I — first name cleared, last name kept (expect no handover)');

    $submit(
        'afformMASProjectDefinitionVC',
        $i['case'],
        $i['vc'],
        ['id' => $i['rep'], 'first_name' => '', 'last_name' => "OutgoingI$stamp"],
        // A DIFFERENT address from the fixture's: this path SHOULD write the email
        // (nothing was being handed over), so asserting it landed is only
        // meaningful if it differs from what was already there.
        ['id' => $i['email'], 'email' => strtolower("updated.I.$stamp") . '@example.org', 'is_primary' => true, 'location_type_id' => 1]
    );

    check('I: case rep unchanged', $currentRep($i['case']), $i['rep']);
    check(
        'I: no empty-named contact was created',
        \Civi\Api4\Contact::get(false)
            ->addWhere('last_name', '=', "OutgoingI$stamp")->selectRowCount()->execute()->count(),
        1
    );
    $iContact = \Civi\Api4\Contact::get(false)
        ->addSelect('first_name')->addWhere('id', '=', $i['rep'])->execute()->first();
    check('I: existing rep first name not blanked', $iContact['first_name'] ?? null, 'Olive');
    $iRole = \Civi\Api4\Relationship::get(false)
        ->addSelect('is_active')->addWhere('contact_id_a', '=', $i['rep'])
        ->addWhere('case_id', '=', $i['case'])->addWhere('relationship_type_id', '=', $repType)
        ->execute()->first();
    check('I: existing rep role still active', (bool) ($iRole['is_active'] ?? false), true);
    // The mirror of scenario K: nothing was being handed over here, so the email
    // edit in the same submission SHOULD land.
    check(
        'I: the email edit in the same submission DID land',
        $primaryEmail($i['rep'])['email'] ?? null,
        strtolower("updated.I.$stamp") . '@example.org'
    );

    // --- Scenario L: blank surname on file, handover attempted ---------------
    // The cell round 6 found. The incumbent has no last_name, so the Rule-2 flag
    // for that half is false however different the submitted surname is. Gating
    // the email drop on those flags therefore let the incoming person's address
    // land on the incumbent — the round-5 harm, in the one population Rule 2
    // exists to protect.

    note('');
    note('SCENARIO L — incumbent has no surname and a handover is attempted (expect nothing written)');

    $submit(
        'afformProjectCloseVCFeedback',
        $l['case'],
        $l['vc'],
        ['id' => $l['rep'], 'first_name' => '', 'last_name' => "JonesL$stamp"],
        ['id' => $l['email'], 'email' => strtolower("incoming.L.$stamp") . '@example.org', 'is_primary' => true, 'location_type_id' => 1]
    );

    check('L: same contact still holds the role', $currentRep($l['case']), $l['rep']);
    $lContact = \Civi\Api4\Contact::get(false)
        ->addSelect('first_name', 'last_name')->addWhere('id', '=', $l['rep'])->execute()->first();
    check('L: surname still blank (not written)', trim((string) ($lContact['last_name'] ?? '')), '');
    check('L: first name intact', $lContact['first_name'] ?? null, 'Olive');
    check(
        'L: incumbent email NOT overwritten with the incoming address',
        $primaryEmail($l['rep'])['email'] ?? null,
        strtolower("olive.L.$stamp") . '@example.org'
    );

    // --- Scenario J: incumbent has no last name, VC supplies one -------------
    // A handover has to be detectable from a field that HAD a value to change.
    // Filling in a missing surname is completing the record; treating it as a
    // handover would create a duplicate and unseat the real rep.

    note('');
    note('SCENARIO J — incumbent has no last name, VC fills it in (expect completion, not handover)');

    $submit(
        'afformMASProjectDefinitionVC',
        $j['case'],
        $j['vc'],
        ['id' => $j['rep'], 'first_name' => 'Olive', 'last_name' => "SuppliedJ$stamp"],
        ['id' => $j['email'], 'email' => strtolower("olive.J.$stamp") . '@example.org', 'is_primary' => true, 'location_type_id' => 1]
    );

    check('J: same contact still holds the role', $currentRep($j['case']), $j['rep']);
    $jContact = \Civi\Api4\Contact::get(false)
        ->addSelect('last_name', 'display_name', 'sort_name')
        ->addWhere('id', '=', $j['rep'])->execute()->first();
    check('J: the missing last name was filled in on the SAME contact', $jContact['last_name'] ?? null, "SuppliedJ$stamp");
    // display_name/sort_name are what the VC Portal and every lifecycle email
    // show. An in-place name write that leaves them stale is a user-visible bug
    // even though the underlying column is right.
    check('J: display_name was recomputed', $jContact['display_name'] ?? null, "Olive SuppliedJ$stamp");
    check('J: sort_name was recomputed', $jContact['sort_name'] ?? null, "SuppliedJ$stamp, Olive");
    check(
        'J: no duplicate contact was created',
        \Civi\Api4\Contact::get(false)
            ->addWhere('last_name', '=', "SuppliedJ$stamp")->selectRowCount()->execute()->count(),
        1
    );

    // --- Scenario K: first cleared AND last changed --------------------------
    // The destructive shape: dropping only the blank half would rename the
    // incumbent ("Olive Smith" -> "Olive Jones") and leave her holding the role.
    // Neither half may be written.

    note('');
    note('SCENARIO K — first name cleared and last name changed (expect NO name written at all)');

    $submit(
        'afformProjectCloseVCFeedback',
        $k['case'],
        $k['vc'],
        ['id' => $k['rep'], 'first_name' => '', 'last_name' => "ChangedK$stamp"],
        // A DIFFERENT address from the fixture's, so "the email did not land" is a
        // real assertion rather than a tautology.
        ['id' => $k['email'], 'email' => strtolower("incoming.K.$stamp") . '@example.org', 'is_primary' => true, 'location_type_id' => 1]
    );

    check('K: same contact still holds the role', $currentRep($k['case']), $k['rep']);
    $kContact = \Civi\Api4\Contact::get(false)
        ->addSelect('first_name', 'last_name')->addWhere('id', '=', $k['rep'])->execute()->first();
    check('K: incumbent was NOT renamed', $kContact['last_name'] ?? null, "OutgoingK$stamp");
    check('K: incumbent first name intact', $kContact['first_name'] ?? null, 'Olive');
    check(
        'K: no contact was created with the submitted surname',
        \Civi\Api4\Contact::get(false)
            ->addWhere('last_name', '=', "ChangedK$stamp")->selectRowCount()->execute()->count(),
        0
    );
    // The half of this submission that used to still land. Suppressing the name
    // but writing the email leaves the incumbent holding the role with the
    // INCOMING person's address — every case email then reaches the wrong person
    // and the incumbent's own address is gone.
    $kEmail = $primaryEmail($k['rep']);
    check('K: incumbent email row unchanged', (int) ($kEmail['id'] ?? 0), $k['email']);
    check(
        'K: incumbent email address NOT overwritten',
        $kEmail['email'] ?? null,
        strtolower("olive.K.$stamp") . '@example.org'
    );

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
