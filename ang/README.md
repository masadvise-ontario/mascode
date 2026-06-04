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
