<?php
/**
 * Discord Therapy Chat Integration - ROLE-BASED ARCHITECTURE
 * 
 * A simplified PHP snippet using Discord's correct role-based access control.
 * Add this as a PHP snippet in WPCode or your theme's functions.php
 * 
 * Architecture:
 * - Each therapy group = 1 Discord role + 1 Discord channel
 * - Users get access via role assignment (NOT per-user permissions)
 * - No invite links needed - role grants automatic channel visibility
 * 
 * @version 2.0.0 (Refactored)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// ============================================================================
// SECTION 1: CONFIGURATION & SETTINGS
// ============================================================================

/**
 * Add Therapy Chats Settings as a top-level admin menu
 */
function dtc_add_settings_page() {
    add_menu_page(
        'Therapy Chats Settings', // Page title
        'Therapy Chats Settings', // Menu title
        'manage_options',
        'discord-therapy-chat',
        'dtc_settings_page_html',
        'dashicons-format-chat', // Icon
        60 // Position
    );
}
add_action('admin_menu', 'dtc_add_settings_page');

/**
 * Register settings
 */
function dtc_register_settings() {
    register_setting('dtc_settings', 'dtc_bot_token');
    register_setting('dtc_settings', 'dtc_server_id');
    register_setting('dtc_settings', 'dtc_category_id');
    register_setting('dtc_settings', 'dtc_enabled');
}
add_action('admin_init', 'dtc_register_settings');

/**
 * Settings page HTML
 */
function dtc_settings_page_html() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $test_result = null;
    if (isset($_GET['dtc_test']) && $_GET['dtc_test'] === '1') {
        $test_result = dtc_test_connection();
    }
    ?>
    <div class="wrap">
        <h1>Therapy Chats Settings (Role-Based)</h1>

        <?php if ($test_result): ?>
            <div class="notice notice-<?php echo $test_result['success'] ? 'success' : 'error'; ?>">
                <p><?php echo esc_html($test_result['message']); ?></p>
            </div>
        <?php endif; ?>

        <form method="post" action="options.php">
            <?php settings_fields('dtc_settings'); ?>

            <table class="form-table">
                <tr>
                    <th scope="row">Enable Discord Integration</th>
                    <td>
                        <label>
                            <input type="checkbox" name="dtc_enabled" value="1" <?php checked(get_option('dtc_enabled'), '1'); ?>>
                            Enable automatic Discord role and channel creation
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Discord Bot Token</th>
                    <td>
                        <input type="password" name="dtc_bot_token" value="<?php echo esc_attr(get_option('dtc_bot_token')); ?>" class="regular-text" autocomplete="off">
                        <p class="description">Your Discord Bot Token from the Developer Portal</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Discord Server (Guild) ID</th>
                    <td>
                        <input type="text" name="dtc_server_id" value="<?php echo esc_attr(get_option('dtc_server_id')); ?>" class="regular-text">
                        <p class="description">Right-click your server → Copy Server ID</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Category ID (Optional)</th>
                    <td>
                        <input type="text" name="dtc_category_id" value="<?php echo esc_attr(get_option('dtc_category_id')); ?>" class="regular-text">
                        <p class="description">Discord category to organize therapy channels</p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>

        <hr>

        <h2>Server Invite</h2>
        <?php
        $invite_url = get_option('dtc_server_invite_url');
        if ($invite_url):
        ?>
            <p><strong>Current Invite:</strong> <code><?php echo esc_html($invite_url); ?></code></p>
            <p>
                <a href="<?php echo admin_url('admin.php?page=discord-therapy-chat&dtc_regenerate_invite=1'); ?>" class="button button-secondary">
                    Regenerate Invite
                </a>
            </p>
        <?php else: ?>
            <p>No server invite generated yet.</p>
            <p>
                <a href="<?php echo admin_url('admin.php?page=discord-therapy-chat&dtc_create_invite=1'); ?>" class="button button-secondary">
                    Create Server Invite
                </a>
            </p>
        <?php endif; ?>

        <hr>

        <h2>Connection Test</h2>
        <p>
            <a href="<?php echo admin_url('admin.php?page=discord-therapy-chat&dtc_test=1'); ?>" class="button button-secondary">
                Test Discord Connection
            </a>
        </p>

        <hr>

        <h2>Architecture Overview</h2>
        <div style="background:#e8f4fd; padding:20px; border-radius:5px;">
            <h3>✅ How This Works (Role-Based)</h3>
            <ol>
                <li><strong>Therapy Group Created</strong> → Bot creates Discord role + channel</li>
                <li><strong>User Enrolls</strong> → Bot assigns role to user</li>
                <li><strong>Channel Appears</strong> → User automatically sees channel in Discord</li>
                <li><strong>Session Expires</strong> → Bot removes role, channel disappears</li>
            </ol>

            <h3>🔑 Key Concepts</h3>
            <ul>
                <li><strong>Role = Access:</strong> User has role → can see channel</li>
                <li><strong>No Invites:</strong> Roles grant automatic visibility</li>
                <li><strong>One Role per Group:</strong> Simple 1:1 mapping</li>
                <li><strong>WordPress = Truth:</strong> All enrollment managed in WordPress</li>
            </ul>
        </div>

        <hr>

        <h2>Required Bot Permissions</h2>
        <div style="background:#fff3cd; padding:15px; border-radius:5px;">
            <p><strong>⚠️ CRITICAL:</strong> Your bot needs these permissions:</p>
            <ul>
                <li>✅ Manage Roles</li>
                <li>✅ Manage Channels</li>
                <li>✅ View Channels</li>
                <li>✅ Send Messages</li>
                <li>✅ Read Message History</li>
                <li>✅ Create Instant Invite (for server invite links)</li>
            </ul>
            <p><strong>Role Hierarchy:</strong> Bot's role MUST be above therapy roles in Server Settings → Roles!</p>
            <p><strong>Important:</strong> Users MUST join the Discord server before role assignment will work.</p>
        </div>

        <hr>

        <h2>Therapy Groups</h2>
        <?php
        $therapy_groups = get_posts([
            'post_type' => 'therapy_group',
            'post_status' => 'publish',
            'numberposts' => -1
        ]);
        ?>

        <table class="widefat">
            <thead>
                <tr>
                    <th>Group</th>
                    <th>Discord Role</th>
                    <th>Discord Channel</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($therapy_groups as $group): ?>
                    <?php 
                    $role_id = get_post_meta($group->ID, '_dtc_discord_role_id', true);
                    $channel_id = get_post_meta($group->ID, '_dtc_discord_channel_id', true);
                    ?>
                    <tr>
                        <td><?php echo esc_html($group->post_title); ?></td>
                        <td><?php echo $role_id ? '✓ ' . esc_html($role_id) : '✗ Not created'; ?></td>
                        <td><?php echo $channel_id ? '✓ ' . esc_html($channel_id) : '✗ Not created'; ?></td>
                        <td>
                            <?php if (!$role_id || !$channel_id): ?>
                                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=discord-therapy-chat&dtc_create=' . $group->ID), 'dtc_create'); ?>" class="button button-small">Create</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php

    // Handle manual creation
    if (isset($_GET['dtc_create']) && wp_verify_nonce($_GET['_wpnonce'], 'dtc_create')) {
        $post_id = intval($_GET['dtc_create']);
        $result = dtc_create_therapy_discord_resources($post_id);
        if ($result['success']) {
            echo '<div class="notice notice-success"><p>Role and channel created!</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Error: ' . esc_html($result['message']) . '</p></div>';
        }
    }

    // Handle invite creation/regeneration
    if (isset($_GET['dtc_create_invite']) || isset($_GET['dtc_regenerate_invite'])) {
        $result = dtc_create_server_invite();
        if ($result['success']) {
            echo '<div class="notice notice-success"><p>Server invite created: ' . esc_html('https://discord.gg/' . $result['data']['code']) . '</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Error creating invite: ' . esc_html($result['message']) . '</p></div>';
        }
    }
}

