# Retreat Registration Diagnostic Report

## Issue Summary
**Problem**: Users complete payment successfully, questionnaire modals open correctly, but users are NOT appearing in the retreat's "Registered Participants" list.

**Symptom**: The retreat booking/registration is not being completed despite successful payment and UI flow.

---

## Data Flow Analysis

### Step 1: Form Submission (Frontend)
**File**: `retreat/retreat_system.php` (Lines 2833-2869)

**What happens**:
1. User fills Personal Information form (`#retreat-register-form`)
2. Form includes hidden fields:
   - `name="retreat_type"` (id: `reg_retreat_type`)  
   - `name="group_id"` (id: `reg_group_id`) ← **CRITICAL**
   - `name="amount"` (id: `reg_amount`)
3. Hidden fields are populated when "Book Your Spot" is clicked (Line 2689):
   ```javascript
   $('#reg_group_id').val(selectedGroupId || window.selectedGroupId);
   ```
4. Form is submitted via AJAX using `FormData`:
   ```javascript
   const formData = new FormData(this);
   formData.append('action', 'save_retreat_booking_data');
   ```

**Potential Issue #1**: `FormData(this)` should capture all form fields including hidden inputs. But is `group_id` actually populated with a valid value?

---

### Step 2: Save Booking Data (Backend)
**File**: `retreat_paytabs_integration.php` (Lines 328-388)

**What happens**:
1. AJAX handler `ajax_save_retreat_booking_data()` receives POST data
2. Extracts `group_id` (Line 354):
   ```php
   'group_id' => intval($_POST['group_id'] ?? 0),
   ```
3. Stores all data in transient with 1-hour expiry:
   ```php
   set_transient('retreat_' . $booking_token, $booking_data, 3600);
   ```

**Potential Issue #2**: If `$_POST['group_id']` is empty or 0, it will be stored as 0. This would cause the group enrollment to fail later.

**Diagnostic needed**: Log the value of `$_POST['group_id']` to confirm it's being received.

---

### Step 3: Payment Redirect & Callback
**File**: `retreat_paytabs_integration.php`

**What happens**:
1. PayTabs payment is initiated with stored booking data
2. User pays on PayTabs
3. PayTabs calls callback URL (updates transient to 'completed')
4. User returns to site with `?payment_return={token}`

**Potential Issue #3**: Transient data expires after 1 hour. If payment takes too long or callback is delayed, data could be lost.

---

### Step 4: Payment Verification (Return Flow)
**File**: `retreat_paytabs_integration.php` (Lines 461-531)

**What happens**:
1. Frontend calls `verify_retreat_payment_status` via AJAX
2. Backend retrieves transient data:
   ```php
   $booking_data = get_transient('retreat_' . $booking_token);
   ```
3. Checks payment status and returns success

**Potential Issue #4**: Transient data is retrieved successfully at this point (modals are opening), but is `group_id` still in the data?

---

### Step 5: Complete Registration (Backend) ← **FAILURE POINT**
**File**: `retreat_paytabs_integration.php` (Lines 543-679)

**What happens**:
1. User fills questionnaire
2. Frontend calls `complete_retreat_registration` with questionnaire answers
3. Backend retrieves transient data again:
   ```php
   $booking_data = get_transient('retreat_' . $booking_token);
   ```
4. Creates WordPress user account (Line 607-618)
5. **CRITICAL STEP** - Enrolls user in BuddyPress group (Lines 646-648):
   ```php
   if (!empty($booking_data['group_id']) && function_exists('groups_join_group')) {
       groups_join_group($booking_data['group_id'], $user_id);
   }
   ```
6. Sends confirmation email
7. Deletes transient

**Potential Issues**:
- **Issue #5**: `$booking_data['group_id']` is 0 or empty
- **Issue #6**: `groups_join_group()` function doesn't exist (BuddyPress not loaded)
- **Issue #7**: `groups_join_group()` is called but silently fails
- **Issue #8**: User is created but group enrollment fails with no error handling

---

## ROOT CAUSE IDENTIFIED ✅

**The Issue**: The retreat system uses a custom retreat registration system (NOT BuddyPress groups), but the registration completion code is trying to use BuddyPress's `groups_join_group()` function.

**How Retreat Membership Works**:
- Retreats are stored as custom post type `retreat_group`  
- Members are tracked using user metadata: `assigned_retreat_group`
- Member count is determined by querying users with this meta key

