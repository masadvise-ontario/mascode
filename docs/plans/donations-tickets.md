# Donations — Ticket Slice

**Status as of 2026-10-06 — the Treasurer accepted the process at the 2026-10-06 demo. DN-1…DN-5
plus the three pre-production requirements R1, R2 and R7 are built on branch `claude/donations-dn1`
(PR #76) and verified on dev.** Nothing is deployed to production yet: merging waits on the review
gate, deploying on Brian. R3–R9 are the follow-ups below. `git log` is authoritative for this
file; this line is the cheap check.

**Spec:** BrianPKM `3-Resources/mas-donation-process.md`. It holds the current process, what core
CiviCRM already does, the proposed design, Brian's answers to the open questions (2026-10-03), and
the items still **TBC** with the Treasurer. Read it from Claude Code desktop at `~/gdrive-brianpkm/...`,
or from any surface via the Klaus MCP `obsidian_read`. This slice is the machine-facing layer. The
spec wins where they disagree.

Origin: Klaus task 122, subtask "Donation process: vc, client, workshop, etc", and the 2026-10-01
donation-process call. The spec also absorbs Phase 4's "donation flow" from
`3-Resources/mas-engagement-lifecycle-automation-spec.md`.

**Your PR updates your ticket's status row here, in the same diff.** (See `completion-signoff-tickets.md`
for why that is a rule.)

## Decisions this slice builds on (from the spec, §4)

- Separate financial types: **Client Donation** and **Private Donation**. The **Member Dues**
  and **Campaign Contribution** types are disabled. There is never a "membership fee".
- **History is NOT re-typed.** Changing `financial_type_id` on an existing contribution makes CiviCRM
  write adjusting financial transactions, which would land in the Treasurer's QuickBooks exports.
  Reports therefore classify a legacy **Donation** by its donor's contact type: Organization → client,
  Individual → private. New entries use the new types.
- Cheques and CanadaHelps are entered **Pending**. The Treasurer uses **Record Payment** (date deposited
  plus fee). There is **no** "Date Deposited" custom field.
- VC notification: **yes**. Amount: **yes, in the email body only** (R2, 2026-10-06, reversing the
  2026-10-03 "no"). Never in the subject, which becomes a case-activity subject the VC Portal lists.
- **CAF Donation** is its own type, in totals but not counted as a donation (R7). The legacy
  **Donation** type is never renamed (1,473 historical gifts on prod) or disabled (core's edit form
  would blank their type); it is only hidden from new entries.
- The board sees **net**.
- CiviMember stays **off** (TBC). Membership is derived from donations, and the CSM enters the AGM dates.

## Tickets

| # | Ticket | Status | Done when |
|---|---|---|---|
| DN-1 | **Config.** `FinancialType` Client Donation and Private Donation (deductible). Disable Member Dues and Campaign Contribution. Custom group `Donation_Link` on Contribution: `Linked_Project` (EntityRef → Case, Projects only) and `Linked_VC` (EntityRef → Contact). | on dev, in review | `cv flush` on dev creates them. The contribution form shows both fields, and the project picker finds a project by its `Pxxxxx` code |
| DN-2 | **Contribution subscriber.** On create/edit with a Linked Project: fill **Linked VC** from the project's Case Coordinator (current, else most recent; fill-empty only). **Changed while building:** the core "Contribution" activity is *not* filed on the case, because its subject carries the amount and the VC Portal lists every case activity subject (spec §4 Q4). | on dev, in review | Saving a donation with only a project set yields the VC |
| DN-3 | **Notification fan-out.** Send `donation_notify__ed`, `__treasurer` and `__vc` once per contribution. The VC is notified only for a client donation with a Linked VC, and without the amount. Gated by setting `mascode_donation_notify_enabled` (default **off**). Recipients come from settings `mascode_donation_notify_ed_contact_id` and `…_treasurer_contact_id`. | on dev, in review | On dev, with the setting on, one save produces three MailHog messages and three "Sent Automated Email" activities. A re-save sends nothing |
| DN-4 | **Reports.** (a) SearchKit *MAS Donations* (Contributions menu, `civicrm/mas/donations`): one row per donation, with project code, client, VC, close date, received date, and gross/fee/net. (b) An API4 action `Mascode.donationQuarterly` plus the admin page `civicrm/mas/donations/quarterly`, reproducing the Treasurer's quarterly summary by **close quarter**, with CSV. | on dev, in review | The page shows per-quarter completed / with-donation / % / total / averages / rolling-4Q columns from dev data |
| DN-5 | **Backfill.** `upgrade_5019`: for existing Donations whose Source holds `P\d{5}`, set Linked Project (first code) and Linked VC (fill-empty). Log multi-code and unmatched rows for hand review. No financial fields touched. | on dev, in review | It runs twice on dev, and the second run changes nothing |
| DN-6 | **Current members.** AGM dates as an option group the CSM edits. The members list is Individuals with a Private (or legacy individual) Donation ≥ $50 dated after the second-most-recent past AGM. | not started | The list matches the Treasurer's member list |
| DN-7 | **CanadaHelps fee.** Optional convenience: a default fee of 3.75% when the payment method is CanadaHelps. **Verified on dev 2026-10-03:** Record Payment on a Pending contribution, with a fee and the deposit date, already sets the contribution's fee and net, flips it to Completed, keeps `receive_date`, and stores the deposit date as the payment's `trxn_date`. So the Treasurer's path needs no code. Still unverified: whether a fee prefilled at entry plus a fee at Record Payment double-counts. | not started | Gross, fee and net are correct after Record Payment |
| DN-8 | **Thank-you text.** Standard wording in the offline receipt template (spec D-F). | not started | The CSM ticks "Send receipt" and the donor gets the standard text |
| DN-9 | **Legacy history.** Load Linked Project for 2020–2022 donations from the Treasurer's *Projects – Details* sheet (`21-105` → `P21105`). **Needs the Treasurer's OK.** | not started | The quarterly report back to 2020 matches his 2022 workbook |
| DN-10 | **Abandoned Event Fee checkouts.** Clean up the Pending event-fee contributions (spec §4 Q6). This is a production data operation, so it needs per-change approval. | not started | No Pending Event Fee older than 30 days |

