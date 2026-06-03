# Project Organization Standards

This reference documents the standard project structure and organization patterns for MAS consulting work.

## Standard Project Structure

Every project follows this three-phase structure:

```
project-name/
├── README.md
├── 01-Planning/
│   ├── requirements.md
│   ├── timeline.md
│   ├── budget.md
│   └── architecture.md
├── 02-Implementation/
│   ├── design/
│   ├── development/
│   ├── testing/
│   └── documentation/
├── 03-Operations/
│   ├── deployment/
│   ├── monitoring/
│   └── maintenance/
└── claude/
    ├── project-context.md
    ├── technical-decisions.md
    └── next-steps.md
```

## Folder Purposes

### 01-Planning/
**Use:** Before starting development

**Contains:**
- `requirements.md` - Feature list, acceptance criteria, user stories
- `timeline.md` - Schedule, milestones, dependencies
- `budget.md` - Cost estimates, resource allocation
- `architecture.md` - System design, tech stack decisions
- `risks.md` - Risk assessment and mitigation strategies

### 02-Implementation/
**Use:** During active development

**Subfolders:**
- `design/` - UI mockups, wireframes, design specifications
- `development/` - Source code, feature branches, code reviews
- `testing/` - Test plans, test cases, QA documentation
- `documentation/` - User guides, API docs, inline comments

### 03-Operations/
**Use:** After launch/deployment

**Subfolders:**
- `deployment/` - Deployment scripts, release procedures, rollback plans
- `monitoring/` - Logs, performance metrics, health checks
- `maintenance/` - Update procedures, security patches, backups
- `support/` - User documentation, FAQs, troubleshooting guides

### claude/
**Use:** Throughout project lifecycle

**Contains:**
- `project-context.md` - Background, objectives, constraints
- `technical-decisions.md` - Architecture choices made and why
- `next-steps.md` - Current priorities and blockers
- `known-issues.md` - Problems and workarounds

## Project Types

### Technology Platform Projects
**Example:** WordPress + CiviCRM integration

**Focus areas:**
- Architecture documentation
- Database schemas
- API integration specs
- Infrastructure setup
- Deployment procedures

**Key files:**
- `architecture.md` with system diagrams
- `api-documentation.md` with endpoints
- Database migration scripts
- Infrastructure-as-code configs

### Content/Campaign Projects
**Example:** Allard Prize donor outreach

**Focus areas:**
- Strategy and planning
- Content templates
- Timeline tracking
- Success metrics

**Key files:**
- Campaign brief and objectives
- Content calendar
- Template library
- Analytics/reporting dashboards

### Workflow/Automation Projects
**Example:** n8n donor intelligence workflows

**Focus areas:**
- Workflow diagrams
- Integration points
- Error handling
- Testing procedures

**Key files:**
- Workflow JSON exports
- Integration documentation
- Testing checklists
- Monitoring dashboards

### Product Development Projects
**Example:** Custom Next.js chatbot

**Focus areas:**
- Full SDLC documentation
- User stories
- Technical architecture
- Testing and QA
- Release management

**Key files:**
- Product requirements doc
- Technical specifications
- Test plans and coverage reports
- Release notes

## Naming Conventions

### Projects
Use kebab-case with descriptive names:
- ✅ `allard-prize-outreach`
- ✅ `mas-wordpress-integration`
- ✅ `donor-intelligence-automation`
- ❌ `project1`
- ❌ `AllardPrizeOutreach`

### Files
- Lowercase with hyphens: `project-requirements.md`
- Include dates for versions: `budget-2024-12-28.md`
- Be descriptive: `civicrm-api-integration-guide.md`

### Folders
- Numbered prefixes for sequence: `01-Planning/`
- Descriptive names: `development/`, `testing/`
- No spaces or special characters

## Shared Resources

Some projects share common infrastructure:

### Shared Repositories
- `maswpcode` - WordPress utility plugins used across MAS projects
- `mascde` - CiviCRM extensions and customizations
- `masdemeo` - Combined development environment

### Common Infrastructure
- MySQL database server (WSL2 Ubuntu)
- Apache web server (WSL2)
- n8n automation instance
- GitHub organization

### External Dependencies
- CiviCRM (nonprofit CRM)
- WordPress (website platform)
- Vercel (Next.js deployment)
- n8n (workflow automation)

## Version Control

### Git Strategy
- Feature branches for development
- Pull requests for code review
- Semantic versioning: `v1.0.0`
- Maintain `CHANGELOG.md` in project root

### Repository Structure
```
.
├── .gitignore
├── README.md
├── CHANGELOG.md
├── CONTRIBUTING.md
├── src/
├── docs/
└── tests/
```

## Documentation Standards

### Markdown Files
- UTF-8 encoding
- H1 for title, H2 for sections
- Table of contents for docs >1000 words
- Link to related docs and external resources

### Code Documentation
- Inline comments for complex logic
- PHPDoc/JSDoc for functions
- README in each major directory
- Architecture decision records (ADRs)

## Maintenance & Updates

### Regular Reviews
- **Monthly:** Check for outdated documentation
- **Quarterly:** Update technical decisions and architecture docs
- **Per Release:** Update CHANGELOG.md and version numbers

### Archive Strategy
- Move completed projects to `_Archive/`
- Keep active projects in main directory
- Maximum 10 active projects at once

## Claude Integration

Each project's `claude/` folder should contain:

1. **project-context.md** - What this project is about
   ```markdown
   # Project Context
   
   ## Purpose
   Building donor intelligence automation for MAS
   
   ## Objectives
   - Monitor donor online activity
   - Trigger outreach at optimal times
   - Integrate with CiviCRM
   
   ## Constraints
   - Budget: $0 (using free tier services)
   - Timeline: 3 months
   - Technical: Must work with existing n8n instance
   ```

2. **technical-decisions.md** - Key choices made
   ```markdown
   # Technical Decisions
   
   ## Use n8n for automation
   **Decision:** Self-hosted n8n instead of Zapier
   **Reason:** Cost ($0 vs $20/month), flexibility, privacy
   **Trade-off:** More setup complexity
   
   ## Use CiviCRM API v4
   **Decision:** API v4 exclusively
   **Reason:** Modern, better docs, type-safe
   **Trade-off:** None (deprecated v3 anyway)
   ```

3. **next-steps.md** - Current priorities
   ```markdown
   # Next Steps
   
   ## Immediate (This Week)
   - [ ] Test Gmail trigger in n8n
   - [ ] Configure OAuth for LinkedIn API
   
   ## Short-term (This Month)
   - [ ] Build donor scoring algorithm
   - [ ] Create CiviCRM activity integration
   
   ## Blocked
   - Waiting on LinkedIn API approval (applied Dec 15)
   ```