**The Bug**:
```php
// WRONG - This code tries to use BuddyPress group enrollment
if (!empty($booking_data['group_id']) && function_exists('groups_join_group')) {
    groups_join_group($booking_data['group_id'], $user_id);
}
```

**The Fix**:
```php
// CORRECT - Use user metadata to register for retreat
if (!empty($booking_data['group_id'])) {
    update_user_meta($user_id, 'assigned_retreat_group', $booking_data['group_id']);
}
```

**Evidence**:
- File: `retreat_system.php`, Line 108-122
- Function: `count_retreat_group_members($group_id)`
- Queries: `get_users(['meta_key' => 'assigned_retreat_group', 'meta_value' => $group_id])`

---

## Root Cause Hypotheses

### Hypothesis A: group_id is 0 or empty
**Why**: 
- Frontend doesn't populate `#reg_group_id` correctly
- `window.selectedGroupId` is null/undefined
- Schedule selection isn't capturing group_id

**Test**:
```javascript
// Add console logs in retreat_system.php (Line 2689)
console.log('Selected Group ID:', selectedGroupId, window.selectedGroupId);
console.log('Hidden field value:', $('#reg_group_id').val());
```

### Hypothesis B: Transient data loses group_id
**Why**: 
- group_id is saved as 0 because `intval($empty)` returns 0
- Transient is corrupted or overwritten

**Test**:
```php
// Add logging in ajax_save_retreat_booking_data (after Line 354)
error_log('Received group_id: ' . print_r($_POST['group_id'], true));
error_log('Parsed group_id: ' . $booking_data['group_id']);
```

### Hypothesis C: groups_join_group() fails silently
**Why**:
- BuddyPress function exists but fails
- Invalid group_id
- Permission issues
- User already in group

**Test**:
```php
// Replace Lines 646-648 in retreat_paytabs_integration.php
if (!empty($booking_data['group_id'])) {
    if (!function_exists('groups_join_group')) {
        error_log('RETREAT ERROR: groups_join_group function not available');
    } else {
        $result = groups_join_group($booking_data['group_id'], $user_id);
        if (!$result) {
            error_log('RETREAT ERROR: groups_join_group failed for user ' . $user_id . ' in group ' . $booking_data['group_id']);
        } else {
            error_log('RETREAT SUCCESS: User ' . $user_id . ' enrolled in group ' . $booking_data['group_id']);
        }
    }
} else {
    error_log('RETREAT ERROR: group_id is empty in booking_data');
    error_log('Booking data: ' . print_r($booking_data, true));
}
```

---

## Recommended Debugging Steps

### Phase 1: Frontend Verification
1. Add console logs to verify `group_id` is populated in hidden field
2. Add console logs to verify FormData includes `group_id`
3. Check browser Network tab to confirm `group_id` is sent in POST

### Phase 2: Backend Verification  
1. Add error_log statements in `ajax_save_retreat_booking_data()` to log received `group_id`
2. Add error_log statements in `ajax_complete_retreat_registration()` to log:
   - Retrieved transient data
   - group_id value
   - Result of `groups_join_group()`

### Phase 3: BuddyPress Verification
1. Verify BuddyPress is active and loaded
2. Test `groups_join_group()` function manually with known user/group IDs
3. Check if group_id corresponds to an actual existing group

---

## Quick Fix Implementation

Add comprehensive logging to identify the exact failure point:

### 1. Frontend Logging
```javascript
// In retreat_system.php, before form submission (Line 2833)
console.log('=== FORM SUBMISSION DEBUG ===');
console.log('retreat_type:', $('#reg_retreat_type').val());
console.log('group_id:', $('#reg_group_id').val());
console.log('amount:', $('#reg_amount').val());
console.log('window.selectedGroupId:', window.selectedGroupId);
```

### 2. Backend Logging (Save)
```php
// In retreat_paytabs_integration.php, in ajax_save_retreat_booking_data()
error_log('=== SAVE BOOKING DATA ===');
error_log('POST group_id: ' . ($_POST['group_id'] ?? 'NOT SET'));
error_log('Parsed group_id: ' . $booking_data['group_id']);
error_log('Full booking data: ' . print_r($booking_data, true));
```

