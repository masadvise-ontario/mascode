---
name: mas-prod-access
description: How to read CiviCRM data on MAS production (masadvise.org) — the shared-hosting layout, where cv lives and why it's a PHAR, the wp-cli and wp-load.php fallback routes when cv won't bootstrap, the API4 read idiom, and the VC Portal's filter-as-security model. Use when inspecting prod data, running cv/wp-cli against mas-prod, debugging a prod-only CiviCRM behaviour, or when cv fails to bootstrap. Read-only inspection — prod writes still require the per-turn approval rule in CLAUDE.md.
---

# MAS Production Access (Safe Inspection)

Read-only by default. The safety rules — SSH-tunnel readonly inspection, the per-turn
prod-write approval rule, the hard rules — live in
[protocols/production-access.md](/home/brian/workspace/claude/context/mas-claude-context/claude-code/global/protocols/production-access.md)
and in this repo's `CLAUDE.md`. This skill is the mechanics only.

Full command reference and environment details: [docs/PRODUCTION-OPS.md](../../../docs/PRODUCTION-OPS.md).
Afform/Playwright inspection patterns and the dev-only cookie-injection recipe:
PRODUCTION-OPS.md "Browser inspection" section.

## Production layout (`mas-prod` / masadvise.org)

The WordPress + CiviCRM web root (production home) is `~/web/masadvise.org/public_html`.
CiviCRM CLI tools like `cv` are installed under `~/web/masadvise.org/public_html/bin`.

It is shared hosting (SSH user `mas`, **no sudo**), so `cv` is a user-installed PHAR rather
than a system binary. Run `cv` from the web root so it can bootstrap CiviCRM
(`wp-content/uploads/civicrm/civicrm.settings.php`):

```bash
ssh mas-prod 'cd ~/web/masadvise.org/public_html && cv api4 Contact.get +l 1'
```

Note the `+w case_id=<id>` / `Full_Self_Assessment_Survey.*` custom-group pattern for reading
SAS/RCS activity data via API4.

`cv --user=` takes a WordPress `user_login` that has a `civicrm_uf_match` row. There is no
`admin` user — passing one fails with "Failed to determine contactID". Omitting `--user`
runs anonymous, so `get(TRUE)` reads silently return zero rows instead of erroring. Check
the available logins with `wp user list --role=administrator --fields=ID,user_login` first;
prod admins are a different set from dev.

## If `cv` isn't available or won't bootstrap

Host without the PHAR, or a bootstrap failure. Read CiviCRM data via either route the
codebase already uses — both bootstrap CiviCRM through WordPress's `wp-load.php`, so no `cv`
is needed. This is how the **VC Portal** itself reads data (Afform + SearchKit `SavedSearch`es
querying **API4** over core's `civicrm/ajax/api4` endpoint):

- **wp-cli (preferred):** from the web root —
  `wp civicrm api4 Case.get '{"where":[["id","=",18781]]}'` (or `wp eval '...'`).
  wp-cli plus the CiviCRM wp-cli integration is present on prod; the `mas-vc-sync` skill
  already relies on it.
- **A PHP bootstrap script (the `extern/` pattern):** `require_once '<web-root>/wp-load.php';`
  then a normal fluent API4 call. Served over HTTP (gated by
  `current_user_can('administrator')`) or run via the site's own PHP
  (`php extern/<script>.php`). See `extern/dataload_header.php` + `extern/project_import.php`.

## API4 read idiom

Dominant pattern in this codebase (~231 uses):

```php
\Civi\Api4\Entity::get(TRUE)->addSelect(...)->addWhere(...)->execute()
```

`TRUE` enforces permissions; `FALSE` bypasses them for trusted scripts. Never API v3 / BAO /
raw SQL.

## VC Portal security model

The VC Portal's real security boundary is **filter-as-security**. Its SavedSearches run
`acl_bypass=TRUE` but bake the entitlement predicate (pool status OR `user_contact_id`
coordinator) into the API4 `WHERE`, so a forged case id just returns zero rows. When editing
one of those SavedSearches, preserve the predicate — dropping it removes the only thing
gating the data.
