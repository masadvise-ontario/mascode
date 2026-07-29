# ang-legacy — versioned, never loaded

Afform files kept under version control for history and rollback. **CiviCRM does not
load anything in this directory** — Afform only scans an extension's `ang/` directory,
so these files are inert by virtue of living here. Do not move a file into `ang/`
without reading the note below on why it was retired.

Populated 2026-07-29, when the unversioned site-local afform directory
(`wp-content/uploads/civicrm/ang/`) was brought under version control. Until then these
files existed on production only, with no git history and no deploy path — a rebuild
would have lost them.

## Superseded — retired, do not resurrect

| File | Why it was retired |
|---|---|
| `afformCaseDetails` | Declared `server_route: civicrm/mas/case-details` — the **same route** as mascode's `afsearchMASCaseDetailsVC`. Two afforms contesting one route. This is the older generation (tag `VC_Menu_bukx`, last edited 2025-11-28); `afsearchMASCaseDetailsVC` is the canonical implementation backed by `SavedSearch_Case_Details_VC*.mgd.php`. |
| `afsearchServiceRequestsSentForAssignmentReport` | Second route (`civicrm/mas/sent-for-assignment-report`) onto the *same* `Service_Requests_Send_for_Assignment` saved search already served by `afsearchServiceRequestsSentForAssignment` at `civicrm/sent-for-assignment`. Rendered `Table_2` (download-only). Duplicate VC menu entry for identical data. |
| `afblockVCPortalButton` | Referenced only by the two files above. Nothing in the live set includes it. |
| `afblockLongFormWarning` | Orphan — referenced by no afform anywhere in `ang/` or the site-local dir. |

All four carry the `VC_Menu_bukx` tag (or none), the marker of the pre-2026-06-20 VC
Portal generation. The 2026-06-20 migration moved that generation's other two forms into
mascode and left these behind.

## Snapshot — live copy lives elsewhere

| File | Note |
|---|---|
| `afsearchContactSearch` | **Not migrated.** This is a local override of a form that core's `civicrm_search_ui` extension ships under the identical name, at route `civicrm/searchui/contact/search`. A site-local file reliably overrides an extension's; one extension overriding another's afform depends on load order and is not a guarantee worth relying on. The live copy therefore stays at `wp-content/uploads/civicrm/ang/`. This is a version-controlled snapshot for history only — **it can drift from the live file**. Re-snapshot if you edit the override. |

Its display `Find_Contacts_Table_1` has `acl_bypass = 0`, which is correct and deliberate:
a contact search that bypassed ACLs would expose every contact to every VC.

## Known issue, deliberately not fixed here

`afsearchProjectsByYear` and `afsearchCurrentYearProjectsByStatusPieChart` were migrated
into `ang/` **as-is**. Both are gated on `access CiviCRM` while their displays
(`Projects_by_Year_Dashlet_Chart_1`, `Projects_by_Status_Dashlet_Pie_Chart`) have
`acl_bypass = 0`. A VC therefore gets two permanently empty charts on their CiviCRM
dashboard. These are management metrics, so the fix is to raise the gate to
`edit all contacts` — matching every other MAS dashlet — not to add `acl_bypass`. Left
unchanged so this migration is a faithful move with a reviewable diff; see
`ang/README.md` for the tag/permission conventions.
