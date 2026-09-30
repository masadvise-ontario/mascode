# Production Operations Reference

Technical commands and procedures for the MAS production environment at masadvise.org.

## Production Environment

| Component | Details |
|-----------|---------|
| **SSH access** | `ssh mas-prod` |
| **Web root** | `/home/mas/web/masadvise.org/public_html/` |
| **WordPress** | 7.1.2 as of 2026-09-29 (upgraded via `/mas-upgrade`; check live with `wp core version`), `DISALLOW_FILE_EDIT=true`, `WP_AUTO_UPDATE_CORE=false` |
| **CiviCRM** | 6.18.1 on WordPress as of 2026-09-29 — check live with `wp civicrm core version` |
| **CiviCRM extensions** | `wp-content/uploads/civicrm/ext/` |
| **CV binary** | `/home/mas/web/masadvise.org/public_html/bin/cv` (PHAR; run from the web root) |
| **Database** | `mas_mas` (combined WP + CiviCRM) |
| **Hosting** | Shared hosting (SSH, no root). `/home/mas` owned by root — use `HOME=/home/mas/tmp` for cv commands that write to home dir. |

## Read-Only Database Access

A `readonly` MySQL user exists for safe production queries. Credentials in mascode `.env` (gitignored).

```bash
# Start SSH tunnel
ssh -f -N -L 3307:localhost:3306 mas-prod

# Query production. Extract single values — never `source` the .env (see below)
E=/home/brian/workspace/development/mascode/.env
v() { grep -m1 "^$1=" "$E" | cut -d= -f2- | sed -E 's/\r$//; s/^[[:space:]]+//; s/[[:space:]]+$//; s/^"(.*)"$/\1/'; }
MYSQL_PWD=$(v PROD_READONLY_PASS) mysql -h "$(v PROD_READONLY_HOST)" -P "$(v PROD_READONLY_PORT)" \
  -u "$(v PROD_READONLY_USER)" "$(v PROD_CIVI_DB)" -e "SELECT ..."
```

This user can only run SELECT — all write operations are blocked.

**Why not `source` the `.env`, and why `MYSQL_PWD` rather than `-p`.** Sourcing loads every value
into the shell, and one stray `echo`, `set -x` or job line prints them into the session transcript
(it happened on 2026-08-19 with a connection string). `-p<password>` puts the password on the
command line, where `ps` shows it and the client warns. Pulling each value out as it is used, and
handing the password over in the environment, fixes both — with one caveat: under `set -x` the
local query's `MYSQL_PWD=...` assignment is traced, value included, so never run it with xtrace on.
The backup/restore recipes below pipe the password instead, so xtrace never sees it. `v()` trims
surrounding whitespace (a `source` would have, and `databases.env` has values with trailing
blanks) and strips one pair of surrounding double quotes.

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

# 3. Run pending upgrade steps (no-op if none) — NOT optional, see Known Gotchas
ssh mas-prod "cd /home/mas/web/masadvise.org/public_html && HOME=/home/mas/tmp cv upgrade:db"

# 4. Flush cache (always required after mascode changes)
ssh mas-prod "cd /home/mas/web/masadvise.org/public_html && HOME=/home/mas/tmp cv flush"
```

Rollback: `git reset --hard <previous-commit>` then `HOME=/home/mas/tmp cv flush`. Capture the
pre-deploy SHA first (`git rev-parse HEAD`) so the target is exact. Note a rollback does NOT
undo an `upgrade_NNNN` step that already ran — those are forward-only.

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
E=/home/brian/.config/development/databases.env
v() { grep -m1 "^$1=" "$E" | cut -d= -f2- | sed -E 's/\r$//; s/^[[:space:]]+//; s/[[:space:]]+$//; s/^"(.*)"$/\1/'; }
U=$(v PROD_DB_USER); DB=$(v PROD_DB_NAME); DATE=$(date +%Y%m%d)
# The password travels as the first line of ssh's stdin, so it never appears in a command
# line (local or remote `ps`) or in the transcript; `read` takes that line and leaves the rest.

# Backup
v PROD_DB_PASSWORD | ssh mas-prod "IFS= read -r MYSQL_PWD; export MYSQL_PWD; mysqldump -u '$U' --single-transaction '$DB'" \
  > /home/brian/backup/mas_mas_pre_change_${DATE}.sql

# Restore (stdin also carries the SQL, after the password line)
{ v PROD_DB_PASSWORD; cat /home/brian/backup/mas_mas_pre_change_${DATE}.sql; } \
  | ssh mas-prod "IFS= read -r MYSQL_PWD; export MYSQL_PWD; mysql -u '$U' '$DB'"
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

Safe-inspection rules live in the shared protocol: `/home/brian/workspace/development/klaus/.claude/home/protocols/production-access.md` (private klaus repo — this repo is public).

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
