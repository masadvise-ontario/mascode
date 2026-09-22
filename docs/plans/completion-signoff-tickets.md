# Completion/Signoff + Digest — Ticket Slice

**Status as of 2026-09-21.** `git log` is authoritative for this file; this line is the cheap check.

Sliced from the spec per handoff #927 (closed 2026-09-21). **Spec approved 2026-09-21.**

**Spec:** BrianPKM `3-Resources/mascode-vc-monthly-donation-digest-spec.md` — the decision
document: problem, hypothesis, approaches considered, decisions D1–D18 with their one-way/two-way
marks. Read it from Claude Code desktop at `~/gdrive-brianpkm/...`, and from any other surface via
the Klaus MCP `obsidian_read` with that same vault-relative path.

**A second version of the same Phase 0 requirements exists in PRP format** at
`3-Resources/mascode-project-completion-signoff-prp.md`. It was written for Klaus task 93 (the
PRP-vs-tpl-spec pilot) and carries the format verdict. It is a *comparison artifact*, not a live
instruction — where it and the spec disagree, the spec wins. One of its confidently-stated
production claims — the duplicate-template risk, which it shares with the spec — was falsified by
reading production (finding 3 below).

**Where these tickets live: here, in this repo, on `master`.** Settled 2026-09-21, replacing the
provisional "in the vault, for now". A slice in the repo arrives as a PR and gets reviewed, is
readable and writable from every surface, and cannot be blocked by a GDrive outage.

The rule is defined in the Klaus repo at `.claude/skills/specify/SKILL.md` Step 3a, **as rewritten
by briangflett/klaus#290**. If the copy you are reading there still says *"ask Brian where tickets
live"*, you have a checkout from before that landed — and **this file is what mascode does either
way**.

**The ticket's own PR updates its own status row here**, in the same diff. It is a rule because
its absence already cost: this document still marked `P0-3 ⬅ NEXT` after P0-3, P0-4 and P0-5 had
all merged, and **nothing in the system would ever have corrected it**.

Today that rule is enforced by a human reading this line — `/worktree land` is a Klaus-scoped skill
and its Step 2c slice check arrives with briangflett/klaus#290, which is still open. Do not read
"the tooling checks it" into this.

**The rename decision, for the record:** labels renamed, machine `name`s frozen. Staff see the new
wording; every `:name` match keeps working. Do not "finish" the rename —
`tests/Unit/Managed/FrozenMachineNamesTest.php` enforces this and explains why.

## Three findings from production that change the work

All verified over the read-only tunnel on 2026-09-17, so they are here rather than buried in a PR.

**1. The rename broke TWO things, and the second is worse.** The handoff and spec describe the
status transition failing silently. It does — but the same rename also stranded
`mas_lifecycle_vc_close_send`, the CiviRule that auto-sends the client close email when a VC close
report arrives. That rule stores the template title in `civirule_rule_action.action_params` as
**serialised data**, which no deploy touches, and `LifecycleMailer::loadTemplate()` **throws** when
the title does not resolve. So the client close email was **not sent at all** — not merely sent
without advancing the case. Rule 12 / action row 33. Fixed by `upgrade_5014` in PR #33.

The general lesson: a template title is written down in *three* unrelated places — `TRANSITIONS`,
the managed declaration, and the provisioner's action params. Every rename needs the same
three-way sweep, plus an upgrade step for anything already serialised into a CiviRules row.

**2. Nothing was stranded.** The spec says every client signoff sent since the rename silently
failed to advance. Production disagreed: the last client close email went out **2026-09-09** and
advanced correctly, and no signoff-subject activity existed at all. The rename postdates that send,
so the bug was armed but never fired. No back-fill ticket was needed.

**3. There was no duplicate-template risk, and the real hazard is elsewhere.** The spec and the PRP
both warn that the next `cv upgrade:db` will create a duplicate because the managed entity matches
on `msg_title`. It does not: CiviCRM joins declarations to `civicrm_managed` on **(module, name)**,
that row already existed pointing at template 75, so the action is `update`. `match` is consulted
only when there is no managed row to adopt. Demonstrated on dev.

The mirror image is real: renaming a managed **`name`** leaves core with an undeclared old row plus
a new declaration, and on any environment whose `msg_title` has not yet migrated the new
declaration falls through to `match`, finds nothing, and **creates a second template**. Core's
supported mechanism for this is the **`replaces`** key.

## Dependency graph

```
P0-1 transition hotfix (PR #33) ──┐
                                  ├──> P0-2 renames ──> P0-3 expenses ──┐
SPEC APPROVAL ────────────────────┘                  └─> P0-4 signoff form ──┴──> P0-5 copy+email
                                                                                        │
                                                          ┌─────────────────────────────┘
                                                          v
   P1-1 activity type ──> P1-2 mini-form ──> P1-3 runner+mailer ──> P1-4 token ──> P1-5 submit ──> P1-6 PILOT
                                                                                                     │
                                                                              FALSIFICATION GATE ─────┤
                                                                                                     v
                                                              P2-1 job ──> P2-2 rollout ──> P2-3 dashboard ──> P2-4 cron health
                                                                                                     │
                                                                                                     v
                                                                                              P3-1 measure
```

