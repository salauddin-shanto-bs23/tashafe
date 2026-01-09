<?php
/**
 * Better Messages Thread Functions
 * 
 * Creates and manages Better Messages group threads directly,
 * bypassing BuddyPress group pages since they have routing issues.
 * 
 * @package TherapySessionBuddyPress
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Create a Better Messages group thread for a therapy session
 * This creates the same type of thread that BM creates when you make a group manually
 * 
 * @param int    $therapy_group_id  The therapy_group post ID
 * @param string $thread_name       Name for the thread
 * @param int    $creator_id        User ID of thread creator
 * @return int|false                Thread ID on success, false on failure
 */
function tsbp_create_bm_thread($therapy_group_id, $thread_name = '', $creator_id = null) {
    global $wpdb;
    
    error_log("[TSBP-BM] Creating BM thread for therapy_group_id: {$therapy_group_id}");
    
    // Check if thread already exists
    $existing_thread_id = get_post_meta($therapy_group_id, '_tsbp_bm_thread_id', true);
    if ($existing_thread_id) {
        error_log("[TSBP-BM] Thread already exists: {$existing_thread_id}");
        return intval($existing_thread_id);
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
    
    // Generate thread name from therapy group
    if (empty($thread_name)) {
        $therapy_group = get_post($therapy_group_id);
        $thread_name = $therapy_group ? $therapy_group->post_title : 'Therapy Session #' . $therapy_group_id;
    }
    
    // Method 1: Use Better Messages API if available
    if (class_exists('Better_Messages') && method_exists(Better_Messages(), 'create_group_chat')) {
        $thread_id = Better_Messages()->create_group_chat([
            'name'       => $thread_name,
            'creator_id' => $creator_id,
        ]);
        
        if ($thread_id) {
            update_post_meta($therapy_group_id, '_tsbp_bm_thread_id', $thread_id);
            error_log("[TSBP-BM] Created thread via BM API: {$thread_id}");
            return $thread_id;
        }
    }
    
    // Method 2: Use BP Messages API (Better Messages extends this)
    if (function_exists('messages_new_message')) {
        // Create initial message to establish thread
        $thread_id = messages_new_message([
            'sender_id'  => $creator_id,
            'recipients' => [$creator_id], // Start with just creator
            'subject'    => $thread_name,
            'content'    => sprintf(
                __('Welcome to %s. This is your therapy group chat.', 'therapy-session-bp'),
                $thread_name
            ),
        ]);
        
        if ($thread_id && !is_wp_error($thread_id)) {
            // Store relationship
            update_post_meta($therapy_group_id, '_tsbp_bm_thread_id', $thread_id);
            
            // Store thread name as meta
            bp_messages_update_meta($thread_id, 'group_message_thread', '1');
            bp_messages_update_meta($thread_id, 'group_name', $thread_name);
            bp_messages_update_meta($thread_id, 'therapy_group_id', $therapy_group_id);
            
            // Mark as group chat (Better Messages recognizes this)
            bp_messages_update_meta($thread_id, 'message_type', 'group');
            
            error_log("[TSBP-BM] Created thread via BP Messages: {$thread_id}");
            return $thread_id;
        }
    }
    
    // Method 3: Direct database insert (last resort)
    $thread_id = tsbp_create_bm_thread_direct($therapy_group_id, $thread_name, $creator_id);
    
    if ($thread_id) {
        update_post_meta($therapy_group_id, '_tsbp_bm_thread_id', $thread_id);
        error_log("[TSBP-BM] Created thread via direct DB: {$thread_id}");
        return $thread_id;
    }
    
    error_log("[TSBP-BM] Failed to create thread for therapy_group {$therapy_group_id}");
    return false;
}

/**
 * Create BM thread via direct database (when APIs aren't available)
 */
function tsbp_create_bm_thread_direct($therapy_group_id, $thread_name, $creator_id) {
    global $wpdb;
    
    $bp = buddypress();
    
    // Check if BP messages tables exist
    $messages_table = $bp->messages->table_name_messages ?? $wpdb->prefix . 'bp_messages_messages';
    $recipients_table = $bp->messages->table_name_recipients ?? $wpdb->prefix . 'bp_messages_recipients';
    $meta_table = $wpdb->prefix . 'bp_messages_meta';
    
    if ($wpdb->get_var("SHOW TABLES LIKE '$messages_table'") !== $messages_table) {
        error_log("[TSBP-BM] BP messages table not found");
        return false;
    }
    
    // Insert initial message
    $result = $wpdb->insert(
        $messages_table,
        [
            'sender_id'  => $creator_id,
            'subject'    => $thread_name,
            'message'    => sprintf(
                __('Welcome to %s. This is your therapy group chat.', 'therapy-session-bp'),
                $thread_name
            ),
            'date_sent'  => bp_core_current_time(),
        ],
        ['%d', '%s', '%s', '%s']
    );
    
    if (!$result) {
        return false;
    }
    
    $message_id = $wpdb->insert_id;
    
    // The thread_id is the message_id for the first message
    $thread_id = $message_id;
    
    // Update the message with its own thread_id
    $wpdb->update(
        $messages_table,
        ['thread_id' => $thread_id],
        ['id' => $message_id],
        ['%d'],
        ['%d']
    );
    
    // Add creator as recipient
    $wpdb->insert(
        $recipients_table,
        [
            'user_id'      => $creator_id,
            'thread_id'    => $thread_id,
            'unread_count' => 0,
            'is_deleted'   => 0,
            'sender_only'  => 0,
        ],
        ['%d', '%d', '%d', '%d', '%d']
    );
    
    // Add meta to mark as group chat
    if ($wpdb->get_var("SHOW TABLES LIKE '$meta_table'") === $meta_table) {
        $wpdb->insert($meta_table, ['message_id' => $thread_id, 'meta_key' => 'group_message_thread', 'meta_value' => '1'], ['%d', '%s', '%s']);
        $wpdb->insert($meta_table, ['message_id' => $thread_id, 'meta_key' => 'group_name', 'meta_value' => $thread_name], ['%d', '%s', '%s']);
        $wpdb->insert($meta_table, ['message_id' => $thread_id, 'meta_key' => 'therapy_group_id', 'meta_value' => $therapy_group_id], ['%d', '%s', '%s']);
    }
    
    return $thread_id;
}

/**
 * Get Better Messages thread ID for a therapy group
 * 
 * @param int $therapy_group_id The therapy_group post ID
 * @return int|false Thread ID or false
 */
function tsbp_get_bm_thread_id($therapy_group_id) {
    // First check post meta
    $thread_id = get_post_meta($therapy_group_id, '_tsbp_bm_thread_id', true);
    if ($thread_id) {
        return intval($thread_id);
    }
    
    // If we have a BP group, check if BM created a thread for it
    $bp_group_id = tsbp_get_bp_group_id($therapy_group_id);
    if ($bp_group_id) {
        $thread_id = tsbp_get_bm_group_thread_id($bp_group_id);
        if ($thread_id) {
            // Cache it
            update_post_meta($therapy_group_id, '_tsbp_bm_thread_id', $thread_id);
            return $thread_id;
        }
    }
    
    return false;
}

/**
 * Add user to Better Messages thread
 * 
 * @param int $user_id   User ID
 * @param int $thread_id Thread ID
 * @return bool
 */
function tsbp_add_user_to_bm_thread($user_id, $thread_id) {
    global $wpdb;
    
    if (!$user_id || !$thread_id) {
        return false;
    }
    
    $bp = buddypress();
    $recipients_table = $bp->messages->table_name_recipients ?? $wpdb->prefix . 'bp_messages_recipients';
    
    // Check if already a recipient
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $recipients_table WHERE user_id = %d AND thread_id = %d",
        $user_id, $thread_id
    ));
    
    if ($exists) {
        // Make sure not deleted
        $wpdb->update(
            $recipients_table,
            ['is_deleted' => 0],
            ['user_id' => $user_id, 'thread_id' => $thread_id],
            ['%d'],
            ['%d', '%d']
        );
        return true;
    }
    
    // Add as recipient
    $result = $wpdb->insert(
        $recipients_table,
        [
            'user_id'      => $user_id,
            'thread_id'    => $thread_id,
            'unread_count' => 1,
            'is_deleted'   => 0,
            'sender_only'  => 0,
        ],
        ['%d', '%d', '%d', '%d', '%d']
    );
    
    if ($result) {
        error_log("[TSBP-BM] Added user {$user_id} to thread {$thread_id}");
        return true;
    }
    
    return false;
}

