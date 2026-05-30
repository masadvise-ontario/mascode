---
name: mas-clone
description: Clone MAS production environment (masadvise.org) to local development (masdemo.localhost). Handles database export, import, migration transforms, and file sync. Use when Brian says "clone production", "refresh dev", "sync from prod", or "/mas-clone".
---

# MAS Production to Development Clone

Clone the MAS production environment (masadvise.org) into the local development environment (masdemo.localhost). This automates the process documented in `/var/www/html/backup.md` (also browsable at http://localhost/md-browser.php).

**Production**: SSH alias `mas-prod`, database `mas_mas` (wp_ + civicrm_ + bgf_ tables)
**Development**: databases `mas_dev` (wp_) and `mas_dev_civi` (civicrm_ + bgf_), credentials in `/home/brian/.config/development/databases.env`

---

## Step 1: Pre-Flight Checks

Run these checks in parallel. Abort if any fail.

```bash
source /home/brian/.config/development/databases.env
```

```bash
# Verify SSH connectivity
ssh -o ConnectTimeout=10 mas-prod "echo 'SSH OK'"
```

```bash
# Verify local MySQL connectivity
mysql -u $MYSQL_ROOT_USER -p$MYSQL_ROOT_PASSWORD -e "SELECT 1" 2>/dev/null && echo "Local MySQL OK"
```

```bash
# Verify migration scripts exist
ls -la /home/brian/backup/mas_mas_civi_to_dev.sql /home/brian/backup/mas_mas_wp_to_dev.sql
```

Report results. If any check fails, stop and troubleshoot.

---

## Step 2: Version Parity Check

Run these comparisons in parallel and present results to Brian as a table.

```bash
# WordPress versions
echo "=== WordPress Versions ==="
echo -n "Production: "; ssh mas-prod "wp core version --path=/home/mas/web/masadvise.org/public_html/"
echo -n "Development: "; /home/brian/buildkit/bin/wp core version --path=/home/brian/buildkit/build/masdemo/web/
```

```bash
# Plugin comparison
echo "=== Production Plugins ==="
ssh mas-prod "wp plugin list --path=/home/mas/web/masadvise.org/public_html/ --format=csv --fields=name,version,status" > /tmp/prod_plugins.csv
echo "=== Development Plugins ==="
/home/brian/buildkit/bin/wp plugin list --path=/home/brian/buildkit/build/masdemo/web/ --format=csv --fields=name,version,status > /tmp/dev_plugins.csv
diff /tmp/prod_plugins.csv /tmp/dev_plugins.csv || echo "(differences found)"
```

```bash
# CiviCRM extension comparison
echo "=== Production Extensions ==="
ssh mas-prod "cd /home/mas/web/masadvise.org/public_html && cv ext:list --columns=key,version,status" > /tmp/prod_ext.txt
echo "=== Development Extensions ==="
/home/brian/buildkit/bin/cv ext:list --columns=key,version,status > /tmp/dev_ext.txt
diff /tmp/prod_ext.txt /tmp/dev_ext.txt || echo "(differences found)"
```

Present a summary comparison table to Brian. This is advisory — Brian may proceed even with differences. Ask Brian to confirm before continuing.

---

## Step 3: Backup Local Dev Databases

Create safety-net backups of the current dev databases before overwriting them.

```bash
source /home/brian/.config/development/databases.env
DATE=$(date +%Y%m%d)
echo "Backing up local dev databases..."
mysqldump -u $MYSQL_ROOT_USER -p$MYSQL_ROOT_PASSWORD $MASDEMO_WP_DB_NAME > /home/brian/backup/mas_dev_wp_${DATE}.sql
echo "WordPress backup: $(du -h /home/brian/backup/mas_dev_wp_${DATE}.sql | cut -f1)"
mysqldump -u $MYSQL_ROOT_USER -p$MYSQL_ROOT_PASSWORD $MASDEMO_CIVI_DB_NAME > /home/brian/backup/mas_dev_civi_${DATE}.sql
echo "CiviCRM backup: $(du -h /home/brian/backup/mas_dev_civi_${DATE}.sql | cut -f1)"
```

---

## Step 4: Export Production Databases

Dump production tables via SSH, split by prefix for the two dev databases.

```bash
source /home/brian/.config/development/databases.env
DATE=$(date +%Y%m%d)

# Get table lists by prefix
echo "Fetching table lists from production..."
WP_TABLES=$(ssh mas-prod "mysql -u $PROD_DB_USER -p'$PROD_DB_PASSWORD' $PROD_DB_NAME -N -e \"SHOW TABLES LIKE 'wp_%'\"" | tr '\n' ' ')
CIVI_TABLES=$(ssh mas-prod "mysql -u $PROD_DB_USER -p'$PROD_DB_PASSWORD' $PROD_DB_NAME -N -e \"SHOW TABLES LIKE 'civicrm_%'\"" | tr '\n' ' ')
CIVIRULE_TABLES=$(ssh mas-prod "mysql -u $PROD_DB_USER -p'$PROD_DB_PASSWORD' $PROD_DB_NAME -N -e \"SHOW TABLES LIKE 'civirule_%'\"" | tr '\n' ' ')
BGF_TABLES=$(ssh mas-prod "mysql -u $PROD_DB_USER -p'$PROD_DB_PASSWORD' $PROD_DB_NAME -N -e \"SHOW TABLES LIKE 'bgf_%'\"" | tr '\n' ' ')

echo "WordPress tables: $(echo $WP_TABLES | wc -w)"
echo "CiviCRM tables: $(echo $CIVI_TABLES | wc -w)"
echo "CiviRules tables: $(echo $CIVIRULE_TABLES | wc -w)"
echo "BGF tables: $(echo $BGF_TABLES | wc -w)"
```

```bash
# Dump WordPress tables
source /home/brian/.config/development/databases.env
DATE=$(date +%Y%m%d)
WP_TABLES=$(ssh mas-prod "mysql -u $PROD_DB_USER -p'$PROD_DB_PASSWORD' $PROD_DB_NAME -N -e \"SHOW TABLES LIKE 'wp_%'\"" | tr '\n' ' ')
echo "Dumping WordPress tables..."
ssh mas-prod "mysqldump -u $PROD_DB_USER -p'$PROD_DB_PASSWORD' --single-transaction $PROD_DB_NAME $WP_TABLES" > /home/brian/backup/mas_mas_wp_${DATE}.sql
echo "WordPress dump: $(du -h /home/brian/backup/mas_mas_wp_${DATE}.sql | cut -f1)"
```

```bash
# Dump CiviCRM + CiviRules + BGF tables
source /home/brian/.config/development/databases.env
DATE=$(date +%Y%m%d)
CIVI_TABLES=$(ssh mas-prod "mysql -u $PROD_DB_USER -p'$PROD_DB_PASSWORD' $PROD_DB_NAME -N -e \"SHOW TABLES LIKE 'civicrm_%'\"" | tr '\n' ' ')
CIVIRULE_TABLES=$(ssh mas-prod "mysql -u $PROD_DB_USER -p'$PROD_DB_PASSWORD' $PROD_DB_NAME -N -e \"SHOW TABLES LIKE 'civirule_%'\"" | tr '\n' ' ')
BGF_TABLES=$(ssh mas-prod "mysql -u $PROD_DB_USER -p'$PROD_DB_PASSWORD' $PROD_DB_NAME -N -e \"SHOW TABLES LIKE 'bgf_%'\"" | tr '\n' ' ')
echo "Dumping CiviCRM + CiviRules + BGF tables..."
ssh mas-prod "mysqldump -u $PROD_DB_USER -p'$PROD_DB_PASSWORD' --single-transaction $PROD_DB_NAME $CIVI_TABLES $CIVIRULE_TABLES $BGF_TABLES" > /home/brian/backup/mas_mas_civi_${DATE}.sql
echo "CiviCRM dump: $(du -h /home/brian/backup/mas_mas_civi_${DATE}.sql | cut -f1)"
```

Report file sizes. If either dump is empty or suspiciously small, stop and investigate.

---

## Step 4.5: Sync Upload Files (Optional)

Ask Brian: "Do you want to sync the current year's upload files from production? (This syncs media files. Skip if you only need a database refresh.)"

If Brian says yes:

```bash
echo "Syncing uploads..."
rsync -avz --progress mas-prod:/home/mas/web/masadvise.org/public_html/wp-content/uploads/2026/ /home/brian/buildkit/build/masdemo/web/wp-content/uploads/2026/
```

Report files transferred and total size.

---

## Step 5: Drop Dev Tables and Import Production Data

**STOP. Ask Brian to confirm before proceeding.**

Say: "Ready to drop all tables in mas_dev and mas_dev_civi and replace with production data. Dev backups are saved. Proceed?"

Only continue after explicit confirmation.

```bash
source /home/brian/.config/development/databases.env
DATE=$(date +%Y%m%d)

echo "=== Dropping WordPress tables in $MASDEMO_WP_DB_NAME ==="
mysql -u $MYSQL_ROOT_USER -p$MYSQL_ROOT_PASSWORD -e "SET FOREIGN_KEY_CHECKS=0; $(mysql -u $MYSQL_ROOT_USER -p$MYSQL_ROOT_PASSWORD -N -e "SELECT CONCAT('DROP TABLE IF EXISTS \`', table_name, '\`;') FROM information_schema.tables WHERE table_schema='$MASDEMO_WP_DB_NAME'" | tr '\n' ' ') SET FOREIGN_KEY_CHECKS=1;" $MASDEMO_WP_DB_NAME
echo "WordPress tables dropped."

echo "=== Importing WordPress dump ==="
mysql -u $MYSQL_ROOT_USER -p$MYSQL_ROOT_PASSWORD $MASDEMO_WP_DB_NAME < /home/brian/backup/mas_mas_wp_${DATE}.sql
echo "WordPress import complete."
```

```bash
source /home/brian/.config/development/databases.env
DATE=$(date +%Y%m%d)

echo "=== Dropping CiviCRM tables in $MASDEMO_CIVI_DB_NAME ==="
mysql -u $MYSQL_ROOT_USER -p$MYSQL_ROOT_PASSWORD -e "SET FOREIGN_KEY_CHECKS=0; $(mysql -u $MYSQL_ROOT_USER -p$MYSQL_ROOT_PASSWORD -N -e "SELECT CONCAT('DROP TABLE IF EXISTS \`', table_name, '\`;') FROM information_schema.tables WHERE table_schema='$MASDEMO_CIVI_DB_NAME'" | tr '\n' ' ') SET FOREIGN_KEY_CHECKS=1;" $MASDEMO_CIVI_DB_NAME
echo "CiviCRM tables dropped."

echo "=== Importing CiviCRM dump ==="
mysql -u $MYSQL_ROOT_USER -p$MYSQL_ROOT_PASSWORD $MASDEMO_CIVI_DB_NAME < /home/brian/backup/mas_mas_civi_${DATE}.sql
echo "CiviCRM import complete."
```

Verify row counts for key tables:

```bash
source /home/brian/.config/development/databases.env
echo "=== Verification ==="
echo -n "wp_posts: "; mysql -u $MYSQL_ROOT_USER -p$MYSQL_ROOT_PASSWORD $MASDEMO_WP_DB_NAME -N -e "SELECT COUNT(*) FROM wp_posts"
echo -n "wp_options: "; mysql -u $MYSQL_ROOT_USER -p$MYSQL_ROOT_PASSWORD $MASDEMO_WP_DB_NAME -N -e "SELECT COUNT(*) FROM wp_options"
echo -n "civicrm_contact: "; mysql -u $MYSQL_ROOT_USER -p$MYSQL_ROOT_PASSWORD $MASDEMO_CIVI_DB_NAME -N -e "SELECT COUNT(*) FROM civicrm_contact"
echo -n "civicrm_activity: "; mysql -u $MYSQL_ROOT_USER -p$MYSQL_ROOT_PASSWORD $MASDEMO_CIVI_DB_NAME -N -e "SELECT COUNT(*) FROM civicrm_activity"
```

---

## Step 5.5: Fix Trigger Definers

Production database triggers use `mas_mas@localhost` as their definer, which doesn't exist in dev. Create the user before running migration scripts (which fire triggers via UPDATE statements).

```bash
source /home/brian/.config/development/databases.env
echo "=== Creating mas_mas user for trigger compatibility ==="
mysql -u $MYSQL_ROOT_USER -p$MYSQL_ROOT_PASSWORD -e "CREATE USER IF NOT EXISTS 'mas_mas'@'localhost' IDENTIFIED BY 'mas_mas_dev'; GRANT ALL PRIVILEGES ON $MASDEMO_CIVI_DB_NAME.* TO 'mas_mas'@'localhost'; GRANT ALL PRIVILEGES ON $MASDEMO_WP_DB_NAME.* TO 'mas_mas'@'localhost'; FLUSH PRIVILEGES;"
echo "User mas_mas@localhost created."
```

If the user already exists from a prior clone, this is a no-op (CREATE USER IF NOT EXISTS).

---

## Step 6: Run Migration Scripts

These transform production settings for the development environment (URLs, paths, SMTP, debug flags, etc.).

```bash
source /home/brian/.config/development/databases.env
echo "=== Running CiviCRM migration script ==="
mysql -u $MYSQL_ROOT_USER -p$MYSQL_ROOT_PASSWORD $MASDEMO_CIVI_DB_NAME < /home/brian/backup/mas_mas_civi_to_dev.sql
echo "CiviCRM migration complete."

echo "=== Running WordPress migration script ==="
mysql -u $MYSQL_ROOT_USER -p$MYSQL_ROOT_PASSWORD $MASDEMO_WP_DB_NAME < /home/brian/backup/mas_mas_wp_to_dev.sql
echo "WordPress migration complete."
```

### Step 6.5: Post-Migration Verification

The SQL migration scripts use string REPLACE on serialized PHP data, which silently corrupts the `s:N:` length prefixes on any string the migration touches. Run the verification script to detect and auto-fix issues:

```bash
set -a && source /home/brian/.config/development/databases.env && set +a
php /home/brian/buildkit/build/masdemo/web/wp-content/uploads/civicrm/ext/mascode/.claude/skills/mas-clone/post-migration-verify.php
```

This script checks and auto-fixes:
- WP Mail SMTP (mailer type, host, port 1025, no TLS) — including serialization length-prefix repair
- CiviCRM mailing_backend (localhost:1025, no auth) — including serialization length-prefix repair
- WordPress URLs (siteurl/home) — flagged (wp-config constants override the DB values anyway)
- CiviCRM environment/debug flags
- Search engine indexing disabled
- RECAPTCHA dev keys
- `active_plugins` — repairs gap corruption (a:N declared but entries skip indices), applies dev plugin policy (removes wordfence/w3-total-cache/unlimited-elements/better-wp-security, ensures wp-mail-smtp is active)
- Elementor Kit (`_elementor_page_settings` on every `kit` post) — repairs serialization length-prefix corruption that breaks global colors/typography. Purges stale `_elementor_css` sitewide so styles regenerate. Without this fix the newsletter signup form (and any styled form) renders with invisible text.
- WPO365 credentials presence — warns if `application_id` / `application_secret` / `tenant_id` are empty (migration intentionally clears these prod secrets). Repopulating happens in Step 6.6.

---

## Step 6.6: Repopulate WPO365 OAuth Credentials

If Step 6.5 warned that WPO365 credentials are missing, "Login with Microsoft" will fail and bounce to the homepage instead of `/wp-admin`. The fix is to copy `application_id`, `application_secret`, and `tenant_id` from prod's `wpo365_options` into dev's.

**Ask Brian to authorize this read-only extraction of prod OAuth secrets** (the auto-classifier will block it without explicit per-run approval). Phrase the ask as:

> "WPO365 credentials are empty in dev. Authorize a read-only extraction of `application_id`, `application_secret`, and `tenant_id` from prod, piped directly into dev via stdin (no disk persistence)?"

Only proceed if Brian approves. Then run:

```bash
CREDS_JSON=$(ssh mas-prod 'cd /home/mas/web/masadvise.org/public_html && /usr/local/bin/wp eval --skip-themes --skip-plugins "
\$opts = get_option(\"wpo365_options\");
if (!is_array(\$opts)) { echo \"{}\"; exit; }
echo json_encode([
  \"application_id\"     => \$opts[\"application_id\"]     ?? \"\",
  \"application_secret\" => \$opts[\"application_secret\"] ?? \"\",
  \"tenant_id\"          => \$opts[\"tenant_id\"]          ?? \"\",
]);
"' 2>/dev/null)

[ -z "$CREDS_JSON" ] || [ "$CREDS_JSON" = "{}" ] && { echo "ERROR: prod returned empty creds"; exit 1; }

echo "Prod cred lengths: $(echo "$CREDS_JSON" | python3 -c 'import json,sys; print({k: len(v) for k,v in json.load(sys.stdin).items()})')"

echo "$CREDS_JSON" | /home/brian/buildkit/bin/cv ev '
$creds = json_decode(stream_get_contents(STDIN), true);
$opts  = get_option("wpo365_options");
if (!is_array($opts)) { $opts = []; }
$opts["application_id"]     = $creds["application_id"];
$opts["application_secret"] = $creds["application_secret"];
$opts["tenant_id"]          = $creds["tenant_id"];
$ok = update_option("wpo365_options", $opts);
$check = get_option("wpo365_options");
$verify = [];
foreach (["application_id","application_secret","tenant_id"] as $k) {
  $verify[$k] = strlen($check[$k] ?? "") > 0 ? "[" . strlen($check[$k]) . " chars]" : "[EMPTY]";
}
echo json_encode(["update_result" => $ok, "verification" => $verify], JSON_PRETTY_PRINT);
' --user=brian.flett@masadvise.org

unset CREDS_JSON
```

Expected: `update_result: true` with `application_id` 36 chars, `application_secret` 40 chars, `tenant_id` 36 chars. No temp files on disk on either side.

---

## Step 7: Post-Migration Automated Checks

```bash
# Flush CiviCRM cache
/home/brian/buildkit/bin/cv flush
echo "CiviCRM cache flushed."
```

```bash
# Verify key WordPress settings
echo "=== WordPress Settings ==="
echo -n "siteurl: "; /home/brian/buildkit/bin/wp option get siteurl --path=/home/brian/buildkit/build/masdemo/web/
echo -n "home: "; /home/brian/buildkit/bin/wp option get home --path=/home/brian/buildkit/build/masdemo/web/
echo ""
echo "=== Inactive Plugins ==="
/home/brian/buildkit/bin/wp plugin list --path=/home/brian/buildkit/build/masdemo/web/ --status=inactive --format=table
```

Report all results.

---

## Step 8: Manual Steps Reminder

Present this checklist to Brian:

1. **Update permalinks**: Go to Settings > Permalinks > Save Changes (rebuilds rewrite rules)
2. **Verify site**: Load https://masdemo.localhost and check it renders correctly. Hard-refresh (Ctrl+Shift+R) the newsletter signup to confirm Elementor regenerated the CSS — form text should be readable with prod's color scheme.
3. **Test WPO365**: Click "Login with Microsoft" — should land on `/wp-admin`. If it bounces to the homepage, Step 6.6 was skipped or failed.

---

## Step 9: Summary

Report:
- Dev backup files created (paths + sizes)
- Production dump files created (paths + sizes)
- Upload files synced (if applicable)
- Migration scripts executed (success/failure)
- Key verification results (URLs, plugin status)
- Manual steps remaining

---

## Rollback

If something goes wrong, restore from the dev backups created in Step 3:

```bash
source /home/brian/.config/development/databases.env
DATE=$(date +%Y%m%d)
mysql -u $MYSQL_ROOT_USER -p$MYSQL_ROOT_PASSWORD $MASDEMO_WP_DB_NAME < /home/brian/backup/mas_dev_wp_${DATE}.sql
mysql -u $MYSQL_ROOT_USER -p$MYSQL_ROOT_PASSWORD $MASDEMO_CIVI_DB_NAME < /home/brian/backup/mas_dev_civi_${DATE}.sql
/home/brian/buildkit/bin/cv flush
```

---

## Expected Differences

These differences between production and development are normal and expected:

**Plugins (active on prod, inactive on dev):**
- `better-wp-security` (iThemes Security) — not needed in dev
- `w3-total-cache` — conflicts with dev environment
- `wordfence` — not needed in dev
- `unlimited-elements-for-elementor` — conflicts with dev

**Plugins (inactive on prod, active on dev):**
- `wp-mail-smtp` — active on dev for localhost mail capture

**CiviCRM extensions (dev-only):**
- `ai-assistants`, `docbot`, `msoffice365`, `org.example.cvtest` — dev/testing extensions

**Plugin version differences** are expected when prod auto-updates between clones.

When presenting the parity check, flag only differences NOT in this list.

---

## Known Issues & Solutions

**Issue**: Trigger definer `mas_mas@localhost` doesn't exist in dev, causing migration scripts to fail with `ERROR 1449` when UPDATE statements fire triggers. | **Solution**: Step 5.5 creates the user before migration. | **Date**: 2026-03-26

**Issue**: CiviCRM `mailing_backend` serialized value had wrong `qfKey` string length (`s:78:` for 15-char string) and port set to 25 instead of 1025. | **Solution**: Fixed in `mas_mas_civi_to_dev.sql` (correct serialization + port 1025). Post-migration verify script catches and fixes any remaining corruption. | **Date**: 2026-03-26

**Issue**: WP Mail SMTP migration uses REPLACE on serialized data, which can corrupt the option. The mailer type isn't changed to `smtp`, and port was set to 25. | **Solution**: Fixed port to 1025 in `mas_mas_wp_to_dev.sql`. Post-migration verify script (`post-migration-verify.php`) properly deserializes, fixes, and re-serializes the option. | **Date**: 2026-03-26

**Issue**: CiviRules tables (`civirule_*`) were not exported because the dump only matched `civicrm_%` and `bgf_%` prefixes. CiviRules 3.x renamed tables from `civirules_*` to `civirule_*` (no 's'). Missing tables cause 500 errors on CiviCRM dashboard. | **Solution**: Added `civirule_%` to the export step. | **Date**: 2026-03-26

**Issue**: Playwright browser can't navigate to `https://masdemo.localhost` due to self-signed cert (`ERR_CERT_AUTHORITY_INVALID`). | **Solution**: Use `http://masdemo.localhost` instead — WordPress redirects to HTTPS automatically. | **Date**: 2026-03-26

**Issue**: `wp_mail_smtp` option silently corrupted by REPLACE on serialized data — the `s:N:` length prefix on `info@masadvise.org` (18 chars) didn't update when the value became `info@masdemo.localhost` (22 chars). `unserialize()` then failed and the verify script only *flagged* the problem instead of repairing it. | **Solution**: Verify script now runs every serialized option through `repair_lengths()` (regex that recomputes `s:N:` prefixes) before bailing. | **Date**: 2026-05-30

**Issue**: `active_plugins` option corrupted with array-count-vs-entries mismatch. Migration's REPLACE removed `wordfence`, `w3-total-cache`, and `unlimited-elements-for-elementor` entries inline, leaving the array declared `a:23` with only 21 entries at non-contiguous indices. `unserialize()` failed → WP-CLI thought zero plugins were active. | **Solution**: Verify script extracts entries via regex when unserialize fails, reindexes contiguously, and reapplies the dev plugin policy (remove `better-wp-security` too, ensure `wp-mail-smtp` is active). | **Date**: 2026-05-30

**Issue**: Migration script doesn't apply the full "Expected Differences" plugin policy — leaves `better-wp-security` active (should be inactive on dev) and doesn't activate `wp-mail-smtp` (should be active on dev). | **Solution**: Verify script's section 7 now diffs against `$devRemove` / `$devEnsure` lists and writes back. | **Date**: 2026-05-30

**Issue**: Elementor Kit (`_elementor_page_settings` on `kit`-type elementor_library posts, typically `post_id=5288`) corrupted by REPLACE on URLs/email addresses embedded in CSS strings. PHP unserialize fails silently → Elementor's global Kit settings don't apply → forms (especially the newsletter signup in the footer) render with invisible text because system colors aren't loaded. | **Solution**: Verify script's section 8 finds every `kit` post, runs the length-repair on its `_elementor_page_settings`, and purges stale `_elementor_css` sitewide so styles regenerate. | **Date**: 2026-05-30

**Issue**: WPO365 OAuth credentials (`application_id`, `application_secret`, `tenant_id`) are intentionally not carried over by the migration. Without them, "Login with Microsoft" completes Azure auth but WPO365 can't validate the token → bounces user to homepage instead of `/wp-admin`. | **Solution**: New Step 6.6 prompts Brian for explicit per-run authorization, then pipes prod's 3 credential values through SSH → stdin → cv ev. No temp files on either side. | **Date**: 2026-05-30
