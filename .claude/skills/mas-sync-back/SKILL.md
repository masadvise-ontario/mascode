---
name: mas-sync-back
description: Bring UI edits back into mascode. Brian edits an Afform in FormBuilder or a message template in the CiviCRM UI, on dev or prod, and wants the change in the repo. Handles the site-local shadow that hides Afform edits from git, and the fact that a UI template edit is TRANSIENT — the next deploy that touches its declaration overwrites it. Use when Brian says "sync that back", "pull my changes into mascode", "I edited the form on prod", "reverse engineer that into the repo", or "/mas-sync-back".
---

# Sync UI edits back into mascode

Brian edits things in the CiviCRM UI. Those edits do **not** reach the repo by themselves.
The two asset types go wrong in **opposite directions**, and mixing them up is the mistake
this skill exists to prevent:

| | Where the UI edit lands | What the repo copy does afterwards |
|---|---|---|
| **Afform** | A **file** in the site-local `ang/` dir, *not* the mascode dir | **Loses.** The shadow is served; a later repo change to that form appears to do nothing |
| **Message template** | The `civicrm_msg_template` **row** | **Wins, eventually.** The next deploy that changes the declaration overwrites the UI edit |

So for an Afform, the risk is that the repo gets **ignored**. For a template, the risk is that
the repo **destroys** Brian's edit. Neither shows up in `git status`.

There is **no Afform table.** `civicrm_afform_submission` holds submissions, not definitions.

---

## Hard rules

1. **Production writes need per-turn approval.** Read prod, write the repo. The only prod
   writes this skill ever makes are in A-4 (back up, then revert a shadow). Preview the exact
   commands, get an explicit "yes", then run them. That is the same rule as `mas-deploy`, and
   `mas-prod-access` covers how to reach prod.
2. **Show the diff before committing.** Brian decides what was intentional and what was a
   mistake made while clicking around.
3. **Never paste template bodies or form content into the transcript.** They carry real client
   names and case details. Diff them, count them, summarise them. Write to
   `$CLAUDE_JOB_DIR/tmp` and give Brian the path if he needs to read one.
4. **Dev is a stale proxy for prod.** Check `ls -lt ~/backup/mas_dev_civi_*.sql | head -1`. If
   the edit was on prod, read prod.
5. **A sync-back goes through the review gate** (`CLAUDE.md`): branch, PR, fresh-context
   reviewer. It changes what ships to prod.

---

## A. Afforms: copy the shadow in, deploy it, prove it landed, and only then clear it

### The mechanism

`Civi/Api4/Utils/AfformSaveTrait.php` calls `createSiteLocalPath()` on **every** save. It never
writes to an extension directory. So a FormBuilder edit to a mascode form creates a **new** file
in the site-local dir, which `CRM_Afform_AfformScanner` weights **200**, above every extension.
The shadow is served, the mascode copy is dead weight, and `git status` stays clean.

| | site-local `ang/` | mascode ext |
|---|---|---|
| dev | `/home/brian/buildkit/build/masdemo/web/wp-content/uploads/civicrm/ang/` | the canonical checkout |
| prod | `~/web/masadvise.org/public_html/wp-content/uploads/civicrm/ang/` | `~/web/masadvise.org/public_html/wp-content/uploads/civicrm/ext/mascode/` |

Confirm rather than trust: `cv ev 'echo (new CRM_Afform_AfformScanner())->getSiteLocalPath();'`.

### ⚠ The ordering is the whole procedure

**Clearing the shadow reverts the live form to whatever mascode file that environment holds
right now.** If you clear it before the synced file is *deployed there*, the live form silently
drops Brian's edit, and on prod the shadow was the only copy. So: copy, merge, deploy,
**verify byte-identical**, back up, and only then revert. `Afform.revert` unlinks the files, and
nothing in CiviCRM can bring them back.

On **dev** the same applies, one step shorter: the mascode copy dev serves is the **canonical
checkout**, not your worktree. Reverting before the change is merged and pulled there reverts
dev's form.

### A-1. Find the shadows (read-only)

