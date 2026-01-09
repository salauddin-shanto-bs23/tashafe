<?php
/**
 * Therapy BuddyPress Chat Automation
 * 
 * Single-file snippet for WPCode/Code Snippets Manager
 * 
 * Features:
 * - Automatically creates PRIVATE BuddyPress groups when therapy groups are created
 * - Automatically enrolls users when they register for therapy sessions
 * - Avoids duplicate group creation
 * - Integrates with Better Messages for chat UI
 * - Creates groups AFTER ACF saves fields (ensures all data is available)
 * 
 * Requirements:
 * - BuddyPress (with Groups component enabled)
 * - Better Messages (with BuddyPress Groups integration enabled)
 * - Ultimate Member (for user registration)
 * - Custom Post Type: therapy_group
 * - ACF Fields: issue_type, gender, max_members, session_start_date, session_expiry_date
 * 
 * @version 2.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// ============================================================================
// SECTION 1: AUTOMATIC BUDDYPRESS GROUP CREATION
// ============================================================================

/**
 * NOTE: BP group creation is handled by acf/save_post hook below
 * We do NOT use save_post_therapy_group because it fires BEFORE ACF saves fields
 * This ensures session_expiry_date and other ACF fields are available during creation
 */

// ============================================================================
// SECTION 1: BUDDYPRESS GROUP CREATION (via ACF hook)
// ============================================================================

/**
 * Create or update BuddyPress group AFTER ACF has saved all fields
 * This ensures all ACF field data is available during BP group creation
 * 
 * Triggered by: acf/save_post (fires AFTER ACF saves fields)
 */
add_action('acf/save_post', 'tbc_acf_save_post', 20);

function tbc_acf_save_post($post_id) {
    // Check if this is a therapy_group post
    if (get_post_type($post_id) !== 'therapy_group') {
        return;
    }
    
    // Only proceed if post is published
    $post = get_post($post_id);
    if (!$post || $post->post_status !== 'publish') {
        return;
    }
    
    // Check if BP group already exists
    $existing_bp_group_id = get_post_meta($post_id, '_tbc_bp_group_id', true);
    
    if (!$existing_bp_group_id) {
        // BP group doesn't exist - create it now (ACF fields are available)
        error_log("[TBC] ACF fields saved, creating BP group for therapy_group {$post_id}");
        tbc_auto_create_bp_group($post_id, $post, true);
    } else {
        // BP group exists - update description if expiry date changed
        error_log("[TBC] ACF fields updated for therapy_group {$post_id}, updating BP group {$existing_bp_group_id}");
        tbc_update_bp_group_description($post_id, $existing_bp_group_id);
    }
}

/**
 * FALLBACK: Also hook into save_post with low priority
 * This ensures BP group is created even if ACF hook doesn't fire (e.g., programmatic creation)
 * Priority 999 ensures this runs AFTER ACF saves fields
 */
add_action('save_post_therapy_group', 'tbc_fallback_create_bp_group', 999, 3);

function tbc_fallback_create_bp_group($therapy_group_id, $post, $update) {
    // Skip if autosave or revision
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    
    // Skip if not published
    if ($post->post_status !== 'publish') {
        return;
    }
    
    // Check if BP group already exists
    $existing_bp_group_id = get_post_meta($therapy_group_id, '_tbc_bp_group_id', true);
    
    if (!$existing_bp_group_id) {
        // BP group doesn't exist - create it
        error_log("[TBC] Fallback: Creating BP group for therapy_group {$therapy_group_id}");
        tbc_auto_create_bp_group($therapy_group_id, $post, $update);
    }
}

/**
 * Create BuddyPress group for therapy group
 * Called from acf/save_post hook to ensure ACF fields are available
 */