`P0-1` shipped ahead of approval because production was broken and the fix direction matched D13
either way.

## Phase 0 — COMPLETE in code; production deploy outstanding

| ID | Ticket | Done when | Status |
|---|---|---|---|
| **P0-1** | Restore the client signoff transition **and** the client close send | `upgrade_5014` repoints the stranded action row; Live script green on prod | **DONE** — PR #33 / `352e8fc` (merged 2026-09-18 UTC / 2026-09-17 EDT). Deploy date differs by source: this slice and its vault original say 2026-09-18; handoff #1090 says both #33 and #34 went to prod 2026-09-21. Live GREEN 25/25 either way |
| **P0-2** | Rename templates, statuses, activity types, custom-group titles | Every row of the spec's rename table applied; each managed-`name` change uses `replaces`; an `upgrade_NNNN` migrates each `OptionValue` string **and every already-serialised CiviRules row naming it**; `cv upgrade:db` on a pre-rename clone produces no duplicate entity and no rule that throws | **DONE** — PR #34 / `b7aec14`, 3 review rounds, deployed 2026-09-21, prod Live GREEN 26/26 |
| **P0-3** | Hide `expenses_incurred` | Gone from the VC form, from the email's instruction bullet, and from the VC-fields SavedSearch declaration under `Civi/Mascode/Managed/`; historical values still queryable | **MERGED, NOT DEPLOYED** — PR #36 / `99bfe0d`, v1.1.18 |
| **P0-4** | Signoff form shows the VC's report | Client opens the form and sees hours + services read-only via `DisplayOnly` (no join, no new entity); expenses excluded | **MERGED, NOT DEPLOYED** — PR #36 / `99bfe0d` |
| **P0-5** | Unified donation copy + email fixes | The canonical D17 text appears on the RCS form, the Signoff form and the Signoff email; `<<project number>>` resolves as `{case.custom_34}`; donate button renders in both | **MERGED, NOT DEPLOYED** — PR #36 / `99bfe0d`. Shipped with a **deliberate departure from "verbatim"**, flagged for Brian rather than decided: D17's second paragraph is past-tense and the RCS form is the *intake* form, so used unchanged it would thank a client for work not yet done. That sentence was rewritten on the RCS form only; ¶1, ¶3, the three donation methods and the button are character-identical to D17. **The spec still says "verbatim", so the two disagree until Brian rules** — see *Still open* |

**Next action: test Phase 0 in dev, then deploy to production.** Phase 1 is gated on that.
The deploy is not complete until `HOME=/home/mas/tmp cv scr tests/Live/LifecycleTransitionTemplatesTest.php`
is green **on production** — a typo in a migration constant survives CI and is visible only there.

> **Read the deploy preconditions before pulling.** They are NOT reproduced here: this repo is
> **public**, and production carries state that must not be named in it. Read **handoff #1090
> § `WATCH OUT`** and **CHANGELOG 1.1.18 § *Deploying this release***. Between them they cover a
> file-level conflict that will stop a pull mid-deploy, and the managed entities whose stamp state
> has to be confirmed on production. Read the list there rather than a count here — at least one is
> unverified on prod, and its failure mode (a column silently not removed) reports nothing.

## `update => 'unmodified'` — what is true, and the stronger claim that was disproved

**A declaration never freezes itself.** Managed reconciliation runs **before** the upgrade steps
inside `cv upgrade:db`, and a successful reconcile **clears** `entity_modified_date` rather than
setting it. So a template body or subject **can** be fixed by an ordinary declaration edit —
**provided that record is unstamped, which you confirm on production, not on dev.** A record someone
hand-edited in the UI since the last check is stamped, and your edit will deploy cleanly and do
nothing. CHANGELOG 1.1.18 states the same imperative: *check before assuming the deploy landed.*

**What does freeze a record is a hand edit in the CiviCRM UI.** That stamps
`entity_modified_date`, and `update => 'unmodified'` then declines to rewrite that record for good.
`upgrade_5013` and `upgrade_5015` are the belt for exactly that case — and because they run after
reconciliation, they only ever fire on a site the declaration could not reach.

**Do not "solve" a stamped record by clearing `entity_modified_date`**: on production that hands the
next `cv flush` permission to overwrite a hand-curated body with whatever the repo holds.

> **This paragraph previously said the opposite, and that is worth keeping visible.** The 1.1.16
> notes claimed the client template was deploy-inert and its body fix could no longer ship as a
> declaration edit. **1.1.17 disproved it by shipping exactly that** — see CHANGELOG §*Correction to
> the 1.1.16 notes*, and handoff #1090's `ESTABLISHED (do not relitigate)` block. Both templates were
> verified unstamped on dev and production on 2026-09-21. The disproved version carried the words
> "verified against core", which is why it survived as long as it did.

