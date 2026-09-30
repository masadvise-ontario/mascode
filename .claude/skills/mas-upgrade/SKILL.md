---
name: mas-upgrade
description: Upgrade WordPress (core, plugins, themes) and CiviCRM (core, contrib extensions) on the MAS stack — dev first, a manual test gate, then production. Checks dev/prod parity, researches release notes and security advisories, backs up, upgrades, and runs a before/after smoke test (smoke.sh). Use when Brian says "upgrade wordpress/civicrm", "update plugins", "patch civi", "monthly upgrade", "security release", or "/mas-upgrade".
---

# MAS Platform Upgrade (WordPress + CiviCRM)

Upgrades the whole platform in place: **dev → Brian tests → prod**. In-house code (mascode,
maswpcode, mas_civicrm_mcp) is NOT part of this — it ships through `/mas-deploy`.

Follow the production-access protocol for every prod command (see `mas-prod-access`). Brian's
"upgrade prod" approves the planned sequence below; anything *outside* it (deleting files,
deactivating plugins, restoring a backup) needs its own yes.

**Cadence:** monthly, plus immediately when civicrm.org publishes a CIVI-SA advisory that affects
the installed version. First run: 2026-09-29 (6.16.1 → 6.18.1, WP 7.0.2 → 7.1.2).

Paths below: dev web root `/home/brian/buildkit/build/masdemo/web`, dev `cv` =
`/home/brian/buildkit/bin/cv`; prod web root `~/web/masadvise.org/public_html`, prod `cv` =
`./bin/cv` from the web root. **Every prod command runs with `export HOME=/home/mas/tmp`**
(`/home/mas` is root-owned). Note that `~` expanded *after* that export is `/home/mas/tmp`.

---

## Step 1: Inventory + parity (read-only)

Run on both sides and diff:

```bash
wp core version; wp core check-update
wp plugin list --fields=name,status,version,update,update_version --format=csv
wp theme list  --fields=name,status,version,update,update_version --format=csv
cv ext:list -L --columns=key,status,version
wp civicrm core version          # Plugin vs Database — must match before starting
```

`wp plugin list` does **not** show core updates — `wp core check-update` does.

**Allowed differences:** plugin *status* only. `/mas-clone` deactivates wordfence,
better-wp-security, unlimited-elements and w3-total-cache on dev and activates wp-mail-smtp; dev
also carries uninstalled experimental extensions. **Stop and ask Brian** on any *version*
difference. In-house drift (mascode/mas_civicrm_mcp ahead on dev = undeployed merge) is his call —
on 2026-09-29 he ruled it out of scope.

**Local core patches:** checksum both CiviCRM installs against the pristine zip of the *installed*
version. Any difference is a hand patch the upgrade will silently delete.

```bash
curl -sSfLo /tmp/pristine.zip https://storage.googleapis.com/civicrm/civicrm-stable/<ver>/civicrm-<ver>-wordpress.zip
# unzip, then: (cd civicrm && find . -type f -print0 | sort -z | xargs -0 md5sum) — compare to the same
# run inside wp-content/plugins/civicrm on dev and (over ssh) on prod
```

Also content-hash the contrib extension folders on both sides (exclude `.git`) — same version
number does not prove same files.

## Step 2: Research (subagents — keep the noise out of the main context)

Dispatch two general-purpose agents in parallel:

1. **CiviCRM**: target version from `https://latest.civicrm.org/stable.php` and
   `versions.json` (ESR line vs latest); release notes for every version between installed and
   target (`raw.githubusercontent.com/civicrm/civicrm-core/<branch>/release-notes/<ver>.md`);
   **security advisories** (civicrm.org/advisory) affecting the installed version; system
   requirements (PHP/MySQL/WP "tested up to"); anything touching Afform, SearchKit, CiviCase,
   CiviRules/FormProcessor/action-provider, Mosaico, Smarty, APIv4; the published SHA256 from
   `download.civicrm.org/civicrm-<ver>.SHA256SUMS`.
2. **WordPress plugins**: changelog of each pending update, flagging major-version jumps,
   minimum PHP/WP, required DB steps, and what to test.

Contrib extension updates: `cv ext:list -R --columns=key,version` (the extdir feed for the
*current* version; re-check after core). An extension that only has a GitHub tag and no extdir
release (airmail 2.2.8, 2026-09) is not published — leave it.

Write the plan to a file and present the decisions (target version, anything out of sync, anything
held back) — Brian's terminal truncates long content (`feedback_long_summaries_as_files`).

## Step 3: Dev upgrade

