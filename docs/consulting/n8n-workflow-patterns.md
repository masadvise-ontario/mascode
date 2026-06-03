# N8N Workflow Patterns

This reference provides common n8n workflow patterns for nonprofit automation, specifically for MAS consulting work.

## Installation & Setup

### Self-Hosted Instance
- **URL:** https://n8n.masadvise.org/
- **Deployment:** Docker container (recommended)
- **Port:** 5678 (default)
- **Database:** PostgreSQL or MySQL

### Key Configuration
- Enable webhook triggers
- Configure SMTP for email nodes
- Set up API credentials for CiviCRM and WordPress
- Configure timezone for scheduled workflows

## Common Workflow Patterns

### 1. Contact Sync (WordPress → CiviCRM)

**Use Case:** When a user registers on WordPress, create corresponding CiviCRM contact

**Workflow Structure:**
```
Webhook Trigger (WordPress user_register)
  ↓
Extract User Data
  ↓
Check if Contact Exists (CiviCRM API v4)
  ↓
IF/Switch Node
  ├─ Contact Exists → Update Contact
  └─ Contact New → Create Contact + Email
```

**Implementation:**

**Step 1: Webhook Trigger**
- Node: Webhook
- Method: POST
- Path: `/webhook/wp-user-register`

**Step 2: CiviCRM Check Contact**
- Node: HTTP Request
- Method: POST
- URL: `https://your-site.org/civicrm/ajax/api4/Contact/get`
- Authentication: API Key
- Body:
```json
{
  "where": [
    ["email_primary.email", "=", "{{ $json.user_email }}"]
  ],
  "limit": 1
}
```

**Step 3: IF Node**
- Condition: `{{ $json.values.length }} > 0`
- True: Contact exists → Update
- False: Contact doesn't exist → Create

**Step 4a: Create Contact (if new)**
```json
{
  "values": {
    "contact_type": "Individual",
    "first_name": "{{ $json.first_name }}",
    "last_name": "{{ $json.last_name }}"
  }
}
```

**Step 4b: Create Email**
```json
{
  "values": {
    "contact_id": "{{ $json.id }}",
    "email": "{{ $node['Webhook'].json.user_email }}",
    "is_primary": true
  }
}
```

### 2. Case Creation from Form Submission

**Use Case:** Form submission creates CiviCRM case with linked activity

**Workflow Structure:**
```
Webhook Trigger (Form Submission)
  ↓
Validate Data
  ↓
Get/Create Contact
  ↓
Create Case
  ↓
Create Initial Activity
  ↓
Send Confirmation Email
```

**Implementation:**

**Step 1: Webhook**
- Receives form data: name, email, issue description

**Step 2: Get/Create Contact**
- Search by email
- Create if not found
- Get contact_id for case

**Step 3: Create Case**
- Node: HTTP Request (CiviCRM API v4)
- Endpoint: `/civicrm/ajax/api4/Case/create`
```json
{
  "values": {
    "case_type_id:name": "service_request",
    "contact_id": "{{ $json.contact_id }}",
    "subject": "{{ $node['Webhook'].json.issue }}",
    "status_id:name": "Open"
  }
}
```

**Step 4: Create Activity**
```json
{
  "values": {
    "activity_type_id:name": "Open Case",
    "source_contact_id": "{{ $json.contact_id }}",
    "case_id": "{{ $node['Create Case'].json.id }}",
    "subject": "Case opened from form",
    "details": "{{ $node['Webhook'].json.description }}"
  }
}
```

**Step 5: Email Confirmation**
- Node: Send Email
- To: `{{ $node['Get Contact'].json.email }}`
- Subject: "Your request has been received"
- Body: Include case number and next steps

### 3. Donor Intelligence Monitoring

**Use Case:** Monitor donor online activity and trigger outreach at optimal times

**Workflow Structure:**
```
Schedule Trigger (Daily 9 AM)
  ↓
Get Active Donors (CiviCRM)
  ↓
FOR EACH Donor
  ├─ Check LinkedIn Activity
  ├─ Check Company News
  ├─ Check Social Media
  └─ Aggregate Signals
    ↓
Calculate Engagement Score
  ↓
IF Score > Threshold
  ├─ Create Activity in CiviCRM
  ├─ Notify Fundraiser
  └─ Add to Outreach Queue
```

