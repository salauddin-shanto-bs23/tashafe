<?php
add_action('init', function () {
    if (!session_id()) {
        session_start();
    }
}, 1);
add_action('template_redirect', function () {
    if (!session_id()) {
        session_start();
    }

    // Redirect /ar/register-2/ to /ar/register-arabic/
    $current_url = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($current_url, '/ar/register-2') !== false || strpos($current_url, '/ar/register-2/') !== false) {
        wp_safe_redirect(home_url('/ar/register-arabic/'));
        exit;
    }

    // Match any page ending with -assessment or -assessment-arabic
    if (is_page()) {
        $page_slug = get_post_field('post_name', get_post());

        if (preg_match('/-assessment(?:-arabic)?(?:-\d+)?$/', $page_slug)) {
            if (isset($_GET['issue']) && isset($_GET['gender'])) {
                $issue  = sanitize_text_field($_GET['issue']);
                $gender = sanitize_text_field($_GET['gender']);

                $_SESSION['issue']  = $issue;
                $_SESSION['gender'] = $gender;

                if ($issue === 'relationship') {
                    $_SESSION['user_concern_type'] = 'relationship';
                    error_log("Auto-set user_concern_type to relationship");
                }
                error_log("Session set from query params: issue={$issue} gender={$gender}");
            } else {
                error_log("Query params missing on assessment page.");
            }
        }

        // Capture selected group ID on any page when provided in URL (supports session_period alias)
        $param_group_id = 0;
        if (isset($_GET['group_id'])) {
            $param_group_id = intval($_GET['group_id']);
        } elseif (isset($_GET['session_period'])) {
            $param_group_id = intval($_GET['session_period']);
        }

        if ($param_group_id > 0) {
            $_SESSION['selected_group_id'] = $param_group_id;
            error_log("Session set selected_group_id: {$param_group_id}");
        }
    }



    // Handle post-registration redirect (Therapy Group only)
    // Works for both auto-login and non-auto-login scenarios
    // Skip redirect if on retreat pages or AJAX requests
    if (!empty($_SESSION['just_registered'])) {
        // Check if we're on a retreat page - don't redirect for retreat registrations
        $current_url = $_SERVER['REQUEST_URI'] ?? '';
        $is_retreat_page = (strpos($current_url, 'retreat') !== false);
        $is_ajax = defined('DOING_AJAX') && DOING_AJAX;

        if (!$is_retreat_page && !$is_ajax) {
            unset($_SESSION['just_registered']);

            $lang = function_exists('pll_current_language') ? pll_current_language() : 'en';
            $redirect_url = ($lang === 'ar') ? home_url('/ar/thank-you-arabic') : home_url('/thank-you');

            wp_safe_redirect($redirect_url);
            exit;
        }
    }
});


// add_action('wpcf7_mail_sent', function ($contact_form) {

//     $submission = WPCF7_Submission::get_instance();
//     if (!$submission) {
//         error_log('No submission instance.');
//         return;
//     }

//     $posted_data = $submission->get_posted_data();




//     if (isset($posted_data['concern_type'])) {
//         $_SESSION['user_concern_type'] = strtolower(trim($posted_data['concern_type']));
//     } else {
//         $_SESSION['user_concern_type'] = '';
//         error_log('Concern type not found in submitted data.');
//     }

//     $_SESSION['posted_data'] = $posted_data;
// });


add_action('wpcf7_mail_sent', function ($contact_form) {
    $submission = WPCF7_Submission::get_instance();
    if (!$submission) {
        error_log('No submission instance.');
        return;
    }

    $posted_data = $submission->get_posted_data();
    $_SESSION['posted_data'] = $posted_data;

    if (isset($posted_data['concern_type'])) {
        $_SESSION['user_concern_type'] = strtolower(trim($posted_data['concern_type']));
    } else {
        $_SESSION['user_concern_type'] = '';
        error_log('Concern type not found in submitted data.');
    }

    // ✅ Now update session values AFTER posted_data is set
    update_session_values();
});



function update_session_values()
{

    if (!isset($_SESSION['posted_data']) || !is_array($_SESSION['posted_data'])) {
        return; // nothing to update
    }
    $posted_data = $_SESSION['posted_data'];

    $yes_count = 0;
    $yes_values = ['yes', 'نعم'];

    foreach ($posted_data as $key => $value) {
        if (preg_match('/^a_q[1-9][0-9]*$/', $key)) {
            $answer = is_array($value) ? implode(', ', $value) : trim($value);
            $normalized = function_exists('mb_strtolower') ? mb_strtolower($answer) : strtolower($answer); // Arabic safe lowercase

            if (in_array($normalized, $yes_values)) {
                $yes_count++;
            }
        } else if (preg_match('/^d_q[1-9][0-9]*$/', $key)) {
            $answer = is_array($value) ? implode(', ', $value) : trim($value);
            $normalized = function_exists('mb_strtolower') ? mb_strtolower($answer) : strtolower($answer); // Arabic safe lowercase

            if (in_array($normalized, $yes_values)) {
                $yes_count++;
            }
        } else if (preg_match('/^g_q[1-9][0-9]*$/', $key)) {
            $answer = is_array($value) ? implode(', ', $value) : trim($value);
            $normalized = function_exists('mb_strtolower') ? mb_strtolower($answer) : strtolower($answer); // Arabic safe lowercase

            if (in_array($normalized, $yes_values)) {
                $yes_count++;
            }
        }
    }
    $submitted_concern_type = isset($posted_data['concern_type']) ? strtolower(trim($posted_data['concern_type'])) : '';

    // Logic: set concern type in session only if yes_count >= 3
    if ($yes_count >= 3 && $submitted_concern_type) {
        $_SESSION['user_concern_type'] = $submitted_concern_type;
        $_SESSION['assessment_passed'] = 'true';
    } else {
        $_SESSION['user_concern_type'] = '';
        $_SESSION['assessment_passed'] = 'false';
    }
}

add_action('um_registration_complete', function ($user_id, $args) {
    error_log("UM registration complete for user ID: $user_id");

    // Only proceed if this is an actual UM form submission (not wp_create_user from other sources)
    // Check if we have UM-specific data or posted_data from assessment
    if (empty($_SESSION['posted_data']) && empty($args)) {
        error_log("Skipping UM registration complete - not a UM form submission");
        return;
    };

    if (isset($_SESSION['posted_data']) && is_array($_SESSION['posted_data'])) {
        update_session_values();
    }

    // Save concern type - priority: user_concern_type (from assessment) > issue (from URL)
    $ct = '';
    if (isset($_SESSION['user_concern_type']) && !empty($_SESSION['user_concern_type'])) {
        $ct = $_SESSION['user_concern_type'];
        unset($_SESSION['user_concern_type']);
    } elseif (isset($_SESSION['issue']) && !empty($_SESSION['issue'])) {
        $ct = $_SESSION['issue'];
        unset($_SESSION['issue']);
    }

    if (!empty($ct)) {
        update_user_meta($user_id, 'concern_type', $ct);
        error_log("Saved concern_type: $ct");
    }

    if (isset($_SESSION['gender'])) {
        $gender = $_SESSION['gender'];
        update_user_meta($user_id, 'gender', $gender);
        error_log("Saved gender: $gender");
        unset($_SESSION['gender']);
    }

    if (isset($_SESSION['assessment_passed'])) {
        $assessment_passed = $_SESSION['assessment_passed'];
        update_user_meta($user_id, 'assessment_passed', $assessment_passed);
        error_log("Saved assessment: $assessment_passed");
        unset($_SESSION['assessment_passed']);
    }

    $user = get_userdata($user_id);
    if (!$user) {
        return;
    }

    // DELETE waiting list entries by email
    remove_user_from_waiting_list_by_email($user->user_email);

    // Get selected group from session
    $selected_group_id = isset($_SESSION['selected_group_id']) ? intval($_SESSION['selected_group_id']) : 0;
    error_log("UM Reg - Calling assign_user_to_active_group with selected_group_id: $selected_group_id");
    assign_user_to_active_group($user_id, [], $selected_group_id);
    
    // Log the final assigned group for BP enrollment to pick up
    $final_assigned_group = get_user_meta($user_id, 'assigned_group', true);
    error_log("[User Reg UM] ✓ User {$user_id} assigned to therapy_group: {$final_assigned_group}");

    // ✅ ENROLL USER INTO BUDDYPRESS CHAT GROUP IMMEDIATELY
    if ($final_assigned_group) {
        $enrollment_result = enroll_user_to_bp_chat_group($user_id, $final_assigned_group);
        if ($enrollment_result) {
            error_log("[User Reg UM] ✓✓✓ BP enrollment successful for user {$user_id}");
        } else {
            error_log("[User Reg UM] ✗✗✗ BP enrollment FAILED for user {$user_id} - Check enroll_user_to_bp_chat_group logs above");
        }
    } else {
        error_log("[User Reg UM] ✗ No therapy group assigned - BP enrollment skipped for user {$user_id}");
    }

    // Let UM do auto-approval from role settings, just mark session flag for redirect
    $_SESSION['just_registered'] = true;
    //     if (function_exists('pll_current_language')) {
    //         $lang = pll_current_language(); // 'en' or 'ar'

    //         if ($lang === 'ar') {
    //             wp_safe_redirect(home_url('/ar/thank-you-2'));
    //         } else {
    //             wp_safe_redirect(home_url('/thank-you'));
    //         }

    //         exit;
    //     }

}, 10, 2);

