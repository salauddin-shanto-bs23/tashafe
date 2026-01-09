<?php
/**
 * Therapy BuddyPress Chat Automation
 * 
 * Single-file snippet for WPCode/Code Snippets Manager
 * 
 * Features:
 * - Automatically creates HIDDEN BuddyPress groups when therapy groups are created
 * - Automatically enrolls users when they register for therapy sessions
 * - Avoids duplicate group creation
 * - Integrates with Better Messages for chat UI
 * 
 * Requirements:
 * - BuddyPress (with Groups component enabled)
 * - Better Messages (with BuddyPress Groups integration enabled)
 * - Ultimate Member (for user registration)
 * - Custom Post Type: therapy_group
 * - ACF Fields: issue_type, gender, max_members, session_start_date, session_expiry_date
 * 
 * @version 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// ============================================================================
// SECTION 1: AUTOMATIC BUDDYPRESS GROUP CREATION
// ============================================================================

/**
 * Automatically create BuddyPress group when therapy_group is created or updated
 * 
 * Triggered by: save_post_therapy_group
 * When: Admin creates a new therapy group via dashboard
 */
add_action('save_post_therapy_group', 'tbc_auto_create_bp_group', 20, 3);

function tbc_auto_create_bp_group($therapy_group_id, $post, $update) {
    
    // Only proceed if BuddyPress is active and groups component is enabled
    if (!function_exists('groups_create_group') || !function_exists('bp_is_active') || !bp_is_active('groups')) {
        error_log('[TBC] BuddyPress Groups not active. Cannot create group.');
        return;
    }
    
    // Skip if this is an autosave or revision
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    
    // Skip if post is not published
    if ($post->post_status !== 'publish') {
        error_log("[TBC] Therapy group {$therapy_group_id} not published. Skipping BP group creation.");
        return;
    }
    
    // Check if BP group already exists for this therapy group
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
    $session_expiry = function_exists('get_field') ? get_field('session_expiry_date', $therapy_group_id) : get_post_meta($therapy_group_id, 'session_expiry_date', true);
    
    // Build group description with expiry notice
    $expiry_notice_date = '';
    if ($session_expiry) {
        $expiry_date_obj = DateTime::createFromFormat('Y-m-d', $session_expiry);
        if ($expiry_date_obj) {
            $expiry_date_obj->modify('+1 day');
            $expiry_notice_date = $expiry_date_obj->format('Y-m-d');
        }
    }
    
    $description = $expiry_notice_date 
        ? 'This group will expire on ' . $expiry_notice_date . '.'
        : 'Therapy group chat.';
    
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
        'status'       => 'hidden', // CRITICAL: Hidden groups are not listed and not searchable
        'enable_forum' => false,
        'date_created' => bp_core_current_time(),
    ];
    
    $bp_group_id = groups_create_group($bp_group_args);
    
    if (!$bp_group_id || is_wp_error($bp_group_id)) {
        error_log("[TBC] FAILED to create BP group for therapy_group {$therapy_group_id}");
        return;
    }
    
    error_log("[TBC] ✓ Successfully created HIDDEN BP group {$bp_group_id} for therapy_group {$therapy_group_id}");
    
    // Store bidirectional relationship
    update_post_meta($therapy_group_id, '_tbc_bp_group_id', $bp_group_id);
    groups_update_groupmeta($bp_group_id, '_tbc_therapy_group_id', $therapy_group_id);
    
    // Store expiry date in BP group meta for future automated cleanup
    if ($session_expiry) {
        groups_update_groupmeta($bp_group_id, '_tbc_expiry_date', sanitize_text_field($session_expiry));
    }
    
    // Mark group as active
    groups_update_groupmeta($bp_group_id, '_tbc_status', 'active');
    
    // Promote creator to admin
    if (function_exists('groups_promote_member')) {
        groups_promote_member($creator_id, $bp_group_id, 'admin');
    }
    
    error_log("[TBC] BP Group {$bp_group_id} configured: Hidden status, Expiry: {$session_expiry}");
}

// ============================================================================
// SECTION 2: AUTOMATIC USER ENROLLMENT
// ============================================================================

/**
 * Automatically enroll user into BuddyPress group when they register for therapy
 * 
 * Triggered by: um_registration_complete (Ultimate Member)
 * When: User completes registration form
 */
add_action('um_registration_complete', 'tbc_auto_enroll_user_to_bp_group', 100, 2);

function tbc_auto_enroll_user_to_bp_group($user_id, $args) {
    
    // Only proceed if BuddyPress is active
    if (!function_exists('groups_join_group') || !function_exists('bp_is_active') || !bp_is_active('groups')) {
        error_log('[TBC] BuddyPress Groups not active. Cannot enroll user.');
        return;
    }
    
    error_log("[TBC] Processing enrollment for user ID: {$user_id}");
    
    // Get the therapy group the user was assigned to
    $assigned_therapy_group_id = get_user_meta($user_id, 'assigned_group', true);
    
    if (!$assigned_therapy_group_id) {
        error_log("[TBC] User {$user_id} has no assigned_group. Cannot enroll to BP group.");
        return;
    }
    
    // Get the corresponding BuddyPress group ID
    $bp_group_id = get_post_meta($assigned_therapy_group_id, '_tbc_bp_group_id', true);
    
    if (!$bp_group_id) {
        error_log("[TBC] No BP group found for therapy_group {$assigned_therapy_group_id}. Creating it now...");
        
        // Try to trigger group creation
        $therapy_post = get_post($assigned_therapy_group_id);
        if ($therapy_post && $therapy_post->post_type === 'therapy_group') {
            tbc_auto_create_bp_group($assigned_therapy_group_id, $therapy_post, true);
            // Re-fetch BP group ID
            $bp_group_id = get_post_meta($assigned_therapy_group_id, '_tbc_bp_group_id', true);
        }
        
        if (!$bp_group_id) {
            error_log("[TBC] FAILED to create BP group for therapy_group {$assigned_therapy_group_id}");
            return;
        }
    }
    
    // Verify BP group exists
    if (function_exists('groups_get_group')) {
        $bp_group = groups_get_group($bp_group_id);
        if (!$bp_group || empty($bp_group->id)) {
            error_log("[TBC] BP group {$bp_group_id} does not exist. Cannot enroll user.");
            return;
        }
    }
    
    // Check if user is already a member
    if (function_exists('groups_is_user_member')) {
        if (groups_is_user_member($user_id, $bp_group_id)) {
            error_log("[TBC] User {$user_id} already member of BP group {$bp_group_id}");
            return;
        }
    }
    
    // Enroll user into BuddyPress group
    $joined = groups_join_group($bp_group_id, $user_id);
    
    if ($joined) {
        error_log("[TBC] ✓ Successfully enrolled user {$user_id} into BP group {$bp_group_id} (therapy_group {$assigned_therapy_group_id})");
        
        // Store enrollment metadata
        update_user_meta($user_id, '_tbc_bp_group_id', $bp_group_id);
        update_user_meta($user_id, '_tbc_enrollment_date', current_time('mysql'));
    } else {
        error_log("[TBC] FAILED to enroll user {$user_id} into BP group {$bp_group_id}");
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

// ============================================================================
// END OF SNIPPET
// ============================================================================

error_log('[TBC] Therapy BuddyPress Automation loaded successfully');
