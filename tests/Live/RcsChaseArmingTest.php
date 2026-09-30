<?php

/**
 * RCS chase arming — a manually-created Service Request must be chased too.
 *
 * WHY THIS IS A `cv scr` SCRIPT, NOT A PHPUnit TEST:
 * The same reason as tests/Live/ClientRepChangeTest.php and tests/Security/
 * (see docs/TESTING.md). The behaviour under test is two CiviRules rules arming
 * off real case events — the triggers, their conditions, the delayed-action
 * queue and the rule log all have to run in one live, fully-bootstrapped
 * CiviCRM. CI has no CiviCRM at all, and the PHPUnit Integration suite
 * self-skips in this WP-buildkit site. The CI-visible half of this feature is
 * tests/Unit/Service/RcsChaseOnCreateWiringTest.php.
 *
 * RUN (from anywhere inside the buildkit site, so cv can find the settings file):
 *   cd /home/brian/buildkit/build/masdemo/web/wp-content/uploads/civicrm/ext/mascode   # the site-side symlink to the repo
 *   cv upgrade:db          # provisions mas_lifecycle_rcs_chase_on_create (upgrade_5012)
 *   cv scr tests/Live/RcsChaseArmingTest.php --user=brian.flett@masadvise.org
 * Exit code 0 = all pass; non-zero = at least one failure (red).
 *
 * `cv upgrade:db` first is not optional, and `cv flush` is NOT a substitute:
 * the rule is a CiviRules row written by an upgrade step, not a managed entity,
 * so a flush does not create it. Without it this file aborts with exit 2 rather
 * than reporting a green it has not earned.
 *
 * WHAT IT GUARDS
 * mas_lifecycle_rcs_chase arms on a status TRANSITION into "Request RCS". Only
 * one of the two intake paths produces one:
 *
 *   - Web form. The request_for_assistance_form FormProcessor creates the case
 *     at "Ongoing"; sending the ask email later advances it (see
 *     RcsRequestStatusSubscriber). A real transition — armed 19 of 19 on prod.
 *   - CiviCRM "New Case" UI. The form has NO default status at all (no
 *     case_status option value carries is_default = 1, so the select renders a
 *     "- select Case Status -" placeholder), so the coordinator picks one and
 *     picks "Request RCS", because they send the ask email in the same sitting.
 *     The case is created AT the arming status, never transitions into it, and
 *     armed 0 of 23 on prod — on the MAJORITY intake path.
 *
 * mas_lifecycle_rcs_chase_on_create closes that on the mas_new_case trigger.
 *
 * THE ASSERTIONS THAT ARE THE POINT OF THIS FILE are A2, A3 and G4 together:
 * a manually-created SR is armed, armed with a coherent 21/42 cadence, and
 * armed by ONE cadence rather than two stacked on each other. That last part
 * matters because the coordinator does re-touch these cases (18828 got a manual
 * ask five days after an automated chase, with its 42-day chase still queued),
 * and a fix that stacked a second pair onto a live cadence would be worse than
 * the missing chase it replaced.
 *
 * WHAT IS AND IS NOT "ONCE" HERE — READ THIS BEFORE TIGHTENING A COUNT
 * The create EVENT happens once per case, but CRM_CivirulesPostTrigger_Case::
 * triggerTrigger() fires the RULE once for the base event, then once per case
 * client, then once per case role, all from that one event — so the firing
 * count is 1 through 3+ depending on how many of those exist when the trigger
 * runs, which in turn depends on whether a transaction is open. API4
 * CiviCase::create() (what these fixtures use) writes the CaseContact row after
 * the post hook and holds no transaction, so the trigger runs inline with no
 * clients visible: 1 firing. The CiviCRM "New Case" UI wraps postProcess() in a
 * transaction, so the trigger is deferred to PHASE_POST_COMMIT and the client
 * and coordinator role both exist by then: 3 firings, which is what every
 * recent UI-created service_request on the dev clone actually shows.
 *
 * So this file does NOT assert a firing count of 1 — that would pass only for
 * the fixture's API4 path and go red against the production path it exists to
 * protect. It asserts the invariants that hold on both: at least one firing,
 * a queue that is exactly two chases per firing at +21 and +42 days, and a
 * count that does not grow when the case leaves the status and comes back.
 * Duplicate SENDS from the extra firings are collapsed by
 * LifecycleMailer::findDuplicate() (same case + template within 23 hours),
 * exactly as they already are for the transition rule — which is why
 * fully-chased production cases show two "Sent Automated Email" activities and
 * not four.
 *
 * SCENARIOS (each on its own independent case, so none can mask another):
 *   A  SR created AT "Request RCS"     -> A1 status preserved
 *                                        A2 on-create rule armed
 *                                        A3 two chases per firing, at +21/+42
 *                                        A4 transition rule did NOT also arm
 *   B  SR created at "Ongoing", then   -> B1 transition rule armed (the proven
 *      transitioned to "Request RCS"         path still works — measured, not
 *                                            assumed, so A is interpretable)
 *                                        B2 on-create rule did NOT also arm
 *   D  SR created at "Ongoing", left   -> neither rule armed (no over-reach:
 *      alone                                nothing has been asked of anyone)
 *   E  SR created at "RCS Completed"   -> neither rule armed (the guard is the
 *                                        arming status, not "any new SR")
 *   F  Project created at "Awaiting VC -> neither rule armed (service_request
 *      Project Definition"                  only; the four sibling chases are
 *                                           out of scope and measured clean)
 *   G  case A re-enters the status     -> arms AGAIN, via the TRANSITION rule.
 *      (-> "RCS Completed" -> back)         A genuine second entry must still
 *                                           chase; the obvious wrong fix is a
 *                                           once-per-case latch, which would
 *                                           silently stop chasing a client who
 *                                           returned one form and was asked
 *                                           again later.
 *
 * A4 and B2 are the mutual-exclusivity pair and are the reason two rules are
 * safe rather than a double-send hazard. D, E and F exist because a rule that
 * does not match is a silent non-event, so an over-broad condition set has no
 * other detector.
 *
 * FIXTURES: independent throwaway cases built through ONE closure that varies
 * only the case type and the creation status, each with a client organisation,
 * a coordinator and a client rep. Everything is hard-deleted at the end,
 * including the delayed-action queue rows the run armed, and anything a
 * previously crashed run left behind is swept first (matched on the
 * 'srintaketest' marker). It does NOT touch existing data.
 *
 * NOTE the case roles are added AFTER the case is created, which is what the
 * coordinator actually does (case 18852: case at 14:26:36, role at 14:27:25,
 * ask email at 14:28:23) and is deliberate here: it means the on-create rule
 * arms while the case has NO client rep. That is fine and is worth pinning —
 * LifecycleEmail::processAction() resolves 'client_rep' to a contact at
 * EXECUTION time, 21 and 42 days later, not when the chase is armed.
 *
 * SAFETY: chases are delayed 21 and 42 days, so nothing sends during this run.
 * Queue rows are deleted in the `finally` block; even if that were missed,
 * CiviRules re-checks conditions at release time with fresh data
 * (ignore_condition_with_delay = 0), so an item whose case is gone is skipped
 * and consumed rather than mailed — see scripts/cleanup-orphaned-chase-queue.php.
 */

