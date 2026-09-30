# Form Processor Deployment Guide

## Overview

Form Processors have reliable built-in export/import functionality through the CiviCRM UI. This guide documents the manual deployment process for MAS Form Processors.

## Form Processor Files Location

Form processor export files are stored in:

```
/home/brian/workspace/development/mascode/Civi/Mascode/FormProcessor/
```

## Development to Production Deployment Process

### Step 1: Export from Development Environment

1. **Access Form Processor Admin**

   - Navigate to: `Administer` → `System Settings` → `Form Processor`
   - Or direct URL: `https://masdemo.localhost/wp-admin/admin.php?page=CiviCRM&q=civicrm/admin/form_processor`

2. **Export Each Form Processor**

   - Click on the form processor you want to export
   - Click the `Export` button
   - Save the `.json` file to the forms directory above
   - Repeat for each form processor

3. **Verify Export Files**
   - Ensure all form processor `.json` files are saved in the forms directory
   - Check that files contain complete form processor definitions

### Step 2: Import to Production Environment

1. **Access Production Form Processor Admin**

   - Navigate to: `Administer` → `System Settings` → `Form Processor`
   - Or direct URL: `https://[production-url]/wp-admin/admin.php?page=CiviCRM&q=civicrm/admin/form_processor`

2. **Import Each Form Processor**

   - Click `Import` button
   - Select the corresponding `.json` file from your forms directory
   - Review the import preview
   - Click `Import` to complete

3. **Verify Imported Form Processors**
   - Check that all form processors are listed and active
   - Test key form processors to ensure they work correctly
   - Verify any dependent configurations (webhooks, etc.)

## Important Notes

### Environment-Specific Configurations

Form processors may reference environment-specific elements that need manual adjustment after import:

- **Webhook URLs**: Update any webhook endpoints to production URLs
- **Email Templates**: Verify message template references are correct
- **Custom Field References**: Ensure custom field IDs match production environment
- **Case Type References**: Verify case type IDs are correct for production

### Pre-Import Checklist

Before importing form processors to production:

- [ ] All referenced custom fields exist in production
- [ ] All referenced case types exist in production
- [ ] All referenced message templates exist in production
- [ ] All referenced contact types and subtypes exist in production
- [ ] Any external webhook endpoints are configured for production

### Post-Import Verification

After importing form processors:

- [ ] Test each form processor with sample data
- [ ] Verify email notifications are sent correctly
- [ ] Check that case creation works as expected
- [ ] Validate any custom field population
- [ ] Test webhook integrations (if applicable)

## Form Processor List

Document your MAS form processors here:

### Active Form Processors

- **Form Processor Name 1**: Brief description of purpose
- **Form Processor Name 2**: Brief description of purpose
- _(Add additional form processors as needed)_

### Dependencies

- Custom Fields: List any custom field dependencies
- Case Types: List any case type dependencies
- Message Templates: List any template dependencies

## Troubleshooting

### Common Import Issues

1. **Missing Dependencies**: Ensure all referenced entities exist in target environment
2. **Permission Issues**: Verify proper CiviCRM permissions for form processor management
3. **Field Mapping**: Check that custom field names match between environments

### Recovery Process

If import fails:

1. Check the CiviCRM log for specific error messages
2. Verify all dependencies exist in target environment
3. Try importing individual components separately
4. Contact form processor support if issues persist

## Version History

- **v1.0**: Initial documentation for manual form processor deployment
- **Date**: 2025-06-18
- **Author**: MAS Team

---

_This documentation is part of the MAS Extension unified deployment system. For other components, see the automated deployment scripts in the `/scripts` directory._
