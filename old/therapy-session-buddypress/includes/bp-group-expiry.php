<?php
/**
 * BuddyPress Group Expiry Handler
 * 
 * Handles automatic expiry of BP groups based on therapy session dates.
 * 
 * @package TherapySessionBuddyPress
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cron hook for checking expired groups
 */
add_action('tsbp_group_expiry_check', 'tsbp_check_expired_groups');

/**
 * Check and handle expired BP groups
 */
function tsbp_check_expired_groups() {
    
    error_log("[TSBP] Running expiry check cron job");
    
    // Get all therapy BP groups
    $bp_groups = tsbp_get_all_therapy_bp_groups(['per_page' => 100]);
    
    $today = current_time('Y-m-d');
    $expired_count = 0;
    
    foreach ($bp_groups as $bp_group) {
        
        $expiry_date = groups_get_groupmeta($bp_group->id, '_tsbp_expiry_date', true);
        $status = groups_get_groupmeta($bp_group->id, '_tsbp_status', true);
        
        // Skip if already expired/archived
        if ($status === 'expired' || $status === 'archived') {
            continue;
        }
        
        // Check if expired
        if ($expiry_date && $expiry_date < $today) {
            tsbp_expire_bp_group($bp_group->id);
            $expired_count++;
            error_log("[TSBP] Expired BP group {$bp_group->id} (expiry: {$expiry_date})");
        }
    }
    
    error_log("[TSBP] Expiry check completed. Expired {$expired_count} groups.");
}

/**
 * Expire a BP group
 * 
 * @param int $bp_group_id The BuddyPress group ID
 * @return bool
 */
function tsbp_expire_bp_group($bp_group_id) {
    
    $expiry_action = get_option('tsbp_expiry_action', 'archive');
    
    error_log("[TSBP] Expiring BP group {$bp_group_id} with action: {$expiry_action}");
    
    switch ($expiry_action) {
        case 'delete':
            // Permanently delete the group
            $therapy_group_id = groups_get_groupmeta($bp_group_id, '_tsbp_therapy_group_id', true);
            $result = groups_delete_group($bp_group_id);
            
            if ($result && $therapy_group_id) {
                delete_post_meta($therapy_group_id, '_tsbp_bp_group_id');
            }
            break;
            
        case 'hide':
        case 'archive':
        default:
            // Set group to hidden status
            tsbp_update_bp_group_status($bp_group_id, 'hidden');
            groups_update_groupmeta($bp_group_id, '_tsbp_status', 'expired');
            groups_update_groupmeta($bp_group_id, '_tsbp_expired_date', current_time('mysql'));
            $result = true;
            break;
    }
    
    if ($result) {
        /**
         * Action: After BP group is expired
         * 
         * @param int    $bp_group_id   The BuddyPress group ID
         * @param string $expiry_action The action taken (archive, delete, hide)
         */
        do_action('tsbp_bp_group_expired', $bp_group_id, $expiry_action);
    }
    
    return $result;
}

/**
 * Get groups expiring soon (for admin warnings)
 * 
 * @param int $days Number of days to look ahead
 * @return array Array of BP groups expiring within $days
 */
function tsbp_get_groups_expiring_soon($days = 7) {
    
    $bp_groups = tsbp_get_all_therapy_bp_groups(['per_page' => 100]);
    
    $expiring = [];
    $today = current_time('Y-m-d');
    $future_date = date('Y-m-d', strtotime("+{$days} days"));
    
    foreach ($bp_groups as $bp_group) {
        
        $expiry_date = groups_get_groupmeta($bp_group->id, '_tsbp_expiry_date', true);
        $status = groups_get_groupmeta($bp_group->id, '_tsbp_status', true);
        
        // Skip if already expired
        if ($status === 'expired' || $status === 'archived') {
            continue;
        }
        
        // Check if expiring within timeframe
        if ($expiry_date && $expiry_date >= $today && $expiry_date <= $future_date) {
            $bp_group->expiry_date = $expiry_date;
            $bp_group->days_until_expiry = (strtotime($expiry_date) - strtotime($today)) / DAY_IN_SECONDS;
            $expiring[] = $bp_group;
        }
    }
    
    // Sort by expiry date
    usort($expiring, function($a, $b) {
        return strcmp($a->expiry_date, $b->expiry_date);
    });
    
    return $expiring;
}

/**
 * Extend expiry date for a BP group
 * 
 * @param int    $bp_group_id The BuddyPress group ID
 * @param string $new_date    New expiry date (Y-m-d format)
 * @return bool
 */
