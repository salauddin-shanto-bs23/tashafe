<?php
/**
 * PayTabs Payment Integration for Retreat Booking System
 * 
 * This file contains ALL payment-related functionality for the retreat booking system:
 * - Core PayTabs API functions (initiate, verify, callback)
 * - Retreat-specific AJAX handlers
 * - Payment return page template
 * - Transaction logging and security
 * 
 * Dependencies: paytabs_admin.php (global settings)
 * 
 * @package Tanafs_Retreat
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// ============================================================================
// SECTION 1: CORE PAYTABS API FUNCTIONS
// ============================================================================

/**
 * Check if PayTabs is properly configured
 * 
 * @return bool True if configured, false otherwise
 */
function paytabs_is_configured() {
    $profile_id = get_option('paytabs_profile_id');
    $server_key = get_option('paytabs_server_key');
    
    return !empty($profile_id) && !empty($server_key);
}

/**
 * Get PayTabs API endpoint based on region
 * 
 * @return string API base URL
 */
function paytabs_get_api_endpoint() {
    $region = get_option('paytabs_region', 'global');
    
    // Return endpoint based on region
    $endpoints = [
        'global' => 'https://secure-global.paytabs.com/payment',
        'ksa'    => 'https://secure.paytabs.sa/payment',
        'uae'    => 'https://secure-uae.paytabs.com/payment',
        'egypt'  => 'https://secure-egypt.paytabs.com/payment',
        'oman'   => 'https://secure-oman.paytabs.com/payment',
        'jordan' => 'https://secure-jordan.paytabs.com/payment',
    ];
    
    return $endpoints[$region] ?? $endpoints['global'];
}

/**
 * Initiate a PayTabs Hosted Payment Page session
 * 
 * @param string $cart_id Unique cart/booking identifier
 * @param float $amount Payment amount
 * @param array $customer_details Customer information (name, email, phone, etc.)
 * @param array $options Additional options (currency, description, etc.)
 * @return array Result with success status and redirect_url or error
 */
function paytabs_initiate_payment($cart_id, $amount, $customer_details, $options = []) {
    // Check configuration
    if (!paytabs_is_configured()) {
        return [
            'success' => false,
            'error' => 'PayTabs is not configured. Please configure PayTabs settings in WordPress Admin.'
        ];
    }
    
    $profile_id = get_option('paytabs_profile_id');
    $server_key = get_option('paytabs_server_key');
    $currency = $options['currency'] ?? get_option('paytabs_currency', 'SAR');
    $description = $options['description'] ?? 'Retreat Booking Payment';
    
    // Build return and callback URLs
    // Return to retreat page with booking token to resume journey
    $retreat_page_url = $options['retreat_page_url'] ?? home_url('/retreats/');
    $return_url = add_query_arg('payment_return', $cart_id, $retreat_page_url);
    $callback_url = home_url('/payment-callback/');
    
    // Prepare API request payload
    $payload = [
        'profile_id' => intval($profile_id),
        'tran_type' => 'sale',
        'tran_class' => 'ecom',
        'cart_id' => $cart_id,
        'cart_description' => $description,
        'cart_currency' => $currency,
        'cart_amount' => number_format(floatval($amount), 2, '.', ''),
        'return' => $return_url,
        'callback' => $callback_url,
        'customer_details' => [
            'name' => $customer_details['name'] ?? '',
            'email' => $customer_details['email'] ?? '',
            'phone' => $customer_details['phone'] ?? '',
            'street1' => $customer_details['street1'] ?? 'N/A',
            'city' => $customer_details['city'] ?? 'N/A',
            'state' => $customer_details['state'] ?? 'N/A',
            'country' => $customer_details['country'] ?? 'SA',
            'zip' => $customer_details['zip'] ?? '00000',
        ],
        'hide_shipping' => true,
    ];
    
    // Make API request
    $endpoint = paytabs_get_api_endpoint() . '/request';
    
    $response = wp_remote_post($endpoint, [
        'headers' => [
            'Authorization' => $server_key,
            'Content-Type' => 'application/json',
        ],
        'body' => json_encode($payload),
        'timeout' => 30,
    ]);
    
    // Handle API errors
    if (is_wp_error($response)) {
        paytabs_log_transaction('initiate_error', [
            'cart_id' => $cart_id,
            'error' => $response->get_error_message(),
        ]);
        
        return [
            'success' => false,
            'error' => 'Payment gateway connection failed: ' . $response->get_error_message(),
        ];
    }
    
    $body = json_decode(wp_remote_retrieve_body($response), true);
    
    // Log the transaction with full details for debugging
    paytabs_log_transaction('initiate', [
        'cart_id' => $cart_id,
        'amount' => $amount,
        'currency' => $currency,
        'payload' => $payload,
        'http_code' => wp_remote_retrieve_response_code($response),
        'response' => $body,
    ]);
    
    // Check response
    if (isset($body['redirect_url']) && !empty($body['redirect_url'])) {
        return [
            'success' => true,
            'redirect_url' => $body['redirect_url'],
            'tran_ref' => $body['tran_ref'] ?? '',
        ];
    }
    
    return [
        'success' => false,
        'error' => $body['message'] ?? 'Failed to initiate payment session',
        'response' => $body,
        'details' => isset($body['errors']) ? $body['errors'] : null,
    ];
}

/**
 * Verify payment status with PayTabs
 * 
 * @param string $tran_ref PayTabs transaction reference
 * @return array Payment verification result
 */
