# PayTabs Payment Integration for Therapy Group Sessions
## Implementation Plan & Architecture Design

**Author:** AI Senior Backend Engineer  
**Date:** January 29, 2026  
**Status:** ⚠️ PENDING APPROVAL - DO NOT IMPLEMENT WITHOUT CONFIRMATION

---

## Executive Summary

This document outlines the implementation plan for integrating PayTabs payment gateway into the **therapy group session booking flow**, ensuring:

✅ **Payment-first architecture** - No booking without successful payment  
✅ **Zero data loss** - User registration, booking, and enrollment guaranteed after payment  
✅ **Leverages existing PayTabs infrastructure** from retreat system  
✅ **No race conditions** - IPN-driven backend processing  
✅ **Consistent with retreat journey** - Same UX patterns and reliability

---

## Table of Contents

1. [Current State Analysis](#1-current-state-analysis)
2. [Target Architecture](#2-target-architecture)
3. [Key Design Decisions](#3-key-design-decisions)
4. [Implementation Strategy](#4-implementation-strategy)
5. [Code Changes Required](#5-code-changes-required)
6. [Data Flow](#6-data-flow)
7. [Security Considerations](#7-security-considerations)
8. [Testing Plan](#8-testing-plan)
9. [Rollout Plan](#9-rollout-plan)
10. [Approval Checklist](#10-approval-checklist)

---

## 1. Current State Analysis

### 1.1 Therapy Session Booking Flow (Current)

```
User Journey:
┌─────────────────────────────────────────┐
│ 1. User lands on therapy groups page   │
│    - Views available groups by issue   │
│    - Selects group_id (from URL param) │
└──────────────┬──────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────┐
│ 2. User fills registration form         │
│    - Custom form OR UM plugin form      │
│    - Personal details (name, email...)  │
│    - Password                           │
│    - Passport scan upload               │
└──────────────┬──────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────┐
│ 3. Form submitted (AJAX or UM handler)  │
│    ✅ WordPress user created             │
│    ✅ User logged in automatically       │
│    ✅ assigned_group user meta set       │
│    ✅ BuddyPress enrollment attempted    │
└──────────────┬──────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────┐
│ 4. Redirect to thank-you page          │
│    - User now has account               │
│    - User assigned to therapy group     │
└─────────────────────────────────────────┘
```

**Critical Files:**
- `therapy_session_booking/User_Registration.php` - Registration handlers
- `therapy_session_booking/Therapy_group_admin_dashboard.php` - Group management & BP enrollment

**Problems with Current Flow:**
- ❌ No payment required
- ❌ Free registration allows unlimited group joining
- ❌ No revenue collection
- ❌ No payment verification

### 1.2 Retreat Payment Flow (Reference Architecture)

```
User Journey:
┌─────────────────────────────────────────┐
│ 1. User submits personal info form      │
│    - AJAX: save_retreat_booking_data    │
│    - Creates transient with booking_token│
│    - NO user created yet                │
└──────────────┬─────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────┐
│ 2. Redirect to PayTabs HPP             │
│    - AJAX: initiate_retreat_payment     │
│    - Returns redirect_url               │
│    - User pays on PayTabs page          │
└──────────────┬─────────────────────────┘
               │
       ┌───────┴────────┐
       │                │
       ▼                ▼
┌──────────────┐  ┌─────────────────────┐
│ PayTabs IPN  │  │ User redirected back│
│  callback    │  │ to retreat page     │
└──────┬───────┘  └──────┬──────────────┘
       │                 │
       ▼                 │
┌──────────────────────────────────┐     │
│ IPN: process_retreat_booking     │     │
│ ✅ CREATE WordPress user          │     │
│ ✅ Assign to retreat group        │     │
│ ✅ Enroll in BuddyPress           │     │
│ ✅ Send confirmation email        │     │
│ ✅ Update transient: user_id set  │     │
└──────────────┬───────────────────┘     │
               │                         │
               └─────────┬───────────────┘
                         ▼
      ┌────────────────────────────────────┐
      │ verify_payment_status (AJAX)       │
      │ - Checks if user_id exists         │
      │ - Auto-logins user if created      │
      │ - Shows questionnaire modal        │
      └────────────┬───────────────────────┘
                   ▼
      ┌────────────────────────────────────┐
      │ Questionnaire submission (optional)│
      │ - Saves additional wellness data   │
      └────────────────────────────────────┘
```

**Key Features We Must Replicate:**
- ✅ Payment BEFORE user creation
- ✅ IPN-driven backend booking
- ✅ Idempotency (handles duplicate IPN)
- ✅ 24-hour transient storage
- ✅ Auto-login after payment verification
- ✅ Fallback if IPN fails

---

## 2. Target Architecture

### 2.1 New Therapy Booking Flow (With Payment)

```
New Payment-First Journey:
┌─────────────────────────────────────────┐
│ 1. User views therapy groups page      │
│    - Selects issue type & gender        │
│    - Clicks on specific group           │
│    - group_id captured in session       │
└──────────────┬──────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────┐
│ 2. User fills therapy registration form│
│    [Same custom form as current]        │
│    - Personal details                   │
│    - Email, phone, DOB                  │
│    - Passport number                    │
│    - Password                           │
│    ⚠️ NO user created yet!              │
└──────────────┬──────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────┐
│ 3. AJAX: save_therapy_booking_data      │
│    - Store form data in transient       │
│    - Generate booking_token             │
│    - TTL: 1 hour (pre-payment)          │
│    - booking_state: 'initiated'         │
└──────────────┬──────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────┐
│ 4. AJAX: initiate_therapy_payment       │
│    - Call PayTabs API                   │
│    - Get HPP redirect_url               │
│    - Store tran_ref in transient        │
└──────────────┬──────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────┐
│ 5. Redirect to PayTabs HPP             │
│    - User enters credit card            │
│    - Completes payment                  │
└──────────────┬──────────────────────────┘
               │
       ┌───────┴────────┐
       │                │
       ▼                ▼
┌──────────────┐  ┌─────────────────────┐
│ PayTabs IPN  │  │ User redirected back│
│  callback    │  │ to therapy page     │
└──────┬───────┘  └──────┬──────────────┘
       │                 │
       ▼                 │
┌──────────────────────────────────────┐  │
│ IPN: paytabs_handle_therapy_callback │  │
│                                      │  │
│ payment_status = 'completed'         │  │
│ booking_state = 'payment_completed'  │  │
│                                      │  │
│ Call: process_therapy_booking()      │  │
│ ✅ CREATE WordPress user              │  │
│ ✅ Set concern_type, gender metadata  │  │
│ ✅ Assign to therapy group            │  │
│ ✅ Enroll in BuddyPress chat          │  │
│ ✅ Send confirmation email            │  │
│                                      │  │
│ booking_state = 'booking_confirmed'  │  │
│ user_id stored in transient          │  │
│ TTL extended to 24 hours             │  │
└──────────────┬───────────────────────┘  │
               │                          │
               └──────────┬───────────────┘
                          ▼
         ┌──────────────────────────────────┐
         │ verify_therapy_payment (AJAX)    │
         │                                  │
         │ IF user_id exists in transient:  │
         │   → User created by IPN          │
         │   → Auto-login user              │
         │   → Show success message         │
         │   → Redirect to thank-you        │
         │                                  │
         │ ELSE (fallback if IPN failed):   │
         │   → Create user now              │
         │   → Assign to group              │
         │   → Enroll in BP                 │
         │   → Login & redirect             │
         └──────────────────────────────────┘
```

### 2.2 Booking State Machine

```
Therapy Booking States:
┏━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┓
┃ initiated                                ┃
┃ - Form data saved in transient           ┃
┃ - booking_token generated                ┃
┃ - selected_group_id stored               ┃
┃ - payment_status: 'pending'              ┃
┗━━━━━━━━━━━━━━━━┳━━━━━━━━━━━━━━━━━━━━━━━━━┛
                 │
                 ▼ (User redirected to PayTabs)
┏━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┓
┃ payment_completed                        ┃
┃ - PayTabs IPN received                   ┃
┃ - payment_status: 'completed'            ┃
┃ - IPN triggered backend booking          ┃
┗━━━━━━━━━━━━━━━━┳━━━━━━━━━━━━━━━━━━━━━━━━━┛
                 │
                 ▼ (IPN processes booking)
┏━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┓
┃ booking_confirmed                        ┃
┃ - WordPress user created                 ┃
┃ - assigned_group user meta set           ┃
┃ - BuddyPress enrollment completed        ┃
┃ - user_id stored in transient            ┃
┃ - Confirmation email sent                ┃
┗━━━━━━━━━━━━━━━━┳━━━━━━━━━━━━━━━━━━━━━━━━━┛
                 │
                 ▼ (User returns to site)
┏━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┓
┃ fully_completed                          ┃
┃ - User auto-logged in                    ┃
┃ - Redirected to thank-you page           ┃
┃ - Transient can be deleted after 24h     ┃
┗━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┛

Alternative Path (Payment Failed):
┏━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┓
┃ failed                                   ┃
┃ - PayTabs returned error/declined        ┃
┃ - payment_status: 'failed'               ┃
┃ - NO user created                        ┃
┃ - User redirected to error page          ┃
┗━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┛
```

---

## 3. Key Design Decisions

### 3.1 Reuse Existing PayTabs Admin Configuration

**Decision:** ✅ Use the same PayTabs credentials from retreat system

**Rationale:**
- `retreat/paytabs_admin.php` already stores:
  - Profile ID
  - Server Key
  - Currency (SAR)
  - Region
  - Mode (test/live)
- No need to duplicate admin settings
- Centralized payment configuration

**Implementation:**
```php
// In therapy payment code, simply call:
$profile_id = get_option('paytabs_profile_id');
$server_key = get_option('paytabs_server_key');
// Use same helper functions from retreat
```

### 3.2 Therapy-Specific Pricing

**Decision:** ✅ Each therapy group has its own price (ACF field)

**Current State:**
- Therapy groups already have ACF fields:
  - `issue_type` (anxiety, depression, grief, relationship)
  - `gender` (male, female)
  - `max_members`
  - `session_start_date`
  - `session_expiry_date`

**New Field Required:**
- `therapy_price` (number field, default: 2500 SAR)

**Admin Dashboard Enhancement:**
- Add "Price (SAR)" field to group creation modal
- Display price in admin group list view

### 3.3 Transient Storage Strategy

**Decision:** ✅ Follow retreat pattern exactly

**Transient Structure:**
```php
$booking_data = [
    // Personal info from form
    'personal_info' => [
        'first_name' => '',
        'last_name' => '',
        'email' => '',
        'phone' => '',
        'passport_no' => '',
        'country' => '',
        'dob' => '',
        'password' => '', // Stored for later user creation
    ],
    
    // Therapy-specific data
    'therapy_type' => 'therapy_session',
    'group_id' => 123, // therapy_group post ID
    'issue_type' => 'anxiety', // From group
    'gender' => 'male', // From group
    'amount' => 2500.00,
    'currency' => 'SAR',
    
    // Payment tracking
    'payment_status' => 'pending', // pending|completed|failed
    'tran_ref' => '',
    'payment_callback' => [],
    
    // Booking state tracking
    'booking_state' => 'initiated',
    'user_id' => null, // Set after user creation
    'booking_created_at' => null,
    'ipn_processed_at' => null,
    'created_at' => '2026-01-29 10:30:00',
    
    // URLs for return flow
    'return_url' => 'https://site.com/therapy-groups/',
    'scroll_to_section' => 'anxiety-male', // Optional
];
```

**Transient Key Format:**
```php
$transient_key = 'therapy_' . $booking_token;
// Example: therapy_a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6
```

**TTL:**
- Initial: 3600 seconds (1 hour) - during payment flow
- After payment: 86400 seconds (24 hours) - for recovery

### 3.4 IPN Callback Endpoint

**Decision:** ✅ Reuse existing `/payment-callback/` endpoint

**Current Retreat Implementation:**
```php
// retreat/retreat_paytabs_integration.php
function paytabs_handle_callback() {
    $callback_data = json_decode(file_get_contents('php://input'), true);
    
    // Check if it's a retreat or therapy booking
    if (isset($callback_data['cart_id'])) {
        if (strpos($callback_data['cart_id'], 'retreat_') === 0) {
            // Process retreat booking
        } elseif (strpos($callback_data['cart_id'], 'therapy_') === 0) {
            // Process therapy booking (NEW!)
        }
    }
}
```

**Enhancement Required:**
- Modify `paytabs_handle_callback()` to detect booking type
- Route to appropriate processor function

### 3.5 User Creation & Enrollment

**Decision:** ✅ Mirror retreat IPN processor exactly

**New Function:**
```php
/**
 * Process therapy booking from IPN/webhook
 * Creates WordPress user and enrolls in therapy group
 * 
 * @param array $booking_data Booking information
 * @param string $booking_token Unique token
 * @return array ['success' => bool, 'user_id' => int|null, 'message' => string]
 */
function process_therapy_booking_from_ipn($booking_data, $booking_token) {
    // 1. Idempotency check
    // 2. Create WordPress user
    // 3. Save user metadata
    // 4. Assign to therapy group (assigned_group meta)
    // 5. Set concern_type & gender
    // 6. Call enroll_user_to_bp_chat_group()
    // 7. Send confirmation email
    // 8. Return success
}
```

**Key Differences from Retreat:**
- Uses `assigned_group` instead of `assigned_retreat_group`
- Uses `concern_type` instead of `retreat_type`
- Calls `enroll_user_to_bp_chat_group()` (therapy version)
- Emails link to therapy groups page, not retreats

---

## 4. Implementation Strategy

### 4.1 Phase 1: Database Schema

**New ACF Field for Therapy Groups:**
```php
// Add to therapy_session_booking functions
if (function_exists('acf_add_local_field_group')) {
    acf_add_local_field([
        'key' => 'field_therapy_price',
        'label' => 'Therapy Price (SAR)',
        'name' => 'therapy_price',
        'type' => 'number',
        'parent' => 'group_therapy_group_fields',
        'default_value' => 500,
        'min' => 0,
        'step' => 0.01,
    ]);
}
```

**Optional: Therapy Payment Log Table**
```sql
CREATE TABLE wp_therapy_payment_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_token VARCHAR(64) NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    event_data LONGTEXT,
    user_id BIGINT UNSIGNED DEFAULT NULL,
    therapy_group_id BIGINT UNSIGNED DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token (booking_token),
    INDEX idx_event (event_type),
    INDEX idx_user (user_id),
    INDEX idx_group (therapy_group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 4.2 Phase 2: Backend AJAX Handlers

**New File:** `therapy_session_booking/therapy_paytabs_integration.php`

**Functions to Create:**

1. `ajax_save_therapy_booking_data()`
   - Sanitize form inputs
   - Generate booking_token
   - Get therapy_price from ACF
   - Store in transient
   - Return token to frontend

2. `ajax_initiate_therapy_payment()`
   - Retrieve transient by token
   - Call PayTabs API
   - Get redirect_url
   - Update transient with tran_ref
   - Return redirect_url

3. `ajax_verify_therapy_payment_status()`
   - Check payment_status in transient
   - If user_id exists: auto-login
   - Else: fallback to create user
   - Return booking state

4. `process_therapy_booking_from_ipn()`
   - Core booking processor
   - Creates user
   - Assigns to group
   - Enrolls in BP
   - Sends email

5. Modify `paytabs_handle_callback()`
   - Add therapy booking detection
   - Call `process_therapy_booking_from_ipn()`

### 4.3 Phase 3: Frontend Integration

**Modify:** `therapy_session_booking/User_Registration.php`

**Changes to Registration Form:**

```javascript
// Current behavior:
$('#therapy-registration-form').on('submit', function(e) {
    e.preventDefault();
    // AJAX: Create user immediately
});

// NEW behavior:
$('#therapy-registration-form').on('submit', function(e) {
    e.preventDefault();
    
    // Step 1: Save booking data (no user creation)
    $.post(THERAPY_REG_AJAX.url, {
        action: 'save_therapy_booking_data',
        nonce: THERAPY_REG_AJAX.nonce,
        personal_info: getFormData(),
        selected_group_id: $('#therapy_selected_group_id').val()
    }, function(response) {
        if (response.success) {
            var bookingToken = response.data.token;
            
            // Step 2: Initiate payment
            $.post(THERAPY_REG_AJAX.url, {
                action: 'initiate_therapy_payment',
                nonce: THERAPY_REG_AJAX.nonce,
                token: bookingToken
            }, function(paymentResponse) {
                if (paymentResponse.success) {
                    // Step 3: Redirect to PayTabs HPP
                    window.location.href = paymentResponse.data.redirect_url;
                }
            });
        }
    });
});
```

**Payment Return Handler:**

```javascript
// On therapy groups page load
if (window.location.search.includes('payment_return=')) {
    var urlParams = new URLSearchParams(window.location.search);
    var bookingToken = urlParams.get('payment_return');
    
    // Show loading message
    showPaymentVerificationMessage();
    
    // Verify payment status
    $.post(THERAPY_REG_AJAX.url, {
        action: 'verify_therapy_payment_status',
        token: bookingToken,
        nonce: THERAPY_REG_AJAX.nonce
    }, function(response) {
        if (response.success && response.data.payment_verified) {
            if (response.data.user_already_created) {
                // Success! User created by IPN
                showSuccessMessage();
                setTimeout(function() {
                    window.location.href = response.data.redirect_url;
                }, 2000);
            } else {
                // Fallback: IPN didn't fire yet (rare)
                // Frontend will trigger user creation
                triggerFallbackRegistration(bookingToken);
            }
        } else {
            // Payment failed
            showPaymentFailedMessage();
        }
    });
}
```

### 4.4 Phase 4: Enhanced IPN Callback Router

**Modify:** `retreat/retreat_paytabs_integration.php`

```php
function paytabs_handle_callback() {
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    
    error_log('=== PAYTABS IPN CALLBACK RECEIVED ===');
    paytabs_log_transaction('callback', $data);
    
    if (!isset($data['cart_id'])) {
        http_response_code(400);
        wp_send_json(['status' => 'error', 'message' => 'No cart_id']);
        return;
    }
    
    $cart_id = sanitize_text_field($data['cart_id']);
    
    // Route to appropriate handler based on cart_id prefix
    if (strpos($cart_id, 'retreat_') === 0) {
        handle_retreat_ipn_callback($data, $cart_id);
    } elseif (strpos($cart_id, 'therapy_') === 0) {
        handle_therapy_ipn_callback($data, $cart_id);
    } else {
        error_log('IPN ERROR: Unknown cart_id format: ' . $cart_id);
        http_response_code(400);
        wp_send_json(['status' => 'error', 'message' => 'Invalid cart_id']);
    }
    
    exit;
}

/**
 * Handle therapy session payment callback
 */
function handle_therapy_ipn_callback($callback_data, $booking_token) {
    error_log('=== THERAPY IPN PROCESSING START ===');
    
    $transient_key = $booking_token; // Already includes 'therapy_' prefix
    $booking_data = get_transient($transient_key);
    
    if (!$booking_data) {
        error_log('IPN ERROR: Transient not found for token: ' . $booking_token);
        wp_send_json(['status' => 'error', 'message' => 'Booking data not found']);
        return;
    }
    
    // Update transient with callback data
    $booking_data['payment_callback'] = $callback_data;
    $booking_data['tran_ref'] = $callback_data['tran_ref'] ?? ($booking_data['tran_ref'] ?? '');
    
    $payment_status = $callback_data['payment_result']['response_status'] ?? '';
    
    if ($payment_status === 'A') {
        // Payment APPROVED
        error_log('IPN: Payment APPROVED for token: ' . $booking_token);
        
        $booking_data['payment_status'] = 'completed';
        $booking_data['booking_state'] = 'payment_completed';
        $booking_data['ipn_processed_at'] = current_time('mysql');
        
        // ★★★ CRITICAL: Create booking immediately ★★★
        $booking_result = process_therapy_booking_from_ipn($booking_data, $booking_token);
        
        if ($booking_result['success']) {
            $booking_data['user_id'] = $booking_result['user_id'];
            $booking_data['booking_state'] = 'booking_confirmed';
            $booking_data['booking_created_at'] = current_time('mysql');
            error_log('IPN SUCCESS: Therapy booking created for user ID: ' . $booking_result['user_id']);
        } else {
            $booking_data['booking_state'] = 'payment_completed';
            if (!isset($booking_data['booking_errors'])) {
                $booking_data['booking_errors'] = [];
            }
            $booking_data['booking_errors'][] = [
                'timestamp' => current_time('mysql'),
                'message' => $booking_result['message']
            ];
            error_log('IPN ERROR: Failed to create booking: ' . $booking_result['message']);
            
            // Send admin notification
            wp_mail(
                get_option('admin_email'),
                '[URGENT] Therapy IPN Booking Failed - Manual Review Required',
                "Booking Token: {$booking_token}\n" .
                "Error: " . $booking_result['message'] . "\n" .
                "Email: " . ($booking_data['personal_info']['email'] ?? 'N/A') . "\n" .
                "Amount: {$booking_data['amount']} {$booking_data['currency']}\n" .
                "Transaction: {$booking_data['tran_ref']}",
                ['Content-Type: text/plain; charset=UTF-8']
            );
        }
        
    } else {
        // Payment FAILED
        error_log('IPN: Payment FAILED for token: ' . $booking_token);
        $booking_data['payment_status'] = 'failed';
        $booking_data['booking_state'] = 'failed';
        $booking_data['failure_reason'] = $callback_data['payment_result']['response_message'] ?? 'Unknown';
    }
    
    // Save updated transient with 24-hour TTL
    set_transient($transient_key, $booking_data, 86400);
    
    wp_send_json(['status' => 'received']);
}
```

### 4.5 Phase 5: Core Booking Processor

**New Function in:** `therapy_session_booking/therapy_paytabs_integration.php`

```php
/**
 * Process therapy booking from IPN/webhook
 * Creates user account, assigns to therapy group, enrolls in BP
 * Idempotent - can be called multiple times safely
 * 
 * @param array $booking_data Booking information from transient
 * @param string $booking_token Unique booking token
 * @return array ['success' => bool, 'user_id' => int|null, 'message' => string]
 */
function process_therapy_booking_from_ipn($booking_data, $booking_token) {
    global $wpdb;
    
    error_log('=== THERAPY IPN BOOKING PROCESSOR START ===');
    error_log('Token: ' . $booking_token);
    
    // ============================================
    // 1. IDEMPOTENCY CHECK
    // ============================================
    if (!empty($booking_data['user_id'])) {
        $existing_user = get_user_by('id', $booking_data['user_id']);
        if ($existing_user) {
            error_log('IPN: User already exists (ID: ' . $booking_data['user_id'] . ')');
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
        return [
            'success' => false,
            'user_id' => null,
            'message' => 'No email in booking data'
        ];
    }
    
    // Check if email already exists
    $existing_user = get_user_by('email', $email);
    if ($existing_user) {
        $user_id = $existing_user->ID;
        error_log('IPN: Email exists, using existing user ID: ' . $user_id);
    } else {
        // ============================================
        // 2. CREATE NEW USER
        // ============================================
        $username = sanitize_user($email);
        $password = $personal_info['password'] ?? wp_generate_password(12, true);
        
        $user_id = wp_create_user($username, $password, $email);
        
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
    $first_name = $personal_info['first_name'] ?? '';
    $last_name = $personal_info['last_name'] ?? '';
    $full_name = trim($first_name . ' ' . $last_name);
    
    update_user_meta($user_id, 'first_name', $first_name);
    update_user_meta($user_id, 'last_name', $last_name);
    update_user_meta($user_id, 'phone_number', $personal_info['phone'] ?? '');
    update_user_meta($user_id, 'passport_no', $personal_info['passport_no'] ?? '');
    update_user_meta($user_id, 'country', $personal_info['country'] ?? '');
    update_user_meta($user_id, 'dob', $personal_info['dob'] ?? '');
    
    // Therapy-specific metadata
    update_user_meta($user_id, 'concern_type', $booking_data['issue_type'] ?? '');
    update_user_meta($user_id, 'gender', $booking_data['gender'] ?? '');
    
    // Payment metadata
    update_user_meta($user_id, 'payment_transaction_id', $booking_data['tran_ref'] ?? '');
    update_user_meta($user_id, 'payment_amount', $booking_data['amount'] ?? 0);
    
    // Update display name
    if ($full_name) {
        wp_update_user([
            'ID' => $user_id,
            'display_name' => $full_name
        ]);
    }
    
    // ============================================
    // 4. ASSIGN TO THERAPY GROUP
    // ============================================
    $group_id = intval($booking_data['group_id'] ?? 0);
    if ($group_id <= 0) {
        return [
            'success' => false,
            'user_id' => $user_id,
            'message' => 'Invalid group_id: ' . $group_id
        ];
    }
    
    $update_result = update_user_meta($user_id, 'assigned_group', $group_id);
    
    if ($update_result === false) {
        // Check if same value already exists
        $existing_group = get_user_meta($user_id, 'assigned_group', true);
        if ($existing_group != $group_id) {
            return [
                'success' => false,
                'user_id' => $user_id,
                'message' => 'Failed to assign user to therapy group'
            ];
        }
    }
    
    error_log('IPN: Assigned user ' . $user_id . ' to therapy group: ' . $group_id);
    
    // ============================================
    // 5. ENROLL IN BUDDYPRESS CHAT GROUP
    // ============================================
    if (function_exists('enroll_user_to_bp_chat_group')) {
        error_log('IPN: Enrolling user in BuddyPress chat group');
        
        $bp_result = enroll_user_to_bp_chat_group($user_id, $group_id);
        
        if ($bp_result) {
            error_log('IPN: ✓ Enrolled user in BuddyPress chat group');
        } else {
            error_log('IPN WARNING: BuddyPress enrollment failed (non-fatal)');
        }
    } else {
        error_log('IPN WARNING: enroll_user_to_bp_chat_group function not available');
    }
    
    // ============================================
    // 6. SEND CONFIRMATION EMAIL
    // ============================================
    $therapy_group_post = get_post($group_id);
    $group_title = $therapy_group_post ? $therapy_group_post->post_title : 'Therapy Group';
    
    $session_start = '';
    $session_expiry = '';
    if (function_exists('get_field')) {
        $session_start = get_field('session_start_date', $group_id);
        $session_expiry = get_field('session_expiry_date', $group_id);
    }
    
    $email_subject = 'Therapy Group Registration Confirmed - Tashafe';
    $email_body = "Dear {$full_name},\n\n";
    $email_body .= "Thank you for registering for therapy group sessions with Tashafe!\n\n";
    $email_body .= "Your registration has been confirmed.\n\n";
    $email_body .= "Group: {$group_title}\n";
    if ($session_start && $session_expiry) {
        $email_body .= "Session Period: {$session_start} to {$session_expiry}\n";
    }
    $email_body .= "\nPayment Transaction ID: " . ($booking_data['tran_ref'] ?? 'N/A') . "\n";
    $email_body .= "Amount Paid: " . ($booking_data['amount'] ?? '0') . " " . ($booking_data['currency'] ?? 'SAR') . "\n\n";
    $email_body .= "You can access your therapy group chat and sessions at:\n";
    $email_body .= home_url('/groups/') . "\n\n";
    $email_body .= "Login at: " . wp_login_url() . "\n\n";
    $email_body .= "We look forward to supporting you on your mental wellness journey!\n\n";
    $email_body .= "Best regards,\nTashafe Team";
    
    wp_mail($email, $email_subject, $email_body);
    
    error_log('IPN: Sent confirmation email to: ' . $email);
    
    // ============================================
    // 7. RETURN SUCCESS
    // ============================================
    error_log('=== THERAPY IPN BOOKING PROCESSOR SUCCESS ===');
    
    return [
        'success' => true,
        'user_id' => $user_id,
        'message' => 'Therapy booking created successfully'
    ];
}
```

---

## 5. Code Changes Required

### Summary of Changes

| File | Change Type | Purpose |
|------|-------------|---------|
| `therapy_session_booking/Therapy_group_admin_dashboard.php` | **Minor** | Add `therapy_price` ACF field |
| `therapy_session_booking/User_Registration.php` | **Major** | Replace direct registration with payment flow |
| `therapy_session_booking/therapy_paytabs_integration.php` | **NEW FILE** | All payment AJAX handlers |
| `retreat/retreat_paytabs_integration.php` | **Minor** | Add therapy IPN routing |
| `therapy_session_booking/functions.php` | **Minor** | Include new payment file |

### Detailed Changes

#### 5.1 Add ACF Field for Therapy Price

**File:** `therapy_session_booking/Therapy_group_admin_dashboard.php`

**Location:** In `handle_create_sub_group()` function

```php
// BEFORE:
$_POST['max_members']

// AFTER: Add price parameter
$therapy_price = floatval($_POST['therapy_price'] ?? 500);
update_field('therapy_price', $therapy_price, $post_id);
```

**Location:** In admin modal HTML

```html
<!-- Add after max_members field -->
<div class="mb-3">
    <label for="therapy_price" class="form-label">Therapy Price (SAR)</label>
    <input type="number" 
           class="form-control" 
           id="therapy_price" 
           name="therapy_price" 
           value="500" 
           step="0.01" 
           min="0" 
           required>
    <small class="text-muted">Price for the entire therapy session period</small>
</div>
```

#### 5.2 Modify Registration Form

**File:** `therapy_session_booking/User_Registration.php`

**Current Behavior (Line ~950):**
```php
function ajax_complete_retreat_registration() {
    // Creates user immediately
    $user_id = wp_create_user($email, $password, $email);
    // ...
}
```

**NEW Behavior:**
```php
// Remove direct user creation from AJAX handler
// Replace with save_therapy_booking_data + initiate_therapy_payment
// User creation moved to IPN processor
```

**Frontend JavaScript Changes:**
```javascript
// BEFORE:
form.addEventListener('submit', function(e) {
    // Direct AJAX to create user
});

// AFTER:
form.addEventListener('submit', function(e) {
    // Step 1: Save to transient
    // Step 2: Get PayTabs redirect
    // Step 3: Redirect to PayTabs HPP
});
```

#### 5.3 Create Payment Integration File

**NEW FILE:** `therapy_session_booking/therapy_paytabs_integration.php`

**Contents:**
- `ajax_save_therapy_booking_data()`
- `ajax_initiate_therapy_payment()`
- `ajax_verify_therapy_payment_status()`
- `ajax_complete_therapy_registration()` (fallback handler)
- `process_therapy_booking_from_ipn()`
- `get_therapy_payment_return_url()`

**Include in main functions file:**
```php
// therapy_session_booking/functions.php
require_once __DIR__ . '/therapy_paytabs_integration.php';
```

#### 5.4 Enhance IPN Router

**File:** `retreat/retreat_paytabs_integration.php`

**Function:** `paytabs_handle_callback()`

**Change:** Add routing logic for therapy bookings

```php
// Line ~270
function paytabs_handle_callback() {
    // ... existing code ...
    
    // NEW: Route based on cart_id prefix
    if (strpos($cart_id, 'retreat_') === 0) {
        handle_retreat_ipn_callback($data, $cart_id);
    } elseif (strpos($cart_id, 'therapy_') === 0) {
        handle_therapy_ipn_callback($data, $cart_id);
    }
}
```

---

## 6. Data Flow

### 6.1 Happy Path Sequence Diagram

```
User                Frontend              Backend              PayTabs              IPN Handler
 │                     │                     │                     │                     │
 │  Fill Form          │                     │                     │                     │
 │───────────────────>│                     │                     │                     │
 │                     │                     │                     │                     │
 │  Click "Register"   │                     │                     │                     │
 │───────────────────>│                     │                     │                     │
 │                     │                     │                     │                     │
 │                     │  AJAX: save_        │                     │                     │
 │                     │  booking_data       │                     │                     │
 │                     │────────────────────>│                     │                     │
 │                     │                     │                     │                     │
 │                     │  Store transient    │                     │                     │
 │                     │  Return token       │                     │                     │
 │                     │<────────────────────│                     │                     │
 │                     │                     │                     │                     │
 │                     │  AJAX: initiate_    │                     │                     │
 │                     │  payment            │                     │                     │
 │                     │────────────────────>│                     │                     │
 │                     │                     │                     │                     │
 │                     │                     │  POST /payment/     │                     │
 │                     │                     │  request            │                     │
 │                     │                     │────────────────────>│                     │
 │                     │                     │                     │                     │
 │                     │                     │  redirect_url       │                     │
 │                     │                     │<────────────────────│                     │
 │                     │                     │                     │                     │
 │                     │  Return redirect    │                     │                     │
 │                     │<────────────────────│                     │                     │
 │                     │                     │                     │                     │
 │  Redirect to HPP    │                     │                     │                     │
 │─────────────────────────────────────────────────────────────>│                     │
 │                     │                     │                     │                     │
 │  User Pays          │                     │                     │                     │
 │<─────────────────────────────────────────────────────────────>│                     │
 │                     │                     │                     │                     │
 │                     │                     │                     │  POST /payment-     │
 │                     │                     │                     │  callback/          │
 │                     │                     │                     │────────────────────>│
 │                     │                     │                     │                     │
 │                     │                     │                     │  Create User        │
 │                     │                     │                     │  Assign Group       │
 │                     │                     │                     │  Enroll BP          │
 │                     │                     │                     │  Send Email         │
 │                     │                     │                     │                     │
 │                     │                     │                     │  200 OK             │
 │                     │                     │                     │<────────────────────│
 │                     │                     │                     │                     │
 │  Redirect to        │                     │                     │                     │
 │  return URL         │                     │                     │                     │
 │<─────────────────────────────────────────────────────────────│                     │
 │                     │                     │                     │                     │
 │                     │  AJAX: verify_      │                     │                     │
 │                     │  payment_status     │                     │                     │
 │                     │────────────────────>│                     │                     │
 │                     │                     │                     │                     │
 │                     │  Check transient    │                     │                     │
 │                     │  user_id exists?    │                     │                     │
 │                     │  YES → Auto-login   │                     │                     │
 │                     │                     │                     │                     │
 │                     │  Success response   │                     │                     │
 │                     │<────────────────────│                     │                     │
 │                     │                     │                     │                     │
 │  Show Success       │                     │                     │                     │
 │  Redirect to        │                     │                     │                     │
 │  Thank You Page     │                     │                     │                     │
 │<────────────────────│                     │                     │                     │
```

### 6.2 Edge Case: IPN Delayed

```
Timeline: User pays and returns before IPN arrives

 0ms: User clicks Pay on PayTabs
 500ms: Payment approved by PayTabs
 550ms: User redirected back to site
 600ms: Frontend calls verify_payment_status
       → Transient shows payment_status: 'pending'
       → user_id: null
       → Frontend shows "Processing..." spinner
 
 2000ms: PayTabs IPN arrives
        → process_therapy_booking_from_ipn() runs
        → User created, enrolled, email sent
        → Transient updated: user_id set
 
 2100ms: Frontend polls again (or user refreshes)
         → verify_payment_status called again
         → Now finds user_id in transient
         → Auto-logins user
         → Redirects to thank-you page
```

**Mitigation:** Frontend implements polling:
```javascript
function pollPaymentStatus(token, attempts = 0) {
    if (attempts > 10) {
        showErrorMessage('Verification timed out. Check your email for confirmation.');
        return;
    }
    
    $.post(THERAPY_REG_AJAX.url, {
        action: 'verify_therapy_payment_status',
        token: token
    }, function(response) {
        if (response.data.user_id) {
            // Success!
            showSuccessAndRedirect();
        } else {
            // Try again in 2 seconds
            setTimeout(() => pollPaymentStatus(token, attempts + 1), 2000);
        }
    });
}
```

---

## 7. Security Considerations

### 7.1 Nonce Verification

**All AJAX endpoints MUST verify nonce:**
```php
function ajax_save_therapy_booking_data() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'therapy_registration_nonce')) {
        wp_send_json_error(['message' => 'Security verification failed']);
        return;
    }
    // ...
}
```

### 7.2 Input Sanitization

**All user inputs MUST be sanitized:**
```php
$personal_info = [
    'first_name' => sanitize_text_field($_POST['first_name']),
    'last_name' => sanitize_text_field($_POST['last_name']),
    'email' => sanitize_email($_POST['email']),
    'phone' => sanitize_text_field($_POST['phone']),
    'passport_no' => sanitize_text_field($_POST['passport_no']),
    'country' => sanitize_text_field($_POST['country']),
    'dob' => sanitize_text_field($_POST['birth_date']),
    'password' => $_POST['password'], // Don't sanitize password
];
```

### 7.3 Amount Verification

**CRITICAL: Never trust frontend amount**

```php
// BAD - Frontend sends amount
$amount = floatval($_POST['amount']); // ❌ User can manipulate

// GOOD - Backend calculates amount
$group_id = intval($_POST['group_id']);
$amount = get_field('therapy_price', $group_id); // ✅ Server-side source of truth

if (empty($amount) || $amount <= 0) {
    $amount = 500; // Default fallback
}
```

### 7.4 Booking Token Security

**Token MUST be cryptographically random:**
```php
// GOOD
$booking_token = bin2hex(random_bytes(16)); // 32 character hex string

// BAD
$booking_token = uniqid(); // ❌ Predictable
```

### 7.5 Transient Expiration

**Security through expiration:**
- Pre-payment: 1 hour (limits token reuse window)
- Post-payment: 24 hours (allows recovery)
- After 24h: Automatic cleanup

### 7.6 IPN Signature Verification (Future Enhancement)

**TODO:** Verify PayTabs signature if available
```php
function verify_paytabs_signature($callback_data, $server_key) {
    // Check PayTabs documentation for signature verification method
    // This prevents fake IPN requests
}
```

---

## 8. Testing Plan

### 8.1 Test Environments

**Required:**
1. **Staging Site** - WordPress clone with test PayTabs account
2. **Test PayTabs Credentials** - Use test mode
3. **Test Credit Card** - 4111 1111 1111 1111

### 8.2 Test Scenarios

#### Scenario 1: Happy Path

**Steps:**
1. Navigate to therapy groups page
2. Select anxiety male group
3. Click "Register"
4. Fill personal information form completely
5. Submit form
6. Verify redirect to PayTabs HPP
7. Complete payment with test card
8. Verify redirect back to therapy page
9. Check success message appears
10. Verify auto-login successful
11. Check redirect to thank-you page

**Expected Results:**
- ✅ User created in WordPress
- ✅ assigned_group meta set
- ✅ concern_type & gender set correctly
- ✅ BuddyPress group membership confirmed
- ✅ Confirmation email received
- ✅ Payment transaction logged

#### Scenario 2: Browser Closed After Payment

**Steps:**
1. Complete payment on PayTabs
2. ❌ Close browser before redirect
3. Wait 5 minutes
4. Check WordPress admin

**Expected Results:**
- ✅ User account exists (created by IPN)
- ✅ assigned_group meta set
- ✅ BuddyPress enrollment completed
- ✅ Email sent

#### Scenario 3: Payment Declined

**Steps:**
1. Fill registration form
2. Submit to PayTabs
3. Use test card that triggers decline
4. Verify redirect back with error

**Expected Results:**
- ❌ No user created
- ❌ No group assignment
- ✅ Transient marked as 'failed'
- ✅ Error message shown to user

#### Scenario 4: Duplicate IPN

**Steps:**
1. Complete payment successfully
2. Manually trigger IPN callback again (via API testing tool)

**Expected Results:**
- ✅ Idempotency check passes
- ✅ No duplicate user created
- ✅ Second IPN returns success without changes

#### Scenario 5: IPN Delayed/Lost

**Steps:**
1. Complete payment
2. Block IPN callback (via firewall)
3. User returns to site

**Expected Results:**
- ⏳ Frontend shows "Processing..." spinner
- ⏳ Payment verification API call shows payment_status: 'pending'
- ✅ Fallback mechanism triggers user creation
- ✅ User successfully registered

### 8.3 Automated Testing (Optional)

**PHPUnit Tests:**
```php
class TherapyPayTabsTest extends WP_UnitTestCase {
    public function test_save_booking_data_creates_transient() {
        // Test transient creation
    }
    
    public function test_ipn_processor_creates_user() {
        // Test IPN processing
    }
    
    public function test_idempotency_check() {
        // Test duplicate IPN handling
    }
}
```

---

## 9. Rollout Plan

### 9.1 Pre-Deployment Checklist

- [ ] Full database backup created
- [ ] All therapy PHP files backed up
- [ ] Testing completed on staging
- [ ] PayTabs test mode verified working
- [ ] Admin price field added to therapy groups
- [ ] Existing groups have price set (migration)
- [ ] IPN callback URL accessible: `https://site.com/payment-callback/`
- [ ] Return URL accessible: `https://site.com/therapy-groups/`

### 9.2 Deployment Steps

**Step 1: ACF Field Deployment**
```php
// Add therapy_price field to all existing therapy groups
$groups = get_posts([
    'post_type' => 'therapy_group',
    'posts_per_page' => -1
]);

foreach ($groups as $group) {
    if (!get_field('therapy_price', $group->ID)) {
        update_field('therapy_price', 500, $group->ID); // Default price
    }
}
```

**Step 2: File Upload**
1. Upload `therapy_session_booking/therapy_paytabs_integration.php`
2. Upload modified `User_Registration.php`
3. Upload modified `Therapy_group_admin_dashboard.php`
4. Upload modified `retreat/retreat_paytabs_integration.php`

**Step 3: Test Mode Verification**
1. Set PayTabs to test mode in admin
2. Perform complete test booking
3. Verify user creation
4. Check email delivery
5. Confirm BP enrollment

**Step 4: Go Live**
1. Switch PayTabs to live mode
2. Announce to users
3. Monitor first 10 bookings closely
4. Watch error logs for issues

### 9.3 Monitoring

**First 48 Hours:**
- Check `wp_paytabs_logs` table every 2 hours
- Monitor `wp_therapy_payment_log` (if created)
- Watch for admin error emails
- Verify no duplicate users created
- Check BP group membership counts

**Weekly Audit:**
```sql
-- Find paid users with no therapy assignment
SELECT u.ID, u.user_email, um.meta_value AS tran_id
FROM wp_users u
INNER JOIN wp_usermeta um ON u.ID = um.user_id AND um.meta_key = 'payment_transaction_id'
LEFT JOIN wp_usermeta um2 ON u.ID = um2.user_id AND um2.meta_key = 'assigned_group'
WHERE um2.meta_value IS NULL
  AND um.meta_value LIKE 'TST%' OR um.meta_value LIKE 'TRN%';
```

**Expected Result:** 0 rows

### 9.4 Rollback Plan

**If Critical Issues Occur:**

1. **Immediate Actions:**
   ```bash
   # Restore backup files
   cp therapy_paytabs_integration.php.backup therapy_paytabs_integration.php
   cp User_Registration.php.backup User_Registration.php
   ```

2. **Switch to Manual Registration:**
   - Remove payment requirement temporarily
   - Allow users to register without payment
   - Contact customers who paid to manually assign them

3. **Data Recovery:**
   - Query `wp_paytabs_logs` for successful payments
   - Manually create user accounts
   - Manually assign to therapy groups
   - Send apology email with login credentials

---

## 10. Approval Checklist

### Technical Review

- [ ] **Architecture** is sound and follows payment-first principle
- [ ] **Reuses existing PayTabs infrastructure** appropriately
- [ ] **IPN-driven booking** ensures reliability
- [ ] **Idempotency** handled correctly
- [ ] **Security** considerations adequate (nonce, sanitization, amount verification)
- [ ] **Error handling** comprehensive
- [ ] **Fallback mechanisms** in place for edge cases
- [ ] **Data persistence** strategy (transients) appropriate
- [ ] **BuddyPress enrollment** properly integrated
- [ ] **Email notifications** implemented

### Business Review

- [ ] **Pricing strategy** clear (therapy_price ACF field)
- [ ] **User experience** matches retreat journey
- [ ] **No revenue loss** risk (payment before booking)
- [ ] **Customer support** scenarios documented
- [ ] **Testing plan** comprehensive
- [ ] **Rollout plan** safe and reversible

### Implementation Readiness

- [ ] **Database changes** documented (ACF field)
- [ ] **File changes** list complete
- [ ] **Deployment steps** clear
- [ ] **Rollback plan** prepared
- [ ] **Monitoring strategy** defined
- [ ] **Test environment** available

---

## 11. Next Steps

**PENDING APPROVAL:**

This plan is ready for review. **DO NOT PROCEED** with implementation until:

1. ✅ Technical lead approves architecture
2. ✅ Business owner approves pricing strategy
3. ✅ QA confirms testing plan is adequate
4. ✅ DevOps confirms staging environment ready

**After Approval:**

1. Create `therapy_paytabs_integration.php` file
2. Modify registration form frontend
3. Enhance IPN callback router
4. Add ACF field for therapy_price
5. Test on staging
6. Deploy to production
7. Monitor first 24 hours closely

---

**END OF PLAN**

**Document Version:** 1.0  
**Last Updated:** January 29, 2026  
**Status:** ⚠️ AWAITING APPROVAL
