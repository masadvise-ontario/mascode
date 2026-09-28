# Build Plan: P1-8 — the per-VC check-in page

**Spec**: BrianPKM `3-Resources/mascode-vc-monthly-donation-digest-spec.md`; design settled by P1-7
(`docs/plans/completion-signoff-p1-7-per-vc-checkin.md`, § Results)
**Ticket slice**: `docs/plans/completion-signoff-tickets.md` row P1-8
**Scope**: Big (one ticket)
**Status**: draft — awaiting Brian's approval
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
   listener, scoped to `Afform.prefill` for this form name, replaces the `Activity1` item's `values`
   with id-less rows `{fields: {case_id, subject}}`. The browser merges by index
   (`ext/afform/core/ang/af/afForm.component.js:112-120`). Never add an `id`: an id makes core update
   an existing activity (P1-7 fact 3).
2. **Entitlement is checked in `civi.afform.validate`**, which throws before any write
   (`Submit.php:50-56`). Afform submit is **not transactional**, so a refusal later than that can't
   undo earlier saves. Every row's submitted `case_id` must pass the **same D10 predicate** as
   `CheckinCaseEntitlementSubscriber::isEntitled()` (`Civi/Mascode/Event/CheckinCaseEntitlementSubscriber.php:321`):
   staff, or the session contact is a *current* Case Coordinator of that case. A missing or
   non-integer `case_id` fails too. Any failure refuses the whole submit.
3. **Unanswered rows must be dropped before core saves them.** A row carrying a Hidden `case_id` is
   non-empty, so core would save it (`Submit.php:469`). Drop it in a `civi.afform.submit` listener
   above priority 0 (P1-7 used 50) with `setRecords`.
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

- `ang/afformMASVcCheckin.aff.{html,json}`: the form. Activity1 has `actions="{create: true, update: false}"`, `af-repeat` `min="0"`, fields `subject` (DisplayOnly), `case_id` (Hidden), `is_complete`, `vc_will_ask`. No `case_id` in `data`, and no other writable entity.
- `Civi/Mascode/Event/VcCheckinPageSubscriber.php`: the prefill-response rows, the validate check and the blank-row drop.
- `Civi/Mascode/Digest/CheckinPageRows.php`: pure rules: `rowsFor(projects, labels)` (the rows, never with an id) and `refusals(submittedRows, isEntitled)` (which rows fail and why).
- `Civi/Mascode/Service/CheckinRecorder.php`: P1-5's after-save logic, moved out of `VcDigestSubmitSubscriber`, which then calls it.
- Tests: unit tests for `CheckinPageRows`; wiring tests pinning the form's safety properties (as `tests/Unit/Event/CheckinEntitlementWiringTest.php:testTheClientPaneIsReadOnlyAndDerivedFromTheGuardedCase` does); `tests/Security/VcCheckinPageTest.php`, a `cv scr` run as a non-staff VC, per the other security tests.

## Known Gotchas

- CRITICAL: **A caller-editable Hidden `case_id` is an IDOR** unless every row passes D10 in
  validate. verified: P1-7 task 5a (a tampered row refused the whole submit, nothing written).
- CRITICAL: **No `id` in a seeded row, ever.** An id turns the row into an UPDATE of an existing
  activity, or a silent refusal with `update: false`. verified: P1-7 fact 3 and PR #55 review round 3.
- CRITICAL: **The respond listener must match the form name AND `fillMode: form`,** and return
  nothing when there is no session contact. Otherwise it leaks one VC's projects to another caller or
  to anonymous. verified: P1-7 task 6 (anonymous prefill returned 0 rows).
- GOTCHA: **af-repeat's Add and Remove buttons render** (`ang/af/afRepeat.html`). `canAdd()` is
  `!max || rows < max` with a fixed `max` (`ang/af/afRepeat.directive.js:56-57`), so it can't be
  switched off per VC. Hide Add with a scoped CSS rule. An added row has no `case_id`, so validate
  refuses it anyway. Remove is harmless: the project is asked about next month. verified: P1-7 render.
- GOTCHA: **"Answered this round" rows (Decision 1)** are meant to render read-only. The plan is a
  second repeat entity, `Activity2`, with `{create: false, update: false}` and DisplayOnly fields
  only, seeded the same id-less way. Its submitted fields are then empty and core skips it
  (`Submit.php:469`). unverified: prove it on dev first. If it fails, list the answered projects in
  the page's intro text instead.
- GOTCHA: **Two coordinators on one project** (4 on prod). Both VCs see it, and both may answer.
  P1-5's handler is already idempotent by case status, so the second "Yes" sends nothing.
  verified: `VcDigestSubmitSubscriber::handleComplete` docblock.
- GOTCHA: **`cv flush` doesn't load worktree code.** Dev verification needs the files in the
  registered checkout, restored afterwards (memory `feedback_bg_worktree_breaks_civi_flush`).

## Tasks

1. Extract `CheckinRecorder` from `VcDigestSubmitSubscriber` (per-record `recordAnswers` +
   `handleComplete`), with no behaviour change. The existing wiring tests must stay green, updated
   only to follow the move.
2. Add `CheckinPageRows` and its unit tests.
3. Add the form and `VcCheckinPageSubscriber`. Wire `normaliseRecords` and `fillSourceContact` for
   the new form, and call `CheckinRecorder` for each saved record.
4. Add wiring tests: Activity1 is `update: false`; no other entity is writable; `case_id` is Hidden
   and absent from `data`; the listener priorities; validate refuses.
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