Not in this slice: expense donations (Q11: not needed). CAF handling (Q8) is R7; tax receipts (Q3) are R5.

## Treasurer demo requirements (spec §7, 2026-10-06)

| # | Requirement | Status | Notes |
|---|---|---|---|
| R1 | **Contact-first entry.** Project lists only the contributor's projects; Volunteer Consultant lists only that project's coordinators, filled in when there is one. | **built, on dev, in review** | `js/donation-contribution-form.js` puts the chosen contact/project into the autocomplete's `values`; `DonationSubscriber` turns them into a WHERE clause (not a filter: the typed input overwrites an `id` filter). Honoured only for users with `edit contributions`. Verified in a headless browser on dev. |
| R2 | **VC email shows the amount**, and for a split gift (same donor and cheque number, received within 31 days) the whole gift. Never in the subject. | **built, on dev, in review** | Subject has its own allowlist without `amount`/`split`; every notice's activity now keeps only a pointer. Limit: the FIRST part of a split gift is notified before the second exists, so only the later part's email mentions the split. |
| R3 | Monthly reconciliation list (Pending Client/Private/CAF, gross/fee/net, bulk-complete, no event fees) + DN-7 fee estimate | not started | Check core's "Update pending contribution status" task first. |
| R4 | Historical matching session with the Treasurer's workbook; validate the quarterly report against 2022; link the ~60 unlinked donations since 2024 | not started | Interactive with Brian. Supersedes DN-9. |
| R5 | Tax receipts for personal donations, `YY-NNN` restarting yearly, approved list before sending | not started | |
| R6 | Quarterly report: "completed" = Awaiting VC Completion Form, Awaiting Client Signoff Form or Completed, dated at the first of these; latest quarter provisional | not started | With R4. Also decide how CAF shows in the report's totals. |
| R7 | **CAF Donation** type + Community Action Foundation contact; legacy Donation not offered for new entries | **built, on dev, in review** | Contact name is from the demo transcript: confirm the spelling with the CSM (it is `update => unmodified`, so a UI correction sticks). |
| R8 | Automatic thank-you to the client | blocked | Brian to get the CSM's wording and exceptions. Overlaps DN-8. |
| R9 | Production recipients: ED = the ED's MAS address; Treasurer = the treasurer@ mailbox | at deploy | Settings `mascode_donation_notify_ed_contact_id` / `…_treasurer_contact_id` hold CONTACT ids: each address needs a contact with it as primary email. |

## Known limits to say out loud in the demo

- **Linked-donation history starts in 2025.** Only about 45 dev donations carry a `P` code in Source.
  Earlier quarters show zero donations until DN-9 runs. The quarterly page therefore defaults to 2025-01-01.
- **The close date currently tracks the donation date.** On at least five 2026 projects the CSM
  closed the case when the money arrived (spec §3d). Q9's answer (the client signoff closes the
  project, TBC) removes the effect going forward, but not in history.
- **Dev data is a clone from 2026-09-21.**
- **CAF Donation sends no notices** (it is not in `DONATION_TYPES`). Revisit if the Treasurer wants them.
- **23 client donations since 2025 have no project code in Source**, so the backfill could not link
  them. The quarterly page counts them. They are the CSM's clean-up list (filter *MAS Donations* by
  type, with no project).
- **One Source has a six-digit code** (a typo). It was reported, not guessed.
- **10 Completed projects on the dev clone have no end date.** The quarterly page lists them (they cannot be placed in a quarter) instead of dropping them.
- Cosmetic: the *Received* filter on *MAS Donations* still shows time pickers.

