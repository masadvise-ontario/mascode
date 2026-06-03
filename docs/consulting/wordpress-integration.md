# WordPress Integration Patterns

This reference documents common integration patterns between WordPress and CiviCRM for MAS nonprofit consulting work.

## Core Integration Architecture

### Data Flow
```
WordPress (Front-end) ↔ WordPress Database
         ↓
    REST API / Hooks
         ↓
CiviCRM (Back-end) ↔ CiviCRM Database
```

### Key Integration Points
1. User/Contact synchronization
2. Form submissions → CiviCRM records
3. Custom post types ↔ CiviCRM entities
4. Membership/Event integration
5. Email/Newsletter coordination

## User ↔ Contact Synchronization

### Pattern 1: WordPress User → CiviCRM Contact (On Registration)

**Use Case:** When someone registers on WordPress, create matching CiviCRM contact

**WordPress Hook:** `user_register`

**Implementation in maswpcode plugin:**

```php
// In maswpcode plugin
add_action('user_register', 'maswpcode_sync_user_to_civicrm', 10, 1);

function maswpcode_sync_user_to_civicrm($user_id) {
    try {
        $user = get_userdata($user_id);
        
        // Check if contact already exists
        $existing = \Civi\Api4\Contact::get(FALSE)
            ->addJoin('Email AS email', 'LEFT')
            ->addWhere('email.email', '=', $user->user_email)
            ->addWhere('email.is_primary', '=', TRUE)
            ->setLimit(1)
            ->execute()
            ->first();
        
        if ($existing) {
            // Contact exists, store mapping
            update_user_meta($user_id, 'civicrm_contact_id', $existing['id']);
            return;
        }
        
        // Create new contact
        $contact = \Civi\Api4\Contact::create(FALSE)
            ->addValue('contact_type', 'Individual')
            ->addValue('first_name', $user->first_name ?: '')
            ->addValue('last_name', $user->last_name ?: '')
            ->addValue('display_name', $user->display_name)
            ->execute()
            ->first();
        
        // Create email
        \Civi\Api4\Email::create(FALSE)
            ->addValue('contact_id', $contact['id'])
            ->addValue('email', $user->user_email)
            ->addValue('is_primary', TRUE)
            ->addValue('is_billing', TRUE)
            ->execute();
        
        // Store mapping
        update_user_meta($user_id, 'civicrm_contact_id', $contact['id']);
        
        // Log success
        error_log("CiviCRM contact {$contact['id']} created for WordPress user {$user_id}");
        
    } catch (Exception $e) {
        error_log("Failed to sync user {$user_id} to CiviCRM: " . $e->getMessage());
    }
}
```

### Pattern 2: CiviCRM Contact → WordPress User (On Contact Creation)

**Use Case:** When CiviCRM contact is created, optionally create WordPress user

**CiviCRM Event:** `hook_civicrm_post`

**Implementation in mascode extension:**

```php
// In mascode extension
namespace Civi\Mascode\EventListener;

use Civi\Core\Event\GenericHookEvent;

class ContactListener {
    
    public function onContactCreated(GenericHookEvent $event) {
        if ($event->entity !== 'Contact' || $event->action !== 'create') {
            return;
        }
        
        $contactId = $event->id;
        $contact = $event->object;
        
        // Only create WP user if email exists and user doesn't exist
        if (!$contact->email_primary) {
            return;
        }
        
        // Check if WP user already exists
        if (email_exists($contact->email_primary)) {
            return;
        }
        
        // Create WordPress user
        $username = sanitize_user(strtolower($contact->email_primary));
        $password = wp_generate_password(12, true);
        
        $user_id = wp_create_user($username, $password, $contact->email_primary);
        
        if (is_wp_error($user_id)) {
            \Civi::log()->error('Failed to create WP user: ' . $user_id->get_error_message());
            return;
        }
        
        // Update user meta
        wp_update_user([
            'ID' => $user_id,
            'first_name' => $contact->first_name ?? '',
            'last_name' => $contact->last_name ?? '',
            'display_name' => $contact->display_name ?? '',
        ]);
        
        // Store mapping
        update_user_meta($user_id, 'civicrm_contact_id', $contactId);
        
        // Send password reset email
        wp_send_new_user_notifications($user_id, 'user');
        
        \Civi::log()->info("WordPress user {$user_id} created for contact {$contactId}");
    }
}
```

### Pattern 3: Bidirectional Sync Helper Functions

**Helper functions in maswpcode:**

