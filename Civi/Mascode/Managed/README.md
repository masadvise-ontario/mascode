# Managed Entities

CiviCRM scans this directory (and the rest of the extension) for `*.mgd.php` files at install/upgrade time. Each file declares one or more entities that mascode owns. When the extension is enabled, Civi reconciles the live database to match these declarations.

## What lives here

| File | Entity | Phase | Purpose |
|------|--------|-------|---------|
| `OptionValue_CaseStatus_AwaitingCloseForm.mgd.php` | OptionValue | mas-lifecycle Phase 1 | Project status: "Awaiting Close Form" (VC done, awaiting client close form) |
| `OptionValue_ActivityType_DraftEmail.mgd.php` | OptionValue | mas-lifecycle Phase 1 | Activity type for propose-mode CiviRules (draft email needs review) |
| `OptionValue_ActivityType_SentAutomatedEmail.mgd.php` | OptionValue | mas-lifecycle Phase 1 | Activity type for auto-mode CiviRules (traceability) |
| `CustomField_Project_EstimatedCompletionDate.mgd.php` | CustomField | mas-lifecycle Phase 1 | Drives close-chase cadence; new field on existing Projects custom group |
| `CaseType_ServiceRequest.mgd.php` | CaseType | mas-lifecycle Phase 1 | Full ownership of Service Request case type definition |
| `CaseType_Project.mgd.php` | CaseType | mas-lifecycle Phase 1 | Full ownership of Project case type definition (incl. Awaiting Close Form status) |
| `OptionValue_CaseStatus_Open_Duplicate_Deactivate.mgd.php` | OptionValue | mas-lifecycle Phase 1 cleanup | Pins the legacy duplicate `Open`/`Open` case_status to is_active=false (CiviCRM core ships `Open`/`Ongoing`; the duplicate was a UI-added accident). 2 stragglers migrated to status_id=1 on 2026-05-31. |
| `MessageTemplate_rcs_chase__client.mgd.php` | MessageTemplate | mas-lifecycle Phase 3 | Skeleton — chase to client whose RCS/SAS forms are outstanding. Body filled via UI. |
| `MessageTemplate_vc_assignment_offer__vc.mgd.php` | MessageTemplate | mas-lifecycle Phase 3 | Skeleton — VC-facing assignment offer when an SR enters "Sent for Assignment". |
| `MessageTemplate_vc_no_pickup_chase__vc.mgd.php` | MessageTemplate | mas-lifecycle Phase 3 | Skeleton — chase to VC if an assignment offer is not picked up. |
| `MessageTemplate_consultant_intro__client.mgd.php` | MessageTemplate | mas-lifecycle Phase 3 | Skeleton — replaces Nina's copy/pasted consultant intro on Project create. |
| `MessageTemplate_close_chase__client.mgd.php` | MessageTemplate | mas-lifecycle Phase 2 | Skeleton — chase to client if close form is outstanding. Uses `{tokenized_close_url}` once Phase 2 token mechanism lands. |
| `MessageTemplate_donation_notify__ed.mgd.php` | MessageTemplate | mas-lifecycle Phase 4 | Skeleton — donation notification to ED. Fanned out by Contribution.create Symfony subscriber. |
| `MessageTemplate_donation_notify__treasurer.mgd.php` | MessageTemplate | mas-lifecycle Phase 4 | Skeleton — donation notification to Treasurer (Steve). |
| `MessageTemplate_donation_notify__vc.mgd.php` | MessageTemplate | mas-lifecycle Phase 4 | Skeleton — donation notification to originating VC. |
| `MessageTemplate_anniversary_checkin__client.mgd.php` | MessageTemplate | mas-lifecycle Phase 4 | Skeleton — twelve-month project anniversary check-in to client. |
| `MessageTemplate_MAS_RCS_Template.mgd.php` | MessageTemplate | snapshot (pre-Phase 1) | Existing initial RCS+SAS ask, brought under management with `update='unmodified'`. Body in sibling `.body.html`. |
| `MessageTemplate_MAS_Form_Submission_Confirmation.mgd.php` | MessageTemplate | snapshot (pre-Phase 1) | Existing auto-sent Afform submission confirmation. Body in sibling `.body.html`. |
| `MessageTemplate_MAS_Project_Close_VC_Template.mgd.php` | MessageTemplate | snapshot (pre-Phase 1) | Existing VC close-form ask. Body in sibling `.body.html`. |
| `MessageTemplate_MAS_Project_Close_Client_Template.mgd.php` | MessageTemplate | snapshot (pre-Phase 1) | Existing client close-form ask. Body in sibling `.body.html`. |
| `MessageTemplate_after_RCS.mgd.php` | MessageTemplate | snapshot (pre-Phase 1) | Existing "your request got circulated" notice to client at SR→Sent for Assignment. Informal title preserved. Body in sibling `.body.html`. |
| `MessageTemplate_MAS_SAS_Template_Deactivate.mgd.php` | MessageTemplate | cleanup pin | Deactivates legacy "MAS SAS Template" (id 72 — superseded by the RCS template which now includes both SAS variants). |

