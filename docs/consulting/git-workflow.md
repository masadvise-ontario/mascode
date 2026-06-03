# Git Workflow Standards

This reference documents Git workflow practices for MAS projects and nonprofit consulting work.

## Branching Strategy

### Branch Types

```
main (production)
  ↓
develop (integration)
  ↓
feature/* (new features)
hotfix/* (urgent fixes)
release/* (release preparation)
```

### Branch Naming Conventions

**Feature Branches**
- Pattern: `feature/description-of-feature`
- Examples:
  - `feature/donor-intelligence-dashboard`
  - `feature/civicrm-case-automation`
  - `feature/wordpress-contact-sync`

**Hotfix Branches**
- Pattern: `hotfix/description-of-fix`
- Examples:
  - `hotfix/email-validation-bug`
  - `hotfix/api-authentication-error`
  - `hotfix/form-submission-timeout`

**Release Branches**
- Pattern: `release/v1.2.0`
- Examples:
  - `release/v1.0.0`
  - `release/v2.1.0`

### Branch Lifecycle

#### Feature Development
```bash
# Start new feature
git checkout develop
git pull origin develop
git checkout -b feature/donor-scoring

# Work on feature
git add .
git commit -m "feat: add donor scoring algorithm"

# Keep updated with develop
git checkout develop
git pull origin develop
git checkout feature/donor-scoring
git merge develop

# When complete, create pull request
# After approval and merge, delete branch
git branch -d feature/donor-scoring
```

#### Hotfix Process
```bash
# Critical bug in production
git checkout main
git pull origin main
git checkout -b hotfix/form-validation

# Fix bug
git add .
git commit -m "fix: validate email before CiviCRM submission"

# Merge to main
git checkout main
git merge hotfix/form-validation
git tag -a v1.0.1 -m "Hotfix: form validation"
git push origin main --tags

# Also merge to develop
git checkout develop
git merge hotfix/form-validation
git push origin develop

# Delete branch
git branch -d hotfix/form-validation
```

## Commit Message Standards

### Semantic Commit Format

```
<type>(<scope>): <subject>

<body>

<footer>
```

### Commit Types

| Type | Description | Example |
|------|-------------|---------|
| `feat` | New feature | `feat: add donor intelligence monitoring` |
| `fix` | Bug fix | `fix: correct email validation in contact form` |
| `docs` | Documentation only | `docs: update API integration guide` |
| `style` | Formatting, missing semicolons, etc. | `style: format code with Prettier` |
| `refactor` | Code change that neither fixes nor adds | `refactor: simplify contact sync logic` |
| `test` | Adding or updating tests | `test: add unit tests for donor scoring` |
| `chore` | Maintenance tasks | `chore: update dependencies` |
| `perf` | Performance improvement | `perf: optimize database queries` |

### Commit Message Examples

**Good Examples:**

```bash
feat(civicrm): add API v4 contact search endpoint

Implements new search functionality using CiviCRM API v4 instead of deprecated v3. Includes support for custom fields and related entities.

Closes #45
```

```bash
fix(wordpress): prevent duplicate contact creation

Added email uniqueness check before creating CiviCRM contact from WordPress registration.

Fixes #78
```

```bash
docs(n8n): document donor monitoring workflow

Added comprehensive documentation for the donor intelligence workflow including setup, configuration, and troubleshooting.
```

**Bad Examples:**

```bash
# ❌ Too vague
fix stuff

# ❌ No type prefix
added new feature

# ❌ Not descriptive
updated files

# ❌ Mixed concerns
feat: add donor scoring and fix email bug and update docs
```

### Scope Guidelines

Use scope to indicate which part of codebase is affected:

- `civicrm` - CiviCRM-related changes
- `wordpress` - WordPress-specific code
- `n8n` - Workflow automation
- `api` - API endpoints or integrations
- `ui` - User interface changes
- `db` - Database schema or migrations
- `docs` - Documentation
- `deps` - Dependency updates

## Pull Request Process

### Creating Pull Requests

**1. Before Creating PR:**
```bash
# Update with latest from develop
git checkout develop
git pull origin develop
git checkout feature/your-feature
git merge develop

# Run tests (if available)
npm test  # or appropriate test command

# Verify code quality
npm run lint
```

