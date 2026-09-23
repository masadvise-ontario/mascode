# CHANGELOG

## 1.1.24 (2026-09-23)

Housekeeping: the message-template naming convention is now written down, and the one
template that broke it is renamed.

### The convention, stated
* **Two tiers, and the prefix answers "who sends this?"** — `MAS <Title Case>` a
  person sends by hand; `mas_*` the system sends unattended, with a `__client` /
  `__vc` / `__ed` / `__treasurer` suffix naming the recipient so the two halves of one
  event sort together. It was already the practice — two declarations say "not a
  CiviRules rule — hence no `mas_lifecycle_` prefix" — but it lived only in docblocks,
  which is why nothing caught `after RCS`. Now in `Civi/Mascode/Managed/README.md`.
* **`_lifecycle_` is a sub-namespace and is NOT reliable, which the audit turned up.**
  It usually marks a CiviRules-fired template, but `mas_lifecycle_donation_notify__ed`,
  `__treasurer` and `__vc` carry it while their own docblocks describe a Symfony
  subscriber on `Contribution.create`. They are unbuilt Phase 4 skeletons so nothing is
  broken — but you cannot read the infix as proof of a rule, and the new test
  deliberately does not assert it.
* **The prefix states the intended mechanism, not a live one.** Several
  `mas_lifecycle_*` templates have no rule firing them yet.

### `after RCS` → `mas_lifecycle_rcs_circulated__client`
* **The old title named neither tier** and read as a developer's note rather
  than something staff would recognise in a dropdown. The new one pairs it with the
  VC-facing half of the same event (`mas_lifecycle_vc_assignment_offer__vc`); both fire
  on Service Request → "Sent for Assignment", and **neither is wired yet** — no
  CiviRules action in dev names either, and no PHP in this extension references them.
  A sweep confirmed that before the rename, which is what makes this safe.
* **`upgrade_5016` does the rename, not the declaration alone.** `match` is on
  `msg_title`, so on a site whose template was hand-edited in the UI —
  `update => 'unmodified'` refuses to rewrite those — the row would keep the old title
  while the declaration claims the new one, and the next flush would match nothing and
  **create a second template** instead of renaming the first. Likelier here than at
  5015: this body is a snapshot of something staff have edited for years.
* **The managed `name` and the file name stay `after_RCS`.** CiviCRM reconciles by
  (module, name, entity_type), so renaming `name` orphans `civicrm_managed` row 312
  (`cleanup='never'`, so it persists) and creates a second managed row for one
  template. Same precedent as the P0-2 VC-completion rename.

### Two live templates deliberately still break the convention
* **`MAS Form Submission Confirmation`** is machine-sent by `AfformSubmitSubscriber`
  (seven `server_route` values map to that literal), and **`MAS Project Signoff -
  Client Template`** is fired automatically by the `mas_lifecycle_vc_close_send`
  CiviRule while also being a key in `ProjectLifecycleStatusSubscriber::TRANSITIONS`, a
  `VcDigestMailer` subject guard and `civirule_rule_action.action_params`. Renaming
  either is a coordinated multi-file change with a live send path behind it — the exact
  shape that broke production on 2026-09-17 — so both are documented as exceptions
  rather than changed here.

### Not changed
* The body of the renamed template keeps both known defects — it opens with a
  hard-coded first name where a token belongs, and its reimbursement sentence is
  garbled and contradicts the RCS form, which no longer collects expenses. Item 0 in
  `docs/plans/completion-signoff-tickets.md`; it needs a wording decision, not a rename.

## 1.1.23 (2026-09-22)

Phase 1 part five, and the last piece before the pilot: a VC's answer now becomes a
durable record and, when they say the work is finished, a real state change.

### Features
* **`VcDigestSubmitSubscriber` (P1-5).** Three jobs, in the order the spec names them: `recordAnswers()` always runs — even when both answers are No, because a project answered "not complete" six months running is the signal the digest exists to surface (Goal 8); `handleComplete()` advances the Project; `flagNoVcAsk()` records the intent and stops there.
* **The Completion request goes to the VC who ANSWERED, not to "a" coordinator.** The first version re-derived one with `ORDER BY near_contact_id ASC LIMIT 1` — the lowest contact id. On the **4 Active projects with two current coordinators** that is arbitrary, and the consequence is worse than a misdirected email: the Completion template carries `{form.afformProjectCloseVCFeedbackLink}`, which mints a **checksum link for the recipient**. So VC B answers "this is finished", VC A receives an authenticated close-form link for work they never reported, B hears nothing, and the 30/90/150 chase then chases the wrong person. The correct value was already in hand — the token-authenticated submitter, whom the entitlement guard had *already* verified coordinates this case.
* **D7 is the load-bearing one: the Project advances by SENDING the Completion template, never by writing `status_id`.** `ProjectLifecycleStatusSubscriber` already maps template → status and is the only code path into the awaiting-form statuses. A second writer here would drift from it, and the 30/90/150 chase that arms off the status would arm off only one of them. Sending gets the status change, the chase and the case-timeline entry as one consequence.
* **The office work item is deliberately NOT created here.** D8 fires on *(no VC ask)* **and** *(signoff returned with no donation)*; a VC who declines to ask is not yet a problem. Queuing on the declined answer alone would generate work items for clients who are about to donate anyway.

