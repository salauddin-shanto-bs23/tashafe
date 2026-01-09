<?php
/**
 * BuddyPress Group Hooks
 * 
 * Automatic BP group creation and sync with therapy sessions.
 * 
 * @package TherapySessionBuddyPress
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ============================================
 * HOOK 1: Auto-create BP group when therapy_group is published
 * ============================================
 */
add_action('save_post_therapy_group', 'tsbp_auto_create_bp_group_on_publish', 20, 3);

function tsbp_auto_create_bp_group_on_publish($post_id, $post, $update) {
    
    error_log("[TSBP] save_post_therapy_group triggered for post {$post_id}");
    
    // Skip autosaves
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        error_log("[TSBP] Skipping - autosave");
        return;
    }
    
    if (wp_is_post_revision($post_id)) {
        error_log("[TSBP] Skipping - revision");
        return;
    }
    
    // Only for published posts
    if ($post->post_status !== 'publish') {
        error_log("[TSBP] Skipping - post not published (status: {$post->post_status})");
        return;
    }
    
    // Check if already has BP group
    $existing = get_post_meta($post_id, '_tsbp_bp_group_id', true);
    if ($existing) {
        // Verify the BP group still exists
        if (function_exists('groups_get_group')) {
            $group = groups_get_group($existing);
            if ($group && !empty($group->id)) {
                error_log("[TSBP] BP group already exists: {$existing}");
                return;
            }
        }
    }
    
    // Check if BuddyPress is ready
    if (!function_exists('groups_create_group')) {
        error_log("[TSBP] BuddyPress not ready, skipping BP group creation");
        return;
    }
    
    // Get details
    $group_name = $post->post_title;
    $issue_type = get_post_meta($post_id, 'issue_type', true);
    $gender = get_post_meta($post_id, 'gender', true);
    
    $description = sprintf(
        'Therapy session group - %s. Issue: %s, Gender: %s',
        $group_name,
        ucfirst($issue_type ?: 'General'),
        ucfirst($gender ?: 'All')
    );
    
    // Get expiry date
    $expiry_date = get_post_meta($post_id, 'session_end_date', true);
    if (!$expiry_date) {
        $default_days = get_option('tsbp_default_expiry_days', 30);
        $expiry_date = date('Y-m-d', strtotime("+{$default_days} days"));
    }
    
    // Get creator (current user or post author)
    $creator_id = get_current_user_id();
    if (!$creator_id) {
        $creator_id = $post->post_author;
    }
    
    // Create BP group
    $bp_group_id = tsbp_create_bp_group(
        $post_id,
        $group_name,
        $description,
        $creator_id,
        $expiry_date
    );
    
    if ($bp_group_id) {
        error_log("[TSBP] Auto-created BP group {$bp_group_id} for therapy_group {$post_id}");
        
        // IMPORTANT: Create BM thread immediately and add admin
        if (function_exists('tsbp_create_bm_thread')) {
            $thread_id = tsbp_create_bm_thread($post_id, $group_name, $creator_id);
            if ($thread_id) {
                // Add the creator (admin) to the BM thread
                tsbp_add_user_to_bm_thread($creator_id, $thread_id);
                error_log("[TSBP] Created BM thread {$thread_id} and added admin {$creator_id}");
            }
        }
    } else {
        error_log("[TSBP] FAILED to create BP group for therapy_group {$post_id}");
    }
}

/**
 * ============================================
 * HOOK 2: Sync BP group status with therapy group status
 * ============================================
 */
add_action('updated_post_meta', 'tsbp_sync_bp_group_on_status_change', 10, 4);

