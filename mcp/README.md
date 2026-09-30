# MAS tool pack for civicrm_mcp

MAS-specific MCP tools, added to the [civicrm_mcp](https://github.com/masadvise-ontario/civicrm_mcp)
extension through its `civi.mcp.tools` event. Namespace `Civi\Mascode\Mcp`, source in `src/`.

| Class | Tools |
|---|---|
| `MasTools` | `ops_queue`, `pipeline_summary`, `find_cases`, `case_detail`, `case_contact_history`, `vc_workload`, `overdue_projects`; restricts core `civi_get` / `civi_describe` to staff |
| `Vc/VcToolsSubscriber` | `vc_describe`, `vc_query`, `vc_directory` for non-staff Active or Test VCs (D30) |

## Why it is not under `Civi/`

The pack implements civicrm_mcp interfaces (`ScopePolicy`). mascode's `scan-classes` mixin loads
every class under `Civi/` and `CRM/`, and loading one whose interface is missing throws, so a pack
under `Civi/` would break every page whenever civicrm_mcp is disabled. Instead `info.xml` maps
`Civi\Mascode\Mcp\` to `mcp/src/`, and `_mascode_register_mcp_pack()` in `mascode.php` registers
the two subscribers **only while civicrm_mcp is enabled**. That gate also keeps the pack off while
the older `mas_civicrm_mcp` (which carries its own copy) is enabled: two copies would register every
MAS tool twice, and the registry refuses duplicates.

Rules (from civicrm_mcp): every API call with `checkPermissions` TRUE except inside `ScopedQuery`;
MAS names live here, never in civicrm_mcp; narrow a core tool with `CollectToolsEvent::restrict()`.
Design history: mas-civicrm-mcp-server `docs/DECISIONS.md` and `docs/plans/`.

## Tests

```bash
# from the mascode root; civicrm_mcp checked out beside it (or set CIVICRM_MCP_DIR)
(cd ../civicrm_mcp && composer install)                                     # once
../civicrm_mcp/vendor/bin/phpunit -c mcp/phpunit.xml.dist --testsuite unit
# live, from the site root (VC env vars: mas-civicrm-mcp-server docs/VALIDATION.md):
cd <site root> && MCP_LIVE_USER=<wp login> \
  <civicrm_mcp checkout>/vendor/bin/phpunit -c <mascode checkout>/mcp/phpunit.xml.dist --testsuite live
```

The live suite loads `Civi\Mcp\*` from the civicrm_mcp checkout, so run it on a site where
`civicrm_mcp` (not the old `mas_civicrm_mcp`) is the enabled MCP extension.

## Operating rules

- **Never disable mascode, or roll it back below 1.1.40, while `civicrm_mcp` is enabled.** The
  staff-only limit on `civi_get` / `civi_describe` lives in this pack; without it those two tools
  fall back to their own `access CiviCRM` check for every connected user. Disable `civicrm_mcp`
  first.
- **This code is public** (mascode is a public repository; it moved here from the private
  mas-civicrm-mcp-server on 2026-09-30). The rules were never secret — the control is the code,
  not its obscurity — but keep secrets, real data and client names out of it, as everywhere in mascode.
