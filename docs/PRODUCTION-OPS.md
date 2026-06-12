# Production Operations Reference

Technical commands and procedures for the MAS production environment at masadvise.org.

## Production Environment

| Component | Details |
|-----------|---------|
| **SSH access** | `ssh mas-prod` |
| **Web root** | `/home/mas/web/masadvise.org/public_html/` |
| **WordPress** | 6.9.4, `DISALLOW_FILE_EDIT=true`, `WP_AUTO_UPDATE_CORE=false` |
| **CiviCRM** | 6.12.2 on WordPress |
| **CiviCRM extensions** | `wp-content/uploads/civicrm/ext/` |
| **CV binary** | `/home/mas/local/bin/cv` |
| **Database** | `mas_mas` (combined WP + CiviCRM) |
| **Hosting** | Shared hosting (SSH, no root). `/home/mas` owned by root — use `HOME=/home/mas/tmp` for cv commands that write to home dir. |

## Read-Only Database Access

A `readonly` MySQL user exists for safe production queries. Credentials in mascode `.env` (gitignored).

```bash
# Start SSH tunnel
ssh -f -N -L 3307:localhost:3306 mas-prod

# Query production
source /home/brian/buildkit/build/masdemo/web/wp-content/uploads/civicrm/ext/mascode/.env
mysql -h $PROD_READONLY_HOST -P $PROD_READONLY_PORT -u $PROD_READONLY_USER -p"$PROD_READONLY_PASS" $PROD_CIVI_DB -e "SELECT ..."
```

This user can only run SELECT — all write operations are blocked.

## Investigation Commands

```bash
# WordPress version and plugin status
ssh mas-prod "wp core version --path=/home/mas/web/masadvise.org/public_html/"
ssh mas-prod "wp plugin list --path=/home/mas/web/masadvise.org/public_html/ --format=table"

# CiviCRM extension status
ssh mas-prod "cd /home/mas/web/masadvise.org/public_html && cv ext:list"

# mascode git status
ssh mas-prod "cd /home/mas/web/masadvise.org/public_html/wp-content/uploads/civicrm/ext/mascode && git status && git log --oneline -5"

# CiviCRM logs
ssh mas-prod "tail -50 /home/mas/web/masadvise.org/public_html/wp-content/uploads/civicrm/ConfigAndLog/CiviCRM.\$(date +%Y%m%d).log"

# WordPress error log
ssh mas-prod "tail -50 /home/mas/web/masadvise.org/public_html/wp-content/debug.log 2>/dev/null"
```

## mascode Deployment

```bash
# 1. Verify no local changes on prod
ssh mas-prod "cd /home/mas/web/masadvise.org/public_html/wp-content/uploads/civicrm/ext/mascode && git status"

# 2. Pull
ssh mas-prod "cd /home/mas/web/masadvise.org/public_html/wp-content/uploads/civicrm/ext/mascode && git pull origin master"

# 3. Flush cache (always required after mascode changes)
ssh mas-prod "cd /home/mas/web/masadvise.org/public_html && cv flush"
```

Rollback: `git checkout <previous-commit>` then `cv flush`.

## maswpcode Deployment

```bash
ssh mas-prod "cd /home/mas/web/masadvise.org/public_html/wp-content/plugins/maswpcode && git pull origin master"
```

No `cv flush` needed — WordPress plugin changes take effect immediately.

## CiviCRM Core Upgrade

**Reference**: https://docs.civicrm.org/sysadmin/en/latest/upgrade/wordpress/

```bash
# 1. Deactivate W3 Total Cache FIRST
ssh mas-prod "wp plugin deactivate w3-total-cache --path=/home/mas/web/masadvise.org/public_html/"

# 2. Upload and extract new CiviCRM (follow CiviCRM docs for WordPress)

# 3. Run DB upgrade via CLI (browser times out on big version jumps)
ssh mas-prod "cd /home/mas/web/masadvise.org/public_html && HOME=/home/mas/tmp cv upgrade:db"

# 4. Flush caches
ssh mas-prod "cd /home/mas/web/masadvise.org/public_html && HOME=/home/mas/tmp cv flush"

# 5. If CiviCRM menu is missing from WP sidebar
ssh mas-prod "wp plugin deactivate civicrm --path=/home/mas/web/masadvise.org/public_html/"
ssh mas-prod "wp plugin activate civicrm --path=/home/mas/web/masadvise.org/public_html/"

# 6. Reactivate W3 Total Cache
ssh mas-prod "wp plugin activate w3-total-cache --path=/home/mas/web/masadvise.org/public_html/"
```

