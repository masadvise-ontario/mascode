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
   # Enable extension in development — registers CiviRules components from
   # Civi/Mascode/CiviRules/*.json via PostInstallOrUpgradeHook
   cv ext:enable mascode

   # Reconcile managed entities and rescan ang/
   cv flush
   ```
   On a brand-new environment you also need to bootstrap the CiviRules rule assemblies —
   see [INSTALLATION.md](INSTALLATION.md#fresh-installs-bootstrap-the-civirules-rule-assemblies).
   Those scripts need `--user=<wp-admin-login>`: a real WordPress `user_login` that has a
   `civicrm_uf_match` row. There is no `admin` user, and omitting the flag runs anonymously.

   ⚠ `scripts/deploy_custom_fields.php` and `scripts/deploy_civirules.php` are **frozen**
   legacy scripts. They predate the managed-entity standard — don't run them as part of
   setup, and don't extend them. See
   [CONFIGURATION-AS-CODE.md](CONFIGURATION-AS-CODE.md).

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
# Create feature branch from master (single-branch workflow — master is the
# only long-lived branch)
git checkout master
git pull upstream master
git checkout -b feature/description

# Make changes following code standards
# Test thoroughly in development environment
# Update documentation as needed

# Run quality checks
cv upgrade:db   # Apply any upgrade_NNNN step you added
cv flush            # Reconcile managed entities, rescan ang/, rebuild container
# Verify forms, dashboards and CiviRules functionality

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

**For Configuration Changes (managed entities, Afforms, upgrade steps):**
- [ ] The config ships as code — a `.mgd.php`, an `ang/` file, or an `upgrade_NNNN` step —
      never UI-only
- [ ] `cv upgrade:db` then `cv flush` produces the config on a clean environment
- [ ] `upgrade_NNNN` steps are idempotent and safe to re-run
- [ ] Managed-entity `update` and `cleanup` policies were chosen deliberately
      (see [CONFIGURATION-AS-CODE.md](CONFIGURATION-AS-CODE.md))
- [ ] New CiviRules JSON entries are paired with an `upgrade_NNNN` step — JSON registration
      does not fire on `cv flush`

### 4. Pull Request Process

```bash
# Push feature branch
git push origin feature/description

# Create PR to master
# Use PR template and include:
# - Description of changes
# - Testing performed
# - Documentation updates
# - Breaking changes (if any)
```

**PR Requirements:**
- [ ] Targets `master` (the only long-lived branch)
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
6. Add an `upgrade_NNNN` step in `CRM/Mascode/Upgrader.php` that registers the component
   idempotently — the `.json` files are read by `PostInstallOrUpgradeHook`, which fires on
   extension install and after **core** upgrades only, *not* on `cv flush`
7. Test action registration and execution: `cv upgrade:db && cv flush`, then
   `cv api4 CiviRulesAction.get +w 'name=<your_action>' --user=<wp-admin-login>`

#### New Trigger
1. Create trigger class in `Civi/Mascode/CiviRules/Trigger/`
2. Register in `Civi/Mascode/CiviRules/triggers.json`
3. Add an `upgrade_NNNN` step that registers the component idempotently (same
   flush-doesn't-fire reason as above)
4. Test trigger activation and event firing

#### New Rule Assembly (trigger + conditions + actions)
1. Add an `ensure*()` provisioner method — see
   `Civi/Mascode/Service/LifecycleRuleProvisioner.php` for the pattern; it must
   short-circuit when the rule name already exists
2. Call it from an `upgrade_NNNN` step so existing installs pick it up
3. Add a thin idempotent `scripts/create-*.php` wrapper for fresh installs, which never run
   upgrade steps
4. Watch condition weights — a mis-weighted condition silently disables the whole rule

### Adding New Forms

#### Afform (Recommended)
1. Create the form in the CiviCRM FormBuilder UI — it writes to
   `wp-content/uploads/civicrm/ang/`
2. Copy the `.aff.html` + `.aff.json` pair into the extension's `ang/` directory and commit
   them. The file is the source of truth; Afforms are **not** managed entities
3. Name it with the `afformMAS` prefix and tag it (Client, VC, Dashlet, Admin, or Block —
   see `ang/README.md`)
4. Reference fields by **name**, never by ID, so the form works in every environment
5. `cv flush`, then test rendering, submission, and data processing
6. Check the site-local `uploads/civicrm/ang/` copy isn't left behind shadowing the
   committed version
7. Update user documentation

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
1. **Development**: Work on a short-lived feature branch off `master`
2. **Testing**: Thorough testing in development environment
3. **PR Review**: Code review and approval process
4. **Merge to Master**: Push or merge PR to `master`
5. **Tag Release**: Update `version` and `releaseDate` in `info.xml`, commit, tag
6. **Deploy**: On prod, `git pull origin master` → `cv upgrade:db` → `cv flush` → any
   one-off script named in the release notes. All idempotent; run every step every deploy.
   See [DEPLOYMENT.md](DEPLOYMENT.md).

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
