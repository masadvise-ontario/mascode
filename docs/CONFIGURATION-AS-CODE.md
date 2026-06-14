# Configuration as Code

How MAS ships CiviCRM configuration from dev to production. The goal: configure once in dev, commit, and let prod converge on `git pull` + the standard deploy ritual — no manual re-creation of config in the prod UI.

**The standard (decided 2026-06-12): all new configuration ships as managed entities** (`Civi/Mascode/Managed/*.mgd.php`) or version-controlled files. Legacy deploy scripts are frozen and migrated opportunistically when touched.

## The Six Channels

Every piece of MAS CiviCRM customization reaches prod through exactly one of these:

| # | Channel | Lives at | Reaches prod via | Examples |
|---|---------|----------|------------------|----------|
| 1 | **Managed entities** (the standard) | `Civi/Mascode/Managed/*.mgd.php` | `cv flush` (managed reconcile) | Case types (ServiceRequest, Project), activity-type + case-status option values, custom fields, tags, lifecycle message templates, SearchKit saved searches/displays (Ops + Board dashboards) |
| 2 | **File-backed Afforms** | `ang/*.aff.{html,json}` | `cv flush` (file rescan) | RCS / SASS / SASF forms, ProjectClose feedback forms, Ops/Cases/Board dashboards and dashlets |
| 3 | **PHP code** | `Civi/Mascode/`, `CRM/Mascode/` | active on pull (`cv flush` rebuilds container) | CiviRules action classes, event subscribers, `LifecycleMailer`, `CaseMoveService`, `CodeGenerator`, `PatchManager` |
| 4 | **CiviRules component registration** | `Civi/Mascode/CiviRules/{triggers,actions,conditions}.json` | `PostInstallOrUpgradeHook` — fires on extension install and after **core** upgrades only (⚠ NOT on `cv flush`) | `mas_create_project_from_sr`, `mas_generate_mas_code`, relationship actions |
| 5 | **Upgrader steps** | `CRM/Mascode/Upgrader.php` (`upgrade_NNNN`) | `cv ext:upgrade-db` | `upgrade_5001` case_status weight reorder |
| 6 | **One-off scripts** | `scripts/*.php` | manual `cv scr`, called out in release notes | `register-lifecycle-email-action.php`, `create-rcs-chase-rule.php`, `create-close-chase-rule.php` |

## Standard Prod Deploy Ritual

All steps are idempotent — run all of them every deploy, even when you think nothing is pending:

```bash
ssh mas-prod
cd <civicrm-ext>/mascode
git pull origin master
cv ext:upgrade-db          # pending upgrade_NNNN steps
cv flush                   # managed-entity reconcile + ang/ rescan + container rebuild
# only if release notes call for it:
cv scr scripts/<one-off>.php --user=admin
```

Why `ext:upgrade-db` is always in the ritual: it is the **only** thing that runs `upgrade_NNNN` steps, and it's a no-op when nothing is pending. Why `pull + flush` alone is not enough: channels 4 and 5 don't fire on flush. The lifecycle email action gap (see History) came from exactly this.

## Where form answers live: the case, not the activity

Convention (2026-06-14): a form's answers are stored as custom fields on the
**case** (one value per engagement — there's one definition / one close / one
sign-off per project), with the submission **activity** kept only as the
timeline + CiviRules trigger marker (no custom fields on it). This gives a
single source of truth visible on the case, lets one form display what another
captured (the client authorization form shows the VC's `Project_Definition`
case fields read-only), and keeps reporting on case fields. **Exception:** the
SSAS/FSAS self-assessment surveys stay as **activity** custom groups — they
assess the org and recur over time, so each dated submission is worth keeping.

## Authoring Flows (dev side)

**Managed entity** (option value, message template, saved search, custom field, case type):
1. Optionally build it in the dev UI first (SearchKit, message template editor, etc.)
2. Export to a `.mgd.php` file — e.g. `cv api4 SavedSearch.export +w name=MAS_Board_QTD`, or pattern-match an existing file in `Civi/Mascode/Managed/`
3. Choose policies deliberately (see below), `cv flush`, verify dev still looks right, commit

**Afform**: edit in FormBuilder (writes to `wp-content/uploads/civicrm/ang/`) or edit the file directly for surgical fixes → copy the `.aff.html` + `.aff.json` pair into the extension's `ang/` → `cv flush` → commit. The file is the source of truth.

