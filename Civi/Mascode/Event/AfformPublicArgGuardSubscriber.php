<?php

// File: Civi/Mascode/Event/AfformPublicArgGuardSubscriber.php

namespace Civi\Mascode\Event;

use Civi\Core\Service\AutoSubscriber;
use Civi\Mascode\Security\AfformArgPolicy;
use CRM_Mascode_ExtensionUtil as E;

/**
 * Stop an anonymous caller from prefilling MAS public forms with ids it picked.
 *
 * The hole
 * --------
 * `civicrm/ajax/api4` is declared `access_callback=1` (core's
 * CRM/Core/xml/Menu/Api4.xml), so the endpoint itself is open, and the seven MAS
 * client-facing afforms are `is_public=true` with `permission=["*always allow*"]`
 * so `Afform.prefill` runs for anyone. Every entity on those forms is declared
 * `security="FBAC"`, which makes FormDataModel::getSecureApi4() issue its reads
 * with `checkPermissions => FALSE` — the form's own configuration is meant to be
 * the only limit.
 *
 * That model holds right up to the point where the caller chooses the id. The
 * forms are designed to be opened from a lifecycle email carrying a signed
 * `?_aff=` JWT whose `afformArgs` supply `case_id` / `contact_id`, but
 * `Afform.prefill` also accepts `args` straight from the request, and nothing
 * required them to have come from the token. So this, with no cookie, no session
 * and no token, returned HTTP 200 and real data:
 *
 *   POST civicrm/ajax/api4/Afform/prefill
 *   params={"name":"afformProjectCloseClientFeedback","fillMode":"form",
 *           "args":{"case_id":13306}}
 *
 * Case ids are sequential integers, so iterating them harvested every project's
 * client feedback. `contact_id` was worse on afformMASRCSForm, where the
 * relationship autofills walked one contact id out to their employer
 * organisation and then to that organisation's President and Executive
 * Director. Confirmed on production 2026-08-28 (task #159); reproduced on dev
 * against all seven forms.
 *
 * The guard
 * ---------
 * `civi.api.prepare` fires before AbstractProcessor::_run(), which is the last
 * point at which `args` can still be edited — the `civi.afform.prefill` event is
 * dispatched per entity AFTER that entity has already been loaded, so it is too
 * late to be a gate. Here, for a guarded form doing a whole-form fill, each
 * caller-supplied record id must be justified; anything unjustified is dropped
 * and the fieldset renders blank.
 *
 * A dropped argument does not break any of the real ways in a MAS form is
 * reached, because in each of them the id does not come from the caller:
 *
 *   - Client opens an emailed `?_aff=` link. The id travels inside the signed
 *     JWT and core copies it out of the authx session in _run() — AFTER this
 *     guard has run on the caller's own args. Where the visitor was already
 *     logged in and authx dropped the session JWT, AfformTokenPrefillSubscriber
 *     restores it from the verified `X-Civi-Auth-Afform` header instead. Both
 *     paths verify a signature; neither is touched here. This guard therefore
 *     needs no crypto of its own.
 *   - VC clicks "Submit Project Definition" / "Submit Close Report" on the VC
 *     Portal case-details screen. Those links DO put the id in the URL
 *     (`ang/afsearchMASCaseDetailsVC.aff.html`), so the VC is authorised
 *     explicitly, by the entitlement test below.
 *   - Anyone opens a form cold from the website (the RCS and SAS surveys). No
 *     ids are supplied, so there is nothing to drop.
 *
 * Case entitlement mirrors the VC Portal's documented filter-as-security
 * predicate — Civi/Mascode/Managed/SavedSearch_Case_Details_VC.mgd.php, spec at
 * ~/gdrive-brianpkm/3-Resources/mascode-vc-portal-security-spec.md — rather than
 * inventing a second rule: a case is entitled when it sits in the
 * Sent-for-Assignment pool, or when the visitor is one of its active Case
 * Coordinators. Anything the guard allows through is therefore something the
 * visitor can already read on the portal's own case-details screen.
 *
 * Note the first branch is narrowed here to reads by an authenticated CiviCRM
 * user; see isCaseEntitled() for why, and for the assumption that narrowing
 * rests on.
 *
 * A permission-checked API read would NOT work as the test here. VC Portal
 * users have no case ACLs at all — that is exactly why the portal runs its
 * displays `acl_bypass=TRUE` and puts the entitlement predicate in the saved
 * search — so a checked read returns nothing for a VC and would break the very
 * flow this preserves.
 *
 * The guard covers `Afform.submit` as well, since Submit extends the same
 * AbstractProcessor, defaults to `fillMode='form'`, and turns these same args
 * into the ids it writes to (loadEntities() -> _entityIds -> fillIdFields()).
 * Before this, a crafted submit could have written client feedback onto any case
 * id. Because an allowed id is a WRITE target there, entitlement is stricter for
 * submit than for prefill — see isCaseEntitled().
 *
 * Only `fillMode: "form"` has its args filtered key by key. EVERY other fill
 * mode is refused outright — an allowlist, not a list of known-bad modes. Those
 * modes load a record from arbitrary caller-supplied field values with no id and
 * no scoping to a parent record, which was a second, live anonymous PII
 * disclosure that this guard's first version did not close; and no MAS public
 * form has an autocomplete widget to drive them. The reasoning, and why core
 * does NOT validate them despite appearances, is in AfformArgPolicy's docblock.
 *
 * A refused READ drops the argument and the fieldset renders blank. A refused
 * WRITE throws, because there the argument IS the record being written to and
 * dropping it would create the submission attached to nothing, silently, behind
 * a normal confirmation screen. See refuse().
 *
 * Task: #159.
 * Tests: tests/Unit/Security/AfformArgPolicyTest.php (policy rules, runs in CI),
 *        tests/Security/AfformPublicArgGuardTest.php (`cv scr`, entitlement),
 *        tests/Security/afform-prefill-anon-probe.sh (real HTTP, anonymous).
 */