function tsbp_extend_bp_group_expiry($bp_group_id, $new_date) {
    
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $new_date)) {
        return false;
    }
    
    groups_update_groupmeta($bp_group_id, '_tsbp_expiry_date', $new_date);
    
    // If group was expired, reactivate it
    $status = groups_get_groupmeta($bp_group_id, '_tsbp_status', true);
    if ($status === 'expired') {
        groups_update_groupmeta($bp_group_id, '_tsbp_status', 'active');
        
        // Set back to private
        $group_status = get_option('tsbp_group_status', 'private');
        tsbp_update_bp_group_status($bp_group_id, $group_status);
        
        error_log("[TSBP] Reactivated BP group {$bp_group_id} with new expiry {$new_date}");
    }
    
    return true;
}

/**
 * Sync expiry date from therapy_group to BP group
 * 
 * @param int $therapy_group_id The therapy_group post ID
 * @return bool
 */
function tsbp_sync_expiry_from_therapy_group($therapy_group_id) {
    
    $bp_group_id = tsbp_get_bp_group_id($therapy_group_id);
    
    if (!$bp_group_id) {
        return false;
    }
    
    $session_end_date = get_post_meta($therapy_group_id, 'session_end_date', true);
    
    if ($session_end_date) {
        return tsbp_extend_bp_group_expiry($bp_group_id, $session_end_date);
    }
    
    return false;
}

/**
 * Hook: Sync expiry when session_end_date meta is updated
 */
add_action('updated_post_meta', 'tsbp_sync_expiry_on_meta_update', 10, 4);
add_action('added_post_meta', 'tsbp_sync_expiry_on_meta_update', 10, 4);

function tsbp_sync_expiry_on_meta_update($meta_id, $post_id, $meta_key, $meta_value) {
    
    if (get_post_type($post_id) !== 'therapy_group') {
        return;
    }
    
    if ($meta_key !== 'session_end_date') {
        return;
    }
    
    $bp_group_id = tsbp_get_bp_group_id($post_id);
    
    if (!$bp_group_id) {
        return;
    }
    
    if ($meta_value) {
        groups_update_groupmeta($bp_group_id, '_tsbp_expiry_date', sanitize_text_field($meta_value));
        error_log("[TSBP] Synced expiry date to BP group {$bp_group_id}: {$meta_value}");
    }
}

/**
 * Admin dashboard widget for expiring groups
 */
add_action('wp_dashboard_setup', 'tsbp_add_expiry_dashboard_widget');

function tsbp_add_expiry_dashboard_widget() {
    
    if (!current_user_can('manage_options')) {
        return;
    }
    
    wp_add_dashboard_widget(
        'tsbp_expiring_groups',
        'Therapy Groups Expiring Soon',
        'tsbp_expiry_dashboard_widget_content'
    );
}

function tsbp_expiry_dashboard_widget_content() {
    
    if (!function_exists('groups_get_group')) {
        echo '<p>BuddyPress is not active.</p>';
        return;
    }
    
    $expiring = tsbp_get_groups_expiring_soon(14);
    
    if (empty($expiring)) {
        echo '<p style="color: green;">✓ No therapy groups expiring in the next 14 days.</p>';
        return;
    }
    
    echo '<table class="widefat striped">';
    echo '<thead><tr><th>Group</th><th>Expires</th><th>Days Left</th></tr></thead>';
    echo '<tbody>';
    
    foreach ($expiring as $group) {
        $days = round($group->days_until_expiry);
        $color = $days <= 3 ? 'red' : ($days <= 7 ? 'orange' : 'inherit');
        
        printf(
            '<tr><td>%s</td><td>%s</td><td style="color:%s;font-weight:bold;">%d</td></tr>',
            esc_html($group->name),
            esc_html(date_i18n(get_option('date_format'), strtotime($group->expiry_date))),
            $color,
            $days
        );
    }
    
    echo '</tbody></table>';
}

/**
 * Manual expiry check trigger (for admin use)
 */
add_action('admin_init', 'tsbp_handle_manual_expiry_check');

function tsbp_handle_manual_expiry_check() {
    
    if (!isset($_GET['tsbp_action']) || $_GET['tsbp_action'] !== 'run_expiry_check') {
        return;
    }
    
    if (!current_user_can('manage_options')) {
        return;
    }
    
    if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'tsbp_run_expiry_check')) {
        return;
    }
    
    tsbp_check_expired_groups();
    
    set_transient('tsbp_admin_notice', [
        'type'    => 'success',
        'message' => 'Expiry check completed successfully.',
    ], 30);
    
    wp_safe_redirect(admin_url('index.php'));
    exit;
}
