<?php

declare(strict_types=1);

// file: Civi/Mascode/Event/VcDigestSubmitSubscriber.php

namespace Civi\Mascode\Event;

use Civi\Core\Service\AutoSubscriber;
use Civi\Mascode\Service\LifecycleMailer;

/**
 * Turns a monthly check-in submission into a durable record and, when the VC
 * says the work is finished, into a real state change.
 *
 * Spec: BrianPKM 3-Resources/mascode-vc-monthly-donation-digest-spec.md,
 * component `VcDigestSubmitSubscriber`, decisions D5, D6, D7 and D8.
 * Ticket: docs/plans/completion-signoff-tickets.md P1-5.
 *
 * Three jobs, in the order the spec names them:
 *
 *   recordAnswers()  ALWAYS runs, even when both answers are No. A project
 *                    answered "not complete" six months running is the signal
 *                    the digest exists to surface (Goal 8), and that is only
 *                    visible if every round leaves a row.
 *   handleComplete() "Complete = Yes" advances the Project — BY SENDING the
 *                    Completion template, never by writing `status_id` (D7).
 * There is deliberately NO flagNoVcAsk() method, though the spec names one as
 * a sub-target and an earlier version of this docblock claimed it existed.
 * The fact it would record is already recorded — `vc_will_ask` on the
 * check-in activity — and D8 fires on (no VC ask) AND (signoff returned with
 * no donation), so there is nothing for it to do at submit time. A VC who
 * declines to ask is not yet a problem; the office item is P2-3's dashboard
 * row reading the field.
 *
 * D7 IS THE LOAD-BEARING ONE. `ProjectLifecycleStatusSubscriber` already maps
 * template → status, and it is the only code path that moves a Project into
 * the awaiting-form statuses. Writing `status_id` here would be a second
 * owner of that transition, the two would drift, and the chase that arms off
 * the status would arm off only one of them. Sending the template gets the
 * status change, the chase and the case-timeline entry as one consequence.
 */
class VcDigestSubmitSubscriber extends AutoSubscriber
{
    public const FORM_NAME = 'afformMASProjectCheckin';

    /** The activity the form creates. */
    public const ACTIVITY_TYPE = 'Monthly Project Check-in';

    /** The template whose SENDING advances the Project (D7). */
    public const COMPLETION_TEMPLATE = 'MAS Project Completion - VC Template';

    /**
     * Statuses from which sending the Completion template still advances.
     *
     * Mirrors ProjectLifecycleStatusSubscriber's `from` list for that
     * template. Duplicated rather than imported because that constant is
     * private — but a mismatch is not silent: this list is only used to decide
     * whether to SKIP a send, so being over-narrow means a missed advance,
     * which the pilot would see. Asserted against the subscriber's own list in
     * tests/Unit/Event/DigestSubmitWiringTest.php.
     */
    private const ADVANCEABLE_FROM = [
        'Active',
        'On Hold',
        'Awaiting VC Project Definition',
        'Awaiting Client Project Definition',
    ];

    public static function getSubscribedEvents(): array
    {
        return [
            'civi.afform.submit' => [
                // Priority > 0: BEFORE core's processGenericEntity (0) writes,
                // so normalise() can correct the submitted values rather than
                // repair them afterwards. A repair-after-write leaves a window
                // in which the wrong value is real, and any post-write hook
                // sees it.
                ['onBeforeSave', 10],
                // Priority < 0: AFTER the write, when the activity has an id.
                ['onAfterSave', -100],
            ],
        ];
    }

