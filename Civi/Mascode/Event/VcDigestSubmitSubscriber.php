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
 *   flagNoVcAsk()    Records that the VC declined to ask. It does NOT create
 *                    the office work item: D8 fires on (no VC ask) AND
 *                    (signoff returned with no donation), and a VC who
 *                    declines is not yet a problem.
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
            $records = $event->getRecords();
            $changed = false;

            foreach ($records as $i => $record) {
                $fields = $record['fields'] ?? [];
                if (!array_key_exists('Monthly_Project_Checkin.vc_will_ask', $fields)) {
                    continue;
                }
                // Strictly "not true": false, '0', null and absent all mean the
                // second question was never put to them.
                $normalised = \Civi\Mascode\Digest\CheckinAnswer::normaliseWillAsk(
                    $fields['Monthly_Project_Checkin.is_complete'] ?? null,
                    $fields['Monthly_Project_Checkin.vc_will_ask']
                );
                if ($normalised !== $fields['Monthly_Project_Checkin.vc_will_ask']) {
                    $records[$i]['fields']['Monthly_Project_Checkin.vc_will_ask'] = $normalised;
                    $changed = true;
                }
            }

            if ($changed) {
                $event->setRecords($records);
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
        $subject = trim("Monthly check-in — {$code} — {$round}", ' —');

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

        if (!in_array($case['status_id:name'] ?? '', self::ADVANCEABLE_FROM, true)) {
            \Civi::log()->info('VcDigestSubmitSubscriber.php - Check-in complete, but the project has already moved on', [
                'case_id' => $caseId,
                'status' => $case['status_id:name'] ?? null,
                'activity_id' => $activityId,
            ]);
            return;
        }

        $vcId = $this->coordinatorOf($caseId);
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
     * The case's current coordinator.
     *
     * `is_current`, not `is_active` — see
     * CheckinCaseEntitlementSubscriber for the measured reason. Sending a
     * Completion request to someone whose role ended is the same mistake as
     * letting them open the form.
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
