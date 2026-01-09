<?php
/**
 * Chat Room Core Functions
 * 
 * Uses Better Messages Chat Rooms (NOT BuddyPress groups)
 * All functions prefixed with tsc_ (Therapy Session Chat)
 * 
 * @package TherapySessionChat
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Create a Better Messages Chat Room for a therapy session
 * 
 * @param int    $therapy_group_id  The therapy_group post ID
 * @param string $room_name         Name for the chat room
 * @param string $description       Room description
 * @param int    $creator_id        User ID of room creator
 * @param string $expiry_date       Expiry date in Y-m-d format
 * @return int|false                Chat Room post ID on success, false on failure
 */
function tsc_create_chat_room($therapy_group_id, $room_name, $description = '', $creator_id = null, $expiry_date = null) {
    
    error_log("[TSC] tsc_create_chat_room called for therapy_group_id: {$therapy_group_id}");
    
    // Check if chat room already exists for this therapy group
    $existing_room_id = get_post_meta($therapy_group_id, '_tsc_chat_room_id', true);
    if ($existing_room_id && get_post($existing_room_id)) {
        error_log("[TSC] Chat room already exists: Room ID {$existing_room_id}");
        return $existing_room_id;
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
    
    // Sanitize room name
    $room_name = sanitize_text_field($room_name);
    if (empty($room_name)) {
        $room_name = 'Therapy Session Chat #' . $therapy_group_id;
    }
    
    // Create Better Messages Chat Room (custom post type: bpbm-chat)
    $room_args = [
        'post_title'   => $room_name,
        'post_content' => sanitize_textarea_field($description),
        'post_status'  => 'publish',
        'post_type'    => 'bpbm-chat',
        'post_author'  => $creator_id,
    ];
    
    $room_id = wp_insert_post($room_args, true);
    
    if (is_wp_error($room_id)) {
        error_log("[TSC] Failed to create chat room: " . $room_id->get_error_message());
        return false;
    }
    
    if (!$room_id) {
        error_log("[TSC] Failed to create chat room - no ID returned");
        return false;
    }
    
    // Initialize the room with Better Messages settings
    // These are the meta keys Better Messages uses internally
    tsc_initialize_bm_room($room_id, $creator_id);
    
    // Store relationship between therapy group and chat room
    update_post_meta($therapy_group_id, '_tsc_chat_room_id', $room_id);
    update_post_meta($room_id, '_tsc_therapy_group_id', $therapy_group_id);
    
    // Set expiry date
    if ($expiry_date) {
        update_post_meta($room_id, '_tsc_expiry_date', sanitize_text_field($expiry_date));
    }
    
    // Mark as active
    update_post_meta($room_id, '_tsc_status', 'active');
    
    error_log("[TSC] Successfully created chat room {$room_id} for therapy group {$therapy_group_id}");
    
    return $room_id;
}

/**
 * Initialize Better Messages room meta
 */
function tsc_initialize_bm_room($room_id, $creator_id) {
    
    // Core Better Messages settings - make room PUBLIC initially so users can be added
    // Better Messages uses these meta keys
    update_post_meta($room_id, 'bpbm_chat_hide_participants', '0');
    update_post_meta($room_id, 'bpbm_chat_auto_join', '0');
    
    // IMPORTANT: For Better Messages, we need to use their thread/participant system
    // Better Messages stores participants in bp_messages_recipients table or custom tables
    
    // Add creator as first participant using Better Messages native method
    tsc_add_user_to_bm_chat_native($creator_id, $room_id);
    
    error_log("[TSC] Initialized BM room {$room_id} with creator {$creator_id}");
}

/**
 * Add user to Better Messages chat using native methods
 * This is the key function that interfaces with Better Messages' actual participant system
 */
function tsc_add_user_to_bm_chat_native($user_id, $room_id) {
    global $wpdb;
    
    $user_id = intval($user_id);
    $room_id = intval($room_id);
    
    error_log("[TSC] tsc_add_user_to_bm_chat_native: user={$user_id}, room={$room_id}");
    
    // Method 1: Try Better Messages API directly
    try {
        if (function_exists('Better_Messages') && is_object(Better_Messages())) {
            $bm = Better_Messages();
            
            // Check for chats component
            if (isset($bm->chats) && is_object($bm->chats)) {
                // Try add_user_to_chat
                if (method_exists($bm->chats, 'add_user_to_chat')) {
                    $bm->chats->add_user_to_chat($room_id, $user_id);
                    error_log("[TSC] Used add_user_to_chat method");
                    return true;
                }
                // Try join method
                if (method_exists($bm->chats, 'join')) {
                    $bm->chats->join($room_id, $user_id);
                    error_log("[TSC] Used join method");
                    return true;
                }
            }
        }
    } catch (Exception $e) {
        error_log("[TSC] BM API exception: " . $e->getMessage());
    } catch (Error $e) {
        error_log("[TSC] BM API error: " . $e->getMessage());
    }
    
    // Method 2: Direct database insertion into Better Messages tables
    // Better Messages uses bp_messages_meta or its own tables
    
    // Check if bpbm_chat_participants table exists (Better Messages custom table)
    $table_name = $wpdb->prefix . 'bpbm_chat_participants';
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;
    
    if ($table_exists) {
        // Check if already participant
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} WHERE chat_id = %d AND user_id = %d",
            $room_id, $user_id
        ));
        
        if (!$exists) {
            $wpdb->insert($table_name, [
                'chat_id' => $room_id,
                'user_id' => $user_id,
                'joined'  => current_time('mysql')
            ], ['%d', '%d', '%s']);
            error_log("[TSC] Inserted into bpbm_chat_participants table");
            return true;
        } else {
            error_log("[TSC] User already in bpbm_chat_participants");
            return true;
        }
    }
    
    // Method 3: Check bp_messages_recipients table (BuddyPress Messages style)
    $bp_table = $wpdb->prefix . 'bp_messages_recipients';
    $bp_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$bp_table}'") === $bp_table;
    
    if ($bp_table_exists) {
        // For chat rooms, thread_id might be the room ID
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$bp_table} WHERE thread_id = %d AND user_id = %d",
            $room_id, $user_id
        ));
        
        if (!$exists) {
            $wpdb->insert($bp_table, [
                'user_id'      => $user_id,
                'thread_id'    => $room_id,
                'unread_count' => 0,
                'sender_only'  => 0,
                'is_deleted'   => 0
            ], ['%d', '%d', '%d', '%d', '%d']);
            error_log("[TSC] Inserted into bp_messages_recipients table");
            return true;
        } else {
            error_log("[TSC] User already in bp_messages_recipients");
            return true;
        }
    }
    
    // Method 4: Post meta fallback (less reliable but try anyway)
    $allowed_users = get_post_meta($room_id, 'bpbm_chat_users', true);
    if (!is_array($allowed_users)) {
        $allowed_users = [];
    }
    if (!in_array($user_id, $allowed_users)) {
        $allowed_users[] = $user_id;
        update_post_meta($room_id, 'bpbm_chat_users', array_unique($allowed_users));
    }
    
    // Also try individual meta entries
    add_post_meta($room_id, 'bpbm_participant', $user_id);
    
    error_log("[TSC] Used post meta fallback for user {$user_id} in room {$room_id}");
    return true;
}