    /**
     * Correct what the browser should have sent, before it is written.
     *
     * ⚠ `vc_will_ask` MUST BE NULL WHENEVER `is_complete` IS NOT TRUE, and the
     * form alone does not guarantee it. Core does not strip
     * conditionally-hidden fields on submit — `getSubmittableFields()` carries
     * the TODO — and the only thing clearing a hidden value today is browser
     * JavaScript (`afField.component.js`'s `afIfDestroy`). So a crafted or
     * replayed submit can arrive with `is_complete = false` AND
     * `vc_will_ask = true`.
     *
     * That combination is a state P1-1's data model declares impossible: NULL
     * means "the question was never put to them", which is a different fact
     * from an answered No, and D8's office follow-up is queued off it. Trusting
     * the submitted value would manufacture a work item about a client nobody
     * was ever asked about. Carried from PR #39's review as a P1-5 done-when.
     */
    public function onBeforeSave($event): void
    {
        if (!$this->isThisForm($event) || $event->getEntityName() !== 'Activity1') {
            return;
        }

        try {
            // The loop lives in CheckinAnswer so that APPLYING the rule is
            // behavioural, not just computing it: review demonstrated the
            // computed-but-never-written slip passing every source assertion,
            // while the subscriber still logged "Cleared vc_will_ask" on a
            // submission where it had cleared nothing.
            $result = \Civi\Mascode\Digest\CheckinAnswer::normaliseRecords($event->getRecords());

            if ($result['changed']) {
                $event->setRecords($result['records']);
                \Civi::log()->info(
                    'VcDigestSubmitSubscriber.php - Cleared vc_will_ask on a check-in that was not complete',
                    ['afform' => self::FORM_NAME]
                );
            }
        } catch (\Throwable $e) {
            // Do not block the submission: a VC's answer is worth more than a
            // tidy field. The activity is still recorded, and the worst case is
            // one row whose vc_will_ask needs reading with care.
            \Civi::log()->error(
                'VcDigestSubmitSubscriber.php - Could not normalise vc_will_ask: ' . $e->getMessage()
            );
        }
    }

    /**
     * Stamp the record, then advance the case if the VC said it is finished.
     */
    public function onAfterSave($event): void
    {
        if (!$this->isThisForm($event) || $event->getEntityName() !== 'Activity1') {
            return;
        }

        $activityId = (int) ($event->getEntityId(0) ?: 0);
        if (!$activityId) {
            // NOT a silent return. Core's processGenericEntity swallows a save
            // failure with only a debug log, so an empty id here is the real
            // "the answer was lost and the VC saw a confirmation" case — the
            // one the entitlement guard's docblock reasons about at length.
            \Civi::log()->error(
                'VcDigestSubmitSubscriber.php - Check-in submitted but no activity id came back; '
                . 'the answer may not have been saved',
                ['afform' => self::FORM_NAME]
            );
            return;
        }

        try {
            $activity = $this->loadActivity($activityId);
            if (!$activity || empty($activity['case_id'])) {
                // No case means the entitlement guard refused the id, or the
                // form was reached some way nobody anticipated. Either way
                // there is nothing to advance and nothing to stamp against.
                \Civi::log()->warning('VcDigestSubmitSubscriber.php - Check-in activity has no case', [
                    'activity_id' => $activityId,
                ]);
                return;
            }

            $this->recordAnswers($activityId, (int) $activity['case_id']);

            if ($this->isTrue($activity['Monthly_Project_Checkin.is_complete'] ?? null)) {
                $this->handleComplete((int) $activity['case_id'], $activityId);
            }
        } catch (\Throwable $e) {
            // The answer is already saved by the time this runs. Failing loudly
            // here would show the VC an error for a submission that succeeded.
            \Civi::log()->error('VcDigestSubmitSubscriber.php - Post-submit handling failed: ' . $e->getMessage(), [
                'activity_id' => $activityId,
            ]);
        }
    }

    /**
     * Give the activity its subject and its round.
     *
     * BOTH ARE COMPOSED SERVER-SIDE, deliberately. `digest_round` is the key
     * P2-1's idempotency guard will match on and the field that makes "months
     * stuck" countable (Goal 8); a hidden form field is a value the client
     * controls, and neither of these should be.
     *
     * The round is read from the digest that prompted this answer where one
     * exists, and falls back to the current month. The spec says `digest_round`
     * is "blank if reached another way" — a blank is honest but useless for
     * counting, and a VC who answered in early October about September's digest
     * belongs to September's round, not October's.
     */
    private function recordAnswers(int $activityId, int $caseId): void
    {
        $round = $this->roundForCase($caseId) ?? date('Y-m');
        $code = $this->masCode($caseId);
        // Built by joining, not by trimming: trim()'s character list is BYTES,
        // and an em dash is three of them, so `trim(..., ' —')` is a latent
        // multibyte bug that also leaves a double space when the code is empty.
        $subject = implode(' — ', array_filter(['Monthly check-in', $code, $round], 'strlen'));

        \Civi\Api4\Activity::update(false)
            ->addValue('subject', $subject)
            ->addValue('Monthly_Project_Checkin.digest_round', $round)
            ->addWhere('id', '=', $activityId)
            ->execute();
    }

