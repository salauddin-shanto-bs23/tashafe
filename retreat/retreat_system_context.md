You are a senior WordPress + PHP engineer with strong experience in payment gateway integrations.

Context:
This is a WordPress site called “Tashafe” that has an existing retreat booking journey implemented using modal-based steps.

Current Retreat Journey Flow:
0. “Book Now” button
1. Select Your Schedule modal
2. Retreat Schedule Details modal (with “Book Your Spot” button)
3. Personal Information modal form (with “Book Your Spot” button)
4. Personal Wellbeing & Background Information modal form
5. Thank You for Sharing modal

Goal:
Inject PayTabs Hosted Payment Page (HPP) into the journey **after step 3 (Personal Information modal)** and before step 4, while keeping the rest of the journey unchanged.

Desired New Flow:
0. Book Now
1. Select Your Schedule modal
2. Retreat Schedule Details modal
3. Personal Information modal form
   → User clicks “Book Your Spot”
   → Redirect to PayTabs Hosted Payment Page (HPP)
4. PayTabs payment completion
   → Redirect back to the same retreat page
   → Replace the Personal Information modal with a payment status message
   → If payment is successful, automatically open:
5. Personal Wellbeing & Background Information modal
6. Thank You for Sharing modal

Important Constraints:
- Must use PayTabs **Hosted Payment Page (HPP)** (NOT Managed Form, NOT Own Form)
- Payment happens outside the site and returns via PayTabs return URL
- The journey must resume seamlessly after payment
- Personal information submitted before payment must NOT be lost
- Implementation must be simple, stable, and WordPress-friendly
- Client prioritizes ease of implementation over perfect UX

Your Tasks:
1. Analyze the existing implementation in:
   - `retreat/retreat_system.php`
2. Identify:
   - Where Personal Information form is submitted
   - How modals are opened/closed
   - How state is currently tracked between steps
3. Research PayTabs official documentation for:
   - Hosted Payment Page (HPP)
   - Transaction initiation
   - Return URL & callback handling
   - Payment status verification
4. Design a **clean integration strategy** that:
   - Saves personal info before redirect
   - Initiates PayTabs HPP
   - Handles return from PayTabs securely
   - Resumes the retreat journey correctly
5. Create a **step-by-step implementation plan**, including:
   - Backend (PHP)
   - Frontend (JS)
   - PayTabs configuration
   - Data persistence strategy (session / DB)
6. Cross-check the plan for:
   - Edge cases (refresh, back button, failed payment)
   - Security issues
   - UX continuity
7. Only after validation, start implementing:
   - PHP functions
   - AJAX handlers
   - PayTabs API calls
   - Return URL logic
   - Modal resume logic

Output Expectations:
- Clear explanation of the integration flow
- Step-by-step implementation guide
- Well-structured, readable PHP & JS code
- Inline comments explaining decisions
- No assumptions without verification
- No shortcuts that break WordPress best practices

Think carefully before coding. Validate the architecture first, then implement.
