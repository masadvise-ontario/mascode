# Afform Relationship Management

## Overview
The MASCode extension automatically creates relationships when Afforms are submitted. This ensures proper contact-to-organization relationships are established without manual intervention.

## RCS Form (afformMASRCSForm)

### Automatic Relationship Creation
When the Request for Consulting Services form is submitted, the following relationships are automatically created:

#### Individual 1 (President/Board Chair)
- **Employee of** → Organization 1
- **President of** → Organization 1

#### Individual 2 (Executive Director)
- **Employee of** → Organization 1
- **Executive Director of** → Organization 1

#### Individual 3 (Primary Contact)
- **Employee of** → Organization 1
- **Case Client Rep is** → Organization 1 (case-specific relationship)

### Implementation Details

**File:** `Civi/Mascode/Event/AfformSubmitSubscriber.php`

**Methods:**
- `onFormSubmitPreProcess()` - Pre-processing (priority +1, before Afform saves)
- `onFormSubmit()` - Post-processing (priority -100, after Afform saves)
- `createRCSRelationshipsPostCommit()` - Creates relationships
- `endPresidentRelationship()` - Ends old president relationship on replacement
- `endExecutiveDirectorRelationship()` - Ends old executive director relationship on replacement

**Process:**

**Pre-Processing (Priority +1 - Before Afform Saves):**
1. Form submission triggers `civi.afform.submit` event
2. For Individual1 (President) and Individual2 (Executive Director):
   - Checks if contact has an ID (autofilled from existing relationship)
   - Compares submitted last_name with current contact's last_name
   - If different → **Role replacement detected**:
     - Stores old contact ID for later
     - Removes contact ID from submission → Forces Afform to create NEW contact
3. Afform then processes at priority 0 (creates/updates contacts)

**Post-Processing (Priority -100 - After Afform Saves):**
1. AfformSubmitSubscriber collects new entity IDs as each entity is processed
2. When Case1 (the final entity) is processed, relationship management is triggered
3. For President and Executive Director:
   - If old contact ID was stored (role replacement):
     - Ends old contact's role relationship (sets `is_active=false`, `end_date=today`)
   - Creates new role relationship for new contact
4. Relationships are checked for existence before creation to avoid duplicates
5. Each relationship type is looked up by name (environment-agnostic)
6. Case-specific relationships include the case_id for proper context

### Role Replacement Logic

**President (Individual 1) and Executive Director (Individual 2):**

When the RCS form is submitted for an organization with existing President or Executive Director:
- The form autofills with the current role holder's contact information
- **If user changes the last name** → System treats this as a role replacement:
  1. Creates NEW contact with the new last name
  2. Ends old contact's role relationship (inactive, with end_date)
  3. Creates new role relationship for new contact
  4. Old contact retains all their historical data and relationships
- **If user changes first name or email** → Updates same contact, no relationship changes

**Rationale:**
- Last name changes more likely indicate a different person in the role
- First name/email changes more likely indicate corrections to same person's data
- Preserves historical data for both contacts (old and new role holders)

### Relationship Type Mapping

The following relationship types must exist in CiviCRM:

| Relationship Type | Direction | Used For | Exclusive? |
|------------------|-----------|----------|------------|
| Employee of | Individual → Organization | All individuals | No |
| President of | Individual → Organization | Individual 1 | Yes (one active per org) |
| Executive Director of | Individual → Organization | Individual 2 | Yes (one active per org) |
| Case Client Rep is | Individual → Organization | Individual 3 (case-specific) | Per case |

### CiviRules Integration

**CiviRules Actions:**
- `EmployerRelationship` - Creates relationships based on employer_id and job title
- Used for manual contact creation outside of Afforms
- Skips execution gracefully when no employer_id is set (preventing transaction rollbacks)

**How It Works:**
1. **Afform Submission:** AfformSubmitSubscriber creates relationships directly
2. **Manual Entry:** CiviRules creates relationships when job title matches and employer_id is set
3. **Duplicate Prevention:** Both systems check for existing relationships before creating

### Error Handling

**Transaction Safety:**
- Relationship creation wrapped in try-catch blocks
- Individual relationship failures don't prevent contact creation
- All failures are logged with detailed context

**Logging:**
- All relationship creation attempts logged at INFO level
- Failures logged at ERROR level with full exception details
- Session ID tracked for debugging multi-entity submissions

### Troubleshooting

**Contact created but no relationships:**
1. Check CiviCRM logs: `/web/wp-content/uploads/civicrm/ConfigAndLog/CiviCRM.*.log`
2. Search for "AfformSubmitSubscriber" entries
3. Verify relationship types exist and use correct names
4. Confirm organization ID is valid

**Relationship already exists errors:**
- This is normal behavior - system checks and skips duplicates
- Logged at INFO level, not an error

**Role replacement not working:**
1. Check logs for "replacement detected in pre-process" messages
2. Verify last name was actually changed (not just first name/email)
3. Confirm old relationship was ended (check `is_active=false`, `end_date` set)
4. Verify new contact was created (different contact_id)

**Contact updated instead of new contact created:**
- This happens when only first name or email changed (expected behavior)
- Last name must change to trigger role replacement

