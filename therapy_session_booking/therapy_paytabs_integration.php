<?php
/**
 * PayTabs Payment Integration for Therapy Session Booking
 * 
 * This file handles payment integration for therapy group bookings:
 * - AJAX handlers for saving booking data and initiating payment
 * - Independent IPN callback processing for therapy bookings
 * - User creation and BuddyPress enrollment on successful payment
 * 
 * Architecture:
 * - Therapy bookings are independent from retreat bookings
 * - Each system handles its own IPN callbacks (cart_id prefix-based routing)
 * - Shares generic PayTabs API functions (paytabs_initiate_payment, etc.)
 *   from retreat_paytabs_integration.php as utility functions
 * - No business logic dependency between retreat and therapy systems
 * 
 * Dependencies: 
 * - retreat/retreat_paytabs_integration.php (for core PayTabs API functions only)
 * - retreat/paytabs_admin.php (for global PayTabs credentials)
 * 
 * @package Tanafs_Therapy
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// ============================================================================
// DEPENDENCY CHECK
// ============================================================================
/**
 * IMPORTANT: This file must be loaded AFTER retreat/retreat_paytabs_integration.php
 * in the Code Snippets plugin (set lower priority number for retreat snippet).
 * 
 * The therapy integration depends on these core PayTabs functions from retreat:
 * - paytabs_initiate_payment()
 * - paytabs_verify_payment()
 * - paytabs_log_transaction()
 * - paytabs_is_configured()
 */
add_action('init', function() {
    if (!function_exists('paytabs_initiate_payment') || !function_exists('paytabs_verify_payment')) {
        error_log('[THERAPY PAYMENT ERROR] Critical dependency missing: retreat_paytabs_integration.php must load first!');
        error_log('[THERAPY PAYMENT ERROR] Please check Code Snippets plugin - retreat snippet must have LOWER priority number than therapy snippet');
    }
});

// ============================================================================
// SECTION 1: AJAX HANDLERS FOR THERAPY PAYMENT FLOW
// ============================================================================

/**
 * AJAX Handler: Save therapy booking data to transient before payment
 * 
 * Generates unique token, stores all form data temporarily (1 hour)
 * Returns token to frontend for payment initiation
 */
add_action('wp_ajax_save_therapy_booking_data', 'ajax_save_therapy_booking_data');
add_action('wp_ajax_nopriv_save_therapy_booking_data', 'ajax_save_therapy_booking_data');

if (!function_exists('ajax_save_therapy_booking_data')) {
function ajax_save_therapy_booking_data() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'therapy_registration_nonce')) {
        wp_send_json_error(['message' => 'Security verification failed']);
        return;
    }

    // Generate unique booking token
    $booking_token = 'therapy_' . bin2hex(random_bytes(16));
    
    // Get therapy group and validate
    $group_id = intval($_POST['selected_group_id'] ?? 0);
    if ($group_id <= 0) {
        wp_send_json_error(['message' => 'Please select a therapy group session']);
        return;
    }
    
    // Verify group exists and is open for registration
    $group_post = get_post($group_id);
    if (!$group_post || $group_post->post_type !== 'therapy_group') {
        wp_send_json_error(['message' => 'Invalid therapy group selected']);
        return;
    }
    
    // Check group availability (if function exists)
    if (function_exists('get_group_availability_status')) {
        $availability = get_group_availability_status($group_id);
        if (isset($availability['is_full']) && $availability['is_full']) {
            wp_send_json_error(['message' => 'This therapy group is full. Please select another session.']);
            return;
        }
    }
    
    // Get therapy price from ACF field (default 3500 for SAR)
    $therapy_price = get_field('therapy_price', $group_id);
    if (empty($therapy_price) || $therapy_price <= 0) {
        $therapy_price = 3500; // Default price
    }
    
    // Sanitize personal info
    $personal_info = [
        'first_name'      => sanitize_text_field($_POST['first_name'] ?? ''),
        'last_name'       => sanitize_text_field($_POST['last_name'] ?? ''),
        'email'           => sanitize_email($_POST['email'] ?? ''),
        'phone'           => sanitize_text_field($_POST['phone'] ?? ''),
        'passport_number' => sanitize_text_field($_POST['passport_number'] ?? ''),
        'country'         => sanitize_text_field($_POST['country'] ?? ''),
        'birth_date'      => sanitize_text_field($_POST['birth_date'] ?? ''),
        'password'        => $_POST['password'] ?? '', // Will be hashed on user creation
    ];
    
    // Validate required fields
    $required = ['first_name', 'last_name', 'email', 'phone', 'passport_number', 'country', 'birth_date', 'password'];
    foreach ($required as $field) {
        if (empty($personal_info[$field])) {
            wp_send_json_error(['message' => 'Please fill in all required fields: ' . $field]);
            return;
        }
    }
    
    // Validate email
    if (!is_email($personal_info['email'])) {
        wp_send_json_error(['message' => 'Please enter a valid email address']);
        return;
    }
    
    // Check if email already registered - if so, we'll use existing user for booking
    $existing_user_id = email_exists($personal_info['email']);
    $is_existing_user = ($existing_user_id !== false);
    
    // Validate password (only required for new users)
    if (!$is_existing_user && strlen($personal_info['password']) < 8) {
        wp_send_json_error(['message' => 'Password must be at least 8 characters long']);
        return;
    }
    
    // Get session data (from assessment)
    $session_data = [];
    if (!session_id()) {
        session_start();
    }
    if (isset($_SESSION['issue'])) {
        $session_data['issue'] = $_SESSION['issue'];
    }
    if (isset($_SESSION['gender'])) {
        $session_data['gender'] = $_SESSION['gender'];
    }
    if (isset($_SESSION['user_concern_type'])) {
        $session_data['concern_type'] = $_SESSION['user_concern_type'];
    }
    if (isset($_SESSION['assessment_passed'])) {
        $session_data['assessment_passed'] = $_SESSION['assessment_passed'];
    }
    if (isset($_SESSION['posted_data'])) {
        $session_data['posted_data'] = $_SESSION['posted_data'];
    }
    
    // Build booking data structure
    $booking_data = [
        'booking_token'   => $booking_token,
        'booking_type'    => $is_existing_user ? 'therapy_existing_user' : 'therapy',
        'group_id'        => $group_id,
        'group_title'     => $group_post->post_title,
        'personal_info'   => $personal_info,
        'session_data'    => $session_data,
        'amount'          => $therapy_price,
        'currency'        => get_option('paytabs_currency', 'SAR'),
        'booking_state'   => 'pending_payment',
        'created_at'      => current_time('mysql'),
        'ip_address'      => $_SERVER['REMOTE_ADDR'] ?? '',
        'user_agent'      => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'existing_user_id'=> $is_existing_user ? $existing_user_id : null,
    ];
    
    error_log('[Therapy Booking] Saving booking data. existing_user=' . ($is_existing_user ? 'YES (ID: ' . $existing_user_id . ')' : 'NO') . ', group_id=' . $group_id);
    
    // Store in transient (1 hour TTL before payment)
    $transient_key = 'therapy_' . str_replace('therapy_', '', $booking_token);
    set_transient($transient_key, $booking_data, HOUR_IN_SECONDS);
    
    error_log('[Therapy Payment] Booking data saved. Token: ' . $booking_token . ', Group: ' . $group_id . ', Email: ' . $personal_info['email']);
    
    wp_send_json_success([
        'booking_token' => $booking_token,
        'amount'        => $therapy_price,
        'currency'      => get_option('paytabs_currency', 'SAR'),
        'group_title'   => $group_post->post_title,
    ]);
}
}

