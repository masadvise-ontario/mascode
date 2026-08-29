# Task #159 — anonymous Afform prefill leak: what was found, fixed, and how to deploy

> **This file is a working handover, not documentation.** Delete it once the fix is on
> production. The durable version lives in `ang/README.md` §"Security: public forms and
> caller-supplied record ids" and in the two classes' docblocks.

---

## 1. What was actually wrong

Two separate anonymous disclosures on the seven `is_public` + `*always allow*` MAS client
afforms. Every `af-entity` on them is `security="FBAC"`, so reads run
`checkPermissions => FALSE`: the form's configuration is meant to be the only limit. That
holds right up to the point where **the caller** chooses the record id.

**(a) The reported one — caller-supplied ids.** No cookie, no session, no `_aff` token:

```
POST civicrm/ajax/api4/Afform/prefill
params={"name":"afformProjectCloseClientFeedback","fillMode":"form","args":{"case_id":<id>}}
```

returned the project code, subject, dates and the whole `Project_Close_Client` field set.
Case ids are sequential integers. `contact_id` was worse on `afformMASRCSForm`, where the
`relationship:` autofills walked one contact out to their employer organisation and then to
that organisation's President and Executive Director.

**(b) Found by review, not in the original report — `fillMode: "join"`.** This one carries
no id at all, so filtering by argument name could not see it:

```
POST civicrm/ajax/api4/Afform/prefill
{"name":"afformMASRCSForm","fillMode":"join",
 "args":{"Organization1":[{"joins":{"Address":[{"city":"Toronto"}]}}]}}
```

returned a real client street address; the same shape returned Email (an email-existence
oracle) and Phone. `loadJoin()` builds its WHERE straight from caller-supplied values with
no scoping to a parent record. It is tempting to assume core validates this via
`validateBySavedSearch()` — it does not: that runs only when the key field has a
`defn.saved_search` (no MAS field does), and for joins it cannot run at all because core
tests `$entity` while its parameter is `$afEntity`.

Both also applied to `Afform.submit`, which turns the same args into the records it writes
to — so a crafted submit could have written client feedback onto an arbitrary case.

## 2. The fix

`Civi/Mascode/Event/AfformPublicArgGuardSubscriber.php` on `civi.api.prepare` (priority
-1000). That event is the last point at which `args` can still be edited —
`civi.afform.prefill` is dispatched *per entity, after that entity has loaded*, so it
cannot be a gate. `Civi/Mascode/Security/AfformArgPolicy.php` holds the pure rules so they
can be unit tested without a CiviCRM bootstrap (CI has no CiviCRM).

- Guarded form = `permission` contains `*always allow*`. **Not** `is_public` — that flag
  only picks the frontend/backend URL scheme; access is decided by `permission` alone.
- Fill modes are an **allowlist**: only `form` is filtered key by key, everything else has
  its args cleared. Core branches on `=== 'join'` and treats every other value identically,
  so a denylist would have been right only by accident.
- In `form` mode, five id args must each be entitled: `case_id` if the caller is an active
  Case Coordinator, or (reads only, with `access CiviCRM`) the case is in the
  Sent-for-Assignment pool. Everything else refused.
- Refused **read** → argument dropped, fieldset renders blank. Refused **write** → throws
  403 with a readable message. A silently unattached activity is worse than an error.
- Staff (`administer CiviCRM` / `edit all contacts`) exempt. Deliberately *not*
  `view all contacts` or `access all cases and activities` — measured on dev, a VC on the WP
  contributor role holds both, so either would exempt real VCs.
- **Token links are untouched.** Their ids arrive from the signed JWT via the authx session
  *after* this guard has run on the caller's own args, so the guard needs no crypto.
- **The VC Portal is preserved.** `afsearchMASCaseDetailsVC.aff.html` links to the two VC
  forms with `#?case_id=…`; entitlement reuses the portal's own documented predicate from
  `SavedSearch_Case_Details_VC.mgd.php`.

## 3. Verification on dev

