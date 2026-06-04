<?php

// File: Civi/Mascode/Event/CaseOpenActivitySubscriber.php

namespace Civi\Mascode\Event;

use Civi\Core\Service\AutoSubscriber;
use Civi\Core\Event\PostEvent;

/**
 * Give the auto-created "Open Case" activity a real clock time.
 *
 * CiviCRM dates the "Open Case" activity to the case start_date, which is a
 * date-only field, so the activity always lands at midnight (12:00 AM). When the
 * Open Case activity is created, this subscriber keeps its start date but sets the
 * time portion to the actual (server-local) creation time, so the case timeline
 * reflects when the case really opened. Applies to every case type and creation
 * path (DB FormProcessor intake, the ServiceRequestToProject CiviRule, or the API).
 */
class CaseOpenActivitySubscriber extends AutoSubscriber
{
    /** @var int|null Cached "Open Case" activity_type option value */
    private static ?int $openCaseTypeId = null;

    /**
     * {@inheritdoc}
     */
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
        // Only when an Activity is first created (the update below fires an 'edit'
        // post, which this guard ignores, so there is no recursion).
        if ($event->action !== 'create' || $event->entity !== 'Activity') {
            return;
        }

        $activityId = (int) $event->id;
        if (!$activityId) {
            return;
        }

        try {
            $openCaseTypeId = $this->getOpenCaseTypeId();
            if (!$openCaseTypeId) {
                return;
            }

            // Fast reject: skip non-Open-Case activities without a query when the
            // posted object exposes the type.
            $objTypeId = isset($event->object->activity_type_id) ? (int) $event->object->activity_type_id : null;
            if ($objTypeId !== null && $objTypeId !== $openCaseTypeId) {
                return;
            }

            $act = \Civi\Api4\Activity::get(false)
                ->addWhere('id', '=', $activityId)
                ->addSelect('activity_type_id', 'activity_date_time')
                ->execute()
                ->first();

            if (empty($act) || (int) $act['activity_type_id'] !== $openCaseTypeId) {
                return;
            }

            // Keep the start date, replace the (midnight) time with DB-local NOW().
            $datePart = substr((string) $act['activity_date_time'], 0, 10);
            $localNow = \CRM_Core_DAO::singleValueQuery('SELECT NOW()');
            $newDateTime = $datePart . substr((string) $localNow, 10); // " HH:MM:SS"

            \Civi\Api4\Activity::update(false)
                ->addWhere('id', '=', $activityId)
                ->addValue('activity_date_time', $newDateTime)
                ->execute();
        } catch (\Throwable $e) {
            \Civi::log()->error('CaseOpenActivitySubscriber.php - Failed to set Open Case activity time', [
                'activity_id' => $activityId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Resolve and cache the "Open Case" activity_type option value.
     */
    private function getOpenCaseTypeId(): int
    {
        if (self::$openCaseTypeId === null) {
            $row = \Civi\Api4\OptionValue::get(false)
                ->addWhere('option_group_id.name', '=', 'activity_type')
                ->addWhere('name', '=', 'Open Case')
                ->addSelect('value')
                ->execute()
                ->first();
            self::$openCaseTypeId = (int) ($row['value'] ?? 0);
        }
        return self::$openCaseTypeId;
    }
}
