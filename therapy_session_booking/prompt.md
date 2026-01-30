Goal
----

Integrate **PayTabs payment** into the **therapy group session booking flow**, ensuring that:

*   **Payment happens before** therapy session booking and BuddyPress group enrollment
    
*   **User registration, login, booking, and enrollment happen ONLY after payment is confirmed**
    
*   No race conditions, authentication issues, or partial states occur
    
*   The implementation **reuses the existing PayTabs setup from the Retreat journey**
    

Current State (Context)
-----------------------

### Therapy Session Booking

*   User submits a **therapy session registration form**, OR
    
*   Backend uses **already logged-in user data**, OR
    
*   If user is neither logged in nor registered:
    
    *   A **WordPress user is created**
        
    *   User is logged in
        
*   After this, the system:
    
    *   Books the therapy session
        
    *   Enrolls the user into the corresponding **BuddyPress group**
        

Relevant files:

*   therapy\_session\_booking/Therapy\_group\_admin\_dashboard.php
    
*   therapy\_session\_booking/User\_Registration.php
    

### Retreat PayTabs Integration (Already Implemented)

*   PayTabs HPP integration exists and works
    
*   IPN reliability planning is already done
    
*   Files to reference:
    
    *   retreat/paytabs\_admin.php
        
    *   retreat/retreat\_paytabs\_integration.php
        
    *   retreat/retreat\_group\_management.php
        
    *   retreat/retreat\_system.php
        
    *   retreat/retreat\_system\_context.md
        
    *   retreat/paytabs\_ipn\_reliability\_plan.md
        

⚠️ **Important**:Use the **same PayTabs credentials (Profile ID & Server Key)** from the Retreat system.❌ Do NOT create a new admin page or credential storage for therapy payments.

Required New Flow (High-Level)
------------------------------

### Step 1: User Initiates Booking

*   User submits therapy session registration form**OR**
    
*   Backend uses logged-in user data
    

> ⚠️ At this point:
> 
> *   **DO NOT create WordPress users**
>     
> *   **DO NOT book therapy sessions**
>     
> *   **DO NOT enroll BuddyPress groups**
>     

### Step 2: Redirect to PayTabs HPP

*   Prepare PayTabs payment request
    
*   Redirect user to **PayTabs Hosted Payment Page (HPP)**
    

### Step 3: Return from PayTabs

After payment attempt:

#### UI Flow

*   Redirect user back to the **registration page**
    
*   “You are being registered to your selected group…”
    

This mirrors the existing retreat journey UX.

### Step 4: Payment Verification (Critical)

*   Backend must confirm **payment success** using:
    
    *   PayTabs response
        
    *   AND/OR PayTabs IPN (preferred for reliability)
        

⚠️ **No backend booking or registration must occur unless payment is verified as successful**

### Step 5: Post-Payment Backend Actions

Only after successful payment confirmation:

1.  Create WordPress user (if not logged in)
    
2.  Log the user in
    
3.  Book the therapy session
    
4.  Enroll the user into the correct BuddyPress group
    
5.  Display the existing **Thank You / Success page**
    

Key Constraints & Rules
-----------------------

*   **Payment-first architecture**
    
*   No partial state allowed:
    
    *   ❌ Paid but not booked
        
    *   ❌ Booked but unpaid
        
*   Must handle:
    
    *   Browser closed after payment
        
    *   Internet loss
        
    *   PC crash
        
*   IPN/Webhook logic should be aligned with:
    
    *   paytabs\_ipn\_reliability\_plan.md
        
*   Therapy booking logic must **mirror retreat reliability guarantees**
    

Your Task (Implementation Instructions)
---------------------------------------

1.  **Analyze existing therapy booking flow**
    
    *   Understand where registration, booking, and BuddyPress enrollment occur
        
2.  **Analyze retreat PayTabs integration**
    
    *   Especially how payment verification and IPN are handled
        
3.  **Design a payment-first architecture** for therapy sessions
    
4.  **Create a clear implementation plan**
    
    *   Include state handling, payment verification, and fallback scenarios
        
5.  **Start implementation ONLY after the plan is approved**