# Technical Stack Reference

This reference provides complete technical environment details for MAS nonprofit consulting projects.

## Core Technology Versions

### Operating Environment
- **Windows 11** with WSL 2 (Ubuntu 22.04 LTS)
- **Apache** 2.4.52
- **MySQL** 8.0.39 (utf8mb4 character set)
- **PHP** 8.3.11

### Required PHP Extensions
- curl, json, pdo_mysql, gd, mbstring, zip

### Development Tools
- **VS Code** with extensions: PHP Intelephense, Prettier, GitLens, REST Client
- **Git** with GitHub hosting
- **pnpm** (preferred package manager)

## CiviCRM Configuration

### Installed Extensions
- Action Provider (workflow automation)
- CiviCase (case management)
- Form Processor (dynamic forms)
- FormBuilder (low-code forms)
- CiviRules (event automation)
- SearchKit (advanced search)
- Mosaico (email templates)

### API Standards
✅ **Use CiviCRM API v4**
```php
\Civi\Api4\Contact::get(FALSE)
  ->addWhere('id', '=', $contactId)
  ->execute();
```

❌ **Do NOT use API v3, BAO, or DAO**

### Event System
✅ **Use Symfony EventDispatcher**
```php
\Civi::dispatcher()->addListener(
  'hook_civicrm_post',
  ['MyClass', 'myMethod']
);
```

❌ **Avoid traditional hooks when possible**

## WordPress Stack

### Core Components
- WordPress 6.x (latest stable)
- Elementor Pro (page builder)
- Custom plugins: maswpcode, mascde

### Integration Pattern
```
WordPress User ↔ CiviCRM Contact
  ↓
Form Submission → CiviCRM Activity/Case
  ↓
n8n Workflow → External API Calls
```

## N8N Automation

### Deployment
- Self-hosted via Docker containers
- Port: 5678
- Purpose: Workflow automation between CiviCRM, WordPress, and external APIs

### Common Use Cases
- Donor activity monitoring
- Automated email sequences
- Data synchronization
- API integrations with external services

## Next.js Projects

### Framework
- Next.js (latest stable)
- React + TypeScript
- Vercel AI SDK for chatbot integration

### Styling Options
- Tailwind CSS (preferred)
- shadcn/ui components
- Material-UI (project-dependent)

### Deployment
- Vercel platform for production
- Local dev server on port 3000

## File Structure Standards

### WordPress Installation
```
/var/www/wordpress/
├── wp-content/
│   └── plugins/
│       ├── maswpcode/      # General utilities
│       └── mascde/         # CiviCRM integration
```

### CiviCRM Extensions
```
/var/www/civicrm/
└── extensions/
    └── [custom-extension]/
```

### Project Organization
```
project-name/
├── 01-Planning/
├── 02-Implementation/
└── 03-Operations/
```

## Port Allocations

| Service | Port | Purpose |
|---------|------|---------|
| Apache | 80, 443 | HTTP/HTTPS |
| MySQL | 3306 | Database |
| n8n | 5678 | Workflow automation |
| Next.js | 3000 | Development server |

## Integration Architecture

### Data Flow
1. WordPress manages user authentication
2. CiviCRM handles contact data and relationships
3. Forms create CiviCRM activities/cases
4. n8n orchestrates automated workflows
5. Next.js apps consume CiviCRM API v4

### Authentication
- CiviCRM API: API keys or OAuth
- WordPress: Cookie-based sessions
- n8n: Webhook tokens or API credentials
