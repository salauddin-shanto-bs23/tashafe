<?php
/**
 * BuddyPress Group Core Functions
 * 
 * Creates and manages BuddyPress groups for therapy sessions.
 * Better Messages automatically provides chat UI for BP groups.
 * 
 * @package TherapySessionBuddyPress
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Create a BuddyPress group for a therapy session
 * 
 * @param int    $therapy_group_id  The therapy_group post ID
 * @param string $group_name        Name for the BP group
 * @param string $description       Group description
 * @param int    $creator_id        User ID of group creator (admin)
 * @param string $expiry_date       Expiry date in Y-m-d format
 * @return int|false                BP Group ID on success, false on failure
 */
function tsbp_create_bp_group($therapy_group_id, $group_name, $description = '', $creator_id = null, $expiry_date = null) {
    
    error_log("[TSBP] tsbp_create_bp_group called for therapy_group_id: {$therapy_group_id}");
    
    // Check if BuddyPress groups function exists
    if (!function_exists('groups_create_group')) {
        error_log("[TSBP] BuddyPress groups_create_group function not available");
        return false;
    }
    
    // Check if BP group already exists for this therapy group
    $existing_bp_group_id = get_post_meta($therapy_group_id, '_tsbp_bp_group_id', true);
    if ($existing_bp_group_id) {
        // Verify the BP group still exists
        $existing_group = groups_get_group($existing_bp_group_id);
        if ($existing_group && !empty($existing_group->id)) {
            error_log("[TSBP] BP group already exists: BP Group ID {$existing_bp_group_id}");
            return $existing_bp_group_id;
        }
    }
    
    // Default creator to current user or first admin
    if (!$creator_id) {
        if (is_user_logged_in()) {
            $creator_id = get_current_user_id();
        } else {
            $admins = get_users(['role' => 'administrator', 'number' => 1]);
            $creator_id = !empty($admins) ? $admins[0]->ID : 1;
        }
    }
    
    // Sanitize group name
    $group_name = sanitize_text_field($group_name);
    if (empty($group_name)) {
        $group_name = 'Therapy Session #' . $therapy_group_id;
    }
    
    // Generate unique slug
    $slug = sanitize_title($group_name . '-' . $therapy_group_id);
    
    // Get group status from options (private or hidden)
    $group_status = get_option('tsbp_group_status', 'private');
    
    // Create BuddyPress Group
    $bp_group_args = [
        'creator_id'   => $creator_id,
        'name'         => $group_name,
        'description'  => sanitize_textarea_field($description),
        'slug'         => $slug,
        'status'       => $group_status, // 'public', 'private', or 'hidden'
        'enable_forum' => false,
        'date_created' => bp_core_current_time(),
    ];
    
    $bp_group_id = groups_create_group($bp_group_args);
    
    if (!$bp_group_id) {
        error_log("[TSBP] Failed to create BP group for therapy_group {$therapy_group_id}");
        return false;
    }
    
    error_log("[TSBP] Successfully created BP group {$bp_group_id} for therapy_group {$therapy_group_id}");
    
    // Store relationship between therapy group and BP group
    update_post_meta($therapy_group_id, '_tsbp_bp_group_id', $bp_group_id);
    
    // Store reverse reference in BP group meta
    groups_update_groupmeta($bp_group_id, '_tsbp_therapy_group_id', $therapy_group_id);
    
    // Set expiry date in BP group meta
    if ($expiry_date) {
        groups_update_groupmeta($bp_group_id, '_tsbp_expiry_date', sanitize_text_field($expiry_date));
    }
    
    // Mark as active
    groups_update_groupmeta($bp_group_id, '_tsbp_status', 'active');
    
    // Store therapy group metadata in BP group for reference
    $issue_type = get_post_meta($therapy_group_id, 'issue_type', true);
    $gender = get_post_meta($therapy_group_id, 'gender', true);
    
    if ($issue_type) {
        groups_update_groupmeta($bp_group_id, '_tsbp_issue_type', $issue_type);
    }
    if ($gender) {
        groups_update_groupmeta($bp_group_id, '_tsbp_gender', $gender);
    }
    
    // The creator is automatically added as admin by groups_create_group()
    // But let's ensure they're properly added
    $is_member = groups_is_user_member($creator_id, $bp_group_id);
    if (!$is_member) {
        groups_join_group($bp_group_id, $creator_id);
        error_log("[TSBP] Manually added creator {$creator_id} to BP group {$bp_group_id}");
    }
    
    // Make creator a group admin
    $member = new BP_Groups_Member($creator_id, $bp_group_id);
    $member->promote('admin');
    
    error_log("[TSBP] Creator {$creator_id} set as admin of BP group {$bp_group_id}");
    
    /**
     * Action: After BP group is created for therapy session
     * 
     * @param int $bp_group_id      The BuddyPress group ID
     * @param int $therapy_group_id The therapy_group post ID
     * @param int $creator_id       The creator user ID
     */
    do_action('tsbp_after_bp_group_created', $bp_group_id, $therapy_group_id, $creator_id);
    
    return $bp_group_id;
}

/**
 * Get the BP group ID for a therapy session
 * 
 * @param int $therapy_group_id The therapy_group post ID
 * @return int|false BP Group ID or false
 */
function tsbp_get_bp_group_id($therapy_group_id) {
    $bp_group_id = get_post_meta($therapy_group_id, '_tsbp_bp_group_id', true);
    return $bp_group_id ? intval($bp_group_id) : false;
}

