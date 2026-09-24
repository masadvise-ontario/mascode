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
drops Brian's edit, and on prod the shadow was the only copy. So: merge, deploy, **prove by hash**
that the right file landed, back up, and only then revert. `Afform.revert` unlinks the files,
and nothing in CiviCRM can bring them back.

On **dev** the served mascode copy is the **canonical checkout**, not your worktree. So "deployed"
there means merged and present on that checkout's `master`.

### A-1. Find the shadows (read-only)

- dev: `cv scr scripts/check-managed-drift.php` → *OVERRIDDEN afforms* (`has_local` + `has_base`).
- prod: `ssh mas-prod 'ls -la ~/web/masadvise.org/public_html/wp-content/uploads/civicrm/ang/'`.
  The drift script also works on prod, but only on a deployed version that has it.

Ignore the `_vc-afform-migrated-*` marker dirs and `*.bak-*` files, which the scanner does not load.
Split what is left:
- **The same basename exists in `mascode/ang/`**: Brian's edit to our form. Sync it.
- **Not in mascode** (e.g. `afsearchContactSearch.aff.*`, a SearchKit form): **leave it alone.**
  It is site-local by design. Do not adopt it without asking.

### A-2. Isolate Brian's edit, and merge it (never copy over)

**Do not copy the shadow over the repo file.** FormBuilder saved the shadow from the copy that
environment was *serving*. On prod that is the deployed version, which can be many releases
behind `master`. Copying it over would silently undo every `master` change to that form since.
Instead, three-way merge:

```bash
T=$CLAUDE_JOB_DIR/tmp/afform && mkdir -p $T && F=afformMASWhatever
P=~/web/masadvise.org/public_html/wp-content/uploads/civicrm
scp "mas-prod:$P/ang/$F.aff.html" "$T/$F.shadow.html"            # Brian's edit
scp "mas-prod:$P/ext/mascode/ang/$F.aff.html" "$T/$F.base.html"   # what he started from
sha256sum "$T/$F.shadow.html"                                      # RECORD this - A-4 needs it
git merge-file -p ang/$F.aff.html "$T/$F.base.html" "$T/$F.shadow.html" > "$T/$F.merged.html"
```

Do the same for `.aff.json` if the shadow has one. `.aff.html` is the layout. `.aff.json` is the
metadata (title, permission, `server_route`, tags), and a save writes `$item + $orig`, so the shadow
JSON can carry environment-specific keys that do not belong in the repo. Diff it and drop those.
When `master` never touched the form, the merge is simply the shadow. On a conflict, stop and show
Brian.

Show Brian `diff base shadow` (**his** edit) and `diff ang/$F.aff.html merged` (what the repo
will get). They should say the same thing. **Call out by name** any change to `permission`,
`server_route` or `is_public`, which are security changes.

Traps to check in the diff:
- **An `af-if` you did not get from FormBuilder**: stop and ask. A hand-written one renders in
  the browser while core's parser, required-field validation and FormBuilder disagree, and
  FormBuilder silently deletes it.
- **`display-name=""`** loses `acl_bypass`, and VC Portal screens then show "None found".
- **A new `autofill="entity_id"` fieldset, or a link carrying a record id into a public form**:
  read `ang/README.md` §"Security: public forms and caller-supplied record ids" and re-run the
  two checks it names.

### A-3. Land it where the form is served

Branch → PR → review → merge. Then:
- **prod**: deploy (`mas-deploy`). Until that is done, **stop here.** Prod keeps serving the
  shadow, which is Brian's edit, so leaving it is safe.
- **dev**: the canonical checkout must already be on `master` and contain the merge. Check with
  `git -C <canonical> rev-parse --abbrev-ref HEAD` and
  `git -C <canonical> merge-base --is-ancestor <merge-sha> HEAD`. **Never `checkout` or `reset`
  it**: it is shared with other sessions. If it is on another branch, stop and ask Brian.

Record the repo file's hash: `git show origin/master:ang/$F.aff.html | sha256sum` (and `.aff.json`).

### A-4. Prove it, back up, clear: ONE fail-closed command (prod: approved write)

Preview the filled-in block to Brian and run it only on his "yes". `set -eu` plus
`sha256sum -c` means **any mismatch aborts before the delete**. Check (a) shows no newer
FormBuilder edit has landed since A-2. Check (b) shows the deployed file is the merged,
reviewed one.

```bash
ssh mas-prod 'set -eu
F=afformMASWhatever
C=~/web/masadvise.org/public_html/wp-content/uploads/civicrm
cd "$C"
echo "<shadow html sha from A-2>  ang/$F.aff.html"            | sha256sum -c -   # (a)
echo "<repo html sha from A-3>  ext/mascode/ang/$F.aff.html"  | sha256sum -c -   # (b)
# If the shadow has a .aff.json, add the same two lines for it. Do not drop them.
d=~/tmp/afform-shadow-backup-$(date +%F-%H%M%S)
mkdir -p "$d"
for f in "ang/$F.aff.html" "ang/$F.aff.json"; do
  if [ -e "$f" ]; then cp -p "$f" "$d"/; cmp "$f" "$d/$(basename "$f")"; fi
done
ls -la "$d"
cd ~/web/masadvise.org/public_html
HOME=/home/mas/tmp bin/cv api4 Afform.revert "{\"where\":[[\"name\",\"=\",\"$F\"]]}" --user=<prod admin login>
HOME=/home/mas/tmp bin/cv flush'
```

`Afform.revert`, not `rm`. It unlinks only that form's site-local `.aff.html`/`.aff.json`, and
reconciles afform's own managed records if the dashlet or navigation setting changed. `where` is
required, so it cannot revert every form by accident. The backup goes to `~/tmp`, outside anything
the scanner loads, named to the second so a second run the same day cannot overwrite it.

**Dev variant.** Same shape, run locally: `C=/home/brian/buildkit/build/masdemo/web/wp-content/uploads/civicrm`,
(b) checks `ext/mascode/ang/$F.aff.html` (the canonical checkout), the backup goes to
`~/backup/afform-shadow-backup-<ts>/`, and the revert is
`/home/brian/buildkit/bin/cv api4 Afform.revert ... --user=brian.flett@masadvise.org`.

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
  ```bash
  N='<declaration name>'; OUT=$CLAUDE_JOB_DIR/tmp/$N.live.html
  mysql --defaults-extra-file=<readonly cnf> -N -B -e "
    SELECT HEX(t.msg_html) FROM civicrm_managed m JOIN civicrm_msg_template t ON t.id = m.entity_id
     WHERE m.module = 'mascode' AND m.entity_type = 'MessageTemplate' AND m.name = '$N'" \
    | xxd -r -p > "$OUT"
  test -s "$OUT" || echo "EMPTY - no bound row, or the query failed. Do not diff."
  ```
  `-N -B` and a **HEX-only** select are both required. `xxd -r -p` stops at the first non-hex
  character, so a header line or an extra column yields a one-byte file. That would read as
  "everything drifted", and copying it over the sidecar would truncate the body. `HEX()` is used
  because the mysql client's own escaping mangles CRLF bodies, and this gives the **exact bytes**.
  Then `cmp`/`diff` against the `.body.html` sidecar. Read `msg_title` and `msg_subject` in a
  separate query, and diff them against the `.mgd.php`.

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