function remove_user_from_waiting_list_by_email($email)
{
    global $wpdb;

    if (empty($email)) {
        return;
    }

    $table_name = $wpdb->prefix . 'waiting_list';

    $wpdb->delete(
        $table_name,
        ['email' => $email],
        ['%s']
    );

    error_log("All waiting list entries deleted for email: {$email}");
}


function assign_user_to_active_group($user_id, $args, $direct_group_id = 0)
{
    try {
        $issue  = get_user_meta($user_id, 'concern_type', true);
        $gender = get_user_meta($user_id, 'gender', true);

        error_log("Assigning group to user $user_id | Issue: $issue | Gender: $gender");

        // Check if user selected a specific group - prioritize direct parameter, then session
        $selected_group_id = $direct_group_id > 0 ? $direct_group_id : (isset($_SESSION['selected_group_id']) ? intval($_SESSION['selected_group_id']) : 0);

        error_log("Selected group ID for assignment: {$selected_group_id}");

        // If user lacks issue/gender but manually picked a group, copy metadata from that group
        if (($issue === '' || $gender === '') && $selected_group_id > 0) {
            $selected_group = get_post($selected_group_id);
            if ($selected_group && $selected_group->post_type === 'therapy_group') {
                $group_issue = function_exists('get_field') ? get_field('issue_type', $selected_group_id) : get_post_meta($selected_group_id, 'issue_type', true);
                $group_gender = function_exists('get_field') ? get_field('gender', $selected_group_id) : get_post_meta($selected_group_id, 'gender', true);

                // Ensure values are strings, not arrays
                if (is_array($group_issue)) {
                    $group_issue = isset($group_issue[0]) ? $group_issue[0] : (reset($group_issue) ?: '');
                }
                if (is_array($group_gender)) {
                    $group_gender = isset($group_gender[0]) ? $group_gender[0] : (reset($group_gender) ?: '');
                }

                if ($group_issue) {
                    update_user_meta($user_id, 'concern_type', $group_issue);
                    $issue = $group_issue;
                    error_log("Copied issue from group: $group_issue");
                }
                if ($group_gender) {
                    update_user_meta($user_id, 'gender', $group_gender);
                    $gender = $group_gender;
                    error_log("Copied gender from group: $group_gender");
                }
            }
        }

        // If we have a selected group, try to assign directly (user explicitly chose this group)
        if ($selected_group_id > 0) {
            $selected_group = get_post($selected_group_id);
            if ($selected_group && $selected_group->post_type === 'therapy_group') {
                // Check if group is not full
                $max_members = function_exists('get_field') ? (get_field('max_members', $selected_group_id) ?: 0) : (get_post_meta($selected_group_id, 'max_members', true) ?: 0);
                $current_count = get_user_count_by_group_id($selected_group_id);

                error_log("Group {$selected_group_id} capacity: {$current_count}/{$max_members}");

                if ($current_count < $max_members) {
                    // Assign to the selected group directly
                    update_user_meta($user_id, 'assigned_group', $selected_group_id);

                    // Also ensure user has the correct issue/gender from the group
                    $group_issue = function_exists('get_field') ? get_field('issue_type', $selected_group_id) : get_post_meta($selected_group_id, 'issue_type', true);
                    $group_gender = function_exists('get_field') ? get_field('gender', $selected_group_id) : get_post_meta($selected_group_id, 'gender', true);

                    // Ensure values are strings, not arrays
                    if (is_array($group_issue)) {
                        $group_issue = isset($group_issue[0]) ? $group_issue[0] : (reset($group_issue) ?: '');
                    }
                    if (is_array($group_gender)) {
                        $group_gender = isset($group_gender[0]) ? $group_gender[0] : (reset($group_gender) ?: '');
                    }

                    if ($group_issue && empty($issue)) {
                        update_user_meta($user_id, 'concern_type', $group_issue);
                    }
                    if ($group_gender && empty($gender)) {
                        update_user_meta($user_id, 'gender', $group_gender);
                    }

                    error_log("SUCCESS: Group assigned from selection: {$selected_group_id} to user $user_id");
                    unset($_SESSION['selected_group_id']);
                    return;
                } else {
                    error_log("Selected group {$selected_group_id} is full. Falling back to auto-assignment.");
                }
            } else {
                error_log("Selected group {$selected_group_id} not found or not a therapy_group.");
            }
            unset($_SESSION['selected_group_id']);
        }

        // Fallback: Need issue and gender for auto-assignment
        if (!$issue || !$gender) {
            error_log("Missing issue or gender for fallback, aborting group assignment.");
            return;
        }

        // Fallback: Find any available group matching issue/gender
        $group = get_group_info($issue, $gender);

        if ($group) {
            update_user_meta($user_id, 'assigned_group', $group->ID);
            error_log("Group assigned (fallback): {$group->ID} to user $user_id");
        } else {
            error_log("No matching group found for issue: $issue and gender: $gender");
        }
    } catch (Throwable $e) {
        error_log('assign_user_to_active_group error: ' . $e->getMessage());
    }
}


function get_group_info($issue, $gender)
{
    $args = [
        'post_type' => 'therapy_group',
        'posts_per_page' => 1,
        'post_status' => 'publish',
        'orderby' => 'date',
        'order' => 'DESC',
        'meta_query' => [
            'relation' => 'AND',
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
        ]
    ];

    $groups = get_posts($args);

    if (!empty($groups)) {
        return $groups[0]; // Return the latest subgroup post object
    }

    return false; // No group found
}

// ============================================================================
// BUDDYPRESS ENROLLMENT FUNCTION
// ============================================================================

/**
 * Enroll user into BuddyPress chat group
 * 
 * @param int $user_id WordPress user ID
 * @param int $therapy_group_id Therapy group post ID
 */