function tbc_auto_create_bp_group($therapy_group_id, $post, $update, $session_expiry_date_override = null) {
    
    // Only proceed if BuddyPress is active and groups component is enabled
    if (!function_exists('groups_create_group') || !function_exists('bp_is_active') || !bp_is_active('groups')) {
        error_log('[TBC] BuddyPress Groups not active. Cannot create group.');
        return;
    }
    
    // Skip if this is an autosave or revision
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    
    // Check if BP group already exists (double-check to prevent duplicates)
    $existing_bp_group_id = get_post_meta($therapy_group_id, '_tbc_bp_group_id', true);
    
    if ($existing_bp_group_id) {
        // Verify the BP group still exists
        if (function_exists('groups_get_group')) {
            $existing_group = groups_get_group($existing_bp_group_id);
            if ($existing_group && !empty($existing_group->id)) {
                error_log("[TBC] BP group already exists for therapy_group {$therapy_group_id}: BP Group ID {$existing_bp_group_id}");
                return; // Group exists, no need to create
            }
        }
    }
    
    // Get therapy group details
    $group_name = $post->post_title;
    
    // Try multiple methods to get session_expiry_date
    $session_expiry = '';
    
    // Method 0: Check if passed directly (from admin dashboard during creation)
    if (!empty($session_expiry_date_override)) {
        $session_expiry = $session_expiry_date_override;
        error_log("[TBC] Using directly passed session_expiry_date: " . var_export($session_expiry, true));
    }
    
    // Method 1: ACF get_field
    if (empty($session_expiry) && function_exists('get_field')) {
        $session_expiry = get_field('session_expiry_date', $therapy_group_id);
        error_log("[TBC] ACF get_field('session_expiry_date'): " . var_export($session_expiry, true));
    }
    
    // Method 2: Fallback to get_post_meta if ACF returns nothing
    if (empty($session_expiry)) {
        $session_expiry = get_post_meta($therapy_group_id, 'session_expiry_date', true);
        error_log("[TBC] get_post_meta('session_expiry_date'): " . var_export($session_expiry, true));
    }
    
    // Method 3: Try with ACF field key prefix (sometimes ACF stores with underscore)
    if (empty($session_expiry)) {
        $session_expiry = get_post_meta($therapy_group_id, '_session_expiry_date', true);
        error_log("[TBC] get_post_meta('_session_expiry_date'): " . var_export($session_expiry, true));
    }
    
    error_log("[TBC] Final session_expiry value for therapy_group {$therapy_group_id}: " . var_export($session_expiry, true));
    
    // Build group description with expiry notice
    $expiry_notice_date = '';
    if (!empty($session_expiry)) {
        error_log("[TBC] Processing session_expiry: '{$session_expiry}' (type: " . gettype($session_expiry) . ")");
        
        // Try multiple date format parsers
        $expiry_date_obj = false;
        
        // Try Y-m-d format
        $expiry_date_obj = DateTime::createFromFormat('Y-m-d', $session_expiry);
        if ($expiry_date_obj !== false) {
            error_log("[TBC] Successfully parsed with Y-m-d format");
        }
        
        // If failed, try Ymd format (ACF sometimes stores without dashes)
        if ($expiry_date_obj === false) {
            $expiry_date_obj = DateTime::createFromFormat('Ymd', $session_expiry);
            if ($expiry_date_obj !== false) {
                error_log("[TBC] Successfully parsed with Ymd format");
            }
        }
        
        // If still failed, try strtotime
        if ($expiry_date_obj === false) {
            $timestamp = strtotime($session_expiry);
            if ($timestamp !== false) {
                try {
                    $expiry_date_obj = new DateTime($session_expiry);
                    error_log("[TBC] Successfully parsed with strtotime/DateTime constructor");
                } catch (Exception $e) {
                    error_log("[TBC] DateTime constructor failed: " . $e->getMessage());
                }
            }
        }
        
        if ($expiry_date_obj !== false && $expiry_date_obj instanceof DateTime) {
            $expiry_date_obj->modify('+1 day');
            $expiry_notice_date = $expiry_date_obj->format('Y-m-d');
            error_log("[TBC] ✓ Calculated expiry notice date: {$expiry_notice_date}");
        } else {
            error_log("[TBC] ✗ FAILED to parse session_expiry_date: '{$session_expiry}'");
        }
    } else {
        error_log("[TBC] ✗ session_expiry_date is EMPTY for therapy_group {$therapy_group_id}");
    }
    
    $description = $expiry_notice_date 
        ? 'This group will expire on ' . $expiry_notice_date . '.'
        : 'Therapy group chat.';
    
    error_log("[TBC] BP Group description: {$description}");
    
    // Get creator (current user or first admin)
    $creator_id = get_current_user_id();
    if (!$creator_id) {
        $admins = get_users(['role' => 'administrator', 'number' => 1]);
        $creator_id = !empty($admins) ? $admins[0]->ID : 1;
    }
    
    // Generate unique slug
    $slug = sanitize_title($group_name . '-' . $therapy_group_id);
    
    // Create BuddyPress Group
    $bp_group_args = [
        'creator_id'   => $creator_id,
        'name'         => sanitize_text_field($group_name),
        'description'  => sanitize_textarea_field($description),
        'slug'         => $slug,
        'status'       => 'private', // PRIVATE: Listed but requires membership approval/invitation
        'enable_forum' => false,
        'date_created' => bp_core_current_time(),
    ];
    
    $bp_group_id = groups_create_group($bp_group_args);
    
    if (!$bp_group_id || is_wp_error($bp_group_id)) {
        error_log("[TBC] FAILED to create BP group for therapy_group {$therapy_group_id}");
        return;
    }
    
    error_log("[TBC] ✓ Successfully created PRIVATE BP group {$bp_group_id} for therapy_group {$therapy_group_id}");
    
    // Store bidirectional relationship
    update_post_meta($therapy_group_id, '_tbc_bp_group_id', $bp_group_id);
    groups_update_groupmeta($bp_group_id, '_tbc_therapy_group_id', $therapy_group_id);
    
    // Store expiry date in BP group meta for future automated cleanup
    if (!empty($session_expiry)) {
        groups_update_groupmeta($bp_group_id, '_tbc_expiry_date', sanitize_text_field($session_expiry));
    }
    
    // Mark group as active
    groups_update_groupmeta($bp_group_id, '_tbc_status', 'active');
    
    // Promote creator to admin
    if (function_exists('groups_promote_member')) {
        groups_promote_member($creator_id, $bp_group_id, 'admin');
    }
    
    error_log("[TBC] BP Group {$bp_group_id} configured: Private status, Expiry: {$session_expiry}, Description: {$description}");
}

