---
name: reference_civicrm_mascode_gotchas
description: "CiviCRM/mascode gotchas: cv flush after FP import, CiviRule/FP ordering, managed-entity update modes, afform :name refs, afform/SearchKit gotchas, SearchKit ACL security, Purifier strips comments, WPO365 VC identity sync, SES From verification"
metadata:
  type: reference
---

Consolidated CiviCRM/mascode gotchas, merged from nine curated Klaus memory topic files.

## feedback_cv_flush_after_fp_import

After any `cv api3 FormProcessorInstance.Import file=...` on dev OR prod, **always** run `cv flush` immediately. The Import updates the DB rows for `civicrm_form_processor_input` / `_action`, but CiviCRM caches the assembled FormProcessor definition in memory/APC. Subsequent calls to `FormProcessor.<name>` use the cached definition until the cache is cleared.

**Why:** First MAS Newsletter prod deploy failed silently — `FormProcessor.mailing_list_form subscribe_ai=on` ran successfully but `input.subscribe_ai` didn't appear in the output (cached FP didn't have the new input), so the AddToGroup AI action never fired. Took a probe-by-probe diagnostic to find this — full trace in conversation transcript dated 2026-05-25.

**How to apply:** Always pair the two calls in scripts and runbooks:

```bash
cv api3 FormProcessorInstance.Import file=/path/to/x.json && cv flush
```

The same pattern likely applies to other CiviCRM API-driven config imports (CiviRules, OptionValue, etc.) — when in doubt after a config Import, flush.

## feedback_civirule_formprocessor_ordering

A CiviRule with trigger `new_contact` and action `add_contact_group` runs synchronously when CiviCRM's `Contact.create` fires its post-DAO hook — BUT the row it inserts into `civicrm_group_contact` is not always visible to later FormProcessor actions in the same outer transaction. Specifically, a `RemoveFromGroup` action queries `civicrm_api3('GroupContact', 'get', ...)` and finds zero rows even when the CiviRule just inserted one moments earlier.

**Why:** Observed end-to-end on 2026-05-26 while building MAS Newsletter - General opt-out path. The form submitted with `subscribe_general=""` for a new contact: CiviRule's Add fired, FormProcessor's Remove ran, but the row's final state was `status='Added'` (not Removed). When the contact pre-existed (the Add row was committed earlier), Remove worked as expected. So the issue is specifically same-transaction visibility for rows created by post-hook actions inside one FormProcessor run.

**How to apply:**
- Don't design FormProcessor flows that rely on CiviRule-created rows being visible to subsequent FP actions within the same run.
- If you need both "default add via CiviRule" and "form opt-out", accept that the CiviRule wins for newly-created contacts and document the limitation. Existing contacts re-submitting the form still work because their row was committed in a prior transaction.
- Workarounds if strict opt-out is required: (a) move the Remove to a delayed FP action with non-zero `delay_configuration`, (b) skip the CiviRule entirely and replicate its logic in mascode using a `hook_civicrm_postCommit` listener, or (c) disable the CiviRule and put the auto-add logic directly in the FormProcessor for form paths only.

## feedback_civicrm_managed_unmodified

CiviCRM managed-entity `update` flag governs reconcile behavior:

- **`update='always'`**: mascode authoritative. Each reconcile overwrites live to match declared values. Use for CaseType definitions, CustomField schemas, OptionValues where the developer is sole editor (UI edits must NOT win).
- **`update='unmodified'`**: hash-tracked. First reconcile sets baseline hash; UI edits flip `civicrm_managed.is_modified=true`; subsequent reconciles SKIP update on modified rows. Use for MessageTemplate body content where operators iterate in the Civi admin UI — mascode plants structure (name, subject, recipient logic, merge-tag scaffolding), body stays editable.
- **`update='never'`**: initial create only; never updates afterwards. Rare — useful when the managed entity is truly create-once.

**Why:** mascode-managed messages have two natural owners. The structure (template name, recipient, merge tags, when it fires) is engineering-owned and belongs in git. The body content (prose, signature) is operator-owned and naturally iterated in Civi WYSIWYG. `update='unmodified'` lets both ownership patterns coexist.

