---
name: mas-deploy
description: Deploy mascode and/or maswpcode changes to MAS production. Checks for uncommitted changes, runs security scan, commits, pushes, and gives Brian the SSH commands to pull on production. Use when Brian says "deploy", "push to prod", "deploy to production", or "/mas-deploy".
---

# MAS Deploy to Production

Deploy mascode (CiviCRM extension) and/or maswpcode (WordPress plugin) changes to production at masadvise.org.

**CRITICAL: Claude never executes commands on production. This skill produces instructions for Brian to run.**

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

## Step 5: Production Deploy Instructions

After pushing, give Brian the exact commands to run. Never execute these yourself.

**For mascode:**

Tell Brian:
```
Run these commands to deploy mascode to production:

ssh mas-prod "cd /home/mas/web/masadvise.org/public_html/wp-content/uploads/civicrm/ext/mascode && git pull origin master"
ssh mas-prod "cd /home/mas/web/masadvise.org/public_html && cv flush"
```

**For maswpcode:**

Tell Brian:
```
Run this command to deploy maswpcode to production:

ssh mas-prod "cd /home/mas/web/masadvise.org/public_html/wp-content/plugins/maswpcode && git pull origin master"
```

Note: maswpcode does not need `cv flush`.

---

## Step 6: Verification

After Brian confirms the deploy commands ran successfully:

1. If the change affects database queries or contact data, run a quick verification using the readonly production DB:
   ```bash
   ssh -f -N -L 3307:localhost:3306 mas-prod
   source /home/brian/buildkit/build/masdemo/web/wp-content/uploads/civicrm/ext/mascode/.env
   # Run appropriate verification query
   ```

2. If the change affects UI, suggest Brian check the relevant page, or use Playwright to screenshot (read-only observation only).

3. Report deployment complete with a summary of what was deployed.