```bash
# 3.1 Backup (local, gzip; umask 077 — the dumps hold personal data)
umask 077; B=/home/brian/backup/pre-upgrade-$(date +%Y%m%d-%H%M); mkdir -p $B
# mysqldump both dev DBs (creds: extract single values from databases.env, MYSQL_PWD — never source it)
tar czf $B/wp-content-code.tgz -C web/wp-content plugins themes mu-plugins uploads/civicrm/ext uploads/civicrm/civicrm.settings.php

# 3.2 Baseline smoke (BEFORE touching anything)
.claude/skills/mas-upgrade/smoke.sh dev mark && .claude/skills/mas-upgrade/smoke.sh dev check

# 3.3 CiviCRM core — output PRINTS THE DB CREDENTIALS: log to a private file, show an allowlist
wp civicrm core update --zipfile=<verified zip> --yes > $B/core-update.log 2>&1; echo rc=$?
grep -E '^(Success|Error|Warning)|completed' $B/core-update.log
cv upgrade:db -n && cv flush              # dev is not in maintenance mode, so cv works here

# 3.4 Contrib extensions (replaces in place; -n = no prompts)
cv dl -r -f -n <key> <key> ... && cv upgrade:db -n && cv flush

# 3.5 WordPress, in risk order: SSO → security → Elementor+Pro → form add-ons → rest
wp plugin update wpo365-login
wp plugin update wordfence better-wp-security
wp plugin update elementor elementor-pro
wp plugin update <the rest>          # never `--all` while a plugin is held back
wp theme update astra
wp core update --version=<x.y.z>     # then: wp core update-db
wp elementor update db; wp elementor flush-css
cv flush

# 3.6 After smoke
.claude/skills/mas-upgrade/smoke.sh dev check
```

**Premium plugins on dev** (Elementor Pro and any other licensed plugin) are not licensed on masdemo.localhost,
so `wp plugin update` skips them. Read prod's licensed package URL from its update cache and
install from it — the URL embeds a token, so write it to a file, never print it:

```bash
ssh mas-prod 'cd <root> && HOME=/home/mas/tmp wp eval '\''$t=get_site_transient("update_plugins"); $r=$t->response["elementor-pro/elementor-pro.php"]??null; echo $r->package??"";'\''' > <job-tmp>/pkg.url
wp plugin install "$(cat <job-tmp>/pkg.url)" --force 2>&1 | sed -E 's#https?://[^ ]+#<url>#g'
```

No package on prod either = that licence's update entitlement has lapsed; hold the plugin back
(pass it in `SMOKE_ALLOW_PENDING`) and raise it with Brian.

## Step 4: Manual test gate

Compare the after-smoke to the baseline: a FAIL present in both is pre-existing (e.g.
`CaseDetailAccessTest` when dev data lacks a fixture case). Write a gate file listing what changed,
automated results, decisions needed, and a manual checklist ordered by risk. Standing items:

- VC login via Microsoft (WPO365) → /vcportal/, own cases visible; check-in page
- Elementor editor opens/saves; ElementsKit header + mega menu on desktop AND mobile
- Elementor Pro forms that post to CiviCRM (maswpcode); conditional fields
- Public afforms (RCS, SAS, project definition) load and submit — mail in MailHog :8025
- FormBuilder open/save; SearchKit dashboards (Board / Cases / Ops); CiviRules list; Mosaico composer
- CiviCase: open a case, timeline, status change
- Anything the research flagged (major jumps, removed features)

Plugins inactive on dev (wordfence, better-wp-security, unlimited-elements, w3tc) get updated but
**not exercised** there — say so. Then **stop**: `needs input:` and wait for Brian's approval.

## Step 5: Prod upgrade

```bash
# 5.1 Pre-flight (read-only): crontab (CiviCRM job.execute runs every 10 min), contact count
#     (6.18 adds a FULLTEXT index on civicrm_contact), disk, admin user_logins for PROD_STAFF_LOGIN

# 5.2 Backup ON THE SERVER only — Brian: don't pull it to the laptop (slow; the host has its own backups)
umask 077; B=/home/mas/tmp/backup/pre-upgrade-$(date +%Y%m%d-%H%M); mkdir -p $B; chmod 700 $B
wp db export - --single-transaction --quiet | gzip > $B/mas_mas.sql.gz; gunzip -t $B/mas_mas.sql.gz
tar czf $B/wp-content-code.tgz -C wp-content plugins themes mu-plugins uploads/civicrm/ext uploads/civicrm/civicrm.settings.php
cp wp-config.php $B/

# 5.3 Baseline smoke (pass the private hold-back list from the handoff, if any)
PROD_STAFF_LOGIN=<login> SMOKE_ALLOW_PENDING='<slugs>' .claude/skills/mas-upgrade/smoke.sh prod mark && ... prod check

# 5.4 Download ON prod (never relay 47 MB through the laptop) and verify
curl -sSfL -o /home/mas/tmp/civicrm-<ver>-wordpress.zip https://storage.googleapis.com/civicrm/civicrm-stable/<ver>/civicrm-<ver>-wordpress.zip
echo "<sha256>  /home/mas/tmp/civicrm-<ver>-wordpress.zip" | sha256sum -c

# 5.5 Pause CiviCRM cron for the window (maintenance mode does NOT stop wp-cli cron), then go.
#     Only the masadvise.org job.execute line — the crontab holds other sites' lines too.
crontab -l > $B/crontab.bak
crontab -l | sed 's|^\([^#].*masadvise.org/public_html civicrm api job.execute.*\)$|#MAS-UPGRADE# \1|' | crontab -
crontab -l | grep -c '^#MAS-UPGRADE#'    # expect 1
wp maintenance-mode activate
wp civicrm core update --zipfile=<zip> --yes > $B/core-update.log 2>&1; echo rc=$?
grep -E '^(Success|Error|Warning)|completed' $B/core-update.log
wp civicrm core update-db --yes          # NOT cv — see gotcha 1
wp civicrm core version                  # Plugin and Database MUST match before going on
wp civicrm cache flush
wp civicrm ext download <key> --yes      # one per extension, NOT cv dl
wp civicrm ext update-db                 # takes no --yes
wp civicrm cache flush

# 5.6 WordPress — same groups as dev; Elementor Pro may need the package-URL route (gotcha 4).
#     Every `wp plugin update` / `wp theme update` / `wp core update` ENDS maintenance mode
#     (gotcha 3), so re-activate after each group — or accept the site is live during 5.6.
wp plugin update <group>; wp maintenance-mode activate      # repeat per group
wp theme update astra; wp maintenance-mode activate
wp core update --version=<x.y.z>
wp core update-db; wp elementor flush-css; wp cache flush; wp civicrm cache flush
wp maintenance-mode deactivate 2>/dev/null; wp maintenance-mode status   # must be off
crontab $B/crontab.bak && crontab -l | grep -c '^#MAS-UPGRADE#'         # restore cron; expect 0

# 5.7 After smoke (maintenance must be off — cv cannot run under it), then confirm the next
#     cron run hit the upgraded DB
./bin/cv flush
PROD_STAFF_LOGIN=<login> .claude/skills/mas-upgrade/smoke.sh prod check
wp db query "SELECT name,last_run FROM civicrm_job WHERE is_active=1 ORDER BY last_run DESC LIMIT 3"
```