**How to apply:**
- CaseType + CustomField + most OptionValues (config schema) → `update='always'`
- MessageTemplate (or any entity with operator-editable body content) → `update='unmodified'`
- Pair MessageTemplate management with sidecar `.body.html` files loaded via `file_get_contents(__DIR__ . '/...')`. See [[jq-byte-faithful-extraction]] for the matching extraction technique.
- The diff-before-deploy workflow keeps sidecars honest: diff live vs sidecar before pushing to prod; refresh or revert per case.

## reference_afform_name_based_refs

CiviCRM Afform `af-entity` data fields accept name-based pseudoconstant references that survive a full FormBuilder UI round-trip — verified via Playwright in mascode dev (2026-05-31).

**Pattern:**
```html
<af-entity data="{source_contact_id: 'Individual1', 'activity_type_id:name': 'Short Self Assessment Survey (SAS)', status_id: 2, subject: 'SAS Short'}" type="Activity" ... />
```

**Verified round-trip:**
1. File edit (numeric → name-based) → Civi parses on next request
2. `Afform.get` returns layout JSON with `"activity_type_id:name": "..."` preserved verbatim
3. FormBuilder Angular state holds the same key
4. FormBuilder renders correctly (form title, Activity context resolves to the right type)
5. FormBuilder save (`scope.save()` calls `Afform.save` API) → re-serializes layout
6. Re-written `.aff.html` → name-based key preserved verbatim

**Why this matters:** Numeric IDs in Afforms (`activity_type_id: 73`, `case_type_id: 3`, `email_confirmation_template_id: 71`) break silently across dev/prod when those IDs drift. Name-based refs resolve via the OptionValue's `name` field, which is stable across envs when the entity is mascode-managed.

**How to apply:**
- Audit `.aff.html` and `.aff.json` for numeric `_id` patterns: `grep -nE '"?[a-z_]+_id":\s*[0-9]+' *.aff.{html,json}`
- Replace numeric IDs with name-based pseudoconstant refs:
  - `case_type_id: 3` → `'case_type_id:name': 'service_request'`
  - `activity_type_id: 73` → `'activity_type_id:name': 'Short Self Assessment Survey (SAS)'`
  - `location_type_id: 1` → `'location_type_id:name': 'Home'`
- Bring referenced entities under mascode management FIRST — name-stable across envs is the prerequisite.
- Manually test each refactored form by submitting — resolution failure produces a runtime error or wrong entity.

**What this disproves:** the recollection that "6 months ago we tried Afform version-control and ran into issues" — the FormBuilder round-trip is NOT the problem. The likely cause was dev/prod ID drift while using numeric refs.

`status_id: 2` (Activity status "Completed") and similar core CiviCRM constants are stable enough to leave numeric.

## reference_civicrm_afform_mascode_gotchas

CiviCRM extension (mascode) gotchas hit during the mas-lifecycle forms work.

**Load shared CSS on public Afform pages.** No `ang-php` mixin is needed. Register a tiny Angular module in `mascode.php` via `hook_civicrm_angularModules` with `['ext'=>'mascode','js'=>['ang/foo.js'],'css'=>['css/foo.css']]`, ship a JS stub (`angular.module('foo', [])`) — a **CSS-only module still needs the `angular.module()` declaration or `requires` fails with "module not available"** — then add the module to each form's `.aff.json` `requires: ["foo"]`. The CSS then loads on the public `is_public:true` form routes. `hook_civicrm_pageRun` does NOT fire for standalone Afform routes, so the PageRunSubscriber pattern doesn't work for forms.

**Styling Afform fieldsets:** the CiviCRM theme styles `af-container-style-pane` fieldsets as clean cards (grey title bar + box-shadow) and overrides custom fieldset/legend CSS. Target `fieldset:has(> legend.af-title)` for sections and `legend.af-title` for titles; use a `.mas-form`-scoped `!important` when you must beat the theme (e.g. section-header colour). `af-title` renders as `<legend class="af-title">`.

