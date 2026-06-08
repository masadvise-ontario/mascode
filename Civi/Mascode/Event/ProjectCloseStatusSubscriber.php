<?php

declare(strict_types=1);

// file: Civi/Mascode/Event/ProjectCloseStatusSubscriber.php

namespace Civi\Mascode\Event;

use Civi\Core\Service\AutoSubscriber;
use Civi\Core\Event\PostEvent;

/**
 * One-step "Awaiting Close Form" for the CSM (project-close sibling of
 * RcsRequestStatusSubscriber).
 *
 * When the coordinator sends the client project-close request email
 * ("MAS Project Close - Client Template") from a Project case, this advances
 * the project to status "Awaiting Close Form" automatically — no separate
 * status-change step. That transition arms the mas_lifecycle_close_chase
 * CiviRule, so sending the one email starts the client close-form chase
 * cadence.
 *
 * Keyed on the CLIENT close template (not the VC one): the close chase
 * chases the client for their feedback form, so it should start when the
 * client has been asked. Sending the VC close request is a separate earlier
 * step and intentionally arms nothing here.
 *
 * Forward-only: a project already at "Awaiting Close Form" or a closed status
 * is left untouched, so re-sending the email never regresses the lifecycle.
 */
class ProjectCloseStatusSubscriber extends AutoSubscriber
{
    /** Statuses the email is allowed to advance FROM. */
    private const FROM_STATUSES = ['Active', 'On Hold'];
    private const TO_STATUS = 'Awaiting Close Form';
    private const TEMPLATE_TITLE = 'MAS Project Close - Client Template';

    /** @var int|null Cached "Email" activity_type option value */
    private static ?int $emailTypeId = null;

    /** @var string|null Cached client-close template subject */
    private static ?string $closeSubject = null;

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

            $closeSubject = $this->getCloseSubject();
            if ($closeSubject === '' || !str_contains((string) ($act['subject'] ?? ''), $closeSubject)) {
                return;
            }

            $case = \Civi\Api4\CiviCase::get(false)
                ->addSelect('status_id:name', 'case_type_id:name')
                ->addWhere('id', '=', $act['case_id'])
                ->execute()
                ->first();
            if (
                empty($case)
                || $case['case_type_id:name'] !== 'project'
                || !in_array($case['status_id:name'], self::FROM_STATUSES, true)
            ) {
                return;
            }

            \Civi\Api4\CiviCase::update(false)
                ->addValue('status_id:name', self::TO_STATUS)
                ->addWhere('id', '=', $act['case_id'])
                ->execute();

            \Civi::log()->info('ProjectCloseStatusSubscriber.php - Client close email sent, project advanced to Awaiting Close Form', [
                'case_id' => $act['case_id'],
                'activity_id' => $activityId,
                'previous_status' => $case['status_id:name'],
            ]);
        } catch (\Throwable $e) {
            \Civi::log()->error('ProjectCloseStatusSubscriber.php - Failed: ' . $e->getMessage(), [
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

    private function getCloseSubject(): string
    {
        if (self::$closeSubject === null) {
            $row = \Civi\Api4\MessageTemplate::get(false)
                ->addWhere('msg_title', '=', self::TEMPLATE_TITLE)
                ->addSelect('msg_subject')
                ->execute()
                ->first();
            self::$closeSubject = (string) ($row['msg_subject'] ?? '');
        }
        return self::$closeSubject;
    }
}
