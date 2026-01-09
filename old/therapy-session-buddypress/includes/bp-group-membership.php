<?php
/**
 * BuddyPress Group Membership Functions
 * 
 * Add/remove users from BP groups linked to therapy sessions.
 * This is the single source of truth for chat access.
 * 
 * @package TherapySessionBuddyPress
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Add a user to the BP group for a therapy session
 * 
 * @param int $user_id          WordPress user ID
 * @param int $therapy_group_id The therapy_group post ID
 * @return bool
 */
function tsbp_add_user_to_therapy_group($user_id, $therapy_group_id) {
    
    error_log("[TSBP] tsbp_add_user_to_therapy_group: user={$user_id}, therapy_group={$therapy_group_id}");
    
    if (!$user_id || !$therapy_group_id) {
        error_log("[TSBP] Invalid user_id or therapy_group_id");
        return false;
    }
    
    $user_id = intval($user_id);
    $therapy_group_id = intval($therapy_group_id);
    
    // Get the BP group ID
    $bp_group_id = tsbp_get_bp_group_id($therapy_group_id);
    
    if (!$bp_group_id) {
        error_log("[TSBP] No BP group found for therapy_group {$therapy_group_id}, attempting to create...");
        
        // Auto-create BP group if it doesn't exist
        $post = get_post($therapy_group_id);
        if ($post && $post->post_type === 'therapy_group') {
            $issue_type = get_post_meta($therapy_group_id, 'issue_type', true);
            $gender = get_post_meta($therapy_group_id, 'gender', true);
            
            $description = sprintf(
                'Therapy session group - %s. Issue: %s, Gender: %s',
                $post->post_title,
                ucfirst($issue_type ?: 'General'),
                ucfirst($gender ?: 'All')
            );
            
            $default_days = get_option('tsbp_default_expiry_days', 30);
            $expiry_date = get_post_meta($therapy_group_id, 'session_end_date', true);
            if (!$expiry_date) {
                $expiry_date = date('Y-m-d', strtotime("+{$default_days} days"));
            }
            
            $bp_group_id = tsbp_create_bp_group(
                $therapy_group_id,
                $post->post_title,
                $description,
                $post->post_author,
                $expiry_date
            );
        }
        
        if (!$bp_group_id) {
            error_log("[TSBP] Failed to create BP group for therapy_group {$therapy_group_id}");
            return false;
        }
    }
    
    // Check if BP group is active
    $status = groups_get_groupmeta($bp_group_id, '_tsbp_status', true);
    if ($status === 'expired' || $status === 'archived') {
        error_log("[TSBP] BP group {$bp_group_id} is {$status}, cannot add user");
        return false;
    }
    
    // Check if user is already a member
    if (groups_is_user_member($user_id, $bp_group_id)) {
        error_log("[TSBP] User {$user_id} is already a member of BP group {$bp_group_id}");
        return true;
    }
    
    // Add user to BP group
    $result = groups_join_group($bp_group_id, $user_id);
    
    if ($result) {
        error_log("[TSBP] User {$user_id} added to BP group {$bp_group_id}");
        
        /**
         * Action: After user is added to therapy BP group
         * 
         * @param int $user_id          WordPress user ID
         * @param int $bp_group_id      BuddyPress group ID
         * @param int $therapy_group_id Therapy group post ID
         */
        do_action('tsbp_user_added_to_therapy_group', $user_id, $bp_group_id, $therapy_group_id);
        
        return true;
    }
    
    error_log("[TSBP] Failed to add user {$user_id} to BP group {$bp_group_id}");
    return false;
}

/**
 * Remove a user from the BP group for a therapy session
 * 
 * @param int $user_id          WordPress user ID
 * @param int $therapy_group_id The therapy_group post ID
 * @return bool
 */
