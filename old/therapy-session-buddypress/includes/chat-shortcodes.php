<?php
/**
 * Chat Shortcodes
 * 
 * Frontend shortcodes for accessing therapy chat via Better Messages.
 * Uses Better Messages threads directly since BP group pages have routing issues.
 * 
 * @package TherapySessionBuddyPress
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * [tsbp_my_therapy_chat] - Embeds or links to user's therapy chat
 * 
 * Uses Better Messages directly (bypassing broken BP group pages).
 * 
 * Usage: [tsbp_my_therapy_chat] or [tsbp_my_therapy_chat mode="embed"]
 * 
 * Modes:
 * - embed: Embed the Better Messages interface (RECOMMENDED)
 * - link: Show a button to open Better Messages
 * - redirect: Redirect to messages page
 */
add_shortcode('tsbp_my_therapy_chat', 'tsbp_shortcode_my_therapy_chat');

function tsbp_shortcode_my_therapy_chat($atts) {
    
    $atts = shortcode_atts([
        'mode'   => 'embed', // embed, link, or redirect
        'height' => '500',
        'text'   => __('Open Chat', 'therapy-session-bp'),
        'class'  => 'tsbp-chat-button',
    ], $atts);
    
    if (!is_user_logged_in()) {
        return '<p class="tsbp-notice tsbp-login-required">' . __('Please log in to access the chat.', 'therapy-session-bp') . '</p>';
    }
    
    $user_id = get_current_user_id();
    
    // Get user's assigned therapy group
    $therapy_group_id = get_user_meta($user_id, 'assigned_group', true);
    
    if (!$therapy_group_id) {
        return '<p class="tsbp-notice">' . __('You are not enrolled in any therapy session.', 'therapy-session-bp') . '</p>';
    }
    
    // Get therapy group info
    $therapy_group = get_post($therapy_group_id);
    $group_name = $therapy_group ? $therapy_group->post_title : 'Therapy Chat';
    
    // Get BP group (for member count display)
    $bp_group_id = tsbp_get_bp_group_id($therapy_group_id);
    $bp_group = $bp_group_id ? groups_get_group($bp_group_id) : null;
    
    // Check if user is member of BP group, add if not
    if ($bp_group_id && !groups_is_user_member($user_id, $bp_group_id)) {
        tsbp_add_user_to_therapy_group($user_id, $therapy_group_id);
    }
    
    // Check status
    if ($bp_group_id) {
        $status = groups_get_groupmeta($bp_group_id, '_tsbp_status', true);
        if ($status === 'expired') {
            return '<p class="tsbp-notice tsbp-expired">' . __('This therapy chat session has expired.', 'therapy-session-bp') . '</p>';
        }
        if ($status === 'archived') {
            return '<p class="tsbp-notice tsbp-archived">' . __('This therapy chat session has been archived.', 'therapy-session-bp') . '</p>';
        }
    }
    
    // Get or create Better Messages thread
    $thread_id = function_exists('tsbp_get_bm_thread_id') ? tsbp_get_bm_thread_id($therapy_group_id) : null;
    
    // If thread exists, make sure user is in it
    if ($thread_id && function_exists('tsbp_add_user_to_bm_thread')) {
        tsbp_add_user_to_bm_thread($user_id, $thread_id);
    }
    
    $member_count = $bp_group_id ? groups_get_total_member_count($bp_group_id) : 1;
    
    // Handle different modes
    switch ($atts['mode']) {
        
        case 'redirect':
            if (!is_admin()) {
                wp_redirect(home_url('/messages/'));
                exit;
            }
            break;
            
        case 'link':
            $messages_url = home_url('/messages/');
            return sprintf(
                '<a href="%s" class="%s">%s</a>',
                esc_url($messages_url),
                esc_attr($atts['class']),
                esc_html($atts['text'])
            );
            
        case 'embed':
        default:
            // Embed Better Messages interface directly
            ob_start();
            ?>
            <div class="tsbp-chat-wrapper">
                <div class="tsbp-chat-header" style="background:linear-gradient(135deg, #0073aa, #005177); color:#fff; padding:20px; border-radius:8px 8px 0 0;">
                    <h4 style="margin:0 0 5px 0; font-size:18px;">
                        <?php echo esc_html($group_name); ?>
                    </h4>
                    <p style="margin:0; font-size:13px; opacity:0.9;">
                        <?php printf(__('%d members in this group', 'therapy-session-bp'), $member_count); ?>
                    </p>
                </div>
                <div class="tsbp-chat-body" style="border:1px solid #ddd; border-top:0; border-radius:0 0 8px 8px; overflow:hidden; min-height:<?php echo intval($atts['height']); ?>px;">
                    <?php 
                    // Use Better Messages shortcode
                    if (shortcode_exists('better_messages')) {
                        echo do_shortcode('[better_messages]');
                    } else {
                        echo '<p style="padding:20px; text-align:center;">' . __('Chat system is loading...', 'therapy-session-bp') . '</p>';
                    }
                    ?>
                </div>
            </div>
            <p style="font-size:12px; color:#666; text-align:center; margin-top:10px;">
                <?php _e('Your therapy group chat will appear in the conversations. Look for your group name.', 'therapy-session-bp'); ?>
            </p>
            <?php
            return ob_get_clean();
    }
    
    return '';
}

