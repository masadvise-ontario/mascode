# Managed Entities

CiviCRM scans this directory (and the rest of the extension) for `*.mgd.php` files at install/upgrade time. Each file declares one or more entities that mascode owns. When the extension is enabled, Civi reconciles the live database to match these declarations.

## What lives here

| File | Entity | Phase | Purpose |
|------|--------|-------|---------|
| `OptionValue_CaseStatus_AwaitingCloseForm.mgd.php` | OptionValue | mas-lifecycle Phase 1 | Project status: "Awaiting Close Form" (VC done, awaiting client close form) |
| `OptionValue_ActivityType_DraftEmail.mgd.php` | OptionValue | mas-lifecycle Phase 1 | Activity type for propose-mode CiviRules (draft email needs review) |
| `OptionValue_ActivityType_SentAutomatedEmail.mgd.php` | OptionValue | mas-lifecycle Phase 1 | Activity type for auto-mode CiviRules (traceability) |
| `CustomField_Project_EstimatedCompletionDate.mgd.php` | CustomField | mas-lifecycle Phase 1 | Drives close-chase cadence; new field on existing Projects custom group |
| `CaseType_ServiceRequest.mgd.php` | CaseType | mas-lifecycle Phase 1 | Full ownership of Service Request case type definition |
| `CaseType_Project.mgd.php` | CaseType | mas-lifecycle Phase 1 | Full ownership of Project case type definition (incl. Awaiting Close Form status) |
| `OptionValue_CaseStatus_Open_Duplicate_Deactivate.mgd.php` | OptionValue | mas-lifecycle Phase 1 cleanup | Pins the legacy duplicate `Open`/`Open` case_status to is_active=false (CiviCRM core ships `Open`/`Ongoing`; the duplicate was a UI-added accident). 2 stragglers migrated to status_id=1 on 2026-05-31. |
| `MessageTemplate_rcs_chase__client.mgd.php` | MessageTemplate | mas-lifecycle Phase 3 | Skeleton — chase to client whose RCS/SAS forms are outstanding. Body filled via UI. |
| `MessageTemplate_vc_assignment_offer__vc.mgd.php` | MessageTemplate | mas-lifecycle Phase 3 | Skeleton — VC-facing assignment offer when an SR enters "Sent for Assignment". |
| `MessageTemplate_vc_no_pickup_chase__vc.mgd.php` | MessageTemplate | mas-lifecycle Phase 3 | Skeleton — chase to VC if an assignment offer is not picked up. |
| `MessageTemplate_consultant_intro__client.mgd.php` | MessageTemplate | mas-lifecycle Phase 3 | Skeleton — replaces Nina's copy/pasted consultant intro on Project create. |
| `MessageTemplate_close_chase__client.mgd.php` | MessageTemplate | mas-lifecycle Phase 2 | Skeleton — chase to client if close form is outstanding. Uses `{tokenized_close_url}` once Phase 2 token mechanism lands. |
| `MessageTemplate_donation_notify__ed.mgd.php` | MessageTemplate | mas-lifecycle Phase 4 | Skeleton — donation notification to ED. Fanned out by Contribution.create Symfony subscriber. |
| `MessageTemplate_donation_notify__treasurer.mgd.php` | MessageTemplate | mas-lifecycle Phase 4 | Skeleton — donation notification to Treasurer (Steve). |
| `MessageTemplate_donation_notify__vc.mgd.php` | MessageTemplate | mas-lifecycle Phase 4 | Skeleton — donation notification to originating VC. |
| `MessageTemplate_anniversary_checkin__client.mgd.php` | MessageTemplate | mas-lifecycle Phase 4 | Skeleton — twelve-month project anniversary check-in to client. |
| `MessageTemplate_MAS_RCS_Template.mgd.php` | MessageTemplate | snapshot (pre-Phase 1) | Existing initial RCS+SAS ask, brought under management with `update='unmodified'`. Body in sibling `.body.html`. |
| `MessageTemplate_MAS_Form_Submission_Confirmation.mgd.php` | MessageTemplate | snapshot (pre-Phase 1) | Existing auto-sent Afform submission confirmation. Body in sibling `.body.html`. |
| `MessageTemplate_MAS_Project_Close_VC_Template.mgd.php` | MessageTemplate | snapshot (pre-Phase 1) | Existing VC close-form ask. Body in sibling `.body.html`. |
| `MessageTemplate_MAS_Project_Close_Client_Template.mgd.php` | MessageTemplate | snapshot (pre-Phase 1) | Existing client close-form ask. Body in sibling `.body.html`. |
| `MessageTemplate_after_RCS.mgd.php` | MessageTemplate | snapshot (pre-Phase 1) | "your request got circulated" notice to client at SR→Sent for Assignment. **Title is an open decision — do not rename** (see the naming section below). Nothing fires it; it is sent by hand. Body synced from production 2026-09-23. |
| `MessageTemplate_MAS_SAS_Template_Deactivate.mgd.php` | MessageTemplate | cleanup pin | Deactivates legacy "MAS SAS Template" (id 72 — superseded by the RCS template which now includes both SAS variants). |
| `MessageTemplate_pd_signoff_notify__vc.mgd.php` | MessageTemplate | VC record email | Tells the assigned VC the client authorized the Project Definition, with a complete printable record (header + definition + authorization). Sent by `AfformSubmitSubscriber`, not a CiviRules rule — hence no `mas_lifecycle_` prefix. |
| `MessageTemplate_close_feedback_share__vc.mgd.php` | MessageTemplate | VC record email | Forwards the client's project-close feedback to the VC when `Project_Close_Client.share_with_vc` is Yes. Sent by `AfformSubmitSubscriber`, not a CiviRules rule — hence no `mas_lifecycle_` prefix. |
| `SavedSearch_MAS_Sent_Email_Log.mgd.php` | SavedSearch + SearchDisplay | ops tooling | Combined log of every outbound email CiviCRM recorded (Email, Bulk Email, Sent Automated Email, Reminder Sent). Surfaced via `afformMASSentEmailLog`, which carries the `edit all contacts` gate. |
| `ActivityType_MonthlyProjectCheckin.mgd.php` | OptionValue + CustomGroup + CustomFields | digest Phase 1 (P1-1) | The *Monthly Project Check-in* activity type and the three fields holding a VC's answers to one monthly digest round. **Two entity kinds in one file on purpose** — `extends_entity_column_value:name` resolves against the live option list at write time, so the option value must be created first; core creates in file-sort then array order, and under this directory's one-entity-per-file naming `CustomGroup_…` sorted before `OptionValue_…`, producing an UNSCOPED group on every clean environment. See the file's docblock and `tests/Unit/Managed/MonthlyCheckinDeclarationTest.php`. |

