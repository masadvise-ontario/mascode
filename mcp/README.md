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
cd ../civicrm_mcp && composer install          # once: phpunit + civicrm_mcp's classes
../civicrm_mcp/vendor/bin/phpunit -c mcp/phpunit.xml.dist --testsuite unit   # from the mascode root
# live, from the site root (VC env vars: mas-civicrm-mcp-server docs/VALIDATION.md):
cd ~/buildkit/build/masdemo && MCP_LIVE_USER=<wp login> \
  /home/brian/workspace/development/civicrm_mcp/vendor/bin/phpunit \
  -c /home/brian/workspace/development/mascode/mcp/phpunit.xml.dist --testsuite live
```