## Phase 1 — the experiment

Ordered so the hypothesis can fail before most of the code exists.

| ID | Ticket | Done when | Depends on |
|---|---|---|---|
| **P1-1** ⬅ **NEXT** | *Monthly Project Check-in* activity type | `OptionValue` managed entity exists and survives a flush | P0-5 **deployed** |
| **P1-2** | `afformMASProjectCheckin` mini-form | Form renders from a tokenised link, and **a tampered `case_id` returns no data** (D10 — re-verified server-side, not trusted from the URL) | P1-1 |
| **P1-3** | `VcDigestRunner` + `VcDigestMailer` | `dry_run=1` lists the right projects per VC under D1/D2; the mailer sends one email to one VC covering N cases | P1-2 |
| **P1-4** | `{digest.project_rows}` token | One row per project, each with its own minted link, TTL = `checksum_timeout` | P1-3 |
| **P1-5** | `VcDigestSubmitSubscriber` | "Complete = Yes" writes the check-in activity and advances the case **by sending the Completion template** (D7 — never by writing `status_id`) | P1-4 |
| **P1-6** | Pilot run | A pilot VC answers and the project lands in *Awaiting VC Project Completion Form* with an armed chase, end to end | P1-5, and MAS office staff picking the pilot VCs |

> **Falsification gate after P1-6.** Response rate under ~15%, or pilot VCs answering "not complete"
> on projects the office knows are finished → **stop. Do not build Phase 2.** This gate is the
> reason the Job is deliberately not built in Phase 1.

## Phase 2 — unattended

| ID | Ticket | Done when | Depends on |
|---|---|---|---|
| **P2-1** | `Job_MasVcMonthlyDigest` | Runs on cron; a **re-run in the same month sends nothing** (the idempotency guard is the point — 62 volunteers getting a duplicate is not recoverable) | gate passed |
| **P2-2** | Full rollout | `pilot_vc_ids` cleared; 30-day suppression confirmed against real data | P2-1 |
| **P2-3** | Two Ops dashboard rows | "signoff returned, no donation, no VC ask" and "Active projects with no VC" both populate; the latter shows **1** project, not 8 | P2-2 |
| **P2-4** | Cron health visibility | A job that stops running is noticed without anyone checking by hand | P2-1 |

## Phase 3 — measurement

| ID | Ticket | Done when | Depends on |
|---|---|---|---|
| **P3-1** | Three-cycle read | Backlog age, response rate, conversion; **donations attributed by route** (form / VC / office) — this is what decides whether the digest's second question earns its place | two clean cycles |

## Parallel-safe set

- **P1-1 alone** until it lands — everything in Phase 1 depends on it.
- **P2-3 and P2-4** may run concurrently once P2-1 is in; they touch different subsystems
  (SearchKit display vs job monitoring).
- Nothing in Phase 0 is parallel-safe any more; its code is all merged (the prod deploy is outstanding, but that is one sequential action, not a ticket).

## Still open, and who decides

| Question | Decided by |
|---|---|
| Which VCs are in the pilot | MAS office staff (named in handoff #1090 — not in this public repo) |
| How hard the digest copy asks | MAS staff (named in handoff #1090 — not in this public repo) |
| Follow up a VC who answered "I'll ask"? | Phase 3 |
| The one Active project with no coordinator — mis-assigned or abandoned? | MAS office staff, once P2-3 exists |
| On Hold backlog (8 projects) | A separate process, out of scope |
| **D17 on the RCS form — keep the tense fix, or revert to verbatim?** | **Brian.** One-line edit either way; the spec and this file disagree until it is settled |

## Carried forward from review

**From P0-2's review, folded into P0-3 and shipped:** `FrozenMachineNamesTest` derives its own
consumer list (runs the comment-stripped sweep inside the test and asserts the derived set equals
the declared one), so a newly-added consumer fails loudly the day it appears. Rejected alternative:
a shared constant — the two afforms are Angular markup with no import mechanism, so it would cover
six of ten consumers and leave two guard mechanisms where there is now one.

**Recorded, not fixed — needs Brian's call.** The expense-reimbursement ask still present in the RCS
email and the `after_RCS` templates now contradicts the RCS form, which no longer collects expenses.
It sits outside P0-3's three named places, so it was deliberately not swept in. It is not blocking
the Phase 0 deploy.

**Inherited gap, still open (from PR #34).** `FORBIDDEN_IN_CONSUMERS` covers only the two renamed
status labels. A file with two code occurrences of an activity-type name stays green if only one is
renamed — `LifecycleRuleProvisioner.php` has two of `Project Close - VC Report`. Recorded here
rather than fixed, because it predates this work.

**Forward rule for any Phase 1+ ticket that renames a managed `name`:** use core's `replaces` key.
P1-1 creates rather than renames, so it does not apply yet.
