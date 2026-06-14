---
name: mas-deploy
description: Deploy mascode and/or maswpcode changes to MAS production. Checks for uncommitted changes, runs security scan, commits, pushes, and — after Brian approves the exact commands — executes the production deploy itself. Use when Brian says "deploy", "push to prod", "deploy to production", or "/mas-deploy".
---

# MAS Deploy to Production

Deploy mascode (CiviCRM extension) and/or maswpcode (WordPress plugin) changes to production at masadvise.org.

**CRITICAL — Production execution is approval-gated, not forbidden.** Claude may run commands on production, but only after showing Brian the exact commands and getting his explicit approval. The sequence is always **preview → approve → execute**:

1. **Preview** — print the exact command(s) verbatim in one block. No placeholders left unresolved, nothing hidden, no paraphrasing.
2. **Approve** — wait for Brian's explicit "yes" / "go" / "approved" for *that block*. Silence or an unrelated reply is not approval.
3. **Execute** — run them yourself via `ssh mas-prod`, then report the real output (don't claim success you didn't observe).

Approval covers the specific commands shown, for this deploy only — it does not carry to a different command set or a later turn. If you need to deviate, re-preview and re-confirm. This protocol applies to **every** production action — `git pull`, `cv flush`, file sync (`scp`/`rsync`), and any DB write — not just the git deploy below. Readonly inspection (Step 6) still follows the safe-inspection rules in the mascode `CLAUDE.md` and doesn't need a fresh approval each time.

---

## Step 1: Detect Changes

Check both repos for uncommitted changes. Run in parallel.

```bash
echo "=== mascode ==="
cd /home/brian/buildkit/build/masdemo/web/wp-content/uploads/civicrm/ext/mascode
git status --short
```

```bash
echo "=== maswpcode ==="
cd /home/brian/buildkit/build/masdemo/web/wp-content/plugins/maswpcode
git status --short
```

If neither repo has changes, tell Brian "Nothing to deploy — both repos are clean." and stop.

Report which repo(s) have changes and what files are modified/untracked. Ask Brian which repo(s) to deploy if both have changes.

---

## Step 2: Security Scan

For each repo being deployed, check for secrets in the changed files.

```bash
cd <repo_path>
# Check staged and unstaged changes for secrets
git diff HEAD | grep -E "(Bearer [a-zA-Z0-9]{20,}|sk-|xoxb-|api_key|password|secret)" || echo "No secrets found"
# Check that no .env files are being tracked
git status | grep "\.env" && echo "WARNING: .env file detected!" || echo "No .env files"
```

If any secrets are found, **stop immediately** and alert Brian. Do not proceed.

---

## Step 3: Review Changes

For each repo being deployed, show the diff for Brian to review.

```bash
cd <repo_path>
git diff
git diff --cached
```

Present a summary of what changed and ask Brian to confirm: "Ready to commit and push these changes?"

---

## Step 3.5: Diff vs Prod (Pre-push Sanity Check)

Before committing, show Brian exactly what will land on prod once he runs `git pull`. Prod tracks `origin/master`, so the diff between `HEAD` and `origin/master` is the deploy delta — including any unrelated commits sitting on the local branch from earlier sessions.

```bash
cd <repo_path>
git fetch origin master --quiet
git log --oneline origin/master..HEAD
git diff origin/master..HEAD --stat
```

If the commit list contains commits Brian didn't expect to ship (drift from an earlier session, partial WIP, etc.), **stop and ask** — never silently propagate them. This is the safeguard against the scp-style drift incident that motivated this skill enhancement: git makes the drift visible, but only if you look.

Re-confirm: "These N commits will land on prod once you `git pull`. Proceed?"

---

## Step 4: Commit and Push

For each repo being deployed:

```bash
cd <repo_path>
git add <specific_files>  # Never use git add -A
git commit -m "<commit message>

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>"
git push origin master
```

**Commit message guidelines:**
- Lead with what changed, not "Update" or "Fix"
- Keep the first line under 72 characters
- Add detail in the body if the change is non-obvious

---

## Step 5: Production Deploy (preview → approve → execute)

After pushing, **preview** the exact command block to Brian, get his explicit approval, then **execute** it yourself via `ssh mas-prod` and report the real output. Show the commands verbatim — never paraphrase them in the preview.

**Backup first — capture the current prod SHA so revert is one command.** Make this the first line of the approved block and record the printed SHA in the deploy summary (Step 6). If anything goes sideways, `git reset --hard <captured-sha>` rolls back exactly that one repo. Repeat for maswpcode if it's also being deployed.

```
ssh mas-prod "cd /home/mas/web/masadvise.org/public_html/wp-content/uploads/civicrm/ext/mascode && git rev-parse HEAD"
```

**For mascode** — preview, then on approval run:
```
ssh mas-prod "cd /home/mas/web/masadvise.org/public_html/wp-content/uploads/civicrm/ext/mascode && git pull origin master"
ssh mas-prod "cd /home/mas/web/masadvise.org/public_html && cv flush"
```

**For maswpcode** — preview, then on approval run:
```
ssh mas-prod "cd /home/mas/web/masadvise.org/public_html/wp-content/plugins/maswpcode && git pull origin master"
```

Note: maswpcode does not need `cv flush`.

**If the harness blocks an approved prod command** (auto-mode classifier denial), tell Brian exactly what was blocked and ask him to run that one line himself or re-authorize — don't silently abandon the deploy or work around the denial.

---

## Step 6: Verification

Once the deploy commands have run:

1. If the change affects database queries or contact data, run a quick verification yourself using the readonly production DB:
   ```bash
   ssh -f -N -L 3307:localhost:3306 mas-prod
   source /home/brian/buildkit/build/masdemo/web/wp-content/uploads/civicrm/ext/mascode/.env
   # Run appropriate verification query
   ```

2. If the change affects UI, check the relevant page yourself via Playwright (read-only screenshot), or suggest Brian eyeball it.

3. Report deployment complete with a summary of what was deployed.
