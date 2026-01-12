<?php

add_filter('wp_mail_content_type', function () {
  return 'text/html';
});

// AJAX - Edit Therapy Group
add_action('wp_ajax_edit_therapy_group', 'handle_edit_therapy_group');
function handle_edit_therapy_group()
{
  if (!current_user_can('manage_options')) {
    wp_send_json_error('Unauthorized');
  }

  $group_id = intval($_POST['group_id'] ?? 0);
  $start_date = sanitize_text_field($_POST['start_date'] ?? '');
  $end_date = sanitize_text_field($_POST['end_date'] ?? '');
  $max_members = intval($_POST['max_members'] ?? 0);
  $session_start_date = sanitize_text_field($_POST['session_start_date'] ?? '');
  $session_expiry_date = sanitize_text_field($_POST['session_expiry_date'] ?? '');

  if (!$group_id) {
    wp_send_json_error('Invalid group ID.');
  }

  if (empty($start_date) || empty($end_date) || !$max_members || empty($session_start_date) || empty($session_expiry_date)) {
    wp_send_json_error('Please fill all fields.');
  }

  // Verify the group exists
  $post = get_post($group_id);
  if (!$post || $post->post_type !== 'therapy_group') {
    wp_send_json_error('Therapy group not found.');
  }

  // Update ACF fields
  update_field('start_date', $start_date, $group_id);
  update_field('end_date', $end_date, $group_id);
  update_field('max_members', $max_members, $group_id);
  update_field('session_start_date', $session_start_date, $group_id);
  update_field('session_expiry_date', $session_expiry_date, $group_id);

  // ✅ UPDATE BP GROUP DESCRIPTION AFTER FIELDS ARE SAVED
  if (function_exists('tbc_update_bp_group_description')) {
    $bp_group_id = get_post_meta($group_id, '_tbc_bp_group_id', true);
    if ($bp_group_id) {
      tbc_update_bp_group_description($group_id, $bp_group_id);
      error_log("[Admin Dashboard] Manually updated BP group description for therapy_group {$group_id}");
    }
  }

  wp_send_json_success('Group updated successfully!');
}

add_action('wp_ajax_create_sub_group', 'handle_create_sub_group');
function handle_create_sub_group()
{
  // Sanitize and fetch POST data
  $issue_type   = sanitize_text_field($_POST['issue_type']);
  $gender       = sanitize_text_field($_POST['gender']);
  $start_date   = sanitize_text_field($_POST['start_date']);
  $end_date     = sanitize_text_field($_POST['end_date']);
  $max_members  = intval($_POST['max_members']);
  $session_start_date  = sanitize_text_field($_POST['session_start_date']);
  $session_expiry_date = sanitize_text_field($_POST['session_expiry_date']);

  // Validate required fields
  if (empty($issue_type) || empty($gender) || empty($start_date) || empty($end_date) || !$max_members || empty($session_start_date) || empty($session_expiry_date)) {
    wp_send_json_error('Please fill all fields.');
  }

  // Get count of existing groups for this issue/gender to determine group number
  $args = [
    'post_type' => 'therapy_group',
    'post_status' => 'publish',
    'posts_per_page' => -1,
    'meta_query' => [
      [
        'key' => 'issue_type',
        'value' => $issue_type,
        'compare' => '='
      ],
      [
        'key' => 'gender',
        'value' => $gender,
        'compare' => '='
      ]
    ]
  ];

  $existing_groups = get_posts($args);

  // Create new post of type 'therapy_group'
  $new_post = array(
    'post_title'    => ucfirst($issue_type) . ' - ' . ucfirst($gender) . ' Group (' . $session_start_date . ' to ' . $session_expiry_date . ')',
    'post_type'     => 'therapy_group',
    'post_status'   => 'publish',
  );

  $post_id = wp_insert_post($new_post);

  if (is_wp_error($post_id)) {
    wp_send_json_error('Failed to create group.');
  }

  // Save ACF fields
  update_field('issue_type', $issue_type, $post_id);
  update_field('gender', $gender, $post_id);
  update_field('start_date', $start_date, $post_id);
  update_field('end_date', $end_date, $post_id);
  update_field('max_members', $max_members, $post_id);
  update_field('group_number', count($existing_groups) + 1, $post_id);
  update_field('session_start_date', $session_start_date, $post_id);
  update_field('session_expiry_date', $session_expiry_date, $post_id);
  update_field('group_status', 'active', $post_id);

  error_log("[Admin Dashboard] About to create BP group with session_expiry_date: '{$session_expiry_date}' (type: " . gettype($session_expiry_date) . ")");

  // ✅ CREATE BUDDYPRESS GROUP IMMEDIATELY
  $post = get_post($post_id);
  create_buddypress_group_for_therapy($post_id, $post, $session_expiry_date);
  error_log("[Admin Dashboard] Created BP group for therapy_group {$post_id} with session_expiry_date: {$session_expiry_date}");

  // Notify waiting list users
  notify_waiting_list_users($issue_type, $gender, $session_start_date, $session_expiry_date);

  wp_send_json_success('Group created successfully!');
}

function notify_waiting_list_users($issue, $gender, $session_start_date, $session_expiry_date)
{
  global $wpdb;

  $table_name = $wpdb->prefix . 'waiting_list';

  $users = $wpdb->get_results(
    $wpdb->prepare(
      "SELECT name, email FROM $table_name WHERE issue = %s AND gender = %s",
      $issue,
      $gender
    )
  );

  if (empty($users)) {
    return;
  }

  $subject = "New Therapy Group Available – Tashafe";

  foreach ($users as $user) {
    $session_period = date('F j, Y', strtotime($session_start_date)) . ' - ' . date('F j, Y', strtotime($session_expiry_date));

    $message = '
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <title>Tashafe Therapy Group</title>
        </head>
        <body style="margin:0; padding:0; background:#f6f6f6; font-family:Arial, sans-serif;">

        <table width="100%" cellpadding="0" cellspacing="0" style="background:#f6f6f6; padding:40px 0;">
            <tr>
                <td align="center">

                    <table width="600" cellpadding="0" cellspacing="0"
                        style="background:#ffffff; border-radius:10px; overflow:hidden;
                               box-shadow:0 4px 12px rgba(0,0,0,0.08);">

                        <!-- Header -->
                        <tr>
                            <td style="background:linear-gradient(135deg, #C3DDD2, #6059A6);
                                       padding:24px; text-align:center;
                                       color:#ffffff; font-size:24px; font-weight:bold;">
                                Tashafe Therapy Groups
                            </td>
                        </tr>

                        <!-- Body -->
                        <tr>
                            <td style="padding:30px; color:#333; font-size:16px; line-height:26px;">

                                <p>Hi ' . esc_html($user->name) . ',</p>

                                <p>
                                    A new <strong>' . ucfirst($gender) . '</strong> therapy subgroup
                                    for <strong>' . ucfirst($issue) . '</strong> has just been created.
                                </p>

                                <p>
                                    <strong>Session Period:</strong> ' . $session_period . '
                                </p>

                                <p>
                                    Since you were on our waiting list, you can now check the available
                                    group and proceed with registration.
                                </p>

                                <!-- Button -->
                                <table cellspacing="0" cellpadding="0" style="margin-top:20px;">
                                    <tr>
                                        <td align="center">
                                            <a href="https://tanafs.com.sa/therapy-groups"
                                               style="display:inline-block; padding:14px 28px;
                                                      background:linear-gradient(135deg, #C3DDD2, #6059A6);
                                                      color:#fff; text-decoration:none;
                                                      font-weight:600; border-radius:6px;
                                                      font-size:16px;">
                                                View Therapy Groups
                                            </a>
                                        </td>
                                    </tr>
                                </table>

                            </td>
                        </tr>

                        <!-- Footer -->
                        <tr>
                            <td style="background:#f0f0f0; padding:16px; text-align:center;
                                       font-size:12px; color:#666;">
                                © ' . date("Y") . ' Tashafe — All Rights Reserved.
                            </td>
                        </tr>

                    </table>

                </td>
            </tr>
        </table>

        </body>
        </html>
        ';

    wp_mail($user->email, $subject, $message);
  }
}

// 1. Add menu item in admin dashboard
add_action('admin_menu', 'therapy_group_dashboard_admin_menu');

function therapy_group_dashboard_admin_menu()
{
  add_menu_page(
    'Therapy Group Dashboard',
    'Therapy Group Dashboard',
    'manage_options',
    'therapy-group-dashboard',
    'render_therapy_group_dashboard',
    'dashicons-groups',
    6
  );
}

function register_therapy_group_cpt()
{
  register_post_type('therapy_group', [
    'labels' => [
      'name' => 'Therapy Groups',
      'singular_name' => 'Therapy Group',
      'add_new_item' => 'Add New Sub Group',
    ],
    'public' => true,
    'has_archive' => false,
    'show_in_menu' => true,
    'menu_icon' => 'dashicons-groups',
    'supports' => ['title'],
  ]);
}
add_action('init', 'register_therapy_group_cpt');

// AJAX - Delete Therapy Group
add_action('wp_ajax_delete_therapy_group', 'ajax_delete_therapy_group');
function ajax_delete_therapy_group()
{
  if (!current_user_can('manage_options')) {
    wp_send_json_error('Unauthorized');
  }

  global $wpdb;

  $group_id = intval($_POST['group_id'] ?? 0);

  if (!$group_id) {
    wp_send_json_error('Invalid group ID');
  }

  $schedules_table = $wpdb->prefix . 'therapy_group_schedules';
  $meetings_table = $wpdb->prefix . 'therapy_scheduled_meetings';

  // Get schedule IDs for this group
  $schedule_ids = $wpdb->get_col($wpdb->prepare(
    "SELECT id FROM {$schedules_table} WHERE group_id = %d",
    $group_id
  ));

  // Delete meetings associated with these schedules
  if (!empty($schedule_ids)) {
    $placeholders = implode(',', array_fill(0, count($schedule_ids), '%d'));
    $wpdb->query($wpdb->prepare(
      "DELETE FROM {$meetings_table} WHERE schedule_id IN ({$placeholders})",
      ...$schedule_ids
    ));
  }

  // Delete schedules
  $wpdb->delete($schedules_table, ['group_id' => $group_id], ['%d']);

  // Unassign users from this group
  $users = get_users([
    'meta_key' => 'assigned_group',
    'meta_value' => $group_id,
  ]);

  foreach ($users as $user) {
    delete_user_meta($user->ID, 'assigned_group');
  }

  // Delete the post
  $result = wp_delete_post($group_id, true);

  if ($result) {
    wp_send_json_success('Therapy group deleted successfully!');
  } else {
    wp_send_json_error('Failed to delete therapy group');
  }
}

// Helper function to get group availability status
function get_group_availability_status($group_id)
{
  $max_members = get_field('max_members', $group_id) ?: 0;
  $session_start = get_field('session_start_date', $group_id);
  $session_expiry = get_field('session_expiry_date', $group_id);

  // Count current members
  $current_members = count(get_users([
    'meta_key' => 'assigned_group',
    'meta_value' => $group_id,
  ]));

  // Check if group is full
  $is_full = $current_members >= $max_members;

  // Check if group is within active date range
  $today = date('Y-m-d');
  $is_active_period = false;
  if ($session_start && $session_expiry) {
    $is_active_period = ($today >= $session_start && $today <= $session_expiry);
  }

  // Check if group has upcoming sessions
  global $wpdb;
  $meetings_table = $wpdb->prefix . 'therapy_scheduled_meetings';
  $has_upcoming = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$meetings_table} WHERE group_id = %d AND meeting_date >= CURDATE()",
    $group_id
  ));

  return [
    'current_members' => $current_members,
    'max_members' => $max_members,
    'is_full' => $is_full,
    'is_active_period' => $is_active_period,
    'has_upcoming' => $has_upcoming > 0,
    'status_text' => $is_full ? 'Full' : 'Open',
    'status_class' => $is_full ? 'status-full' : 'status-open',
  ];
}

/**
 * Create BuddyPress group for therapy group
 * Moved from therapy-buddypress-automation.php to keep group creation logic with group management
 * 
 * @param int $therapy_group_id Therapy group post ID
 * @param WP_Post $post Post object
 * @param string $session_expiry_date_override Session expiry date (passed directly to avoid ACF timing issues)
 * @return int|false BP group ID on success, false on failure
 */
function create_buddypress_group_for_therapy($therapy_group_id, $post, $session_expiry_date_override = null) {
    
    // Only proceed if BuddyPress is active
    if (!function_exists('groups_create_group') || !function_exists('bp_is_active') || !bp_is_active('groups')) {
        error_log('[BP Create] BuddyPress Groups not active. Cannot create group.');
        return false;
    }
    
    error_log("[BP Create] ==== Creating BP group for therapy_group {$therapy_group_id} ====");
    
    // Check if BP group already exists
    $existing_bp_group_id = get_post_meta($therapy_group_id, '_tbc_bp_group_id', true);
    
    if ($existing_bp_group_id) {
        if (function_exists('groups_get_group')) {
            $existing_group = groups_get_group($existing_bp_group_id);
            if ($existing_group && !empty($existing_group->id)) {
                error_log("[BP Create] BP group already exists: {$existing_bp_group_id}");
                return $existing_bp_group_id;
            }
        }
    }
    
    // Get therapy group details
    $group_name = $post->post_title;
    
    // Get session expiry date - PRIORITY: Use passed value from form data
    $session_expiry = '';
    if (!empty($session_expiry_date_override)) {
        $session_expiry = $session_expiry_date_override;
        error_log("[BP Create] ✓ Using PASSED session_expiry_date: '{$session_expiry}' (type: " . gettype($session_expiry_date_override) . ")");
    } else if (function_exists('get_field')) {
        $session_expiry = get_field('session_expiry_date', $therapy_group_id);
        error_log("[BP Create] Got session_expiry_date from ACF: '{$session_expiry}'");
    }
    
    if (empty($session_expiry)) {
        $session_expiry = get_post_meta($therapy_group_id, 'session_expiry_date', true);
        error_log("[BP Create] Got session_expiry_date from post_meta: '{$session_expiry}'");
    }
    
    // Build group description with expiry date
    $description = 'Therapy group chat.';
    if (!empty($session_expiry)) {
        error_log("[BP Create] Processing expiry date: '{$session_expiry}' (type: " . gettype($session_expiry) . ", value: " . var_export($session_expiry, true) . ")");
        
        $expiry_date_obj = null;
        
        // If it's a numeric timestamp (ACF Number field stores Unix timestamp)
        if (is_numeric($session_expiry)) {
            error_log("[BP Create] Detected numeric timestamp: {$session_expiry}");
            try {
                $expiry_date_obj = new DateTime();
                $expiry_date_obj->setTimestamp((int)$session_expiry);
                error_log("[BP Create] ✓ Created DateTime from timestamp");
            } catch (Exception $e) {
                error_log("[BP Create] ✗ Failed to create DateTime from timestamp: " . $e->getMessage());
            }
        }
        
        // Try format 1: Y-m-d (2026-01-15) - from HTML date input
        if (!$expiry_date_obj) {
            $expiry_date_obj = DateTime::createFromFormat('Y-m-d', $session_expiry);
            if ($expiry_date_obj !== false) {
                error_log("[BP Create] ✓ Parsed date with Y-m-d format");
            }
        }
        
        // Try format 2: Ymd (20260115)
        if ($expiry_date_obj === false) {
            $expiry_date_obj = DateTime::createFromFormat('Ymd', $session_expiry);
            if ($expiry_date_obj !== false) {
                error_log("[BP Create] ✓ Parsed date with Ymd format");
            }
        }
        
        // Try format 3: strtotime as final fallback
        if ($expiry_date_obj === false && strtotime($session_expiry)) {
            try {
                $expiry_date_obj = new DateTime($session_expiry);
                error_log("[BP Create] ✓ Parsed date with strtotime/DateTime");
            } catch (Exception $e) {
                error_log("[BP Create] ✗ DateTime parsing failed: " . $e->getMessage());
                $expiry_date_obj = null;
            }
        }
        
        if ($expiry_date_obj && $expiry_date_obj instanceof DateTime) {
            $expiry_date_obj->modify('+1 day');
            $expiry_notice_date = $expiry_date_obj->format('Y-m-d');
            $description = 'This group will expire on ' . $expiry_notice_date . '.';
            error_log("[BP Create] ✓✓ SUCCESS - Description set to: '{$description}'");
        } else {
            error_log("[BP Create] ✗✗ FAILED to parse expiry date '{$session_expiry}' - using default description");
        }
    } else {
        error_log("[BP Create] ⚠ No session_expiry date available - using default description");
    }
    
    // Get creator
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
        'status'       => 'private',
        'enable_forum' => false,
        'date_created' => function_exists('bp_core_current_time') ? bp_core_current_time() : current_time('mysql'),
    ];
    
    $bp_group_id = groups_create_group($bp_group_args);
    
    if (!$bp_group_id || is_wp_error($bp_group_id)) {
        error_log("[BP Create] ✗ FAILED to create BP group for therapy_group {$therapy_group_id}");
        return false;
    }
    
    error_log("[BP Create] ✓✓ Successfully created BP group {$bp_group_id}");
    
    // Store bidirectional relationship
    update_post_meta($therapy_group_id, '_tbc_bp_group_id', $bp_group_id);
    groups_update_groupmeta($bp_group_id, '_tbc_therapy_group_id', $therapy_group_id);
    
    // Store expiry date in BP group meta
    if (!empty($session_expiry)) {
        groups_update_groupmeta($bp_group_id, '_tbc_expiry_date', sanitize_text_field($session_expiry));
    }
    
    groups_update_groupmeta($bp_group_id, '_tbc_status', 'active');
    
    // Promote creator to admin
    if (function_exists('groups_promote_member')) {
        groups_promote_member($creator_id, $bp_group_id, 'admin');
    }
    
    error_log("[BP Create] ==== BP group creation complete ====");
    return $bp_group_id;
}

