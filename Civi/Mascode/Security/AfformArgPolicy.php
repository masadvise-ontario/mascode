<?php

// File: Civi/Mascode/Security/AfformArgPolicy.php

namespace Civi\Mascode\Security;

/**
 * Which `Afform.prefill` / `Afform.submit` arguments a caller is allowed to supply.
 *
 * This class is deliberately free of every CiviCRM dependency so it can be unit
 * tested without a bootstrapped Civi. It answers two questions and nothing else:
 *
 *   1. Is this form one where caller-supplied record ids must be authorised?
 *      -> isGuardedForm()
 *   2. Given the set of keys that have been authorised, what may survive?
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
 * Those five names are therefore the whole attack surface for a whole-form
 * prefill, and they are also exactly what a signed `_aff` token puts in its
 * `afformArgs` (see each behavior's onCreateToken()). Anything else in `args`
 * is inert or handled elsewhere, so this is an allow-by-default filter over a
 * closed, enumerable set rather than a guess at what might be dangerous:
 *
 *   - Entity-named args (`Case1`, `Individual1`, ...) do NOT load a record on
 *     these forms. AbstractProcessor::loadEntities() only honours them when the
 *     matched field carries an `autofill` input attribute, which none of the MAS
 *     forms' id fields do — verified empirically against all seven forms.
 *   - `sid` selects a stored AfformSubmission through a permission-checked
 *     `AfformSubmission.get()`, which already returns 403 anonymously.
 *
 * `activity_id`, `event_id` and `participant_id` are inert on today's MAS forms
 * (no MAS entity declares those autofill modes) but are guarded anyway: adding
 * `autofill="entity_id"` to an Activity fieldset would otherwise silently
 * reopen the hole.
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
     * The fill mode in which the autofill behaviors act — and so the only mode
     * this policy applies to.
     *
     * Every behavior listed above tests `getFillMode() === 'form'` before
     * loading. The `entity` and `join` modes are driven by autocomplete widgets
     * and are validated separately by core through
     * AbstractProcessor::validateBySavedSearch(), which runs the
     * permission-checked `autocomplete` action scoped to the form. Guarding
     * them here would break picking an existing record in an autocomplete field
     * while adding no protection.
     */
    public const GUARDED_FILL_MODE = 'form';

    /**
     * Does this form leave caller-supplied ids ungated by CiviCRM permissions?
     *
     * True only for a form that is both reachable anonymously (`is_public`) and
     * carries no permission requirement at all (`*always allow*`). A public
     * form that still demands a real permission — or any non-public form — is
     * already gated by that permission and is left alone.
     *
     * @param array $afform  An Afform.get record (needs `is_public`, `permission`).
     */
    public static function isGuardedForm(array $afform): bool
    {
        if (empty($afform['is_public'])) {
            return false;
        }

        $permissions = $afform['permission'] ?? [];
        if (!is_array($permissions)) {
            $permissions = [$permissions];
        }

        return in_array('*always allow*', $permissions, true);
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
