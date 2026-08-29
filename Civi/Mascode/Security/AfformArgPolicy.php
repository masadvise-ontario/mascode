<?php

// File: Civi/Mascode/Security/AfformArgPolicy.php

namespace Civi\Mascode\Security;

/**
 * Which `Afform.prefill` / `Afform.submit` arguments a caller is allowed to supply.
 *
 * This class is deliberately free of every CiviCRM dependency so it can be unit
 * tested without a bootstrapped Civi. It answers three questions and nothing else:
 *
 *   1. Is this form one where caller-supplied record ids must be authorised?
 *      -> isGuardedForm()
 *   2. Which of the caller's arguments name a record? -> guardedKeys()
 *   3. Given the set of keys that have been authorised, what may survive?
 *      -> sanitize()
 *
 * The authorisation itself — who is logged in, what they may see — lives in
 * \Civi\Mascode\Event\AfformPublicArgGuardSubscriber, which is where the Civi
 * lookups belong.
 *
 * Why these particular argument names
 * -----------------------------------
 * Afform's "autofill" behaviors load an EXISTING record when a specific,
 * hard-coded argument name is present on a whole-form prefill. Each behavior
 * reads exactly one name:
 *
 *   contact_id      Civi\Afform\Behavior\ContactAutofill        (autofill="entity_id")
 *   case_id         Civi\Afform\Behavior\CaseAutofill           (case-autofill="entity_id")
 *   activity_id     Civi\Afform\Behavior\ActivityAutofill
 *   event_id        Civi\Afform\Behavior\EventAutofill
 *   participant_id  Civi\Afform\Behavior\ParticipantAutofill
 *
 * Those five names are the whole attack surface for a whole-form prefill, and
 * they are also exactly what a signed `_aff` token puts in its `afformArgs`
 * (see each behavior's onCreateToken()).
 *
 * Entity-named args (`Case1`, `Individual1`, ...) do NOT load a record on these
 * forms. AbstractProcessor::loadEntities() honours them only when the matched
 * field carries an `autofill` input attribute AND either `url-autofill` is
 * truthy or the matched field is declared on the form; no MAS form declares an
 * `id` field or sets `url-autofill`. Verified empirically against all seven
 * forms in both `form` and `entity` fill mode. `sid` selects a stored
 * AfformSubmission through a permission-checked `AfformSubmission.get()`, which
 * returns 403 anonymously.
 *
 * `activity_id`, `event_id` and `participant_id` are inert on today's MAS forms
 * (no MAS entity declares those autofill modes) but are guarded anyway: adding
 * `autofill="entity_id"` to an Activity fieldset would otherwise silently
 * reopen the hole.
 *
 * Fill modes
 * ----------
 * The five names above only act in `form` mode — every behavior tests
 * `getFillMode() === 'form'` before loading. Every OTHER mode has to be refused
 * outright rather than filtered, and NOT because core validates them:
 *
 * An earlier version of this file claimed core validated `entity` and `join`
 * mode through AbstractProcessor::validateBySavedSearch(), which runs the
 * permission-checked `autocomplete` action. **That claim was wrong**, in two
 * independent ways. validateBySavedSearch() is only reached when the key field
 * carries a `defn.saved_search` (AbstractProcessor.php:253, :358) and no MAS
 * form field does; and for joins it cannot run at all, because
 * getJoinResult() tests `$entity['joins']` while its parameter is named
 * `$afEntity`, so the condition is permanently false (a core bug).
 *
 * The consequence was a live, unauthenticated PII disclosure that this guard's
 * first version did not close, because it is not expressed through any of the
 * five names. loadJoin() builds its WHERE straight from caller-supplied join
 * values with no scoping to a parent record, and FBAC runs it with
 * checkPermissions => FALSE, so anonymously:
 *
 *   POST civicrm/ajax/api4/Afform/prefill
 *   {"name":"afformMASRCSForm","fillMode":"join",
 *    "args":{"Organization1":[{"joins":{"Address":[{"city":"Toronto"}]}}]}}
 *
 * returned a real client street address, and the same shape returned Email
 * (an email-existence oracle) and Phone, one record per request.
 *
 * So every mode other than `form` is refused wholesale on a guarded form —
 * stated as an allowlist rather than as a list of bad modes, because core does
 * not branch the way "entity and join are the dangerous ones" implies:
 * loadEntities() tests `=== 'join'` and treats EVERY other value, recognised or
 * not, identically. A denylist would be correct only by coincidence.
 *
 * Refusing them is safe because those modes exist to serve autocomplete widgets,
 * and **none of the seven MAS public forms has one** — no `saved_search`, no
 * EntityRef, no Autocomplete input anywhere in their layouts. If you ever add an
 * autocomplete field to a public form, this decision has to be revisited: see
 * ang/README.md §"Security: public forms and caller-supplied record ids".
 */