**2. PR Title Format:**
Same as commit message:
```
feat(civicrm): add contact merge functionality
```

**3. PR Description Template:**
```markdown
## Description
Brief description of changes

## Type of Change
- [ ] Bug fix (non-breaking change which fixes an issue)
- [ ] New feature (non-breaking change which adds functionality)
- [ ] Breaking change (fix or feature that would cause existing functionality to not work as expected)
- [ ] Documentation update

## Testing
- [ ] Tested locally in masdemo environment
- [ ] Verified CiviCRM API v4 compatibility
- [ ] Checked WordPress integration
- [ ] Tested n8n workflow integration (if applicable)

## Checklist
- [ ] Code follows project style guidelines
- [ ] Self-review completed
- [ ] Comments added for complex logic
- [ ] Documentation updated
- [ ] No new warnings generated

## Related Issues
Closes #123
Related to #456
```

### PR Review Guidelines

**As Reviewer:**
- Check code quality and style
- Verify tests pass
- Test functionality locally if possible
- Provide constructive feedback
- Approve when satisfied

**As Author:**
- Respond to all comments
- Make requested changes
- Re-request review when updated
- Don't merge until approved

### Merging Strategy

**For Feature Branches:**
- Squash and merge (clean history)
- Delete branch after merge

**For Hotfixes:**
- Merge commit (preserve context)
- Keep tag reference

## Repository Structure

### Standard Project Layout

```
project-name/
├── .git/
├── .gitignore
├── README.md
├── CHANGELOG.md
├── CONTRIBUTING.md
├── LICENSE
├── package.json (or composer.json)
├── src/
│   ├── civicrm/
│   ├── wordpress/
│   └── common/
├── tests/
├── docs/
└── .github/
    └── workflows/
```

### .gitignore Best Practices

**Always Ignore:**
```gitignore
# Dependencies
node_modules/
vendor/

# Environment files
.env
.env.local
*.local

# IDE files
.vscode/
.idea/
*.swp

# OS files
.DS_Store
Thumbs.db

# Build output
dist/
build/
*.log

# Sensitive data
config/local.php
secrets/
*.key
*.pem
```

**MAS-Specific Ignores:**
```gitignore
# CiviCRM
civicrm.settings.php
files/civicrm/upload/
files/civicrm/templates_c/

# WordPress
wp-config-local.php
uploads/

# n8n
.n8n/
credentials.json
```

## Tagging & Releases

### Semantic Versioning

Format: `MAJOR.MINOR.PATCH`

- **MAJOR:** Breaking changes (v1.0.0 → v2.0.0)
- **MINOR:** New features, backward compatible (v1.0.0 → v1.1.0)
- **PATCH:** Bug fixes, backward compatible (v1.0.0 → v1.0.1)

### Creating Tags

```bash
# Annotated tag (recommended)
git tag -a v1.2.0 -m "Release version 1.2.0"

# Push tag to remote
git push origin v1.2.0

# Push all tags
git push origin --tags

# List tags
git tag -l

# Delete local tag
git tag -d v1.2.0

# Delete remote tag
git push origin --delete v1.2.0
```

### Release Process

**1. Prepare Release Branch:**
```bash
git checkout develop
git pull origin develop
git checkout -b release/v1.2.0
```

**2. Update Version Numbers:**
- `package.json` or `composer.json`
- Plugin headers (WordPress)
- Extension info (CiviCRM)

**3. Update CHANGELOG.md:**
```markdown
## [1.2.0] - 2024-12-28

### Added
- Donor intelligence monitoring workflow
- Contact merge functionality

### Changed
- Upgraded to CiviCRM API v4
- Improved error handling in n8n workflows

### Fixed
- Email validation bug in contact forms
- Race condition in WordPress user sync

### Deprecated
- CiviCRM API v3 endpoints (use v4)
```

**4. Merge and Tag:**
```bash
# Merge to main
git checkout main
git merge release/v1.2.0
git tag -a v1.2.0 -m "Release v1.2.0"
git push origin main --tags

# Merge back to develop
git checkout develop
git merge release/v1.2.0
git push origin develop

# Delete release branch
git branch -d release/v1.2.0
```

