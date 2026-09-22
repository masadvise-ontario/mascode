# Completion/Signoff + Digest — Ticket Slice

**Status as of 2026-09-22 — Phase 0 is deployed.** `git log` is authoritative for this file; this line is the cheap check.

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
by briangflett/klaus#290**. Test which copy you have by what it *contains*, not by what it lacks: a
post-#290 Step 3a names `docs/plans/<epic-slug>-tickets.md`. If yours does not, it predates the
change — and **this file is what mascode does either way**.

**The ticket's own PR updates its own status row here**, in the same diff. It is a rule because
its absence already cost: this document still marked `P0-3 ⬅ NEXT` after P0-3, P0-4 and P0-5 had
all merged, and **nothing in the system would ever have corrected it**.

**In this repo the only thing enforcing it is a human reading this line.** klaus#290 added
`/worktree land` Step 2c, which is repo-agnostic and would work here — but `/worktree land` never
reaches it: its Step 1c runs `git fetch origin main`, and **mascode's default branch is `master`**,
so the run aborts long before Step 2c. LAND.md says as much itself. Do not read "the tooling checks
it" into the presence of Step 2c.

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

## Phase 0 — COMPLETE and DEPLOYED (v1.1.18, 2026-09-22)

| ID | Ticket | Done when | Depends on | Status |
|---|---|---|---|---|
| **P0-1** | Restore the client signoff transition **and** the client close send | `upgrade_5014` repoints the stranded action row; Live script green on prod | — | **DONE** — PR #33 / `352e8fc` (merged 2026-09-18 UTC / 2026-09-17 EDT). Deploy date is not settled: the vault original says 2026-09-18 in two places and 2026-09-21 in a third, and handoff #1090 says #33 and #34 both went to prod on 2026-09-21. Treat 2026-09-21 as the load-bearing date and the earlier one as unverified. A 25-assertion Live run is recorded against this ticket; the production runs this file can vouch for are the 26-assertion ones after P0-2 and after the 2026-09-22 deploy |
| **P0-2** | Rename templates, statuses, activity types, custom-group titles | Every row of the spec's rename table applied; each managed-`name` change uses `replaces`; an `upgrade_NNNN` migrates each `OptionValue` string **and every already-serialised CiviRules row naming it**; `cv upgrade:db` on a pre-rename clone produces no duplicate entity and no rule that throws | P0-1, spec approval | **DONE** — PR #34 / `b7aec14`, 3 review rounds, deployed 2026-09-21, prod Live GREEN 26/26 |
| **P0-3** | Hide `expenses_incurred` | Gone from the VC form, from the email's instruction bullet, and from `Civi/Mascode/Managed/SavedSearch_Case_Details_VC_Fields.mgd.php`; historical values still queryable | P0-2 | **DONE and DEPLOYED** — PR #36 / `99bfe0d`, v1.1.18, deployed 2026-09-22, prod Live GREEN 26/26 |
| **P0-4** | Signoff form shows the VC's report | Client opens the form and sees hours + services read-only via `DisplayOnly` (no join, no new entity); expenses excluded | P0-2 | **DONE and DEPLOYED** — PR #36 / `99bfe0d`, v1.1.18, deployed 2026-09-22 |
| **P0-5** | Unified donation copy + email fixes | The canonical D17 text appears on the RCS form, the Signoff form and the Signoff email; `<<project number>>` resolves as `{case.custom_34}`; donate button renders in both | P0-4 | **DONE and DEPLOYED** — PR #36 / `99bfe0d`, v1.1.18, deployed 2026-09-22. Shipped with a **deliberate departure from "verbatim"**, flagged for Brian rather than decided: D17's second paragraph is past-tense and the RCS form is the *intake* form, so used unchanged it would thank a client for work not yet done. That sentence was rewritten on the RCS form only; ¶1, ¶3, the three donation methods and the button are character-identical to D17. **The spec still says "verbatim", so the two disagree until Brian rules** — see *Still open* |

**Next action: P1-1.** Phase 0 shipped to production on 2026-09-22 as v1.1.18, with the Live
script GREEN there (26/26), both message templates and the SavedSearch confirmed updated in the
prod DB, and both live forms rendering. Handoff #1093 carries that record; #1090, which tracked
Phase 0, is closed.