class AfformPublicArgGuardSubscriber extends AutoSubscriber
{
    /**
     * Permissions that mark a genuine staff user, who is exempt.
     *
     * "edit all contacts" is the codebase's established staff gate for afform
     * access, and the two permissions that look like obvious additions are both
     * wrong here, for the same reason: VC accounts hold them.
     *
     * Measured on dev 2026-08-28 across three accounts:
     *
     *   account            WP role         edit all  view all  all cases
     *   Allan Reitzes      subscriber      no        no        no
     *   Sue Pulfer         contributor     no        YES       YES
     *   Brian Flett        administrator   YES       YES       YES
     *
     * So "access all cases and activities" would exempt Sue, and production VCs
     * hold it too. "view all contacts" would also exempt Sue. Either one turns
     * the entitlement test below into a no-op for a real VC — and because VC
     * accounts are not consistently roled, that would not reliably show up in
     * testing. Only "edit all contacts" separates staff from VCs in this data.
     *
     * This list is therefore deliberately NARROWER than
     * VcNativeScreenGuardSubscriber::STAFF_PERMISSIONS, which also accepts
     * "view all contacts". That guard decides whether to serve a native CiviCRM
     * page, where holding "view all contacts" genuinely does entitle the user to
     * the screen; this one decides whose record ids to honour, which is a
     * narrower question. The difference is intentional, not drift.
     */
    private const STAFF_PERMISSIONS = [
        'administer CiviCRM',
        'edit all contacts',
    ];

    /**
     * AbstractProcessor actions that only READ.
     *
     * The six subclasses are prefill and getOptions (read); submit, submitDraft,
     * submitFile and process (write). Listed as an allowlist of readers so that
     * a new processor action is treated as a write until someone decides
     * otherwise.
     */
    private const READ_ACTIONS = ['prefill', 'getOptions'];

    public static function getSubscribedEvents(): array
    {
        return [
            // Priority -1000 (later than core's W_LATE = -100) so this guard has
            // the LAST word on args. Nothing currently re-adds args on this
            // event, but a guard that runs in the middle only holds while that
            // stays true.
            'civi.api.prepare' => [
                ['onApiPrepare', -1000],
            ],
        ];
    }