// ============================================================================
// SECTION 2: AUTOMATIC USER ENROLLMENT
// ============================================================================
function tbc_update_bp_group_description($therapy_group_id, $bp_group_id) {
    if (!function_exists('groups_get_group')) {
        return;
    }
    
    // Get updated session_expiry_date
    $session_expiry = '';
    if (function_exists('get_field')) {
        $session_expiry = get_field('session_expiry_date', $therapy_group_id);
    }
    if (empty($session_expiry)) {
        $session_expiry = get_post_meta($therapy_group_id, 'session_expiry_date', true);
    }
    
    if (!empty($session_expiry)) {
        // Calculate expiry notice date
        $expiry_date_obj = DateTime::createFromFormat('Y-m-d', $session_expiry);
        if (!$expiry_date_obj) {
            $expiry_date_obj = DateTime::createFromFormat('Ymd', $session_expiry);
        }
        if (!$expiry_date_obj && strtotime($session_expiry)) {
            $expiry_date_obj = new DateTime($session_expiry);
        }
        
        if ($expiry_date_obj) {
            $expiry_date_obj->modify('+1 day');
            $expiry_notice_date = $expiry_date_obj->format('Y-m-d');
            $new_description = 'This group will expire on ' . $expiry_notice_date . '.';
            
            // Update BP group description using direct database update
            global $wpdb, $bp;
            $table_name = $bp->groups->table_name;
            
            $updated = $wpdb->update(
                $table_name,
                ['description' => $new_description],
                ['id' => $bp_group_id],
                ['%s'],
                ['%d']
            );
            
            if ($updated !== false) {
                // Also update the expiry meta
                groups_update_groupmeta($bp_group_id, '_tbc_expiry_date', $session_expiry);
                error_log("[TBC] ✓ Updated BP group {$bp_group_id} description: {$new_description}");
            } else {
                error_log("[TBC] ✗ Failed to update BP group {$bp_group_id} description");
            }
        }
    }
}

// ============================================================================
// SECTION 2: AUTOMATIC USER ENROLLMENT
// ============================================================================

/**
 * NOTE: Automatic user enrollment is now handled directly in User_Registration.php
 * This hook is disabled to prevent duplicate enrollment attempts.
 * The enrollment happens immediately after therapy group assignment for better reliability.
 * 
 * If you need to re-enable this hook, uncomment the add_action below.
 */

// DISABLED - Enrollment now happens in User_Registration.php
// add_action('um_registration_complete', 'tbc_auto_enroll_user_to_bp_group', 100, 2);

/**
 * Automatically enroll user into BuddyPress group when they register for therapy
 * 
 * This function is kept for backward compatibility and manual enrollment
 * but is no longer hooked to um_registration_complete
 */