function paytabs_verify_payment($tran_ref) {
    if (!paytabs_is_configured()) {
        return [
            'success' => false,
            'error' => 'PayTabs is not configured',
        ];
    }
    
    $profile_id = get_option('paytabs_profile_id');
    $server_key = get_option('paytabs_server_key');
    
    $endpoint = paytabs_get_api_endpoint() . '/query';
    
    $payload = [
        'profile_id' => intval($profile_id),
        'tran_ref' => $tran_ref,
    ];
    
    $response = wp_remote_post($endpoint, [
        'headers' => [
            'Authorization' => $server_key,
            'Content-Type' => 'application/json',
        ],
        'body' => json_encode($payload),
        'timeout' => 30,
    ]);
    
    if (is_wp_error($response)) {
        paytabs_log_transaction('verify_error', [
            'tran_ref' => $tran_ref,
            'error' => $response->get_error_message(),
        ]);
        
        return [
            'success' => false,
            'error' => $response->get_error_message(),
        ];
    }
    
    $body = json_decode(wp_remote_retrieve_body($response), true);
    
    paytabs_log_transaction('verify', [
        'tran_ref' => $tran_ref,
        'response' => $body,
    ]);
    
    // Check if payment was approved
    if (isset($body['payment_result']['response_status']) && 
        $body['payment_result']['response_status'] === 'A') {
        return [
            'success' => true,
            'status' => 'approved',
            'amount' => $body['cart_amount'] ?? 0,
            'currency' => $body['cart_currency'] ?? '',
            'cart_id' => $body['cart_id'] ?? '',
            'transaction_id' => $body['tran_ref'] ?? '',
            'response' => $body,
        ];
    }
    
    return [
        'success' => false,
        'status' => 'declined',
        'message' => $body['payment_result']['response_message'] ?? 'Payment was not approved',
        'response' => $body,
    ];
}

/**
 * Log PayTabs transaction for debugging
 * 
 * @param string $type Transaction type (initiate, verify, callback, etc.)
 * @param array $data Transaction data
 */
function paytabs_log_transaction($type, $data) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'paytabs_logs';
    
    // Create table if it doesn't exist
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        log_type varchar(50) NOT NULL,
        log_data longtext NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY log_type (log_type),
        KEY created_at (created_at)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    // Insert log entry
    $wpdb->insert(
        $table_name,
        [
            'log_type' => $type,
            'log_data' => json_encode($data),
        ],
        ['%s', '%s']
    );
}

/**
 * Add custom rewrite rules for payment endpoints
 */
function paytabs_add_rewrite_rules() {
    add_rewrite_rule('^payment-return/?', 'index.php?payment_return=1', 'top');
    add_rewrite_rule('^payment-callback/?', 'index.php?payment_callback=1', 'top');
}
add_action('init', 'paytabs_add_rewrite_rules');

/**
 * Add query vars for payment endpoints
 */
function paytabs_query_vars($vars) {
    $vars[] = 'payment_return';
    $vars[] = 'payment_callback';
    return $vars;
}
add_filter('query_vars', 'paytabs_query_vars');

/**
 * Handle PayTabs callback/webhook
 * 
 * ENHANCED: Now creates user and completes booking immediately on successful payment.
 * This ensures booking is never lost even if user closes browser after payment.
 */
function paytabs_handle_callback() {
    if (get_query_var('payment_callback')) {
        $json = file_get_contents('php://input');
        $data = json_decode($json, true);
        
        error_log('=== PAYTABS IPN CALLBACK RECEIVED ===');
        paytabs_log_transaction('callback', $data);
        
        // Validate callback data
        if (!isset($data['cart_id']) || empty($data['cart_id'])) {
            error_log('IPN ERROR: No cart_id in callback data');
            wp_send_json(['status' => 'error', 'message' => 'No cart_id']);
            exit;
        }
        
        $booking_token = sanitize_text_field($data['cart_id']);
        $cart_key = 'retreat_' . $booking_token;
        $booking_data = get_transient($cart_key);
        
        if (!$booking_data) {
            error_log('IPN ERROR: Transient not found for token: ' . $booking_token);
            wp_send_json(['status' => 'error', 'message' => 'Booking data not found']);
            exit;
        }
        
        // Store callback data
        $booking_data['payment_callback'] = $data;
        $booking_data['tran_ref'] = $data['tran_ref'] ?? ($booking_data['tran_ref'] ?? '');
        
        // Check payment status
        $payment_status = $data['payment_result']['response_status'] ?? '';
        
        if ($payment_status === 'A') {
            // Payment APPROVED - Create booking immediately!
            error_log('IPN: Payment APPROVED for token: ' . $booking_token);
            
            $booking_data['payment_status'] = 'completed';
            $booking_data['booking_state'] = 'payment_completed';
            $booking_data['ipn_processed_at'] = current_time('mysql');
            
            // ★★★ CRITICAL: Create booking immediately ★★★
            $booking_result = process_retreat_booking_from_ipn($booking_data, $booking_token);
            
            if ($booking_result['success']) {
                // Update transient with user_id and new state
                $booking_data['user_id'] = $booking_result['user_id'];
                $booking_data['booking_state'] = 'booking_confirmed';
                $booking_data['booking_created_at'] = current_time('mysql');
                error_log('IPN SUCCESS: Retreat booking auto-created for user ID: ' . $booking_result['user_id']);
            } else {
                // Log error but keep transient for fallback/manual recovery
                $booking_data['booking_state'] = 'payment_completed'; // Payment done but booking failed
                if (!isset($booking_data['booking_errors'])) {
                    $booking_data['booking_errors'] = [];
                }
                $booking_data['booking_errors'][] = [
                    'timestamp' => current_time('mysql'),
                    'message' => $booking_result['message']
                ];
                error_log('IPN ERROR: Failed to create booking: ' . $booking_result['message']);
                
                // Send admin notification about failed booking
                wp_mail(
                    get_option('admin_email'),
                    '[URGENT] Retreat IPN Booking Failed - Manual Review Required',
                    "A retreat booking failed to process after successful payment.\n\n" .
                    "Booking Token: {$booking_token}\n" .
                    "Error: " . $booking_result['message'] . "\n" .
                    "User Email: " . ($booking_data['personal_info']['email'] ?? 'N/A') . "\n" .
                    "Amount Paid: " . ($booking_data['amount'] ?? 'N/A') . " " . ($booking_data['currency'] ?? 'SAR') . "\n\n" .
                    "Transaction Reference: " . ($booking_data['tran_ref'] ?? 'N/A') . "\n\n" .
                    "Please manually create the booking in WordPress admin.",
                    ['Content-Type: text/plain; charset=UTF-8']
                );
            }
            
        } else {
            // Payment FAILED or DECLINED
            error_log('IPN: Payment FAILED for token: ' . $booking_token . ' (status: ' . $payment_status . ')');
            $booking_data['payment_status'] = 'failed';
            $booking_data['booking_state'] = 'failed';
            $booking_data['failure_reason'] = $data['payment_result']['response_message'] ?? 'Unknown';
        }
        
        // Save updated transient with 24-hour TTL (increased for recovery window)
        set_transient($cart_key, $booking_data, 86400);
        
        // Respond to PayTabs
        wp_send_json(['status' => 'received']);
        exit;
    }
}
add_action('template_redirect', 'paytabs_handle_callback');