- dev: `cv scr scripts/check-managed-drift.php` → *OVERRIDDEN afforms* (`has_local` + `has_base`).
- prod: `ssh mas-prod 'ls -la <prod site-local ang dir>'`. The drift script also works on prod,
  but only on a deployed version that has it.

Ignore the `_vc-afform-migrated-*` marker dirs and `*.bak-*` files, which the scanner does not load.
Split what is left:
- **The same basename exists in `mascode/ang/`**: Brian's edit to our form. Sync it.
- **Not in mascode** (e.g. `afsearchContactSearch.aff.*`, a SearchKit form): **leave it alone.**
  It is site-local by design. Do not adopt it without asking.

### A-2. Bring the shadow into the repo

Copy both files if both exist. `.aff.html` is the layout, and `.aff.json` is the metadata
(title, permission, `server_route`, tags). From prod, `scp` into `$CLAUDE_JOB_DIR/tmp` first,
then into `ang/` on your branch. Diff against the repo copy and show Brian. **Call out by name**
any change to `permission`, `server_route` or `is_public`, which are security changes.

Traps to check in the diff:
- **An `af-if` you did not get from FormBuilder**: stop and ask. A hand-written one renders in
  the browser while core's parser, required-field validation and FormBuilder disagree, and
  FormBuilder silently deletes it.
- **`display-name=""`** loses `acl_bypass`, and VC Portal screens then show "None found".
- **A new `autofill="entity_id"` fieldset, or a link carrying a record id into a public form**:
  read `ang/README.md` §"Security: public forms and caller-supplied record ids" and re-run the
  two checks it names.

### A-3. Land it where the form is served

Branch → PR → review → merge. Then get the file onto the environment that holds the shadow:
- prod: deploy (`mas-deploy`). Until that is done, **stop here.** Prod keeps serving the shadow,
  which is Brian's edit, so leaving it is safe.
- dev: pull the merge into the canonical checkout.

### A-4. Prove it landed, back up, then clear (prod: approved write)

Preview all of this to Brian as one block, and run it only on his "yes":

```bash
# 1. The deployed mascode copy must be byte-identical to the shadow. If ANY line differs, STOP.
ssh mas-prod 'cd ~/web/masadvise.org/public_html/wp-content/uploads/civicrm &&
  sha256sum ang/afformMASWhatever.aff.* ext/mascode/ang/afformMASWhatever.aff.*'

# 2. Back up the shadow OUTSIDE the scanned dir, then verify the copy.
ssh mas-prod 'd=~/tmp/afform-shadow-backup-$(date +%F) && mkdir -p "$d" &&
  cp -p ~/web/masadvise.org/public_html/wp-content/uploads/civicrm/ang/afformMASWhatever.aff.* "$d"/ &&
  sha256sum "$d"/*'

# 3. Only now clear it. Use the API, not rm: Afform.revert also reconciles managed
#    entities when a dashlet setting changed. See mas-prod-access for prod's cv invocation.
cv api4 Afform.revert '{"where":[["name","=","afformMASWhatever"]]}'
cv flush
```

Step 1 compares **both** files. A shadow may hold only the `.aff.html`. In that case compare
that one, and confirm the repo's `.aff.json` is the one prod already served. `Afform.revert`
deletes whichever local files exist.

### A-5. Verify

`cv api4 Afform.get '+w' 'name=afformMASWhatever' '+s' 'name,has_local,has_base,server_route,permission'`
must show `has_local` empty and `has_base` = 1, and the site-local dir must no longer list the
form. Load the form once in a browser, since a checksum URL does not reach a guarded public form
(memory `reference_afform_render_verification`). Keep the backup until Brian has seen the form work.

---

## B. Message templates: sync prod into the repo BEFORE anyone edits the declaration

### The mechanism (corrected in v1.1.25; full detail in `Civi/Mascode/Managed/README.md`)

The declarations say `update => 'unmodified'`, and **it does not protect a UI edit.**
`MessageTemplate` is not an APIv4 ManagedEntity, so `civicrm_managed.entity_modified_date` is
**never stamped** for it and the policy degrades to always-update. What actually happens:

- A UI edit survives **only while the declaration's checksum is unchanged**, because
  `optimizePlan()` drops the update then. The moment anyone changes the `.mgd.php` or its
  `.body.html` and deploys, **the repo overwrites the live row**. An extension disable/enable
  does it too.
- So **the repo is what reaches prod.** A repo fix is not "reverted"; it lands.
- `cv flush` alone is **not** a restore path. With the checksum unchanged, it does nothing.
- **Any query filtering on `entity_modified_date IS NOT NULL` can never find template drift.**
  The column is always NULL here. The only working drift check is a **content diff**.

### B-1. Find the drift (read-only)

- **Where `scripts/check-managed-drift.php` has section 3** (from v1.1.25): run it on that
  environment. *TEMPLATE CONTENT DRIFT* lists each declaration whose live row differs, resolved
  through `civicrm_managed.entity_id` rather than by title, with byte counts only.
- **Prod on an older version** (v1.1.18 as of 2026-09-24): diff by hand over the read-only
  tunnel (`mas-prod-access`). Resolve the row the same way, through the managed binding:
  ```sql
  SELECT t.id, t.msg_title, HEX(t.msg_html) AS html_hex
    FROM civicrm_managed m JOIN civicrm_msg_template t ON t.id = m.entity_id
   WHERE m.module = 'mascode' AND m.entity_type = 'MessageTemplate' AND m.name = '<declaration name>';
  ```
  `HEX()` → `xxd -r -p > $CLAUDE_JOB_DIR/tmp/<name>.live.html` gives the **exact bytes**. The
  mysql client's own escaping mangles the CRLF bodies. Then `cmp`/`diff` against the
  `.body.html` sidecar. Diff `msg_subject` and `msg_title` against the `.mgd.php` too.

Not every declaration has a body. `MessageTemplate_MAS_SAS_Template_Deactivate.mgd.php` is a
deactivation pin.

### B-2. Bring the live copy into the repo

Show Brian a summary of the diff: which lines, how many bytes, never the text. **He decides
which side is right.** Usually the live one is newer, as with both templates on 2026-09-23.

**⚠ Write the body in BINARY mode.** Lifecycle bodies are **CRLF**. A Python text-mode write
reflows the whole file and buries a one-line change in a 50-line diff. Copy the
`xxd`-restored file straight over the sidecar, or use `'wb'`. Confirm with
`git diff --stat`: a one-word edit should be a one-line diff.

No upgrade step is needed to carry a body: once the repo matches, the deploy's reconcile writes
the same content back. That is a no-op on the environment it came from, and it carries the edit to
every other one. (The retired version of this skill said the opposite, and it was wrong.)

### B-3. ⚠ A `msg_title` change is a code change

`msg_title` is the key several subscribers look up by literal. On 2026-09-17 a rename of template
75 in the production UI silently stopped the client lifecycle transition and the arming of
`mas_lifecycle_close_chase`. And the next deploy that changes that declaration **renames the
row back**. If the diff shows a title change, grep the extension for the old literal first. The
known consumers are `ProjectLifecycleStatusSubscriber::TRANSITIONS`, `VcDigestMailer`,
`VcDigestSubmitSubscriber::COMPLETION_TEMPLATE`, `StaleServiceRequestCloser::CHASE_TEMPLATE`, and
`civirule_rule_action.action_params`, which is serialised so no deploy touches it. A real rename
is declaration + every literal + an `upgrade_NNNN` that renames the row and repoints the
CiviRules actions (`upgrade_5015` is the worked example), all in one commit. The naming
convention is in `Civi/Mascode/Managed/README.md` §"Message template naming", enforced by
`tests/Unit/Managed/MessageTemplateNamingTest.php`.

---

## Finishing

- `./vendor/bin/phpunit --testsuite unit`. The naming and frozen-title tests are the likeliest to
  catch a bad sync.
- On dev after merging: `cv flush`, then re-run `scripts/check-managed-drift.php`. The synced
  declarations should no longer appear.
- The commit message says what came from where (form or template, dev or prod), and what state
  prod is left in: still carrying the shadow (A-3 not yet deployed), or cleared (A-4 done). The
  next deploy behaves differently in each.
