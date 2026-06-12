<?php

declare(strict_types=1);

// file: Civi/Mascode/Event/ProjectCloseStatusSubscriber.php

namespace Civi\Mascode\Event;

use Civi\Core\Service\AutoSubscriber;
use Civi\Core\Event\PostEvent;
use Civi\Mascode\Service\LifecycleMailer;

/**
 * Close-path status advancement for Project cases (project-close sibling of
 * RcsRequestStatusSubscriber). Sending a close-request email IS the status
 * change — no separate step:
 *
 *  - VC close request ("MAS Project Close - VC Template") sent
 *      → "Awaiting VC Project Close Form" (arms mas_lifecycle_vc_close_chase)
 *  - Client close request ("MAS Project Close - Client Template") sent
 *      → "Awaiting Client Project Close Form" (arms mas_lifecycle_close_chase)
 *
 * Watches BOTH activity types an outbound email can land as: "Email" (sent
 * manually from the case) and "Sent Automated Email" (a click-sent
 * LifecycleMailer draft — e.g. the auto-proposed client close email after the
 * VC close form arrives).
 *
 * Forward-only: each transition's from-list excludes the to-status and every
 * later status, so re-sending an email never regresses the lifecycle.
 */
class ProjectCloseStatusSubscriber extends AutoSubscriber
{
    /** Template msg_title => allowed from-statuses and the to-status. */
    private const TRANSITIONS = [
        'MAS Project Close - VC Template' => [
            'from' => ['Active', 'On Hold', 'Awaiting Project Definition'],
            'to' => 'Awaiting VC Project Close Form',
        ],
        'MAS Project Close - Client Template' => [
            'from' => ['Active', 'On Hold', 'Awaiting Project Definition', 'Awaiting VC Project Close Form'],
            'to' => 'Awaiting Client Project Close Form',
        ],
    ];

    /** @var array<string,int>|null Cached activity-type name => value map */
    private static ?array $emailTypeIds = null;

    /** @var array<string,string>|null Cached template msg_title => msg_subject */
    private static ?array $templateSubjects = null;

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
            $emailTypeIds = $this->getEmailTypeIds();
            $objTypeId = isset($event->object->activity_type_id) ? (int) $event->object->activity_type_id : null;
            if ($objTypeId !== null && !in_array($objTypeId, $emailTypeIds, true)) {
                return;
            }

            $act = \Civi\Api4\Activity::get(false)
                ->addSelect('activity_type_id', 'subject', 'case_id')
                ->addWhere('id', '=', $activityId)
                ->execute()
                ->first();
            if (
                empty($act)
                || !in_array((int) $act['activity_type_id'], $emailTypeIds, true)
                || empty($act['case_id'])
            ) {
                return;
            }

            $transition = $this->matchTransition((string) ($act['subject'] ?? ''));
            if ($transition === null) {
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
                || !in_array($case['status_id:name'], $transition['from'], true)
            ) {
                return;
            }

            \Civi\Api4\CiviCase::update(false)
                ->addValue('status_id:name', $transition['to'])
                ->addWhere('id', '=', $act['case_id'])
                ->execute();

            \Civi::log()->info('ProjectCloseStatusSubscriber.php - Close email sent, project advanced', [
                'case_id' => $act['case_id'],
                'activity_id' => $activityId,
                'previous_status' => $case['status_id:name'],
                'new_status' => $transition['to'],
            ]);
        } catch (\Throwable $e) {
            \Civi::log()->error('ProjectCloseStatusSubscriber.php - Failed: ' . $e->getMessage(), [
                'activity_id' => $activityId,
            ]);
        }
    }

    /**
     * Match the activity subject against the close-template subjects.
     *
     * @return array{from: string[], to: string}|null
     */
    private function matchTransition(string $activitySubject): ?array
    {
        foreach ($this->getTemplateSubjects() as $title => $subject) {
            if ($subject !== '' && str_contains($activitySubject, $subject)) {
                return self::TRANSITIONS[$title];
            }
        }
        return null;
    }

    /**
     * @return int[] activity_type values for Email + Sent Automated Email
     */
    private function getEmailTypeIds(): array
    {
        if (self::$emailTypeIds === null) {
            $rows = \Civi\Api4\OptionValue::get(false)
                ->addWhere('option_group_id.name', '=', 'activity_type')
                ->addWhere('name', 'IN', ['Email', LifecycleMailer::TYPE_SENT])
                ->addSelect('name', 'value')
                ->execute();
            self::$emailTypeIds = [];
            foreach ($rows as $row) {
                self::$emailTypeIds[$row['name']] = (int) $row['value'];
            }
        }
        return array_values(self::$emailTypeIds);
    }

    /**
     * @return array<string,string> template msg_title => msg_subject
     */
    private function getTemplateSubjects(): array
    {
        if (self::$templateSubjects === null) {
            $rows = \Civi\Api4\MessageTemplate::get(false)
                ->addWhere('msg_title', 'IN', array_keys(self::TRANSITIONS))
                ->addSelect('msg_title', 'msg_subject')
                ->execute();
            self::$templateSubjects = [];
            foreach ($rows as $row) {
                self::$templateSubjects[$row['msg_title']] = (string) ($row['msg_subject'] ?? '');
            }
        }
        return self::$templateSubjects;
    }
}
