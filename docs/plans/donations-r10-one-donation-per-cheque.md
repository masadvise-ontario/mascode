# R10 — One donation per cheque, several projects and VCs

Slice: [donations-tickets.md](donations-tickets.md). Decided by Brian on 2026-10-08, in the R4 session:
"one donation per cheque, with the ability for the donation to be associated with multiple VCs and
multiple projects. Nina picks the projects first, then sees all the VCs for all the projects."
He chose an **even split** of a cheque's net across its projects in the quarterly report, and the
**whole amount** in each VC's email.

## Why

The Treasurer allocates one cheque across several projects (for example, one cheque recorded as two equal halves). With one
project per contribution, R4 could not link those gifts as he records them, and 2022 matched only
49% by dollars. Splitting a cheque into several contributions (the old rule) also fragments the
money record and needed the R2 cheque-number heuristic to tell a VC about the whole gift.

## Design

- **Fields.** `Donation_Link.Linked_Project` and `Linked_VC` become multi-value (`serialize`).
  Core converts in place: the column becomes text, existing values are wrapped, and the FK
  constraint is dropped. No data migration and no new field names; the 47 prod links carry over.
- **`Linked_Project_Codes`** (new, view-only text): "P26101, P26102". APIv4 can implicit-join
  through a serialized field only to the case's CORE fields (subject, status, end date), not to
  its custom fields, and explicit joins on a serialized field are a DB error (probed on dev). So
  the *MAS Donations* list shows codes from this field. `DonationLinker` refreshes it whenever it
  writes links, and on every contribution save (postCommit).
- **Form (R1).** Both pickers are multi-select. Projects narrows to the contributor's projects;
  Volunteer Consultants narrows to the coordinators of ALL picked projects. On a project change
  the VC list keeps picks that still belong and adds each project's sole coordinator. Core renders
  a serialized EntityReference as single-select on the classic form, so the form script switches
  on `multiple`.
- **Server VC fill.** Only when the VC list is EMPTY: each linked project's sole coordinator
  (`coordinatorFor`). With several, the CSM picks, as today.
- **VC notice.** One email per linked VC who is a coordinator (current-first, as today) of at least
  one linked project that is a live Project the donor is a client of. The body shows the whole
  cheque amount. The project placeholders show that VC's own projects, and the split note says how
  many projects the gift covers. The activity is filed on the first of that VC's projects.
  Idempotency is per VC: the existing marker plus the activity's target contact, so notices sent
  before this change still count.
- **Quarterly report.** A donation's net is split evenly across its linked Project cases. The
  footnote lists (open / not completed) do the same.
- **History (R4).** The map's `case_id` and `vc_id` cells take several ids separated by `;`.
  Fill-empty compares the sets.
- **DN-5 follow-up (upgrade step).** Reconcile the managed fields (the conversion), fill
  `Linked_Project_Codes` for every linked donation, and for a donation whose Source names several
  `P` codes and whose link is still exactly the first one (untouched since `upgrade_5019`), add the
  others. That clears the CSM list's "several codes" rows.

## Done when

- Unit tests pin the new pure rules: id parsing, VC recipients across projects, per-VC case filing,
  the split note for a multi-project gift, the even split, and the history verdict with lists.
- On dev, a contribution with two projects saves from the classic form with two projects and two
  VCs, sends one VC email per VC (MailHog) with the whole amount, and a re-save sends nothing.
- The quarterly page and *MAS Donations* list show it under both projects.
- Two fresh-context review rounds (general + adversarial) with nothing blocking.
