<?php

/**
 * Lifecycle transitions — every TRANSITIONS key must name a live template.
 *
 * WHY THIS IS A `cv scr` SCRIPT, NOT A PHPUnit TEST:
 * The same reason as the other files in tests/Live/ (see docs/TESTING.md). The
 * fact under test is a MISMATCH BETWEEN SOURCE AND DATABASE, so a test that
 * reads only the repository cannot see it. CI has no CiviCRM, and the
 * Integration suite self-skips in this WP-buildkit site — an Integration test
 * here would have reported green through the entire outage. The CI-visible
 * half is tests/Unit/Event/LifecycleTransitionTemplateWiringTest.php, which
 * compares the TRANSITIONS keys to the managed declarations; it cannot see a
 * rename made in the production UI, which is what actually happened.
 *
 * READ-ONLY. It creates nothing, updates nothing and deletes nothing — every
 * call is an API4 get or a SELECT. That is deliberate: this is the one
 * lifecycle test that is meant to be pointed at PRODUCTION, and it is safe
 * there.
 *
 * ⚠ Despite the *Test.php name this file is NOT collected by PHPUnit:
 * phpunit.xml.dist defines its testsuites over tests/Unit, tests/Integration
 * and tests/E2E only. That matters because the file calls exit() at top level —
 * broadening a testsuite to tests/ would make this kill the run mid-suite.
 *
 * RUN (dev, from inside the buildkit site):
 *   cd /home/brian/buildkit/build/masdemo/web/wp-content/uploads/civicrm/ext/mascode
 *   cv scr tests/Live/LifecycleTransitionTemplatesTest.php --user=brian.flett@masadvise.org
 *
 * RUN (production — read-only, see the mas-prod-access skill for the cv PHAR
 * and the HOME= requirement):
 *   cd ~/public_html/wp-content/uploads/civicrm/ext/mascode
 *   HOME=/home/mas/tmp cv scr tests/Live/LifecycleTransitionTemplatesTest.php --user=<a user with a uf_match row>
 *
 * Exit code 0 = all pass; 1 = at least one failure; 2 = could not run.
 *
 * WHAT IT GUARDS
 * ProjectLifecycleStatusSubscriber advances a Project case when a lifecycle
 * email is sent. getTemplateSubjects() resolves the TRANSITIONS keys against
 * `civicrm_msg_template.msg_title`, and matchTransition() then substring-matches
 * the sent activity's subject against those templates' subjects.
 *
 * Three ways that silently stops working, one assertion each:
 *
 *   A1  A key names no ACTIVE template. The lookup returns nothing, the
 *       transition is a no-op, the email still sends, nothing is logged. This
 *       is the 2026-09-17 production outage: template 75 was renamed in the UI
 *       to "MAS Project Signoff - Client Template" while the code still keyed
 *       "MAS Project Close - Client Template", so sending the client signoff
 *       stopped advancing the case and mas_lifecycle_close_chase never armed.
 *
 *   A2  Two subject prefixes overlap (D18). matchTransition() returns the FIRST
 *       hit in declaration order, so one transition becomes unreachable and the
 *       other fires for both emails. Worse than A1: the case DOES move, to the
 *       wrong status, so it looks like the automation worked.
 *
 *   A3  A `from` or `to` names a case status that does not exist. A bad `to`
 *       makes CiviCase::update() throw, which the subscriber catches and logs;
 *       a bad `from` can simply never match. Either way the case does not move.
 *
 *   A4  A CiviRules action names a template that does not resolve. Added after
 *       review, and it is the assertion that would have caught the 2026-09-17
 *       outage on day one: A1-A3 all check titles held in SOURCE, but the copy
 *       that stopped the client close email being sent was serialised data in
 *       civirule_rule_action.action_params, which no deploy touches and which
 *       nothing here previously read. LifecycleMailer throws on it, so unlike
 *       A1 the email is not merely mis-routed — it is never sent.
 */

// --- Harness -----------------------------------------------------------------

class LifecycleTransitionTemplatesT
{
    /** @var string[] */
    public static array $failures = [];
    public static int $passes = 0;
}

function ltt_note(string $msg): void
{
    echo $msg . PHP_EOL;
}