// ============================================================================
// SECTION 2: DISCORD API FUNCTIONS
// ============================================================================

/**
 * Make Discord API request
 */
function dtc_discord_api($endpoint, $method = 'GET', $data = null) {
    $bot_token = get_option('dtc_bot_token');
    
    if (empty($bot_token)) {
        return ['success' => false, 'message' => 'Bot token not configured'];
    }
    
    $url = 'https://discord.com/api/v10' . $endpoint;
    
    $args = [
        'method' => $method,
        'headers' => [
            'Authorization' => 'Bot ' . $bot_token,
            'Content-Type' => 'application/json',
        ],
        'timeout' => 30,
    ];
    
    if ($data !== null) {
        $args['body'] = json_encode($data);
    }
    
    error_log("DTC: {$method} {$endpoint}");
    
    $response = wp_remote_request($url, $args);
    
    if (is_wp_error($response)) {
        error_log("DTC Error: " . $response->get_error_message());
        return ['success' => false, 'message' => $response->get_error_message()];
    }
    
    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);
    
    if ($code >= 200 && $code < 300) {
        return ['success' => true, 'data' => $body, 'code' => $code];
    }
    
    $error_msg = isset($body['message']) ? $body['message'] : 'Unknown error';
    error_log("DTC Failed: {$error_msg}");
    return ['success' => false, 'message' => $error_msg, 'code' => $code];
}

/**
 * Test connection
 */
function dtc_test_connection() {
    $server_id = get_option('dtc_server_id');
    
    if (empty($server_id)) {
        return ['success' => false, 'message' => 'Server ID not configured'];
    }
    
    $result = dtc_discord_api('/guilds/' . $server_id);
    
    if ($result['success']) {
        return ['success' => true, 'message' => '✅ Connected to: ' . $result['data']['name']];
    }
    
    return ['success' => false, 'message' => 'Connection failed: ' . $result['message']];
}

/**
 * Create Discord role for therapy group
 */
function dtc_create_role($name) {
    $server_id = get_option('dtc_server_id');
    
    if (empty($server_id)) {
        return ['success' => false, 'message' => 'Server ID not configured'];
    }
    
    $role_name = sanitize_text_field($name);
    $role_name = substr($role_name, 0, 100);
    
    $role_data = [
        'name' => $role_name,
        'permissions' => '0', // No special permissions
        'color' => 5793266, // Blue color
        'hoist' => false, // Don't display separately
        'mentionable' => false,
    ];
    
    $result = dtc_discord_api('/guilds/' . $server_id . '/roles', 'POST', $role_data);
    
    if ($result['success']) {
        error_log("DTC: Created role '{$role_name}' (ID: {$result['data']['id']})");
    }
    
    return $result;
}

/**
 * Create Discord channel for therapy group
 */
function dtc_create_channel($name, $role_id) {
    $server_id = get_option('dtc_server_id');
    $category_id = get_option('dtc_category_id');
    
    if (empty($server_id)) {
        return ['success' => false, 'message' => 'Server ID not configured'];
    }
    
    $channel_name = sanitize_title($name);
    $channel_name = substr($channel_name, 0, 100);
    
    $channel_data = [
        'name' => $channel_name,
        'type' => 0, // Text channel
        'topic' => 'Therapy Group: ' . $name,
        'permission_overwrites' => [
            // @everyone: DENY VIEW
            [
                'id' => $server_id,
                'type' => 0,
                'deny' => '1024',
            ],
            // Therapy role: ALLOW VIEW + SEND
            [
                'id' => $role_id,
                'type' => 0,
                'allow' => '68608', // VIEW + SEND + READ_HISTORY
            ],
        ],
    ];
    
    if (!empty($category_id)) {
        $channel_data['parent_id'] = $category_id;
    }
    
    $result = dtc_discord_api('/guilds/' . $server_id . '/channels', 'POST', $channel_data);
    
    if ($result['success']) {
        error_log("DTC: Created channel '#{$channel_name}' (ID: {$result['data']['id']})");
    }
    
    return $result;
}

/**
 * Assign role to user
 */
