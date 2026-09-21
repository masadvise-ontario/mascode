# Afform and SearchKit Management

## Storage Strategy

**The MAS client-facing Afforms are extension-packaged base forms — they live as
`.aff.html` + `.aff.json` files in this directory and are owned by the mascode
extension.**

> **Naming note (2026-09-21).** The activity-type and case-status strings referenced by the two project-close Afforms below are frozen **machine names** and still read "Project Close". What staff see is the *label*: **Project Completion** (VC) and **Project Signoff** (client). That split is deliberate — the names are matched on by `TRANSITIONS`, `CaseStatusSet`, SearchKit filters and serialised CiviRules params, several of which no deploy rewrites. Do not "finish" the rename here; `tests/Unit/Managed/FrozenMachineNamesTest.php` will stop you, and its docblock explains why.

Packaged forms (`base_module = mascode`):

| Form | Route | Creates |
|------|-------|---------|
| `afformMASRCSForm` | `civicrm/mas-rcs-form` | Organization + Individuals + `service_request` Case |
| `afformMASSASF` | `civicrm/mas-sasf-form` | `Full Self Assessment Survey (SAS)` Activity |
| `afformMASSASS` | `civicrm/mas-sass-form` | `Short Self Assessment Survey (SAS)` Activity |
| `afformMASProjectDefinitionVC` | `civicrm/mas-pdef-vc` | `Project Definition` Activity on a Case |
| `afformMASProjectDefinitionClient` | `civicrm/mas-pdef-client` | `Project Definition - Client Authorization` Activity on a Case |
| `afformProjectCloseVCFeedback` | `civicrm/mas-pclose-vc` | `Project Close - VC Report` Activity on a Case |
| `afformProjectCloseClientFeedback` | `civicrm/mas-pclose-client` | `Project Close - Client Feedback` Activity on a Case |

Staff-facing packaged forms — read-only, create nothing, and gated rather than public:

| Form | Route | Shows | Gate |
|------|-------|-------|------|
| `afformMASSentEmailLog` | `civicrm/mas-sent-email-log` | Sent Email Log — embeds `MAS_Sent_Email_Log_Table` | `edit all contacts` |

This approach:
- Version-controls the forms with the rest of the extension
- Removes cross-environment ID drift — all pseudoconstant references are by **name**
- Deploys with the extension (`git pull` + `cv flush`), no separate file sync

### Name-based references (cross-environment safety)

The `af-entity` data blocks reference option/case-type values by **name**, not
numeric ID, using the API4 `:name` pseudoconstant suffix. Quote the colon key in
the Angular object literal:

```html
<af-entity data="{source_contact_id: 'Individual1', 'activity_type_id:name': 'Project Close - VC Report', status_id: 2, case_id: 'Case1'}" type="Activity" ... />
<af-entity data="{contact_id: 'Organization1', 'case_type_id:name': 'service_request'}" type="Case" ... />
```

The referenced activity types are themselves mascode-managed
(`Civi/Mascode/Managed/OptionValue_ActivityType_*.mgd.php`), so the names are
guaranteed stable across dev/prod. `status_id: 2` (Completed) is core/stable and
left numeric.

> **Exception:** `afformMASRCSForm.aff.json` keeps `email_confirmation_template_id: 71`.
> Afform metadata FKs have no `:name` form, and this template ID is identical in
> dev and prod, so it is left numeric.

## Editing and Deployment

**Editing in dev:** FormBuilder UI edits write a *local override* to
`wp-content/uploads/civicrm/ang/` that shadows the packaged version. To fold a UI
change back into the extension:

1. Edit the form in FormBuilder (dev).
2. Copy the changed `uploads/civicrm/ang/afform<Name>.aff.{html,json}` into this
   directory; strip per-instance metadata (`modified_date`, `created_id`, `locale`).
3. `cv ev '\Civi\Api4\Afform::revert(FALSE)->addWhere("name","=","afform<Name>")->execute();'`
   to drop the override so the packaged version is authoritative again.
4. `cv flush` and verify `base_module = mascode`, `has_local = false`.

**Deploying to prod:** `git pull` + `cv flush`. The Afform scanner auto-discovers
packaged forms — no `Managed.reconcile` needed for the forms themselves (the
managed *option values* they reference still reconcile as usual).