## GitHub Project Setup

### Repository Settings

**Branch Protection Rules (main branch):**
- Require pull request reviews before merging
- Require status checks to pass
- Require branches to be up to date before merging
- Include administrators in restrictions
- Restrict deletions

**Branch Protection Rules (develop branch):**
- Require pull request reviews before merging
- Require status checks to pass

### GitHub Actions (Optional)

**Example: Automated Testing**
```yaml
# .github/workflows/test.yml
name: Run Tests

on:
  pull_request:
    branches: [ develop, main ]

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      - name: Setup Node
        uses: actions/setup-node@v3
        with:
          node-version: '18'
      - name: Install dependencies
        run: pnpm install
      - name: Run tests
        run: pnpm test
```

## Collaboration Best Practices

### For Solo Projects (Your MAS Work)

**Simplified Workflow:**
```bash
# Work directly on develop for small changes
git checkout develop
git add .
git commit -m "feat: add new feature"
git push origin develop

# Use feature branches for larger work
git checkout -b feature/major-change
# ... work ...
git checkout develop
git merge feature/major-change
git push origin develop
```

**When to Use Branches:**
- Experimental features
- Breaking changes
- Work that spans multiple days
- When you might need to revert

**When to Commit Directly:**
- Documentation updates
- Small bug fixes
- Configuration changes

### For Team Projects (MAS Collaborations)

**Always:**
- Use feature branches
- Create pull requests
- Get code review
- Follow all standards strictly

**Communication:**
- Link commits to issues
- Tag relevant team members in PRs
- Update project board
- Document decisions

## Common Git Commands

### Daily Workflow
```bash
# Start work
git checkout develop
git pull origin develop

# Create feature branch
git checkout -b feature/new-feature

# Stage changes
git add .
git add specific-file.js

# Commit
git commit -m "feat: add new feature"

# Push
git push origin feature/new-feature

# Update with latest
git checkout develop
git pull origin develop
git checkout feature/new-feature
git merge develop
```

### Fixing Mistakes

```bash
# Undo last commit (keep changes)
git reset --soft HEAD^

# Undo last commit (discard changes)
git reset --hard HEAD^

# Amend last commit message
git commit --amend -m "New message"

# Unstage file
git restore --staged filename

# Discard local changes
git restore filename

# Stash changes temporarily
git stash
git stash pop
```

### Viewing History

```bash
# View commit history
git log

# Compact view
git log --oneline

# View changes
git diff

# View changes for specific file
git diff filename

# View file at specific commit
git show commit-hash:path/to/file
```

## Integration with MAS Projects

### Project-Specific Conventions

**maswpcode (WordPress Plugin):**
- Prefix commits with `feat(wp):` or `fix(wp):`
- Tag releases for WordPress plugin repository
- Update plugin version in header

**mascode (CiviCRM Extension):**
- Prefix commits with `feat(civi):` or `fix(civi):`
- Follow CiviCRM extension versioning
- Update `info.xml` version

**mas-ai-chatbot (Next.js App):**
- Prefix commits with `feat(ai):` or `fix(ai):`
- Follow npm versioning in package.json
- Tag for Vercel deployments

### Repository Links
- maswpcode: `https://github.com/[username]/maswpcode`
- mascode: `https://github.com/[username]/mascode`
- mas-ai-chatbot: `https://github.com/[username]/mas-ai-chatbot`
- masdemo: Local development environment (may not be in Git)

## Tips & Tricks

### Useful Aliases

Add to `~/.gitconfig`:
```ini
[alias]
  st = status
  co = checkout
  br = branch
  ci = commit
  unstage = restore --staged
  last = log -1 HEAD
  visual = log --oneline --graph --all
  aliases = config --get-regexp alias
```

### Quick Reference

| Task | Command |
|------|---------|
| Create branch | `git checkout -b feature/name` |
| Switch branch | `git checkout branch-name` |
| Stage all | `git add .` |
| Commit | `git commit -m "message"` |
| Push | `git push origin branch-name` |
| Pull latest | `git pull origin develop` |
| Merge branch | `git merge branch-name` |
| Delete branch | `git branch -d branch-name` |
| View status | `git status` |
| View history | `git log --oneline` |
