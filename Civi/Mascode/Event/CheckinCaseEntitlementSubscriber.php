<?php

declare(strict_types=1);

// File: Civi/Mascode/Event/CheckinCaseEntitlementSubscriber.php

namespace Civi\Mascode\Event;

use Civi\Core\Service\AutoSubscriber;
use CRM_Mascode_ExtensionUtil as E;

/**
 * D10 for afformMASProjectCheckin: the form re-derives, server-side, that the
 * visitor coordinates the case it is about to show or write to.
 *
 * WHY THIS EXISTS WHEN AfformPublicArgGuardSubscriber ALREADY GUARDS case_id
 * ----------------------------------------------------------------------------
 * That guard filters the args the CALLER sent, on `civi.api.prepare`, and it
 * deliberately does not see token-supplied ids: core copies `afformArgs` out of
 * the signed JWT inside AbstractProcessor::_run(), which runs AFTER that event.
 * Its own docblock says so, and that is correct for the seven Phase 0 forms —
 * there the token is minted by a lifecycle rule for the one case the email is
 * about, and a tampered JWT fails its signature.
 *
 * It is not sufficient here, for a reason that has nothing to do with forgery:
 *
 *   A MINTED LINK OUTLIVES THE ENTITLEMENT IT WAS MINTED UNDER.
 *
 * The digest mints one link per (VC, project) and the TTL follows
 * `checksum_timeout`, which is 60 days on this install (D11) — deliberately
 * longer than the monthly cadence. Case roles change inside that window: a
 * project gets reassigned, a volunteer steps back, a coordinator row is ended.
 * The JWT keeps verifying perfectly, because a signature attests to what was
 * true when it was signed and to nothing else. Without this subscriber a VC
 * removed from a project in week 1 can still open that project's check-in form
 * in week 8, read its details and file an answer against it.
 *
 * The spec calls D10 one-way and inherited, and the one non-negotiable in the
 * build: on these forms the predicate IS the security model, because every
 * entity is `security="FBAC"` and therefore reads with `checkPermissions =>
 * FALSE`. There is no permission engine underneath to catch a mistake here.
 *
 * WHERE IT HOOKS, AND WHY THOSE TWO POINTS
 * ----------------------------------------------------------------------------
 * Prefill: `civi.afform.prefill` at priority 500. That event is dispatched once
 * per entity, and core's loadEntities() has already run loadEntity() by then —
 * which is why AfformPublicArgGuardSubscriber's docblock calls it "too late to
 * be a gate". That is true of ENTITY-NAMED args (`Case1=[...]`), and those are
 * inert on MAS forms. It is NOT true of `case_id`: nothing in core reads that
 * name until the CaseAutofill BEHAVIOR reads it, and behaviors subscribe to
 * this same event at priority 99. So 500 is after AfformTokenPrefillSubscriber
 * has restored token args (1000) and before anything consumes them (99). That
 * ordering is the whole correctness argument for the read path, and it is
 * asserted in tests/Unit/Event/CheckinEntitlementWiringTest.php so a priority
 * edit cannot quietly invert it.
 *
 * Submit: `civi.afform.submit` at priority 500. Core's own
 * `processGenericEntity` is registered at priority 0 and is what writes, so any
 * handler above 0 runs first and can stop the write by throwing.
 *
 * REFUSAL BEHAVIOUR
 * ----------------------------------------------------------------------------
 * A refused READ drops `case_id` and the fieldset renders blank — the same
 * shape AfformPublicArgGuardSubscriber uses, and the same thing a visitor sees
 * who opened the form with no arguments.
 *
 * A refused WRITE throws. Dropping the id on a write would create the check-in
 * activity attached to no case, silently, behind the normal confirmation
 * screen: a lost answer that looks to the VC exactly like a delivered one. That
 * is strictly worse than an error, and it matches how the existing guard
 * reasons about the same choice.
 *
 * WHY THE STAFF LIST IS DUPLICATED HERE RATHER THAN SHARED
 * ----------------------------------------------------------------------------
 * Deliberate, and not drift. Production carries an UNCOMMITTED HAND-PATCH in
 * both `AfformPublicArgGuardSubscriber.php` and `Security/AfformArgPolicy.php`
 * (recorded in handoff #1093 and in the mascode memory note
 * reference_prod_uncommitted_security_patch). A deploy whose incoming diff
 * touches either file conflicts mid-deploy, on a live site, mid-`git pull`.
 * Hoisting a shared constant into `AfformArgPolicy` would be the tidier
 * refactor and would do exactly that. So this file stands alone until that
 * patch is reconciled, and the duplication is the cheaper of the two costs.
 *
 * The list is the narrow one for the same reason the other guard gives: on this
 * data only `edit all contacts` separates staff from VCs. "access all cases and
 * activities" and "view all contacts" are both held by production VC accounts,
 * so either would turn the entitlement test below into a no-op for a real VC.
 *
 * Tests: tests/Unit/Event/CheckinEntitlementWiringTest.php (wiring + priorities,
 *        runs in CI), tests/Security/CheckinEntitlementTest.php (`cv scr`, real
 *        entitlement against live data).
 */
