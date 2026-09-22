<?php

declare(strict_types=1);

// file: Civi/Api4/Action/Mascode/RunVcDigest.php

namespace Civi\Api4\Action\Mascode;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Mascode\Service\VcDigestRunner;

/**
 * Plan a monthly VC donation-digest run.
 *
 * The spec (§Triggers) requires this to exist BEFORE the scheduled Job does:
 * "Manual — `cv api4` invocation of the same action, with `dry_run` and
 * `pilot_vc_ids`. The pilot and testing path; must exist before the Job is ever
 * enabled." P2-1's Job is a thin wrapper over this, behind the falsification
 * gate.
 *
 * Dry run, as it stands today:
 *
 *   cv api4 Mascode.runVcDigest '{"dryRun":1}'
 *   cv api4 Mascode.runVcDigest '{"dryRun":1,"asOf":"2026-10-01"}'
 *   cv api4 Mascode.runVcDigest '{"dryRun":1,"pilotVcIds":[123,456]}'
 *
 * ⚠ camelCase, and the spec's `dry_run=1` shorthand will NOT work. API4
 * derives a setter as `set` . ucfirst($name), so `dry_run` becomes
 * `setDry_run` and core throws "Unknown api parameter". An earlier version of
 * this docblock showed the snake_case form in all three lines — the only
 * invocation documentation inside the code, and wrong. It fails loudly rather
 * than silently, which is the one mercy.
 *
 * It returns ONE result row holding the whole run summary, rather than one row
 * per VC. That is deliberate: the counts only mean anything together — 62 VCs
 * mailed is reassuring until you notice 14 projects had no coordinator — and a
 * per-VC result set would put the exception reports somewhere a caller has to
 * remember to look.
 */
class RunVcDigest extends AbstractAction
{
    /**
     * Plan the run as at this date (`Y-m-d`); defaults to today.
     *
     * Exists so a run can be reproduced and so a test is not hostage to the
     * calendar — D2's 30-day suppression makes every result date-dependent.
     *
     * @var string|null
     */
    protected $asOf = null;

    /**
     * Restrict delivery to these VC contact ids (D12's pilot).
     *
     * Accepts an array or a CSV string; a scheduled `Job` parameter field can
     * only hold the latter. An unreadable value is REFUSED rather than treated
     * as empty, because empty means all 62 volunteers.
     *
     * @var array|string|null
     */
    protected $pilotVcIds = null;

    /**
     * Plan only. Defaults to TRUE, and that default is the safety.
     *
     * A caller who forgets the parameter gets a plan, not 62 emails. The
     * spec's whole Phase 1 ordering — dry run, then a pilot subset, then the
     * gate — depends on the cautious thing being the easy thing.
     *
     * @var bool
     */
    protected $dryRun = true;

    public function _run(Result $result)
    {
        $summary = VcDigestRunner::run([
            'as_of' => $this->asOf,
            'pilot_vc_ids' => $this->pilotVcIds,
            'dry_run' => $this->dryRun,
        ]);

        $result[] = $summary;
    }
}