**`PostEvent` property names.** `hook_civicrm_post` dispatches a `\Civi\Core\Event\PostEvent` whose public props are `$action` / `$entity` / `$id` / `$object` — NOT `$op`/`$objectName`/`$objectId`. Using the wrong names silently returns null (magic `__get`), so the handler no-ops with no error.

**Anonymous FormProcessor / activity_date_time defaults to UTC.** In an anonymous public web request (FormProcessor, Afform submit) PHP's default timezone isn't initialised, so an `Activity::create` that leaves `activity_date_time` unset stamps it in UTC — ~4h ahead of `created_date` (Eastern). Stamp it explicitly from the DB's local now: `\CRM_Core_DAO::singleValueQuery('SELECT NOW()')`. Same class of fix used for the "Open Case at 12:00 AM" issue (case `start_date` is date-only → midnight; a `hook_civicrm_post` Activity-create subscriber re-stamps the time, preserving the date).

**Metadata-driven labels (no hardcoded maps).** To render a submitted Afform's values without drift-prone label maps, read the saved entity via API4 with the `:label` suffix (`"$group.$field:label"`) — resolves option/Yes-No/satisfaction/Likert labels from metadata; enumerate a custom group's fields via `CustomField::get()->addWhere('custom_group_id:name','=',$group)`. See `Civi/Mascode/Submission/SubmissionSummaryService.php`. Activity `details` is HTML — escape entered values with `htmlspecialchars()`.

**Afform files are filesystem-backed and do NOT travel with a prod→dev DB clone — saved searches + displays DO.** `.aff.html`/`.aff.json` live under `wp-content/uploads/civicrm/ang/` (the `cv flush` re-scans them; the file is the source of truth incl. `server_route`). `mas-clone` copies the CiviCRM DB only, so `civicrm_saved_search` + `civicrm_search_display` sync prod→dev but the afform files do not — dev and prod afform files drift silently. Symptom hit 2026-06-14: the dev "My Cases" portal report (`afsearchMyCasesReport.aff.html`) pointed at `search-name="My_Cases_2"` (nonexistent → 0 rows) while prod's same file correctly said `My_Cases` (+ different display, Table_2 vs Table_1, + extra markup). Fixing afforms = edit the `.aff` file in each env (or `scp`), then `cv flush`; fixing search/display config = edit on prod and let the clone carry it to dev.

