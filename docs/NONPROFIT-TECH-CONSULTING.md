# MAS Nonprofit Tech Consulting

Guidance for nonprofit technology consulting work at MAS (Management Advisory Service): CiviCRM development best practices, WordPress integration patterns, n8n workflow automation for donor intelligence, and nonprofit-specific AI adoption strategies.

> **Provenance:** Demoted from the global `mas-nonprofit-tech-consulting` Claude Code skill (klaus repo) on 2026-06-03 — this is domain knowledge, not an invocable workflow, so it lives as a doc. Detailed references are in [`docs/consulting/`](consulting/).

## CiviCRM Development Standards

### API Usage
- **Always use CiviCRM API v4** (never v3, BAO, or DAO)
- API v4 provides better structure, type safety, and modern syntax
- Example: `\Civi\Api4\Contact::get(FALSE)->addWhere('id', '=', $contactId)->execute();`

### Event Handling
- **Use Symfony EventDispatcher** (preferred over traditional hooks)
- Modern event system with better dependency injection
- See: [consulting/civicrm-patterns.md](consulting/civicrm-patterns.md) for common patterns

### Extension Development
- Follow CiviCRM extension best practices
- Use `civix` for scaffolding
- Keep extensions focused and modular

## WordPress Integration

### Plugin Architecture
- Separate concerns: CiviCRM logic vs WordPress presentation
- Use WordPress REST API for frontend/backend communication
- Elementor Pro for page building when needed

### Common Integration Patterns
See [consulting/wordpress-integration.md](consulting/wordpress-integration.md) for:
- User ↔ Contact synchronization (bidirectional)
- Form submissions → CiviCRM activities/cases
- Custom post types ↔ CiviCRM entities
- REST API endpoints for Next.js apps
- Elementor Pro form integration
- Error handling and caching strategies

## N8N Workflow Automation

### Self-Hosted Instance
- Deployed at: https://n8n.masadvise.org/
- Used for CiviCRM/WordPress automation
- Donor intelligence monitoring
- Form submission processing

### Common Patterns
See [consulting/n8n-workflow-patterns.md](consulting/n8n-workflow-patterns.md) for:
- Contact sync (WordPress → CiviCRM)
- Case creation from forms
- Donor intelligence monitoring
- Email notification workflows
- Bulk data processing
- Chatbot identity resolution (VC Lookup → Inject Contact ID)
- Error handling and retry logic
- Testing procedures

## Nonprofit AI Adoption

### Budget-Conscious Recommendations
- Compare model pricing: ChatGPT, Claude, DeepSeek
- Consider usage patterns and token costs
- Self-hosted solutions where appropriate

### Common Nonprofit Use Cases
- Donor communication personalization
- Grant writing assistance
- Board report generation
- Event planning and coordination

## Project Organization

### Standard Project Structure
```
project-name/
├── 01-Planning/
│   ├── requirements.md
│   └── timeline.md
├── 02-Implementation/
│   ├── code/
│   └── documentation/
└── 03-Operations/
    ├── maintenance.md
    └── support.md
```

### Claude Context Files
Each project should have a `claude/` folder with:
- `project-context.md` - Project-specific background
- `technical-decisions.md` - Architecture choices made
- `next-steps.md` - Current priorities

### Git Workflow Standards
See [consulting/git-workflow.md](consulting/git-workflow.md) for:
- Branching strategy (main/develop/feature/hotfix)
- Semantic commit messages (feat:, fix:, docs:)
- Pull request process
- Tagging and releases
- Integration with maswpcode, mascode, mas-ai-chatbot projects

## Reference Files

Load these as needed for detailed guidance:
- [consulting/technical-stack.md](consulting/technical-stack.md) - Complete environment and version details
- [consulting/civicrm-patterns.md](consulting/civicrm-patterns.md) - API v4 code examples and event patterns
- [consulting/project-organization.md](consulting/project-organization.md) - Project structure and documentation standards
- [consulting/n8n-workflow-patterns.md](consulting/n8n-workflow-patterns.md) - Automation workflows and integration examples
- [consulting/git-workflow.md](consulting/git-workflow.md) - Version control standards and best practices
- [consulting/wordpress-integration.md](consulting/wordpress-integration.md) - WordPress ↔ CiviCRM integration patterns