/**
 * Static tracker — a plain `$failures` variable does NOT work here: under
 * `cv scr` the script body runs inside a method, so `global` in a helper
 * function refers to a different (always empty) variable and the summary line
 * reports success no matter what the assertions did.
 */
class RcsChaseArmingT
{
    public static array $failures = [];
    public static int $passes = 0;
}

function rcsArming_note(string $s): void
{
    echo $s . "\n";
}

function rcsArming_pass(string $label): void
{
    RcsChaseArmingT::$passes++;
    rcsArming_note("  [PASS] $label");
}

function rcsArming_fail(string $label, string $detail): void
{
    RcsChaseArmingT::$failures[] = "$label — $detail";
    rcsArming_note("  [FAIL] $label — $detail");
}

function rcsArming_check(string $label, $got, $want): void
{
    if ($got === $want) {
        rcsArming_pass($label);
        return;
    }
    rcsArming_fail($label, sprintf('got %s, want %s', var_export($got, true), var_export($want, true)));
}

// --- Environment guard ------------------------------------------------------
// This script hard-deletes contacts (setUseTrash(false)) and DELETEs rows from
// civicrm_queue_item and civirule_rule_log. All of it is scoped to fixtures it
// created itself, but the blast radius if a matcher ever went wrong is real, so
// refuse to run anywhere that does not look like a development site. Override
// deliberately with MASCODE_ALLOW_LIVE_TEST=1 if your dev host is named
// differently.
$baseUrl = (string) \CRM_Utils_System::baseURL();
$looksLikeDev = (bool) preg_match('~(localhost|\.local|masdemo|127\.0\.0\.1)~i', $baseUrl);
if (!$looksLikeDev && getenv('MASCODE_ALLOW_LIVE_TEST') !== '1') {
    rcsArming_note("ABORT: this does not look like a development site (baseURL: $baseUrl).");
    rcsArming_note('       This script creates and hard-deletes fixture data. If this host really is');
    rcsArming_note('       a dev environment, re-run with MASCODE_ALLOW_LIVE_TEST=1. Nothing was created.');
    exit(2);
}