> The other `SavedSearch_*.mgd.php` files predate this table and are not yet inventoried individually — pre-existing gap, not a licence to skip the row for a new one.

## Message template naming — the prefix states who sends it

A `msg_title` is the only thing staff see in the CiviCRM dropdown, and it is also
the match key this directory declares on and that several subscribers key their
lookups by. So it has to answer one question on sight: **does a human send this,
or does the system?**

| Prefix | Who sends it | Example |
|---|---|---|
| `MAS <Title Case>` | A person composes and sends it from the case | `MAS RCS Template` |
| `mas_*` | The system sends it, unattended | `mas_vc_monthly_digest__vc` |

That top-level split is the load-bearing one, and it is what the `__client` /
`__vc` / `__ed` / `__treasurer` suffix completes: the suffix names the recipient,
so the two halves of one event sort together —
`mas_lifecycle_rcs_circulated__client` beside `mas_lifecycle_vc_assignment_offer__vc`.

### `_lifecycle_` is a sub-namespace, and it is NOT reliable

Most `mas_lifecycle_*` templates are fired by a CiviRules rule through
`LifecycleMailer`, and three declarations say in their own words that they are not:
`mas_pd_signoff_notify__vc` and `mas_close_feedback_share__vc` are sent by
`AfformSubmitSubscriber`, and `mas_vc_monthly_digest__vc` by `VcDigestMailer`. The
inventory table above phrases it as "not a CiviRules rule — hence no `mas_lifecycle_`
prefix".