function ltt_pass(string $what): void
{
    LifecycleTransitionTemplatesT::$passes++;
    ltt_note("  PASS  $what");
}

function ltt_fail(string $what, string $detail): void
{
    LifecycleTransitionTemplatesT::$failures[] = $what . ' — ' . $detail;
    ltt_note("  FAIL  $what");
    ltt_note("        $detail");
}

// --- Setup -------------------------------------------------------------------

if (!class_exists('\Civi\Api4\MessageTemplate')) {
    ltt_note('ABORT: CiviCRM API4 not available — is this running under `cv scr`?');
    exit(2);
}

// Reflection is fine HERE, unlike in the unit test: cv scr gives us a fully
// bootstrapped CiviCRM, so AutoSubscriber is on the autoloader.
$ref = new \ReflectionClass(\Civi\Mascode\Event\ProjectLifecycleStatusSubscriber::class);
$transitions = $ref->getConstant('TRANSITIONS');

if (!is_array($transitions) || $transitions === []) {
    // Exit 2, not 1. An empty constant would make every loop below iterate zero
    // times and the script would report GREEN having asserted nothing — a false
    // pass, which is the one outcome worse than a false failure.
    ltt_note('ABORT: TRANSITIONS could not be read, or is empty. Refusing to report a green it has not earned.');
    exit(2);
}

ltt_note('Lifecycle transition templates — ' . count($transitions) . ' transition(s) declared');
ltt_note('');

// --- A1: every key names an active template ----------------------------------

ltt_note('A1  Every TRANSITIONS key resolves to an ACTIVE message template');

$templates = \Civi\Api4\MessageTemplate::get(false)
    ->addSelect('id', 'msg_title', 'msg_subject', 'is_active')
    ->addWhere('msg_title', 'IN', array_keys($transitions))
    ->execute();

$live = [];
foreach ($templates as $template) {
    $live[$template['msg_title']] = $template;
}

foreach (array_keys($transitions) as $title) {
    if (!isset($live[$title])) {
        ltt_fail(
            "template exists: \"$title\"",
            'no message template carries this msg_title. The transition is a silent no-op: the '
            . 'email sends, the case never advances, and nothing is logged. Either it was renamed '
            . 'in the UI (rename the TRANSITIONS key to match and add an upgrade step so other '
            . 'environments converge) or it was deleted.'
        );
        continue;
    }
    if (empty($live[$title]['is_active'])) {
        ltt_fail(
            "template active: \"$title\"",
            'template id ' . $live[$title]['id'] . ' exists but is_active = 0. getTemplateSubjects() '
            . 'does not filter on is_active, so this transition still fires — but the template '
            . 'cannot be sent from the UI, so in practice the email never goes out.'
        );
        continue;
    }
    ltt_pass("\"$title\" => template id " . $live[$title]['id']);
}

ltt_note('');

// --- A2: D18, no subject prefix contains another -----------------------------

ltt_note('A2  No template subject prefix is a substring of another (D18)');

$prefixes = [];
foreach ($live as $title => $template) {
    $subject = (string) ($template['msg_subject'] ?? '');
    // Mirrors matchTransition() exactly: the static head, up to the first token.
    $tokenPos = strpos($subject, '{');
    $prefix = $tokenPos === false ? $subject : rtrim(substr($subject, 0, $tokenPos));

    if ($prefix === '') {
        ltt_fail(
            "subject has static text: \"$title\"",
            'the subject begins with a token, so its static prefix is empty. matchTransition() '
            . 'skips an empty prefix, so this transition can never fire.'
        );
        continue;
    }
    $prefixes[$title] = $prefix;
}

$collisionFound = false;
foreach ($prefixes as $titleA => $prefixA) {
    foreach ($prefixes as $titleB => $prefixB) {
        if ($titleA === $titleB) {
            continue;
        }
        if (str_contains($prefixA, $prefixB)) {
            $collisionFound = true;
            ltt_fail(
                "prefix collision: \"$titleB\" inside \"$titleA\"",
                "\"$prefixB\" is a substring of \"$prefixA\". matchTransition() returns the first "
                . 'hit in TRANSITIONS declaration order, so one of these transitions is unreachable '
                . 'and the other fires for both emails — moving the case to the wrong status. '
                . 'Change one subject so neither contains the other.'
            );
        }
    }
}

