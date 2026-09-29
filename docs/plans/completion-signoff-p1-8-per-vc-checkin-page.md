# Build Plan: P1-8 — the per-VC check-in page

**Spec**: BrianPKM `3-Resources/mascode-vc-monthly-donation-digest-spec.md`; design settled by P1-7
(`docs/plans/completion-signoff-p1-7-per-vc-checkin.md`, § Results)
**Ticket slice**: `docs/plans/completion-signoff-tickets.md` row P1-8
**Scope**: Big (one ticket)
**Status**: built 2026-09-29 (v1.1.34); approved by Brian 2026-09-28. **Deviation:** no `Civi/Mascode/Service/CheckinRecorder.php`. `VcDigestSubmitSubscriber` serves both forms (`PAGE_FORM_NAME`) and its after-save walks every surviving record key. That reuses P1-5's code unchanged rather than moving it.
**Confidence**: 7/10 — the row mechanism is proven on dev. The two unproven pieces are the
"already answered this round" block (Decision 1) and hiding `af-repeat`'s Add button.

## What ships

A public, token-placeable Afform, **`afformMASVcCheckin`** (route `civicrm/mas-checkin-all`). A VC
opens it from one link and sees **one block per eligible project**: code, client and subject, then
*Is the work on this project complete?* and *Will you ask this client about a donation yourself?*
One submit records one *Monthly Project Check-in* per answered project. Each one then runs P1-5's
existing logic unchanged: source contact, `vc_will_ask` normalisation, round stamp and subject, and
on "Yes", the Completion email that advances the project.

## Out of Scope / Non-Goals

- **The email.** It keeps one link per project until P1-9. This ticket can ship dark: reachable,
  but linked from nothing.
- **Retiring `afformMASProjectCheckin`.** Links already sent stay valid for 60 days (D11).
- **Changing eligibility.** The page lists exactly what the digest would ask about
  (`VcDigestRunner::selectEligibleProjects` + `groupByCoordinator`), for the session contact.
- **The Job (P2-1), the dashboards (P2-3), or new questions.**

## CONTEXT REFERENCES

### Context Completeness Check

The load-bearing facts, all measured in P1-7:

1. **Rows are seeded by altering the prefill *response***, not by `loadEntity`. A `civi.api.respond`
   listener, scoped to `Afform.prefill` for this form name, **appends** an `Activity1` item with
   id-less rows `{fields: {case_id, subject}}`. With nothing loaded, core returns no `Activity1` item
   at all (`_entityValues` stays `[]`, `AbstractProcessor.php:76`), so the item has to be added, not
   replaced. The browser merges by index
   (`ext/afform/core/ang/af/afForm.component.js:112-120`). Never add an `id`: an id makes core update
   an existing activity (P1-7 fact 3).
2. **One predicate decides "answered": `CheckinPageRows::isAnswered($fields)`**, true only when
   `is_complete` is strictly set: `CheckinAnswer::isTrue`, or an explicit false (`false`, `0`, `'0'`).
   `''` and `null` are not answered. Defined this way, the same predicate works on submitted values
   and on stored ones. It is not "both
   blank", because a crafted row can carry `vc_will_ask` alone. **Validate and the drop call it, and Decision 1's
   "answered this round" rows are the round's stored check-ins filtered through it in PHP.** If they disagreed, a crafted row with `vc_will_ask` and
   no `is_complete` would survive the drop at priority 50, have `vc_will_ask` cleared by the
   normalisation at 10 (`VcDigestSubmitSubscriber.php:79`), and save as an **empty check-in**, which
   Decision 1 would then show as "answered".