## Review record (PR #76)

Round 1, 2026-10-03:
- **General reviewer** (fresh-context general-purpose agent): no Critical or High findings; "mergeable for dev demo".
- **Adversarial reviewer** (fresh-context general-purpose agent): no Critical or High findings; one Medium.

All Mediums were fixed in round 2:
- the VC notice now goes for **client** donations only;
- the 90-day gate now requires **both** `created_date` and `receive_date` to be recent;
- the "sent" marker activity is written before the mail and removed if the send fails;
- Completed projects with no end date are listed instead of dropped;
- Cancelled projects count as not completed, and status classes come from `CaseStatusSet`;
- pure-function tests cover the VC rule, case filing, the amount guard, the coordinator choice and the rolling window.

Lows that were also fixed:
- no amount in any notification subject;
- a send-time refusal of a VC template that shows an amount;
- the coordinator is the one who ended most recently;
- test fixtures use synthetic project codes;
- the CSV URL is escaped;
- the quarter range is capped;
- docblocks corrected.

Round 2 (on 4d885fa): fresh general and adversarial reviewers, both "no Critical or High; mergeable". Fixed in round 3:
- the VC template is checked against an **allowlist** of placeholders at send time (Source and reference can hold amounts and names);
- the VC notice needs an **Organization** donor for either type, and a link to a real **Project** case;
- ED and Treasurer activities keep only a pointer, not the email (amount and donor);
- placeholders are filled after core's token pass, through a sentinel, so a contact name cannot pull in a placeholder;
- the open-project footnote is `NOT IN` the closed class again, so off-definition statuses still show;
- an old cheque entered late still notifies (received within 365 days, created within 90);
- real project codes were removed from comments and help text;
- boundary tests for the range cap;
- stale test names fixed in docblocks; a failed marker delete is logged with the mail error.

Round 3 (on ae64560):
- **General reviewer:** no Critical or High findings.
- **Adversarial reviewer:** **one High, introduced by ae64560.** Adjacent placeholders (`%%mas_donation.donor%%mas_donation.amount%%`) slipped past the raw-text allowlist regex and would have put the amount in the VC email.

Fixed in round 4:
- the allowlist now mirrors render(): it is substitution-based and checks subject and body separately;
- a literal sentinel byte is refused;
- the sentinel is a per-render random nonce, so neither a template nor a contact name can forge it;
- leftover placeholders go back to visible text;
- ED/Treasurer activities get a fixed subject (no donor name);
- the report and the VC rules ignore non-Project or trashed cases;
- the backlog wording matches the 90/365 gate;
- tests cover the adjacent-placeholder and sentinel cases, the pointer body and subject, and a private-donation test that can now fail.

Round 4 (on 0f7442f):
- **General reviewer:** no Critical or High findings; one Medium.
- **Adversarial reviewer:** **one High, proved on dev.** Core renders `{contact.*}` tokens (and filters such as `|default:"amount"`, or an empty token) between the template check and the fill, so a placeholder name could be completed at render time: `%%mas_donation.{contact.x|default:"amount"}%%` filled the amount into the VC email.

Fixed in round 5, **structurally**: the VC notice is filled through `fill()` with only `VC_SAFE_PLACEHOLDERS` available, so no template, token or contact value can produce an unsafe value. The check now also refuses any placeholder start not followed by a known name (token-built or unknown). Tests use the reviewer's exact inputs. On dev, the attack inputs were run through the real core token pass: none leaked.

Lower-tier findings left unfixed, recorded here:
- `vcRecipient` trusts a hand-set Linked VC, and does not check it against the project's coordinator or the donor against the project's client. The exposure is the organisation's name and the project subject, with no amount.
- An Organization contact can carry a private person's name (e.g. a family fund).
- A coordinator role disabled with a FUTURE end date sorts as "most recently ended".
- Two simultaneous saves can both send (the marker check is not atomic).
- A sent VC notice is not retracted if the donation is later re-typed.
- The legacy "Donation" type stays active for new entries. Consider disabling it once the CSM uses the new types.
- The Project picker filter is display-only; an API write can link a non-Project case, and the reports ignore it.
- The disabled financial types are `update => always`, so a UI re-enable is undone by the next deploy (documented in the declaration).
- The notifier settings are undeclared (no settings UI or type).
- `DonationReport` and `donationQuarterly` read with `checkPermissions = false`, so a financial-type ACL would be bypassed. The `financialacls` extension is not enabled.
- The idempotency LIKE scan is unindexed; volume is small.
- Turning notifications on sends the backlog for donations created in the last 90 days and received in the last 365 (documented in the CHANGELOG and the notifier).
- Pre-existing, not from this PR: the VC Portal activity searches show subjects with `acl_bypass`, so any future automated case activity whose subject names an amount would leak it.
- **Before enabling on prod, confirm VCs (WordPress `subscriber`) do not hold `access CiviContribute`.** That was checked on dev only.