final class AfformArgPolicy
{
    /**
     * Argument names that make core load an existing record on a whole-form fill.
     *
     * @var string[]
     */
    public const GUARDED_ID_ARGS = [
        'case_id',
        'contact_id',
        'activity_id',
        'event_id',
        'participant_id',
    ];

    /**
     * The fill mode in which the five names above act, and so the mode whose
     * args are filtered key by key rather than dropped wholesale.
     */
    public const FILL_MODE_FORM = 'form';

    /**
     * Does this form leave caller-supplied ids ungated by CiviCRM permissions?
     *
     * The test is `*always allow*` alone. It deliberately does NOT also require
     * `is_public`: that flag does not gate access to a form, it only chooses
     * between the frontend and backend URL scheme when a token link is minted
     * (Civi\Afform\Tokens::createUrl()). What decides whether an anonymous
     * caller may reach Afform.prefill is the `permission` field and nothing
     * else (Civi\Api4\Action\Afform\Get::checkPermission()).
     *
     * An earlier version required `is_public` too, which would have skipped the
     * guard entirely on a form that was `*always allow*` but not public — fully
     * reachable, completely unguarded. All seven MAS forms happen to be public,
     * so that was latent rather than live, but "the filter is the security" is
     * an established pattern in this codebase and such a form is a plausible
     * thing for someone to create.
     *
     * A record with NO permission is treated as guarded, not as safe. Core
     * defaults an empty permission to `['access CiviCRM']`
     * (Civi\Api4\Action\Afform\Get), so in practice the only way to get here
     * with nothing is a lookup that failed — and guessing "not guarded" from a
     * failed lookup is guessing in the direction that reopens the hole. Guarding
     * a form we could not read costs a blank fieldset on a request that was
     * going to fail in _run() anyway.
     *
     * @param array $afform  An Afform.get record (needs `permission`).
     */
    public static function isGuardedForm(array $afform): bool
    {
        $permissions = $afform['permission'] ?? null;
        if ($permissions === null || $permissions === [] || $permissions === '') {
            return true;
        }
        if (!is_array($permissions)) {
            $permissions = [$permissions];
        }

        return in_array('*always allow*', $permissions, true);
    }

    /**
     * May this fill mode's args be filtered key by key, rather than refused?
     *
     * ONLY `form`. This is an allowlist on purpose. Naming `entity` and `join`
     * as the blocked modes reads naturally but would be right only by accident:
     * core's loadEntities() tests `=== 'join'` and sends every other value —
     * 'entity', '', null, 'JOIN', 'xyz' — down one identical else path, so a
     * mode blocked by name and an unrecognised mode are the same code in core.
     */
    public static function isFilterableFillMode(?string $fillMode): bool
    {
        return $fillMode === self::FILL_MODE_FORM;
    }

    /**
     * Which of the caller's arguments are record ids needing authorisation?
     *
     * @param array $args  Caller-supplied Afform args.
     * @return string[]    Guarded keys actually present, in GUARDED_ID_ARGS order.
     */
    public static function guardedKeys(array $args): array
    {
        $present = [];
        foreach (self::GUARDED_ID_ARGS as $key) {
            // A key present but empty ('' / null / 0) cannot load anything —
            // core's behaviors all bail on a falsy id — so it is not a finding
            // and reporting it would only produce noise in the log.
            if (array_key_exists($key, $args) && !empty($args[$key])) {
                $present[] = $key;
            }
        }
        return $present;
    }

    /**
     * Drop every guarded argument that was not authorised.
     *
     * Non-guarded arguments pass through untouched, and a guarded argument that
     * WAS authorised keeps its caller-supplied value. Removing an argument is
     * not an error for the visitor: the corresponding fieldset simply renders
     * blank, exactly as it does for someone opening the form with no arguments.
     *
     * @param array    $args         Caller-supplied Afform args.
     * @param string[] $allowedKeys  Guarded keys the caller is entitled to.
     * @return array                 The args that may proceed.
     */
    public static function sanitize(array $args, array $allowedKeys): array
    {
        $sanitized = $args;
        foreach (self::guardedKeys($args) as $key) {
            if (!in_array($key, $allowedKeys, true)) {
                unset($sanitized[$key]);
            }
        }
        return $sanitized;
    }
}