function enroll_user_to_bp_chat_group($user_id, $therapy_group_id) {

    // BuddyPress can be loaded later in the request; defer until bp_init if needed.
    if (function_exists('did_action') && !did_action('bp_init')) {
        add_action('bp_init', function () use ($user_id, $therapy_group_id) {
            enroll_user_to_bp_chat_group($user_id, $therapy_group_id);
        }, 20);
        error_log("[User Reg BP] BuddyPress not ready yet (bp_init not fired). Deferring enrollment for user {$user_id}.");
        return false;
    }

    // Only proceed if BuddyPress Groups are active.
    if (!function_exists('bp_is_active') || !bp_is_active('groups')) {
        error_log('[User Reg BP] BuddyPress Groups component not active. Cannot enroll user.');
        return false;
    }

    if (!$therapy_group_id) {
        error_log("[User Reg BP] ✗ No therapy group ID provided. Cannot enroll.");
        return false;
    }

    $user_id = intval($user_id);
    $therapy_group_id = intval($therapy_group_id);
    error_log("[User Reg BP] ==== Starting BP enrollment for user {$user_id} (therapy_group {$therapy_group_id}) ====");

    // Get the corresponding BuddyPress group ID.
    $bp_group_id = get_post_meta($therapy_group_id, '_tbc_bp_group_id', true);

    // Auto-create BP group when missing (matches older working behavior).
    if (!$bp_group_id && function_exists('create_buddypress_group_for_therapy')) {
        $therapy_post = get_post($therapy_group_id);
        if ($therapy_post && $therapy_post->post_type === 'therapy_group') {
            error_log("[User Reg BP] No BP group for therapy_group {$therapy_group_id}. Attempting auto-create...");
            $created_id = create_buddypress_group_for_therapy($therapy_group_id, $therapy_post, null);
            if ($created_id && !is_wp_error($created_id)) {
                $bp_group_id = $created_id;
            } else {
                $bp_group_id = get_post_meta($therapy_group_id, '_tbc_bp_group_id', true);
            }
        }
    }

    if (!$bp_group_id) {
        error_log("[User Reg BP] ✗ No BP group found/created for therapy_group {$therapy_group_id}. Cannot enroll user.");
        return false;
    }

    $bp_group_id = intval($bp_group_id);
    error_log("[User Reg BP] Using BP group ID: {$bp_group_id}");

    // Verify BP group exists and capture status.
    $bp_group_status = '';
    if (function_exists('groups_get_group')) {
        $bp_group = groups_get_group($bp_group_id);
        if (!$bp_group || empty($bp_group->id)) {
            error_log("[User Reg BP] ✗ BP group {$bp_group_id} does not exist in database. Cannot enroll user.");
            return false;
        }
        $bp_group_status = isset($bp_group->status) ? $bp_group->status : '';
        error_log("[User Reg BP] ✓ BP group verified: '{$bp_group->name}' (status: {$bp_group_status})");
    }

    // Check if user is already a member.
    if (function_exists('groups_is_user_member') && groups_is_user_member($user_id, $bp_group_id)) {
        error_log("[User Reg BP] ℹ User {$user_id} already member of BP group {$bp_group_id}");
        return true;
    }

    // IMPORTANT: your BP groups are created as 'private' in admin code.
    // groups_join_group() will only auto-join PUBLIC groups; private/hidden groups create a membership request.
    // To truly enroll the user, use groups_add_member() when available.
    $enrolled = false;
    if (function_exists('groups_add_member')) {
        error_log("[User Reg BP] Attempting groups_add_member for user {$user_id} into BP group {$bp_group_id}...");
        $enrolled = (bool) groups_add_member($user_id, $bp_group_id);
    } elseif (function_exists('groups_join_group')) {
        error_log("[User Reg BP] groups_add_member unavailable; falling back to groups_join_group for user {$user_id} into BP group {$bp_group_id}...");
        $enrolled = (bool) groups_join_group($bp_group_id, $user_id);
    } else {
        error_log('[User Reg BP] No BuddyPress group membership functions available (groups_add_member/groups_join_group).');
        return false;
    }

    if ($enrolled) {
        error_log("[User Reg BP] ✓✓✓ SUCCESS! User {$user_id} enrolled into BP group {$bp_group_id} (therapy_group {$therapy_group_id})");
        update_user_meta($user_id, '_tbc_bp_group_id', $bp_group_id);
        update_user_meta($user_id, '_tbc_enrollment_date', current_time('mysql'));
        error_log("[User Reg BP] ==== Enrollment complete ====");
        return true;
    }

    error_log("[User Reg BP] ✗✗✗ FAILED to enroll user {$user_id} into BP group {$bp_group_id} (status: {$bp_group_status})");
    return false;
}

// ============================================================================
// DEBUGGING SHORTCODES FOR BUDDYPRESS ENROLLMENT
// ============================================================================

/**
 * Debugging shortcode to check user enrollment status
 * Usage: [debug_bp_enrollment user_id="123"]
 * Or just [debug_bp_enrollment] to check current user
 */
add_shortcode('debug_bp_enrollment', 'debug_bp_enrollment_shortcode');

function debug_bp_enrollment_shortcode($atts) {
    $atts = shortcode_atts([
        'user_id' => get_current_user_id()
    ], $atts);
    
    $user_id = intval($atts['user_id']);
    
    if (!$user_id) {
        return '<div style="padding:20px; background:#f8d7da; border:2px solid #dc3545; border-radius:5px;"><h3>❌ No User Found</h3><p>Please provide a user_id or login first.</p></div>';
    }
    
    ob_start();
    
    echo '<div style="padding:20px; background:#f8f9fa; border:2px solid #333; border-radius:5px; font-family:monospace; font-size:14px;">';
    echo '<h2 style="margin-top:0;">🔍 BuddyPress Enrollment Debug - User ID: ' . $user_id . '</h2>';
    
    $user = get_userdata($user_id);
    if (!$user) {
        echo '<p style="color:red;">❌ User does not exist!</p></div>';
        return ob_get_clean();
    }
    
    echo '<p><strong>Username:</strong> ' . esc_html($user->user_login) . '</p>';
    echo '<p><strong>Email:</strong> ' . esc_html($user->user_email) . '</p>';
    echo '<hr>';
    
    // Check therapy group assignment
    $assigned_group = get_user_meta($user_id, 'assigned_group', true);
    echo '<h3>1. Therapy Group Assignment</h3>';
    if ($assigned_group) {
        echo '<p style="color:green;">✓ User assigned to therapy_group: <strong>' . $assigned_group . '</strong></p>';
        
        $therapy_post = get_post($assigned_group);
        if ($therapy_post) {
            echo '<p>Group Name: ' . esc_html($therapy_post->post_title) . '</p>';
            
            $issue = function_exists('get_field') ? get_field('issue_type', $assigned_group) : get_post_meta($assigned_group, 'issue_type', true);
            $gender = function_exists('get_field') ? get_field('gender', $assigned_group) : get_post_meta($assigned_group, 'gender', true);
            $expiry = function_exists('get_field') ? get_field('session_expiry_date', $assigned_group) : get_post_meta($assigned_group, 'session_expiry_date', true);
            
            echo '<p>Issue: ' . esc_html($issue) . '</p>';
            echo '<p>Gender: ' . esc_html($gender) . '</p>';
            echo '<p>Session Expiry: ' . esc_html($expiry) . '</p>';
        } else {
            echo '<p style="color:red;">❌ Therapy group post not found!</p>';
        }
    } else {
        echo '<p style="color:red;">❌ User NOT assigned to any therapy group!</p>';
        echo '<p>This is why enrollment failed. User must be assigned to a therapy_group during registration.</p>';
    }
    
    echo '<hr><h3>2. BuddyPress Group</h3>';
    
    if ($assigned_group) {
        $bp_group_id = get_post_meta($assigned_group, '_tbc_bp_group_id', true);
        
        if ($bp_group_id) {
            echo '<p style="color:green;">✓ BP group exists for therapy group: <strong>' . $bp_group_id . '</strong></p>';
            
            if (function_exists('groups_get_group')) {
                $bp_group = groups_get_group($bp_group_id);
                if ($bp_group && !empty($bp_group->id)) {
                    echo '<p>BP Group Name: ' . esc_html($bp_group->name) . '</p>';
                    echo '<p>BP Group Status: ' . esc_html($bp_group->status) . '</p>';
                } else {
                    echo '<p style="color:red;">❌ BP group doesn\'t exist in database (orphaned reference)</p>';
                }
            } else {
                echo '<p style="color:orange;">⚠ BuddyPress functions not available</p>';
            }
        } else {
            echo '<p style="color:red;">❌ No BP group created for this therapy group!</p>';
            echo '<p>Create the BP group via Admin Dashboard → Edit Therapy Group</p>';
        }
    }
    
    echo '<hr><h3>3. User Enrollment Status</h3>';
    
    $user_bp_group_id = get_user_meta($user_id, '_tbc_bp_group_id', true);
    $enrollment_date = get_user_meta($user_id, '_tbc_enrollment_date', true);
    
    if ($user_bp_group_id) {
        echo '<p style="color:green;">✓ User has enrollment metadata: BP group ' . $user_bp_group_id . '</p>';
        if ($enrollment_date) {
            echo '<p>Enrolled on: ' . esc_html($enrollment_date) . '</p>';
        }
    } else {
        echo '<p style="color:red;">❌ User has no enrollment metadata</p>';
    }
    
    if ($assigned_group && $bp_group_id && function_exists('groups_is_user_member')) {
        $is_member = groups_is_user_member($user_id, $bp_group_id);
        
        if ($is_member) {
            echo '<p style="color:green; font-size:18px; font-weight:bold;">✓✓✓ USER IS ENROLLED IN BP GROUP!</p>';
        } else {
            echo '<p style="color:red; font-size:18px; font-weight:bold;">❌❌❌ USER IS NOT ENROLLED IN BP GROUP!</p>';
            echo '<p><strong>Troubleshooting Steps:</strong></p>';
            echo '<ol>';
            echo '<li>Check if BuddyPress Groups component is enabled</li>';
            echo '<li>Try manually enrolling via: Admin Dashboard → Therapy Group → Edit → Add User</li>';
            echo '<li>Register a new test user to see if enrollment works</li>';
            echo '</ol>';
        }
    }
    
    echo '<hr><h3>4. Quick Fix</h3>';
    
    if ($assigned_group && $bp_group_id && function_exists('groups_join_group')) {
        if (isset($_POST['manual_enroll_' . $user_id]) && current_user_can('manage_options')) {
            $joined = groups_join_group($bp_group_id, $user_id);
            if ($joined) {
                update_user_meta($user_id, '_tbc_bp_group_id', $bp_group_id);
                update_user_meta($user_id, '_tbc_enrollment_date', current_time('mysql'));
                echo '<p style="color:green; font-weight:bold;">✓ Successfully enrolled user!</p>';
                echo '<script>window.location.reload();</script>';
            } else {
                echo '<p style="color:red;">❌ Enrollment failed.</p>';
            }
        } else {
            if (current_user_can('manage_options')) {
                echo '<form method="post">';
                echo '<button type="submit" name="manual_enroll_' . $user_id . '" class="button button-primary">Manually Enroll User Now</button>';
                echo '</form>';
            }
        }
    }
    
    echo '</div>';
    
    return ob_get_clean();
}

