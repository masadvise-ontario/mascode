# CHANGELOG

## 1.1.13 (2026-08-30)

### Features
* The two VC project forms (`afformMASProjectDefinitionVC`, `afformProjectCloseVCFeedback`) now let the Volunteer Consultant maintain the **client representative**, alongside their own name and email. Correcting the rep's email updates that person's contact record in place; changing their name is treated as a different person taking over, so a new contact is created, the outgoing rep's `Case Client Rep is` role on that case is ended and end-dated, and the incoming contact receives that role plus an `Employee of` link to the client organisation — mirroring what the RCS form does for a new President or Executive Director.
* The client-rep fields are optional. On the 2026-05-30 dev clone, 23 of 154 Active project cases carry no active client rep, and a VC must not be blocked from filing a close report because CiviCRM is missing that contact. A blank fieldset creates nothing.

### What counts as "a different person"

Deciding this correctly took most of the work, and every rule below exists because getting it wrong wrote to a real contact silently. Stated once here rather than scattered through the fix list:

* **Blank means "no change".** A blank half of the name, or a blank email, is never written. Blankness is tested on the trimmed string cast, not `isset()` or `!empty()` — a present-but-`null` field and a present-but-empty-`array` join both slip past those and reach `first_name = NULL` and `Email::delete` respectively.
* **A handover needs BOTH name halves.** One half blank is an incomplete edit and writes neither half. Dropping only the blank half renamed the incumbent instead of leaving them alone.
* **A handover is only detectable from a field that HAD a value.** On the dev clone, 4 of the 382 people holding an active client-rep role have an empty `last_name` (1 of the 195 whose role is *current*); a VC filling one in is completing the record, not replacing the person.
* **An unfinishable handover writes nothing at all** — not the name, and not the email or any other join in the same submission. Suppressing only the name left the incumbent holding the role with the *incoming* person's address.
* **An ambiguous case writes to nobody.** A case carrying more than one client rep has its fieldset ignored entirely: which one the form autofilled from is not knowable server-side, because core issues that query with no `ORDER BY`. Merely declining to move the role was not enough — Afform's default still renamed whichever contact it had autofilled.

### Fixes
* **Join ids are dropped whenever the contact id is.** Without this the outgoing person's Email row — whose id the browser echoes back from the prefill — is *reassigned* to the new contact by `Email::replace()`, leaving them with no email address and no error anywhere. Reproduced on dev before the fix and confirmed neutralised after.
* **The incoming role is created before the outgoing one is ended.** `Afform.submit` is not transactional (`TransactionSubscriber` returns early for APIv4), so the previous order left a window in which an interruption — a hook or CiviRules action vetoing the create, a deadlock, an execution-time kill — left the case with **no** active client rep at all, silently. Creating first inverts that into a transient duplicate, which is visible and warned about.
* **Writes are pinned to the contact holding the case role.** Core's `ContactDedupe` (priority 101) could otherwise retarget an email correction onto a duplicate matching first+last+email, leaving the actual rep with the stale address and nothing logged. The incumbent is read from the case's own role, never from the submitted record id.
* **Relationship writes are skipped, and logged as an error, when no contact was actually saved.** `processGenericEntity()` catches and merely logs a failed `Contact::save`, so the id otherwise used could have been the pre-populated or dedupe-matched one.
* `createRelationshipIfNotExists()` scopes its existence check to non-case rows, so a case-scoped relationship no longer suppresses creation of the standing organisation-wide one.
* Client-rep tracking state is cleared at the start of every submission rather than relying on cleanup in a later branch, and `getSessionId()`'s no-session fallback is memoised instead of ending in `time()` — which could return two different keys either side of a second boundary within one submission and orphan the stored data. CLI/`cv scr` only; web submissions always have a session.
* `_entityIds` is kept in step with the pinned record id, so a swallowed save failure cannot leave an audit trail naming a contact that was never written.