/**
 * Add a user to a Better Messages Chat Room directly by room ID
 * 
 * @param int $user_id  WordPress user ID
 * @param int $room_id  Chat Room post ID
 * @return bool
 */
function tsc_add_user_to_chat_room($user_id, $room_id) {
    
    if (!$room_id || !$user_id) {
        error_log("[TSC] Invalid user_id or room_id");
        return false;
    }
    
    $user_id = intval($user_id);
    $room_id = intval($room_id);
    
    // Verify room exists
    $room = get_post($room_id);
    if (!$room) {
        error_log("[TSC] Chat room {$room_id} not found");
        return false;
    }
    
    // Use the native Better Messages integration
    $result = tsc_add_user_to_bm_chat_native($user_id, $room_id);
    
    if ($result) {
        error_log("[TSC] User {$user_id} added to room {$room_id}");
    }
    
    return $result;
}

/**
 * Add a user to therapy chat by therapy_group_id
 * 
 * @param int $user_id          WordPress user ID
 * @param int $therapy_group_id The therapy_group post ID
 * @return bool
 */
function tsc_add_user_to_chat($user_id, $therapy_group_id) {
    
    error_log("[TSC] tsc_add_user_to_chat called: user={$user_id}, therapy_group={$therapy_group_id}");
    
    // Get the chat room ID for this therapy group
    $room_id = get_post_meta($therapy_group_id, '_tsc_chat_room_id', true);
    
    if (!$room_id) {
        error_log("[TSC] No chat room found for therapy group {$therapy_group_id}, creating one...");
        
        // Auto-create chat room if it doesn't exist
        $post = get_post($therapy_group_id);
        if ($post) {
            $default_days = get_option('tsc_default_expiry_days', 30);
            $room_id = tsc_create_chat_room(
                $therapy_group_id,
                $post->post_title . ' Chat',
                'Chat room for ' . $post->post_title,
                null,
                date('Y-m-d', strtotime("+{$default_days} days"))
            );
        }
        
        if (!$room_id) {
            error_log("[TSC] Failed to create chat room for therapy group {$therapy_group_id}");
            return false;
        }
    }
    
    // Check if room is active
    $chat_status = get_post_meta($room_id, '_tsc_status', true);
    if ($chat_status === 'expired' || $chat_status === 'archived') {
        error_log("[TSC] Chat room {$room_id} is {$chat_status}");
        return false;
    }
    
    $result = tsc_add_user_to_chat_room($user_id, $room_id);
    
    if ($result) {
        // Store reference in user meta
        update_user_meta($user_id, '_tsc_enrolled_chat', $therapy_group_id);
        update_user_meta($user_id, '_tsc_chat_room_id', $room_id);
        error_log("[TSC] User {$user_id} enrolled in chat for therapy group {$therapy_group_id}");
    }
    
    return $result;
}