function tbc_auto_enroll_user_to_bp_group($user_id, $args) {
    
    // Only proceed if BuddyPress is active
    if (!function_exists('groups_join_group') || !function_exists('bp_is_active') || !bp_is_active('groups')) {
        error_log('[TBC Enroll] BuddyPress Groups not active. Cannot enroll user.');
        return;
    }
    
    error_log("[TBC Enroll] ==== Starting BP enrollment for user ID: {$user_id} ====");
    
    // Get the therapy group the user was assigned to
    $assigned_therapy_group_id = get_user_meta($user_id, 'assigned_group', true);
    
    if (!$assigned_therapy_group_id) {
        error_log("[TBC Enroll] ✗ User {$user_id} has no assigned_group meta. Cannot enroll to BP group.");
        error_log("[TBC Enroll] This might mean the user wasn't assigned to any therapy group during registration.");
        return;
    }
    
    error_log("[TBC Enroll] ✓ User {$user_id} assigned to therapy_group: {$assigned_therapy_group_id}");
    
    // Get the corresponding BuddyPress group ID
    $bp_group_id = get_post_meta($assigned_therapy_group_id, '_tbc_bp_group_id', true);
    
    if (!$bp_group_id) {
        error_log("[TBC Enroll] ⚠ No BP group found for therapy_group {$assigned_therapy_group_id}. Attempting to create it...");
        
        // Try to trigger group creation
        $therapy_post = get_post($assigned_therapy_group_id);
        if ($therapy_post && $therapy_post->post_type === 'therapy_group') {
            tbc_auto_create_bp_group($assigned_therapy_group_id, $therapy_post, true);
            // Re-fetch BP group ID
            $bp_group_id = get_post_meta($assigned_therapy_group_id, '_tbc_bp_group_id', true);
        }
        
        if (!$bp_group_id) {
            error_log("[TBC Enroll] ✗ FAILED to create BP group for therapy_group {$assigned_therapy_group_id}");
            return;
        }
        
        error_log("[TBC Enroll] ✓ Successfully created BP group {$bp_group_id} for therapy_group {$assigned_therapy_group_id}");
    }
    
    error_log("[TBC Enroll] Found BP group ID: {$bp_group_id}");
    
    // Verify BP group exists
    if (function_exists('groups_get_group')) {
        $bp_group = groups_get_group($bp_group_id);
        if (!$bp_group || empty($bp_group->id)) {
            error_log("[TBC Enroll] ✗ BP group {$bp_group_id} does not exist in database. Cannot enroll user.");
            return;
        }
        error_log("[TBC Enroll] ✓ BP group {$bp_group_id} verified: '{$bp_group->name}'");
    }
    
    // Check if user is already a member
    if (function_exists('groups_is_user_member')) {
        if (groups_is_user_member($user_id, $bp_group_id)) {
            error_log("[TBC Enroll] ℹ User {$user_id} already member of BP group {$bp_group_id}");
            return;
        }
    }
    
    // Enroll user into BuddyPress group
    error_log("[TBC Enroll] Attempting to enroll user {$user_id} into BP group {$bp_group_id}...");
    $joined = groups_join_group($bp_group_id, $user_id);
    
    if ($joined) {
        error_log("[TBC Enroll] ✓✓✓ SUCCESS! User {$user_id} enrolled into BP group {$bp_group_id} (therapy_group {$assigned_therapy_group_id})");
        
        // Store enrollment metadata
        update_user_meta($user_id, '_tbc_bp_group_id', $bp_group_id);
        update_user_meta($user_id, '_tbc_enrollment_date', current_time('mysql'));
        
        // Get group info for logging
        $group_info = tbc_get_therapy_group_info($assigned_therapy_group_id);
        if ($group_info) {
            error_log("[TBC Enroll] Group Info - Issue: {$group_info['issue_type']}, Gender: {$group_info['gender']}, Expiry: {$group_info['session_expiry_date']}");
        }
        
        error_log("[TBC Enroll] ==== Enrollment complete ====");
    } else {
        error_log("[TBC Enroll] ✗✗✗ FAILED to enroll user {$user_id} into BP group {$bp_group_id}");
        error_log("[TBC Enroll] This could be due to: group doesn't exist, user is banned, or BuddyPress error");
    }
}

// ============================================================================
// SECTION 3: MANUAL ADMIN CONTROLS
// ============================================================================

/**
 * Add manual enrollment button to therapy group admin dashboard
 * Allows admins to manually add users to chat groups
 */
add_action('add_meta_boxes', 'tbc_add_group_member_metabox');

function tbc_add_group_member_metabox() {
    add_meta_box(
        'tbc_group_members',
        'BuddyPress Chat Group Members',
        'tbc_render_group_members_metabox',
        'therapy_group',
        'side',
        'default'
    );
}