/**
 * Get the therapy_group ID for a BP group
 * 
 * @param int $bp_group_id The BuddyPress group ID
 * @return int|false therapy_group post ID or false
 */
function tsbp_get_therapy_group_id($bp_group_id) {
    $therapy_group_id = groups_get_groupmeta($bp_group_id, '_tsbp_therapy_group_id', true);
    return $therapy_group_id ? intval($therapy_group_id) : false;
}

/**
 * Get the BP group object for a therapy session
 * 
 * @param int $therapy_group_id The therapy_group post ID
 * @return BP_Groups_Group|false BP Group object or false
 */
function tsbp_get_bp_group($therapy_group_id) {
    $bp_group_id = tsbp_get_bp_group_id($therapy_group_id);
    
    if (!$bp_group_id) {
        return false;
    }
    
    return groups_get_group($bp_group_id);
}

/**
 * Get BP group URL for a therapy session
 * 
 * @param int $therapy_group_id The therapy_group post ID
 * @return string|false Group URL or false
 */
function tsbp_get_bp_group_url($therapy_group_id) {
    $bp_group = tsbp_get_bp_group($therapy_group_id);
    
    if (!$bp_group) {
        return false;
    }
    
    return bp_get_group_permalink($bp_group);
}

/**
 * Get BP group chat URL (messages tab) for a therapy session
 * 
 * @param int $therapy_group_id The therapy_group post ID
 * @return string|false Chat URL or false
 */
function tsbp_get_bp_group_chat_url($therapy_group_id) {
    $group_url = tsbp_get_bp_group_url($therapy_group_id);
    
    if (!$group_url) {
        return false;
    }
    
    // Better Messages typically adds chat to the messages tab or a custom tab
    // The exact URL depends on Better Messages configuration
    return trailingslashit($group_url) . 'messages/';
}

/**
 * Delete BP group when therapy session is deleted
 * 
 * @param int $therapy_group_id The therapy_group post ID
 * @return bool
 */
function tsbp_delete_bp_group($therapy_group_id) {
    $bp_group_id = tsbp_get_bp_group_id($therapy_group_id);
    
    if (!$bp_group_id) {
        return false;
    }
    
    $result = groups_delete_group($bp_group_id);
    
    if ($result) {
        delete_post_meta($therapy_group_id, '_tsbp_bp_group_id');
        error_log("[TSBP] Deleted BP group {$bp_group_id} for therapy_group {$therapy_group_id}");
    }
    
    return $result;
}

/**
 * Update BP group status
 * 
 * @param int    $bp_group_id The BuddyPress group ID
 * @param string $status      'public', 'private', or 'hidden'
 * @return bool
 */
function tsbp_update_bp_group_status($bp_group_id, $status) {
    global $wpdb;
    
    $allowed_statuses = ['public', 'private', 'hidden'];
    
    if (!in_array($status, $allowed_statuses)) {
        return false;
    }
    
    $bp = buddypress();
    
    $result = $wpdb->update(
        $bp->groups->table_name,
        ['status' => $status],
        ['id' => $bp_group_id],
        ['%s'],
        ['%d']
    );
    
    if ($result !== false) {
        // Clear group cache
        wp_cache_delete($bp_group_id, 'bp_groups');
        groups_update_groupmeta($bp_group_id, '_tsbp_status', $status === 'hidden' ? 'archived' : 'active');
        error_log("[TSBP] Updated BP group {$bp_group_id} status to {$status}");
        return true;
    }
    
    return false;
}

/**
 * Archive BP group (set to hidden)
 * 
 * @param int $therapy_group_id The therapy_group post ID
 * @return bool
 */
function tsbp_archive_bp_group($therapy_group_id) {
    $bp_group_id = tsbp_get_bp_group_id($therapy_group_id);
    
    if (!$bp_group_id) {
        return false;
    }
    
    $result = tsbp_update_bp_group_status($bp_group_id, 'hidden');
    
    if ($result) {
        groups_update_groupmeta($bp_group_id, '_tsbp_status', 'archived');
        groups_update_groupmeta($bp_group_id, '_tsbp_archived_date', current_time('mysql'));
    }
    
    return $result;
}

/**
 * Check if BP group is active
 * 
 * @param int $therapy_group_id The therapy_group post ID
 * @return bool
 */
function tsbp_is_bp_group_active($therapy_group_id) {
    $bp_group_id = tsbp_get_bp_group_id($therapy_group_id);
    
    if (!$bp_group_id) {
        return false;
    }
    
    $status = groups_get_groupmeta($bp_group_id, '_tsbp_status', true);
    
    return $status !== 'archived' && $status !== 'expired';
}

/**
 * Get all BP groups created by this plugin
 * 
 * @param array $args Query arguments
 * @return array Array of BP_Groups_Group objects
 */
function tsbp_get_all_therapy_bp_groups($args = []) {
    $defaults = [
        'per_page'     => 50,
        'page'         => 1,
        'show_hidden'  => true,
        'meta_query'   => [
            [
                'key'     => '_tsbp_therapy_group_id',
                'compare' => 'EXISTS',
            ],
        ],
    ];
    
    $args = wp_parse_args($args, $defaults);
    
    $groups = groups_get_groups($args);
    
    return $groups['groups'] ?? [];
}
