<?php

namespace Civi\Mascode\Test\Unit\Event;

use Civi\Mascode\Test\TestCase;

/**
 * The CI-visible half of the D10 guard on afformMASProjectCheckin.
 *
 * WHAT IS ACTUALLY FRAGILE HERE. CheckinCaseEntitlementSubscriber is correct
 * only because of where it sits in two priority orders, and neither of those
 * orders is visible from the file itself:
 *
 *   READ  civi.afform.prefill — must be BELOW AfformTokenPrefillSubscriber
 *         (1000), which restores the token's afformArgs for an already-logged-in
 *         visitor, and ABOVE core's autofill behaviors (99), which are what
 *         actually consume `case_id`. Too high and it inspects args that have
 *         not arrived yet and waves everything through; too low and the case is
 *         already loaded and returned.
 *   WRITE civi.afform.submit — must be ABOVE core's processGenericEntity (0),
 *         which is what writes.
 *
 * A one-character edit to that number turns a guard into a no-op that still
 * looks present, still logs nothing, and still passes any test that only asks
 * "is it subscribed". So the number is asserted against its neighbours rather
 * than pinned to a literal.
 *
 * ⚠ BUT ONLY ONE OF THE THREE NEIGHBOURS IS READ FROM SOURCE, and an earlier
 * version of this docblock claimed all of them were.
 * `AfformTokenPrefillSubscriber`'s 1000 is parsed out of its own file, so
 * lowering it fails this test. Core's two — the autofill behaviors at 99 and
 * `processGenericEntity` at 0 — are LITERALS here, because CI has no CiviCRM
 * to read them from (docs/TESTING.md). So a CiviCRM upgrade that moved
 * `Civi\Afform\Behavior\CaseAutofill` above 500 would leave this test green
 * while the read guard silently became a no-op.
 *
 * That residual risk is real and is not closed by this file. What catches it
 * today is the live test's REFUSED assertions, not its entitled one — and an
 * earlier version of this paragraph had that backwards, which matters because
 * this is the paragraph justifying not building a stronger check.
 *
 * If `CaseAutofill` moved above 500, an ENTITLED visitor would still load
 * their case (the guard returns early and strips nothing), so that assertion
 * passes and proves nothing. A REFUSED visitor is the one that breaks: the
 * behavior would have loaded the case into the entity values before the guard
 * ran, so `assertBlocked` sees `Case1` populated and fails. So the live test
 * does still catch it — through the assertions that matter rather than the
 * first one.
 *
 * WHAT THIS FILE CANNOT DO. It reads source, not behaviour: it cannot prove the
 * entitlement predicate returns the right answer, because CI has no CiviCRM
 * (docs/TESTING.md) and the predicate is an API4 query. That half is
 * tests/Security/CheckinEntitlementTest.php, a `cv scr` script that runs
 * against real data as a real non-staff VC. Neither file replaces the other,
 * and the live one is the one that would catch a wrong predicate.
 *
 * Parsed as TEXT, not reflection, for the reason
 * LifecycleTransitionTemplateWiringTest gives: the subscriber extends
 * \Civi\Core\Service\AutoSubscriber, so loading the class needs CiviCRM on the
 * autoloader, which CI does not have.
 *
 * @coversNothing
 */
class CheckinEntitlementWiringTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    private const GUARD = self::ROOT . '/Civi/Mascode/Event/CheckinCaseEntitlementSubscriber.php';

    private const TOKEN_PREFILL = self::ROOT . '/Civi/Mascode/Event/AfformTokenPrefillSubscriber.php';

    private const FORM_HTML = self::ROOT . '/ang/afformMASProjectCheckin.aff.html';

    private const FORM_JSON = self::ROOT . '/ang/afformMASProjectCheckin.aff.json';

    private function guardSource(): string
    {
        $this->assertFileExists(self::GUARD, 'The D10 guard for the check-in form is missing.');
        return file_get_contents(self::GUARD);
    }

    /**
     * Pull an integer class constant out of a source file by name.
     */
    private function constantValue(string $source, string $name): ?int
    {
        if (preg_match('/const\s+' . preg_quote($name, '/') . '\s*=\s*(\d+)\s*;/', $source, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    /**
     * The priority the token-arg restorer uses, read from ITS file.
     *
     * Read rather than hard-coded: if someone lowers that subscriber's
     * priority, the relationship this guard depends on changes, and a literal
     * 1000 here would keep asserting against a number that moved.
     */
    private function tokenPrefillPriority(): int
    {
        $source = file_get_contents(self::TOKEN_PREFILL);
        $this->assertNotFalse($source, 'AfformTokenPrefillSubscriber is missing.');
        $this->assertSame(
            1,
            preg_match("/\['onAfformPrefill',\s*(\d+)\]/", $source, $m),
            'Could not read AfformTokenPrefillSubscriber\'s priority; this test cannot verify the ordering it exists for.'
        );
        return (int) $m[1];
    }

    public function testGuardSubscribesToBothPrefillAndSubmit(): void
    {
        $source = $this->guardSource();

        $this->assertMatchesRegularExpression(
            "/'civi\.afform\.prefill'\s*=>\s*\[\s*\['onPrefill',\s*self::PRIORITY\]/",
            $source,
            'The guard must gate the READ path. Without it a stale link still renders another VC\'s project.'
        );
        $this->assertMatchesRegularExpression(
            "/'civi\.afform\.submit'\s*=>\s*\[\s*\['onSubmit',\s*self::PRIORITY\]/",
            $source,
            'The guard must gate the WRITE path. Without it a stale link still files an answer against a project '
            . 'the visitor no longer coordinates.'
        );
    }

    /**
     * The priority must sit strictly between its two neighbours on the read
     * path, and strictly above core's writer on the write path.
     */
    public function testPriorityIsBetweenTokenRestoreAndTheAutofillBehaviors(): void
    {
        $priority = $this->constantValue($this->guardSource(), 'PRIORITY');
        $this->assertNotNull($priority, 'CheckinCaseEntitlementSubscriber::PRIORITY is missing or not an integer.');

        // Core's autofill behaviors (Civi\Afform\Behavior\CaseAutofill etc.)
        // subscribe to civi.afform.prefill at 99 and are what read `case_id`.
        $behaviorPriority = 99;
        // Core registers Submit::processGenericEntity on civi.afform.submit at 0.
        $coreWritePriority = 0;

        $this->assertLessThan(
            $this->tokenPrefillPriority(),
            $priority,
            'The guard must run AFTER AfformTokenPrefillSubscriber restores the token args. Above it, the guard '
            . 'inspects args that have not arrived yet, finds no case_id, and lets every request through.'
        );
        $this->assertGreaterThan(
            $behaviorPriority,
            $priority,
            'The guard must run BEFORE core\'s autofill behaviors (priority 99) consume case_id. Below them the '
            . 'case has already been loaded and returned, and stripping the arg afterwards protects nothing.'
        );
        $this->assertGreaterThan(
            $coreWritePriority,
            $priority,
            'The guard must run BEFORE core\'s processGenericEntity (priority 0) writes.'
        );
    }

    /**
     * The guard names the form, and the form is the kind of form that needs it.
     *
     * If the form ever stopped being `*always allow*`, CiviCRM's permission
     * engine would be underneath it again and this guard would be belt-and-
     * braces rather than the only thing there. It is worth knowing which of
     * those two worlds we are in, so the assertion states it.
     */
    public function testGuardNamesTheFormAndTheFormIsUnprotectedWithoutIt(): void
    {
        $this->assertStringContainsString(
            "'afformMASProjectCheckin'",
            $this->guardSource(),
            'The guard must name the form it governs.'
        );

        $this->assertFileExists(self::FORM_JSON);
        $json = json_decode(file_get_contents(self::FORM_JSON), true);

        $this->assertContains(
            '*always allow*',
            $json['permission'],
            'The check-in form is reached from an emailed token link with no login, so it is `*always allow*`. '
            . 'If that changed, re-read the guard: its whole justification is that no permission engine sits underneath.'
        );
        $this->assertContains(
            'msg_token_single',
            $json['placement'],
            'The form must be token-placeable or the digest cannot mint per-project links for it (P1-4).'
        );
    }

    /**
     * The form must not hand the caller an extra way to name a record.
     *
     * `case-autofill="entity_id"` is the one sanctioned door and the guard
     * covers it. An `autofill` attribute on an id field would make the
     * entity-named arg (`Case1=N`) live too, which neither this guard nor
     * AfformPublicArgGuardSubscriber inspects — ang/README.md states the same
     * rule for the other public forms.
     */
    public function testFormDeclaresNoSecondDoorForCallerSuppliedIds(): void
    {
        $this->assertFileExists(self::FORM_HTML);
        $html = file_get_contents(self::FORM_HTML);

        $this->assertStringContainsString(
            'case-autofill="entity_id"',
            $html,
            'The form loads its project from the token\'s case_id; without this it shows nothing.'
        );
        $this->assertStringNotContainsString(
            'url-autofill',
            $html,
            'url-autofill makes entity-named args (Case1=N) load a record. Neither guard covers that name.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<af-field\s+name="id"/',
            $html,
            'An id field carrying an autofill attribute is the other way to make Case1=N live. See ang/README.md '
            . '§"Security: public forms and caller-supplied record ids".'
        );
    }

    /**
     * A refused write must throw, not drop the id.
     *
     * Dropping it would create the check-in activity attached to no case,
     * behind the normal confirmation screen — an answer the VC believes they
     * gave and that exists nowhere. The existing public-form guard reasons the
     * same way about the same choice.
     */
    public function testRefusedWriteThrowsRatherThanSilentlyDroppingTheCase(): void
    {
        $source = $this->guardSource();

        $this->assertStringContainsString(
            'UnauthorizedException',
            $source,
            'A refused submit must throw.'
        );
        $this->assertMatchesRegularExpression(
            '/private function refuse\(\)\s*:\s*never/',
            $source,
            'refuse() must be declared `never`, so that onSubmit\'s null-case branch cannot silently fall '
            . 'through into the entitlement check with no case.'
        );
    }
}
