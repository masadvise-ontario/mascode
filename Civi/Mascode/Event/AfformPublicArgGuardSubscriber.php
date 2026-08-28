<?php

// File: Civi/Mascode/Event/AfformPublicArgGuardSubscriber.php

namespace Civi\Mascode\Event;

use Civi\Core\Service\AutoSubscriber;
use Civi\Mascode\Security\AfformArgPolicy;

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
 * id.
 *
 * Task: #159. Tests: tests/Unit/Security/AfformArgPolicyTest.php (policy),
 * scripts/verify-afform-arg-guard.php (end-to-end, `cv scr`).
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

    public static function getSubscribedEvents(): array
    {
        return [
            'civi.api.prepare' => [
                ['onApiPrepare', 0],
            ],
        ];
    }

    public function onApiPrepare($event): void
    {
        try {
            $apiRequest = $event->getApiRequest();

            // Cheapest possible rejection first: this fires for EVERY API call,
            // and it must happen before the Afform.get below, which is itself an
            // API call that would otherwise re-enter this handler.
            if (!$apiRequest instanceof \Civi\Api4\Action\Afform\AbstractProcessor) {
                return;
            }
            if ($apiRequest->getFillMode() !== AfformArgPolicy::GUARDED_FILL_MODE) {
                return;
            }

            $args = $apiRequest->getArgs();
            $guardedKeys = AfformArgPolicy::guardedKeys($args);
            if (!$guardedKeys) {
                // Nothing to authorise — the overwhelmingly common case,
                // including every tokenised link.
                return;
            }

            $formName = $apiRequest->getName();
            if (empty($formName) || !AfformArgPolicy::isGuardedForm($this->getAfform($formName))) {
                return;
            }

            // Staff see everything anyway; leave their args alone.
            if ($this->isStaff()) {
                return;
            }

            $contactId = (int) (\CRM_Core_Session::getLoggedInContactID() ?: 0);

            $allowed = [];
            foreach ($guardedKeys as $key) {
                if ($this->isAuthorized($key, $args[$key], $contactId)) {
                    $allowed[] = $key;
                }
            }

            $rejected = array_values(array_diff($guardedKeys, $allowed));
            if (!$rejected) {
                return;
            }

            $apiRequest->setArgs(AfformArgPolicy::sanitize($args, $allowed));

            // Worth a warning, not an info: on every supported path the ids
            // arrive from a signed token or an entitled portal link, so reaching
            // here means someone supplied an id the site will not honour.
            \Civi::log()->warning(
                'AfformPublicArgGuardSubscriber.php - Dropped unauthorised prefill args',
                [
                    'afform' => $formName,
                    'action' => $apiRequest->getActionName(),
                    'rejected_keys' => $rejected,
                    'logged_in_contact' => $contactId ?: null,
                ]
            );
        } catch (\Throwable $e) {
            // A guard that throws would take the form down with it. Log loudly
            // and let core proceed: the pre-existing behaviour is the fallback,
            // which is why the log line says the guard did not run.
            \Civi::log()->error(
                'AfformPublicArgGuardSubscriber.php - Guard did not run: ' . $e->getMessage()
            );
        }
    }

    /**
     * Is the caller entitled to this particular record id?
     *
     * @param string $key       One of AfformArgPolicy::GUARDED_ID_ARGS.
     * @param mixed  $value     The caller-supplied id.
     * @param int    $contactId Logged-in contact, or 0 for anonymous.
     */
    private function isAuthorized(string $key, $value, int $contactId): bool
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

        switch ($key) {
            case 'case_id':
                return $this->isCaseEntitled($id, $contactId);

            case 'contact_id':
                // Prefilling yourself reveals nothing you cannot already see.
                return $id === $contactId;

            default:
                // activity_id / event_id / participant_id: no MAS flow supplies
                // these in a URL, so there is no entitlement rule to apply and
                // nothing legitimate to preserve. Token-supplied values still
                // work, because they never pass through here.
                return false;
        }
    }

    /**
     * The VC Portal's case entitlement predicate, reused verbatim in intent.
     *
     * @see \Civi\Mascode\Managed\SavedSearch_Case_Details_VC (mgd file)
     */
    private function isCaseEntitled(int $caseId, int $contactId): bool
    {
        // (a) In the Sent-for-Assignment pool — visible to every VC by design.
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
            ->addSelect('name', 'is_public', 'permission')
            ->addWhere('name', '=', $formName)
            ->setLimit(1)
            ->execute()
            ->first();

        return $afform ?? [];
    }
}
