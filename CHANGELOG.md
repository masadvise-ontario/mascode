# CHANGELOG

## 1.1.19 (2026-09-22)

Starts Phase 1 of the VC monthly donation digest — the pilot experiment. This release is
P1-1 only: the data model the rest of Phase 1 writes into. Nothing user-visible changes yet,
and no email is sent by anything here.

### Features
* **New *Monthly Project Check-in* activity type, and the custom group holding a VC's answers (P1-1).** One activity per project per digest round: `is_complete`, `vc_will_ask` and `digest_round`. The form that writes them is P1-2; this is the shape it writes into.
* **The answers live on the ACTIVITY, not on the case — a deliberate departure from the 2026-06-14 "form answers live on the case" decision.** `Project_Close_VC` and `Project_Close_Client` extend `Case` because each is answered once per project and only the latest answer matters. This is the opposite: the repeat history *is* the product. A project answered "not complete" six months running is the signal the digest exists to surface (spec Goal 8, D5), and case custom fields would overwrite the previous round every time and destroy exactly that.
* **`vc_will_ask` is nullable on purpose.** NULL means the question was never put to the VC, because answering No to `is_complete` hides it. The spec mandates it directly — *§Data Model*: "Boolean, nullable | Q2. **NULL when Q1 = No**" — and the distinction is worth keeping because "not asked" and "answered No" are different facts about a VC, which matters the moment anyone reports on the second question. (An earlier draft of this note attributed the nullability to **D8**. D8 governs when the office follow-up fires — on *(no VC ask)* AND *(signoff returned with no donation)* — and draws no NULL-vs-FALSE distinction at all. The field is right; the citation was not.)

### One deliberate deviation from the spec
* The spec's *Data Model* table names the activity type `Monthly_Project_Check_in`. It is declared as **`Monthly Project Check-in`** — a human-readable string, which is what every other mascode-managed activity type is (`Sent Automated Email`, `Project Definition - Client Authorization`, `Project Close - VC Report`). Matching the neighbours beats matching a table written before any of this existed. Flagged rather than left to be discovered because this is a **frozen match key**: P1-3, P1-5 and every SearchKit filter must spell it exactly, and those sessions will read the spec. Recorded at the declaration and in the ticket slice so nobody "corrects" it back and strands every match.

### A managed-entity trap, found here and live on this branch before it was fixed
* **`extends_entity_column_value:name` can resolve to NULL, silently — and NULL means "scoped to EVERY activity type", not "scoped to nothing".** Core resolves that pseudoconstant against the *live* option list at write time (`CRM_Core_BAO_CustomGroup::getExtendsEntityColumnValueOptions()`). When the named option value does not exist yet it writes NULL with no exception, no log line and a reconcile that reports success. All three check-in fields would have appeared on **every activity form in CiviCRM**.
* **The ordering was deterministic and against us.** The `mgd-php@2` mixin does `sort($mgdFiles)` and appends each file's array in order; `ManagedEntities::reconcileEntities()` walks the `create` plan in that same order. Declared under this directory's own one-entity-per-file convention the files were `CustomGroup_…` and `OptionValue_ActivityType_…` — **"C" sorts before "O"** — so on every clean environment the group was created before the option value existed. Reproduced on dev.
* **A bad create is PERMANENT, and an earlier draft of these notes said the opposite.** `ManagedEntities::optimizePlan()` drops every `update` item whose stored checksum still matches the declaration's, and hand-breaking a *record* does not change the *declaration's* checksum. The exceptions are upgrade mode, an active install/enable process, and a changed declaration — and **`cv flush` is none of them**. Measured on dev both ways: clear the column, `cv flush`, still NULL; `cv upgrade:db`, restored. So the remedy is `cv upgrade:db`, and a flush would have been a reassuring no-op.
* **The wrong claim came from a bad experiment, not a bad reading** — recorded because the experiment looked conclusive. `CustomGroup::update()->addValue('extends_entity_column_value', NULL)` reports success and stamps `entity_modified_date` while leaving the column **unchanged**, so the "reset" never happened and the flush that followed had nothing to heal. Caught in review, which is the second time this file has had to record a "verified against core" claim that was not — see the 1.1.16 correction. Clearing that column has to be done at the column to be real.
* **It would still have surfaced on production and nowhere else**, because only a *clean* environment creates these records for the first time, and the deploy ritual's `cv upgrade:db` leg is what would have covered it up.
* **Fixed by declaring both in one array, option value first** (`Civi/Mascode/Managed/ActivityType_MonthlyProjectCheckin.mgd.php`), which removes the ordering question rather than answering it. Verified the way it had to be: delete both records *and* their `civicrm_managed` rows, `cv flush` **once**, confirm the column is populated.
* **`extends` must stay in the same `values` array as the scoping.** Core's option loader needs it to know which option list to search; an update that omits it resolves to NULL just as quietly. Demonstrated both ways on dev.
* **Two pre-existing groups use the same idiom and are not fixed here** — `Project_Definition_Fields` and `Project_Definition_Client_Fields`. They read back correctly **only because their values predate the declarations**, so those `:name` lines have never had to resolve anything. They are correct on every environment that exists; a fresh install is the only thing that would expose them. Recorded in `docs/plans/completion-signoff-tickets.md` rather than fixed, because the gap predates this work.