    public function onApiPrepare($event): void
    {
        // Outside the try: a failure to even read the request would otherwise
        // leave $apiRequest null and the catch with nothing to fail closed ON.
        $apiRequest = $event->getApiRequest();

        // Cheapest possible rejection first: this fires for EVERY API call, and
        // it must happen before the Afform.get below, which is itself an API
        // call that would otherwise re-enter this handler.
        if (!$apiRequest instanceof \Civi\Api4\Action\Afform\AbstractProcessor) {
            return;
        }

        try {
            $args = $apiRequest->getArgs();
            if (!$args) {
                // Nothing to authorise — the overwhelmingly common case,
                // including every tokenised link, whose ids arrive from the
                // signed JWT rather than from the caller. Worth short-circuiting
                // before the Afform.get below.
                return;
            }

            // ALLOWLIST, not a denylist. Only `form` has args that can be
            // filtered key by key; everything else is refused outright.
            //
            // Naming `entity` and `join` as the blocked modes would be wrong,
            // because core does not branch the way that framing implies:
            // loadEntities() tests `=== 'join'` and sends EVERY other value —
            // 'entity', '', null, 'JOIN', 'xyz' — down one identical else path.
            // So the mode blocked by name and an unrecognised mode are the same
            // code in core, and a denylist would be right only by accident. That
            // is the same shape as the `is_public` bug fixed a commit ago.
            $fillMode = $apiRequest->getFillMode();
            $isFormFill = AfformArgPolicy::isFilterableFillMode($fillMode);

            // In `form` mode only the five id args can load a record, so their
            // absence means there is nothing to authorise. In any other mode the
            // args are arbitrary field matches, so all of them matter.
            $guardedKeys = AfformArgPolicy::guardedKeys($args);
            if ($isFormFill && !$guardedKeys) {
                return;
            }

            // Not empty(): a form legitimately named "0" is falsy, and this
            // path returns WITHOUT clearing args. Core's ValidateFieldsSubscriber
            // (priority 50, so ahead of this guard at -1000) already rejects a
            // missing name, so this is precision rather than a live hole.
            $formName = $apiRequest->getName();
            if ($formName === null || $formName === '') {
                return;
            }

            // Staff first, and before the Afform.get: they are exempt either
            // way, and exiting here keeps a transient failure in that lookup
            // from blanking a staff user's form.
            if ($this->isStaff()) {
                return;
            }

            if (!AfformArgPolicy::isGuardedForm($this->getAfform($formName))) {
                return;
            }

            $contactId = (int) (\CRM_Core_Session::getLoggedInContactID() ?: 0);

            // `prefill` and `getOptions` read; `submit`, `submitDraft`,
            // `submitFile` and `process` write. Enumerating the readers rather
            // than excluding one writer means a read-only processor action added
            // to core later is treated as a WRITE — the safe direction to be
            // wrong in.
            $isWrite = !in_array($apiRequest->getActionName(), self::READ_ACTIONS, true);

            if (!$isFormFill) {
                // These modes load a record from arbitrary caller-supplied field
                // values with no id and no scoping to a parent record, and no
                // MAS public form has an autocomplete widget to drive them.
                //
                // Clear the args BEFORE reporting. refuse() only logs and, on a
                // write, throws — it does not touch the request. Leaving the
                // clearing to it once let `fillMode: "join"` straight through
                // again while every other mode still looked refused, because the
                // others load nothing anyway and so a leak was invisible in all
                // of them but the one that mattered.
                $apiRequest->setArgs([]);
                $this->refuse($apiRequest, $formName, ['fillMode:' . $fillMode], $contactId, $isWrite);
                return;
            }

            $allowed = [];
            foreach ($guardedKeys as $key) {
                if ($this->isAuthorized($key, $args[$key], $contactId, $isWrite)) {
                    // Write back the normalised id rather than the caller's
                    // spelling, so what was authorised is exactly what core
                    // queries — no room for a cast here to disagree with a
                    // coercion in the database.
                    $args[$key] = (int) $args[$key];
                    $allowed[] = $key;
                }
            }

            $rejected = array_values(array_diff($guardedKeys, $allowed));
            if (!$rejected) {
                $apiRequest->setArgs($args);
                return;
            }

            $apiRequest->setArgs(AfformArgPolicy::sanitize($args, $allowed));
            $this->refuse($apiRequest, $formName, $rejected, $contactId, $isWrite);
        } catch (\Throwable $e) {
            if ($e instanceof \Civi\API\Exception\UnauthorizedException) {
                // Our own refusal — let it travel.
                throw $e;
            }
            // FAIL CLOSED. Letting core proceed with the caller's args is the
            // pre-existing vulnerability, so an unexpected failure here must not
            // become the way back to it. Staff have already returned above, so
            // the cost of being wrong is a blank fieldset for a non-staff
            // visitor on a public form — against the cost of failing open, which
            // is the record.
            try {
                $apiRequest->setArgs([]);
            } catch (\Throwable $inner) {
                // Nothing further we can safely do; the log line is the only
                // signal that this request went unguarded.
            }
            \Civi::log()->error(
                'AfformPublicArgGuardSubscriber.php - Guard failed; args cleared: ' . $e->getMessage()
            );
        }
    }

