<?php
/**
 * Debugging Shortcode for Therapy Group Fields
 * 
 * Usage: [debug_therapy_group id="123"]
 * Or just: [debug_therapy_group] (will show all therapy groups)
 */

add_shortcode('debug_therapy_group', 'debug_therapy_group_fields');

function debug_therapy_group_fields($atts) {
    $atts = shortcode_atts([
        'id' => 0
    ], $atts);
    
    $therapy_group_id = intval($atts['id']);
    
    ob_start();
    
    echo '<div style="background: #f5f5f5; padding: 20px; margin: 20px 0; border: 2px solid #333; font-family: monospace;">';
    echo '<h2 style="margin-top: 0;">🔍 Therapy Group Debug Info</h2>';
    
    if ($therapy_group_id) {
        // Debug specific group
        debug_single_therapy_group($therapy_group_id);
    } else {
        // Debug all therapy groups
        $groups = get_posts([
            'post_type' => 'therapy_group',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'date',
            'order' => 'DESC'
        ]);
        
        if (empty($groups)) {
            echo '<p style="color: red;">❌ No therapy groups found.</p>';
        } else {
            echo '<p><strong>Found ' . count($groups) . ' therapy group(s)</strong></p>';
            echo '<hr style="margin: 20px 0;">';
            
            foreach ($groups as $group) {
                debug_single_therapy_group($group->ID);
                echo '<hr style="margin: 20px 0; border-top: 3px solid #666;">';
            }
        }
    }
    
    echo '</div>';
    
    return ob_get_clean();
}