### Tests
* `tests/Live/ClientRepChangeTest.php` — twelve scenarios across ten independent cases against a live site (`cv scr`), 53 assertions: email-only, last-name change, email cleared, no-rep blank, no-rep supplied, first-name-only, prefilled-fieldset-emptied, two-reps-refused, one-name-half-cleared, incumbent-with-no-last-name, first-cleared-plus-last-changed, and blank-surname-on-file-plus-handover-attempted. Every scenario after the first two was added in response to a review round, and **each found a real defect**. Scenario J also asserts `display_name` and `sort_name` are recomputed on an in-place name write — a source-level reading suggested they would go stale, and measurement showed they do not.
* `tests/Unit/Event/ClientRepWiringTest.php` — CI-runnable source tripwire pinning the invariants CI can see, since it cannot run the live test: the pre-process priority is bounded on both sides, the join-id strip stays paired with the contact-id strip, the incumbent is read from the case, an ambiguous case clears the record rather than merely returning, the outgoing role is *ended* rather than a second one merely added, a blank email drops the join unconditionally, an incomplete name writes neither half, an unfinishable handover suppresses the joins too, a handover requires the field to have had a value, and both forms and both routes stay in scope. Each assertion is mutation-checked in both directions — broken invariants must fail it, and the reformattings a maintainer would plausibly apply must not.

## 1.1.12 (2026-08-29)

### Fixes
* Rules-as-code: `mas_lifecycle_rcs_chase` now has an `ensureRcsChaseRule()` provisioner and an `upgrade_5011` caller, so `cv upgrade:db` provisions it like every other lifecycle rule instead of it existing only where the creation script was run by hand.
* Rename the two auto-send rules off their fossil `_propose` names — `mas_lifecycle_vc_close_propose` → `mas_lifecycle_vc_close_send`, `mas_lifecycle_pd_client_propose` → `mas_lifecycle_pd_client_send` — since both have sent immediately (not queued a draft) since 1.1.10. `upgrade_5011` migrates the `civirule_rule` rows on existing installs so the provisioner does not create duplicates.

## 1.1.11 (2026-08-27)