// Helper function to count users assigned to a specific group
if (!function_exists('get_user_count_by_group_id')) {
    function get_user_count_by_group_id($group_id)
    {
        $users = get_users([
            'meta_key' => 'assigned_group',
            'meta_value' => $group_id,
            'fields' => 'ID'
        ]);
        return count($users);
    }
}

add_shortcode('debug_session_data', function () {
    if (!session_id()) {
        session_start();
    }

    ob_start();

    echo '<h3>Session Debug Info</h3>';
    echo '<pre>';
    print_r($_SESSION);
    echo '</pre>';

    return ob_get_clean();
});

// =====================================================
// CUSTOM THERAPY GROUP REGISTRATION FORM
// =====================================================

/**
 * Enqueue scripts for the custom registration form
 */
add_action('wp_enqueue_scripts', function () {
    wp_localize_script('jquery', 'THERAPY_REG_AJAX', [
        'url'   => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('therapy_registration_nonce'),
    ]);
});

/**
 * Shortcode: [therapy_registration_form]
 * Custom registration form to replace UM plugin
 */
add_action('init', function () {
    // Register on init so it reliably exists when content is rendered.
    if (!shortcode_exists('therapy_registration_form')) {
        add_shortcode('therapy_registration_form', 'render_therapy_registration_form');
    }
}, 5);

// Fallback: some themes/plugins remove shortcode processing from page content.
// If the page contains our tag, force shortcode parsing for that content.
add_filter('the_content', function ($content) {
    if (!is_string($content) || strpos($content, '[therapy_registration_form') === false) {
        return $content;
    }

    if (!shortcode_exists('therapy_registration_form')) {
        // In case this file was loaded late, register now as well.
        add_shortcode('therapy_registration_form', 'render_therapy_registration_form');
    }

    return do_shortcode($content);
}, 99);

