# Build Plan: P1-7 — one check-in page per VC (spike, then design)

**Spec**: BrianPKM `3-Resources/mascode-vc-monthly-donation-digest-spec.md` (the per-project form is
P1-2/P1-4; this ticket changes the *shape* of the answer surface, not what is asked)
**Ticket slice**: `docs/plans/completion-signoff-tickets.md` rows P1-7, P1-8, P1-9
**Scope**: Big — this plan covers the P1-7 spike and fixes the design P1-8 and P1-9 build on. Their own
plans are written just before each is built, per the slice convention.
**Status**: draft — awaiting Brian's approval
**Confidence**: 6/10 — design A lands in one pass only if core Afform honours a caller-editable
Hidden `case_id` per repeated Activity row, and if our submit subscriber can validate each row
before core writes. Both are unproven; proving them is what P1-7 is for.

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
3. **A prefill subscriber can supply N rows.** `AbstractProcessor::loadEntity($entity, $values)`
   (`AbstractProcessor.php:230`) loads a list of records and respects `af-repeat` `min`/`max`, which
   is how core's autofill behaviours populate fieldsets.
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
- A spike prefill subscriber that loads the VC's eligible projects into `Activity1` via `loadEntity`.

### Patterns to follow

- Pure-function extraction for CI: `Civi/Mascode/Digest/CheckinAnswer.php` (the rules) vs. the subscriber (the wiring).
- Wiring pinned by source-text tests: `tests/Unit/Event/DigestSubmitWiringTest.php`, `tests/Unit/Event/CheckinEntitlementWiringTest.php`.

## Known Gotchas

- CRITICAL: **A caller-editable per-row `case_id` is an IDOR** (a caller changing the id to reach a
  record they shouldn't) unless every row is intersected with the VC's *current* entitled set on
  submit. The set must come from the token contact, never from anything submitted.
  verified: this is the task #159 class documented in `ang/README.md`.
- CRITICAL: **Refuse the whole submit if any row names an unentitled case.** Don't silently drop
  that row, for the same reason as `CheckinCaseEntitlementSubscriber::onSubmit`.
  verified: `CheckinCaseEntitlementSubscriber.php:193` and its docblock.
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

**A: stay in Afform (preferred if the spike passes).** One public form with a repeated Activity block.
A prefill subscriber lists the VC's projects; a submit subscriber (priority above 0) verifies every
row's `case_id` against the VC's entitled set and throws on any mismatch; P1-5's logic runs per record.
- *For:* it reuses the page token, the public-form guard, the anonymous probe and FormBuilder
  editing, and adds no new credential.
- *Against:* it depends on unproven per-row behaviour (gotchas above).

**B: custom mascode page (fallback).** A new route that verifies a mascode-signed JWT
(`\Civi::service('crypto.jwt')`, as `Civi/Mascode/Event/AfformTokenPrefillSubscriber.php:90` already
decodes), renders the list server-side, and handles one POST.
- *For:* full control over rendering and partial answers.
- *Against:* a **new credential and a new public surface**. Its own guard, probe coverage and CSRF
  handling have to be designed, and it can't be edited in FormBuilder.

## Tasks (P1-7 spike, dev only)

1. Write the spike form and prefill subscriber into the registered dev checkout (working tree only)
   and run `cv flush`.
2. Prefill as a VC with 3+ projects. Does one row per eligible project render, with the right labels?
3. Submit with answers on 2 of 3 rows. Is exactly one activity per answered row written against its
   own case, with the source contact, round and subject set?
4. Tamper with one row's `case_id` to an uncoordinated case. Is the whole submit refused, with nothing written?
5. Run `tests/Security/afform-prefill-anon-probe.sh` with the spike form included.
6. Restore the checkout (`git checkout -- .`, remove untracked spike files, `cv flush`). Write the
   findings into this plan, choose A or B, and write P1-8's plan.

## Validation

- `vendor/bin/phpunit --testsuite=unit` stays green (the spike adds no merged code).
- Each of tasks 2–5 is recorded with the exact input that tripped it, or its absence.

## Decisions for Brian

1. **Partial answers.** When a VC answers 3 of 10 and comes back, should the page show the 3 as
   already answered this round and let them change them? Recommended: yes, show them, allow changes.
   P1-5's handler is already idempotent by case status.
2. **What an unanswered row means.** Recommended: nothing is recorded. The project is asked about
   again next month, the same as not clicking a per-project link today.
3. **Approve design A-first, with B as the fallback** (recommended), before the spike runs.
