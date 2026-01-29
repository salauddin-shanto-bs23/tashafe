# PayTabs IPN Reliability Plan: Guaranteeing Retreat Bookings on Payment Success

**Author:** Senior Backend Engineer  
**Date:** January 28, 2026  
**Status:** PENDING APPROVAL ⚠️

---

## 1. Root Cause Analysis

### 1.1 The Critical Flaw

**Current Flow Problem:**
```
User Pays → PayTabs Redirects Back → Frontend Opens Questionnaire Modal 
  → User Submits Questionnaire → complete_retreat_registration() Runs
    → ✅ User Created, ✅ Assigned to Retreat, ✅ Enrolled in BP Chat
```

**Failure Scenarios:**
- ❌ User closes browser after payment, before seeing questionnaire
- ❌ Internet connection drops during redirect
- ❌ PC crashes after payment success
- ❌ User gets distracted and never submits questionnaire
- ❌ Browser navigation error prevents modal from opening

**Result:** User pays money but is NOT registered in the retreat system.

### 1.2 Evidence from Code Analysis

**File:** [retreat_paytabs_integration.php](retreat/retreat_paytabs_integration.php#L543-L679)

The ONLY place where retreat booking actually happens:
```php
function ajax_complete_retreat_registration() {
    // Line 559: Verify payment
    if ($booking_data['payment_status'] !== 'completed') {
        wp_send_json_error('Payment not verified');
    }
    
    // Line 593-597: CREATE USER ACCOUNT
    $user_id = wp_create_user($username, $password, $email);
    
    // Line 654: ASSIGN TO RETREAT GROUP (CRITICAL!)
    update_user_meta($user_id, 'assigned_retreat_group', $group_id);
    
    // Line 664: ENROLL IN BUDDYPRESS CHAT
    enroll_retreat_user_to_bp_chat_group(...);
}
```

**This function is triggered by frontend AJAX** from the questionnaire modal submit button.

**Problematic Dependencies:**
1. Requires frontend JavaScript to execute
2. Requires modal to open successfully
3. Requires user to manually click submit
4. No fallback if frontend fails

### 1.3 Why Current Callback Implementation is Insufficient

**File:** [retreat_paytabs_integration.php](retreat/retreat_paytabs_integration.php#L267-L290)

Current callback handler:
```php
function paytabs_handle_callback() {
    // Receives PayTabs POST notification
    // ✅ Logs transaction
    // ✅ Updates transient payment_status
    // ❌ Does NOT create user
    // ❌ Does NOT assign to retreat
    // ❌ Does NOT enroll in BP
}
```

**The callback exists but doesn't do the critical work.**

---

## 2. Solution Architecture: IPN/Webhook-Driven Booking

### 2.1 Design Principle

> **Payment success = immediate booking guarantee, regardless of frontend state.**

### 2.2 Core State Machine

```
┌─────────────────────────────────────────────────────────────────┐
│ BOOKING LIFECYCLE STATES                                        │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│ 1. INITIATED                                                    │
│    - Transient created with booking data                       │
│    - payment_status: 'pending'                                  │
│    - booking_state: 'initiated'                                 │
│                                                                 │
│ 2. PAYMENT_COMPLETED                                            │
│    - PayTabs IPN received with success status                  │
│    - payment_status: 'completed'                                │
│    - booking_state: 'payment_completed'                         │
│    ⚠️ TRIGGER: Create user + assign retreat (BACKEND ONLY)     │
│                                                                 │
│ 3. BOOKING_CONFIRMED                                            │
│    - User account created                                       │
│    - assigned_retreat_group user meta set                       │
│    - BuddyPress enrollment completed                            │
│    - booking_state: 'booking_confirmed'                         │
│    - user_id stored in transient                                │
│                                                                 │
│ 4. QUESTIONNAIRE_PENDING                                        │
│    - User logged in, retreat booked                             │
│    - Questionnaire NOT yet submitted                            │
│    - booking_state: 'questionnaire_pending'                     │
│                                                                 │
│ 5. FULLY_COMPLETED                                              │
│    - Questionnaire answers saved to database                    │
│    - booking_state: 'fully_completed'                           │
│    - Transient can be safely deleted                            │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### 2.3 Enhanced Transient Data Structure

**Before Enhancement:**
```php
[
    'full_name' => 'John Doe',
    'email' => 'john@example.com',
    'group_id' => 123,
    'amount' => 4800,
    'payment_status' => 'pending' // Only this changes on payment
]
```

**After Enhancement:**
```php
[
    // User data
    'full_name' => 'John Doe',
    'email' => 'john@example.com',
    'phone' => '+966...',
    'password' => 'hashed...',
    'group_id' => 123,
    'retreat_type' => 'male',
    'amount' => 4800,
    
    // Payment tracking
    'payment_status' => 'completed',      // pending|completed|failed
    'tran_ref' => 'TST2425461...',        // PayTabs transaction reference
    'payment_callback' => [...],           // Raw callback data
    
    // NEW: Booking state tracking
    'booking_state' => 'booking_confirmed', // initiated|payment_completed|booking_confirmed|questionnaire_pending|fully_completed
    'user_id' => 456,                      // WordPress user ID (set after creation)
    'booking_created_at' => '2026-01-28 14:32:10', // Backend booking timestamp
    'ipn_processed_at' => '2026-01-28 14:32:08',   // When IPN created the booking
    'questionnaire_submitted_at' => null,  // When user completes questionnaire
    
    // Error tracking
    'booking_errors' => []                 // Array of any errors during IPN processing
]
```

---

## 3. Implementation Strategy

### 3.1 Phase 1: Enhance PayTabs Callback (IPN Handler)

**File to Modify:** [retreat_paytabs_integration.php](retreat/retreat_paytabs_integration.php#L267-L290)

**Function:** `paytabs_handle_callback()`

**Current Behavior:**
```php
function paytabs_handle_callback() {
    $callback_data = json_decode(file_get_contents('php://input'), true);
    paytabs_log_transaction('callback', $callback_data);
    
    if (!empty($callback_data['cart_id'])) {
        $booking_data = get_transient('retreat_' . $callback_data['cart_id']);
        if ($booking_data) {
            $booking_data['payment_callback'] = $callback_data;
            
            // This is the ONLY logic currently:
            if ($callback_data['payment_result']['response_status'] === 'A') {
                $booking_data['payment_status'] = 'completed';
            } else {
                $booking_data['payment_status'] = 'failed';
            }
            
            set_transient('retreat_' . $callback_data['cart_id'], $booking_data, 3600);
        }
    }
}
```

**NEW Enhanced Behavior:**

```php
function paytabs_handle_callback() {
    $callback_data = json_decode(file_get_contents('php://input'), true);
    paytabs_log_transaction('callback', $callback_data);
    
    if (empty($callback_data['cart_id'])) {
        wp_send_json(['status' => 'error', 'message' => 'No cart_id']);
        return;
    }
    
    $booking_token = sanitize_text_field($callback_data['cart_id']);
    $booking_data = get_transient('retreat_' . $booking_token);
    
    if (clearbooking_data) {
        error_log('IPN ERROR: Transient not found for token: ' . $booking_token);
        wp_send_json(['status' => 'error', 'message' => 'Booking data not found']);
        return;
    }
    
    // Update payment status and callback data
    $booking_data['payment_callback'] = $callback_data;
    $booking_data['tran_ref'] = $callback_data['tran_ref'] ?? '';
    
    $payment_status = $callback_data['payment_result']['response_status'] ?? '';
    
    if ($payment_status === 'A') { // Approved
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
            // Log error but keep transient for manual recovery
            $booking_data['booking_state'] = 'payment_completed'; // Don't advance state
            $booking_data['booking_errors'][] = [
                'timestamp' => current_time('mysql'),
                'message' => $booking_result['message']
            ];
            error_log('IPN ERROR: Failed to create booking: ' . $booking_result['message']);
        }
        
    } else {
        $booking_data['payment_status'] = 'failed';
        $booking_data['booking_state'] = 'failed';
    }
    
    // Save updated transient (increase TTL to 24 hours)
    set_transient('retreat_' . $booking_token, $booking_data, 86400);
    
    wp_send_json(['status' => 'success', 'message' => 'IPN processed']);
}
```

### 3.2 Phase 2: Create New IPN Booking Processor

**New Function:** `process_retreat_booking_from_ipn($booking_data, $booking_token)`

**Purpose:** Extract and isolate the core booking logic from the questionnaire handler.

**Location:** Add to [retreat_paytabs_integration.php](retreat/retreat_paytabs_integration.php)

**Implementation:**

```php
/**
 * Process retreat booking from IPN/webhook
 * This function creates the user account and assigns them to the retreat
 * Idempotent: Can be safely called multiple times with same data
 * 
 * @param array $booking_data Booking information from transient
 * @param string $booking_token Unique booking token
 * @return array ['success' => bool, 'user_id' => int|null, 'message' => string]
 */
function process_retreat_booking_from_ipn($booking_data, $booking_token) {
    global $wpdb;
    
    // ============================================
    // 1. IDEMPOTENCY CHECK
    // ============================================
    // If user already created, return success
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
    
    // Check if email already registered
    $existing_user = get_user_by('email', $booking_data['email']);
    if ($existing_user) {
        // Email exists - use existing account
        $user_id = $existing_user->ID;
        error_log('IPN: Email exists, using existing user ID: ' . $user_id);
    } else {
        // ============================================
        // 2. CREATE NEW USER ACCOUNT
        // ============================================
        $username = sanitize_user($booking_data['email']);
        $password = $booking_data['password'] ?? wp_generate_password(12, true);
        
        $user_id = wp_create_user($username, $password, $booking_data['email']);
        
        if (is_wp_error($user_id)) {
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
    $full_name = $booking_data['full_name'] ?? '';
    $names = explode(' ', trim($full_name), 2);
    $first_name = $names[0] ?? '';
    
    update_user_meta($user_id, 'full_name', $full_name);
    update_user_meta($user_id, 'first_name', $first_name);
    update_user_meta($user_id, 'phone', $booking_data['phone'] ?? '');
    update_user_meta($user_id, 'country', $booking_data['country'] ?? '');
    update_user_meta($user_id, 'gender', $booking_data['gender'] ?? '');
    update_user_meta($user_id, 'birth_date', $booking_data['birth_date'] ?? '');
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
    $group_id = intval($booking_data['group_id']);
    if ($group_id <= 0) {
        return [
            'success' => false,
            'user_id' => $user_id,
            'message' => 'Invalid group_id: ' . $group_id
        ];
    }
    
    $update_result = update_user_meta($user_id, 'assigned_retreat_group', $group_id);
    
    if ($update_result === false) {
        return [
            'success' => false,
            'user_id' => $user_id,
            'message' => 'Failed to assign user to retreat group'
        ];
    }
    
    error_log('IPN: Assigned user to retreat group: ' . $group_id);
    
    // ============================================
    // 5. ENROLL IN BUDDYPRESS CHAT GROUP
    // ============================================
    if (function_exists('enroll_retreat_user_to_bp_chat_group')) {
        // Get retreat start date for nickname
        $start_date = '';
        if ($group_id > 0) {
            $start_date = get_field('start_date', $group_id);
        }
        
        $bp_result = enroll_retreat_user_to_bp_chat_group(
            $user_id,
            $booking_data['retreat_type'] ?? '',
            $first_name,
            $start_date
        );
        
        if ($bp_result) {
            error_log('IPN: Enrolled user in BuddyPress chat group');
        } else {
            error_log('IPN WARNING: BuddyPress enrollment failed');
            // Don't fail the entire booking for BP enrollment failure
        }
    }
    
    // ============================================
    // 6. SEND CONFIRMATION EMAIL
    // ============================================
    // Get retreat details for email
    $retreat_title = get_the_title($group_id);
    $start_date = get_field('start_date', $group_id);
    $end_date = get_field('end_date', $group_id);
    $destination = get_field('trip_destination', $group_id);
    
    $email_subject = 'Retreat Booking Confirmation - ' . $retreat_title;
    $email_body = get_retreat_confirmation_email_from_ipn(
        $full_name,
        $retreat_title,
        $start_date,
        $end_date,
        $destination,
        $booking_data['amount']
    );
    
    wp_mail($booking_data['email'], $email_subject, $email_body, [
        'Content-Type: text/html; charset=UTF-8'
    ]);
    
    error_log('IPN: Sent confirmation email to: ' . $booking_data['email']);
    
    // ============================================
    // 7. RETURN SUCCESS
    // ============================================
    return [
        'success' => true,
        'user_id' => $user_id,
        'message' => 'Booking created successfully'
    ];
}
```

### 3.3 Phase 3: Modify Frontend Return Flow

**File:** [retreat_system.php](retreat/retreat_system.php#L2625-L2714)

**Current Behavior:** Auto-opens questionnaire modal after payment verification.

**New Behavior:** Check booking state and adapt accordingly.

**Changes to JavaScript:**

```javascript
// Payment return verification (line ~2650)
$.post(RETREAT_AJAX.url, {
    action: 'verify_retreat_payment_status',
    token: paymentReturnToken,
    nonce: RETREAT_AJAX.nonce
}, function(response) {
    if (response.success && response.data.payment_verified) {
        // NEW: Check if user already created by IPN
        if (response.data.user_already_created) {
            // Booking already confirmed by IPN
            // Auto-login user
            if (response.data.auto_login_url) {
                // Show success message
                showBookingConfirmedMessage();
                
                // Optionally still show questionnaire for additional data
                setTimeout(function() {
                    if (confirm('Your booking is confirmed! Would you like to fill out the optional wellness questionnaire?')) {
                        $('#retreat-questionnaire-modal').fadeIn(300);
                    } else {
                        // Redirect to retreat dashboard or homepage
                        window.location.href = response.data.redirect_url;
                    }
                }, 2000);
            }
        } else {
            // Fallback: IPN didn't process yet or failed
            // Show questionnaire modal (original behavior)
            setTimeout(function() {
                $('#retreat-questionnaire-modal').fadeIn(300);
            }, 1000);
        }
    }
});
```

### 3.4 Phase 4: Modify Verify Payment AJAX Handler

**File:** [retreat_paytabs_integration.php](retreat/retreat_paytabs_integration.php#L479-L531)

**Function:** `ajax_verify_retreat_payment_status()`

**Enhancement:** Return additional state information about booking.

```php
function ajax_verify_retreat_payment_status() {
    check_ajax_referer('retreat_nonce', 'nonce');
    
    $booking_token = sanitize_text_field($_POST['token']);
    $booking_data = get_transient('retreat_' . $booking_token);
    
    if (clearbooking_data) {
        wp_send_json_error(['message' => 'Booking session expired']);
        return;
    }
    
    // Check payment status
    $payment_verified = ($booking_data['payment_status'] === 'completed');
    
    // NEW: Check if IPN already created the user
    $user_already_created = false;
    $auto_login_url = '';
    
    if ($payment_verified && !empty($booking_data['user_id'])) {
        $user = get_user_by('id', $booking_data['user_id']);
        if ($user) {
            $user_already_created = true;
            
            // Auto-login the user
            wp_set_current_user($booking_data['user_id']);
            wp_set_auth_cookie($booking_data['user_id'], true);
            do_action('wp_login', $user->user_login, $user);
            
            $auto_login_url = home_url('/my-retreats/'); // Or wherever you want to redirect
            
            error_log('VERIFY: User already created by IPN, auto-logged in: ' . $booking_data['user_id']);
        }
    }
    
    wp_send_json_success([
        'payment_verified' => $payment_verified,
        'user_already_created' => $user_already_created,
        'auto_login_url' => $auto_login_url,
        'booking_state' => $booking_data['booking_state'] ?? 'unknown',
        'retreat_type' => $booking_data['retreat_type'] ?? '',
        'scroll_to_section' => $booking_data['retreat_type'] ?? ''
    ]);
}
```

### 3.5 Phase 5: Modify Questionnaire Submission Handler

**File:** [retreat_paytabs_integration.php](retreat/retreat_paytabs_integration.php#L543-L679)

**Function:** `ajax_complete_retreat_registration()`

**Changes:** Make this function handle ONLY questionnaire saving if user already exists.

```php
function ajax_complete_retreat_registration() {
    check_ajax_referer('retreat_nonce', 'nonce');
    
    $booking_token = sanitize_text_field($_POST['token']);
    $questionnaire_json = sanitize_textarea_field($_POST['questionnaire_answers']);
    
    $booking_data = get_transient('retreat_' . $booking_token);
    
    if (clearbooking_data) {
        wp_send_json_error(['message' => 'Session expired']);
        return;
    }
    
    if ($booking_data['payment_status'] !== 'completed') {
        wp_send_json_error(['message' => 'Payment not completed']);
        return;
    }
    
    // ============================================
    // NEW: Check if IPN already created the user
    // ============================================
    if (!empty($booking_data['user_id'])) {
        $user = get_user_by('id', $booking_data['user_id']);
        if ($user) {
            // User already exists, just save questionnaire
            error_log('QUESTIONNAIRE: User already exists (ID: ' . $booking_data['user_id'] . '), saving answers only');
            
            $user_id = $booking_data['user_id'];
            
            // Auto-login if not already
            if (get_current_user_id() !== $user_id) {
                wp_set_current_user($user_id);
                wp_set_auth_cookie($user_id, true);
                do_action('wp_login', $user->user_login, $user);
            }
            
            // Save questionnaire answers
            $save_result = save_retreat_questionnaire_answers(
                $user_id,
                $questionnaire_json,
                $booking_data
            );
            
            if ($save_result) {
                // Update state to fully completed
                $booking_data['booking_state'] = 'fully_completed';
                $booking_data['questionnaire_submitted_at'] = current_time('mysql');
                set_transient('retreat_' . $booking_token, $booking_data, 3600);
                
                // Now safe to delete transient after short delay
                // (Keep it briefly for confirmation page)
                
                wp_send_json_success([
                    'message' => 'Questionnaire saved successfully',
                    'user_id' => $user_id,
                    'redirect_url' => home_url('/my-retreats/')
                ]);
                return;
            } else {
                wp_send_json_error(['message' => 'Failed to save questionnaire']);
                return;
            }
        }
    }
    
    // ============================================
    // FALLBACK: IPN didn't create user (rare case)
    // ============================================
    error_log('QUESTIONNAIRE: IPN did not create user, creating now (fallback)');
    
    // Use the new IPN processor function
    $booking_result = process_retreat_booking_from_ipn($booking_data, $booking_token);
    
    if (clearbooking_result['success']) {
        wp_send_json_error(['message' => $booking_result['message']]);
        return;
    }
    
    $user_id = $booking_result['user_id'];
    
    // Auto-login
    $user = get_user_by('id', $user_id);
    wp_set_current_user($user_id);
    wp_set_auth_cookie($user_id, true);
    do_action('wp_login', $user->user_login, $user);
    
    // Save questionnaire
    save_retreat_questionnaire_answers($user_id, $questionnaire_json, $booking_data);
    
    // Update state
    $booking_data['user_id'] = $user_id;
    $booking_data['booking_state'] = 'fully_completed';
    $booking_data['booking_created_at'] = current_time('mysql');
    $booking_data['questionnaire_submitted_at'] = current_time('mysql');
    set_transient('retreat_' . $booking_token, $booking_data, 3600);
    
    wp_send_json_success([
        'message' => 'Registration complete',
        'user_id' => $user_id
    ]);
}
```

### 3.6 Phase 6: Extract Questionnaire Saving Logic

**New Helper Function:** `save_retreat_questionnaire_answers()`

```php
/**
 * Save questionnaire answers to database
 * Extracted for reusability
 */
function save_retreat_questionnaire_answers($user_id, $questionnaire_json, $booking_data) {
    global $wpdb;
    
    $questionnaire = json_decode(stripslashes($questionnaire_json), true);
    
    if (!is_array($questionnaire) || empty($questionnaire)) {
        error_log('Invalid questionnaire data');
        return false;
    }
    
    $questions = get_retreat_questionnaire_questions(); // Existing function
    
    $table_name = $wpdb->prefix . 'retreat_questionnaire_answers';
    
    foreach ($questionnaire as $index => $answer) {
        if (empty($answer)) continue;
        
        $wpdb->insert($table_name, [
            'user_id' => $user_id,
            'retreat_type' => $booking_data['retreat_type'] ?? '',
            'retreat_group_id' => $booking_data['group_id'] ?? 0,
            'question_number' => $index + 1,
            'question_text' => $questions[$index] ?? 'Question ' . ($index + 1),
            'answer' => sanitize_textarea_field($answer),
            'created_at' => current_time('mysql')
        ], [
            '%d', '%s', '%d', '%d', '%s', '%s', '%s'
        ]);
    }
    
    error_log('Saved ' . count($questionnaire) . ' questionnaire answers for user: ' . $user_id);
    return true;
}
```

---

## 4. Failure Handling & Edge Cases

### 4.1 Idempotency Protection

**Problem:** PayTabs might send duplicate IPN callbacks.

**Solution:** Check `$booking_data['user_id']` before creating user.

**Implementation:** Already included in `process_retreat_booking_from_ipn()` (section 3.2).

### 4.2 Transient Expiration

**Problem:** User might return after 1 hour (current transient TTL).

**Solution:** Increase transient TTL to 24 hours after payment success.

**Implementation:**
```php
// In IPN handler, after successful payment
set_transient('retreat_' . $booking_token, $booking_data, 86400); // 24 hours
```

### 4.3 IPN Processing Failure

**Problem:** IPN tries to create user but fails (e.g., database error).

**Solution:** 
1. Log error in `booking_errors` array
2. Keep transient alive for manual recovery
3. Send admin email notification

**Implementation:**
```php
// In paytabs_handle_callback()
if (clearbooking_result['success']) {
    $booking_data['booking_errors'][] = [
        'timestamp' => current_time('mysql'),
        'message' => $booking_result['message']
    ];
    
    // Send admin notification
    wp_mail(
        get_option('admin_email'),
        'URGENT: Retreat IPN Booking Failed',
        "Booking token: $booking_token\nError: " . $booking_result['message'],
        ['Content-Type: text/plain; charset=UTF-8']
    );
}
```

### 4.4 Race Condition: IPN vs Frontend

**Problem:** IPN and frontend questionnaire submission happen simultaneously.

**Solution:** Idempotency check in both paths. First one wins, second one detects existing user.

**Already Handled:** Both `process_retreat_booking_from_ipn()` and `ajax_complete_retreat_registration()` check for existing user_id.

### 4.5 Failed Payment Scenarios

**Problem:** User pays but payment gets declined/refunded.

**Solution:**
1. IPN sets `booking_state = 'failed'`
2. Frontend redirects to failure page
3. Admin can manually refund if needed

**Implementation:**
```php
// In paytabs_handle_callback()
if ($payment_status !== 'A') {
    $booking_data['payment_status'] = 'failed';
    $booking_data['booking_state'] = 'failed';
    $booking_data['failure_reason'] = $callback_data['payment_result']['response_message'] ?? 'Unknown';
    
    // Do NOT create user or booking
    set_transient('retreat_' . $booking_token, $booking_data, 86400);
    return;
}
```

### 4.6 User Returns After IPN Created Booking

**Problem:** User closes browser, comes back later, sees questionnaire modal.

**Solution:** Detect existing user in `verify_retreat_payment_status` and show appropriate message.

**Implementation:** Already covered in section 3.3 & 3.4.

---

## 5. Data Flow Diagram: Before vs After

### 5.1 BEFORE (Current Vulnerable Flow)

```
┌─────────────┐
│   User      │
│ Submits     │ ──┐
│ Personal    │   │
│ Info Form   │   │
└─────────────┘   │
                  ▼
          ┌──────────────────┐
          │ save_booking_data│
          │   (AJAX)         │
          │ Creates transient│
          └────────┬─────────┘
                   │
                   ▼
          ┌──────────────────┐
          │ initiate_payment │
          │   (AJAX)         │
          │ Calls PayTabs API│
          └────────┬─────────┘
                   │
                   ▼
          ┌──────────────────┐
          │ Redirect to      │
          │ PayTabs HPP      │
          └────────┬─────────┘
                   │
        ┌──────────┴──────────┐
        │                     │
        ▼                     ▼
┌──────────────┐      ┌──────────────┐
│ PayTabs      │      │ PayTabs      │
│ Callback/IPN │      │ Redirects    │
│ (Background) │      │ User Back    │
└──────┬───────┘      └──────┬───────┘
       │                     │
       ▼                     ▼
┌──────────────┐      ┌──────────────┐
│ Updates      │      │ verify_      │
│ transient    │      │ payment_     │
│ payment_     │      │ status       │
│ status ONLY  │      │ (AJAX)       │
└──────────────┘      └──────┬───────┘
                             │
                ┌────────────┴────────────┐
                │ ❌ IF USER CLOSES       │
                │ BROWSER HERE,           │
                │ JOURNEY ENDS!           │
                └────────────┬────────────┘
                             │
                             ▼
                ┌────────────────────────┐
                │ Opens Questionnaire    │
                │ Modal (Frontend)       │
                └────────┬───────────────┘
                         │
                         ▼
                ┌────────────────────────┐
                │ User Submits           │
                │ Questionnaire          │
                └────────┬───────────────┘
                         │
                         ▼
                ┌────────────────────────┐
                │ complete_retreat_      │
                │ registration (AJAX)    │
                │                        │
                │ ✅ CREATE USER         │
                │ ✅ ASSIGN RETREAT      │
                │ ✅ ENROLL BP           │
                │ ✅ SAVE QUESTIONNAIRE  │
                └────────────────────────┘
```

### 5.2 AFTER (Reliable IPN-Driven Flow)

```
┌─────────────┐
│   User      │
│ Submits     │ ──┐
│ Personal    │   │
│ Info Form   │   │
└─────────────┘   │
                  ▼
          ┌──────────────────┐
          │ save_booking_data│
          │   (AJAX)         │
          │ Creates transient│
          │ booking_state =  │
          │ 'initiated'      │
          └────────┬─────────┘
                   │
                   ▼
          ┌──────────────────┐
          │ initiate_payment │
          │   (AJAX)         │
          │ Calls PayTabs API│
          └────────┬─────────┘
                   │
                   ▼
          ┌──────────────────┐
          │ Redirect to      │
          │ PayTabs HPP      │
          └────────┬─────────┘
                   │
        ┌──────────┴──────────┐
        │                     │
        ▼                     ▼
┌──────────────────────┐  ┌──────────────┐
│ PayTabs Callback/IPN │  │ PayTabs      │
│ (Background)         │  │ Redirects    │
└──────┬───────────────┘  │ User Back    │
       │                  └──────┬───────┘
       ▼                         │
┌─────────────────────────────┐  │
│ paytabs_handle_callback()   │  │
│                             │  │
│ 1. payment_status =         │  │
│    'completed'              │  │
│ 2. booking_state =          │  │
│    'payment_completed'      │  │
│ 3. Call:                    │  │
│    process_retreat_         │  │
│    booking_from_ipn()       │  │
│                             │  │
│ ✅ CREATE USER              │  │
│ ✅ ASSIGN RETREAT           │  │
│ ✅ ENROLL BP                │  │
│ ✅ SEND EMAIL               │  │
│                             │  │
│ 4. booking_state =          │  │
│    'booking_confirmed'      │  │
│ 5. Store user_id in         │  │
│    transient                │  │
│ 6. TTL = 24 hours           │  │
└─────────────────────────────┘  │
                                 │
       ┌─────────────────────────┘
       │
       ▼
┌─────────────────────────────┐
│ verify_payment_status       │
│ (AJAX)                      │
│                             │
│ Checks booking_state:       │
│ - If 'booking_confirmed'    │
│   → User already created    │
│   → Auto-login user         │
│   → Show success message    │
│   → Questionnaire optional  │
│                             │
│ - If 'payment_completed'    │
│   → IPN failed (rare)       │
│   → Show questionnaire      │
│   → Fallback path           │
└──────────┬──────────────────┘
           │
           ▼
┌──────────────────────────────┐
│ Questionnaire Modal          │
│ (Optional/Additional Data)   │
└──────────┬───────────────────┘
           │
           ▼
┌──────────────────────────────┐
│ complete_retreat_            │
│ registration (AJAX)          │
│                              │
│ IF user_id exists:           │
│   ✅ SAVE QUESTIONNAIRE ONLY │
│   ✅ booking_state =         │
│      'fully_completed'       │
│                              │
│ ELSE (fallback):             │
│   ✅ CREATE USER             │
│   ✅ ASSIGN RETREAT          │
│   ✅ ENROLL BP               │
│   ✅ SAVE QUESTIONNAIRE      │
└──────────────────────────────┘
```

---

## 6. Testing Strategy

### 6.1 Happy Path Testing

**Test Case 1: Normal Flow (IPN arrives before user return)**

1. User submits personal info form
2. Get redirected to PayTabs
3. Complete payment successfully
4. PayTabs sends IPN → creates user & booking
5. User gets redirected back to site
6. JavaScript calls `verify_payment_status`
7. Response shows `user_already_created: true`
8. User auto-logged in
9. Questionnaire modal shown (optional)
10. User submits questionnaire
11. Answers saved to database

**Expected Result:** ✅ User registered, ✅ In retreat group, ✅ Questionnaire saved

### 6.2 Critical Failure Scenario Testing

**Test Case 2: User closes browser after payment**

1. User submits personal info form
2. Get redirected to PayTabs
3. Complete payment successfully
4. PayTabs sends IPN → creates user & booking
5. ❌ User closes browser (never returns to site)

**Manual Verification:**
- Check `wp_users` table → user exists
- Check user meta `assigned_retreat_group` → retreat group ID set
- Check BuddyPress group members → user enrolled
- Check email inbox → confirmation email sent

**Expected Result:** ✅ User fully registered despite frontend abandonment

**Test Case 3: Internet drops during redirect**

1. User completes payment
2. PayTabs sends IPN → creates user & booking
3. ❌ Internet connection drops during redirect
4. User reconnects 10 minutes later
5. User manually navigates back to retreat page

**Manual Verification:**
- User logs in with credentials
- User sees "My Retreats" page
- Retreat booking visible

**Expected Result:** ✅ Booking persists

### 6.3 Edge Case Testing

**Test Case 4: IPN arrives after user return (race condition)**

1. User completes payment
2. User redirected back immediately (fast internet)
3. Frontend calls `verify_payment_status` BEFORE IPN arrives
4. Response shows `user_already_created: false`
5. Questionnaire modal shown
6. IPN arrives → creates user
7. User submits questionnaire
8. Backend detects `user_id` exists → saves questionnaire only

**Expected Result:** ✅ No duplicate user created, ✅ Idempotency works

**Test Case 5: Duplicate IPN callbacks**

1. PayTabs sends IPN (creates user)
2. PayTabs sends duplicate IPN 5 seconds later
3. Second IPN checks `user_id` exists → returns success, skips creation

**Expected Result:** ✅ No errors, ✅ No duplicate users

**Test Case 6: Transient expires**

1. User completes payment (IPN creates user)
2. User doesn't return for 25 hours
3. Transient deleted (24-hour TTL exceeded)
4. User manually logs in

**Expected Result:** ✅ User account still exists, ✅ Retreat assignment intact

**Note:** Questionnaire won't be accessible after transient expiry (acceptable loss)

### 6.4 Rollback/Refund Testing

**Test Case 7: Failed payment**

1. User submits form
2. PayTabs payment declined
3. IPN receives `response_status = 'D'`
4. IPN sets `booking_state = 'failed'`
5. User redirected back
6. Frontend shows "Payment failed" message

**Expected Result:** ✅ No user created, ✅ No booking

**Test Case 8: Payment refunded later**

1. User completes booking successfully
2. Admin issues refund in PayTabs dashboard
3. PayTabs sends refund IPN

**Current Limitation:** Refund IPN not handled yet

**Future Enhancement:** Add refund detection in callback handler:
```php
if ($callback_data['tran_type'] === 'refund') {
    // Remove user from retreat group
    delete_user_meta($user_id, 'assigned_retreat_group');
    // Notify admin
}
```

---

## 7. Security Considerations

### 7.1 IPN Authentication

**Problem:** Malicious actors could send fake IPN requests.

**Solution:** Verify IPN signature (if PayTabs provides one).

**Implementation:**
```php
function paytabs_handle_callback() {
    // Get callback data
    $callback_data = json_decode(file_get_contents('php://input'), true);
    
    // Verify signature (if PayTabs provides server key in callback)
    $signature = $callback_data['signature'] ?? '';
    $server_key = get_option('paytabs_server_key');
    
    // PayTabs signature verification logic
    // (Check PayTabs docs for exact implementation)
    
    if (!paytabs_verify_signature($callback_data, $signature, $server_key)) {
        error_log('IPN SECURITY: Invalid signature detected!');
        http_response_code(403);
        die('Invalid signature');
    }
    
    // Continue processing...
}
```

**Documentation Reference:** Check PayTabs official docs for signature verification method.

### 7.2 Nonce Verification

**Current Status:** ✅ All AJAX endpoints use `check_ajax_referer()`

**No Changes Needed:** Existing nonce system is adequate.

### 7.3 Data Sanitization

**Current Status:** ✅ All inputs sanitized with `sanitize_text_field()`, `sanitize_email()`, etc.

**Enhancement:** Add extra validation in IPN processor:

```php
function process_retreat_booking_from_ipn($booking_data, $booking_token) {
    // Validate email format
    if (!is_email($booking_data['email'])) {
        return ['success' => false, 'message' => 'Invalid email format'];
    }
    
    // Validate group_id is a valid retreat post
    $retreat_post = get_post($booking_data['group_id']);
    if (clearretreat_post || $retreat_post->post_type !== 'retreat_group') {
        return ['success' => false, 'message' => 'Invalid retreat group'];
    }
    
    // Continue...
}
```

### 7.4 Rate Limiting

**Problem:** Spam IPN requests could overload server.

**Solution:** Implement rate limiting by IP or transient checks.

**Implementation (Optional):**
```php
function paytabs_handle_callback() {
    // Check rate limit
    $ip = $_SERVER['REMOTE_ADDR'];
    $rate_key = 'ipn_rate_' . md5($ip);
    $request_count = get_transient($rate_key) ?: 0;
    
    if ($request_count > 10) { // Max 10 requests per minute
        http_response_code(429);
        die('Rate limit exceeded');
    }
    
    set_transient($rate_key, $request_count + 1, 60);
    
    // Continue processing...
}
```

---

## 8. Monitoring & Observability

### 8.1 Enhanced Logging

**Create New Log Table:** `wp_retreat_booking_log`

```sql
CREATE TABLE wp_retreat_booking_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_token VARCHAR(64) NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    event_data LONGTEXT,
    user_id BIGINT UNSIGNED DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token (booking_token),
    INDEX idx_event (event_type),
    INDEX idx_user (user_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Log Events:**
- `booking_initiated` - Personal info form submitted
- `payment_initiated` - Redirected to PayTabs
- `ipn_received` - PayTabs callback received
- `booking_created` - User account created by IPN
- `booking_failed` - IPN processing error
- `questionnaire_submitted` - User completed questionnaire
- `booking_fully_completed` - All steps done

**Helper Function:**
```php
function log_retreat_booking_event($booking_token, $event_type, $event_data = []) {
    global $wpdb;
    
    $wpdb->insert(
        $wpdb->prefix . 'retreat_booking_log',
        [
            'booking_token' => $booking_token,
            'event_type' => $event_type,
            'event_data' => json_encode($event_data),
            'user_id' => $event_data['user_id'] ?? null,
            'created_at' => current_time('mysql')
        ],
        ['%s', '%s', '%s', '%d', '%s']
    );
}
```

### 8.2 Admin Dashboard Widget

**New Widget:** "Recent Retreat Bookings"

**Location:** WordPress Admin Dashboard

**Display:**
- Last 10 bookings
- Booking state (color-coded)
- Payment status
- Time since payment
- Quick link to user profile

**Implementation:**
```php
function retreat_booking_dashboard_widget() {
    add_meta_box(
        'retreat_recent_bookings',
        'Recent Retreat Bookings',
        'render_retreat_booking_widget',
        'dashboard',
        'normal',
        'high'
    );
}
add_action('wp_dashboard_setup', 'retreat_booking_dashboard_widget');

function render_retreat_booking_widget() {
    global $wpdb;
    
    $logs = $wpdb->get_results("
        SELECT booking_token, event_type, event_data, created_at
        FROM {$wpdb->prefix}retreat_booking_log
        WHERE event_type IN ('booking_created', 'booking_failed')
        ORDER BY created_at DESC
        LIMIT 10
    ");
    
    echo '<table class="widefat">';
    // Render table...
    echo '</table>';
}
```

### 8.3 Error Alerting

**Send Admin Email on Critical Errors:**

```php
// In paytabs_handle_callback()
if (clearbooking_result['success']) {
    wp_mail(
        get_option('admin_email'),
        '[URGENT] Retreat Booking Failed - Manual Review Required',
        "A retreat booking failed to process after successful payment.\n\n" .
        "Booking Token: $booking_token\n" .
        "Error: " . $booking_result['message'] . "\n" .
        "User Email: " . $booking_data['email'] . "\n" .
        "Amount Paid: " . $booking_data['amount'] . "\n\n" .
        "Please manually create the booking in WordPress admin.\n\n" .
        "Transaction Reference: " . $booking_data['tran_ref'],
        ['Content-Type: text/plain; charset=UTF-8']
    );
}
```

---

## 9. Rollout Plan

### 9.1 Pre-Deployment Checklist

- [ ] Backup entire database
- [ ] Backup all retreat PHP files
- [ ] Test on staging environment first
- [ ] Verify PayTabs webhook endpoint is accessible
- [ ] Check PayTabs dashboard callback URL is configured correctly
- [ ] Increase transient TTL to 24 hours
- [ ] Create booking log table
- [ ] Test with PayTabs test mode

### 9.2 Deployment Steps

**Step 1: Database Changes**
```sql
-- Run in phpMyAdmin or WP-CLI
CREATE TABLE wp_retreat_booking_log (...);
```

**Step 2: Code Deployment**

1. Upload modified `retreat_paytabs_integration.php`
2. Upload modified `retreat_system.php`
3. Clear WordPress object cache (if using)
4. Clear page cache (if using)

**Step 3: PayTabs Configuration**

1. Login to PayTabs dashboard
2. Navigate to Integration Settings
3. Verify callback URL: `https://yoursite.com/payment-callback/`
4. Ensure "Send IPN" is enabled

**Step 4: Testing**

1. Create test retreat group
2. Perform end-to-end booking with test card
3. Monitor error logs: `tail -f /path/to/debug.log`
4. Verify user created in WordPress admin
5. Check BuddyPress group membership
6. Confirm email received

**Step 5: Monitor First 24 Hours**

- Check `wp_retreat_booking_log` table hourly
- Monitor `wp_paytabs_logs` for IPN callbacks
- Watch for admin error emails
- Verify no duplicate users created

### 9.3 Rollback Plan

**If Critical Issues Occur:**

1. Restore backup files:
   ```bash
   cp retreat_paytabs_integration.php.backup retreat_paytabs_integration.php
   cp retreat_system.php.backup retreat_system.php
   ```

2. Keep new booking log table (for forensics)

3. Manually process any stuck bookings:
   - Query `wp_paytabs_logs` for successful payments
   - Cross-reference with `wp_users` to find missing users
   - Manually create user accounts
   - Assign to retreat groups

---

## 10. Future Enhancements (Post-MVP)

### 10.1 Questionnaire Recovery Page

**Problem:** Users who paid but didn't submit questionnaire lose access after transient expires.

**Solution:** Create a "Complete Your Profile" page.

**Implementation:**
- Check if logged-in user has `assigned_retreat_group` but no questionnaire answers
- Show questionnaire form
- Save to database

### 10.2 Admin Manual Recovery Tool

**Feature:** WP Admin page to manually process stuck bookings.

**UI:**
- List all transients with `booking_state = 'payment_completed'`
- Button: "Create Booking Manually"
- Calls `process_retreat_booking_from_ipn()` with button click

### 10.3 Webhook Retry Mechanism

**Problem:** If IPN fails due to temporary server issue, booking is lost.

**Solution:** PayTabs sends multiple retry attempts, but add our own recovery:

- Cron job runs every 6 hours
- Queries `wp_paytabs_logs` for successful payments
- Checks if corresponding user exists
- Auto-creates missing bookings

### 10.4 SMS Notifications

**Feature:** Send SMS after booking confirmation.

**Integration:** Use Twilio or local Saudi SMS gateway.

**Trigger:** After `process_retreat_booking_from_ipn()` succeeds.

---

## 11. Success Metrics

### 11.1 KPIs to Track

| Metric | Target | Measurement |
|--------|--------|-------------|
| Payment-to-Booking Conversion | 100% | Count users with `payment_transaction_id` vs `assigned_retreat_group` |
| IPN Processing Success Rate | >99% | Count `booking_created` events vs `ipn_received` events |
| Questionnaire Completion Rate | N/A (optional now) | Count bookings with questionnaire vs without |
| Average IPN Processing Time | <5 seconds | Log timestamp difference |
| Zero Lost Payments | 0 lost bookings | Weekly audit |

### 11.2 Weekly Audit Query

```sql
-- Find paid users with no retreat assignment (should be zero)
SELECT u.ID, u.user_email, um.meta_value AS tran_id
FROM wp_users u
INNER JOIN wp_usermeta um ON u.ID = um.user_id AND um.meta_key = 'payment_transaction_id'
LEFT JOIN wp_usermeta um2 ON u.ID = um2.user_id AND um2.meta_key = 'assigned_retreat_group'
WHERE um2.meta_value IS NULL;
```

**Expected Result:** 0 rows

---

## 12. Documentation

### 12.1 Update System Context Document

**File to Update:** [retreat_system_context.md](retreat/retreat_system_context.md)

**Add Section:**
```markdown
## IPN-Driven Booking Architecture

As of January 2026, the retreat booking system uses PayTabs IPN (Instant Payment Notification) to guarantee booking creation immediately upon payment success, independent of frontend state.

### Booking States:
1. **initiated** - User submitted personal info
2. **payment_completed** - Payment succeeded (IPN received)
3. **booking_confirmed** - User account created & assigned to retreat
4. **questionnaire_pending** - Awaiting questionnaire submission
5. **fully_completed** - Questionnaire saved

### Critical Functions:
- `paytabs_handle_callback()` - Receives IPN and triggers booking creation
- `process_retreat_booking_from_ipn()` - Creates user and assigns retreat
- `save_retreat_questionnaire_answers()` - Saves optional questionnaire

### Data Persistence:
- Transient TTL: 24 hours (increased from 1 hour)
- Booking state stored in transient
- Recovery possible via admin tools
```

### 12.2 Developer Onboarding Guide

**Create New File:** `retreat/DEVELOPER_GUIDE.md`

**Contents:**
- System architecture diagram
- Booking state flowchart
- Common debugging scenarios
- How to test IPN locally
- How to manually recover stuck bookings

---

## 13. CONCLUSION

### 13.1 Summary of Changes

| Component | Change Type | Risk Level | Lines of Code |
|-----------|-------------|------------|---------------|
| `paytabs_handle_callback()` | Major Enhancement | Medium | ~60 lines |
| New function: `process_retreat_booking_from_ipn()` | New | Medium | ~150 lines |
| New function: `save_retreat_questionnaire_answers()` | Refactor | Low | ~40 lines |
| `ajax_verify_retreat_payment_status()` | Minor Enhancement | Low | ~30 lines |
| `ajax_complete_retreat_registration()` | Major Refactor | Medium | ~80 lines |
| JavaScript payment return handler | Minor Enhancement | Low | ~20 lines |
| Database: New log table | New | Low | N/A |

**Total:** ~380 lines of new/modified code

**Risk Assessment:** Medium (core booking logic changes)

**Mitigation:** Comprehensive testing + staged rollout + rollback plan

### 13.2 Benefits

✅ **100% booking guarantee** - No more lost payments  
✅ **Fault tolerance** - Resilient to browser crashes, internet drops  
✅ **Idempotency** - Duplicate IPNs handled safely  
✅ **Backward compatible** - Frontend questionnaire still works  
✅ **Observable** - Comprehensive logging & monitoring  
✅ **Recoverable** - Admin tools for manual intervention  
✅ **Secure** - No new attack vectors introduced  

### 13.3 Trade-offs

⚠️ **Questionnaire becomes optional** - Users might skip it (acceptable)  
⚠️ **Code complexity increase** - More state management logic  
⚠️ **Testing requirement** - Need thorough edge case testing  

---

## 14. APPROVAL REQUIRED

**This plan is ready for review.**

**Before proceeding with implementation, please confirm:**

1. ✅ Architecture approach is sound
2. ✅ State machine design is acceptable
3. ✅ Security considerations are adequate
4. ✅ Testing strategy is comprehensive
5. ✅ Rollout plan is safe

**Approved by:** _________________________

**Date:** _________________________

**Implementation Start Date:** _________________________

---

**END OF PLAN**
