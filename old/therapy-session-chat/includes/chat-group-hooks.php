<?php
/**
 * Chat Room Hooks
 * 
 * Automatic chat room creation and user enrollment.
 * Uses Better Messages Chat Rooms.
 * 
 * @package TherapySessionChat
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ============================================
 * HOOK 1: Auto-create chat room when therapy_group is published
 * ============================================
 */
add_action('save_post_therapy_group', 'tsc_auto_create_chat_on_publish', 20, 3);

function tsc_auto_create_chat_on_publish($post_id, $post, $update) {
    
    error_log("[TSC] save_post_therapy_group triggered for post {$post_id}");
    
    // Skip autosaves
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        error_log("[TSC] Skipping - autosave");
        return;
    }
    
    if (wp_is_post_revision($post_id)) {
        error_log("[TSC] Skipping - revision");
        return;
    }
    
    // Only for published posts
    if ($post->post_status !== 'publish') {
        error_log("[TSC] Skipping - post not published (status: {$post->post_status})");
        return;
    }
    
    // Check if already has chat room
    $existing = get_post_meta($post_id, '_tsc_chat_room_id', true);
    if ($existing && get_post($existing)) {
        error_log("[TSC] Chat room already exists: {$existing}");
        return;
    }
    
    // Get details
    $group_name = $post->post_title;
    $issue_type = get_post_meta($post_id, 'issue_type', true);
    $gender = get_post_meta($post_id, 'gender', true);
    
    $description = sprintf(
        'Therapy session chat - %s. Issue: %s, Gender: %s',
        $group_name,
        ucfirst($issue_type),
        ucfirst($gender)
    );
    
    // Get expiry date
    $expiry_date = get_post_meta($post_id, 'session_end_date', true);
    if (!$expiry_date) {
        $default_days = get_option('tsc_default_expiry_days', 30);
        $expiry_date = date('Y-m-d', strtotime("+{$default_days} days"));
    }
    
    // Create chat room
    $room_id = tsc_create_chat_room(
        $post_id,
        $group_name . ' Chat',
        $description,
        get_current_user_id(),
        $expiry_date
    );
    
    if ($room_id) {
        error_log("[TSC] Auto-created chat room {$room_id} for therapy_group {$post_id}");
    } else {
        error_log("[TSC] FAILED to create chat room for therapy_group {$post_id}");
    }
}

/**
 * ============================================
 * HOOK 2: Auto-enroll user after Ultimate Member registration
 * ============================================
 */
add_action('um_registration_complete', 'tsc_enroll_user_after_registration', 20, 2);

function tsc_enroll_user_after_registration($user_id, $args) {
    
    try {
        error_log("[TSC] um_registration_complete triggered for user {$user_id}");
        
        // Small delay - the existing code at priority 10 assigns the group
        // We run at priority 20 to get the assigned_group value
        
        // Get assigned therapy group
        $therapy_group_id = get_user_meta($user_id, 'assigned_group', true);
        
        if (!$therapy_group_id) {
            error_log("[TSC] No therapy group assigned to user {$user_id}");
            return;
        }
        
        error_log("[TSC] User {$user_id} assigned to therapy group {$therapy_group_id}");
        
        // Add user to chat
        $result = tsc_add_user_to_chat($user_id, $therapy_group_id);
        
        if ($result) {
            error_log("[TSC] User {$user_id} successfully enrolled in chat");
        } else {
            error_log("[TSC] FAILED to enroll user {$user_id} in chat");
        }
    } catch (Exception $e) {
        error_log("[TSC] Registration hook exception: " . $e->getMessage());
    } catch (Error $e) {
        error_log("[TSC] Registration hook error: " . $e->getMessage());
    }
}

/**
 * ============================================
 * HOOK 3: Sync chat when user's assigned_group changes
 * ============================================
 */
add_action('updated_user_meta', 'tsc_sync_chat_on_group_change', 10, 4);
add_action('added_user_meta', 'tsc_sync_chat_on_group_change', 10, 4);

function tsc_sync_chat_on_group_change($meta_id, $user_id, $meta_key, $meta_value) {
    
    if ($meta_key !== 'assigned_group') {
        return;
    }
    
    if (empty($meta_value)) {
        return;
    }
    
    try {
        $therapy_group_id = intval($meta_value);
        
        error_log("[TSC] assigned_group changed for user {$user_id} to {$therapy_group_id}");
        
        // Check if already enrolled in this chat
        $already_enrolled = get_user_meta($user_id, '_tsc_enrolled_chat', true);
        if ($already_enrolled == $therapy_group_id) {
            error_log("[TSC] User already enrolled in this chat");
            return;
        }
        
        // Remove from old chat if different
        if ($already_enrolled && $already_enrolled != $therapy_group_id) {
            tsc_remove_user_from_chat($user_id, $already_enrolled);
        }
        
        // Add to new chat
        $result = tsc_add_user_to_chat($user_id, $therapy_group_id);
        
        if ($result) {
            error_log("[TSC] User {$user_id} chat synced to therapy group {$therapy_group_id}");
        }
    } catch (Exception $e) {
        error_log("[TSC] Group change hook exception: " . $e->getMessage());
    } catch (Error $e) {
        error_log("[TSC] Group change hook error: " . $e->getMessage());
    }
}

/**
 * ============================================
 * HOOK 4: Sync chat status with therapy group status
 * ============================================
 */
add_action('updated_post_meta', 'tsc_sync_chat_on_status_change', 10, 4);

function tsc_sync_chat_on_status_change($meta_id, $post_id, $meta_key, $meta_value) {
    
    if (get_post_type($post_id) !== 'therapy_group') {
        return;
    }
    
    if ($meta_key !== 'group_status') {
        return;
    }
    
    $room_id = get_post_meta($post_id, '_tsc_chat_room_id', true);
    
    if (!$room_id) {
        return;
    }
    
    if ($meta_value === 'inactive' || $meta_value === 'completed') {
        update_post_meta($room_id, '_tsc_status', 'archived');
        error_log("[TSC] Chat room {$room_id} archived - therapy group status: {$meta_value}");
    } elseif ($meta_value === 'active') {
        update_post_meta($room_id, '_tsc_status', 'active');
    }
}

/**
 * ============================================
 * HOOK 5: Delete chat room when therapy_group is deleted
 * ============================================
 */
add_action('before_delete_post', 'tsc_delete_chat_with_therapy_group', 10, 1);

function tsc_delete_chat_with_therapy_group($post_id) {
    
    if (get_post_type($post_id) !== 'therapy_group') {
        return;
    }
    
    $room_id = get_post_meta($post_id, '_tsc_chat_room_id', true);
    
    if (!$room_id) {
        return;
    }
    
    wp_delete_post($room_id, true);
    error_log("[TSC] Deleted chat room {$room_id} with therapy_group {$post_id}");
}
