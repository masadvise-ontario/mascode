---
name: mas-sync-back
description: Reverse-engineer UI edits back into mascode. Brian edits an Afform in FormBuilder or a message template in the CiviCRM UI, on dev or prod, then asks for those changes to land in the repo. Handles the site-local shadow that makes Afform edits invisible to git, the entity_modified_date freeze that makes template edits permanent, and the CRLF trap in template bodies. Use when Brian says "sync that back", "pull my changes into mascode", "I edited the form on prod", "reverse engineer that into the repo", or "/mas-sync-back".
---

# Sync UI edits back into mascode

Brian edits things in the CiviCRM UI. Those edits do **not** reach the repo by themselves,
and in one of the two cases they do not even show up in `git status`. This skill brings
them back.

**Two asset types, two completely different mechanisms.** Get this wrong and you will
spend an hour looking in the wrong place:

| | Where the UI edit lands | Does `git status` see it? |
|---|---|---|
| **Afform** | A **file**, in the site-local `ang/` dir — *not* the mascode dir | **No.** Silently shadows mascode |
| **Message template** | A **database row** | No — it is a DB row, not a file |

There is **no Afform table in the database.** `civicrm_afform_submission` holds
submissions, not definitions. If you go looking for form definitions in SQL you will not
find them.

---

## Hard rules

1. **Production is read-only.** Read prod, write the repo. Never edit a prod file or row
   to "tidy up" while syncing. The one exception — clearing an Afform shadow on prod —
   is a separate, explicitly-approved step; see below.
2. **Show the diff before committing.** Always. Brian decides what is intentional and
   what was a mistake he made while clicking around.
3. **Never print template bodies or form data into the transcript.** These carry real
   client names, emails and case details. Diff them, summarise them, count them — do not
   paste them. Write to a scratch file and give Brian the path if he needs to read one.
4. **Check the clone date before trusting dev as a proxy for prod**
   (`ls -lt ~/backup/mas_dev_civi_*.sql | head -1`). They drift.

---

## A. Afforms — the shadow is the whole story

### What actually happens when Brian edits a form

`Civi/Api4/Utils/AfformSaveTrait.php:42,60` calls `createSiteLocalPath()` **on every
save**, unconditionally. It never writes to the extension directory. So a FormBuilder
edit to a mascode-shipped form creates a *new* file in the site-local dir:

- **dev**: `/home/brian/buildkit/build/masdemo/web/wp-content/uploads/civicrm/ang/`
- **prod**: `/home/mas/web/masadvise.org/public_html/wp-content/uploads/civicrm/ang/`

(Confirm rather than trust: `cv ev 'echo (new CRM_Afform_AfformScanner())->getSiteLocalPath();'`)

That directory has **weight 200** in `CRM_Afform_AfformScanner` — the highest of any
search path, above every extension. So the shadow **wins**, and:

- `git status` in mascode is **clean**. Nothing to see.
- The mascode copy is live-dead: it is still the file in git, and it is not the file
  being served.
- **A future deploy that changes the mascode copy will appear to do nothing**, because
  the shadow still wins. This is the failure mode this skill exists to prevent.

### The procedure

1. **Find the shadows.** On the environment Brian edited:
   ```
   ls -la <site-local ang dir>
   ```
   Ignore `_vc-afform-migrated-*` marker dirs and any `*.bak-*` files — a `.bak-` suffix
   means the scanner does not load them. You are looking for `*.aff.html` / `*.aff.json`.

2. **Split them into two groups**, because they need opposite treatment:
   - **Shadowing a mascode form** — the same basename exists in `mascode/ang/`. These
     are Brian's edits to our forms. Sync and then clear.
   - **Not in mascode** — e.g. `afsearchContactSearch.aff.*`, a SearchKit-generated
     form. **Leave these alone.** They are site-local by design and belong to whoever
     made them. Do not "adopt" one into mascode without asking.

3. **Diff each shadowing pair** — repo copy vs shadow — and show Brian. Both files
   matter: `.aff.html` is the layout, `.aff.json` the metadata (title, permissions,
   `server_route`, tags). A permissions or `server_route` change in the `.json` is a
   security-relevant change and deserves calling out by name.

4. **Copy the shadow over the mascode copy**, for the files Brian confirms.

5. **⚠ Then clear the shadow, or nothing has been achieved.** Use the API, not `rm` —
   `Afform.revert` deletes both files *and* reconciles managed entities if a dashlet
   setting changed:
   ```
   cv api4 Afform.revert '{"where":[["name","=","afformMASWhatever"]]}' --user=brian.flett@masadvise.org
   ```
   **On prod this is a write.** Preview the exact command, get Brian's explicit approval,
   then run it — the same preview → approve → execute rule as `mas-deploy`.