function tbc_render_group_members_metabox($post) {
    $bp_group_id = get_post_meta($post->ID, '_tbc_bp_group_id', true);
    
    if (!$bp_group_id) {
        echo '<p>No BuddyPress group created yet. Save this therapy group to create one.</p>';
        return;
    }
    
    // Check if BP is active
    if (!function_exists('groups_get_group')) {
        echo '<p>BuddyPress is not active.</p>';
        return;
    }
    
    $bp_group = groups_get_group($bp_group_id);
    
    if (!$bp_group || empty($bp_group->id)) {
        echo '<p>BuddyPress group no longer exists.</p>';
        return;
    }
    
    echo '<p><strong>BP Group ID:</strong> ' . esc_html($bp_group_id) . '</p>';
    echo '<p><strong>Group Name:</strong> ' . esc_html($bp_group->name) . '</p>';
    echo '<p><strong>Status:</strong> Hidden (Private)</p>';
    
    // Get member count
    if (function_exists('groups_get_total_member_count')) {
        $member_count = groups_get_total_member_count($bp_group_id);
        echo '<p><strong>Members:</strong> ' . esc_html($member_count) . '</p>';
    }
    
    // Link to manage group in BuddyPress
    $bp_admin_url = admin_url('admin.php?page=bp-groups&gid=' . $bp_group_id);
    echo '<p><a href="' . esc_url($bp_admin_url) . '" class="button" target="_blank">Manage Group in BuddyPress</a></p>';
    
    // Manual add user section
    echo '<hr>';
    echo '<h4>Manually Add User</h4>';
    echo '<div id="tbc-manual-add">';
    echo '<input type="text" id="tbc-user-search" placeholder="Search username or email..." style="width:100%; margin-bottom:8px;">';
    echo '<div id="tbc-user-results"></div>';
    echo '<button type="button" id="tbc-add-user-btn" class="button button-secondary" style="width:100%; margin-top:8px;" disabled>Add to Chat Group</button>';
    echo '</div>';
    
    // Add inline JS for user search and add
    ?>
    <script>
    jQuery(document).ready(function($) {
        let selectedUserId = null;
        
        $('#tbc-user-search').on('keyup', function() {
            const searchTerm = $(this).val();
            if (searchTerm.length < 2) {
                $('#tbc-user-results').html('');
                return;
            }
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'tbc_search_users',
                    search: searchTerm,
                    nonce: '<?php echo wp_create_nonce('tbc_search_users'); ?>'
                },
                success: function(response) {
                    if (response.success && response.data.length > 0) {
                        let html = '<ul style="margin:0; padding:8px; background:#f9f9f9; max-height:150px; overflow-y:auto;">';
                        response.data.forEach(function(user) {
                            html += '<li style="padding:4px; cursor:pointer;" data-user-id="' + user.id + '">';
                            html += user.display_name + ' (' + user.user_email + ')';
                            html += '</li>';
                        });
                        html += '</ul>';
                        $('#tbc-user-results').html(html);
                        
                        $('#tbc-user-results li').on('click', function() {
                            selectedUserId = $(this).data('user-id');
                            $('#tbc-user-search').val($(this).text());
                            $('#tbc-user-results').html('');
                            $('#tbc-add-user-btn').prop('disabled', false);
                        });
                    } else {
                        $('#tbc-user-results').html('<p style="padding:8px; margin:0;">No users found</p>');
                    }
                }
            });
        });
        
        $('#tbc-add-user-btn').on('click', function() {
            if (!selectedUserId) return;
            
            const btn = $(this);
            btn.prop('disabled', true).text('Adding...');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'tbc_manual_add_user',
                    user_id: selectedUserId,
                    therapy_group_id: <?php echo $post->ID; ?>,
                    nonce: '<?php echo wp_create_nonce('tbc_manual_add'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        alert('User added successfully!');
                        location.reload();
                    } else {
                        alert('Error: ' + response.data);
                        btn.prop('disabled', false).text('Add to Chat Group');
                    }
                }
            });
        });
    });
    </script>
    <?php
}

// AJAX handler for user search
add_action('wp_ajax_tbc_search_users', 'tbc_ajax_search_users');

function tbc_ajax_search_users() {
    check_ajax_referer('tbc_search_users', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }
    
    $search = sanitize_text_field($_POST['search']);
    
    $users = get_users([
        'search' => '*' . $search . '*',
        'search_columns' => ['user_login', 'user_email', 'display_name'],
        'number' => 10
    ]);
    
    $results = [];
    foreach ($users as $user) {
        $results[] = [
            'id' => $user->ID,
            'display_name' => $user->display_name,
            'user_email' => $user->user_email
        ];
    }
    
    wp_send_json_success($results);
}

// AJAX handler for manual user add
add_action('wp_ajax_tbc_manual_add_user', 'tbc_ajax_manual_add_user');

function tbc_ajax_manual_add_user() {
    check_ajax_referer('tbc_manual_add', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }
    
    $user_id = intval($_POST['user_id']);
    $therapy_group_id = intval($_POST['therapy_group_id']);
    
    if (!$user_id || !$therapy_group_id) {
        wp_send_json_error('Invalid data');
    }
    
    // Get BP group ID
    $bp_group_id = get_post_meta($therapy_group_id, '_tbc_bp_group_id', true);
    
    if (!$bp_group_id) {
        wp_send_json_error('No BuddyPress group exists for this therapy group');
    }
    
    // Check if BuddyPress is active
    if (!function_exists('groups_join_group')) {
        wp_send_json_error('BuddyPress is not active');
    }
    
    // Check if already member
    if (function_exists('groups_is_user_member')) {
        if (groups_is_user_member($user_id, $bp_group_id)) {
            wp_send_json_error('User is already a member');
        }
    }
    
    // Add user to group
    $joined = groups_join_group($bp_group_id, $user_id);
    
    if ($joined) {
        // Also update user meta for consistency
        update_user_meta($user_id, 'assigned_group', $therapy_group_id);
        update_user_meta($user_id, '_tbc_bp_group_id', $bp_group_id);
        update_user_meta($user_id, '_tbc_manually_added', current_time('mysql'));
        
        error_log("[TBC] Admin manually added user {$user_id} to BP group {$bp_group_id}");
        wp_send_json_success('User added successfully');
    } else {
        wp_send_json_error('Failed to add user to group');
    }
}

