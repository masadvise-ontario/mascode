<?php

namespace Civi\Mascode\Test\Unit\Security;

use Civi\Mascode\Test\TestCase;
use Civi\Mascode\Security\AfformArgPolicy;

/**
 * The filter half of the fix for the anonymous Afform.prefill leak (task #159).
 *
 * AfformPublicArgGuardSubscriber decides WHO is entitled to an id; this class
 * decides WHICH arguments carry an id at all and drops the unentitled ones. That
 * split exists so this half can be tested with no CiviCRM bootstrap — the
 * Integration suite self-skips under WP-buildkit, so a rule that lived in the
 * subscriber would have no executing test in CI at all.
 *
 * Pure functions over arrays — no CiviCRM, no database.
 *
 * @covers \Civi\Mascode\Security\AfformArgPolicy
 */
class AfformArgPolicyTest extends TestCase
{
    // --- isGuardedForm ------------------------------------------------------

    /**
     * The seven MAS client forms: public AND no permission requirement. These
     * are the shape that made caller-supplied ids reachable anonymously.
     */
    public function testPublicAlwaysAllowFormIsGuarded(): void
    {
        $this->assertTrue(AfformArgPolicy::isGuardedForm([
            'name' => 'afformProjectCloseClientFeedback',
            'is_public' => true,
            'permission' => ['*always allow*'],
        ]));
    }

    /**
     * A public form that still demands a real permission is already gated by
     * that permission; guarding it too would only risk breaking it.
     */
    public function testPublicFormWithRealPermissionIsNotGuarded(): void
    {
        $this->assertFalse(AfformArgPolicy::isGuardedForm([
            'name' => 'afformSomethingStaffy',
            'is_public' => true,
            'permission' => ['edit all contacts'],
        ]));
    }

    /**
     * `is_public` must NOT be part of the test. It does not gate access to a
     * form — it only chooses the frontend vs backend URL scheme when a token
     * link is minted. Access is decided by `permission` alone, so a form that is
     * `*always allow*` but not public is still fully reachable anonymously and
     * must still be guarded.
     *
     * An earlier version of the policy required `is_public` as well, and an
     * earlier version of THIS test asserted that as correct — which would have
     * let the next person create a non-public `*always allow*` form, silently
     * reopen the hole, and still see a green suite.
     */
    public function testNonPublicAlwaysAllowFormIsStillGuarded(): void
    {
        $this->assertTrue(AfformArgPolicy::isGuardedForm([
            'name' => 'afformSomethingInternalButUngated',
            'is_public' => false,
            'permission' => ['*always allow*'],
        ]));
    }

    /**
     * The real exclusion: a form that demands any actual permission.
     */
    public function testNonPublicFormWithRealPermissionIsNotGuarded(): void
    {
        $this->assertFalse(AfformArgPolicy::isGuardedForm([
            'name' => 'afformMASBoardDashboard',
            'is_public' => false,
            'permission' => ['edit all contacts'],
        ]));
    }

    /**
     * `*always allow*` alongside another permission still means no gate, since
     * Afform's permission_operator can be OR. Guard it.
     */
    public function testAlwaysAllowAmongOthersIsGuarded(): void
    {
        $this->assertTrue(AfformArgPolicy::isGuardedForm([
            'is_public' => true,
            'permission' => ['access CiviCRM', '*always allow*'],
        ]));
    }

    /**
     * Afform.get should always return an array here, but a scalar or a missing
     * key must not become a silent "not guarded" — that would fail open.
     */
    public function testScalarPermissionIsHandled(): void
    {
        $this->assertTrue(AfformArgPolicy::isGuardedForm([
            'is_public' => true,
            'permission' => '*always allow*',
        ]));
        // An absent permission cannot fail open here either: Afform's Get
        // action defaults an empty permission to ['access CiviCRM'], so a form
        // reaching this method with no permission key is not `*always allow*`.
        $this->assertFalse(AfformArgPolicy::isGuardedForm(['is_public' => true]));
        $this->assertFalse(AfformArgPolicy::isGuardedForm([]));
    }

    // --- guardedKeys -------------------------------------------------------

    /**
     * The two names actually shown to leak data on production and dev.
     */
    public function testDetectsCaseAndContactIdArgs(): void
    {
        $this->assertSame(
            ['case_id', 'contact_id'],
            AfformArgPolicy::guardedKeys(['case_id' => 13306, 'contact_id' => 2])
        );
    }

    /**
     * Inert on today's forms — no MAS entity declares those autofill modes —
     * but guarded so that adding autofill="entity_id" to an Activity fieldset
     * cannot silently reopen the hole.
     */
    public function testDetectsTheOtherAutofillIdArgs(): void
    {
        $this->assertSame(
            ['activity_id', 'event_id', 'participant_id'],
            AfformArgPolicy::guardedKeys([
                'activity_id' => 1,
                'event_id' => 2,
                'participant_id' => 3,
            ])
        );
    }