### Tests
* **`MonthlyCheckinDeclarationTest` (new)** guards the four things that make the scoping work: the option value is declared before the group, nothing else declares either record (so no filename sort can separate them again), the group carries `extends` alongside its `:name` scoping, and the two answers are real Booleans with `vc_will_ask` nullable.
* It `include`s the `.mgd.php` rather than parsing it as text — the file is a bare `return [...]` with no Civi calls, so it loads in CI where there is no CiviCRM. That is stronger than the text-parsing approach `LifecycleTransitionTemplateWiringTest` is forced into by its subject extending an `AutoSubscriber`.
* **Two assertions added in review, closing the mutation nearest the bug.** The first version asserted nothing about the option value's `option_group_id.name` or its `is_active` — so moving the declaration to a different option group left all four tests **green** while the custom group's `:name` scoping resolved against a list the value was not in and landed NULL, which is precisely the defect the file exists to prevent. A disabled value fails the same way, because core resolves the list with `$includeDisabled = FALSE`.
* **The file-split guard was both too tight and too loose, and is now neither.** It compared raw file contents, so a future `.mgd.php` that merely *mentions* the activity type would have gone red — and one is planned, since P2-3's Ops rows are naturally a SavedSearch filtering `activity_type_id:name` on this exact string. It also globbed one directory, while core's mixin collects `*.mgd.php` from the extension root plus `managed/`, `api/`, `CRM/` and `Civi/` recursively. It now strips comments and walks the whole tree, and looks for a *declaration* rather than a mention.
* **Seven mutations checked, each goes red:** drop `extends`; make `vc_will_ask` required; scope by numeric value instead of `:name`; move the option value to the end of the array; split it back into its own file; move it to a different option group; deactivate it. Plus one mutation that must stay **green** and does — a sibling file mentioning the type only in comments.
* What the test **cannot** do, stated in its docblock: it reads the declaration, not the database, so it proves the inputs to core's resolution are right, not that resolution succeeded. The live half was verified by hand on dev and has no CI home.

### Deploying this release
* `HOME=/home/mas/tmp cv upgrade:db` then `HOME=/home/mas/tmp cv flush`, as usual. No upgrade step. The `HOME=` prefix is required, not decoration — see `CLAUDE.md`.
* **Confirm the scoping actually landed on production.** This is the first environment where these records are created from clean, and the defect above is a create-time one:

  ```
  HOME=/home/mas/tmp cv api4 CustomGroup.get '{"select":["name","extends_entity_column_value:name"],"where":[["name","=","Monthly_Project_Checkin"]]}'
  ```

  It must return `["Monthly Project Check-in"]`. **Null or empty means the group is scoped to every activity type**, and the three check-in fields are showing on every activity form in CiviCRM.
* **If it is null, the fix is `HOME=/home/mas/tmp cv upgrade:db` — NOT a flush.** A flush will report success and change nothing, because the checksum still matches (above). Re-run the check afterwards, and say out loud that it happened rather than fixing it quietly.

## 1.1.18 (2026-09-21)

Finishes Phase 0 of the Completion/Signoff rework: P0-3, P0-4 and P0-5. Nothing here
was broken — 1.1.17 left these three deliberately ("Not in this release").