/**
 * Get all necessary information about a therapy group for user enrollment
 * 
 * @param int $therapy_group_id The therapy_group post ID
 * @return array|false Array with group info or false on failure
 */
if (!function_exists('tbc_get_therapy_group_info')) {
    function tbc_get_therapy_group_info($therapy_group_id) {
  $post = get_post($therapy_group_id);
  
  if (!$post || $post->post_type !== 'therapy_group') {
    error_log("[TBC] Invalid therapy group ID: {$therapy_group_id}");
    return false;
  }
  
  // Get BuddyPress group ID
  $bp_group_id = get_post_meta($therapy_group_id, '_tbc_bp_group_id', true);
  
  // Get ACF fields
  $info = [
    'post_id' => $therapy_group_id,
    'post_title' => $post->post_title,
    'post_status' => $post->post_status,
    'bp_group_id' => $bp_group_id ? intval($bp_group_id) : 0,
    'issue_type' => get_field('issue_type', $therapy_group_id) ?: '',
    'gender' => get_field('gender', $therapy_group_id) ?: '',
    'start_date' => get_field('start_date', $therapy_group_id) ?: '',
    'end_date' => get_field('end_date', $therapy_group_id) ?: '',
    'max_members' => get_field('max_members', $therapy_group_id) ?: 0,
    'group_number' => get_field('group_number', $therapy_group_id) ?: 0,
    'session_start_date' => get_field('session_start_date', $therapy_group_id) ?: '',
    'session_expiry_date' => get_field('session_expiry_date', $therapy_group_id) ?: '',
    'group_status' => get_field('group_status', $therapy_group_id) ?: 'active',
  ];
  
  // Fallback to post_meta if ACF returns empty
  if (empty($info['session_expiry_date'])) {
    $info['session_expiry_date'] = get_post_meta($therapy_group_id, 'session_expiry_date', true);
  }
  
  // Verify BuddyPress group exists
  if ($bp_group_id && function_exists('groups_get_group')) {
    $bp_group = groups_get_group($bp_group_id);
    if ($bp_group && !empty($bp_group->id)) {
      $info['bp_group_exists'] = true;
      $info['bp_group_name'] = $bp_group->name;
      $info['bp_group_slug'] = $bp_group->slug;
      $info['bp_group_status'] = $bp_group->status;
    } else {
      $info['bp_group_exists'] = false;
    }
  } else {
    $info['bp_group_exists'] = false;
  }
  
  error_log("[TBC] Collected therapy group info for ID {$therapy_group_id}: " . json_encode($info));
  
  return $info;
    }
}

// UPDATED render_subgroups_by_issue_and_gender function
function render_subgroups_by_issue_and_gender($issue, $gender)
{
  $args = [
    'post_type' => 'therapy_group',
    'posts_per_page' => -1,
    'post_status' => 'publish',
    'meta_query' => [
      [
        'key' => 'issue_type',
        'value' => $issue,
        'compare' => '='
      ],
      [
        'key' => 'gender',
        'value' => $gender,
        'compare' => '='
      ]
    ],
    'orderby' => 'meta_value',
    'meta_key' => 'session_start_date',
    'order' => 'DESC',
  ];

  $groups = get_posts($args);

  if (!$groups) {
    echo '<p>No subgroups found.</p>';
    return;
  }

  $accordion_id = strtolower($issue) . ucfirst($gender) . 'Accordion';
  echo '<div class="accordion" id="' . esc_attr($accordion_id) . '">';

  foreach ($groups as $index => $group) {
    $post_id = $group->ID;
    $collapse_id = 'collapse' . $post_id;
    $group_number = get_field('group_number', $post_id) ?: ($index + 1);
    $max_members = get_field('max_members', $post_id) ?: 'N/A';
    $start_date = get_field('start_date', $post_id) ?: 'N/A';
    $end_date = get_field('end_date', $post_id) ?: 'N/A';
    $session_start_date = get_field('session_start_date', $post_id) ?: 'N/A';
    $session_expiry_date = get_field('session_expiry_date', $post_id) ?: 'N/A';

    // Get availability status
    $availability = get_group_availability_status($post_id);

    // Convert dates to YYYY-MM-DD format for HTML date inputs
    $start_date_input = ($start_date !== 'N/A' && strtotime($start_date)) ? date('Y-m-d', strtotime($start_date)) : '';
    $end_date_input = ($end_date !== 'N/A' && strtotime($end_date)) ? date('Y-m-d', strtotime($end_date)) : '';
    $session_start_input = ($session_start_date !== 'N/A' && strtotime($session_start_date)) ? date('Y-m-d', strtotime($session_start_date)) : '';
    $session_expiry_input = ($session_expiry_date !== 'N/A' && strtotime($session_expiry_date)) ? date('Y-m-d', strtotime($session_expiry_date)) : '';

    // Format display dates
    $session_period = 'Not Set';
    if ($session_start_date !== 'N/A' && $session_expiry_date !== 'N/A') {
      $session_period = date('M j, Y', strtotime($session_start_date)) . ' - ' . date('M j, Y', strtotime($session_expiry_date));
    }

    echo '<div class="accordion-item">';

    // Header
    echo '<h2 class="accordion-header d-flex align-items-center justify-content-between pe-1" id="heading' . esc_attr($post_id) . '">';
    echo '<div class="d-flex w-100 align-items-center justify-content-between">';

    // Collapsible button
    echo '<button class="accordion-button collapsed flex-grow-0" type="button" data-bs-toggle="collapse" data-bs-target="#' . esc_attr($collapse_id) . '" aria-expanded="false" aria-controls="' . esc_attr($collapse_id) . '">';
    echo '<div>';
    echo '<span class="fw-bold">Sub Group ' . esc_html($group_number) . '</span>';
    echo '<br><small class="text-muted">' . esc_html($session_period) . '</small>';
    echo '</div>';
    echo '</button>';

    // Status and action buttons
    echo '<div class="d-flex align-items-center ms-3">';

    // Capacity status
    echo '<span class="group-capacity-status ' . $availability['status_class'] . ' me-2">';
    echo '<i class="bi ' . ($availability['is_full'] ? 'bi-person-fill-slash' : 'bi-person-check-fill') . ' me-1"></i>';
    echo '<span class="status-text">' . $availability['current_members'] . '/' . $availability['max_members'] . ' (' . $availability['status_text'] . ')</span>';
    echo '</span>';

    echo <<<HTML
    <button class="btn btn-sm edit-group-btn ms-2" 
            data-group-id="{$post_id}"
            data-issue="{$issue}"
            data-gender="{$gender}"
            data-max-members="{$max_members}"
            data-start-date="{$start_date_input}"
            data-end-date="{$end_date_input}"
            data-session-start="{$session_start_input}"
            data-session-expiry="{$session_expiry_input}"
            data-bs-toggle="modal" 
            data-bs-target="#editSubGroupModal"
            title="Edit Group">
        <i class="bi bi-pencil"></i>
    </button>
    <button class="btn btn-sm delete-group-btn ms-2" 
            data-group-id="{$post_id}"
            data-issue="{$issue}"
            data-gender="{$gender}"
            title="Delete Group">
        <i class="bi bi-trash"></i>
    </button>
HTML;

    echo '</div>'; // close action buttons
    echo '</div>'; // close flex wrapper
    echo '</h2>'; // close header

    echo '<div id="' . esc_attr($collapse_id) . '" class="accordion-collapse collapse" aria-labelledby="heading' . esc_attr($post_id) . '" data-bs-parent="#' . esc_attr($accordion_id) . '">';
    echo '<div class="accordion-body">';
    echo '<div class="row mb-3">';
    echo '<div class="col-md-6">';
    echo '<p><strong>Registration Period:</strong><br>' . esc_html($start_date) . ' to ' . esc_html($end_date) . '</p>';
    echo '</div>';
    echo '<div class="col-md-6">';
    echo '<p><strong>Session Period:</strong><br>' . esc_html($session_start_date) . ' to ' . esc_html($session_expiry_date) . '</p>';
    echo '</div>';
    echo '</div>';

    // Get users assigned to this group
    $members = get_users([
      'meta_key' => 'assigned_group',
      'meta_value' => $post_id,
    ]);

    if ($members) {
      echo '<div class="table-responsive">';
      echo '<table class="table table-sm table-bordered">';
      echo '<thead class="table-light">';
      echo '<tr><th>#</th><th>Name</th><th>DOB</th><th>Email</th><th>NID/Passport</th><th>Country</th><th>Reg Date</th></tr>';
      echo '</thead><tbody>';

      $i = 1;
      foreach ($members as $member) {
        $dob = get_field('dob', 'user_' . $member->ID);
        $passport = get_field('passport_no', 'user_' . $member->ID);
        $country = get_field('country', 'user_' . $member->ID);
        $reg_date = date('Y-m-d', strtotime($member->user_registered));
        $nid = get_field('nid_no', 'user_' . $member->ID);
        $email = $member->user_email;
        $id_to_display = !empty($nid) ? $nid : $passport;

        echo '<tr>';
        echo '<td>' . $i++ . '</td>';
        echo '<td>' . esc_html($member->display_name) . '</td>';
        echo '<td>' . esc_html($dob) . '</td>';
        echo '<td>' . esc_html($email) . '</td>';
        echo '<td>' . esc_html($id_to_display) . '</td>';
        echo '<td>' . esc_html($country) . '</td>';
        echo '<td>' . esc_html($reg_date) . '</td>';
        echo '</tr>';
      }

      echo '</tbody></table></div>';
    } else {
      echo '<p><em>No members registered yet.</em></p>';
    }

    echo '</div></div></div>';
  }

  echo '</div>';
}

