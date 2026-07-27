# Contributing to Mascode

## Getting Started

### Development Setup

1. **Fork and Clone**
   ```bash
   git clone https://github.com/yourusername/mascode.git
   cd mascode
   git remote add upstream https://github.com/briangflett/mascode.git
   ```

2. **Development Environment**
   - See [Development Guide](DEVELOPMENT.md) for complete CiviCRM setup
   - Ensure CiviRules extension is installed
   - Test environment should match production versions

3. **Initial Setup**
   ```bash
   # Enable extension in development
   cv ext:enable mascode
   
   # Deploy core components
   cv scr scripts/deploy_custom_fields.php --user=<wp-admin-login>
   cv scr scripts/deploy_civirules.php --user=<wp-admin-login>
   
   # Clear cache
   cv flush
   ```

### Code Standards

- **PHP Version**: 8.3+ (8.1+ minimum)
- **Coding Style**: [PER Coding Style](https://www.php-fig.org/per/coding-style/) (successor to PSR-12)
- **CiviCRM Patterns**: Use API4, EventDispatcher, modern patterns
- **Testing**: Comprehensive test coverage for all new functionality
- **Documentation**: Update relevant .md files for all changes

### Architecture Guidelines

- **PSR-4 Classes**: Use `Civi/Mascode/` namespace for new classes
- **Event-Driven**: Use EventDispatcher for loose coupling
- **API-First**: CiviCRM API4 for all data operations
- **Service Injection**: Register services via Symfony DI container
- **CiviRules Integration**: Extend `CRM_CivirulesActions_Generic_Api` for actions

## Contribution Workflow

### 1. Create Issue

- Use appropriate [issue template](https://github.com/briangflett/mascode/issues/new/choose)
- **Bug Reports**: Include steps to reproduce, expected vs actual behavior
- **Feature Requests**: Describe use case, proposed solution, alternatives considered
- Discuss approach for large changes in [Discussions](https://github.com/briangflett/mascode/discussions)

### 2. Development Process

```bash
# Create feature branch from dev
git checkout dev
git pull upstream dev
git checkout -b feature/description

# Make changes following code standards
# Test thoroughly in development environment
# Update documentation as needed

# Run quality checks
cv flush  # Clear cache
# Test deployment scripts if modified
# Verify forms and CiviRules functionality

# Commit with conventional commits
git commit -m "feat: add automatic URL prefixing"
```

### 3. Testing Requirements

**For All Changes:**
- [ ] Extension enables without errors
- [ ] Cache clears without issues (`cv flush`)
- [ ] No PHP errors or warnings in logs
- [ ] Existing functionality remains intact

**For CiviRules Changes:**
- [ ] Actions register properly in CiviRules admin
- [ ] Form classes and templates render correctly
- [ ] Business logic executes as expected
- [ ] Error handling works appropriately

**For Form Changes:**
- [ ] Forms render properly and submit successfully
- [ ] Anonymous access works (if applicable)
- [ ] Data saves to correct entities with proper relationships
- [ ] Email confirmations sent (if configured)

**For Deployment Script Changes:**
- [ ] Scripts run without errors in clean environment
- [ ] Configuration sections are clear and complete
- [ ] Error handling provides helpful messages
- [ ] Components deploy correctly and function as expected

### 4. Pull Request Process

```bash
# Push feature branch
git push origin feature/description

# Create PR to dev branch (not master)
# Use PR template and include:
# - Description of changes
# - Testing performed
# - Documentation updates
# - Breaking changes (if any)
```

**PR Requirements:**
- [ ] Targets `dev` branch (not `master`)
- [ ] Clear description of changes and rationale
- [ ] All tests pass and functionality verified
- [ ] Documentation updated for user-facing changes
- [ ] No merge conflicts with target branch

## Development Guidelines

### Adding New CiviRules Components

#### New Action
1. Create action class in `Civi/Mascode/CiviRules/Action/`
2. Extend `CRM_CivirulesActions_Generic_Api`
3. Register in `Civi/Mascode/CiviRules/actions.json`
4. Create form in `CRM/Mascode/CiviRules/Form/` (legacy namespace required)
5. Create template in `templates/CRM/Mascode/CiviRules/Form/`
6. Update deployment script to include new action
7. Test action registration and execution

#### New Trigger
1. Create trigger class in `Civi/Mascode/CiviRules/Trigger/`
2. Register in `Civi/Mascode/CiviRules/triggers.json`
3. Update deployment script to include new trigger
4. Test trigger activation and event firing

### Adding New Forms

#### Afform (Recommended)
1. Create form using CiviCRM FormBuilder UI
2. Export form structure and save to deployment script
3. Update deployment script with new form
4. Test form rendering, submission, and data processing
5. Update user documentation

#### Legacy Forms (Avoid)
- Only use if Afform capabilities are insufficient
- Follow existing patterns in `CRM/Mascode/Form/`
- Create corresponding templates

### Adding New Event Subscribers

1. Create in `Civi/Mascode/Event/`
2. Implement `EventSubscriberInterface`
3. Register in container with `event_subscriber` tag
4. Test event subscription and handling

### Documentation Standards

**Code Documentation:**
- PHPDoc blocks for all public methods
- Clear variable names and comments for complex logic
- README files for new components

**User Documentation:**
- Update relevant .md files for user-facing changes
- Include screenshots for UI changes
- Provide step-by-step instructions
- Update troubleshooting sections

## Release Process

### Version Numbering
- **Major** (X.0.0): Breaking changes, major feature additions
- **Minor** (X.Y.0): New features, significant enhancements
- **Patch** (X.Y.Z): Bug fixes, minor improvements

### Release Workflow
1. **Development**: All changes go to `dev` branch
2. **Testing**: Thorough testing in development environment
3. **PR Review**: Code review and approval process
4. **Merge to Master**: Push or merge PR to master
5. **Tag Release**: Update version in `info.xml`, commit, tag
6. **Deploy**: Pull in production, run deployment scripts if needed, `cv flush`

## Community Guidelines

### Communication
- **Be Respectful**: Professional and constructive communication
- **Be Patient**: Contributors have varying experience levels
- **Be Helpful**: Share knowledge and assist other contributors
- **Be Collaborative**: Work together to improve the extension

### Issue Management
- **Search First**: Check existing issues before creating new ones
- **Clear Titles**: Descriptive and specific issue titles
- **Detailed Descriptions**: Provide context, steps, and examples
- **Follow Up**: Respond to questions and provide updates

### Code Review
- **Constructive Feedback**: Focus on code quality and functionality
- **Testing**: Verify changes work as described
- **Documentation**: Ensure adequate documentation for changes
- **Approval**: Require approval from maintainers before merge

## Getting Help

### Resources
- **Documentation**: [Development Guide](DEVELOPMENT.md), [Architecture](ARCHITECTURE.md)
- **Examples**: Review existing code for patterns and approaches
- **CiviCRM Docs**: [Developer Documentation](https://docs.civicrm.org/dev/)
- **Community**: [CiviCRM Chat](https://chat.civicrm.org/)

### Asking Questions
1. **Check Documentation**: Review existing documentation first
2. **Search Issues**: Look for similar questions or problems
3. **Create Discussion**: Use GitHub Discussions for questions
4. **Provide Context**: Include relevant details and code examples

## Recognition

Contributors are recognized in:
- **CHANGELOG.md**: Credited for significant contributions
- **GitHub**: Contributor listings and commit history
- **Documentation**: Author credits where appropriate

Thank you for contributing to the Mascode extension and helping improve nonprofit technology tools!