### `vc_will_ask` is normalised server-side, and it has to be
* **NULL whenever the work is not complete**, because the form hides the second question — and that is a different fact from an answered No. D8 queues the office's donation follow-up off the distinction.
* **The browser cannot be trusted with it.** Core does not strip conditionally-hidden fields on submit (`AbstractProcessor::getSubmittableFields()` carries the TODO), and the only thing clearing a hidden value today is JavaScript. A crafted or replayed submit has none, so `is_complete = false` **with** `vc_will_ask = true` is reachable — a state P1-1's data model declares impossible, which would manufacture a work item about a client nobody was ever asked about. Carried from PR #39's review as a P1-5 done-when.
* Normalised **before** the write (priority > 0), not repaired after. A repair-after-write leaves a window in which the impossible state is real and any post-write hook sees it.

### Idempotency by case status, not by counting sends
* The spec requires that re-submitting the same link must not send the Completion email twice. `LifecycleMailer`'s own duplicate guard is a **23-hour** window — enough for a double-click, not for a VC who answers twice in a week, and not for the **4 projects that have two coordinators** and could be answered by both.
* So the check asks the question that matters: **has this project already moved on?** If it is no longer in an advanceable status, the Completion request has been sent, or the form has already come back, or the project has closed — and in all three cases a second email is noise to a volunteer.

### Two rules extracted so CI can hold them
* **`Civi\Mascode\Digest\CheckinAnswer`** is free of every CiviCRM dependency, like `AfformArgPolicy` and `DigestRowRenderer`. `VcDigestSubmitSubscriber` extends `AutoSubscriber` and cannot load in CI, so a rule left inside it can only be asserted over source text — and the first draft of this release did exactly that, including one "test" that did nothing but `markTestSkipped`. Both rules now have behavioural tests.
* **`isTrue()` is strict on purpose.** It decides whether an email goes to a volunteer. A `(bool)` cast gets it wrong in both directions: the string `'0'` is falsy but the string `'false'` is **truthy**, and CiviCRM round-trips booleans as `'1'`/`'0'` strings often enough that a loose test is a coin flip. Anything not unambiguously true is treated as not-complete — the safe direction, since the VC is simply asked again next month.

### Tests
* `CheckinAnswerTest` — 19 cases across two data providers, covering `'0'` vs `'false'` and every shape of the NULL rule.
* `DigestSubmitWiringTest` — the priorities (normalise before the write, advance after), D7 (no `status_id` write), the `is_current` coordinator lookup, and the duplicated `ADVANCEABLE_FROM` list asserted **against `ProjectLifecycleStatusSubscriber`'s own from-list**, since that constant is private and a divergence would mean a check-in that should advance silently does not.
* **`normaliseRecords()` is extracted too**, so APPLYING the rule is behavioural rather than merely computing it. Review demonstrated the ordinary refactor slip — the rule called, the result computed, and never written back — passing every source assertion, while the subscriber still logged "Cleared vc_will_ask" on a submission where it had cleared nothing. Source-text tests cannot see that; the repo's answer has twice been to move the logic somewhere they can, rather than to concede the gap.
* **`shouldAdvance()` is extracted as well, but it closes less than an earlier draft of these notes claimed.** It pins the predicate's *sense* — inverting it is now caught behaviourally — and it does **not** close the "present but inert" case, because what that removes is the `return;` at the *call site*, which stays in the subscriber. Review re-measured it still green. The early return remains source-only, and saying otherwise would have put an overclaim in the one paragraph of this release that is about overclaiming.
* **Fifteen mutations checked, each goes red:** loosen the truth test; move the normalisation after the write; revert the coordinator lookup to `is_active`; gut `onBeforeSave()`; write `status_id` (in either quote style); delete the re-send guard; delete the `digest_round` write; send to an arbitrary coordinator instead of the answering VC; and two on the fragment rules.
* **Two of an earlier five did NOT hold, and review measured it.** Replacing `onBeforeSave()`'s entire body with `return;` left the suite unmoved — the ticket's headline done-when, with nothing asserting the subscriber ever calls the rule or writes the result back. And the `status_id` guard only matched the quote style I had typed. Two further guards had no test at all: deleting the re-send check and deleting the `digest_round` write both passed. The pattern each time is the same — the predicate was pinned and the **wiring** was not, which is precisely what the immediately preceding commit had been written to fix for the sibling guard.
* Unit suite **195 tests / 690 assertions** green.

### Deploying this release
* `HOME=/home/mas/tmp cv upgrade:db` then `HOME=/home/mas/tmp cv flush`. No upgrade step.
* **This is the last piece before the pilot (P1-6), and the pilot is gated on two people, not on code:** Nina picks the pilot VCs, and Steve signs off how hard the digest copy asks. Neither is a deploy blocker; both are send blockers.

## 1.1.22 (2026-09-22)

Phase 1 part four: the digest can now actually send. **It is still hand-invoked** — the
scheduled Job is P2-1, behind the falsification gate — and `dryRun` still defaults to TRUE.

### Features
* **`VcDigestMailer` (P1-4)** — one email per VC, one minted check-in link per project, one *Sent Automated Email* activity per project so the digest appears in each case's timeline.
* **`{digest.project_rows}`, `{digest.project_count}`, `{digest.month}`** — so the body lives in a managed template rather than in PHP strings. That is not tidiness: the spec puts the digest wording with Nina and how hard the ask is with Steve, and the thing most likely to change after the pilot *is* the wording. Changing a sentence must not need a deploy.
* **A sibling of `LifecycleMailer`, not a change to it.** That service is case-scoped by contract and seven live CiviRules depend on it; a digest has one recipient and N cases.

