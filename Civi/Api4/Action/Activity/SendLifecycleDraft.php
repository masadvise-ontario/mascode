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
        $errors = [];
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
                $errors[] = "Draft {$id}: " . $e->getMessage();
            }
        }

        // SearchKit's apiBatch runner only distinguishes HTTP success from
        // rejection — it never inspects per-row status. If we returned quietly,
        // a failed send would come back HTTP 200 and the CSM would be told the
        // draft was "Sent" while no email left. Throw so the task's error path
        // fires with the real reason(s); successful sends in the same batch
        // have already happened and are recorded on their cases.
        if ($errors) {
            throw new \CRM_Core_Exception(implode(' | ', $errors));
        }
    }
}