// UPDATED render_therapy_group_dashboard
function render_therapy_group_dashboard()
{
?>
  <div class="wrap">
    <h1 class="wp-heading-inline">Therapy Group Dashboard</h1>
    <hr class="wp-header-end">

    <!-- Load Bootstrap & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <style>
      body {
        color: #120D1F;
      }

      .card-header {
        background-color: #b4aad0;
        color: white;
        font-weight: 600;
      }

      .accordion-button {
        background-color: #cdc6e0;
        font-weight: 600;
        color: #120D1F;
        padding: 12px 16px;
      }

      .accordion-button:not(.collapsed) {
        background-color: #b4aad0;
        color: white;
      }

      .accordion-button small {
        font-weight: normal;
        font-size: 0.85rem;
      }

      .group-capacity-status {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 0.875rem;
        font-weight: 500;
        color: white;
      }

      .status-open {
        background-color: #28a745;
      }

      .status-full {
        background-color: #dc3545;
      }

      .table th,
      .table td {
        font-size: 0.85rem;
      }

      .btn-create {
        background-color: #635ba3;
        border: none;
        color: white;
      }

      .btn-create:hover {
        background-color: #b4aad0;
      }

      .nav-tabs .nav-link.active {
        background-color: #bcd7dc;
        color: white;
      }

      .nav-tabs .nav-link {
        color: #635ba3;
      }

      h4 {
        margin-bottom: 1rem;
        color: #120D1F;
      }

      /* Delete button styles */
      .delete-group-btn {
        background-color: #dc3545;
        border: none;
        color: white;
        padding: 4px 8px;
        border-radius: 4px;
        cursor: pointer;
        font-size: 0.85rem;
        transition: all 0.2s;
      }

      .delete-group-btn:hover {
        background-color: #c82333;
      }

      .delete-group-btn i {
        pointer-events: none;
      }

      /* Edit button styles */
      .edit-group-btn {
        background-color: #635ba3;
        border: none;
        color: white;
        padding: 4px 8px;
        border-radius: 4px;
        cursor: pointer;
        font-size: 0.85rem;
        transition: all 0.2s;
      }

      .edit-group-btn:hover {
        background-color: #b4aad0;
      }

      .edit-group-btn i {
        pointer-events: none;
      }

      .accordion-body {
        background-color: #f8f9fa;
      }
    </style>

    <!-- Main Tabs -->
    <ul class="nav nav-tabs mb-4" id="groupTabs" role="tablist">
      <li class="nav-item"><a class="nav-link active" id="anxiety-tab" data-bs-toggle="tab" href="#anxiety" role="tab">Anxiety</a></li>
      <li class="nav-item"><a class="nav-link" id="depression-tab" data-bs-toggle="tab" href="#depression" role="tab">Depression</a></li>
      <li class="nav-item"><a class="nav-link" id="grief-tab" data-bs-toggle="tab" href="#grief" role="tab">Grief</a></li>
      <li class="nav-item"><a class="nav-link" id="relationship-tab" data-bs-toggle="tab" href="#relationship" role="tab">Relationship</a></li>
    </ul>

    <div class="tab-content">
      <!-- Anxiety -->
      <div class="tab-pane fade show active" id="anxiety" role="tabpanel">
        <div class="row">
          <div class="col-md-6 mb-4">
            <h4><i class="bi bi-gender-male me-2"></i>Male Groups</h4>
            <button class="btn btn-sm btn-create mb-2 open-subgroup-modal" data-issue="anxiety" data-gender="male" data-bs-toggle="modal" data-bs-target="#createSubGroupModal">
              <i class="bi bi-plus-circle me-1"></i>Create Sub Group
            </button>
            <?php render_subgroups_by_issue_and_gender('anxiety', 'male'); ?>
          </div>
          <div class="col-md-6 mb-4">
            <h4><i class="bi bi-gender-female me-2"></i>Female Groups</h4>
            <button class="btn btn-sm btn-create mb-2 open-subgroup-modal" data-issue="anxiety" data-gender="female" data-bs-toggle="modal" data-bs-target="#createSubGroupModal">
              <i class="bi bi-plus-circle me-1"></i>Create Sub Group
            </button>
            <?php render_subgroups_by_issue_and_gender('anxiety', 'female'); ?>
          </div>
        </div>
      </div>

      <!-- Depression -->
      <div class="tab-pane fade" id="depression" role="tabpanel">
        <div class="row">
          <div class="col-md-6 mb-4">
            <h4><i class="bi bi-gender-male me-2"></i>Male Groups</h4>
            <button class="btn btn-sm btn-create mb-2 open-subgroup-modal" data-issue="depression" data-gender="male" data-bs-toggle="modal" data-bs-target="#createSubGroupModal">
              <i class="bi bi-plus-circle me-1"></i>Create Sub Group
            </button>
            <?php render_subgroups_by_issue_and_gender('depression', 'male'); ?>
          </div>
          <div class="col-md-6 mb-4">
            <h4><i class="bi bi-gender-female me-2"></i>Female Groups</h4>
            <button class="btn btn-sm btn-create mb-2 open-subgroup-modal" data-issue="depression" data-gender="female" data-bs-toggle="modal" data-bs-target="#createSubGroupModal">
              <i class="bi bi-plus-circle me-1"></i>Create Sub Group
            </button>
            <?php render_subgroups_by_issue_and_gender('depression', 'female'); ?>
          </div>
        </div>
      </div>

      <!-- Grief -->
      <div class="tab-pane fade" id="grief" role="tabpanel">
        <div class="row">
          <div class="col-md-6 mb-4">
            <h4><i class="bi bi-gender-male me-2"></i>Male Groups</h4>
            <button class="btn btn-sm btn-create mb-2 open-subgroup-modal" data-issue="grief" data-gender="male" data-bs-toggle="modal" data-bs-target="#createSubGroupModal">
              <i class="bi bi-plus-circle me-1"></i>Create Sub Group
            </button>
            <?php render_subgroups_by_issue_and_gender('grief', 'male'); ?>
          </div>
          <div class="col-md-6 mb-4">
            <h4><i class="bi bi-gender-female me-2"></i>Female Groups</h4>
            <button class="btn btn-sm btn-create mb-2 open-subgroup-modal" data-issue="grief" data-gender="female" data-bs-toggle="modal" data-bs-target="#createSubGroupModal">
              <i class="bi bi-plus-circle me-1"></i>Create Sub Group
            </button>
            <?php render_subgroups_by_issue_and_gender('grief', 'female'); ?>
          </div>
        </div>
      </div>

      <!-- Relationship -->
      <div class="tab-pane fade" id="relationship" role="tabpanel">
        <div class="row">
          <div class="col-md-6 mb-4">
            <h4><i class="bi bi-gender-male me-2"></i>Male Groups</h4>
            <button class="btn btn-sm btn-create mb-2 open-subgroup-modal" data-issue="relationship" data-gender="male" data-bs-toggle="modal" data-bs-target="#createSubGroupModal">
              <i class="bi bi-plus-circle me-1"></i>Create Sub Group
            </button>
            <?php render_subgroups_by_issue_and_gender('relationship', 'male'); ?>
          </div>
          <div class="col-md-6 mb-4">
            <h4><i class="bi bi-gender-female me-2"></i>Female Groups</h4>
            <button class="btn btn-sm btn-create mb-2 open-subgroup-modal" data-issue="relationship" data-gender="female" data-bs-toggle="modal" data-bs-target="#createSubGroupModal">
              <i class="bi bi-plus-circle me-1"></i>Create Sub Group
            </button>
            <?php render_subgroups_by_issue_and_gender('relationship', 'female'); ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Sub Group Creation Modal -->
  <div class="modal fade" id="createSubGroupModal" tabindex="-1" aria-labelledby="createSubGroupModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <form id="createSubGroupForm">
        <div class="modal-content">
          <div class="modal-header" style="background:#6059A6; color:white;">
            <h5 class="modal-title" id="createSubGroupModalLabel">Create New Sub Group</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div id="createSubGroupAlert"></div>

            <input type="hidden" id="issue_type" name="issue_type">
            <input type="hidden" id="gender" name="gender">

            <div class="alert alert-info">
              <i class="bi bi-info-circle me-2"></i>
              <strong>Tip:</strong> Session dates determine when users can register and attend. You can have multiple groups with different session periods.
            </div>

            <div class="mb-3">
              <label for="session_start_date" class="form-label">Session Start Date</label>
              <input type="date" class="form-control" id="session_start_date" name="session_start_date" required>
              <small class="text-muted">First day users can attend sessions</small>
            </div>

            <div class="mb-3">
              <label for="session_expiry_date" class="form-label">Session Expiry Date</label>
              <input type="date" class="form-control" id="session_expiry_date" name="session_expiry_date" required>
              <small class="text-muted">Last day of sessions</small>
            </div>

            <div class="mb-3">
              <label for="start_date" class="form-label">Registration Start Date</label>
              <input type="date" class="form-control" id="start_date" name="start_date" required>
              <small class="text-muted">When registration opens</small>
            </div>

            <div class="mb-3">
              <label for="end_date" class="form-label">Registration End Date</label>
              <input type="date" class="form-control" id="end_date" name="end_date" required>
              <small class="text-muted">When registration closes</small>
            </div>

            <div class="mb-3">
              <label for="max_members" class="form-label">Max Participants</label>
              <input type="number" class="form-control" id="max_members" name="max_members" value="12" required>
            </div>

          </div>
          <div class="modal-footer">
            <button type="submit" class="btn btn-create">Create Group</button>
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- Sub Group Edit Modal -->
  <div class="modal fade" id="editSubGroupModal" tabindex="-1" aria-labelledby="editSubGroupModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <form id="editSubGroupForm">
        <div class="modal-content">
          <div class="modal-header" style="background:#6059A6; color:white;">
            <h5 class="modal-title" id="editSubGroupModalLabel">Edit Sub Group</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div id="editSubGroupAlert"></div>

            <input type="hidden" id="edit_group_id" name="group_id">
            <input type="hidden" id="edit_issue_type" name="issue_type">
            <input type="hidden" id="edit_gender" name="gender">

            <div class="mb-3">
              <label for="edit_session_start_date" class="form-label">Session Start Date</label>
              <input type="date" class="form-control" id="edit_session_start_date" name="session_start_date" required>
            </div>

            <div class="mb-3">
              <label for="edit_session_expiry_date" class="form-label">Session Expiry Date</label>
              <input type="date" class="form-control" id="edit_session_expiry_date" name="session_expiry_date" required>
            </div>

            <div class="mb-3">
              <label for="edit_start_date" class="form-label">Registration Start Date</label>
              <input type="date" class="form-control" id="edit_start_date" name="start_date" required>
            </div>

            <div class="mb-3">
              <label for="edit_end_date" class="form-label">Registration End Date</label>
              <input type="date" class="form-control" id="edit_end_date" name="end_date" required>
            </div>

            <div class="mb-3">
              <label for="edit_max_members" class="form-label">Max Participants</label>
              <input type="number" class="form-control" id="edit_max_members" name="max_members" required>
            </div>

          </div>
          <div class="modal-footer">
            <button type="submit" class="btn btn-create">Save Changes</button>
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <script>
    document.querySelectorAll('.open-subgroup-modal').forEach(btn => {
      btn.addEventListener('click', function() {
        document.getElementById('issue_type').value = this.getAttribute('data-issue');
        document.getElementById('gender').value = this.getAttribute('data-gender');
      });
    });

    document.getElementById('createSubGroupForm').addEventListener('submit', function(e) {
      e.preventDefault();
      const form = e.target;
      const data = new FormData(form);
      data.append('action', 'create_sub_group');

      fetch('<?php echo admin_url("admin-ajax.php"); ?>', {
          method: 'POST',
          body: data
        })
        .then(res => res.json())
        .then(response => {
          const alertBox = document.getElementById('createSubGroupAlert');
          if (response.success) {
            alertBox.innerHTML = '<div class="alert alert-success">Sub group created successfully!</div>';
            form.reset();

            const modal = bootstrap.Modal.getInstance(document.getElementById('createSubGroupModal'));
            modal.hide();
            location.reload();
          } else {
            alertBox.innerHTML = '<div class="alert alert-danger">' + response.data + '</div>';
          }
        });
    });

    // DELETE GROUP FUNCTIONALITY
    document.querySelectorAll('.delete-group-btn').forEach(btn => {
      btn.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();

        const groupId = this.getAttribute('data-group-id');
        const issue = this.getAttribute('data-issue');
        const gender = this.getAttribute('data-gender');

        if (!confirm('Are you sure you want to delete this therapy group?\n\nThis will also delete:\n• All associated schedules\n• All scheduled meetings\n• User assignments\n\nThis action cannot be undone!')) {
          return;
        }

        const btn = this;
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-hourglass-split"></i>';

        const data = new FormData();
        data.append('action', 'delete_therapy_group');
        data.append('group_id', groupId);

        fetch('<?php echo admin_url("admin-ajax.php"); ?>', {
            method: 'POST',
            body: data
          })
          .then(res => res.json())
          .then(response => {
            if (response.success) {
              alert(response.data);
              location.reload();
            } else {
              alert("Error: " + response.data);
              btn.disabled = false;
              btn.innerHTML = '<i class="bi bi-trash"></i>';
            }
          })
          .catch(error => {
            alert("Error: " + error);
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-trash"></i>';
          });
      });
    });

    // EDIT GROUP FUNCTIONALITY
    document.querySelectorAll('.edit-group-btn').forEach(btn => {
      btn.addEventListener('click', function(e) {
        e.stopPropagation();

        document.getElementById('edit_group_id').value = this.getAttribute('data-group-id');
        document.getElementById('edit_issue_type').value = this.getAttribute('data-issue');
        document.getElementById('edit_gender').value = this.getAttribute('data-gender');
        document.getElementById('edit_max_members').value = this.getAttribute('data-max-members');
        document.getElementById('edit_start_date').value = this.getAttribute('data-start-date');
        document.getElementById('edit_end_date').value = this.getAttribute('data-end-date');
        document.getElementById('edit_session_start_date').value = this.getAttribute('data-session-start');
        document.getElementById('edit_session_expiry_date').value = this.getAttribute('data-session-expiry');
      });
    });

    document.getElementById('editSubGroupForm').addEventListener('submit', function(e) {
      e.preventDefault();
      const form = e.target;
      const data = new FormData(form);
      data.append('action', 'edit_therapy_group');

      const submitBtn = form.querySelector('button[type="submit"]');
      submitBtn.disabled = true;
      submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Saving...';

      fetch('<?php echo admin_url("admin-ajax.php"); ?>', {
          method: 'POST',
          body: data
        })
        .then(res => res.json())
        .then(response => {
          const alertBox = document.getElementById('editSubGroupAlert');
          if (response.success) {
            alertBox.innerHTML = '<div class="alert alert-success">' + response.data + '</div>';
            setTimeout(() => {
              const modal = bootstrap.Modal.getInstance(document.getElementById('editSubGroupModal'));
              modal.hide();
              location.reload();
            }, 1000);
          } else {
            alertBox.innerHTML = '<div class="alert alert-danger">' + response.data + '</div>';
            submitBtn.disabled = false;
            submitBtn.innerHTML = 'Save Changes';
          }
        })
        .catch(error => {
          document.getElementById('editSubGroupAlert').innerHTML = '<div class="alert alert-danger">Error: ' + error + '</div>';
          submitBtn.disabled = false;
          submitBtn.innerHTML = 'Save Changes';
        });
    });
  </script>

<?php
}



// ===============================================
// THERAPY GROUP SCHEDULING SYSTEM
// ===============================================

// DATABASE TABLE FOR SCHEDULES
add_action('init', 'create_therapy_group_schedules_table');
function create_therapy_group_schedules_table()
{
  global $wpdb;

  $table_name = $wpdb->prefix . 'therapy_group_schedules';
  $charset_collate = $wpdb->get_charset_collate();

  $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        group_id BIGINT UNSIGNED NOT NULL,
        issue_type VARCHAR(50) NOT NULL,
        gender VARCHAR(20) NOT NULL,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME NOT NULL,
        repetition_days VARCHAR(100) NOT NULL,
        zoom_meeting_id VARCHAR(100) DEFAULT NULL,
        zoom_join_url TEXT DEFAULT NULL,
        zoom_start_url TEXT DEFAULT NULL,
        zoom_password VARCHAR(50) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_by BIGINT UNSIGNED DEFAULT NULL,
        PRIMARY KEY (id),
        KEY group_id (group_id),
        KEY issue_type (issue_type),
        KEY gender (gender)
    ) {$charset_collate};";

  require_once ABSPATH . 'wp-admin/includes/upgrade.php';
  dbDelta($sql);
}

// DATABASE TABLE FOR SCHEDULED MEETINGS
add_action('init', 'create_therapy_scheduled_meetings_table');
function create_therapy_scheduled_meetings_table()
{
  global $wpdb;

  $table_name = $wpdb->prefix . 'therapy_scheduled_meetings';
  $charset_collate = $wpdb->get_charset_collate();

  $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        schedule_id BIGINT UNSIGNED NOT NULL,
        group_id BIGINT UNSIGNED NOT NULL,
        meeting_date DATE NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME NOT NULL,
        zoom_meeting_id VARCHAR(100) DEFAULT NULL,
        zoom_join_url TEXT DEFAULT NULL,
        zoom_start_url TEXT DEFAULT NULL,
        zoom_password VARCHAR(50) DEFAULT NULL,
        notification_sent TINYINT(1) DEFAULT 0,
        notification_count INT UNSIGNED DEFAULT 0,
        meeting_status VARCHAR(20) DEFAULT 'scheduled',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY schedule_id (schedule_id),
        KEY group_id (group_id),
        KEY meeting_date (meeting_date),
        KEY notification_sent (notification_sent)
    ) {$charset_collate};";

  require_once ABSPATH . 'wp-admin/includes/upgrade.php';
  dbDelta($sql);
}

// Ensure Zoom URL columns can store long payloads even if tables were created before the TEXT change
add_action('init', 'tashafe_upgrade_zoom_url_columns');
function tashafe_upgrade_zoom_url_columns()
{
  global $wpdb;

  $tables = [
    $wpdb->prefix . 'therapy_group_schedules' => ['zoom_join_url', 'zoom_start_url'],
    $wpdb->prefix . 'therapy_scheduled_meetings' => ['zoom_join_url', 'zoom_start_url']
  ];

  foreach ($tables as $table => $columns) {
    foreach ($columns as $column) {
      $column_info = $wpdb->get_row($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $column));
      if ($column_info && stripos($column_info->Type, 'text') === false) {
        $wpdb->query("ALTER TABLE {$table} MODIFY {$column} TEXT NULL");
      }
    }
  }

  // Add notification_count column if it doesn't exist
  $meetings_table = $wpdb->prefix . 'therapy_scheduled_meetings';
  $column_exists = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'notification_count'",
    $meetings_table
  ));

  if (!$column_exists) {
    $wpdb->query("ALTER TABLE {$meetings_table} ADD COLUMN notification_count INT UNSIGNED DEFAULT 0 AFTER notification_sent");
  }
}

// ZOOM API CONFIGURATION
if (!defined('TASHAFE_ZOOM_ACCOUNT_ID')) {
  define('TASHAFE_ZOOM_ACCOUNT_ID', '2dqw3mnMSXuzr1VumS6jpQ');
  define('TASHAFE_ZOOM_CLIENT_ID', 'TR2Cj1lRzexMemOmTNLzw');
  define('TASHAFE_ZOOM_CLIENT_SECRET', 'n8u3U7mRDJu23iUCKqyy2gSTZ99IOvi7');
}

