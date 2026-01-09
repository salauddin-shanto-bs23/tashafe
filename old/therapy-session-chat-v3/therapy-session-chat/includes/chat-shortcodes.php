<?php
/**
 * Chat Shortcodes
 * 
 * Frontend shortcodes for displaying chat rooms.
 * Uses Better Messages Chat Room shortcode.
 * 
 * @package TherapySessionChat
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * [tsc_chat_room] - Embeds the user's therapy chat room
 * 
 * Usage: [tsc_chat_room] or [tsc_chat_room height="500"]
 */
add_shortcode('tsc_chat_room', 'tsc_shortcode_chat_room');

function tsc_shortcode_chat_room($atts) {
    
    $atts = shortcode_atts([
        'height' => '500',
    ], $atts);
    
    if (!is_user_logged_in()) {
        return '<p class="tsc-notice">' . __('Please log in to access the chat.', 'therapy-session-chat') . '</p>';
    }
    
    $user_id = get_current_user_id();
    
    // Get user's enrolled therapy group
    $therapy_group_id = get_user_meta($user_id, '_tsc_enrolled_chat', true);
    
    if (!$therapy_group_id) {
        // Fallback to assigned_group
        $therapy_group_id = get_user_meta($user_id, 'assigned_group', true);
    }
    
    if (!$therapy_group_id) {
        return '<p class="tsc-notice">' . __('You are not enrolled in any therapy session.', 'therapy-session-chat') . '</p>';
    }
    
    // Get chat room ID
    $room_id = get_post_meta($therapy_group_id, '_tsc_chat_room_id', true);
    
    if (!$room_id) {
        return '<p class="tsc-notice">' . __('Chat room not available yet. Please check back later.', 'therapy-session-chat') . '</p>';
    }
    
    // Check if user has access
    if (!tsc_user_can_access_chat($user_id, $therapy_group_id)) {
        return '<p class="tsc-notice">' . __('You do not have access to this chat room.', 'therapy-session-chat') . '</p>';
    }
    
    // Check if chat is active
    $chat_status = get_post_meta($room_id, '_tsc_status', true);
    if ($chat_status === 'expired') {
        return '<p class="tsc-notice tsc-expired">' . __('This chat session has expired.', 'therapy-session-chat') . '</p>';
    }
    
    // Return Better Messages chat room shortcode
    // Try different shortcode formats that Better Messages might use
    $shortcode = '[better_messages_chat_room id="' . intval($room_id) . '" height="' . intval($atts['height']) . '"]';
    $output = do_shortcode($shortcode);
    
    // If shortcode didn't render (returned unchanged), try alternative
    if (strpos($output, '[better_messages_chat_room') !== false) {
        $shortcode = '[bpbm_chat_room id="' . intval($room_id) . '"]';
        $output = do_shortcode($shortcode);
    }
    
    return $output;
}

/**
 * [tsc_chat_status] - Shows chat status card with embedded chat
 */
add_shortcode('tsc_chat_status', 'tsc_shortcode_chat_status');

