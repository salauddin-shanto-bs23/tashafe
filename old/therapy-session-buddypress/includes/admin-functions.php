<?php
/**
 * Admin Functions
 * 
 * Admin UI for managing BuddyPress groups linked to therapy sessions.
 * 
 * @package TherapySessionBuddyPress
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Add meta box to therapy_group edit screen
 */
add_action('add_meta_boxes', 'tsbp_add_meta_boxes');

function tsbp_add_meta_boxes() {
    add_meta_box(
        'tsbp_bp_group_info',
        __('BuddyPress Group Chat', 'therapy-session-bp'),
        'tsbp_bp_group_meta_box_callback',
        'therapy_group',
        'side',
        'high'
    );
}

/**
 * Meta box callback
 */
function tsbp_bp_group_meta_box_callback($post) {
    
    $bp_group_id = tsbp_get_bp_group_id($post->ID);
    
    wp_nonce_field('tsbp_meta_box', 'tsbp_meta_box_nonce');
    
    if (!$bp_group_id) {
        ?>
        <p style="color: #d63638;">
            <span class="dashicons dashicons-warning"></span>
            <?php _e('No BuddyPress group linked.', 'therapy-session-bp'); ?>
        </p>
        <p>
            <button type="submit" name="tsbp_create_bp_group" value="1" class="button button-primary">
                <?php _e('Create BP Group Now', 'therapy-session-bp'); ?>
            </button>
        </p>
        <?php
        return;
    }
    
    // Get BP group info
    $bp_group = groups_get_group($bp_group_id);
    
    if (!$bp_group || empty($bp_group->id)) {
        ?>
        <p style="color: #d63638;">
            <span class="dashicons dashicons-warning"></span>
            <?php _e('Linked BP group no longer exists.', 'therapy-session-bp'); ?>
        </p>
        <p>
            <button type="submit" name="tsbp_create_bp_group" value="1" class="button button-primary">
                <?php _e('Create New BP Group', 'therapy-session-bp'); ?>
            </button>
        </p>
        <?php
        return;
    }
    
    $status = groups_get_groupmeta($bp_group_id, '_tsbp_status', true) ?: 'active';
    $expiry_date = groups_get_groupmeta($bp_group_id, '_tsbp_expiry_date', true);
    $member_count = groups_get_total_member_count($bp_group_id);
    $group_url = bp_get_group_permalink($bp_group);
    
    ?>
    <div class="tsbp-admin-box">
        <p>
            <span style="color: #00a32a;">
                <span class="dashicons dashicons-yes-alt"></span>
                <?php _e('BP Group Active', 'therapy-session-bp'); ?>
            </span>
        </p>
        
        <table class="form-table" style="margin:0;">
            <tr>
                <th style="padding:5px 0;"><?php _e('BP Group ID:', 'therapy-session-bp'); ?></th>
                <td style="padding:5px 0;"><code><?php echo $bp_group_id; ?></code></td>
            </tr>
            <tr>
                <th style="padding:5px 0;"><?php _e('Status:', 'therapy-session-bp'); ?></th>
                <td style="padding:5px 0;">
                    <span class="tsbp-status-badge tsbp-status-<?php echo esc_attr($status); ?>">
                        <?php echo esc_html(ucfirst($status)); ?>
                    </span>
                </td>
            </tr>
            <tr>
                <th style="padding:5px 0;"><?php _e('Visibility:', 'therapy-session-bp'); ?></th>
                <td style="padding:5px 0;"><?php echo esc_html(ucfirst($bp_group->status)); ?></td>
            </tr>
            <tr>
                <th style="padding:5px 0;"><?php _e('Members:', 'therapy-session-bp'); ?></th>
                <td style="padding:5px 0;"><strong><?php echo intval($member_count); ?></strong></td>
            </tr>
            <?php if ($expiry_date): ?>
            <tr>
                <th style="padding:5px 0;"><?php _e('Expires:', 'therapy-session-bp'); ?></th>
                <td style="padding:5px 0;"><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($expiry_date))); ?></td>
            </tr>
            <?php endif; ?>
        </table>
        
        <p style="margin-top:15px;">
            <a href="<?php echo esc_url($group_url); ?>" class="button" target="_blank">
                <?php _e('View Group', 'therapy-session-bp'); ?>
            </a>
            <a href="<?php echo esc_url(trailingslashit($group_url) . 'admin/'); ?>" class="button" target="_blank">
                <?php _e('Manage', 'therapy-session-bp'); ?>
            </a>
        </p>
        
        <hr style="margin:15px 0;">
        
        <p>
            <label for="tsbp_expiry_date"><strong><?php _e('Update Expiry:', 'therapy-session-bp'); ?></strong></label>
            <input type="date" id="tsbp_expiry_date" name="tsbp_expiry_date" 
                   value="<?php echo esc_attr($expiry_date); ?>" style="width:100%;">
        </p>
    </div>
    
    <style>
        .tsbp-status-badge { padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: bold; }
        .tsbp-status-active { background: #d4edda; color: #155724; }
        .tsbp-status-archived { background: #fff3cd; color: #856404; }
        .tsbp-status-expired { background: #f8d7da; color: #721c24; }
    </style>
    <?php
}

/**
 * Save meta box data
 */
add_action('save_post_therapy_group', 'tsbp_save_meta_box', 25, 2);

function tsbp_save_meta_box($post_id, $post) {
    
    // Verify nonce
    if (!isset($_POST['tsbp_meta_box_nonce']) || !wp_verify_nonce($_POST['tsbp_meta_box_nonce'], 'tsbp_meta_box')) {
        return;
    }
    
    // Check permissions
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }
    
    // Handle "Create BP Group" button
    if (isset($_POST['tsbp_create_bp_group']) && $_POST['tsbp_create_bp_group'] == '1') {
        $issue_type = get_post_meta($post_id, 'issue_type', true);
        $gender = get_post_meta($post_id, 'gender', true);
        
        $description = sprintf(
            'Therapy session group - %s. Issue: %s, Gender: %s',
            $post->post_title,
            ucfirst($issue_type ?: 'General'),
            ucfirst($gender ?: 'All')
        );
        
        $default_days = get_option('tsbp_default_expiry_days', 30);
        $expiry_date = get_post_meta($post_id, 'session_end_date', true);
        if (!$expiry_date) {
            $expiry_date = date('Y-m-d', strtotime("+{$default_days} days"));
        }
        
        tsbp_create_bp_group(
            $post_id,
            $post->post_title,
            $description,
            get_current_user_id(),
            $expiry_date
        );
    }
    
    // Handle expiry date update
    if (isset($_POST['tsbp_expiry_date'])) {
        $bp_group_id = tsbp_get_bp_group_id($post_id);
        if ($bp_group_id && !empty($_POST['tsbp_expiry_date'])) {
            $new_expiry = sanitize_text_field($_POST['tsbp_expiry_date']);
            tsbp_extend_bp_group_expiry($bp_group_id, $new_expiry);
        }
    }
}

/**
 * Add custom column to therapy_group list
 */
add_filter('manage_therapy_group_posts_columns', 'tsbp_add_bp_group_column');

function tsbp_add_bp_group_column($columns) {
    $new_columns = [];
    
    foreach ($columns as $key => $value) {
        $new_columns[$key] = $value;
        
        // Add after title
        if ($key === 'title') {
            $new_columns['bp_group'] = __('BP Group', 'therapy-session-bp');
        }
    }
    
    return $new_columns;
}

/**
 * Populate custom column
 */
add_action('manage_therapy_group_posts_custom_column', 'tsbp_bp_group_column_content', 10, 2);

function tsbp_bp_group_column_content($column, $post_id) {
    
    if ($column !== 'bp_group') {
        return;
    }
    
    $bp_group_id = tsbp_get_bp_group_id($post_id);
    
    if (!$bp_group_id) {
        echo '<span style="color:#d63638;">✗ Not linked</span>';
        return;
    }
    
    $bp_group = groups_get_group($bp_group_id);
    
    if (!$bp_group || empty($bp_group->id)) {
        echo '<span style="color:#dba617;">⚠ Group deleted</span>';
        return;
    }
    
    $status = groups_get_groupmeta($bp_group_id, '_tsbp_status', true) ?: 'active';
    $member_count = groups_get_total_member_count($bp_group_id);
    
    printf(
        '<span style="color:#00a32a;">✓</span> ID: %d<br><small>%s • %d members</small>',
        $bp_group_id,
        ucfirst($status),
        $member_count
    );
}

/**
 * Add bulk action to create BP groups
 */
add_filter('bulk_actions-edit-therapy_group', 'tsbp_add_bulk_actions');

function tsbp_add_bulk_actions($actions) {
    $actions['tsbp_create_bp_groups'] = __('Create BP Groups', 'therapy-session-bp');
    return $actions;
}

/**
 * Handle bulk action
 */
add_filter('handle_bulk_actions-edit-therapy_group', 'tsbp_handle_bulk_action', 10, 3);

function tsbp_handle_bulk_action($redirect_to, $action, $post_ids) {
    
    if ($action !== 'tsbp_create_bp_groups') {
        return $redirect_to;
    }
    
    $created = 0;
    
    foreach ($post_ids as $post_id) {
        // Skip if already has BP group
        if (tsbp_get_bp_group_id($post_id)) {
            continue;
        }
        
        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish') {
            continue;
        }
        
        $issue_type = get_post_meta($post_id, 'issue_type', true);
        $gender = get_post_meta($post_id, 'gender', true);
        
        $description = sprintf(
            'Therapy session group - %s. Issue: %s, Gender: %s',
            $post->post_title,
            ucfirst($issue_type ?: 'General'),
            ucfirst($gender ?: 'All')
        );
        
        $default_days = get_option('tsbp_default_expiry_days', 30);
        $expiry_date = get_post_meta($post_id, 'session_end_date', true);
        if (!$expiry_date) {
            $expiry_date = date('Y-m-d', strtotime("+{$default_days} days"));
        }
        
        $bp_group_id = tsbp_create_bp_group(
            $post_id,
            $post->post_title,
            $description,
            $post->post_author,
            $expiry_date
        );
        
        if ($bp_group_id) {
            $created++;
        }
    }
    
    return add_query_arg('tsbp_created', $created, $redirect_to);
}