// ============================================================================
// SECTION 1.5: IPN-DRIVEN BOOKING FUNCTIONS
// ============================================================================

/**
 * Process retreat booking immediately from IPN/webhook
 * 
 * This function creates the user account, assigns them to the retreat,
 * enrolls them in BuddyPress chat group, and sends confirmation email.
 * Idempotent: Can be safely called multiple times with same data.
 * 
 * @param array $booking_data Booking information from transient
 * @param string $booking_token Unique booking token
 * @return array ['success' => bool, 'user_id' => int|null, 'message' => string]
 */
function process_retreat_booking_from_ipn($booking_data, $booking_token) {
    global $wpdb;
    
    error_log('=== IPN BOOKING PROCESSOR START ===');
    error_log('Token: ' . $booking_token);
    
    // ============================================
    // 1. IDEMPOTENCY CHECK
    // ============================================
    // If user already created (by previous IPN or frontend), return success
    if (!empty($booking_data['user_id'])) {
        $existing_user = get_user_by('id', $booking_data['user_id']);
        if ($existing_user) {
            error_log('IPN: User already exists (ID: ' . $booking_data['user_id'] . '), skipping creation');
            return [
                'success' => true,
                'user_id' => $booking_data['user_id'],
                'message' => 'User already created'
            ];
        }
    }
    
    $personal_info = $booking_data['personal_info'] ?? [];
    $email = $personal_info['email'] ?? '';
    
    if (empty($email)) {
        error_log('IPN ERROR: No email in booking data');
        return [
            'success' => false,
            'user_id' => null,
            'message' => 'No email in booking data'
        ];
    }
    
    // Check if email already registered
    $existing_user = get_user_by('email', $email);
    if ($existing_user) {
        // Email exists - use existing account
        $user_id = $existing_user->ID;
        error_log('IPN: Email exists, using existing user ID: ' . $user_id);
    } else {
        // ============================================
        // 2. CREATE NEW USER ACCOUNT
        // ============================================
        $username = sanitize_user($email);
        $password = $personal_info['password'] ?? wp_generate_password(12, true);
        
        $user_id = wp_create_user($username, $password, $email);
        
        if (is_wp_error($user_id)) {
            error_log('IPN ERROR: Failed to create user: ' . $user_id->get_error_message());
            return [
                'success' => false,
                'user_id' => null,
                'message' => 'Failed to create user: ' . $user_id->get_error_message()
            ];
        }
        
        error_log('IPN: Created new user ID: ' . $user_id);
    }
    
    // ============================================
    // 3. SAVE USER METADATA
    // ============================================
    $full_name = $personal_info['full_name'] ?? '';
    $names = explode(' ', trim($full_name), 2);
    $first_name = $names[0] ?? '';
    
    update_user_meta($user_id, 'full_name', $full_name);
    update_user_meta($user_id, 'first_name', $first_name);
    update_user_meta($user_id, 'phone', $personal_info['phone'] ?? '');
    update_user_meta($user_id, 'country', $personal_info['country'] ?? '');
    update_user_meta($user_id, 'gender', $personal_info['gender'] ?? '');
    update_user_meta($user_id, 'birth_date', $personal_info['birth_date'] ?? '');
    update_user_meta($user_id, 'retreat_type', $booking_data['retreat_type'] ?? '');
    
    // Payment metadata
    update_user_meta($user_id, 'payment_transaction_id', $booking_data['tran_ref'] ?? '');
    update_user_meta($user_id, 'payment_amount', $booking_data['amount'] ?? 0);
    
    // Passport file
    if (!empty($booking_data['passport_url'])) {
        update_user_meta($user_id, 'passport_scan', $booking_data['passport_url']);
    }
    
    // ============================================
    // 4. ASSIGN TO RETREAT GROUP (CRITICAL!)
    // ============================================
    $group_id = intval($booking_data['group_id'] ?? 0);
    if ($group_id <= 0) {
        error_log('IPN ERROR: Invalid group_id: ' . $group_id);
        return [
            'success' => false,
            'user_id' => $user_id,
            'message' => 'Invalid group_id: ' . $group_id
        ];
    }
    
    $update_result = update_user_meta($user_id, 'assigned_retreat_group', $group_id);
    
    if ($update_result === false) {
        // Check if same value already exists (update returns false if no change)
        $existing_group = get_user_meta($user_id, 'assigned_retreat_group', true);
        if ($existing_group != $group_id) {
            error_log('IPN ERROR: Failed to assign user to retreat group');
            return [
                'success' => false,
                'user_id' => $user_id,
                'message' => 'Failed to assign user to retreat group'
            ];
        }
    }
    
    error_log('IPN: Assigned user ' . $user_id . ' to retreat group: ' . $group_id);
    
    // ============================================
    // 5. ENROLL IN BUDDYPRESS CHAT GROUP
    // ============================================
    if (function_exists('enroll_retreat_user_to_bp_chat_group')) {
        // Get retreat start date for nickname
        $start_date = '';
        if ($group_id > 0 && function_exists('get_field')) {
            $start_date = get_field('start_date', $group_id);
        }
        
        error_log('IPN: Enrolling user in BuddyPress chat group');
        error_log('IPN: Retreat type: ' . ($booking_data['retreat_type'] ?? 'unknown'));
        error_log('IPN: First name: ' . $first_name);
        error_log('IPN: Start date: ' . $start_date);
        
        $bp_result = enroll_retreat_user_to_bp_chat_group(
            $user_id,
            $booking_data['retreat_type'] ?? '',
            $first_name,
            $start_date
        );
        
        if ($bp_result) {
            error_log('IPN: ✓ Enrolled user in BuddyPress chat group');
        } else {
            error_log('IPN WARNING: BuddyPress enrollment failed (non-fatal)');
            // Don't fail the entire booking for BP enrollment failure
        }
    } else {
        error_log('IPN WARNING: enroll_retreat_user_to_bp_chat_group function not available');
    }
    
    // ============================================
    // 6. SEND CONFIRMATION EMAIL
    // ============================================
    $retreat_title = get_the_title($group_id);
    $start_date = '';
    $end_date = '';
    $destination = '';
    
    if (function_exists('get_field')) {
        $start_date = get_field('start_date', $group_id);
        $end_date = get_field('end_date', $group_id);
        $destination = get_field('trip_destination', $group_id);
    }
    
    $email_subject = 'Retreat Booking Confirmation - Tanafs';
    $email_body = "Dear {$full_name},\n\n";
    $email_body .= "Thank you for booking your retreat with Tanafs!\n\n";
    $email_body .= "Your retreat booking has been confirmed.\n\n";
    $email_body .= "Retreat: {$retreat_title}\n";
    if ($start_date && $end_date) {
        $email_body .= "Dates: {$start_date} to {$end_date}\n";
    }
    if ($destination) {
        $email_body .= "Destination: {$destination}\n";
    }
    $email_body .= "\nPayment Transaction ID: " . ($booking_data['tran_ref'] ?? 'N/A') . "\n";
    $email_body .= "Amount Paid: " . ($booking_data['amount'] ?? '0') . " " . ($booking_data['currency'] ?? 'SAR') . "\n\n";
    $email_body .= "Please complete the wellness questionnaire when you return to the site.\n\n";
    $email_body .= "You can login at: " . wp_login_url() . "\n\n";
    $email_body .= "We look forward to seeing you!\n\n";
    $email_body .= "Best regards,\nTanafs Team";
    
    wp_mail($email, $email_subject, $email_body);
    
    error_log('IPN: Sent confirmation email to: ' . $email);
    
    // ============================================
    // 7. RETURN SUCCESS
    // ============================================
    error_log('=== IPN BOOKING PROCESSOR SUCCESS ===');
    error_log('User ID: ' . $user_id);
    
    return [
        'success' => true,
        'user_id' => $user_id,
        'message' => 'Booking created successfully'
    ];
}