// ZOOM API - GET ACCESS TOKEN
function tashafe_get_zoom_access_token()
{
  $transient_key = 'tashafe_zoom_access_token';
  $access_token = get_transient($transient_key);

  if ($access_token) {
    return $access_token;
  }

  $credentials = base64_encode(TASHAFE_ZOOM_CLIENT_ID . ':' . TASHAFE_ZOOM_CLIENT_SECRET);

  $response = wp_remote_post('https://zoom.us/oauth/token', [
    'headers' => [
      'Authorization' => 'Basic ' . $credentials,
      'Content-Type' => 'application/x-www-form-urlencoded'
    ],
    'body' => [
      'grant_type' => 'account_credentials',
      'account_id' => TASHAFE_ZOOM_ACCOUNT_ID
    ],
    'timeout' => 30
  ]);

  if (is_wp_error($response)) {
    error_log('Zoom OAuth Error: ' . $response->get_error_message());
    return false;
  }

  $body = json_decode(wp_remote_retrieve_body($response), true);

  if (isset($body['access_token'])) {
    $expires_in = isset($body['expires_in']) ? $body['expires_in'] - 60 : 3540;
    set_transient($transient_key, $body['access_token'], $expires_in);
    return $body['access_token'];
  }

  error_log('Zoom OAuth Error: ' . print_r($body, true));
  return false;
}

// ZOOM API - CREATE MEETING
function tashafe_create_zoom_meeting($topic, $start_time, $duration = 90, $timezone = 'Asia/Riyadh')
{
  $access_token = tashafe_get_zoom_access_token();

  if (!$access_token) {
    return ['error' => 'Failed to get Zoom access token'];
  }

  $response = wp_remote_post('https://api.zoom.us/v2/users/me/meetings', [
    'headers' => [
      'Authorization' => 'Bearer ' . $access_token,
      'Content-Type' => 'application/json'
    ],
    'body' => json_encode([
      'topic' => $topic,
      'type' => 2,
      'start_time' => $start_time,
      'duration' => $duration,
      'timezone' => $timezone,
      'settings' => [
        'host_video' => true,
        'participant_video' => true,
        'join_before_host' => false,
        'mute_upon_entry' => true,
        'waiting_room' => true,
        'approval_type' => 0,
        'registration_type' => 1
      ]
    ]),
    'timeout' => 30
  ]);

  if (is_wp_error($response)) {
    error_log('Zoom Create Meeting Error: ' . $response->get_error_message());
    return ['error' => $response->get_error_message()];
  }

  $body = json_decode(wp_remote_retrieve_body($response), true);

  if (isset($body['id'])) {
    return [
      'meeting_id' => $body['id'],
      'join_url' => $body['join_url'],
      'start_url' => $body['start_url'],
      'password' => $body['password'] ?? ''
    ];
  }

  error_log('Zoom Create Meeting Error: ' . print_r($body, true));
  return ['error' => isset($body['message']) ? $body['message'] : 'Failed to create Zoom meeting'];
}

// ADMIN MENU
add_action('admin_menu', 'therapy_group_scheduling_menu');
function therapy_group_scheduling_menu()
{
  add_menu_page(
    'Therapy Scheduling',
    'Therapy Scheduling',
    'manage_options',
    'therapy-scheduling',
    'render_therapy_scheduling_dashboard',
    'dashicons-calendar-alt',
    7
  );
}

// GET SUB-GROUPS WITHOUT SCHEDULES
function get_unscheduled_subgroups($issue_type, $gender)
{
  global $wpdb;

  $schedules_table = $wpdb->prefix . 'therapy_group_schedules';
  $meetings_table = $wpdb->prefix . 'therapy_scheduled_meetings';

  // Make sure the custom tables exist before attempting any writes
  create_therapy_group_schedules_table();
  create_therapy_scheduled_meetings_table();

  $args = [
    'post_type' => 'therapy_group',
    'posts_per_page' => -1,
    'post_status' => 'publish',
    'meta_query' => [
      [
        'key' => 'issue_type',
        'value' => $issue_type,
        'compare' => '='
      ],
      [
        'key' => 'gender',
        'value' => $gender,
        'compare' => '='
      ]
    ],
    'orderby' => 'date',
    'order' => 'DESC',
  ];

  $groups = get_posts($args);
  $unscheduled = [];

  foreach ($groups as $group) {
    $has_schedule = $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$schedules_table} WHERE group_id = %d AND end_date >= CURDATE()",
      $group->ID
    ));

    $has_upcoming_meetings = $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$meetings_table} WHERE group_id = %d AND meeting_date >= CURDATE()",
      $group->ID
    ));

    if (!$has_schedule || !$has_upcoming_meetings) {
      $group_number = get_field('group_number', $group->ID) ?: 'N/A';
      $max_members = get_field('max_members', $group->ID) ?: 'N/A';
      $group_status = get_field('group_status', $group->ID) ?: 'active';
      $session_start_date = get_field('session_start_date', $group->ID) ?: '';
      $session_expiry_date = get_field('session_expiry_date', $group->ID) ?: '';

      $unscheduled[] = [
        'id' => $group->ID,
        'title' => $group->post_title,
        'group_number' => $group_number,
        'max_members' => $max_members,
        'status' => $group_status,
        'session_start_date' => $session_start_date,
        'session_expiry_date' => $session_expiry_date
      ];
    }
  }

  return $unscheduled;
}

// AJAX - GET UNSCHEDULED SUB-GROUPS
add_action('wp_ajax_get_unscheduled_subgroups', 'ajax_get_unscheduled_subgroups');
function ajax_get_unscheduled_subgroups()
{
  if (!current_user_can('manage_options')) {
    wp_send_json_error('Unauthorized');
  }

  $issue = sanitize_text_field($_POST['issue'] ?? '');
  $gender = sanitize_text_field($_POST['gender'] ?? '');

  if (empty($issue) || empty($gender)) {
    wp_send_json_error('Issue and gender are required');
  }

  $subgroups = get_unscheduled_subgroups($issue, $gender);
  wp_send_json_success($subgroups);
}

// GET SUB-GROUPS WITH SCHEDULES (for revert functionality)
function get_scheduled_subgroups($issue_type, $gender)
{
  global $wpdb;

  $schedules_table = $wpdb->prefix . 'therapy_group_schedules';
  $meetings_table = $wpdb->prefix . 'therapy_scheduled_meetings';

  $args = [
    'post_type' => 'therapy_group',
    'posts_per_page' => -1,
    'post_status' => 'publish',
    'meta_query' => [
      [
        'key' => 'issue_type',
        'value' => $issue_type,
        'compare' => '='
      ],
      [
        'key' => 'gender',
        'value' => $gender,
        'compare' => '='
      ]
    ],
    'orderby' => 'date',
    'order' => 'DESC',
  ];

  $groups = get_posts($args);
  $scheduled = [];

  foreach ($groups as $group) {
    $schedule = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM {$schedules_table} WHERE group_id = %d ORDER BY id DESC LIMIT 1",
      $group->ID
    ));

    if ($schedule) {
      $meetings_count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$meetings_table} WHERE schedule_id = %d",
        $schedule->id
      ));

      $group_number = get_field('group_number', $group->ID) ?: 'N/A';
      $max_members = get_field('max_members', $group->ID) ?: 'N/A';
      $group_status = get_field('group_status', $group->ID) ?: 'active';

      $scheduled[] = [
        'id' => $group->ID,
        'title' => $group->post_title,
        'group_number' => $group_number,
        'max_members' => $max_members,
        'status' => $group_status,
        'schedule_id' => $schedule->id,
        'start_date' => $schedule->start_date,
        'end_date' => $schedule->end_date,
        'start_time' => substr($schedule->start_time, 0, 5),
        'end_time' => substr($schedule->end_time, 0, 5),
        'meetings_count' => $meetings_count
      ];
    }
  }

  return $scheduled;
}

// AJAX - GET SCHEDULED SUB-GROUPS (for revert tab)
add_action('wp_ajax_get_scheduled_subgroups', 'ajax_get_scheduled_subgroups');
function ajax_get_scheduled_subgroups()
{
  if (!current_user_can('manage_options')) {
    wp_send_json_error('Unauthorized');
  }

  $issue = sanitize_text_field($_POST['issue'] ?? '');
  $gender = sanitize_text_field($_POST['gender'] ?? '');

  if (empty($issue) || empty($gender)) {
    wp_send_json_error('Issue and gender are required');
  }

  $subgroups = get_scheduled_subgroups($issue, $gender);
  wp_send_json_success($subgroups);
}

// AJAX - REVERT THERAPY SCHEDULE
add_action('wp_ajax_revert_therapy_schedule', 'ajax_revert_therapy_schedule');
function ajax_revert_therapy_schedule()
{
  if (!current_user_can('manage_options')) {
    wp_send_json_error('Unauthorized');
  }

  global $wpdb;

  $group_id = intval($_POST['group_id'] ?? 0);
  $schedule_id = intval($_POST['schedule_id'] ?? 0);

  if (!$group_id || !$schedule_id) {
    wp_send_json_error('Invalid group or schedule ID');
  }

  $schedules_table = $wpdb->prefix . 'therapy_group_schedules';
  $meetings_table = $wpdb->prefix . 'therapy_scheduled_meetings';

  // Delete all meetings associated with this schedule
  $meetings_deleted = $wpdb->delete(
    $meetings_table,
    ['schedule_id' => $schedule_id],
    ['%d']
  );

  // Delete the schedule itself
  $schedule_deleted = $wpdb->delete(
    $schedules_table,
    ['id' => $schedule_id],
    ['%d']
  );

  if ($schedule_deleted !== false) {
    wp_send_json_success([
      'message' => "Schedule reverted successfully! {$meetings_deleted} meeting(s) deleted. The sub-group is now available for re-scheduling.",
      'meetings_deleted' => $meetings_deleted
    ]);
  } else {
    wp_send_json_error('Failed to revert schedule');
  }
}