class CheckinCaseEntitlementSubscriber extends AutoSubscriber
{
    /**
     * The form this subscriber governs.
     *
     * Deliberately one form, not a list. Every other MAS public form reaches a
     * case through a token minted for a single lifecycle event, answered within
     * days; this one is minted in bulk, for a set of cases, against a 60-day
     * TTL. Widening this to the other forms is a decision with its own evidence,
     * not a tidy-up.
     */
    public const FORM_NAME = 'afformMASProjectCheckin';

    /**
     * Priority for both subscriptions.
     *
     * Read path: must sit BELOW AfformTokenPrefillSubscriber (1000), which
     * restores token args for already-logged-in visitors, and ABOVE core's
     * autofill behaviors (99), which are what actually consume `case_id`.
     * Write path: must sit ABOVE core's processGenericEntity (0), which writes.
     */
    public const PRIORITY = 500;

    /**
     * @see AfformPublicArgGuardSubscriber::STAFF_PERMISSIONS — duplicated on
     *      purpose; see this class's docblock for why it is not shared.
     */
    private const STAFF_PERMISSIONS = [
        'administer CiviCRM',
        'edit all contacts',
    ];

    public static function getSubscribedEvents(): array
    {
        return [
            'civi.afform.prefill' => [
                ['onPrefill', self::PRIORITY],
            ],
            'civi.afform.submit' => [
                ['onSubmit', self::PRIORITY],
            ],
        ];
    }

    /**
     * Drop an unentitled `case_id` before any behavior loads the case.
     */
    public function onPrefill($event): void
    {
        try {
            $apiRequest = $event->getApiRequest();
            if (!$this->isThisForm($event)) {
                return;
            }

            $args = $apiRequest->getArgs();
            $caseId = $this->positiveInt($args['case_id'] ?? null);

            // No case named: nothing to authorise, and the form renders blank
            // on its own. Note this is also what a SECOND dispatch of this
            // event sees, after the first one stripped the id — the strip is
            // idempotent rather than re-reported once per entity.
            if ($caseId === null) {
                return;
            }

            if ($this->isEntitled($caseId)) {
                return;
            }

            unset($args['case_id']);
            // Also strip the entity-named form, for tidiness ONLY — and an
            // earlier comment here claimed otherwise, which would have
            // misled the next editor.
            //
            // It is NOT a second line of defence. Core's loadEntities() reads
            // `$this->args['Case1']` and calls loadEntity() BEFORE dispatching
            // `civi.afform.prefill`, so if a future layout edit ever made
            // `Case1=N` live, core would have loaded the case before this hook
            // ran. This class's own docblock says as much. What actually holds
            // that door shut is the form declaring no `autofill` id field and
            // no `url-autofill`, asserted by
            // CheckinEntitlementWiringTest::testFormDeclaresNoSecondDoorForCallerSuppliedIds().
            unset($args['Case1']);
            $apiRequest->setArgs($args);

            $this->report($caseId, 'prefill');
        } catch (\Throwable $e) {
            // FAIL CLOSED. The alternative to this guard is the exposure it
            // exists to prevent, so an unexpected failure must not become the
            // way back to it. Blanking a fieldset is the cost of being wrong.
            $this->failClosed($event, $e);
        }
    }