/**
 * Save questionnaire answers to database
 * Extracted for reusability between IPN and frontend flows
 * 
 * @param int $user_id WordPress user ID
 * @param string $questionnaire_json JSON encoded questionnaire answers
 * @param array $booking_data Booking data containing retreat_type and group_id
 * @return bool True on success, false on failure
 */
function save_retreat_questionnaire_answers($user_id, $questionnaire_json, $booking_data) {
    global $wpdb;
    
    $questionnaire = json_decode(stripslashes($questionnaire_json), true);
    
    if (!is_array($questionnaire) || empty($questionnaire)) {
        error_log('Invalid questionnaire data for user: ' . $user_id);
        return false;
    }
    
    // Define questionnaire questions (must match retreat_system.php)
    $questions = [
        'Do you have any chronic illnesses?',
        'Have you had any previous surgeries or injuries?',
        'Have you ever been diagnosed with any psychological disorder?',
        'Are you currently taking any psychiatric or other medications?',
        'How are you feeling during this period of your life?',
        'How do your emotions affect your daily life and relationships?',
        'Do you have any fears or challenges you would like to discuss in the group?',
        'How do you think group therapy sessions can support you in achieving what you are aiming for?',
        'What steps have you taken so far to overcome the challenges you are facing?',
        'What is your level of comfort with sharing and expressing your emotions within a group?',
        'Have you practiced yoga before?',
        'Do you have any food allergies or follow any specific dietary restrictions?'
    ];
    
    $table_name = $wpdb->prefix . 'retreat_questionnaire_answers';
    
    error_log('=== SAVING QUESTIONNAIRE ANSWERS (HELPER) ===');
    error_log('User ID: ' . $user_id);
    error_log('Number of answers: ' . count($questionnaire));
    
    // Delete old answers for this user if any (to allow re-submission)
    $deleted = $wpdb->delete($table_name, ['user_id' => $user_id], ['%d']);
    if ($deleted) {
        error_log("Deleted {$deleted} old questionnaire answers for user {$user_id}");
    }
    
    $saved_count = 0;
    foreach ($questionnaire as $index => $answer) {
        if (isset($questions[$index]) && !empty(trim($answer))) {
            $insert_result = $wpdb->insert(
                $table_name,
                [
                    'user_id' => $user_id,
                    'retreat_type' => $booking_data['retreat_type'] ?? '',
                    'retreat_group_id' => $booking_data['group_id'] ?? 0,
                    'question_number' => intval($index) + 1,
                    'question_text' => $questions[$index],
                    'answer' => sanitize_textarea_field($answer)
                ],
                ['%d', '%s', '%d', '%d', '%s', '%s']
            );
            
            if ($insert_result) {
                $saved_count++;
            } else {
                error_log("Failed to insert answer for question {$index}: " . $wpdb->last_error);
            }
        }
    }
    
    error_log("Saved {$saved_count} questionnaire answers for user {$user_id}");
    return $saved_count > 0;
}

// ============================================================================
// SECTION 2: RETREAT-SPECIFIC AJAX HANDLERS
// ============================================================================

/**
 * AJAX Handler: Save retreat booking data to transient before payment
 * 
 * Generates unique token, stores all form data temporarily (1 hour)
 * Returns token to frontend for payment initiation
 */