if (!$collisionFound && $prefixes !== []) {
    ltt_pass(count($prefixes) . ' subject prefix(es) mutually distinct');
}

foreach ($prefixes as $title => $prefix) {
    ltt_note("        \"$title\" matches on: \"$prefix\"");
}

ltt_note('');

// --- A3: every from/to names a real case status ------------------------------

ltt_note('A3  Every from/to names an existing case status');

$known = \Civi\Api4\OptionValue::get(false)
    ->addSelect('name')
    ->addWhere('option_group_id.name', '=', 'case_status')
    ->execute()
    ->column('name');

foreach ($transitions as $title => $transition) {
    $to = $transition['to'] ?? '';
    if (!in_array($to, $known, true)) {
        ltt_fail(
            "to-status exists: \"$title\"",
            "'to' names case status \"$to\", which does not exist. CiviCase::update() throws, the "
            . 'subscriber catches and logs it, and the case does not move.'
        );
    }
    else {
        ltt_pass("\"$title\" to => \"$to\"");
    }

    foreach (($transition['from'] ?? []) as $from) {
        if (!in_array($from, $known, true)) {
            ltt_fail(
                "from-status exists: \"$title\"",
                "'from' names case status \"$from\", which does not exist. It can never match, so "
                . 'that starting state is silently excluded from the transition.'
            );
        }
    }
}

// --- A4: every CiviRules action resolves its template ------------------------

ltt_note('A4  Every CiviRules action template resolves to a live template');

// THE ASSERTION THAT WOULD HAVE CAUGHT FAULT 2 ON DAY ONE, added after review.
// A1-A3 all check titles held in SOURCE. The copy that actually broke the send
// was serialised data in civirule_rule_action.action_params, which no deploy
// touches and which nothing in the test suite read.
//
// LifecycleMailer::loadTemplate() resolves this value and THROWS when it does
// not match, so a stale title here is not a silent no-op — the email is never
// sent at all. Read-only: a SELECT and unserialize(), no writes.
//
// CRM_Core_DAO rather than API4 because CiviRules exposes no API4 entity; that
// is the established idiom for these tables in this extension.
$ruleActions = \CRM_Core_DAO::executeQuery(
    "SELECT ra.id, ra.action_params, ra.is_active, r.name AS rule_name, r.is_active AS rule_active
       FROM civirule_rule_action ra
       JOIN civirule_rule r ON r.id = ra.rule_id"
);

$checked = 0;
while ($ruleActions->fetch()) {
    // allowed_classes => false is safe HERE specifically because this path only
    // READS. On the write-back path in repointClientCloseTemplate() the same
    // flag would be a hazard — it yields __PHP_Incomplete_Class objects that
    // re-serialise differently — but nothing is written here. Matches the
    // repo's existing idiom at Civi/Mascode/CiviRules/Action/LifecycleEmail.php.
    $params = @unserialize((string) $ruleActions->action_params, ['allowed_classes' => false]);
    if (!is_array($params) || !isset($params['template'])) {
        // Most CiviRules actions are not template sends. Not our concern.
        continue;
    }
    // Only live paths. A disabled rule cannot send, so a stale title on one is
    // untidy rather than broken, and failing on it would train people to ignore
    // this script.
    if (empty($ruleActions->is_active) || empty($ruleActions->rule_active)) {
        continue;
    }

    $checked++;
    $wanted = $params['template'];
    $label = "rule {$ruleActions->rule_name} (action row {$ruleActions->id})";

    // Mirrors LifecycleMailer::loadTemplate() exactly, including the numeric
    // branch — if this diverges, the test stops describing what actually runs.
    $get = \Civi\Api4\MessageTemplate::get(false)
        ->addSelect('id', 'msg_title', 'is_active')
        ->setLimit(1);
    if (is_numeric($wanted)) {
        $get->addWhere('id', '=', (int) $wanted);
    } else {
        $get->addWhere('msg_title', '=', (string) $wanted);
    }
    $found = $get->execute()->first();

    if (!$found) {
        ltt_fail(
            "action template resolves: $label",
            "action_params names template \"$wanted\", which does not exist. "
            . 'LifecycleMailer::loadTemplate() throws \InvalidArgumentException on this, so the '
            . 'email is NOT SENT — this is a hard failure, not a silent one. The title is '
            . 'serialised into the database row, so no deploy will fix it; it needs an upgrade '
            . 'step (see upgrade_5014 for the shape).'
        );
        continue;
    }
    if (empty($found['is_active'])) {
        ltt_fail(
            "action template active: $label",
            "template \"{$found['msg_title']}\" (id {$found['id']}) is inactive."
        );
        continue;
    }
    ltt_pass("$label => \"{$found['msg_title']}\" (id {$found['id']})");
}

