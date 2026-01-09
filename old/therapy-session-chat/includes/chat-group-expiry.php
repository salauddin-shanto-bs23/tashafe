<?php
/**
 * Chat Room Expiry Handler
 * 
 * WP-Cron based automatic expiry system.
 * 
 * @package TherapySessionChat
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cron action: Check and process expired chat rooms
 */
add_action('tsc_chat_expiry_check', 'tsc_process_expired_chats');

function tsc_process_expired_chats() {
    
    $today = current_time('Y-m-d');
    
    error_log("[TSC] Running expiry check for: {$today}");
    
    // Find expired chat rooms
    $args = [
        'post_type'      => 'bm-chat-room',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'meta_query'     => [
            'relation' => 'AND',
            [
                'key'     => '_tsc_expiry_date',
                'value'   => $today,
                'compare' => '<=',
                'type'    => 'DATE',
            ],
            [
                'key'     => '_tsc_status',
                'value'   => 'active',
                'compare' => '=',
            ],
        ],
    ];
    
    $expired_rooms = get_posts($args);
    
    if (empty($expired_rooms)) {
        error_log("[TSC] No expired chat rooms found");
        return;
    }
    
    $expiry_action = get_option('tsc_expiry_action', 'archive');
    
    foreach ($expired_rooms as $room) {
        $therapy_group_id = get_post_meta($room->ID, '_tsc_therapy_group_id', true);
        
        if ($expiry_action === 'delete') {
            tsc_delete_expired_chat($room->ID, $therapy_group_id);
        } else {
            tsc_archive_expired_chat($room->ID, $therapy_group_id);
        }
    }
}

/**
 * Archive an expired chat room
 */
function tsc_archive_expired_chat($room_id, $therapy_group_id) {
    
    update_post_meta($room_id, '_tsc_status', 'expired');
    update_post_meta($room_id, '_tsc_expired_on', current_time('mysql'));
    
    if ($therapy_group_id) {
        update_post_meta($therapy_group_id, '_tsc_chat_expired', 1);
    }
    
    tsc_notify_expiry($room_id, $therapy_group_id, 'archived');
    
    error_log("[TSC] Archived expired chat room {$room_id}");
}

/**
 * Delete an expired chat room
 */
function tsc_delete_expired_chat($room_id, $therapy_group_id) {
    
    $room = get_post($room_id);
    $room_name = $room ? $room->post_title : 'Unknown';
    
    wp_delete_post($room_id, true);
    
    if ($therapy_group_id) {
        delete_post_meta($therapy_group_id, '_tsc_chat_room_id');
        update_post_meta($therapy_group_id, '_tsc_chat_deleted', 1);
    }
    
    tsc_notify_expiry($room_id, $therapy_group_id, 'deleted');
    
    error_log("[TSC] Deleted expired chat room {$room_id}");
}

/**
 * Send admin notification
 */
function tsc_notify_expiry($room_id, $therapy_group_id, $action) {
    
    $admin_email = get_option('admin_email');
    $therapy_title = $therapy_group_id ? get_the_title($therapy_group_id) : 'Unknown';
    
    $subject = sprintf('[%s] Chat Room %s: %s', get_bloginfo('name'), ucfirst($action), $therapy_title);
    
    $message = sprintf(
        "Chat room for \"%s\" has been %s due to expiry.\n\n" .
        "Therapy Group ID: %d\n" .
        "Chat Room ID: %d\n" .
        "Date: %s",
        $therapy_title,
        $action,
        $therapy_group_id,
        $room_id,
        current_time('mysql')
    );
    
    wp_mail($admin_email, $subject, $message);
}

/**
 * Manually expire a chat room
 */
function tsc_manually_expire_chat($therapy_group_id, $action = 'archive') {
    
    $room_id = get_post_meta($therapy_group_id, '_tsc_chat_room_id', true);
    
    if (!$room_id) {
        return new WP_Error('no_chat', 'No chat room found');
    }
    
    if ($action === 'delete') {
        tsc_delete_expired_chat($room_id, $therapy_group_id);
    } else {
        tsc_archive_expired_chat($room_id, $therapy_group_id);
    }
    
    return true;
}

/**
 * Extend chat expiry date
 */
function tsc_extend_chat_expiry($therapy_group_id, $new_expiry_date) {
    
    $room_id = get_post_meta($therapy_group_id, '_tsc_chat_room_id', true);
    
    if (!$room_id) {
        return new WP_Error('no_chat', 'No chat room found');
    }
    
    $date = DateTime::createFromFormat('Y-m-d', $new_expiry_date);
    if (!$date) {
        return new WP_Error('invalid_date', 'Invalid date format');
    }
    
    update_post_meta($room_id, '_tsc_expiry_date', $new_expiry_date);
    
    // Reactivate if was expired
    $status = get_post_meta($room_id, '_tsc_status', true);
    if ($status === 'expired') {
        update_post_meta($room_id, '_tsc_status', 'active');
        delete_post_meta($therapy_group_id, '_tsc_chat_expired');
    }
    
    return true;
}