```php
/**
 * Get CiviCRM contact ID for WordPress user
 */
function maswpcode_get_contact_id($user_id) {
    return get_user_meta($user_id, 'civicrm_contact_id', true);
}

/**
 * Get WordPress user ID for CiviCRM contact
 */
function maswpcode_get_user_id($contact_id) {
    $users = get_users([
        'meta_key' => 'civicrm_contact_id',
        'meta_value' => $contact_id,
        'number' => 1,
    ]);
    
    return $users ? $users[0]->ID : false;
}

/**
 * Sync user profile updates to CiviCRM
 */
add_action('profile_update', 'maswpcode_sync_profile_to_civicrm', 10, 2);

function maswpcode_sync_profile_to_civicrm($user_id, $old_user_data) {
    $contact_id = maswpcode_get_contact_id($user_id);
    if (!$contact_id) {
        return;
    }
    
    $user = get_userdata($user_id);
    
    try {
        \Civi\Api4\Contact::update(FALSE)
            ->addWhere('id', '=', $contact_id)
            ->addValue('first_name', $user->first_name)
            ->addValue('last_name', $user->last_name)
            ->execute();
            
    } catch (Exception $e) {
        error_log("Failed to sync profile update: " . $e->getMessage());
    }
}
```

## Form Submissions → CiviCRM Records

### Pattern 4: Contact Form → CiviCRM Activity

**Use Case:** Contact form submission creates CiviCRM activity

**WordPress Integration:** Contact Form 7 / Gravity Forms / Elementor Forms

**Example with Contact Form 7:**

```php
// In maswpcode plugin
add_action('wpcf7_before_send_mail', 'maswpcode_cf7_to_civicrm_activity');

function maswpcode_cf7_to_civicrm_activity($contact_form) {
    $submission = WPCF7_Submission::get_instance();
    
    if (!$submission) {
        return;
    }
    
    $data = $submission->get_posted_data();
    
    try {
        // Get or create contact
        $contact_id = maswpcode_get_or_create_contact(
            $data['your-email'],
            $data['your-name']
        );
        
        // Create activity
        \Civi\Api4\Activity::create(FALSE)
            ->addValue('activity_type_id:name', 'Contact Form Submission')
            ->addValue('source_contact_id', $contact_id)
            ->addValue('subject', 'Website Contact Form: ' . $data['your-subject'])
            ->addValue('details', $data['your-message'])
            ->addValue('status_id:name', 'Completed')
            ->addValue('activity_date_time', date('Y-m-d H:i:s'))
            ->execute();
            
    } catch (Exception $e) {
        error_log("Failed to create CiviCRM activity: " . $e->getMessage());
    }
}

function maswpcode_get_or_create_contact($email, $name = '') {
    // Search for existing contact
    $contact = \Civi\Api4\Contact::get(FALSE)
        ->addJoin('Email AS email', 'LEFT')
        ->addWhere('email.email', '=', $email)
        ->setLimit(1)
        ->execute()
        ->first();
    
    if ($contact) {
        return $contact['id'];
    }
    
    // Parse name
    $name_parts = explode(' ', $name, 2);
    $first_name = $name_parts[0] ?? '';
    $last_name = $name_parts[1] ?? '';
    
    // Create contact
    $new_contact = \Civi\Api4\Contact::create(FALSE)
        ->addValue('contact_type', 'Individual')
        ->addValue('first_name', $first_name)
        ->addValue('last_name', $last_name)
        ->execute()
        ->first();
    
    // Add email
    \Civi\Api4\Email::create(FALSE)
        ->addValue('contact_id', $new_contact['id'])
        ->addValue('email', $email)
        ->addValue('is_primary', TRUE)
        ->execute();
    
    return $new_contact['id'];
}
```

### Pattern 5: Service Request Form → CiviCRM Case

**Use Case:** Service request form creates CiviCRM case

```php
// In maswpcode plugin
add_action('gform_after_submission', 'maswpcode_service_request_to_case', 10, 2);

function maswpcode_service_request_to_case($entry, $form) {
    // Only process service request form (ID 5)
    if ($form['id'] != 5) {
        return;
    }
    
    try {
        // Get or create contact
        $contact_id = maswpcode_get_or_create_contact(
            rgar($entry, '2'), // Email field
            rgar($entry, '1')  // Name field
        );
        
        // Create case
        $case = \Civi\Api4\CiviCase::create(FALSE)
            ->addValue('case_type_id:name', 'service_request')
            ->addValue('contact_id', $contact_id)
            ->addValue('subject', rgar($entry, '3')) // Issue summary
            ->addValue('status_id:name', 'Open')
            ->addValue('start_date', date('Y-m-d'))
            ->execute()
            ->first();
        
        // Create opening activity
        \Civi\Api4\Activity::create(FALSE)
            ->addValue('activity_type_id:name', 'Open Case')
            ->addValue('source_contact_id', $contact_id)
            ->addValue('case_id', $case['id'])
            ->addValue('subject', 'Case opened from website')
            ->addValue('details', rgar($entry, '4')) // Full description
            ->addValue('status_id:name', 'Completed')
            ->execute();
        
        // Store case ID in entry meta for reference
        gform_update_meta($entry['id'], 'civicrm_case_id', $case['id']);
        
    } catch (Exception $e) {
        error_log("Failed to create case: " . $e->getMessage());
    }
}
```