function render_therapy_registration_form()
{
    if (!session_id()) {
        session_start();
    }

    $lang = function_exists('pll_current_language') ? pll_current_language() : 'en';
    $is_rtl = ($lang === 'ar');

    $preselected_group_id = 0;
    if (isset($_GET['group_id'])) {
        $preselected_group_id = intval($_GET['group_id']);
    } elseif (isset($_GET['session_period'])) {
        $preselected_group_id = intval($_GET['session_period']);
    } elseif (isset($_SESSION['selected_group_id'])) {
        $preselected_group_id = intval($_SESSION['selected_group_id']);
    }

    if ($preselected_group_id > 0) {
        $_SESSION['selected_group_id'] = $preselected_group_id;
        error_log("Registration Form - preselected_group_id set to: {$preselected_group_id}");
    } else {
        error_log("Registration Form - No preselected_group_id found. Session: " . (isset($_SESSION['selected_group_id']) ? $_SESSION['selected_group_id'] : 'not set'));
    }

    // If user is already logged in, auto-register them to the selected therapy group
    if (is_user_logged_in()) {
        $group_title = '';
        if ($preselected_group_id > 0) {
            $group_post = get_post($preselected_group_id);
            if ($group_post && $group_post->post_type === 'therapy_group') {
                $group_title = $group_post->post_title;
            }
        }

        ob_start();
?>
        <div class="therapy-reg-container">
            <div class="therapy-reg-message">
                <p>
                    <?php
                    if ($is_rtl) {
                        echo $group_title
                            ? 'يتم الآن تسجيلك في مجموعة: ' . esc_html($group_title)
                            : 'يتم الآن تسجيلك في المجموعة المختارة...';
                    } else {
                        echo $group_title
                            ? 'You are being registered to: ' . esc_html($group_title)
                            : 'You are being registered to your selected group...';
                    }
                    ?>
                </p>
                <p id="therapy-reg-status" style="margin-bottom: 0; color: #666;"></p>
                <button id="therapy-reg-retry" class="therapy-reg-btn" style="display:none; margin-top:16px;">
                    <?php echo $is_rtl ? 'إعادة المحاولة' : 'Try Again'; ?>
                </button>
            </div>
        </div>
        <script>
            (function() {
                const statusEl = document.getElementById('therapy-reg-status');
                const retryBtn = document.getElementById('therapy-reg-retry');
                const isRtl = <?php echo $is_rtl ? 'true' : 'false'; ?>;
                const selectedGroupId = <?php echo $preselected_group_id > 0 ? intval($preselected_group_id) : 0; ?>;

                const messages = {
                    registering: isRtl ? 'جاري التسجيل...' : 'Registering...',
                    success: isRtl ? 'تم التسجيل بنجاح! جاري التوجيه...' : 'Registration successful! Redirecting...',
                    missingGroup: isRtl ? 'لم يتم تحديد مجموعة. يرجى اختيار مجموعة علاجية أولاً.' : 'No group selected. Please choose a therapy group first.',
                    error: isRtl ? 'حدث خطأ. يرجى المحاولة مرة أخرى.' : 'An error occurred. Please try again.'
                };

                function setStatus(text, isError) {
                    statusEl.textContent = text;
                    statusEl.style.color = isError ? '#dc2626' : '#666';
                }

                function registerLoggedInUser() {
                    retryBtn.style.display = 'none';

                    if (!selectedGroupId) {
                        setStatus(messages.missingGroup, true);
                        retryBtn.style.display = 'inline-block';
                        return;
                    }

                    setStatus(messages.registering, false);

                    const formData = new FormData();
                    formData.append('action', 'therapy_logged_in_registration');
                    formData.append('nonce', THERAPY_REG_AJAX.nonce);
                    formData.append('selected_group_id', selectedGroupId);

                    fetch(THERAPY_REG_AJAX.url, {
                            method: 'POST',
                            body: formData,
                            credentials: 'same-origin'
                        })
                        .then(function(response) {
                            return response.json();
                        })
                        .then(function(data) {
                            if (data.success) {
                                setStatus(messages.success, false);
                                setTimeout(function() {
                                    if (data.data && data.data.redirect_url) {
                                        window.location.href = data.data.redirect_url;
                                    } else {
                                        window.location.reload();
                                    }
                                }, 1200);
                            } else {
                                setStatus(data.data || messages.error, true);
                                retryBtn.style.display = 'inline-block';
                            }
                        })
                        .catch(function() {
                            setStatus(messages.error, true);
                            retryBtn.style.display = 'inline-block';
                        });
                }

                retryBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    registerLoggedInUser();
                });

                registerLoggedInUser();
            })();
        </script>
    <?php
        return ob_get_clean();
    }

    // Labels based on language
    $labels = [
        'first_name'       => $is_rtl ? 'الاسم الأول' : 'First Name',
        'last_name'        => $is_rtl ? 'اسم العائلة' : 'Last Name',
        'email'            => $is_rtl ? 'البريد الإلكتروني' : 'Email',
        'phone'            => $is_rtl ? 'رقم الهاتف' : 'Phone',
        'passport'         => $is_rtl ? 'رقم جواز السفر' : 'Passport Number',
        'country'          => $is_rtl ? 'الدولة' : 'Country',
        'dob'              => $is_rtl ? 'تاريخ الميلاد' : 'Date of Birth',
        'password'         => $is_rtl ? 'كلمة المرور' : 'Password',
        'confirm_password' => $is_rtl ? 'تأكيد كلمة المرور' : 'Confirm Password',
        'register'         => $is_rtl ? 'تسجيل' : 'Register',
        'registering'      => $is_rtl ? 'جاري التسجيل...' : 'Registering...',
        'required'         => $is_rtl ? 'مطلوب' : 'Required',
        'select_country'   => $is_rtl ? 'اختر الدولة' : 'Select Country',
    ];

    // Common countries list - sorted alphabetically by English name
    $countries = [
        'DZ' => $is_rtl ? 'الجزائر' : 'Algeria',
        'AR' => $is_rtl ? 'الأرجنتين' : 'Argentina',
        'AU' => $is_rtl ? 'أستراليا' : 'Australia',
        'AT' => $is_rtl ? 'النمسا' : 'Austria',
        'BH' => $is_rtl ? 'البحرين' : 'Bahrain',
        'BD' => $is_rtl ? 'بنغلاديش' : 'Bangladesh',
        'BE' => $is_rtl ? 'بلجيكا' : 'Belgium',
        'BR' => $is_rtl ? 'البرازيل' : 'Brazil',
        'CA' => $is_rtl ? 'كندا' : 'Canada',
        'CN' => $is_rtl ? 'الصين' : 'China',
        'DK' => $is_rtl ? 'الدنمارك' : 'Denmark',
        'EG' => $is_rtl ? 'مصر' : 'Egypt',
        'FI' => $is_rtl ? 'فنلندا' : 'Finland',
        'FR' => $is_rtl ? 'فرنسا' : 'France',
        'DE' => $is_rtl ? 'ألمانيا' : 'Germany',
        'IN' => $is_rtl ? 'الهند' : 'India',
        'ID' => $is_rtl ? 'إندونيسيا' : 'Indonesia',
        'IQ' => $is_rtl ? 'العراق' : 'Iraq',
        'IT' => $is_rtl ? 'إيطاليا' : 'Italy',
        'JP' => $is_rtl ? 'اليابان' : 'Japan',
        'JO' => $is_rtl ? 'الأردن' : 'Jordan',
        'KE' => $is_rtl ? 'كينيا' : 'Kenya',
        'KW' => $is_rtl ? 'الكويت' : 'Kuwait',
        'LB' => $is_rtl ? 'لبنان' : 'Lebanon',
        'LY' => $is_rtl ? 'ليبيا' : 'Libya',
        'MY' => $is_rtl ? 'ماليزيا' : 'Malaysia',
        'MX' => $is_rtl ? 'المكسيك' : 'Mexico',
        'MA' => $is_rtl ? 'المغرب' : 'Morocco',
        'NL' => $is_rtl ? 'هولندا' : 'Netherlands',
        'NG' => $is_rtl ? 'نيجيريا' : 'Nigeria',
        'NO' => $is_rtl ? 'النرويج' : 'Norway',
        'OM' => $is_rtl ? 'عمان' : 'Oman',
        'PK' => $is_rtl ? 'باكستان' : 'Pakistan',
        'PS' => $is_rtl ? 'فلسطين' : 'Palestine',
        'PH' => $is_rtl ? 'الفلبين' : 'Philippines',
        'PL' => $is_rtl ? 'بولندا' : 'Poland',
        'QA' => $is_rtl ? 'قطر' : 'Qatar',
        'SA' => $is_rtl ? 'المملكة العربية السعودية' : 'Saudi Arabia',
        'SG' => $is_rtl ? 'سنغافورة' : 'Singapore',
        'ZA' => $is_rtl ? 'جنوب أفريقيا' : 'South Africa',
        'KR' => $is_rtl ? 'كوريا الجنوبية' : 'South Korea',
        'ES' => $is_rtl ? 'إسبانيا' : 'Spain',
        'SD' => $is_rtl ? 'السودان' : 'Sudan',
        'SE' => $is_rtl ? 'السويد' : 'Sweden',
        'CH' => $is_rtl ? 'سويسرا' : 'Switzerland',
        'SY' => $is_rtl ? 'سوريا' : 'Syria',
        'TN' => $is_rtl ? 'تونس' : 'Tunisia',
        'TR' => $is_rtl ? 'تركيا' : 'Turkey',
        'AE' => $is_rtl ? 'الإمارات العربية المتحدة' : 'United Arab Emirates',
        'GB' => $is_rtl ? 'المملكة المتحدة' : 'United Kingdom',
        'US' => $is_rtl ? 'الولايات المتحدة' : 'United States',
        'YE' => $is_rtl ? 'اليمن' : 'Yemen',
        'OTHER' => $is_rtl ? 'أخرى' : 'Other',
    ];

    ob_start();
    ?>
    <style>
        .therapy-reg-container {
            max-width: 680px;
            margin: 0 auto;
            padding: 20px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
        }

        .therapy-reg-container * {
            box-sizing: border-box;
        }

        .therapy-reg-form {
            background: #ffffff;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid #e8e8e8;
        }

        .therapy-reg-header {
            text-align: center;
            margin-bottom: 20px;
        }

        .therapy-reg-header h2 {
            background: linear-gradient(135deg, #6059A6, #C3DDD2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-size: 26px;
            margin: 0 0 8px 0;
            font-weight: 700;
        }

        .therapy-reg-header p {
            color: #666;
            font-size: 14px;
            margin: 0;
        }

        .therapy-reg-row {
            display: flex;
            gap: 15px;
            margin-bottom: 0;
        }

        .therapy-reg-row .therapy-reg-field {
            flex: 1;
        }

        .therapy-reg-field {
            margin-bottom: 15px;
        }

        .therapy-reg-field label {
            display: block;
            font-weight: 600;
            color: #333;
            margin-bottom: 6px;
            font-size: 14px;
        }

        .therapy-reg-field label .required {
            color: #e74c3c;
            margin-<?php echo $is_rtl ? 'right' : 'left'; ?>: 3px;
        }

        .therapy-reg-field input,
        .therapy-reg-field select {
            width: 100%;
            padding: 12px 14px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 15px;
            transition: all 0.3s ease;
            background: #fafafa;
            color: #333;
        }

        .therapy-reg-field input:focus,
        .therapy-reg-field select:focus {
            outline: none;
            border-color: #6059A6;
            background: #fff;
            box-shadow: 0 0 0 4px rgba(96, 89, 166, 0.1);
        }

        .therapy-reg-field input::placeholder {
            color: #aaa;
        }

        .therapy-reg-field input.error,
        .therapy-reg-field select.error {
            border-color: #e74c3c;
            background: #fff5f5;
        }

        .therapy-reg-field .field-error {
            color: #e74c3c;
            font-size: 12px;
            margin-top: 5px;
            display: none;
        }

        .therapy-reg-field .field-error.show {
            display: block;
        }

        .therapy-reg-password-wrapper {
            position: relative;
        }

        .therapy-reg-password-wrapper input {
            padding-<?php echo $is_rtl ? 'left' : 'right'; ?>: 45px;
        }

        .therapy-reg-toggle-password {
            position: absolute;
            <?php echo $is_rtl ? 'left' : 'right'; ?>: 14px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #999;
            font-size: 18px;
            transition: color 0.2s;
        }

        .therapy-reg-toggle-password:hover {
            color: #6059A6;
        }

        .therapy-reg-btn {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #6059A6, #7a74b8);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 10px;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }

        .therapy-reg-btn:hover {
            background: linear-gradient(135deg, #524b8f, #6059A6);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(96, 89, 166, 0.3);
        }

        .therapy-reg-btn:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }

        .therapy-reg-btn.loading {
            position: relative;
            color: transparent;
        }

        .therapy-reg-btn.loading::after {
            content: '';
            position: absolute;
            width: 20px;
            height: 20px;
            top: 50%;
            left: 50%;
            margin-left: -10px;
            margin-top: -10px;
            border: 2px solid #fff;
            border-top-color: transparent;
            border-radius: 50%;
            animation: therapy-reg-spin 0.8s linear infinite;
        }

        @keyframes therapy-reg-spin {
            to {
                transform: rotate(360deg);
            }
        }

        .therapy-reg-alert {
            padding: 14px 18px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            display: none;
        }

        .therapy-reg-alert.error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
        }

        .therapy-reg-alert.success {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #16a34a;
        }

        .therapy-reg-alert.show {
            display: block;
        }

        .therapy-reg-message {
            text-align: center;
            padding: 40px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }

        .therapy-reg-message p {
            color: #333;
            font-size: 16px;
            margin-bottom: 20px;
        }

        /* RTL Support */
        <?php if ($is_rtl): ?>.therapy-reg-container {
            direction: rtl;
            text-align: right;
        }

        .therapy-reg-field select {
            background-position: left 14px center;
        }

        <?php endif; ?>
        /* Responsive */
        @media (max-width: 600px) {
            .therapy-reg-container {
                padding: 15px;
            }

            .therapy-reg-form {
                padding: 25px 20px;
            }

            .therapy-reg-row {
                flex-direction: column;
                gap: 0;
            }

            .therapy-reg-header h2 {
                font-size: 22px;
            }
        }
    </style>

    <div class="therapy-reg-container">
        <form id="therapy-registration-form" class="therapy-reg-form" novalidate>
            <div class="therapy-reg-header">
                <h2><?php echo $is_rtl ? 'إنشاء حساب جديد' : 'Create Account'; ?></h2>
                <p><?php echo $is_rtl ? 'انضم إلى مجموعات العلاج النفسي' : 'Join our therapy groups'; ?></p>
            </div>

            <div class="therapy-reg-alert" id="therapy-reg-alert"></div>

            <!-- First Name & Last Name -->
            <div class="therapy-reg-row">
                <div class="therapy-reg-field">
                    <label for="therapy_first_name"><?php echo $labels['first_name']; ?><span class="required">*</span></label>
                    <input type="text" id="therapy_first_name" name="first_name" required>
                    <div class="field-error" id="error_first_name"></div>
                </div>

                <div class="therapy-reg-field">
                    <label for="therapy_last_name"><?php echo $labels['last_name']; ?><span class="required">*</span></label>
                    <input type="text" id="therapy_last_name" name="last_name" required>
                    <div class="field-error" id="error_last_name"></div>
                </div>
            </div>

            <!-- Email -->
            <div class="therapy-reg-field">
                <label for="therapy_email"><?php echo $labels['email']; ?><span class="required">*</span></label>
                <input type="email" id="therapy_email" name="email" required>
                <div class="field-error" id="error_email"></div>
            </div>

            <!-- Phone -->
            <div class="therapy-reg-field">
                <label for="therapy_phone"><?php echo $labels['phone']; ?><span class="required">*</span></label>
                <input type="tel" id="therapy_phone" name="phone" placeholder="<?php echo $is_rtl ? '+966 5xxxxxxxx' : '+966 5xxxxxxxx'; ?>" required>
                <div class="field-error" id="error_phone"></div>
            </div>

            <!-- Passport Number -->
            <div class="therapy-reg-field">
                <label for="therapy_passport"><?php echo $labels['passport']; ?><span class="required">*</span></label>
                <input type="text" id="therapy_passport" name="passport_number" required>
                <div class="field-error" id="error_passport"></div>
            </div>

            <!-- Country -->
            <div class="therapy-reg-field">
                <label for="therapy_country"><?php echo $labels['country']; ?><span class="required">*</span></label>
                <select id="therapy_country" name="country" required>
                    <option value=""><?php echo $labels['select_country']; ?></option>
                    <?php foreach ($countries as $code => $name): ?>
                        <option value="<?php echo esc_attr($code); ?>"><?php echo esc_html($name); ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="field-error" id="error_country"></div>
            </div>

            <!-- Date of Birth -->
            <div class="therapy-reg-field">
                <label for="therapy_dob"><?php echo $labels['dob']; ?><span class="required">*</span></label>
                <input type="date" id="therapy_dob" name="birth_date" required>
                <div class="field-error" id="error_dob"></div>
            </div>

            <!-- Password -->
            <div class="therapy-reg-field">
                <label for="therapy_password"><?php echo $labels['password']; ?><span class="required">*</span></label>
                <div class="therapy-reg-password-wrapper">
                    <input type="password" id="therapy_password" name="password" required minlength="8">
                    <span class="therapy-reg-toggle-password" data-target="therapy_password">👁</span>
                </div>
                <div class="field-error" id="error_password"></div>
            </div>

            <!-- Confirm Password -->
            <div class="therapy-reg-field">
                <label for="therapy_confirm_password"><?php echo $labels['confirm_password']; ?><span class="required">*</span></label>
                <div class="therapy-reg-password-wrapper">
                    <input type="password" id="therapy_confirm_password" name="confirm_password" required>
                    <span class="therapy-reg-toggle-password" data-target="therapy_confirm_password">👁</span>
                </div>
                <div class="field-error" id="error_confirm_password"></div>
            </div>

            <input type="hidden" name="action" value="therapy_custom_registration">
            <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('therapy_registration_nonce'); ?>">
            <input type="hidden" name="selected_group_id" id="therapy_selected_group_id" value="<?php echo $preselected_group_id > 0 ? esc_attr($preselected_group_id) : ''; ?>">

            <button type="submit" class="therapy-reg-btn" id="therapy-reg-submit">
                <?php echo $labels['register']; ?>
            </button>
        </form>
    </div>

    <script>
        (function() {
            const form = document.getElementById('therapy-registration-form');
            const submitBtn = document.getElementById('therapy-reg-submit');
            const alertBox = document.getElementById('therapy-reg-alert');
            const isRtl = <?php echo $is_rtl ? 'true' : 'false'; ?>;

            const messages = {
                required: isRtl ? 'هذا الحقل مطلوب' : 'This field is required',
                email: isRtl ? 'يرجى إدخال بريد إلكتروني صحيح' : 'Please enter a valid email',
                passwordLength: isRtl ? 'كلمة المرور يجب أن تكون 8 أحرف على الأقل' : 'Password must be at least 8 characters',
                passwordMatch: isRtl ? 'كلمات المرور غير متطابقة' : 'Passwords do not match',
                registering: isRtl ? 'جاري التسجيل...' : 'Registering...',
                register: isRtl ? 'تسجيل' : 'Register',
                successRedirect: isRtl ? 'تم التسجيل بنجاح! جاري التوجيه...' : 'Registration successful! Redirecting...'
            };

            // Toggle password visibility
            document.querySelectorAll('.therapy-reg-toggle-password').forEach(function(toggle) {
                toggle.addEventListener('click', function() {
                    const input = document.getElementById(this.getAttribute('data-target'));
                    if (input.type === 'password') {
                        input.type = 'text';
                        this.textContent = '🙈';
                    } else {
                        input.type = 'password';
                        this.textContent = '👁';
                    }
                });
            });

            // Clear error on input
            form.querySelectorAll('input, select').forEach(function(field) {
                field.addEventListener('input', function() {
                    this.classList.remove('error');
                    const errorDiv = document.getElementById('error_' + this.name.replace('_', '_'));
                    if (errorDiv) {
                        errorDiv.classList.remove('show');
                        errorDiv.textContent = '';
                    }
                });
            });

            function showFieldError(fieldName, message) {
                const field = form.querySelector('[name="' + fieldName + '"]');
                const errorDiv = document.getElementById('error_' + fieldName.replace('birth_date', 'dob').replace('passport_number', 'passport'));

                if (field) field.classList.add('error');
                if (errorDiv) {
                    errorDiv.textContent = message;
                    errorDiv.classList.add('show');
                }
            }

            function showAlert(message, type) {
                alertBox.textContent = message;
                alertBox.className = 'therapy-reg-alert ' + type + ' show';
                alertBox.scrollIntoView({
                    behavior: 'smooth',
                    block: 'nearest'
                });
            }

            function hideAlert() {
                alertBox.className = 'therapy-reg-alert';
            }

            function validateForm() {
                let isValid = true;
                hideAlert();

                // Clear previous errors
                form.querySelectorAll('.error').forEach(function(el) {
                    el.classList.remove('error');
                });
                form.querySelectorAll('.field-error').forEach(function(el) {
                    el.classList.remove('show');
                    el.textContent = '';
                });

                const firstName = form.querySelector('[name="first_name"]').value.trim();
                const lastName = form.querySelector('[name="last_name"]').value.trim();
                const email = form.querySelector('[name="email"]').value.trim();
                const phone = form.querySelector('[name="phone"]').value.trim();
                const passport = form.querySelector('[name="passport_number"]').value.trim();
                const country = form.querySelector('[name="country"]').value;
                const dob = form.querySelector('[name="birth_date"]').value;
                const password = form.querySelector('[name="password"]').value;
                const confirmPassword = form.querySelector('[name="confirm_password"]').value;

                if (!firstName) {
                    showFieldError('first_name', messages.required);
                    isValid = false;
                }
                if (!lastName) {
                    showFieldError('last_name', messages.required);
                    isValid = false;
                }

                if (!email) {
                    showFieldError('email', messages.required);
                    isValid = false;
                } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                    showFieldError('email', messages.email);
                    isValid = false;
                }

                if (!phone) {
                    showFieldError('phone', messages.required);
                    isValid = false;
                }
                if (!passport) {
                    showFieldError('passport_number', messages.required);
                    isValid = false;
                }
                if (!country) {
                    showFieldError('country', messages.required);
                    isValid = false;
                }
                if (!dob) {
                    showFieldError('birth_date', messages.required);
                    isValid = false;
                }

                if (!password) {
                    showFieldError('password', messages.required);
                    isValid = false;
                } else if (password.length < 8) {
                    showFieldError('password', messages.passwordLength);
                    isValid = false;
                }

                if (!confirmPassword) {
                    showFieldError('confirm_password', messages.required);
                    isValid = false;
                } else if (password !== confirmPassword) {
                    showFieldError('confirm_password', messages.passwordMatch);
                    isValid = false;
                }

                return isValid;
            }

            form.addEventListener('submit', function(e) {
                e.preventDefault();

                if (!validateForm()) {
                    return;
                }

                submitBtn.disabled = true;
                submitBtn.classList.add('loading');
                submitBtn.textContent = messages.registering;
                hideAlert();

                const formData = new FormData(form);

                fetch(THERAPY_REG_AJAX.url, {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin'
                    })
                    .then(function(response) {
                        return response.json();
                    })
                    .then(function(data) {
                        if (data.success) {
                            showAlert(messages.successRedirect, 'success');

                            // Redirect after short delay
                            setTimeout(function() {
                                if (data.data && data.data.redirect_url) {
                                    window.location.href = data.data.redirect_url;
                                } else {
                                    window.location.reload();
                                }
                            }, 1500);
                        } else {
                            showAlert(data.data || (isRtl ? 'حدث خطأ. يرجى المحاولة مرة أخرى.' : 'An error occurred. Please try again.'), 'error');
                            submitBtn.disabled = false;
                            submitBtn.classList.remove('loading');
                            submitBtn.textContent = messages.register;
                        }
                    })
                    .catch(function(error) {
                        console.error('Registration error:', error);
                        showAlert(isRtl ? 'حدث خطأ في الاتصال. يرجى المحاولة مرة أخرى.' : 'Connection error. Please try again.', 'error');
                        submitBtn.disabled = false;
                        submitBtn.classList.remove('loading');
                        submitBtn.textContent = messages.register;
                    });
            });
        })();
    </script>