function dtc_assign_role($discord_user_id, $role_id) {
    $server_id = get_option('dtc_server_id');
    
    if (empty($server_id) || empty($discord_user_id) || empty($role_id)) {
        return ['success' => false, 'message' => 'Missing required parameters'];
    }
    
    $endpoint = "/guilds/{$server_id}/members/{$discord_user_id}/roles/{$role_id}";
    $result = dtc_discord_api($endpoint, 'PUT');
    
    if ($result['success']) {
        error_log("DTC: Assigned role {$role_id} to user {$discord_user_id}");
    }
    
    return $result;
}

/**
 * Remove role from user
 */
function dtc_remove_role($discord_user_id, $role_id) {
    $server_id = get_option('dtc_server_id');
    
    if (empty($server_id) || empty($discord_user_id) || empty($role_id)) {
        return ['success' => false, 'message' => 'Missing required parameters'];
    }
    
    $endpoint = "/guilds/{$server_id}/members/{$discord_user_id}/roles/{$role_id}";
    $result = dtc_discord_api($endpoint, 'DELETE');
    
    if ($result['success']) {
        error_log("DTC: Removed role {$role_id} from user {$discord_user_id}");
    }
    
    return $result;
}

/**
 * Create server invite
 */
function dtc_create_server_invite() {
    $server_id = get_option('dtc_server_id');
    
    if (empty($server_id)) {
        return ['success' => false, 'message' => 'Server ID not configured'];
    }
    
    // Try to get channels to create invite from
    $channels_result = dtc_discord_api('/guilds/' . $server_id . '/channels', 'GET');
    
    if (!$channels_result['success'] || empty($channels_result['data'])) {
        return ['success' => false, 'message' => 'Could not fetch server channels'];
    }
    
    // Find a text channel to create invite from (prefer general or first text channel)
    $invite_channel = null;
    foreach ($channels_result['data'] as $channel) {
        if ($channel['type'] == 0) { // Text channel
            $invite_channel = $channel['id'];
            if (stripos($channel['name'], 'general') !== false) {
                break; // Prefer general channel
            }
        }
    }
    
    if (!$invite_channel) {
        return ['success' => false, 'message' => 'No suitable channel found for invite'];
    }
    
    // Create invite that never expires
    $invite_data = [
        'max_age' => 0,      // Never expires
        'max_uses' => 0,     // Unlimited uses
        'temporary' => false, // Not temporary membership
    ];
    
    $result = dtc_discord_api('/channels/' . $invite_channel . '/invites', 'POST', $invite_data);
    
    if ($result['success']) {
        // Cache the invite code
        update_option('dtc_server_invite_code', $result['data']['code']);
        update_option('dtc_server_invite_url', 'https://discord.gg/' . $result['data']['code']);
    }
    
    return $result;
}

/**
 * Get server invite URL (cached or create new)
 */
function dtc_get_server_invite() {
    $cached_url = get_option('dtc_server_invite_url');
    
    if (!empty($cached_url)) {
        return ['success' => true, 'url' => $cached_url];
    }
    
    $result = dtc_create_server_invite();
    
    if ($result['success']) {
        return ['success' => true, 'url' => 'https://discord.gg/' . $result['data']['code']];
    }
    
    return $result;
}

/**
 * Search for user in server by username and return their Discord user ID
 * Returns array with success, user_id, and full username (with discriminator if applicable)
 */
function dtc_find_user_by_username($username) {
    $server_id = get_option('dtc_server_id');
    
    if (empty($server_id) || empty($username)) {
        return ['success' => false, 'message' => 'Missing server ID or username'];
    }
    
    // Clean username - remove @ if user included it
    $username = ltrim($username, '@');
    
    // Try search endpoint first (more efficient)
    $search_result = dtc_discord_api('/guilds/' . $server_id . '/members/search?query=' . urlencode($username) . '&limit=10', 'GET');
    
    if ($search_result['success'] && !empty($search_result['data'])) {
        // Check for exact match (case-insensitive)
        foreach ($search_result['data'] as $member) {
            if (!isset($member['user'])) continue;
            
            $user = $member['user'];
            $member_username = $user['username'];
            
            // New username system (no discriminator) - exact match
            if (strcasecmp($member_username, $username) === 0) {
                return [
                    'success' => true,
                    'user_id' => $user['id'],
                    'username' => $member_username,
                    'display_name' => $member['nick'] ?? $user['global_name'] ?? $member_username,
                    'avatar' => $user['avatar'] ?? null
                ];
            }
            
            // Old username system with discriminator (username#1234)
            if (isset($user['discriminator']) && $user['discriminator'] !== '0') {
                $full_username = $member_username . '#' . $user['discriminator'];
                if (strcasecmp($full_username, $username) === 0 || strcasecmp($member_username, $username) === 0) {
                    return [
                        'success' => true,
                        'user_id' => $user['id'],
                        'username' => $full_username,
                        'display_name' => $member['nick'] ?? $user['global_name'] ?? $member_username,
                        'avatar' => $user['avatar'] ?? null
                    ];
                }
            }
        }
        
        // No exact match found
        if (count($search_result['data']) > 0) {
            // Suggest similar usernames
            $suggestions = array_slice(array_map(function($m) {
                return $m['user']['username'];
            }, $search_result['data']), 0, 3);
            
            return [
                'success' => false,
                'message' => 'No exact match found. Did you mean: ' . implode(', ', $suggestions) . '?'
            ];
        }
    }
    
    return ['success' => false, 'message' => 'User not found in server. Make sure you joined the server first!'];
}

/**
 * Check if user is a member of the server
 */
function dtc_check_user_in_server($discord_user_id) {
    $server_id = get_option('dtc_server_id');
    
    if (empty($server_id) || empty($discord_user_id)) {
        return false;
    }
    
    $result = dtc_discord_api('/guilds/' . $server_id . '/members/' . $discord_user_id, 'GET');
    
    return $result['success'];
}

/**
 * Send message to channel
 */
function dtc_send_message($channel_id, $message) {
    if (empty($channel_id)) {
        return ['success' => false, 'message' => 'Missing channel ID'];
    }
    
    return dtc_discord_api('/channels/' . $channel_id . '/messages', 'POST', [
        'content' => $message
    ]);
}

/**
 * Delete role
 */
