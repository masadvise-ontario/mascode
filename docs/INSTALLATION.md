# Installation Guide

## Requirements

### System Requirements

- **CiviCRM**: 6.1 or higher
- **PHP**: 8.3 or higher (8.1+ minimum)
- **MySQL**: 8.0 or higher  
- **CMS**: WordPress, Drupal, or Joomla
- **Web Server**: Apache or Nginx with SSL support

### Required Extensions

- **CiviRules** (org.civicoop.civirules) - Required for automation and business logic
- **Afform** (built into CiviCRM 6.1+) - Required for Self Assessment Surveys and RCS form

### Recommended Extensions

- **Action Provider** - Enhanced FormProcessor integration
- **FormProcessor** - Advanced form actions and workflows
- **SearchKit** - Enhanced reporting (CiviCRM 6.1+ core)
- **Form Builder** - Advanced form creation capabilities

## Installation Methods

### Method 1: Git Clone (Development/Production)

```bash
# Clone to CiviCRM extensions directory
cd /path/to/civicrm/extensions/
git clone https://github.com/briangflett/mascode.git

# Enable extension
cv ext:enable mascode

# Verify installation
cv ext:list | grep mascode
```

### Method 2: Extension Manager (When Available)

1. In CiviCRM, go to **Administer → System Settings → Extensions**
2. Click **Add New** tab
3. Search for "mascode"
4. Click **Download** then **Install**

### Method 3: Manual Download

```bash
# Download latest release
cd /path/to/civicrm/extensions/
wget https://github.com/briangflett/mascode/releases/latest/download/mascode.tar.gz
tar -xzf mascode.tar.gz

# Enable via CiviCRM
cv ext:enable mascode
```

## Post-Installation Configuration

### Verify Dependencies

```bash
# Check CiviRules is installed and enabled
cv ext:list | grep civirules

# Verify API4 is available
cv api4 --help

# Test cache clearing
cv flush
```

### Initial Component Deployment

Most configuration ships as code and installs itself:

- `cv ext:enable mascode` registers the CiviRules actions, triggers and conditions from
  `Civi/Mascode/CiviRules/*.json` (via `PostInstallOrUpgradeHook`) and seeds the
  `mascode_admin_contact_id` setting.
- `cv flush` reconciles the managed entities in `Civi/Mascode/Managed/*.mgd.php` — case
  types, activity types, case statuses, custom fields, tags, message templates, SearchKit
  saved searches and displays — and rescans the file-backed Afforms in `ang/`.

```bash
# Reconcile managed entities and rescan ang/
cv flush

# Confirm the managed entities landed
cv api4 Managed.get +w 'module=mascode' +s 'entity_type,name' +l 5 --user=<wp-admin-login>
```

#### Fresh installs: bootstrap the CiviRules rule assemblies

⚠ One thing does **not** self-install: the CiviRules *rule assemblies* (the
trigger + condition + action wiring for the lifecycle chase and propose rules). Existing
installs receive these from `upgrade_NNNN` steps via `cv upgrade:db`, but a brand-new
install never runs them — CiviCRM stamps `schema_version` to the newest revision at
install time (`CRM_Extension_Upgrader_Base::onPostInstall()`), so every step is already
marked applied.

On a brand-new environment, run the idempotent bootstrap scripts. The action registration
must come first; the rules depend on it:

```bash
# 1. Register the mas_lifecycle_email action the rules depend on
cv scr scripts/register-lifecycle-email-action.php --user=<wp-admin-login>

# 2. Assemble the lifecycle rules
cv scr scripts/create-pd-rules.php              --user=<wp-admin-login>
cv scr scripts/create-rcs-chase-rule.php        --user=<wp-admin-login>
cv scr scripts/create-close-chase-rule.php      --user=<wp-admin-login>
cv scr scripts/create-vc-close-chase-rule.php   --user=<wp-admin-login>
cv scr scripts/create-vc-close-propose-rule.php --user=<wp-admin-login>

cv flush
```

`--user` takes a real WordPress `user_login` that has a `civicrm_uf_match` row — there is
no `admin` user, and omitting the flag runs anonymously (reads return zero rows).

⚠ The legacy `scripts/deploy_custom_fields.php` and `scripts/deploy_civirules.php` are
**frozen**. They predate the managed-entity standard, are not part of installation, and
must not be extended — the config they once created now lives in
`Civi/Mascode/Managed/`. See [CONFIGURATION-AS-CODE.md](CONFIGURATION-AS-CODE.md) for the
full six-channel model.

