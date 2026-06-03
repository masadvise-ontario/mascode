# CiviCRM Development Patterns

This reference provides working code examples for common CiviCRM tasks using API v4 and Symfony EventDispatcher.

## API v4 Patterns

### Basic Entity Operations

#### Get Contact by ID
```php
$contact = \Civi\Api4\Contact::get(FALSE)
  ->addWhere('id', '=', $contactId)
  ->execute()
  ->first();
```

#### Search Contacts
```php
$contacts = \Civi\Api4\Contact::get(FALSE)
  ->addWhere('first_name', '=', 'Brian')
  ->addWhere('contact_type', '=', 'Individual')
  ->setLimit(25)
  ->execute();

foreach ($contacts as $contact) {
  echo $contact['display_name'];
}
```

#### Create Activity
```php
$activity = \Civi\Api4\Activity::create(FALSE)
  ->addValue('activity_type_id:name', 'Meeting')
  ->addValue('subject', 'Donor Consultation')
  ->addValue('source_contact_id', $contactId)
  ->addValue('status_id:name', 'Scheduled')
  ->addValue('activity_date_time', date('Y-m-d H:i:s'))
  ->execute()
  ->first();
```

#### Update Contact
```php
$result = \Civi\Api4\Contact::update(FALSE)
  ->addWhere('id', '=', $contactId)
  ->addValue('custom_123', 'New Value')
  ->execute();
```

#### Delete Record (Soft)
```php
$result = \Civi\Api4\Contact::delete(FALSE)
  ->addWhere('id', '=', $contactId)
  ->execute();
```

### Advanced Queries

#### Joins
```php
$results = \Civi\Api4\Contact::get(FALSE)
  ->addSelect('first_name', 'last_name', 'email.email')
  ->addJoin('Email AS email', 'LEFT')
  ->addWhere('email.is_primary', '=', TRUE)
  ->execute();
```

#### Aggregates
```php
$stats = \Civi\Api4\Contribution::get(FALSE)
  ->addSelect('SUM(total_amount) AS total')
  ->addSelect('COUNT(id) AS count')
  ->addWhere('contact_id', '=', $contactId)
  ->addWhere('contribution_status_id:name', '=', 'Completed')
  ->execute()
  ->first();
```

#### Custom Fields
```php
// Get custom field value
$contact = \Civi\Api4\Contact::get(FALSE)
  ->addWhere('id', '=', $contactId)
  ->addSelect('custom_123') // Use field ID
  ->execute()
  ->first();

// Or use field name
$contact = \Civi\Api4\Contact::get(FALSE)
  ->addWhere('id', '=', $contactId)
  ->addSelect('MyGroup.MyField') // Use group.field
  ->execute()
  ->first();
```

### Batch Operations

#### Create Multiple Records
```php
$contacts = \Civi\Api4\Contact::save(FALSE)
  ->setRecords([
    ['first_name' => 'John', 'last_name' => 'Doe', 'contact_type' => 'Individual'],
    ['first_name' => 'Jane', 'last_name' => 'Smith', 'contact_type' => 'Individual'],
  ])
  ->execute();
```

#### Bulk Update
```php
$result = \Civi\Api4\Contact::update(FALSE)
  ->addWhere('contact_sub_type:name', 'CONTAINS', 'Donor')
  ->addValue('custom_456', 'Updated Value')
  ->execute();
```

## Symfony Event Patterns

### Subscribe to Events

#### Basic Event Listener
```php
// In your extension's main file (e.g., mascde.php)
use Symfony\Component\DependencyInjection\ContainerBuilder;

function mascde_civicrm_container(ContainerBuilder $container) {
  $container->autowire('my_event_listener', \Civi\Mascde\EventListener\MyListener::class)
    ->addTag('kernel.event_listener', [
      'event' => 'hook_civicrm_post',
      'method' => 'onContactSaved'
    ]);
}
```

#### Event Listener Class
```php
namespace Civi\Mascde\EventListener;

use Civi\Core\Event\GenericHookEvent;

class MyListener {
  
  /**
   * React when a contact is saved
   */
  public function onContactSaved(GenericHookEvent $event) {
    // Only process Contact entities
    if ($event->entity !== 'Contact') {
      return;
    }
    
    // Only process 'create' operations
    if ($event->action !== 'create') {
      return;
    }
    
    $contactId = $event->id;
    $contact = $event->object;
    
    // Your custom logic here
    \Civi::log()->info("New contact created: {$contactId}");
  }
}
```

### Common Events

#### hook_civicrm_post
Fires after entity is saved to database:
```php
public function onPost(GenericHookEvent $event) {
  $entity = $event->entity;    // 'Contact', 'Contribution', etc.
  $action = $event->action;    // 'create', 'edit', 'delete'
  $id = $event->id;            // Entity ID
  $object = $event->object;    // Entity data
}
```

#### hook_civicrm_pre
Fires before entity is saved:
```php
public function onPre(GenericHookEvent $event) {
  // Can modify $event->object before save
  if ($event->entity === 'Contact' && $event->action === 'create') {
    $event->object->custom_123 = 'Default Value';
  }
}
```

#### hook_civicrm_buildForm
Fires when building forms:
```php
public function onBuildForm(GenericHookEvent $event) {
  $formName = $event->form->getFormName();
  
  if ($formName === 'CRM_Contact_Form_Contact') {
    // Modify contact form
    $event->form->assign('myVar', 'myValue');
  }
}
```

### Advanced Event Patterns

#### Priority Control
```php
// Higher priority = runs first
$container->autowire('high_priority_listener', MyListener::class)
  ->addTag('kernel.event_listener', [
    'event' => 'hook_civicrm_post',
    'method' => 'onContactSaved',
    'priority' => 100  // Default is 0
  ]);
```

