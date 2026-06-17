<?php

// File: Civi/Mascode/Event/AfformTokenStatelessSubscriber.php

namespace Civi\Mascode\Event;

use Civi\Core\Service\AutoSubscriber;

/**
 * Make Afform `_aff` token links work for visitors who are already logged in.
 *
 * Background
 * ----------
 * MAS lifecycle emails (Project Definition, Project Close, etc.) link to public
 * Afforms with `?_aff=Bearer <JWT>`. The token both identifies the recipient
 * (`sub: cid:N`) and carries the prefill args (`afformArgs`, e.g. case_id /
 * contact_id). Core's afform-page flow is designed to be **stateless**:
 * `Civi\Afform\PageTokenCredential` authenticates with `useSession => FALSE`
 * and relies on `CRM_Core_Session::useFakeSession()` — its own code comments
 * note that an existing login cookie is "counter-productive" here.
 *
 * The problem is ordering: `Civi\Authx\Authenticator::login()` calls
 * `checkAlreadyLoggedIn()` BEFORE the fake session is established, and that
 * check inspects both the Civi session (`getLoggedInContactID`) and the CMS
 * user (`getCurrentUserId`). So when a visitor already has a session:
 *   - logged in as the token's own contact  -> "already-logged-in" shortcut
 *     drops the JWT (`'jwt' => NULL`)  -> afformArgs lost -> blank form;
 *   - logged in as a DIFFERENT contact       -> `reject('Cannot login. A
 *     mismatched session is already active.')` -> HTTP 401 at page load.
 * Anonymous visitors hit neither branch (no existing session), so the link
 * works only when logged out. (Reproduced CiviCRM 6.12.2.)
 *
 * Fix
 * ---
 * When a request carries a valid, unexpired afform token AND a session is
 * already active, force the request stateless BEFORE authx runs
 * (`civi.invoke.auth` priority 200 > PageTokenCredential's 105): swap to a fake
 * Civi session and drop the CMS current user for this request only. authx then
 * sees no existing login, authenticates as the token's contact in the fake
 * session, and core injects the afformArgs exactly as it does for anonymous
 * visitors. The effect is that a tokenized form link always opens in the
 * recipient's context — identical to the (working) incognito experience —
 * regardless of who is logged in. The auth cookie is untouched, so the user
 * remains logged in on every other page and tab.
 *
 * Anonymous requests are left completely untouched (early return when no
 * session is active); an invalid/expired/non-afform token is also a no-op,
 * leaving core to handle it.
 */
class AfformTokenStatelessSubscriber extends AutoSubscriber
{
    public static function getSubscribedEvents(): array
    {
        // Priority 200 > PageTokenCredential's onInvoke (105) so the session is
        // already stateless by the time authx evaluates the token.
        return [
            'civi.invoke.auth' => [
                ['onInvokeAuth', 200],
            ],
        ];
    }

    public function onInvokeAuth($event): void
    {
        try {
            // Page request carries ?_aff=...; embedded AJAX calls resend it as
            // the X-Civi-Auth-Afform header (PageTokenCredential::onInvoke).
            $rawToken = $_SERVER['HTTP_X_CIVI_AUTH_AFFORM'] ?? $_REQUEST['_aff'] ?? null;
            if (empty($rawToken) || !is_string($rawToken)) {
                return;
            }

            // Only intervene when a real session is active. Anonymous visitors
            // already work through core's normal stateless flow — leave them be.
            $loggedInContact = \CRM_Core_Session::getLoggedInContactID();
            $loggedInUser = function_exists('get_current_user_id') ? (int) \get_current_user_id() : 0;
            if (empty($loggedInContact) && empty($loggedInUser)) {
                return;
            }

            // Verify the token (signature + expiry) before touching the session,
            // and confirm it is genuinely an afform page token. A bad/expired or
            // unrelated token throws or bails — we then leave the request alone.
            $jwtStr = preg_replace('/^Bearer\s+/i', '', trim($rawToken));
            if ($jwtStr === '' || !preg_match(';^[A-Za-z0-9._-]+$;', $jwtStr)) {
                return;
            }
            $claims = \Civi::service('crypto.jwt')->decode($jwtStr);
            $scopes = isset($claims['scope']) ? explode(' ', (string) $claims['scope']) : [];
            if (!in_array('afform', $scopes, true) || empty($claims['afform'])) {
                return;
            }

            // Force this single request stateless — exactly what core's
            // afform-page flow intends. authx will now authenticate as the
            // token's contact instead of rejecting (401) or dropping the
            // afformArgs (blank form). Cookie/login persist for other requests.
            \CRM_Core_Session::useFakeSession();
            if (function_exists('wp_set_current_user')) {
                \wp_set_current_user(0);
            }

            \Civi::log()->info('AfformTokenStatelessSubscriber.php - Forced stateless session for afform token link', [
                'afform' => $claims['afform'],
                'was_logged_in_contact' => $loggedInContact,
            ]);
        } catch (\Throwable $e) {
            // Invalid/expired token or any failure: never block the request.
            return;
        }
    }
}
