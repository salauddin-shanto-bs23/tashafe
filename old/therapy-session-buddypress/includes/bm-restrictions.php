<?php
/**
 * Better Messages Restrictions
 * 
 * Restricts Better Messages functionality so that:
 * - Users can ONLY see their therapy group chat
 * - Users CANNOT create new conversations
 * - Users CANNOT add participants to groups
 * - Only admins can manage conversations and participants
 * 
 * @package TherapySessionBuddyPress
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ============================================
 * RESTRICTION 1: Hide "New Message" / "New Conversation" button for non-admins
 * ============================================
 */
add_filter('bp_better_messages_mini_chat_enabled', 'tsbp_disable_mini_chat_for_users', 10, 1);
add_filter('better_messages_can_start_conversation', 'tsbp_restrict_new_conversation', 10, 2);

function tsbp_disable_mini_chat_for_users($enabled) {
    if (current_user_can('manage_options')) {
        return $enabled;
    }
    // Disable mini chat (floating button) for non-admins
    return false;
}

function tsbp_restrict_new_conversation($can_start, $user_id) {
    if (current_user_can('manage_options')) {
        return true;
    }
    // Non-admins cannot start new conversations
    return false;
}

/**
 * ============================================
 * RESTRICTION 2: Filter BP Messages capability to prevent new conversations
 * ============================================
 */
add_filter('bp_user_can', 'tsbp_filter_bp_messaging_caps', 10, 5);

function tsbp_filter_bp_messaging_caps($retval, $user_id, $capability, $site_id, $args) {
    
    // Allow admins everything
    if (user_can($user_id, 'manage_options')) {
        return $retval;
    }
    
    // Block these capabilities for non-admins
    $blocked_caps = [
        'bp_moderate',
        'bp_docs_manage',
    ];
    
    if (in_array($capability, $blocked_caps)) {
        return false;
    }
    
    return $retval;
}

/**
 * ============================================
 * RESTRICTION 3: Prevent non-admins from sending messages to anyone
 * except in their assigned therapy group
 * ============================================
 */
add_filter('messages_message_before_send', 'tsbp_restrict_message_sending', 10, 1);
add_filter('bp_messages_can_user_send_to_recipient', 'tsbp_restrict_recipient_selection', 10, 3);

function tsbp_restrict_message_sending($args) {
    
    // Allow admins
    if (current_user_can('manage_options')) {
        return $args;
    }
    
    $user_id = get_current_user_id();
    $thread_id = $args['thread_id'] ?? 0;
    
    // If this is a new thread (no thread_id), block non-admins
    if (!$thread_id) {
        // Check if this is a reply to existing thread via subject match
        // If not, it's a new conversation - block it
        error_log("[TSBP] User {$user_id} tried to start new conversation - BLOCKED");
        return new WP_Error('restricted', __('You can only send messages in your therapy group chat.', 'therapy-session-bp'));
    }
    
    // Check if thread is a therapy group thread
    $allowed_threads = tsbp_get_user_allowed_threads($user_id);
    
    if (!in_array($thread_id, $allowed_threads)) {
        error_log("[TSBP] User {$user_id} tried to message in thread {$thread_id} - NOT ALLOWED");
        return new WP_Error('restricted', __('You can only send messages in your therapy group chat.', 'therapy-session-bp'));
    }
    
    return $args;
}

function tsbp_restrict_recipient_selection($can_send, $user_id, $recipient_id) {
    
    // Allow admins
    if (current_user_can('manage_options')) {
        return true;
    }
    
    // Non-admins cannot start 1v1 conversations
    return false;
}

/**
 * ============================================
 * RESTRICTION 4: Filter threads visible to user - only show therapy group threads
 * ============================================
 */
add_filter('bp_messages_thread_query', 'tsbp_filter_visible_threads', 10, 1);
add_filter('better_messages_threads_results', 'tsbp_filter_bm_threads_results', 10, 2);

function tsbp_filter_visible_threads($args) {
    
    // Allow admins to see all
    if (current_user_can('manage_options')) {
        return $args;
    }
    
    // For non-admins, we'll filter in the results hook
    return $args;
}

function tsbp_filter_bm_threads_results($threads, $user_id) {
    
    // Allow admins to see all
    if (current_user_can('manage_options')) {
        return $threads;
    }
    
    // Get allowed threads for this user
    $allowed_threads = tsbp_get_user_allowed_threads($user_id);
    
    if (empty($allowed_threads)) {
        // No therapy group assigned - show empty
        return [];
    }
    
    // Filter to only show allowed threads
    $filtered = [];
    foreach ($threads as $thread) {
        $thread_id = is_object($thread) ? $thread->thread_id : $thread['thread_id'];
        if (in_array($thread_id, $allowed_threads)) {
            $filtered[] = $thread;
        }
    }
    
    return $filtered;
}

