# CiviCRM API4 Protocol

**CRITICAL: ALWAYS use CiviCRM API4 for database operations. NEVER use direct SQL queries.**

## API4 Call Pattern

```php
// Use FALSE as first parameter to suppress default permissions
\Civi\Api4\EntityName::action(FALSE)
    ->addWhere('field', '=', 'value')
    ->addValue('field', 'value')
    ->execute();
```

## Key API4 Guidelines

- **Namespace**: Use `\Civi\Api4\` namespace for all API4 calls
- **Permissions**: Always add `FALSE` as first parameter to suppress default permissions
- **Actions**: Chain methods - `.get()`, `.create()`, `.update()`, `.delete()`
- **Filtering**: Use `.addWhere()` to filter results
- **Setting values**: Use `.addValue()` to set field values
- **Execution**: End with `.execute()` to run the query
- **Cross-environment compatibility**: **Use field names, not numeric IDs**

## Cross-Environment Compatibility (CRITICAL)

**ALWAYS use names, not IDs** for custom fields, relationships, option values, etc:

```php
// GOOD - Using names (works across dev/prod)
\Civi\Api4\CustomField::get(FALSE)
    ->addWhere('custom_group_id:name', '=', 'Group_Name')
    ->addWhere('name', '=', 'field_name')
    ->execute();

// BAD - Using IDs (breaks across environments)
\Civi\Api4\CustomField::get(FALSE)
    ->addWhere('custom_group_id', '=', 123)  // IDs differ between dev/prod
    ->addWhere('id', '=', 456)
    ->execute();
```

## CV Command Patterns

### Script Execution

```bash
# Standard execution
/home/brian/buildkit/bin/cv scr /path/to/script.php --user=admin

# With debugging (XDEBUG)
XDEBUG_SESSION=1 /home/brian/buildkit/bin/cv scr /path/to/script.php --user=admin

# Short form (if cv is in PATH)
cv scr /path/to/script.php --user=admin
```

### Cache Management

```bash
# ALWAYS run after code changes
/home/brian/buildkit/bin/cv flush

# Or short form
cv flush
```

### Extension Management

```bash
# List extensions
cv ext:list | grep <extension_name>

# Enable extension
cv ext:enable <extension_name>

# Disable extension
cv ext:disable <extension_name>
```

## API and Code Verification (CRITICAL)

**Before using any CiviCRM entities, actions, or methods, ALWAYS verify they exist** by reading the actual source code:

### Verification Locations

- **CiviCRM Core APIs**: `/home/brian/buildkit/build/masdemo/web/wp-content/plugins/civicrm/civicrm/` subdirectories
- **Extension APIs**: `/home/brian/buildkit/build/masdemo/web/wp-content/uploads/civicrm/ext/` subdirectories
- **Entity Actions**: Verify API4 entities and their available actions in core or extension code
- **Method Signatures**: Check actual class methods and parameters before using them

### Verification Process

1. **Read the source code** - Don't assume APIs exist
2. **Check method signatures** - Verify parameters and return types
3. **Verify entity actions** - Ensure the action is available for that entity
4. **Test in dev first** - Always test API calls in development before deploying

**Never assume** entity actions, object methods, or API endpoints exist without verifying in the actual codebase.

## Common API4 Patterns

### Get Records

```php
$results = \Civi\Api4\Contact::get(FALSE)
    ->addWhere('contact_type', '=', 'Individual')
    ->addWhere('is_deleted', '=', 0)
    ->setLimit(25)
    ->execute();

foreach ($results as $contact) {
    // Process each contact
}
```

### Create Record

```php
$result = \Civi\Api4\Contact::create(FALSE)
    ->addValue('contact_type', 'Individual')
    ->addValue('first_name', 'Jane')
    ->addValue('last_name', 'Doe')
    ->execute();

$contactId = $result->first()['id'];
```

### Update Record

```php
\Civi\Api4\Contact::update(FALSE)
    ->addWhere('id', '=', $contactId)
    ->addValue('first_name', 'Janet')
    ->execute();
```

### Delete Record

```php
\Civi\Api4\Contact::delete(FALSE)
    ->addWhere('id', '=', $contactId)
    ->execute();
```

### Working with Custom Fields

```php
// Get custom field info
$customField = \Civi\Api4\CustomField::get(FALSE)
    ->addWhere('custom_group_id:name', '=', 'Donor_Information')
    ->addWhere('name', '=', 'total_giving')
    ->execute()->first();

// Access custom field in contact
$contact = \Civi\Api4\Contact::get(FALSE)
    ->addWhere('id', '=', $contactId)
    ->addSelect('Donor_Information.total_giving')  // Use group.field syntax
    ->execute()->first();
```

## Development Workflow

1. **Write API4 code** in your extension or script
2. **Test locally** using `cv scr` command
3. **Flush cache** after any changes: `cv flush`
4. **Verify in UI** that changes work as expected
5. **Commit to git** once tested and working
6. **Deploy to production** (code first, then data)

## Debugging Tips

### Enable XDEBUG

```bash
XDEBUG_SESSION=1 cv scr /path/to/script.php --user=admin
```

### Use CiviCRM Debug Log

```php
\Civi::log()->debug('Variable value: ' . print_r($variable, TRUE));
```

### Check API Explorer

Access CiviCRM's built-in API Explorer:
- URL: `https://masdemo.localhost/civicrm/api4#/explorer`
- Test API calls interactively
- See available entities and actions
- Copy generated PHP code

## Common Pitfalls

❌ **Using direct SQL** - Always use API4
❌ **Using numeric IDs** - Use names for cross-environment compatibility
❌ **Forgetting FALSE parameter** - Always suppress default permissions
❌ **Not flushing cache** - Cache issues cause stale data
❌ **Assuming APIs exist** - Always verify in source code first

## Additional Resources

- **CiviCRM API4 Documentation**: https://docs.civicrm.org/dev/en/latest/api/v4/
- **API Explorer**: https://masdemo.localhost/civicrm/api4#/explorer
- **Custom Field API**: https://docs.civicrm.org/dev/en/latest/framework/api-architecture/

---

**Last Updated:** 2025-12-29