function tsbp_remove_user_from_therapy_group($user_id, $therapy_group_id) {
    
    error_log("[TSBP] tsbp_remove_user_from_therapy_group: user={$user_id}, therapy_group={$therapy_group_id}");
    
    $bp_group_id = tsbp_get_bp_group_id($therapy_group_id);
    
    if (!$bp_group_id) {
        error_log("[TSBP] No BP group found for therapy_group {$therapy_group_id}");
        return false;
    }
    
    // Check if user is a member
    if (!groups_is_user_member($user_id, $bp_group_id)) {
        error_log("[TSBP] User {$user_id} is not a member of BP group {$bp_group_id}");
        return true; // Not a member, so "removal" is successful
    }
    
    // Remove user from BP group
    $result = groups_leave_group($bp_group_id, $user_id);
    
    if ($result) {
        error_log("[TSBP] User {$user_id} removed from BP group {$bp_group_id}");
        
        /**
         * Action: After user is removed from therapy BP group
         * 
         * @param int $user_id          WordPress user ID
         * @param int $bp_group_id      BuddyPress group ID
         * @param int $therapy_group_id Therapy group post ID
         */
        do_action('tsbp_user_removed_from_therapy_group', $user_id, $bp_group_id, $therapy_group_id);
        
        return true;
    }
    
    error_log("[TSBP] Failed to remove user {$user_id} from BP group {$bp_group_id}");
    return false;
}

/**
 * Check if user is a member of the therapy session BP group
 * 
 * @param int $user_id          WordPress user ID
 * @param int $therapy_group_id The therapy_group post ID
 * @return bool
 */
function tsbp_is_user_in_therapy_group($user_id, $therapy_group_id) {
    
    $bp_group_id = tsbp_get_bp_group_id($therapy_group_id);
    
    if (!$bp_group_id) {
        return false;
    }
    
    return groups_is_user_member($user_id, $bp_group_id);
}

/**
 * Get all members of a therapy session BP group
 * 
 * @param int $therapy_group_id The therapy_group post ID
 * @return array Array of user IDs
 */
function tsbp_get_therapy_group_members($therapy_group_id) {
    
    $bp_group_id = tsbp_get_bp_group_id($therapy_group_id);
    
    if (!$bp_group_id) {
        return [];
    }
    
    // Get all group members
    $members = groups_get_group_members([
        'group_id'   => $bp_group_id,
        'per_page'   => 9999,
        'page'       => 1,
        'exclude_admins_mods' => false,
    ]);
    
    $user_ids = [];
    
    if (!empty($members['members'])) {
        foreach ($members['members'] as $member) {
            $user_ids[] = $member->ID;
        }
    }
    
    return $user_ids;
}

/**
 * Get member count of a therapy session BP group
 * 
 * @param int $therapy_group_id The therapy_group post ID
 * @return int
 */
function tsbp_get_therapy_group_member_count($therapy_group_id) {
    
    $bp_group_id = tsbp_get_bp_group_id($therapy_group_id);
    
    if (!$bp_group_id) {
        return 0;
    }
    
    return groups_get_total_member_count($bp_group_id);
}

/**
 * Add multiple users to a therapy session BP group
 * 
 * @param array $user_ids        Array of WordPress user IDs
 * @param int   $therapy_group_id The therapy_group post ID
 * @return array Results for each user
 */
function tsbp_add_users_to_therapy_group($user_ids, $therapy_group_id) {
    
    $results = [];
    
    foreach ($user_ids as $user_id) {
        $results[$user_id] = tsbp_add_user_to_therapy_group($user_id, $therapy_group_id);
    }
    
    return $results;
}

/**
 * Sync user's BP group membership when their assigned_group changes
 * This is hooked to user meta updates
 * 
 * @param int    $user_id           WordPress user ID
 * @param int    $new_therapy_group The new therapy_group post ID
 * @param int    $old_therapy_group The old therapy_group post ID (optional)
 * @return bool
 */
function tsbp_sync_user_therapy_group($user_id, $new_therapy_group, $old_therapy_group = null) {
    
    error_log("[TSBP] tsbp_sync_user_therapy_group: user={$user_id}, new={$new_therapy_group}, old={$old_therapy_group}");
    
    // Remove from old group if provided
    if ($old_therapy_group && $old_therapy_group != $new_therapy_group) {
        tsbp_remove_user_from_therapy_group($user_id, $old_therapy_group);
    }
    
    // Add to new group
    if ($new_therapy_group) {
        return tsbp_add_user_to_therapy_group($user_id, $new_therapy_group);
    }
    
    return true;
}

/**
 * ============================================
 * HOOK: Auto-enroll user after Ultimate Member registration
 * This runs AFTER the existing code assigns the therapy group
 * ============================================
 */
add_action('um_registration_complete', 'tsbp_enroll_user_after_um_registration', 25, 2);