3. **Entitlement is checked in `civi.afform.validate`, on exactly the rows `isAnswered` keeps.**
   Validate throws before any entity write (`Submit.php:50-56`), and Afform submit is **not transactional**, so a
   later refusal can't undo earlier saves. Every answered row's `case_id` must pass the **same D10
   predicate** as `CheckinCaseEntitlementSubscriber::isEntitled()`
   (`Civi/Mascode/Event/CheckinCaseEntitlementSubscriber.php:321`): staff, or the session contact is a
   *current* Case Coordinator of that case. A missing or non-integer `case_id` on an answered row
   fails too, and any failure refuses the whole submit. **Unanswered rows are not checked:** a role
   that ended, or a case reassigned to a new id (P1-6), between page load and submit must not block
   the VC's other answers. Unanswered rows are then dropped in a `civi.afform.submit` listener above
   priority 0 with `setRecords` (a Hidden `case_id` makes them non-empty, so core would save them,
   `Submit.php:469`). The drop runs at 50, before normalisation at 10, which is why it must use the
   same `isAnswered` rather than its own test. **The drop must fail CLOSED.** Unlike P1-5's listeners,
   which catch `Throwable` so as not to block a submission, it re-throws. If it swallowed an
   exception, every unanswered, never-validated row would reach core's save: an activity on an
   arbitrary case, which is an IDOR write. A throw at 50 aborts before core's save at 0
   (`AbstractProcessor.php:786-800`, re-thrown at `:796-798`). **Backstop, after the drop, not in
   validate:** a second `civi.afform.submit` listener on Activity1 at priority **30** (after the drop
   at 50, before normalisation at 10) asserts that every **surviving** record passes both `isAnswered`
   and D10, and throws if not. It catches a drop that silently stopped dropping, without ever judging
   an unanswered row. A validate-stage "every row must be in today's list" check was considered and
   rejected: it would refuse a whole page over a stale unanswered row (an ended role, a reassigned
   case id, or a project another coordinator has just advanced), which is the problem fact 3 exists to prevent
   (a stale unanswered row blocking the VC's other answers).
4. **P1-5's logic is keyed on one form and one record.** `VcDigestSubmitSubscriber` checks
   `FORM_NAME` and `getEntityId(0)`. It has to become shared per-record code that both forms call,
   not a copy.

### Read these files before implementing

```yaml
- file: Civi/Mascode/Event/VcDigestSubmitSubscriber.php
  why: the per-project logic both forms must share
  pattern: onBeforeSave (103) normalise + fillSourceContact; onAfterSave (170) → recordAnswers (230) → handleComplete (262)
  gotcha: onAfterSave reads getEntityId(0) only; the new form saves N records in one event, so loop over every saved index
- file: Civi/Mascode/Event/CheckinCaseEntitlementSubscriber.php
  why: the D10 predicate to reuse per row
  pattern: isEntitled (321), isStaff (401)
  gotcha: it is keyed on the per-project form name and reads args['case_id']; the new form has no args, so it must NOT be relied on for this form
- file: Civi/Mascode/Digest/CheckinAnswer.php
  why: the pure-rule home; the new row rules go here so CI can test them
  pattern: normaliseRecords (63), fillSourceContact (111)
- file: Civi/Mascode/Service/VcDigestRunner.php
  why: eligibility for the page list
  pattern: selectEligibleProjects (323), groupByCoordinator (392)
- file: tests/Security/afform-prefill-anon-probe.sh
  why: the FORMS list (64) is fixed; the new form must be added
- file: ang/README.md
  why: §"Security: public forms and caller-supplied record ids" — the checks to re-run after any public-form change
```

### Files this plan creates

- `ang/afformMASVcCheckin.aff.{html,json}`: the form. Activity1 has `actions="{create: true, update: false}"`, `af-repeat` `min="0"`, fields `subject` (DisplayOnly), `case_id` (Hidden), `is_complete`, `vc_will_ask`. **`is_complete` is NOT `required`**: core's `validateFieldInput` checks every submitted row (`afform.php:43`, `Submit.php:138-147`), so `required` would refuse any page with a project left unanswered. The per-project form's `required: true` (`ang/afformMASProjectCheckin.aff.html:20`) must not be copied. "Answered" is enforced by `isAnswered` instead. No `case_id` in `data`, no other writable entity, and `autosave_draft` off, so a restored draft (`Prefill.php:26-39`) can't duplicate the appended rows. `create_submission` stays **true**, for the audit trail the per-project form has. Core writes a *Pending* AfformSubmission row before `processFormData` (`Submit.php:58-80`), so a submit refused by the fail-closed drop or the backstop leaves that row behind. It is not an entity write, and it is accepted.
- `Civi/Mascode/Event/VcCheckinPageSubscriber.php`: the prefill-response rows, the validate check, the blank-row drop (priority 50) and the fail-closed backstop (priority 30).
- `Civi/Mascode/Digest/CheckinPageRows.php`: pure rules: `rowsFor(projects, labels)` (the rows, never with an id), `isAnswered(fields)`, and `refusals(submittedRows, isEntitled)` (which **answered** rows fail and why).
- `Civi/Mascode/Service/CheckinRecorder.php`: P1-5's after-save logic, moved out of `VcDigestSubmitSubscriber`, which then calls it.
- Tests: unit tests for `CheckinPageRows`; wiring tests pinning the form's safety properties (as `tests/Unit/Event/CheckinEntitlementWiringTest.php:testTheClientPaneIsReadOnlyAndDerivedFromTheGuardedCase` does); `tests/Security/VcCheckinPageTest.php`, a `cv scr` run as a non-staff VC, per the other security tests.

## Known Gotchas

- CRITICAL: **A caller-editable Hidden `case_id` is an IDOR** unless every **answered** row passes
  D10 in validate, and the priority-30 backstop re-checks every surviving record. verified: P1-7 task 5a (a tampered row refused the whole submit, nothing written).
- CRITICAL: **No `id` in a seeded row, ever.** An id turns the row into an UPDATE of an existing
  activity, or a silent refusal with `update: false`. verified: P1-7 fact 3 and PR #55 review round 3.
- CRITICAL: **The respond listener must match the form name AND `fillMode: form`,** and return
  nothing when there is no session contact. Otherwise it leaks one VC's projects to another caller or
  to anonymous. verified: P1-7 task 6 (anonymous prefill returned 0 rows).
- GOTCHA: **af-repeat's Add and Remove buttons render** (`ang/af/afRepeat.html`). `canAdd()` is
  `!max || rows < max` with a fixed `max` (`ang/af/afRepeat.directive.js:56-57`), so it can't be
  switched off per VC. Hide Add with a scoped CSS rule. An added row has no `case_id`: validate
  refuses it if answered, and the drop removes it otherwise. Remove is harmless: the project is asked about next month. verified: P1-7 render.
- CRITICAL: **Every mascode listener on this form must check `getEntityName() === 'Activity1'`.**
  DisplayOnly fields are stripped on submit (`AbstractProcessor.php:668-670`), so an `Activity2` row
  reaches validate with no `case_id`. A loop over "every row on the form" would refuse every submit,
  and `fillSourceContact` on Activity2 would make its fields non-empty (a create is then refused and
  swallowed: `FormDataModel.php:120-127`, `Submit.php:487-491`). Pin it with a wiring test.
  verified: review of PR #56.
- CRITICAL: **The P1-9 token must carry no `afformArgs`.** Core merges the JWT's `afformArgs` into
  args *after* `civi.api.prepare` (`AbstractProcessor.php:124-136`), which bypasses
  `AfformPublicArgGuardSubscriber` (memory `feedback_afform_token_args_bypass_the_guard`). The page
  needs none. Mint with `[]` and pin it with a test in P1-9. verified: review of PR #56.
- GOTCHA: **"Answered this round" rows (Decision 1**, P1-7 plan § Decisions for Brian**)** are meant to render read-only. The plan is a
  second repeat entity, `Activity2`, with `{create: false, update: false}`, DisplayOnly fields
  only, and **no `data` and no `afform_default`** (`getForcedDefaultValues` pushes DisplayOnly defaults back in), seeded the same id-less way. Its submitted fields are then empty and core skips it
  (`Submit.php:469`). unverified: prove it on dev first. If it fails, list the answered projects in
  the page's intro text instead.
- GOTCHA: **Two coordinators on one project** (4 on prod). Both VCs see it, and both may answer.
  P1-5's handler is already idempotent by case status, so the second "Yes" sends nothing.
  verified: `VcDigestSubmitSubscriber::handleComplete` docblock.
- GOTCHA: **The recorder loop follows the kept records' own keys** (`getEntityId($index)` for each
  surviving key), not 0..n-1. `setRecords` after a drop may leave gaps.
