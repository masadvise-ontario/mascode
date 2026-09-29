# Build Plan: P1-9 — the digest sends one link per VC

**Ticket slice**: `docs/plans/completion-signoff-tickets.md` row P1-9
**Scope**: Medium
**Status**: built 2026-09-29 (v1.1.35). Brian asked for it directly ("do P1-9"), so this plan
was written with the build rather than approved first. It records what was done and why.

## What changed

- The email carries **one** tokenised link, to `afformMASVcCheckin` (P1-8), rendered as
  `{digest.checkin_button}` with a `{digest.checkin_url}` plain-link fallback. Project rows are a list.
- `VcDigestMailer::checkinUrl()` mints it as `Tokens::createUrl($form, $vcId, [])`.

## Non-goals

- The per-case activities, the repeat-send guard (`alreadySentThisRound`), the subject transition
  guard, and eligibility are all unchanged.
- The per-project form is not retired. Its links stay valid for 60 days (D11).

## Known Gotchas

- CRITICAL: **no `afformArgs` in the token.** Core merges them into args after `civi.api.prepare`,
  bypassing `AfformPublicArgGuardSubscriber` (memory `feedback_afform_token_args_bypass_the_guard`;
  P1-8 plan). Pinned by `tests/Unit/Service/VcDigestOneLinkTest::testTheOneLinkCarriesNoArgs`, which
  goes red when `['case_id' => …]` is put back (measured). verified: dev token decoded, `afformArgs: []`.
- GOTCHA: **the template update overwrites production's copy.** MessageTemplate `update: unmodified`
  degrades to always-update. Production's `msg_html` md5 equalled the old declaration's
  (`bce629b2…`) on 2026-09-29, so no office edit is lost. verified: md5 compared.
- GOTCHA: **the activity details now hold a link to ALL of the VC's projects** (the rendered email is
  stored on each case). It is the same sensitivity class as before: staff-visible only (memory
  `reference_activity_details_hold_form_tokens`).
- GOTCHA (P1-8 defect, fixed here): an empty `af-repeat` pane renders one phantom row, because
  `afFieldset.getFieldData()` pushes a blank record. It is hidden by the `mascodeForms` class
  directives `masVcCheckinOpen` and `masVcCheckinAnswered`. verified: dev render, both directions.