/**
 * AJAX Handler: Initiate PayTabs payment for therapy booking
 * 
 * Uses the booking token from save_therapy_booking_data
 * Calls core paytabs_initiate_payment() from retreat integration
 */
add_action('wp_ajax_initiate_therapy_payment', 'ajax_initiate_therapy_payment');
add_action('wp_ajax_nopriv_initiate_therapy_payment', 'ajax_initiate_therapy_payment');

if (!function_exists('ajax_initiate_therapy_payment')) {
function ajax_initiate_therapy_payment() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'therapy_registration_nonce')) {
        wp_send_json_error(['message' => 'Security verification failed']);
        return;
    }
    
    $booking_token = sanitize_text_field($_POST['booking_token'] ?? '');
    if (empty($booking_token)) {
        wp_send_json_error(['message' => 'Invalid booking session']);
        return;
    }
    
    // Retrieve booking data from transient
    $transient_key = 'therapy_' . str_replace('therapy_', '', $booking_token);
    $booking_data = get_transient($transient_key);
    
    if (!$booking_data) {
        wp_send_json_error(['message' => 'Booking session expired. Please fill out the form again.']);
        return;
    }
    
    // Check if core PayTabs function exists
    if (!function_exists('paytabs_initiate_payment')) {
        error_log('[Therapy Payment] ERROR: paytabs_initiate_payment function not found');
        wp_send_json_error(['message' => 'Payment system not configured. Please contact support.']);
        return;
    }
    
    // Prepare customer details for PayTabs
    $personal_info = $booking_data['personal_info'];
    $customer_details = [
        'name'    => $personal_info['first_name'] . ' ' . $personal_info['last_name'],
        'email'   => $personal_info['email'],
        'phone'   => $personal_info['phone'],
        'street1' => 'N/A',
        'city'    => 'N/A',
        'state'   => 'N/A',
        'country' => $personal_info['country'] ?: 'SA',
        'zip'     => '00000',
    ];
    
    // Determine return URL based on language
    $lang = function_exists('pll_current_language') ? pll_current_language() : 'en';
    $register_page = ($lang === 'ar') ? home_url('/ar/register-arabic/') : home_url('/register/');
    
    // Initiate payment
    $result = paytabs_initiate_payment(
        $booking_token,  // cart_id = booking_token (prefixed with therapy_)
        $booking_data['amount'],
        $customer_details,
        [
            'currency'         => $booking_data['currency'],
            'description'      => 'Therapy Session: ' . $booking_data['group_title'],
            'retreat_page_url' => $register_page, // Return URL after payment
        ]
    );
    
    if ($result['success']) {
        // Update transient with payment initiation data
        $booking_data['payment_initiated'] = true;
        $booking_data['tran_ref'] = $result['tran_ref'] ?? '';
        $booking_data['payment_initiated_at'] = current_time('mysql');
        set_transient($transient_key, $booking_data, HOUR_IN_SECONDS);
        
        error_log('[Therapy Payment] Payment initiated. Token: ' . $booking_token . ', TranRef: ' . ($result['tran_ref'] ?? 'N/A'));
        
        wp_send_json_success([
            'redirect_url' => $result['redirect_url'],
            'tran_ref'     => $result['tran_ref'] ?? '',
        ]);
    } else {
        error_log('[Therapy Payment] Payment initiation failed: ' . ($result['error'] ?? 'Unknown error'));
        wp_send_json_error([
            'message' => $result['error'] ?? 'Failed to connect to payment gateway',
        ]);
    }
}
}

/**
 * AJAX Handler: Verify therapy payment status
 * 
 * Called on return page to check payment status
 * If payment confirmed but booking not created, creates it (fallback)
 */
add_action('wp_ajax_verify_therapy_payment_status', 'ajax_verify_therapy_payment_status');
add_action('wp_ajax_nopriv_verify_therapy_payment_status', 'ajax_verify_therapy_payment_status');

