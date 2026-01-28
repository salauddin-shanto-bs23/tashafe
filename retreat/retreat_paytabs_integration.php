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
 */
function paytabs_handle_callback() {
    if (get_query_var('payment_callback')) {
        $json = file_get_contents('php://input');
        $data = json_decode($json, true);
        
        paytabs_log_transaction('callback', $data);
        
        // Update transient if cart_id is present
        if (isset($data['cart_id'])) {
            $cart_key = 'retreat_' . $data['cart_id'];
            $booking_data = get_transient($cart_key);
            
            if ($booking_data) {
                $booking_data['payment_callback'] = $data;
                $booking_data['payment_status'] = ($data['payment_result']['response_status'] === 'A') ? 'completed' : 'failed';
                set_transient($cart_key, $booking_data, 3600);
            }
        }
        
        // Respond to PayTabs
        wp_send_json(['status' => 'received']);
        exit;
    }
}
add_action('template_redirect', 'paytabs_handle_callback');

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
 * Checks if payment was completed successfully
 * Called when user returns to retreat page
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
    
    // If payment status is already completed, return success
    if (isset($booking_data['payment_status']) && $booking_data['payment_status'] === 'completed') {
        wp_send_json_success([
            'payment_verified' => true,
            'message' => 'Payment verified successfully',
            'retreat_type' => $booking_data['retreat_type'] ?? '',
            'scroll_to_section' => $booking_data['scroll_to_section'] ?? '',
        ]);
        return;
    }
    
    // If payment is still pending and we have a transaction reference, verify with PayTabs
    if (isset($booking_data['tran_ref']) && !empty($booking_data['tran_ref'])) {
        $verification = paytabs_verify_payment($booking_data['tran_ref']);
        
        if ($verification['success']) {
            // Payment is approved - update transient
            $booking_data['payment_status'] = 'completed';
            $booking_data['payment_verification'] = $verification;
            set_transient($transient_key, $booking_data, 3600);
            
            wp_send_json_success([
                'payment_verified' => true,
                'message' => 'Payment verified successfully',
                'retreat_type' => $booking_data['retreat_type'] ?? '',
                'scroll_to_section' => $booking_data['scroll_to_section'] ?? '',
            ]);
        } else {
            // Payment failed or declined
            $booking_data['payment_status'] = 'failed';
            set_transient($transient_key, $booking_data, 3600);
            
            wp_send_json_error([
                'payment_verified' => false,
                'message' => $verification['message'] ?? 'Payment verification failed',
            ]);
        }
    } else {
        // No transaction reference - payment not initiated properly
        wp_send_json_error([
            'payment_verified' => false,
            'message' => 'Payment not completed. Status: ' . ($booking_data['payment_status'] ?? 'unknown'),
        ]);
    }
}
add_action('wp_ajax_verify_retreat_payment_status', 'ajax_verify_retreat_payment_status');
add_action('wp_ajax_nopriv_verify_retreat_payment_status', 'ajax_verify_retreat_payment_status');

/**
 * AJAX Handler: Complete retreat registration after payment verification
 * 
 * Verifies payment status, creates user account, saves questionnaire
 * Sends confirmation email
 */
