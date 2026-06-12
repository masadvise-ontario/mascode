# Deployment Guide

## Overview

MAS configuration ships as code and auto-migrates to production via the standard deploy ritual below. The full model — six channels, authoring flows, managed-entity policies — is in [CONFIGURATION-AS-CODE.md](CONFIGURATION-AS-CODE.md). This page is the checklist.

## Standard Production Deployment

All steps are idempotent — run all of them every deploy:

```bash
# 1. SSH to production
ssh mas-prod

# 2. Pull latest code
cd <civicrm-ext>/mascode
git pull origin master

# 3. Run pending upgrade steps (no-op if none)
cv ext:upgrade-db

# 4. Reconcile managed entities, rescan ang/, rebuild container
cv flush

# 5. Only if the release notes call for it — one-off scripts
cv scr scripts/<one-off>.php --user=admin
```

⚠ `git pull + cv flush` alone is **not** a complete deploy: `upgrade_NNNN` steps only run via `ext:upgrade-db`, and CiviRules JSON registration doesn't fire on flush at all (see CONFIGURATION-AS-CODE.md, channel 4).

## What Migrates Automatically

| Component | Mechanism | Deploy action |
|-----------|-----------|---------------|
| Case types, option values, custom fields, tags, message templates, SearchKit searches/displays | Managed entities (`Civi/Mascode/Managed/`) | `cv flush` |
| Afforms — forms, dashboards, dashlets | Files in `ang/` | `cv flush` |
| PHP behavior — CiviRules actions, subscribers, services | Code | `git pull` (+ flush) |
| Config/data migrations on existing installs | `upgrade_NNNN` in Upgrader | `cv ext:upgrade-db` |
| CiviRules component definitions (JSON) | `PostInstallOrUpgradeHook` | install / core upgrade — pair new entries with an `upgrade_NNNN` |

## What Is Still Manual

- **CiviRules rule assemblies** — versioned `scripts/create-*.php`, run once per environment (direction: move into upgrade steps)
- **Form Processors** — CiviCRM export/import UI, see `scripts/deploy_form_processors.md`
- **Rule activation / scheduled-job config / WordPress-side config** — deliberate per-environment acts

Legacy scripts `deploy_custom_fields.php` and `deploy_civirules.php` are frozen — do not extend them; new config ships as managed entities.

## Release Discipline

1. Bump `version` + `releaseDate` in `info.xml` every release
2. Any config change that can't be a managed entity gets an `upgrade_NNNN` step — not a memo to run a script
3. If a one-off script is unavoidable, make it idempotent and name it in the release/commit notes
4. Commit and push to `master`, then run the ritual on prod

## Post-Deploy Verification

- [ ] `cv ext:list | grep mascode` shows the new version
- [ ] Spot-check a deployed artifact (dashboard renders, new option value present)
- [ ] Monitor CiviCRM log for managed-reconcile or upgrade errors

See [PRODUCTION-OPS.md](PRODUCTION-OPS.md) for production access patterns and safety rules.

---

*For development workflow, see [DEVELOPMENT.md](DEVELOPMENT.md). For the configuration model, see [CONFIGURATION-AS-CODE.md](CONFIGURATION-AS-CODE.md).*

*Last Updated: 2026-06-12*