/**
 * Get a working group URL, handling misconfigured BuddyPress
 */
function tsbp_get_working_group_url($bp_group_id) {
    $bp_group = groups_get_group($bp_group_id);
    
    if (!$bp_group || empty($bp_group->id)) {
        return false;
    }
    
    // Try standard BP permalink
    $group_url = bp_get_group_permalink($bp_group);
    
    // Check if the URL looks valid (should contain /groups/ typically)
    if ($group_url && strpos($group_url, '/groups/') !== false) {
        return $group_url;
    }
    
    // Try to construct URL manually using BP pages
    $groups_page_id = bp_core_get_directory_page_id('groups');
    if ($groups_page_id) {
        $groups_page_url = get_permalink($groups_page_id);
        if ($groups_page_url) {
            return trailingslashit($groups_page_url) . $bp_group->slug . '/';
        }
    }
    
    // Last resort: construct from site URL
    return home_url('/groups/' . $bp_group->slug . '/');
}

/**
 * Get Better Messages thread ID for a BP group
 * Better Messages creates threads for BP groups automatically
 */
function tsbp_get_bm_group_thread_id($bp_group_id) {
    global $wpdb;
    
    // Better Messages stores group threads in bp_messages_meta or has a specific format
    // The thread is usually created when the first message is sent
    
    // Method 1: Check if Better Messages has a function for this
    if (function_exists('Better_Messages') && method_exists(Better_Messages(), 'get_group_thread_id')) {
        return Better_Messages()->get_group_thread_id($bp_group_id);
    }
    
    // Method 2: Look in the messages meta table for group_id
    $table = $wpdb->prefix . 'bp_messages_meta';
    if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
        $thread_id = $wpdb->get_var($wpdb->prepare(
            "SELECT message_id FROM $table WHERE meta_key = 'group_id' AND meta_value = %d LIMIT 1",
            $bp_group_id
        ));
        if ($thread_id) {
            // Get the thread_id from the message
            $thread_table = $wpdb->prefix . 'bp_messages_messages';
            return $wpdb->get_var($wpdb->prepare(
                "SELECT thread_id FROM $thread_table WHERE id = %d",
                $thread_id
            ));
        }
    }
    
    // Method 3: Check Better Messages specific table
    $bm_table = $wpdb->prefix . 'bm_message_threads';
    if ($wpdb->get_var("SHOW TABLES LIKE '$bm_table'") === $bm_table) {
        $thread_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $bm_table WHERE type = 'group' AND item_id = %d LIMIT 1",
            $bp_group_id
        ));
        if ($thread_id) {
            return $thread_id;
        }
    }
    
    return false;
}

/**
 * [tsbp_therapy_chat_iframe] - Embed Better Messages chat in an iframe
 * This is a workaround when BP group pages don't work
 */
add_shortcode('tsbp_therapy_chat_iframe', 'tsbp_shortcode_chat_iframe');

function tsbp_shortcode_chat_iframe($atts) {
    
    $atts = shortcode_atts([
        'height' => '600',
        'width'  => '100%',
    ], $atts);
    
    if (!is_user_logged_in()) {
        return '<p class="tsbp-notice">' . __('Please log in to access the chat.', 'therapy-session-bp') . '</p>';
    }
    
    $user_id = get_current_user_id();
    $therapy_group_id = get_user_meta($user_id, 'assigned_group', true);
    
    if (!$therapy_group_id) {
        return '<p class="tsbp-notice">' . __('You are not enrolled in any therapy session.', 'therapy-session-bp') . '</p>';
    }
    
    $bp_group_id = tsbp_get_bp_group_id($therapy_group_id);
    
    if (!$bp_group_id) {
        return '<p class="tsbp-notice">' . __('Chat group is being set up.', 'therapy-session-bp') . '</p>';
    }
    
    // Check membership
    if (!groups_is_user_member($user_id, $bp_group_id)) {
        tsbp_add_user_to_therapy_group($user_id, $therapy_group_id);
    }
    
    $bp_group = groups_get_group($bp_group_id);
    
    // Use Better Messages shortcode if available
    if (shortcode_exists('better_messages')) {
        ob_start();
        ?>
        <div class="tsbp-chat-wrapper">
            <div class="tsbp-chat-header" style="background:#0073aa; color:#fff; padding:15px; border-radius:8px 8px 0 0;">
                <h4 style="margin:0; font-size:16px;">
                    <?php echo esc_html($bp_group->name); ?>
                    <span style="font-weight:normal; font-size:13px; opacity:0.8;">
                        — <?php printf(__('%d members', 'therapy-session-bp'), groups_get_total_member_count($bp_group_id)); ?>
                    </span>
                </h4>
            </div>
            <div class="tsbp-chat-body" style="border:1px solid #ddd; border-top:0; border-radius:0 0 8px 8px; overflow:hidden;">
                <?php echo do_shortcode('[better_messages]'); ?>
            </div>
        </div>
        <p style="font-size:12px; color:#666; text-align:center; margin-top:10px;">
            <?php _e('Select your therapy group conversation from the list above to start chatting.', 'therapy-session-bp'); ?>
        </p>
        <?php
        return ob_get_clean();
    }
    
    return '<p class="tsbp-notice">' . __('Chat system is not available.', 'therapy-session-bp') . '</p>';
}