### The check that matters more than the sending
* **A digest subject containing a lifecycle transition string would silently advance every project in it.** `ProjectLifecycleStatusSubscriber` fires on *Sent Automated Email* activities — exactly what this mailer writes, one per project — and `matchTransition()` does `str_contains($activitySubject, $prefix)` against the static part of each transition template's subject. Those prefixes are currently the bare strings **"Project Completion"** and **"MAS Project Signoff"**. On the 2026-09-21 clone that is **132 projects** moved to an awaiting-form status, chases armed, Ops dashboard filled, with no error and a perfectly normal-looking email. The only symptom would be a status change nobody made.
* `assertSubjectCannotTriggerATransition()` refuses to send, checking **both** the email subject and the per-project activity subject.
* **The prefixes AND the template list both come from the transition's owner**, `ProjectLifecycleStatusSubscriber`, which gained two public accessors for the purpose. The first version of this got it half right and said so confidently: the subject *prefixes* were read live, but the set of `msg_title`s was a hard-coded copy of a `private const` two directories away. A **`msg_title` rename is exactly what broke the client transition on production in September** — so the copy would have gone on guarding a title nobody uses, while the subscriber armed on one this guard had never heard of. Adding a fourth transition would have done the same, silently. There is now one owner of the list and one implementation of the prefix computation.
* **A digest is never sent twice to the same VC in the same round** — keyed on the VC **and** the round, which the first version of this guard got wrong in a way worth recording. It took `$vcContactId` and never used it, so the question it asked was *"has anyone been mailed about any of these cases this round"*. For a project with two coordinators that is catastrophic and silent: `deliver()` walks VCs in ascending contact id, the lower id marks the shared case, and the higher id then matches the **other** VC's marker and is skipped **entirely** — every project they hold — while being counted under `vcs_skipped_already_sent`, which reads as correct behaviour. **Measured on the clone: 3 of 62 VCs would have received nothing, every month, deterministically.** That is the "a wrong answer silently drops a VC" failure `VcDigestRunner` is explicitly built against, reintroduced in the delivery step.
* **The contact-id fragment carries a closing brace**, because `"recipient_contact_id":763` is a LIKE-prefix of `…:7634` and both ids exist on the clone. Without it, contact 763 is treated as already-mailed because 7634 was.
* **A re-run will not pick up newly-eligible projects within the same round.** A VC marked in the first run is skipped wholesale, so a project that became eligible afterwards waits for next month. That is the intended trade — duplicate email is unrecoverable, a month's delay is not — but it is worth knowing before relying on a re-run.
* **A digest is never sent twice to the same VC in the same round.** The spec puts the month-level guard on P2-1's Job, and its reasoning — *"62 volunteers getting a duplicate is not recoverable"* — applies to this hand-invoked path first, because this is the path the pilot uses. Worse, `deliver()` reports per-VC failures, which makes re-running the natural response and would have re-sent to everyone who succeeded. Keyed on (case, round) via the marker this release already writes. **ANY of the VC's projects, not all**: a run that died mid-loop leaves the VC holding the email, so under-sending is recoverable and over-sending is not.
* **A failed activity write after a successful send is no longer reported as a failed send** — which invited exactly that re-run. Caught per project, reported as `activity_errors`, distinct from `errors`.
* It is a **substring** match, not a prefix match, so "our Project Completion process" is as fatal as a subject that starts with it. The tests assert that property directly, because it is the part people get wrong.

### Two extractions, both for testability rather than tidiness
* **`VcDigestMailer::subjectTriggersTransition()` is a pure function.** The consequence of getting the rule wrong is 132 projects moving; its input is an API4 call CI cannot make. A rule that expensive should not be untestable because of where its inputs come from.
* **`Civi\Mascode\Digest\DigestRowRenderer` is free of every CiviCRM dependency**, for the same stated reason as `AfformArgPolicy`: `VcDigestTokenSubscriber` extends `AutoSubscriber`, so anything left inside it cannot load in CI and is untestable by construction. The rows carry the escaping of client-entered case subjects into an email and the one minted link per project — both worth a test.

### Sending, and what happens when one fails
* **Per VC, with per-VC error capture.** A run is 61 recipients today. If the fourth throws, the other 57 must still be asked and the office must be able to see which one broke, so each send is caught and recorded in `errors` and the run reports what it managed rather than what it attempted.
* An **unmailable** VC is skipped rather than attempted and caught — it is already known and already reported, and attempting it would turn a known data problem into an error line that looks like a fault.
* `vcs_mailed` exists **only** on the send path. A dry run has no such key rather than a zero, because a zero reads as "ran and sent nothing".
* Rows are tables with inline attributes, not styled `<div>`s — Outlook on Windows renders through the Word engine, and MAS's sector runs on Microsoft 365 (the reasoning CHANGELOG 1.1.18 records for the donate button).