## Styling: the two invariants a FormBuilder round-trip can break

The seven client-facing forms share one stylesheet, `css/mas-forms.css`, carried
by the `mascodeForms` Angular module (registered in `mascode.php`'s
`hook_civicrm_angularModules`). It is what makes the submit control look like a
button, among other things — see that file's header for the Greenwich/Bootstrap
specificity story.

It only loads if **both** of these hold, and a FormBuilder round-trip (the flow
in "Editing and Deployment" above) can silently drop either:

1. The form's `.aff.json` lists `"requires": ["mascodeForms"]`.
2. The form's outer container in `.aff.html` carries `class="af-container mas-form"`
   — every rule in the stylesheet is scoped under `.mas-form` so it cannot leak
   into the rest of CiviCRM, which also means nothing applies without it.

Neither failure is loud: the form still works, it just renders unstyled, and the
submit button reverts to the grey full-width bar that a client once could not
recognise as a button at all. After any FormBuilder edit to a client form, check
both before folding the change back in:

```bash
grep -L 'class="af-container mas-form"' \
  ang/afformMAS{RCSForm,SASF,SASS,ProjectDefinitionVC,ProjectDefinitionClient}.aff.html \
  ang/afformProjectClose{VC,Client}Feedback.aff.html
grep -L 'mascodeForms' \
  ang/afformMAS{RCSForm,SASF,SASS,ProjectDefinitionVC,ProjectDefinitionClient}.aff.json \
  ang/afformProjectClose{VC,Client}Feedback.aff.json
```

Both should print nothing. (`grep -L` lists files **missing** the match.) The first
matches the full attribute rather than the bare `mas-form` substring, so a
`mas-form-*` helper class cannot satisfy it by accident. It is stricter, not
strictly better: it would also fail on a harmless rewrite such as
`class="af-container af-layout-cols mas-form"`, and it cannot tell an outer
container from an inner one. If it fires, read the file before assuming breakage. Note `grep -L` **exits 1 when it
prints nothing** — i.e. on the success path — so wrap it (`|| true`, or test the
output) before putting either line in a `set -e` script or a CI step.

## Security: public forms and caller-supplied record ids

The seven client-facing forms are `is_public: true` with
`permission: ["*always allow*"]`, and every `af-entity` on them is declared
`security="FBAC"`. That combination means their reads run with
`checkPermissions => FALSE` — the form's own configuration is intended to be the
only limit on what it returns.

**That model holds only while the form, not the caller, chooses the record id.**
`Afform.prefill` and `Afform.submit` both accept `args` straight from the
request, so before the guard described below, this returned real case data with
no cookie, no session and no `_aff` token (task #159, confirmed on production
2026-08-28):

```
POST civicrm/ajax/api4/Afform/prefill
params={"name":"afformProjectCloseClientFeedback","fillMode":"form",
        "args":{"case_id":18832}}
```

Case ids are sequential integers, so iterating them harvested every project's
client feedback. `contact_id` was worse on `afformMASRCSForm`, where the
`relationship:` autofills walked one contact id out to their employer
organisation and then to that organisation's President and Executive Director.

`Civi/Mascode/Event/AfformPublicArgGuardSubscriber.php` now requires each
caller-supplied record id on these forms to be justified. Ids that arrive inside
a signed `_aff` token are unaffected, because core injects those from the authx
session *after* the guard has run on the caller's own args.

There was a **second** disclosure of the same kind, through a different door.
`fillMode: "join"` loads a record from arbitrary caller-supplied *field values* —
no id at all, and no scoping to any parent record — so none of the five guarded
argument names appears and filtering by name could not see it. Anonymously:

```
POST civicrm/ajax/api4/Afform/prefill
{"name":"afformMASRCSForm","fillMode":"join",
 "args":{"Organization1":[{"joins":{"Address":[{"city":"Toronto"}]}}]}}
```

returned a real client street address; the same shape returned Email (an
email-existence oracle) and Phone, one record per request. It is tempting to
assume core validates this through `validateBySavedSearch()` — it does not. That
method only runs when the key field carries a `defn.saved_search`, which no MAS
form field does, and for joins it cannot run at all (core passes `$afEntity` but
tests `$entity`, so the condition is permanently false).