function dtc_delete_role($role_id) {
    $server_id = get_option('dtc_server_id');
    
    if (empty($server_id) || empty($role_id)) {
        return ['success' => false, 'message' => 'Missing parameters'];
    }
    
    return dtc_discord_api("/guilds/{$server_id}/roles/{$role_id}", 'DELETE');
}

/**
 * Delete channel
 */
function dtc_delete_channel($channel_id) {
    if (empty($channel_id)) {
        return ['success' => false, 'message' => 'Missing channel ID'];
    }
    
    return dtc_discord_api('/channels/' . $channel_id, 'DELETE');
}

// ============================================================================
// SECTION 3: WORDPRESS HOOKS & AUTOMATION
// ============================================================================

/**
 * Create Discord resources when therapy_group is published
 */
function dtc_on_therapy_group_publish($new_status, $old_status, $post) {
    if ($post->post_type !== 'therapy_group') {
        return;
    }
    
    if ($new_status !== 'publish' || $old_status === 'publish') {
        return;
    }
    
    if (get_option('dtc_enabled') !== '1') {
        return;
    }
    
    // Check if already created
    $role_id = get_post_meta($post->ID, '_dtc_discord_role_id', true);
    if (!empty($role_id)) {
        return;
    }
    
    dtc_create_therapy_discord_resources($post->ID);
}
add_action('transition_post_status', 'dtc_on_therapy_group_publish', 10, 3);

/**
 * Create Discord role and channel for therapy group
 */
function dtc_create_therapy_discord_resources($post_id) {
    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'therapy_group') {
        return ['success' => false, 'message' => 'Invalid therapy group'];
    }
    
    // Step 1: Create role
    $role_result = dtc_create_role($post->post_title);
    
    if (!$role_result['success']) {
        return $role_result;
    }
    
    $role_id = $role_result['data']['id'];
    update_post_meta($post_id, '_dtc_discord_role_id', $role_id);
    
    // Step 2: Create channel with role permissions
    $channel_result = dtc_create_channel($post->post_title, $role_id);
    
    if (!$channel_result['success']) {
        // Rollback: delete role
        dtc_delete_role($role_id);
        delete_post_meta($post_id, '_dtc_discord_role_id');
        return $channel_result;
    }
    
    $channel_id = $channel_result['data']['id'];
    update_post_meta($post_id, '_dtc_discord_channel_id', $channel_id);
    
    // Step 3: Send welcome message
    $welcome_msg = "👋 Welcome to **{$post->post_title}**!\n\n";
    $welcome_msg .= "This is your private therapy group channel.\n";
    $welcome_msg .= "Only enrolled members can see and participate here.";
    dtc_send_message($channel_id, $welcome_msg);
    
    error_log("DTC: Created resources for '{$post->post_title}' - Role: {$role_id}, Channel: {$channel_id}");
    
    return ['success' => true, 'role_id' => $role_id, 'channel_id' => $channel_id];
}

/**
 * Clean up when therapy group is trashed
 */
function dtc_on_therapy_group_trash($post_id) {
    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'therapy_group') {
        return;
    }
    
    // Remove all users from this group
    dtc_remove_all_users_from_group($post_id);
    
    // Optional: Delete Discord resources
    // Uncomment to auto-delete role and channel:
    /*
    $role_id = get_post_meta($post_id, '_dtc_discord_role_id', true);
    $channel_id = get_post_meta($post_id, '_dtc_discord_channel_id', true);
    
    if ($role_id) dtc_delete_role($role_id);
    if ($channel_id) dtc_delete_channel($channel_id);
    */
}
add_action('wp_trash_post', 'dtc_on_therapy_group_trash');

/**
 * Assign role when user is enrolled in therapy group
 */
function dtc_on_user_assigned_to_group($meta_id, $user_id, $meta_key, $meta_value) {
    if ($meta_key !== 'therapy_group_id' && $meta_key !== 'assigned_group') {
        return;
    }
    
    if (get_option('dtc_enabled') !== '1') {
        return;
    }
    
    if (empty($meta_value)) {
        return;
    }
    
    dtc_assign_user_to_therapy_group($user_id, $meta_value);
}
add_action('updated_user_meta', 'dtc_on_user_assigned_to_group', 10, 4);
add_action('added_user_meta', 'dtc_on_user_assigned_to_group', 10, 4);

/**
 * Assign Discord role to user
 */
function dtc_assign_user_to_therapy_group($user_id, $therapy_group_id) {
    // Get user's Discord ID
    $discord_user_id = get_user_meta($user_id, '_dtc_discord_user_id', true);
    
    if (empty($discord_user_id)) {
        error_log("DTC: User {$user_id} hasn't linked Discord yet");
        return ['success' => false, 'message' => 'Discord not linked'];
    }
    
    // CRITICAL: Check if user is in the server first
    if (!dtc_check_user_in_server($discord_user_id)) {
        error_log("DTC: User {$user_id} (Discord ID: {$discord_user_id}) is not in the server");
        return ['success' => false, 'message' => 'User must join Discord server first'];
    }
    
    // Get role ID
    $role_id = get_post_meta($therapy_group_id, '_dtc_discord_role_id', true);
    
    if (empty($role_id)) {
        error_log("DTC: Therapy group {$therapy_group_id} has no Discord role");
        return ['success' => false, 'message' => 'No Discord role for this group'];
    }
    
    // Assign role
    $result = dtc_assign_role($discord_user_id, $role_id);
    
    if ($result['success']) {
        // Send notification in channel
        $channel_id = get_post_meta($therapy_group_id, '_dtc_discord_channel_id', true);
        if ($channel_id) {
            $user = get_user_by('ID', $user_id);
            dtc_send_message($channel_id, "👋 **{$user->display_name}** has joined the group!");
        }
    }
    
    return $result;
}

/**
 * Remove user from therapy group
 */
function dtc_remove_user_from_therapy_group($user_id, $therapy_group_id) {
    $discord_user_id = get_user_meta($user_id, '_dtc_discord_user_id', true);
    $role_id = get_post_meta($therapy_group_id, '_dtc_discord_role_id', true);
    
    if (empty($discord_user_id) || empty($role_id)) {
        return ['success' => false, 'message' => 'Missing Discord or role ID'];
    }
    
    return dtc_remove_role($discord_user_id, $role_id);
}