/**
 * Get thread IDs that a user is allowed to access
 */
function tsbp_get_user_allowed_threads($user_id) {
    
    $allowed = [];
    
    // Get user's assigned therapy group
    $therapy_group_id = get_user_meta($user_id, 'assigned_group', true);
    
    if ($therapy_group_id) {
        // Get the BM thread for this therapy group
        $thread_id = tsbp_get_bm_thread_id($therapy_group_id);
        if ($thread_id) {
            $allowed[] = intval($thread_id);
        }
        
        // Also get BP group thread if different
        $bp_group_id = tsbp_get_bp_group_id($therapy_group_id);
        if ($bp_group_id) {
            $bp_thread = tsbp_get_bm_group_thread_id($bp_group_id);
            if ($bp_thread && !in_array($bp_thread, $allowed)) {
                $allowed[] = intval($bp_thread);
            }
        }
    }
    
    return $allowed;
}

/**
 * ============================================
 * RESTRICTION 5: Prevent non-admins from adding members to threads
 * ============================================
 */
add_filter('better_messages_can_add_recipients', 'tsbp_restrict_adding_recipients', 10, 2);
add_filter('bp_messages_can_add_recipient_to_thread', 'tsbp_restrict_bp_adding_recipients', 10, 3);

function tsbp_restrict_adding_recipients($can_add, $thread_id) {
    
    // Only admins can add recipients
    if (current_user_can('manage_options')) {
        return true;
    }
    
    return false;
}

function tsbp_restrict_bp_adding_recipients($can_add, $user_id, $thread_id) {
    
    // Only admins can add recipients
    if (user_can($user_id, 'manage_options')) {
        return true;
    }
    
    return false;
}

/**
 * ============================================
 * RESTRICTION 6: Hide UI elements via JavaScript/CSS for non-admins
 * ============================================
 */
add_action('wp_head', 'tsbp_add_bm_restriction_styles');
add_action('wp_footer', 'tsbp_add_bm_restriction_scripts');

function tsbp_add_bm_restriction_styles() {
    
    if (current_user_can('manage_options')) {
        return;
    }
    
    ?>
    <style id="tsbp-bm-restrictions">
        /* Hide New Message / New Conversation buttons */
        .bp-better-messages .new-message,
        .bp-better-messages .new-conversation,
        .bp-better-messages [data-action="new-message"],
        .bp-better-messages [data-action="new-conversation"],
        .bp-better-messages .bm-new-conversation,
        .better-messages .new-message,
        .better-messages .new-conversation,
        .better-messages .bm-new-thread,
        .bpbm-new-message,
        .bpbm-new-conversation,
        a[href*="compose"],
        .compose-message,
        #compose-message {
            display: none !important;
        }
        
        /* Hide Add Members / Add Recipients buttons */
        .bp-better-messages .add-member,
        .bp-better-messages .add-recipient,
        .bp-better-messages .bm-add-member,
        .bp-better-messages [data-action="add-recipient"],
        .better-messages .add-member,
        .better-messages .add-recipient,
        .bpbm-add-recipient,
        .group-add-member,
        .thread-add-member {
            display: none !important;
        }
        
        /* Hide member search/selection UI */
        .bp-better-messages .member-search,
        .bp-better-messages .recipient-search,
        .better-messages .member-search,
        .better-messages .recipient-search {
            display: none !important;
        }
        
        /* Hide create group button */
        .bp-better-messages .create-group,
        .bp-better-messages .new-group,
        .better-messages .create-group,
        .better-messages .new-group,
        .bpbm-create-group {
            display: none !important;
        }
        
        /* Hide conversation management options */
        .bp-better-messages .thread-settings,
        .bp-better-messages .conversation-settings,
        .better-messages .thread-settings,
        .better-messages .conversation-settings {
            display: none !important;
        }
        
        /* Hide delete/leave conversation */
        .bp-better-messages .delete-thread,
        .bp-better-messages .leave-thread,
        .better-messages .delete-thread,
        .better-messages .leave-thread {
            display: none !important;
        }
    </style>
    <?php
}