#### Multiple Event Methods
```php
class ContactListener {
  
  public function onCreate(GenericHookEvent $event) {
    if ($event->action !== 'create') return;
    // Handle creates
  }
  
  public function onUpdate(GenericHookEvent $event) {
    if ($event->action !== 'edit') return;
    // Handle updates
  }
}

// Register both methods
$container->autowire('contact_listener', ContactListener::class)
  ->addTag('kernel.event_listener', [
    'event' => 'hook_civicrm_post',
    'method' => 'onCreate'
  ])
  ->addTag('kernel.event_listener', [
    'event' => 'hook_civicrm_post',
    'method' => 'onUpdate'
  ]);
```

#### Stop Event Propagation
```php
public function onContactSaved(GenericHookEvent $event) {
  // Process the event
  // ...
  
  // Prevent other listeners from running
  $event->stopPropagation();
}
```

## Integration Patterns

### WordPress User → CiviCRM Contact Sync
```php
// When WordPress user is created
add_action('user_register', function($userId) {
  $user = get_userdata($userId);
  
  // Create corresponding CiviCRM contact
  $contact = \Civi\Api4\Contact::create(FALSE)
    ->addValue('contact_type', 'Individual')
    ->addValue('first_name', $user->first_name)
    ->addValue('last_name', $user->last_name)
    ->execute()
    ->first();
  
  // Create email
  \Civi\Api4\Email::create(FALSE)
    ->addValue('contact_id', $contact['id'])
    ->addValue('email', $user->user_email)
    ->addValue('is_primary', TRUE)
    ->execute();
  
  // Store mapping
  update_user_meta($userId, 'civicrm_contact_id', $contact['id']);
});
```

### N8N Webhook → CiviCRM Activity
```php
// Endpoint: /civicrm/n8n/activity
// Receive POST data from n8n and create activity

$data = json_decode(file_get_contents('php://input'), TRUE);

$activity = \Civi\Api4\Activity::create(FALSE)
  ->addValue('activity_type_id:name', $data['type'])
  ->addValue('subject', $data['subject'])
  ->addValue('source_contact_id', $data['contact_id'])
  ->addValue('details', $data['details'])
  ->addValue('activity_date_time', date('Y-m-d H:i:s'))
  ->execute();

http_response_code(201);
echo json_encode($activity);
```

### Form Processor → CiviCase
```php
// Event listener for form submissions
public function onFormSubmit(GenericHookEvent $event) {
  $submission = $event->submission;
  
  // Create case
  $case = \Civi\Api4\Case::create(FALSE)
    ->addValue('case_type_id:name', 'service_request')
    ->addValue('contact_id', $submission['contact_id'])
    ->addValue('subject', $submission['issue'])
    ->addValue('status_id:name', 'Open')
    ->execute()
    ->first();
  
  // Add initial activity
  \Civi\Api4\Activity::create(FALSE)
    ->addValue('activity_type_id:name', 'Open Case')
    ->addValue('source_contact_id', $submission['contact_id'])
    ->addValue('case_id', $case['id'])
    ->addValue('subject', 'Case opened from form')
    ->execute();
}
```

## Best Practices

### Error Handling
```php
try {
  $contact = \Civi\Api4\Contact::get(FALSE)
    ->addWhere('id', '=', $contactId)
    ->execute()
    ->first();
} catch (\API_Exception $e) {
  \Civi::log()->error('Failed to fetch contact: ' . $e->getMessage());
  // Handle gracefully
}
```

### Permission Checks
```php
// Check for permission FALSE = skip permission check
$contact = \Civi\Api4\Contact::get(TRUE) // TRUE = check permissions
  ->addWhere('id', '=', $contactId)
  ->execute()
  ->first();
```

### Logging
```php
\Civi::log()->debug('Processing contact: ' . $contactId);
\Civi::log()->info('Contact created successfully');
\Civi::log()->warning('Unusual activity detected');
\Civi::log()->error('Failed to save contact', ['id' => $contactId]);
```

### Configuration
```php
// Get extension settings
$setting = \Civi\Api4\Setting::get(FALSE)
  ->addSelect('my_extension_setting')
  ->execute()
  ->first();

// Set extension settings
\Civi\Api4\Setting::set(FALSE)
  ->addValue('my_extension_setting', 'value')
  ->execute();
```

## Testing

### Unit Test Example
```php
namespace Civi\Mascde\Tests;

class ContactTest extends \PHPUnit\Framework\TestCase {
  
  public function testCreateContact() {
    $contact = \Civi\Api4\Contact::create(FALSE)
      ->addValue('first_name', 'Test')
      ->addValue('last_name', 'Contact')
      ->addValue('contact_type', 'Individual')
      ->execute()
      ->first();
    
    $this->assertNotEmpty($contact['id']);
    $this->assertEquals('Test', $contact['first_name']);
  }
}
```

## Common Pitfalls

❌ **Don't use API v3**
```php
// BAD
civicrm_api3('Contact', 'create', $params);
```

✅ **Use API v4**
```php
// GOOD
\Civi\Api4\Contact::create(FALSE)
  ->addValue('first_name', 'Brian')
  ->execute();
```

❌ **Don't use BAO directly**
```php
// BAD
CRM_Contact_BAO_Contact::create($params);
```

✅ **Use API v4**
```php
// GOOD
\Civi\Api4\Contact::create(FALSE)
  ->setValues($params)
  ->execute();
```

❌ **Don't use hooks when events exist**
```php
// BAD
function myext_civicrm_post($op, $objectName, $objectId, &$objectRef) {
  // Old hook system
}
```

✅ **Use Symfony EventDispatcher**
```php
// GOOD
class MyListener {
  public function onPost(GenericHookEvent $event) {
    // Modern event system
  }
}
```