/**
 * Remove all users from a therapy group
 */
function dtc_remove_all_users_from_group($therapy_group_id) {
    $users = get_users([
        'meta_query' => [
            [
                'key' => 'assigned_group',
                'value' => $therapy_group_id,
                'compare' => '='
            ]
        ]
    ]);
    
    foreach ($users as $user) {
        dtc_remove_user_from_therapy_group($user->ID, $therapy_group_id);
        delete_user_meta($user->ID, 'assigned_group');
    }
}

// ============================================================================
// SECTION 4: SHORTCODES
// ============================================================================

/**
 * Shortcode: [dtc_join_chat]
 * Display therapy chat information
 */
function dtc_shortcode_join_chat($atts) {
    if (!is_user_logged_in()) {
        return '<p>Please log in to access your therapy chat.</p>';
    }
    
    $user_id = get_current_user_id();
    
    $therapy_group_id = get_user_meta($user_id, 'therapy_group_id', true);
    if (empty($therapy_group_id)) {
        $therapy_group_id = get_user_meta($user_id, 'assigned_group', true);
    }
    
    if (empty($therapy_group_id)) {
        return '<div class="dtc-notice dtc-info"></div>';
    }
    
    $therapy_group = get_post($therapy_group_id);
    if (!$therapy_group) {
        return '<div class="dtc-notice dtc-error">Therapy group not found.</div>';
    }
    
    $discord_user_id = get_user_meta($user_id, '_dtc_discord_user_id', true);
    $discord_username = get_user_meta($user_id, '_dtc_discord_username', true);
    $channel_id = get_post_meta($therapy_group_id, '_dtc_discord_channel_id', true);
    
    // Check if user is in the Discord server
    $in_server = !empty($discord_user_id) && dtc_check_user_in_server($discord_user_id);
    
    // Get server invite
    $invite_result = dtc_get_server_invite();
    $server_invite = $invite_result['success'] ? $invite_result['url'] : null;
    
    ob_start();
    ?>
    <div class="dtc-chat-box">
        <h3>🗨️ Your Therapy Group Chat</h3>
        <p><strong>Group:</strong> <?php echo esc_html($therapy_group->post_title); ?></p>
        
        <?php if (empty($channel_id)): ?>
            <!-- Channel not created yet -->
            <div class="dtc-notice dtc-info">
                <p>📋 Your therapy chat channel is being set up. Please check back soon.</p>
            </div>
            
        <?php else: ?>
            <!-- Show 3-step setup process -->
            <div style="background:#f8f9fa; padding:20px; border-radius:5px; margin:20px 0;">
                <h4 style="margin-top:0;">📋 Setup Steps:</h4>
                
                <!-- Step 1: Join Server -->
                <div style="margin-bottom:20px; padding:15px; background:<?php echo $in_server ? '#d4edda' : '#fff'; ?>; border-left:4px solid <?php echo $in_server ? '#28a745' : '#17a2b8'; ?>; border-radius:4px;">
                    <h5 style="margin:0 0 10px 0;">
                        <?php echo $in_server ? '✅' : '1️⃣'; ?> Join Our Discord Server
                    </h5>
                    <?php if ($in_server): ?>
                        <p style="margin:0; color:#155724;">You're already in the server!</p>
                    <?php else: ?>
                        <p style="margin:0 0 10px 0;">Click the button below to join our Discord server:</p>
                        <?php if ($server_invite): ?>
                            <a href="<?php echo esc_url($server_invite); ?>" target="_blank" class="dtc-button">
                                Join Discord Server
                            </a>
                        <?php else: ?>
                            <p style="color:#721c24; margin:0;">⚠️ Server invite not available. Please contact support.</p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                
                <!-- Step 2: Link Discord ID -->
                <div style="margin-bottom:20px; padding:15px; background:<?php echo !empty($discord_user_id) ? '#d4edda' : '#fff'; ?>; border-left:4px solid <?php echo !empty($discord_user_id) ? '#28a745' : '#ffc107'; ?>; border-radius:4px;">
                    <h5 style="margin:0 0 10px 0;">
                        <?php echo !empty($discord_user_id) ? '✅' : '2️⃣'; ?> Link Your Discord Account
                    </h5>
                    <?php if (!empty($discord_user_id)): ?>
                        <p style="margin:0; color:#155724;">Discord linked! <?php echo $discord_username ? 'Connected as: ' . esc_html($discord_username) : ''; ?></p>
                    <?php else: ?>
                        <?php echo dtc_shortcode_discord_connect([]); ?>
                    <?php endif; ?>
                </div>
                
                <!-- Step 3: Access Channel -->
                <div style="padding:15px; background:<?php echo ($in_server && !empty($discord_user_id)) ? '#d4edda' : '#fff'; ?>; border-left:4px solid <?php echo ($in_server && !empty($discord_user_id)) ? '#28a745' : '#6c757d'; ?>; border-radius:4px;">
                    <h5 style="margin:0 0 10px 0;">
                        <?php echo ($in_server && !empty($discord_user_id)) ? '✅' : '3️⃣'; ?> Access Your Therapy Chat
                    </h5>
                    <?php if ($in_server && !empty($discord_user_id)): ?>
                        <p style="margin:0 0 10px 0; color:#155724;">You're all set! Open Discord to see your channel:</p>
                        <p style="margin:0;">
                            <a href="https://discord.com/channels/<?php echo esc_attr(get_option('dtc_server_id')); ?>/<?php echo esc_attr($channel_id); ?>" 
                               target="_blank" class="dtc-button">
                                🚀 Open Discord Chat
                            </a>
                        </p>
                        <p style="margin:10px 0 0 0; font-size:13px; color:#666;">
                            Look for channel: <code>#<?php echo esc_html(sanitize_title($therapy_group->post_title)); ?></code>
                        </p>
                    <?php else: ?>
                        <p style="margin:0; color:#6c757d;">Complete steps 1 and 2 first to access your chat.</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if ($in_server && !empty($discord_user_id)): ?>
            <details style="margin-top:15px;">
                <summary style="cursor:pointer; color:#666; font-size:13px;">Not seeing the channel?</summary>
                <div style="padding:10px 0; font-size:13px;">
                    <p><strong>Try these steps:</strong></p>
                    <ol>
                        <li>Refresh Discord (press Ctrl+R or Cmd+R)</li>
                        <li>Wait 10-15 seconds for changes to sync</li>
                        <li>Check the server's channel list carefully</li>
                        <li>Make sure you're in the correct Discord server</li>
                        <li>If still not visible after 1 minute, contact support</li>
                    </ol>
                </div>
            </details>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    
    <style>
    .dtc-chat-box {
        background: #f8f9fa;
        border: 1px solid #dee2e6;
        border-radius: 8px;
        padding: 20px;
        max-width: 600px;
        margin: 20px 0;
    }
    .dtc-chat-box h3 {
        margin-top: 0;
        color: #5865F2;
    }
    .dtc-notice {
        padding: 15px;
        border-radius: 5px;
        margin: 15px 0;
    }
    .dtc-notice.dtc-success {
        background: #d4edda;
        border: 1px solid #28a745;
        color: #155724;
    }
    .dtc-notice.dtc-info {
        background: #d1ecf1;
        border: 1px solid #17a2b8;
        color: #0c5460;
    }
    .dtc-notice.dtc-warning {
        background: #fff3cd;
        border: 1px solid #ffc107;
        color: #856404;
    }
    .dtc-notice.dtc-error {
        background: #f8d7da;
        border: 1px solid #dc3545;
        color: #721c24;
    }
    .dtc-button {
        display: inline-block;
        padding: 12px 24px;
        background: #5865F2;
        color: white !important;
        text-decoration: none;
        border-radius: 5px;
        font-weight: bold;
    }
    .dtc-button:hover {
        background: #4752C4;
    }
    </style>
    <?php
    return ob_get_clean();
}
add_shortcode('dtc_join_chat', 'dtc_shortcode_join_chat');