### Fixes
* Delayed lifecycle emails now honour the live send mode rather than the mode snapshotted when the action was queued (#17)
* Board dashboard service-request rows exclude MAS's own cases (#18)

### Docs
* Stop restating the lifecycle send mode in script docblocks (it is rewritten by exact-phrase match, so a stale copy silently breaks the sync)
* Retire the frozen deploy scripts from the setup and deploy paths; sharpen the dev/prod parity rule to cover cited dev data

## 1.1.10 (2026-08-20)

### Features
* Lifecycle emails send immediately instead of being drafted for review (propose → auto)
* Board dashboard: previous-quarter column, row 5 drill-down lists open projects, consistent drill-down lists
* Bring API4 patterns in-repo; version the site-local `ang/` directory in mascode

### Fixes
* Gate project metric dashlets on `edit all contacts`
* Name the SR assignment display so `acl_bypass` applies

## 1.1.9 (2026-07-01)

### Changes
* Move the client-authored `expected_benefits` custom field to the Project Definition Authorization group

## 1.1.8 (2026-06-30)

### Features
* Consolidate project hours onto the close-report `hours_worked` field; retire the legacy `Projects.Hours` field (#16)
* VC Portal: client Project Definition form gains an agree-with-description checkbox (required); prefill VC info from the case coordinator
* SearchKit: Download Spreadsheet on list displays; show MAS Rep (Case Coordinator) on project searches; status filter + start-date sort on My Cases Report
* Add the `mas-vc-sync` skill for VC identity audit/repair

### Fixes
* Repair case-detail action-button links (crmUrl + case_id)
* Send drafts whose meta comment was stripped, and surface send errors

## 1.1.7 (2026-06-20)

### Features
* VC Portal: file-back the My Cases Report and Sent-for-Assignment afforms

## 1.1.6 (2026-06-19)

### Features
* VC Portal: secure custom case-detail page with a native-screen guard (#608)

## 1.1.5 (2026-06-17)

### Features
* Make the three VC Menu SearchKit searches managed entities

## 1.1.4 (2026-06-17)

### Fixes
* Email Drafts dashlet: the Case link opens the Manage Case screen

## 1.1.3 (2026-06-17)

### Features
* Project Definition: the VC defines completion criteria and the client authors expected benefits

### Fixes
* Order project case sections so the VC Report precedes Client Feedback

## 1.1.2 (2026-06-17)

### Fixes
* Token form links work when the visitor is already logged in (previously HTTP 401)

## 1.1.1 (2026-06-16)

### Features
* Project Definition and Project Close answers move onto the case; the client form shows the VC definition
* Cases dashlets show all open-class statuses in sequence, auto-derived

### Fixes
* Restore token prefill for already-logged-in visitors
* RCS chase includes the RCS and SAS form links; include Project Definition answers in the submission-confirmation summary

### Tools
* Managed-drift checker extended to afform overrides (flags UI-edited managed entities that reconcile will skip)

## 1.1.0 (2026-06-12)

### Features
* Close-path status rework, MAS-code email subjects, and close automation
* Quarterly Board Dashboard: QTD board metrics (rows 1–21) for VC + Client organizations, plus an ED dashlet

### Fixes
* Gate staff dashboards on `edit all contacts`, not `access all cases and activities`

### Docs
* Document the configuration-as-code model and the canonical deploy ritual; trim CLAUDE.md (prod-access moved to a shared protocol)

## 1.0.6 (2026-06-09)

The MAS engagement-lifecycle automation build (Phases 1–4). Large release consolidating the lifecycle work.

### Features
* Lifecycle runtime: `LifecycleMailer` + CiviRules action + VC tokens in propose mode, with an idempotency guard
* Cases Dashboard: status-count matrix (SR + Projects, open/closed by quarter/year), outcome pie dashlets, home-dashboard dashlets, and count → filtered-case-list drill-down
* CSM action-queue page (`afformMASOpsHome`) + four queue searches; Email Drafts review/send dashlet with inline rendered preview
* One-step flows: sending the RCS email advances the Service Request status; the client close email advances the case to Awaiting Close Form
* RCS-chase and close-chase cadence rules; metadata-driven submission summary themed into the six FSAS categories
* Package five client Afforms with name-based references; manage four activity types; MAS-navy Afform pane title bars

### Fixes
* Pass mail params by variable (`CRM_Utils_Mail::send` is by-reference); explicit condition weights in rule-creation scripts
* Repair RCS-created President/ED contacts losing `employer_id`; make the Full SAS form publicly accessible via token link

### Tools & Docs
* Harden `/mas-clone` against migration-induced serialization corruption; `mas-deploy` pre-push diff-vs-prod + capture-prod-SHA backup (#15)
* Document Production Access (Safe Inspection) patterns; add `.env.example`

## 1.0.5 (2025-10-19)

### Changes
* Version bump; no functional changes recorded.

## 1.0.4 (2025-07-02)

### Form Enhancements and Email Improvements
* Updated afformMASSASS with section headings to match afformMASSASF layout
* Enhanced AfformSubmitSubscriber to send confirmation emails for all forms (RCS, SASS, SASF)
* Synchronized deployment scripts with current development environment layouts
* Modified survey deployment scripts to overwrite existing forms for consistent behavior
* Updated project documentation with CV command patterns and deployment best practices

## 1.0.3 (2025-06-18)

### Enhanced Export/Import Functionality
* **BREAKING**: Replaced fragile export/import system with robust deployment scripts
* Add `deploy_self_assessment_surveys.php` for automated SASS/SASF deployment
* Add `deploy_civirules.php` for automated CiviRules deployment with proper API4 entities
* Add `deploy_rcs_form.php` for automated RCS form deployment
* Add `deploy_form_processors.md` for manual Form Processor deployment documentation

### Self Assessment Survey System
* Add Short Self Assessment Survey (SASS) - 21 questions
* Add Full Self Assessment Survey (SASF) - 35 questions 
* Create unified custom field group for both survey types (DRY principle)
* Implement Activity-based storage with Organization → Individual → Activity → Case structure

### CiviRules Integration
* Export existing CiviRules configuration to JSON files
* Implement proper CiviRules API4 entity usage (CiviRulesTrigger, CiviRulesCondition, etc.)
* Add environment-specific deployment with foreign key mapping

### Development Workflow
* Update development to production workflow documentation
* Add environment-specific configuration management
* Implement script-based deployment replacing export/import functionality

## 1.0.2 (2025-06-04)

* Add CiviRules export script for deployment between environments
* Add CiviRules import script with ID mapping and safety features
* Add script to create employer relationships based on job titles (President, Executive Director)
* Fix PHP warnings and improve error handling in scripts

## 1.0.0 (work in progress)

* Convert legacy data from access DB