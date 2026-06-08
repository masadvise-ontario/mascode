<?php

declare(strict_types=1);

// file: Civi/Mascode/Event/RcsRequestStatusSubscriber.php

namespace Civi\Mascode\Event;

use Civi\Core\Service\AutoSubscriber;
use Civi\Core\Event\PostEvent;

/**
 * One-step "Request RCS" for the CSM.
 *
 * When the coordinator sends the RCS-ask email from a Service Request case
 * (the "MAS RCS Template"), this subscriber moves the case to status
 * "Request RCS" automatically — no separate status-change step. That status
 * transition is what arms the mas_lifecycle_rcs_chase CiviRule, so sending
 * the one email starts the whole chase cadence.
 *
 * Forward-only: a case already at "Request RCS" or any later stage is left
 * untouched, so re-sending the email never regresses the lifecycle.
 */
class RcsRequestStatusSubscriber extends AutoSubscriber
{
    /** Statuses the email is allowed to advance FROM. */
    private const FROM_STATUSES = ['Open', 'Ongoing'];
    private const TO_STATUS = 'Request RCS';
    private const TEMPLATE_TITLE = 'MAS RCS Template';

    /** @var int|null Cached "Email" activity_type option value */
    private static ?int $emailTypeId = null;

    /** @var string|null Cached RCS template subject */
    private static ?string $rcsSubject = null;

    public static function getSubscribedEvents(): array
    {
        return [
            'hook_civicrm_post' => 'onPost',
        ];
    }

    /**
     * @param \Civi\Core\Event\PostEvent $event
     */
    public function onPost(PostEvent $event): void
    {
        if ($event->action !== 'create' || $event->entity !== 'Activity') {
            return;
        }
        $activityId = (int) $event->id;
        if (!$activityId) {
            return;
        }

        try {
            $emailTypeId = $this->getEmailTypeId();
            // Fast reject without a query when the posted object exposes the type.
            $objTypeId = isset($event->object->activity_type_id) ? (int) $event->object->activity_type_id : null;
            if ($objTypeId !== null && $objTypeId !== $emailTypeId) {
                return;
            }

            $act = \Civi\Api4\Activity::get(false)
                ->addSelect('activity_type_id', 'subject', 'case_id')
                ->addWhere('id', '=', $activityId)
                ->execute()
                ->first();
            if (
                empty($act)
                || (int) $act['activity_type_id'] !== $emailTypeId
                || empty($act['case_id'])
            ) {
                return;
            }

            // Is this the RCS-ask email? Match on the template's subject
            // (contains, to tolerate "[case #...]" threading prefixes).
            $rcsSubject = $this->getRcsSubject();
            if ($rcsSubject === '' || !str_contains((string) ($act['subject'] ?? ''), $rcsSubject)) {
                return;
            }

            $case = \Civi\Api4\CiviCase::get(false)
                ->addSelect('status_id:name', 'case_type_id:name')
                ->addWhere('id', '=', $act['case_id'])
                ->execute()
                ->first();
            if (
                empty($case)
                || $case['case_type_id:name'] !== 'service_request'
                || !in_array($case['status_id:name'], self::FROM_STATUSES, true)
            ) {
                return;
            }

            \Civi\Api4\CiviCase::update(false)
                ->addValue('status_id:name', self::TO_STATUS)
                ->addWhere('id', '=', $act['case_id'])
                ->execute();

            \Civi::log()->info('RcsRequestStatusSubscriber.php - RCS email sent, case advanced to Request RCS', [
                'case_id' => $act['case_id'],
                'activity_id' => $activityId,
                'previous_status' => $case['status_id:name'],
            ]);
        } catch (\Throwable $e) {
            \Civi::log()->error('RcsRequestStatusSubscriber.php - Failed: ' . $e->getMessage(), [
                'activity_id' => $activityId,
            ]);
        }
    }

    private function getEmailTypeId(): int
    {
        if (self::$emailTypeId === null) {
            $row = \Civi\Api4\OptionValue::get(false)
                ->addWhere('option_group_id.name', '=', 'activity_type')
                ->addWhere('name', '=', 'Email')
                ->addSelect('value')
                ->execute()
                ->first();
            self::$emailTypeId = (int) ($row['value'] ?? 0);
        }
        return self::$emailTypeId;
    }

    private function getRcsSubject(): string
    {
        if (self::$rcsSubject === null) {
            $row = \Civi\Api4\MessageTemplate::get(false)
                ->addWhere('msg_title', '=', self::TEMPLATE_TITLE)
                ->addSelect('msg_subject')
                ->execute()
                ->first();
            self::$rcsSubject = (string) ($row['msg_subject'] ?? '');
        }
        return self::$rcsSubject;
    }
}