/**
 * Shortcode: [dtc_discord_connect]
 * Let users link their Discord account
 */
function dtc_shortcode_discord_connect($atts) {
    if (!is_user_logged_in()) {
        return '<p>Please log in to link your Discord account.</p>';
    }
    
    $user_id = get_current_user_id();
    $discord_user_id = get_user_meta($user_id, '_dtc_discord_user_id', true);
    $discord_username = get_user_meta($user_id, '_dtc_discord_username', true);
    
    // Handle form submission
    $message = '';
    if (isset($_POST['dtc_discord_username_submit']) && wp_verify_nonce($_POST['_wpnonce'], 'dtc_discord_connect')) {
        $input_username = sanitize_text_field($_POST['dtc_discord_username']);
        
        if (!empty($input_username)) {
            // Search for user in server
            $search_result = dtc_find_user_by_username($input_username);
            
            if ($search_result['success']) {
                // Save both user ID and username
                update_user_meta($user_id, '_dtc_discord_user_id', $search_result['user_id']);
                update_user_meta($user_id, '_dtc_discord_username', $search_result['username']);
                update_user_meta($user_id, '_dtc_discord_display_name', $search_result['display_name']);
                
                $discord_user_id = $search_result['user_id'];
                $discord_username = $search_result['username'];
                
                $message = '<div class="dtc-notice dtc-success">✅ Discord account linked successfully as <strong>' . esc_html($search_result['display_name']) . '</strong>!</div>';
                
                // Auto-assign to therapy group if enrolled
                $therapy_group_id = get_user_meta($user_id, 'therapy_group_id', true);
                if (empty($therapy_group_id)) {
                    $therapy_group_id = get_user_meta($user_id, 'assigned_group', true);
                }
                
                if ($therapy_group_id) {
                    $assign_result = dtc_assign_user_to_therapy_group($user_id, $therapy_group_id);
                    if ($assign_result['success']) {
                        $message .= '<div class="dtc-notice dtc-success">✅ You\'ve been added to your therapy group channel!</div>';
                    }
                }
            } else {
                $message = '<div class="dtc-notice dtc-error">❌ ' . esc_html($search_result['message']) . '</div>';
            }
        } else {
            $message = '<div class="dtc-notice dtc-error">❌ Please enter your Discord username</div>';
        }
    }
    
    ob_start();
    ?>
    <div class="dtc-connect-box">
        <h4>🔗 Link Your Discord Account</h4>
        
        <?php echo $message; ?>
        
        <?php if (!empty($discord_user_id)): ?>
            <div class="dtc-connected">
                <p>✅ <strong>Discord Connected!</strong></p>
                <?php 
                $display_name = get_user_meta($user_id, '_dtc_discord_display_name', true);
                if ($display_name): 
                ?>
                    <p>Connected as: <strong><?php echo esc_html($display_name); ?></strong></p>
                    <?php if ($discord_username && $discord_username !== $display_name): ?>
                        <p style="font-size:13px; color:#666;">Username: <?php echo esc_html($discord_username); ?></p>
                    <?php endif; ?>
                <?php elseif ($discord_username): ?>
                    <p>Account: <strong><?php echo esc_html($discord_username); ?></strong></p>
                <?php else: ?>
                    <p>Discord ID: <code><?php echo esc_html($discord_user_id); ?></code></p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div style="background:#fff3cd; padding:12px; border-radius:4px; margin-bottom:15px; border:1px solid #ffc107;">
                <p style="margin:0; font-size:13px; color:#856404;">
                    ⚠️ <strong>Important:</strong> Make sure you've joined the Discord server first (Step 1)!
                </p>
            </div>
            
            <form method="post">
                <?php wp_nonce_field('dtc_discord_connect'); ?>
                <p>
                    <label for="dtc_discord_username"><strong>Your Discord Username:</strong></label><br>
                    <input type="text" name="dtc_discord_username" id="dtc_discord_username" 
                           placeholder="e.g., johndoe or johndoe#1234"
                           required
                           style="width:100%; max-width:300px; padding:10px; margin-top:5px; font-size:14px;">
                    <br>
                    <span style="font-size:12px; color:#666; margin-top:5px; display:inline-block;">
                        Enter your Discord username (found at the bottom left of Discord)
                    </span>
                </p>
                <button type="submit" name="dtc_discord_username_submit" class="dtc-button">
                    Link Discord Account
                </button>
            </form>
            
            <details style="margin-top:15px;">
                <summary style="cursor:pointer; color:#5865F2; font-size:13px;">Where do I find my Discord username?</summary>
                <div style="padding:10px 0; font-size:13px;">
                    <p><strong>Easy way:</strong></p>
                    <ol style="padding-left:20px; margin:5px 0;">
                        <li>Open Discord (app or web)</li>
                        <li>Look at the <strong>bottom left corner</strong></li>
                        <li>You'll see your username next to your avatar</li>
                        <li>Copy and paste it here</li>
                    </ol>
                    <p style="margin-top:10px;"><strong>Note:</strong> If you have an old account, your username might look like "username#1234" - that's fine, enter it exactly as shown!</p>
                </div>
            </details>
        <?php endif; ?>
    </div>
    
    <style>
    .dtc-connect-box {
        background: #f0f0f0;
        padding: 20px;
        border-radius: 5px;
        margin: 20px 0;
        max-width: 500px;
    }
    .dtc-connect-box h4 {
        margin-top: 0;
    }
    .dtc-connected {
        background: #d4edda;
        border: 1px solid #28a745;
        padding: 15px;
        border-radius: 5px;
    }
    </style>
    <?php
    return ob_get_clean();
}
add_shortcode('dtc_discord_connect', 'dtc_shortcode_discord_connect');

