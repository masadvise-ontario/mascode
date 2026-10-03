# Donations — Ticket Slice

**Status as of 2026-10-03 — DN-1…DN-5 are built and running on dev (masdemo) from branch
`claude/donations-dn1`, in review, for a demo to the Treasurer on 2026-10-06.** Nothing is deployed
to production. Merging waits on the review gate; deploying waits on the Treasurer's TBCs. `git log` is authoritative for this
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
- VC notification: **yes**. Amount: **no** (TBC).
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
| DN-7 | **CanadaHelps fee.** A default fee of 3.75% when the payment method is CanadaHelps. **First verify** how a fee on Record Payment combines with a fee already on the contribution, so it can't be double-counted. | not started | Gross, fee and net are correct after Record Payment |
| DN-8 | **Thank-you text.** Standard wording in the offline receipt template (spec D-F). | not started | The CSM ticks "Send receipt" and the donor gets the standard text |
| DN-9 | **Legacy history.** Load Linked Project for 2020–2022 donations from the Treasurer's *Projects – Details* sheet (`21-105` → `P21105`). **Needs the Treasurer's OK.** | not started | The quarterly report back to 2020 matches his 2022 workbook |
| DN-10 | **Abandoned Event Fee checkouts.** Clean up the Pending event-fee contributions (spec §4 Q6). This is a production data operation, so it needs per-change approval. | not started | No Pending Event Fee older than 30 days |

Not in this slice, pending the Treasurer: CDN Tax Receipts (Q3), CAF handling (Q8), expense
donations (Q11).

## Known limits to say out loud in the demo

- **Linked-donation history starts in 2025.** Only about 45 dev donations carry a `P` code in Source.
  Earlier quarters show zero donations until DN-9 runs. The quarterly page therefore defaults to 2025-01-01.
- **The close date currently tracks the donation date.** On at least five 2026 projects the CSM
  closed the case when the money arrived (spec §3d). Q9's answer (the client signoff closes the
  project, TBC) removes the effect going forward, but not in history.
- **Dev data is a clone from 2026-09-21.**
- **23 client donations since 2025 have no project code in Source**, so the backfill could not link
  them. The quarterly page counts them. They are the CSM's clean-up list (filter *MAS Donations* by
  type, with no project).
- **One Source has a six-digit code** (`P252107`). It was reported, not guessed.
- Cosmetic: the *Received* filter on *MAS Donations* still shows time pickers.
