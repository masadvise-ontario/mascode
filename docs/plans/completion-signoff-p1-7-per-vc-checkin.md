# Build Plan: P1-7 — one check-in page per VC (spike, then design)

**Spec**: BrianPKM `3-Resources/mascode-vc-monthly-donation-digest-spec.md` (the per-project form is
P1-2/P1-4; this ticket changes the *shape* of the answer surface, not what is asked)
**Ticket slice**: `docs/plans/completion-signoff-tickets.md` rows P1-7, P1-8, P1-9
**Scope**: Big — this plan covers the P1-7 spike and fixes the design P1-8 and P1-9 build on. Their own
plans are written just before each is built, per the slice convention.
**Status**: landed — approved by Brian 2026-09-28; spike run on dev the same day. **Design A chosen.** Results below.
**Confidence**: 4/10 — design A needs a way to render N **new** Activity rows, each carrying a
server-chosen `case_id`. Core has no public way to do that (fact 3), so A may not be viable at all.
The spike's first job is to settle that. If it can't, B is the design.

## Results (dev, 2026-09-28, clone of 2026-09-21)

Spike files lived as untracked files in the registered checkout and were removed afterwards
(`git status` clean, form no longer returned by `Afform.get`). The test user was a non-staff VC
(WordPress role `subscriber`, contact 7468) holding 3 eligible projects.

| Task | Result |
|---|---|
| 1. Gating question: can new rows be seeded without `loadEntity`? | **Yes.** A `civi.api.respond` listener on `Afform.prefill` (this form only) replaces the `Activity1` item's `values` with one `{fields: {case_id, subject}}` per eligible project, with **no id**. The browser merges prefill rows by index (`ang/af/afForm.component.js:112-120`) and `af-repeat` renders one block per row. Nothing existing is loaded, so fact 3 never applies. |
| 3. Prefill as the VC | 3 rows returned, one per eligible case, each with its own `case_id` and label and no `id`. |
| 3b. Real browser render (Playwright, signed `_aff` token minted as the digest does) | 3 repeat blocks with label, both questions and a hidden `case_id` input each; no errors. ⚠ `af-repeat` also renders **Add** and per-row **Remove** buttons. |
| 4. Answer 2 of 3 | Exactly **2 new** activities, each on its own case, source = the VC; the unanswered row dropped by a `setRecords` listener at priority 50; **no existing activity on the three cases changed** (type and `modified_date` snapshotted before and after). |
| 5a. One row tampered to an uncoordinated case | Refused in `civi.afform.validate` (thrown at `Submit.php:54`). **0 activities and 0 AfformSubmission rows** written, although the other row was legitimate. |
| 5b. A row with no `case_id` | Refused the same way, nothing written. |
| 5c. Middle row deleted, remaining rows reversed | Each answer landed on its **own** case. Pairing is by the submitted `case_id`, not by position. |
| 6. Anonymous | A direct anonymous prefill of the spike form returned 0 rows. `afform-prefill-anon-probe.sh` passed 56/56, but its form list is fixed and **does not include a new form**, so P1-8 must add it. |

Not exercised by the spike, deliberately: P1-5's per-project logic (normalisation, round stamp, the
Completion send). The spike set only the source contact, so one row stored `vc_will_ask = true` with
`is_complete = false`. P1-8 reuses `CheckinAnswer::normaliseRecords`, which prevents that.

## Why

Brian, 2026-09-28: "instead of one form for each project, … one form per VC with the two questions
asked for each project". Production, read-only dry run of `Mascode.runVcDigest` on 2026-09-28:
**33 of 62 VCs hold 2+ eligible projects, and they account for 107 of 136 digest rows.** Distribution
(projects → VCs): 1→29, 2→17, 3→6, 4→5, 5→1, 6→2, 8→1, 10→1. Today the VC with 10 projects clicks
10 links. The response rate is the number the falsification gate reads, so this lands before full
rollout (P2-2), not after.

## Out of Scope / Non-Goals

- **Not changing the two questions**, their wording, or what a "Yes" does. P1-5's per-project logic
  (source contact, `vc_will_ask` normalisation, round stamp, the Completion send, and its
  idempotency by status) is reused unchanged.
- **Not retiring the per-project form** (`afformMASProjectCheckin`). Links already in inboxes live
  60 days (D11), so it keeps working at least that long. Retiring it is a later, separate decision.