$stamp = 'srintaketest' . time();

// --- Preconditions -----------------------------------------------------------
// Look both rules up by NAME, never by id: the transition rule is id 9 on
// production and id 10 on the dev clone. A hardcoded id would make this file
// quietly test nothing.

$ruleIdByName = static function (string $name): ?array {
    $dao = \CRM_Core_DAO::executeQuery(
        "SELECT id, is_active FROM civirule_rule WHERE name = %1",
        [1 => [$name, 'String']]
    );
    if ($dao->fetch()) {
        return ['id' => (int) $dao->id, 'is_active' => (int) $dao->is_active];
    }
    return null;
};

$transition = $ruleIdByName('mas_lifecycle_rcs_chase');
$onCreate = $ruleIdByName('mas_lifecycle_rcs_chase_on_create');

foreach (['mas_lifecycle_rcs_chase' => $transition, 'mas_lifecycle_rcs_chase_on_create' => $onCreate] as $n => $row) {
    if (!$row) {
        rcsArming_note("ABORT: CiviRules rule \"$n\" does not exist in this environment.");
        rcsArming_note('       Provision both: cv upgrade:db   (or, on a fresh install,');
        rcsArming_note('       cv scr scripts/create-rcs-chase-rule.php --user=<admin>)');
        rcsArming_note('       Nothing was created.');
        exit(2);
    }
    if ($row['is_active'] !== 1) {
        rcsArming_note("ABORT: rule $n (id {$row['id']}) exists but is_active = {$row['is_active']}. Nothing was created.");
        exit(2);
    }
}
$transitionId = $transition['id'];
$onCreateId = $onCreate['id'];
rcsArming_note("Rules: mas_lifecycle_rcs_chase = id $transitionId, mas_lifecycle_rcs_chase_on_create = id $onCreateId. Both active.");

$queueName = \CRM_Civirules_Engine::QUEUE_NAME;

/** Firings of one rule recorded against one case. */
$firings = static function (int $ruleId, int $caseId): int {
    return (int) \CRM_Core_DAO::singleValueQuery(
        "SELECT COUNT(*) FROM civirule_rule_log
          WHERE rule_id = %1 AND entity_table = 'civicrm_case' AND entity_id = %2",
        [1 => [$ruleId, 'Integer'], 2 => [$caseId, 'Integer']]
    );
};

/**
 * Delayed-action queue rows armed for one case, as [id => release_time]. Same
 * best-effort extraction as scripts/cleanup-orphaned-chase-queue.php — the
 * queue stores a serialized task blob with no public accessor, so matching is
 * by pattern on purpose.
 */
$queueRows = static function (int $caseId) use ($queueName): array {
    $rows = [];
    $dao = \CRM_Core_DAO::executeQuery(
        "SELECT id, data, release_time FROM civicrm_queue_item WHERE queue_name = %1",
        [1 => [$queueName, 'String']]
    );
    while ($dao->fetch()) {
        $found = null;
        if (preg_match('/civicrm_case.*?"id";s:\d+:"(\d+)"/s', $dao->data, $m)) {
            $found = (int) $m[1];
        } elseif (preg_match('/s:7:"case_id";s:\d+:"(\d+)"/', $dao->data, $m)) {
            $found = (int) $m[1];
        } elseif (preg_match('/s:7:"case_id";i:(\d+)/', $dao->data, $m)) {
            $found = (int) $m[1];
        }
        if ($found === $caseId) {
            $rows[(int) $dao->id] = (string) $dao->release_time;
        }
    }
    return $rows;
};