function debug_single_therapy_group($therapy_group_id) {
    $post = get_post($therapy_group_id);
    
    if (!$post || $post->post_type !== 'therapy_group') {
        echo '<p style="color: red;">❌ Invalid therapy group ID: ' . $therapy_group_id . '</p>';
        return;
    }
    
    echo '<h3 style="color: #0066cc;">Therapy Group ID: ' . $therapy_group_id . '</h3>';
    echo '<p><strong>Post Title:</strong> ' . esc_html($post->post_title) . '</p>';
    echo '<p><strong>Post Status:</strong> ' . esc_html($post->post_status) . '</p>';
    echo '<p><strong>Created:</strong> ' . esc_html($post->post_date) . '</p>';
    
    echo '<h4>📋 ACF Fields (using get_field):</h4>';
    echo '<table style="width: 100%; border-collapse: collapse; background: white;">';
    echo '<tr style="background: #333; color: white;">';
    echo '<th style="padding: 10px; text-align: left; border: 1px solid #ddd;">Field Name</th>';
    echo '<th style="padding: 10px; text-align: left; border: 1px solid #ddd;">Value (get_field)</th>';
    echo '<th style="padding: 10px; text-align: left; border: 1px solid #ddd;">Value (get_post_meta)</th>';
    echo '</tr>';
    
    $acf_fields = [
        'issue_type',
        'gender',
        'group_status',
        'max_members',
        'start_date',
        'end_date',
        'group_number',
        'current_user',
        'session_start_date',
        'session_expiry_date'
    ];
    
    foreach ($acf_fields as $field_name) {
        echo '<tr>';
        echo '<td style="padding: 8px; border: 1px solid #ddd;"><strong>' . esc_html($field_name) . '</strong></td>';
        
        // Get value using ACF get_field
        $acf_value = function_exists('get_field') ? get_field($field_name, $therapy_group_id) : 'ACF not active';
        echo '<td style="padding: 8px; border: 1px solid #ddd;">';
        if (is_array($acf_value)) {
            echo '<pre>' . print_r($acf_value, true) . '</pre>';
        } elseif (empty($acf_value) && $acf_value !== '0' && $acf_value !== 0) {
            echo '<span style="color: red;">EMPTY</span>';
        } else {
            echo '<span style="color: green;">' . esc_html($acf_value) . '</span>';
        }
        echo '</td>';
        
        // Get value using get_post_meta
        $meta_value = get_post_meta($therapy_group_id, $field_name, true);
        echo '<td style="padding: 8px; border: 1px solid #ddd;">';
        if (is_array($meta_value)) {
            echo '<pre>' . print_r($meta_value, true) . '</pre>';
        } elseif (empty($meta_value) && $meta_value !== '0' && $meta_value !== 0) {
            echo '<span style="color: red;">EMPTY</span>';
        } else {
            echo '<span style="color: green;">' . esc_html($meta_value) . '</span>';
        }
        echo '</td>';
        
        echo '</tr>';
    }
    
    echo '</table>';
    
    // Special section for date fields with multiple formats
    echo '<h4>📅 Date Fields - Multiple Format Tests:</h4>';
    echo '<table style="width: 100%; border-collapse: collapse; background: white;">';
    echo '<tr style="background: #333; color: white;">';
    echo '<th style="padding: 10px; text-align: left; border: 1px solid #ddd;">Field</th>';
    echo '<th style="padding: 10px; text-align: left; border: 1px solid #ddd;">Raw Value</th>';
    echo '<th style="padding: 10px; text-align: left; border: 1px solid #ddd;">Y-m-d</th>';
    echo '<th style="padding: 10px; text-align: left; border: 1px solid #ddd;">d/m/Y</th>';
    echo '<th style="padding: 10px; text-align: left; border: 1px solid #ddd;">Timestamp</th>';
    echo '<th style="padding: 10px; text-align: left; border: 1px solid #ddd;">DateTime Parse</th>';
    echo '</tr>';
    
    $date_fields = ['start_date', 'end_date', 'session_start_date', 'session_expiry_date'];
    
    foreach ($date_fields as $date_field) {
        echo '<tr>';
        echo '<td style="padding: 8px; border: 1px solid #ddd;"><strong>' . esc_html($date_field) . '</strong></td>';
        
        // Raw value
        $raw_value = function_exists('get_field') ? get_field($date_field, $therapy_group_id) : get_post_meta($therapy_group_id, $date_field, true);
        echo '<td style="padding: 8px; border: 1px solid #ddd;">';
        echo empty($raw_value) ? '<span style="color: red;">EMPTY</span>' : esc_html($raw_value);
        echo ' <em>(' . gettype($raw_value) . ')</em>';
        echo '</td>';
        
        // Try Y-m-d format
        echo '<td style="padding: 8px; border: 1px solid #ddd;">';
        if ($raw_value) {
            $date_obj = DateTime::createFromFormat('Y-m-d', $raw_value);
            echo $date_obj ? '✅ ' . $date_obj->format('Y-m-d') : '❌ Failed';
        } else {
            echo '-';
        }
        echo '</td>';
        
        // Try d/m/Y format
        echo '<td style="padding: 8px; border: 1px solid #ddd;">';
        if ($raw_value) {
            $date_obj = DateTime::createFromFormat('d/m/Y', $raw_value);
            echo $date_obj ? '✅ ' . $date_obj->format('Y-m-d') : '❌ Failed';
        } else {
            echo '-';
        }
        echo '</td>';
        
        // Try as timestamp
        echo '<td style="padding: 8px; border: 1px solid #ddd;">';
        if ($raw_value && is_numeric($raw_value)) {
            echo '✅ ' . date('Y-m-d', $raw_value);
        } else {
            echo '❌ Not numeric';
        }
        echo '</td>';
        
        // Try strtotime
        echo '<td style="padding: 8px; border: 1px solid #ddd;">';
        if ($raw_value) {
            $timestamp = strtotime($raw_value);
            echo $timestamp ? '✅ ' . date('Y-m-d', $timestamp) : '❌ Failed';
        } else {
            echo '-';
        }
        echo '</td>';
        
        echo '</tr>';
    }
    
    echo '</table>';
    
    // BuddyPress Group Info
    echo '<h4>💬 BuddyPress Group Info:</h4>';
    $bp_group_id = get_post_meta($therapy_group_id, '_tbc_bp_group_id', true);
    
    if ($bp_group_id) {
        echo '<p><strong>BP Group ID:</strong> <span style="color: green;">' . esc_html($bp_group_id) . '</span></p>';
        
        if (function_exists('groups_get_group')) {
            $bp_group = groups_get_group($bp_group_id);
            if ($bp_group && !empty($bp_group->id)) {
                echo '<p><strong>BP Group Name:</strong> ' . esc_html($bp_group->name) . '</p>';
                echo '<p><strong>BP Group Description:</strong> ' . esc_html($bp_group->description) . '</p>';
                echo '<p><strong>BP Group Status:</strong> ' . esc_html($bp_group->status) . '</p>';
                
                if (function_exists('groups_get_groupmeta')) {
                    $bp_expiry = groups_get_groupmeta($bp_group_id, '_tbc_expiry_date', true);
                    $bp_status = groups_get_groupmeta($bp_group_id, '_tbc_status', true);
                    echo '<p><strong>BP Stored Expiry:</strong> ' . ($bp_expiry ? esc_html($bp_expiry) : '<span style="color: red;">EMPTY</span>') . '</p>';
                    echo '<p><strong>BP Stored Status:</strong> ' . ($bp_status ? esc_html($bp_status) : '<span style="color: red;">EMPTY</span>') . '</p>';
                }
            } else {
                echo '<p style="color: orange;">⚠️ BP Group exists in meta but not found in BuddyPress</p>';
            }
        } else {
            echo '<p style="color: orange;">⚠️ BuddyPress not active</p>';
        }
    } else {
        echo '<p style="color: red;">❌ No BuddyPress group linked (_tbc_bp_group_id is empty)</p>';
    }
    
    // All post meta (raw dump)
    echo '<h4>🗄️ All Post Meta (Raw):</h4>';
    $all_meta = get_post_meta($therapy_group_id);
    echo '<pre style="background: white; padding: 10px; border: 1px solid #ddd; overflow-x: auto; max-height: 400px;">';
    print_r($all_meta);
    echo '</pre>';
}