function ajax_complete_retreat_registration() {
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
    
    // Verify payment status
    if ($booking_data['payment_status'] !== 'completed') {
        wp_send_json_error(['message' => 'Payment verification failed. Please contact support.']);
        return;
    }
    
    // Get questionnaire data
    $questionnaire = [];
    if (isset($_POST['questionnaire_answers'])) {
        $questionnaire_json = stripslashes($_POST['questionnaire_answers']);
        $questionnaire = json_decode($questionnaire_json, true);
        if (!is_array($questionnaire)) {
            $questionnaire = [];
        }
    } elseif (isset($_POST['questionnaire']) && is_array($_POST['questionnaire'])) {
        foreach ($_POST['questionnaire'] as $key => $value) {
            $questionnaire[sanitize_key($key)] = sanitize_textarea_field($value);
        }
    }
    
    $personal_info = $booking_data['personal_info'];
    
    // Prepare user account details
    $username = sanitize_user($personal_info['email']);
    $email = $personal_info['email'];
    $password = $personal_info['password']; // Use password from registration form
    
    // Check if user already exists
    $existing_user_id = email_exists($email);
    $user_created = false;
    
    if ($existing_user_id) {
        // User already exists - use existing account (seamless for retreat booking)
        $user_id = $existing_user_id;
        error_log("RETREAT: Using existing user account (ID: {$user_id}) for email: {$email}");
    } else {
        // Create new user account
        $user_id = wp_create_user($username, $password, $email);
        
        if (is_wp_error($user_id)) {
            wp_send_json_error(['message' => 'Failed to create user account: ' . $user_id->get_error_message()]);
            return;
        }
        
        $user_created = true;
        error_log("RETREAT: Created new user account (ID: {$user_id}) for email: {$email}");
    }
    
    // Update user metadata (for both new and existing users)
    update_user_meta($user_id, 'full_name', $personal_info['full_name']);
    update_user_meta($user_id, 'first_name', $personal_info['full_name']);
    update_user_meta($user_id, 'phone', $personal_info['phone']);
    update_user_meta($user_id, 'country', $personal_info['country']);
    update_user_meta($user_id, 'gender', $personal_info['gender']);
    update_user_meta($user_id, 'birth_date', $personal_info['birth_date']);
    update_user_meta($user_id, 'retreat_type', $booking_data['retreat_type']);
    update_user_meta($user_id, 'payment_transaction_id', $booking_data['tran_ref'] ?? '');
    update_user_meta($user_id, 'payment_amount', $booking_data['amount']);
    
    // Save passport file reference
    if (isset($booking_data['passport_url'])) {
        update_user_meta($user_id, 'passport_scan', $booking_data['passport_url']);
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
    
    // Save questionnaire answers to database with correct schema
    global $wpdb;
    $table_name = $wpdb->prefix . 'retreat_questionnaire_answers';
    
    error_log('=== SAVING QUESTIONNAIRE ANSWERS ===');
    error_log('Questionnaire data: ' . print_r($questionnaire, true));
    error_log('Number of questions: ' . count($questions));
    
    // Delete old answers for this user if any
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
                    'retreat_type' => $booking_data['retreat_type'],
                    'retreat_group_id' => $booking_data['group_id'],
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
    
    // DEBUG: Log registration attempt
    error_log('=== COMPLETE REGISTRATION ===');
    error_log('Token: ' . $booking_token);
    error_log('User ID created: ' . $user_id);
    error_log('Booking data group_id: ' . ($booking_data['group_id'] ?? 'NOT SET'));
    error_log('Booking data retreat_type: ' . ($booking_data['retreat_type'] ?? 'NOT SET'));
    
    // Register user for retreat by setting user metadata
    // NOTE: This system uses user metadata, NOT BuddyPress groups
    if (empty($booking_data['group_id']) || intval($booking_data['group_id']) === 0) {
        error_log('RETREAT ERROR: group_id is empty or zero!');
        error_log('Full booking data: ' . print_r($booking_data, true));
        wp_send_json_error(['message' => 'Registration error: No retreat group selected. Please contact support.']);
        return;
    }
    
    // Assign user to retreat group using metadata
    $update_result = update_user_meta($user_id, 'assigned_retreat_group', $booking_data['group_id']);
    
    if ($update_result === false) {
        error_log('RETREAT ERROR: Failed to assign user to retreat group!');
        error_log('User ID: ' . $user_id . ', Group ID: ' . $booking_data['group_id']);
        wp_send_json_error(['message' => 'Failed to complete retreat registration. Please contact support.']);
        return;
    }
    
    error_log('RETREAT SUCCESS: User ' . $user_id . ' assigned to retreat group ' . $booking_data['group_id']);
    
    // Get retreat start date for BP enrollment
    $start_date = '';
    if (function_exists('get_field')) {
        $start_date = get_field('start_date', $booking_data['group_id']);
    }
    
    // Extract first name from full name
    $first_name = $personal_info['full_name'];
    if (strpos($first_name, ' ') !== false) {
        $first_name = explode(' ', $first_name)[0];
    }
    
    // Enroll user in BuddyPress chat group
    if (function_exists('enroll_retreat_user_to_bp_chat_group')) {
        error_log('=== ENROLLING USER IN BP CHAT ===');
        error_log('User ID: ' . $user_id);
        error_log('Retreat Type: ' . $booking_data['retreat_type']);
        error_log('First Name: ' . $first_name);
        error_log('Start Date: ' . $start_date);
        
        $bp_enrollment_result = enroll_retreat_user_to_bp_chat_group(
            $user_id,
            $booking_data['retreat_type'],
            $first_name,
            $start_date
        );
        
        if ($bp_enrollment_result) {
            error_log("✓ BP chat enrollment successful for user {$user_id}");
        } else {
            error_log("✗ BP chat enrollment failed for user {$user_id} - Check BP logs");
        }
    } else {
        error_log('ERROR: enroll_retreat_user_to_bp_chat_group function not available');
    }
    
    // Send confirmation email
    $to = $email;
    $subject = 'Retreat Booking Confirmation - Tanafs';
    $message = "Dear {$personal_info['full_name']},\n\n";
    $message .= "Thank you for booking your retreat with Tanafs!\n\n";
    
    if ($user_created) {
        $message .= "Your account has been created successfully.\n\n";
        $message .= "Login Details:\n";
        $message .= "Username: {$username}\n";
        $message .= "Password: [The password you created during registration]\n\n";
    } else {
        $message .= "Your retreat booking has been confirmed using your existing account.\n\n";
    }
    
    $message .= "Payment Transaction ID: " . ($booking_data['tran_ref'] ?? 'N/A') . "\n";
    $message .= "Amount Paid: {$booking_data['amount']} {$booking_data['currency']}\n\n";
    $message .= "You can login at: " . wp_login_url() . "\n\n";
    $message .= "We look forward to seeing you!\n\n";
    $message .= "Best regards,\nTanafs Team";
    
    wp_mail($to, $subject, $message);
    
    // Auto-login the user (both new and existing users)
    error_log("RETREAT: Auto-logging in user {$user_id}");
    wp_set_current_user($user_id);
    wp_set_auth_cookie($user_id, true);
    do_action('wp_login', $username, get_user_by('id', $user_id));
    error_log("RETREAT: User {$user_id} logged in successfully");
    
    // Clean up transient
    delete_transient($transient_key);
    
    wp_send_json_success([
        'message' => 'Registration completed successfully!',
        'user_id' => $user_id,
        'user_created' => $user_created,
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