/**
 * Show admin notice after bulk action
 */
add_action('admin_notices', 'tsbp_bulk_action_notice');

function tsbp_bulk_action_notice() {
    
    if (!isset($_GET['tsbp_created'])) {
        return;
    }
    
    $created = intval($_GET['tsbp_created']);
    
    printf(
        '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
        sprintf(
            _n(
                'Created %d BuddyPress group.',
                'Created %d BuddyPress groups.',
                $created,
                'therapy-session-bp'
            ),
            $created
        )
    );
}

/**
 * Add settings page
 */
add_action('admin_menu', 'tsbp_add_settings_page');

function tsbp_add_settings_page() {
    add_submenu_page(
        'edit.php?post_type=therapy_group',
        __('BP Chat Settings', 'therapy-session-bp'),
        __('BP Chat Settings', 'therapy-session-bp'),
        'manage_options',
        'tsbp-settings',
        'tsbp_settings_page_callback'
    );
}

/**
 * Settings page callback
 */
function tsbp_settings_page_callback() {
    
    // Save settings
    if (isset($_POST['tsbp_save_settings']) && wp_verify_nonce($_POST['_wpnonce'], 'tsbp_settings')) {
        update_option('tsbp_group_status', sanitize_text_field($_POST['tsbp_group_status'] ?? 'private'));
        update_option('tsbp_expiry_action', sanitize_text_field($_POST['tsbp_expiry_action'] ?? 'archive'));
        update_option('tsbp_default_expiry_days', intval($_POST['tsbp_default_expiry_days'] ?? 30));
        
        echo '<div class="notice notice-success"><p>' . __('Settings saved.', 'therapy-session-bp') . '</p></div>';
    }
    
    $group_status = get_option('tsbp_group_status', 'private');
    $expiry_action = get_option('tsbp_expiry_action', 'archive');
    $default_expiry_days = get_option('tsbp_default_expiry_days', 30);
    
    ?>
    <div class="wrap">
        <h1><?php _e('Therapy Session BP Chat Settings', 'therapy-session-bp'); ?></h1>
        
        <form method="post">
            <?php wp_nonce_field('tsbp_settings'); ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="tsbp_group_status"><?php _e('Default Group Privacy', 'therapy-session-bp'); ?></label>
                    </th>
                    <td>
                        <select name="tsbp_group_status" id="tsbp_group_status">
                            <option value="private" <?php selected($group_status, 'private'); ?>><?php _e('Private - Only members can see content', 'therapy-session-bp'); ?></option>
                            <option value="hidden" <?php selected($group_status, 'hidden'); ?>><?php _e('Hidden - Only members can find and access', 'therapy-session-bp'); ?></option>
                            <option value="public" <?php selected($group_status, 'public'); ?>><?php _e('Public - Anyone can see (not recommended)', 'therapy-session-bp'); ?></option>
                        </select>
                        <p class="description"><?php _e('Privacy level for new BP groups. Private is recommended for therapy sessions.', 'therapy-session-bp'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="tsbp_expiry_action"><?php _e('On Group Expiry', 'therapy-session-bp'); ?></label>
                    </th>
                    <td>
                        <select name="tsbp_expiry_action" id="tsbp_expiry_action">
                            <option value="archive" <?php selected($expiry_action, 'archive'); ?>><?php _e('Archive (hide but keep data)', 'therapy-session-bp'); ?></option>
                            <option value="hide" <?php selected($expiry_action, 'hide'); ?>><?php _e('Hide (set to hidden status)', 'therapy-session-bp'); ?></option>
                            <option value="delete" <?php selected($expiry_action, 'delete'); ?>><?php _e('Delete permanently', 'therapy-session-bp'); ?></option>
                        </select>
                        <p class="description"><?php _e('What happens when a therapy session BP group expires.', 'therapy-session-bp'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="tsbp_default_expiry_days"><?php _e('Default Expiry (days)', 'therapy-session-bp'); ?></label>
                    </th>
                    <td>
                        <input type="number" name="tsbp_default_expiry_days" id="tsbp_default_expiry_days" 
                               value="<?php echo esc_attr($default_expiry_days); ?>" min="1" max="365" style="width:80px;">
                        <p class="description"><?php _e('Default number of days until a group expires (if no session end date is set).', 'therapy-session-bp'); ?></p>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <input type="submit" name="tsbp_save_settings" class="button button-primary" value="<?php _e('Save Settings', 'therapy-session-bp'); ?>">
            </p>
        </form>
        
        <hr>
        
        <h2><?php _e('Quick Actions', 'therapy-session-bp'); ?></h2>
        
        <?php
        $create_url = wp_nonce_url(
            admin_url('edit.php?post_type=therapy_group&tsbp_action=create_missing_groups'),
            'tsbp_create_missing_groups'
        );
        $expiry_url = wp_nonce_url(
            admin_url('index.php?tsbp_action=run_expiry_check'),
            'tsbp_run_expiry_check'
        );
        ?>
        
        <p>
            <a href="<?php echo esc_url($create_url); ?>" class="button" onclick="return confirm('Create BP groups for all therapy sessions that don\'t have one?');">
                <?php _e('Create Missing BP Groups', 'therapy-session-bp'); ?>
            </a>
            
            <a href="<?php echo esc_url($expiry_url); ?>" class="button">
                <?php _e('Run Expiry Check Now', 'therapy-session-bp'); ?>
            </a>
        </p>
        
        <hr>
        
        <h2><?php _e('Groups Expiring Soon', 'therapy-session-bp'); ?></h2>
        
        <?php
        if (function_exists('tsbp_get_groups_expiring_soon')) {
            $expiring = tsbp_get_groups_expiring_soon(14);
            
            if (empty($expiring)) {
                echo '<p style="color:green;">✓ ' . __('No groups expiring in the next 14 days.', 'therapy-session-bp') . '</p>';
            } else {
                echo '<table class="widefat striped">';
                echo '<thead><tr><th>' . __('Group', 'therapy-session-bp') . '</th><th>' . __('Expires', 'therapy-session-bp') . '</th><th>' . __('Days Left', 'therapy-session-bp') . '</th></tr></thead>';
                echo '<tbody>';
                
                foreach ($expiring as $group) {
                    $days = round($group->days_until_expiry);
                    $color = $days <= 3 ? '#d63638' : ($days <= 7 ? '#dba617' : 'inherit');
                    
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
        } else {
            echo '<p>' . __('Expiry check function not available.', 'therapy-session-bp') . '</p>';
        }
        ?>
    </div>
    <?php
}