/**
 * Remove a user from a therapy chat
 * 
 * @param int $user_id          WordPress user ID
 * @param int $therapy_group_id The therapy_group post ID
 * @return bool
 */
function tsc_remove_user_from_chat($user_id, $therapy_group_id) {
    
    $room_id = get_post_meta($therapy_group_id, '_tsc_chat_room_id', true);
    
    if (!$room_id) {
        return false;
    }
    
    // Remove from serialized array
    $allowed_users = get_post_meta($room_id, 'bm_chat_room_users', true);
    if (is_array($allowed_users)) {
        $allowed_users = array_diff($allowed_users, [$user_id]);
        update_post_meta($room_id, 'bm_chat_room_users', array_values($allowed_users));
    }
    
    // Remove individual meta entries
    global $wpdb;
    $wpdb->delete($wpdb->postmeta, [
        'post_id'    => $room_id,
        'meta_key'   => 'bm-room-participant',
        'meta_value' => $user_id
    ]);
    
    // Update JSON format
    $participants_json = get_post_meta($room_id, 'bm_participants', true);
    if ($participants_json) {
        $participants = json_decode($participants_json, true);
        if (is_array($participants)) {
            $participants = array_values(array_diff($participants, [$user_id]));
            update_post_meta($room_id, 'bm_participants', wp_json_encode($participants));
        }
    }
    
    // Clear user meta
    delete_user_meta($user_id, '_tsc_enrolled_chat');
    delete_user_meta($user_id, '_tsc_chat_room_id');
    
    error_log("[TSC] User {$user_id} removed from chat room {$room_id}");
    
    return true;
}

/**
 * Get the chat room ID for a therapy session
 * 
 * @param int $therapy_group_id The therapy_group post ID
 * @return int|false
 */
function tsc_get_chat_room_id($therapy_group_id) {
    $room_id = get_post_meta($therapy_group_id, '_tsc_chat_room_id', true);
    return $room_id ? intval($room_id) : false;
}

/**
 * Check if user can access therapy chat
 * 
 * @param int $user_id          WordPress user ID
 * @param int $therapy_group_id The therapy_group post ID
 * @return bool
 */
function tsc_user_can_access_chat($user_id, $therapy_group_id) {
    
    $room_id = get_post_meta($therapy_group_id, '_tsc_chat_room_id', true);
    
    if (!$room_id) {
        error_log("[TSC] No chat room for therapy group {$therapy_group_id}");
        return false;
    }
    
    // Check status
    $chat_status = get_post_meta($room_id, '_tsc_status', true);
    if ($chat_status === 'expired') {
        return false;
    }
    
    // Check multiple formats
    // Format 1: Serialized array
    $allowed_users = get_post_meta($room_id, 'bm_chat_room_users', true);
    if (is_array($allowed_users) && in_array(intval($user_id), array_map('intval', $allowed_users))) {
        return true;
    }
    
    // Format 2: Individual meta entries
    global $wpdb;
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} 
         WHERE post_id = %d AND meta_key = 'bm-room-participant' AND meta_value = %d",
        $room_id, $user_id
    ));
    if ($exists) {
        return true;
    }
    
    // Format 3: JSON
    $participants_json = get_post_meta($room_id, 'bm_participants', true);
    if ($participants_json) {
        $participants = json_decode($participants_json, true);
        if (is_array($participants) && in_array($user_id, $participants)) {
            return true;
        }
    }
    
    return false;
}

/**
 * Get all members of a chat room
 * 
 * @param int $therapy_group_id The therapy_group post ID
 * @return array
 */
function tsc_get_chat_members($therapy_group_id) {
    
    $room_id = get_post_meta($therapy_group_id, '_tsc_chat_room_id', true);
    
    if (!$room_id) {
        return [];
    }
    
    $members = [];
    
    // Format 1: Serialized array
    $allowed_users = get_post_meta($room_id, 'bm_chat_room_users', true);
    if (is_array($allowed_users)) {
        $members = array_merge($members, $allowed_users);
    }
    
    // Format 2: Individual meta entries
    global $wpdb;
    $participant_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->postmeta} 
         WHERE post_id = %d AND meta_key = 'bm-room-participant'",
        $room_id
    ));
    if (!empty($participant_ids)) {
        $members = array_merge($members, $participant_ids);
    }
    
    return array_unique(array_map('intval', $members));
}

/**
 * Get member count of a chat room
 * 
 * @param int $therapy_group_id The therapy_group post ID
 * @return int
 */
function tsc_get_chat_member_count($therapy_group_id) {
    $members = tsc_get_chat_members($therapy_group_id);
    return count($members);
}