**But three do not obey it.** The Phase 4 donation trio —
`mas_lifecycle_donation_notify__ed`, `__treasurer` and `__vc` — are declared with the
`lifecycle` infix while their own docblocks say a Symfony subscriber on
`Contribution.create` fans them out. They are unbuilt skeletons, so nothing is broken;
but it means **you cannot read `_lifecycle_` as proof of a CiviRules rule.** Read the
declaration's docblock, or grep `civirule_rule_action.action_params`, for that.

The honest rule: `_lifecycle_` marks a template belonging to the engagement lifecycle
(Service Request → Project → close), which is usually but not always CiviRules-driven.
Nothing enforces the infix, and this directory's test does not either — it checks the
`MAS ` / `mas_` split and the recipient suffix only.

**The prefix states the intended mechanism, not a live one.** Several
`mas_lifecycle_*` templates have no rule firing them yet; the prefix says what will
fire them when Phase 2–4 wire them up.

### Live templates that do not obey this

Both are `MAS `-prefixed and both are machine-sent. Neither is renamed, because a
rename is a code change in more than one place and the risk is real:

- **`MAS Form Submission Confirmation`** — sent by `AfformSubmitSubscriber` on
  every client Afform submission. The subscriber maps seven `server_route`
  values to this literal title.
- **`MAS Project Signoff - Client Template`** — sent automatically by the
  `mas_lifecycle_vc_close_send` CiviRule, and it is also a key in
  `ProjectLifecycleStatusSubscriber::TRANSITIONS`, a `VcDigestMailer` subject
  guard, and `civirule_rule_action.action_params`.

`MAS Project Completion - VC Template` is the ambiguous third: a person sends it
today, but sending it is what advances the case, and Phase 2 wires it to fire
automatically — at which point it belongs in the `mas_lifecycle_` tier too.

`MessageTemplateNamingTest` **freezes these two titles** — rename either in the
declarations without updating its `GRANDFATHERED` constant and it goes red. It does
**not** and cannot stop a *new* `MAS `-prefixed machine-sent template: the test sees
the shape of a string, and a title does not encode its sender. That one needs a human
reading the PR.

**And one title is undecided, not wrong.** `after RCS` matches neither tier, and the test exempts it through
`PENDING_DECISION` rather than treating it as an offender. The reason is that **both
tiers assert something**: `mas_*` says the system sends it, `MAS <Title Case>` says a
person does, and which is true is the decision Nina is making. It was renamed to
`mas_lifecycle_rcs_circulated__client` in PR #43 and reverted, because the send data says
a person sends it — read from **production** on 2026-09-23, **58 sends across 29 distinct
days in 2026, 53 of them with distinct bodies**, i.e. edited almost every time it goes out.

The exemption is self-clearing: `testPendingDecisionTitlesAreStillDeclared()` goes red as
soon as the title changes, and tells whoever changed it to delete the entry rather than
carry a stale exemption. That matters because the original `after RCS` sat unnoticed from
May to September — an exemption nobody is forced to revisit is how that happens.

**Renaming any of these is a coordinated change**, not a UI edit. Renaming
template 75 in the production UI on 2026-09-17 silently stopped the client
transition and the arming of `mas_lifecycle_close_chase`. The procedure is:
declaration + every code literal + an `upgrade_NNNN` that renames the row and
repoints CiviRules actions (`upgrade_5015` is the worked example), all in one commit.

## Sidecar `.body.html` files