**Implementation:**

**Step 1: Schedule Trigger**
- Node: Cron
- Schedule: `0 9 * * *` (9 AM daily)

**Step 2: Get Donors**
- CiviCRM API v4: Contact/get
- Filter: `contact_sub_type CONTAINS 'Donor'`
- Select: id, display_name, email

**Step 3: Loop Through Donors**
- Node: Split In Batches
- Batch Size: 10 (to avoid rate limits)

**Step 4: Check Multiple Sources**
- LinkedIn API (if available)
- Google News API (company mentions)
- Twitter/X API (social activity)
- Each returns engagement indicators

**Step 5: Aggregate Signals**
- Node: Function
```javascript
const signals = {
  linkedin_posts: $node['LinkedIn'].json.post_count || 0,
  news_mentions: $node['News'].json.mention_count || 0,
  social_engagement: $node['Social'].json.engagement_score || 0
};

const score = 
  (signals.linkedin_posts * 3) +
  (signals.news_mentions * 5) +
  (signals.social_engagement * 2);

return {
  contact_id: $node['Split In Batches'].json.id,
  engagement_score: score,
  signals: signals
};
```

**Step 6: IF High Engagement**
- Condition: `{{ $json.engagement_score }} > 15`

**Step 7a: Create Activity**
- CiviCRM API v4: Activity/create
- Type: "Donor Intelligence Alert"
- Details: Include signals and score

**Step 7b: Send Alert Email**
- To: Fundraiser
- Subject: "High engagement detected: [Donor Name]"
- Body: Summary of signals and suggested actions

### 4. Email Notification on Case Update

**Use Case:** When case status changes, notify relevant stakeholders

**Workflow Structure:**
```
Webhook Trigger (CiviCRM hook_civicrm_post)
  ↓
Filter: Entity = Case, Action = edit
  ↓
Get Case Details
  ↓
Get Contact Details
  ↓
Check Status Change
  ↓
IF Status Changed
  ├─ Get Case Manager
  ├─ Get Client
  └─ Send Notifications
```

**Implementation:**

**Step 1: Webhook Setup**
- In CiviCRM extension, trigger webhook on case update
- POST to n8n webhook URL with case_id

**Step 2: Get Case**
- CiviCRM API v4: Case/get
- Include: contact_id, case_manager_id, status_id

**Step 3: Check Status Change**
- Node: Function
- Compare `old_status` vs `new_status`

**Step 4: Send Notifications**
- Email to case manager
- Email to client (if appropriate)
- Create activity record

### 5. Bulk Data Processing

**Use Case:** Scheduled cleanup, reporting, or batch updates

**Workflow Structure:**
```
Schedule Trigger (Weekly Sunday 2 AM)
  ↓
Get Records to Process
  ↓
Split In Batches (100 at a time)
  ↓
FOR EACH Batch
  ├─ Process Records
  ├─ Update Database
  └─ Log Results
    ↓
Aggregate Results
  ↓
Send Summary Report
```

**Best Practices:**
- Use small batch sizes (50-100 records)
- Include error handling for each batch
- Log all operations
- Send summary report on completion

### 6. Chatbot Identity Resolution (Identity-Injection Pattern)

**Use Case:** Any n8n AI Agent chatbot that needs to personalize responses per CiviCRM contact — log activities to the right contact, surface user-specific data, or honor per-user policies.

**Workflow Structure:**
```
Webhook Trigger (chat message in)
  ↓
Extract Identifier (email or session token from payload)
  ↓
Lookup Contact (VC Lookup workflow OR CiviCRM API v4 Contact::get)
  ↓
Inject contact_id into AI Agent input payload
  ↓
AI Agent (now able to call tools scoped to that contact)
  ↓
Log activity against contact_id
```

**Steps:**
1. **Extract** — pull user identifier (email, session token, or auth header) from webhook payload.
2. **Lookup** — call VC Lookup sub-workflow (preferred for mas-vc-chatbot ecosystem) or CiviCRM API v4 `Contact::get` directly. Always with `checkPermissions=FALSE` and lookup by name not ID (cross-env compatible).
3. **Inject** — pass `contact_id` (and any related fields you need) into the AI Agent node's input so downstream tool calls can reference the correct contact.

**Where built:** mas-vc-chatbot-stream.