// ============================================================================
// SECTION 4: GROUP EXPIRY & CLEANUP
// ============================================================================

/**
 * Daily cron job to check and handle expired therapy groups
 * Archives or deletes expired BuddyPress groups
 */
add_action('init', 'tbc_schedule_expiry_check');

function tbc_schedule_expiry_check() {
    if (!wp_next_scheduled('tbc_daily_expiry_check')) {
        wp_schedule_event(time(), 'daily', 'tbc_daily_expiry_check');
    }
}

add_action('tbc_daily_expiry_check', 'tbc_process_expired_groups');

function tbc_process_expired_groups() {
    
    // Check if BuddyPress is active
    if (!function_exists('groups_delete_group')) {
        return;
    }
    
    error_log('[TBC] Running daily expiry check...');
    
    // Get all therapy groups
    $therapy_groups = get_posts([
        'post_type' => 'therapy_group',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'meta_query' => [
            [
                'key' => '_tbc_bp_group_id',
                'compare' => 'EXISTS'
            ]
        ]
    ]);
    
    $today = current_time('Y-m-d');
    
    foreach ($therapy_groups as $therapy_group) {
        $expiry_date = function_exists('get_field') 
            ? get_field('session_expiry_date', $therapy_group->ID) 
            : get_post_meta($therapy_group->ID, 'session_expiry_date', true);
        
        if (!$expiry_date) {
            continue;
        }
        
        // Check if expired
        if ($expiry_date < $today) {
            $bp_group_id = get_post_meta($therapy_group->ID, '_tbc_bp_group_id', true);
            
            if ($bp_group_id) {
                // Option 1: Archive the group (set status to inactive)
                groups_update_groupmeta($bp_group_id, '_tbc_status', 'expired');
                
                // Option 2: Delete the group completely (uncomment if preferred)
                // groups_delete_group($bp_group_id);
                // delete_post_meta($therapy_group->ID, '_tbc_bp_group_id');
                
                error_log("[TBC] Marked BP group {$bp_group_id} as expired (therapy_group {$therapy_group->ID})");
            }
        }
    }
}

// ============================================================================
// SECTION 5: ADMIN NOTIFICATIONS & DIAGNOSTICS
// ============================================================================

/**
 * Add admin notice if BuddyPress or Better Messages is not active
 */
add_action('admin_notices', 'tbc_dependency_check');

function tbc_dependency_check() {
    
    $missing = [];
    
    // Check BuddyPress
    if (!function_exists('buddypress') && !class_exists('BuddyPress')) {
        $missing[] = 'BuddyPress';
    } elseif (function_exists('bp_is_active') && !bp_is_active('groups')) {
        $missing[] = 'BuddyPress Groups Component (enable in Settings > BuddyPress > Components)';
    }
    
    // Check Better Messages (optional but recommended)
    if (!class_exists('Better_Messages')) {
        $missing[] = 'Better Messages (recommended for chat UI)';
    }
    
    if (!empty($missing)) {
        ?>
        <div class="notice notice-warning">
            <p><strong>Therapy BuddyPress Automation:</strong> The following are required or recommended:</p>
            <ul style="list-style-type: disc; margin-left: 20px;">
                <?php foreach ($missing as $item): ?>
                    <li><?php echo esc_html($item); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }
}

/**
 * Add diagnostic information to admin bar
 */
add_action('admin_bar_menu', 'tbc_admin_bar_diagnostic', 100);

function tbc_admin_bar_diagnostic($wp_admin_bar) {
    
    if (!current_user_can('manage_options')) {
        return;
    }
    
    // Count active BP groups
    $bp_group_count = 0;
    if (function_exists('groups_get_groups')) {
        $groups = groups_get_groups([
            'meta_query' => [
                [
                    'key' => '_tbc_status',
                    'value' => 'active',
                    'compare' => '='
                ]
            ]
        ]);
        $bp_group_count = $groups['total'];
    }
    
    $wp_admin_bar->add_node([
        'id' => 'tbc-diagnostic',
        'title' => 'Therapy Groups: ' . $bp_group_count . ' active',
        'href' => admin_url('edit.php?post_type=therapy_group')
    ]);
}

// ============================================================================
// SECTION 6: UTILITY FUNCTIONS
// ============================================================================

/**
 * Get BuddyPress group ID from therapy group ID
 */
function tbc_get_bp_group_id($therapy_group_id) {
    return get_post_meta($therapy_group_id, '_tbc_bp_group_id', true);
}