Templates whose bodies are version-controlled use a sidecar `.body.html` file alongside the `.mgd.php`. The `.mgd.php` loads the body via `file_get_contents(__DIR__ . '/<name>.body.html')`. This keeps HTML readable in git diffs and preserves CRLF line endings (which matters for CiviCRM's `is_modified` hash detection).

When the in-UI body diverges from the sidecar:
1. Run `cv api4 MessageTemplate.get` to dump the live `msg_html`
2. Diff against the `.body.html` snapshot
3. Either refresh the sidecar (snapshot stale, accept the UI version) or revert in Civi (sidecar canonical, push to UI)
4. The diff-before-deploy workflow drives the cadence

## Cleanup policy

| Entity type | `cleanup` | Why |
|-------------|-----------|-----|
| OptionValue (case_status, activity_type) | `unused` | Don't drop a status/type if cases or activities still reference it |
| CustomGroup | `never` | Same reason as CustomField, one level up: dropping a group drops its data table. Applies to every `CustomGroup_*.mgd.php` here, which predate this row |
| CustomField | `never` | Schema-level drop = permanent data loss; uninstalling mascode should NOT remove fields |
| CaseType | `never` | Cases reference case types via FK; dropping a case type would orphan thousands of cases |
| MessageTemplate | `never` | Templates may be referenced by historical activities; uninstall should NOT delete |
| SavedSearch / SearchDisplay | `unused` | Nothing references a search by FK, so dropping one on uninstall is safe. `unused` (not `never`) keeps a search that an Afform still embeds by name, since that reference is not an FK CiviCRM can see. |

`update` is `always` on case-type config (mascode is authoritative; UI drift reverts on next reconcile — Brian is the sole editor). MessageTemplate entries use `update='unmodified'` — **and it does not do what that name suggests.** `MessageTemplate` is not an APIv4 ManagedEntity (`CoreUtil::getInfoItem('MessageTemplate','type')` is `['DAOEntity']`), so `civicrm_managed.entity_modified_date` is never stamped for it and the `unmodified` check degrades to always-update; core logs that fallback on every reconcile. **A UI edit to a template body is therefore TRANSIENT, not protected.** It survives only while this declaration's checksum is unchanged — `optimizePlan()` drops the update then — so the moment anyone edits the `.mgd.php` or its `.body.html` and deploys, the repo overwrites whatever production is carrying. On 2026-09-23 production was found 322 and 370 bytes ahead of the repo in two templates for exactly this reason. Nina/Brian/Steve still own the wording, but the repo is what ships it: content-diff production before editing a template declaration, and sync production → repo first if it is ahead. SavedSearch/SearchDisplay entries default to `update='unmodified'`, with one deliberate exception: `SavedSearch_MAS_Sent_Email_Log.mgd.php` uses `always`, because it is authoritative ops config nobody should hand-edit and a stray UI tweak would otherwise detach the file from reconciliation permanently. The trade-off is that UI edits to that one search are silently reverted on the next flush.

## Post-CiviCase-upgrade checklist

When bumping CiviCRM (any major version), run through this before deploying mascode to prod:

1. **Apply CiviCRM upgrade in dev first** (`/mas-clone` if state matters, then `cv upgrade-db`).
2. **Reconcile managed entities**: `cv api4 Managed.reconcile`.
3. **Smoke test**: `vendor/bin/phpunit --group case_type` (runs `tests/Integration/Managed/CaseTypeSmokeTest.php`).
4. **Inspect the live CaseType definitions** for new fields CiviCase may have added:
   ```bash
   cv api4 CaseType.get '{"select":["definition"]}'
   ```
   Compare to the `.mgd.php` definitions here. If CiviCase added a new optional key (e.g., a new automation setting), decide whether to manage it explicitly or let Civi default it.
5. **Create a sample case in each type** via the Civi admin UI — make sure all expected statuses appear in the dropdown and the timeline activity creates.
6. **Only then** push to master and pull on prod.

## Adding new managed entities

1. Add a new `*.mgd.php` file in this directory returning an array of one or more entries.
2. Use `match` to identify existing rows (so reconcile UPDATEs rather than creating duplicates).
3. Use `option_group_id.name`, `custom_group_id.name`, etc. (FK-by-name) — never raw IDs.
4. Pick `cleanup` per the policy table above.
5. Run `cv api4 Managed.reconcile` in dev to apply.
6. Verify with `cv api4 Managed.get '{"where":[["module","=","mascode"]]}'`.

## References

- Spec: BrianPKM `3-Resources/mas-engagement-lifecycle-automation-spec.md`
- Dashboard task #107: MAS Lifecycle: state + templates (Phase 1)
- CiviCRM docs: <https://docs.civicrm.org/dev/en/latest/extensions/civix/#managed-entities>