/**
 * Remove user from Better Messages thread
 * 
 * @param int $user_id   User ID
 * @param int $thread_id Thread ID
 * @return bool
 */
function tsbp_remove_user_from_bm_thread($user_id, $thread_id) {
    global $wpdb;
    
    if (!$user_id || !$thread_id) {
        return false;
    }
    
    $bp = buddypress();
    $recipients_table = $bp->messages->table_name_recipients ?? $wpdb->prefix . 'bp_messages_recipients';
    
    // Mark as deleted (don't actually delete to preserve history)
    $result = $wpdb->update(
        $recipients_table,
        ['is_deleted' => 1],
        ['user_id' => $user_id, 'thread_id' => $thread_id],
        ['%d'],
        ['%d', '%d']
    );
    
    if ($result !== false) {
        error_log("[TSBP-BM] Removed user {$user_id} from thread {$thread_id}");
        return true;
    }
    
    return false;
}

/**
 * Get URL to open Better Messages with a specific thread
 * 
 * @param int $thread_id Thread ID
 * @return string URL
 */
function tsbp_get_bm_thread_url($thread_id) {
    if (!$thread_id) {
        return home_url('/messages/');
    }
    
    // Better Messages uses URL parameters to open specific threads
    // Try different formats that BM supports
    
    // Format 1: Direct thread URL
    $url = add_query_arg('thread_id', $thread_id, home_url('/messages/'));
    
    return $url;
}

