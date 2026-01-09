<?php
/**
 * Chat Admin Functions
 * 
 * Admin meta boxes and AJAX handlers for Better Messages Chat Rooms.
 * 
 * @package TherapySessionChat
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Add meta box to therapy_group edit screen
 */
add_action('add_meta_boxes', 'tsc_add_chat_meta_box');

function tsc_add_chat_meta_box() {
    add_meta_box(
        'tsc_chat_status',
        __('Chat Room', 'therapy-session-chat'),
        'tsc_render_chat_meta_box',
        'therapy_group',
        'side',
        'high'
    );
}

function tsc_render_chat_meta_box($post) {
    
    $room_id = get_post_meta($post->ID, '_tsc_chat_room_id', true);
    
    wp_nonce_field('tsc_meta_box', 'tsc_nonce');
    
    if (!$room_id || !get_post($room_id)) {
        ?>
        <p><strong>Status:</strong> No chat room created</p>
        <p>
            <button type="button" class="button button-primary" id="tsc-create-chat" data-post-id="<?php echo esc_attr($post->ID); ?>">
                Create Chat Room Now
            </button>
        </p>
        <script>
        jQuery(document).ready(function($) {
            $('#tsc-create-chat').on('click', function() {
                var $btn = $(this);
                $btn.prop('disabled', true).text('Creating...');
                
                $.post(ajaxurl, {
                    action: 'tsc_create_chat',
                    post_id: $(this).data('post-id'),
                    nonce: '<?php echo wp_create_nonce('tsc_create_chat'); ?>'
                }, function(response) {
                    if (response.success) {
                        alert('Chat room created successfully! Room ID: ' + response.data.room_id);
                        location.reload();
                    } else {
                        alert('Error: ' + response.data);
                        $btn.prop('disabled', false).text('Create Chat Room Now');
                    }
                }).fail(function() {
                    alert('Request failed');
                    $btn.prop('disabled', false).text('Create Chat Room Now');
                });
            });
        });
        </script>
        <?php
        return;
    }
    
    // Get room info
    $room = get_post($room_id);
    $chat_status = get_post_meta($room_id, '_tsc_status', true) ?: 'active';
    $expiry_date = get_post_meta($room_id, '_tsc_expiry_date', true);
    $allowed_users = get_post_meta($room_id, 'bm_chat_room_users', true);
    $member_count = is_array($allowed_users) ? count($allowed_users) : 0;
    
    $status_colors = [
        'active' => '#28a745',
        'archived' => '#ffc107',
        'expired' => '#dc3545',
    ];
    
    ?>
    <p>
        <strong>Status:</strong> 
        <span style="color: <?php echo esc_attr($status_colors[$chat_status] ?? '#6c757d'); ?>; font-weight: bold;">
            <?php echo esc_html(ucfirst($chat_status)); ?>
        </span>
    </p>
    
    <p><strong>Chat Room ID:</strong> <?php echo esc_html($room_id); ?></p>
    
    <p><strong>Members:</strong> <?php echo esc_html($member_count); ?></p>
    
    <p><strong>Expires:</strong> <?php echo $expiry_date ? esc_html(date('M j, Y', strtotime($expiry_date))) : 'Not set'; ?></p>
    
    <p>
        <label><strong>Change Expiry:</strong></label><br>
        <input type="date" id="tsc_expiry_date" name="tsc_expiry_date" value="<?php echo esc_attr($expiry_date); ?>" style="width: 100%;">
    </p>
    
    <p><strong>Shortcode:</strong></p>
    <code style="display: block; padding: 5px; background: #f0f0f0; font-size: 11px;">[better_messages_chat_room id="<?php echo esc_attr($room_id); ?>"]</code>
    
    <p style="margin-top: 10px;">
        <a href="<?php echo esc_url(admin_url('post.php?post=' . $room_id . '&action=edit')); ?>" class="button button-small" target="_blank">
            Edit Chat Room
        </a>
    </p>
    
    <hr>
    <p><strong>Actions:</strong></p>
    
    <?php if ($chat_status === 'active'): ?>
        <button type="button" class="button tsc-action" data-action="archive" data-post-id="<?php echo esc_attr($post->ID); ?>">Archive</button>
    <?php else: ?>
        <button type="button" class="button tsc-action" data-action="reactivate" data-post-id="<?php echo esc_attr($post->ID); ?>">Reactivate</button>
    <?php endif; ?>
    
    <button type="button" class="button tsc-action" data-action="delete" data-post-id="<?php echo esc_attr($post->ID); ?>" style="color: #dc3545;">Delete</button>
    
    <script>
    jQuery(document).ready(function($) {
        $('.tsc-action').on('click', function() {
            var action = $(this).data('action');
            if (action === 'delete' && !confirm('Delete this chat room? This cannot be undone!')) return;
            if (action === 'archive' && !confirm('Archive this chat room?')) return;
            
            $.post(ajaxurl, {
                action: 'tsc_chat_action',
                post_id: $(this).data('post-id'),
                chat_action: action,
                nonce: '<?php echo wp_create_nonce('tsc_chat_action'); ?>'
            }, function(response) {
                if (response.success) location.reload();
                else alert('Error: ' + response.data);
            });
        });
    });
    </script>
    <?php
}