- **Not the mailer change**. The email switches to one link in P1-9, once P1-8's page exists.
- **Not the scheduled Job** (P2-1), which is still behind the falsification gate.
- **Not a new question such as "answer all as complete"**. Tempting, but it is a product decision.

## CONTEXT REFERENCES

### Context Completeness Check

Someone new could run the spike from this plan. The four facts that would otherwise cost the
afternoon:

1. **Afform cannot pair a repeated answer with a different case by reference.** Core resolves an
   entity reference such as `case_id: 'Case1'` to the *first* record only
   (`ext/afform/core/Civi/Api4/Action/Afform/AbstractProcessor.php:605`,
   `$this->_entityIds[$value][0]['id']`), so ten repeated Activities would all attach to the first
   case. The per-row case id has to be a **field on each row**, not a reference.
2. **The page token only allows Afform actions.** `PageTokenCredential::getAllowedApi4Calls()`
   (`ext/afform/core/Civi/Afform/PageTokenCredential.php:244-260`) whitelists `Afform.prefill`,
   `submit`, `submitDraft`, `submitFile` and `getOptions`. So a custom page calling a mascode API
   from a digest link is refused. Design B needs its own credential for that reason.
3. **`loadEntity` loads EXISTING records. It cannot create new rows with preset values.** This was
   found in review, and it is the fact the spike turns on.
   - In update mode, `AbstractProcessor::loadEntity($entity, $values)` (`AbstractProcessor.php:230`,
     `:245-276`) runs `apiGet(<type>, where <key> IN …)`. So `loadEntity(Activity1, [['case_id'=>X], …])`
     would load activities **already on** those cases, such as the *Sent Automated Email* ones. It
     keeps one per case and records their ids in `_entityIds`.
   - `Afform.submit` re-runs the load, and `fillIdFields` (`:617-622`) then turns every row into an
     **UPDATE of that existing activity**, retyped by `data`: silent corruption, when `update` is on
     (the default). With `update: false` the row is refused and swallowed, so the answer vanishes.
     Core's own autofill load is gated on `actions[update]` (`:189`); a subscriber calling the public
     `loadEntity()` skips that gate.
   - A case with no activity renders no row at all.
   - Create mode looks up `Activity.id IN <case ids>` (`:250`, `:266-269`), and the prefill event has
     no public setter for values.
   - `min`/`max` slicing only applies when both are set (`isset($entity['min'], $entity['max'])`).
4. **Only fields on the form are submitted.** `getSubmittableFields()` (`AbstractProcessor.php:759`)
   strips everything else, and DisplayOnly fields too. A per-row `case_id` therefore has to be a
   submittable (Hidden) field, which makes it **caller-editable**. That means every row's case must be
   re-verified server-side against the VC's own entitled set, exactly as
   `CheckinCaseEntitlementSubscriber::onSubmit` (`Civi/Mascode/Event/CheckinCaseEntitlementSubscriber.php:193`)
   does for the one-case form today.

### Read these files before implementing

```yaml
- file: Civi/Mascode/Event/VcDigestSubmitSubscriber.php
  why: The per-project answer logic P1-8 must reuse, not copy
  pattern: onBeforeSave (103) normalise + fillSourceContact; onAfterSave (170) → recordAnswers (230) → handleComplete (262)
  gotcha: it keys on getEntityName() === 'Activity1' and a single case; a repeated block is N records in ONE event, so the loops must be per record
- file: Civi/Mascode/Event/CheckinCaseEntitlementSubscriber.php
  why: The D10 re-verification (current coordinator, is_current) the new page must apply PER ROW
  pattern: PRIORITY = 500 (114); onPrefill (140) strips; onSubmit (193) throws UnauthorizedException
  gotcha: a refused write must THROW, never drop the id — dropping files an answer against nothing behind a thank-you page
- file: Civi/Mascode/Service/VcDigestRunner.php
  why: The eligibility the page must list — Active, current coordinator, D2 suppression
  pattern: selectEligibleProjects (323) + groupByCoordinator (392); both split into pure functions for CI
- file: Civi/Mascode/Service/VcDigestMailer.php
  why: How links are minted today (P1-9 changes this)
  pattern: buildProjectRows (177), Tokens::createUrl with ['case_id' => …] (201)
- file: ang/README.md
  why: §"Security: public forms and caller-supplied record ids" — the rules and the two checks to re-run after any public-form change
- file: ang/afformMASProjectCheckin.aff.html
  why: The per-project form this generalises, including the v1.1.32 Client pane pattern
```

