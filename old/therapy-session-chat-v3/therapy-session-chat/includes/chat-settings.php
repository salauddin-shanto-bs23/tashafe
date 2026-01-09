<?php
/**
 * Chat Settings Page
 * 
 * Admin settings for the chat system.
 * 
 * @package TherapySessionChat
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Add settings menu
 */
add_action('admin_menu', 'tsc_add_settings_menu');

function tsc_add_settings_menu() {
    add_options_page(
        __('Therapy Chat', 'therapy-session-chat'),
        __('Therapy Chat', 'therapy-session-chat'),
        'manage_options',
        'therapy-session-chat',
        'tsc_render_settings_page'
    );
}

function tsc_render_settings_page() {
    
    // Save settings
    if (isset($_POST['tsc_save']) && check_admin_referer('tsc_settings')) {
        update_option('tsc_expiry_action', sanitize_text_field($_POST['expiry_action']));
        update_option('tsc_default_expiry_days', intval($_POST['default_expiry_days']));
        echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
    }
    
    $expiry_action = get_option('tsc_expiry_action', 'archive');
    $default_days = get_option('tsc_default_expiry_days', 30);
    
    ?>
    <div class="wrap">
        <h1><?php _e('Therapy Session Chat Settings', 'therapy-session-chat'); ?></h1>
        
        <form method="post">
            <?php wp_nonce_field('tsc_settings'); ?>
            
            <table class="form-table">
                <tr>
                    <th><label for="expiry_action"><?php _e('Expiry Action', 'therapy-session-chat'); ?></label></th>
                    <td>
                        <select name="expiry_action" id="expiry_action">
                            <option value="archive" <?php selected($expiry_action, 'archive'); ?>>
                                Archive (keep messages)
                            </option>
                            <option value="delete" <?php selected($expiry_action, 'delete'); ?>>
                                Delete (remove everything)
                            </option>
                        </select>
                    </td>
                </tr>
                
                <tr>
                    <th><label for="default_expiry_days"><?php _e('Default Expiry Days', 'therapy-session-chat'); ?></label></th>
                    <td>
                        <input type="number" name="default_expiry_days" id="default_expiry_days" 
                               value="<?php echo esc_attr($default_days); ?>" min="1" max="365">
                        <p class="description">Days before chat rooms expire.</p>
                    </td>
                </tr>
            </table>
            
            <hr>
            
            <h2><?php _e('System Status', 'therapy-session-chat'); ?></h2>
            
            <?php
            // Check for Better Messages in multiple ways
            global $wpdb;
            $bm_class = class_exists('Better_Messages');
            $bm_posts_exist = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'bpbm-chat'");
            $bm_post_type_registered = post_type_exists('bpbm-chat');
            $our_rooms = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_tsc_therapy_group_id'");
            ?>
            
            <table class="widefat" style="max-width: 600px;">
                <tr>
                    <td><strong>Better Messages Class</strong></td>
                    <td>
                        <?php if ($bm_class): ?>
                            <span style="color: green;">✓ Active</span>
                        <?php else: ?>
                            <span style="color: red;">✗ Not Found</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td><strong>Chat Room Post Type</strong></td>
                    <td>
                        <?php if ($bm_post_type_registered): ?>
                            <span style="color: green;">✓ Registered</span>
                        <?php else: ?>
                            <span style="color: orange;">○ Not Registered (may be loaded later)</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td><strong>Chat Room Posts in DB</strong></td>
                    <td>
                        <?php if ($bm_posts_exist > 0): ?>
                            <span style="color: green;">✓ <?php echo $bm_posts_exist; ?> posts found</span>
                        <?php else: ?>
                            <span style="color: orange;">○ No posts yet</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td><strong>TSC-Created Rooms</strong></td>
                    <td>
                        <span><?php echo $our_rooms; ?> rooms linked to therapy groups</span>
                    </td>
                </tr>
                <tr>
                    <td><strong>Expiry Cron</strong></td>
                    <td>
                        <?php if (wp_next_scheduled('tsc_chat_expiry_check')): ?>
                            <span style="color: green;">✓ Scheduled</span>
                            <small>(<?php echo esc_html(date('M j, H:i', wp_next_scheduled('tsc_chat_expiry_check'))); ?>)</small>
                        <?php else: ?>
                            <span style="color: orange;">○ Not Scheduled</span>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
            
            <hr>
            
            <h2><?php _e('Available Shortcodes', 'therapy-session-chat'); ?></h2>
            
            <table class="widefat" style="max-width: 700px;">
                <thead>
                    <tr>
                        <th>Shortcode</th>
                        <th>Description</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>[tsc_chat_room]</code></td>
                        <td>Embeds the user's therapy chat room directly</td>
                    </tr>
                    <tr>
                        <td><code>[tsc_chat_status]</code></td>
                        <td>Shows chat status card with embedded chat</td>
                    </tr>
                    <tr>
                        <td><code>[tsc_chat_status show_chat="no"]</code></td>
                        <td>Shows status only (no embedded chat)</td>
                    </tr>
                    <tr>
                        <td><code>[tsc_chat_members]</code></td>
                        <td>Shows list of chat members</td>
                    </tr>
                    <tr>
                        <td><code>[tsc_debug]</code></td>
                        <td>Debug info (admin only)</td>
                    </tr>
                </tbody>
            </table>
            
            <hr>
            
            <h2><?php _e('Quick Actions', 'therapy-session-chat'); ?></h2>
            
            <p>
                <?php 
                // Better Messages uses bpbm-chat post type
                $bm_edit_url = admin_url('edit.php?post_type=bpbm-chat');
                ?>
                <a href="<?php echo $bm_edit_url; ?>" class="button">
                    Better Messages Chat Rooms
                </a>
                <a href="<?php echo admin_url('edit.php?post_type=therapy_group'); ?>" class="button">
                    View Therapy Groups
                </a>
            </p>
            
            <hr>
            
            <h2><?php _e('Migration Tool', 'therapy-session-chat'); ?></h2>
            
            <?php
            // Check for old bm-chat-room posts that need migration
            global $wpdb;
            $old_rooms = $wpdb->get_var("
                SELECT COUNT(*) FROM {$wpdb->posts} p
                JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_tsc_therapy_group_id'
                WHERE p.post_type = 'bm-chat-room'
            ");
            ?>
            
            <?php if ($old_rooms > 0): ?>
                <div style="background: #fff3cd; border: 1px solid #ffc107; padding: 15px; margin: 10px 0;">
                    <p><strong>⚠️ Found <?php echo $old_rooms; ?> chat room(s) with incorrect post type (bm-chat-room).</strong></p>
                    <p>These need to be recreated with the correct post type (bpbm-chat).</p>
                    
                    <?php if (isset($_POST['tsc_migrate']) && check_admin_referer('tsc_settings')): ?>
                        <?php
                        // Delete old rooms and clear references
                        $old_room_ids = $wpdb->get_col("
                            SELECT p.ID FROM {$wpdb->posts} p
                            JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_tsc_therapy_group_id'
                            WHERE p.post_type = 'bm-chat-room'
                        ");
                        
                        foreach ($old_room_ids as $old_id) {
                            $therapy_id = get_post_meta($old_id, '_tsc_therapy_group_id', true);
                            if ($therapy_id) {
                                delete_post_meta($therapy_id, '_tsc_chat_room_id');
                            }
                            wp_delete_post($old_id, true);
                        }
                        
                        echo '<div class="notice notice-success"><p>✓ Deleted ' . count($old_room_ids) . ' old rooms. New rooms will be created when users register or you click "Create Chat Room Now" in each therapy group.</p></div>';
                        ?>
                    <?php else: ?>
                        <form method="post" style="margin-top:10px;">
                            <?php wp_nonce_field('tsc_settings'); ?>
                            <input type="submit" name="tsc_migrate" class="button button-secondary" value="Delete Old Rooms & Allow Recreation" onclick="return confirm('This will delete the old chat rooms. New rooms will be created with correct post type. Continue?');">
                        </form>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <p style="color: green;">✓ No migration needed. All chat rooms use correct post type.</p>
            <?php endif; ?>
            
            <h2><?php _e('Diagnostic Tools', 'therapy-session-chat'); ?></h2>
            
            <?php if (isset($_POST['tsc_diagnose']) && check_admin_referer('tsc_settings')): ?>
                <?php
                // Get our created chat rooms (both old and new post types)
                $our_rooms = $wpdb->get_results("
                    SELECT pm.post_id, pm.meta_value as therapy_id, p.post_title, p.post_type, p.post_status
                    FROM {$wpdb->postmeta} pm
                    JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                    WHERE pm.meta_key = '_tsc_therapy_group_id'
                ");
                
                // Get a working manual room (bpbm-chat without our meta)
                $manual_room = $wpdb->get_row("
                    SELECT p.ID, p.post_title, p.post_type
                    FROM {$wpdb->posts} p
                    LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_tsc_therapy_group_id'
                    WHERE p.post_type = 'bpbm-chat'
                    AND pm.post_id IS NULL
                    LIMIT 1
                ");
                ?>
                
                <div style="background: #fff; border: 1px solid #ddd; padding: 15px; margin: 15px 0;">
                    <h4>Our Created Chat Rooms</h4>
                    <?php if (!empty($our_rooms)): ?>
                        <table class="widefat">
                            <tr><th>Room ID</th><th>Title</th><th>Post Type</th><th>Status</th><th>Meta</th></tr>
                            <?php foreach ($our_rooms as $room): ?>
                                <?php $meta = get_post_meta($room->post_id); ?>
                                <tr style="<?php echo $room->post_type !== 'bpbm-chat' ? 'background:#fff3cd;' : ''; ?>">
                                    <td><?php echo $room->post_id; ?></td>
                                    <td><?php echo esc_html($room->post_title); ?></td>
                                    <td><?php echo esc_html($room->post_type); ?> <?php echo $room->post_type !== 'bpbm-chat' ? '⚠️' : '✓'; ?></td>
                                    <td><?php echo esc_html($room->post_status); ?></td>
                                    <td><pre style="font-size:10px; max-height:100px; overflow:auto;"><?php 
                                        foreach ($meta as $k => $v) {
                                            echo esc_html($k) . ': ' . esc_html(print_r(maybe_unserialize($v[0]), true)) . "\n";
                                        }
                                    ?></pre></td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    <?php else: ?>
                        <p>No rooms created by our plugin yet.</p>
                    <?php endif; ?>
                    
                    <?php if ($manual_room): ?>
                        <h4 style="margin-top:20px;">Working Manual Room for Comparison (ID: <?php echo $manual_room->ID; ?>)</h4>
                        <?php $manual_meta = get_post_meta($manual_room->ID); ?>
                        <pre style="background:#f9f9f9; padding:10px; font-size:11px; max-height:200px; overflow:auto;"><?php 
                            foreach ($manual_meta as $k => $v) {
                                echo esc_html($k) . ': ' . esc_html(print_r(maybe_unserialize($v[0]), true)) . "\n";
                            }
                        ?></pre>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <form method="post">
                <?php wp_nonce_field('tsc_settings'); ?>
                <input type="submit" name="tsc_diagnose" class="button-secondary" value="Run Diagnostic">
            </form>
            
            <p class="submit">
                <input type="submit" name="tsc_save" class="button-primary" value="<?php _e('Save Settings', 'therapy-session-chat'); ?>">
            </p>
        </form>
    </div>
    <?php
}