## Sidecar `.body.html` files

Templates whose bodies are version-controlled use a sidecar `.body.html` file alongside the `.mgd.php`. The `.mgd.php` loads the body via `file_get_contents(__DIR__ . '/<name>.body.html')`. This keeps HTML readable in git diffs and preserves CRLF line endings (which matters for CiviCRM's `is_modified` hash detection).

When the in-UI body diverges from the sidecar:
1. Run `cv api4 MessageTemplate.get` to dump the live `msg_html`
2. Diff against the `.body.html` snapshot
3. Either refresh the sidecar (snapshot stale, accept the UI version) or revert in Civi (sidecar canonical, push to UI)
4. The diff-before-deploy workflow drives the cadence

## Cleanup policy

| Entity type | `cleanup` | Why |
|-------------|-----------|-----|
| OptionValue (case_status, activity_type) | `unused` | Don't drop a status/type if cases or activities still reference it |
| CustomField | `never` | Schema-level drop = permanent data loss; uninstalling mascode should NOT remove fields |
| CaseType | `never` | Cases reference case types via FK; dropping a case type would orphan thousands of cases |
| MessageTemplate | `never` | Templates may be referenced by historical activities; uninstall should NOT delete |

`update` is `always` on case-type config (mascode is authoritative; UI drift reverts on next reconcile — Brian is the sole editor). MessageTemplate entries use `update='unmodified'`: mascode plants the skeleton (name, subject, structure, merge-tag scaffolding), but body edits in the Civi admin UI survive subsequent reconciles. Nina/Brian/Steve own template body content; mascode owns the structure around it.

## Post-CiviCase-upgrade checklist

When bumping CiviCRM (any major version), run through this before deploying mascode to prod:

1. **Apply CiviCRM upgrade in dev first** (`/mas-clone` if state matters, then `cv upgrade-db`).
2. **Reconcile managed entities**: `cv api4 Managed.reconcile`.
3. **Smoke test**: `vendor/bin/phpunit --group case_type` (runs `tests/Integration/Managed/CaseTypeSmokeTest.php`).
4. **Inspect the live CaseType definitions** for new fields CiviCase may have added:
   ```bash
   cv api4 CaseType.get '{"select":["definition"]}'
   ```
   Compare to the `.mgd.php` definitions here. If CiviCase added a new optional key (e.g., a new automation setting), decide whether to manage it explicitly or let Civi default it.
5. **Create a sample case in each type** via the Civi admin UI — make sure all expected statuses appear in the dropdown and the timeline activity creates.
6. **Only then** push to master and pull on prod.

## Adding new managed entities

1. Add a new `*.mgd.php` file in this directory returning an array of one or more entries.
2. Use `match` to identify existing rows (so reconcile UPDATEs rather than creating duplicates).
3. Use `option_group_id.name`, `custom_group_id.name`, etc. (FK-by-name) — never raw IDs.
4. Pick `cleanup` per the policy table above.
5. Run `cv api4 Managed.reconcile` in dev to apply.
6. Verify with `cv api4 Managed.get '{"where":[["module","=","mascode"]]}'`.

## References

- Spec: BrianPKM `3-Resources/mas-engagement-lifecycle-automation-spec.md`
- Dashboard task #107: MAS Lifecycle: state + templates (Phase 1)
- CiviCRM docs: <https://docs.civicrm.org/dev/en/latest/extensions/civix/#managed-entities>
