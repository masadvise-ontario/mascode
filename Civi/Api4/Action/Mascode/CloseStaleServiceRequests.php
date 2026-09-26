<?php

declare(strict_types=1);

// file: Civi/Api4/Action/Mascode/CloseStaleServiceRequests.php

namespace Civi\Api4\Action\Mascode;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Mascode\Service\StaleServiceRequestCloser;

/**
 * Close Service Requests stuck in "Request RCS" for more than 64 days since
 * they opened, once a reminder has actually been sent.
 *
 * The rules and why they are shaped as they are live in
 * StaleServiceRequestCloser. Invocation:
 *
 *   cv api4 Mascode.closeStaleServiceRequests '{"dryRun":1}'
 *   cv api4 Mascode.closeStaleServiceRequests '{"dryRun":0,"caseIds":[123,456]}'
 *   cv api4 Mascode.closeStaleServiceRequests '{"dryRun":0,"allEligible":1}'   (the daily Job)
 *
 * camelCase — `dry_run` fails with "Unknown api parameter" (see RunVcDigest).
 *
 * Returns ONE row holding the whole report, like RunVcDigest: the skipped
 * buckets (`stale_without_reminder`, `no_start_date`) are the part the office
 * acts on, and a per-case result set would hide them.
 */
class CloseStaleServiceRequests extends AbstractAction
{
    /**
     * Reckon ages as at this date (`Y-m-d`); defaults to today.
     *
     * @var string|null
     */
    protected $asOf = null;

    /**
     * Close only these case ids — the list approved from a dry run.
     *
     * Accepts an array or a CSV string. An unreadable value is REFUSED, since
     * empty means every eligible case.
     *
     * @var array|string|null
     */
    protected $caseIds = null;

    /**
     * Plan only. Defaults to TRUE: forgetting the parameter produces a report,
     * not closed cases.
     *
     * @var bool
     */
    protected $dryRun = true;

    /**
     * Live run with no list: close every case that qualifies today.
     *
     * The daily scheduled Job's mode (Job_MasCloseStaleServiceRequests). It must
     * be asked for by name: `dryRun=0` alone is refused, so forgetting
     * `caseIds` can never become "close everything".
     *
     * @var bool
     */
    protected $allEligible = false;

    public function _run(Result $result)
    {
        // As the system, even when a person runs it: the close is the sweep's
        // decision, and that is what the Change Case Status activity must say.
        $result[] = \Civi\Mascode\Util\SystemContext::run(fn() => StaleServiceRequestCloser::run([
            'as_of' => $this->asOf,
            'case_ids' => $this->caseIds,
            'all_eligible' => $this->allEligible,
            'dry_run' => $this->dryRun,
        ]));
    }
}
