# Error Fixes - March 6, 2026

## Errors Identified

### ✅ Error 1: rawurlencode() Deprecation (FIXED)
**Location**: payment_integration.php  
**Error**: `rawurlencode(): Passing null to parameter #1 ($string) of type string is deprecated`  
**Cause**: PHP 8.1+ stricter type checking when building query strings  
**Fix Applied**: Modified `tanafs_aps_initiate_payment()` to filter out empty query parameters before calling `add_query_arg()`

**Changed Code** (lines 695-710):
```php
// Build return URL
$return_url = $options['return_url'] ?? home_url('/payment-return/');

// Only add non-empty query parameters to avoid rawurlencode() null deprecation in PHP 8.1+
$query_args = [];
if (!empty($booking_token)) {
    $query_args['booking_token'] = sanitize_text_field($booking_token);
}
if (!empty($booking_type)) {
    $query_args['booking_type'] = sanitize_text_field($booking_type);
}

if (!empty($query_args)) {
    $return_url = add_query_arg($query_args, $return_url);
}
```

---

### ⚠️ Error 2: Duplicate Function Declaration - render_subgroups_by_issue_and_gender()
**Error**: `Cannot redeclare render_subgroups_by_issue_and_gender()`  
**Affected Snippet**: User Registration (#2918)  

**Root Cause**: You have MULTIPLE snippets active that both declare this function:
- Therapy_group_admin_dashboard.php
- Another snippet (possibly an old backup or duplicate)

**Solution**:
1. Go to your code snippets plugin admin panel
2. Search for all snippets containing "render_subgroups_by_issue_and_gender"
3. You should find:
   - ✅ **Keep**: Therapy_group_admin_dashboard.php (the current one)
   - ❌ **Deactivate**: Any duplicate or old backup snippet

**How to Check**:
```bash
# In WordPress admin, go to:
Snippets → All Snippets → Search: "render_subgroups"

# Look for duplicates like:
- "Therapy Group Dashboard" (keep this)
- "Therapy Group Dashboard - Backup" (deactivate this)
- "Old User Registration" (deactivate this)
```

**Alternative Quick Fix** (if you can't find the duplicate):
The function is already wrapped in `if (!function_exists())`, so the error suggests snippet loading order issues. Try:
1. Deactivate ALL therapy-related snippets
2. Re-activate them ONE AT A TIME in this order:
   - User_Registration.php first
   - Therapy_group_admin_dashboard.php second

---

### ⚠️ Error 3: Duplicate Function Declaration - process_academy_booking_from_ipn()
**Error**: `Cannot redeclare process_academy_booking_from_ipn()` declared at line 1391 and line 8990  
**Affected Snippet**: tanafs_academy.php  

**Root Cause**: You have TWO versions of tanafs_academy.php active as snippets:
- Version 1 (old): Declares the function at one location
- Version 2 (new): Declares the function at a different location

**Solution**:
1. Go to: **Snippets → All Snippets**
2. Search for: "academy" or "tanafs_academy"
3. You'll likely see:
   - ✅ **Tanafs Academy** (Snippet #XXXX) - 2,900 lines
   - ❌ **Tanafs Academy OLD** or **Tanafs Academy - Backup** or just a duplicate with different ID

4. **IMPORTANT**: Check the snippet IDs carefully:
   - Only keep the MOST RECENT version
   - Deactivate/delete any old backups

**How to Identify the Correct Version**:
Open each academy snippet and search for this text:
```php
// IPN FULFILLMENT WRAPPER FOR APS PAYMENT CALLBACK
```

- ✅ **Keep** the snippet that has this comment (with "WRAPPER FOR APS")
- ❌ **Deactivate** the snippet that doesn't have this (old PayTabs version)

**File Structure Check**:
The CORRECT tanafs_academy.php should have:
- `process_academy_booking_from_ipn()` - NEW APS wrapper (around line 2877)
- `academy_paytabs_handle_callback()` - OLD PayTabs handler (around line 2930)
- Both functions should coexist temporarily during migration

If you have TWO separate files with the same function, one must be deactivated.

---

## Action Plan

### Immediate Steps:

1. **Fix Error 1** (Already Done ✅)
   - payment_integration.php updated automatically
   - No action needed from you

2. **Fix Error 2** (Manual Action Required)
   ```
   Step 1: WordPress Admin → Snippets → All Snippets
   Step 2: Filter by "User Registration" or search "render_subgroups"
   Step 3: Identify duplicate snippets
   Step 4: Deactivate the older/backup version
   Step 5: Keep only ONE active snippet for User_Registration.php
   ```

3. **Fix Error 3** (Manual Action Required)
   ```
   Step 1: WordPress Admin → Snippets → All Snippets
   Step 2: Search for "academy" or "tanafs_academy"
   Step 3: Check how many snippets you have with "academy" in the name
   Step 4: Open each one and check for "process_academy_booking_from_ipn"
   Step 5: Keep ONLY the snippet that has both:
           - process_academy_booking_from_ipn (APS wrapper)
           - academy_paytabs_handle_callback (old PayTabs handler)
   Step 6: Deactivate/delete any duplicate academy snippets
   ```

---

## Verification Steps

After deactivating duplicates:

### Test 1: All Snippets Load Without Errors
```
1. Go to WordPress Admin → Snippets
2. Activate snippets in this order:
   a. payment_integration.php
   b. User_Registration.php
   c. Therapy_group_admin_dashboard.php
   d. retreat_system.php
   e. tanafs_academy.php
   
3. Check for error messages after each activation
4. If any snippet shows "Snippet has not been activated due to an error":
   - Click "View Snippets With Errors"
   - Copy the full error message
   - Report back for further diagnosis
```

### Test 2: Admin Pages Load Correctly
```
1. Visit: WordPress Admin → Payment Integration → All Payments
   - Should load without rawurlencode() deprecation warning
   - Payment list should display correctly

2. Visit: WordPress Admin → Therapy Group Dashboard
   - Should load without render_subgroups error
   - All tabs (Anxiety, Depression, Grief, Relationship) should display

3. Visit: WordPress Admin → Academy Dashboard
   - Should load without process_academy_booking_from_ipn error
   - Programs and registrations should display
```

### Test 3: Error Log Check
```
1. Enable WordPress debug logging (if not already enabled):
   Add to wp-config.php:
   define('WP_DEBUG', true);
   define('WP_DEBUG_LOG', true);
   define('WP_DEBUG_DISPLAY', false);

2. Monitor: wp-content/debug.log
3. Look for these patterns:
   ✅ GOOD: No "Cannot redeclare" errors
   ✅ GOOD: No "rawurlencode(): Passing null" warnings
   ✅ GOOD: Payment initiation logs show correct data
   ❌ BAD: Any "Fatal error" messages
   ❌ BAD: Any "deprecated" warnings
```

---

## Common Causes of Duplicate Snippets

### Scenario 1: Multiple Snippet IDs for Same File
You may have accidentally:
- Created a new snippet for the same file multiple times
- Imported the same snippet from different sources
- Restored from a backup without deleting the current version

**Check**: Look for snippet IDs like #2918, #2919, #2920 with similar names

### Scenario 2: Old Backup Snippets Still Active
During previous updates, you may have:
- Created a backup copy before editing
- Forgotten to delete the old version
- Left both "Therapy Dashboard" and "Therapy Dashboard OLD" active

**Check**: Sort snippets by "Last Modified" dateto find old versions

### Scenario 3: Plugin Import/Export
If you've used the snippet plugin's import/export feature:
- The import may have created duplicates
- Old snippets weren't removed before importing new ones

**Check**: Look in the snippets list for entries with "(Imported)" in the name

---

## Expected Snippet Count

You should have exactly **5 active snippets** for Tanafs:
1. ✅ payment_integration.php (~1,300 lines)
2. ✅ User_Registration.php (~2,450 lines)
3. ✅ Therapy_group_admin_dashboard.php (~3,500 lines)
4. ✅ retreat_system.php (~4,400 lines)
5. ✅ tanafs_academy.php (~3,000 lines)

**Total**: 5 snippets

If you have MORE than 5, you have duplicates that need to be removed.

---

## Error Prevention Going Forward

### Best Practices:
1. **Before activating a new snippet version**:
   - Deactivate the old version first
   - Then delete the old snippet
   - Then activate the new one

2. **Use clear naming conventions**:
   - Good: "Tanafs Payment Integration (2026-03-06)"
   - Bad: "payment snippet", "new payment", "payment copy"

3. **Document snippet IDs**:
   Keep a note of your active snippet IDs:
   ```
   payment_integration.php = Snippet #XXXX
   User_Registration.php = Snippet #XXXX
   Therapy_group_admin_dashboard.php = Snippet #XXXX
   retreat_system.php = Snippet #XXXX
   tanafs_academy.php = Snippet #XXXX
   ```

4. **Use version comments**:
   Add this at the top of each snippet:
   ```php
   <?php
   /**
    * Version: 2.0.0 (APS Migration)
    * Last Updated: 2026-03-06
    * Description: [snippet purpose]
    */
   ```

---

## Need Help?

If you still see errors after following these steps:

### Provide This Information:
1. **Full error message** from the snippet plugin
2. **List of all active snippets** (name + ID):
   ```
   Example:
   - "Tanafs Payment Integration" (ID: #2915)
   - "User Registration" (ID: #2918)
   - etc.
   ```
3. **PHP version**: Check in WordPress Admin → Site Health → Info → Server
4. **WordPress version**: Check in Dashboard → Updates
5. **Snippet plugin**: Name and version (e.g., "Code Snippets 3.6.0")

### Debug Command:
Run this MySQL query in phpMyAdmin or WordPress Database plugin:
```sql
SELECT option_name, LENGTH(option_value) as size
FROM wp_options
WHERE option_name LIKE 'wpcode_snippet_%'
ORDER BY option_name;
```
This will show all snippet database entries and their sizes, helping identify duplicates.

---

**Status**: 1/3 errors fixed automatically, 2 errors require manual deactivation of duplicate snippets  
**Next Step**: Check snippet admin panel for duplicates and deactivate them  
**Priority**: HIGH - These errors prevent snippets from loading