**Security note:** When the chatbot endpoint is publicly reachable, harden with server-side identifier verification before the lookup — don't trust the client-supplied email/token alone. See handoff #383 for the verify pattern.

**Reusable in:** mas-vc-chatbot, mas-ai-advisor, any future MAS chatbot requiring personalization.

## Integration Patterns

### CiviCRM API v4 Integration

**Setup:**
- Node: HTTP Request
- Authentication: API Key in headers
- Base URL: `https://your-site.org/civicrm/ajax/api4/`

**Headers:**
```
X-Civi-Auth: Bearer YOUR_API_KEY
Content-Type: application/json
```

**Common Endpoints:**
- `Contact/get` - Search contacts
- `Contact/create` - Create contact
- `Activity/create` - Log activity
- `Case/get` - Get case details
- `Email/create` - Add email to contact

### WordPress REST API Integration

**Setup:**
- Node: HTTP Request
- Authentication: Application Password or JWT
- Base URL: `https://your-site.org/wp-json/wp/v2/`

**Common Endpoints:**
- `users` - Manage users
- `posts` - Create/update posts
- `civicrm/contact` - Custom endpoint if created

### Error Handling

**Pattern:**
```
Main Workflow
  ↓
Try/Catch (Error Trigger)
  ├─ Success → Continue
  └─ Error → Error Handler
      ├─ Log Error
      ├─ Send Alert Email
      └─ Create Failed Job Record
```

**Error Node Configuration:**
- Node: Error Trigger
- Connected to all nodes that might fail
- Catches errors and routes to handler

**Error Handler Actions:**
- Log to file or database
- Send email to admin
- Create retry queue entry
- Update status in tracking system

## Workflow Documentation Standards

### Required Documentation
1. **Workflow Name** - Descriptive, following pattern: `[System]-[Action]-[Target]`
   - Examples: `WP-Sync-Contact`, `Civi-Create-Case`, `Email-Notify-Manager`

2. **Purpose** - One sentence description

3. **Trigger** - What starts the workflow
   - Webhook URL and authentication
   - Schedule (cron syntax)
   - Manual trigger conditions

4. **Data Flow** - Brief description of each step

5. **Error Handling** - What happens on failure

6. **Dependencies** - Required credentials, external APIs

7. **Testing** - How to test safely

### Version Control
- Export workflow JSON to Git repository
- Include version number in workflow name
- Document changes in commit messages
- Tag releases: `v1.0.0`, `v1.1.0`, etc.

### Testing Procedures

**1. Test in Sandbox First**
- Duplicate workflow
- Point to test environment
- Use test data
- Verify all paths (success and error)

**2. Test Individual Nodes**
- Use "Execute Node" feature
- Verify data transformation
- Check API responses
- Validate conditional logic

**3. Monitor Initial Production Runs**
- Watch execution logs
- Verify data accuracy
- Check for errors
- Monitor performance

## Common Pitfalls

### ❌ Don't: Hardcode Values
```javascript
// BAD
const contactId = 123;
```

### ✅ Do: Use Dynamic References
```javascript
// GOOD
const contactId = $node['Get Contact'].json.id;
```

### ❌ Don't: Ignore Rate Limits
```javascript
// BAD - Processes all 1000 contacts at once
```

### ✅ Do: Use Batching
```javascript
// GOOD - Process 50 at a time with delays
Split In Batches: 50
Wait: 1 second between batches
```

### ❌ Don't: Skip Error Handling
```
Workflow without Error Trigger
```

### ✅ Do: Always Add Error Handling
```
Workflow → Error Trigger → Handler
```

## Performance Tips

1. **Batch Processing** - Process in chunks of 50-100
2. **Caching** - Store frequently accessed data
3. **Parallel Execution** - Use Split In Batches for independent operations
4. **Webhooks over Polling** - Push notifications instead of scheduled checks
5. **Async Where Possible** - Don't wait for slow operations

## Monitoring & Logging

### Execution Logs
- Review failed executions daily
- Monitor execution time trends
- Track error patterns

### Custom Logging
```javascript
// Add to Function node for detailed logging
console.log('Processing contact:', $json.contact_id);
console.log('Engagement score:', engagementScore);

return {
  ...$json,
  log_timestamp: new Date().toISOString()
};
```

### Alerts
- Email on workflow failures
- Weekly summary reports
