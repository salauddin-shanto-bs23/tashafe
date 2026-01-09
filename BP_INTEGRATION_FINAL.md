# BuddyPress Integration - Final Implementation

## What Was Changed

### 1. **Moved BP Group Creation to `Therapy_group_admin_dashboard.php`**
   - Function: `create_buddypress_group_for_therapy()`
   - Called when creating therapy groups via admin dashboard
   - Creates BP group with proper description including expiry date

### 2. **Kept User Enrollment in `User_Registration.php`**
   - Function: `enroll_user_to_bp_chat_group()`
   - Called immediately after therapy group assignment
   - Enrolls user into existing BP group

### 3. **Added Debugging Shortcodes**
   - `[debug_bp_enrollment]` - Check user enrollment status
   - `[debug_bp_enrollment user_id="123"]` - Check specific user

### 4. **Removed Dependency on `therapy-buddypress-automation.php`**
   - All BP logic now in relevant files
   - No need for the automation snippet

---

## How to Test

### Step 1: Create a Therapy Group

1. Go to **Therapy Group Dashboard** in WordPress admin
2. Click "Create New Sub Group"
3. Fill in all fields including:
   - Issue Type (e.g., anxiety)
   - Gender (e.g., female)
   - Session Start Date
   - **Session Expiry Date** (e.g., 2026-01-31)
   - Max Members
4. Click "Create Group"

**Expected Result:**
- Therapy group created
- BuddyPress group created automatically
- BP group description shows expiry date

### Step 2: Register a New User

1. Go to registration page
2. Fill in registration form with:
   - Concern Type: anxiety
   - Gender: female
3. Complete registration

**Expected Result:**
- User assigned to therapy group
- User enrolled in BP group automatically

### Step 3: Debug Enrollment

Create a page and add this shortcode:

```
[debug_bp_enrollment]
```

Or check a specific user:

```
[debug_bp_enrollment user_id="123"]
```

**What You'll See:**
1. ✓ Therapy group assignment status
2. ✓ BP group exists check
3. ✓ User enrollment status
4. Quick fix button (for admins)

---

## Troubleshooting

### Issue: User not enrolled after registration

**Debug Steps:**

1. **Add shortcode to a page:**
   ```
   [debug_bp_enrollment user_id="USER_ID"]
   ```

2. **Check each section:**
   - ❌ "User NOT assigned to therapy group" → Check `assign_user_to_active_group()` function
   - ❌ "No BP group created" → Create BP group via admin dashboard
   - ❌ "User NOT ENROLLED" → Use "Manually Enroll" button or check BP permissions

### Issue: BP group not created during therapy group creation

**Solutions:**

1. **Verify BuddyPress is active:**
   - Go to Plugins → Check BuddyPress is activated
   - Go to Settings → BuddyPress → Components
   - Enable "User Groups"

2. **Check function exists:**
   - Add this to functions.php temporarily:
   ```php
   add_action('init', function() {
       if (function_exists('groups_create_group')) {
           error_log('BP group functions available');
       } else {
           error_log('BP group functions NOT available');
       }
   });
   ```

3. **Manually create missing BP groups:**
   - Edit existing therapy group
   - Save it again (this will trigger BP group creation)

---

## Database References

### User Meta
| Key | Description |
|-----|-------------|
| `assigned_group` | Therapy group post ID |
| `_tbc_bp_group_id` | BuddyPress group ID |
| `_tbc_enrollment_date` | When user was enrolled |
| `concern_type` | User's issue type |
| `gender` | User's gender |

### Post Meta (therapy_group)
| Key | Description |
|-----|-------------|
| `_tbc_bp_group_id` | Associated BP group ID |
| `issue_type` | Group's issue type |
| `gender` | Group's gender |
| `session_expiry_date` | Expiry date |
| `max_members` | Maximum capacity |

### BP Group Meta
| Key | Description |
|-----|-------------|
| `_tbc_therapy_group_id` | Associated therapy group ID |
| `_tbc_expiry_date` | Expiry date |
| `_tbc_status` | active/expired |

---

## Manual Enrollment (If Needed)

If automatic enrollment fails, use the debugging shortcode:

1. Create a page with: `[debug_bp_enrollment user_id="123"]`
2. Visit the page as admin
3. Click "Manually Enroll User Now" button

Or via code:

```php
$user_id = 123;
$therapy_group_id = 456;
$bp_group_id = get_post_meta($therapy_group_id, '_tbc_bp_group_id', true);

if ($bp_group_id && function_exists('groups_join_group')) {
    groups_join_group($bp_group_id, $user_id);
    update_user_meta($user_id, '_tbc_bp_group_id', $bp_group_id);
    update_user_meta($user_id, '_tbc_enrollment_date', current_time('mysql'));
}
```

---

## Files Modified

1. **`snippets/Therapy_group_admin_dashboard.php`**
   - Added `create_buddypress_group_for_therapy()` function
   - Creates BP groups when therapy groups are created

2. **`snippets/User_Registration.php`**
   - Added `enroll_user_to_bp_chat_group()` function
   - Added `debug_bp_enrollment_shortcode()` for debugging
   - Enrolls users during registration

3. **`therapy_chatting/therapy-buddypress-automation.php`**
   - Can now be disabled/removed
   - All functionality moved to appropriate files

---

## Next Steps

1. **Test group creation:** Create a new therapy group and verify BP group is created
2. **Test user registration:** Register a new user and check enrollment
3. **Use debugging shortcode:** Add `[debug_bp_enrollment]` to a page and test
4. **Remove automation file:** Once confirmed working, deactivate `therapy-buddypress-automation.php`

---

## Support

If enrollment still fails after following all steps:

1. Use the `[debug_bp_enrollment]` shortcode to identify the exact issue
2. Check all sections show green checkmarks
3. Use the "Manual Enroll" button for immediate fix
4. Check BuddyPress settings and permissions