## Custom Post Types ↔ CiviCRM Entities

### Pattern 6: Custom Post Type for Projects → CiviCRM Cases

**Use Case:** WordPress custom post type "Projects" synced with CiviCRM Cases

**Register Post Type:**

```php
// In maswpcode plugin
add_action('init', 'maswpcode_register_project_post_type');

function maswpcode_register_project_post_type() {
    register_post_type('mas_project', [
        'labels' => [
            'name' => 'Projects',
            'singular_name' => 'Project',
        ],
        'public' => true,
        'has_archive' => true,
        'supports' => ['title', 'editor', 'custom-fields'],
        'menu_icon' => 'dashicons-portfolio',
    ]);
}
```

**Sync to CiviCRM:**

```php
// Create CiviCRM case when project is published
add_action('publish_mas_project', 'maswpcode_sync_project_to_case', 10, 2);

function maswpcode_sync_project_to_case($post_id, $post) {
    // Avoid infinite loops
    if (get_post_meta($post_id, '_syncing', true)) {
        return;
    }
    update_post_meta($post_id, '_syncing', 1);
    
    try {
        // Get project details
        $contact_id = get_post_meta($post_id, 'client_contact_id', true);
        
        if (!$contact_id) {
            delete_post_meta($post_id, '_syncing');
            return;
        }
        
        // Check if case already exists
        $case_id = get_post_meta($post_id, 'civicrm_case_id', true);
        
        if ($case_id) {
            // Update existing case
            \Civi\Api4\CiviCase::update(FALSE)
                ->addWhere('id', '=', $case_id)
                ->addValue('subject', $post->post_title)
                ->execute();
        } else {
            // Create new case
            $case = \Civi\Api4\CiviCase::create(FALSE)
                ->addValue('case_type_id:name', 'project')
                ->addValue('contact_id', $contact_id)
                ->addValue('subject', $post->post_title)
                ->addValue('details', $post->post_content)
                ->addValue('status_id:name', 'Open')
                ->execute()
                ->first();
            
            update_post_meta($post_id, 'civicrm_case_id', $case['id']);
        }
        
    } catch (Exception $e) {
        error_log("Failed to sync project to case: " . $e->getMessage());
    } finally {
        delete_post_meta($post_id, '_syncing');
    }
}
```

## REST API Endpoints

### Pattern 7: Custom REST API for CiviCRM Operations

**Use Case:** Expose CiviCRM functionality via WordPress REST API for Next.js apps

**Implementation:**

```php
// In maswpcode plugin
add_action('rest_api_init', 'maswpcode_register_civicrm_routes');

function maswpcode_register_civicrm_routes() {
    // Search contacts
    register_rest_route('maswpcode/v1', '/contacts/search', [
        'methods' => 'GET',
        'callback' => 'maswpcode_rest_search_contacts',
        'permission_callback' => 'maswpcode_check_permissions',
        'args' => [
            'query' => [
                'required' => true,
                'type' => 'string',
            ],
        ],
    ]);
    
    // Get contact details
    register_rest_route('maswpcode/v1', '/contacts/(?P<id>\d+)', [
        'methods' => 'GET',
        'callback' => 'maswpcode_rest_get_contact',
        'permission_callback' => 'maswpcode_check_permissions',
    ]);
    
    // Create activity
    register_rest_route('maswpcode/v1', '/activities', [
        'methods' => 'POST',
        'callback' => 'maswpcode_rest_create_activity',
        'permission_callback' => 'maswpcode_check_permissions',
    ]);
}

function maswpcode_check_permissions() {
    return current_user_can('edit_posts');
}

function maswpcode_rest_search_contacts($request) {
    $query = $request->get_param('query');
    
    try {
        $contacts = \Civi\Api4\Contact::get(FALSE)
            ->addSelect('id', 'display_name', 'email_primary.email')
            ->addJoin('Email AS email_primary', 'LEFT', ['email_primary.is_primary', '=', TRUE])
            ->addWhere('display_name', 'CONTAINS', $query)
            ->setLimit(25)
            ->execute();
        
        return rest_ensure_response($contacts->getArrayCopy());
        
    } catch (Exception $e) {
        return new WP_Error('api_error', $e->getMessage(), ['status' => 500]);
    }
}

function maswpcode_rest_get_contact($request) {
    $contact_id = $request->get_param('id');
    
    try {
        $contact = \Civi\Api4\Contact::get(FALSE)
            ->addWhere('id', '=', $contact_id)
            ->execute()
            ->first();
        
        if (!$contact) {
            return new WP_Error('not_found', 'Contact not found', ['status' => 404]);
        }
        
        return rest_ensure_response($contact);
        
    } catch (Exception $e) {
        return new WP_Error('api_error', $e->getMessage(), ['status' => 500]);
    }
}

function maswpcode_rest_create_activity($request) {
    $params = $request->get_json_params();
    
    try {
        $activity = \Civi\Api4\Activity::create(FALSE)
            ->addValue('activity_type_id:name', $params['type'])
            ->addValue('source_contact_id', $params['contact_id'])
            ->addValue('subject', $params['subject'])
            ->addValue('details', $params['details'] ?? '')
            ->addValue('status_id:name', 'Completed')
            ->execute()
            ->first();
        
        return rest_ensure_response($activity);
        
    } catch (Exception $e) {
        return new WP_Error('api_error', $e->getMessage(), ['status' => 500]);
    }
}
```