## Database Backup and Restore

```bash
source /home/brian/.config/development/databases.env
DATE=$(date +%Y%m%d)

# Backup
ssh mas-prod "mysqldump -u $PROD_DB_USER -p'$PROD_DB_PASSWORD' --single-transaction $PROD_DB_NAME" > /home/brian/backup/mas_mas_pre_change_${DATE}.sql

# Restore
ssh mas-prod "mysql -u $PROD_DB_USER -p'$PROD_DB_PASSWORD' $PROD_DB_NAME" < /home/brian/backup/mas_mas_pre_change_${DATE}.sql
ssh mas-prod "cd /home/mas/web/masadvise.org/public_html && cv flush"
```

## WordPress Plugin Rollback

```bash
ssh mas-prod "wp plugin install <plugin-name> --version=<old-version> --force --path=/home/mas/web/masadvise.org/public_html/"
```

## Debugging

```bash
# Enable CiviCRM debug temporarily
ssh mas-prod "cd /home/mas/web/masadvise.org/public_html && cv api4 Setting.set '+v' '{\"debug_enabled\":1}'"

# Disable after
ssh mas-prod "cd /home/mas/web/masadvise.org/public_html && cv api4 Setting.set '+v' '{\"debug_enabled\":0}'"
```

## Known Gotchas

- **`cv upgrade:db` fails with "Permission denied"**: `/home/mas` owned by root. Fix: `HOME=/home/mas/tmp` prefix.
- **Browser upgrade page hangs**: Large CiviCRM version jumps time out. Always use CLI.
- **CiviCRM WP menu disappears after upgrade**: W3TC serves stale admin menu. Deactivate W3TC before upgrade.
- **W3 Total Cache breaks CiviCRM Angular pages**: JS minification corrupts Angular bundles. Symptom: empty tables on admin pages (e.g., Headers/Footers). Error: `TypeError: Cannot read properties of undefined (reading 'run')`. Workaround: disable W3TC. Exclusion lists did not help.
- **Elementor Data Updater notices**: Unrelated to CiviCRM — dismiss or run separately.

## Browser inspection (Playwright)

Safe-inspection rules live in the shared protocol: `mas-claude-context/.../protocols/production-access.md`.

**Live prod Afform state** (read without submitting):

```javascript
// Public form (no auth)
browser_navigate('https://www.masadvise.org/civicrm/mas-rcs-form/')

// Read the live Angular state:
const c = angular.element(document.querySelector('[af-fieldset="Individual1"]'))
                 .controller('afFieldset')
const data = c.getData()  // records array with current field values
```

Useful patterns:
- `c.getData()` on an `afFieldset` controller — current entity records (incl. fields like `do_not_email`)
- `document.querySelectorAll('af-field[name="do_not_email"]')` — locate specific fields
- `select2-chosen` text inside an `af-field` — what the user sees vs. the underlying value
- **Don't click submit** on real prod forms unless that's the intended, approved write

**CiviCRM admin via cookie injection (DEV ONLY — never prod)**:

1. Generate auth cookies: `wp eval` with `wp_generate_auth_cookie()` for user ID 42 (brian.flett), valid 24h
2. Inject: `browser_run_code` → `context.addCookies()` — logged_in cookie at `/`, secure_auth at `/wp-admin`
3. Navigate: `https://masdemo.localhost/wp-admin/admin.php?page=CiviCRM`

Requires Playwright MCP with `--ignore-https-errors`. Full recipe: Klaus memory `reference_playwright_civicrm_auth.md`. For prod admin, ask Brian — he logs in himself or provides a screenshot.

---

*Last updated: 2026-06-12*