<?php
    return ob_get_clean();
}

/**
 * AJAX Handler for Custom Therapy Registration
 */
add_action('wp_ajax_therapy_custom_registration', 'handle_therapy_custom_registration');
add_action('wp_ajax_nopriv_therapy_custom_registration', 'handle_therapy_custom_registration');
add_action('wp_ajax_therapy_logged_in_registration', 'handle_therapy_logged_in_registration');

function handle_therapy_custom_registration()
{
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'therapy_registration_nonce')) {
        wp_send_json_error(__('Security check failed. Please refresh the page and try again.', 'therapy'));
    }

    if (!session_id()) {
        session_start();
    }

    $posted_group_id = isset($_POST['selected_group_id']) ? intval($_POST['selected_group_id']) : 0;
    if ($posted_group_id > 0) {
        $_SESSION['selected_group_id'] = $posted_group_id;
    }

    // Sanitize input
    $first_name       = sanitize_text_field($_POST['first_name'] ?? '');
    $last_name        = sanitize_text_field($_POST['last_name'] ?? '');
    $email            = sanitize_email($_POST['email'] ?? '');
    $phone            = sanitize_text_field($_POST['phone'] ?? '');
    $passport_number  = sanitize_text_field($_POST['passport_number'] ?? '');
    $country          = sanitize_text_field($_POST['country'] ?? '');
    $birth_date       = sanitize_text_field($_POST['birth_date'] ?? '');
    $password         = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Validation
    if (
        empty($first_name) || empty($last_name) || empty($email) || empty($phone) ||
        empty($passport_number) || empty($country) || empty($birth_date) || empty($password)
    ) {
        wp_send_json_error(__('Please fill in all required fields.', 'therapy'));
    }

    if (!is_email($email)) {
        wp_send_json_error(__('Please enter a valid email address.', 'therapy'));
    }

    if (strlen($password) < 8) {
        wp_send_json_error(__('Password must be at least 8 characters long.', 'therapy'));
    }

    if ($password !== $confirm_password) {
        wp_send_json_error(__('Passwords do not match.', 'therapy'));
    }

    // Check if email already exists
    if (email_exists($email)) {
        wp_send_json_error(__('This email is already registered. Please log in or use a different email.', 'therapy'));
    }

    // Check if username (email) already exists
    if (username_exists($email)) {
        wp_send_json_error(__('This email is already registered. Please log in or use a different email.', 'therapy'));
    }

    // Create user
    $user_id = wp_create_user($email, $password, $email);

    if (is_wp_error($user_id)) {
        error_log('Therapy Registration Error: ' . $user_id->get_error_message());
        wp_send_json_error(__('Registration failed. Please try again.', 'therapy'));
    }

    // Update user data
    wp_update_user([
        'ID'           => $user_id,
        'first_name'   => $first_name,
        'last_name'    => $last_name,
        'display_name' => $first_name . ' ' . $last_name,
    ]);

    // Save user meta - using ACF field keys that dashboard expects
    update_user_meta($user_id, 'first_name', $first_name);
    update_user_meta($user_id, 'last_name', $last_name);
    update_user_meta($user_id, 'phone_number', $phone);
    update_user_meta($user_id, 'passport_no', $passport_number);  // ACF field key
    update_user_meta($user_id, 'country', $country);              // ACF field key
    update_user_meta($user_id, 'dob', $birth_date);               // ACF field key

    // Set user role (default to subscriber or your custom role)
    $user = new WP_User($user_id);
    $user->set_role('subscriber');

    // Mark account as active (UM compatibility)
    update_user_meta($user_id, 'account_status', 'approved');

    // Trigger the same hooks that UM would trigger
    // This ensures existing functionality continues to work

    // Process session data (assessment results, etc.)
    if (isset($_SESSION['posted_data']) && is_array($_SESSION['posted_data'])) {
        update_session_values();
    }

    // Save concern type from session (from assessment form or URL params)
    // Priority: user_concern_type (from assessment) > issue (from URL params)
    $concern_type = '';
    if (isset($_SESSION['user_concern_type']) && !empty($_SESSION['user_concern_type'])) {
        $concern_type = $_SESSION['user_concern_type'];
        unset($_SESSION['user_concern_type']);
    } elseif (isset($_SESSION['issue']) && !empty($_SESSION['issue'])) {
        $concern_type = $_SESSION['issue'];
        unset($_SESSION['issue']);
    }

    if (!empty($concern_type)) {
        update_user_meta($user_id, 'concern_type', $concern_type);
        error_log("Custom Reg - Saved concern_type: $concern_type for user $user_id");
    }

    // Save gender from session
    if (isset($_SESSION['gender'])) {
        $gender = $_SESSION['gender'];
        update_user_meta($user_id, 'gender', $gender);
        error_log("Custom Reg - Saved gender: $gender for user $user_id");
        unset($_SESSION['gender']);
    }

    // Save assessment passed status from session
    if (isset($_SESSION['assessment_passed'])) {
        $assessment_passed = $_SESSION['assessment_passed'];
        update_user_meta($user_id, 'assessment_passed', $assessment_passed);
        error_log("Custom Reg - Saved assessment_passed: $assessment_passed for user $user_id");
        unset($_SESSION['assessment_passed']);
    }

    // Get user object for email operations
    $user_obj = get_userdata($user_id);
    if ($user_obj) {
        // Remove from waiting list if they were on it
        remove_user_from_waiting_list_by_email($user_obj->user_email);
    }

    // Assign user to therapy group - pass the posted group_id directly for reliability
    $selected_group_id = $posted_group_id > 0 ? $posted_group_id : (isset($_SESSION['selected_group_id']) ? intval($_SESSION['selected_group_id']) : 0);
    error_log("Custom Reg - Calling assign_user_to_active_group with selected_group_id: $selected_group_id");
    assign_user_to_active_group($user_id, [], $selected_group_id);
    
    // ✅ ENROLL USER INTO BUDDYPRESS CHAT GROUP IMMEDIATELY
    $final_assigned_group = get_user_meta($user_id, 'assigned_group', true);
    error_log("[User Reg Custom] ✓ User {$user_id} assigned to therapy_group: {$final_assigned_group}");
    
    if ($final_assigned_group) {
        $enrollment_result = enroll_user_to_bp_chat_group($user_id, $final_assigned_group);
        if ($enrollment_result) {
            error_log("[User Reg Custom] ✓✓✓ BP enrollment successful for user {$user_id}");
        } else {
            error_log("[User Reg Custom] ✗✗✗ BP enrollment FAILED for user {$user_id} - Check enroll_user_to_bp_chat_group logs above");
        }
    } else {
        error_log("[User Reg Custom] ✗ No therapy group assigned - BP enrollment skipped for user {$user_id}");
    }

    // Send registration confirmation email
    send_therapy_registration_email($email, $first_name);

    // Log user in automatically
    wp_set_current_user($user_id);
    wp_set_auth_cookie($user_id, true);

    // Set flag for redirect (same as UM flow)
    $_SESSION['just_registered'] = true;

    // Determine redirect URL
    $lang = function_exists('pll_current_language') ? pll_current_language() : 'en';
    $redirect_url = ($lang === 'ar') ? home_url('/ar/thank-you-arabic') : home_url('/thank-you');

    error_log("Custom Therapy Registration complete for user ID: $user_id");

    wp_send_json_success([
        'message'      => __('Registration successful!', 'therapy'),
        'user_id'      => $user_id,
        'redirect_url' => $redirect_url,
    ]);
}