    /**
     * Entity-named args do not load a record on these forms (core only honours
     * them when the matched field carries an `autofill` input attribute, which
     * none of the MAS id fields do), and `sid` is already permission-checked.
     * Claiming them here would be a lie about what the guard protects.
     */
    public function testIgnoresArgsThatCannotLoadARecord(): void
    {
        $this->assertSame([], AfformArgPolicy::guardedKeys([
            'Case1' => 13306,
            'Individual1' => 2,
            'Organization1' => 1,
            'sid' => 5,
        ]));
    }

    /**
     * A falsy id cannot load anything — every core behavior bails on it — so
     * reporting it would put noise in the security log for no gain.
     */
    public function testEmptyIdsAreNotFindings(): void
    {
        $this->assertSame([], AfformArgPolicy::guardedKeys([
            'case_id' => 0,
            'contact_id' => '',
        ]));
        $this->assertSame([], AfformArgPolicy::guardedKeys(['case_id' => null]));
    }

    public function testNoArgsIsNoFindings(): void
    {
        $this->assertSame([], AfformArgPolicy::guardedKeys([]));
    }

    // --- sanitize ----------------------------------------------------------

    /**
     * The anonymous attack: nothing is authorised, so nothing survives.
     */
    public function testUnauthorisedIdsAreDropped(): void
    {
        $this->assertSame(
            [],
            AfformArgPolicy::sanitize(['case_id' => 13306, 'contact_id' => 2], [])
        );
    }

    /**
     * The VC Portal link (`#?case_id=N` on the case-details screen): the case is
     * authorised and must survive, or the two VC workflows break.
     */
    public function testAuthorisedIdSurvivesWithItsCallerSuppliedValue(): void
    {
        $this->assertSame(
            ['case_id' => 13176],
            AfformArgPolicy::sanitize(['case_id' => 13176], ['case_id'])
        );
    }

    /**
     * Partial entitlement drops only what was not justified.
     */
    public function testMixedEntitlementDropsOnlyTheUnauthorised(): void
    {
        $this->assertSame(
            ['case_id' => 13176],
            AfformArgPolicy::sanitize(
                ['case_id' => 13176, 'contact_id' => 999],
                ['case_id']
            )
        );
    }

    /**
     * Everything else the form legitimately passes must be left exactly alone —
     * the guard is about record ids, not about narrowing the request.
     */
    public function testNonGuardedArgsPassThroughUntouched(): void
    {
        $args = [
            'case_id' => 13306,
            'Case1' => 13306,
            'sid' => 5,
            'redirect' => '/thank-you/',
        ];

        $this->assertSame(
            ['Case1' => 13306, 'sid' => 5, 'redirect' => '/thank-you/'],
            AfformArgPolicy::sanitize($args, [])
        );
    }

    /**
     * Authorising a key that was never supplied must not invent it.
     */
    public function testAllowingAnAbsentKeyAddsNothing(): void
    {
        $this->assertSame(
            ['contact_id' => 66],
            AfformArgPolicy::sanitize(['contact_id' => 66], ['case_id', 'contact_id'])
        );
    }

    // --- fill modes --------------------------------------------------------

    /**
     * `entity` and `join` must be blocked outright, NOT filtered by key.
     *
     * They load a record from arbitrary caller-supplied field values rather than
     * an id, so none of the five guarded names appears and key filtering cannot
     * see them. This was a live anonymous PII disclosure the guard's first
     * version did not close:
     *
     *   {"name":"afformMASRCSForm","fillMode":"join",
     *    "args":{"Organization1":[{"joins":{"Address":[{"city":"Toronto"}]}}]}}
     *
     * returned a real client street address, and the same shape returned Email
     * and Phone. Blocking is safe because those modes serve autocomplete
     * widgets and no MAS public form has one.
     */
    public function testEntityAndJoinFillModesAreBlocked(): void
    {
        $this->assertTrue(AfformArgPolicy::isBlockedFillMode('entity'));
        $this->assertTrue(AfformArgPolicy::isBlockedFillMode('join'));
    }

    /**
     * `form` is filtered key by key, not blocked — blocking it would break every
     * legitimate tokenised link.
     */
    public function testFormFillModeIsNotBlocked(): void
    {
        $this->assertFalse(AfformArgPolicy::isBlockedFillMode('form'));
        $this->assertSame('form', AfformArgPolicy::FILL_MODE_FORM);
    }

    /**
     * Comparison is strict, so a missing or oddly-cased mode cannot slip past
     * the blocked list into a mode core would then treat as something else.
     */
    public function testUnknownFillModesAreNotTreatedAsBlocked(): void
    {
        $this->assertFalse(AfformArgPolicy::isBlockedFillMode(null));
        $this->assertFalse(AfformArgPolicy::isBlockedFillMode(''));
        $this->assertFalse(AfformArgPolicy::isBlockedFillMode('JOIN'));
    }
}