function tsbp_sync_bp_group_on_status_change($meta_id, $post_id, $meta_key, $meta_value) {
    
    if (get_post_type($post_id) !== 'therapy_group') {
        return;
    }
    
    if ($meta_key !== 'group_status') {
        return;
    }
    
    $bp_group_id = tsbp_get_bp_group_id($post_id);
    
    if (!$bp_group_id) {
        return;
    }
    
    error_log("[TSBP] Therapy group {$post_id} status changed to: {$meta_value}");
    
    if ($meta_value === 'inactive' || $meta_value === 'completed') {
        // Archive the BP group (set to hidden)
        tsbp_archive_bp_group($post_id);
        error_log("[TSBP] BP group {$bp_group_id} archived - therapy group status: {$meta_value}");
    } elseif ($meta_value === 'active') {
        // Reactivate the BP group (set to private)
        $group_status = get_option('tsbp_group_status', 'private');
        tsbp_update_bp_group_status($bp_group_id, $group_status);
        groups_update_groupmeta($bp_group_id, '_tsbp_status', 'active');
        error_log("[TSBP] BP group {$bp_group_id} reactivated");
    }
}

/**
 * ============================================
 * HOOK 3: Delete BP group when therapy_group is deleted
 * ============================================
 */
add_action('before_delete_post', 'tsbp_delete_bp_group_with_therapy_group', 10, 1);

function tsbp_delete_bp_group_with_therapy_group($post_id) {
    
    if (get_post_type($post_id) !== 'therapy_group') {
        return;
    }
    
    $bp_group_id = tsbp_get_bp_group_id($post_id);
    
    if (!$bp_group_id) {
        return;
    }
    
    // Check option for what to do on delete
    $expiry_action = get_option('tsbp_expiry_action', 'archive');
    
    if ($expiry_action === 'delete') {
        tsbp_delete_bp_group($post_id);
        error_log("[TSBP] Deleted BP group {$bp_group_id} with therapy_group {$post_id}");
    } else {
        // Archive instead of delete
        tsbp_archive_bp_group($post_id);
        error_log("[TSBP] Archived BP group {$bp_group_id} (therapy_group {$post_id} deleted)");
    }
}

/**
 * ============================================
 * HOOK 4: Handle therapy group title update - sync to BP group
 * ============================================
 */
add_action('post_updated', 'tsbp_sync_bp_group_name_on_update', 10, 3);

function tsbp_sync_bp_group_name_on_update($post_id, $post_after, $post_before) {
    
    if (get_post_type($post_id) !== 'therapy_group') {
        return;
    }
    
    // Check if title changed
    if ($post_after->post_title === $post_before->post_title) {
        return;
    }
    
    $bp_group_id = tsbp_get_bp_group_id($post_id);
    
    if (!$bp_group_id) {
        return;
    }
    
    // Update BP group name
    global $wpdb;
    $bp = buddypress();
    
    $wpdb->update(
        $bp->groups->table_name,
        ['name' => $post_after->post_title],
        ['id' => $bp_group_id],
        ['%s'],
        ['%d']
    );
    
    // Clear cache
    wp_cache_delete($bp_group_id, 'bp_groups');
    
    error_log("[TSBP] Updated BP group {$bp_group_id} name to: {$post_after->post_title}");
}

/**
 * ============================================
 * HOOK 5: Create BP group for existing therapy_groups (bulk action)
 * ============================================
 */
add_action('admin_init', 'tsbp_handle_bulk_create_bp_groups');