// AJAX - SAVE SCHEDULE AND CREATE ZOOM MEETINGS
add_action('wp_ajax_save_therapy_schedule', 'ajax_save_therapy_schedule');
function ajax_save_therapy_schedule()
{
  if (!current_user_can('manage_options')) {
    wp_send_json_error('Unauthorized');
  }

  global $wpdb;

  $group_id = intval($_POST['group_id'] ?? 0);
  $issue_type = sanitize_text_field($_POST['issue_type'] ?? '');
  $gender = sanitize_text_field($_POST['gender'] ?? '');
  $start_date = sanitize_text_field($_POST['start_date'] ?? '');
  $end_date = sanitize_text_field($_POST['end_date'] ?? '');
  $start_time = sanitize_text_field($_POST['start_time'] ?? '');
  $end_time = sanitize_text_field($_POST['end_time'] ?? '');
  $repetition_days = isset($_POST['repetition_days']) ? $_POST['repetition_days'] : [];

  if (
    !$group_id || empty($issue_type) || empty($gender) || empty($start_date) ||
    empty($end_date) || empty($start_time) || empty($end_time) || empty($repetition_days)
  ) {
    wp_send_json_error('All fields are required');
  }

  if (is_array($repetition_days)) {
    $repetition_days = implode(',', array_map('sanitize_text_field', $repetition_days));
  }

  $schedules_table = $wpdb->prefix . 'therapy_group_schedules';
  $meetings_table = $wpdb->prefix . 'therapy_scheduled_meetings';

  $group_number = get_field('group_number', $group_id) ?: 'Group';
  $meeting_topic = ucfirst($issue_type) . ' - ' . ucfirst($gender) . ' Sub Group ' . $group_number . ' - Therapy Session';

  $start_dt = new DateTime($start_time);
  $end_dt = new DateTime($end_time);
  $duration = ($end_dt->getTimestamp() - $start_dt->getTimestamp()) / 60;

  $inserted = $wpdb->insert($schedules_table, [
    'group_id' => $group_id,
    'issue_type' => $issue_type,
    'gender' => $gender,
    'start_date' => $start_date,
    'end_date' => $end_date,
    'start_time' => $start_time,
    'end_time' => $end_time,
    'repetition_days' => $repetition_days,
    'created_by' => get_current_user_id()
  ], ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d']);

  if (!$inserted) {
    wp_send_json_error('Failed to save schedule');
  }

  $schedule_id = $wpdb->insert_id;

  $rep_days = explode(',', $repetition_days);

  $current_date = new DateTime($start_date);
  $end_date_obj = new DateTime($end_date);
  $meetings_created = 0;
  $zoom_errors = [];

  while ($current_date <= $end_date_obj) {
    $current_day_name = strtolower($current_date->format('l'));

    if (in_array($current_day_name, $rep_days)) {
      $meeting_datetime = $current_date->format('Y-m-d') . 'T' . $start_time;
      $zoom_result = tashafe_create_zoom_meeting($meeting_topic, $meeting_datetime, $duration);

      $zoom_meeting_id = null;
      $zoom_join_url = null;
      $zoom_start_url = null;
      $zoom_password = null;

      if (isset($zoom_result['meeting_id'])) {
        $zoom_meeting_id = $zoom_result['meeting_id'];
        $zoom_join_url = $zoom_result['join_url'];
        $zoom_start_url = $zoom_result['start_url'];
        $zoom_password = $zoom_result['password'];
      } else {
        $zoom_errors[] = $current_date->format('Y-m-d') . ': ' . ($zoom_result['error'] ?? 'Unknown error');
      }

      $meeting_inserted = $wpdb->insert($meetings_table, [
        'schedule_id' => $schedule_id,
        'group_id' => $group_id,
        'meeting_date' => $current_date->format('Y-m-d'),
        'start_time' => $start_time,
        'end_time' => $end_time,
        'zoom_meeting_id' => $zoom_meeting_id,
        'zoom_join_url' => $zoom_join_url,
        'zoom_start_url' => $zoom_start_url,
        'zoom_password' => $zoom_password,
        'notification_sent' => 0,
        'meeting_status' => 'scheduled'
      ], ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s']);

      if ($meeting_inserted === false) {
        $zoom_errors[] = $current_date->format('Y-m-d') . ': DB error - ' . $wpdb->last_error;
      } else {
        $meetings_created++;
      }
    }

    $current_date->modify('+1 day');
  }

  if ($meetings_created === 0) {
    // Roll back the schedule so the subgroup becomes available again
    $wpdb->delete($schedules_table, ['id' => $schedule_id], ['%d']);
    $error_message = 'Unable to generate any meetings for this schedule. Please verify the date range and repetition days.';
    if (!empty($zoom_errors)) {
      $error_message .= ' Details: ' . implode(' | ', $zoom_errors);
    }
    wp_send_json_error($error_message);
  }

  $response = [
    'message' => "Schedule saved! {$meetings_created} meeting(s) created.",
    'schedule_id' => $schedule_id,
    'meetings_created' => $meetings_created
  ];

  if (!empty($zoom_errors)) {
    $response['zoom_errors'] = $zoom_errors;
  }

  wp_send_json_success($response);
}

// AJAX - GET SCHEDULED MEETINGS
add_action('wp_ajax_get_therapy_scheduled_meetings', 'ajax_get_therapy_scheduled_meetings');
function ajax_get_therapy_scheduled_meetings()
{
  if (!current_user_can('manage_options')) {
    wp_send_json_error('Unauthorized');
  }

  global $wpdb;

  $meetings_table = $wpdb->prefix . 'therapy_scheduled_meetings';
  $schedules_table = $wpdb->prefix . 'therapy_group_schedules';

  // First check if tables exist
  $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$meetings_table}'");
  if (!$table_exists) {
    error_log('Therapy Scheduling Error: Table ' . $meetings_table . ' does not exist');
    wp_send_json_success([]);
    return;
  }

  // Get filter parameters
  $filter_issue = sanitize_text_field($_POST['filter_issue'] ?? '');
  $filter_gender = sanitize_text_field($_POST['filter_gender'] ?? '');
  $filter_group = intval($_POST['filter_group'] ?? 0);

  // Build WHERE clause for filters
  $where_clauses = [];
  $where_values = [];

  if (!empty($filter_issue)) {
    $where_clauses[] = "s.issue_type = %s";
    $where_values[] = $filter_issue;
  }

  if (!empty($filter_gender)) {
    $where_clauses[] = "s.gender = %s";
    $where_values[] = $filter_gender;
  }

  if ($filter_group > 0) {
    $where_clauses[] = "m.group_id = %d";
    $where_values[] = $filter_group;
  }

  $where_sql = '';
  if (!empty($where_clauses)) {
    $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
  }

  // Get all scheduled meetings (including past ones)
  $query = "
        SELECT m.*, s.issue_type, s.gender, s.repetition_days
        FROM {$meetings_table} m
        INNER JOIN {$schedules_table} s ON m.schedule_id = s.id
        {$where_sql}
        ORDER BY m.meeting_date ASC, m.start_time ASC
    ";

  if (!empty($where_values)) {
    $meetings = $wpdb->get_results($wpdb->prepare($query, ...$where_values), ARRAY_A);
  } else {
    $meetings = $wpdb->get_results($query, ARRAY_A);
  }

  // Check for SQL errors
  if ($wpdb->last_error) {
    error_log('Therapy Scheduling DB Error: ' . $wpdb->last_error);
  }

  // Handle null or empty results
  if ($meetings === null || $meetings === false) {
    error_log('Therapy Scheduling: Query returned null/false. Last error: ' . $wpdb->last_error);
    wp_send_json_success([]);
    return;
  }

  if (empty($meetings)) {
    wp_send_json_success([]);
    return;
  }

  // Process meetings data
  foreach ($meetings as &$meeting) {
    $group_number = get_field('group_number', $meeting['group_id']) ?: 'N/A';
    $meeting['group_number'] = $group_number;
    $meeting['group_title'] = get_the_title($meeting['group_id']);
    // Ensure notification_count exists (for older records)
    $meeting['notification_count'] = intval($meeting['notification_count'] ?? 0);
  }

  wp_send_json_success($meetings);
}

// SEND MEETING NOTIFICATION EMAIL
function send_therapy_meeting_notification($meeting_id, $source = 'cron')
{
  global $wpdb;

  $meetings_table = $wpdb->prefix . 'therapy_scheduled_meetings';
  $schedules_table = $wpdb->prefix . 'therapy_group_schedules';

  $meeting = $wpdb->get_row($wpdb->prepare("
        SELECT m.*, s.issue_type, s.gender
        FROM {$meetings_table} m
        JOIN {$schedules_table} s ON m.schedule_id = s.id
        WHERE m.id = %d
    ", $meeting_id), ARRAY_A);

  if (!$meeting) {
    return false;
  }

  $members = get_users([
    'meta_key' => 'assigned_group',
    'meta_value' => $meeting['group_id'],
  ]);

  if (empty($members)) {
    return false;
  }

  $group_number = get_field('group_number', $meeting['group_id']) ?: 'Your';
  $issue = ucfirst($meeting['issue_type']);
  $gender = ucfirst($meeting['gender']);

  $meeting_date = date('l, F j, Y', strtotime($meeting['meeting_date']));
  $start_time = date('g:i A', strtotime($meeting['start_time']));
  $end_time = date('g:i A', strtotime($meeting['end_time']));

  $subject = "Upcoming Therapy Session – {$meeting_date} – Tashafe";

  foreach ($members as $member) {
    $first_name = get_user_meta($member->ID, 'first_name', true) ?: $member->display_name;

    $message = generate_meeting_notification_email(
      $first_name,
      $issue,
      $gender,
      $group_number,
      $meeting_date,
      $start_time,
      $end_time,
      $meeting['zoom_join_url'],
      $meeting['zoom_password']
    );

    $headers = ['Content-Type: text/html; charset=UTF-8'];
    wp_mail($member->user_email, $subject, $message, $headers);
  }

  // Update notification_sent flag and increment notification_count
  $current_count = intval($meeting['notification_count'] ?? 0);
  $wpdb->update(
    $meetings_table,
    [
      'notification_sent' => 1,
      'notification_count' => $current_count + 1
    ],
    ['id' => $meeting_id],
    ['%d', '%d'],
    ['%d']
  );

  return true;
}

// GENERATE MEETING NOTIFICATION EMAIL HTML
function generate_meeting_notification_email($first_name, $issue, $gender, $group_number, $meeting_date, $start_time, $end_time, $zoom_link, $zoom_password)
{
  ob_start();
?>
  <!DOCTYPE html>
  <html lang="en">

  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Therapy Session Reminder</title>
  </head>

  <body style="margin:0; padding:0; background:#f6f6f6; font-family:Arial, sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f6f6f6; padding:40px 0;">
      <tr>
        <td align="center">
          <table width="600" cellpadding="0" cellspacing="0"
            style="background:#ffffff; border-radius:10px; overflow:hidden; box-shadow:0 4px 12px rgba(0,0,0,0.08);">
            <tr>
              <td style="background:linear-gradient(135deg, #C3DDD2, #6059A6); padding:24px; text-align:center; color:#ffffff; font-size:24px; font-weight:bold;">
                Upcoming Therapy Session
              </td>
            </tr>
            <tr>
              <td style="padding:30px; color:#333; font-size:16px; line-height:26px;">
                <p>Hi <?php echo esc_html($first_name); ?>,</p>
                <p>This is a reminder for your upcoming <strong><?php echo esc_html($issue); ?></strong> therapy group session.</p>
                <div style="background:#f8f9fa; padding:20px; border-radius:10px; margin:20px 0; border-left:4px solid #6059A6;">
                  <p style="margin:0 0 10px 0; font-weight:600; color:#6059A6; font-size:18px;">📅 Meeting Details</p>
                  <table style="width:100%; font-size:15px; color:#333;">
                    <tr>
                      <td style="padding:5px 0;"><strong>Group:</strong></td>
                      <td style="padding:5px 0;"><?php echo esc_html($gender); ?> Sub Group <?php echo esc_html($group_number); ?></td>
                    </tr>
                    <tr>
                      <td style="padding:5px 0;"><strong>Date:</strong></td>
                      <td style="padding:5px 0;"><?php echo esc_html($meeting_date); ?></td>
                    </tr>
                    <tr>
                      <td style="padding:5px 0;"><strong>Time:</strong></td>
                      <td style="padding:5px 0;"><?php echo esc_html($start_time); ?> – <?php echo esc_html($end_time); ?></td>
                    </tr>
                    <?php if ($zoom_password): ?>
                      <tr>
                        <td style="padding:5px 0;"><strong>Password:</strong></td>
                        <td style="padding:5px 0;"><?php echo esc_html($zoom_password); ?></td>
                      </tr>
                    <?php endif; ?>
                  </table>
                </div>
                <?php if ($zoom_link): ?>
                  <table cellspacing="0" cellpadding="0" style="margin:25px 0; width:100%;">
                    <tr>
                      <td align="center">
                        <a href="<?php echo esc_url($zoom_link); ?>"
                          style="display:inline-block; padding:16px 40px; background:linear-gradient(135deg, #C3DDD2, #6059A6); color:#fff; text-decoration:none; font-weight:600; border-radius:10px; font-size:18px; box-shadow:0 4px 12px rgba(96,89,166,0.3);">
                          🎥 Join Zoom Meeting
                        </a>
                      </td>
                    </tr>
                  </table>
                <?php endif; ?>
                <p style="margin-top:20px; font-size:14px; color:#666;">
                  Please join a few minutes early to ensure everything is working properly.
                  If you have any questions, feel free to contact us.
                </p>
              </td>
            </tr>
            <tr>
              <td style="background:#f0f0f0; padding:16px; text-align:center; font-size:12px; color:#666;">
                © <?php echo date("Y"); ?> Tashafe — All Rights Reserved.
              </td>
            </tr>
          </table>
        </td>
      </tr>
    </table>
  </body>

  </html>
<?php
  return ob_get_clean();
}

// CRON JOB FOR SENDING NOTIFICATIONS
add_action('init', 'schedule_therapy_meeting_notifications');
function schedule_therapy_meeting_notifications()
{
  if (!wp_next_scheduled('tashafe_send_therapy_notifications')) {
    wp_schedule_event(time(), 'hourly', 'tashafe_send_therapy_notifications');
  }
}

add_action('tashafe_send_therapy_notifications', 'process_therapy_meeting_notifications');
function process_therapy_meeting_notifications()
{
  global $wpdb;

  $meetings_table = $wpdb->prefix . 'therapy_scheduled_meetings';
  $tomorrow = date('Y-m-d', strtotime('+1 day'));

  $meetings = $wpdb->get_results($wpdb->prepare("
        SELECT id FROM {$meetings_table}
        WHERE meeting_date = %s
        AND notification_sent = 0
        AND meeting_status = 'scheduled'
    ", $tomorrow), ARRAY_A);

  foreach ($meetings as $meeting) {
    send_therapy_meeting_notification($meeting['id'], 'cron');
  }
}

// AJAX - SEND TEST NOTIFICATION
add_action('wp_ajax_send_therapy_notification_now', 'ajax_send_therapy_notification_now');
function ajax_send_therapy_notification_now()
{
  if (!current_user_can('manage_options')) {
    wp_send_json_error('Unauthorized');
  }

  $meeting_id = intval($_POST['meeting_id'] ?? 0);

  if (!$meeting_id) {
    wp_send_json_error('Invalid meeting ID');
  }

  $result = send_therapy_meeting_notification($meeting_id, 'manual');

  if ($result) {
    wp_send_json_success('Notifications sent successfully');
  } else {
    wp_send_json_error('Failed to send notifications (no members assigned to this group)');
  }
}

// AJAX - EDIT THERAPY MEETING
add_action('wp_ajax_edit_therapy_meeting', 'ajax_edit_therapy_meeting');
function ajax_edit_therapy_meeting()
{
  if (!current_user_can('manage_options')) {
    wp_send_json_error('Unauthorized');
  }

  global $wpdb;

  $meeting_id = intval($_POST['meeting_id'] ?? 0);
  $meeting_date = sanitize_text_field($_POST['meeting_date'] ?? '');
  $start_time = sanitize_text_field($_POST['start_time'] ?? '');
  $end_time = sanitize_text_field($_POST['end_time'] ?? '');
  $zoom_join_url = sanitize_text_field($_POST['zoom_join_url'] ?? '');
  $zoom_password = sanitize_text_field($_POST['zoom_password'] ?? '');

  if (!$meeting_id || empty($meeting_date) || empty($start_time) || empty($end_time)) {
    wp_send_json_error('All fields are required');
  }

  $meetings_table = $wpdb->prefix . 'therapy_scheduled_meetings';

  // Verify meeting exists
  $meeting = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$meetings_table} WHERE id = %d",
    $meeting_id
  ));

  if (!$meeting) {
    wp_send_json_error('Meeting not found');
  }

  // Update the meeting
  $update_data = [
    'meeting_date' => $meeting_date,
    'start_time' => $start_time,
    'end_time' => $end_time
  ];
  $format = ['%s', '%s', '%s'];

  // Only update zoom fields if provided
  if (!empty($zoom_join_url)) {
    $update_data['zoom_join_url'] = $zoom_join_url;
    $format[] = '%s';
  }
  if (!empty($zoom_password)) {
    $update_data['zoom_password'] = $zoom_password;
    $format[] = '%s';
  }

  $updated = $wpdb->update(
    $meetings_table,
    $update_data,
    ['id' => $meeting_id],
    $format,
    ['%d']
  );

  if ($updated !== false) {
    wp_send_json_success('Meeting updated successfully');
  } else {
    wp_send_json_error('Failed to update meeting');
  }
}

// AJAX - DELETE THERAPY MEETING
add_action('wp_ajax_delete_therapy_meeting', 'ajax_delete_therapy_meeting');
function ajax_delete_therapy_meeting()
{
  if (!current_user_can('manage_options')) {
    wp_send_json_error('Unauthorized');
  }

  global $wpdb;

  $meeting_id = intval($_POST['meeting_id'] ?? 0);

  if (!$meeting_id) {
    wp_send_json_error('Invalid meeting ID');
  }

  $meetings_table = $wpdb->prefix . 'therapy_scheduled_meetings';

  // Verify meeting exists
  $meeting = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$meetings_table} WHERE id = %d",
    $meeting_id
  ));

  if (!$meeting) {
    wp_send_json_error('Meeting not found');
  }

  // Delete the meeting
  $deleted = $wpdb->delete(
    $meetings_table,
    ['id' => $meeting_id],
    ['%d']
  );

  if ($deleted) {
    wp_send_json_success('Meeting deleted successfully');
  } else {
    wp_send_json_error('Failed to delete meeting');
  }
}

// AJAX - GET ALL SUBGROUPS FOR FILTERING
add_action('wp_ajax_get_all_subgroups', 'ajax_get_all_subgroups');
function ajax_get_all_subgroups()
{
  if (!current_user_can('manage_options')) {
    wp_send_json_error('Unauthorized');
  }

  $args = [
    'post_type' => 'therapy_group',
    'posts_per_page' => -1,
    'post_status' => 'publish',
    'orderby' => 'title',
    'order' => 'ASC',
  ];

  $groups = get_posts($args);
  $result = [];

  foreach ($groups as $group) {
    $group_number = get_field('group_number', $group->ID) ?: 'N/A';
    $issue_type = get_field('issue_type', $group->ID) ?: '';
    $gender = get_field('gender', $group->ID) ?: '';

    $result[] = [
      'id' => $group->ID,
      'title' => $group->post_title,
      'group_number' => $group_number,
      'issue_type' => $issue_type,
      'gender' => $gender
    ];
  }

  wp_send_json_success($result);
}