    /**
     * Refuse a write against a case this visitor does not coordinate.
     *
     * @throws \Civi\API\Exception\UnauthorizedException
     */
    public function onSubmit($event): void
    {
        if (!$this->isThisForm($event)) {
            return;
        }

        // NOTE this event is dispatched once PER ENTITY, so this runs for both
        // Case1 and Activity1. The check is idempotent and cheap, and running
        // it on every dispatch means it cannot be skipped by a future change to
        // the entity sort order.
        //
        // ⚠ WHAT THIS HOOK ACTUALLY CONTRIBUTES, stated honestly because the
        // first version of this class overstated it. `Afform.submit` runs
        // loadEntities() too, so onPrefill() has ALREADY stripped an
        // unentitled `case_id` before this fires — which means in the real
        // stale-link flow the write is stopped by the READ hook, and the
        // refusal below comes from the no-case branch rather than from
        // isEntitled(). This hook is defence in depth: it is what stops a
        // write if the read path is ever bypassed, reordered or disabled. It
        // is not the thing doing the work today, and the tests and notes for
        // this ticket should not imply that it is.
        $caseId = null;
        try {
            $caseId = $this->submittedCaseId($event);
        } catch (\Throwable $e) {
            // Could not determine the target at all. Refuse rather than guess:
            // a write whose case cannot be identified is exactly the silent
            // data loss this branch exists to avoid.
            \Civi::log()->error(
                'CheckinCaseEntitlementSubscriber.php - Could not resolve the submitted case: ' . $e->getMessage()
            );
            $this->refuse();
        }

        if ($caseId === null) {
            // A check-in with no case is meaningless and would be created
            // attached to nothing. Core's required-field handling does not
            // cover it, because the case arrives as an argument rather than as
            // a field the visitor filled in.
            // On the real stale-link path this is the branch that fires, NOT
            // the entitlement branch below: onPrefill() has already stripped
            // `case_id`, so by submit time there is no case left to test. The
            // message says so, because an operator reading "no case" and
            // concluding the form is broken would be chasing the wrong thing.
            \Civi::log()->warning(
                'CheckinCaseEntitlementSubscriber.php - Refused a check-in with no case '
                . '(usually means the read guard already refused this case for this visitor)',
                ['afform' => self::FORM_NAME]
            );
            $this->refuse();
        }

        if (!$this->isEntitled($caseId)) {
            $this->report($caseId, 'submit');
            $this->refuse();
        }
    }

    // ------------------------------------------------------------------

    /**
     * Is the visitor an active Case Coordinator of this case?
     *
     * Staff are exempt, as they are on every other MAS public form, so that an
     * administrator can open a VC's form to diagnose it.
     *
     * NOT a permission-checked read. VC Portal accounts hold no case ACLs at
     * all — that is precisely why the portal runs its displays `acl_bypass` and
     * puts the predicate in the saved search — so a checked read returns
     * nothing for a real VC and would refuse the only flow this protects.
     *
     * ⚠ `is_current`, AND IT DELIBERATELY DIVERGES FROM THE TWO PREDICATES IT
     * OTHERWISE MIRRORS. `AfformPublicArgGuardSubscriber::isCaseEntitled()` and
     * `SavedSearch_Case_Details_VC.mgd.php` both test `is_active`. An earlier
     * version of this method copied them, and review caught that doing so made
     * the guard miss the very case it was written for.
     *
     * `is_active` is a flag somebody sets. `is_current` is core's
     * `is_active = 1 AND (start_date <= today OR start_date IS NULL) AND
     * (end_date >= today OR end_date IS NULL)`
     * (`IsCurrentFieldSpecProvider::renderIsCurrentSql()`), and it is what
     * core's own case-role reader uses
     * (`civi_case/Civi/Afform/Behavior/ContactAutofillBasedOnCase.php`).
     *
     * The two come apart constantly, because there are two ways to end a case
     * role and only one of them clears the flag:
     *
     *   - Ended through the case-roles UI -> `CRM_Case_BAO_Case::endCaseRole()`
     *     sets BOTH `is_active = 0` and `end_date = now`. Either test catches it.
     *   - Ended by setting an end date on the Relationships tab, by an import,
     *     by a bulk data fix, or by the *Disable expired relationships* job not
     *     having run yet -> `is_active` stays 1. ONLY `is_current` catches it.
     *
     * Measured on the 2026-09-21 dev clone (a faithful production clone): of
     * 481 `Case Coordinator is` rows with `is_active = TRUE`, **299 are ended**
     * — `end_date` in the past, some as far back as March 2025 — and 31 of
     * those sit on cases that are not closed. This guard exists to stop a link
     * outliving the role it was minted under; testing `is_active` would have
     * admitted every one of them.
     *
     * The divergence is therefore the point, not drift. The other two decide
     * what the VC Portal DISPLAYS to a logged-in volunteer; this decides
     * whether a public, no-login form hands over a case and accepts a write
     * against it. They should probably all move to `is_current` — recorded for
     * Brian rather than done here, because
     * `AfformPublicArgGuardSubscriber.php` and `Security/AfformArgPolicy.php`
     * carry an UNCOMMITTED PRODUCTION HAND-PATCH and a deploy whose incoming
     * diff touches either conflicts mid-`git pull` on a live site.
     *
     * NOTE the deliberate absence of the other guard's first branch, which also
     * entitles any case sitting in the Sent-for-Assignment pool. That branch
     * exists so a VC can read an unassigned case they might pick up. It has no
     * place here: a check-in asserts something about a project the VC ran, and
     * a pooled case has no coordinator to be. Narrower on purpose.
     */
    private function isEntitled(int $caseId): bool
    {
        if ($this->isStaff()) {
            return true;
        }

        $contactId = (int) (\CRM_Core_Session::getLoggedInContactID() ?: 0);
        if (!$contactId) {
            // Anonymous. On the legitimate path the token has already
            // authenticated the VC into a (fake) session by the time either of
            // these events fires, so no contact here means no token — and a
            // caller with no identity is entitled to nothing.
            return false;
        }

        return (bool) \Civi\Api4\RelationshipCache::get(false)
            ->addSelect('id')
            ->addWhere('case_id', '=', $caseId)
            ->addWhere('near_relation:name', '=', 'Case Coordinator is')
            ->addWhere('near_contact_id', '=', $contactId)
            // `is_current`, NOT `is_active`. See the note below — this is the
            // difference between closing the staleness hole and only appearing
            // to.
            ->addWhere('is_current', '=', true)
            ->setLimit(1)
            ->execute()
            ->count();
    }