    /**
     * Record the refusal, and on a write turn it into a hard error.
     *
     * On a READ, dropping the argument is the right failure: the fieldset
     * renders blank, exactly as it does for someone who opened the form with no
     * arguments at all.
     *
     * On a WRITE it is not. The rejected id IS the record the submit would have
     * written to — `Case1` unresolved on afformProjectCloseClientFeedback means
     * the feedback Activity, whose `data` names `case_id: 'Case1'`, is created
     * attached to nothing while the visitor sees the normal confirmation. Silent
     * data loss is a worse outcome than a refusal, and it is also much harder to
     * diagnose, so a write that loses its target stops.
     *
     * @param string[] $rejected
     * @throws \Civi\API\Exception\UnauthorizedException
     */
    private function refuse(
        $apiRequest,
        string $formName,
        array $rejected,
        int $contactId,
        bool $isWrite
    ): void {
        $context = [
            'afform' => $formName,
            'action' => $apiRequest->getActionName(),
            'rejected' => $rejected,
            'logged_in_contact' => $contactId ?: null,
        ];
        $message = 'AfformPublicArgGuardSubscriber.php - Refused unauthorised afform args';

        // Both levels land in the SAME file at the same volume:
        // CRM_Core_Error_Log::log() maps every level onto debug_log_message(),
        // and createDebugLogger() builds a Log_file with no priority mask. The
        // distinction here buys greppability, not disk — an earlier version of
        // this comment claimed it capped log growth under an enumeration sweep,
        // which is simply not true of CiviCRM's logger. If refusal volume ever
        // becomes a real problem it needs per-IP suppression, not a level.
        //
        // Note the write path below adds more than this line: core's
        // CRM_Api4_Page_AJAX logs a 403 at warning WITH the exception, which
        // expands to a full backtrace.
        if ($contactId) {
            \Civi::log()->warning($message, $context);
        } else {
            \Civi::log()->info($message, $context);
        }

        if ($isWrite) {
            // show_detailed_error is what makes the message visible to someone
            // without "view debug output" — i.e. to every client and VC. Without
            // it CRM_Api4_Page_AJAX replaces the text with "Sorry an error
            // occurred… (Error ID: …)", which reads as a server fault and gets
            // reported as a bug rather than understood. The message deliberately
            // names no record id, so showing it discloses nothing; core's own
            // equivalent throw in AbstractProcessor::_run() does the same.
            throw new \Civi\API\Exception\UnauthorizedException(
                E::ts('This form cannot be submitted with the supplied record reference.'),
                ['show_detailed_error' => true]
            );
        }
    }