### 3. Backend Logging (Registration)
```php
// In retreat_paytabs_integration.php, in ajax_complete_retreat_registration()
error_log('=== COMPLETE REGISTRATION ===');
error_log('Token: ' . $booking_token);
error_log('Booking data group_id: ' . ($booking_data['group_id'] ?? 'NOT SET'));
error_log('groups_join_group exists: ' . (function_exists('groups_join_group') ? 'YES' : 'NO'));

if (!empty($booking_data['group_id']) && function_exists('groups_join_group')) {
    $result = groups_join_group($booking_data['group_id'], $user_id);
    error_log('groups_join_group result: ' . ($result ? 'SUCCESS' : 'FAILED'));
    error_log('User ID: ' . $user_id . ', Group ID: ' . $booking_data['group_id']);
}
```

---

## Expected Outcomes

After implementing logging:
1. Check WordPress debug.log file for error messages
2. Check browser console for frontend values
3. Identify which hypothesis is correct
4. Apply targeted fix

---

## SOLUTION IMPLEMENTED ✅

**File**: `retreat_paytabs_integration.php`  
**Function**: `ajax_complete_retreat_registration()`  
**Lines**: ~646-670

**What Was Changed**:

### Before (INCORRECT):
```php
// Enroll user in BuddyPress group if group_id is set
if (!empty($booking_data['group_id']) && function_exists('groups_join_group')) {
    groups_join_group($booking_data['group_id'], $user_id);
}
```

**Problems**:
- Assumes retreat uses BuddyPress groups (it doesn't)
- Silently fails if `groups_join_group()` doesn't exist
- No validation of result
- No error reporting

### After (CORRECT):
```php
// Register user for retreat by setting user metadata
if (empty($booking_data['group_id']) || intval($booking_data['group_id']) === 0) {
    error_log('RETREAT ERROR: group_id is empty or zero!');
    wp_send_json_error(['message' => 'Registration error: No retreat group selected.']);
    return;
}

// Assign user to retreat group using metadata
$update_result = update_user_meta($user_id, 'assigned_retreat_group', $booking_data['group_id']);

if ($update_result === false) {
    error_log('RETREAT ERROR: Failed to assign user to retreat group!');
    wp_send_json_error(['message' => 'Failed to complete retreat registration.']);
    return;
}

error_log('RETREAT SUCCESS: User ' . $user_id . ' assigned to retreat group ' . $booking_data['group_id']);
```

**Benefits**:
- Uses correct retreat registration method (`assigned_retreat_group` user meta)
- Validates group_id before attempting registration
- Checks if update_user_meta succeeds
- Returns proper error messages to user
- Logs success/failure for debugging

---

## Testing Checklist

After implementing this fix, verify:

1. **Frontend Logs** (Browser Console):
   - [ ] `group_id` is populated in hidden field
   - [ ] `group_id` value matches selected retreat ID
   - [ ] Form data includes valid `group_id`

2. **Backend Logs** (WordPress debug.log):
   - [ ] POST data includes correct `group_id`
   - [ ] Booking data stored with valid `group_id`
   - [ ] User registration succeeds with success log message

3. **Database Verification**:
   - [ ] New user created in `wp_users` table
   - [ ] User metadata includes `assigned_retreat_group` with correct group ID
   - [ ] Member count increases when querying `count_retreat_group_members()`

4. **UI Verification**:
   - [ ] User appears in retreat's "Registered Participants" list in admin dashboard
   - [ ] Available spots count decreases by 1
   - [ ] User receives confirmation email
   - [ ] "Thank You for Sharing" modal displays

---

## Expected Outcomes

After successful payment and questionnaire completion:

✅ User account created  
✅ User metadata `assigned_retreat_group` = retreat group ID  
✅ User appears in retreat participant list  
✅ Available spots correctly updated  
✅ Confirmation email sent  

---

## Next Steps

1. **Test Complete Flow**:
   - Select a retreat
   - Fill personal information
   - Complete payment
   - Fill questionnaire
   - Verify user appears in registered participants

2. **Verify Database**:
   ```sql
   SELECT user_id, meta_key, meta_value 
   FROM wp_usermeta 
   WHERE meta_key = 'assigned_retreat_group' 
   ORDER BY umeta_id DESC 
   LIMIT 10;
   ```

3. **Check Logs**:
   - Look for "RETREAT SUCCESS" messages
   - Confirm no "RETREAT ERROR" messages

---

## Next Steps

1. Implement logging in all 3 locations
2. Test complete booking flow
3. Review logs to identify failure point
4. Apply appropriate fix based on findings