function tsbp_add_bm_restriction_scripts() {
    
    if (current_user_can('manage_options')) {
        return;
    }
    
    ?>
    <script id="tsbp-bm-restrictions-js">
    (function() {
        'use strict';
        
        // Function to hide restricted elements
        function hideRestrictedElements() {
            // Hide new message buttons
            var newMsgBtns = document.querySelectorAll(
                '.new-message, .new-conversation, .bm-new-conversation, ' +
                '.bpbm-new-message, .compose-message, [data-action="new-message"], ' +
                '[data-action="new-conversation"], a[href*="compose"]'
            );
            newMsgBtns.forEach(function(btn) {
                btn.style.display = 'none';
            });
            
            // Hide add member buttons
            var addMemberBtns = document.querySelectorAll(
                '.add-member, .add-recipient, .bm-add-member, ' +
                '.bpbm-add-recipient, [data-action="add-recipient"]'
            );
            addMemberBtns.forEach(function(btn) {
                btn.style.display = 'none';
            });
            
            // Hide create group buttons
            var createGroupBtns = document.querySelectorAll(
                '.create-group, .new-group, .bpbm-create-group'
            );
            createGroupBtns.forEach(function(btn) {
                btn.style.display = 'none';
            });
        }
        
        // Run on load
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', hideRestrictedElements);
        } else {
            hideRestrictedElements();
        }
        
        // Watch for dynamic content (Better Messages loads content via AJAX)
        var observer = new MutationObserver(function(mutations) {
            hideRestrictedElements();
        });
        
        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
        
        // Also run periodically to catch any delayed elements
        setInterval(hideRestrictedElements, 1000);
        
    })();
    </script>
    <?php
}

/**
 * ============================================
 * RESTRICTION 7: Better Messages specific settings override
 * ============================================
 */
add_filter('better_messages_settings', 'tsbp_override_bm_settings', 999);
add_filter('bp_better_messages_settings', 'tsbp_override_bm_settings', 999);

function tsbp_override_bm_settings($settings) {
    
    // These settings apply to all users (admin will bypass via capability checks)
    if (!current_user_can('manage_options')) {
        // Disable features for non-admins
        if (is_array($settings)) {
            $settings['disableNewConversations'] = true;
            $settings['disableNewMessages'] = true;
            $settings['disableGroupCreation'] = true;
            $settings['disableAddRecipients'] = true;
            $settings['miniChatsEnable'] = false;
            $settings['allowMuteThreads'] = false;
            $settings['allowDeleteThreads'] = false;
            $settings['allowLeaveThreads'] = false;
        }
    }
    
    return $settings;
}

/**
 * ============================================
 * RESTRICTION 8: AJAX handlers - block non-admin actions
 * ============================================
 */
add_action('wp_ajax_bp_messages_new_thread', 'tsbp_block_ajax_new_thread', 1);
add_action('wp_ajax_bm_new_thread', 'tsbp_block_ajax_new_thread', 1);
add_action('wp_ajax_better_messages_new_conversation', 'tsbp_block_ajax_new_thread', 1);

function tsbp_block_ajax_new_thread() {
    
    if (current_user_can('manage_options')) {
        return; // Allow admins
    }
    
    // Block non-admins from creating new threads
    wp_send_json_error([
        'message' => __('You can only chat in your assigned therapy group.', 'therapy-session-bp')
    ]);
    exit;
}

add_action('wp_ajax_bp_messages_add_recipient', 'tsbp_block_ajax_add_recipient', 1);
add_action('wp_ajax_bm_add_recipient', 'tsbp_block_ajax_add_recipient', 1);
add_action('wp_ajax_better_messages_add_recipient', 'tsbp_block_ajax_add_recipient', 1);

function tsbp_block_ajax_add_recipient() {
    
    if (current_user_can('manage_options')) {
        return; // Allow admins
    }
    
    // Block non-admins from adding recipients
    wp_send_json_error([
        'message' => __('Only administrators can add members to therapy groups.', 'therapy-session-bp')
    ]);
    exit;
}

/**
 * ============================================
 * RESTRICTION 9: Shortcode output - show only therapy group chat
 * ============================================
 */
add_filter('do_shortcode_tag', 'tsbp_filter_bm_shortcode_output', 10, 4);

function tsbp_filter_bm_shortcode_output($output, $tag, $attr, $m) {
    
    // Only filter better_messages shortcode
    if ($tag !== 'better_messages') {
        return $output;
    }
    
    // Allow admins full access
    if (current_user_can('manage_options')) {
        return $output;
    }
    
    // For non-admins, we've already filtered the visible threads
    // Just ensure the output doesn't have new message buttons
    
    return $output;
}

/**
 * ============================================
 * Hook: Display notice when user tries restricted action
 * ============================================
 */
add_action('bp_before_messages_compose_content', 'tsbp_show_compose_restriction_notice');

function tsbp_show_compose_restriction_notice() {
    
    if (current_user_can('manage_options')) {
        return;
    }
    
    ?>
    <div class="tsbp-restriction-notice" style="background:#fff3cd; border:1px solid #ffc107; padding:15px; margin:15px 0; border-radius:4px;">
        <p style="margin:0;">
            <strong><?php _e('Notice:', 'therapy-session-bp'); ?></strong>
            <?php _e('You can only participate in your assigned therapy group chat. Creating new conversations is not available.', 'therapy-session-bp'); ?>
        </p>
    </div>
    <?php
}