    /**
     * Is the caller entitled to this particular record id?
     *
     * @param string $key       One of AfformArgPolicy::GUARDED_ID_ARGS.
     * @param mixed  $value     The caller-supplied id.
     * @param int    $contactId Logged-in contact, or 0 for anonymous.
     * @param bool   $isWrite   True for submit/process, false for prefill.
     */
    private function isAuthorized(string $key, $value, int $contactId, bool $isWrite): bool
    {
        // Anonymous callers are never entitled to name an id. Their legitimate
        // ids come from the signed token, which core injects later.
        if (!$contactId) {
            return false;
        }

        // Reject anything that is not a single positive integer id outright,
        // rather than trying to authorise an array or a non-numeric string.
        if (is_array($value) || !is_numeric($value) || (int) $value <= 0) {
            return false;
        }
        $id = (int) $value;

        if ($key === 'case_id') {
            return $this->isCaseEntitled($id, $contactId, $isWrite);
        }

        // Everything else — contact_id, activity_id, event_id, participant_id.
        // No MAS flow supplies any of them in a URL: the only URL-arg flow in
        // the codebase is the two `#?case_id=` VC Portal links
        // (ang/afsearchMASCaseDetailsVC.aff.html), and all seven forms are
        // `placement: ['msg_token_single']` only, so none is ever embedded on a
        // contact-summary screen that would supply contact_id. Token-supplied
        // values still work, because they never pass through here.
        //
        // contact_id is NOT excepted for "it's your own record". That looks
        // harmless and is not: on afformMASRCSForm the `relationship:` autofills
        // walk self -> employer organisation -> that organisation's President
        // and Executive Director, so a self id yields other people's names,
        // emails, phone and address. Since nothing legitimate needs it, the
        // safe answer and the free answer are the same one.
        return false;
    }

    /**
     * The VC Portal's case entitlement predicate, reused verbatim in intent.
     *
     * @see \Civi\Mascode\Managed\SavedSearch_Case_Details_VC (mgd file)
     */
    private function isCaseEntitled(int $caseId, int $contactId, bool $isWrite): bool
    {
        // (a) In the Sent-for-Assignment pool — visible to every VC by design.
        //
        // Two conditions the saved search does not state, because its own
        // context supplies them and this guard's does not:
        //
        //   - "access CiviCRM". The portal screen carrying this predicate
        //     (afsearchMASCaseDetailsVC) is behind that permission, so without
        //     it here the guard would be strictly MORE permissive than the rule
        //     it claims to mirror — any logged-in contact at all, including a
        //     client contact who has never been a VC, would be entitled to every
        //     pooled case.
        //   - Reads only. The same entitlement decides Afform.submit, where an
        //     allowed case_id is the record that gets WRITTEN to. Nobody needs
        //     to file a Project Definition or Close Report against a case merely
        //     because it is unassigned; on both VC forms the submitter is the
        //     Case Coordinator, so branch (b) already covers the real flow.
        //
        //     ASSUMPTION, checked on dev 2026-08-28 but NOT an invariant: all
        //     9 pooled cases are `service_request`, and the two VC forms this
        //     restricts are Project forms whose buttons sit in the
        //     `.mas-vc-project` region. Note what actually hides that region —
        //     css/vc-case-detail.css keys on the Project card returning NO
        //     RESULTS, not on the case type, so a pooled case that is a Project
        //     (or a service_request carrying Project custom-field data) would
        //     still show the buttons. The window is closed today by the data,
        //     not by the CSS.
        //
        //     If that changes, a VC submitting against a pooled case gets a
        //     refusal rather than silent data loss, because refuse() throws on a
        //     write. That is the failure this assumption is allowed to have.
        if (!$isWrite && \CRM_Core_Permission::check('access CiviCRM')) {
            $pooled = \Civi\Api4\CiviCase::get(false)
                ->addSelect('id')
                ->addWhere('id', '=', $caseId)
                ->addWhere('status_id:name', '=', 'Sent for Assignment')
                ->addWhere('is_deleted', '=', false)
                ->setLimit(1)
                ->execute()
                ->count();
            if ($pooled) {
                return true;
            }
        }

        // (b) Coordinated by this contact — an active "Case Coordinator is" row.
        return (bool) \Civi\Api4\RelationshipCache::get(false)
            ->addSelect('id')
            ->addWhere('case_id', '=', $caseId)
            ->addWhere('near_relation:name', '=', 'Case Coordinator is')
            ->addWhere('near_contact_id', '=', $contactId)
            ->addWhere('is_active', '=', true)
            ->setLimit(1)
            ->execute()
            ->count();
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

    /**
     * @return array The Afform record, or [] when it cannot be read.
     */
    private function getAfform(string $formName): array
    {
        $afform = \Civi\Api4\Afform::get(false)
            ->addSelect('name', 'permission')
            ->addWhere('name', '=', $formName)
            ->setLimit(1)
            ->execute()
            ->first();

        return $afform ?? [];
    }
}
