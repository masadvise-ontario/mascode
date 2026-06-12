# MASCode Extension Development Guide

**Note:** When updating key project information in this file, also update the summary in `/home/brian/workspace/claude/context/mas-claude-context/claude-code/projects/mascode.md`

## Quick Reference

- **Framework**: CiviCRM on WordPress
- **Branch**: master (single branch workflow)
- **Database Credentials**: `/home/brian/.config/development/databases.env`
- **CV Binary**: `/home/brian/buildkit/bin/cv --user=admin`
- **Cache Clear**: `/home/brian/buildkit/bin/cv flush` (run after all code changes)

## Development Approaches

### When to Use Each Pattern

1. **Afform Relationships** - Automatic relationship creation on form submission
   - **See**: [docs/AFFORM-RELATIONSHIPS.md](docs/AFFORM-RELATIONSHIPS.md)
   - For: RCS form, Survey forms
   - Pattern: Event subscribers handle post-submission relationship creation

2. **CiviRules** - Trigger-based automation
   - Best for: Automated responses to CiviCRM events, business rules
   - Pattern: Actions extend `CRM_CivirulesActions_Generic_Api`
   - Database: Tables start with `civirules_`

3. **Symfony Events** - Complex business logic
   - Best for: System integration, decoupled architecture
   - Pattern: Subscribers in `Civi/Mascode/Event/`, extend `AutoSubscriber`

4. **FormProcessor** - External data processing
   - Best for: WordPress forms, data import/export
   - Pattern: Actions in `Civi/Mascode/FormProcessor/Action/`

5. **CRM Forms** - Administrative functions
   - Best for: Backend admin tools, complex validation
   - Location: `CRM/Mascode/Form/` + `templates/CRM/Mascode/Form/`

### Form Development

**Afforms (Preferred for public/user forms)**:
- **Managed in Database** - Create/edit in FormBuilder UI
- Naming: Prefix with "afformMAS" (e.g., `afformMASRCSForm`)
- Deployment: Manually replicate or use API4 export/import
- **See**: "Afform Management" section below

**CRM Forms (For admin tools)**:
- Traditional approach for backend functionality
- Complex validation and business logic
- File-based in `CRM/Mascode/Form/` + `templates/`

## API4 Patterns (CRITICAL)

**For complete API4 patterns, CV commands, and code verification protocol, see:**
**[/home/brian/workspace/claude/context/mas-claude-context/claude-code/global/protocols/api4.md](/home/brian/workspace/claude/context/mas-claude-context/claude-code/global/protocols/api4.md)**

**Quick Reference:**
- **ALWAYS use CiviCRM API4** - NEVER use direct SQL
- **Use FALSE** as first parameter to suppress permissions
- **Use names, not IDs** for cross-environment compatibility
- **Common pattern**: `\Civi\Api4\EntityName::action(FALSE)->addWhere()->execute()`
- **Verify first**: Always check source code before using APIs

## Afform Management

**File-backed storage**: Afforms live as `.aff.html` + `.aff.json` files at:
```
wp-content/uploads/civicrm/ang/afform<Name>.aff.{html,json}
```

FormBuilder UI edits write to these files. The file IS the source of truth — `Afform.get` returns the parsed file content. No DB-only override layer.

**Deployment**:
- Edit via FormBuilder UI in dev (or directly in the .aff.html file for surgical fixes)
- Sync the `.aff.html` (and `.aff.json` if metadata changed) to the same path on prod
- Run `cv flush` on prod after the file is in place

**Key Principles**:
- **Naming**: Always prefix with "afformMAS"
- **Field References**: Use names, never IDs (custom fields, relationships, etc.)
- **Tags**: Use Client, VC, Dashlet, Admin, or Block tags (see `ang/README.md`)
- **Cache**: Run `cv flush` after any changes
- **Boolean Select fields**: Use `id: false` / `id: true` (real booleans), NOT `id: '0'` / `id: '1'` (strings). Core's `afField.component.js` casts `!!option.id` for Boolean data_type — string "0" becomes truthy, breaking the field. Case history: [docs/AFFORM-RELATIONSHIPS.md](docs/AFFORM-RELATIONSHIPS.md) "Field gotchas".

## CV Command Patterns

**See [protocols/api4.md](/home/brian/workspace/claude/context/mas-claude-context/claude-code/global/protocols/api4.md) for complete CV command reference**

**Quick commands:**
```bash
cv flush  # ALWAYS after code changes
cv scr /path/to/script.php --user=admin
XDEBUG_SESSION=1 cv scr /path/to/script.php --user=admin
```

## Documentation Map

**Core Development**:
- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) - Extension architecture and components
- [docs/CONFIGURATION-AS-CODE.md](docs/CONFIGURATION-AS-CODE.md) - How config ships dev → prod (six channels, deploy ritual)
- [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md) - Development workflow and deployment
- [docs/INSTALLATION.md](docs/INSTALLATION.md) - Setup and installation

**Feature-Specific**:
- [docs/AFFORM-RELATIONSHIPS.md](docs/AFFORM-RELATIONSHIPS.md) - Automatic relationship creation (RCS form)
- [docs/TESTING.md](docs/TESTING.md) - Testing framework and practices
- [docs/VC_ACL_SETUP_INSTRUCTIONS.md](docs/VC_ACL_SETUP_INSTRUCTIONS.md) - Volunteer Consultant ACL setup
- [docs/PRODUCTION-OPS.md](docs/PRODUCTION-OPS.md) - Production operations