### Tests
* `VcDigestSubjectSafetyTest` — **14 tests**, and a new `tests/Live/VcDigestIdempotencyTest.php`. **Ten mutations checked, each goes red:** put a transition string in the activity subject; put one in the template subject; make the match prefix-only; let an empty prefix match everything; drop the escaping of a client-entered case subject; make the guard a no-op; delete either call site; hard-code the template list again; remove the idempotency check; let an activity-write failure masquerade as a failed send.
* **The assertions were then found to be greps for the literal strings I had typed, not properties.** Review measured six of seven fresh mutations surviving — and, worse, **all five of them passed while the idempotency check was ignoring its `$vcContactId` argument**: the test written to protect that check certified the bug as correct. `markerFragmentsFor()` was extracted as a pure function so the key can be asserted as a property instead, and that assertion is red on the shipped bug.
* **One test had been locking a defect in place.** It asserted the prefix computation appeared *exactly twice*, so unifying the two copies — the correct fix — turned the suite red. The rule now exists once and the assertion says one.
* **Five of those ten were added because review broke the guard four ways with the suite green.** Every mutation the first version checked exercised the pure predicate; nothing exercised the *call site* or the *list it is handed* — on the highest-consequence guard in this feature. That is the sixth guard in this epic found asserting less than it claimed, and the pattern is recorded in `docs/plans/completion-signoff-tickets.md`.
* A project with no `start_date` omits the "started" clause rather than printing a gap — the same judgement as the empty-report block on the Signoff form.
* Unit suite **165 tests / 621 assertions** green.
* **A source assertion cannot see reachability, and review proved it on this code.** Replacing `alreadySentThisRound()`'s body with an early `return false;` left the query present as dead code, so every source assertion passed — including one of mine that looked for the query in the whole class rather than in that method. `tests/Live/VcDigestIdempotencyTest.php` closes it against a real database: seven assertions inside a transaction that is always rolled back, sending no email. **It catches both mutations the unit tests could not** — the early return, and applying only the first marker fragment, which is byte-for-byte the Critical defect review found.

### Deploying this release
* `HOME=/home/mas/tmp cv upgrade:db` then `HOME=/home/mas/tmp cv flush`. The digest template is a managed entity and must reconcile before anything can send.
* **Nothing sends on its own.** There is no Job; `dryRun` defaults to TRUE; a real send needs an explicit `{"dryRun":0}` and, for the pilot, `pilotVcIds`.
* **Verify the re-send guard ON THE TARGET before the first real send** — it is the only thing standing between a failed run and 61 volunteers receiving a duplicate, and nothing else checks it there:
  ```
  HOME=/home/mas/tmp cv scr wp-content/uploads/civicrm/ext/mascode/tests/Live/VcDigestIdempotencyTest.php --user=<a login with a uf_match row>
  ```
  Seven assertions, inside a transaction that is always rolled back, sending no email. Its last assertion checks the rollback itself, so a silently-failed rollback goes red rather than leaving data behind. **An earlier draft of these notes asserted "a re-run is safe" while naming this script only in the Tests section, so nobody was told to run the one thing that proves it.**
* **A re-run is safe, and that is deliberate rather than incidental.** A VC who already received this round is skipped and counted under `vcs_skipped_already_sent`, so the response to "3 VCs failed" is simply to run the same command again. Check `errors` (the send failed) apart from `activity_errors` (the send succeeded, a case-timeline entry did not).
* **Before the first real send, confirm the subject guard on the target**, because it reads production's template subjects rather than dev's:
  `HOME=/home/mas/tmp cv api4 Mascode.runVcDigest '{"dryRun":1}'` should plan cleanly, and any subject collision throws at send time with the offending prefix named.

## 1.1.21 (2026-09-22)

Phase 1 part three: the digest's selection and grouping, and the manual entry point the
spec requires to exist before the scheduled Job does. **Nothing sends email yet** — a
non-dry run throws rather than returning a tidy summary having sent nothing.

### Features
* **`VcDigestRunner` (P1-3)** — which projects the monthly digest asks about (D1, D2), grouped by the volunteer who would be asked (D3), with the pilot restriction (D12).
* **`Mascode.runVcDigest`** — a new non-DAO API4 entity so the operation is reachable the way every other CiviCRM operation is: `cv api4 Mascode.runVcDigest '{"dryRun":1}'`, and later a `Job` row. The spec requires the manual path to exist *before* the Job (§Triggers); P2-1's Job will be a thin wrapper, behind the falsification gate.
* `dryRun` defaults to **TRUE**, and that default is the safety: a caller who forgets the parameter gets a plan, not 62 emails.

### The rule that is applied in PHP on purpose
* **D2's 30-day suppression is NOT a `WHERE` clause, and must not become one.** `start_date` is nullable on `civicrm_case`, and SQL's `start_date <= '…'` is NULL — not TRUE — for a NULL row, so the obvious simplification **silently drops every project whose start date nobody recorded**. That is the one direction the spec says this code must never fail in ("where a wrong answer silently drops a VC"). The 2026-09-21 clone has zero such rows, so the trap is **latent**: every test would stay green while someone introduced it. Guarded by a test that builds the rows the database does not have.

