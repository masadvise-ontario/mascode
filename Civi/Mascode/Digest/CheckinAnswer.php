<?php

declare(strict_types=1);

// file: Civi/Mascode/Digest/CheckinAnswer.php

namespace Civi\Mascode\Digest;

/**
 * How a check-in's two answers are read.
 *
 * FREE OF EVERY CiviCRM DEPENDENCY, deliberately, like
 * Civi\Mascode\Security\AfformArgPolicy and Civi\Mascode\Digest\DigestRowRenderer.
 * `VcDigestSubmitSubscriber` extends `AutoSubscriber` and cannot load without a
 * bootstrapped Civi, so a rule left inside it can only be asserted over source
 * text. These two rules decide whether an email goes to a volunteer and
 * whether a work item is manufactured about a client — both deserve a
 * behavioural test.
 */
final class CheckinAnswer
{
    /**
     * Is this answer TRUE, strictly?
     *
     * This decides whether the Completion template is sent — which is what
     * advances the Project (D7). A loose `(bool)` cast gets it wrong in both
     * directions: the string `'0'` is falsy but the string `'false'` is
     * TRUTHY, and CiviCRM round-trips booleans as `'1'`/`'0'` strings often
     * enough that a loose test is a coin flip. The related trap on the form
     * side is recorded in the mascode memory note
     * feedback_afform_boolean_string_id_bug — core casts `!!option.id`, so a
     * string `'0'` option becomes true.
     *
     * Anything that is not unambiguously true is treated as not-complete,
     * which is the safe direction: the VC is asked again next month rather
     * than emailed a form for work they did not say was finished.
     */
    public static function isTrue($value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }

    /**
     * What `vc_will_ask` should be, given what `is_complete` says.
     *
     * NULL whenever the work is not complete, because the second question was
     * never put to them — the form hides it. That is a different fact from an
     * answered No, and D8 queues the office's donation follow-up off the
     * distinction, so collapsing the two manufactures a work item about a
     * client nobody was ever asked about.
     *
     * It cannot be left to the browser. Core does not strip
     * conditionally-hidden fields on submit (`getSubmittableFields()` carries
     * the TODO), so the only thing clearing a hidden value today is JavaScript
     * — and a crafted or replayed submit has no JavaScript.
     *
     * @param mixed $isComplete   The submitted Q1.
     * @param mixed $submittedAsk The submitted Q2.
     * @return bool|null          What should be stored.
     */
    public static function normaliseWillAsk($isComplete, $submittedAsk): ?bool
    {
        if (!self::isTrue($isComplete)) {
            return null;
        }
        if ($submittedAsk === null) {
            return null;
        }
        return self::isTrue($submittedAsk);
    }
}