function tsbp_enroll_user_after_um_registration($user_id, $args) {
    
    try {
        error_log("[TSBP] um_registration_complete triggered for user {$user_id}");
        
        // Get assigned therapy group (set by existing code at priority 10)
        $therapy_group_id = get_user_meta($user_id, 'assigned_group', true);
        
        if (!$therapy_group_id) {
            error_log("[TSBP] No therapy group assigned to user {$user_id}");
            return;
        }
        
        error_log("[TSBP] User {$user_id} assigned to therapy group {$therapy_group_id}");
        
        // Add user to BP group
        $result = tsbp_add_user_to_therapy_group($user_id, $therapy_group_id);
        
        if ($result) {
            error_log("[TSBP] User {$user_id} successfully added to BP group");
        } else {
            error_log("[TSBP] FAILED to add user {$user_id} to BP group");
        }
    } catch (Exception $e) {
        error_log("[TSBP] Registration hook exception: " . $e->getMessage());
    } catch (Error $e) {
        error_log("[TSBP] Registration hook error: " . $e->getMessage());
    }
}

/**
 * ============================================
 * HOOK: Sync BP group when user's assigned_group changes
 * ============================================
 */
add_action('updated_user_meta', 'tsbp_sync_bp_membership_on_group_change', 10, 4);
add_action('added_user_meta', 'tsbp_sync_bp_membership_on_group_change', 10, 4);

function tsbp_sync_bp_membership_on_group_change($meta_id, $user_id, $meta_key, $meta_value) {
    
    if ($meta_key !== 'assigned_group') {
        return;
    }
    
    if (empty($meta_value)) {
        return;
    }
    
    try {
        $new_therapy_group_id = intval($meta_value);
        
        error_log("[TSBP] assigned_group changed for user {$user_id} to {$new_therapy_group_id}");
        
        // Get old value to remove from old group
        // Note: At this point, the meta has already been updated, so we need another approach
        // We'll store the old value in a static variable during 'update_user_meta' hook
        static $old_values = [];
        
        $old_therapy_group_id = isset($old_values[$user_id]) ? $old_values[$user_id] : null;
        
        // Sync the membership
        tsbp_sync_user_therapy_group($user_id, $new_therapy_group_id, $old_therapy_group_id);
        
        // Clear stored value
        unset($old_values[$user_id]);
        
    } catch (Exception $e) {
        error_log("[TSBP] Group change hook exception: " . $e->getMessage());
    } catch (Error $e) {
        error_log("[TSBP] Group change hook error: " . $e->getMessage());
    }
}

/**
 * Store old assigned_group value before update
 */
add_filter('update_user_metadata', 'tsbp_store_old_assigned_group', 10, 5);

function tsbp_store_old_assigned_group($check, $object_id, $meta_key, $meta_value, $prev_value) {
    
    if ($meta_key !== 'assigned_group') {
        return $check;
    }
    
    static $old_values = [];
    
    // Store the current value before update
    $current = get_user_meta($object_id, 'assigned_group', true);
    if ($current) {
        $old_values[$object_id] = $current;
    }
    
    return $check; // Don't modify the update
}

/**
 * Make a user an admin of their therapy BP group
 * 
 * @param int $user_id          WordPress user ID
 * @param int $therapy_group_id The therapy_group post ID
 * @return bool
 */
function tsbp_make_user_group_admin($user_id, $therapy_group_id) {
    
    $bp_group_id = tsbp_get_bp_group_id($therapy_group_id);
    
    if (!$bp_group_id) {
        return false;
    }
    
    // First ensure user is a member
    if (!groups_is_user_member($user_id, $bp_group_id)) {
        groups_join_group($bp_group_id, $user_id);
    }
    
    // Promote to admin
    $member = new BP_Groups_Member($user_id, $bp_group_id);
    return $member->promote('admin');
}

/**
 * Make a user a moderator of their therapy BP group
 * 
 * @param int $user_id          WordPress user ID
 * @param int $therapy_group_id The therapy_group post ID
 * @return bool
 */
function tsbp_make_user_group_mod($user_id, $therapy_group_id) {
    
    $bp_group_id = tsbp_get_bp_group_id($therapy_group_id);
    
    if (!$bp_group_id) {
        return false;
    }
    
    // First ensure user is a member
    if (!groups_is_user_member($user_id, $bp_group_id)) {
        groups_join_group($bp_group_id, $user_id);
    }
    
    // Promote to mod
    $member = new BP_Groups_Member($user_id, $bp_group_id);
    return $member->promote('mod');
}