function tsbp_handle_bulk_create_bp_groups() {
    
    if (!isset($_GET['tsbp_action']) || $_GET['tsbp_action'] !== 'create_missing_groups') {
        return;
    }
    
    if (!current_user_can('manage_options')) {
        return;
    }
    
    if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'tsbp_create_missing_groups')) {
        return;
    }
    
    // Find all therapy_groups without BP groups
    $args = [
        'post_type'      => 'therapy_group',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'meta_query'     => [
            [
                'key'     => '_tsbp_bp_group_id',
                'compare' => 'NOT EXISTS',
            ],
        ],
    ];
    
    $therapy_groups = get_posts($args);
    $created = 0;
    
    foreach ($therapy_groups as $therapy_group) {
        $issue_type = get_post_meta($therapy_group->ID, 'issue_type', true);
        $gender = get_post_meta($therapy_group->ID, 'gender', true);
        
        $description = sprintf(
            'Therapy session group - %s. Issue: %s, Gender: %s',
            $therapy_group->post_title,
            ucfirst($issue_type ?: 'General'),
            ucfirst($gender ?: 'All')
        );
        
        $expiry_date = get_post_meta($therapy_group->ID, 'session_end_date', true);
        if (!$expiry_date) {
            $default_days = get_option('tsbp_default_expiry_days', 30);
            $expiry_date = date('Y-m-d', strtotime("+{$default_days} days"));
        }
        
        $bp_group_id = tsbp_create_bp_group(
            $therapy_group->ID,
            $therapy_group->post_title,
            $description,
            $therapy_group->post_author,
            $expiry_date
        );
        
        if ($bp_group_id) {
            $created++;
            
            // Also create BM thread and add admin
            if (function_exists('tsbp_create_bm_thread')) {
                $thread_id = tsbp_create_bm_thread($therapy_group->ID, $therapy_group->post_title, $therapy_group->post_author);
                if ($thread_id) {
                    tsbp_add_user_to_bm_thread($therapy_group->post_author, $thread_id);
                }
            }
        }
    }
    
    // Store notice for display
    set_transient('tsbp_admin_notice', [
        'type'    => 'success',
        'message' => sprintf('Created %d BP groups for existing therapy sessions.', $created),
    ], 30);
    
    // Redirect back
    wp_safe_redirect(admin_url('edit.php?post_type=therapy_group'));
    exit;
}

/**
 * ============================================
 * HOOK 6: Create BM threads for existing BP groups (bulk action)
 * ============================================
 */
add_action('admin_init', 'tsbp_handle_bulk_create_bm_threads');

function tsbp_handle_bulk_create_bm_threads() {
    
    if (!isset($_GET['tsbp_action']) || $_GET['tsbp_action'] !== 'create_bm_threads') {
        return;
    }
    
    if (!current_user_can('manage_options')) {
        return;
    }
    
    if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'tsbp_create_bm_threads')) {
        return;
    }
    
    // Find all therapy_groups WITH BP groups but WITHOUT BM threads
    $args = [
        'post_type'      => 'therapy_group',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'meta_query'     => [
            [
                'key'     => '_tsbp_bp_group_id',
                'compare' => 'EXISTS',
            ],
        ],
    ];
    
    $therapy_groups = get_posts($args);
    $created = 0;
    $admin_id = get_current_user_id();
    
    foreach ($therapy_groups as $therapy_group) {
        // Check if BM thread already exists
        $existing_thread = get_post_meta($therapy_group->ID, '_tsbp_bm_thread_id', true);
        if ($existing_thread) {
            // Thread exists, make sure admin is in it
            tsbp_add_user_to_bm_thread($admin_id, $existing_thread);
            continue;
        }
        
        // Create BM thread
        $thread_id = tsbp_create_bm_thread($therapy_group->ID, $therapy_group->post_title, $admin_id);
        
        if ($thread_id) {
            // Add admin to thread
            tsbp_add_user_to_bm_thread($admin_id, $thread_id);
            $created++;
            
            // Also sync any existing BP group members
            tsbp_sync_bp_group_to_bm_thread($therapy_group->ID);
        }
    }
    
    // Store notice for display
    set_transient('tsbp_admin_notice', [
        'type'    => 'success',
        'message' => sprintf('Created %d chat threads. Admin added to all therapy group chats.', $created),
    ], 30);
    
    // Redirect back
    wp_safe_redirect(admin_url('edit.php?post_type=therapy_group'));
    exit;
}

/**
 * Display admin notices from transient
 */
add_action('admin_notices', 'tsbp_display_admin_notices');

function tsbp_display_admin_notices() {
    $notice = get_transient('tsbp_admin_notice');
    
    if (!$notice) {
        return;
    }
    
    delete_transient('tsbp_admin_notice');
    
    printf(
        '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
        esc_attr($notice['type']),
        esc_html($notice['message'])
    );
}