**Reference**:
- [docs/CONTRIBUTING.md](docs/CONTRIBUTING.md) - Contribution guidelines
- [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) - Production deployment process
- [docs/NONPROFIT-TECH-CONSULTING.md](docs/NONPROFIT-TECH-CONSULTING.md) - MAS consulting guidance (CiviCRM standards, WP integration, n8n automation, nonprofit AI adoption); detailed references in [docs/consulting/](docs/consulting/). Demoted from the global Claude Code skill 2026-06-03.

## Code Verification Protocol

**See [protocols/api4.md](/home/brian/workspace/claude/context/mas-claude-context/claude-code/global/protocols/api4.md#api-and-code-verification-critical) for complete verification procedures**

**Critical:** Before using any CiviCRM entities, actions, or methods, verify they exist in source code - never assume APIs exist

## PHP Code Standards

- **Auto-formatter**: PHP Intelephense (PSR-12)
- Lowercase booleans: `false`, `true`, `null`
- Array syntax: `array()` not `array ()`
- Files auto-format on save

## Database Access

**Credentials**: `/home/brian/.config/development/databases.env`

**Development**:
- WordPress DB: `MASDEMO_WP_DB_NAME`
- CiviCRM DB: `MASDEMO_CIVI_DB_NAME`
- User/Pass: `MYSQL_ROOT_USER`, `MYSQL_ROOT_PASSWORD`

**Production**: see "Production Access (Safe Inspection)" section below. Default to the read-only DB user (`PROD_READONLY_*` in `.env`). The writable `mas_mas` user is in memory but should NOT be used without explicit Brian-approved write intent.

## Configuration as Code

**All new CiviCRM config ships as managed entities** (`Civi/Mascode/Managed/*.mgd.php`) or version-controlled files — never UI-only. Full model (six channels, authoring flows, update/cleanup policies): [docs/CONFIGURATION-AS-CODE.md](docs/CONFIGURATION-AS-CODE.md).

Quick decision rule for new config:
- Option value / custom field / case type / tag / message template / SearchKit search → `.mgd.php` managed entity
- Form / dashboard / dashlet → file-backed Afform in `ang/`
- CiviRules action/trigger/condition → PHP class + JSON entry **+ an `upgrade_NNNN` step** (JSON registration does NOT fire on `cv flush`)
- CiviRules rule assembly → idempotent `scripts/create-*.php` (direction: move into upgrade steps)
- One-time config/data migration → `upgrade_NNNN` in `CRM/Mascode/Upgrader.php`

Legacy `scripts/deploy_custom_fields.php` / `deploy_civirules.php` are frozen — don't extend them.

## Release Process

1. Update version/releaseDate in `info.xml`
2. Commit and push to GitHub master branch
3. On prod (all idempotent — run every deploy): `git pull origin master` → `cv ext:upgrade-db` → `cv flush` → any release-noted `cv scr scripts/<one-off>.php`

**Manual deployment only** - no automated releases. ⚠ `pull + flush` alone skips `upgrade_NNNN` steps and CiviRules JSON registration — always include `ext:upgrade-db`. See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).

## Common Commands

```bash
# Cache management (ALWAYS after code changes)
/home/brian/buildkit/bin/cv flush

# View extension status
/home/brian/buildkit/bin/cv ext:list | grep mascode

# Debug script execution
XDEBUG_SESSION=1 /home/brian/buildkit/bin/cv scr <script> --user=admin
```

## Production Access (Safe Inspection)

**Follow the shared protocol:** [protocols/production-access.md](/home/brian/workspace/claude/context/mas-claude-context/claude-code/global/protocols/production-access.md) — SSH-tunnel readonly inspection, the per-turn prod-write approval rule, and the hard rules. Environment details + full command reference: [docs/PRODUCTION-OPS.md](docs/PRODUCTION-OPS.md). Afform/Playwright inspection patterns and the dev-only cookie-injection recipe: PRODUCTION-OPS.md "Browser inspection" section.

## Session Lifecycle

- **Start**: `/bootstrap` only for landscape sessions (per klaus CLAUDE.md decision rule); dispatched task sessions start working directly
- **End**: `/wrapup` for substantive sessions (logs summary to Postgres, handles handoffs, checks git)

Klaus capabilities are provided via the globally available `klaus-workflows`, `bootstrap`, and `wrapup` skills.

**Project-local skills** (under `.claude/skills/`):
- `mas-clone` — clone production database to dev (mature ~340-line skill with parity check, backup, dump, transform, verify)
- `mas-deploy` — push mascode/maswpcode changes to production

**Session-start parity prompt**: When starting work in this directory, ask Brian whether to check dev/prod drift first. Quick gauge:
- `git log --oneline -5` (dev) vs `ssh mas-prod 'cd .../mascode && git log --oneline -5'` — should match
- File timestamps: `civicrm/ang/afform*.aff.html` should be identical
- Last `mas_dev_*_*.sql` in `~/backup/` shows last clone date

If drift looks meaningful (Civi version mismatch, code drift, or investigating a contact-state-dependent bug), suggest `/mas-clone`. Otherwise proceed without cloning. Cadence guideline: quarterly anti-rot, plus before any data-state-dependent investigation.

---

## Need More Detail?

Refer to the appropriate documentation file in `docs/` based on the area you're working on. This file provides quick reference; detailed docs contain comprehensive information for specific features and workflows.

---

**Last Updated**: 2026-06-12