/**
 * Get therapy group ID from BuddyPress group ID
 */
function tbc_get_therapy_group_id($bp_group_id) {
    if (!function_exists('groups_get_groupmeta')) {
        return false;
    }
    return groups_get_groupmeta($bp_group_id, '_tbc_therapy_group_id', true);
}

/**
 * Check if user is enrolled in therapy group's chat
 */
function tbc_is_user_enrolled($user_id, $therapy_group_id) {
    $bp_group_id = tbc_get_bp_group_id($therapy_group_id);
    
    if (!$bp_group_id || !function_exists('groups_is_user_member')) {
        return false;
    }
    
    return groups_is_user_member($user_id, $bp_group_id);
}

/**
 * Get all necessary information about a therapy group for user enrollment
 * Only declare if not already defined (may be in admin dashboard file)
 * 
 * @param int $therapy_group_id The therapy_group post ID
 * @return array|false Array with group info or false on failure
 */
if (!function_exists('tbc_get_therapy_group_info')) {
    function tbc_get_therapy_group_info($therapy_group_id) {
        $post = get_post($therapy_group_id);
        
        if (!$post || $post->post_type !== 'therapy_group') {
            error_log("[TBC] Invalid therapy group ID: {$therapy_group_id}");
            return false;
        }
        
        // Get BuddyPress group ID
        $bp_group_id = get_post_meta($therapy_group_id, '_tbc_bp_group_id', true);
        
        // Get ACF fields
        $info = [
            'post_id' => $therapy_group_id,
            'post_title' => $post->post_title,
            'post_status' => $post->post_status,
            'bp_group_id' => $bp_group_id ? intval($bp_group_id) : 0,
            'issue_type' => '',
            'gender' => '',
            'session_start_date' => '',
            'session_expiry_date' => '',
            'max_members' => 0,
        ];
        
        // Try to get fields via ACF if available
        if (function_exists('get_field')) {
            $info['issue_type'] = get_field('issue_type', $therapy_group_id) ?: '';
            $info['gender'] = get_field('gender', $therapy_group_id) ?: '';
            $info['session_start_date'] = get_field('session_start_date', $therapy_group_id) ?: '';
            $info['session_expiry_date'] = get_field('session_expiry_date', $therapy_group_id) ?: '';
            $info['max_members'] = get_field('max_members', $therapy_group_id) ?: 0;
        }
        
        // Fallback to post_meta if ACF not available or returns empty
        if (empty($info['issue_type'])) {
            $info['issue_type'] = get_post_meta($therapy_group_id, 'issue_type', true);
        }
        if (empty($info['gender'])) {
            $info['gender'] = get_post_meta($therapy_group_id, 'gender', true);
        }
        if (empty($info['session_expiry_date'])) {
            $info['session_expiry_date'] = get_post_meta($therapy_group_id, 'session_expiry_date', true);
        }
        if (empty($info['session_start_date'])) {
            $info['session_start_date'] = get_post_meta($therapy_group_id, 'session_start_date', true);
        }
        if (empty($info['max_members'])) {
            $info['max_members'] = get_post_meta($therapy_group_id, 'max_members', true);
        }
        
        // Verify BuddyPress group exists
        if ($bp_group_id && function_exists('groups_get_group')) {
            $bp_group = groups_get_group($bp_group_id);
            if ($bp_group && !empty($bp_group->id)) {
                $info['bp_group_exists'] = true;
                $info['bp_group_name'] = $bp_group->name;
                $info['bp_group_slug'] = $bp_group->slug;
                $info['bp_group_status'] = $bp_group->status;
            } else {
                $info['bp_group_exists'] = false;
            }
        } else {
            $info['bp_group_exists'] = false;
        }
        
        return $info;
    }
}

// ============================================================================
// SECTION 7: MANUAL REPAIR TOOLS
// ============================================================================

/**
 * Shortcode to manually repair BP group descriptions
 * Usage: [repair_bp_groups]
 */
add_shortcode('repair_bp_groups', 'tbc_repair_bp_groups_shortcode');