**Fix afform drift permanently: make it a managed extension entity.** The drift above happens because UI-created afforms are **site-local** files (`uploads/civicrm/ang/`, `Afform.get` shows `base_module=null`) that don't travel with code or the DB clone. To stop it, move the `.aff.html`/`.aff.json` into the extension's `ang/` dir (e.g. `ext/mascode/ang/`) → `base_module` becomes `mascode` and it version-controls + deploys with the extension. **Critical**: the site-local copy *shadows* the extension version, so you must also remove (move out) the local `uploads/civicrm/ang/<name>.aff.*` files in **each** env, then `cv flush` — otherwise nothing changes. Verify with `cv api4 Afform.get +w "name=X" +s base_module`. Prod mascode is a writable git checkout deployed via `git pull` (origin `briangflett/mascode` redirects to `masadvise-ontario/mascode` — same repo). Done 2026-06-20 (#609): made `afsearchMyCasesReport` + `afsearchServiceRequestsSentForAssignment` managed (v1.1.7); fixed prod's My Cases Report Case-Subject link (prod afform had embedded the linkless `My_Cases_Table_2`).

**SearchKit: adding a column needs it in TWO places.** The field must be in the saved search's `api_params.select` AND in the display's `settings.columns`. Adding only the column (or only the select field) renders nothing. The portal afform names a specific display (`display-name`), so edit the display the afform actually uses — or all displays of the search to be safe. See `mascode/tools/add_case_status_column_my_cases.php` (idempotent: patches select + every display of a named search).

**Afform action-button links (VC case-detail buttons, fixed 2026-06-24, mascode #?).** Four traps when hand-coding `<a ng-href>` buttons in an afform that deep-link to another afform:
- **`CRM.url()` does NOT work inside an Angular `{{ }}` expression** — `CRM` is a window global, not on the afform scope, so it silently evaluates to empty and the path vanishes (the link collapses to a same-page hash → "button does nothing"). Use **`crmUrl('civicrm/route')`** — the scope function afform inherits from `crmUtil` (afCore requires it). Core SearchKit templates use `crmUrl(...)` the same way.
- **`afFieldset.getFieldData()` is undefined outside `af-repeat`** — `$scope.afFieldset` is only published inside repeat contexts. To read a URL param in a plain fieldset template, use **`routeParams.<param>`** (set on the route scope by `afCore.js`; the documented pattern, e.g. `routeParams.id`).
- **A Case entity with the `case-autofill="entity_id"` behavior loads from the prefill arg `case_id`, NOT the entity-name token.** So the deep-link must be `#?case_id=<id>`, not `#?Case1=<id>`. Wrong param ⇒ form opens but the Case entity stays empty (blank DisplayOnly project fields). The `civi_case` `CaseAutofill` behavior reads `getArgs()['case_id']`. Verify with `cv api4 Afform.prefill name=X fillMode=form 'args={"case_id":N}'`.
- **To prefill a Contact (e.g. the VC) from the case rather than a token**, use `autofill="role_on_case:<RelationshipType near_relation>"` + `autofill-case="Case1"` (`civi_case` `ContactAutofillBasedOnCase`). MAS VC = the case's `Case Coordinator is` role. This works for BOTH the portal button and emailed-link paths (both carry the case), unlike `autofill="entity_id"` (token-only) or `autofill="user"` (session-only). `roleType` matches `RelationshipCache.near_relation` exactly.

**Afform search filters: two traps (My Cases Report status filter, 2026-06-24).** Adding a user-facing filter field (`<af-field>` inside the search `af-fieldset`) that defaults to some values and actually filters the embedded display:
- **`afform_default` does NOT bind to a filter on a JOINED field.** If the search's `api_entity` is X but you want to filter on a field reached via a join (e.g. base RelationshipCache, filtering `RelationshipCache_Case_case_id_01.status_id`), the default silently won't populate and the filter won't apply. Core afform defaults bind reliably only to **base-entity** fields. Fix: base the saved search on the entity whose field you're filtering (we rebuilt My_Cases RelationshipCache→Case so `status_id` is native), scoping access via a join/WHERE instead.
- **Do NOT set `search_operator: 'IN'` on a multi-select filter.** It nests the field value as `{IN: null}`, so an `afform_default: ['1','6',...]` array lands *outside* the `.IN` slot and is dropped (field stays empty, nothing filters). A multi-select (`input_attrs: {multiple: true}`) is implicitly IN — omit `search_operator`. Core's own filters (e.g. `contribution_status_id`) do exactly this. Multi-select defaults must be an array of **string** option values (`['1']`, not `[1]`); status-class defaults = the case_status `grouping='Opened'` values.
- afform fieldset fields auto-filter the embedded `crm-search-display-table` with no explicit `filters=` attribute (same fieldset). Verify in-browser: empty select2 chips + unfiltered rows = the default didn't bind.

**SearchKit "Download Spreadsheet" = the display's `actions` setting.** A SearchDisplay's `settings.actions`: `true` = ALL search tasks enabled (incl. download), `false` = no actions menu, an **array** = only those task names (e.g. `['download']` = download only — the safe choice for acl_bypass portal displays, no Update/Delete exposed). The download task name is `'download'`. `actions_display_mode` defaults to `'menu'` at runtime (`|| 'menu'`), so it need not be set. In mascode dashboards, **count tiles** (aggregate) vs **list** displays are distinguished by `'pager' => FALSE` (count) vs `['hide_single' => TRUE]` (list) — handy when enabling download on lists but not count tiles. Done 2026-06-24.

Related: [[feedback_drvfs_no_chmod_utime]] (mascode dev), [[reference_playwright_civicrm_auth]] (dev cookie auth for Playwright form testing).

## reference_civicrm_searchkit_acl_security

Building a CiviCRM front-end portal where the SearchKit WHERE filter is the security boundary (mascode VC Portal, #608). Hard-won gotchas:

- **`acl_bypass=TRUE` displays need afform access, not case/contact perms.** `AbstractRunAction::_run` (search_kit): with `checkPermissions=TRUE`, a non-superuser running an `acl_bypass` display must pass `loadAfform()` (the afform's own `permission`, e.g. `access CiviCRM`); then the inner query runs `checkPermissions=FALSE`, so the WHERE clause alone scopes rows. So a Subscriber with only `access CiviCRM` CAN run the portal — no case/custom-data caps needed.
- **`cv scr` CANNOT test WP permissions.** CLI runs with a permissive permission backend: `CRM_Core_Permission::check('view all contacts')` may say no while `CRM_Contact_BAO_Contact_Permission::allow()` says ALLOWED, and `SearchDisplay.run` with `checkPermissions=TRUE` throws "Access denied" (no afform context). The **browser is the only source of truth** for permission behavior. The TDD test (`tests/Security/CaseDetailAccessTest.php`) uses `checkPermissions=FALSE` → it validates the filter LOGIC only, not the live permission path; verify that in-browser as the real role (cookie-injection per [[reference_playwright_civicrm_auth]]).
- **`user_contact_id` resolves only in WHERE, not in JOIN ON conditions** (SearchKit reads the JOIN RHS as a field name → "Invalid field 'user_contact_id'"). Put the coordinator join on relation/active only; put `near_contact_id = user_contact_id` in a WHERE OR-group.
- **SearchKit grid omits empty fields/columns** (even populated cards drop their null fields). To hide a fully-empty custom-field group, make its search require `['field','IS NOT EMPTY']` (not `IS NOT NULL` — empty-string custom fields are non-null) so it returns 0 rows, then hide the wrapper via CSS `:has(.crm-search-display-grid-no-results)`.
- **acl_bypass secures the PORTAL, not native screens.** Blocking native `civicrm/contact/*` + `civicrm/case/*` for low-priv users is separate — do it with a `hook_civicrm_pageRun` guard that denies when the user lacks `view all contacts`/`edit all contacts`/`administer CiviCRM` (WP admins pass these via `manage_options` even when the named WP cap shows "no"). Don't rebuild contact-based ACLs (the rabbit hole). See `Civi/Mascode/Event/VcNativeScreenGuardSubscriber.php`.

## reference_civicrm_purifier_strips_comments

CiviCRM's HTML Purifier removes HTML comments (`<!-- ... -->`) from rich-text
fields (e.g. activity `details`) whenever the record is saved through the
WYSIWYG activity form. API4 `Activity.create` writes survive, but a later
human edit in the UI silently strips any comment.

**Bit me in mascode (2026-06-24):** the MAS engagement-lifecycle "Send draft
email" SearchKit task stored the recipient/template in a `<!--mas-lifecycle …-->`
comment inside the draft `details`. A CSM edited one draft in the activity form,
the comment was stripped, and `LifecycleMailer::sendDraft()` threw "no recipient
metadata" — which `SendLifecycleDraft::_run()` swallowed into an HTTP-200 error
row that SearchKit's apiBatch runner reported as "Sent." Email never left; UI
showed nothing wrong. Fix (commit c05a9bb): recipient now falls back to the
activity's Activity Targets contact (survives edits, is what the dashlet shows),
and the task throws on failure instead of faking success.

**How to apply:** never make required machine-data depend on an HTML comment in
an editable field. Persist it somewhere durable (custom field, the activity's
target/assignee contact, a dedicated table) and treat any in-body marker as a
hint, not a hard dependency. Also: an APIv4 `AbstractBatchAction` that catches
per-row errors into result rows looks like success to SearchKit's apiBatch task
(it only branches on HTTP success/reject) — throw to surface failures.

## reference_civicrm_wpo365_vc_identity_sync

masadvise.org VC Portal: each Volunteer Consultant should have a Microsoft 365
(Entra) account `firstname.lastname@masadvise.org`, a WordPress **subscriber**,
and a CiviCRM Individual/**MAS_Rep** contact whose **primary email is personal**
with the masadvise.org address as a **non-primary "Other"** email — plus a
`civicrm_uf_match` row linking WP user → that MASRep.

**Why WPO365 made duplicate contacts.** WPO365 has NO CiviCRM integration — it
only creates the WP user. WordPress `user_register` then triggers CiviCRM
`UFMatch::synchronize` → `matchContactOnEmail(masadvise email, 'Individual')`,
which matches ANY email on a non-deleted Individual. If the masadvise email is
already on the MASRep (as Other) it links correctly; if it's missing or
**mistyped** (saw `@masadvise.or`), CiviCRM creates a NEW contact (masadvise as
primary, no sub-type) and binds `uf_match` to that dupe. The binding is sticky —
adding the email later doesn't fix it; you must re-point `uf_match` + merge.

**Guard:** CiviCRM setting `syncCMSEmail` must be **0**, else a profile-form
submit overwrites the MASRep's personal primary with the masadvise email
(`UFMatch::updateContactEmail`).

**Fix recipe per dupe→MASRep pair:** (1) re-point `uf_match.contact_id` dupe→MASRep;
(2) ensure masadvise email is on the MASRep as Other; (3) delete the dupe's email
(removes the "Email 1 (Main)" merge conflict); (4) `cv api3 Contact.merge mode=safe`.
Custom-field conflicts (VC Status Active/Withdrawn, etc.) = a real duplicate
MASRep → hand to Brian for a field-level merge, don't auto-resolve. Exclude
system/role mailboxes (info@, serviceadmin@, treasurer@, civicrmcron@,
n8n_chatbot_user@, test*) and tech consultants (not MAS_Reps, never use the portal).

**Data sources (CLI):** Microsoft = `az ad user list` (az already authed to MAS
tenant 40f505c6…; no separate CLI needed); WordPress = `wp user list --role=subscriber`
(WP ID = uf_match.uf_id); CiviCRM = `cv api4 Email.get/UFMatch.get`, or SearchKit
display `Contacts_with_masadvise_org_Email`. NB: `cv api4 +w "is_primary=true"`
filtering is unreliable — evaluate is_primary in code.

**Skill:** `mascode/.claude/skills/mas-vc-sync/` audits + repairs this. Done
2026-06-24: merged 18 VC login-dupes, set syncCMSEmail=0. See
[[reference_civicrm_afform_mascode_gotchas]].

## feedback_ses_from_verification

MAS prod's CiviCRM outbound SMTP is Amazon SES at `ssl://email-smtp.ca-central-1.amazonaws.com:465`. SES enforces a strict policy: any `From:` header identity must be verified in the SES account (either as an individual address or as a domain). A test mailing on 2026-05-26 with `From: brian.g.flett@gmail.com` failed at SMTP send with `554 Message rejected: Email address is not verified. The following identities failed the check in region CA-CENTRAL-1`. CiviCRM logged it as a "Syntax" bounce.

**Why:** The masadvise.org domain is verified at the domain level in SES, so any `@masadvise.org` address sends fine. Personal Gmail addresses are not (and can't be — you'd need to control gmail.com's DNS).

**How to apply:**
- Newsletter From addresses must be `@masadvise.org` (verified) — `info@masadvise.org` is the safe default for general MAS mailings.
- CiviCRM's From-Email Address list (`OptionValue` in `option_group_id:name='from_email_address'`) is a CiviCRM-side curated list — having an entry there does NOT mean SES will accept it. Both layers must agree.
- When diagnosing CiviCRM mailing bounces, check `civicrm_mailing_event_bounce.bounce_reason` AND `civicrm/ConfigAndLog/CiviCRM.*.log` (often has the full SMTP response that the bounce_reason column truncated).
- For Brian's name on personal-feeling sends, add `"Brian Flett" <brian.flett@masadvise.org>` to CiviCRM's From-Email list (it'll inherit the domain-level SES verification).