function ajax_save_retreat_booking_data() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'retreat_nonce')) {
        wp_send_json_error(['message' => 'Security verification failed']);
        return;
    }
    
    // Generate unique booking token
    $booking_token = bin2hex(random_bytes(16));
    
    // DEBUG: Log received POST data
    error_log('=== SAVE BOOKING DATA ===');
    error_log('POST group_id: ' . ($_POST['group_id'] ?? 'NOT SET'));
    error_log('POST retreat_type: ' . ($_POST['retreat_type'] ?? 'NOT SET'));
    error_log('POST amount: ' . ($_POST['amount'] ?? 'NOT SET'));
    
    // Prepare booking data
    $booking_data = [
        'personal_info' => [
            'full_name' => sanitize_text_field($_POST['full_name'] ?? ''),
            'email' => sanitize_email($_POST['email'] ?? ''),
            'phone' => sanitize_text_field($_POST['phone'] ?? ''),
            'country' => sanitize_text_field($_POST['country'] ?? ''),
            'gender' => sanitize_text_field($_POST['gender'] ?? ''),
            'birth_date' => sanitize_text_field($_POST['birth_date'] ?? ''),
            'password' => $_POST['password'] ?? '', // Store password for later user creation
        ],
        'retreat_type' => sanitize_text_field($_POST['retreat_type'] ?? ''),
        'group_id' => intval($_POST['group_id'] ?? 0),
        'amount' => floatval($_POST['amount'] ?? 0),
        'currency' => get_option('paytabs_currency', 'SAR'),
        'payment_status' => 'pending',
        'created_at' => current_time('mysql'),
        'return_url' => esc_url_raw($_POST['return_url'] ?? ''), // Store the page URL to return to
        'scroll_to_section' => sanitize_text_field($_POST['scroll_to_section'] ?? ''), // Store section ID for scrolling
    ];
    
    error_log('Parsed group_id: ' . $booking_data['group_id']);
    error_log('Parsed retreat_type: ' . $booking_data['retreat_type']);
    
    // Handle passport file upload if present
    if (!empty($_FILES['passport'])) {
        $upload = wp_handle_upload($_FILES['passport'], ['test_form' => false]);
        if (isset($upload['file'])) {
            $booking_data['passport_file'] = $upload['file'];
            $booking_data['passport_url'] = $upload['url'];
        }
    }
    
    // Store in transient (1 hour expiry)
    $transient_key = 'retreat_' . $booking_token;
    set_transient($transient_key, $booking_data, 3600);
    
    wp_send_json_success([
        'message' => 'Booking data saved successfully',
        'token' => $booking_token,
    ]);
}
add_action('wp_ajax_save_retreat_booking_data', 'ajax_save_retreat_booking_data');
add_action('wp_ajax_nopriv_save_retreat_booking_data', 'ajax_save_retreat_booking_data');

/**
 * AJAX Handler: Initiate PayTabs payment session
 * 
 * Retrieves booking data from transient, calls PayTabs API
 * Returns redirect URL to frontend
 */
function ajax_initiate_retreat_payment() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'retreat_nonce')) {
        wp_send_json_error(['message' => 'Security verification failed']);
        return;
    }
    
    $booking_token = sanitize_text_field($_POST['token'] ?? '');
    
    if (empty($booking_token)) {
        wp_send_json_error(['message' => 'Invalid booking token']);
        return;
    }
    
    // Retrieve booking data from transient
    $transient_key = 'retreat_' . $booking_token;
    $booking_data = get_transient($transient_key);
    
    if (!$booking_data) {
        wp_send_json_error(['message' => 'Booking session expired. Please start over.']);
        return;
    }
    
    // Prepare customer details for PayTabs
    $customer_details = [
        'name' => $booking_data['personal_info']['full_name'],
        'email' => $booking_data['personal_info']['email'],
        'phone' => $booking_data['personal_info']['phone'],
        'street1' => 'N/A',
        'city' => $booking_data['personal_info']['country'] ?? 'N/A',
        'state' => 'N/A',
        'country' => 'SA',
        'zip' => '00000',
    ];
    
    // Use stored return URL or fallback to home
    $return_page_url = !empty($booking_data['return_url']) ? $booking_data['return_url'] : home_url('/');
    
    // Initiate payment with PayTabs
    $result = paytabs_initiate_payment(
        $booking_token,
        $booking_data['amount'],
        $customer_details,
        [
            'currency' => $booking_data['currency'],
            'description' => 'Retreat Booking - ' . $booking_data['retreat_type'],
            'retreat_page_url' => $return_page_url,
        ]
    );
    
    if ($result['success']) {
        // Update transient with payment reference
        $booking_data['tran_ref'] = $result['tran_ref'];
        set_transient($transient_key, $booking_data, 3600);
        
        wp_send_json_success([
            'redirect_url' => $result['redirect_url'],
        ]);
    } else {
        $error_message = $result['error'] ?? 'Failed to initiate payment';
        
        // Add more details if available
        if (isset($result['details'])) {
            $error_message .= ' Details: ' . json_encode($result['details']);
        }
        
        wp_send_json_error([
            'message' => $error_message,
        ]);
    }
}
add_action('wp_ajax_initiate_retreat_payment', 'ajax_initiate_retreat_payment');
add_action('wp_ajax_nopriv_initiate_retreat_payment', 'ajax_initiate_retreat_payment');

/**
 * AJAX Handler: Verify payment status after return from PayTabs
 * 
 * ENHANCED: Now checks if IPN already created the user and auto-logs them in.
 * Called when user returns to retreat page.
 */