    /**
     * The case this submission would write to.
     *
     * Read from the processor's resolved entity ids rather than from the raw
     * args: by submit time core has already turned `case_id` into `Case1`'s id
     * through the same behaviors, and the resolved id is what the write will
     * actually use. Authorising the raw arg while core writes to something else
     * is the classic shape of a guard that passes and protects nothing.
     */
    private function submittedCaseId($event): ?int
    {
        // From the EVENT's resolved entity ids, not from the raw args. By
        // submit time core has already turned `case_id` into Case1's id through
        // the same autofill behaviors, and that resolved id is what the write
        // will use. Authorising the raw arg while core writes somewhere else is
        // the classic shape of a guard that passes and protects nothing.
        foreach ($event->getEntityIds('Case1') as $id) {
            $id = $this->positiveInt($id);
            if ($id !== null) {
                return $id;
            }
        }

        // Fallback for the case where Case1 resolved to nothing: the argument
        // itself. Reached when the visitor supplied a case that does not exist,
        // which must still be refused rather than treated as "no case named".
        return $this->positiveInt($event->getApiRequest()->getArgs()['case_id'] ?? null);
    }

    private function isThisForm($event): bool
    {
        $afform = $event->getAfform();
        return ($afform['name'] ?? null) === self::FORM_NAME;
    }

    /**
     * A single positive integer id, or NULL for anything else.
     *
     * Arrays and non-numeric strings are refused rather than coerced — the same
     * rule AfformArgPolicy applies, and for the same reason: a value that is not
     * an id should not be turned into one on the way to a security check.
     */
    private function positiveInt($value): ?int
    {
        if (is_array($value) || $value === null || !is_numeric($value)) {
            return null;
        }
        $id = (int) $value;
        return $id > 0 ? $id : null;
    }

    private function isStaff(): bool
    {
        foreach (self::STAFF_PERMISSIONS as $permission) {
            if (\CRM_Core_Permission::check($permission)) {
                return true;
            }
        }
        return false;
    }

    private function report(int $caseId, string $phase): void
    {
        \Civi::log()->warning('CheckinCaseEntitlementSubscriber.php - Refused a check-in for an uncoordinated case', [
            'afform' => self::FORM_NAME,
            'phase' => $phase,
            'case_id' => $caseId,
            'logged_in_contact' => (int) (\CRM_Core_Session::getLoggedInContactID() ?: 0) ?: null,
        ]);
    }

    /**
     * `never` rather than `void` on purpose: it tells both the reader and
     * static analysis that every call site below it is unreachable, which is
     * what makes the `$caseId === null` branch in onSubmit() narrow correctly
     * instead of appearing to fall through into isEntitled(int).
     *
     * @throws \Civi\API\Exception\UnauthorizedException
     */
    private function refuse(): never
    {
        // show_detailed_error so the VC sees this sentence rather than core's
        // "Sorry an error occurred (Error ID: …)", which reads as a server
        // fault and gets reported as a broken form. The message names no id, so
        // showing it discloses nothing.
        throw new \Civi\API\Exception\UnauthorizedException(
            E::ts('This check-in link is no longer valid for that project. If you still work on it, please contact the MAS office.'),
            ['show_detailed_error' => true]
        );
    }

    private function failClosed($event, \Throwable $e): void
    {
        if ($e instanceof \Civi\API\Exception\UnauthorizedException) {
            throw $e;
        }
        try {
            $apiRequest = $event->getApiRequest();
            $args = $apiRequest->getArgs();
            unset($args['case_id'], $args['Case1']);
            $apiRequest->setArgs($args);
        } catch (\Throwable $inner) {
            // Nothing further can be done safely; the log line below is the
            // only signal that this request went unguarded.
        }
        \Civi::log()->error(
            'CheckinCaseEntitlementSubscriber.php - Guard failed; case reference cleared: ' . $e->getMessage()
        );
    }
}