/**
 * [tsbp_therapy_chat_status] - Shows chat status card with embedded Better Messages
 */
add_shortcode('tsbp_therapy_chat_status', 'tsbp_shortcode_chat_status');

function tsbp_shortcode_chat_status($atts) {
    
    $atts = shortcode_atts([
        'show_button' => 'yes',
        'show_chat'   => 'no', // Set to 'yes' to embed chat
    ], $atts);
    
    if (!is_user_logged_in()) {
        return '<p class="tsbp-notice">' . __('Please log in to view your therapy chat status.', 'therapy-session-bp') . '</p>';
    }
    
    $user_id = get_current_user_id();
    $therapy_group_id = get_user_meta($user_id, 'assigned_group', true);
    
    if (!$therapy_group_id) {
        return '<p class="tsbp-notice">' . __('You are not enrolled in any therapy session.', 'therapy-session-bp') . '</p>';
    }
    
    $therapy_group = get_post($therapy_group_id);
    $bp_group_id = tsbp_get_bp_group_id($therapy_group_id);
    
    if (!$bp_group_id) {
        ob_start();
        ?>
        <div class="tsbp-status-card">
            <h4><?php _e('Your Therapy Chat', 'therapy-session-bp'); ?></h4>
            <p><strong><?php _e('Session:', 'therapy-session-bp'); ?></strong> 
                <?php echo esc_html($therapy_group ? $therapy_group->post_title : 'N/A'); ?>
            </p>
            <p class="tsbp-notice"><?php _e('Chat group is being set up. Please check back later.', 'therapy-session-bp'); ?></p>
        </div>
        <?php
        return ob_get_clean();
    }
    
    $bp_group = groups_get_group($bp_group_id);
    $status = groups_get_groupmeta($bp_group_id, '_tsbp_status', true) ?: 'active';
    $expiry_date = groups_get_groupmeta($bp_group_id, '_tsbp_expiry_date', true);
    $member_count = groups_get_total_member_count($bp_group_id);
    $is_member = groups_is_user_member($user_id, $bp_group_id);
    
    // Ensure user is member and in BM thread
    if (!$is_member) {
        tsbp_add_user_to_therapy_group($user_id, $therapy_group_id);
        $is_member = true;
    }
    
    // URL to Better Messages
    $messages_url = home_url('/messages/');
    
    ob_start();
    ?>
    <div class="tsbp-status-card">
        <h4><?php _e('Your Therapy Chat', 'therapy-session-bp'); ?></h4>
        
        <p><strong><?php _e('Session:', 'therapy-session-bp'); ?></strong> 
            <?php echo esc_html($therapy_group ? $therapy_group->post_title : $bp_group->name); ?>
        </p>
        
        <p><strong><?php _e('Status:', 'therapy-session-bp'); ?></strong> 
            <span class="tsbp-status-<?php echo esc_attr($status); ?>">
                <?php echo esc_html(ucfirst($status)); ?>
            </span>
        </p>
        
        <p><strong><?php _e('Members:', 'therapy-session-bp'); ?></strong> 
            <?php echo intval($member_count); ?>
        </p>
        
        <?php if ($expiry_date): ?>
            <p><strong><?php _e('Available until:', 'therapy-session-bp'); ?></strong> 
                <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($expiry_date))); ?>
            </p>
        <?php endif; ?>
        
        <?php if ($status === 'active' && $is_member && $atts['show_button'] === 'yes'): ?>
            <?php if ($atts['show_chat'] === 'yes' && shortcode_exists('better_messages')): ?>
                <div style="margin-top:20px; border:1px solid #ddd; border-radius:8px; overflow:hidden;">
                    <?php echo do_shortcode('[better_messages]'); ?>
                </div>
            <?php else: ?>
                <p style="margin-top: 15px;">
                    <a href="<?php echo esc_url($messages_url); ?>" class="tsbp-chat-button">
                        <?php _e('Open Group Chat', 'therapy-session-bp'); ?>
                    </a>
                </p>
                <p style="font-size:12px; color:#666;">
                    <?php _e('Click to open your messages. Your therapy group chat is there.', 'therapy-session-bp'); ?>
                </p>
            <?php endif; ?>
        <?php elseif ($status === 'expired'): ?>
            <p class="tsbp-expired"><?php _e('This therapy chat session has expired.', 'therapy-session-bp'); ?></p>
        <?php elseif (!$is_member): ?>
            <p class="tsbp-notice"><?php _e('You are not a member of this chat group.', 'therapy-session-bp'); ?></p>
        <?php endif; ?>
    </div>
    
    <style>
        .tsbp-status-card { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 20px; margin: 15px 0; }
        .tsbp-status-card h4 { margin-top: 0; margin-bottom: 15px; color: #333; }
        .tsbp-status-active { color: #28a745; font-weight: bold; }
        .tsbp-status-archived { color: #ffc107; font-weight: bold; }
        .tsbp-status-expired { color: #dc3545; font-weight: bold; }
        .tsbp-expired { color: #dc3545; font-style: italic; }
        .tsbp-notice { color: #856404; background: #fff3cd; padding: 10px 15px; border-radius: 4px; }
        .tsbp-chat-button { 
            display: inline-block; 
            padding: 10px 20px; 
            background: #0073aa; 
            color: #fff !important; 
            text-decoration: none; 
            border-radius: 4px;
            font-weight: bold;
        }
        .tsbp-chat-button:hover { background: #005177; }
    </style>
    <?php
    return ob_get_clean();
}

/**
 * [tsbp_therapy_group_members] - Shows member list
 */
add_shortcode('tsbp_therapy_group_members', 'tsbp_shortcode_group_members');

function tsbp_shortcode_group_members($atts) {
    
    $atts = shortcode_atts([
        'show_avatars' => 'yes',
        'max'          => 20,
    ], $atts);
    
    if (!is_user_logged_in()) {
        return '';
    }
    
    $user_id = get_current_user_id();
    $therapy_group_id = get_user_meta($user_id, 'assigned_group', true);
    
    if (!$therapy_group_id) {
        return '';
    }
    
    $bp_group_id = tsbp_get_bp_group_id($therapy_group_id);
    
    if (!$bp_group_id) {
        return '';
    }
    
    // Only show to members
    if (!groups_is_user_member($user_id, $bp_group_id)) {
        return '';
    }
    
    $members_data = groups_get_group_members([
        'group_id'   => $bp_group_id,
        'per_page'   => intval($atts['max']),
        'page'       => 1,
    ]);
    
    if (empty($members_data['members'])) {
        return '<p>' . __('No members yet.', 'therapy-session-bp') . '</p>';
    }
    
    ob_start();
    ?>
    <div class="tsbp-members">
        <h4><?php _e('Group Members', 'therapy-session-bp'); ?> (<?php echo intval($members_data['count']); ?>)</h4>
        <ul>
            <?php foreach ($members_data['members'] as $member): ?>
                <li>
                    <?php if ($atts['show_avatars'] === 'yes'): ?>
                        <?php echo get_avatar($member->ID, 32); ?>
                    <?php endif; ?>
                    <span><?php echo esc_html($member->display_name); ?></span>
                    <?php if ($member->ID === $user_id): ?><small>(<?php _e('You', 'therapy-session-bp'); ?>)</small><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    
    <style>
        .tsbp-members ul { list-style: none; padding: 0; margin: 0; }
        .tsbp-members li { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px solid #eee; }
        .tsbp-members li:last-child { border: none; }
        .tsbp-members img { border-radius: 50%; }
        .tsbp-members small { color: #666; font-style: italic; }
    </style>
    <?php
    return ob_get_clean();
}

/**
 * [tsbp_all_therapy_groups] - Admin shortcode to list all therapy groups with chat links
 * Useful for testing and admin access
 */
add_shortcode('tsbp_all_therapy_groups', 'tsbp_shortcode_all_groups');

function tsbp_shortcode_all_groups($atts) {
    
    // Only for logged-in users who are admins or have manage_options capability
    if (!is_user_logged_in() || !current_user_can('manage_options')) {
        return '<p class="tsbp-notice">' . __('This content is only available for administrators.', 'therapy-session-bp') . '</p>';
    }
    
    $atts = shortcode_atts([
        'limit' => 20,
    ], $atts);
    
    // Get all therapy groups
    $therapy_groups = get_posts([
        'post_type'      => 'therapy_group',
        'posts_per_page' => intval($atts['limit']),
        'post_status'    => 'publish',
        'orderby'        => 'date',
        'order'          => 'DESC',
    ]);
    
    if (empty($therapy_groups)) {
        return '<p>' . __('No therapy groups found.', 'therapy-session-bp') . '</p>';
    }
    
    ob_start();
    ?>
    <div class="tsbp-all-groups">
        <h4><?php _e('All Therapy Group Chats (Admin View)', 'therapy-session-bp'); ?></h4>
        <table style="width:100%; border-collapse:collapse; background:#fff; margin:15px 0;">
            <thead>
                <tr style="background:#f8f9fa;">
                    <th style="border:1px solid #ddd; padding:10px; text-align:left;"><?php _e('Therapy Group', 'therapy-session-bp'); ?></th>
                    <th style="border:1px solid #ddd; padding:10px; text-align:left;"><?php _e('BP Group', 'therapy-session-bp'); ?></th>
                    <th style="border:1px solid #ddd; padding:10px; text-align:center;"><?php _e('Members', 'therapy-session-bp'); ?></th>
                    <th style="border:1px solid #ddd; padding:10px; text-align:center;"><?php _e('Action', 'therapy-session-bp'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($therapy_groups as $tg): ?>
                    <?php 
                    $bp_group_id = tsbp_get_bp_group_id($tg->ID);
                    $bp_group = $bp_group_id ? groups_get_group($bp_group_id) : null;
                    $member_count = $bp_group_id ? groups_get_total_member_count($bp_group_id) : 0;
                    $user_id = get_current_user_id();
                    $is_member = $bp_group_id ? groups_is_user_member($user_id, $bp_group_id) : false;
                    $bm_thread_id = function_exists('tsbp_get_bm_thread_id') ? tsbp_get_bm_thread_id($tg->ID) : null;
                    ?>
                    <tr>
                        <td style="border:1px solid #ddd; padding:10px;">
                            <?php echo esc_html($tg->post_title); ?>
                            <br><small style="color:#666;">ID: <?php echo $tg->ID; ?></small>
                        </td>
                        <td style="border:1px solid #ddd; padding:10px;">
                            <?php if ($bp_group): ?>
                                <?php echo esc_html($bp_group->name); ?>
                                <br><small style="color:#666;">BP: <?php echo $bp_group_id; ?> | BM Thread: <?php echo $bm_thread_id ?: 'None'; ?></small>
                            <?php else: ?>
                                <span style="color:#d63638;">Not linked</span>
                            <?php endif; ?>
                        </td>
                        <td style="border:1px solid #ddd; padding:10px; text-align:center;">
                            <?php echo intval($member_count); ?>
                        </td>
                        <td style="border:1px solid #ddd; padding:10px; text-align:center;">
                            <?php if ($bp_group_id): ?>
                                <a href="<?php echo esc_url(home_url('/messages/')); ?>" class="button" style="margin:2px;">
                                    <?php _e('Open Chat', 'therapy-session-bp'); ?>
                                </a>
                                <?php if (!$is_member): ?>
                                    <form method="post" style="display:inline; margin:2px;">
                                        <?php wp_nonce_field('tsbp_join_group_' . $bp_group_id); ?>
                                        <input type="hidden" name="tsbp_action" value="admin_join_group">
                                        <input type="hidden" name="bp_group_id" value="<?php echo $bp_group_id; ?>">
                                        <button type="submit" class="button" style="background:#28a745; color:#fff; border-color:#28a745;">
                                            <?php _e('Join', 'therapy-session-bp'); ?>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span style="color:#28a745;">✓ Member</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="color:#999;">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p style="font-size:12px; color:#666;">
            <?php _e('Join a group to see it in your Messages. Then open "Open Chat" to access Better Messages where your group conversations appear.', 'therapy-session-bp'); ?>
        </p>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * [tsbp_debug] - Debug shortcode for admins
 */
add_shortcode('tsbp_debug', 'tsbp_shortcode_debug');

function tsbp_shortcode_debug($atts) {
    
    if (!current_user_can('administrator')) {
        return '';
    }
    
    if (!is_user_logged_in()) {
        return '<p>Not logged in</p>';
    }
    
    $user_id = get_current_user_id();
    
    // User data
    $therapy_group_id = get_user_meta($user_id, 'assigned_group', true);
    $bp_group_id = $therapy_group_id ? tsbp_get_bp_group_id($therapy_group_id) : null;
    
    // BP Group data
    $bp_group = $bp_group_id ? groups_get_group($bp_group_id) : null;
    $is_member = $bp_group_id ? groups_is_user_member($user_id, $bp_group_id) : false;
    
    // BuddyPress Pages Configuration
    $bp_pages = bp_core_get_directory_page_ids();
    $groups_page_id = isset($bp_pages['groups']) ? $bp_pages['groups'] : 0;
    $groups_page = $groups_page_id ? get_post($groups_page_id) : null;
    
    // Check Better Messages
    $bm_active = class_exists('Better_Messages') || function_exists('Better_Messages');
    $bm_shortcodes = [];
    foreach (['bp_better_messages_group', 'better_messages_group', 'better_messages', 'better_messages_chat_room'] as $sc) {
        $bm_shortcodes[$sc] = shortcode_exists($sc) ? '✓' : '✗';
    }
    
    // All therapy groups with BP groups
    $all_therapy_groups = get_posts([
        'post_type'      => 'therapy_group',
        'posts_per_page' => 10,
        'post_status'    => 'publish',
    ]);
    
    ob_start();
    ?>
    <div style="background: #f5f5f5; padding: 15px; border: 1px solid #ddd; margin: 10px 0; font-family: monospace; font-size: 12px;">
        <h4 style="margin-top:0; color:#d63638;">⚠️ TSBP Debug - Diagnostic Info</h4>
        
        <h5 style="background:#f8d7da; padding:10px; margin:10px 0;">🔴 BuddyPress Components Status (IMPORTANT!)</h5>
        <?php
        $bp_active_components = bp_get_option('bp-active-components', []);
        $groups_component_active = isset($bp_active_components['groups']);
        ?>
        <table style="width:100%; border-collapse:collapse; background:#fff; font-size:11px;">
            <tr>
                <td style="border:1px solid #ddd; padding:5px;"><strong>Groups Component:</strong></td>
                <td style="border:1px solid #ddd; padding:5px;">
                    <?php if ($groups_component_active): ?>
                        <span style="color:green;">✓ ACTIVE</span>
                    <?php else: ?>
                        <span style="color:red; font-weight:bold;">✗ NOT ACTIVE - THIS IS THE PROBLEM!</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td style="border:1px solid #ddd; padding:5px;"><strong>All Active Components:</strong></td>
                <td style="border:1px solid #ddd; padding:5px;"><pre style="margin:0;font-size:10px;"><?php print_r(array_keys($bp_active_components)); ?></pre></td>
            </tr>
            <tr>
                <td style="border:1px solid #ddd; padding:5px;"><strong>bp_is_active('groups'):</strong></td>
                <td style="border:1px solid #ddd; padding:5px;"><?php echo bp_is_active('groups') ? '<span style="color:green;">✓ Yes</span>' : '<span style="color:red;">✗ No</span>'; ?></td>
            </tr>
            <tr>
                <td style="border:1px solid #ddd; padding:5px;"><strong>Groups Component Object:</strong></td>
                <td style="border:1px solid #ddd; padding:5px;"><?php echo isset(buddypress()->groups) ? '<span style="color:green;">✓ Loaded</span>' : '<span style="color:red;">✗ Not Loaded</span>'; ?></td>
            </tr>
        </table>
        
        <?php if (!$groups_component_active || !bp_is_active('groups')): ?>
        <div style="background:#f8d7da; border:2px solid #f5c6cb; color:#721c24; padding:15px; margin:15px 0; border-radius:4px;">
            <strong>🚨 CRITICAL: BuddyPress Groups Component is NOT Active!</strong><br><br>
            <strong>This is why /groups/ redirects to user profiles.</strong><br><br>
            <strong>To fix:</strong><br>
            1. Go to <a href="<?php echo admin_url('admin.php?page=bp-components'); ?>" target="_blank" style="color:#721c24;">Settings → BuddyPress → Components</a><br>
            2. Find "User Groups" and make sure it's <strong>CHECKED/ENABLED</strong><br>
            3. Click "Save Settings"<br>
            4. Go to Settings → Permalinks and click "Save Changes"<br>
            5. Clear any caching plugins
        </div>
        <?php endif; ?>
        
        <h5 style="background:#fff3cd; padding:10px; margin:10px 0;">BuddyPress Pages Configuration</h5>
        <table style="width:100%; border-collapse:collapse; background:#fff; font-size:11px;">
            <tr>
                <td style="border:1px solid #ddd; padding:5px;"><strong>Groups Page ID:</strong></td>
                <td style="border:1px solid #ddd; padding:5px;"><?php echo $groups_page_id ?: '<span style="color:red;">NOT SET!</span>'; ?></td>
            </tr>
            <tr>
                <td style="border:1px solid #ddd; padding:5px;"><strong>Groups Page Title:</strong></td>
                <td style="border:1px solid #ddd; padding:5px;"><?php echo $groups_page ? esc_html($groups_page->post_title) : '<span style="color:red;">N/A</span>'; ?></td>
            </tr>
            <tr>
                <td style="border:1px solid #ddd; padding:5px;"><strong>Groups Page Slug:</strong></td>
                <td style="border:1px solid #ddd; padding:5px;"><?php echo $groups_page ? esc_html($groups_page->post_name) : '<span style="color:red;">N/A</span>'; ?></td>
            </tr>
            <tr>
                <td style="border:1px solid #ddd; padding:5px;"><strong>Groups Page Status:</strong></td>
                <td style="border:1px solid #ddd; padding:5px;"><?php echo $groups_page ? esc_html($groups_page->post_status) : '<span style="color:red;">N/A</span>'; ?></td>
            </tr>
            <tr>
                <td style="border:1px solid #ddd; padding:5px;"><strong>Expected Groups URL:</strong></td>
                <td style="border:1px solid #ddd; padding:5px;"><?php echo $groups_page_id ? esc_url(get_permalink($groups_page_id)) : home_url('/groups/'); ?></td>
            </tr>
            <tr>
                <td style="border:1px solid #ddd; padding:5px;"><strong>All BP Pages:</strong></td>
                <td style="border:1px solid #ddd; padding:5px;"><pre style="margin:0;font-size:10px;"><?php print_r($bp_pages); ?></pre></td>
            </tr>
        </table>
        
        <?php if (!$groups_page_id || !$groups_page || $groups_page->post_status !== 'publish'): ?>
        <div style="background:#f8d7da; border:1px solid #f5c6cb; color:#721c24; padding:15px; margin:15px 0; border-radius:4px;">
            <strong>🚨 PROBLEM DETECTED: BuddyPress Groups page is not configured!</strong><br><br>
            <strong>How to fix:</strong><br>
            1. Go to <a href="<?php echo admin_url('admin.php?page=bp-page-settings'); ?>" target="_blank">Settings → BuddyPress → Pages</a><br>
            2. For "Groups", select or create a page (e.g., create a page called "Groups" with slug "groups")<br>
            3. Save Changes<br>
            4. Go to <a href="<?php echo admin_url('options-permalink.php'); ?>" target="_blank">Settings → Permalinks</a> and click "Save Changes" to flush rewrite rules
        </div>
        <?php endif; ?>
        
        <h5>Better Messages Status</h5>
        <p><strong>Better Messages Active:</strong> <?php echo $bm_active ? '✓ Yes' : '✗ No'; ?></p>
        <p><strong>Available Shortcodes:</strong></p>
        <ul style="margin:5px 0;">
            <?php foreach ($bm_shortcodes as $sc => $status): ?>
                <li><code>[<?php echo $sc; ?>]</code>: <?php echo $status; ?></li>
            <?php endforeach; ?>
        </ul>
        
        <h5>Current User (ID: <?php echo $user_id; ?>)</h5>
        <p><strong>Assigned therapy_group:</strong> <?php echo $therapy_group_id ?: 'None'; ?></p>
        <p><strong>Linked BP Group ID:</strong> <?php echo $bp_group_id ?: 'None'; ?></p>
        <p><strong>BP Group Name:</strong> <?php echo $bp_group ? esc_html($bp_group->name) : 'N/A'; ?></p>
        <p><strong>BP Group Slug:</strong> <?php echo $bp_group ? esc_html($bp_group->slug) : 'N/A'; ?></p>
        <p><strong>Is BP Group Member:</strong> <?php echo $is_member ? '✓ Yes' : '✗ No'; ?></p>
        
        <?php if ($bp_group): ?>
            <p><strong>BP Group Status:</strong> <?php echo esc_html($bp_group->status); ?></p>
            <p><strong>TSBP Status:</strong> <?php echo groups_get_groupmeta($bp_group_id, '_tsbp_status', true) ?: 'Not set'; ?></p>
            <p><strong>Expiry Date:</strong> <?php echo groups_get_groupmeta($bp_group_id, '_tsbp_expiry_date', true) ?: 'Not set'; ?></p>
            <p><strong>Raw Group URL (bp_get_group_permalink):</strong> <code><?php echo esc_html(bp_get_group_permalink($bp_group)); ?></code></p>
            <p><strong>Calculated Working URL:</strong> <code><?php echo esc_html(tsbp_get_working_group_url($bp_group_id)); ?></code></p>
            <p><strong>Manual URL:</strong> <code><?php echo esc_html(home_url('/groups/' . $bp_group->slug . '/')); ?></code></p>
            
            <h5 style="background:#f8d7da; padding:10px; margin:10px 0 5px 0;">🔍 Detailed URL Analysis</h5>
            <p><strong>bp_get_groups_directory_permalink():</strong> <code><?php echo esc_html(bp_get_groups_directory_permalink()); ?></code></p>
            <p><strong>BP_GROUPS_SLUG constant:</strong> <code><?php echo defined('BP_GROUPS_SLUG') ? BP_GROUPS_SLUG : 'NOT DEFINED'; ?></code></p>
            <p><strong>buddypress()->groups->id:</strong> <code><?php echo isset(buddypress()->groups->id) ? buddypress()->groups->id : 'Not set'; ?></code></p>
            <p><strong>buddypress()->groups->slug:</strong> <code><?php echo isset(buddypress()->groups->slug) ? buddypress()->groups->slug : 'Not set'; ?></code></p>
            <p><strong>bp_get_group_slug($bp_group):</strong> <code><?php echo esc_html(bp_get_group_slug($bp_group)); ?></code></p>
        <?php endif; ?>
        
        <h5>Therapy Groups with BP Groups</h5>
        <table style="width:100%; border-collapse:collapse; background:#fff; font-size:11px;">
            <tr>
                <th style="border:1px solid #ddd; padding:5px;">Therapy ID</th>
                <th style="border:1px solid #ddd; padding:5px;">Title</th>
                <th style="border:1px solid #ddd; padding:5px;">BP Group ID</th>
                <th style="border:1px solid #ddd; padding:5px;">BP Slug</th>
                <th style="border:1px solid #ddd; padding:5px;">Members</th>
            </tr>
            <?php foreach ($all_therapy_groups as $tg): ?>
                <?php 
                $tg_bp_id = tsbp_get_bp_group_id($tg->ID);
                $tg_bp = $tg_bp_id ? groups_get_group($tg_bp_id) : null;
                $tg_count = $tg_bp_id ? groups_get_total_member_count($tg_bp_id) : 0;
                ?>
                <tr>
                    <td style="border:1px solid #ddd; padding:5px;"><?php echo $tg->ID; ?></td>
                    <td style="border:1px solid #ddd; padding:5px;"><?php echo esc_html($tg->post_title); ?></td>
                    <td style="border:1px solid #ddd; padding:5px;"><?php echo $tg_bp_id ?: '<em>None</em>'; ?></td>
                    <td style="border:1px solid #ddd; padding:5px;"><?php echo $tg_bp ? esc_html($tg_bp->slug) : '-'; ?></td>
                    <td style="border:1px solid #ddd; padding:5px;"><?php echo $tg_count; ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        
        <h5 style="margin-top:15px;">Quick Actions</h5>
        <?php 
        $create_url = wp_nonce_url(
            admin_url('edit.php?post_type=therapy_group&tsbp_action=create_missing_groups'),
            'tsbp_create_missing_groups'
        );
        $create_threads_url = wp_nonce_url(
            admin_url('edit.php?post_type=therapy_group&tsbp_action=create_bm_threads'),
            'tsbp_create_bm_threads'
        );
        ?>
        <p>
            <a href="<?php echo admin_url('admin.php?page=bp-settings'); ?>" class="button" target="_blank">
                ⚙️ BuddyPress Settings
            </a>
            <a href="<?php echo admin_url('options-permalink.php'); ?>" class="button" target="_blank">
                🔄 Flush Permalinks
            </a>
            <a href="<?php echo esc_url($create_url); ?>" class="button" onclick="return confirm('Create BP groups for all therapy groups?');">
                Create Missing BP Groups
            </a>
            <a href="<?php echo esc_url($create_threads_url); ?>" class="button button-primary" onclick="return confirm('Create chat threads and add admin to all groups?');">
                🔥 Create Chat Threads + Add Admin
            </a>
        </p>
        
        <h5 style="margin-top:15px; background:#d4edda; padding:10px;">Chat Threads Status</h5>
        <table style="width:100%; border-collapse:collapse; background:#fff; font-size:11px;">
            <tr>
                <th style="border:1px solid #ddd; padding:5px;">Therapy ID</th>
                <th style="border:1px solid #ddd; padding:5px;">Title</th>
                <th style="border:1px solid #ddd; padding:5px;">BP Group</th>
                <th style="border:1px solid #ddd; padding:5px;">BM Thread</th>
                <th style="border:1px solid #ddd; padding:5px;">Admin in Thread?</th>
            </tr>
            <?php 
            $admin_id = get_current_user_id();
            foreach ($all_therapy_groups as $tg): 
                $tg_bp_id = tsbp_get_bp_group_id($tg->ID);
                $tg_thread_id = function_exists('tsbp_get_bm_thread_id') ? tsbp_get_bm_thread_id($tg->ID) : null;
                $admin_in_thread = false;
                if ($tg_thread_id && function_exists('tsbp_get_bm_thread_users')) {
                    $thread_users = tsbp_get_bm_thread_users($tg_thread_id);
                    $admin_in_thread = in_array($admin_id, $thread_users);
                }
            ?>
                <tr>
                    <td style="border:1px solid #ddd; padding:5px;"><?php echo $tg->ID; ?></td>
                    <td style="border:1px solid #ddd; padding:5px;"><?php echo esc_html($tg->post_title); ?></td>
                    <td style="border:1px solid #ddd; padding:5px;"><?php echo $tg_bp_id ?: '<span style="color:red;">None</span>'; ?></td>
                    <td style="border:1px solid #ddd; padding:5px;"><?php echo $tg_thread_id ?: '<span style="color:red;">None</span>'; ?></td>
                    <td style="border:1px solid #ddd; padding:5px;">
                        <?php if ($tg_thread_id): ?>
                            <?php echo $admin_in_thread ? '<span style="color:green;">✓ Yes</span>' : '<span style="color:red;">✗ No</span>'; ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
        
        <?php if ($bp_group_id): ?>
        <h5 style="margin-top:15px;">Test Group Links (Note: BP group pages have routing issues)</h5>
        <p style="font-size:11px;">Since BP group pages redirect to user profiles, use the chat interface directly via <code>[tsbp_my_therapy_chat]</code></p>
        <form method="post" style="margin-top:10px;">
            <?php wp_nonce_field('tsbp_add_self_to_group'); ?>
            <input type="hidden" name="tsbp_action" value="add_self_to_group">
            <input type="hidden" name="bp_group_id" value="<?php echo $bp_group_id; ?>">
            <button type="submit" class="button button-primary">Add Myself to BP Group</button>
        </form>
        <?php endif; ?>
        
        <h5 style="background:#d4edda; padding:10px; margin:15px 0 10px 0;">🔧 Alternative: Better Messages Direct Access</h5>
        <p style="font-size:11px;">If BuddyPress group pages don't work, users can access group chats through Better Messages directly:</p>
        <p>
            <a href="<?php echo home_url('/messages/'); ?>" class="button" target="_blank">Open /messages/</a>
            <a href="<?php echo home_url('/?bm-open-group=' . ($bp_group_id ?: '1')); ?>" class="button" target="_blank">Try BM Group Open</a>
        </p>
        <p style="font-size:11px;">Or use shortcode: <code>[tsbp_my_therapy_chat mode="bm_direct"]</code></p>
        
        <h5 style="background:#cce5ff; padding:10px; margin:15px 0 10px 0;">💡 Workaround Shortcodes</h5>
        <p style="font-size:11px;">These shortcodes work even if BP group pages are broken:</p>
        <ul style="font-size:11px;">
            <li><code>[tsbp_my_therapy_chat mode="bm_direct"]</code> - Opens Better Messages with group chat</li>
            <li><code>[tsbp_therapy_chat_iframe]</code> - Embeds chat in an iframe</li>
        </ul>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Handle debug form actions
 */
add_action('init', 'tsbp_handle_debug_form_actions');

function tsbp_handle_debug_form_actions() {
    
    if (!isset($_POST['tsbp_action']) || !current_user_can('administrator')) {
        return;
    }
    
    if ($_POST['tsbp_action'] === 'add_self_to_group') {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'tsbp_add_self_to_group')) {
            return;
        }
        
        $bp_group_id = intval($_POST['bp_group_id'] ?? 0);
        if ($bp_group_id) {
            groups_join_group($bp_group_id, get_current_user_id());
        }
    }
    
    // Handle admin join group from the all groups list
    if ($_POST['tsbp_action'] === 'admin_join_group') {
        $bp_group_id = intval($_POST['bp_group_id'] ?? 0);
        
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'tsbp_join_group_' . $bp_group_id)) {
            return;
        }
        
        if ($bp_group_id) {
            groups_join_group($bp_group_id, get_current_user_id());
            // Make admin a group admin too
            $member = new BP_Groups_Member(get_current_user_id(), $bp_group_id);
            $member->promote('admin');
        }
    }
}