function ajax_verify_retreat_payment_status() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'retreat_nonce')) {
        wp_send_json_error(['message' => 'Security verification failed']);
        return;
    }
    
    $booking_token = sanitize_text_field($_POST['token'] ?? '');
    
    if (empty($booking_token)) {
        wp_send_json_error(['message' => 'Invalid booking token']);
        return;
    }
    
    // Retrieve booking data
    $transient_key = 'retreat_' . $booking_token;
    $booking_data = get_transient($transient_key);
    
    if (!$booking_data) {
        wp_send_json_error(['message' => 'Booking session expired']);
        return;
    }
    
    // Check if payment is already completed (either via callback or IPN)
    $payment_verified = false;
    
    if (isset($booking_data['payment_status']) && $booking_data['payment_status'] === 'completed') {
        $payment_verified = true;
    } elseif (isset($booking_data['tran_ref']) && !empty($booking_data['tran_ref'])) {
        // Verify with PayTabs API if payment status is still pending
        $verification = paytabs_verify_payment($booking_data['tran_ref']);
        
        if ($verification['success']) {
            $payment_verified = true;
            $booking_data['payment_status'] = 'completed';
            $booking_data['payment_verification'] = $verification;
            
            // ★★★ If IPN didn't process yet, create booking now (fallback) ★★★
            if (empty($booking_data['user_id'])) {
                error_log('VERIFY: IPN has not processed yet, creating booking now (fallback)');
                $booking_result = process_retreat_booking_from_ipn($booking_data, $booking_token);
                
                if ($booking_result['success']) {
                    $booking_data['user_id'] = $booking_result['user_id'];
                    $booking_data['booking_state'] = 'booking_confirmed';
                    $booking_data['booking_created_at'] = current_time('mysql');
                    error_log('VERIFY: Fallback booking created for user ID: ' . $booking_result['user_id']);
                } else {
                    error_log('VERIFY ERROR: Fallback booking failed: ' . $booking_result['message']);
                }
            }
            
            // Save updated transient with 24-hour TTL
            set_transient($transient_key, $booking_data, 86400);
        } else {
            // Payment failed
            $booking_data['payment_status'] = 'failed';
            set_transient($transient_key, $booking_data, 86400);
            
            wp_send_json_error([
                'payment_verified' => false,
                'message' => $verification['message'] ?? 'Payment verification failed',
            ]);
            return;
        }
    } else {
        // No transaction reference - payment not initiated properly
        wp_send_json_error([
            'payment_verified' => false,
            'message' => 'Payment not completed. Status: ' . ($booking_data['payment_status'] ?? 'unknown'),
        ]);
        return;
    }
    
    // Payment is verified - now handle user session
    $user_already_created = false;
    $fresh_nonce = '';
    
    if (!empty($booking_data['user_id'])) {
        $user = get_user_by('id', $booking_data['user_id']);
        if ($user) {
            $user_already_created = true;
            
            // Auto-login the user
            if (get_current_user_id() !== $booking_data['user_id']) {
                wp_set_current_user($booking_data['user_id']);
                wp_set_auth_cookie($booking_data['user_id'], true);
                do_action('wp_login', $user->user_login, $user);
                error_log('VERIFY: Auto-logged in user ID: ' . $booking_data['user_id']);
                
                // Generate fresh nonce for the newly logged-in user
                $fresh_nonce = wp_create_nonce('retreat_nonce');
                error_log('VERIFY: Generated fresh nonce for logged-in user');
            }
        }
    }
    
    wp_send_json_success([
        'payment_verified' => true,
        'user_already_created' => $user_already_created,
        'booking_state' => $booking_data['booking_state'] ?? 'unknown',
        'message' => 'Payment verified successfully',
        'retreat_type' => $booking_data['retreat_type'] ?? '',
        'scroll_to_section' => $booking_data['scroll_to_section'] ?? '',
        'fresh_nonce' => $fresh_nonce, // Return fresh nonce if user was auto-logged in
    ]);
}
add_action('wp_ajax_verify_retreat_payment_status', 'ajax_verify_retreat_payment_status');
add_action('wp_ajax_nopriv_verify_retreat_payment_status', 'ajax_verify_retreat_payment_status');

/**
 * AJAX Handler: Complete retreat registration after payment verification
 * 
 * ENHANCED: Now checks if user was already created by IPN.
 * - If user exists (created by IPN): Only saves questionnaire answers
 * - If user doesn't exist (fallback): Creates user and saves questionnaire
 * 
 * Sends confirmation email and auto-logs in the user.
 */
