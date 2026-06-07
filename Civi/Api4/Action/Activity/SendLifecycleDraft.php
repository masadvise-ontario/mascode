<?php

declare(strict_types=1);

// file: Civi/Api4/Action/Activity/SendLifecycleDraft.php

namespace Civi\Api4\Action\Activity;

use Civi\Api4\Generic\AbstractBatchAction;
use Civi\Api4\Generic\Result;
use Civi\Mascode\Service\LifecycleMailer;

/**
 * Send reviewed MAS-lifecycle draft emails.
 *
 * Batch action over "Draft Email - Needs Review" activities: each selected
 * draft is sent to its stored recipient via LifecycleMailer::sendDraft()
 * (draft -> Completed, a "Sent Automated Email" activity is recorded on the
 * case). Non-draft / already-sent selections come back as error rows rather
 * than failing the batch — SearchKit's task runner shows them per-row.
 *
 * Usage:
 *   Activity.sendLifecycleDraft where=[['id','IN',[...]]]
 * Exposed in SearchKit as the "Send draft email (MAS lifecycle)" task
 * (mascode_civicrm_searchKitTasks).
 */
class SendLifecycleDraft extends AbstractBatchAction
{
    /**
     * @param \Civi\Api4\Generic\Result $result
     */
    public function _run(Result $result)
    {
        foreach ($this->getBatchRecords() as $record) {
            $id = (int) $record['id'];
            try {
                $sent = LifecycleMailer::sendDraft($id);
                $result[] = [
                    'id' => $id,
                    'status' => 'sent',
                    'sent_activity_id' => $sent['sent_activity_id'],
                    'recipient_email' => $sent['recipient_email'],
                ];
            } catch (\Throwable $e) {
                $result[] = [
                    'id' => $id,
                    'status' => 'error',
                    'error_message' => $e->getMessage(),
                ];
            }
        }
    }
}