> **For any future deploy of this extension** — not for Phase 0, which is done — the checks that
> mattered here are worth repeating: run
> `HOME=/home/mas/tmp cv scr tests/Live/LifecycleTransitionTemplatesTest.php` on **production**
> (a typo in a migration constant survives CI and is visible only there; the test docblock's
> production `--user=` is a placeholder, and handoff #1090 § `WATCH OUT` carries the real
> invocation), and read **CHANGELOG 1.1.18 § *Deploying this release*** for the managed entities
> whose stamp state has to be confirmed on the target rather than assumed from dev.

## `update => 'unmodified'` — what is true, and the stronger claim that was disproved

**A declaration never freezes itself.** Managed reconciliation runs **before** the upgrade steps
inside `cv upgrade:db`, and a successful reconcile **clears** `entity_modified_date` rather than
setting it. So a template body or subject **can** be fixed by an ordinary declaration edit —
**provided that record is unstamped, which you confirm on production, not on dev.** A record someone
hand-edited in the UI since the last check is stamped, and your edit will deploy cleanly and do
nothing. CHANGELOG 1.1.18 states the same imperative: *check before assuming the deploy landed.*

**What freezes a record is any edit outside reconciliation** — a hand edit in the CiviCRM UI, **or
an API4 write from an upgrade step.** Core stamps `entity_modified_date` on *any* edit of a managed
entity with no exemption for code, and `update => 'unmodified'` then declines to rewrite that record
for good. `upgrade_5013`'s own comment block (`CRM/Mascode/Upgrader.php`) spells the mechanism
out: **when its rename branch fires, it is a one-way door for that template on that site.** On the
extension-only upgrade path (`cv upgrade:db` with the DB already at the code version) the post hook
is live, so the stamp lands. On a full core upgrade it does not — `CRM_Upgrade_DispatchPolicy`
drops `hook_civicrm_post` — so treat the stamp as the default and the exception as the thing to
check, not the other way round.

**That comment then draws a further conclusion, and 1.1.17 falsified it** — which is the best
worked example this section has. It says the retired `<h1>` in the sibling `.body.html` therefore
needs its own upgrade step. It did not: `b7aec14` (v1.1.17) shipped it as an ordinary declaration
edit, and the file today reads `<h1>MAS Project Signoff</h1>`. The rename branch had never fired on
dev or production — both templates were verified unstamped on 2026-09-21 — so nothing was frozen.

Which is the whole rule in one case: **the mechanism is real, and whether it has fired on your
target is a separate question you answer by looking.** A P1+ session reasoning "nobody has touched
the UI, so a declaration edit is safe" gets it wrong in one direction; one reasoning "an upgrade
step ran once, so the declaration is dead" gets it wrong in the other.

**Note for anyone reading handoff #1090.** Its `ESTABLISHED (do not relitigate)` block says
`update => 'unmodified'` freezes a record *only* when a human edits it in the CiviCRM UI. That is
over-narrow — an upgrade step's own API4 write stamps it too, on the path above. The mechanism
here is verified against core; #1090's wording predates that check.

**Do not "solve" a stamped record by clearing `entity_modified_date`**: on production that hands the
next `cv flush` permission to overwrite a hand-curated body with whatever the repo holds.

> **This paragraph previously said the opposite, and that is worth keeping visible.** The 1.1.16
> notes claimed the client template was deploy-inert and its body fix could no longer ship as a
> declaration edit. **1.1.17 disproved it by shipping exactly that** — see CHANGELOG §*Correction to
> the 1.1.16 notes*, and handoff #1090's `ESTABLISHED (do not relitigate)` block. Both templates were
> verified unstamped on dev and production on 2026-09-21. The disproved version carried the words
> "verified against core" — in PR #33's body, not in the CHANGELOG — which is why it survived as
> long as it did.

## Phase 1 — the experiment

Ordered so the hypothesis can fail before most of the code exists.