function ajax_complete_retreat_registration() {
    // Verify nonce - check both logged-in and logged-out contexts
    $nonce_valid = false;
    
    if (isset($_POST['nonce'])) {
        // Try to verify nonce (works for both logged-in and logged-out if nonce was generated in the correct context)
        $nonce_valid = wp_verify_nonce($_POST['nonce'], 'retreat_nonce');
        
        // Additional verification: if user is logged in, check if they have a valid booking token
        if (!$nonce_valid && is_user_logged_in()) {
            error_log('QUESTIONNAIRE: Nonce verification failed for logged-in user, checking token validity');
            // If nonce fails but user is logged in and has valid token, allow it
            // This handles the case where user was auto-logged in after nonce generation
            $booking_token = sanitize_text_field($_POST['token'] ?? '');
            if (!empty($booking_token)) {
                $transient_key = 'retreat_' . $booking_token;
                $booking_data = get_transient($transient_key);
                // If transient exists and payment is completed, allow the submission
                if ($booking_data && $booking_data['payment_status'] === 'completed') {
                    $nonce_valid = true;
                    error_log('QUESTIONNAIRE: Allowing submission based on valid booking token');
                }
            }
        }
    }
    
    if (!$nonce_valid) {
        error_log('QUESTIONNAIRE ERROR: Security verification failed');
        error_log('User logged in: ' . (is_user_logged_in() ? 'yes' : 'no'));
        error_log('User ID: ' . get_current_user_id());
        wp_send_json_error(['message' => 'Security verification failed']);
        return;
    }
    
    $booking_token = sanitize_text_field($_POST['token'] ?? '');
    
    if (empty($booking_token)) {
        wp_send_json_error(['message' => 'Invalid booking token']);
        return;
    }
    
    // Retrieve booking data
    $transient_key = 'retreat_' . $booking_token;
    $booking_data = get_transient($transient_key);
    
    if (!$booking_data) {
        wp_send_json_error(['message' => 'Booking session expired']);
        return;
    }
    
    // Verify payment status
    if ($booking_data['payment_status'] !== 'completed') {
        wp_send_json_error(['message' => 'Payment verification failed. Please contact support.']);
        return;
    }
    
    // Get questionnaire data
    $questionnaire_json = '';
    if (isset($_POST['questionnaire_answers'])) {
        $questionnaire_json = $_POST['questionnaire_answers'];
    }
    
    error_log('=== COMPLETE REGISTRATION (QUESTIONNAIRE SUBMISSION) ===');
    error_log('Token: ' . $booking_token);
    error_log('Booking state: ' . ($booking_data['booking_state'] ?? 'unknown'));
    error_log('User ID in transient: ' . ($booking_data['user_id'] ?? 'NOT SET'));
    error_log('Has questionnaire data: ' . (!empty($questionnaire_json) ? 'YES' : 'NO'));
    error_log('Questionnaire JSON length: ' . strlen($questionnaire_json));
    
    // ============================================
    // CHECK IF IPN ALREADY CREATED THE USER
    // ============================================
    $user_created_by_ipn = false;
    $user_id = null;
    
    if (!empty($booking_data['user_id'])) {
        $existing_user = get_user_by('id', $booking_data['user_id']);
        if ($existing_user) {
            $user_created_by_ipn = true;
            $user_id = $booking_data['user_id'];
            error_log('QUESTIONNAIRE: User already created by IPN (ID: ' . $user_id . '), saving answers only');
        }
    }
    
    // ============================================
    // FALLBACK: CREATE USER IF IPN DIDN'T
    // ============================================
    if (!$user_created_by_ipn) {
        error_log('QUESTIONNAIRE: IPN did not create user, using fallback path');
        
        // Use the IPN processor function as fallback
        $booking_result = process_retreat_booking_from_ipn($booking_data, $booking_token);
        
        if (!$booking_result['success']) {
            wp_send_json_error(['message' => $booking_result['message']]);
            return;
        }
        
        $user_id = $booking_result['user_id'];
        
        // Update transient with user_id
        $booking_data['user_id'] = $user_id;
        $booking_data['booking_state'] = 'booking_confirmed';
        $booking_data['booking_created_at'] = current_time('mysql');
    }
    
    // ============================================
    // SAVE QUESTIONNAIRE ANSWERS
    // ============================================
    if (!empty($questionnaire_json)) {
        // Verify questionnaire data is valid JSON and not empty
        $test_decode = json_decode(stripslashes($questionnaire_json), true);
        if (is_array($test_decode) && count($test_decode) > 0) {
            error_log('QUESTIONNAIRE: Valid questionnaire data received, saving...');
            $save_result = save_retreat_questionnaire_answers($user_id, $questionnaire_json, $booking_data);
            
            if ($save_result) {
                error_log('QUESTIONNAIRE: Answers saved successfully for user ' . $user_id);
                $booking_data['questionnaire_submitted_at'] = current_time('mysql');
            } else {
                error_log('QUESTIONNAIRE WARNING: Failed to save some answers for user ' . $user_id);
                // Don't fail the whole registration if questionnaire save has issues
            }
        } else {
            error_log('QUESTIONNAIRE WARNING: Invalid or empty questionnaire data, skipping save');
        }
    } else {
        error_log('QUESTIONNAIRE WARNING: No questionnaire data provided, skipping save');
    }
    
    // ============================================
    // UPDATE BOOKING STATE
    // ============================================
    $booking_data['booking_state'] = 'fully_completed';
    set_transient($transient_key, $booking_data, 86400);
    
    // ============================================
    // AUTO-LOGIN THE USER
    // ============================================
    $user = get_user_by('id', $user_id);
    if ($user && get_current_user_id() !== $user_id) {
        error_log("QUESTIONNAIRE: Auto-logging in user {$user_id}");
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, true);
        do_action('wp_login', $user->user_login, $user);
        error_log("QUESTIONNAIRE: User {$user_id} logged in successfully");
    }
    
    // ============================================
    // CLEAN UP TRANSIENT (after a delay to allow for any retries)
    // ============================================
    // Keep transient for 1 hour after completion for any edge cases
    // It will auto-expire after 24 hours anyway
    
    error_log('=== REGISTRATION FULLY COMPLETED ===');
    error_log('User ID: ' . $user_id);
    
    wp_send_json_success([
        'message' => 'Registration completed successfully!',
        'user_id' => $user_id,
        'user_created' => !$user_created_by_ipn,
        'logged_in' => true,
        'redirect_url' => home_url('/groups/'),
    ]);
}
add_action('wp_ajax_complete_retreat_registration', 'ajax_complete_retreat_registration');
add_action('wp_ajax_nopriv_complete_retreat_registration', 'ajax_complete_retreat_registration');

/**
 * Handle payment callback updates
 * Updates booking data transient when PayTabs sends callback
 */
function handle_retreat_payment_callback($callback_data) {
    if (isset($callback_data['cart_id'])) {
        $transient_key = 'retreat_' . $callback_data['cart_id'];
        $booking_data = get_transient($transient_key);
        
        if ($booking_data) {
            $booking_data['payment_callback'] = $callback_data;
            $booking_data['payment_status'] = ($callback_data['payment_result']['response_status'] === 'A') 
                ? 'completed' 
                : 'failed';
            set_transient($transient_key, $booking_data, 3600);
        }
    }
}

// ============================================================================
// SECTION 3: PAYMENT RETURN PAGE TEMPLATE
// ============================================================================

/**
 * Register custom page template filter
 */
function paytabs_register_template($templates) {
    $templates['template-payment-return.php'] = 'Payment Return Page';
    return $templates;
}
add_filter('theme_page_templates', 'paytabs_register_template');

/**
 * Load custom page template
 */
function paytabs_load_template($template) {
    global $post;
    
    if ($post && get_page_template_slug($post->ID) === 'template-payment-return.php') {
        $plugin_template = __DIR__ . '/template-payment-return.php';
        if (file_exists($plugin_template)) {
            return $plugin_template;
        }
    }
    
    return $template;
}
add_filter('template_include', 'paytabs_load_template');

/**
 * Payment Return Page Template Content
 * This function outputs the HTML for the payment return page
 * Called when user is redirected back from PayTabs
 */