### Form Processor Setup (If Using)

1. Install FormProcessor extension if not already installed
2. Follow manual import process in `scripts/deploy_form_processors.md`
3. Configure any Form Processor webhooks or integrations

### Environment-Specific Configuration

Managed entities and Afforms reference everything **by name**, not by ID, so there are no
per-environment IDs to edit — the same committed config works in dev and prod unchanged.
What genuinely differs between environments:

#### Development Environment
- Default configuration works out of the box after the steps above
- Test all forms and confirm submissions create the expected cases and activities

#### Production Environment
- **WordPress-side config** — pages embedding the forms, Elementor layouts, menus, and
  form redirect URLs for the production domain
- **Scheduled jobs** — frequency and enablement are set per environment in the CiviCRM UI
- **Form Processors** — imported through the CiviCRM UI per environment
- **Lifecycle email mode** — ships as `auto` (emails send immediately). To run an
  environment in `propose` mode instead:
  `MASCODE_LIFECYCLE_MODE=propose cv scr tools/set_lifecycle_email_mode.php --user=<wp-admin-login>`
- Configure SSL certificates for secure form submission
- Test anonymous form access from external networks

After any config change reaches prod via `git pull`, run
`scripts/check-managed-drift.php` (read-only) to catch managed entities whose prod UI
edits will be silently ignored by the reconcile.

## Verification Steps

### 1. Extension Status
```bash
# Verify extension is enabled
cv ext:list | grep mascode

# Check for any extension errors
cv ext:refresh
```

### 2. CiviRules Integration
```bash
# List CiviRules actions (should include MAS actions)
cv api4 CiviRulesAction.get | grep mas

# List CiviRules triggers (should include MAS triggers)
cv api4 CiviRulesTrigger.get | grep mas
```

### 3. Form Accessibility
- **SASS Form**: Navigate to Short Self Assessment Survey URL
- **SASF Form**: Navigate to Full Self Assessment Survey URL  
- **RCS Form**: Navigate to Request for Consulting Services URL
- Verify forms load properly and submit successfully

### 4. Case Management
- Create a test Service Request case
- Verify MAS code generation (format: R25XXX)
- Change status to "Project Created"
- Verify Project case creation (format: P25XXX)

## Troubleshooting

### Common Installation Issues

**Extension won't enable**
- Check PHP version compatibility (8.3+ recommended)
- Verify CiviCRM version (6.1+ required)
- Check file permissions on extension directory
- Review CiviCRM log files for specific errors

**CiviRules not working**
- Ensure CiviRules extension is installed and enabled
- Check CiviRules log for rule execution
- Verify trigger conditions are met
- Clear cache with `cv flush`

**Forms not accessible**
- Check public access permissions
- Verify URL routing configuration
- Test from external network (for anonymous access)
- Review web server error logs

**Managed entity or Afform didn't appear**
- Run `cv flush` — the managed reconcile and `ang/` rescan only happen on flush
- Check `cv api4 Managed.get +w 'module=mascode'` for the entity
- Run `scripts/check-managed-drift.php` — a prior UI edit can pin an
  `'update' => 'unmodified'` entity so code changes stop applying
- For Afforms, check `uploads/civicrm/ang/` isn't shadowing the extension's `ang/` file

**Lifecycle rules missing or not firing**
- On a fresh install, run the bootstrap scripts above — `upgrade_NNNN` steps do not run at
  install time
- On an existing install, run `cv upgrade:db`; `git pull` + `cv flush` alone skips
  upgrade steps and CiviRules JSON registration
- Verify `--user` is a real WordPress `user_login` with a `civicrm_uf_match` row
- Check CiviRules condition weights — a mis-weighted condition can silently disable a rule

### Getting Help

- **Documentation**: Review [Development Guide](DEVELOPMENT.md) and [Deployment Guide](DEPLOYMENT.md)
- **Issues**: Create issue on [GitHub repository](https://github.com/briangflett/mascode/issues)
- **Logs**: Check CiviCRM log files in ConfigAndLog directory
- **Community**: Ask questions on [CiviCRM Chat](https://chat.civicrm.org/)

## Next Steps

After successful installation:

1. **Configure Forms**: Customize form fields and workflows as needed
2. **Test Workflows**: Verify case management and automation workflows
3. **Production Deployment**: Follow [DEPLOYMENT.md](DEPLOYMENT.md) for production deployment
4. **Monitor Usage**: Review CiviCRM logs and form submission patterns
