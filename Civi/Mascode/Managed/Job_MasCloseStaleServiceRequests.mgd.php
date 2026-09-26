<?php

declare(strict_types=1);

/**
 * Daily scheduled Job: close Service Requests stale in "Request RCS".
 *
 * Nina's decision (2026-09-24) that stale requests close themselves, run daily
 * at Brian's choice (2026-09-25) so a case closes on day 65 rather than
 * somewhere in a week. The rules — more than 64 days since the case opened, at
 * least one SENT RCS reminder, the latest at least 22 days old, into "No Client
 * Response" — live in StaleServiceRequestCloser. A stale case with no reminder
 * on file is never closed by this Job.
 *
 * `allEligible` is the only thing that lets a live run proceed without an
 * approved case list, and it is set here and nowhere else.
 *
 * `checkPermissions: false`: cron may run anonymously, and the action is gated
 * on "administer CiviCRM". The Job is itself admin-configured, so the gate is
 * who may edit Scheduled Jobs, not who triggers cron.
 *
 * What a run closed is NOT in the Job log: CiviCRM's JobManager cannot read an
 * APIv4 Result and records only "Success" or the error. It is in the CiviCRM
 * log (StaleServiceRequestCloser.php line, `closed_case_ids`), and each closed
 * case carries a "Change Case Status" activity reported by MAS Automated System.
 *
 * `update => 'unmodified'`: Job is an APIv4 ManagedEntity, so disabling it (or
 * changing its frequency) in Administer > Scheduled Jobs survives later
 * deploys. **Disable it; do not delete it** — a deleted managed Job is
 * recreated, active, by the next reconcile. `cleanup => 'always'`: nothing
 * references a Job row.
 *
 * It runs only where the CiviCRM `environment` setting is Production (core's
 * isAPIJobAllowedToRun), so on dev it logs "not executed" unless run by hand.
 */
return [
  [
    'name' => 'Job_MasCloseStaleServiceRequests',
    'entity' => 'Job',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'MAS: Close stale Request RCS service requests',
        'description' => 'Closes Request RCS service requests as No Client Response once opened over 64 days and the last RCS reminder is at least 22 days old (about 64 days after entering Request RCS). No reminder: left for staff.',
        'run_frequency' => 'Daily',
        'api_entity' => 'Mascode',
        'api_action' => 'closeStaleServiceRequests',
        'parameters' => '{"version":4,"dryRun":0,"allEligible":1,"checkPermissions":false}',
        'is_active' => true,
      ],
      'match' => ['name'],
    ],
  ],
];