/**
 * Save expiry date on post save
 */
add_action('save_post_therapy_group', 'tsc_save_expiry_meta', 30, 1);

function tsc_save_expiry_meta($post_id) {
    
    if (!isset($_POST['tsc_nonce']) || !wp_verify_nonce($_POST['tsc_nonce'], 'tsc_meta_box')) {
        return;
    }
    
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    
    if (isset($_POST['tsc_expiry_date']) && !empty($_POST['tsc_expiry_date'])) {
        $room_id = get_post_meta($post_id, '_tsc_chat_room_id', true);
        if ($room_id) {
            update_post_meta($room_id, '_tsc_expiry_date', sanitize_text_field($_POST['tsc_expiry_date']));
        }
    }
}

/**
 * AJAX: Create chat room
 */
add_action('wp_ajax_tsc_create_chat', 'tsc_ajax_create_chat');

function tsc_ajax_create_chat() {
    
    check_ajax_referer('tsc_create_chat', 'nonce');
    
    if (!current_user_can('edit_posts')) {
        wp_send_json_error('Permission denied');
    }
    
    $post_id = intval($_POST['post_id']);
    $post = get_post($post_id);
    
    if (!$post || $post->post_type !== 'therapy_group') {
        wp_send_json_error('Invalid therapy group');
    }
    
    $default_days = get_option('tsc_default_expiry_days', 30);
    
    $room_id = tsc_create_chat_room(
        $post_id,
        $post->post_title . ' Chat',
        'Chat room for ' . $post->post_title,
        get_current_user_id(),
        date('Y-m-d', strtotime("+{$default_days} days"))
    );
    
    if ($room_id) {
        wp_send_json_success(['room_id' => $room_id]);
    } else {
        wp_send_json_error('Failed to create chat room. Check error logs.');
    }
}

/**
 * AJAX: Chat actions (archive, reactivate, delete)
 */
add_action('wp_ajax_tsc_chat_action', 'tsc_ajax_chat_action');

function tsc_ajax_chat_action() {
    
    check_ajax_referer('tsc_chat_action', 'nonce');
    
    if (!current_user_can('edit_posts')) {
        wp_send_json_error('Permission denied');
    }
    
    $post_id = intval($_POST['post_id']);
    $action = sanitize_text_field($_POST['chat_action']);
    $room_id = get_post_meta($post_id, '_tsc_chat_room_id', true);
    
    if (!$room_id) {
        wp_send_json_error('No chat room found');
    }
    
    switch ($action) {
        case 'archive':
            tsc_archive_expired_chat($room_id, $post_id);
            wp_send_json_success('Archived');
            break;
            
        case 'reactivate':
            update_post_meta($room_id, '_tsc_status', 'active');
            delete_post_meta($post_id, '_tsc_chat_expired');
            wp_send_json_success('Reactivated');
            break;
            
        case 'delete':
            tsc_delete_expired_chat($room_id, $post_id);
            wp_send_json_success('Deleted');
            break;
            
        default:
            wp_send_json_error('Invalid action');
    }
}

/**
 * Add chat status column to therapy_group list
 */
add_filter('manage_therapy_group_posts_columns', 'tsc_add_chat_column');

function tsc_add_chat_column($columns) {
    $columns['tsc_chat'] = __('Chat Room', 'therapy-session-chat');
    return $columns;
}

add_action('manage_therapy_group_posts_custom_column', 'tsc_render_chat_column', 10, 2);

function tsc_render_chat_column($column, $post_id) {
    
    if ($column !== 'tsc_chat') {
        return;
    }
    
    $room_id = get_post_meta($post_id, '_tsc_chat_room_id', true);
    
    if (!$room_id || !get_post($room_id)) {
        echo '<span style="color: #999;">—</span>';
        return;
    }
    
    $chat_status = get_post_meta($room_id, '_tsc_status', true) ?: 'active';
    $allowed_users = get_post_meta($room_id, 'bm_chat_room_users', true);
    $member_count = is_array($allowed_users) ? count($allowed_users) : 0;
    
    $badges = [
        'active'   => '<span style="background:#28a745;color:#fff;padding:2px 6px;border-radius:3px;font-size:11px;">Active</span>',
        'archived' => '<span style="background:#ffc107;color:#000;padding:2px 6px;border-radius:3px;font-size:11px;">Archived</span>',
        'expired'  => '<span style="background:#dc3545;color:#fff;padding:2px 6px;border-radius:3px;font-size:11px;">Expired</span>',
    ];
    
    echo ($badges[$chat_status] ?? $chat_status) . ' <small>(' . $member_count . ')</small>';
}