| ID | Ticket | Done when | Depends on | Status |
|---|---|---|---|---|
| **P1-1** | *Monthly Project Check-in* activity type **+ the custom group holding the answers** | `OptionValue` managed entity exists and survives a flush; the group is scoped to that type on a **single-pass** reconcile from clean | P0-5 — satisfied 2026-09-22 | **MERGED** — PR #38, `10e2f99`, 2026-09-22, after five review rounds. Scope widened by one custom group and three custom fields: P1-2's form cannot be built without them and the spec's Data Model treats them as one unit. Found and fixed a silent first-install defect in the `extends_entity_column_value:name` idiom — see below |
| **P1-2** | `afformMASProjectCheckin` mini-form | Form renders from a tokenised link, and **a `case_id` the visitor does not coordinate returns no data** (D10 — re-verified server-side, not trusted from the token) | P1-1 | **MERGED** — PR #39, `7fcf36c`, 2026-09-22, after five review rounds. The threat turned out not to be a tampered id — the JWT is signed — but a **stale** one: a link's TTL is 60 days (D11) and case roles change inside it. Measured with the guard off: a token-supplied `case_id` for an uncoordinated project **returned the case** and a submit against it **was allowed**. See *A token-supplied id is not covered by the existing guard* below |
| **P1-3** | `VcDigestRunner` + the `Mascode.runVcDigest` dry run | `dryRun` lists the right projects per VC under D1/D2 | P1-2 | **PR open** (#40), based on `master` since P1-1 and P1-2 merged. **Resliced**: the mailer moves to P1-4, because a mailer cannot be shown to work without the token provider that renders its rows — the two are one reviewable unit and the runner is the half whose predicate can be checked against real data now. Dry run on the 2026-09-21 clone: 61 VCs, 132 projects, 136 rows, 2 coordinator-less. Review found a **fatal** on two live paths — the D12 pilot and a month with nothing eligible — caused by a `?: [[]]` "guard" that was itself the bug; `run()` had no unit test, so the count it crashed in is now a pure function with a one-line test for the empty case |
| **P1-4** | `VcDigestMailer` + the `{digest.*}` tokens | The mailer sends one email to one VC covering N cases; one row per project, each with its own minted link, TTL = `checksum_timeout` | P1-3 | **PR open**, stacked on P1-3. Absorbed P1-3's mailer. Found the highest-consequence trap in the feature: a digest subject containing a lifecycle transition prefix would silently advance **every** project in the digest, because the per-project *Sent Automated Email* activity is exactly what `matchTransition()` substring-matches. Guarded at send time against the LIVE template subjects |
| **P1-5** | `VcDigestSubmitSubscriber` | "Complete = Yes" writes the check-in activity and advances the case **by sending the Completion template** (D7 — never by writing `status_id`); **and `vc_will_ask` is forced to NULL whenever `is_complete` is not true** | P1-4 | **PR open** (#42). ⚠ **Carried from PR #39 review:** core does NOT strip conditionally-hidden fields on submit — `AbstractProcessor::getSubmittableFields()` carries the TODO, and the only thing clearing a hidden `vc_will_ask` today is browser JS. So a crafted or replayed submit can produce `is_complete = false` WITH `vc_will_ask = true`, a state P1-1's data model declares impossible and which D8 would turn into an office work item. P1-5 must normalise server-side rather than trust the submitted value |
| **P1-6** ⬅ **NEXT — and blocked on people, not code** | Pilot run | A pilot VC answers and the project lands in *Awaiting VC Project Completion Form* with an armed chase, end to end | P1-5, and MAS office staff picking the pilot VCs | not started ⚠ **Before the pilot, add the client organisation to each digest row.** Spec §Outputs asks for it and P1-4 shipped without it — the ticket's own done-when did not require it, so this is a deviation recorded rather than a defect. It matters *here* specifically: a VC with ten projects has nothing in the row to tell them apart, and the pilot's response rate is the number the falsification gate turns on. A usability problem in that one run is indistinguishable from the hypothesis being wrong. Raised in PR #41's review. |

> **Falsification gate after P1-6.** Response rate under ~15%, or pilot VCs answering "not complete"
> on projects the office knows are finished → **stop. Do not build Phase 2.** This gate is the
> reason the Job is deliberately not built in Phase 1.

## Phase 2 — unattended

| ID | Ticket | Done when | Depends on | Status |
|---|---|---|---|---|
| **P2-1** | `Job_MasVcMonthlyDigest` | Runs on cron; a **re-run in the same month sends nothing** (the idempotency guard is the point — 62 volunteers getting a duplicate is not recoverable) | gate passed | not started — behind the falsification gate |
| **P2-2** | Full rollout | `pilot_vc_ids` cleared; 30-day suppression confirmed against real data | P2-1 | not started |
| **P2-3** | Two Ops dashboard rows | "signoff returned, no donation, no VC ask" and "Active projects with no VC" both populate; the latter shows **2** projects, not 8 — ⚠ **the expected figure changed from 1 to 2 in P1-3**, when the coordinator predicate moved from `is_active` to `is_current` and a project whose coordinator role had ended stopped counting as coordinated | P2-2 | not started |
| **P2-4** | Cron health visibility | A job that stops running is noticed without anyone checking by hand | P2-1 | not started |

## Phase 3 — measurement

| ID | Ticket | Done when | Depends on | Status |
|---|---|---|---|---|
| **P3-1** | Three-cycle read | Backlog age, response rate, conversion; **donations attributed by route** (form / VC / office) — this is what decides whether the digest's second question earns its place | two clean cycles | not started |

## A managed-entity trap found building P1-1

**`extends_entity_column_value:name` can resolve to NULL, silently, and NULL means "every type".**
Core resolves that pseudoconstant against the *live* option list at write time
(`CRM_Core_BAO_CustomGroup::getExtendsEntityColumnValueOptions()`). If the named option value does
not exist yet, core writes NULL with no exception, no log line and a successful reconcile — and a
NULL there does not scope the group to nothing, it scopes it to **every** activity type. The three
check-in fields would have appeared on every activity form in CiviCRM.

The ordering was deterministic and against us. The `mgd-php@1` mixin — the version `info.xml`
declares — recursively collects every `*.mgd.php` under the extension, `sort()`s the full paths and
appends each file's array in order; `ManagedEntities::reconcileEntities()` walks the `create` plan
in that same order. Under this directory's one-entity-per-file convention the files were
`CustomGroup_…` and `OptionValue_ActivityType_…`, so **"C" sorted before "O"** and the group was
created before the option value existed, on every clean environment.

*(The first write-up cited `mgd-php@2`, which mascode does not use. The two differ in how they
SEARCH, not in how they order, so the conclusion held — but the guard test written from that
citation searched five named directories instead of the whole tree, and was therefore **narrower
than what core loads**, which is the one thing that guard must never be. Both corrected; the walk
is now verified against core's own `CRM_Utils_File::findFiles()`, same 65 files. A claim about core
should name the code that runs.)*

**A bad create is PERMANENT, and the first version of this section said the opposite.**
`ManagedEntities::optimizePlan()` drops every `update` item whose stored checksum still matches the
declaration's, and hand-breaking a *record* does not change the *declaration's* checksum. The
exceptions are upgrade mode, an active install/enable process, and a changed declaration — and
**`cv flush` is none of them**. Measured on dev 2026-09-22 both ways: clear the column, `cv flush`,
still NULL; `cv upgrade:db`, restored.

**The wrong version came from a bad experiment, not a bad reading**, which is the part worth
carrying forward. `CustomGroup::update()->addValue('extends_entity_column_value', NULL)` reports
success and stamps `entity_modified_date` while leaving the column **unchanged** — so the "reset"
never happened, and the flush that followed had nothing to heal. It looked like a clean
demonstration. Clearing that column has to be done at the column to be real. This is the second
time this document has had to record a confidently-stated "verified against core" claim that was
not; the other is the 1.1.16 note above. Both were caught by review rather than by anything
automatic.

**It would still have surfaced on production and nowhere else**, because only a clean environment
creates these records for the first time. Whether the deploy ritual's `cv upgrade:db` leg would then
have covered it up is **not verified**: a create is not an `update`, so `optimizePlan()` never sees
it, and covering it up would need a second reconcile inside the same invocation. Left as the open
question it is.

Fixed by declaring both in **one array, option value first**, which removes the ordering question
instead of answering it. Guarded by `tests/Unit/Managed/MonthlyCheckinDeclarationTest.php`, whose
six tests were each mutation-checked — eleven mutations red, including splitting the file again,
moving the option value to a different option group, declaring a duplicate outside
`Civi/Mascode/Managed/`, and removing the dot-directory pruning.

**One of those guards was vacuous until review measured it**, and the lesson generalises: the
pruning that keeps `.claude/worktrees/` out of the scan was justified by "that copy would otherwise
be flagged", and the worktree in place at the time contained neither record name — so deleting the
pruning left every test green. The fixture is now built rather than borrowed. **A guard justified by
a condition of the environment is only as good as that condition, and nothing checks it.**

**Two pre-existing groups use the same idiom** — `Project_Definition_Fields` and
`Project_Definition_Client_Fields`. They read back correctly **only because their values predate the
declarations**, so those `:name` lines have never had to resolve anything. Recorded, not fixed: they
are correct on every environment that exists today, and a fresh install is the only thing that would
expose them.

**Forward rule:** a new `.mgd.php` CustomGroup that scopes itself to a mascode-managed OptionValue
declares both in one file, option value first, and keeps `extends` in the same `values` array as the
scoping. Core's option loader needs `extends` — or an `id`/`name` it can look `extends` up from — to
know which list to search. On the managed **update** path a `name` is present and core injects the
`id`, so omitting `extends` there would still resolve; it is **create** that breaks, because the row
does not exist yet and both fallbacks miss. Create is the only case that matters, since a bad create
is permanent.

**And the spelling deviation, recorded so nobody "fixes" it:** the spec's Data Model table names the
activity type `Monthly_Project_Check_in`; it is declared as **`Monthly Project Check-in`**, matching
every other mascode-managed activity type. It is a frozen match key that P1-3, P1-5 and every
SearchKit filter must spell exactly, and those sessions will read the spec, not this paragraph —
which is why it is also stated at the declaration itself.

## A token-supplied id is not covered by the existing public-form guard

Found building P1-2, and it changes what D10 is actually protecting against.

`AfformPublicArgGuardSubscriber` filters the args the **caller** sent, on
`civi.api.prepare`. Core copies a signed token's `afformArgs` in later, inside
`AbstractProcessor::_run()`. So an id arriving inside the `_aff` JWT reaches the form having
passed through no guard at all — by design, and its docblock says so.

**D10's wording points at the wrong threat.** It says the form must re-verify "rather than trusting
the id in the URL", which reads as a tampering concern. Tampering is not the exposure: the id is
inside a signed JWT, and a forged one fails its signature. The real exposure is **staleness**. The
digest mints one link per (VC, project) in bulk with a TTL of `checksum_timeout` — 60 days here,
deliberately longer than the monthly cadence (D11) — and case roles change inside that window. A
signature attests to what was true when it was signed and to nothing else, so **a minted link
outlives the entitlement it was minted under**.

That distinction matters for scope: it means the rule is about a link's LIFETIME, not about trust in
tokens, so the other **seven** public forms genuinely do not need this, and a future form reached by
a short-lived per-event token would not either.

**Measured, not argued.** With `CheckinCaseEntitlementSubscriber` disabled, on dev, running as a
real non-staff VC: a token-supplied `case_id` for a project that VC does not coordinate **returned
the case**, and a submit against it **was allowed** — a check-in filed on someone else's project.
The caller-supplied form of the same request stayed blocked throughout, which is the cleanest
statement of what each guard covers.

**The predicate has to be `is_current`, not `is_active`, and the first version got it wrong.**
Review caught it. `is_active` is a flag somebody sets; `is_current` is core's
`is_active = 1 AND (start_date <= today OR IS NULL) AND (end_date >= today OR IS NULL)`. There are
two ways to end a case role and only one clears the flag — `endCaseRole()` (the case-roles UI)
clears it, while an end date set on the Relationships tab, an import, a bulk fix, or the *Disable
expired relationships* job not having run does not. **On the 2026-09-21 clone, 299 of 481 active
coordinator rows that carry a case are ended** — 62% — some since March 2025. The guard was written
to stop a link outliving its role and would have admitted every one of them.

*(A first draft of this paragraph said "31 sit on cases that are not closed". Wrong by 10×, in the
flattering direction: `Project Created` carries `grouping = Closed` in `civicrm_case_status`, and
filtering to `Opened`-grouped statuses gives **1** case today. It does not weaken the finding — the
299 show that ending a role without clearing `is_active` is the NORMAL case, and a guard protects a
60-day window against a future state, not today's snapshot. Methodology note for anyone
re-deriving it: `status_id:grouping` is not a valid API4 suffix and silently matches nothing;
filter on an explicit list of `Opened` status names.)*

That divergence from `AfformPublicArgGuardSubscriber` and `SavedSearch_Case_Details_VC` is
deliberate: those decide portal DISPLAY, this decides whether a public no-login form hands over a
case. **Inherited finding for Brian, recorded not fixed:** those two should probably follow, and
cannot be touched here — production carries an uncommitted hand-patch in
`AfformPublicArgGuardSubscriber.php` and `Security/AfformArgPolicy.php`, so a deploy whose incoming
diff touches either conflicts mid-`git pull` on a live site. The same reason leaves their "seven
forms" docblocks knowingly stale.

**The digest runner (P1-3) had the same bug and is fixed with it.** Grouping on `is_active` would
have mailed a VC about a project whose coordinator role ended. Under `is_current` the 2026-09-21
clone moves exactly one project out of a VC's digest and into the coordinator-less exception report,
which is where it belongs. ⚠ **This changes P2-3's acceptance criterion**: that row currently says
"shows **1** project, not 8", and under `is_current` today's answer is **2**.

**The submit hook is defence in depth, not the thing doing the work.** `Afform.submit` runs
`loadEntities()` too, so the read hook has already stripped an unentitled `case_id` before the
submit hook fires; the submit refusal comes from its no-case branch rather than from the entitlement
test. Worth knowing before someone reads a log line and concludes the form is broken.

**Two things about the guard that a later edit could silently undo**, both asserted in
`tests/Unit/Event/CheckinEntitlementWiringTest.php` against the neighbours *by name* rather than
against a literal: on `civi.afform.prefill` it must sit below `AfformTokenPrefillSubscriber` (1000)
and above core's autofill behaviors (99); on `civi.afform.submit` it must sit above core's
`processGenericEntity` (0).

**Its staff list is duplicated from the other guard rather than shared**, deliberately. Production
carries an uncommitted hand-patch in both `AfformPublicArgGuardSubscriber.php` and
`Security/AfformArgPolicy.php`; a deploy whose incoming diff touches either conflicts mid-`git
pull`, on a live site. The tidier refactor is the one that breaks the deploy. Revisit when that
patch is reconciled.

## The check that found four of this epic's defects, and belongs in review guidance

Named here because it is not what code review naturally does, it is cheap, and it has now caught
four separate things across three PRs:

> **For every guard, name the input that trips it — and then show that input exists in the data.**

Reading a guard for correctness passes all four of these. Asking what state makes it fire, and
whether that state occurs, fails all four:

| guard | reads correctly | the input that trips it |
|---|---|---|
| `array_values($byVc) ?: [[]]` (#40 H1) | looks like an empty-result guard | an empty result — which **fatals**. The guard *was* the crash |
| `is_active` on the coordinator role (#39 H1) | looks like "still the coordinator" | an ENDED role: `is_active` stays TRUE. **299 of 481 rows** that carry a case, on the clone |
| the `onSubmit` entitlement branch (#39 M1) | looks like the write-path gate | nothing: the read hook already stripped the id, so `isEntitled()` is never reached there |
| the test's own fixture discovery (#39 H3) | looks like "a case I coordinate" | an ended role again — **70%** of candidate VCs, so the check fails against a *correct* guard |

Two of those were guards **added to close an earlier finding**, which is the part worth
internalising: a fix written under review pressure is where the next vacuous guard comes from.
The same shape appeared twice more in the test suite itself — a dot-directory exclusion asserted by
a worktree that did not contain the thing it was meant to catch, and a duplicate-coordinator test
fed an already-deduplicated fixture.

**What to do with it:** when reviewing or writing a guard in this repo, state the tripping input in
a comment or a test name, and check it against real data if the data is reachable. If the input
cannot be produced, the guard is decoration and should be deleted or replaced with something that
can fire.

## What PR #40's review found, and the shape of it

Three things worth carrying forward, because none was a typo.

**A "guard" that was the bug.** `array_values($byVc) ?: [[]]` was written to protect an empty
result and instead guaranteed a fatal on one: it iterates ONCE with `$vc = []`, so
`array_column()` gets NULL. It took down the **D12 pilot path** — the spec's mandatory pre-send
step — and **a month with no eligible projects**, which is the feature succeeding and precisely the
case the run summary exists to distinguish from a job that never ran. `run()` issues API4 calls so
CI cannot reach it; the fix was to pull the count into a pure function rather than to patch the
expression, so the empty case became a one-line assertion.

**Tests that were vacuous in the specific way this project keeps producing.** The
duplicate-coordinator test passed an already-deduplicated fixture, so deleting the collapse it
claimed to guard left the suite green; and the fixtures used one row shape throughout, so renaming
the `case_id` key that the headline count reads also left it green. **Both were caught by mutation,
not by reading.** That is now three separate guards in this epic found asserting nothing —
worth treating as the default suspicion rather than an unlucky run.

**A refusal that only fired on total garbage.** `normalisePilotIds()` claimed to refuse an
unparseable pilot list and in fact kept whatever parsed: `'1,abc'` silently dropped a chosen VC,
and `'12.9'` silently substituted a **different** one. The class is explicitly shaped against
silently dropping a VC; it was doing it one step later, in delivery rather than selection.

## Two spec deviations in P1-5, recorded rather than left in a docblock

**`digest_round` falls back to the current month instead of being blank.** Spec §Data Model says
"`YYYY-MM` of the prompting digest; **blank if reached another way**". The code reads the round from
the digest activity that prompted the answer and falls back to `date('Y-m')`. A blank is honest but
useless for counting, and a VC who answers in early October about September's digest belongs to
September's round — which the lookup gets right. The fallback only applies when no digest marker can
be found at all. **The trade is that a purified marker yields a stale round rather than a blank**,
and a wrong `YYYY-MM` passes the shape check and looks right; HTML Purifier strips HTML comments
when an activity is edited in the CiviCRM UI, which `LifecycleMailer` documents for its own marker.
Goal 8 counts distinct rounds, so this matters if it happens.

**`target_contact_id` is not set to the client organisation.** Spec §Data Model asks for it;
neither the afform nor the submit subscriber sets it. Inherited from P1-2 rather than introduced
here, but P1-5 owns the server-side stamp, so this is the natural place to fix it — and it is the
join a "which clients has this VC been asked about" query would want.

## Parallel-safe set

- **P1-1 alone** until it lands — everything in Phase 1 depends on it. (P1-2 is being built stacked on P1-1's branch rather than in parallel, for exactly that reason.)
- **P2-3 and P2-4** may run concurrently once **P2-2** is in — P2-3 depends on P2-2 and P2-4 on
  P2-1, so P2-2 is the later of the two gates. They touch different subsystems (SearchKit display
  vs job monitoring).
- Nothing in Phase 0 is parallel-safe any more; it is merged and deployed.

## Still open, and who decides

| Question | Decided by |
|---|---|
| Which VCs are in the pilot | Named in the spec's `## Open Questions` |
| How hard the digest copy asks | Named in the spec's `## Open Questions` |
| Follow up a VC who answered "I'll ask"? | Phase 3 |
| The **two** Active projects with no *current* coordinator — mis-assigned or abandoned? (Was one; `is_current` made it two in P1-3, because a project whose coordinator role had ended stopped counting as coordinated.) | Named in the spec's `## Open Questions`, once P2-3 exists |
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
It sits outside P0-3's three named places, so it was deliberately not swept in. It did not block
the Phase 0 deploy and does not block Phase 1.

**Inherited gap, still open (from PR #34).** `FORBIDDEN_IN_CONSUMERS` covers only the two renamed
status labels — deliberately, per the test's own docblock. The positive assertions ask whether a
file mentions a frozen name *somewhere*, so a file with two **code** occurrences stays green when
only one is renamed.

The case that **demonstrated** it is PR #34's review round 2: renaming just the client transition's
`from` gate in `Civi/Mascode/Event/ProjectLifecycleStatusSubscriber.php` (line 69) left line 66's
occurrence intact, the suite passed, and a project would have stopped advancing silently. **That
exact mutant is now caught** — `FORBIDDEN_IN_CONSUMERS` was added for it, and the renamed spelling
is item 1 on the list. What stays open is everything the list does not name: activity-type names,
and any status label other than those two.

**`LifecycleRuleProvisioner.php` is NOT an example of this**, and CHANGELOG 1.1.18 citing it as one
is wrong — corrected here rather than carried. It has two raw occurrences of
`Project Close - VC Report` but only one survives `FrozenMachineNamesTest`'s `codeOnly()`: line 266 is
a docblock, line 290 is the live `addWhere`. Renaming that one turns the suite **red**. Recorded
rather than fixed, because the gap itself predates this work.

**Forward rule for any Phase 1+ ticket that renames a managed `name`:** use core's `replaces` key.
P1-1 creates rather than renames, so it does not apply yet.
