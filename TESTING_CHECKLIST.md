# APS Payment Integration - Testing Checklist

## Pre-Testing Setup

### 1. Configuration Requirements
- [ ] APS sandbox credentials configured in admin panel
- [ ] Database tables created (`wp_tanafs_payments`, `wp_tanafs_payment_logs`)
- [ ] Code Snippets plugin active with all snippets enabled
- [ ] Test cards documented (APS sandbox test cards)

### 2. Environment Verification
- [ ] WordPress error logging enabled (`WP_DEBUG_LOG = true`)
- [ ] Monitor error log: `wp-content/debug.log`
- [ ] Browser dev tools console enabled for AJAX debugging

---

## Module 1: Therapy Booking (New Users)

### Test Case 1.1: Full Registration Flow
**Scenario**: New user completes assessment, selects group, makes payment

**Steps**:
1. Navigate to assessment page with `?issue=anxiety&gender=female`
2. Complete assessment form
3. Select therapy group on registration page
4. Fill in personal information (first/last name, email, phone, country, passport, birthdate)
5. Click "Proceed to Payment"
6. Verify redirect to APS payment page (POST form auto-submit)
7. Use APS test card: `4111 1111 1111 1111` / Expiry: any future date / CVV: `123`
8. Complete payment on APS sandbox

**Expected Results**:
- [ ] AJAX action `tanafs_initiate_therapy_payment` succeeds
- [ ] Redirect URL and params returned in JSON response
- [ ] POST form auto-submits to APS
- [ ] Payment record created in `wp_tanafs_payments` with status `pending`
- [ ] Transient booking data stored for 4 hours
- [ ] IPN callback processes successfully
- [ ] User account created with correct metadata
- [ ] User assigned to correct therapy group (`assigned_group` meta)
- [ ] User enrolled in BuddyPress chat group
- [ ] Confirmation email sent
- [ ] Payment status updated to `complete` in database

**Verification Queries**:
```sql
SELECT * FROM wp_tanafs_payments WHERE booking_type = 'therapy' ORDER BY created_at DESC LIMIT 1;
SELECT * FROM wp_users WHERE user_email = '{test_email}';
SELECT * FROM wp_usermeta WHERE user_id = {user_id} AND meta_key IN ('assigned_group', 'payment_transaction_id', 'account_status');
```

---

### Test Case 1.2: Logged-In User Payment
**Scenario**: Existing user selects new therapy group and pays

**Steps**:
1. Login as existing WordPress user
2. Navigate to therapy group selection page
3. Select therapy group via `?selected_group_id={group_id}`
4. Click payment button
5. Complete APS payment flow

**Expected Results**:
- [ ] AJAX action `tanafs_initiate_therapy_payment_logged_in` succeeds
- [ ] Booking token stored with user ID reference
- [ ] Payment processes correctly
- [ ] User assigned to new group (allows multiple group registrations)
- [ ] BuddyPress enrollment succeeds
- [ ] No duplicate user account created

---

## Module 2: Retreat Booking

### Test Case 2.1: Male Retreat Registration
**Scenario**: New user registers for male retreat

**Steps**:
1. Navigate to retreat page
2. Click "Book Male Retreat"
3. Select retreat schedule/group
4. Fill registration form (full name, email, phone, country, gender, birthdate)
5. Upload passport scan (if required)
6. Proceed to payment
7. Complete APS payment

**Expected Results**:
- [ ] AJAX action `tanafs_initiate_retreat_payment` succeeds
- [ ] Booking token format: `retreat_{unique_id}`
- [ ] Transient key: `retreat_retreat_{unique_id}`
- [ ] Payment record created with `booking_type = 'retreat'`
- [ ] IPN creates user account
- [ ] User metadata includes: `assigned_retreat_group`, `retreat_type`, `passport_scan`
- [ ] BuddyPress chat enrollment with formatted nickname
- [ ] Confirmation email sent with retreat details

**Verification**:
```sql
SELECT * FROM wp_tanafs_payments WHERE booking_type = 'retreat' ORDER BY created_at DESC LIMIT 1;
SELECT meta_value FROM wp_usermeta WHERE user_id = {user_id} AND meta_key = 'assigned_retreat_group';
```

---

### Test Case 2.2: Female Retreat Registration
**Scenario**: Same as Test Case 2.1 but for female retreat

**Expected Results**: Same as above with `retreat_type = 'female'`

---

## Module 3: Academy Registration

### Test Case 3.1: Professional Training Program
**Scenario**: User registers for academy program

**Steps**:
1. Navigate to academy page
2. Select training program
3. Fill form: full name, email, phone, job title, license number, country
4. Click "Register & Pay"
5. Complete APS payment