if ($checked === 0) {
    // Not a pass. Zero template-sending actions on a site that runs the
    // lifecycle means the rules are missing, or the params shape changed and
    // this check has quietly stopped looking at anything.
    ltt_fail(
        'CiviRules template actions found',
        'no ACTIVE CiviRules action carries a template parameter. Either the lifecycle rules are '
        . 'not provisioned on this site, or action_params no longer stores the template under that '
        . 'key — in which case this assertion is no longer checking anything and must be repointed.'
    );
}

ltt_note('');

// --- A5: no OTHER template's subject contains a transition prefix ------------

ltt_note('A5  No non-transition template subject contains a TRANSITIONS prefix');

// A2 checks the transition templates against EACH OTHER. This checks them
// against everything else, which is a different and nastier failure.
//
// matchTransition() tests `str_contains($activitySubject, $prefix)` against the
// subject of whatever email was just logged on the case. It does not care which
// template produced it. So any OTHER lifecycle email whose subject happens to
// contain a transition prefix will move the case as though the transition email
// had been sent.
//
// Nearly introduced on 2026-09-21: renaming the VC transition's subject to
// "Project Completion" made the obvious chase wording — "Reminder: Project
// Completion report for {code}" — a superstring of it, so a reminder would have
// advanced the case. The chase subjects were reworded instead. Nothing would
// have caught that; this does.
//
// The forward-only `from` lists mean such a false match is often a no-op today,
// which makes it worse rather than better: it is latent, and one status change
// away from moving a case for a reason nobody can find.
$allTemplates = \Civi\Api4\MessageTemplate::get(false)
    ->addSelect('id', 'msg_title', 'msg_subject')
    ->addWhere('is_active', '=', true)
    ->setLimit(0)
    ->execute();

$transitionTitles = array_keys($transitions);
$checkedOthers = 0;

foreach ($allTemplates as $other) {
    $title = (string) $other['msg_title'];
    if (in_array($title, $transitionTitles, true)) {
        continue;
    }
    $subject = (string) ($other['msg_subject'] ?? '');
    if ($subject === '') {
        continue;
    }
    $checkedOthers++;

    foreach ($prefixes as $transitionTitle => $prefix) {
        if (str_contains($subject, $prefix)) {
            ltt_fail(
                "no false transition: \"$title\"",
                "its subject (\"$subject\") contains the transition prefix \"$prefix\" from "
                . "\"$transitionTitle\". Sending this email would log an activity that "
                . 'matchTransition() reads as that transition, moving the case for a reason no '
                . 'one will be able to trace. Reword this subject so it does not contain the '
                . 'prefix — the transition subject is the one that cannot move.'
            );
        }
    }
}

if ($checkedOthers > 0) {
    ltt_pass("$checkedOthers other active template subject(s) carry no transition prefix");
}
else {
    ltt_fail(
        'other templates were checked',
        'no other active message template was examined, so this assertion proved nothing. '
        . 'Either the site has no other templates (implausible) or the query is wrong.'
    );
}

ltt_note('');

// --- Summary -----------------------------------------------------------------

ltt_note('');
if (LifecycleTransitionTemplatesT::$failures) {
    ltt_note('RESULT: RED — ' . count(LifecycleTransitionTemplatesT::$failures) . ' failure(s), ' . LifecycleTransitionTemplatesT::$passes . ' pass(es)');
    foreach (LifecycleTransitionTemplatesT::$failures as $f) {
        ltt_note("  - $f");
    }
    exit(1);
}
ltt_note('RESULT: GREEN — all ' . LifecycleTransitionTemplatesT::$passes . ' assertions passed');
exit(0);