/**
 * Get all users in a BM thread
 * 
 * @param int $thread_id Thread ID
 * @return array Array of user IDs
 */
function tsbp_get_bm_thread_users($thread_id) {
    global $wpdb;
    
    if (!$thread_id) {
        return [];
    }
    
    $bp = buddypress();
    $recipients_table = $bp->messages->table_name_recipients ?? $wpdb->prefix . 'bp_messages_recipients';
    
    $users = $wpdb->get_col($wpdb->prepare(
        "SELECT user_id FROM $recipients_table WHERE thread_id = %d AND is_deleted = 0",
        $thread_id
    ));
    
    return array_map('intval', $users);
}

/**
 * Sync BP group members to BM thread
 * Call this when users are added to BP group
 * 
 * @param int $therapy_group_id Therapy group ID
 * @return int Number of users synced
 */
function tsbp_sync_bp_group_to_bm_thread($therapy_group_id) {
    $bp_group_id = tsbp_get_bp_group_id($therapy_group_id);
    $thread_id = tsbp_get_bm_thread_id($therapy_group_id);
    
    if (!$bp_group_id || !$thread_id) {
        return 0;
    }
    
    // Get BP group members
    $members = groups_get_group_members([
        'group_id'   => $bp_group_id,
        'per_page'   => 100,
        'page'       => 1,
    ]);
    
    $synced = 0;
    
    if (!empty($members['members'])) {
        foreach ($members['members'] as $member) {
            if (tsbp_add_user_to_bm_thread($member->ID, $thread_id)) {
                $synced++;
            }
        }
    }
    
    return $synced;
}

/**
 * Hook: When user is added to BP group, also add to BM thread
 */
add_action('groups_join_group', 'tsbp_on_bp_group_join', 10, 2);
add_action('groups_membership_accepted', 'tsbp_on_bp_group_join', 10, 2);

function tsbp_on_bp_group_join($group_id, $user_id) {
    // Find the therapy group for this BP group
    $therapy_group_id = groups_get_groupmeta($group_id, '_tsbp_therapy_group_id', true);
    
    if (!$therapy_group_id) {
        return;
    }
    
    // Get or create BM thread
    $thread_id = tsbp_get_bm_thread_id($therapy_group_id);
    
    if (!$thread_id) {
        // Create thread if it doesn't exist
        $bp_group = groups_get_group($group_id);
        $thread_id = tsbp_create_bm_thread($therapy_group_id, $bp_group->name);
    }
    
    if ($thread_id) {
        tsbp_add_user_to_bm_thread($user_id, $thread_id);
    }
}

/**
 * Hook: When user leaves BP group, remove from BM thread
 */
add_action('groups_leave_group', 'tsbp_on_bp_group_leave', 10, 2);
add_action('groups_member_after_remove', 'tsbp_on_bp_group_leave', 10, 2);

function tsbp_on_bp_group_leave($group_id, $user_id) {
    // Find the therapy group for this BP group
    $therapy_group_id = groups_get_groupmeta($group_id, '_tsbp_therapy_group_id', true);
    
    if (!$therapy_group_id) {
        return;
    }
    
    $thread_id = tsbp_get_bm_thread_id($therapy_group_id);
    
    if ($thread_id) {
        tsbp_remove_user_from_bm_thread($user_id, $thread_id);
    }
}

/**
 * Hook: After BP group is created, create BM thread
 */
add_action('tsbp_after_bp_group_created', 'tsbp_create_bm_thread_for_bp_group', 10, 3);

function tsbp_create_bm_thread_for_bp_group($bp_group_id, $therapy_group_id, $creator_id) {
    $bp_group = groups_get_group($bp_group_id);
    
    if ($bp_group) {
        $thread_id = tsbp_create_bm_thread($therapy_group_id, $bp_group->name, $creator_id);
        
        if ($thread_id) {
            // Add creator to thread
            tsbp_add_user_to_bm_thread($creator_id, $thread_id);
            error_log("[TSBP-BM] Created BM thread {$thread_id} for BP group {$bp_group_id}");
        }
    }
}