/**
 * QUICK FIX for Existing Group ID 7562
 * 
 * Run this ONCE to fix your existing group
 * Add to WPCode as a temporary snippet, run once, then delete
 */

// Manual fix for therapy group ID 7562
$therapy_group_id = 7562;
$bp_group_id = 48;

// Get session expiry date
$session_expiry = get_field('session_expiry_date', $therapy_group_id);
if (empty($session_expiry)) {
    $session_expiry = get_post_meta($therapy_group_id, 'session_expiry_date', true);
}

echo '<div style="background: #f8f9fa; padding: 20px; margin: 20px; border: 2px solid #333;">';
echo '<h2>Quick Fix for Group 7562</h2>';

if (!empty($session_expiry)) {
    echo '<p><strong>Found session_expiry_date:</strong> ' . esc_html($session_expiry) . '</p>';
    
    // Calculate expiry + 1 day
    $date_obj = new DateTime($session_expiry);
    $date_obj->modify('+1 day');
    $expiry_notice = $date_obj->format('Y-m-d');
    
    echo '<p><strong>Expiry notice date:</strong> ' . esc_html($expiry_notice) . '</p>';
    
    $new_description = 'This group will expire on ' . $expiry_notice . '.';
    echo '<p><strong>New description:</strong> ' . esc_html($new_description) . '</p>';
    
    // Update BP group description
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
        
        echo '<div style="background: #d4edda; padding: 15px; margin-top: 15px; border-radius: 5px;">';
        echo '<p style="color: #155724; font-weight: bold; margin: 0;">✅ SUCCESS! BP Group description updated.</p>';
        echo '</div>';
        
        echo '<p><strong>Verify:</strong> Go to BuddyPress > Groups > Group #48 and check description</p>';
    } else {
        echo '<div style="background: #f8d7da; padding: 15px; margin-top: 15px; border-radius: 5px;">';
        echo '<p style="color: #721c24; font-weight: bold; margin: 0;">❌ FAILED to update description</p>';
        echo '<p style="margin: 5px 0 0 0;">Error: ' . $wpdb->last_error . '</p>';
        echo '</div>';
    }
    
    // Show what was in BP group before
    if (function_exists('groups_get_group')) {
        $bp_group = groups_get_group($bp_group_id);
        echo '<hr>';
        echo '<h3>Current BP Group Info</h3>';
        echo '<p><strong>Name:</strong> ' . esc_html($bp_group->name) . '</p>';
        echo '<p><strong>Description:</strong> ' . esc_html($bp_group->description) . '</p>';
        echo '<p><strong>Status:</strong> ' . esc_html($bp_group->status) . '</p>';
    }
    
} else {
    echo '<p style="color: red;">❌ Could not find session_expiry_date for therapy group ' . $therapy_group_id . '</p>';
}

echo '</div>';