// RENDER ADMIN DASHBOARD
function render_therapy_scheduling_dashboard()
{
  $issues = ['anxiety', 'depression', 'grief', 'relationship'];
?>
  <div class="wrap" style="max-width: 100%; margin-right: 20px;">
    <h1 class="wp-heading-inline">Therapy Scheduling</h1>
    <hr class="wp-header-end">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <style>
      /* Reset WordPress admin conflicts */
      #wpbody-content {
        overflow-x: hidden !important;
      }

      #wpbody-content .wrap {
        max-width: 100% !important;
        width: 100% !important;
        overflow-x: hidden !important;
      }

      #wpbody-content .wrap .card,
      #wpbody-content .wrap .table-responsive,
      #wpbody-content .wrap .tab-content,
      #wpbody-content .wrap .tab-pane {
        width: 100% !important;
        max-width: 100% !important;
      }

      .therapy-scheduling-wrapper {
        width: 100%;
        max-width: 100%;
        overflow-x: hidden;
      }

      .therapy-scheduling-wrapper .card {
        border: none;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        margin-bottom: 20px;
        width: 100%;
      }

      .therapy-scheduling-wrapper .card-header {
        background: #635ba3;
        color: white;
        font-weight: 600;
      }

      .therapy-scheduling-wrapper .btn-schedule {
        background: #635ba3;
        border: none;
        color: white;
        padding: 12px 30px;
        font-weight: 600;
      }

      .therapy-scheduling-wrapper .btn-schedule:hover {
        background: #504a8a;
        color: white;
      }

      .therapy-scheduling-wrapper .subgroup-card {
        background: #f8f9fa;
        border: 1px solid #e0e0e0;
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 10px;
        cursor: pointer;
        transition: all 0.2s;
      }

      .therapy-scheduling-wrapper .subgroup-card:hover {
        border-color: #6059A6;
        background: #f0eef7;
      }

      .therapy-scheduling-wrapper .subgroup-card.selected {
        border: 2px solid #6059A6;
        background: #f0eef7;
      }

      .therapy-scheduling-wrapper .status-badge {
        font-size: 12px;
        padding: 3px 10px;
        border-radius: 12px;
      }

      .therapy-scheduling-wrapper .status-active {
        background: #28a745;
        color: white;
      }

      .therapy-scheduling-wrapper .status-inactive {
        background: #dc3545;
        color: white;
      }

      .therapy-scheduling-wrapper .day-checkbox {
        display: inline-block;
        margin-right: 10px;
        margin-bottom: 10px;
      }

      .therapy-scheduling-wrapper .day-checkbox input {
        display: none;
      }

      .therapy-scheduling-wrapper .day-checkbox label {
        cursor: pointer;
        padding: 8px 16px;
        border: 2px solid #e0e0e0;
        border-radius: 20px;
        transition: all 0.2s;
      }

      .therapy-scheduling-wrapper .day-checkbox input:checked+label {
        background: #6059A6;
        color: white;
        border-color: #6059A6;
      }

      .therapy-scheduling-wrapper .meetings-table {
        width: 100% !important;
      }

      .therapy-scheduling-wrapper .meetings-table th {
        background: #635ba3;
        color: white;
      }

      .therapy-scheduling-wrapper .zoom-link {
        max-width: 200px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        display: inline-block;
      }

      .therapy-scheduling-wrapper .loading {
        text-align: center;
        padding: 30px;
        color: #666;
      }

      .therapy-scheduling-wrapper .nav-tabs .nav-link.active {
        background-color: #635ba3;
        color: white;
        border-color: #635ba3;
      }

      .therapy-scheduling-wrapper .nav-tabs .nav-link {
        color: #635ba3;
        font-weight: 600;
      }

      .therapy-scheduling-wrapper .nav-tabs .nav-link:hover {
        border-color: #e0e0e0;
      }

      .therapy-scheduling-wrapper .revert-group-card {
        background: #fff;
        border: 1px solid #e0e0e0;
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 10px;
        transition: all 0.2s;
      }

      .therapy-scheduling-wrapper .revert-group-card:hover {
        border-color: #dc3545;
        background: #fff5f5;
      }

      .therapy-scheduling-wrapper .revert-group-card.selected {
        border: 2px solid #dc3545;
        background: #fff5f5;
      }

      .therapy-scheduling-wrapper .btn-revert {
        background: #dc3545;
        border: none;
        color: white;
        padding: 12px 30px;
        font-weight: 600;
      }

      .therapy-scheduling-wrapper .btn-revert:hover {
        background: #c82333;
        color: white;
      }

      .therapy-scheduling-wrapper .table-responsive {
        width: 100% !important;
        overflow-x: auto;
      }
    </style>

    <div class="therapy-scheduling-wrapper">
      <!-- Main Tabs -->
      <ul class="nav nav-tabs mb-4" id="schedulingTabs" role="tablist">
        <li class="nav-item">
          <a class="nav-link active" id="schedule-booking-tab" data-bs-toggle="tab" href="#scheduleBooking" role="tab">
            <i class="bi bi-calendar-plus me-1"></i> Schedule Booking
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link" id="booked-schedule-tab" data-bs-toggle="tab" href="#bookedSchedule" role="tab">
            <i class="bi bi-calendar-check me-1"></i> Booked Schedule
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link" id="revert-schedule-tab" data-bs-toggle="tab" href="#revertSchedule" role="tab">
            <i class="bi bi-arrow-counterclockwise me-1"></i> Revert Schedule
          </a>
        </li>
      </ul>

      <div class="tab-content">
        <!-- Tab 1: Schedule Booking -->
        <div class="tab-pane fade show active" id="scheduleBooking" role="tabpanel">
          <div class="card">
            <div class="card-header"><i class="bi bi-1-circle me-2"></i>Step 1: Select Issue Type and Gender</div>
            <div class="card-body">
              <div class="row">
                <div class="col-md-6">
                  <label class="form-label fw-bold">Issue Type</label>
                  <select class="form-select" id="issueSelect">
                    <option value="">-- Select Issue --</option>
                    <?php foreach ($issues as $issue): ?>
                      <option value="<?php echo esc_attr($issue); ?>"><?php echo ucfirst($issue); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label fw-bold">Gender</label>
                  <select class="form-select" id="genderSelect">
                    <option value="">-- Select Gender --</option>
                    <option value="male">Male</option>
                    <option value="female">Female</option>
                  </select>
                </div>
              </div>
            </div>
          </div>

          <div class="card" id="subgroupCard" style="display:none;">
            <div class="card-header"><i class="bi bi-2-circle me-2"></i>Step 2: Select Sub-Group (Without Existing Schedule)</div>
            <div class="card-body">
              <div id="subgroupsContainer">
                <div class="loading"><i class="bi bi-arrow-repeat"></i> Loading sub-groups...</div>
              </div>
              <input type="hidden" id="selectedGroupId">
            </div>
          </div>

          <div class="card" id="scheduleCard" style="display:none;">
            <div class="card-header"><i class="bi bi-3-circle me-2"></i>Step 3: Set Schedule Details</div>
            <div class="card-body">
              <div class="row mb-3">
                <div class="col-md-6">
                  <label class="form-label fw-bold">Start Date</label>
                  <input type="date" class="form-control" id="startDate">
                </div>
                <div class="col-md-6">
                  <label class="form-label fw-bold">End Date</label>
                  <input type="date" class="form-control" id="endDate">
                </div>
              </div>
              <div class="row mb-3">
                <div class="col-md-6">
                  <label class="form-label fw-bold">Start Time</label>
                  <input type="time" class="form-control" id="startTime" value="18:00">
                </div>
                <div class="col-md-6">
                  <label class="form-label fw-bold">End Time</label>
                  <input type="time" class="form-control" id="endTime" value="19:30">
                </div>
              </div>
              <div class="mb-3">
                <label class="form-label fw-bold">Repetition Days</label>
                <div id="repetitionDays">
                  <span class="day-checkbox"><input type="checkbox" id="daySunday" value="sunday"><label for="daySunday">Sunday</label></span>
                  <span class="day-checkbox"><input type="checkbox" id="dayMonday" value="monday"><label for="dayMonday">Monday</label></span>
                  <span class="day-checkbox"><input type="checkbox" id="dayTuesday" value="tuesday"><label for="dayTuesday">Tuesday</label></span>
                  <span class="day-checkbox"><input type="checkbox" id="dayWednesday" value="wednesday"><label for="dayWednesday">Wednesday</label></span>
                  <span class="day-checkbox"><input type="checkbox" id="dayThursday" value="thursday"><label for="dayThursday">Thursday</label></span>
                  <span class="day-checkbox"><input type="checkbox" id="dayFriday" value="friday"><label for="dayFriday">Friday</label></span>
                  <span class="day-checkbox"><input type="checkbox" id="daySaturday" value="saturday"><label for="daySaturday">Saturday</label></span>
                </div>
              </div>
              <div class="text-center mt-4">
                <button type="button" class="btn btn-schedule btn-lg" id="saveScheduleBtn">
                  <i class="bi bi-calendar-check me-2"></i>Create Schedule & Zoom Meetings
                </button>
              </div>
              <div id="scheduleResult" class="mt-3"></div>
            </div>
          </div>
        </div>

        <!-- Tab 2: Booked Schedule -->
        <div class="tab-pane fade" id="bookedSchedule" role="tabpanel">
          <div class="card">
            <div class="card-header"><i class="bi bi-calendar-week me-2"></i>Scheduled Meetings</div>
            <div class="card-body">
              <!-- Filters -->
              <div class="row mb-3">
                <div class="col-md-3">
                  <label class="form-label fw-bold">Filter by Issue</label>
                  <select class="form-select form-select-sm" id="filterIssue">
                    <option value="">All Issues</option>
                    <option value="anxiety">Anxiety</option>
                    <option value="depression">Depression</option>
                    <option value="grief">Grief</option>
                    <option value="relationship">Relationship</option>
                  </select>
                </div>
                <div class="col-md-3">
                  <label class="form-label fw-bold">Filter by Gender</label>
                  <select class="form-select form-select-sm" id="filterGender">
                    <option value="">All Genders</option>
                    <option value="male">Male</option>
                    <option value="female">Female</option>
                  </select>
                </div>
                <div class="col-md-4">
                  <label class="form-label fw-bold">Filter by Sub-Group</label>
                  <select class="form-select form-select-sm" id="filterSubGroup">
                    <option value="">All Sub-Groups</option>
                  </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                  <button class="btn btn-sm btn-outline-secondary w-100" id="clearFiltersBtn">
                    <i class="bi bi-x-circle me-1"></i>Clear
                  </button>
                </div>
              </div>

              <div class="table-responsive">
                <table class="table table-hover meetings-table">
                  <thead>
                    <tr>
                      <th>Date</th>
                      <th>Time</th>
                      <th>Issue</th>
                      <th>Gender</th>
                      <th>Group</th>
                      <th>Zoom Link</th>
                      <th>Status</th>
                      <th>Notified</th>
                      <th>Actions</th>
                    </tr>
                  </thead>
                  <tbody id="meetingsTableBody">
                    <tr>
                      <td colspan="9" class="text-center">Loading...</td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <!-- Pagination -->
              <div class="d-flex justify-content-between align-items-center mt-3">
                <div id="paginationInfo" class="text-muted small"></div>
                <nav aria-label="Meetings pagination">
                  <ul class="pagination pagination-sm mb-0" id="paginationContainer"></ul>
                </nav>
              </div>
            </div>
          </div>
        </div>

        <!-- Tab 3: Revert Schedule -->
        <div class="tab-pane fade" id="revertSchedule" role="tabpanel">
          <div class="card">
            <div class="card-header"><i class="bi bi-arrow-counterclockwise me-2"></i>Revert Schedule</div>
            <div class="card-body">
              <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <strong>Warning:</strong> Reverting a schedule will delete all scheduled meetings for the selected sub-group. The sub-group will then appear again in the Schedule Booking tab for re-scheduling.
              </div>

              <div class="row mb-4">
                <div class="col-md-6">
                  <label class="form-label fw-bold">Issue Type</label>
                  <select class="form-select" id="revertIssueSelect">
                    <option value="">-- Select Issue --</option>
                    <?php foreach ($issues as $issue): ?>
                      <option value="<?php echo esc_attr($issue); ?>"><?php echo ucfirst($issue); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label fw-bold">Gender</label>
                  <select class="form-select" id="revertGenderSelect">
                    <option value="">-- Select Gender --</option>
                    <option value="male">Male</option>
                    <option value="female">Female</option>
                  </select>
                </div>
              </div>

              <div id="revertGroupsContainer" style="display:none;">
                <h5 class="mb-3"><i class="bi bi-collection me-2"></i>Select a Sub-Group to Revert</h5>
                <div id="revertGroupsList">
                  <div class="loading"><i class="bi bi-arrow-repeat"></i> Loading...</div>
                </div>
                <input type="hidden" id="selectedRevertGroupId">
                <input type="hidden" id="selectedRevertScheduleId">

                <div id="revertActionContainer" style="display:none;" class="mt-4">
                  <div class="text-center">
                    <button type="button" class="btn btn-revert btn-lg" id="revertScheduleBtn">
                      <i class="bi bi-arrow-counterclockwise me-2"></i>Revert Schedule
                    </button>
                  </div>
                </div>
                <div id="revertResult" class="mt-3"></div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Edit Meeting Modal -->
      <div class="modal fade" id="editMeetingModal" tabindex="-1" aria-labelledby="editMeetingModalLabel" aria-hidden="true">
        <div class="modal-dialog">
          <form id="editMeetingForm">
            <div class="modal-content">
              <div class="modal-header" style="background:#635ba3; color:white;">
                <h5 class="modal-title" id="editMeetingModalLabel"><i class="bi bi-pencil me-2"></i>Edit Meeting</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body">
                <div id="editMeetingAlert"></div>
                <input type="hidden" id="editMeetingId" name="meeting_id">

                <div class="mb-3">
                  <label for="editMeetingDate" class="form-label fw-bold">Meeting Date</label>
                  <input type="date" class="form-control" id="editMeetingDate" name="meeting_date" required>
                </div>

                <div class="row">
                  <div class="col-md-6 mb-3">
                    <label for="editMeetingStartTime" class="form-label fw-bold">Start Time</label>
                    <input type="time" class="form-control" id="editMeetingStartTime" name="start_time" required>
                  </div>
                  <div class="col-md-6 mb-3">
                    <label for="editMeetingEndTime" class="form-label fw-bold">End Time</label>
                    <input type="time" class="form-control" id="editMeetingEndTime" name="end_time" required>
                  </div>
                </div>

                <div class="mb-3">
                  <label for="editZoomJoinUrl" class="form-label fw-bold">Zoom Join URL</label>
                  <input type="url" class="form-control" id="editZoomJoinUrl" name="zoom_join_url" placeholder="https://zoom.us/j/...">
                  <small class="text-muted">Leave blank to keep existing URL</small>
                </div>

                <div class="mb-3">
                  <label for="editZoomPassword" class="form-label fw-bold">Zoom Password</label>
                  <input type="text" class="form-control" id="editZoomPassword" name="zoom_password" placeholder="Meeting password">
                  <small class="text-muted">Leave blank to keep existing password</small>
                </div>

                <div class="alert alert-info small">
                  <i class="bi bi-info-circle me-1"></i>
                  After editing, use the Notify button to send updated meeting details to members.
                </div>
              </div>
              <div class="modal-footer">
                <button type="submit" class="btn btn-schedule">Save Changes</button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
              </div>
            </div>
          </form>
        </div>
      </div>

      <script>
        jQuery(document).ready(function($) {
          const ajaxUrl = '<?php echo admin_url("admin-ajax.php"); ?>';

          // Pagination variables
          let allMeetings = [];
          let currentPage = 1;
          const itemsPerPage = 15;

          // Load meetings when switching to Booked Schedule tab
          $('#booked-schedule-tab').on('shown.bs.tab', function() {
            loadScheduledMeetings();
            loadSubGroupsForFilter();
          });

          // Initial load
          loadScheduledMeetings();
          loadSubGroupsForFilter();

          // Filter change handlers
          $('#filterIssue, #filterGender, #filterSubGroup').on('change', function() {
            currentPage = 1;
            loadScheduledMeetings();
          });

          // Clear filters
          $('#clearFiltersBtn').on('click', function() {
            $('#filterIssue, #filterGender, #filterSubGroup').val('');
            currentPage = 1;
            loadScheduledMeetings();
          });

          // Load sub-groups for filter dropdown
          function loadSubGroupsForFilter() {
            $.post(ajaxUrl, {
              action: 'get_all_subgroups'
            }, function(response) {
              if (response.success && response.data.length > 0) {
                let options = '<option value="">All Sub-Groups</option>';
                response.data.forEach(function(g) {
                  options += `<option value="${g.id}">${g.title}</option>`;
                });
                $('#filterSubGroup').html(options);
              }
            });
          }

          // Schedule Booking Tab Logic
          $('#issueSelect, #genderSelect').on('change', function() {
            const issue = $('#issueSelect').val();
            const gender = $('#genderSelect').val();
            if (issue && gender) {
              loadUnscheduledSubgroups(issue, gender);
            } else {
              $('#subgroupCard, #scheduleCard').hide();
            }
          });

          function loadUnscheduledSubgroups(issue, gender) {
            $('#subgroupCard').show();
            $('#scheduleCard').hide();
            $('#subgroupsContainer').html('<div class="loading"><i class="bi bi-arrow-repeat"></i> Loading...</div>');

            $.post(ajaxUrl, {
              action: 'get_unscheduled_subgroups',
              issue: issue,
              gender: gender
            }, function(response) {
              if (response.success && response.data.length > 0) {
                let html = '';
                response.data.forEach(function(group) {
                  const statusClass = group.status === 'active' ? 'status-active' : 'status-inactive';
                  html += `<div class="subgroup-card" data-group-id="${group.id}" data-session-start="${group.session_start_date}" data-session-expiry="${group.session_expiry_date}">
                  <div class="d-flex justify-content-between align-items-center">
                    <div><strong>Sub Group ${group.group_number}</strong> <span class="text-muted">(Max: ${group.max_members})</span></div>
                    <span class="status-badge ${statusClass}">${group.status}</span>
                  </div>
                </div>`;
                });
                $('#subgroupsContainer').html(html);
                $('.subgroup-card').on('click', function() {
                  $('.subgroup-card').removeClass('selected');
                  $(this).addClass('selected');
                  $('#selectedGroupId').val($(this).data('group-id'));

                  // Auto-fill start and end dates from session dates
                  const sessionStart = $(this).data('session-start');
                  const sessionExpiry = $(this).data('session-expiry');

                  if (sessionStart) {
                    // Convert date to YYYY-MM-DD format if needed
                    const startDate = new Date(sessionStart);
                    if (!isNaN(startDate.getTime())) {
                      $('#startDate').val(startDate.toISOString().split('T')[0]);
                    }
                  }

                  if (sessionExpiry) {
                    // Convert date to YYYY-MM-DD format if needed
                    const expiryDate = new Date(sessionExpiry);
                    if (!isNaN(expiryDate.getTime())) {
                      $('#endDate').val(expiryDate.toISOString().split('T')[0]);
                    }
                  }

                  $('#scheduleCard').show();
                });
              } else {
                $('#subgroupsContainer').html('<div class="alert alert-info">No sub-groups without schedules found.</div>');
              }
            });
          }

          $('#saveScheduleBtn').on('click', function() {
            const groupId = $('#selectedGroupId').val();
            const issue = $('#issueSelect').val();
            const gender = $('#genderSelect').val();
            const startDate = $('#startDate').val();
            const endDate = $('#endDate').val();
            const startTime = $('#startTime').val();
            const endTime = $('#endTime').val();
            const repetitionDays = [];
            $('#repetitionDays input:checked').each(function() {
              repetitionDays.push($(this).val());
            });

            if (!groupId || !startDate || !endDate || !startTime || !endTime || repetitionDays.length === 0) {
              alert('Please fill all fields and select at least one day.');
              return;
            }

            const $btn = $(this);
            $btn.prop('disabled', true).html('<i class="bi bi-arrow-repeat"></i> Creating...');

            $.post(ajaxUrl, {
              action: 'save_therapy_schedule',
              group_id: groupId,
              issue_type: issue,
              gender: gender,
              start_date: startDate,
              end_date: endDate,
              start_time: startTime,
              end_time: endTime,
              'repetition_days[]': repetitionDays
            }, function(response) {
              $btn.prop('disabled', false).html('<i class="bi bi-calendar-check me-2"></i>Create Schedule & Zoom Meetings');

              if (response.success) {
                let html = '<div class="alert alert-success">' + response.data.message + '</div>';
                if (response.data.zoom_errors && response.data.zoom_errors.length > 0) {
                  html += '<div class="alert alert-warning"><strong>Some Zoom errors:</strong><ul>';
                  response.data.zoom_errors.forEach(err => html += '<li>' + err + '</li>');
                  html += '</ul></div>';
                }
                $('#scheduleResult').html(html);

                // Reload page after 1.5 seconds to show updated data
                setTimeout(function() {
                  location.reload();
                }, 1500);
              } else {
                $('#scheduleResult').html('<div class="alert alert-danger">' + response.data + '</div>');
              }
            });
          });

          // Booked Schedule Tab Logic
          function loadScheduledMeetings() {
            $('#meetingsTableBody').html('<tr><td colspan="9" class="text-center"><i class="bi bi-arrow-repeat"></i> Loading...</td></tr>');

            $.post(ajaxUrl, {
              action: 'get_therapy_scheduled_meetings',
              filter_issue: $('#filterIssue').val(),
              filter_gender: $('#filterGender').val(),
              filter_group: $('#filterSubGroup').val()
            }, function(response) {
              if (response.success) {
                allMeetings = response.data || [];
                renderMeetingsTable();
              } else {
                $('#meetingsTableBody').html('<tr><td colspan="9" class="text-center text-danger">Error loading meetings: ' + (response.data || 'Unknown error') + '</td></tr>');
              }
            }).fail(function(jqXHR, textStatus, errorThrown) {
              $('#meetingsTableBody').html('<tr><td colspan="9" class="text-center text-danger">AJAX Error: ' + textStatus + '</td></tr>');
            });
          }

          function renderMeetingsTable() {
            if (allMeetings.length === 0) {
              $('#meetingsTableBody').html('<tr><td colspan="9" class="text-center text-muted">No scheduled meetings found.</td></tr>');
              $('#paginationInfo').html('');
              $('#paginationContainer').html('');
              return;
            }

            // Calculate pagination
            const totalPages = Math.ceil(allMeetings.length / itemsPerPage);
            const startIndex = (currentPage - 1) * itemsPerPage;
            const endIndex = Math.min(startIndex + itemsPerPage, allMeetings.length);
            const paginatedMeetings = allMeetings.slice(startIndex, endIndex);

            let html = '';
            const today = new Date().toISOString().split('T')[0];

            paginatedMeetings.forEach(function(m) {
              const isPast = m.meeting_date < today;
              const rowClass = isPast ? 'table-secondary' : '';
              const date = new Date(m.meeting_date).toLocaleDateString('en-US', {
                weekday: 'short',
                year: 'numeric',
                month: 'short',
                day: 'numeric'
              });

              // Notification badge with count
              let notifiedBadge = '';
              const notifyCount = parseInt(m.notification_count) || 0;
              if (m.notification_sent == 1) {
                notifiedBadge = `<span class="badge bg-success">Yes (${notifyCount}x)</span>`;
              } else {
                notifiedBadge = '<span class="badge bg-secondary">No</span>';
              }

              const zoomLink = m.zoom_join_url ? `<a href="${m.zoom_join_url}" target="_blank" class="zoom-link" title="${m.zoom_join_url}">Join Link</a>` : '<span class="text-muted">N/A</span>';
              const issueType = m.issue_type ? m.issue_type.charAt(0).toUpperCase() + m.issue_type.slice(1) : 'N/A';
              const gender = m.gender ? m.gender.charAt(0).toUpperCase() + m.gender.slice(1) : 'N/A';

              html += `<tr class="${rowClass}">
                <td>${date}${isPast ? ' <small class="text-muted">(Past)</small>' : ''}</td>
                <td>${m.start_time.substring(0,5)} - ${m.end_time.substring(0,5)}</td>
                <td>${issueType}</td>
                <td>${gender}</td>
                <td>Sub Group ${m.group_number || 'N/A'}</td>
                <td>${zoomLink}</td>
                <td><span class="badge bg-info">${m.meeting_status}</span></td>
                <td>${notifiedBadge}</td>
                <td>
                  <div class="btn-group btn-group-sm">
                    <button class="btn btn-outline-primary send-notification-btn" data-id="${m.id}" title="Send/Resend Notification">
                      <i class="bi bi-envelope"></i>
                    </button>
                    <button class="btn btn-outline-secondary edit-meeting-btn" data-id="${m.id}" 
                            data-date="${m.meeting_date}" 
                            data-start="${m.start_time.substring(0,5)}" 
                            data-end="${m.end_time.substring(0,5)}"
                            data-zoom-url="${m.zoom_join_url || ''}"
                            data-zoom-password="${m.zoom_password || ''}"
                            title="Edit Meeting">
                      <i class="bi bi-pencil"></i>
                    </button>
                    <button class="btn btn-outline-danger delete-meeting-btn" data-id="${m.id}" title="Delete Meeting">
                      <i class="bi bi-trash"></i>
                    </button>
                  </div>
                </td>
              </tr>`;
            });

            $('#meetingsTableBody').html(html);

            // Update pagination info
            $('#paginationInfo').html(`Showing ${startIndex + 1}-${endIndex} of ${allMeetings.length} meetings`);

            // Render pagination
            renderPagination(totalPages);

            // Bind event handlers
            bindMeetingActions();
          }

          function renderPagination(totalPages) {
            if (totalPages <= 1) {
              $('#paginationContainer').html('');
              return;
            }

            let paginationHtml = '';

            // Previous button
            paginationHtml += `<li class="page-item ${currentPage === 1 ? 'disabled' : ''}">
              <a class="page-link" href="#" data-page="${currentPage - 1}">«</a>
            </li>`;

            // Page numbers
            for (let i = 1; i <= totalPages; i++) {
              if (i === 1 || i === totalPages || (i >= currentPage - 2 && i <= currentPage + 2)) {
                paginationHtml += `<li class="page-item ${i === currentPage ? 'active' : ''}">
                  <a class="page-link" href="#" data-page="${i}">${i}</a>
                </li>`;
              } else if (i === currentPage - 3 || i === currentPage + 3) {
                paginationHtml += '<li class="page-item disabled"><span class="page-link">...</span></li>';
              }
            }

            // Next button
            paginationHtml += `<li class="page-item ${currentPage === totalPages ? 'disabled' : ''}">
              <a class="page-link" href="#" data-page="${currentPage + 1}">»</a>
            </li>`;

            $('#paginationContainer').html(paginationHtml);

            // Bind pagination click
            $('#paginationContainer .page-link').on('click', function(e) {
              e.preventDefault();
              const page = parseInt($(this).data('page'));
              if (page >= 1 && page <= totalPages && page !== currentPage) {
                currentPage = page;
                renderMeetingsTable();
              }
            });
          }

          function bindMeetingActions() {
            // Send notification
            $('.send-notification-btn').off('click').on('click', function() {
              const $btn = $(this);
              const meetingId = $btn.data('id');
              if (!confirm('Send notification emails to all group members?')) return;
              $btn.prop('disabled', true).html('<i class="bi bi-arrow-repeat"></i>');
              $.post(ajaxUrl, {
                action: 'send_therapy_notification_now',
                meeting_id: meetingId
              }, function(response) {
                if (response.success) {
                  $btn.html('<i class="bi bi-check"></i>');
                  setTimeout(function() {
                    loadScheduledMeetings();
                  }, 500);
                } else {
                  $btn.prop('disabled', false).html('<i class="bi bi-envelope"></i>');
                  alert('Failed: ' + response.data);
                }
              });
            });

            // Edit meeting
            $('.edit-meeting-btn').off('click').on('click', function() {
              const meetingId = $(this).data('id');
              const meetingDate = $(this).data('date');
              const startTime = $(this).data('start');
              const endTime = $(this).data('end');
              const zoomUrl = $(this).data('zoom-url') || '';
              const zoomPassword = $(this).data('zoom-password') || '';

              $('#editMeetingId').val(meetingId);
              $('#editMeetingDate').val(meetingDate);
              $('#editMeetingStartTime').val(startTime);
              $('#editMeetingEndTime').val(endTime);
              $('#editZoomJoinUrl').val(zoomUrl);
              $('#editZoomPassword').val(zoomPassword);
              $('#editMeetingAlert').html('');

              const modal = new bootstrap.Modal(document.getElementById('editMeetingModal'));
              modal.show();
            });

            // Delete meeting
            $('.delete-meeting-btn').off('click').on('click', function() {
              const $btn = $(this);
              const meetingId = $btn.data('id');

              if (!confirm('Are you sure you want to delete this meeting?\n\nThis action cannot be undone.')) return;

              $btn.prop('disabled', true).html('<i class="bi bi-arrow-repeat"></i>');

              $.post(ajaxUrl, {
                action: 'delete_therapy_meeting',
                meeting_id: meetingId
              }, function(response) {
                if (response.success) {
                  loadScheduledMeetings();
                } else {
                  $btn.prop('disabled', false).html('<i class="bi bi-trash"></i>');
                  alert('Failed: ' + response.data);
                }
              });
            });
          }

          // Edit meeting form submit
          $('#editMeetingForm').on('submit', function(e) {
            e.preventDefault();
            const $form = $(this);
            const $btn = $form.find('button[type="submit"]');

            $btn.prop('disabled', true).html('<i class="bi bi-arrow-repeat me-1"></i>Saving...');

            $.post(ajaxUrl, {
              action: 'edit_therapy_meeting',
              meeting_id: $('#editMeetingId').val(),
              meeting_date: $('#editMeetingDate').val(),
              start_time: $('#editMeetingStartTime').val(),
              end_time: $('#editMeetingEndTime').val(),
              zoom_join_url: $('#editZoomJoinUrl').val(),
              zoom_password: $('#editZoomPassword').val()
            }, function(response) {
              $btn.prop('disabled', false).html('Save Changes');

              if (response.success) {
                $('#editMeetingAlert').html('<div class="alert alert-success">' + response.data + '</div>');
                setTimeout(function() {
                  bootstrap.Modal.getInstance(document.getElementById('editMeetingModal')).hide();
                  loadScheduledMeetings();
                }, 1000);
              } else {
                $('#editMeetingAlert').html('<div class="alert alert-danger">' + response.data + '</div>');
              }
            });
          });

          // Revert Schedule Tab Logic
          $('#revertIssueSelect, #revertGenderSelect').on('change', function() {
            const issue = $('#revertIssueSelect').val();
            const gender = $('#revertGenderSelect').val();
            if (issue && gender) {
              loadScheduledSubgroups(issue, gender);
            } else {
              $('#revertGroupsContainer').hide();
            }
          });

          function loadScheduledSubgroups(issue, gender) {
            $('#revertGroupsContainer').show();
            $('#revertActionContainer').hide();
            $('#revertGroupsList').html('<div class="loading"><i class="bi bi-arrow-repeat"></i> Loading...</div>');

            $.post(ajaxUrl, {
              action: 'get_scheduled_subgroups',
              issue: issue,
              gender: gender
            }, function(response) {
              if (response.success && response.data.length > 0) {
                let html = '';
                response.data.forEach(function(group) {
                  const statusClass = group.status === 'active' ? 'status-active' : 'status-inactive';
                  html += `<div class="revert-group-card" data-group-id="${group.id}" data-schedule-id="${group.schedule_id}">
                  <div class="d-flex justify-content-between align-items-center">
                    <div>
                      <strong>Sub Group ${group.group_number}</strong> 
                      <span class="text-muted">(Max: ${group.max_members})</span>
                    </div>
                    <span class="status-badge ${statusClass}">${group.status}</span>
                  </div>
                  <div class="mt-2 small text-muted">
                    <i class="bi bi-calendar me-1"></i> Schedule: ${group.start_date} to ${group.end_date}
                    <span class="ms-3"><i class="bi bi-clock me-1"></i> ${group.start_time} - ${group.end_time}</span>
                    <span class="ms-3"><i class="bi bi-collection me-1"></i> ${group.meetings_count} meeting(s)</span>
                  </div>
                </div>`;
                });
                $('#revertGroupsList').html(html);

                $('.revert-group-card').on('click', function() {
                  $('.revert-group-card').removeClass('selected');
                  $(this).addClass('selected');
                  $('#selectedRevertGroupId').val($(this).data('group-id'));
                  $('#selectedRevertScheduleId').val($(this).data('schedule-id'));
                  $('#revertActionContainer').show();
                });
              } else {
                $('#revertGroupsList').html('<div class="alert alert-info">No scheduled sub-groups found for this issue and gender.</div>');
              }
            });
          }

          $('#revertScheduleBtn').on('click', function() {
            const groupId = $('#selectedRevertGroupId').val();
            const scheduleId = $('#selectedRevertScheduleId').val();

            if (!groupId || !scheduleId) {
              alert('Please select a sub-group to revert.');
              return;
            }

            if (!confirm('Are you sure you want to revert this schedule? All scheduled meetings for this sub-group will be deleted.')) {
              return;
            }

            const $btn = $(this);
            $btn.prop('disabled', true).html('<i class="bi bi-arrow-repeat"></i> Reverting...');

            $.post(ajaxUrl, {
              action: 'revert_therapy_schedule',
              group_id: groupId,
              schedule_id: scheduleId
            }, function(response) {
              $btn.prop('disabled', false).html('<i class="bi bi-arrow-counterclockwise me-2"></i>Revert Schedule');

              if (response.success) {
                $('#revertResult').html('<div class="alert alert-success">' + response.data.message + '</div>');
                $('#selectedRevertGroupId').val('');
                $('#selectedRevertScheduleId').val('');
                $('.revert-group-card').removeClass('selected');
                $('#revertActionContainer').hide();

                // Reload the list
                const issue = $('#revertIssueSelect').val();
                const gender = $('#revertGenderSelect').val();
                loadScheduledSubgroups(issue, gender);
                loadScheduledMeetings();
              } else {
                $('#revertResult').html('<div class="alert alert-danger">' + response.data + '</div>');
              }
            });
          });
        });
      </script>
    </div><!-- end therapy-scheduling-wrapper -->
  </div>