### Features
* **Expenses are gone from the VC's Project Completion form, its email and the VC Portal case-detail screen (P0-3).** MAS no longer collects them. Three edits, and the custom field and every historical value are untouched: the field is `cleanup => 'never'` and the captured history is not disposable (D14). The case-detail card is `requireAnyNonNull`, so a project whose *only* populated close field was expenses now hides that card entirely — correct, since there is nothing left to show.
* **The Project Signoff form shows the client their consultant's report (P0-4).** Hours contributed and services delivered, read-only, above the feedback questions. No join and no new entity: both custom groups declare `extends => 'Case'` and the client form already loads `Case1` via `case-autofill="entity_id"`, so these are ordinary `DisplayOnly` fields (D15). Expenses are excluded here too. Hours are the number that makes the donation ask land, because they are what the client would otherwise have paid for.
* **One canonical donation ask, on both forms and in the Signoff email (D17, P0-5).** The same three paragraphs, the same three ways to give — e-Transfer, cheque, then CanadaHelps with the administration fee stated plainly — and a real donate button rather than a bare link. Ranking CanadaHelps last, and saying why, is MAS's stated preference and costs nothing to honour (D16).
* The Signoff email now names the project (`Project: {case.subject}` / `MAS code: {case.custom_34}`), matching every other lifecycle template. This is the `<<project number>>` slot from the source copy; `custom_34` is MAS Project Case Code, verified on production.

### The empty-report case, and why it is an `af-if`
* A client whose consultant has not filed yet must not be shown an empty **"Your consultant's report"** heading — that reads as a system fault, not as an absence. The block is wrapped in core's `af-if`, and **each of the two fields carries its own condition as well as the block**, so a partly-filled report shows only the parts that exist.
* **The per-field guard exists because of production, not dev.** All 2306 close records on production have hours recorded, but **2286 have no services text — and it is ongoing, not legacy**: of projects started in 2024 or later it is 253 blank against 19 filled. Guarding only the block would therefore have shown ~93% of clients "Hours Contributed: 10" followed by a **"Services Delivered" label with nothing under it** — the same empty-field fault the feature exists to prevent, moved down one level. A dev-only check could not have found this.
* This is the **first `af-if` in the extension**, so it was verified by rendering rather than by reading, across all three data shapes: both fields, hours-only, and neither.

### `af-if` has a serialization format, and hand-writing it is not enough
Review caught this and it would have shipped: the condition rendered correctly in the browser while **three separate core mechanisms disagreed with it**. Recorded in full because the extension will write more of these.

* Core stores `af-if` as **`(<JSON, with `"` as `&quot;`>)`** and addresses fields in **bracket** notation — `Case1[0][fields][X]`, never `Case1[0].fields[X]`.
* The client-side directive is the forgiving one: `afForm.component.js`'s quote-restoration regex repairs a hand-written value, which is exactly why the render looked right.
* Everything else rejects it. `FormDataModel::parseFields()` does `substr(…, 1, -1)` then `json_decode()` — on an unwrapped value that yields **`NULL`**, and core then attaches that `NULL` to **every sibling field after it**; `Submit::getRequiredFieldError()` passes each to `checkAfformConditional(array $conditional, …)`, so a **`TypeError` on a public client-facing form** the day anyone marks one of those fields required. `Submit::getValueFromEntity()` resolves the dot path to `NULL`. And FormBuilder's conditional dialog **silently deletes** any value not starting with `(` — one round-trip through the UI this repo's own workflow recommends would have removed the guard with no error anywhere.
* Fixed by using core's own format, verified by running core's parser, path evaluator and `checkAfformConditional()` against this branch. The block is also **nested one level deeper**, which confines core's sibling-condition accumulation: the nine client-feedback fields now carry **no** condition at all, where previously they each carried a broken one.
* `IS NOT EMPTY` is `!!$val`, so hours of exactly `0` is falsy. That is harmless and arguably right: 0 hours with a services description still shows the block, 0 hours with nothing else hides it. Production has no zero-hours record.

### One deliberate departure from the spec
* D17 says the canonical text is used **verbatim** in all three places. On the **RCS form** one sentence is not: the canonical paragraph 2 is past-tense ("the project our volunteer consultant *did* for your organization"), and the RCS form is the *intake* form — it would thank a client for work that has not started. That sentence reads "When your project is complete, we hope you will be happy with the results, and we ask that you consider a donation to MAS at that point." Everything else on the RCS form — both other paragraphs, all three methods, the button — is the canonical text character for character. **Flagged for Brian rather than decided quietly:** reverting it to verbatim is a one-line edit if that is the call.