## Elementor Pro Integration

### Pattern 8: Elementor Form → CiviCRM

**Use Case:** Elementor form submissions create CiviCRM records

```php
// In maswpcode plugin
add_action('elementor_pro/forms/new_record', 'maswpcode_elementor_to_civicrm', 10, 2);

function maswpcode_elementor_to_civicrm($record, $handler) {
    $form_name = $record->get_form_settings('form_name');
    
    // Only process donation forms
    if ($form_name !== 'donation_form') {
        return;
    }
    
    $fields = $record->get_formatted_data();
    
    try {
        // Get or create contact
        $contact_id = maswpcode_get_or_create_contact(
            $fields['email'],
            $fields['name']
        );
        
        // Create contribution
        \Civi\Api4\Contribution::create(FALSE)
            ->addValue('contact_id', $contact_id)
            ->addValue('financial_type_id:name', 'Donation')
            ->addValue('total_amount', $fields['amount'])
            ->addValue('contribution_status_id:name', 'Pending')
            ->addValue('source', 'Website Donation Form')
            ->execute();
            
    } catch (Exception $e) {
        error_log("Elementor to CiviCRM failed: " . $e->getMessage());
    }
}
```

## Best Practices

### Error Handling
```php
try {
    // CiviCRM API call
} catch (\API_Exception $e) {
    error_log("CiviCRM API Error: " . $e->getMessage());
    // Graceful fallback
} catch (Exception $e) {
    error_log("General Error: " . $e->getMessage());
    // Notify admin if critical
}
```

### Logging
```php
// WordPress debugging
if (defined('WP_DEBUG') && WP_DEBUG) {
    error_log("Processing contact ID: {$contact_id}");
}

// CiviCRM logging
\Civi::log()->info("Contact synced from WordPress", [
    'wp_user_id' => $user_id,
    'civi_contact_id' => $contact_id,
]);
```

### Performance
- Cache CiviCRM queries when possible
- Use WordPress transients for frequently accessed data
- Batch process large operations
- Use background jobs (WP-Cron) for heavy tasks

### Security
- Validate and sanitize all inputs
- Check permissions before CiviCRM operations
- Use nonces for form submissions
- Escape output when displaying CiviCRM data in WordPress

## Common Pitfalls

❌ **Don't query CiviCRM on every page load**
```php
// BAD - Runs on every page
$contact = \Civi\Api4\Contact::get(FALSE)...
```

✅ **Cache results**
```php
// GOOD - Cache for 1 hour
$cache_key = 'civicrm_contact_' . $contact_id;
$contact = get_transient($cache_key);

if (false === $contact) {
    $contact = \Civi\Api4\Contact::get(FALSE)...
    set_transient($cache_key, $contact, HOUR_IN_SECONDS);
}
```

❌ **Don't use API v3**
```php
// BAD
civicrm_api3('Contact', 'create', $params);
```

✅ **Use API v4**
```php
// GOOD
\Civi\Api4\Contact::create(FALSE)->addValue(...)...
```

❌ **Don't hardcode entity IDs**
```php
// BAD
'activity_type_id' => 5
```

✅ **Use entity names**
```php
// GOOD
'activity_type_id:name' => 'Meeting'
```