function tbc_repair_bp_groups_shortcode() {
    // Check admin permission
    if (!current_user_can('manage_options')) {
        return '<p style="color: red;">⛔ You need administrator permission to use this tool.</p>';
    }
    
    ob_start();
    
    // Handle repair action
    if (isset($_POST['tbc_repair_all']) && check_admin_referer('tbc_repair_groups', 'tbc_repair_nonce')) {
        $repaired = tbc_repair_all_bp_groups();
        echo '<div style="background: #d4edda; border: 2px solid #28a745; padding: 15px; margin: 20px 0; border-radius: 5px;">';
        echo '<h3 style="margin-top: 0; color: #155724;">✅ Repair Complete</h3>';
        echo '<p><strong>Groups Repaired:</strong> ' . $repaired . '</p>';
        echo '</div>';
    }
    
    // Get all therapy groups with BP groups
    $therapy_groups = get_posts([
        'post_type' => 'therapy_group',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'meta_query' => [
            [
                'key' => '_tbc_bp_group_id',
                'compare' => 'EXISTS'
            ]
        ]
    ]);
    
    echo '<div style="background: #f8f9fa; padding: 20px; margin: 20px 0; border: 2px solid #333; border-radius: 5px;">';
    echo '<h2 style="margin-top: 0;">🔧 Repair BuddyPress Group Descriptions</h2>';
    echo '<p>This tool will update all BuddyPress group descriptions with the correct expiry dates.</p>';
    
    if (empty($therapy_groups)) {
        echo '<p style="color: orange;">⚠️ No therapy groups with BuddyPress groups found.</p>';
    } else {
        echo '<p><strong>Found ' . count($therapy_groups) . ' therapy group(s) with BP groups</strong></p>';
        
        // Show table of groups
        echo '<table style="width: 100%; border-collapse: collapse; background: white; margin: 20px 0;">';
        echo '<thead><tr style="background: #333; color: white;">';
        echo '<th style="padding: 10px; text-align: left;">Therapy Group</th>';
        echo '<th style="padding: 10px; text-align: left;">BP Group ID</th>';
        echo '<th style="padding: 10px; text-align: left;">Current Description</th>';
        echo '<th style="padding: 10px; text-align: left;">Session Expiry</th>';
        echo '<th style="padding: 10px; text-align: left;">Status</th>';
        echo '</tr></thead><tbody>';
        
        foreach ($therapy_groups as $tg) {
            $bp_group_id = get_post_meta($tg->ID, '_tbc_bp_group_id', true);
            $session_expiry = get_field('session_expiry_date', $tg->ID);
            if (empty($session_expiry)) {
                $session_expiry = get_post_meta($tg->ID, 'session_expiry_date', true);
            }
            
            $bp_group = function_exists('groups_get_group') ? groups_get_group($bp_group_id) : null;
            $current_desc = $bp_group ? $bp_group->description : 'N/A';
            
            $needs_repair = $current_desc === 'Therapy group chat.';
            
            echo '<tr style="background: ' . ($needs_repair ? '#fff3cd' : 'white') . ';">';
            echo '<td style="padding: 8px; border: 1px solid #ddd;">' . esc_html($tg->post_title) . ' <small>(ID: ' . $tg->ID . ')</small></td>';
            echo '<td style="padding: 8px; border: 1px solid #ddd;">' . esc_html($bp_group_id) . '</td>';
            echo '<td style="padding: 8px; border: 1px solid #ddd;">' . esc_html($current_desc) . '</td>';
            echo '<td style="padding: 8px; border: 1px solid #ddd;">' . ($session_expiry ? esc_html($session_expiry) : '<span style="color: red;">MISSING</span>') . '</td>';
            echo '<td style="padding: 8px; border: 1px solid #ddd;">';
            if ($needs_repair) {
                echo '<span style="color: orange; font-weight: bold;">⚠️ NEEDS REPAIR</span>';
            } else {
                echo '<span style="color: green;">✅ OK</span>';
            }
            echo '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
        
        // Repair button
        echo '<form method="post" style="margin-top: 20px;">';
        wp_nonce_field('tbc_repair_groups', 'tbc_repair_nonce');
        echo '<button type="submit" name="tbc_repair_all" class="button button-primary" style="padding: 10px 20px; font-size: 16px; background: #28a745; border-color: #28a745;">';
        echo '🔧 Repair All Groups';
        echo '</button>';
        echo '<p style="color: #666; font-size: 12px; margin-top: 10px;">This will update all BuddyPress group descriptions with correct expiry dates.</p>';
        echo '</form>';
    }
    
    echo '</div>';
    
    return ob_get_clean();
}

/**
 * Repair all BP group descriptions
 */
function tbc_repair_all_bp_groups() {
    $therapy_groups = get_posts([
        'post_type' => 'therapy_group',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'meta_query' => [
            [
                'key' => '_tbc_bp_group_id',
                'compare' => 'EXISTS'
            ]
        ]
    ]);
    
    $repaired_count = 0;
    
    foreach ($therapy_groups as $tg) {
        $bp_group_id = get_post_meta($tg->ID, '_tbc_bp_group_id', true);
        if (!$bp_group_id) {
            continue;
        }
        
        // Update description
        tbc_update_bp_group_description($tg->ID, $bp_group_id);
        $repaired_count++;
    }
    
    error_log("[TBC] Manual repair completed: {$repaired_count} groups repaired");
    return $repaired_count;
}

// ============================================================================
// END OF SNIPPET
// ============================================================================

error_log('[TBC] Therapy BuddyPress Automation loaded successfully');