/**
 * Shortcode: [dtc_debug]
 * Debug information for admins
 */
function dtc_shortcode_debug($atts) {
    if (!current_user_can('manage_options')) {
        return '<p></p>';
    }
    
    $server_id = get_option('dtc_server_id');
    
    ob_start();
    ?>
    <div style="background:#f5f5f5; padding:20px; border:1px solid #ddd; font-family:monospace; font-size:12px;">
        <h3>Discord Therapy Chat - Debug Info (Role-Based)</h3>
        
        <h4>Configuration</h4>
        <ul>
            <li><strong>Enabled:</strong> <?php echo get_option('dtc_enabled') ? 'Yes' : 'No'; ?></li>
            <li><strong>Server ID:</strong> <?php echo get_option('dtc_server_id') ?: 'Not set'; ?></li>
            <li><strong>Bot Token:</strong> <?php echo get_option('dtc_bot_token') ? '✓ Set' : '✗ Not set'; ?></li>
        </ul>
        
        <h4>Therapy Groups</h4>
        <table style="width:100%; border-collapse:collapse; background:#fff;">
            <tr style="background:#eee;">
                <th style="border:1px solid #ddd; padding:5px; text-align:left;">Group</th>
                <th style="border:1px solid #ddd; padding:5px; text-align:left;">Role ID</th>
                <th style="border:1px solid #ddd; padding:5px; text-align:left;">Channel ID</th>
            </tr>
            <?php
            $groups = get_posts(['post_type' => 'therapy_group', 'post_status' => 'publish', 'numberposts' => -1]);
            foreach ($groups as $group):
                $role_id = get_post_meta($group->ID, '_dtc_discord_role_id', true);
                $channel_id = get_post_meta($group->ID, '_dtc_discord_channel_id', true);
            ?>
            <tr>
                <td style="border:1px solid #ddd; padding:5px;"><?php echo esc_html($group->post_title); ?></td>
                <td style="border:1px solid #ddd; padding:5px;"><?php echo $role_id ?: '-'; ?></td>
                <td style="border:1px solid #ddd; padding:5px;"><?php echo $channel_id ?: '-'; ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        
        <h4>Users with Discord Connected</h4>
        <table style="width:100%; border-collapse:collapse; background:#fff; margin-top:10px;">
            <tr style="background:#eee;">
                <th style="border:1px solid #ddd; padding:5px; text-align:left;">User</th>
                <th style="border:1px solid #ddd; padding:5px; text-align:left;">Discord Username</th>
                <th style="border:1px solid #ddd; padding:5px; text-align:left;">Discord ID</th>
                <th style="border:1px solid #ddd; padding:5px; text-align:left;">Assigned Group</th>
            </tr>
            <?php
            $users = get_users(['meta_key' => '_dtc_discord_user_id', 'meta_compare' => 'EXISTS']);
            foreach ($users as $user):
                $discord_id = get_user_meta($user->ID, '_dtc_discord_user_id', true);
                $discord_username = get_user_meta($user->ID, '_dtc_discord_username', true);
                $discord_display = get_user_meta($user->ID, '_dtc_discord_display_name', true);
                $group_id = get_user_meta($user->ID, 'assigned_group', true);
                $group = $group_id ? get_post($group_id) : null;
            ?>
            <tr>
                <td style="border:1px solid #ddd; padding:5px;"><?php echo esc_html($user->display_name); ?></td>
                <td style="border:1px solid #ddd; padding:5px;">
                    <?php if ($discord_display): ?>
                        <strong><?php echo esc_html($discord_display); ?></strong><br>
                    <?php endif; ?>
                    <?php if ($discord_username): ?>
                        <span style="font-size:11px; color:#666;"><?php echo esc_html($discord_username); ?></span>
                    <?php else: ?>
                        <em>-</em>
                    <?php endif; ?>
                </td>
                <td style="border:1px solid #ddd; padding:5px; font-size:10px;"><?php echo esc_html($discord_id); ?></td>
                <td style="border:1px solid #ddd; padding:5px;"><?php echo $group ? esc_html($group->post_title) : '-'; ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('dtc_debug', 'dtc_shortcode_debug');

// ============================================================================
// SECTION 5: ADMIN ENHANCEMENTS
// ============================================================================

/**
 * Add Discord column to therapy_group admin list
 */
function dtc_add_admin_columns($columns) {
    $columns['discord_role'] = 'Discord Role';
    $columns['discord_channel'] = 'Discord Channel';
    return $columns;
}
add_filter('manage_therapy_group_posts_columns', 'dtc_add_admin_columns');

/**
 * Display Discord column content
 */
function dtc_admin_column_content($column, $post_id) {
    if ($column === 'discord_role') {
        $role_id = get_post_meta($post_id, '_dtc_discord_role_id', true);
        echo $role_id ? '✓' : '✗';
    }
    
    if ($column === 'discord_channel') {
        $channel_id = get_post_meta($post_id, '_dtc_discord_channel_id', true);
        echo $channel_id ? '✓' : '✗';
    }
}
add_action('manage_therapy_group_posts_custom_column', 'dtc_admin_column_content', 10, 2);

/**
 * Add Discord fields to user profile
 */
function dtc_user_profile_fields($user) {
    $discord_username = get_user_meta($user->ID, '_dtc_discord_username', true);
    $discord_user_id = get_user_meta($user->ID, '_dtc_discord_user_id', true);
    $discord_display = get_user_meta($user->ID, '_dtc_discord_display_name', true);
    ?>
    <h3>Discord Integration</h3>
    <table class="form-table">
        <tr>
            <th><label for="dtc_discord_username_lookup">Discord Username Lookup</label></th>
            <td>
                <input type="text" name="dtc_discord_username_lookup" id="dtc_discord_username_lookup" 
                       placeholder="Enter Discord username to lookup" 
                       class="regular-text">
                <button type="button" id="dtc_lookup_btn" class="button" onclick="dtcLookupUsername()">
                    Lookup & Link
                </button>
                <p class="description">Enter the user's Discord username to automatically find and link their account</p>
            </td>
        </tr>
        <tr>
            <th><label>Linked Discord Account</label></th>
            <td>
                <?php if ($discord_username): ?>
                    <p><strong>Username:</strong> <?php echo esc_html($discord_username); ?></p>
                    <?php if ($discord_display): ?>
                        <p><strong>Display Name:</strong> <?php echo esc_html($discord_display); ?></p>
                    <?php endif; ?>
                    <p><strong>User ID:</strong> <code><?php echo esc_html($discord_user_id); ?></code></p>
                <?php else: ?>
                    <p><em>Not linked yet</em></p>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th><label for="dtc_discord_user_id">Manual Discord User ID</label></th>
            <td>
                <input type="text" name="dtc_discord_user_id" id="dtc_discord_user_id" 
                       value="<?php echo esc_attr($discord_user_id); ?>" 
                       class="regular-text">
                <p class="description">Only use this if automatic lookup fails (17-19 digits)</p>
            </td>
        </tr>
    </table>
    <script>
    function dtcLookupUsername() {
        const username = document.getElementById('dtc_discord_username_lookup').value;
        if (!username) {
            alert('Please enter a Discord username');
            return;
        }
        
        const btn = document.getElementById('dtc_lookup_btn');
        btn.disabled = true;
        btn.textContent = 'Looking up...';
        
        // This would need AJAX implementation for admin
        alert('Username lookup: ' + username + '\n\nNote: Automatic lookup from admin panel requires AJAX implementation.\nFor now, have the user enter their username on the frontend form.');
        
        btn.disabled = false;
        btn.textContent = 'Lookup & Link';
    }
    </script>
    <?php
}
add_action('show_user_profile', 'dtc_user_profile_fields');
add_action('edit_user_profile', 'dtc_user_profile_fields');

/**
 * Save Discord fields from user profile
 */
function dtc_save_user_profile_fields($user_id) {
    if (!current_user_can('edit_user', $user_id)) {
        return;
    }
    
    // Manual user ID entry (for admins)
    if (isset($_POST['dtc_discord_user_id'])) {
        $discord_id = sanitize_text_field($_POST['dtc_discord_user_id']);
        if (!empty($discord_id) && preg_match('/^\d{17,19}$/', $discord_id)) {
            update_user_meta($user_id, '_dtc_discord_user_id', $discord_id);
        }
    }
}
add_action('personal_options_update', 'dtc_save_user_profile_fields');
add_action('edit_user_profile_update', 'dtc_save_user_profile_fields');

// ============================================================================
// END OF REFACTORED SNIPPET
// ============================================================================


// ============================================================================
// SECTION 6: CRON FOR REMOVING USERS FROM EXPIRED GROUPS
// ============================================================================

/**
 * Schedule hourly cron event for removing users from expired therapy groups
 */
function dtc_schedule_expiry_cron() {
    if (!wp_next_scheduled('dtc_remove_expired_group_members')) {
        wp_schedule_event(time() + 60, 'hourly', 'dtc_remove_expired_group_members');
    }
}
add_action('wp', 'dtc_schedule_expiry_cron');

/**
 * Cron callback: Remove users from expired therapy groups
 */
function dtc_cron_remove_expired_group_members() {
    $now = current_time('timestamp');
    $expired_groups = get_posts([
        'post_type' => 'therapy_group',
        'post_status' => 'publish',
        'numberposts' => -1,
        'meta_query' => [
            [
                'key' => 'session_expiry_date',
                'value' => $now,
                'compare' => '<',
                'type' => 'NUMERIC',
            ]
        ]
    ]);

    foreach ($expired_groups as $group) {
        $group_id = $group->ID;
        $role_id = get_post_meta($group_id, '_dtc_discord_role_id', true);
        if (empty($role_id)) continue;

        // Find all users assigned to this group (by either meta key)
        $users = get_users([
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => 'assigned_group',
                    'value' => $group_id,
                    'compare' => '=',
                ],
                [
                    'key' => 'therapy_group_id',
                    'value' => $group_id,
                    'compare' => '=',
                ],
            ]
        ]);

        foreach ($users as $user) {
            $discord_user_id = get_user_meta($user->ID, '_dtc_discord_user_id', true);
            if (empty($discord_user_id)) continue;

            // Remove role from user
            $result = dtc_remove_role($discord_user_id, $role_id);
            if ($result['success']) {
                error_log("DTC CRON: Removed role $role_id from user $discord_user_id (user ID: {$user->ID}) for expired group $group_id");
            } else {
                error_log("DTC CRON: Failed to remove role $role_id from user $discord_user_id (user ID: {$user->ID}) for expired group $group_id: " . $result['message']);
            }

            // Remove group assignment meta
            delete_user_meta($user->ID, 'assigned_group');
            delete_user_meta($user->ID, 'therapy_group_id');
        }
    }
}
add_action('dtc_remove_expired_group_members', 'dtc_cron_remove_expired_group_members');
