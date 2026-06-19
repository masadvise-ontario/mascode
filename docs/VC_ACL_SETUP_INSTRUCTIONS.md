# Volunteer Consultant (VC) ACL Setup Instructions - Manual Configuration

> ⚠️ **SUPERSEDED (2026-06-19).** This native-ACL approach has been **retired** and is kept for historical reference only — do not configure a site from it.
>
> The VC Portal now enforces access through **custom front-end Afform + SearchKit screens** where the SearchKit WHERE filter (scoped by `user_contact_id`) is the security boundary; displays run `acl_bypass=TRUE` and Subscribers hold only `access CiviCRM`. The contact-based ACL path here was abandoned because its per-user filter hook (`mascode_civicrm_aclGroup`, Part 6) was never implemented, so it leaked every VC's clients to every VC.
>
> Canonical design: BrianPKM `3-Resources/mascode-vc-portal-security-spec.md`. Implementation: `Civi/Mascode/Managed/SavedSearch_Case_Details_VC*.mgd.php` + `ang/afsearchMASCaseDetailsVC.aff.*`. Decision record: Klaus handoff theme `vc-portal-security` (#608).

This document provides step-by-step manual instructions for configuring CiviCRM ACL permissions to allow WordPress Subscribers (Volunteer Consultants) to view specific contacts and cases.

## Overview

WordPress Subscribers will be able to view:
1. **All contacts** with Service Requests in "Sent for Assignment" status
2. **All contacts** where the VC is assigned as "Case Coordinator" on any case (Service Request or Project)

Access is **contact-based**, meaning VCs will see ALL data for permitted contacts (cases, activities, relationships, contributions, etc.).

---

## Prerequisites

Before starting, verify these items exist in your CiviCRM:
- ✅ Case Type: "Service Request" (name: `service_request`)
- ✅ Case Type: "Project" (name: `project`)
- ✅ Case Status: "Sent for Assignment"
- ✅ Relationship Type: "Case Coordinator is / Case Coordinator"
- ✅ ACL Role: "Volunteer Consultant" (should already exist)

---

## Part 1: Create Smart Groups

### Step 1.1: Create Smart Group - "Clients with Open Service Requests"

**Note:** You may already have this group (ID 47: "Clients - Open Service Requests (Sent for Assignment)"). If so, skip to Step 1.2.

1. Go to: **CiviCRM → Search → SearchKit**

2. Click **New Search**

3. **Configure Search:**
   - **Search for:** Contact
   - **Search name:** `clients_with_open_service_requests`
   - **Search label:** "Clients with Open Service Requests (Sent for Assignment)"

4. **Add WHERE clauses:**
   - Click **Add WHERE**
   - Select: `Case (Contact) → Case Type` **Equals** `Service Request`
   - Click **Add WHERE**
   - Select: `Case (Contact) → Case Status` **Equals** `Sent for Assignment`
   - Click **Add WHERE**
   - Select: `Case (Contact) → Is Deleted` **Equals** `No`

5. **Group By:**
   - Click **Group By**
   - Select: `Contact ID`

6. Click **Save**

7. **Create Smart Group from Search:**
   - After saving, click the **Actions** button
   - Select **Save as Smart Group**
   - **Group Name:** `Clients_Open_Service_Requests`
   - **Group Title:** "Clients - Open Service Requests (Sent for Assignment)"
   - **Description:** "Contacts who have Service Requests in 'Sent for Assignment' status"
   - **Visibility:** User and User Admin Only
   - Click **Save**

8. **Record the Group ID** (you'll see it in the URL: `civicrm/group?...&gid=XX`)
   - Group ID: ________ (write this down!)

---

### Step 1.2: Create Smart Group - "Clients Assigned to Current VC"

1. Go to: **CiviCRM → Search → SearchKit**

2. Click **New Search**

3. **Configure Search:**
   - **Search for:** Contact
   - **Search name:** `Clients_Assigned_to_Current_VC`
   - **Search label:** "Clients Assigned to Current VC as Case Coordinator"

4. **Add JOIN:**
   - Click **Add JOIN**
   - Select: **RelationshipCache**
   - Alias: `Contact_RelationshipCache_Contact_01`
   - Join Type: LEFT
   - Join Condition: `Contact ID` = `RelationshipCache → Far Contact ID`

5. **Add SELECT fields:**
   - `Contact ID`
   - `Display Name`
   - `RelationshipCache → Near Contact ID`
   - `RelationshipCache → Case ID`

6. **Add WHERE clauses:**
   - Click **Add WHERE**
   - Select: `RelationshipCache → Near Relation` **Equals** `Case Coordinator is`
   - Click **Add WHERE**
   - Select: `RelationshipCache → Is Active` **Equals** `Yes`
   - Click **Add WHERE**
   - Select: `Is Deleted` **Equals** `No`

7. **Group By:**
   - Click **Group By**
   - Select: `Contact ID`

8. Click **Save**

9. **Create Smart Group from Search:**
   - Click **Actions** → **Save as Smart Group**
   - **Group Name:** `Clients_Assigned_to_Current_VC`
   - **Group Title:** "Clients Assigned to Current VC"
   - **Description:** "Contacts who have cases where the current user is assigned as Case Coordinator"
   - **Visibility:** User and User Admin Only
   - Click **Save**

10. **Record the Group ID**
    - Group ID: ________ (write this down! You'll need it for the hook configuration)

---

## Part 2: Create ACL Group to Hold VC Contacts

This group will contain all Volunteer Consultants who should have ACL permissions.

1. Go to: **CiviCRM → Contacts → Manage Groups**

2. Click **Add Group**

3. **Configure Group:**
   - **Group Name:** `VC_ACL_Group`
   - **Group Title:** "Volunteer Consultants (ACL Group)"
   - **Description:** "Contains all CiviCRM contacts who are Volunteer Consultants. WordPress Subscribers are automatically added to this group."
   - **Group Type:** (leave default - Access Control)
   - **Visibility:** User and User Admin Only
   - **Is Active:** Yes
   - **Is Reserved:** No

4. Click **Save**

5. **Record the Group ID**
   - Group ID: ________ (write this down!)

---

## Part 3: Verify ACL Role Exists

The "Volunteer Consultant" ACL role should already exist from your previous setup.

1. Go to: **CiviCRM → Administer → Users and Permissions → Manage ACL Roles**

2. **Verify** you see: "Volunteer Consultant"

3. Click **Edit** next to "Volunteer Consultant"

4. **Record the Value** (e.g., `3`)
   - ACL Role Value: ________ (write this down!)

**If the role doesn't exist:**
1. Click **Add ACL Role**
2. **Label:** "Volunteer Consultant"
3. **Value:** (auto-generated, note it down)
4. **Description:** "Volunteer Consultants who can view assigned cases"
5. **Is Active:** Yes
6. Click **Save**

---

## Part 4: Assign ACL Role to ACL Group

This connects the "Volunteer Consultant" role to the group containing VC contacts.

1. Go to: **CiviCRM → Administer → Users and Permissions → Assign Users to ACL Roles**

2. Click **Add ACL Role Assignment**

3. **Configure Assignment:**
   - **ACL Role:** Select "Volunteer Consultant"
   - **Assigned to:** Select "Volunteer Consultants (ACL Group)" (the group you created in Part 2)
   - **Is Active:** Yes

4. Click **Save**

---

## Part 5: Create ACL Rules

These rules define what contacts VCs can view.

### Rule 1: VC View Clients with Service Requests Sent for Assignment

1. Go to: **CiviCRM → Administer → Users and Permissions → Manage ACLs**

2. Click **Add ACL**

3. **Configure ACL:**
   - **Description:** "VC View Clients with Service Requests Sent for Assignment"
   - **Role:** Select "Volunteer Consultant"
   - **Operation:** View
   - **Type of Data:** A group of contacts
   - **Which group:** Select "Clients - Open Service Requests (Sent for Assignment)" (from Part 1.1)
   - **Is Active:** Yes
   - **Priority:** 10

4. Click **Save**

5. **Record the ACL ID**
   - ACL Rule 1 ID: ________

---

### Rule 2: VC View Clients Assigned to Current VC

1. Go to: **CiviCRM → Administer → Users and Permissions → Manage ACLs**

2. Click **Add ACL**

3. **Configure ACL:**
   - **Description:** "VC View Clients Assigned to Current VC"
   - **Role:** Select "Volunteer Consultant"
   - **Operation:** View
   - **Type of Data:** A group of contacts
   - **Which group:** Select "Clients Assigned to Current VC" (from Part 1.2)
   - **Is Active:** Yes
   - **Priority:** 10

4. Click **Save**

5. **Record the ACL ID**
   - ACL Rule 2 ID: ________

---

## Part 6: Custom Hook for Dynamic ACL Filtering

> **STATUS: NOT YET IMPLEMENTED.** The `mascode_civicrm_aclGroup` hook described below needs to be created in `mascode.php` to enable dynamic filtering of the "Clients Assigned to Current VC" smart group by the logged-in user.

**What it should do:**
- When a VC accesses CiviCRM, dynamically filter the "Clients Assigned to Current VC" group
- Only show contacts where the current user is assigned as "Case Coordinator"
- Use the group name "Clients_Assigned_to_Current_VC" to find the correct group ID automatically

**Implementation needed in:** `mascode.php` (function `mascode_civicrm_aclGroup`)

Without this hook, the smart group from Part 1.2 will show all contacts with any Case Coordinator, not just the current user's assignments. Parts 1-5 and 7+ still work for the "Open Service Requests" group which doesn't need per-user filtering.

---

## Part 7: Add VC Contacts to ACL Group

Volunteer Consultants must be added to the "Volunteer Consultants (ACL Group)" to receive permissions.

### Option A: Manual Addition

1. Go to: **CiviCRM → Contacts → Manage Groups**

2. Find: "Volunteer Consultants (ACL Group)"

3. Click **Settings** → **Group Members**

4. Click **Add Members to Group**

5. Search for and add VC contacts

6. Click **Add to Group**

---

### Option B: Automatic Sync via CiviRule (Recommended)

**Prerequisites:** CiviRules extension must be installed

1. Go to: **CiviCRM → Administer → Automation → CiviRules**

2. Click **Add Rule**

3. **Configure Rule:**
   - **Label:** "Auto-Add WordPress Subscribers to VC ACL Group"
   - **Description:** "Automatically adds WordPress users with Subscriber role to the VC ACL Group"
   - **Trigger:** Select "Daily trigger for Contact"
   - **Is Active:** Yes

4. Click **Save**

5. **Add Condition:**
   - Click **Add Condition**
   - **Condition Type:** "WordPress User has Role"
   - **WordPress Role:** "Subscriber"
   - Click **Save**

6. **Add Action:**
   - Click **Add Action**
   - **Action Type:** "Add contact to group"
   - **Group:** Select "Volunteer Consultants (ACL Group)"
   - Click **Save**

7. **Enable the Rule:**
   - Ensure the rule status is **Enabled**

**Alternative Triggers:**
- "WordPress User is created" - For new users only
- "WordPress User role is changed" - For role changes

---

## Part 8: Configure WordPress Permissions

### Step 8.1: Grant CiviCRM Permissions to Subscriber Role

1. Go to: **WordPress Admin → CiviCRM → Administer → Users and Permissions → Permissions (WordPress)**

2. Find the **Subscriber** column

3. **Check these permissions:**
   - ✅ `access CiviCRM`
   - ✅ `access all cases and activities`
   - ✅ `view all contacts`

4. **Optional permissions** (add if VCs need to take actions):
   - `add contacts` - If VCs should create contacts
   - `edit all contacts` - If VCs should edit contact data (still restricted by ACLs)
   - `add cases` - If VCs should create cases

5. Click **Save**

---

### Step 8.2: Allow Subscribers to Access WordPress Admin for CiviCRM

By default, Subscribers cannot access `/wp-admin/`. Add this code to grant access for CiviCRM pages only.

**Location:** Add to `/wp-content/plugins/maswpcode/maswpcode.php` at the end of the file

```php
/**
 * Allow Subscribers to access CiviCRM admin pages
 */
function mas_allow_subscriber_civicrm_admin_access() {
    $user = wp_get_current_user();

    // Only for subscribers
    if (!in_array('subscriber', (array) $user->roles)) {
        return;
    }

    // Only allow CiviCRM pages
    if (!isset($_GET['page']) || $_GET['page'] !== 'CiviCRM') {
        return;
    }

    // Grant read capability to access admin area
    add_filter('user_has_cap', function($allcaps) {
        $allcaps['read'] = true;
        return $allcaps;
    }, 999);
}
add_action('admin_init', 'mas_allow_subscriber_civicrm_admin_access', 1);
```

**Optional - Hide admin bar for Subscribers:**

```php
/**
 * Hide WordPress admin bar for Subscribers
 */
function mas_hide_admin_bar_for_subscribers() {
    $user = wp_get_current_user();
    if (in_array('subscriber', (array) $user->roles)) {
        show_admin_bar(false);
    }
}
add_action('wp', 'mas_hide_admin_bar_for_subscribers');
```

After adding the code:
1. Save the file
2. Test by logging in as a Subscriber and visiting: `/wp-admin/admin.php?page=CiviCRM`

---

## Part 9: Enable ACLs in CiviCRM

**⚠️ CRITICAL:** This step restricts access globally. Test thoroughly before enabling in production.

1. Go to: **CiviCRM → Administer → Users and Permissions → Permissions (Access Control)**

2. **Check:** "Enable Access Control"

3. Click **Save**

**What happens after enabling:**
- Users without ACL permissions will have restricted access
- Administrators should have proper permissions configured
- Test with admin user first to ensure you still have access

---

## Part 10: Create Navigation Menu Items

Create menu items for VCs to easily access their permitted cases.

### Menu Item 1: Service Requests - Sent for Assignment

1. Go to: **CiviCRM → Administer → Customize Data and Screens → Navigation Menu**

2. Click **Add Menu Item**

3. **Configure:**
   - **Label:** "Service Requests - Sent for Assignment"
   - **URL:** `civicrm/group?reset=1&gid=[GROUP_ID_FROM_PART_1.1]&context=search`
   - **Parent:** Select "Cases" or create a custom parent menu
   - **Permission:** `access CiviCRM`
   - **Operator:** OR
   - **Is Active:** Yes
   - **Separator:** No

4. Click **Save**

**Alternative URL (using existing SearchKit display):**
- If you have SearchKit display ID 125: `civicrm/admin/search#/display/125`

---

### Menu Item 2: My Assigned Cases

1. Click **Add Menu Item**

2. **Configure:**
   - **Label:** "My Assigned Cases"
   - **URL:** `civicrm/group?reset=1&gid=[GROUP_ID_FROM_PART_1.2]&context=search`
   - **Parent:** Select "Cases"
   - **Permission:** `access CiviCRM`
   - **Operator:** OR
   - **Is Active:** Yes
   - **Separator:** No

3. Click **Save**

---

## Part 11: Testing

### Test Preparation

1. **Create Test VC Contact** (if you don't have one):
   - Go to: **CiviCRM → Contacts → New Individual**
   - Create contact: "Test VC" with email `test.vc@yourdomain.org`
   - Click **Save**

2. **Create WordPress User:**
   - Go to: **WordPress Admin → Users → Add New**
   - Username: `test.vc@yourdomain.org`
   - Email: `test.vc@yourdomain.org`
   - Role: **Subscriber**
   - Click **Add New User**

3. **Link WordPress User to CiviCRM Contact** (if not automatic):
   - Go to: **CiviCRM → Administer → Users and Permissions → Synchronize Users to Contacts**
   - Click **Synchronize**

4. **Add Test VC to ACL Group:**
   - Go to: **CiviCRM → Contacts → Manage Groups**
   - Find: "Volunteer Consultants (ACL Group)"
   - Click **Settings** → **Group Members** → **Add Members**
   - Add "Test VC"

5. **Create Test Cases:**
   - **Case 1:** Create a Service Request with status "Sent for Assignment"
   - **Case 2:** Create a Project and assign "Test VC" as Case Coordinator (use "Add Case Role")
   - **Case 3:** Create an unrelated case (Test VC should NOT see this)

---

### Test as Subscriber

1. **Log out** as administrator

2. **Log in** as Test VC (`test.vc@yourdomain.org`)

3. **Test Access:**
   - Navigate to: `https://yoursite.com/wp-admin/admin.php?page=CiviCRM`
   - ✅ Should successfully access CiviCRM

4. **Test Menu Items:**
   - Click "Service Requests - Sent for Assignment"
   - Verify: Should see contacts with Case 1
   - Click "My Assigned Cases"
   - Verify: Should see contacts with Case 2 (where Test VC is Case Coordinator)

5. **Test Contact Search:**
   - Go to: **CiviCRM → Search → Find Contacts**
   - Search for all contacts
   - Verify: Should ONLY see contacts from permitted groups
   - Verify: Should NOT see unrelated contacts (Case 3 client)

6. **Test Case Access:**
   - Click on a permitted contact
   - Go to their **Cases** tab
   - Verify: Can view all case details, activities, relationships

---

### Troubleshooting

**Problem:** VC cannot access `/wp-admin/admin.php?page=CiviCRM`
- **Solution:** Verify WordPress admin access code is added (Part 8.2)
- **Solution:** Check WordPress permissions are granted (Part 8.1)

**Problem:** VC sees all contacts (not restricted)
- **Solution:** Verify ACLs are enabled (Part 9)
- **Solution:** Check ACL rules are active: **CiviCRM → Administer → Users and Permissions → Manage ACLs**
- **Solution:** Clear cache: `cv flush` or via UI

**Problem:** VC sees NO contacts
- **Solution:** Verify VC is in "Volunteer Consultants (ACL Group)" (Part 7)
- **Solution:** Check ACL Entity Role assignment exists (Part 4)
- **Solution:** Verify smart groups have members: **CiviCRM → Contacts → Manage Groups**

**Problem:** "My Assigned Cases" shows all cases with Case Coordinators, not just mine
- **Solution:** Verify the smart group name is exactly "Clients_Assigned_to_Current_VC" (Part 1.2)
- **Solution:** Clear cache: `cv flush`
- **Solution:** Check relationship: Ensure Test VC has "Case Coordinator" role on cases
- **Solution:** Check CiviCRM logs for any hook errors: `/path/to/civicrm/ConfigAndLog/`

**Problem:** Permission denied errors
- **Solution:** Check CiviCRM logs: `/path/to/civicrm/ConfigAndLog/`
- **Solution:** Verify WordPress user is properly linked to CiviCRM contact
- **Solution:** Ensure ACL rules have correct group IDs

---

## Production Deployment Checklist

Before deploying to production:

- [ ] Backup production database
- [ ] Document all development IDs (groups, ACLs, etc.)
- [ ] Create Smart Groups in production (Part 1)
- [ ] Create ACL Group in production (Part 2)
- [ ] Verify ACL Role exists (Part 3)
- [ ] Assign ACL Role to ACL Group (Part 4)
- [ ] Create ACL Rules (Part 5)
- [ ] Verify hook is in mascode.php (Part 6 - already implemented, no config needed)
- [ ] Add WordPress admin access code (Part 8.2)
- [ ] Configure WordPress permissions (Part 8.1)
- [ ] Create CiviRule for auto-sync (Part 7, Option B)
- [ ] Add existing VCs to ACL Group (Part 7)
- [ ] Create navigation menu items (Part 10)
- [ ] **TEST with a real VC user before enabling ACLs**
- [ ] Enable ACLs (Part 9)
- [ ] Final testing with multiple VC users
- [ ] Document production resource IDs

---

## Resource IDs Reference Sheet

Fill this in as you complete each part:

**Development:**
- Smart Group (Open Service Requests) ID: ________
- Smart Group (Assigned to Current VC) ID: ________
- ACL Group (VC_ACL_Group) ID: ________
- ACL Role (Volunteer Consultant) Value: ________
- ACL Entity Role Assignment ID: ________
- ACL Rule 1 (View Open SR) ID: ________
- ACL Rule 2 (View Assigned) ID: ________

**Production:**
- Smart Group (Open Service Requests) ID: ________
- Smart Group (Assigned to Current VC) ID: ________
- ACL Group (VC_ACL_Group) ID: ________
- ACL Role (Volunteer Consultant) Value: ________
- ACL Entity Role Assignment ID: ________
- ACL Rule 1 (View Open SR) ID: ________
- ACL Rule 2 (View Assigned) ID: ________

---

## Maintenance

### Adding New VCs

**If using CiviRule auto-sync:**
1. Create WordPress user with "Subscriber" role
2. CiviRule automatically adds them to ACL group

**If using manual process:**
1. Create WordPress user with "Subscriber" role
2. Add CiviCRM contact to "Volunteer Consultants (ACL Group)"

### Removing VCs

1. Change WordPress role from "Subscriber"
2. Remove from "Volunteer Consultants (ACL Group)"

### Modifying Visible Contacts

To change what VCs can see:
1. Edit the smart group's saved search (Part 1)
2. Adjust WHERE clauses
3. Clear cache: `cv flush`

---

## Support Resources

- **CiviCRM ACL Documentation:** https://docs.civicrm.org/user/en/latest/initial-set-up/permissions-and-access-control/
- **CiviRules Documentation:** https://docs.civicrm.org/civirules/en/latest/
- **WordPress Roles & Capabilities:** https://wordpress.org/documentation/article/roles-and-capabilities/
- **SearchKit Documentation:** https://docs.civicrm.org/user/en/latest/search/searchkit/

---

**Last Updated:** 2025-01-11
**Version:** 2.0 (Manual Configuration)
**Author:** Brian Flett