| Check | Result |
|---|---|
| `tests/Security/afform-prefill-anon-probe.sh` (49 vectors × 7 forms) | clean |
| Same probe with the guard removed (negative control) | **18 of 49 leak** |
| Every fillMode: `form`, `entity`, `join`, `''`, `JOIN`, `xyz` | clean |
| `AfformPublicArgGuardTest.php` as a non-staff VC | 10/10 |
| VC Portal: own case + pooled case still prefill | yes |
| Tokenised client email link | still prefills; cannot pivot to another case |
| Refused anonymous submit | HTTP 403, readable message, 0 rows written |
| `phpunit --testsuite=unit` | 45 tests green |
| PSR-12 on new files | clean |

Reviewed in fresh context over four rounds. Round 1 found 1 High + 5 Medium; round 2, 3
Medium; round 3, 1 Medium; all applied. Rounds 2–4 found no Critical and no High.

## 4. Deploying

⚠ **Read this first.** `masadvise-ontario/mascode` is a **public** repo and production
deploys *by pulling from it*. Pushing this branch publishes a working exploit against live
client data, and prod is not patched until it pulls. Pick a sequence deliberately.

**Option A — patch prod first (recommended, zero public window).**

```bash
# 1. Copy the four files to prod (adjust the remote path to the real ext dir)
scp Civi/Mascode/Event/AfformPublicArgGuardSubscriber.php \
    mas-prod:<ext>/mascode/Civi/Mascode/Event/
ssh mas-prod 'mkdir -p <ext>/mascode/Civi/Mascode/Security'
scp Civi/Mascode/Security/AfformArgPolicy.php \
    mas-prod:<ext>/mascode/Civi/Mascode/Security/
scp tests/Security/afform-prefill-anon-probe.sh \
    mas-prod:<ext>/mascode/tests/Security/

# 2. Flush
ssh mas-prod 'cd <wp-root> && <cv> flush'

# 3. Confirm the guarded set on PROD — dev is not proof of prod
ssh mas-prod "cd <wp-root> && <cv> api4 Afform.get \
  '{\"select\":[\"name\",\"permission\"],\"where\":[[\"permission\",\"CONTAINS\",\"*always allow*\"]]}'"

# 4. Verify from your own machine, anonymously, with ids known to exist on prod
tests/Security/afform-prefill-anon-probe.sh https://www.masadvise.org <case_id> <contact_id>
#    -> must print "OK — 49 probes, no data returned anonymously"

# 5. Only then: push, PR, merge. Prod's next `git pull` becomes a no-op for these files.
```

**Option B — normal flow.** Push → PR → merge → deploy immediately per
`docs/DEPLOYMENT.md` (`git pull` → `cv ext:upgrade-db` → `cv flush`). Shorter path, but
leaves a window where the exploit is public and prod is not yet patched.

Either way: **run step 4's probe against production afterwards.** It is read-only and safe
there, and it is the only thing that proves the hole is shut on the site that matters.
A pass means nothing if the ids do not exist — the script says so itself.

## 5. Still open — worth their own tasks

1. **Submit-side `values` join ids.** `preprocessSubmittedValues()` explicitly allows a
   join's id field (`$allowedFields[$idField] = TRUE`) and `fillIdFields()` will not
   overwrite a caller-supplied `fields.id`. The main entities on these forms are safe
   because `id` is not a declared `af-field`, but join rows (Email/Phone/Address) may not
   be. **Untested** — testing it means writing to dev and possibly firing lifecycle email.
2. **Authenticated VCs can still read any case directly.** Pre-existing and already noted
   in `tests/Security/CaseDetailAccessTest.php`: prod VC roles carry
   `access all cases and activities`, so a hand-crafted API4 call bypasses the portal's
   filter-as-security entirely. This guard narrows the afform route only; it does not and
   cannot fix that.
3. **`SavedSearch_Case_Details_VC` may surface deleted pooled cases** — its predicate omits
   `is_deleted`, unlike the guard. Cosmetic, but it is a divergence.