### Files this plan creates (spike only — not merged)

- `ang/afformMASVcCheckin.aff.{html,json}` (spike copy, dev only). Public, token-placeable. One `Individual1` = the token contact; one repeated `Activity1` block (`af-repeat`, `min="0"`, no `max`), each row with `case_id` as a Hidden field plus the two questions.
- Whatever row-seeding mechanism task 1 finds, plus a `civi.afform.validate` subscriber. **Not** `loadEntity` on the answer block (fact 3).

### Patterns to follow

- Pure-function extraction for CI: `Civi/Mascode/Digest/CheckinAnswer.php` (the rules) vs. the subscriber (the wiring).
- Wiring pinned by source-text tests: `tests/Unit/Event/DigestSubmitWiringTest.php`, `tests/Unit/Event/CheckinEntitlementWiringTest.php`.

## Known Gotchas

- CRITICAL: **A caller-editable per-row `case_id` is an IDOR** (a caller changing the id to reach a
  record they shouldn't) unless every row is intersected with the VC's *current* entitled set on
  submit. The set must come from the token contact, never from anything submitted.
  verified: this is the task #159 class documented in `ang/README.md`.
- CRITICAL: **Refuse the whole submit if any row names an unentitled case, or names no case.**
  Don't silently drop that row, for the same reason as `CheckinCaseEntitlementSubscriber::onSubmit`,
  whose no-case branch (`:229-243`) throws. `af-repeat` lets the browser add blank rows and delete
  rows. Read each case id **from the record**, not from `args['case_id']` as the one-case guard does.
  verified: `CheckinCaseEntitlementSubscriber.php:193`, `:229-243`.
- CRITICAL: **Afform submit is not transactional.** `civi.afform.submit` fires once per entity, in
  weight order, and nothing wraps the whole submit (there is no `Transaction` in
  `Civi/Api4/Action/Afform/`). A per-row refusal at submit time can't undo entities already saved.
  Refuse in `civi.afform.validate`, which runs before any write, **and** keep every entity except the
  answer block `{create: false, update: false}`. verified: `Submit.php:50-56` (validate → `addError`
  → throw) runs before the submission save (`:58`) and `processFormData` (`:92`).
- CRITICAL: **Rows pair by position, not by case.** If server-chosen ids come back through
  `fillIdFields`, that pairing is positional, and `af-repeat` lets the browser delete a row, which
  shifts every later answer onto its neighbour's case. The validate check must compare each row's
  **submitted** `case_id` with the server-side list for that position, or not use positional ids at
  all. verified: review of PR #55, round 2.
- GOTCHA: **A blank row still saves.** A row carrying a Hidden `case_id` has non-empty fields, so
  `processGenericEntity` saves an activity for it (`Submit.php:469`). Unanswered rows must be removed
  in the submit handler with `setRecords`, at a priority **above 0**, so it runs before
  `processGenericEntity` (registered at 0, `afform.php:45`). That is dropping a blank, not refusing a row. Related:
  that function also swallows a failed save (`:484-488`), so a row that fails disappears silently.
  verified: same.
- CRITICAL: **Never load existing activities into the answer block** (fact 3). They become UPDATEs
  of those activities on submit. verified: `AbstractProcessor.php:245-276`, `:617-622`, `:741-743`. With `update` on (the default, `Civi/Afform/FormDataModel.php:20`) that is an UPDATE of the old activity. With `{update: false}`, the save goes through `getSecureApi4()` → `isActionAllowed` (`FormDataModel.php:81-103`, `:120-136`), throws `UnauthorizedException`, and is swallowed (`Submit.php:484-488`). The answer then **vanishes silently** instead. Either way the answer is lost.
- GOTCHA: **A `case_id` in the answer entity's `data` overrides the submitted one**
  (`AbstractProcessor.php:741-743`). The per-project form sets `case_id: 'Case1'` in `data`
  (`ang/afformMASProjectCheckin.aff.html:4`), so a copied form would pin every row to one reference.
  Keep it out of `data`. verified: same.
- GOTCHA: **`af-if` inside an `af-repeat`.** The per-project form hides `vc_will_ask` behind a
  FormBuilder-written `af-if` indexed `Activity1[0]`. A per-row conditional may not serialise, and
  mascode memory says never to hand-write an `af-if`. Fallback: show both questions on every row and
  rely on the server-side normalisation, which already forces `vc_will_ask` to NULL when not complete.
  unverified.
- GOTCHA: **DisplayOnly project labels per row.** Code, subject and client need to render per row
  from a `case_id`. They may need the prefill to supply display values, or a small custom Angular
  directive in the existing `mascodeForms` module. unverified.
- GOTCHA: **`cv flush` in a worktree does not load this code.** The spike has to run with files in
  the registered checkout, and be restored after (mascode memory:
  `feedback_bg_worktree_breaks_civi_flush`).

## Designs

**A: stay in Afform (only if the spike finds a way to render new rows).** One public form with a
repeated answer block, one row per eligible project. A `civi.afform.validate` subscriber verifies
every row's `case_id` against the VC's entitled set and refuses the whole submit on any mismatch or
missing case. P1-5's logic then runs per record. Open question: how rows get their server-chosen
`case_id` without loading existing activities. Candidates: a small Angular directive in
`mascodeForms` that seeds the rows client-side, or a virtual API4 entity. Both are unproven. For the
directive, **where the list comes from is part of the gating question**: the page token only allows
`Afform.*` calls (fact 2), and the prefill response has no public setter for extra values.
- *For:* it reuses the page token, the public-form guard, the anonymous probe and FormBuilder
  editing, and adds no new credential.
- *Against:* it depends on unproven per-row behaviour (gotchas above).

**B: custom mascode page (the likelier design after review).** A new route that verifies a mascode-signed JWT
(`\Civi::service('crypto.jwt')`, as `Civi/Mascode/Event/AfformTokenPrefillSubscriber.php:90` already
decodes), renders the list server-side, and handles one POST.
- *For:* full control over rendering and partial answers.
- *Against:* a **new credential and a new public surface**. Its own guard, probe coverage and CSRF
  handling have to be designed, and it can't be edited in FormBuilder.

## Tasks (P1-7 spike, dev only)

1. **Answer the gating question first, from core source and a throwaway dev form:** can a public
   Afform render N *new* answer rows, each with a server-chosen `case_id`, without `loadEntity`
   (fact 3)? If not, stop and choose B. The rest of the spike then applies to B's page.
2. Write the spike form and its subscribers into the registered dev checkout (working tree only)
   and run `cv flush`.
3. Prefill as a VC with 3+ projects. Does one row per eligible project render, with the right labels?
4. Submit with answers on 2 of 3 rows. Is exactly one **new** activity per answered row written
   against its own case, with the source contact, round and subject set? Is **no existing activity
   modified**? Snapshot `modified_date` and `activity_type_id` of the cases' activities before and after.
5. Tamper with one row's `case_id` to an uncoordinated case, and separately submit a row with no
   `case_id`. Is the whole submit refused, with **no record of any kind** written? Then **delete a
   middle row and submit**. Does every remaining answer land on its own case?
6. Run `tests/Security/afform-prefill-anon-probe.sh` with the spike form included.
7. Restore the checkout (`git checkout -- .`, remove untracked spike files, `cv flush`). Write the
   findings into this plan, choose A or B, and write P1-8's plan.

## Validation

- `vendor/bin/phpunit --testsuite=unit` stays green (the spike adds no merged code).
- Each of tasks 2–5 is recorded with the exact input that tripped it, or its absence.

## Decisions for Brian

1. **Partial answers.** When a VC answers 3 of 10 and comes back, should the page show the 3 as
   already answered this round? Recommended: show them **read-only** as "answered on <date>", and ask
   only about the rest. Letting them be changed means loading existing check-ins, which in Afform
   turns the new answer into an edit of the old activity (fact 3). A second answer is a new activity,
   and P1-5's handler is already idempotent by case status.
2. **What an unanswered row means.** Recommended: nothing is recorded. The project is asked about
   again next month, the same as not clicking a per-project link today. This is **not** core's
   default: a row carrying a `case_id` saves even when unanswered, so the handler has to drop it
   (see gotchas).
3. **Approve the spike's order**: settle the new-rows question first (task 1). If Afform can't
   do it, build B. Recommended.