**Only `fillMode: "form"` is filtered; every other value is refused outright** —
an allowlist, not a list of known-bad modes. That distinction matters: core
branches on `=== 'join'` and treats *every* other value (`entity`, `''`, `null`,
`JOIN`, `xyz`) identically, so naming the bad modes would be right only by
accident. It is safe to refuse them all only because those modes exist to serve
autocomplete widgets and **none of the seven forms has one**.

A refused **read** drops the argument and the fieldset renders blank. A refused
**write** throws: there the argument *is* the record being written to, and
dropping it would create the submission attached to nothing, silently, behind a
normal confirmation screen.

**What this means when editing these forms:**

- **`autofill="entity_id"` / `case-autofill="entity_id"` is what opens the door.**
  Those attributes are what make an entity load from a caller-supplied
  `contact_id` / `case_id`. Adding one to a fieldset on a public form adds a
  record the caller can ask for by id.
- **Do not add an `autofill` input attribute to an id field** on a public form.
  Entity-named args (`Case1=N`) are inert today only because core requires that
  attribute before it will honour them; adding one makes `Case1=N` load too, and
  the guard does not cover that name.
- **Adding an autocomplete / EntityRef field to a public form breaks an
  assumption the guard depends on.** It would need `entity`/`join` mode, which is
  currently blocked wholesale. Re-read
  `Civi/Mascode/Security/AfformArgPolicy.php` before doing it.
- **`*always allow*` is what makes a form guarded — not `is_public`.** That flag
  only picks the frontend vs backend URL scheme for token links; access is
  decided by `permission` alone. A form that is `*always allow*` but not public
  is still fully reachable anonymously.
- **After deploying, re-enumerate the guarded set on the target environment.**
  The guard keys on `*always allow*`, and dev is not proof of prod:
  `cv api4 Afform.get '{"select":["name","permission"],"where":[["permission","CONTAINS","*always allow*"]]}'`
- **A URL that carries a record id into a public form needs an entitlement rule.**
  `afsearchMASCaseDetailsVC.aff.html` links to `civicrm/mas-pdef-vc` and
  `civicrm/mas-pclose-vc` with `#?case_id={{ routeParams.id }}`; that works
  because the guard reuses the VC Portal's documented predicate (pool case, or
  active Case Coordinator — `SavedSearch_Case_Details_VC.mgd.php`). A new link of
  that shape needs a matching rule, or the id will simply be dropped.
- **After changing any of these forms, re-run both checks:**
  ```bash
  tests/Security/afform-prefill-anon-probe.sh          # anonymous, real HTTP
  cv scr tests/Security/AfformPublicArgGuardTest.php --user=<a VC login>
  ```
  The probe is safe against production and is the intended post-deploy
  verification. The `cv scr` test must be run as a non-staff VC — it aborts
  rather than passing vacuously if you run it as staff.

  **"A VC login" is the part that costs time.** The test needs a
  `firstname.lastname@masadvise.org` login with a `civicrm_uf_match` row that has
  **neither** `administer CiviCRM` **nor** `edit all contacts`, and which
  actively coordinates at least one case — without that the entitlement fixtures
  cannot be discovered and the run aborts. On a current dev clone there are
  dozens: WordPress role `contributor` (the same role production VCs hold) or
  `subscriber`. Find one rather than hard-coding a name here — this repo is
  public, and a volunteer's address is not ours to publish:

  ```bash
  # one line — cv api4 will not accept the JSON argument wrapped
  cv api4 UFMatch.get '{"select":["uf_name"],"join":[["RelationshipCache AS rc","INNER",["rc.near_contact_id","=","contact_id"]]],"where":[["rc.near_relation:name","=","Case Coordinator is"],["rc.is_active","=",true],["rc.case_id","IS NOT NULL"]],"groupBy":["uf_name"],"limit":15}'
  ```

  That lists logins belonging to active Case Coordinators — **including staff**,
  so pick one that is not. Guessing wrong is cheap: the test aborts with
  "the guard exempts staff" rather than passing vacuously.

  The `groupBy` is load-bearing, not tidiness. The join is to
  `RelationshipCache`, which holds one row per coordinated case, so without it
  the limit counts *relationships* rather than logins — the first version of this
  query returned the same login five times, and that login happened to be an
  administrator, which reads as "dev has no non-staff VC". It has about thirty.

  ⚠ **A dev pass is weaker than a prod pass for the *entitlement* half**: dev
  contributors lack the `view_all_activities` / `view_all_contacts` that
  production contributors carry. The refusal half (blocked fill modes, joins,
  entity-named args) is equally strong in both.