**Expected Results**:
- [ ] AJAX action `tanafs_initiate_academy_payment` succeeds
- [ ] Booking token format: `academy_{unique_id}`
- [ ] Payment record created with `booking_type = 'academy'`
- [ ] IPN inserts record into `wp_academy_registrations` table
- [ ] Idempotency check prevents duplicate registrations
- [ ] Confirmation email sent via `academy_send_registration_confirmation()`
- [ ] `registration_status = 'registered'`

**Verification**:
```sql
SELECT * FROM wp_academy_registrations WHERE email = '{test_email}' ORDER BY id DESC LIMIT 1;
SELECT * FROM wp_tanafs_payments WHERE booking_type = 'academy' ORDER BY created_at DESC LIMIT 1;
```

---

## Security & Edge Cases

### Test Case 4.1: Signature Verification
**Steps**:
1. Capture legitimate IPN POST data from APS
2. Modify `signature` field in callback
3. Send modified request to IPN endpoint

**Expected Results**:
- [ ] IPN handler rejects request with HTTP 403
- [ ] Error logged: "Signature verification failed"
- [ ] Payment status NOT updated

---

### Test Case 4.2: Duplicate IPN Prevention (Idempotency)
**Steps**:
1. Complete payment successfully
2. Manually trigger same IPN callback again (same `merchant_reference`)

**Expected Results**:
- [ ] Second IPN responds "OK" but does not process
- [ ] Payment status remains `complete` (not changed)
- [ ] No duplicate user account created
- [ ] Log: "Payment already processed (idempotent)"

---

### Test Case 4.3: Failed Payment Handling
**Steps**:
1. Initiate payment
2. Use declined test card on APS sandbox
3. Return to site

**Expected Results**:
- [ ] IPN receives `response_code != 14000`
- [ ] Payment status updated to `failed`
- [ ] User account NOT created
- [ ] No group assignment
- [ ] Admin notification NOT sent (only sent on fulfillment failure)

---

### Test Case 4.4: Expired Booking Session
**Steps**:
1. Initiate payment
2. Wait 4+ hours (or manually delete transient)
3. Complete payment (IPN fires)

**Expected Results**:
- [ ] IPN cannot retrieve booking data from transient
- [ ] Error logged: "Booking data not found in transient storage"
- [ ] Payment marked complete but fulfillment fails
- [ ] Admin urgent email sent with manual processing instructions

---

### Test Case 4.5: AJAX Security (Nonce Validation)
**Steps**:
1. Open browser console
2. Manually trigger AJAX payment initiation without nonce

**Expected Results**:
- [ ] AJAX request denied with: "Security verification failed"
- [ ] HTTP 400 or 403 response

---

### Test Case 4.6: Missing Required Fields
**Steps**:
1. Submit therapy registration without email field
2. Submit retreat registration without group_id

**Expected Results**:
- [ ] AJAX handler validates required fields
- [ ] Error returned: "Please fill in all required fields"
- [ ] No payment initiated

---

## Database Integrity

### Test Case 5.1: Payment Records Schema
**Verification**:
```sql
DESCRIBE wp_tanafs_payments;
```

**Required Columns**:
- [ ] `id` (primary key)
- [ ] `booking_token` (unique)
- [ ] `transaction_id` (unique, APS merchant_reference)
- [ ] `booking_type` (therapy, retreat, academy)
- [ ] `payment_status` (pending, complete, failed)
- [ ] `amount`, `currency`
- [ ] `customer_name`, `customer_email`, `customer_phone`
- [ ] `aps_response_code`, `aps_response_message`
- [ ] `response_data` (JSON)
- [ ] `created_at`, `updated_at`

---

### Test Case 5.2: Payment Logs
**Verification**:
```sql
SELECT * FROM wp_tanafs_payment_logs ORDER BY created_at DESC LIMIT 10;
```

**Expected Entries**:
- [ ] `payment_initiated` events
- [ ] `callback_received` events
- [ ] `callback_processed` events with status
- [ ] JSON data includes full APS response

---

## Admin Interface

### Test Case 6.1: Payment Management Page
**Steps**:
1. Login as admin
2. Navigate to "Payment Integration" → "All Payments"

**Expected Features**:
- [ ] Table displays all payments with columns: ID, Transaction ID, Booking Type, Customer, Amount, Status, Date
- [ ] Filter by booking type (therapy/retreat/academy)
- [ ] Filter by payment status (pending/complete/failed)
- [ ] Search by customer name/email/transaction ID
- [ ] View full details link for each payment

---

### Test Case 6.2: Settings Page
**Steps**:
1. Navigate to "Payment Integration" → "Settings"

