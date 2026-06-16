<?php

// File: Civi/Mascode/Event/AfformTokenPrefillSubscriber.php

namespace Civi\Mascode\Event;

use Civi\Core\Service\AutoSubscriber;
use Civi\Afform\Event\AfformPrefillEvent;

/**
 * Restore Afform token prefill for already-logged-in visitors.
 *
 * Background
 * ----------
 * MAS lifecycle emails (Project Definition, Project Close, etc.) link to public
 * Afforms with a `?_aff=Bearer <JWT>` token. The JWT carries both an identity
 * (`sub: cid:N`) and the prefill arguments (`afformArgs`, e.g. case_id /
 * contact_id) that drive the forms' `autofill="entity_id"` / `case-autofill`
 * entities.
 *
 * Core reads those args from the authx session JWT:
 *   Civi\Api4\Action\Afform\AbstractProcessor::loadData()
 *     $authx = CRM_Core_Session::singleton()->get('authx');
 *     if ($authx && isset($authx['jwt']['afformArgs'])) { ...copy into $this->args... }
 *
 * When the visitor is ALREADY logged in as the token's contact, authx takes the
 * "already-logged-in" shortcut (Civi\Authx\Authenticator::createAlreadyLoggedIn)
 * which stores `'jwt' => NULL`. The afformArgs are therefore dropped, the
 * autofill behaviors find no contact_id/case_id, and the form renders blank.
 * Anonymous visitors get a fresh login (createRedacted) which keeps the JWT, so
 * they prefill correctly. Net effect: the link works logged-out, breaks
 * logged-in. (CiviCRM 6.12.2 core behavior.)
 *
 * Fix
 * ---
 * The raw token is still available on every Afform AJAX call as the
 * `X-Civi-Auth-Afform` header (added by Civi\Afform\PageTokenCredential). When
 * the session JWT was dropped but the request still carries a token, we
 * re-verify it with core's crypto.jwt service (signature + expiry), confirm its
 * scope is `afform` and its target form matches the form being prefilled, then
 * backfill any missing afformArgs into the processor — exactly what core does
 * for anonymous visitors. The autofill behaviors (priority 99 on
 * civi.afform.prefill) then resolve the entities normally.
 *
 * This runs at a higher priority than those behaviors and is strictly additive:
 * it only fills args that are absent, and only when the verified token belongs
 * to this exact form, so it cannot clobber a working anonymous prefill or
 * inject another form's arguments.
 */
class AfformTokenPrefillSubscriber extends AutoSubscriber
{
    public static function getSubscribedEvents(): array
    {
        // Priority 1000 > ContactAutofill/CaseAutofill (99), so args are
        // restored before those behaviors read getArgs()['contact_id'] etc.
        return [
            'civi.afform.prefill' => [
                ['onAfformPrefill', 1000],
            ],
        ];
    }

    public function onAfformPrefill(AfformPrefillEvent $event): void
    {
        try {
            $apiRequest = $event->getApiRequest();

            // Only act when core's session-JWT path produced no afformArgs.
            // If the session already carries the JWT (anonymous visitor), core
            // has already populated the args — leave that working path alone.
            $authx = \CRM_Core_Session::singleton()->get('authx');
            if (!empty($authx['jwt']['afformArgs'])) {
                return;
            }

            // The page token is re-sent on each AJAX call as this header.
            $rawToken = $_SERVER['HTTP_X_CIVI_AUTH_AFFORM'] ?? $_REQUEST['_aff'] ?? null;
            if (empty($rawToken) || !is_string($rawToken)) {
                return;
            }

            // Strip the "Bearer " credential-format prefix, if present.
            $jwt = preg_replace('/^Bearer\s+/i', '', trim($rawToken));
            if ($jwt === '' || !preg_match(';^[A-Za-z0-9._-]+$;', $jwt)) {
                return;
            }

            // Verify signature + expiry using the same service core uses. A
            // forged/expired token throws and we silently bail (no prefill).
            $claims = \Civi::service('crypto.jwt')->decode($jwt);

            $scopes = isset($claims['scope']) ? explode(' ', (string) $claims['scope']) : [];
            if (!in_array('afform', $scopes, true)) {
                return;
            }
            if (empty($claims['afformArgs'])) {
                return;
            }

            // Security: the token must target the form currently being prefilled,
            // so a valid token for form A can never seed form B's arguments.
            $thisForm = $apiRequest->getName() ?? ($event->getAfform()['name'] ?? null);
            if (empty($claims['afform']) || $claims['afform'] !== $thisForm) {
                return;
            }

            // afformArgs may decode to stdClass — normalize to an array.
            $afformArgs = json_decode(json_encode($claims['afformArgs']), true);
            if (!is_array($afformArgs)) {
                return;
            }

            $args = $apiRequest->getArgs();
            $restored = [];
            foreach ($afformArgs as $key => $value) {
                // Strictly additive: never overwrite an arg already present.
                if (!array_key_exists($key, $args)) {
                    $args[$key] = $value;
                    $restored[$key] = $value;
                }
            }

            if ($restored) {
                $apiRequest->setArgs($args);
                \Civi::log()->info('AfformTokenPrefillSubscriber.php - Restored token afformArgs for logged-in visitor', [
                    'afform' => $thisForm,
                    'restored_keys' => array_keys($restored),
                ]);
            }
        } catch (\Throwable $e) {
            // Never let a prefill backfill failure break the form render.
            \Civi::log()->warning('AfformTokenPrefillSubscriber.php - Skipped token arg restore: ' . $e->getMessage());
        }
    }
}