if (!function_exists('ajax_verify_therapy_payment_status')) {
function ajax_verify_therapy_payment_status() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'therapy_registration_nonce')) {
        wp_send_json_error(['message' => 'Security verification failed']);
        return;
    }
    
    $booking_token = sanitize_text_field($_POST['booking_token'] ?? '');
    if (empty($booking_token)) {
        wp_send_json_error(['message' => 'Invalid booking session']);
        return;
    }
    
    // Retrieve booking data
    $transient_key = 'therapy_' . str_replace('therapy_', '', $booking_token);
    $booking_data = get_transient($transient_key);
    
    if (!$booking_data) {
        wp_send_json_error(['message' => 'Booking session not found or expired']);
        return;
    }
    
    // Check if booking already completed by IPN
    if (isset($booking_data['booking_state']) && $booking_data['booking_state'] === 'booking_confirmed' && !empty($booking_data['user_id'])) {
        // Auto-login the user
        wp_set_current_user($booking_data['user_id']);
        wp_set_auth_cookie($booking_data['user_id'], true);
        
        $lang = function_exists('pll_current_language') ? pll_current_language() : 'en';
        $redirect_url = ($lang === 'ar') ? home_url('/ar/thank-you-arabic') : home_url('/thank-you');
        
        wp_send_json_success([
            'status'       => 'completed',
            'message'      => 'Registration successful!',
            'user_id'      => $booking_data['user_id'],
            'redirect_url' => $redirect_url,
            'debug' => [
                'source' => 'already_completed_by_ipn',
                'booking_type' => $booking_data['booking_type'] ?? 'unknown',
                'group_id' => $booking_data['group_id'] ?? 'NONE',
                'assigned_group' => get_user_meta($booking_data['user_id'], 'assigned_group', true),
            ],
        ]);
        return;
    }
    
    // If payment completed but booking not created (IPN may have failed)
    $booking_state = $booking_data['booking_state'] ?? '';
    $payment_status = $booking_data['payment_status'] ?? '';
    
    if ($booking_state === 'payment_completed' || $payment_status === 'completed') {
        // Try to create booking now (fallback mechanism)
        // Check booking type to use correct processor
        $booking_type = $booking_data['booking_type'] ?? '';
        
        if ($booking_type === 'therapy_logged_in') {
            $result = process_therapy_logged_in_booking_from_ipn($booking_data, $booking_token);
            error_log('[Therapy Verify] Processing logged-in user booking. group_id=' . ($booking_data['group_id'] ?? 'NONE'));
        } elseif ($booking_type === 'therapy_existing_user') {
            $result = process_therapy_existing_user_booking_from_ipn($booking_data, $booking_token);
            error_log('[Therapy Verify] Processing existing user (not logged in) booking. group_id=' . ($booking_data['group_id'] ?? 'NONE'));
        } else {
            $result = process_therapy_booking_from_ipn($booking_data, $booking_token);
            error_log('[Therapy Verify] Processing new user booking. group_id=' . ($booking_data['group_id'] ?? 'NONE'));
        }
        
        if ($result['success']) {
            // Update transient
            $booking_data['user_id'] = $result['user_id'];
            $booking_data['booking_state'] = 'booking_confirmed';
            set_transient($transient_key, $booking_data, DAY_IN_SECONDS);
            
            // Auto-login
            wp_set_current_user($result['user_id']);
            wp_set_auth_cookie($result['user_id'], true);
            
            $lang = function_exists('pll_current_language') ? pll_current_language() : 'en';
            $redirect_url = ($lang === 'ar') ? home_url('/ar/thank-you-arabic') : home_url('/thank-you');
            
            wp_send_json_success([
                'status'       => 'completed',
                'message'      => 'Registration successful!',
                'user_id'      => $result['user_id'],
                'redirect_url' => $redirect_url,
                'debug' => [
                    'processor' => $booking_type,
                    'group_id' => $booking_data['group_id'] ?? 'NONE',
                    'booking_type' => $booking_type,
                    'assigned_group' => get_user_meta($result['user_id'], 'assigned_group', true),
                ],
            ]);
        } else {
            wp_send_json_error([
                'status'  => 'error',
                'message' => $result['message'] ?? 'Failed to complete registration',
                'debug' => [
                    'processor' => $booking_type,
                    'group_id' => $booking_data['group_id'] ?? 'NONE',
                    'booking_type' => $booking_type,
                    'result' => $result,
                ],
            ]);
        }
        return;
    }
    
    // Check payment status via API (if we have a transaction reference)
    if (!empty($booking_data['tran_ref']) && function_exists('paytabs_verify_payment')) {
        $verification = paytabs_verify_payment($booking_data['tran_ref']);
        
        if ($verification['success'] && $verification['status'] === 'approved') {
            // Payment approved but IPN didn't arrive yet - process now
            $booking_data['payment_status'] = 'completed';
            $booking_data['booking_state'] = 'payment_completed';
            set_transient($transient_key, $booking_data, DAY_IN_SECONDS);
            
            // Create booking - check booking type to use correct processor
            $booking_type = $booking_data['booking_type'] ?? '';
            
            if ($booking_type === 'therapy_logged_in') {
                $result = process_therapy_logged_in_booking_from_ipn($booking_data, $booking_token);
                error_log('[Therapy Verify API] Processing logged-in user booking. group_id=' . ($booking_data['group_id'] ?? 'NONE'));
            } elseif ($booking_type === 'therapy_existing_user') {
                $result = process_therapy_existing_user_booking_from_ipn($booking_data, $booking_token);
                error_log('[Therapy Verify API] Processing existing user (not logged in) booking. group_id=' . ($booking_data['group_id'] ?? 'NONE'));
            } else {
                $result = process_therapy_booking_from_ipn($booking_data, $booking_token);
                error_log('[Therapy Verify API] Processing new user booking. group_id=' . ($booking_data['group_id'] ?? 'NONE'));
            }
            
            if ($result['success']) {
                $booking_data['user_id'] = $result['user_id'];
                $booking_data['booking_state'] = 'booking_confirmed';
                set_transient($transient_key, $booking_data, DAY_IN_SECONDS);
                
                // Auto-login
                wp_set_current_user($result['user_id']);
                wp_set_auth_cookie($result['user_id'], true);
                
                $lang = function_exists('pll_current_language') ? pll_current_language() : 'en';
                $redirect_url = ($lang === 'ar') ? home_url('/ar/thank-you-arabic') : home_url('/thank-you');
                
                wp_send_json_success([
                    'status'       => 'completed',
                    'message'      => 'Registration successful!',
                    'user_id'      => $result['user_id'],
                    'redirect_url' => $redirect_url,
                    'debug' => [
                        'processor' => $booking_type,
                        'group_id' => $booking_data['group_id'] ?? 'NONE',
                        'booking_type' => $booking_type,
                        'assigned_group' => get_user_meta($result['user_id'], 'assigned_group', true),
                        'source' => 'api_verification',
                    ],
                ]);
                return;
            }
        }
    }
    
    // Payment still pending or failed
    wp_send_json_success([
        'status'        => $booking_data['booking_state'] ?? 'pending_payment',
        'payment_status'=> $booking_data['payment_status'] ?? 'pending',
        'message'       => 'Waiting for payment confirmation...',
        'debug' => [
            'booking_type' => $booking_data['booking_type'] ?? 'unknown',
            'group_id' => $booking_data['group_id'] ?? 'NONE',
            'user_id' => $booking_data['user_id'] ?? 'NONE',
            'tran_ref' => $booking_data['tran_ref'] ?? 'NONE',
            'transient_key' => $transient_key,
        ],
    ]);
}
}

