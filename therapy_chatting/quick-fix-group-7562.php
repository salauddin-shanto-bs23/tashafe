<?php
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
