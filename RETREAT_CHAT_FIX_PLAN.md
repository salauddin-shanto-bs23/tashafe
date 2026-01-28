# Retreat Chat "Not Available" Fix - Implementation Plan

## Problem Analysis

**Issue**: Retreat group chats showing "Chat is not available" message
- Affected groups: `/groups/female-retreat/`, `/groups/male-retreat/`, `/groups/teen-retreat/`
- Root cause: Therapy group chat activation/expiry logic being applied to retreat groups

## Current Behavior

The therapy chat system (in `snippets/Therapy_group_admin_dashboard.php`) has 4 enforcement points that apply to ALL BuddyPress groups:

1. **Frontend UI Lock** (`tbc_enqueue_bm_chat_lock_assets()`)
   - Adds JavaScript that hides message composer
   - Shows "Chat is not available" banner
   - Checks chat state and locks UI if not active

2. **Message Send Filter** (`tbc_enforce_chat_activation_expiry_on_send()`)
   - Hooks into `messages_message_before_send`
   - Returns WP_Error if chat is locked
   - Checks session dates from group meta

3. **REST API Filter** (`tbc_block_bm_rest_send_when_chat_locked()`)
   - Hooks into `rest_pre_dispatch`
   - Blocks Better Messages REST API sends
   - Returns 403 error if chat locked

4. **State Checker** (`tbc_get_chat_state_for_bp_group()`)
   - Reads `_tbc_session_start_date` and `_tbc_session_expiry_date` meta
   - Returns: 'active', 'expired', 'before_start', or 'missing_dates'
   - Retreat groups have no such meta, so returns 'missing_dates'

## Solution Design

### Create Helper Function
```php
/**
 * Check if a BuddyPress group is a retreat group
 * Retreat groups should NEVER have chat restrictions
 */
function tbc_is_retreat_group($bp_group_id) {
    $retreat_slugs = ['female-retreat', 'male-retreat', 'teen-retreat'];
    
    if (function_exists('groups_get_group')) {
        $group = groups_get_group($bp_group_id);
        if ($group && isset($group->slug)) {
            return in_array($group->slug, $retreat_slugs, true);
        }
    }
    
    return false;
}
```

### Modify 4 Functions

**1. Frontend UI Lock**
```php
function tbc_enqueue_bm_chat_lock_assets() {
    // ... existing checks ...
    
    $bp_group_id = intval(bp_get_current_group_id());
    
    // SKIP retreat groups - they should ALWAYS be available
    if (tbc_is_retreat_group($bp_group_id)) {
        return;
    }
    
    // ... rest of function ...
}
```

**2. Message Send Filter**
```php
function tbc_enforce_chat_activation_expiry_on_send($args) {
    // ... get bp_group_id ...
    
    // SKIP retreat groups
    if (tbc_is_retreat_group($bp_group_id)) {
        return $args;
    }
    
    // ... rest of function ...
}
```

**3. REST API Filter**
```php
function tbc_block_bm_rest_send_when_chat_locked($result, $server, $request) {
    // ... get bp_group_id ...
    
    // SKIP retreat groups
    if (tbc_is_retreat_group($bp_group_id)) {
        return $result;
    }
    
    // ... rest of function ...
}
```

**4. State Checker**
```php
function tbc_get_chat_state_for_bp_group($bp_group_id) {
    // SKIP retreat groups - always active
    if (tbc_is_retreat_group($bp_group_id)) {
        return 'active';
    }
    
    // ... rest of function ...
}
```

## Risk Assessment

### Low Risk ✅
- **Scope**: Only affects 3 specific retreat groups by slug
- **Isolation**: Early returns prevent any logic modification
- **Backwards Compatible**: Therapy groups unaffected
- **Defensive**: Helper function has null checks

### Medium Risk ⚠️
- **Slug Dependency**: Relies on exact slug matching
  - Risk: If retreat group slugs change, code won't recognize them
  - Mitigation: Slugs are unlikely to change; add logging if needed

### Edge Cases Handled
- BuddyPress not loaded: `groups_get_group()` check
- Invalid group ID: Returns false safely
- Group object missing slug: Returns false

## Implementation Steps

1. Add `tbc_is_retreat_group()` helper function before existing functions
2. Add retreat check in `tbc_enqueue_bm_chat_lock_assets()` (line ~3869)
3. Add retreat check in `tbc_enforce_chat_activation_expiry_on_send()` (line ~4135)
4. Add retreat check in `tbc_get_chat_state_for_bp_group()` (line ~4157)
5. Add retreat check in `tbc_block_bm_rest_send_when_chat_locked()` (line ~4391)

## Testing Checklist

After implementation:
- [ ] Visit `/groups/female-retreat/bp-messages/` - composer should be visible
- [ ] Visit `/groups/male-retreat/bp-messages/` - composer should be visible
- [ ] Visit `/groups/teen-retreat/bp-messages/` - composer should be visible
- [ ] Send a test message in each retreat group - should work
- [ ] Visit a therapy group chat - should still show lock if not active
- [ ] Check browser console for JavaScript errors
- [ ] Check WordPress debug.log for PHP errors

## Rollback Plan

If issues occur:
1. Remove the 5-line change blocks (early returns)
2. Keep helper function (harmless if unused)
3. Therapy functionality remains intact

## Changes Summary

**Files Modified**: 1
- `snippets/Therapy_group_admin_dashboard.php`

**Lines Added**: ~25 (5 per function + helper)
**Lines Modified**: 0 (only additions)
**Breaking Changes**: None