Also compare `git status --short` in prod's mascode checkout with the same command run before
the upgrade — the platform upgrade must not touch it.

## Step 6: Report

Versions before → after per environment, smoke results vs baseline, anything held back and why,
backup paths, follow-ups (handoffs). Update the memory index if a new gotcha surfaced.

---

## Gotchas (each cost time on the first run)

1. **WordPress maintenance mode blocks `cv`.** `cv` bootstraps WordPress, gets the "Briefly
   unavailable" page and exits 0 having done nothing — `cv upgrade:db` silently didn't run on
   2026-09-29, leaving 6.18.1 code on a 6.16.1 database. wp-cli bypasses maintenance, so use
   `wp civicrm core update-db`, `wp civicrm ext download`, `wp civicrm ext update-db` while it is on.
   Always read `wp civicrm core version` (Plugin vs Database) after the DB step.
2. **`cv upgrade:db --dry-run` prints "Upgrade to X completed."** It did not write. Don't trust
   its wording in either direction — check `wp civicrm core version`.
3. **Every WordPress upgrader run ends maintenance mode** — `wp plugin update`, `wp theme update`
   and `wp core update` each switch it on and then off. Re-activate after each, and never assume
   the site stayed down through step 5.6.
4. **`wp plugin update elementor elementor-pro` updates only core** ("1 of 2") and afterwards
   `wp plugin update elementor-pro` says "already updated" at the old version. Install from the
   licensed package URL (Step 3 premium route), read *before* the plugin updates refresh the cache.
5. **`wp civicrm core update` echoes the database credentials.** Send its output to a file in the
   `umask 077` backup dir and show only allowlisted lines — a denylist grep misses DSN forms.
6. **Never print `wpo365_options` with a substring filter on "mail"** — it matches
   `mail_application_secret`. Select explicit keys.
7. **Held-back plugins** go in `SMOKE_ALLOW_PENDING` (space-separated slugs; default empty). Which
   plugins are held back, and why, lives in the private handoff queue — not in this public repo.
8. **Never use `cv upgrade:db --dry-run` as a check.** It rebuilds triggers, reconciles managed
   entities and resets the upgrade queue — a write, and after a half-failed upgrade it destroys
   the `--retry` path. smoke.sh compares code/DB versions and `CRM_Extension_Upgrades::hasPending()`
   through `cv ev` instead.
9. **The CiviCRM `upgrade:db` pre-upgrade messages are worth reading** — 6.18 announced a
   FULLTEXT index on `civicrm_contact` and removed the FormBuilder HTML Editor extension (raw-markup
   editing moved behind the "FormBuilder: edit raw HTML markup" permission).

## Rollback

- **CiviCRM files**: extract `plugins/civicrm` from the code tarball; **DB**: restore the dump.
  CiviCRM has no down-migrations, so files and DB go back together, never one alone.
- **A plugin**: `wp plugin install <slug> --version=<old> --force` (wp.org), or from the tarball.
- Dev full restore: the two `mas_dev*.sql.gz` + tarball; or just `/mas-clone` again.

## Smoke test

`smoke.sh dev|prod mark|check` — versions, CiviCRM/DB version match, nothing pending in
`upgrade:db`, System.check (no error/critical), core/plugin checksums, pending updates, API4
reads, jobs, CiviRules, HTTP 200 without PHP errors on the public pages and forms, the anonymous
Afform.prefill probe, mascode's security and live tests (prod runs only the read-only/rolled-back
subset), and new fatal log lines since `mark`. Its header says what each env var does.
