# Development Guide

## Quick Start

### Prerequisites
- CiviCRM 6.1+ with CiviRules extension enabled
- PHP 8.3+
- Development environment with XDebug

### Installation
```bash
# Clone to CiviCRM extensions directory
git clone https://github.com/briangflett/mascode.git

# Enable extension
cv ext:enable mascode

# Verify installation
cv ext:list | grep mascode
```

## Development Workflow

### Development to Production Process

1. **Develop in Development Environment**
   - Make changes in `/home/brian/buildkit/build/masdemo/web/wp-content/uploads/civicrm/ext/mascode/`
   - Test thoroughly using development environment
   - Use `cv flush` after code changes
   - Use `XDEBUG_SESSION=1 cv scr <script>` for debugging

2. **Ship the change as code, not as a UI act**
   New CiviCRM configuration never stays UI-only. Pick its channel:

   | New thing | Ships as |
   |-----------|----------|
   | Option value, custom field, case type, tag, message template, SearchKit search/display | `Civi/Mascode/Managed/*.mgd.php` |
   | Form, dashboard, dashlet | file-backed Afform in `ang/` |
   | CiviRules action/trigger/condition | PHP class + entry in `Civi/Mascode/CiviRules/*.json` **plus** an `upgrade_NNNN` step (JSON registration does not fire on `cv flush`) |
   | CiviRules rule assembly | `ensure*()` provisioner called from an `upgrade_NNNN` step, with an idempotent `scripts/create-*.php` for fresh installs |
   | One-time config or data migration | `upgrade_NNNN` in `CRM/Mascode/Upgrader.php` |

   Full model: [CONFIGURATION-AS-CODE.md](CONFIGURATION-AS-CODE.md).

3. **Commit and Push to Master**
   ```bash
   git add .
   git commit -m "Description of changes"
   git push origin master
   ```
   Single-branch workflow — `master` is the only long-lived branch. Use short-lived
   feature branches for larger work. Bump `version` and `releaseDate` in `info.xml` for
   releases.

4. **Deploy to Production Environment**
   All steps are idempotent — run all of them every deploy, even when nothing looks pending:
   ```bash
   # Pull latest changes in production
   git pull origin master

   # Apply pending upgrade_NNNN steps (no-op if none)
   cv upgrade:db

   # Reconcile managed entities, rescan ang/, rebuild container
   cv flush

   # Only if the release notes call for it
   cv scr scripts/<one-off>.php --user=<wp-admin-login>

   # For Form Processors, follow manual process in:
   # scripts/deploy_form_processors.md
   ```
   ⚠ `git pull` + `cv flush` alone is **not** a complete deploy: `upgrade_NNNN` steps only
   run via `upgrade:db`, and CiviRules JSON registration doesn't fire on flush at all.

   ⚠ `scripts/deploy_custom_fields.php` and `scripts/deploy_civirules.php` are **frozen** —
   they predate the managed-entity standard. Don't run them and don't extend them.

5. **Production Deployment Checklist**
   - [ ] `cv ext:list | grep mascode` shows the new version
   - [ ] `cv upgrade:db` ran (not just `cv flush`)
   - [ ] Spot-check a deployed artifact — dashboard renders, new option value present
   - [ ] `cv scr scripts/check-managed-drift.php --user=<wp-admin-login>` shows no
         unexpected pinned entities or shadowed Afforms
   - [ ] Monitor CiviCRM logs for managed-reconcile or upgrade errors

   See [DEPLOYMENT.md](DEPLOYMENT.md) for the checklist form of this ritual.

### Essential Commands
```bash
cv flush                              # Clear cache; reconcile managed entities, rescan ang/
cv upgrade:db                     # Apply pending upgrade_NNNN steps
XDEBUG_SESSION=1 cv scr <script>     # Run scripts with debugging
cv api4 EntityName.action            # Test API calls
```

### Code Standards
- **API**: Use CiviCRM API4 exclusively
- **Classes**: PSR-4 in `Civi/Mascode/` namespace
- **Forms**: FormBuilder (Afform) preferred over Smarty
- **Events**: EventDispatcher over traditional hooks
- **Standards**: PSR-1/2/4, CiviCRM conventions

### Adding CiviRules Components

#### New Action
1. Create action class in `Civi/Mascode/CiviRules/Action/`
2. Extend `CRM_CivirulesActions_Generic_Api`
3. Register in `Civi/Mascode/CiviRules/actions.json`
4. Create form in `CRM/Mascode/CiviRules/Form/` (legacy namespace)
5. Create template in `templates/CRM/Mascode/CiviRules/Form/`
6. **Add an `upgrade_NNNN` step** in `CRM/Mascode/Upgrader.php` that registers the
   component idempotently — the `.json` files are read by `PostInstallOrUpgradeHook`, which
   fires on extension install and after **core** upgrades only, *not* on `cv flush`. Without
   the upgrade step the action never reaches an existing prod install.
7. Verify: `cv upgrade:db && cv flush`, then
   `cv api4 CiviRulesAction.get +w 'name=<your_action>' --user=<wp-admin-login>`

#### New Event Subscriber
1. Create in `Civi/Mascode/Event/`
2. Implement `EventSubscriberInterface`
3. Register in container with `event_subscriber` tag

### Testing
- Always run `cv flush` after code changes
- Test in development environment before production
- Use XDebug for debugging complex issues
- Check logs: `tail -f path/to/CiviCRM.*.log`

### Troubleshooting
- **Container issues**: Check service definitions and dependencies
- **Events not firing**: Verify subscriber registration and event names
- **Cache problems**: Run `cv flush` and check file permissions
- **Form errors**: Verify template paths and form class inheritance

## Extension Structure
See [ARCHITECTURE.md](ARCHITECTURE.md) for detailed component documentation.

## Resources
- [CiviCRM API4 Docs](https://docs.civicrm.org/dev/en/latest/api/v4/)
- [CiviRules Documentation](https://civirules.org/)
- [Symfony EventDispatcher](https://symfony.com/doc/current/components/event_dispatcher.html)