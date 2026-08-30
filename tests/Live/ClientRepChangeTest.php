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
 * FIXTURES: creates its own throwaway org, VC, client rep and project case, and
 * hard-deletes them at the end. It also sweeps anything a previously crashed run
 * left behind, matched on the 'clireptest' name marker. It does NOT touch
 * existing data. Run it against dev, where outbound mail is caught by MailHog —
 * submitting these forms sends confirmation email.
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

$orgId = $mk('Organization', ['organization_name' => "Org $stamp"]);
$vcId = $mk('Individual', ['first_name' => 'Vee', 'last_name' => "Cee$stamp", 'contact_sub_type' => ['MAS_Rep']]);
$repId = $mk('Individual', ['first_name' => 'Olive', 'last_name' => "Outgoing$stamp"]);

$repEmailId = (int) \Civi\Api4\Email::create(false)
    ->addValue('contact_id', $repId)
    ->addValue('email', "olive.$stamp@example.org")
    ->addValue('is_primary', true)
    ->addValue('location_type_id', 1)
    ->execute()->first()['id'];

$caseId = (int) \Civi\Api4\CiviCase::create(false)
    ->addValue('case_type_id:name', 'project')
    ->addValue('subject', "Case $stamp")
    ->addValue('status_id:name', 'Active')
    ->addValue('start_date', date('Y-m-d'))
    ->addValue('contact_id', $orgId)
    ->execute()->first()['id'];
$createdCases[] = $caseId;

foreach ([[$vcId, $coordType], [$repId, $repType]] as [$contactId, $typeId]) {
    \Civi\Api4\Relationship::create(false)
        ->addValue('contact_id_a', $contactId)
        ->addValue('contact_id_b', $orgId)
        ->addValue('relationship_type_id', $typeId)
        ->addValue('case_id', $caseId)
        ->addValue('is_active', true)
        ->execute();
}

note("Fixtures: org=$orgId vc=$vcId rep=$repId case=$caseId repEmailRow=$repEmailId");

// --- Helpers -----------------------------------------------------------------

$currentRep = function () use ($caseId, $repType): ?int {
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

$submit = function (string $formName, array $repFields, array $repEmail) use ($caseId, $vcId) {
    return \Civi\Api4\Afform::submit(false)
        ->setName($formName)
        ->setArgs(['case_id' => $caseId])
        ->setValues([
            'Individual1' => [['fields' => ['id' => $vcId, 'first_name' => 'Vee', 'last_name' => 'Cee'], 'joins' => []]],
            'Individual2' => [['fields' => $repFields, 'joins' => ['Email' => [$repEmail]]]],
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
        ['id' => $repId, 'first_name' => 'Olive', 'last_name' => "Outgoing$stamp"],
        ['id' => $repEmailId, 'email' => "olive.new.$stamp@example.org", 'is_primary' => true, 'location_type_id' => 1]
    );

    check('case rep is still the original contact', $currentRep(), $repId);
    $email = $primaryEmail($repId);
    check('email updated in place', $email['email'] ?? null, "olive.new.$stamp@example.org");
    check('the same email row was reused', (int) ($email['id'] ?? 0), $repEmailId);
    check(
        'no second contact was created',
        \Civi\Api4\Contact::get(false)->addWhere('last_name', '=', "Outgoing$stamp")
            ->selectRowCount()->execute()->count(),
        1
    );

    // --- Scenario B: name change ---------------------------------------------

    note('');
    note('SCENARIO B — last name changes (expect a NEW contact and the role to move)');

    $submit(
        'afformProjectCloseVCFeedback',
        ['id' => $repId, 'first_name' => 'Ingrid', 'last_name' => "Incoming$stamp"],
        ['id' => $repEmailId, 'email' => "ingrid.$stamp@example.org", 'is_primary' => true, 'location_type_id' => 1]
    );

    $newRepId = $currentRep();
    if ($newRepId) {
        $createdContacts[] = $newRepId;
    }
    note('  new active rep contact id: ' . var_export($newRepId, true));

    check('a different contact now holds the role', ($newRepId !== null && $newRepId !== $repId), true);

    $incoming = \Civi\Api4\Contact::get(false)
        ->addSelect('first_name', 'last_name')->addWhere('id', '=', (int) $newRepId)->execute()->first();
    check('incoming contact first name', $incoming['first_name'] ?? null, 'Ingrid');
    check('incoming contact last name', $incoming['last_name'] ?? null, "Incoming$stamp");

    $outgoingRole = \Civi\Api4\Relationship::get(false)
        ->addSelect('is_active', 'end_date')
        ->addWhere('contact_id_a', '=', $repId)
        ->addWhere('case_id', '=', $caseId)
        ->addWhere('relationship_type_id', '=', $repType)
        ->execute()->first();
    check('outgoing rep role deactivated', (bool) ($outgoingRole['is_active'] ?? true), false);
    check('outgoing rep role end-dated', ($outgoingRole['end_date'] ?? null) !== null, true);

    check(
        'incoming rep has Employee of the client organisation',
        \Civi\Api4\Relationship::get(false)
            ->addWhere('contact_id_a', '=', (int) $newRepId)
            ->addWhere('contact_id_b', '=', $orgId)
            ->addWhere('relationship_type_id', '=', $employeeType)
            ->addWhere('is_active', '=', true)
            ->selectRowCount()->execute()->count(),
        1
    );

    // The join-id assertions. See the file docblock — without the strip in
    // onClientRepPreProcess() the outgoing rep ends up with zero email rows.
    $outgoingEmail = $primaryEmail($repId);
    check('outgoing rep KEPT their own email row', (int) ($outgoingEmail['id'] ?? 0), $repEmailId);
    check('outgoing rep email address untouched', $outgoingEmail['email'] ?? null, "olive.new.$stamp@example.org");

    $incomingEmail = $primaryEmail((int) $newRepId);
    check(
        'incoming rep got a NEW email row',
        ($incomingEmail !== null && (int) $incomingEmail['id'] !== $repEmailId),
        true
    );
    check('incoming rep email address', $incomingEmail['email'] ?? null, "ingrid.$stamp@example.org");
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
