# Installation Guide

## Requirements

### System Requirements

- **CiviCRM**: 6.1 or higher
- **PHP**: 8.3 or higher (8.1+ minimum)
- **MySQL**: 8.0 or higher  
- **CMS**: WordPress, Drupal, or Joomla
- **Web Server**: Apache or Nginx with SSL support

### Required Extensions

- **CiviRules** (org.civicoop.civirules) - Required for automation and business logic
- **Afform** (built into CiviCRM 6.1+) - Required for Self Assessment Surveys and RCS form

### Recommended Extensions

- **Action Provider** - Enhanced FormProcessor integration
- **FormProcessor** - Advanced form actions and workflows
- **SearchKit** - Enhanced reporting (CiviCRM 6.1+ core)
- **Form Builder** - Advanced form creation capabilities

## Installation Methods

### Method 1: Git Clone (Development/Production)

```bash
# Clone to CiviCRM extensions directory
cd /path/to/civicrm/extensions/
git clone https://github.com/briangflett/mascode.git

# Enable extension
cv ext:enable mascode

# Verify installation
cv ext:list | grep mascode
```

### Method 2: Extension Manager (When Available)

1. In CiviCRM, go to **Administer → System Settings → Extensions**
2. Click **Add New** tab
3. Search for "mascode"
4. Click **Download** then **Install**

### Method 3: Manual Download

```bash
# Download latest release
cd /path/to/civicrm/extensions/
wget https://github.com/briangflett/mascode/releases/latest/download/mascode.tar.gz
tar -xzf mascode.tar.gz

# Enable via CiviCRM
cv ext:enable mascode
```

## Post-Installation Configuration

### Verify Dependencies

```bash
# Check CiviRules is installed and enabled
cv ext:list | grep civirules

# Verify API4 is available
cv api4 --help

# Test cache clearing
cv flush
```

### Initial Component Deployment

After installation, deploy the core components:

```bash
# Deploy custom fields
cv scr scripts/deploy_custom_fields.php --user=<wp-admin-login>

# Deploy CiviRules configuration
cv scr scripts/deploy_civirules.php --user=<wp-admin-login>

# Clear cache
cv flush
```

Afforms are version-controlled in `ang/` and deploy automatically with the code.

### Form Processor Setup (If Using)

1. Install FormProcessor extension if not already installed
2. Follow manual import process in `scripts/deploy_form_processors.md`
3. Configure any Form Processor webhooks or integrations

### Environment-Specific Configuration

#### Development Environment
- Default configuration should work out of the box
- Update deployment script configurations if using different case type IDs
- Test all forms and ensure proper submission handling

#### Production Environment
- **IMPORTANT**: Update deployment script configurations with production-specific IDs:
  - Case type IDs
  - Message template IDs
  - Location type IDs
  - Custom field group IDs
- Update form redirect URLs for production domain
- Configure SSL certificates for secure form submission
- Test anonymous form access from external networks

## Verification Steps

### 1. Extension Status
```bash
# Verify extension is enabled
cv ext:list | grep mascode

# Check for any extension errors
cv ext:refresh
```

### 2. CiviRules Integration
```bash
# List CiviRules actions (should include MAS actions)
cv api4 CiviRulesAction.get | grep mas

# List CiviRules triggers (should include MAS triggers)
cv api4 CiviRulesTrigger.get | grep mas
```

### 3. Form Accessibility
- **SASS Form**: Navigate to Short Self Assessment Survey URL
- **SASF Form**: Navigate to Full Self Assessment Survey URL  
- **RCS Form**: Navigate to Request for Consulting Services URL
- Verify forms load properly and submit successfully

### 4. Case Management
- Create a test Service Request case
- Verify MAS code generation (format: R25XXX)
- Change status to "Project Created"
- Verify Project case creation (format: P25XXX)

## Troubleshooting

### Common Installation Issues

**Extension won't enable**
- Check PHP version compatibility (8.3+ recommended)
- Verify CiviCRM version (6.1+ required)
- Check file permissions on extension directory
- Review CiviCRM log files for specific errors

**CiviRules not working**
- Ensure CiviRules extension is installed and enabled
- Check CiviRules log for rule execution
- Verify trigger conditions are met
- Clear cache with `cv flush`

**Forms not accessible**
- Check public access permissions
- Verify URL routing configuration
- Test from external network (for anonymous access)
- Review web server error logs

**Deployment script errors**
- Verify user has appropriate CiviCRM permissions
- Check that referenced entities exist (case types, etc.)
- Update script configurations for your environment
- Run scripts individually to isolate issues

### Getting Help

- **Documentation**: Review [Development Guide](DEVELOPMENT.md) and [Deployment Guide](DEPLOYMENT.md)
- **Issues**: Create issue on [GitHub repository](https://github.com/briangflett/mascode/issues)
- **Logs**: Check CiviCRM log files in ConfigAndLog directory
- **Community**: Ask questions on [CiviCRM Chat](https://chat.civicrm.org/)

## Next Steps

After successful installation:

1. **Configure Forms**: Customize form fields and workflows as needed
2. **Test Workflows**: Verify case management and automation workflows
3. **Production Deployment**: Follow [DEPLOYMENT.md](DEPLOYMENT.md) for production deployment
4. **Monitor Usage**: Review CiviCRM logs and form submission patterns