function tsc_shortcode_chat_status($atts) {
    
    $atts = shortcode_atts([
        'show_chat' => 'yes',
        'height'    => '400',
    ], $atts);
    
    if (!is_user_logged_in()) {
        return '<p class="tsc-notice">' . __('Please log in to access the chat.', 'therapy-session-chat') . '</p>';
    }
    
    $user_id = get_current_user_id();
    
    $therapy_group_id = get_user_meta($user_id, '_tsc_enrolled_chat', true);
    if (!$therapy_group_id) {
        $therapy_group_id = get_user_meta($user_id, 'assigned_group', true);
    }
    
    if (!$therapy_group_id) {
        return '<p class="tsc-notice">' . __('You are not enrolled in any therapy session.', 'therapy-session-chat') . '</p>';
    }
    
    $room_id = get_post_meta($therapy_group_id, '_tsc_chat_room_id', true);
    $therapy_group = get_post($therapy_group_id);
    
    if (!$room_id) {
        ob_start();
        ?>
        <div class="tsc-status-card">
            <h4><?php _e('Your Therapy Chat', 'therapy-session-chat'); ?></h4>
            <p><strong><?php _e('Session:', 'therapy-session-chat'); ?></strong> 
                <?php echo esc_html($therapy_group ? $therapy_group->post_title : 'N/A'); ?>
            </p>
            <p class="tsc-notice"><?php _e('Chat room is being set up. Please check back later.', 'therapy-session-chat'); ?></p>
        </div>
        <?php
        return ob_get_clean();
    }
    
    $chat_status = get_post_meta($room_id, '_tsc_status', true) ?: 'active';
    $expiry_date = get_post_meta($room_id, '_tsc_expiry_date', true);
    $can_access = tsc_user_can_access_chat($user_id, $therapy_group_id);
    
    ob_start();
    ?>
    <div class="tsc-status-card">
        <h4><?php _e('Your Therapy Chat', 'therapy-session-chat'); ?></h4>
        
        <p><strong><?php _e('Session:', 'therapy-session-chat'); ?></strong> 
            <?php echo esc_html($therapy_group ? $therapy_group->post_title : 'N/A'); ?>
        </p>
        
        <p><strong><?php _e('Status:', 'therapy-session-chat'); ?></strong> 
            <span class="tsc-status-<?php echo esc_attr($chat_status); ?>">
                <?php echo esc_html(ucfirst($chat_status)); ?>
            </span>
        </p>
        
        <?php if ($expiry_date): ?>
            <p><strong><?php _e('Available until:', 'therapy-session-chat'); ?></strong> 
                <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($expiry_date))); ?>
            </p>
        <?php endif; ?>
        
        <?php if ($chat_status === 'active' && $can_access && $atts['show_chat'] === 'yes'): ?>
            <div class="tsc-chat-container" style="margin-top: 15px;">
                <?php 
                $shortcode = '[better_messages_chat_room id="' . intval($room_id) . '" height="' . intval($atts['height']) . '"]';
                $output = do_shortcode($shortcode);
                if (strpos($output, '[better_messages_chat_room') !== false) {
                    $output = do_shortcode('[bpbm_chat_room id="' . intval($room_id) . '"]');
                }
                echo $output;
                ?>
            </div>
        <?php elseif ($chat_status === 'expired'): ?>
            <p class="tsc-expired"><?php _e('This chat session has expired.', 'therapy-session-chat'); ?></p>
        <?php elseif (!$can_access): ?>
            <p class="tsc-notice"><?php _e('You do not have access to this chat.', 'therapy-session-chat'); ?></p>
        <?php endif; ?>
    </div>
    
    <style>
        .tsc-status-card { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 20px; margin: 15px 0; }
        .tsc-status-card h4 { margin-top: 0; margin-bottom: 15px; }
        .tsc-status-active { color: #28a745; font-weight: bold; }
        .tsc-status-archived { color: #ffc107; font-weight: bold; }
        .tsc-status-expired { color: #dc3545; font-weight: bold; }
        .tsc-expired { color: #dc3545; font-style: italic; }
        .tsc-notice { color: #856404; background: #fff3cd; padding: 10px 15px; border-radius: 4px; }
        .tsc-chat-container { border: 1px solid #ddd; border-radius: 4px; overflow: hidden; }
    </style>
    <?php
    return ob_get_clean();
}

/**
 * [tsc_chat_members] - Shows member list
 */
add_shortcode('tsc_chat_members', 'tsc_shortcode_chat_members');

function tsc_shortcode_chat_members($atts) {
    
    $atts = shortcode_atts([
        'show_avatars' => 'yes',
        'max' => 20,
    ], $atts);
    
    if (!is_user_logged_in()) {
        return '';
    }
    
    $user_id = get_current_user_id();
    $therapy_group_id = get_user_meta($user_id, '_tsc_enrolled_chat', true);
    
    if (!$therapy_group_id) {
        $therapy_group_id = get_user_meta($user_id, 'assigned_group', true);
    }
    
    if (!$therapy_group_id) {
        return '';
    }
    
    $member_ids = tsc_get_chat_members($therapy_group_id);
    
    if (empty($member_ids)) {
        return '<p>' . __('No members yet.', 'therapy-session-chat') . '</p>';
    }
    
    $member_ids = array_slice($member_ids, 0, intval($atts['max']));
    
    ob_start();
    ?>
    <div class="tsc-members">
        <h4><?php _e('Chat Members', 'therapy-session-chat'); ?> (<?php echo count($member_ids); ?>)</h4>
        <ul>
            <?php foreach ($member_ids as $mid): ?>
                <?php $member = get_userdata($mid); ?>
                <?php if ($member): ?>
                    <li>
                        <?php if ($atts['show_avatars'] === 'yes'): ?>
                            <?php echo get_avatar($mid, 32); ?>
                        <?php endif; ?>
                        <span><?php echo esc_html($member->display_name); ?></span>
                        <?php if ($mid === $user_id): ?><small>(You)</small><?php endif; ?>
                    </li>
                <?php endif; ?>
            <?php endforeach; ?>
        </ul>
    </div>
    
    <style>
        .tsc-members ul { list-style: none; padding: 0; }
        .tsc-members li { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px solid #eee; }
        .tsc-members li:last-child { border: none; }
        .tsc-members img { border-radius: 50%; }
    </style>
    <?php
    return ob_get_clean();
}

/**
 * [tsc_debug] - Debug shortcode to check user enrollment
 */
add_shortcode('tsc_debug', 'tsc_shortcode_debug');

function tsc_shortcode_debug($atts) {
    
    if (!current_user_can('administrator')) {
        return '';
    }
    
    if (!is_user_logged_in()) {
        return '<p>Not logged in</p>';
    }
    
    $user_id = get_current_user_id();
    global $wpdb;
    
    $therapy_group_id = get_user_meta($user_id, 'assigned_group', true);
    $enrolled_chat = get_user_meta($user_id, '_tsc_enrolled_chat', true);
    $chat_room_id_user = get_user_meta($user_id, '_tsc_chat_room_id', true);
    
    $room_id_from_therapy = $therapy_group_id ? get_post_meta($therapy_group_id, '_tsc_chat_room_id', true) : null;
    
    // Check Better Messages tables
    $bp_recipients_table = $wpdb->prefix . 'bp_messages_recipients';
    $bp_messages_table = $wpdb->prefix . 'bp_messages_messages';
    $bp_meta_table = $wpdb->prefix . 'bp_messages_meta';
    
    $bp_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$bp_recipients_table}'") === $bp_recipients_table;
    $bp_messages_exists = $wpdb->get_var("SHOW TABLES LIKE '{$bp_messages_table}'") === $bp_messages_table;
    $bp_meta_exists = $wpdb->get_var("SHOW TABLES LIKE '{$bp_meta_table}'") === $bp_meta_table;
    
    // Get ALL meta from working room to understand structure
    $working_room_id = 6657;
    $working_meta = get_post_meta($working_room_id);
    
    // Get meta from our latest room
    $our_room_id = 6686;
    $our_meta = get_post_meta($our_room_id);
    
    // Check if thread_id is stored in post meta
    $working_thread = get_post_meta($working_room_id, 'thread_id', true);
    $our_thread = get_post_meta($our_room_id, 'thread_id', true);
    
    // Find all chat rooms
    $all_chat_rooms = $wpdb->get_results("
        SELECT p.ID, p.post_title, p.post_type, p.post_status 
        FROM {$wpdb->posts} p 
        WHERE p.post_type IN ('bpbm-chat', 'bm-chat-room')
        ORDER BY p.ID DESC
        LIMIT 10
    ");
    
    // Get recent threads from bp_messages
    $recent_threads = [];
    if ($bp_messages_exists) {
        $recent_threads = $wpdb->get_results("
            SELECT DISTINCT thread_id, subject, sender_id, date_sent 
            FROM {$bp_messages_table} 
            ORDER BY id DESC 
            LIMIT 10
        ");
    }
    
    // Check bp_messages_meta for chat room links
    $chat_meta = [];
    if ($bp_meta_exists) {
        $chat_meta = $wpdb->get_results("
            SELECT * FROM {$bp_meta_table} 
            WHERE meta_key LIKE '%chat%' OR meta_key LIKE '%room%'
            LIMIT 20
        ");
    }
    
    ob_start();
    ?>
    <div style="background: #f5f5f5; padding: 15px; border: 1px solid #ddd; margin: 10px 0; font-family: monospace; font-size: 11px;">
        <h4 style="margin-top:0;">TSC Debug - Deep Diagnostic</h4>
        
        <h5>Database Tables</h5>
        <p><strong>bp_messages_recipients:</strong> <?php echo $bp_table_exists ? '✓' : '✗'; ?></p>
        <p><strong>bp_messages_messages:</strong> <?php echo $bp_messages_exists ? '✓' : '✗'; ?></p>
        <p><strong>bp_messages_meta:</strong> <?php echo $bp_meta_exists ? '✓' : '✗'; ?></p>
        
        <h5>Working Room (6657) - All Post Meta</h5>
        <pre style="background:#fff; padding:10px; max-height:200px; overflow:auto;"><?php 
            if ($working_meta) {
                foreach ($working_meta as $key => $val) {
                    $value = maybe_unserialize($val[0]);
                    if (is_array($value)) {
                        echo esc_html($key) . ': ' . esc_html(json_encode($value)) . "\n";
                    } else {
                        echo esc_html($key) . ': ' . esc_html($value) . "\n";
                    }
                }
            } else {
                echo "No meta found";
            }
        ?></pre>
        
        <h5>Our Room (6686) - All Post Meta</h5>
        <pre style="background:#fff; padding:10px; max-height:200px; overflow:auto;"><?php 
            if ($our_meta) {
                foreach ($our_meta as $key => $val) {
                    $value = maybe_unserialize($val[0]);
                    if (is_array($value)) {
                        echo esc_html($key) . ': ' . esc_html(json_encode($value)) . "\n";
                    } else {
                        echo esc_html($key) . ': ' . esc_html($value) . "\n";
                    }
                }
            } else {
                echo "No meta found";
            }
        ?></pre>
        
        <h5>Thread IDs</h5>
        <p><strong>Working room thread_id meta:</strong> <?php echo $working_thread ?: 'Not set'; ?></p>
        <p><strong>Our room thread_id meta:</strong> <?php echo $our_thread ?: 'Not set'; ?></p>
        
        <?php if (!empty($recent_threads)): ?>
        <h5>Recent Threads (bp_messages_messages)</h5>
        <table style="width:100%; border-collapse:collapse; background:#fff; font-size:10px;">
            <tr><th style="border:1px solid #ddd; padding:3px;">Thread ID</th><th style="border:1px solid #ddd; padding:3px;">Subject</th><th style="border:1px solid #ddd; padding:3px;">Sender</th><th style="border:1px solid #ddd; padding:3px;">Date</th></tr>
            <?php foreach ($recent_threads as $t): ?>
                <tr>
                    <td style="border:1px solid #ddd; padding:3px;"><?php echo $t->thread_id; ?></td>
                    <td style="border:1px solid #ddd; padding:3px;"><?php echo esc_html(substr($t->subject, 0, 30)); ?></td>
                    <td style="border:1px solid #ddd; padding:3px;"><?php echo $t->sender_id; ?></td>
                    <td style="border:1px solid #ddd; padding:3px;"><?php echo $t->date_sent; ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
        
        <?php if (!empty($chat_meta)): ?>
        <h5>Chat-related Meta (bp_messages_meta)</h5>
        <pre style="background:#fff; padding:10px; max-height:150px; overflow:auto;"><?php 
            foreach ($chat_meta as $m) {
                echo "message_id={$m->message_id}, key={$m->meta_key}, value=" . esc_html(substr($m->meta_value, 0, 50)) . "\n";
            }
        ?></pre>
        <?php endif; ?>
        
        <h5>All Chat Rooms</h5>
        <table style="width:100%; border-collapse:collapse; background:#fff; font-size:10px;">
            <tr><th style="border:1px solid #ddd; padding:3px;">ID</th><th style="border:1px solid #ddd; padding:3px;">Title</th><th style="border:1px solid #ddd; padding:3px;">Type</th></tr>
            <?php foreach ($all_chat_rooms as $room): ?>
                <tr style="<?php echo $room->post_type !== 'bpbm-chat' ? 'background:#fff3cd;' : ''; ?>">
                    <td style="border:1px solid #ddd; padding:3px;"><?php echo $room->ID; ?></td>
                    <td style="border:1px solid #ddd; padding:3px;"><?php echo esc_html($room->post_title); ?></td>
                    <td style="border:1px solid #ddd; padding:3px;"><?php echo $room->post_type; ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        
        <h5 style="margin-top:15px;">Quick Actions</h5>
        <form method="post" style="margin-bottom:5px;">
            <?php wp_nonce_field('tsc_debug_action'); ?>
            <input type="hidden" name="tsc_action" value="cleanup_old_rooms">
            <button type="submit" class="button" onclick="return confirm('Delete old bm-chat-room posts?');">Cleanup Old Posts</button>
        </form>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Handle debug form actions
 */
add_action('init', 'tsc_handle_debug_actions');

function tsc_handle_debug_actions() {
    if (!isset($_POST['tsc_action']) || !current_user_can('administrator')) {
        return;
    }
    
    if (!wp_verify_nonce($_POST['_wpnonce'], 'tsc_debug_action')) {
        return;
    }
    
    global $wpdb;
    
    if ($_POST['tsc_action'] === 'add_admin_to_all') {
        // Add current admin to all bpbm-chat rooms
        $admin_id = get_current_user_id();
        $rooms = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'bpbm-chat' AND post_status = 'publish'");
        
        foreach ($rooms as $room_id) {
            tsc_add_user_to_bm_chat_native($admin_id, $room_id);
        }
        
        add_action('admin_notices', function() use ($rooms) {
            echo '<div class="notice notice-success"><p>Added admin to ' . count($rooms) . ' chat rooms.</p></div>';
        });
    }
    
    if ($_POST['tsc_action'] === 'cleanup_old_rooms') {
        // Delete old bm-chat-room posts and clear references
        $old_rooms = $wpdb->get_results("
            SELECT p.ID, pm.meta_value as therapy_id
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_tsc_therapy_group_id'
            WHERE p.post_type = 'bm-chat-room'
        ");
        
        $count = 0;
        foreach ($old_rooms as $room) {
            if ($room->therapy_id) {
                delete_post_meta($room->therapy_id, '_tsc_chat_room_id');
            }
            wp_delete_post($room->ID, true);
            $count++;
        }
        
        add_action('admin_notices', function() use ($count) {
            echo '<div class="notice notice-success"><p>Deleted ' . $count . ' old chat rooms.</p></div>';
        });
    }
}
