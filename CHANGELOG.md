# CHANGELOG

## 1.1.13 (2026-08-30)

### Features
* The two VC project forms (`afformMASProjectDefinitionVC`, `afformProjectCloseVCFeedback`) now let the Volunteer Consultant maintain the **client representative**, alongside their own name and email. Correcting the rep's email updates that person's contact record in place; changing their first or last name is treated as a different person taking over, so a new contact is created, the outgoing rep's `Case Client Rep is` role on that case is ended and end-dated, and the incoming contact receives that role plus an `Employee of` link to the client organisation (mirroring what the RCS form does for a new President or Executive Director).
* The client-rep fields are optional. On the 2026-05-30 dev clone, 23 of 154 Active project cases carry no active client rep, and a VC must not be blocked from filing a close report because CiviCRM is missing that contact. A blank fieldset creates nothing.

### Fixes
* When a submitted contact id is dropped to force creation of a new contact, the submitted join ids are now dropped with it. Without this the outgoing person's Email row — whose id the browser echoes back from the prefill — is *reassigned* to the new contact by `Email::replace()`, leaving the outgoing contact with no email address and no error anywhere. Reproduced on dev before the fix and confirmed neutralised after.

### Fixes (from fresh-context review of this change)
* The incoming client rep's case role is now created **before** the outgoing one is ended. `Afform.submit` is not transactional (`TransactionSubscriber` returns early for APIv4), so the previous order left a window in which an interrupted pair — a hook or CiviRules action vetoing the create, a deadlock, an execution-time kill — would leave the case with **no** active client rep at all, silently. Creating first inverts that into a transient duplicate, which is visible and self-correcting.
* A blank email on the client-rep fieldset now means "leave the existing address alone" rather than blanking or deleting the row. The join allows update and delete, so an empty value previously reached `saveJoins()` as either a blank write or an `Email::delete` over the whole where clause.
* On the email-only path the write is now pinned to the contact holding the case role. Core's `ContactDedupe` (priority 101) could otherwise retarget the correction onto a duplicate contact matching first+last+email, leaving the actual rep with the stale address and nothing logged.
* The relationship writes are skipped, and logged as an error, when a replacement was expected but no contact was actually saved — `processGenericEntity()` catches and merely logs a failed `Contact::save`, so the id previously used could have been the pre-populated or dedupe-matched one.
* The incumbent client rep is resolved in relationship-cache order, matching the order core's autofill displays, and de-duplicated (one dev case carries two cache rows pointing at the same contact). *(Superseded within this release: see the ambiguous-case entry below — the method became the plural `getCaseClientRepIds()` and the caller now refuses ambiguous cases outright rather than warning and continuing.)*
* The no-session fallback in `getSessionId()` is memoised instead of ending in `time()`, which could return two different keys either side of a second boundary within one submission and orphan the stored data. CLI/`cv scr` only; web submissions always have a session.
* `createRelationshipIfNotExists()` now scopes its existence check to non-case rows, so a case-scoped relationship no longer suppresses creation of the standing organisation-wide one.

### Fixes (from round 2 of review, on the round-1 fix commit itself)
* A case carrying more than one client rep is now left entirely alone — the fieldset is ignored and nothing is written. Which of the two the form autofilled from is not knowable server-side (core issues that query with no `ORDER BY`), and merely declining to move the case role was not enough: Afform's default still renamed whichever contact it had autofilled.
* The blank-email guard no longer misses the present-but-empty-array shape, which `preprocessSubmittedValues()` preserves and which reached `saveJoins()` as an `Email::delete` over the client rep's whole address set.
* The "no contact was actually saved" guard now covers the no-rep path too, not only the replacement path.
* Client-rep tracking state is cleared at the start of each submission rather than relying on cleanup in a later branch, so a submission that throws part-way cannot carry a case id into the next one in the same CLI process.
* `_entityIds` is kept in step with the pinned record id, so a swallowed save failure cannot leave an audit trail naming a contact that was never written.

### Fixes (from round 6 of review)
* The gate deciding whether an incomplete name is an *unfinishable handover* no longer reuses the Rule-2 flags. Those carry a `$currentX !== ''` term that exists to avoid creating a duplicate contact, and borrowing it reopened the round-5 defect in the one population Rule 2 protects: with a blank `last_name` on file, a handover attempt scored `lastChanged = false`, so the incoming person's email was written onto the incumbent. The gate now asks its own narrower question — was a non-blank half that differs from the record refused?

### Fixes (from round 5 of review)
* An incomplete name that looks like an attempted handover now suppresses the submitted **email** as well as the name. Suppressing only the name left the incumbent holding the role with the *incoming* person's address — `saveJoins()` re-attaches the existing row id, so `Email::replace` overwrites in place — meaning every case email thereafter reached the wrong person and the incumbent's own address was gone.
* The client-rep blurb on both forms now says that recording a different person requires **both** name halves, and that a half-blank name is ignored. The previous wording promised that changing either name creates a new contact, which stopped being true when incomplete names began to be suppressed.

### Fixes (from rounds 3 and 4 of review)
* A handover is only recognised from a name field that HAD a value to change. On the 2026-05-30 dev clone, 4 of the 382 people holding an active `Case Client Rep is` role have an empty `last_name` (1 of the 195 whose role is *current*); without this, a VC filling one in was misread as replacing the person, creating a duplicate contact and unseating the real rep.
* An incomplete name — one half blank — now writes **neither** half. Dropping only the blank half was worse than doing nothing: with "Olive Smith" on file, submitting `first=''` `last='Jones'` renamed Olive Smith to "Olive Jones" and left her holding the role.
* Blankness is tested on the trimmed string cast rather than with `isset()`, which is false for a present-but-`null` field — and `null` survives core's `array_intersect_key`, `FormattingUtil` and `copyValues` all the way to `first_name = NULL`.
* A partially blank client-rep name is treated as an incomplete edit, not a handover. Clearing the first name to retype it previously created a contact with an empty first name, moved the case role to it and end-dated the real rep — while the form's own blurb promised that a blank field means no change. Blank name fields are now dropped on the in-place path too, so that promise holds for names as well as email.

### Tests
* `tests/Live/ClientRepChangeTest.php` — twelve scenarios across ten independent cases against a live site (`cv scr`), 53 assertions: email-only, last-name change, email cleared, no-rep blank, no-rep supplied, first-name-only, prefilled-fieldset-emptied, two-reps-refused, one-name-half-cleared, incumbent-with-no-last-name, first-cleared-plus-last-changed, and blank-surname-on-file-plus-handover-attempted. Each scenario after the first two was added in response to a review round, and each found a real defect. Scenario J also asserts `display_name` and `sort_name` are recomputed on an in-place name write — a source-level reading suggested they would go stale, and measurement showed they do not.
* `tests/Unit/Event/ClientRepWiringTest.php` — CI-runnable tripwire pinning the invariants CI can see, every assertion mutation-verified: the pre-process priority is bounded on both sides, the join-id strip stays paired with the contact-id strip, the incumbent rep is read from the case rather than from the submitted record id, an ambiguous case clears the record rather than merely returning, the outgoing role is *ended* rather than a second one merely added, a blank email drops the join unconditionally, an incomplete name writes neither half, an unfinishable handover suppresses the email too, a handover requires the field to have had a value, and both forms and both routes stay in scope.

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