- ACCEPTED, inherited from the per-project form: **staff** pass D10 for any case
  (`CheckinCaseEntitlementSubscriber.php:323`), so a staff submit is recorded with staff as the
  source. **D10 checks coordination, not eligibility**, so a VC can tamper a row to another of their
  own non-eligible cases. That writes a check-in but sends nothing, because `handleComplete`'s
  status guard stops it (`VcDigestSubmitSubscriber.php:274-282`).
- GOTCHA: **`cv flush` doesn't load worktree code.** Dev verification needs the files in the
  registered checkout, restored afterwards (memory `feedback_bg_worktree_breaks_civi_flush`).

## Tasks

1. Extract `CheckinRecorder` from `VcDigestSubmitSubscriber` (per-record `recordAnswers` +
   `handleComplete`), with no behaviour change. The existing wiring tests must stay green, updated
   only to follow the move.
2. Add `CheckinPageRows` and its unit tests.
3. Add the form and `VcCheckinPageSubscriber`. Wire `normaliseRecords` and `fillSourceContact` for
   the new form, and call `CheckinRecorder` for each saved record.
4. Add wiring tests: Activity1 is `update: false`; no other entity is writable and Activity2 has no
   `data`; `case_id` is Hidden and absent from `data`; the listener priorities; every listener is
   scoped to `Activity1`; validate refuses; validate and the drop both call `isAnswered`;
   `is_complete` is not `required`; `autosave_draft` is off; the drop re-throws (test: a throwing
   drop refuses the submit); and the priority-30 backstop exists and throws on a surviving record
   that is unanswered or fails D10.
5. Add the form to the anonymous probe's `FORMS` list; add `tests/Security/VcCheckinPageTest.php`.
6. Verify on dev as a non-staff VC. Everything P1-7 tasks 3–6 checked, **plus**: a "Yes" sends the
   Completion email to MailHog and advances that case only; normalisation clears `vc_will_ask` on
   "not complete"; answered-this-round rows render read-only; Add is hidden.
7. Update CHANGELOG, `info.xml`, the P1-8 slice row and `ang/README.md` (the guarded-form count, and
   the respond-listener pattern as a sanctioned way to seed rows).

## Validation

- `vendor/bin/phpunit --testsuite=unit` is green.
- On dev, with files in the registered checkout: `tests/Security/afform-prefill-anon-probe.sh` is
  green including the new form, and `AfformPublicArgGuardTest`, `CheckinEntitlementTest` and
  `VcCheckinPageTest` pass as a non-staff VC.
- After deploy: re-enumerate the guarded forms on prod (9 expected) and run the probe against
  production.