**CiviRules causing rollbacks:**
- Ensure `EmployerRelationship` action properly overrides `processAction()`
- Verify it returns early when no employer_id is found
- Check that it doesn't throw exceptions when skipping

## Survey Forms (SASS/SASF)

Survey forms use a simpler structure:
- Individual 1 serves as primary contact
- No automatic relationship creation currently implemented
- Relationships managed manually or via CiviRules

## Extension to Other Forms

To add relationship creation to other Afforms:

1. Add form route to `$emailForms` array in `AfformSubmitSubscriber::onFormSubmit()`
2. Add entity tracking in the appropriate case block
3. Create relationships in a dedicated method (similar to `createRCSRelationshipsPostCommit()`)
4. Use `createRelationshipIfNotExists()` or `createCaseRelationshipIfNotExists()` helpers

**Example:**
```php
case 'NewForm1':
    self::$submissionData[$sessionId]['entity_id'] = $entityId;
    $this->createNewFormRelationships(self::$submissionData[$sessionId], $sessionId);
    break;
```

## Testing

### Manual Testing - Initial Form Submission
1. Submit afformMASRCSForm with new contacts for Individual 1, 2, and 3
2. Verify all contacts created successfully
3. Check each contact's Relationships tab:
   - Individual 1: Employee of + President of
   - Individual 2: Employee of + Executive Director of
   - Individual 3: Employee of + Case Client Rep is
4. Verify Case Client Rep relationship shows correct case ID

### Manual Testing - Role Replacement
**Test President Replacement:**
1. Open RCS form for organization with existing president
2. Verify Individual1 autofills with current president's information
3. Change president's last name to a new name
4. Submit form
5. Verify:
   - New contact created with new last name
   - Old president's "President of" relationship has `is_active=false` and `end_date=today`
   - New president's "President of" relationship is active
   - Old president retains all other data and relationships

**Test Executive Director Replacement:**
1. Open RCS form for organization with existing executive director
2. Verify Individual2 autofills with current executive director's information
3. Change executive director's last name to a new name
4. Submit form
5. Verify:
   - New contact created with new last name
   - Old executive director's "Executive Director of" relationship has `is_active=false` and `end_date=today`
   - New executive director's "Executive Director of" relationship is active
   - Old executive director retains all other data and relationships

**Test Name/Email Updates (No Replacement):**
1. Open RCS form for organization with existing president
2. Change only first name or email (keep last name same)
3. Submit form
4. Verify:
   - Same contact updated (no new contact created)
   - "President of" relationship remains unchanged
   - Contact information updated with new first name/email

### Log Verification
```bash
tail -f /home/brian/buildkit/build/masdemo/web/wp-content/uploads/civicrm/ConfigAndLog/CiviCRM.*.log | grep AfformSubmitSubscriber
```

### Database Verification
```php
// Check active relationships for contact
$activeRels = \Civi\Api4\Relationship::get(false)
  ->addWhere('contact_id_a', '=', $contactId)
  ->addWhere('is_active', '=', true)
  ->addSelect('id', 'contact_id_b.display_name', 'relationship_type_id:label', 'case_id', 'start_date', 'end_date')
  ->execute();

// Check inactive relationships (ended role relationships)
$inactiveRels = \Civi\Api4\Relationship::get(false)
  ->addWhere('contact_id_a', '=', $contactId)
  ->addWhere('is_active', '=', false)
  ->addSelect('id', 'contact_id_b.display_name', 'relationship_type_id:label', 'start_date', 'end_date')
  ->execute();

// Check for active role relationships for an organization
$currentPresident = \Civi\Api4\Relationship::get(false)
  ->addWhere('relationship_type_id:name', '=', 'President of')
  ->addWhere('contact_id_b', '=', $organizationId)
  ->addWhere('is_active', '=', true)
  ->addSelect('contact_id_a.display_name', 'start_date')
  ->execute();
```

## Best Practices

1. **Always use relationship type names**, never IDs (ensures cross-environment compatibility)
2. **Check for existing relationships** before creating to avoid duplicates
3. **Wrap creation in try-catch** to prevent transaction rollbacks
4. **Log all attempts** with sufficient context for debugging
5. **Use case-specific relationships** when relationship context matters
6. **Test in development** before deploying to production
7. **Clear cache** after code changes: `cv flush`

## Field gotchas

**Boolean Select fields**: use `id: false` / `id: true` (real booleans), NOT `id: '0'` / `id: '1'` (strings). Core's `afField.component.js` casts `!!option.id` for Boolean data_type — string "0" becomes truthy, breaking the field.

**RCS form `do_not_email` case history** (moved from CLAUDE.md 2026-06-12):
- 2026-02-26: Made the field visible, set `afform_default: '0'` (string), reordered options. Fixed the *untouched-default* path but did NOT fix the *user-interaction* path.
- 2026-05-08: Switched options + default to true booleans (`id: false` / `id: true`) — the actual fix. CiviCRM core's `!!option.id` cast was making both string-id options resolve to `true`.

## Related Documentation

- [ARCHITECTURE.md](ARCHITECTURE.md) - Overall extension architecture
- [DEVELOPMENT.md](DEVELOPMENT.md) - Development workflow
- Main CLAUDE.md - Environment configuration and API patterns