**Expected Features**:
- [ ] Form fields for: Merchant Identifier, Access Code, SHA Request/Response Phrases
- [ ] Mode selector (sandbox/live)
- [ ] Currency dropdown (SAR, AED, USD, EUR, EGP)
- [ ] Language selector (en/ar)
- [ ] Save button with admin capability check
- [ ] Success message on save

---

## Logging & Debugging

### Test Case 7.1: Error Log Monitoring
**Check Points**:
- [ ] Payment initiation logs include booking_token and amount
- [ ] IPN logs include full callback data
- [ ] Fulfillment logs show user creation steps
- [ ] BuddyPress enrollment logs indicate success/failure
- [ ] Signature verification failures logged with calculated vs received signatures

**Sample Log Entries**:
```
=== TANAFS APS IPN CALLBACK RECEIVED ===
[Therapy IPN] Created new user ID: 123
[Therapy IPN] Direct assignment: user 123 to group 456
[Therapy IPN] ✓ Enrolled user in BuddyPress chat group
```

---

## Cross-Module Consistency

### Test Case 8.1: All Modules Use Unified System
**Verification**:
- [ ] All AJAX actions prefixed with `tanafs_`
- [ ] All redirect using `tanafsRedirectToAPS()` POST form method
- [ ] All use `wp_tanafs_payments` table (not separate tables)
- [ ] All currency options read from `tanafs_aps_currency`
- [ ] All IPN handlers route through `tanafs_handle_payment_callback()`

---

## Performance & Optimization

### Test Case 9.1: Database Query Efficiency
**Check**:
- [ ] All DB queries use `$wpdb->prepare()` for security
- [ ] Idempotency checks use indexed columns (`transaction_id`)
- [ ] No N+1 query issues in admin list pages

---

### Test Case 9.2: Transient Expiry
**Verification**:
- [ ] Therapy transients expire after 4 hours
- [ ] Retreat transients expire after 4 hours
- [ ] Academy transients expire after 4 hours
- [ ] Expired transients cleaned up automatically by WordPress cron

---

## Migration Verification

### Test Case 10.1: Old PayTabs Code Compatibility
**Check**:
- [ ] Old `therapy_paytabs_integration.php` can remain active (no conflicts)
- [ ] Old `retreat_paytabs_integration.php` can remain active (no conflicts)
- [ ] Old admin pages not conflicting with new "Payment Integration" menu
- [ ] Old transient keys still accessible by new system

---

## Final Smoke Test

### End-to-End Flow (All Modules)
1. **Therapy**: Complete 1 registration from assessment to payment confirmation
2. **Retreat**: Complete 1 male and 1 female retreat booking
3. **Academy**: Register for 1 training program

**Success Criteria**:
- [ ] All 4 bookings complete without errors
- [ ] 4 payment records in database with status `complete`
- [ ] 3 new user accounts created (therapy + 2 retreats)
- [ ] 1 academy registration record created
- [ ] All confirmation emails received
- [ ] BuddyPress groups populated correctly
- [ ] No PHP errors in debug.log
- [ ] No JavaScript console errors

---

## Post-Launch Monitoring

### Week 1 Checklist
- [ ] Monitor `wp_tanafs_payment_logs` for unusual patterns
- [ ] Check for admin urgent emails (fulfillment failures)
- [ ] Verify all live payments have `response_code = 14000`
- [ ] Review signature verification failures (should be 0)
- [ ] Confirm transient cleanup running (no stale data)

---

## Rollback Plan

If critical issues detected:
1. Disable payment_integration.php snippet
2. Re-enable old PayTabs snippets
3. Document failures in GitHub issue
4. Review debug.log for root cause
5. Fix in staging environment
6. Re-test full checklist before re-deploying

---

## Test Cards (APS Sandbox)

| Card Number         | Brand      | Result  |
|---------------------|------------|---------|
| 4111 1111 1111 1111 | Visa       | Success |
| 5123 4567 8901 2346 | Mastercard | Success |
| 4000 0000 0000 0002 | Visa       | Decline |

**Test Details**:
- Expiry: Any future date (e.g., 12/25)
- CVV: Any 3 digits (e.g., 123)
- 3DS: Skip or use test code if prompted

---

## Notes

- Always test in APS **sandbox mode** before switching to live
- Keep `WP_DEBUG_LOG` enabled during first week of live deployment
- All times are stored in WordPress timezone (`current_time('mysql')`)
- Signature verification uses HMAC-SHA256 with APS SHA phrases
- Module fulfillment functions are in respective module files, not payment_integration.php

---

**Last Updated**: 2025-01-05  
**Version**: 1.0 (APS Migration)