    /**
     * Advance the Project by SENDING the Completion template (D7).
     *
     * IDEMPOTENT, and by case STATUS rather than by counting sends. The spec
     * requires "re-submitting the same link must not send the Completion email
     * twice", and LifecycleMailer's own duplicate guard is a 23-hour window —
     * enough for a double-click, not for a VC who answers twice in a week, or
     * for the four projects that have two coordinators and could be answered
     * by both.
     *
     * Checking the status instead asks the question that actually matters: has
     * this project already moved on? If it is no longer in an advanceable
     * status, the Completion request has been sent, or the VC has already
     * returned the form, or the project has closed — and in all three cases a
     * second email is noise to a volunteer.
     */
    private function handleComplete(int $caseId, int $activityId): void
    {
        $case = \Civi\Api4\CiviCase::get(false)
            ->addSelect('id', 'status_id:name', 'case_type_id:name')
            ->addWhere('id', '=', $caseId)
            ->execute()
            ->first();

        if (!$case || ($case['case_type_id:name'] ?? '') !== 'project') {
            return;
        }

        // Delegated so the guard's EFFECT is behavioural, not just its
        // presence: review showed it present, correctly sensed, and inert.
        if (!\Civi\Mascode\Digest\CheckinAnswer::shouldAdvance(
            (string) ($case['status_id:name'] ?? ''),
            self::ADVANCEABLE_FROM
        )) {
            \Civi::log()->info('VcDigestSubmitSubscriber.php - Check-in complete, but the project has already moved on', [
                'case_id' => $caseId,
                'status' => $case['status_id:name'] ?? null,
                'activity_id' => $activityId,
            ]);
            return;
        }

        $vcId = $this->answeringVc($caseId);
        if (!$vcId) {
            // Nobody to send the Completion request to. Reported rather than
            // guessed at: the project is in the coordinator-less exception
            // report the office already works from.
            \Civi::log()->warning('VcDigestSubmitSubscriber.php - Cannot send Completion request: no current coordinator', [
                'case_id' => $caseId,
            ]);
            return;
        }

        $result = LifecycleMailer::execute([
            'case_id' => $caseId,
            'template' => self::COMPLETION_TEMPLATE,
            'recipient_contact_id' => $vcId,
            // D9 / C1: the house default since 2026-08-20. The status change is
            // a consequence of the SEND, so propose mode would leave the
            // project un-advanced until somebody clicked.
            'mode' => 'auto',
        ]);

        \Civi::log()->info('VcDigestSubmitSubscriber.php - Check-in complete; Completion template sent', [
            'case_id' => $caseId,
            'activity_id' => $activityId,
            'sent_activity_id' => $result['activity_id'] ?? null,
            'skipped_duplicate' => !empty($result['skipped']),
        ]);
    }

    // ------------------------------------------------------------------

    /**
     * The `YYYY-MM` of the digest that prompted this answer, if any.
     *
     * Reads the marker VcDigestMailer wrote into its per-project activity.
     * Most recent wins: a VC answering late is answering the last digest they
     * received.
     */
    private function roundForCase(int $caseId): ?string
    {
        $row = \Civi\Api4\Activity::get(false)
            ->addSelect('details')
            ->addWhere('case_id', '=', $caseId)
            ->addWhere('activity_type_id:name', '=', LifecycleMailer::TYPE_SENT)
            ->addWhere('details', 'LIKE', '%<!--mas-digest %')
            ->addOrderBy('activity_date_time', 'DESC')
            ->setLimit(1)
            ->execute()
            ->first();

        if (!$row) {
            return null;
        }

        if (preg_match('/<!--mas-digest (\{.*?\}) -->/s', (string) $row['details'], $m)) {
            $meta = json_decode($m[1], true);
            $round = $meta['digest_round'] ?? null;
            // Shape-check rather than trust: this is parsed out of a comment in
            // an activity body, which a staff member can edit in the UI.
            if (is_string($round) && preg_match('/^\d{4}-\d{2}$/', $round)) {
                return $round;
            }
        }
        return null;
    }

    private function masCode(int $caseId): string
    {
        $row = \Civi\Api4\CiviCase::get(false)
            ->addSelect('Projects.MAS_Project_Case_Code')
            ->addWhere('id', '=', $caseId)
            ->execute()
            ->first();
        return (string) ($row['Projects.MAS_Project_Case_Code'] ?? '');
    }