<?php
}

// ============================================================================
// BUDDYPRESS GROUP MANAGEMENT FUNCTIONS
// Moved from therapy-buddypress-automation.php
// ============================================================================

/**
 * Update BuddyPress group description with expiry date
 */
function update_bp_group_description($therapy_group_id, $bp_group_id) {
    if (!function_exists('groups_get_group')) {
        return;
    }
    
    // Get updated session_expiry_date
    $session_expiry = '';
    if (function_exists('get_field')) {
        $session_expiry = get_field('session_expiry_date', $therapy_group_id);
    }
    if (empty($session_expiry)) {
        $session_expiry = get_post_meta($therapy_group_id, 'session_expiry_date', true);
    }
    
    if (!empty($session_expiry)) {
        $expiry_date_obj = DateTime::createFromFormat('Y-m-d', $session_expiry);
        if (!$expiry_date_obj) {
            $expiry_date_obj = DateTime::createFromFormat('Ymd', $session_expiry);
        }
        if (!$expiry_date_obj && strtotime($session_expiry)) {
            $expiry_date_obj = new DateTime($session_expiry);
        }
        
        if ($expiry_date_obj) {
            $expiry_date_obj->modify('+1 day');
            $expiry_notice_date = $expiry_date_obj->format('Y-m-d');
            $new_description = 'This group will expire on ' . $expiry_notice_date . '.';
            
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
                groups_update_groupmeta($bp_group_id, '_tbc_expiry_date', $session_expiry);
                error_log("[BP Update] ✓ Updated BP group {$bp_group_id} description");
            }
        }
    }
}