// ============================================================================
// SECTION 2: IPN PROCESSING FOR THERAPY BOOKINGS
// ============================================================================

/**
 * Handle PayTabs IPN callback for therapy bookings
 * 
 * This runs independently from retreat callbacks
 * Hooks into template_redirect and processes therapy_ prefixed cart_ids
 */
if (!function_exists('therapy_paytabs_handle_callback')) {
function therapy_paytabs_handle_callback() {
    if (get_query_var('payment_callback')) {
        $json = file_get_contents('php://input');
        $data = json_decode($json, true);
        
        // Validate callback data
        if (!isset($data['cart_id']) || empty($data['cart_id'])) {
            return; // Not our callback, let other handlers try
        }
        
        $booking_token = sanitize_text_field($data['cart_id']);
        
        // Only process therapy bookings (prefixed with 'therapy_')
        if (strpos($booking_token, 'therapy_') !== 0) {
            return; // Not a therapy booking, let other handlers process
        }
        
        error_log('=== THERAPY PAYTABS IPN CALLBACK ===');
        error_log('Token: ' . $booking_token);
        
        // Process the therapy payment callback
        $result = process_therapy_payment_callback($data, $booking_token);
        
        // Respond to PayTabs and exit
        wp_send_json(['status' => 'received']);
        exit;
    }
}
}
add_action('template_redirect', 'therapy_paytabs_handle_callback', 5); // Priority 5 to run before retreat handler

/**
 * Process therapy payment callback from PayTabs IPN
 * 
 * @param array $data IPN callback data from PayTabs
 * @param string $booking_token Therapy booking token
 * @return array Result status
 */