6. **`cv flush`**, then verify the served form is now the repo's:
   ```
   cv api4 Afform.get '+w' 'name=afformMASWhatever' '+s' 'name,title,server_route,permission'
   ```
   And re-confirm the site-local dir no longer holds that form.

7. **Commit** with a message saying which form, which environment it came from, and
   that the shadow was cleared.

### Gotchas

- **Never hand-write an `af-if`.** It renders correctly in the browser while core's PHP
  parser, required-field validation and FormBuilder all disagree, and FormBuilder
  silently deletes it. If a diff shows an `af-if` you did not get from FormBuilder,
  stop and ask.
- **`display-name=""` loses `acl_bypass`** and silently renders "None found" on VC
  Portal screens. If a diff removes a display name, flag it.
- **Public forms and caller-supplied record ids**: if the diff adds an
  `autofill="entity_id"` fieldset or a link carrying a `case_id`/`contact_id` into a
  public form, read `ang/README.md` § "Security: public forms and caller-supplied record
  ids" and re-run the two checks it names before committing.

---

## B. Message templates — the freeze is the whole story

### What actually happens when Brian edits a template

The edit goes to the `civicrm_msg_template` row. Then
`CRM_Core_BAO_Managed::on_hook_civicrm_post()` stamps
`civicrm_managed.entity_modified_date` for that row — and
`updateExistingEntity()` reads `update => 'unmodified'` as
`$doUpdate = empty($entity_modified_date)`.

**So a hand-edited template is never rewritten by a deploy again.** The repo copy
becomes a historical artifact, quietly. That is why syncing back matters here: not to
avoid losing the edit, but because from that point the declaration no longer drives the
template and a `.body.html` change would not land.

### The procedure

1. **Find what drifted.** Which managed templates are stamped:
   ```sql
   SELECT t.id, t.msg_title, m.name, m.entity_modified_date
   FROM civicrm_managed m JOIN civicrm_msg_template t ON t.id = m.entity_id
   WHERE m.entity_type = 'MessageTemplate' AND m.module = 'mascode'
   ORDER BY m.entity_modified_date IS NULL, t.id;
   ```
   `entity_modified_date IS NULL` means deploy-live and unedited. Non-NULL means frozen —
   those are the candidates.

2. **Diff DB `msg_html` against the `.body.html` sidecar.** Not every mascode template
   has one; `MessageTemplate_MAS_SAS_Template_Deactivate.mgd.php` is a deactivation pin
   with no body. Also diff `msg_subject` and `msg_title` against the declaration.

3. **⚠ Write the body in BINARY mode.** Lifecycle template bodies are **CRLF**. A Python
   text-mode write reflows the entire file and buries a one-line change in a 50-line
   diff. Use `sed`, or open with `'wb'`, or dump straight from MySQL to the file.

4. **Show Brian the diff, then update** the `.body.html` and, if subject/title changed,
   the `.mgd.php`.

5. **Decide whether an upgrade step is needed.** This is the judgement call:
   - **Stamped only on the environment Brian edited** (e.g. dev), other environments
     still NULL → the declaration will carry the change everywhere else. No step needed.
   - **Stamped on prod** → the declaration will *not* rewrite prod, so the repo change
     alone will never reach it. Ship an `upgrade_NNNN` that writes the value explicitly.
     `upgrade_5015` and `upgrade_5016` in `CRM/Mascode/Upgrader.php` are the worked
     examples — idempotent, branch on current state, log what they did.

6. **Do not clear `entity_modified_date` to "unfreeze" it.** On a site where a human
   curated that template, clearing the stamp hands the next `cv flush` permission to
   overwrite their work.

### Gotcha: renaming a template is a code change

`msg_title` is a match key and several subscribers key lookups by the literal string.
Renaming template 75 in the production UI on 2026-09-17 silently stopped the client
lifecycle transition and the arming of `mas_lifecycle_close_chase`. If a diff shows a
`msg_title` change, grep the extension for the old literal before doing anything —
`ProjectLifecycleStatusSubscriber::TRANSITIONS`, `VcDigestMailer`,
`VcDigestSubmitSubscriber::COMPLETION_TEMPLATE` and `civirule_rule_action.action_params`
are the known consumers, and the last one is serialised so no deploy touches it.

Naming convention for any template you add or rename: `Civi/Mascode/Managed/README.md`
§ "Message template naming", enforced by `tests/Unit/Managed/MessageTemplateNamingTest.php`.

---

## Finishing

- `cv flush`, then `./vendor/bin/phpunit --testsuite unit` — the naming and frozen-name
  tests are the ones most likely to catch a bad sync.
- Commit on a branch and open a PR. A sync-back changes what ships to prod, so it goes
  through the review gate in `CLAUDE.md` like any other behaviour change.
- If the sync came **from** prod, say so in the commit message and note whether prod
  still carries the edit or was reverted to the repo copy. Those are different states
  and the next deploy behaves differently in each.