$statusOf = static function (int $caseId): ?string {
    $case = \Civi\Api4\CiviCase::get(false)
        ->addSelect('status_id:name')->addWhere('id', '=', $caseId)
        ->execute()->first();
    return $case['status_id:name'] ?? null;
};

// --- Sweep fixtures stranded by an earlier crashed run -----------------------

foreach (
    \Civi\Api4\CiviCase::get(false)
        ->addSelect('id')->addWhere('subject', 'LIKE', '%srintaketest%')->execute() as $stale
) {
    foreach (array_keys($queueRows((int) $stale['id'])) as $qid) {
        \CRM_Core_DAO::executeQuery("DELETE FROM civicrm_queue_item WHERE id = %1", [1 => [$qid, 'Integer']]);
    }
    \CRM_Core_DAO::executeQuery(
        "DELETE FROM civirule_rule_log WHERE entity_table = 'civicrm_case' AND entity_id = %1",
        [1 => [(int) $stale['id'], 'Integer']]
    );
    \Civi\Api4\CiviCase::delete(false)->addWhere('id', '=', $stale['id'])->execute();
}
foreach (
    \Civi\Api4\Contact::get(false)
        ->addSelect('id')
        ->addClause('OR', ['last_name', 'LIKE', '%srintaketest%'], ['organization_name', 'LIKE', '%srintaketest%'])
        ->execute() as $stale
) {
    \Civi\Api4\Contact::delete(false)->addWhere('id', '=', $stale['id'])->setUseTrash(false)->execute();
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
    ->addWhere('name_a_b', 'IN', ['Case Client Rep is', 'Case Coordinator is'])
    ->execute()
    ->indexBy('name_a_b');
$repType = (int) $relTypes['Case Client Rep is']['id'];
$coordType = (int) $relTypes['Case Coordinator is']['id'];

/**
 * Build one independent case. Every fixture goes through here and differs ONLY
 * in $caseType and $createStatus.
 *
 * @return array{case:int, org:int}
 */
$makeCase = function (string $tag, string $caseType, string $createStatus) use (
    $mk,
    $stamp,
    &$createdCases,
    $repType,
    $coordType
): array {
    $orgId = $mk('Organization', ['organization_name' => "Org $tag $stamp"]);
    // 'Case Coordinator is' declares contact_sub_type_a = MAS_Rep; a plain
    // Individual is rejected with "Invalid Relationship".
    $vcId = $mk('Individual', ['first_name' => 'Vee', 'last_name' => "Cee$tag$stamp", 'contact_sub_type' => ['MAS_Rep']]);
    $repId = $mk('Individual', ['first_name' => 'Rhea', 'last_name' => "Rep$tag$stamp"]);

    $caseId = (int) \Civi\Api4\CiviCase::create(false)
        ->addValue('case_type_id:name', $caseType)
        ->addValue('subject', "Case $tag $stamp")
        ->addValue('status_id:name', $createStatus)
        ->addValue('start_date', date('Y-m-d'))
        ->addValue('contact_id', $orgId)
        ->execute()->first()['id'];
    $createdCases[] = $caseId;

    // Roles AFTER creation, as the coordinator does it. See the file docblock:
    // this means the on-create rule arms with no client rep on the case, which
    // is deliberate.
    \Civi\Api4\Relationship::create(false)
        ->addValue('contact_id_a', $vcId)->addValue('contact_id_b', $orgId)
        ->addValue('relationship_type_id', $coordType)->addValue('case_id', $caseId)
        ->addValue('is_active', true)->execute();
    \Civi\Api4\Relationship::create(false)
        ->addValue('contact_id_a', $repId)->addValue('contact_id_b', $orgId)
        ->addValue('relationship_type_id', $repType)->addValue('case_id', $caseId)
        ->addValue('is_active', true)->execute();

    return ['case' => $caseId, 'org' => $orgId];
};

try {
    $a = $makeCase('A', 'service_request', 'Request RCS');
    $b = $makeCase('B', 'service_request', 'Open');
    $d = $makeCase('D', 'service_request', 'Open');
    $e = $makeCase('E', 'service_request', 'RCS Completed');
    $f = $makeCase('F', 'project', 'Awaiting VC Project Definition');

    rcsArming_note("Fixtures: A(case={$a['case']}) B(case={$b['case']}) D(case={$d['case']}) "
        . "E(case={$e['case']}) F(case={$f['case']})");
    rcsArming_note('');

    // --- A: the defect ------------------------------------------------------
    rcsArming_note('A: SR created directly at "Request RCS" (manual intake — the defect)');
    rcsArming_check('A1: case ends AT "Request RCS" (coordinator\'s choice preserved)', $statusOf($a['case']), 'Request RCS');

    $aOnCreate = $firings($onCreateId, $a['case']);
    if ($aOnCreate >= 1) {
        rcsArming_pass("A2: armed by the on-create rule (fired $aOnCreate time(s))");
    } else {
        rcsArming_fail('A2: armed by the on-create rule', 'rule never fired — a manually-created SR is still leaking');
    }

    // The cadence, asserted per OFFSET rather than as a total. Expressed
    // relative to the firing count, not as a fixed 2: the count is 1 here and 3
    // on the transaction-wrapped UI path (see the docblock), so a hardcoded
    // total would pass only against these fixtures. Per-offset rather than a
    // bare ratio because a total alone is satisfiable by the wrong shape —
    // 3 firings with five items at +21 and one at +42 has the right count and
    // the right distinct offsets, and is broken.
    $aQueue = $queueRows($a['case']);
    $offsetCounts = [];
    foreach ($aQueue as $release) {
        $days = (int) round((strtotime($release) - time()) / 86400);
        $offsetCounts[$days] = ($offsetCounts[$days] ?? 0) + 1;
    }
    ksort($offsetCounts);
    if ($aOnCreate > 0) {
        rcsArming_check(
            'A3: one +21d and one +42d chase per firing, and nothing else',
            $offsetCounts,
            [21 => $aOnCreate, 42 => $aOnCreate]
        );
    } else {
        // Guarded because [] === [] would otherwise report a pass for a case
        // that was never armed at all. A2 has already failed in that branch.
        rcsArming_fail('A3: cadence queued', 'not evaluated — nothing was armed (see A2)');
    }

    rcsArming_check('A4: transition rule did NOT also arm (no double-arming)', $firings($transitionId, $a['case']), 0);

    // --- B: the control -----------------------------------------------------
    rcsArming_note('B: SR created at "Ongoing" then transitioned (web-form path)');
    \Civi\Api4\CiviCase::update(false)
        ->addWhere('id', '=', $b['case'])->addValue('status_id:name', 'Request RCS')->execute();
    rcsArming_check('B0: case is AT "Request RCS"', $statusOf($b['case']), 'Request RCS');
    $bTransition = $firings($transitionId, $b['case']);
    if ($bTransition > 0) {
        rcsArming_pass("B1: transition rule armed (fired $bTransition time(s)) — the proven path still works");
    } else {
        rcsArming_fail('B1: transition rule armed', 'the web-form path itself did not arm, so this run cannot '
            . 'interpret A either — the rule or its conditions are broken');
    }
    rcsArming_check('B2: on-create rule did NOT also arm (no double-arming)', $firings($onCreateId, $b['case']), 0);

    // --- D: no over-reach ---------------------------------------------------
    rcsArming_note('D: SR created at "Ongoing" and left alone');
    rcsArming_check('D1: still at "Ongoing"', $statusOf($d['case']), 'Open');
    rcsArming_check('D2: on-create rule NOT armed', $firings($onCreateId, $d['case']), 0);
    rcsArming_check('D3: transition rule NOT armed', $firings($transitionId, $d['case']), 0);
    rcsArming_check('D4: nothing queued', count($queueRows($d['case'])), 0);

    // --- E: a non-arming creation status ------------------------------------
    rcsArming_note('E: SR created at "RCS Completed"');
    rcsArming_check('E1: status untouched', $statusOf($e['case']), 'RCS Completed');
    rcsArming_check('E2: on-create rule NOT armed', $firings($onCreateId, $e['case']), 0);
    rcsArming_check('E3: nothing queued', count($queueRows($e['case'])), 0);

    // --- F: case-type guard -------------------------------------------------
    rcsArming_note('F: Project created at "Awaiting VC Project Definition"');
    rcsArming_check('F1: status untouched', $statusOf($f['case']), 'Awaiting VC Project Definition');
    rcsArming_check('F2: on-create rule NOT armed', $firings($onCreateId, $f['case']), 0);
    rcsArming_check('F3: transition rule NOT armed', $firings($transitionId, $f['case']), 0);

    // --- G: a genuine RE-entry must still arm -------------------------------
    rcsArming_note('G: case A leaves "Request RCS" and is asked again');
    \Civi\Api4\CiviCase::update(false)
        ->addWhere('id', '=', $a['case'])->addValue('status_id:name', 'RCS Completed')->execute();
    rcsArming_check('G1: A moved off to "RCS Completed"', $statusOf($a['case']), 'RCS Completed');
    \Civi\Api4\CiviCase::update(false)
        ->addWhere('id', '=', $a['case'])->addValue('status_id:name', 'Request RCS')->execute();
    rcsArming_check('G2: A is back at "Request RCS"', $statusOf($a['case']), 'Request RCS');

    $aTransitionAfter = $firings($transitionId, $a['case']);
    if ($aTransitionAfter > 0) {
        rcsArming_pass("G3: re-entry armed again via the TRANSITION rule (fired $aTransitionAfter time(s)): "
            . 'still once per ENTRY, not once per case');
    } else {
        rcsArming_fail(
            'G3: re-entry armed again via the transition rule',
            'the transition rule never fired for a real re-entry — a client who returned one form and '
            . 'was later asked again would silently never be chased'
        );
    }
    // The real invariant, and the one a once-per-case latch or a widened
    // trigger would break: leaving and re-entering the status must not make
    // the CREATE rule fire again. Compared against A's own earlier count
    // rather than a literal, so it holds at any multiplicity.
    rcsArming_check(
        'G4: on-create rule did NOT fire again on re-entry (creation cannot recur)',
        $firings($onCreateId, $a['case']),
        $aOnCreate
    );
} catch (\Throwable $ex) {
    rcsArming_fail('rcs chase arming', get_class($ex) . ': ' . $ex->getMessage());
} finally {
    rcsArming_note('');
    rcsArming_note('Cleanup...');
    foreach ($createdCases as $id) {
        // Queue rows first: once the case is gone the blob can no longer be
        // matched back to it, and the row would sit in the queue until its
        // release_time (up to 42 days) before self-clearing.
        foreach (array_keys($queueRows((int) $id)) as $qid) {
            \CRM_Core_DAO::executeQuery("DELETE FROM civicrm_queue_item WHERE id = %1", [1 => [$qid, 'Integer']]);
        }
        // The rule log is an audit trail of real business events; rows pointing
        // at a deleted throwaway case are noise in it, so this run removes only
        // its own.
        \CRM_Core_DAO::executeQuery(
            "DELETE FROM civirule_rule_log WHERE entity_table = 'civicrm_case' AND entity_id = %1",
            [1 => [(int) $id, 'Integer']]
        );
        \Civi\Api4\CiviCase::delete(false)->addWhere('id', '=', $id)->execute();
    }
    foreach (array_unique(array_filter($createdContacts)) as $id) {
        \Civi\Api4\Contact::delete(false)->addWhere('id', '=', $id)->setUseTrash(false)->execute();
    }
}

// --- Summary -----------------------------------------------------------------

rcsArming_note('');
if (RcsChaseArmingT::$failures) {
    rcsArming_note('RESULT: RED — ' . count(RcsChaseArmingT::$failures) . ' failure(s), ' . RcsChaseArmingT::$passes . ' pass(es)');
    foreach (RcsChaseArmingT::$failures as $f) {
        rcsArming_note("  - $f");
    }
    exit(1);
}
rcsArming_note('RESULT: GREEN — all ' . RcsChaseArmingT::$passes . ' assertions passed');
exit(0);
