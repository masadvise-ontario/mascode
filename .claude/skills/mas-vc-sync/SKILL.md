---
name: mas-vc-sync
description: Audit and repair Volunteer Consultant (VC) identity sync across Microsoft 365 (Entra ID), WordPress, and CiviCRM for the masadvise.org VC Portal. Detects and fixes the WPO365 duplicate-contact problem (login creates a new CiviCRM contact instead of linking to the VC's MASRep). Use when Brian says "check VC sync", "audit VC accounts", "are the VC portal accounts in sync", "find duplicate VC contacts", "a VC can't see their cases", or "/mas-vc-sync".
---

# MAS VC Identity Sync (Microsoft ↔ WordPress ↔ CiviCRM)

Every Volunteer Consultant who has VC Portal access should exist in **three** systems, correctly linked:

| System | What they should have |
|--------|-----------------------|
| **Microsoft 365 (Entra ID)** | A member account, UPN = `firstname.lastname@masadvise.org` (not a `#EXT#` guest) |
| **WordPress** | A **subscriber** user; `user_login`/`user_email` = the masadvise.org address; WP `ID` = N |
| **CiviCRM** | An Individual contact with sub-type **MAS_Rep**, holding the masadvise.org email as a **non-primary "Other"** email; their **personal** email stays the **primary**. A `civicrm_uf_match` row links WP user N → this contact. |

This lets the VC sign in via Microsoft SSO and see the cases assigned to them (cases attach to the MASRep contact).

## How the duplicate problem happens (root cause)

WPO365 has **no CiviCRM integration** — it only creates the WordPress user. WordPress then fires `user_register`, and CiviCRM's WP layer calls `CRM_Core_BAO_UFMatch::synchronize` → `matchContactOnEmail(masadvise.org email, 'Individual')`. That matches **any** email (primary or Other) on a non-deleted Individual.

- If the masadvise.org email is **already on the MASRep** (as Other) → it links the WP user to the MASRep. ✅
- If it is **missing or mistyped** → CiviCRM **creates a brand-new contact** (masadvise.org as primary, no MAS_Rep sub-type) and binds the WP user's `uf_match` to that **dupe**. ❌

Once a `uf_match` row points at the dupe, every later login reuses it (the binding is sticky). Adding the email to the MASRep afterward does **not** fix the existing binding — that requires re-pointing `uf_match` and merging the dupe (see Fix).

**Prevention:** add the masadvise.org email as an "Other" email on the VC's MASRep **before** their first sign-in, spelled **exactly** like the M365 UPN. A typo (e.g. `…@masadvise.or`) breaks the match and creates a dupe.

---

## Phase 1 — Gather data from all three systems (read-only)

Read-only inspection follows the safe-inspection rules in the mascode `CLAUDE.md`; no approval needed. Production CiviCRM/WordPress runs on `mas-prod`; web root is `/home/mas/web/masadvise.org/public_html`, `cv` is `/home/mas/local/bin/cv`.

### 1a. Microsoft 365 / Entra ID — `az` (already installed; authed to the MAS tenant as brian.flett@masadvise.org)

```bash
# All masadvise.org member accounts (excludes #EXT# guests)
az ad user list \
  --query "[?ends_with(userPrincipalName, '@masadvise.org')].{name:displayName, upn:userPrincipalName, mail:mail}" \
  -o json
```

No separate CLI is needed — `az ad user list` queries Entra ID directly. (Per-user license/`accountEnabled` detail isn't returned reliably by `az ad`; if you need it, call Graph: `az rest --method GET --url "https://graph.microsoft.com/v1.0/users?\$select=displayName,userPrincipalName,accountEnabled,assignedLicenses"`.)

### 1b. WordPress users — `wp-cli` on prod

```bash
ssh mas-prod 'cd /home/mas/web/masadvise.org/public_html && \
  wp user list --role=subscriber --fields=ID,user_login,user_email,display_name --format=json'
```
- The WP `ID` equals `civicrm_uf_match.uf_id`.
- Ignore any Elementor `symfony/dependency-injection ... deprecated` PHP notice on stderr — it's harmless noise.

### 1c. CiviCRM — `cv api4` on prod (or the SearchKit display in the UI)

GUI: **Contacts with masadvise.org Email** —
`https://www.masadvise.org/wp-admin/admin.php?page=CiviCRM&q=civicrm%2Fsearch#/display/Contacts_with_masadvise_org_Email`

CLI equivalents:
```bash
ssh mas-prod 'cd /home/mas/web/masadvise.org/public_html && CV=/home/mas/local/bin/cv

# Every contact holding a @masadvise.org email, with sub-type + primary flag
$CV api4 Email.get +w "email LIKE %@masadvise.org" \
  +s "contact_id,contact_id.sort_name,contact_id.contact_sub_type,contact_id.is_deleted,email,is_primary,location_type_id:name" +l 0 --out=json

# All WP↔contact links
$CV api4 UFMatch.get +s "id,uf_id,uf_name,contact_id" +l 0 --out=json

# Guard setting (must be false — see Phase 4)
$CV api4 Setting.get +s "syncCMSEmail" --out=json'
```

> ⚠️ `cv api4` boolean `+w "is_primary=true"` filtering is unreliable — do **not** filter on it. Pull all rows and evaluate `is_primary` in your own code (Python/jq).

---

## Phase 2 — Reconcile & classify

Join the three datasets on the masadvise.org email (and WP `ID` = `uf_match.uf_id`). For each masadvise.org address, classify:

| Class | Signature | Action |
|-------|-----------|--------|
| ✅ **In sync** | MASRep has masadvise as Other (personal primary); `uf_match` uf_id→MASRep; WP subscriber + Entra account exist | none |
| ❌ **Login dupe** | A contact with masadvise as **primary** and **no MAS_Rep** sub-type (often `sort_name` == the email); a separate MAS_Rep exists for the same person | **Fix** (merge into MASRep) |
| ❌ **Mislinked** | `uf_match.uf_id` (the WP user) → a dupe contact, not the MASRep | **Fix** (re-point) |
| ⚠️ **Missing/typo email** | MASRep exists (match by name) but lacks the masadvise email, or has a typo (`@masadvise.or`) | add/fix the Other email; then Fix the dupe |
| ❓ **No MASRep** | masadvise account + dupe, but **no** MAS_Rep contact for that person | **Ask Brian** — promote the dupe to a MASRep, create one, or exclude |
| ❓ **Duplicate MASReps** | >1 MAS_Rep for the same person (e.g. conflicting VC Status) | **Ask Brian** — field-level merge in CiviCRM's merge screen |
| ⚠️ **Orphan** | Entra/WP account with no MASRep, or MASRep with no Entra/WP, or WP user with no `uf_match` | report; decide per case |

### Exclude (NOT VC portal accounts) — never merge these
- **System/role mailboxes:** `info@`, `serviceadmin@`, `treasurer@`, `civicrmcron@`, `n8n_chatbot_user@`, anything `test*@masadvise.org`.
- **Tech consultants** (e.g. Edsel Lopez, Shyam Ramani): they have masadvise accounts but are **not MAS_Reps** and never sign into the VC Portal. Leave their contacts alone.

Always present the classified report **before** fixing, with the explicit exclusions listed, so Brian can confirm scope.

---

## Phase 3 — Fix (production writes — approval-gated)

**Production execution is approval-gated, not forbidden.** Same protocol as `mas-deploy`: **preview → approve → execute**. Show Brian the exact pairs/commands, get explicit approval, then run via `ssh mas-prod` and report real output. Test ONE pair first, verify, then batch.

### 3a. One-time global guard

CiviCRM's `syncCMSEmail` must be **off**, or a CiviCRM profile-form submit will overwrite the MASRep's personal primary with the masadvise.org email.
```bash
ssh mas-prod 'cd /home/mas/web/masadvise.org/public_html && \
  /home/mas/local/bin/cv api4 Setting.set values=\{\"syncCMSEmail\":false\}'
```

### 3b. Per dupe→MASRep pair (the proven recipe)

For each pair `email : dupeContactId : masRepContactId`:
1. **Re-point** the dupe's `uf_match` to the MASRep (skip if the dupe has none):
   ```bash
   ufid=$($CV api4 UFMatch.get +w "contact_id=$DUPE" +s "id" --out=json | jq -r '.[0].id // empty')
   [ -n "$ufid" ] && $CV api4 UFMatch.update +w "id=$ufid" +v "contact_id=$REP"
   ```
2. **Ensure** the MASRep has the masadvise email as a non-primary "Other" (only if missing):
   ```bash
   $CV api4 Email.create +v "contact_id=$REP" +v "email=$EMAIL" +v "location_type_id:name=Other" +v "is_primary=0"
   ```
3. **Delete** the dupe's email(s) — they're redundant and cause an "Email 1 (Main)" merge conflict:
   ```bash
   for eid in $($CV api4 Email.get +w "contact_id=$DUPE" +s "id" --out=json | jq -r '.[].id'); do $CV api4 Email.delete +w "id=$eid"; done
   ```
4. **Safe-merge** dupe → MASRep (moves any activities, soft-deletes the dupe; safe mode keeps the MASRep's fields):
   ```bash
   $CV api3 Contact.merge to_keep_id=$REP to_remove_id=$DUPE mode=safe
   ```
   If the result is `"skipped"`, inspect the conflict and resolve before retrying:
   ```bash
   $CV api3 Contact.get_merge_conflicts to_keep_id=$REP to_remove_id=$DUPE mode=safe
   ```
   - Conflict on **email Main** → you missed deleting the dupe's email (step 3).
   - Conflict on **custom fields** (VC Status, Enrollment Date, Skills/notes) → this is a real **duplicate MASRep** decision. Do **not** auto-resolve; hand it to Brian for a field-level merge in CiviCRM's merge screen.

### 3c. Verify each fix
```bash
# MASRep keeps a PERSONAL primary; masadvise is "Other"; uf_match points to the MASRep; dupe is deleted
$CV api4 Email.get +w "contact_id=$REP" +s "email,is_primary,location_type_id:name" --out=json   # primary must NOT be @masadvise.org
$CV api4 UFMatch.get +w "contact_id=$REP" +s "uf_id,contact_id" --out=json
$CV api4 Contact.get +w "id=$DUPE" +w "is_deleted IN [0,1]" +s "is_deleted" --out=json            # true
```

### 3d. Final completeness scan
List contacts whose masadvise.org email is **actually primary** (evaluate `is_primary` in code, not via `+w`); the only ones left should be the excluded system/consultant accounts:
```bash
$CV api4 Email.get +w "email LIKE %@masadvise.org" \
  +s "contact_id,contact_id.sort_name,contact_id.contact_sub_type,contact_id.is_deleted,email,is_primary" +l 0 --out=json
```

---

## Reference

- Mechanism confirmed in CiviCRM source: `CRM/Core/BAO/UFMatch.php::synchronize` (login → `matchContactOnEmail`, line ~202) and `CRM/Contact/BAO/Contact.php::matchContactOnEmail` (matches any email, ordered `is_primary DESC`). `updateContactEmail` (gated by `syncCMSEmail`) is the field that can clobber the personal primary.
- Optional WPO365 hardening: unchecking **"Create new users"** in WPO365 settings makes it match existing WP users (by Object ID / UPN / email) instead of provisioning new ones — a second line of defense, but requires pre-creating WP accounts. The email-on-MASRep-first process above is the low-effort prevention.
- Klaus memory: `reference_civicrm_wpo365_vc_identity_sync` (full diagnosis + the 2026-06-24 cleanup of 18 dupes).
