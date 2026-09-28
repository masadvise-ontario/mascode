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
     * Normalise a whole set of submitted records.
     *
     * EXTRACTED SO THE APPLICATION IS BEHAVIOURAL, not merely the rule. Review
     * demonstrated the "computed but not applied" slip: `normaliseWillAsk()`
     * called, the result computed, and never written into `$records` — every
     * source assertion passing, and the subscriber still logging "Cleared
     * vc_will_ask" on a submission where it cleared nothing, so the failure
     * was invisible in operations too. That is an ordinary refactor slip, not
     * a contrived mutation.
     *
     * Source-text tests genuinely cannot see that. The repo's answer to
     * "cannot be tested where it lives" has twice been to move it somewhere it
     * can be — PR #40 pulled a crashing count into a pure function rather than
     * patching the expression, and this class exists for the same reason. So
     * the loop moves here instead of being conceded as untestable.
     *
     * @param array $records Afform submit records, each `['fields' => [...]]`.
     * @return array{records:array, changed:bool}
     */
    public static function normaliseRecords(array $records): array
    {
        $changed = false;
        foreach ($records as $i => $record) {
            $fields = $record['fields'] ?? [];
            if (!array_key_exists('Monthly_Project_Checkin.vc_will_ask', $fields)) {
                continue;
            }
            $normalised = self::normaliseWillAsk(
                $fields['Monthly_Project_Checkin.is_complete'] ?? null,
                $fields['Monthly_Project_Checkin.vc_will_ask']
            );
            if ($normalised !== $fields['Monthly_Project_Checkin.vc_will_ask']) {
                $records[$i]['fields']['Monthly_Project_Checkin.vc_will_ask'] = $normalised;
                $changed = true;
            }
        }
        return ['records' => $records, 'changed' => $changed];
    }

    /**
     * Give every check-in record a source contact.
     *
     * ⚠ WITHOUT THIS THE ANSWER IS LOST. `source_contact_id` is mandatory on
     * Activity, and on a public form reached from a digest link nothing
     * supplies it: the form's `data` names only type, status and case, and the
     * visitor need not be logged in. Core's processGenericEntity then swallows
     * the "Mandatory values missing" exception at DEBUG level, the VC sees the
     * thank-you page, and no activity exists — which is exactly what happened
     * to the first pilot answer on production (2026-09-28).
     *
     * Filled server-side, from the case, rather than by adding a coordinator
     * `Individual1` to the form the way the Completion form does: on a public
     * form every extra entity is another record the submit can write to
     * (the join-id write hazard), and the answering VC is derivable here.
     *
     * A value already present is kept. A record with no case, or a case the
     * resolver cannot name a contact for, is reported as unresolved rather
     * than guessed at — the entitlement guard strips `case_id` from a refused
     * submission, and that save is meant to fail.
     *
     * @param array $records Afform submit records, each `['fields' => [...]]`.
     *   `case_id` has already been resolved from `'Case1'` to an id by core.
     * @param callable $resolve fn(int $caseId): ?int
     * @return array{records:array, changed:bool, unresolved:bool}
     */
    public static function fillSourceContact(array $records, callable $resolve): array
    {
        $changed = false;
        $unresolved = false;
        foreach ($records as $i => $record) {
            $fields = $record['fields'] ?? [];
            if (!empty($fields['source_contact_id'])) {
                continue;
            }
            $caseId = (int) ($fields['case_id'] ?? 0);
            $contactId = $caseId > 0 ? (int) ($resolve($caseId) ?? 0) : 0;
            if ($contactId > 0) {
                $records[$i]['fields']['source_contact_id'] = $contactId;
                $changed = true;
            } else {
                $unresolved = true;
            }
        }
        return ['records' => $records, 'changed' => $changed, 'unresolved' => $unresolved];
    }

    /**
     * Is this case status one the Completion template can still advance from?
     *
     * ⚠ WHAT THIS EXTRACTION DOES AND DOES NOT CLOSE. An earlier version of
     * this docblock claimed it closed the "present but inert" mutation —
     * `{ $noop = true; }` in place of the call site's `return;`. It does not,
     * and review re-measured that mutation still green.
     *
     * It cannot: what that mutation removes is the `return;` at the CALL SITE,
     * which lives in VcDigestSubmitSubscriber. Moving the predicate here can
     * never reach it.
     *
     * What it does close is the predicate's own SENSE — inverting this body is
     * caught behaviourally. The contents of `ADVANCEABLE_FROM` were already
     * pinned against ProjectLifecycleStatusSubscriber's from-list, and the
     * negation at the call site by a string assertion. So the early return
     * remains source-only, which is the honest position and the one the ticket
     * slice states two lines below where the overclaim used to sit.
     *
     * @param string[] $advanceableFrom
     */
    public static function shouldAdvance(string $status, array $advanceableFrom): bool
    {
        return in_array($status, $advanceableFrom, true);
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