if (!function_exists('process_therapy_payment_callback')) {
function process_therapy_payment_callback($data, $booking_token) {
    error_log('=== THERAPY PAYTABS IPN CALLBACK ===');
    error_log('Token: ' . $booking_token);
    
    $transient_key = 'therapy_' . str_replace('therapy_', '', $booking_token);
    $booking_data = get_transient($transient_key);
    
    if (!$booking_data) {
        error_log('[Therapy IPN] ERROR: Transient not found for token: ' . $booking_token);
        return [
            'success' => false,
            'message' => 'Booking data not found'
        ];
    }
    
    // Store callback data
    $booking_data['payment_callback'] = $data;
    $booking_data['tran_ref'] = $data['tran_ref'] ?? ($booking_data['tran_ref'] ?? '');
    
    // Check payment status
    $payment_status = $data['payment_result']['response_status'] ?? '';
    
    if ($payment_status === 'A') {
        // Payment APPROVED - Create booking immediately!
        error_log('[Therapy IPN] Payment APPROVED for token: ' . $booking_token);
        
        $booking_data['payment_status'] = 'completed';
        $booking_data['booking_state'] = 'payment_completed';
        $booking_data['ipn_processed_at'] = current_time('mysql');
        
        // Determine booking type and process accordingly
        $booking_type = $booking_data['booking_type'] ?? 'unknown';
        error_log('[Therapy IPN] Processing booking_type: ' . $booking_type . ', group_id: ' . ($booking_data['group_id'] ?? 'NONE'));
        
        if ($booking_type === 'therapy_logged_in') {
            $booking_result = process_therapy_logged_in_booking_from_ipn($booking_data, $booking_token);
        } elseif ($booking_type === 'therapy_existing_user') {
            // Existing user who wasn't logged in - process like logged-in user
            $booking_result = process_therapy_existing_user_booking_from_ipn($booking_data, $booking_token);
        } else {
            $booking_result = process_therapy_booking_from_ipn($booking_data, $booking_token);
        }
        
        if ($booking_result['success']) {
            $booking_data['user_id'] = $booking_result['user_id'];
            $booking_data['booking_state'] = 'booking_confirmed';
            $booking_data['booking_created_at'] = current_time('mysql');
            error_log('[Therapy IPN] SUCCESS: Booking auto-created for user ID: ' . $booking_result['user_id']);
        } else {
            // Log error but keep transient for fallback recovery
            $booking_data['booking_state'] = 'payment_completed';
            if (!isset($booking_data['booking_errors'])) {
                $booking_data['booking_errors'] = [];
            }
            $booking_data['booking_errors'][] = [
                'timestamp' => current_time('mysql'),
                'message' => $booking_result['message']
            ];
            error_log('[Therapy IPN] ERROR: Failed to create booking: ' . $booking_result['message']);
            
            // Send admin notification
            wp_mail(
                get_option('admin_email'),
                '[URGENT] Therapy IPN Booking Failed - Manual Review Required',
                "A therapy booking failed to process after successful payment.\n\n" .
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
        error_log('[Therapy IPN] Payment FAILED for token: ' . $booking_token . ' (status: ' . $payment_status . ')');
        $booking_data['payment_status'] = 'failed';
        $booking_data['booking_state'] = 'failed';
        $booking_data['failure_reason'] = $data['payment_result']['response_message'] ?? 'Unknown';
    }
    
    // Save updated transient (24 hours for recovery window)
    set_transient($transient_key, $booking_data, DAY_IN_SECONDS);
    
    return [
        'success' => ($payment_status === 'A'),
        'booking_state' => $booking_data['booking_state']
    ];
}
}

/**
 * Process therapy booking from IPN callback
 * 
 * Creates user account, assigns to therapy group, enrolls in BP chat
 * Idempotent: Can be safely called multiple times
 * 
 * @param array $booking_data Booking data from transient
 * @param string $booking_token Unique booking token
 * @return array ['success' => bool, 'user_id' => int|null, 'message' => string]
 */
if (!function_exists('process_therapy_booking_from_ipn')) {
function process_therapy_booking_from_ipn($booking_data, $booking_token) {
    error_log('=== THERAPY IPN BOOKING PROCESSOR START ===');
    error_log('Token: ' . $booking_token);
    
    // ============================================
    // 1. IDEMPOTENCY CHECK
    // ============================================
    if (!empty($booking_data['user_id'])) {
        $existing_user = get_user_by('id', $booking_data['user_id']);
        if ($existing_user) {
            error_log('[Therapy IPN] User already exists (ID: ' . $booking_data['user_id'] . '), skipping creation');
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
        error_log('[Therapy IPN] ERROR: No email in booking data');
        return [
            'success' => false,
            'user_id' => null,
            'message' => 'No email in booking data'
        ];
    }
    
    // ============================================
    // 2. CHECK IF EMAIL ALREADY EXISTS
    // ============================================
    $existing_user = get_user_by('email', $email);
    if ($existing_user) {
        $user_id = $existing_user->ID;
        error_log('[Therapy IPN] Email exists, using existing user ID: ' . $user_id);
    } else {
        // ============================================
        // 3. CREATE NEW USER ACCOUNT
        // ============================================
        $password = $personal_info['password'] ?? wp_generate_password(12, true);
        
        $user_id = wp_create_user($email, $password, $email);
        
        if (is_wp_error($user_id)) {
            error_log('[Therapy IPN] ERROR: Failed to create user: ' . $user_id->get_error_message());
            return [
                'success' => false,
                'user_id' => null,
                'message' => 'Failed to create user: ' . $user_id->get_error_message()
            ];
        }
        
        error_log('[Therapy IPN] Created new user ID: ' . $user_id);
        
        // Update user display name
        wp_update_user([
            'ID'           => $user_id,
            'first_name'   => $personal_info['first_name'] ?? '',
            'last_name'    => $personal_info['last_name'] ?? '',
            'display_name' => ($personal_info['first_name'] ?? '') . ' ' . ($personal_info['last_name'] ?? ''),
        ]);
    }
    
    // ============================================
    // 4. SAVE USER METADATA
    // ============================================
    update_user_meta($user_id, 'first_name', $personal_info['first_name'] ?? '');
    update_user_meta($user_id, 'last_name', $personal_info['last_name'] ?? '');
    update_user_meta($user_id, 'phone_number', $personal_info['phone'] ?? '');
    update_user_meta($user_id, 'passport_no', $personal_info['passport_number'] ?? '');
    update_user_meta($user_id, 'country', $personal_info['country'] ?? '');
    update_user_meta($user_id, 'dob', $personal_info['birth_date'] ?? '');
    update_user_meta($user_id, 'account_status', 'approved');
    
    // Payment metadata
    update_user_meta($user_id, 'payment_transaction_id', $booking_data['tran_ref'] ?? '');
    update_user_meta($user_id, 'payment_amount', $booking_data['amount'] ?? 0);
    update_user_meta($user_id, 'payment_method', 'paytabs');
    
    // Session data (from assessment)
    $session_data = $booking_data['session_data'] ?? [];
    if (!empty($session_data['concern_type'])) {
        update_user_meta($user_id, 'concern_type', $session_data['concern_type']);
    }
    if (!empty($session_data['gender'])) {
        update_user_meta($user_id, 'gender', $session_data['gender']);
    }
    if (!empty($session_data['assessment_passed'])) {
        update_user_meta($user_id, 'assessment_passed', $session_data['assessment_passed']);
    }
    
    // Set user role
    $user = new WP_User($user_id);
    $user->set_role('subscriber');
    
    // ============================================
    // 5. ASSIGN TO THERAPY GROUP
    // ============================================
    $group_id = intval($booking_data['group_id'] ?? 0);
    if ($group_id <= 0) {
        error_log('[Therapy IPN] ERROR: Invalid group_id: ' . $group_id);
        return [
            'success' => false,
            'user_id' => $user_id,
            'message' => 'Invalid therapy group'
        ];
    }
    
    // DIRECT ASSIGNMENT (bypass capacity checks - user already paid!)
    update_user_meta($user_id, 'assigned_group', $group_id);
    error_log('[Therapy IPN] Direct assignment: user ' . $user_id . ' to group ' . $group_id);
    
    // Copy issue/gender metadata from group to user (required for system compatibility)
    if (function_exists('get_field')) {
        $group_issue = get_field('issue_type', $group_id);
        $group_gender = get_field('gender', $group_id);
        
        if ($group_issue && empty(get_user_meta($user_id, 'concern_type', true))) {
            update_user_meta($user_id, 'concern_type', $group_issue);
            error_log('[Therapy IPN] Set user concern_type: ' . $group_issue);
        }
        if ($group_gender && empty(get_user_meta($user_id, 'gender', true))) {
            update_user_meta($user_id, 'gender', $group_gender);
            error_log('[Therapy IPN] Set user gender: ' . $group_gender);
        }
    }
    
    // Verify assignment
    $assigned_group = get_user_meta($user_id, 'assigned_group', true);
    error_log('[Therapy IPN] Verified assigned_group: ' . $assigned_group);
    
    // ============================================
    // 6. ENROLL IN BUDDYPRESS CHAT GROUP
    // ============================================
    if (function_exists('enroll_user_to_bp_chat_group') && $assigned_group) {
        $bp_result = enroll_user_to_bp_chat_group($user_id, $assigned_group);
        if ($bp_result) {
            error_log('[Therapy IPN] ✓ Enrolled user in BuddyPress chat group');
        } else {
            error_log('[Therapy IPN] WARNING: BuddyPress enrollment failed (non-fatal)');
        }
    } else {
        error_log('[Therapy IPN] WARNING: enroll_user_to_bp_chat_group not available or no group assigned');
    }
    
    // ============================================
    // 7. SEND CONFIRMATION EMAIL
    // ============================================
    $group_title = $booking_data['group_title'] ?? get_the_title($group_id);
    $session_start = '';
    $session_expiry = '';
    if (function_exists('get_field')) {
        $session_start = get_field('session_start_date', $group_id);
        $session_expiry = get_field('session_expiry_date', $group_id);
    }
    
    $email_subject = 'Therapy Session Booking Confirmation - Tanafs';
    $email_body = "Dear " . ($personal_info['first_name'] ?? '') . ",\n\n";
    $email_body .= "Thank you for booking your therapy session with Tanafs!\n\n";
    $email_body .= "Your booking has been confirmed.\n\n";
    $email_body .= "Therapy Group: {$group_title}\n";
    if ($session_start && $session_expiry) {
        $email_body .= "Session Period: {$session_start} to {$session_expiry}\n";
    }
    $email_body .= "\nPayment Transaction ID: " . ($booking_data['tran_ref'] ?? 'N/A') . "\n";
    $email_body .= "Amount Paid: " . ($booking_data['amount'] ?? '0') . " " . ($booking_data['currency'] ?? 'SAR') . "\n\n";
    $email_body .= "You can login at: " . wp_login_url() . "\n";
    $email_body .= "Your username is your email: {$email}\n\n";
    $email_body .= "We look forward to seeing you!\n\n";
    $email_body .= "Best regards,\nTanafs Team";
    
    wp_mail($email, $email_subject, $email_body);
    error_log('[Therapy IPN] Sent confirmation email to: ' . $email);
    
    // ============================================
    // 8. REMOVE FROM WAITING LIST IF APPLICABLE
    // ============================================
    if (function_exists('remove_user_from_waiting_list_by_email')) {
        remove_user_from_waiting_list_by_email($email);
    }
    
    // ============================================
    // 9. RETURN SUCCESS
    // ============================================
    error_log('=== THERAPY IPN BOOKING PROCESSOR SUCCESS ===');
    error_log('User ID: ' . $user_id);
    
    return [
        'success' => true,
        'user_id' => $user_id,
        'message' => 'Therapy booking created successfully'
    ];
}
}

// ============================================================================
// SECTION 3: LOGGED-IN USER PAYMENT FLOW
// ============================================================================

/**
 * AJAX Handler: Initiate payment for logged-in user therapy booking
 */
add_action('wp_ajax_initiate_therapy_payment_logged_in', 'ajax_initiate_therapy_payment_logged_in');

if (!function_exists('ajax_initiate_therapy_payment_logged_in')) {
function ajax_initiate_therapy_payment_logged_in() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'therapy_registration_nonce')) {
        wp_send_json_error(['message' => 'Security verification failed']);
        return;
    }
    
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'You must be logged in']);
        return;
    }
    
    $user_id = get_current_user_id();
    $user = get_userdata($user_id);
    
    $group_id = intval($_POST['selected_group_id'] ?? 0);
    if ($group_id <= 0) {
        wp_send_json_error(['message' => 'Please select a therapy group session']);
        return;
    }
    
    // Verify group exists
    $group_post = get_post($group_id);
    if (!$group_post || $group_post->post_type !== 'therapy_group') {
        wp_send_json_error(['message' => 'Invalid therapy group selected']);
        return;
    }
    
    // Allow users to register for multiple therapy groups if needed
    // (Removed check that prevented multiple group registrations)
    
    // Get therapy price
    $therapy_price = get_field('therapy_price', $group_id);
    if (empty($therapy_price) || $therapy_price <= 0) {
        $therapy_price = 3500;
    }
    
    // Generate booking token
    $booking_token = 'therapy_' . bin2hex(random_bytes(16));
    
    // Build booking data
    $booking_data = [
        'booking_token'   => $booking_token,
        'booking_type'    => 'therapy_logged_in',
        'user_id'         => $user_id,
        'group_id'        => $group_id,
        'group_title'     => $group_post->post_title,
        'personal_info'   => [
            'first_name' => $user->first_name,
            'last_name'  => $user->last_name,
            'email'      => $user->user_email,
            'phone'      => get_user_meta($user_id, 'phone_number', true),
        ],
        'amount'          => $therapy_price,
        'currency'        => get_option('paytabs_currency', 'SAR'),
        'booking_state'   => 'pending_payment',
        'created_at'      => current_time('mysql'),
    ];
    
    // Store transient
    $transient_key = 'therapy_' . str_replace('therapy_', '', $booking_token);
    set_transient($transient_key, $booking_data, HOUR_IN_SECONDS);
    
    // Prepare customer details (ensure no null values)
    $phone_number = get_user_meta($user_id, 'phone_number', true);
    $country_code = get_user_meta($user_id, 'country', true);
    
    $customer_details = [
        'name'    => trim($user->first_name . ' ' . $user->last_name) ?: 'User',
        'email'   => $user->user_email ?: 'noemail@example.com',
        'phone'   => !empty($phone_number) ? $phone_number : '0000000000',
        'street1' => 'N/A',
        'city'    => 'N/A',
        'state'   => 'N/A',
        'country' => !empty($country_code) ? $country_code : 'SA',
        'zip'     => '00000',
    ];
    
    // Get return URL
    $lang = function_exists('pll_current_language') ? pll_current_language() : 'en';
    $register_page = ($lang === 'ar') ? home_url('/ar/register-arabic/') : home_url('/register/');
    
    // Initiate payment
    if (!function_exists('paytabs_initiate_payment')) {
        wp_send_json_error(['message' => 'Payment system not configured']);
        return;
    }
    
    $result = paytabs_initiate_payment(
        $booking_token,
        $therapy_price,
        $customer_details,
        [
            'currency'         => get_option('paytabs_currency', 'SAR'),
            'description'      => 'Therapy Session: ' . $group_post->post_title,
            'retreat_page_url' => $register_page,
        ]
    );
    
    if ($result['success']) {
        $booking_data['payment_initiated'] = true;
        $booking_data['tran_ref'] = $result['tran_ref'] ?? '';
        set_transient($transient_key, $booking_data, HOUR_IN_SECONDS);
        
        error_log('[Therapy Payment] Initiated for logged-in user. group_id=' . $group_id . ', token=' . $booking_token . ', transient_key=' . $transient_key);
        
        wp_send_json_success([
            'redirect_url' => $result['redirect_url'],
            'debug' => [
                'group_id_received' => $group_id,
                'booking_token' => $booking_token,
                'transient_key' => $transient_key,
                'group_title' => $group_post->post_title,
                'user_id' => $user_id,
            ],
        ]);
    } else {
        wp_send_json_error([
            'message' => $result['error'] ?? 'Failed to connect to payment gateway',
        ]);
    }
}
}