## Security: staff-only forms and the `edit all contacts` gate

A staff-only form or dashlet is gated on **`edit all contacts`**, not on
`access all cases and activities` or bare `access CiviCRM`. The reason is what
production's WordPress roles actually carry:

| Role | `access_civicrm` | `view_all_activities` / `view_all_contacts` | `edit_all_contacts` |
|------|------------------|---------------------------------------------|---------------------|
| administrator / editor / author | yes | yes | **yes** |
| **contributor** (Volunteer Consultants) | yes | **yes** | **no** |
| subscriber | yes | no | no |

Because VCs hold `view_all_activities` **and** `view_all_contacts`, a form gated
on anything weaker than `edit all contacts` is visible to every VC, and CiviCRM's
own ACLs add no restriction on top — `addSelectWhereClause()` returns nothing to
filter by when a user holds view-all. Dev is not a reliable check here: dev VCs
do not have these capabilities, so a form that looks correctly gated in dev can
be wide open on production.

**An Afform gate alone does not close the bare SearchKit route.** A
SearchDisplay is also reachable at `civicrm/search#/display/<Search>/<Display>`,
which requires only `access CiviCRM` — so gating the Afform protects the
intended entry point and nothing else. To actually close that route, set
`'acl_bypass' => TRUE` on the SearchDisplay: core then refuses to run the
display unless it is loaded through an Afform the viewer may access
(`AbstractRunAction::_run`), and the bare route returns *Access denied* —
unless the viewer holds `all CiviCRM permissions and ACLs`, which is exempted.
The check is real, not nominal: core verifies the named Afform actually embeds
this search **and** display, and throws *Afform does not contain search display*
otherwise.

`MAS_Sent_Email_Log_Table` does this; so do the VC Portal displays.

Two consequences to respect when you use `acl_bypass`:

- The inner query runs with `checkPermissions => FALSE`, so **the Afform's
  permission becomes the only control**. Do not embed an `acl_bypass` display on
  a second, less-gated Afform — that Afform silently becomes the new boundary.
- Because ACLs no longer filter the query, every viewer sees identical rows and
  counts — and **trashed contacts and trashed cases are included**, since
  `access deleted contacts` and `administer CiviCase` no longer gate them. That
  is the intent for a staff report; it would be wrong for anything per-user,
  where the VC Portal's filter-as-security predicate is the right tool instead.

**`acl_bypass` closes a route, not the data.** It stops the SearchKit URL; it does
nothing about the capability that made the data readable in the first place. A role
holding `view all contacts` / `view all activities` can still reach equivalent
information through native CiviCRM screens — `civicrm/activity/search` and
`afsearchFindActivities` are ACL-filtered, which means unfiltered for exactly those
users, and `VcNativeScreenGuardSubscriber` deliberately waves view-all holders
through the native contact and case screens. Where that matters, **the only real fix
is trimming the capability from the role** — not adding another Afform permission.

## Replacing a person on a form (the join-id trap)

Some MAS forms let a user replace the *person* holding a role rather than edit
the one on file: the RCS form for a new President or Executive Director, and the
two VC project forms for a new client representative. The pattern is a
pre-process listener on `civi.afform.submit` at a positive priority that removes
the submitted contact `id`, so core creates a new contact instead of updating the
existing one — see `Civi/Mascode/Event/AfformSubmitSubscriber.php`.

Five things about that pattern are easy to get wrong, and none of them fails
loudly.

- **Strip the join ids along with the contact id.** The browser echoes the
  prefilled `Email` join back with the OUTGOING person's email-row id in it.
  `Afform::saveJoins()` only replaces that id when `loadJoins()` finds an existing
  row, which a brand-new contact never has, so it survives into
  `Email::replace()` — whose `BasicReplaceAction` merges the where clause
  (`contact_id` = the NEW contact) into the record as a default and **moves the
  row**. The outgoing person is left with no email address, no error is raised,
  and nothing looks wrong on screen. Applies to any `af-join` with `update`
  allowed, not just Email.