**CiviRules action/trigger/condition**: PHP class in `Civi/Mascode/CiviRules/`, entry in the matching `.json` file, form + template in the legacy `CRM/Mascode/CiviRules/Form/` namespace. ⚠ Because channel 4 doesn't fire on flush, also add an `upgrade_NNNN` step that calls the JSON insert (or registers the component idempotently) so prod picks it up via `ext:upgrade-db`.

**CiviRules rule** (the trigger+condition+action assembly): currently an idempotent `scripts/create-*.php` run once per environment. Direction (agreed 2026-06-12): move toward zero-touch — register rules from code in `upgrade_NNNN` steps so no manual `cv scr` is needed. Until then, every new rule ships with a versioned, idempotent creation script, never UI-only.

### Managed entity policies

Current usage mixes two `update` policies — choose deliberately:

- `'update' => 'always'` — code wins; prod UI edits to the entity are overwritten on reconcile. Use for things only developers should touch (option values, case types, custom fields).
- `'update' => 'unmodified'` — code updates the entity only until someone edits it in the UI; after that, the UI version sticks. Use for things staff may legitimately tweak in prod (message template wording, dashboard searches).
- `'cleanup' => 'unused'` vs `'never'` — whether the entity is removed when the `.mgd.php` disappears. Prefer `'unused'`; use `'never'` for entities with data riding on them (activity types, case statuses).

Caveat: with `'unmodified'`, a prod-side UI edit silently pins the entity — later code changes stop applying and nothing warns you. **Run `scripts/check-managed-drift.php`** (read-only) to list managed entities whose UI edits will be ignored by the reconcile, plus afforms whose shipped `ang/` file is shadowed by a site override — run it in prod after a deploy. CiviCRM's underlying signals: `civicrm_managed.entity_modified_date` for managed entities, `Afform.get` `has_local`/`has_base` for forms.

**Afforms are NOT managed entities** — and shouldn't be. They're file-backed in `ang/` (channel 2), which is CiviCRM's purpose-built form packaging: FormBuilder reads/writes these files and the Afform API tracks them by `base_module`. They migrate on `cv flush` like managed entities. The drift analog: a prod FormBuilder edit writes a site-level **local override** (`has_local`) that shadows the extension's shipped **base** file — revert the local override to let the committed `ang/` version show again.

## What Is Still Manual

- **FormProcessors** — CiviCRM export/import UI per `scripts/deploy_form_processors.md`
- **CiviRules rule activation** — rules ship disabled/propose-mode; enabling chase automation in prod is a deliberate manual act
- **Scheduled job configuration** — job frequency/enablement set in each environment's UI
- **WordPress-side config** — pages embedding forms, Elementor, menus
- **Legacy config** predating the managed-entity standard — anything originally created via `scripts/deploy_custom_fields.php` / `scripts/deploy_civirules.php`; frozen, migrate to `.mgd.php` when next touched

## Known Gaps & Direction (2026-06-12)

1. **CiviRules JSON registration doesn't fire on flush** (channel 4) — new actions/triggers/conditions need an accompanying `upgrade_NNNN`. Candidate improvement: wire `PostInstallOrUpgradeHook::installCiviRulesComponents()` into the extension's own upgrader so every `ext:upgrade-db` re-syncs the JSON.
2. **Rule assemblies are script-based** — target is zero-touch registration (see above).
3. **`CRM_Mascode_Upgrader` docblock says "not currently used"** — stale; it carries `upgrade_5001` and is now a first-class channel.
4. **Legacy deploy scripts** (`deploy_custom_fields.php`, `deploy_civirules.php`) — superseded as a pattern; do not add to them.

## History (why it's shaped this way)

Before the lifecycle work (June 2026), config was built manually in dev and then re-built manually in prod — double work and drift risk. The engagement-lifecycle + dashboards build (Phases 1–2, 2026-06-08/09) moved MAS to managed entities + file-backed Afforms as the primary channels; the `mas_lifecycle_email` action — originally UI-created in dev and therefore invisible to git — surfaced the channel-4 gap and was retro-versioned via `scripts/register-lifecycle-email-action.php`.

---
*Companion docs: [DEPLOYMENT.md](DEPLOYMENT.md) (the ritual in checklist form), [ARCHITECTURE.md](ARCHITECTURE.md) (code structure), [PRODUCTION-OPS.md](PRODUCTION-OPS.md) (prod access).*

*Last Updated: 2026-06-12*