/**
 * Process logged-in user therapy booking from IPN
 * 
 * Simpler than new user flow - just assigns group and enrolls in BP
 */
if (!function_exists('process_therapy_logged_in_booking_from_ipn')) {
function process_therapy_logged_in_booking_from_ipn($booking_data, $booking_token) {
    error_log('=== THERAPY LOGGED-IN IPN PROCESSOR ===');
    error_log('[Therapy Logged-In IPN] Full booking_data: ' . print_r($booking_data, true));
    
    $user_id = $booking_data['user_id'] ?? 0;
    if (!$user_id) {
        error_log('[Therapy Logged-In IPN] ERROR: No user_id in booking data!');
        return [
            'success' => false,
            'user_id' => null,
            'message' => 'No user ID in booking data'
        ];
    }
    
    $user = get_user_by('id', $user_id);
    if (!$user) {
        error_log('[Therapy Logged-In IPN] ERROR: User not found for ID: ' . $user_id);
        return [
            'success' => false,
            'user_id' => null,
            'message' => 'User not found'
        ];
    }
    
    $group_id = intval($booking_data['group_id'] ?? 0);
    error_log('[Therapy Logged-In IPN] Extracted group_id: ' . $group_id);
    
    if ($group_id <= 0) {
        error_log('[Therapy Logged-In IPN] ERROR: Invalid group_id: ' . $group_id);
        return [
            'success' => false,
            'user_id' => $user_id,
            'message' => 'Invalid group ID in booking data: ' . $group_id
        ];
    }
    
    // Verify group exists
    $group_post = get_post($group_id);
    if (!$group_post || $group_post->post_type !== 'therapy_group') {
        error_log('[Therapy Logged-In IPN] ERROR: Group post not found or wrong type: ' . $group_id);
        return [
            'success' => false,
            'user_id' => $user_id,
            'message' => 'Therapy group not found: ' . $group_id
        ];
    }
    
    error_log('[Therapy Logged-In IPN] Group verified: ' . $group_post->post_title . ' (ID: ' . $group_id . ')');
    
    // Get previous assigned group for logging
    $prev_assigned = get_user_meta($user_id, 'assigned_group', true);
    error_log('[Therapy Logged-In IPN] User ' . $user_id . ' previous assigned_group: ' . ($prev_assigned ?: 'NONE'));
    
    // DIRECT ASSIGNMENT (bypass capacity checks - user already paid!)
    update_user_meta($user_id, 'assigned_group', $group_id);
    
    // Verify assignment worked
    $new_assigned = get_user_meta($user_id, 'assigned_group', true);
    error_log('[Therapy Logged-In IPN] User ' . $user_id . ' NEW assigned_group: ' . $new_assigned);
    
    if ($new_assigned != $group_id) {
        error_log('[Therapy Logged-In IPN] CRITICAL: Assignment failed! Expected ' . $group_id . ' but got ' . $new_assigned);
    } else {
        error_log('[Therapy Logged-In IPN] ✓ Assignment SUCCESS: user ' . $user_id . ' -> group ' . $group_id);
    }
    
    // Copy issue/gender metadata from group to user if not set
    if (function_exists('get_field')) {
        $group_issue = get_field('issue_type', $group_id);
        $group_gender = get_field('gender', $group_id);
        
        if ($group_issue && empty(get_user_meta($user_id, 'concern_type', true))) {
            update_user_meta($user_id, 'concern_type', $group_issue);
        }
        if ($group_gender && empty(get_user_meta($user_id, 'gender', true))) {
            update_user_meta($user_id, 'gender', $group_gender);
        }
    }
    
    // Enroll in BP chat
    $assigned_group = get_user_meta($user_id, 'assigned_group', true);
    if (function_exists('enroll_user_to_bp_chat_group') && $assigned_group) {
        $bp_result = enroll_user_to_bp_chat_group($user_id, $assigned_group);
        if ($bp_result) {
            error_log('[Therapy Logged-In IPN] ✓ Enrolled user in BuddyPress chat');
        }
    }
    
    // Payment metadata
    update_user_meta($user_id, 'payment_transaction_id', $booking_data['tran_ref'] ?? '');
    update_user_meta($user_id, 'payment_amount', $booking_data['amount'] ?? 0);
    
    // Send confirmation email
    $personal_info = $booking_data['personal_info'] ?? [];
    $email = $personal_info['email'] ?? $user->user_email;
    
    wp_mail(
        $email,
        'Therapy Session Booking Confirmation - Tanafs',
        "Dear " . ($personal_info['first_name'] ?? $user->first_name) . ",\n\n" .
        "Your therapy session booking has been confirmed!\n\n" .
        "Group: " . ($booking_data['group_title'] ?? '') . "\n" .
        "Transaction ID: " . ($booking_data['tran_ref'] ?? 'N/A') . "\n\n" .
        "Best regards,\nTanafs Team"
    );
    
    error_log('[Therapy Logged-In IPN] SUCCESS for user ' . $user_id);
    
    return [
        'success' => true,
        'user_id' => $user_id,
        'message' => 'Booking confirmed'
    ];
}
}

