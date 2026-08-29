# Afform and SearchKit Management

## Storage Strategy

**The MAS client-facing Afforms are extension-packaged base forms — they live as
`.aff.html` + `.aff.json` files in this directory and are owned by the mascode
extension.**

Packaged forms (`base_module = mascode`):

| Form | Route | Creates |
|------|-------|---------|
| `afformMASRCSForm` | `civicrm/mas-rcs-form` | Organization + Individuals + `service_request` Case |
| `afformMASSASF` | `civicrm/mas-sasf-form` | `Full Self Assessment Survey (SAS)` Activity |
| `afformMASSASS` | `civicrm/mas-sass-form` | `Short Self Assessment Survey (SAS)` Activity |
| `afformProjectCloseVCFeedback` | `civicrm/mas-pclose-vc` | `Project Close - VC Report` Activity on a Case |
| `afformProjectCloseClientFeedback` | `civicrm/mas-pclose-client` | `Project Close - Client Feedback` Activity on a Case |

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

`fillMode` `entity` and `join` are therefore **blocked outright** on these forms.
That is safe only because those modes exist to serve autocomplete widgets and
**none of the seven forms has one**.

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
