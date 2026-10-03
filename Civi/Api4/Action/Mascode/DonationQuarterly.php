<?php

declare(strict_types=1);

// file: Civi/Api4/Action/Mascode/DonationQuarterly.php

namespace Civi\Api4\Action\Mascode;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Mascode\Service\DonationReport;

/**
 * The Treasurer's quarterly donations report (donations ticket DN-4).
 *
 *   cv api4 Mascode.donationQuarterly
 *   cv api4 Mascode.donationQuarterly '{"from":"2025-01-01","to":"2026-09-30"}'
 *
 * Returns ONE row holding the whole report (quarters plus footnote lists), for
 * the same reason as runVcDigest: the parts only mean anything together.
 * The admin page civicrm/mas/donations/quarterly renders it.
 */
class DonationQuarterly extends AbstractAction
{
    /**
     * First day of the range (`Y-m-d`), matched against the project's end_date.
     * Defaults to DonationReport::DEFAULT_FROM, where linked data begins.
     *
     * @var string|null
     */
    protected $from = null;

    /**
     * Last day of the range (`Y-m-d`); defaults to today.
     *
     * @var string|null
     */
    protected $to = null;

    public function _run(Result $result)
    {
        $result[] = DonationReport::quarterly($this->from, $this->to);
    }
}