/**
 * Process existing user (not logged in) therapy booking from IPN
 * 
 * For users who have an account but weren't logged in during registration.
 * Uses existing_user_id from booking data, assigns group and enrolls in BP.
 */
if (!function_exists('process_therapy_existing_user_booking_from_ipn')) {
function process_therapy_existing_user_booking_from_ipn($booking_data, $booking_token) {
    error_log('=== THERAPY EXISTING USER IPN PROCESSOR ===');
    error_log('[Therapy Existing User IPN] Full booking_data: ' . print_r($booking_data, true));
    
    // Get the existing user ID from booking data
    $user_id = $booking_data['existing_user_id'] ?? 0;
    
    // Fallback: try to find user by email if existing_user_id not set
    if (!$user_id) {
        $email = $booking_data['personal_info']['email'] ?? '';
        if ($email) {
            $user_id = email_exists($email);
            error_log('[Therapy Existing User IPN] Found user by email: ' . $user_id);
        }
    }
    
    if (!$user_id) {
        error_log('[Therapy Existing User IPN] ERROR: No existing user found!');
        return [
            'success' => false,
            'user_id' => null,
            'message' => 'Existing user not found'
        ];
    }
    
    $user = get_user_by('id', $user_id);
    if (!$user) {
        error_log('[Therapy Existing User IPN] ERROR: User object not found for ID: ' . $user_id);
        return [
            'success' => false,
            'user_id' => null,
            'message' => 'User not found'
        ];
    }
    
    error_log('[Therapy Existing User IPN] Processing for existing user: ' . $user->user_email . ' (ID: ' . $user_id . ')');
    
    $group_id = intval($booking_data['group_id'] ?? 0);
    error_log('[Therapy Existing User IPN] Extracted group_id: ' . $group_id);
    
    if ($group_id <= 0) {
        error_log('[Therapy Existing User IPN] ERROR: Invalid group_id: ' . $group_id);
        return [
            'success' => false,
            'user_id' => $user_id,
            'message' => 'Invalid group ID in booking data: ' . $group_id
        ];
    }
    
    // Verify group exists
    $group_post = get_post($group_id);
    if (!$group_post || $group_post->post_type !== 'therapy_group') {
        error_log('[Therapy Existing User IPN] ERROR: Group post not found or wrong type: ' . $group_id);
        return [
            'success' => false,
            'user_id' => $user_id,
            'message' => 'Therapy group not found: ' . $group_id
        ];
    }
    
    error_log('[Therapy Existing User IPN] Group verified: ' . $group_post->post_title . ' (ID: ' . $group_id . ')');
    
    // Get previous assigned group for logging
    $prev_assigned = get_user_meta($user_id, 'assigned_group', true);
    error_log('[Therapy Existing User IPN] User ' . $user_id . ' previous assigned_group: ' . ($prev_assigned ?: 'NONE'));
    
    // DIRECT ASSIGNMENT (bypass capacity checks - user already paid!)
    update_user_meta($user_id, 'assigned_group', $group_id);
    
    // Verify assignment worked
    $new_assigned = get_user_meta($user_id, 'assigned_group', true);
    error_log('[Therapy Existing User IPN] User ' . $user_id . ' NEW assigned_group: ' . $new_assigned);
    
    if ($new_assigned != $group_id) {
        error_log('[Therapy Existing User IPN] CRITICAL: Assignment failed! Expected ' . $group_id . ' but got ' . $new_assigned);
    } else {
        error_log('[Therapy Existing User IPN] ✓ Assignment SUCCESS: user ' . $user_id . ' -> group ' . $group_id);
    }
    
    // Copy issue/gender metadata from group to user if not set
    if (function_exists('get_field')) {
        $group_issue = get_field('issue_type', $group_id);
        $group_gender = get_field('gender', $group_id);
        
        if ($group_issue && empty(get_user_meta($user_id, 'concern_type', true))) {
            update_user_meta($user_id, 'concern_type', $group_issue);
            error_log('[Therapy Existing User IPN] Set user concern_type: ' . $group_issue);
        }
        if ($group_gender && empty(get_user_meta($user_id, 'gender', true))) {
            update_user_meta($user_id, 'gender', $group_gender);
            error_log('[Therapy Existing User IPN] Set user gender: ' . $group_gender);
        }
    }
    
    // Enroll in BP chat
    $assigned_group = get_user_meta($user_id, 'assigned_group', true);
    if (function_exists('enroll_user_to_bp_chat_group') && $assigned_group) {
        $bp_result = enroll_user_to_bp_chat_group($user_id, $assigned_group);
        if ($bp_result) {
            error_log('[Therapy Existing User IPN] ✓ Enrolled user in BuddyPress chat');
        } else {
            error_log('[Therapy Existing User IPN] ✗ BP enrollment failed');
        }
    }
    
    // Payment metadata
    update_user_meta($user_id, 'payment_transaction_id', $booking_data['tran_ref'] ?? '');
    update_user_meta($user_id, 'payment_amount', $booking_data['amount'] ?? 0);
    
    // Send confirmation email
    $personal_info = $booking_data['personal_info'] ?? [];
    $email = $personal_info['email'] ?? $user->user_email;
    
    wp_mail(
        $email,
        'Therapy Session Booking Confirmation - Tanafs',
        "Dear " . ($personal_info['first_name'] ?? $user->first_name) . ",\n\n" .
        "Your therapy session booking has been confirmed!\n\n" .
        "Group: " . ($booking_data['group_title'] ?? '') . "\n" .
        "Transaction ID: " . ($booking_data['tran_ref'] ?? 'N/A') . "\n\n" .
        "You can log in to your existing account to access the therapy group.\n\n" .
        "Best regards,\nTanafs Team"
    );
    
    error_log('[Therapy Existing User IPN] SUCCESS for existing user ' . $user_id);
    
    return [
        'success' => true,
        'user_id' => $user_id,
        'message' => 'Booking confirmed for existing user'
    ];
}
}