// AJAX handler for user search in manual add metabox
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

// AJAX handler for manual user add to BP group
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
    
    $bp_group_id = get_post_meta($therapy_group_id, '_tbc_bp_group_id', true);
    
    if (!$bp_group_id) {
        wp_send_json_error('No BuddyPress group exists for this therapy group');
    }
    
    if (!function_exists('groups_join_group')) {
        wp_send_json_error('BuddyPress is not active');
    }
    
    if (function_exists('groups_is_user_member')) {
        if (groups_is_user_member($user_id, $bp_group_id)) {
            wp_send_json_error('User is already a member');
        }
    }
    
    $joined = groups_join_group($bp_group_id, $user_id);
    
    if ($joined) {
        update_user_meta($user_id, 'assigned_group', $therapy_group_id);
        update_user_meta($user_id, '_tbc_bp_group_id', $bp_group_id);
        update_user_meta($user_id, '_tbc_manually_added', current_time('mysql'));
        
        error_log("[BP Manual] Admin added user {$user_id} to BP group {$bp_group_id}");
        wp_send_json_success('User added successfully');
    } else {
        wp_send_json_error('Failed to add user to group');
    }
}

// Add manual enrollment metabox to therapy group edit screen
add_action('add_meta_boxes', 'add_bp_group_member_metabox');

function add_bp_group_member_metabox() {
    add_meta_box(
        'bp_group_members',
        'BuddyPress Chat Group Members',
        'render_bp_group_members_metabox',
        'therapy_group',
        'side',
        'default'
    );
}

function render_bp_group_members_metabox($post) {
    $bp_group_id = get_post_meta($post->ID, '_tbc_bp_group_id', true);
    
    if (!$bp_group_id) {
        echo '<p>No BuddyPress group created yet. Save this therapy group to create one.</p>';
        return;
    }
    
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
    echo '<p><strong>Status:</strong> ' . esc_html($bp_group->status) . '</p>';
    
    if (function_exists('groups_get_total_member_count')) {
        $member_count = groups_get_total_member_count($bp_group_id);
        echo '<p><strong>Members:</strong> ' . $member_count . '</p>';
    }
    
    $bp_admin_url = admin_url('admin.php?page=bp-groups&gid=' . $bp_group_id);
    echo '<p><a href="' . esc_url($bp_admin_url) . '" class="button" target="_blank">Manage Group in BuddyPress</a></p>';
    
    echo '<hr>';
    echo '<h4>Manually Add User</h4>';
    echo '<div id="tbc-manual-add">';
    echo '<input type="text" id="tbc-user-search" placeholder="Search username or email..." style="width:100%; margin-bottom:8px;">';
    echo '<div id="tbc-user-results"></div>';
    echo '<button type="button" id="tbc-add-user-btn" class="button button-secondary" style="width:100%; margin-top:8px;" disabled>Add to Chat Group</button>';
    echo '</div>';
    
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

// ============================================================================
// ACF HOOK INTEGRATION FOR BUDDYPRESS GROUP CREATION
// ============================================================================

/**
 * Create or update BuddyPress group AFTER ACF has saved all fields
 * This ensures all ACF field data is available during BP group creation
 * 
 * Triggered by: acf/save_post (fires AFTER ACF saves fields)
 */
add_action('acf/save_post', 'tbc_acf_save_post', 20);

function tbc_acf_save_post($post_id) {
    // Check if this is a therapy_group post
    if (get_post_type($post_id) !== 'therapy_group') {
        return;
    }
    
    // Only proceed if post is published
    $post = get_post($post_id);
    if (!$post || $post->post_status !== 'publish') {
        return;
    }
    
    // Check if BP group already exists
    $existing_bp_group_id = get_post_meta($post_id, '_tbc_bp_group_id', true);
    
    if (!$existing_bp_group_id) {
        // BP group doesn't exist - create it now (ACF fields are NOW available)
        error_log("[TBC] ACF fields saved, creating BP group for therapy_group {$post_id}");
        
        // Get the expiry date from the just-saved ACF field
        $session_expiry = function_exists('get_field') ? get_field('session_expiry_date', $post_id) : get_post_meta($post_id, 'session_expiry_date', true);
        error_log("[TBC] Got session_expiry_date after ACF save: '{$session_expiry}'");
        
        create_buddypress_group_for_therapy($post_id, $post, $session_expiry);
    } else {
        // BP group exists - update description if expiry date changed
        error_log("[TBC] ACF fields updated for therapy_group {$post_id}, updating BP group {$existing_bp_group_id}");
        update_bp_group_description($post_id, $existing_bp_group_id);
    }
}

/**
 * FALLBACK: Also hook into save_post with low priority
 * This ensures BP group is created even if ACF hook doesn't fire (e.g., programmatic creation)
 * Priority 999 ensures this runs AFTER ACF saves fields
 */
add_action('save_post_therapy_group', 'tbc_fallback_create_bp_group', 999, 3);

function tbc_fallback_create_bp_group($therapy_group_id, $post, $update) {
    // Skip if autosave or revision
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    
    // Skip if not published
    if ($post->post_status !== 'publish') {
        return;
    }
    
    // Check if BP group already exists
    $existing_bp_group_id = get_post_meta($therapy_group_id, '_tbc_bp_group_id', true);
    
    if (!$existing_bp_group_id) {
        // BP group doesn't exist - create it
        error_log("[TBC] Fallback: Creating BP group for therapy_group {$therapy_group_id}");
        
        // Try to get expiry date from post meta (ACF should have saved it by now due to priority 999)
        $session_expiry = get_post_meta($therapy_group_id, 'session_expiry_date', true);
        if (empty($session_expiry) && function_exists('get_field')) {
            $session_expiry = get_field('session_expiry_date', $therapy_group_id);
        }
        error_log("[TBC] Fallback: Got session_expiry_date: '{$session_expiry}'");
        
        create_buddypress_group_for_therapy($therapy_group_id, $post, $session_expiry);
    }
}

// ============================================================================
// CRON JOB FOR EXPIRED GROUP CLEANUP
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
// ADMIN NOTIFICATIONS & DIAGNOSTICS
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
// MANUAL REPAIR TOOLS
// ============================================================================

/**
 * Shortcode to manually repair BP group descriptions
 * Usage: [repair_bp_groups]
 */
add_shortcode('repair_bp_groups', 'tbc_repair_bp_groups_shortcode');

function tbc_repair_bp_groups_shortcode() {
    // Check admin permission
    if (!current_user_can('manage_options')) {
        return '<p style="color: red;">⛔ You need administrator permission to use this tool.</p>';
    }
    
    ob_start();
    
    // Handle repair action
    if (isset($_POST['tbc_repair_all']) && check_admin_referer('tbc_repair_groups', 'tbc_repair_nonce')) {
        $repaired = tbc_repair_all_bp_groups();
        echo '<div style="background: #d4edda; border: 2px solid #28a745; padding: 15px; margin: 20px 0; border-radius: 5px;">';
        echo '<h3 style="margin-top: 0; color: #155724;">✅ Repair Complete</h3>';
        echo '<p><strong>Groups Repaired:</strong> ' . $repaired . '</p>';
        echo '</div>';
    }
    
    // Get all therapy groups with BP groups
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
    
    echo '<div style="background: #f8f9fa; padding: 20px; margin: 20px 0; border: 2px solid #333; border-radius: 5px;">';
    echo '<h2 style="margin-top: 0;">🔧 Repair BuddyPress Group Descriptions</h2>';
    echo '<p>This tool will update all BuddyPress group descriptions with the correct expiry dates.</p>';
    
    if (empty($therapy_groups)) {
        echo '<p style="color: orange;">⚠️ No therapy groups with BuddyPress groups found.</p>';
    } else {
        echo '<p><strong>Found ' . count($therapy_groups) . ' therapy group(s) with BP groups</strong></p>';
        
        // Show table of groups
        echo '<table style="width: 100%; border-collapse: collapse; background: white; margin: 20px 0;">';
        echo '<thead><tr style="background: #333; color: white;">';
        echo '<th style="padding: 10px; text-align: left;">Therapy Group</th>';
        echo '<th style="padding: 10px; text-align: left;">BP Group ID</th>';
        echo '<th style="padding: 10px; text-align: left;">Current Description</th>';
        echo '<th style="padding: 10px; text-align: left;">Session Expiry</th>';
        echo '<th style="padding: 10px; text-align: left;">Status</th>';
        echo '</tr></thead><tbody>';
        
        foreach ($therapy_groups as $tg) {
            $bp_group_id = get_post_meta($tg->ID, '_tbc_bp_group_id', true);
            $session_expiry = get_field('session_expiry_date', $tg->ID);
            if (empty($session_expiry)) {
                $session_expiry = get_post_meta($tg->ID, 'session_expiry_date', true);
            }
            
            $bp_group = function_exists('groups_get_group') ? groups_get_group($bp_group_id) : null;
            $current_desc = $bp_group ? $bp_group->description : 'N/A';
            
            $needs_repair = $current_desc === 'Therapy group chat.';
            
            echo '<tr style="background: ' . ($needs_repair ? '#fff3cd' : 'white') . ';">';
            echo '<td style="padding: 8px; border: 1px solid #ddd;">' . esc_html($tg->post_title) . ' <small>(ID: ' . $tg->ID . ')</small></td>';
            echo '<td style="padding: 8px; border: 1px solid #ddd;">' . esc_html($bp_group_id) . '</td>';
            echo '<td style="padding: 8px; border: 1px solid #ddd;">' . esc_html($current_desc) . '</td>';
            echo '<td style="padding: 8px; border: 1px solid #ddd;">' . ($session_expiry ? esc_html($session_expiry) : '<span style="color: red;">MISSING</span>') . '</td>';
            echo '<td style="padding: 8px; border: 1px solid #ddd;">';
            if ($needs_repair) {
                echo '<span style="color: orange; font-weight: bold;">⚠️ NEEDS REPAIR</span>';
            } else {
                echo '<span style="color: green;">✅ OK</span>';
            }
            echo '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
        
        // Repair button
        echo '<form method="post" style="margin-top: 20px;">';
        wp_nonce_field('tbc_repair_groups', 'tbc_repair_nonce');
        echo '<button type="submit" name="tbc_repair_all" class="button button-primary" style="padding: 10px 20px; font-size: 16px; background: #28a745; border-color: #28a745;">';
        echo '🔧 Repair All Groups';
        echo '</button>';
        echo '<p style="color: #666; font-size: 12px; margin-top: 10px;">This will update all BuddyPress group descriptions with correct expiry dates.</p>';
        echo '</form>';
    }
    
    echo '</div>';
    
    return ob_get_clean();
}

/**
 * Repair all BP group descriptions
 */
function tbc_repair_all_bp_groups() {
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
    
    $repaired_count = 0;
    
    foreach ($therapy_groups as $tg) {
        $bp_group_id = get_post_meta($tg->ID, '_tbc_bp_group_id', true);
        if (!$bp_group_id) {
            continue;
        }
        
        // Update description
        update_bp_group_description($tg->ID, $bp_group_id);
        $repaired_count++;
    }
    
    error_log("[TBC] Manual repair completed: {$repaired_count} groups repaired");
    return $repaired_count;
}

// ============================================================================
// BUDDYPRESS UTILITY FUNCTIONS
// ============================================================================

/**
 * Get BuddyPress group ID from therapy group ID
 */
if (!function_exists('tbc_get_bp_group_id')) {
    function tbc_get_bp_group_id($therapy_group_id) {
        return get_post_meta($therapy_group_id, '_tbc_bp_group_id', true);
    }
}

/**
 * Get therapy group ID from BuddyPress group ID
 */
if (!function_exists('tbc_get_therapy_group_id')) {
    function tbc_get_therapy_group_id($bp_group_id) {
        if (!function_exists('groups_get_groupmeta')) {
            return false;
        }
        return groups_get_groupmeta($bp_group_id, '_tbc_therapy_group_id', true);
    }
}

/**
 * Check if user is enrolled in therapy group's chat
 */
if (!function_exists('tbc_is_user_enrolled')) {
    function tbc_is_user_enrolled($user_id, $therapy_group_id) {
        $bp_group_id = tbc_get_bp_group_id($therapy_group_id);
        
        if (!$bp_group_id || !function_exists('groups_is_user_member')) {
            return false;
        }
        
        return groups_is_user_member($user_id, $bp_group_id);
    }
}
