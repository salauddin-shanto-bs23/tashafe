# Payment Return Nonce Verification Fix
**Issue:** "Security verification failed" error when user returns from PayTabs  
**Date:** January 29, 2026  
**Status:** ✅ Fixed

---

## Problem Description

Users were encountering "Security verification failed" error when returning from PayTabs payment gateway, even though payment was successful.

**Error Screenshot Context:**
- URL: `https://tanafs.com.sa/en/retreat/?payment_return=5c$15101956860c383b78d7543fec18a`
- Modal: "Verifying Your Payment... Please wait while we confirm your payment."
- Alert: "Payment Error: Security verification failed. Please contact support if you were charged."

---

## Root Cause Analysis

### The Issue
WordPress **nonce invalidation due to user context switch** during payment flow.

### Detailed Flow

1. **Page Load (Anonymous User)**
   ```javascript
   // retreat_system.php - Line 498
   'nonce' => wp_create_nonce('retreat_nonce')  // Created for user_id=0
   ```
   - User visits retreat page as **anonymous visitor**
   - JavaScript `RETREAT_AJAX.nonce` generated for `user_id=0`
   - Nonce embedded in page HTML/JavaScript

2. **Payment Initiation**
   - User clicks "Book Now"
   - Transient created with booking data
   - User redirected to PayTabs payment page

3. **Payment Processing (5-10 minutes)**
   - User fills payment form on PayTabs
   - Payment completed successfully
   - PayTabs sends IPN to: `yoursite.com/wp-admin/admin-ajax.php?action=paytabs_callback`

4. **IPN Processing (Background)**
   ```php
   // retreat_paytabs_integration.php - Line 301-408
   paytabs_handle_callback() {
       // Creates user account
       // Auto-logs in user: wp_set_auth_cookie()
       // Sets login cookies in browser
   }
   ```
   - User account created
   - User automatically logged in (cookies set)
   - **User context changed:** `user_id=0` → `user_id=123`

5. **Payment Return Redirect**
   - PayTabs redirects user back: `yoursite.com/en/retreat/?payment_return=TOKEN`
   - Browser still has **OLD page** loaded as anonymous user
   - Browser **also has NEW cookies** from auto-login

6. **Payment Verification AJAX Call (BUG POINT)**
   ```javascript
   // retreat_system.php - Line 2384
   $.post(RETREAT_AJAX.url, {
       action: 'verify_retreat_payment_status',
       token: paymentReturnToken,
       nonce: RETREAT_AJAX.nonce  // ❌ OLD nonce for user_id=0
   })
   ```
   - JavaScript sends OLD nonce (created for anonymous user)
   - But browser sends NEW cookies (logged-in user)
   - **Context mismatch!**

7. **Server-Side Verification Fails**
   ```php
   // retreat_paytabs_integration.php - Line 840 (OLD CODE)
   wp_verify_nonce($_POST['nonce'], 'retreat_nonce')
   // ❌ Fails because:
   // - Nonce was created for user_id=0
   // - Server sees user_id=123 (from cookies)
   // - WordPress nonce verification checks user context
   // - Mismatch detected → verification fails
   ```

---

## Why Nonces Fail on User Context Switch

WordPress nonces are **user-specific** to prevent CSRF attacks:

```php
// WordPress Core: wp-includes/pluggable.php
function wp_create_nonce($action) {
    $user_id = get_current_user_id();
    // Nonce includes user_id in hash calculation
    $hash = hash_hmac('md5', $action . '|' . $user_id . '|' . $token, $key);
    return substr($hash, -12, 10);
}

function wp_verify_nonce($nonce, $action) {
    $user_id = get_current_user_id();  // Gets CURRENT user
    // Verifies nonce was created for THIS user
    $expected = wp_create_nonce($action);
    return hash_equals($expected, $nonce);
}
```

**Result:**
- Nonce created for `user_id=0` (anonymous)
- Verified against `user_id=123` (logged-in)
- Hash mismatch → verification fails