function paytabs_render_return_page() {
    // Get parameters from URL
    $cart_id = isset($_GET['cart_id']) ? sanitize_text_field($_GET['cart_id']) : '';
    $tran_ref = isset($_GET['tranRef']) ? sanitize_text_field($_GET['tranRef']) : '';
    
    $payment_status = 'verifying';
    $payment_message = 'Verifying your payment...';
    $payment_data = [];
    
    // Verify payment if transaction reference is present
    if (!empty($tran_ref)) {
        $verification = paytabs_verify_payment($tran_ref);
        
        if ($verification['success']) {
            $payment_status = 'success';
            $payment_message = 'Payment successful! Please complete the questionnaire below.';
            $payment_data = $verification;
            
            // Update transient with payment status
            if (!empty($cart_id)) {
                $transient_key = 'retreat_' . $cart_id;
                $booking_data = get_transient($transient_key);
                
                if ($booking_data) {
                    $booking_data['payment_status'] = 'completed';
                    $booking_data['payment_verification'] = $verification;
                    set_transient($transient_key, $booking_data, 3600);
                }
            }
        } else {
            $payment_status = 'failed';
            $payment_message = 'Payment verification failed: ' . ($verification['message'] ?? 'Unknown error');
            $payment_data = $verification;
        }
    } elseif (!empty($cart_id)) {
        $payment_status = 'pending';
        $payment_message = 'Waiting for payment confirmation...';
    } else {
        $payment_status = 'error';
        $payment_message = 'Invalid payment session. Please try again.';
    }
    
    // Output HTML
    ?>
    <!DOCTYPE html>
    <html <?php language_attributes(); ?>>
    <head>
        <meta charset="<?php bloginfo('charset'); ?>">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Payment Return - <?php bloginfo('name'); ?></title>
        <?php wp_head(); ?>
        <style>
            .payment-return-container {
                max-width: 800px;
                margin: 50px auto;
                padding: 30px;
                background: #fff;
                border-radius: 10px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            }
            .payment-status {
                text-align: center;
                padding: 30px;
                margin-bottom: 30px;
            }
            .payment-status.success {
                background: #d4edda;
                border: 2px solid #28a745;
                color: #155724;
            }
            .payment-status.failed {
                background: #f8d7da;
                border: 2px solid #dc3545;
                color: #721c24;
            }
            .payment-status.verifying {
                background: #fff3cd;
                border: 2px solid #ffc107;
                color: #856404;
            }
            .payment-status h2 {
                margin: 0 0 15px 0;
                font-size: 24px;
            }
            .payment-status p {
                margin: 0;
                font-size: 16px;
            }
            .payment-details {
                background: #f8f9fa;
                padding: 20px;
                border-radius: 5px;
                margin-top: 20px;
            }
            .payment-details h3 {
                margin-top: 0;
            }
            .payment-detail-row {
                display: flex;
                justify-content: space-between;
                padding: 10px 0;
                border-bottom: 1px solid #dee2e6;
            }
            .payment-detail-row:last-child {
                border-bottom: none;
            }
            .btn-primary {
                background: #007bff;
                color: #fff;
                padding: 12px 30px;
                border: none;
                border-radius: 5px;
                font-size: 16px;
                cursor: pointer;
                text-decoration: none;
                display: inline-block;
                margin-top: 20px;
            }
            .btn-primary:hover {
                background: #0056b3;
            }
            .spinner {
                border: 4px solid #f3f3f3;
                border-top: 4px solid #007bff;
                border-radius: 50%;
                width: 40px;
                height: 40px;
                animation: spin 1s linear infinite;
                margin: 20px auto;
            }
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
        </style>
    </head>
    <body <?php body_class(); ?>>
        
    <div class="payment-return-container">
        <div class="payment-status <?php echo esc_attr($payment_status); ?>">
            <?php if ($payment_status === 'verifying'): ?>
                <div class="spinner"></div>
                <h2>Verifying Payment</h2>
                <p>Please wait while we confirm your payment...</p>
            <?php elseif ($payment_status === 'success'): ?>
                <h2>✓ Payment Successful!</h2>
                <p><?php echo esc_html($payment_message); ?></p>
            <?php elseif ($payment_status === 'failed'): ?>
                <h2>✗ Payment Failed</h2>
                <p><?php echo esc_html($payment_message); ?></p>
            <?php else: ?>
                <h2>Payment Status Unknown</h2>
                <p><?php echo esc_html($payment_message); ?></p>
            <?php endif; ?>
        </div>
        
        <?php if ($payment_status === 'success' && !empty($payment_data)): ?>
            <div class="payment-details">
                <h3>Payment Details</h3>
                <div class="payment-detail-row">
                    <strong>Transaction ID:</strong>
                    <span><?php echo esc_html($payment_data['transaction_id'] ?? 'N/A'); ?></span>
                </div>
                <div class="payment-detail-row">
                    <strong>Amount:</strong>
                    <span><?php echo esc_html($payment_data['amount'] ?? '0'); ?> <?php echo esc_html($payment_data['currency'] ?? ''); ?></span>
                </div>
                <div class="payment-detail-row">
                    <strong>Status:</strong>
                    <span style="color: #28a745; font-weight: bold;">APPROVED</span>
                </div>
            </div>
            
            <!-- Hidden input to pass booking token to questionnaire modal -->
            <input type="hidden" id="booking-token" value="<?php echo esc_attr($cart_id); ?>">
            
            <script>
                // Auto-trigger questionnaire modal after successful payment
                jQuery(document).ready(function($) {
                    setTimeout(function() {
                        // Trigger questionnaire modal open
                        if (typeof openQuestionnaireModal === 'function') {
                            openQuestionnaireModal();
                        } else {
                            // Fallback: trigger the questionnaire modal click
                            $('.retreat-questionnaire-modal').fadeIn();
                        }
                    }, 1500);
                });
            </script>
        <?php elseif ($payment_status === 'failed'): ?>
            <div style="text-align: center;">
                <p>You can try booking again or contact support for assistance.</p>
                <a href="<?php echo home_url('/retreats/'); ?>" class="btn-primary">Return to Retreats</a>
            </div>
        <?php endif; ?>
    </div>
    
    <?php wp_footer(); ?>
    </body>
    </html>
    <?php
}

// Hook to handle the actual template rendering when the template file is loaded
if (get_page_template_slug() === 'template-payment-return.php') {
    add_action('template_redirect', function() {
        if (get_page_template_slug() === 'template-payment-return.php') {
            paytabs_render_return_page();
            exit;
        }
    });
}