- **Do not read "who is on file" from the submitted record id.** Core's
  `ContactDedupe` behavior subscribes to the same event at priority **101**, so it
  has already run and may have rewritten that id to a contact it matched on the
  submitted values. Comparing the submitted name against it then finds them equal
  and concludes nothing changed. Read the incumbent from the authoritative source
  instead — for the client rep that is the case's own active `Case Client Rep is`
  role (`getCaseClientRepIds()`).
- **Decide what a BLANK field means, and make the code agree with the form.** A
  field left empty almost always means "no change" to the person filling it in,
  but nothing enforces that: an empty name is written as an empty string, and an
  empty `af-join` value reaches `saveJoins()` as either a blank write or — when
  the row carries no id — an `Email::delete` over the whole where clause. Test
  for blankness on the trimmed **string cast**, not with `isset()` or `!empty()`
  on the raw value: a present-but-`null` field and a present-but-empty-`array`
  join both slip past those and reach the destructive path. On the VC forms an
  incomplete name writes NEITHER half and a blank email drops the whole join.
- **Two rules decide whether a person was REPLACED or merely corrected**, and
  both are needed. A blank submitted half is "no change to that half", never
  evidence of a handover — otherwise clearing a first name to retype it creates a
  duplicate contact. And a handover is only detectable from a field that HAD a
  value to change: on the 2026-05-30 dev clone 4 of the 382 people holding an
  active `Case Client Rep is` role have an empty `last_name` (1 of the 195 whose
  role is *current*), so without that second rule a VC completing one of those
  records is misread as replacing the person.
- **Suppress the WHOLE submission, not the field you noticed.** When an edit is
  too ambiguous to act on, every field in it is equally ambiguous. Suppressing
  the incomplete name but still writing the submitted email leaves the incumbent
  holding the role with the *incoming* person's address — the harm simply moves
  to the field you did not think about. The VC forms drop the name halves and
  **every** join together whenever an incomplete name looks like an attempted
  handover — every join, not the one you happen to have in mind today, because a
  join block added later is exactly how this recurs.

All five are pinned by `tests/Unit/Event/ClientRepWiringTest.php` (runs in CI, a
source tripwire) and proved end to end by `tests/Live/ClientRepChangeTest.php`.
Every assertion in the tripwire is mutation-checked in both directions: each
invariant is broken and the assertion confirmed to fire, and each is run against
the reformattings a maintainer would plausibly apply — reordering conjuncts,
dropping redundant parens, splitting an `unset`, adding a comment — which must
NOT turn it red. That second direction is not a proof, only a check against the
edits we thought of; a source tripwire is a blunt instrument and is only worth
having because CI cannot run the live test.

One more rule the VC forms follow, worth copying: **when you cannot tell WHICH
record the user was editing, write to none of them.** A case carrying two active
client reps is ambiguous — core's autofill issues its query with no `ORDER BY`,
so which one the fieldset displayed is not knowable server-side. Declining to
move the case role is not sufficient there, because Afform's default still
updates whichever contact it autofilled; the record has to be cleared. A case in
that state has its client-rep fieldset silently ignored, and says so in the log
(`Case has multiple client reps; client rep fieldset ignored`).

## Tags

- **`Client`** — Client-facing public forms (RCS Form, Self-Assessment Surveys, Client Feedback)
- **`VC`** — Volunteer Consultant forms and searches (VC Feedback, My Cases)
- **`Dashlet`** — Dashboard SearchKit widgets (Projects by Status/Year)
- **`Admin`** — Backend administrative tools (future use)
- **`Block`** — Reusable form blocks / shared fieldsets

## Naming Convention

All custom forms must be prefixed with `afformMAS` or `afblockMAS`:
- Forms: `afformMAS{FormName}` (e.g., `afformMASRCSForm`)
- Blocks: `afblockMAS{BlockName}` (e.g., `afblockMASContactFields`)
- Searches: `afsearchMAS{SearchName}` (optional, e.g., `afsearchMASProjects`)

SearchKit searches may still be managed via the UI Export/Import (Search → Manage
Searches) where file-packaging is not warranted.