/**
 * AJAX Handler for Logged-in Therapy Registration
 */
function handle_therapy_logged_in_registration()
{
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'therapy_registration_nonce')) {
        wp_send_json_error(__('Security check failed. Please refresh the page and try again.', 'therapy'));
    }

    if (!session_id()) {
        session_start();
    }

    if (!is_user_logged_in()) {
        wp_send_json_error(__('You must be logged in to register.', 'therapy'));
    }

    $user_id = get_current_user_id();
    if (!$user_id) {
        wp_send_json_error(__('Unable to identify your account. Please log in again.', 'therapy'));
    }

    $posted_group_id = isset($_POST['selected_group_id']) ? intval($_POST['selected_group_id']) : 0;
    if ($posted_group_id > 0) {
        $_SESSION['selected_group_id'] = $posted_group_id;
    }

    $selected_group_id = $posted_group_id > 0 ? $posted_group_id : (isset($_SESSION['selected_group_id']) ? intval($_SESSION['selected_group_id']) : 0);
    if ($selected_group_id <= 0) {
        wp_send_json_error(__('No group selected. Please choose a therapy group first.', 'therapy'));
    }

    // Process session data (assessment results, etc.)
    if (isset($_SESSION['posted_data']) && is_array($_SESSION['posted_data'])) {
        update_session_values();
    }

    // Save concern type from session (from assessment form or URL params)
    $concern_type = '';
    if (isset($_SESSION['user_concern_type']) && !empty($_SESSION['user_concern_type'])) {
        $concern_type = $_SESSION['user_concern_type'];
        unset($_SESSION['user_concern_type']);
    } elseif (isset($_SESSION['issue']) && !empty($_SESSION['issue'])) {
        $concern_type = $_SESSION['issue'];
        unset($_SESSION['issue']);
    }

    if (!empty($concern_type)) {
        update_user_meta($user_id, 'concern_type', $concern_type);
        error_log("Logged-in Reg - Saved concern_type: $concern_type for user $user_id");
    }

    // Save gender from session
    if (isset($_SESSION['gender'])) {
        $gender = $_SESSION['gender'];
        update_user_meta($user_id, 'gender', $gender);
        error_log("Logged-in Reg - Saved gender: $gender for user $user_id");
        unset($_SESSION['gender']);
    }

    // Save assessment passed status from session
    if (isset($_SESSION['assessment_passed'])) {
        $assessment_passed = $_SESSION['assessment_passed'];
        update_user_meta($user_id, 'assessment_passed', $assessment_passed);
        error_log("Logged-in Reg - Saved assessment_passed: $assessment_passed for user $user_id");
        unset($_SESSION['assessment_passed']);
    }

    // Remove from waiting list if they were on it
    $user_obj = get_userdata($user_id);
    if ($user_obj) {
        remove_user_from_waiting_list_by_email($user_obj->user_email);
    }

    // Assign user to therapy group - pass the selected group ID directly
    error_log("Logged-in Reg - Calling assign_user_to_active_group with selected_group_id: $selected_group_id");
    assign_user_to_active_group($user_id, [], $selected_group_id);

    // ✅ ENROLL USER INTO BUDDYPRESS CHAT GROUP IMMEDIATELY
    $final_assigned_group = get_user_meta($user_id, 'assigned_group', true);
    error_log("[User Reg Logged-in] ✓ User {$user_id} assigned to therapy_group: {$final_assigned_group}");

    if ($final_assigned_group) {
        $enrollment_result = enroll_user_to_bp_chat_group($user_id, $final_assigned_group);
        if ($enrollment_result) {
            error_log("[User Reg Logged-in] ✓✓✓ BP enrollment successful for user {$user_id}");
        } else {
            error_log("[User Reg Logged-in] ✗✗✗ BP enrollment FAILED for user {$user_id} - Check enroll_user_to_bp_chat_group logs above");
        }
    } else {
        error_log("[User Reg Logged-in] ✗ No therapy group assigned - BP enrollment skipped for user {$user_id}");
        wp_send_json_error(__('Unable to assign you to a therapy group. Please contact support.', 'therapy'));
    }

    // Set flag for redirect (same as UM flow)
    $_SESSION['just_registered'] = true;

    // Determine redirect URL
    $lang = function_exists('pll_current_language') ? pll_current_language() : 'en';
    $redirect_url = ($lang === 'ar') ? home_url('/ar/thank-you-arabic') : home_url('/thank-you');

    error_log("Logged-in Therapy Registration complete for user ID: $user_id");

    wp_send_json_success([
        'message'      => __('Registration successful!', 'therapy'),
        'user_id'      => $user_id,
        'redirect_url' => $redirect_url,
    ]);
}

/**
 * Send registration confirmation email
 */
function send_therapy_registration_email($email, $first_name)
{
    $subject = 'Registration Confirmed – Tashafe Therapy Groups';

    $message = '
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Tashafe Registration</title>
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
                                <p>Hi ' . esc_html($first_name) . ',</p>
                                <p>
                                    Thank you for registering with <strong>Tashafe Therapy Groups</strong>!
                                    Your account has been successfully created.
                                </p>
                                <p>
                                    You are now part of our therapy community. We will be in touch
                                    with more information about your group sessions soon.
                                </p>
                                <!-- Button -->
                                <table cellspacing="0" cellpadding="0" style="margin-top:20px;">
                                    <tr>
                                        <td align="center">
                                            <a href="https://tanafs.com.sa/dashboard"
                                               style="display:inline-block; padding:14px 28px;
                                                      background:linear-gradient(135deg, #C3DDD2, #6059A6);
                                                      color:#fff; text-decoration:none;
                                                      font-weight:600; border-radius:6px;
                                                      font-size:16px;">
                                                Visit Your Dashboard
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

    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: Tashafe <no-reply@tanafs.com.sa>'
    ];

    wp_mail($email, $subject, $message, $headers);
}