    /**
     * The VC who just answered — NOT "a" coordinator of the case.
     *
     * ⚠ THIS DISTINCTION IS THE WHOLE POINT, and the first version got it
     * wrong. It called coordinatorOf(), which orders by `near_contact_id ASC`
     * and takes the first — the LOWEST CONTACT ID. On the 4 Active projects
     * that have two current coordinators, that is an arbitrary choice, and
     * review measured all four on the clone.
     *
     * The consequence is worse than a misdirected email. The Completion
     * template's body carries `{form.afformProjectCloseVCFeedbackLink}`, which
     * mints a CHECKSUM LINK FOR THE RECIPIENT. So VC B answers "the work is
     * done", VC A receives an authenticated close-form link for work they did
     * not report finishing, and B is told nothing. The case has still
     * advanced, so the 30/90/150 chase then chases the wrong person.
     *
     * The correct value was already in hand:
     * `CRM_Core_Session::getLoggedInContactID()` is the token-authenticated
     * VC, and CheckinCaseEntitlementSubscriber — which ran at priority 500 on
     * this very submit — has ALREADY verified that contact is a current
     * coordinator of this case. Re-deriving discarded a known-correct value
     * for an arbitrary one.
     *
     * The fallback exists only for the staff-exempt path: a staff member
     * opening a VC's form to diagnose it is not the VC, so the Completion
     * request goes to a real coordinator rather than to them.
     */
    private function answeringVc(int $caseId): ?int
    {
        $contactId = (int) (\CRM_Core_Session::getLoggedInContactID() ?: 0);
        if ($contactId && $this->isCurrentCoordinator($caseId, $contactId)) {
            return $contactId;
        }

        // Staff, or a path nobody anticipated. Fall back rather than refuse:
        // the answer is already recorded, and not advancing the case is a
        // worse outcome than advancing it with a coordinator as recipient.
        $fallback = $this->coordinatorOf($caseId);
        if ($fallback && $contactId && $fallback !== $contactId) {
            \Civi::log()->info(
                'VcDigestSubmitSubscriber.php - Submitter is not a current coordinator; '
                . 'sending the Completion request to one instead',
                ['case_id' => $caseId, 'submitter' => $contactId, 'recipient' => $fallback]
            );
        }
        return $fallback;
    }

    private function isCurrentCoordinator(int $caseId, int $contactId): bool
    {
        return (bool) \Civi\Api4\RelationshipCache::get(false)
            ->addSelect('id')
            ->addWhere('case_id', '=', $caseId)
            ->addWhere('near_relation:name', '=', 'Case Coordinator is')
            ->addWhere('near_contact_id', '=', $contactId)
            ->addWhere('is_current', '=', true)
            ->setLimit(1)
            ->execute()
            ->count();
    }

    /**
     * Any current coordinator of the case — the fallback only.
     *
     * `is_current`, not `is_active` — see CheckinCaseEntitlementSubscriber for
     * the measured reason. Sending a Completion request to someone whose role
     * ended is the same mistake as letting them open the form.
     */
    private function coordinatorOf(int $caseId): ?int
    {
        $row = \Civi\Api4\RelationshipCache::get(false)
            ->addSelect('near_contact_id')
            ->addWhere('case_id', '=', $caseId)
            ->addWhere('near_relation:name', '=', 'Case Coordinator is')
            ->addWhere('is_current', '=', true)
            ->addOrderBy('near_contact_id', 'ASC')
            ->setLimit(1)
            ->execute()
            ->first();
        return $row ? (int) $row['near_contact_id'] : null;
    }

    private function loadActivity(int $activityId): ?array
    {
        return \Civi\Api4\Activity::get(false)
            ->addSelect(
                'id',
                'case_id',
                'Monthly_Project_Checkin.is_complete',
                'Monthly_Project_Checkin.vc_will_ask'
            )
            ->addWhere('id', '=', $activityId)
            ->execute()
            ->first();
    }

    private function isThisForm($event): bool
    {
        return ($event->getAfform()['name'] ?? null) === self::FORM_NAME;
    }

    /**
     * @see \Civi\Mascode\Digest\CheckinAnswer — the two answer rules live
     *      there so CI can test them by behaviour; this class extends
     *      AutoSubscriber and cannot load without CiviCRM.
     */
    public static function isTrue($value): bool
    {
        return \Civi\Mascode\Digest\CheckinAnswer::isTrue($value);
    }
}