---

## Solution Implemented

### Enhanced Security Verification with Fallback

**File:** [retreat/retreat_paytabs_integration.php](retreat/retreat_paytabs_integration.php#L838-L883)

```php
function ajax_verify_retreat_payment_status() {
    $booking_token = sanitize_text_field($_POST['token'] ?? '');
    
    // Retrieve booking data FIRST
    $transient_key = 'retreat_' . $booking_token;
    $booking_data = get_transient($transient_key);
    
    // ★★★ PRIMARY: Normal nonce verification ★★★
    $nonce_valid = isset($_POST['nonce']) && wp_verify_nonce($_POST['nonce'], 'retreat_nonce');
    
    if (!$nonce_valid) {
        // ★★★ FALLBACK: Token-based verification ★★★
        $fallback_valid = false;
        
        if ($booking_data && is_user_logged_in()) {
            $current_user_id = get_current_user_id();
            $booking_user_id = $booking_data['user_id'] ?? 0;
            
            // Allow if:
            // 1. User is logged in
            // 2. Booking has been created by IPN (has user_id)
            // 3. Current logged-in user matches booking user
            if ($current_user_id > 0 && $booking_user_id > 0 && $current_user_id === $booking_user_id) {
                $fallback_valid = true;
                error_log('VERIFY: Nonce failed but fallback passed (auto-login context switch)');
            }
        }
        
        // Reject only if BOTH verifications fail
        if (!$fallback_valid) {
            wp_send_json_error(['message' => 'Security verification failed. Please refresh the page and try again.']);
            return;
        }
    }
    
    // Continue with payment verification...
}
```

### Security Logic

**Two-Layer Verification:**

1. **Layer 1 - Standard Nonce (Preferred)**
   - Works for normal scenarios
   - CSRF protection via WordPress nonces
   - No changes to existing security model

2. **Layer 2 - Token-Based Fallback (Payment Return Only)**
   - Activates only when nonce fails
   - Verifies:
     - ✅ Valid booking token exists in transient
     - ✅ User is logged in
     - ✅ Logged-in user matches IPN-created user
   - Prevents unauthorized access while handling legitimate auto-login case

**Why This Is Secure:**

- Booking token is cryptographically random (`bin2hex(random_bytes(16))`)
- Token stored server-side only (transient with 24-hour TTL)
- User must be logged in to pass fallback
- User ID must match booking (can't hijack someone else's payment)
- Only works for `verify_retreat_payment_status` action (narrow scope)

---

## Frontend Improvements

**File:** [retreat/retreat_system.php](retreat/retreat_system.php#L2453-L2477)

### Better Error Handling

```javascript
if (!response.success) {
    const errorMsg = response.data?.message || 'Payment verification failed';
    
    // Detect security/session errors
    if (errorMsg.toLowerCase().includes('security') || 
        errorMsg.toLowerCase().includes('verification failed')) {
        
        // Offer page refresh
        if (confirm('Payment verification session issue detected.\n\n' +
                    'Your payment may have been processed. Click OK to refresh.\n\n' +
                    'If issue persists, contact support.')) {
            window.location.reload();
        }
    } else {
        alert('Payment Error: ' + errorMsg + '\n\nContact support if charged.');
    }
}
```

### AJAX Failure Handling

```javascript
.fail(function(xhr, status, error) {
    $('#payment-verification-overlay').remove();
    
    // Parse error message
    let errorMessage = 'Unable to verify payment status.';
    try {
        const responseData = JSON.parse(xhr.responseText);
        if (responseData.data?.message) {
            errorMessage = responseData.data.message;
        }
    } catch (e) {}
    
    // Offer refresh for session issues
    if (confirm(errorMessage + '\n\nRefresh page and try again?\n\n' +
                'Click Cancel to contact support.')) {
        window.location.reload();
    }
})
```

**Benefits:**
- Offers self-service recovery (page refresh)
- Parses and displays actual error messages
- Reduces support burden
- Better user experience

---

## Testing Scenarios

### ✅ Scenario 1: Normal Flow (Anonymous User)
1. Anonymous user visits page
2. Completes payment
3. Returns from PayTabs
4. **Result:** Nonce valid → Primary verification passes ✓

### ✅ Scenario 2: Auto-Login Context Switch (Bug Case)
1. Anonymous user loads page
2. Goes to PayTabs (5-10 min)
3. IPN fires → user created and logged in
4. Returns with payment_return token
5. Old nonce fails (context mismatch)
6. **Result:** Fallback verification passes (user matches booking) ✓

### ✅ Scenario 3: Registered User (Already Logged In)
1. Logged-in user visits page
2. Completes payment
3. Returns from PayTabs
4. **Result:** Nonce valid → Primary verification passes ✓

### ✅ Scenario 4: Expired Session
1. User completes payment
2. Waits 24+ hours
3. Returns to page
4. **Result:** Booking transient expired → Error with clear message ✓

### ✅ Scenario 5: Malicious Request Attempt
1. Attacker tries to verify someone else's payment
2. Sends valid booking token
3. Either not logged in OR logged in as different user
4. **Result:** Both verifications fail → Rejected ✓

---

## Related Files Modified

### Backend
- [retreat/retreat_paytabs_integration.php](retreat/retreat_paytabs_integration.php#L838-L883)
  - Enhanced `ajax_verify_retreat_payment_status()` with fallback verification

### Frontend  
- [retreat/retreat_system.php](retreat/retreat_system.php#L2453-L2477)
  - Improved error handling with refresh suggestions
  - Better AJAX failure recovery

---

## Migration Notes

### No Breaking Changes
- ✅ Backward compatible
- ✅ Existing security model intact
- ✅ Only adds fallback for specific edge case
- ✅ No database changes required
- ✅ No frontend changes for users

### Monitoring Recommendations

Check error logs for these entries:
```
VERIFY: Nonce failed but fallback passed (auto-login context switch)
VERIFY ERROR: Security verification failed - nonce invalid and fallback checks failed
```

First message = fix working correctly  
Second message = potential security issue or bug

---

## Future Enhancements (Optional)

### Option 1: Include Fresh Nonce in Return URL
```php
// When creating payment page
$return_url .= '&fresh_nonce=' . wp_create_nonce('retreat_payment_return');
```
**Pros:** Cleaner, purpose-built nonce  
**Cons:** Nonce exposed in URL (less secure)

### Option 2: Server-Side Page Refresh After IPN
```php
// In IPN callback after auto-login
set_transient('retreat_user_login_' . $user_id, true, 300);

// On page load
if (get_transient('retreat_user_login_' . get_current_user_id())) {
    // Auto-refresh JavaScript variables with new nonce
}
```
**Pros:** Automatically syncs frontend state  
**Cons:** More complex, potential race conditions

### Option 3: WebSocket/SSE for Real-Time IPN Updates
**Pros:** Real-time, no polling  
**Cons:** Infrastructure overhead, unnecessary complexity

**Recommendation:** Current solution is sufficient for production use.

---

## Conclusion

✅ **Root Cause:** Nonce user context mismatch after IPN auto-login  
✅ **Fix:** Two-layer verification (nonce + fallback token-based)  
✅ **Security:** Maintained with additional safeguards  
✅ **User Experience:** Improved with self-service recovery  
✅ **Testing:** All scenarios validated  

**Action:** Deploy to production and monitor error logs.

---

## Related Documentation

- [paytabs_ipn_reliability_plan.md](paytabs_ipn_reliability_plan.md) - Original IPN implementation
- [PAYTABS_REGION_CONFIGURATION_SUMMARY.md](PAYTABS_REGION_CONFIGURATION_SUMMARY.md) - Region switching guide
- [RETREAT_CHAT_FIX_PLAN.md](RETREAT_CHAT_FIX_PLAN.md) - System overview
