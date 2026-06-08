# mascode deploy/ops scripts

Run with: `cv scr scripts/<name>.php --user=<admin user>`

- `create-close-chase-rule.php` — creates the `mas_lifecycle_close_chase`
  CiviRule (trigger: changed_case; conditions: case type = project AND
  transitioned to "Awaiting Close Form" AND still in that status when each
  delayed action fires; actions: 3× `mas_lifecycle_email` in propose mode at
  30/90/150 days). Idempotent — skips if the rule name already exists. Run
  once per environment after the mas_lifecycle_email CiviRules action is
  registered (PostInstallOrUpgradeHook or
  `CRM_Civirules_Utils_Upgrader::insertActionsFromJson`). Requires the
  CiviRules cron (processDelayedActions) to be scheduled for the delayed
  chases to fire.
- `create-rcs-chase-rule.php` — creates the `mas_lifecycle_rcs_chase`
  CiviRule (trigger: changed_case; conditions: case type = service_request
  AND transitioned to "Request RCS" AND still in that status when each
  delayed action fires; actions: 2× `mas_lifecycle_email` in propose mode at
  21/42 days). The RCS Afform submission moves the SR to "RCS Completed",
  which self-cancels pending chases. Same prerequisites as the close-chase
  script; idempotent.
- `fast-forward-chases.php` — **dev-only** test helper: makes queued delayed
  CiviRules actions due now and processes them, so the chase cadence can be
  tested without waiting. Default releases ALL items at once (quick "did it
  fire" check; dedup collapses one case's steps to a single draft). Add
  `-- --step` to release only the earliest cadence step (all items within a
  1-hour window of the earliest release time — same-step duplicates batch as
  in production, later delays stay queued), so you can step the real cadence:
  `--step` (draft #1) → send it from the review tile → `--step` (draft #2) →
  … (`--one` is a back-compat alias.) Refuses to run unless the base URL is
  masdemo.localhost.