### Two things the spec does not decide, surfaced rather than chosen quietly
* **4 Active projects have two active coordinators.** Both are asked, because the alternative is picking one and silently not asking the other; choosing would need a rule saying *which*, and nobody has written one. Reported in the run summary so the office sees it rather than hearing it from a confused volunteer. P1-5's `handleComplete()` is required to be idempotent regardless, so the second answer records an activity without sending a second Completion email.
* **2 Active projects have no coordinator at all** — reported, never dropped (Goal 9). (Two, not one: `is_current` moved a project whose coordinator role had ended out of that VC's digest and into this report, which is where it belongs.)

### `is_current`, not `is_active` — the same bug review found in P1-2
* The grouping first tested `is_active`, which stays TRUE on an **ended** case role. On the 2026-09-21 clone **299 of 481** active coordinator rows that carry a case are ended — 62%, some since March 2025. For the digest the consequence is a wrong email rather than a leak — a volunteer asked to confirm a project they handed over months ago — and it *hides* the real problem, because that project then never appears in the coordinator-less report the office works from.
* Under `is_current`, exactly one project moves out of a VC's digest and into that report, which is where a project whose coordinator has left belongs.
* ⚠ **This moves a number P2-3 asserts on.** That ticket says the no-VC row should show "**1** project, not 8". Under `is_current`, today's answer is **2**.

### Two counts, because one of them would have been a lie
* A project with two coordinators appears in two digests, so summing the per-VC lists gives more rows than there are projects — 136 rows against 132 distinct projects on the clone. That reads as a selection bug to anyone checking the arithmetic. `projects_included` is therefore **distinct projects** and `digest_rows` is **lines that will be sent**; they differ by exactly the multi-coordinator count.
* There is no `vcs_mailed` and no `errors` key. Nothing here sends or partially fails, so both would be structurally empty on every run — a field that always reports success-with-nothing-done. P1-4 adds them when there is something to put in them.

### Dry run against the 2026-09-21 dev clone
61 VCs, 132 distinct projects, 136 digest rows, 4 suppressed by D2, 2 coordinator-less, 4 multi-coordinator, 0 unmailable. A pilot naming a contact who coordinates nothing now returns a clean zero summary (and still reports the 2 coordinator-less projects) rather than fatalling. The heaviest VC has 10 projects — matching the spec's independent production reading of 2026-09-17, which is the cross-check that the predicate is selecting the right population.

### Tests
* `VcDigestRunnerTest` — **27 tests**. The selection and grouping rules are separated from the API4 calls that feed them precisely so CI can run them; a rule left inside a method that issues a query is a rule with no test.
* **Thirteen mutations checked across three rounds, each goes red:** treat a NULL `start_date` as suppressed (the SQL-`WHERE` simplification); make the cutoff exclusive; drop coordinator-less projects instead of reporting them; ask only the first coordinator; let an unreadable `pilot_vc_ids` degrade to "all VCs"; revert the predicate to `is_active`; rename the emitted `case_id` key; remove the duplicate-coordinator collapse; break each of the four D1 query filters; restore the crashing `?: [[]]` expression; key the distinct count on `subject` or on `start_date`; drop the integer-overflow round-trip; remove either half of the trashed-coordinator clause.
* **The pilot parser was attacked with 46 inputs in review — zero false refusals, zero leaks.** `'000000012'`, `'010'`, `'1010'`, `" 12,\n34"` and `PHP_INT_MAX` all accepted; `'0'`, `'00'`, `'+12'`, `'12.9'`, `'1e3'`, `'0x1A'`, Arabic-Indic digits and both overflow forms all refused.
* Unit suite **151 tests / 568 assertions** green on this branch (124/511 on `master` — the 27 tests and 57 assertions added here).
* **A crash the tests could not see, because `run()` has no unit test.** The distinct-project count was an inline expression carrying an `array_values($byVc) ?: [[]]` "guard" that *was* the bug: an empty `$byVc` iterated once with `$vc = []`, and `array_column()` fatalled on NULL. It took down the **D12 pilot path** — the spec's mandatory pre-send step — and a month with **no eligible projects**, which is this feature succeeding and precisely the case the run summary exists to distinguish from a job that never ran. The count is now a pure `countDistinctProjects()` so the empty case is one assertion rather than something only a live invocation can find.
* **The pilot list now refuses a PARTLY readable value, not just a wholly unreadable one.** Measured before the fix: `'1,abc'` → `[1]` silently dropped a chosen VC, and `'12.9'` → `[12]` silently substituted a *different* one. The second is a misdelivery rather than an omission, and both are this class's own stated failure — silently dropping a VC — moved from selection into delivery.
* **Two tests were vacuous and one contract was untested.** The duplicate-coordinator test passed an already-deduplicated fixture, so removing the collapse left the suite green; the fixtures used one row shape throughout, so renaming the emitted `case_id` key — which `countDistinctProjects()` reads — also left it green. Raw and post-suppression fixtures are now distinct types, and the collapse is asserted where it actually lives.
* **The four D1 query filters have a source-level assertion.** They sit inside an API4 call that CI cannot reach, and review measured all four mutating freely with the suite green — including `is_deleted`, which on this data is the difference between 146 and 138 Active project cases. A weak test of a strong fact beats no test.

### Deploying this release
* `HOME=/home/mas/tmp cv upgrade:db` then `HOME=/home/mas/tmp cv flush`. No upgrade step.
* **Run the dry run on production before anything else is built on it**, because dev is a clone and the population is the whole point: `HOME=/home/mas/tmp cv api4 Mascode.runVcDigest '{"dryRun":1}'`. Compare `projects_included`, `projects_without_vc` and `projects_with_multiple_vcs` against the figures above; a large divergence means the predicate is selecting a different population than it did here, not that production is busier.

## 1.1.20 (2026-09-22)

Phase 1 of the VC monthly donation digest, part two: the form a VC answers, and the guard
that decides whether they may. Still nothing sends email — the digest that mints these
links is P1-3/P1-4.

### Features
* **`afformMASProjectCheckin` (P1-2).** One project, two questions: is the work complete, and — only if it is — will you ask this client about a donation yourself. Public, reached from a tokenised link with no login, at `civicrm/mas-checkin`. It shows the MAS project code, subject and start date read-only, so a VC with ten of these can tell which one they are answering.
* The second question is wrapped in an `af-if` on the first, so a VC who answers "not complete" is never asked it. That is what makes `vc_will_ask` NULL rather than FALSE in that case, which P1-1 explains is a distinction the office follow-up depends on.
* **The activity's `subject` and `digest_round` are deliberately NOT on the form.** Both are composed server-side in P1-5. A hidden field is a value the client controls, and `digest_round` is the key the Phase 2 idempotency guard will match on — not something to accept from a browser.

### The security finding: a signed token is not the same as a current one
* **D10 says the form must re-verify the case "rather than trusting the id in the URL", which reads as a tampering concern. Tampering is not the exposure.** The id travels inside a signed JWT; a forged one fails its signature. The exposure is **staleness**: the digest mints one link per (VC, project) in bulk, the TTL follows `checksum_timeout` — 60 days here, deliberately longer than the monthly cadence (D11) — and case roles change inside that window. A signature attests to what was true when it was signed and to nothing else, so **a minted link outlives the entitlement it was minted under**.
* **The existing `AfformPublicArgGuardSubscriber` cannot cover this**, and correctly says so in its own docblock: it filters caller-supplied args on `civi.api.prepare`, and core copies the token's `afformArgs` in later, inside `AbstractProcessor::_run()`. That is the right design for the seven Phase 0 forms, whose tokens are minted per lifecycle event for one case and answered within days.
* **Measured rather than argued.** With the new guard disabled, on dev, as a real non-staff VC: a token-supplied `case_id` for a project that VC does **not** coordinate **returned the case**, and a submit against it **was allowed** — a check-in filed on someone else's project. The caller-supplied form of the same request stayed blocked throughout, which is the cleanest statement of what each guard covers.
* **`CheckinCaseEntitlementSubscriber` (new)** re-derives entitlement on both prefill and submit: the visitor must be a **current** Case Coordinator of that case, or staff. A refused read drops the id and the fieldset renders blank; a refused **write throws**, because dropping it would file the answer against nothing behind the normal confirmation screen — an answer the VC believes they gave and that exists nowhere.
* **It is narrower than the guard it sits beside, on purpose.** That one also entitles any case in the Sent-for-Assignment pool, so a VC can read a case they might pick up. A check-in asserts something about a project the VC *ran*, and a pooled case has no coordinator to be.
* **Its staff list is duplicated rather than shared, and that is the uncomfortable call.** Production carries an uncommitted hand-patch in both `AfformPublicArgGuardSubscriber.php` and `Security/AfformArgPolicy.php`. Hoisting a shared constant into `AfformArgPolicy` is the tidier refactor and would conflict mid-`git pull` on a live site. The duplication is the cheaper of the two costs; revisit when that patch is reconciled.

### `is_current`, not `is_active` — the fix that made the guard actually close the hole
* **Review caught the guard asking the wrong question, and it was the question the feature is named after.** The first version tested `is_active` on the coordinator relationship, copying the two predicates it otherwise mirrors (`AfformPublicArgGuardSubscriber::isCaseEntitled()` and `SavedSearch_Case_Details_VC.mgd.php`). `is_active` is a flag somebody sets; `is_current` is core's `is_active = 1 AND (start_date <= today OR IS NULL) AND (end_date >= today OR IS NULL)`.
* **They come apart constantly, because there are two ways to end a case role and only one clears the flag.** Ending it through the case-roles UI (`CRM_Case_BAO_Case::endCaseRole()`) sets both `is_active = 0` and `end_date`. Setting an end date on the Relationships tab, an import, a bulk fix, or the *Disable expired relationships* job not having run, leaves `is_active = 1`.
* **Measured on the 2026-09-21 dev clone:** of 481 `Case Coordinator is` rows that carry a case and have `is_active = TRUE`, **299 are ended** — 62%, `end_date` in the past, some back to March 2025. A guard written to stop a link outliving its role would have admitted every one.
* **A figure in the first draft of these notes was wrong by 10×, in the flattering direction, and review caught it.** It said 31 of those sit on cases that are not closed. `Project Created` — the 31-case bucket that number came from — carries `grouping = Closed` in `civicrm_case_status`, as do `Completed`, `Cancelled` and the rest; filtering to statuses actually grouped `Opened` gives **1** case today. The correction does not weaken the reason for the predicate: what the 299 demonstrate is that ending a role *without* clearing `is_active` is the normal case, and the guard protects a 60-day token window against a role ending at any point inside it — a future state, not today's snapshot.
* **The divergence from its two neighbours is now the point, not drift.** They decide what the VC Portal *displays* to a logged-in volunteer; this decides whether a public, no-login form hands over a case and accepts a write against it. They should probably all move to `is_current` — **recorded for Brian, not done here**, because both those files carry an uncommitted production hand-patch and a deploy touching either conflicts mid-`git pull` on a live site.
* Asserted by a new live case: the test ends the running VC's own role *without* clearing `is_active` — the state those 299 rows are in — inside a transaction that is always rolled back, then asserts refusal. Reverting the predicate to `is_active` fails it with a real leak.

### What the submit hook actually contributes, stated honestly
* **`Afform.submit` runs `loadEntities()` too**, so the prefill hook has already stripped an unentitled `case_id` before the submit hook fires. In the real stale-link flow the write is therefore stopped by the **read** hook, and the submit refusal comes from the no-case branch rather than from the entitlement test. The submit hook is defence in depth — what stops a write if the read path is ever bypassed, reordered or disabled — and the first version of these notes implied it was doing the work. Its log line now says so, because an operator reading "no case" would otherwise chase a broken form.

### The `af-if` was verified through core, not through a browser
* 1.1.18 recorded that a hand-written `af-if` renders correctly while core's PHP parser, required-field validation and FormBuilder all disagree. So this one was checked by running core's own `FormDataModel` over the saved layout and core's `checkAfformConditional()` over the parsed result — and by diffing its parsed shape against the known-good conditions on `afformProjectCloseClientFeedback`.
* Core iterates `af-if` as a **list of conditionals**, passing each to `checkAfformConditional()` — worth stating because reading it as a single conditional produces a `TypeError` that looks like a malformed condition and is not.
* Evaluated across five shapes: `true` → shown; `false`, `'0'`, and absent → hidden; `'1'` → shown. The string cases matter given the `feedback_afform_boolean_string_id_bug` trap, and they behave.
* The condition is confined to the wrapped block: `is_complete` carries **no** condition at all, which is the sibling-accumulation problem 1.1.18 had to fix by nesting.

### Tests
* **`CheckinEntitlementTest` (new, `cv scr`)** asserts the live half as a real non-staff VC, and **aborts rather than passing vacuously** when run as staff, because the guard exempts staff. It simulates the token by seeding `authx` on the session — which is exactly where core reads it from — rather than minting a JWT, so it exercises the same injection point without testing core's crypto.
* Its submit assertion runs **inside a transaction that is always rolled back**, so a guard that wrongly allows the write cannot leave a real check-in activity on a real case.
* It also asserts the ordering rule that a token id **wins over** a caller-supplied id for a different case, since core's copy loop overwrites caller args with token args for the same key.
* **The guard was disabled and the suite re-run to prove the assertions are not vacuous** — two went red, and they are the two that matter. This is the check that distinguishes a guard from a comment.
* **`CheckinEntitlementWiringTest` (new, CI)** pins the two priority relationships **against the neighbours by name**, not against the literal 500: below `AfformTokenPrefillSubscriber` (read from its own file, so lowering that one fails here), above core's autofill behaviors, above core's writer. It also asserts the form declares no second door for caller-supplied ids (`url-autofill`, an `autofill` attribute on an id field) — the rule `ang/README.md` states for the other public forms.
* Unit suite **123 tests / 505 assertions** green.
* **The live security test discovered its fixtures with `is_active` while the guard now used `is_current`** — so on real data it would have failed against a *correct* guard for **70%** of candidate VC logins (measured: 16 of 23 runnable non-staff VCs), on a script the deploy notes tell an operator to run on production. A security check that is red most of the time gets switched off, and then the predicate has nothing holding it. Both the discovery query and the docblock's find-a-login recipe now use `is_current`, and the ended-role fixture requires a role that is genuinely **current** before ending it — otherwise the "end it" step is a no-op and the assertion demonstrates nothing. Verified green across three different VC logins, including one from the previously-failing group.
* **The anonymous probe now covers this form, and did not before.** `tests/Security/afform-prefill-anon-probe.sh` hardcodes the form list, so adding an eighth `*always allow*` form left the repo's own detector for this exact vulnerability class (task #159) blind to it — while the run still reported OK. Now **56 probes**, nothing returned. The list carries a note to enumerate the guarded set on the target rather than trusting the file.
* **Three sentences in `ang/README.md` still said "seven client-facing forms"**, including the one that *defines the guarded set* and the one that justifies refusing every non-`form` fill mode. Corrected, with a note that the count is never the authority — `AfformArgPolicy::isGuardedForm()` tests the `permission` field per request, so a stale number misleads people, not code. `AfformArgPolicy.php` and `AfformPublicArgGuardSubscriber.php` are **knowingly left stale**: production's uncommitted hand-patch is in both.
* `AfformPublicArgGuardTest` still passes 10 assertions as a real non-staff VC.

### Deploying this release
* `HOME=/home/mas/tmp cv upgrade:db` then `HOME=/home/mas/tmp cv flush`. The Afform scanner discovers packaged forms, so the form itself needs no reconcile — but P1-1's managed entities do, and the check-in form's fields will render as raw names until they exist.
* **Re-enumerate the guarded set on production after deploying**, per `ang/README.md`: the new form is `*always allow*` and must appear.
  `HOME=/home/mas/tmp cv api4 Afform.get '{"select":["name","permission"],"where":[["permission","CONTAINS","*always allow*"]]}'`
* **Run the new entitlement check on production as a non-staff VC.** A dev pass is weaker than a prod pass for the entitlement half, because dev contributors lack permissions production contributors hold — `ang/README.md` records why.

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
* **The ordering was deterministic and against us.** The `mgd-php@1` mixin — the version `info.xml` declares — collects every `*.mgd.php` under the extension, `sort()`s the full paths and appends each file's array in order; `ManagedEntities::reconcileEntities()` walks the `create` plan in that same order. Declared under this directory's own one-entity-per-file convention the files were `CustomGroup_…` and `OptionValue_ActivityType_…` — **"C" sorts before "O"** — so on every clean environment the group was created before the option value existed. Reproduced on dev.
* **A bad create is PERMANENT, and an earlier draft of these notes said the opposite.** `ManagedEntities::optimizePlan()` drops every `update` item whose stored checksum still matches the declaration's, and hand-breaking a *record* does not change the *declaration's* checksum. The exceptions are upgrade mode, an active install/enable process, and a changed declaration — and **`cv flush` is none of them**. Measured on dev both ways: clear the column, `cv flush`, still NULL; `cv upgrade:db`, restored. So the remedy is `cv upgrade:db`, and a flush would have been a reassuring no-op.
* **The wrong claim came from a bad experiment, not a bad reading** — recorded because the experiment looked conclusive. `CustomGroup::update()->addValue('extends_entity_column_value', NULL)` reports success and stamps `entity_modified_date` while leaving the column **unchanged**, so the "reset" never happened and the flush that followed had nothing to heal. Caught in review, which is the second time this file has had to record a "verified against core" claim that was not — see the 1.1.16 correction. Clearing that column has to be done at the column to be real.
* **It would still have surfaced on production and nowhere else**, because only a *clean* environment creates these records for the first time. Whether the deploy ritual's `cv upgrade:db` leg would then have covered it up is **not verified** — a create is not an `update`, so `optimizePlan()` never sees it, and covering it up would need a second reconcile inside the same invocation. Stated as the open question it is, rather than as the reassurance the first draft offered.
* **Fixed by declaring both in one array, option value first** (`Civi/Mascode/Managed/ActivityType_MonthlyProjectCheckin.mgd.php`), which removes the ordering question rather than answering it. Verified the way it had to be: delete both records *and* their `civicrm_managed` rows, `cv flush` **once**, confirm the column is populated.
* **`extends` must stay in the same `values` array as the scoping — on the CREATE path.** Core's option loader needs `extends`, or an `id`/`name` it can look `extends` up from, to know which option list to search. On the managed *update* path a `name` is present and core injects the `id`, so omitting `extends` there would still resolve; **create** is what breaks, because the row does not exist yet and both fallbacks miss. Create is the only case that matters, since a bad create is permanent.
* **Two pre-existing groups use the same idiom and are not fixed here** — `Project_Definition_Fields` and `Project_Definition_Client_Fields`. They read back correctly **only because their values predate the declarations**, so those `:name` lines have never had to resolve anything. They are correct on every environment that exists; a fresh install is the only thing that would expose them. Recorded in `docs/plans/completion-signoff-tickets.md` rather than fixed, because the gap predates this work.

### Tests
* **`MonthlyCheckinDeclarationTest` (new)** guards the six things that make the scoping work: the option value is declared before the group; it is an **active** value in the **activity_type** group; nothing else declares either record (so no filename sort can separate them again); **a declaration inside a dot-directory is ignored, as core ignores it**; the group carries `extends` alongside its `:name` scoping; and the two answers are real Booleans with `vc_will_ask` nullable.
* It `include`s the `.mgd.php` rather than parsing it as text — the file is a bare `return [...]` with no Civi calls, so it loads in CI where there is no CiviCRM. That is stronger than the text-parsing approach `LifecycleTransitionTemplateWiringTest` is forced into by its subject extending an `AutoSubscriber`.
* **Two assertions added in review, closing the mutation nearest the bug.** The first version asserted nothing about the option value's `option_group_id.name` or its `is_active` — so moving the declaration to a different option group left all four tests **green** while the custom group's `:name` scoping resolved against a list the value was not in and landed NULL, which is precisely the defect the file exists to prevent. A disabled value fails the same way, because core resolves the list with `$includeDisabled = FALSE`.
* **The file-split guard was both too tight and too loose, and is now neither.** It compared raw file contents, so a future `.mgd.php` that merely *mentions* the activity type would have gone red — and one is planned, since P2-3's Ops rows are naturally a SavedSearch filtering `activity_type_id:name` on this exact string. It now matches the managed **record** names, in either quote style, over comment-stripped source.
* **And the widened walk had to be widened again**, because the first fix mirrored `mgd-php@2` while `info.xml` declares **`mgd-php@1`** — so it was *narrower than what core actually loads*, which is the one thing this guard must never be. v1 recursively scans the whole extension tree. Verified **set-identical** to core's own `CRM_Utils_File::findFiles()` on the real tree, not merely equal in count.
* **The dot-directory exclusion is now guarded by a fixture rather than by a coincidence.** It was justified by "the `.claude/worktrees/` copy would otherwise be flagged" — and review measured that the worktree present at the time contains neither record name, so **deleting the exclusion entirely left every test green**. An exclusion asserted by nothing is the same shape as the option-group gap this file was extended to close. There is now a test that builds a dot-directory holding a duplicate declaration; removing the pruning fails it.
* **Three deliberate divergences from v1 are written down rather than left to be found**, since "mirrors" was doing a lot of work: directory symlinks are not followed (core follows them — the unsafe direction, accepted because matching core here inherits core's own symlink-cycle hang, and this extension has none); dot-*files* are found here and skipped by core (safe direction); and `CIVICRM_EXCLUDE_DIRS_PATTERN`, which a site may define, is not honoured (verified undefined on dev, where CiviCRM ships it commented out; production was not checked, so this is the default rather than a fact about prod).
* **An unreadable directory is now skipped rather than fatal** (`CATCH_GET_CHILD`), as core does. This matters more since the walk covers the whole tree: the surface now includes `vendor/`, build output and whatever a deploy or worktree leaves behind.
* The guard test's own headline assertion was **vacuous** until review measured it: it compared an unnormalised needle (`EXTENSION_ROOT` carries a literal `../../..`) against a normalised haystack, so it could never match and passed with or without the pruning. The work was being done by a call to another test method. Both fixed — `realpath()` on the needle, and the offender collection extracted to a helper both tests assert on, so nobody removes the load-bearing line thinking it redundant.
* **Eleven mutations checked, each goes red:** drop `extends`; make `vc_will_ask` required; scope by numeric value instead of `:name`; move the option value to the end of the array; split it back into its own file; move it to a different option group; deactivate it; declare a duplicate in `ang/`, in `scripts/`, and with double quotes; and remove the dot-directory pruning. Plus one that must stay **green** and does — a sibling file mentioning the type only in comments.
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