### Tests
* **`FrozenMachineNamesTest` now derives its own consumer list** instead of trusting a hand-written one (carried into P0-3 from PR #34's round-3 review). It re-runs the comment-stripped sweep the list was built from and fails when the list and the repo disagree **in either direction** — a new file referring to a frozen name, or a declared file that stopped referring to one. A file must be classified as a consumer or excluded with a written reason; `NOT_CONSUMERS` is itself checked, so a stale exclusion cannot sit there hiding the next real one.
* The sweep immediately found two undeclared references: `tests/Integration/Managed/CaseTypeSmokeTest.php` matches on both frozen status names and is now guarded. `tests/Unit/Submission/StaffCopyIdentificationTest.php` uses one as a fixture `form_title` and is excluded with that reason.
* Rejected alternative, again: hoisting the strings into a shared constant. The two afforms are Angular markup with no import mechanism, so a constant covers six of ten references and leaves two guard mechanisms where there is now one.
* All four new assertions were mutation-checked — an undeclared consumer, a renamed reference, a stale `CONSUMERS` entry and a dead exclusion each go red — because this file's own history is of a guard that passed while the invariant broke.
* **`CanonicalDonationCopyTest` (new) makes D17 a mechanism rather than an intention.** "One text with one owner" was three hand-maintained copies in three files, two of them editable through the FormBuilder UI, with nothing comparing them — a reviewer diffed all three by hand for this PR, which was the last time anyone was going to. The canonical fragments are now declared once and asserted into all three, compared as rendered prose so the markup is free to differ (the email needs a table button, the forms use a CSS class). The RCS tense exception is asserted in **both** directions, because the interesting failure is not that it vanishes but that it spreads.
* The frozen-name sweep now also scans `.json` and `.tpl`, not just `.php` and `.aff.html` — the narrower filter made the docblock's "anywhere in the tree" claim untrue, since a name landing in the CiviRules registration JSON would have been invisible. Verified to add no new hits, so this closes a gap in the claim rather than a live one.
* That guard compares the **whole ask**, start to donate button, not just that each canonical fragment is present. Checking presence says nothing about text that has been *added*, and additive drift is the realistic case — a campaign line or a changed fee note dropped into the email and not the two forms. Review demonstrated it defeating the presence-only version, so the equality assertion exists because the weaker one was watched to fail.
* Writing it immediately caught a fault in its own constants: the RCS substitute paragraph held only the sentence that differs, not the sentence it shares with the canonical one, which made the two silently non-substitutable.
* `ang/README.md` now names a **working non-staff VC login** for `AfformPublicArgGuardTest`. That test aborts rather than passing vacuously when run as staff, and "run it as a VC" was the whole cost — finding a login with a `uf_match` row, without `edit all contacts`, that actively coordinates a case. It also records that a dev pass is weaker than a prod pass for the entitlement half, because dev contributors lack permissions production contributors hold.
* Every new assertion in both test files was mutation-checked. Unit suite **113 tests / 464 assertions** green. Live script GREEN on dev, 26 assertions. Both public-form guard checks pass: the anonymous probe (49 probes, nothing returned) and `AfformPublicArgGuardTest` run as a real non-staff VC (10 assertions).

### Notable
* `SavedSearch_Case_Details_VC_Fields.mgd.php` names both activity types, but as SearchKit **admin labels**, not as match values — the heading a VC reads lives in `ang/afsearchMASCaseDetailsVC.aff.html` and was renamed in 1.1.17. It stays on the consumer list because the file matches on status names elsewhere; the docblock now says so, so a red test there is not mistaken for a live transition break.
* **The email's donate button is a table, not a styled `<a>`.** Outlook 2007–2019/365 on Windows renders through the Word engine, which ignores `display:inline-block`, `padding` on inline elements and `border-radius` — the white label would have survived where the navy fill did not, leaving an invisible donation link in the inbox of a sector that runs on Microsoft 365. `bgcolor` plus padding on a `<td>` survives there. Its geometry matches `.mas-donate-btn`.
* `css/mas-forms.css` gains `.mas-donate-btn`. It is an `<a>`, so none of the `#bootstrap-theme .btn*` rules apply — but `#bootstrap-theme a` does, at (1,0,1), setting `background-color`, `color` and `text-decoration`. Those three, the hover pair and the focus outline are the only `!important`s, per the per-property test the submit button's comment block documents. White on `--mas-navy` is 9.1:1.

### Noted, deliberately not fixed here
* **Two other templates still ask clients to reimburse VC expenses** — `MessageTemplate_MAS_RCS_Template.body.html` and `MessageTemplate_after_RCS.body.html`. The RCS *form* and the RCS *email* now say different things about money. P0-3 names three places and these are not among them, so this is a scope question for Brian: if "MAS no longer collects expenses" covers the reimbursement ask too, these need their own ticket.
* **`FORBIDDEN_IN_CONSUMERS` still covers only the two renamed status labels** (inherited from PR #34). A file with two code occurrences of an activity-type name stays green if only one is renamed — `LifecycleRuleProvisioner.php` has two of `Project Close - VC Report`. Adding `Project Completion Report` to that list looks safe; `Project Signoff` does not, because it false-positives on legitimate new wording.

### Deploying this release
* `cv upgrade:db` then `cv flush`. No new upgrade step, but the managed message templates must reconcile.
* **Check `SavedSearch_Case_Details_VC_ProjCloseVC` too, not just the templates** — it is also `update => 'unmodified'`. If anyone has ever edited that search in the SearchKit UI on production, the expenses column will not be removed there and nothing will report it. Unstamped on dev; confirm on prod.
* Both lifecycle templates were verified **unstamped** on dev and production on 2026-09-21, so these body edits ship as ordinary declaration edits — see the 1.1.17 correction. If either has since been hand-edited in the production UI, `update => 'unmodified'` will decline to rewrite it and the donation copy will need an upgrade step instead. **Check before assuming the deploy landed.**


## 1.1.17 (2026-09-21)

### Features
* **The Project Close forms, emails, statuses and activity types now read "Project Completion" and "Project Signoff"** — MAS's own language for what these things are. The VC's form and email are *Project Completion*; the client's are *Project Signoff*. Staff see the new wording in case-status dropdowns, on the activity timeline, in the custom-field group headings, on the ops dashboard and in both emails.

### The rename is labels-only, and that is deliberate
* **Every machine `name` is frozen at its old spelling.** CiviCRM shows a user the `label`; the `name` is matched on by code. mascode uses `:name` for WHERE filters and `:label` for displays — with three label-filter exceptions, one of which this rename broke and review caught (see below) — so renaming labels alone delivers the entire user-visible change while `ProjectLifecycleStatusSubscriber::TRANSITIONS`, `CaseStatusSet`, the SavedSearch filters and — critically — the **serialised CiviRules condition params that no deploy rewrites** all keep matching, untouched.
* This is the same reasoning D13 used to decline renaming the Afform machine names: the benefit would have been a string no user ever reads, and the cost was a data migration across ~30 files plus the exact silent-failure class that broke the client close email in September.
* The frozen names *look* wrong — they all still say "Close" — so `tests/Unit/Managed/FrozenMachineNamesTest.php` exists to stop a future tidy-up "finishing the job". It fails if a declaration renames a name, if a label reverts to matching its name, or if a consumer and its declaration stop agreeing. All three were mutation-checked.

### Fixes
* The VC lifecycle template is renamed to `MAS Project Completion - VC Template` with subject `Project Completion`, and `TRANSITIONS` is rekeyed in the same commit. `upgrade_5015` converges any environment whose copy the declaration cannot reach.
* Both email bodies carry the new headings, and the client body's retired `MAS Project Close - Client` `<h1>` — deferred from 1.1.16 — is fixed.

### Correction to the 1.1.16 notes
* 1.1.16 said the client template was now deploy-inert and that its body fix could no longer ship as a declaration edit. **That was stronger than the facts, and this release disproves it:** the `<h1>` shipped as an ordinary declaration edit. Two things the earlier note missed — managed reconciliation runs **before** the upgrade steps inside `cv upgrade:db`, and a successful reconcile **clears** `entity_modified_date` rather than setting it, so a declaration never freezes itself. Both templates were verified unstamped on dev and production on 2026-09-21.
* The underlying mechanism is still real and still worth knowing: a **hand edit in the CiviCRM UI** does stamp the record, and `update => 'unmodified'` then declines to rewrite it for good. That is what `upgrade_5013` and `upgrade_5015` are the belt for — and because they run after reconciliation, they only ever fire on a site the declaration could not reach.

### Also fixed, found in review
* **Board metric 20 would have silently undercounted open projects.** `SavedSearch_MAS_Board_QTD` row 20 filtered on `status_id:label` against a hardcoded list, which worked only while every status name equalled its label. The rename broke that: two entries matched nothing, so the quarterly board report would have quietly excluded **9 live production cases** with no error and no empty grid. The filter now uses `status_id:name`, which is a zero-semantic-change fix because that list was always the names. Dev has no cases in those statuses, which is why only a review caught it.
* The claim that this extension "uses `:label` only in displays" — made in the 1.1.17 notes below and in the PR — **was wrong**. Three SavedSearch declarations filter on labels; two derive them live from `CaseStatusSet` and self-heal, and the third was the bug above.
* The VC's and client's **confirmation emails** were still headed "Project Close - VC Report" / "Project Close - Client Feedback": hardcoded overrides in `SummaryConfig` shadowed the renamed CustomGroup titles. Both removed, so the heading now follows the live title and cannot drift again.
* Both **chase reminder emails**, the anniversary check-in, the **Ops Home** heading and the **VC Portal** case-detail screen all still named the old forms.
* The guard test also allowed a **partial** rename within one file: its assertions asked whether a file mentioned the frozen name *somewhere*, so renaming only the client transition's `from` gate stayed green while a project silently stopped advancing. A negative assertion now forbids the renamed status labels from appearing in any consumer at all.
* `tests/Unit/Managed/FrozenMachineNamesTest.php` **did not actually guard**. Its assertions were substring matches over whole files, and every file also names the frozen strings in its docblock — so renaming `TRANSITIONS`' from/to values left the suite green. It now strips comments before matching, and its consumer list was rebuilt from a comment-stripped sweep rather than from memory.

### A new class of guard: A5
* Renaming the VC transition's subject to *Project Completion* made the obvious chase wording — "Reminder: Project Completion report for …" — a **superstring** of it. Since `matchTransition()` substring-matches whatever subject was logged on the case, that reminder would have advanced the case. The chase subjects were reworded, and the Live script's new **A5** asserts that no other active template's subject contains a transition prefix. Verified by putting the naive wording back and watching it go red.

### Not in this release
* Removing `expenses_incurred` (its own ticket) and the unified donation copy (likewise). Both are deliberately left in the templates and forms.

## 1.1.16 (2026-09-17)

### Fixes
* **Sending a client project signoff advances the case again, and the automated client close email is sent again.** On 2026-09-17 message template 75 was renamed in the production UI from `MAS Project Close - Client Template` to `MAS Project Signoff - Client Template`. That exact string was hardcoded in three unrelated places, and two of them broke. `ProjectLifecycleStatusSubscriber::TRANSITIONS` keys the client transition on it and resolves templates with `WHERE msg_title IN (…)`, so the lookup missed and the case silently stopped advancing — no exception, no log line, the email still sending and still looking correct. Separately, the CiviRules rule `mas_lifecycle_vc_close_send` stores the title as *serialised data* in `civirule_rule_action.action_params`, which no deploy touches; `LifecycleMailer::loadTemplate()` resolves by `msg_title` and **throws** when it misses, so that email was not being sent at all. `upgrade_5013` converges an environment still holding the old title, `upgrade_5014` repoints the serialised rows, and the provisioner's own literal is corrected so a rebuilt environment does not reintroduce it.
* Neither fault had actually fired. The last client close email went out 2026-09-09 and completed correctly; the rename postdates it and no VC close report has arrived since. Nothing was lost and nothing needs re-sending.
* `matchTransition()` now iterates the transitions in declaration order. It always documented that it takes the first matching subject prefix "in TRANSITIONS order", but the map was built straight from an API4 result set with no `ORDER BY`, so the real order was the database's. D18 (no lifecycle subject prefix may contain another) means nothing was ambiguous in practice — this closes a latent gap between the code and its own stated rationale.

### Tests
* `tests/Unit/Event/LifecycleTransitionTemplateWiringTest.php` (new, runs in CI) holds the source-to-source half: the `TRANSITIONS` keys, the provisioner's action-params literal and the upgrade steps' migration constants must all name a declared template title, and no declared subject prefix may contain another. An Integration-suite test could not do this job — CI has no CiviCRM, so that suite self-skips and would have reported green throughout the outage.
* `tests/Live/LifecycleTransitionTemplatesTest.php` (new, `cv scr`, read-only) holds the half that needs a real database, including the assertion that would have caught this on day one: every active CiviRules action must name a template that actually resolves. Safe to point at production.

### Deploying this release
* `cv upgrade:db` is **required**, not `pull` + `flush` — both fixes live in upgrade steps.
* **Running the Live script afterwards is a required step, not a suggestion:** `HOME=/home/mas/tmp cv scr tests/Live/LifecycleTransitionTemplatesTest.php --user=<a user with a uf_match row>`. Review established that a typo in a migration step's *source* title survives CI and is visible only here — no source-text test can catch it even in principle. The step would log "no CiviRules action names the retired title", which reads exactly like success, while production stayed unrepaired. Exit 0 is green; 1 is a failure; 2 means it refused to report a green it had not earned.

### Known follow-up
* `MessageTemplate_MAS_Project_Close_Client_Template.body.html` still carries the retired `MAS Project Close - Client` `<h1>`. It is cosmetic and belongs with the wider Completion/Signoff rename — but it can no longer ship as a declaration edit. These templates are `update => 'unmodified'`, and CiviCRM stamps `entity_modified_date` on any edit of a managed entity, including `upgrade_5013`'s own rename, which makes the declaration permanently inert for that row. The body fix has to ship as its own upgrade step or it will deploy and silently do nothing.

## 1.1.15 (2026-09-14)

### Fixes
* **The submit button on the seven client-facing forms now looks like a button.** A client completing the Project Definition authorization form reported there was no way to submit it — they had filled it in three times. They were right that nothing looked clickable. CiviCRM's "default" frontend theme is Greenwich, which ships Bootstrap 3 and recolours `.btn-primary` to a flat `#70716b` at 30px tall; afform's `.af-layout-inline > * { flex: 1 }` then stretched it to the full form width. Nothing about the result read as clickable. `css/mas-forms.css` now styles `.mas-form .af-button` explicitly: MAS navy, white 17px semibold label, 12px/32px padding, rounded, with hover, pressed, keyboard-focus and disabled states. CSS only — no markup, behaviour or permission change, and every rule stays scoped under `.mas-form`.
* Long submit labels wrap instead of overflowing at narrow widths (Bootstrap's `.btn` sets `white-space: nowrap`), and the keyboard focus ring meets WCAG 2.2 SC 1.4.11.

### Docs
* `ang/README.md` gains a **Styling** section naming the two invariants the stylesheet depends on — `"requires": ["mascodeForms"]` in the `.aff.json` and `class="af-container mas-form"` on the outer container — either of which a FormBuilder round-trip can silently drop, un-styling the form. Its packaged-forms table also gains the two Project Definition forms, which were missing.

*(No 1.1.14 entry: that release shipped without one.)*

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
* **An ambiguous case writes to nobody.** A case carrying more than one **distinct person** as client rep has its fieldset ignored entirely (distinct: the cache holds 206 current rows for 195 people, so without de-duplicating them ~11 cases would be misread as ambiguous and silently refused): which one the form autofilled from is not knowable server-side, because core issues that query with no `ORDER BY`. Merely declining to move the role was not enough — Afform's default still renamed whichever contact it had autofilled.

### Fixes
* **The client-rep blurb on both forms states these rules.** It says that recording a different person needs both name halves, that a blank field means no change, and that an incomplete name which would have changed the name on file leaves the email unchanged too — so the form and the code agree.
* **Join ids are dropped whenever the contact id is.** Without this the outgoing person's Email row — whose id the browser echoes back from the prefill — is *reassigned* to the new contact by `Email::replace()`, leaving them with no email address and no error anywhere. Reproduced on dev before the fix and confirmed neutralised after.
* **The incoming role is created before the outgoing one is ended.** `Afform.submit` is not transactional (`TransactionSubscriber` returns early for APIv4), so the previous order left a window in which an interruption — a hook or CiviRules action vetoing the create, a deadlock, an execution-time kill — left the case with **no** active client rep at all, silently. Creating first inverts that into a transient duplicate, which is visible and warned about.
* **Email corrections are pinned to the contact holding the case role.** Core's `ContactDedupe` (priority 101) could otherwise retarget an email correction onto a duplicate matching first+last+email, leaving the actual rep with the stale address and nothing logged. The incumbent is read from the case's own role, never from the submitted record id.
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