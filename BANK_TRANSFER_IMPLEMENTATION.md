# Bank Transfer Payment Integration – Implementation Spec

**Project:** Tanafs – Therapy, Retreat & Academy Booking  
**Date:** 2026-02-26  
**Scope:** Add Manual Bank Transfer as a second payment method across all three booking journeys

> **Repository note:** This repo is a collection of extracted WordPress code snippets, not the full production tree.  
> File paths below identify *which snippet* holds the logic — not a filesystem dependency.  
> No folders are reorganised, no plugin architecture is redesigned.

---

## Table of Contents

1. [Current System Analysis](#1-current-system-analysis)
2. [File Integration Map](#2-file-integration-map)
3. [Database Changes](#3-database-changes)
4. [Admin UI Structure](#4-admin-ui-structure)
5. [Booking Flow Diagrams](#5-booking-flow-diagrams)
6. [State Handling Logic](#6-state-handling-logic)
7. [Task Breakdown](#7-task-breakdown)
8. [Effort Estimation](#8-effort-estimation)

---

## 1. Current System Analysis

### 1.1 Files & Responsibilities

| File | Role |
|------|------|
| `retreat/paytabs_admin.php` | Admin menu + PayTabs global settings page (credentials, region, currency). Registers top-level menu `PayTabs (Retreat)` → slug `paytabs-retreat-settings`. |
| `retreat/retreat_paytabs_integration.php` | Core PayTabs API functions (`paytabs_initiate_payment`, `paytabs_verify_payment`, `paytabs_log_transaction`). Handles retreat IPN callback (`paytabs_handle_callback`, priority 5). Processes retreat bookings post-payment (`process_retreat_booking_from_ipn`): creates WP user, saves meta, assigns to retreat group, enrolls in BuddyPress group, sends email. |
| `retreat/retreat_system.php` | Retreat CPT, DB tables (waiting list, questionnaire answers), availability helpers, BuddyPress group enrollment (`enroll_retreat_user_to_bp_chat_group`). |
| `retreat/retreat_group_management.php` | Admin AJAX for gender group cover images, retreat group CRUD, date normalization helpers. |
| `therapy_session_booking/therapy_paytabs_integration.php` | Therapy-specific PayTabs integration. Persistent booking storage (`therapy_booking_save/get/delete` — transient + `_tbp_` wp_option fallback). Three AJAX flows: new user, existing user (not logged in), logged-in user. IPN handler (`therapy_paytabs_handle_callback`, priority 5). Three IPN processors (`process_therapy_booking_from_ipn`, `process_therapy_existing_user_booking_from_ipn`, `process_therapy_logged_in_booking_from_ipn`). |
| `therapy_session_booking/Therapy_group_admin_dashboard.php` | Therapy Group CPT, admin dashboard menu, group CRUD AJAX, waiting list notifications, BuddyPress group creation. |
| `therapy_session_booking/User_Registration.php` | Session management, assessment page handling, post-registration redirect logic. |
| `tanafs_academy/tanafs_academy.php` | Academy DB tables (programs, schedules, sessions, registrations). Program init. Admin menu. Schedule creation with Zoom. Registrations management. **PayTabs payment integration is fully implemented** (lines 2487–2928): persistent booking storage (`academy_booking_save/get/delete` — transient + `_abp_` wp_option fallback), `ajax_academy_initiate_payment`, `ajax_academy_verify_payment`, `academy_complete_registration`, IPN handler `academy_paytabs_handle_callback` (priority 4 — runs before therapy/retreat). Price sourced from `get_option('academy_program_price_{id}', get_option('academy_registration_fee', 500))`. **Key difference vs therapy/retreat: no WP user creation** — Academy inserts directly into `wp_academy_registrations`; `user_id` is the currently logged-in user or `0`. No BuddyPress enrollment. |

### 1.2 Payment Flow Summary (Updated — all three modules)

> **UX change:** The primary booking button no longer triggers payment directly. After form submission it opens a **Payment Selection Modal** where the user chooses Online Payment or Bank Transfer. See Section 5 for full diagrams.

**Therapy & Retreat — PayTabs path (creates WP user):**
```
[User fills form] → clicks submit → [Payment Selection Modal]
    → user selects "Online Payment"
    → [save_*_booking_data AJAX → token stored]
    → [initiate_*_payment AJAX → paytabs_initiate_payment() → redirect_url]
    → [User pays on PayTabs HPP]
    → [IPN callback → process_*_from_ipn()]
        → wp_create_user() | assign_group | enroll_BP | send_email
    → [User returns → verify_*_payment_status AJAX]
        → wp_set_auth_cookie() (auto-login)
        → Redirect to /thank-you[-arabic]/
```

**Therapy & Retreat — Bank Transfer path (NEW):**
```
[User fills form] → clicks submit → [Payment Selection Modal]
    → user selects "Bank Transfer"
    → save_*_bank_transfer_data AJAX → wp_create_user / find existing
    → INSERT wp_manual_payments (status='pending')
    → [Modal expands: bank details + transfer form + receipt upload]
    → user submits transfer info + receipt(s)
    → submit_bank_transfer AJAX → record updated → emails sent
    → success message + "Return to Homepage" button (no auto-redirect)
    ─── ADMIN SIDE ─────────────────────────────────────────────────────
    → Admin approves → enroll_BP | meta set | approval email
    → OR Admin rejects → rejection email with note
```

**Academy — PayTabs path (no WP user creation):**
```
[User fills form] → clicks submit → [Payment Selection Modal]
    → user selects "Online Payment"
    → [academy_initiate_payment AJAX → token stored]
    → [User pays on PayTabs HPP]
    → [IPN: academy_paytabs_handle_callback()]
        → academy_complete_registration()
            → INSERT wp_academy_registrations (status='registered')
            → send confirmation email
    → [User returns → academy_verify_payment AJAX]
        → Show success message (stays on academy page, no redirect)
```

**Academy — Bank Transfer path (NEW):**
```
[User fills form] → clicks submit → [Payment Selection Modal]
    → user selects "Bank Transfer"
    → save_academy_bank_transfer_data AJAX
    → INSERT wp_academy_registrations (status='pending')
    → INSERT wp_manual_payments (status='pending')
    → [Modal expands: bank details + transfer form + receipt upload]
    → user submits → submit_bank_transfer AJAX → emails sent
    → success message + "Return to Homepage" button (no auto-redirect)
    ─── ADMIN SIDE ─────────────────────────────────────────────────────
    → Admin approves → registrations status='registered' | confirmation email
    → OR Admin rejects → status='cancelled' | rejection email
```

### 1.3 Key Patterns Identified (All Modules)

| Pattern | Therapy | Retreat | Academy |
|---------|---------|---------|---------|
| Booking token prefix | `therapy_` | `retreat_` | `academy_` |
| Persistent storage prefix | `_tbp_` | *(transient only)* | `_abp_` |
| IPN hook priority | 5 | 5 | **4** |
| WP user created on payment | ✅ | ✅ | ❌ (user_id=0 if not logged in) |
| BuddyPress enrollment | ✅ | ✅ | ❌ |
| Post-payment destination | `/thank-you/` (redirect) | `/thank-you/` (redirect) | Same page (success message) |
| Idempotency check | `booking_data['user_id']` | `booking_data['user_id']` | email+program_id duplicate check |
| Price source | ACF `therapy_price` on post | ACF fields on post | `wp_option academy_program_price_{id}` |

- **IPN routing by token prefix**: `academy_` caught at priority 4, `therapy_` at 5, retreat at 5 (cart_id not prefixed `therapy_`/`academy_`).
- **Admin menu anchors**: `paytabs-retreat-settings` (PayTabs global), `therapy-group-dashboard` (therapy), `academy-management` (academy).

---

## 2. File Integration Map

> **Core rule:** Extend existing files first. A new file is created only when bank transfer logic cannot reasonably fit inside an existing file.

### 2.1 Files to Extend (no new files created for these)

| Existing File | What Gets Added |
|---------------|-----------------|
| `retreat/paytabs_admin.php` | Rename menu to **Payment Configuration**; add **Payment Methods** and **PayTabs Settings** submenus; add **Manual Payments** top-level menu + 3 submenu registrations pointing to the 3 new dashboard files |
| `retreat/retreat_paytabs_integration.php` | `wp_manual_payments` + `wp_manual_payment_receipts` table creation (dbDelta on `init`); shared `submit_bank_transfer` AJAX handler (used by all three modules); `save_retreat_bank_transfer_data` AJAX; retreat bank transfer approval helper function |
| `retreat/retreat_system.php` | No changes required — existing `enroll_retreat_user_to_bp_chat_group()` called as-is from dashboard approval handler |
| `therapy_session_booking/therapy_paytabs_integration.php` | `save_therapy_bank_transfer_data` AJAX handler; therapy bank transfer approval helper function (calls existing `enroll_user_to_bp_chat_group()`) |
| `therapy_session_booking/Therapy_group_admin_dashboard.php` | `get_active_payment_methods()` helper; payment method selector HTML rendered in therapy registration shortcode/form |
| `tanafs_academy/tanafs_academy.php` | `ALTER TABLE wp_academy_registrations` (add `payment_method`, `payment_status`, `manual_payment_id`) in existing `academy_upgrade_*` pattern; `save_academy_bank_transfer_data` AJAX; payment method selector in registration modal JS; bank transfer details modal HTML block |

### 2.2 New Files Allowed (Admin Dashboards Only)

Three new standalone snippets — one per module. Each is a self-contained listing + approval dashboard. No shared base class. No service layer.

| New File | Purpose |
|----------|---------|
| `therapy_session_booking/therapy_manual_payments_dashboard.php` | Therapy Manual Payments admin sub-page: table, filters, approve/reject AJAX, approval email |
| `retreat/retreat_manual_payments_dashboard.php` | Retreat Manual Payments admin sub-page: same structure |
| `tanafs_academy/academy_manual_payments_dashboard.php` | Academy Manual Payments admin sub-page: same structure |

Each dashboard file:
- Registers its own `add_submenu_page` call inside an `admin_menu` hook (pointing to the `manual-payments` parent slug registered in `paytabs_admin.php`)
- Contains its own `approve_*_manual_payment` and `reject_*_manual_payment` AJAX handlers
- Calls module-specific completion functions that already exist (e.g., `enroll_user_to_bp_chat_group`, `enroll_retreat_user_to_bp_chat_group`, `academy_send_registration_confirmation`)
- Follows the same Bootstrap 5 + gradient (`#C3DDD2` → `#6059A6`) UI style used in `paytabs_admin.php`

### 2.3 New wp_options Keys

| Option Key | Type | Description |
|-----------|------|-------------|
| `payment_method_paytabs_enabled` | `1`/`0` | PayTabs on/off (default `1`) |
| `payment_method_bank_transfer_enabled` | `1`/`0` | Bank transfer on/off (default `0`) |
| `bank_transfer_account_holder` | string | Account holder name |
| `bank_transfer_bank_name` | string | Bank name |
| `bank_transfer_iban` | string | IBAN |
| `bank_transfer_account_number` | string | Account number |
| `bank_transfer_swift` | string | SWIFT/BIC code |
| `bank_transfer_branch` | string | Branch name |
| `bank_transfer_instructions` | text | Free-form instructions shown to user |

---

## 3. Database Changes

### 3.1 New Table: `wp_manual_payments`

> Table creation via `dbDelta` is added to `retreat/retreat_paytabs_integration.php` on the `init` hook — consistent with how `retreat_system.php` already creates its own tables.

```sql
CREATE TABLE wp_manual_payments (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Booking source
    module          VARCHAR(20)  NOT NULL,   -- 'therapy' | 'retreat' | 'academy'
    booking_ref     VARCHAR(100) NOT NULL,   -- e.g. therapy_<hex>
    booking_type    VARCHAR(50)  DEFAULT '',  -- 'therapy_new_user', 'retreat_female', 'academy', etc.

    -- User info (stored even if WP user not yet created)
    user_id         BIGINT UNSIGNED DEFAULT NULL,
    user_name       VARCHAR(255) NOT NULL,
    user_email      VARCHAR(255) NOT NULL,
    user_phone      VARCHAR(50)  DEFAULT '',

    -- Booking details
    group_id        BIGINT UNSIGNED DEFAULT NULL,  -- therapy or retreat group post ID; NULL for academy
    group_title     VARCHAR(255) DEFAULT '',
    amount          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    currency        VARCHAR(10)  NOT NULL DEFAULT 'SAR',

    -- Transfer details (user-supplied)
    transfer_date        DATE         DEFAULT NULL,
    transfer_amount      DECIMAL(10,2) DEFAULT NULL,
    transfer_reference   VARCHAR(255) DEFAULT '',
    sender_account_name  VARCHAR(255) DEFAULT '',
    sender_bank_name     VARCHAR(255) DEFAULT '',
    transfer_notes       TEXT         DEFAULT NULL,

    -- Receipt uploads (JSON array of URLs — denormalised read-cache)
    receipt_urls    LONGTEXT     DEFAULT NULL,

    -- Full booking snapshot (needed to complete registration on approval)
    booking_snapshot LONGTEXT    DEFAULT NULL,

    -- Workflow
    status          VARCHAR(20)  NOT NULL DEFAULT 'pending',
    -- 'pending' | 'approved' | 'rejected'
    admin_note      TEXT         DEFAULT NULL,
    reviewed_by     BIGINT UNSIGNED DEFAULT NULL,
    reviewed_at     DATETIME     DEFAULT NULL,

    -- Timestamps
    submitted_at    DATETIME     DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY module (module),
    KEY status (status),
    KEY user_email (user_email),
    KEY group_id (group_id),
    KEY submitted_at (submitted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3.2 New Table: `wp_manual_payment_receipts`

> Also created in `retreat/retreat_paytabs_integration.php`. `receipt_urls` JSON in the main table is a denormalised read-cache.

```sql
CREATE TABLE wp_manual_payment_receipts (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    payment_id      BIGINT UNSIGNED NOT NULL,
    file_url        TEXT NOT NULL,
    file_name       VARCHAR(255) DEFAULT '',
    file_size       INT UNSIGNED DEFAULT 0,
    mime_type       VARCHAR(100) DEFAULT '',
    uploaded_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY payment_id (payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3.3 Existing Table Modification

#### `wp_academy_registrations` — added inside `tanafs_academy.php` using the existing `academy_upgrade_arabic_columns()` pattern
```sql
ALTER TABLE wp_academy_registrations
    ADD COLUMN payment_method    VARCHAR(20)  DEFAULT 'paytabs'   AFTER registration_status,
    ADD COLUMN payment_status    VARCHAR(20)  DEFAULT 'completed'  AFTER payment_method,
    ADD COLUMN manual_payment_id BIGINT UNSIGNED DEFAULT NULL      AFTER payment_status;
```

Therapy and Retreat store payment meta in `wp_usermeta`. No schema change needed for those modules.

#### New user meta keys for bank transfer bookings (Therapy & Retreat only)

| Meta Key | Description |
|----------|-------------|
| `payment_method` | `'paytabs'` or `'bank_transfer'` |
| `bank_transfer_status` | `'pending'` / `'approved'` / `'rejected'` |
| `bank_transfer_payment_id` | FK to `wp_manual_payments.id` |
| `bank_transfer_group_pending` | Group post ID held pending approval |

---

## 4. Admin UI Structure

### 4.1 Renamed / Restructured Menu

All changes are inside `retreat/paytabs_admin.php`. No new admin configuration file is created.

**Before:**
```
PayTabs (Retreat)                 [slug: paytabs-retreat-settings]
```

**After:**
```
Payment Configuration             [slug: payment-configuration]
  ├── Payment Methods             [slug: payment-configuration-methods]
  └── PayTabs Settings            [slug: payment-configuration-paytabs]
        (existing settings form — moved under new parent, slug unchanged)

Manual Payments                   [slug: manual-payments]
  ├── Therapy Payments            [slug: manual-payments-therapy]
  ├── Retreat Payments            [slug: manual-payments-retreat]
  └── Academy Payments            [slug: manual-payments-academy]
```

`Manual Payments` top-level menu is registered in `paytabs_admin.php`. Each sub-page render callback lives in its respective new dashboard file.

### 4.2 Payment Methods Page (inside `paytabs_admin.php`)

**Enable/disable toggles:**
- ☑ Enable PayTabs (Online Payment)
- ☑ Enable Bank Transfer (Offline Payment)

**Bank Account Info Section:**

| Field | Input Type |
|-------|-----------|
| Account Holder Name | text |
| Bank Name | text |
| IBAN | text |
| Account Number | text |
| SWIFT / BIC | text |
| Branch Name | text |
| Transfer Instructions | textarea |

Saves all fields via single `<form method="post">` with `wp_nonce_field` — same pattern as the existing PayTabs settings form in this file. Styling: Bootstrap 5 `.settings-card` + `.btn-save` gradient (`#C3DDD2` → `#6059A6`) already in use.

**Informational note on page:**
> - Both enabled → both buttons active in the Payment Selection Modal during booking
> - Only one enabled → the other button is greyed out with "Coming Soon" / "قريباً"

### 4.3 Manual Payments Dashboard (per module) — 3 new files

Each sub-page shows a filterable table with **minimal columns** — one row per pending/processed payment:

| # | Column | Notes |
|---|--------|-------|
| 1 | User | Name + email |
| 2 | Booking Type | Therapy Group / Retreat / Academy Program |
| 3 | Related Group | Group title if applicable |
| 4 | Amount | Amount + currency |
| 5 | Submitted | Date/time |
| 6 | Status | Badge: Pending / Approved / Rejected |
| 7 | Actions | **Details** button only |

Row is intentionally compact — full transfer data and receipts are accessed via the Details modal.

**Filters:**
- Status dropdown: All / Pending / Approved / Rejected
- Search field: searches user name, email, phone (LIKE query)

**Details Modal (opens on "Details" button click):**

Displays the full payment snapshot in a Bootstrap 5 modal:
- User info: name, email, phone
- Booking: type, group/program, amount, booking reference
- Transfer details: transfer date, amount, bank name, reference number, sender name, notes
- Receipts: inline thumbnail previews with full-size links (one per uploaded file)
- Submission timestamp and current status

Modal footer contains two action buttons:
- **Approve** (green / success) → submits approve AJAX → closes modal → row status badge updates to Approved
- **Reject** (red / danger) → clicking Reject **reveals an inline Remarks textarea** inside the modal (does not close or navigate). Admin types the rejection reason, then clicks a **Confirm Rejection** button to submit. The remarks text is stored in `wp_manual_payments.admin_note` and **included in the rejection email sent to the user**.

---

## 5. Booking Flow Diagrams & UX Specification

### 5.0 Trigger Behavior Change

**Before (current):**
The primary booking button directly initiates payment:
- Therapy → "Continue to Payment" → immediately triggers PayTabs redirect
- Retreat → "Book Your Spot 📅" → immediately triggers PayTabs redirect
- Academy → "Submit Registration" → immediately triggers PayTabs redirect

**After (new):**
The primary booking button validates and saves the registration form, then **replaces the form content with a Payment Selection Modal inside the same container** — no immediate redirect. The user actively chooses a payment method before any processing begins.

---

### 5.1 Payment Selection Modal — Specification

**Trigger:** Form submit button click → form validated → AJAX saves registration data → modal replaces form content (in-page, no page reload, no redirect)

**Layout:** Two side-by-side option buttons

| | Online Payment | Bank Transfer |
|---|---|---|
| **Label (EN)** | Online Payment | Bank Transfer |
| **Sub-label (EN)** | Secure payment via PayTabs | Manual / Offline Transfer |
| **Label (AR)** | الدفع الإلكتروني | التحويل البنكي |
| **Sub-label (AR)** | دفع آمن عبر PayTabs | تحويل يدوي / بنكي |

**Enabled / Disabled rule — both buttons are ALWAYS rendered:**
- If a method is **enabled** in admin → button is active and clickable
- If a method is **disabled** in admin → button appears greyed out (`opacity: 0.5`, `cursor: not-allowed`, pointer-events disabled); a label below reads:
  - English page: **"Coming Soon"**
  - Arabic page: **"قريباً"**

**Language detection (inline JS added to each module's existing enqueue):**
```javascript
var isArabic = document.documentElement.lang !== 'en'
               || document.documentElement.dir === 'rtl';
var comingSoonText = isArabic ? 'قريباً' : 'Coming Soon';
var successMsg     = isArabic
    ? 'تم تقديم طلب تسجيلك بنجاح. سيقوم فريقنا بمراجعة دفعتك وإخطارك عند الموافقة.'
    : 'Your registration has been submitted successfully. Our team will review your payment and notify you once approved.';
var returnBtnText  = isArabic ? 'العودة إلى الصفحة الرئيسية' : 'Return to Homepage';
var returnBtnUrl   = isArabic ? '/' : '/en/home-life-coach/';
```

**Styling:** Consistent with existing module modal design. Active button: gradient `#C3DDD2 → #6059A6`. Disabled button: `#d0d0d0 → #a8a8a8`.

---

### 5.2 Master Visual Flow — All Three Modules

> Applies to Therapy, Retreat, and Academy.

```
┌─────────────────────────────────────────────────────────────────────┐
│  STEP 1 · REGISTRATION                                              │
│                                                                     │
│  User fills and submits the registration form                       │
│  (Therapy / Retreat / Academy — form design unchanged)              │
│                                                                     │
│  Clicks:                                                            │
│    Therapy  → "Continue to Payment"                                 │
│    Retreat  → "Book Your Spot"                                      │
│    Academy  → "Submit Registration"                                 │
└──────────────────────────────┬──────────────────────────────────────┘
                               │
                               ▼
┌─────────────────────────────────────────────────────────────────────┐
│  STEP 2 · PAYMENT SELECTION                                         │
│                                                                     │
│  Form area is replaced with two payment options (no page redirect)  │
│                                                                     │
│   ┌────────────────────────┐      ┌────────────────────────┐       │
│   │   💳  ONLINE PAYMENT   │      │   🏦  BANK TRANSFER    │       │
│   │       (PayTabs)        │      │   (Manual / Offline)   │       │
│   │                        │      │                        │       │
│   │   [ Active button ]    │ ─OR─ │   [ Active button ]    │       │
│   │        — or —          │      │        — or —          │       │
│   │    [ Greyed out ]      │      │   [ Greyed out ]       │       │
│   │    "Coming Soon"       │      │   "Coming Soon"        │       │
│   │      "قريباً"        │      │           "قريباً"         │       │
│   └────────────────────────┘      └────────────────────────┘       │
└──────────────┬──────────────────────────────┬───────────────────────┘
               │ User selects                 │ User selects
               │ Online Payment               │ Bank Transfer
               ▼ follows existing flow        ▼
┌──────────────────────────┐  ┌───────────────────────────────────────┐
│  STEP 3A                 │  │  STEP 3B                              │
│  PAYTABS REDIRECT        │  │  BANK TRANSFER DETAILS                │
│                          │  │                                       │
│  User redirected to      │  │  Modal expands in-page to show:       │
│  PayTabs secure          │  │  · Bank account details               │
│  payment page            │  │  · Transfer info form fields          │
│                          │  │  · Receipt upload (up to 5 files)     │
│  User completes          │  │                                       │
│  payment online          │  │  User fills details, uploads proof,   │
│                          │  │  and clicks "Submit Transfer"         │
└────────────┬─────────────┘  └───────────────────────┬───────────────┘
             │                                        │
             ▼                                        ▼
┌──────────────────────────┐  ┌───────────────────────────────────────┐
│  STEP 4A                 │  │  STEP 4B                              │
│  INSTANT COMPLETION      │  │  PENDING REVIEW                       │
│                          │  │                                       │
│  Payment confirmed       │  │  Success message shown in modal:      │
│  automatically by        │  │  "Your registration has been          │
│  PayTabs IPN             │  │  submitted. Our team will review      │
│                          │  │  your payment and notify you."        │
│  Therapy / Retreat:      │  │                                       │
│  → Enrolled in group     │  │  ┌─────────────────────────────────┐  │
│                          │  │  │  "Return to Homepage" button    │  │
│  Academy:                │  │  │  (user must click — no          │  │
│  → Registration saved    │  │  │   automatic redirect)           │  │
│                          │  │  └─────────────────────────────────┘  │
│                          │  │                                       │
└──────────────────────────┘  └───────────────────────┬───────────────┘
                                                      │
                                                    ▼
                              ┌────────────────────────────────────────┐
                              │  STEP 5 · ADMIN REVIEW                 │
                              │                                        │
                              │  Admin clicks "Details" on a row in   │
                              │  the Manual Payments dashboard         │
                              │                                        │
                              │  Details modal shows full snapshot:    │
                              │  user info · transfer details ·        │
                              │  receipt previews                      │
                              │                                        │
                              │   ┌─────────────┐  ┌──────────────┐   │
                              │   │  ✅ APPROVE │  │  ❌ REJECT   │   │
                              │   └──────┬──────┘  └──────┬───────┘   │
                              │          │                 │           │
                              │          │           Remarks textarea  │
                              │          │           revealed inline   │
                              │          │           → Confirm button  │
                              └──────────┼─────────────────┼───────────┘
                                         │                 │
                         ┌───────────────┘                 └──────────────┐
                         ▼                                                 ▼
           ┌─────────────────────────┐               ┌─────────────────────────┐
           │  STEP 6A · CONFIRMED    │               │  STEP 6B · REJECTED     │
           │                         │               │                         │
           │  Therapy / Retreat:     │               │  Rejection email sent   │
           │  → BuddyPress group     │               │  to user with note      │
           │    enrollment triggered │               │                         │
           │  → Approval email sent  │               │  No enrollment          │
           │                         │               │  No group assignment    │
           │  Academy:               │               │                         │
           │  → Registration marked  │               │                         │
           │    as confirmed         │               │                         │
           │  → Confirmation email   │               │                         │
           └─────────────────────────┘               └─────────────────────────┘
```

---

### 5.3 Step-by-Step: Therapy Booking

#### Path A — Online Payment (PayTabs, unchanged internally)

1. User fills therapy registration form on the booking page
2. Clicks **"Continue to Payment"**
3. Form validated; `save_therapy_booking_data` AJAX saves booking token (`_tbp_`)
4. **Payment Selection Modal** replaces the form area (in-page)
5. User clicks **"Online Payment"**
6. `initiate_therapy_payment` AJAX fires → `paytabs_initiate_payment()` → `redirect_url`
7. User redirected to PayTabs HPP; completes payment
8. PayTabs IPN → `therapy_paytabs_handle_callback()` (priority 5)
   - WP user created or matched; assigned to therapy group
   - Enrolled in BuddyPress group; confirmation email sent
9. User returns → `verify_therapy_payment_status` AJAX → auto-login → redirect `/thank-you-arabic/`

#### Path B — Bank Transfer

1. User fills therapy registration form
2. Clicks **"Continue to Payment"**
3. Form validated; `save_therapy_booking_data` AJAX saves booking token
4. **Payment Selection Modal** replaces the form area
5. User clicks **"Bank Transfer"**
6. `save_therapy_bank_transfer_data` AJAX fires:
   - WP user created or found; `wp_set_auth_cookie()`
   - `wp_manual_payments` row inserted (`status='pending'`, `module='therapy'`)
   - User meta: `bank_transfer_group_pending={group_id}`, `bank_transfer_status='pending'`
7. Modal **expands** to show: bank account details, transfer info form, receipt uploader
8. User fills transfer info, uploads receipt(s), clicks **"Submit Transfer"**
9. `submit_bank_transfer` AJAX: files uploaded, record updated, emails dispatched
10. **Success message** displayed in modal (language-aware):
    - EN: *"Your registration has been submitted successfully. Our team will review your payment and notify you once approved."*
    - AR: *"تم تقديم طلب تسجيلك بنجاح. سيقوم فريقنا بمراجعة دفعتك وإخطارك عند الموافقة."*
11. **"Return to Homepage"** button appears — user must click to navigate:
    - EN → `/en/home-life-coach/`
    - AR → `/`
    - *(No automatic redirect)*

**Admin review:**
12. Admin: Manual Payments → Therapy Payments → clicks **Details** button for the payment row
    - Details modal opens: full booking snapshot, transfer details, receipt previews
    - Clicks **Approve** → `approve_therapy_manual_payment` AJAX:
      - `assigned_group` user meta set; `enroll_user_to_bp_chat_group()` called
      - Approval email sent to user; modal closes; row status → Approved
    - Clicks **Reject** → **Remarks textarea** appears inline in modal → admin types reason → clicks **Confirm Rejection**:
      - `reject_therapy_manual_payment` AJAX: `bank_transfer_status='rejected'`; `admin_note` saved; rejection email sent with remarks text

---

### 5.4 Step-by-Step: Retreat Booking

#### Path A — Online Payment (PayTabs, unchanged internally)

1. User opens retreat booking modal → fills registration form + questionnaire
2. Clicks **"Book Your Spot 📅"**
3. Form + questionnaire validated; booking data saved
4. **Payment Selection Modal** replaces the form content inside the modal
5. User clicks **"Online Payment"**
6. Existing retreat PayTabs initiation fires → redirect to PayTabs HPP
7. User pays on PayTabs HPP
8. PayTabs IPN → `paytabs_handle_callback()` (priority 5)
   - WP user created; questionnaire answers saved to `wp_retreat_questionnaire_answers`
   - Assigned to retreat group; enrolled in BuddyPress group; confirmation email sent
9. User returns → verify flow → auto-login → redirect `/thank-you-arabic/`

#### Path B — Bank Transfer

1. User fills retreat registration modal + questionnaire
2. Clicks **"Book Your Spot 📅"**
3. Form + questionnaire validated; booking data saved
4. **Payment Selection Modal** replaces form content
5. User clicks **"Bank Transfer"**
6. `save_retreat_bank_transfer_data` AJAX fires:
   - WP user created or found; auto-login
   - Questionnaire answers saved to `wp_retreat_questionnaire_answers`
   - `wp_manual_payments` row inserted
   - User meta: `assigned_retreat_group_pending={group_id}`, `bank_transfer_status='pending'`
7. Modal expands: bank account details, transfer info form, receipt uploader
8. User fills and submits
9. `submit_bank_transfer` AJAX: files uploaded, record updated, emails sent
10. Success message + **"Return to Homepage"** button *(user must click, no auto-redirect)*:
    - EN → `/en/home-life-coach/`  |  AR → `/`

**Admin review:**
11. Admin: Manual Payments → Retreat Payments → clicks **Details** button for the payment row
    - Details modal opens: full booking snapshot, questionnaire summary, transfer details, receipt previews
    - Clicks **Approve** → `approve_retreat_manual_payment` AJAX:
      - `assigned_retreat_group` meta set; `enroll_retreat_user_to_bp_chat_group()` called; approval email sent; row status → Approved
    - Clicks **Reject** → **Remarks textarea** appears inline in modal → admin types reason → clicks **Confirm Rejection**:
      - `reject_retreat_manual_payment` AJAX: status → `rejected`; `admin_note` saved; rejection email sent with remarks text

---

### 5.5 Step-by-Step: Tanafs Academy Registration

#### Path A — Online Payment (PayTabs, current — fully unchanged internally)

1. User opens academy registration modal; fills form
2. Clicks **"Submit Registration"**
3. Form validated; duplicate-registration check runs
4. **Payment Selection Modal** replaces form content
5. User clicks **"Online Payment"**
6. `academy_initiate_payment` AJAX fires → `paytabs_initiate_payment()` → redirect to PayTabs HPP
7. User pays on PayTabs HPP
8. PayTabs IPN → `academy_paytabs_handle_callback()` (priority 4)
   - `academy_complete_registration()` called
   - `wp_academy_registrations` row inserted (`status='registered'`)
   - Confirmation email sent
9. User returns → `academy_verify_payment` AJAX → success message displayed *(no page redirect — stays on academy page)*

#### Path B — Bank Transfer

1. User fills academy registration modal
2. Clicks **"Submit Registration"**
3. Form validated; duplicate-registration check runs
4. **Payment Selection Modal** replaces form content
5. User clicks **"Bank Transfer"**
6. `save_academy_bank_transfer_data` AJAX fires:
   - `wp_academy_registrations` inserted: `status='pending'`, `payment_method='bank_transfer'`
   - `wp_manual_payments` row inserted
   - `manual_payment_id` saved back to the registration row
7. Modal expands: bank account details, transfer info form, receipt uploader
8. User fills and submits
9. `submit_bank_transfer` AJAX: files uploaded, record updated, emails sent
10. Success message displayed *(in-page, mirrors `academy_verify_payment` UX — no redirect)*:
    - EN: *"Your registration has been submitted successfully. Our team will review your payment and notify you once approved."*
    - AR: *"تم تقديم طلب تسجيلك بنجاح. سيقوم فريقنا بمراجعة دفعتك وإخطارك عند الموافقة."*
11. **"Return to Homepage"** button *(user must click, no auto-redirect)*:
    - EN → `/en/home-life-coach/`  |  AR → `/`

**Admin review:**
12. Admin: Manual Payments → Academy Payments → clicks **Details** button for the payment row
    - Details modal opens: full registration snapshot, program details, transfer details, receipt previews
    - Clicks **Approve** → `approve_academy_manual_payment` AJAX:
      - `wp_academy_registrations` updated `status='registered'`, `payment_status='completed'`; `academy_send_registration_confirmation()` called; row status → Approved
    - Clicks **Reject** → **Remarks textarea** appears inline in modal → admin types reason → clicks **Confirm Rejection**:
      - `reject_academy_manual_payment` AJAX: `status='cancelled'`, `payment_status='rejected'`; `admin_note` saved; rejection email sent with remarks text

---

### 5.6 Backend Integration Notes

The Payment Selection Modal is a **frontend-only change**. No backend logic is modified:
- PayTabs AJAX handlers are triggered from the modal instead of directly from the form — otherwise identical
- Bank transfer AJAX handlers operate the same regardless of how they are invoked
- The modal intercepts the form submit at the JS layer only, presents the two options, then dispatches to the appropriate AJAX handler based on user selection
- All IPN callbacks, token storage, verify flows, and admin approval logic are unchanged

**Scope of frontend changes only:**
- ✅ Form submit now shows Payment Selection Modal instead of triggering payment
- ✅ Both payment buttons always visible; disabled option shows "Coming Soon" / "قريباً"
- ✅ Language-aware labels and messages throughout
- ✅ Bank transfer success shows in-modal message + manual "Return to Homepage" button
- ❌ No backend changes
- ❌ No PayTabs processing changes
- ❌ No admin configuration changes
- ❌ No booking state logic changes

---

## 6. State Handling Logic

### 6.1 wp_manual_payments Status Machine

```
                   ┌──────────┐
    form submitted │  PENDING │
                   └────┬─────┘
                        │
            ┌───────────┴──────────┐
            ▼                      ▼
       ┌──────────┐          ┌──────────┐
       │ APPROVED │          │ REJECTED │
       └──────────┘          └──────────┘
```

**On APPROVED:**
- Therapy → `assigned_group` meta set → `enroll_user_to_bp_chat_group()`
- Retreat → `assigned_retreat_group` meta set → `enroll_retreat_user_to_bp_chat_group()`
- Academy → `wp_academy_registrations.registration_status = 'registered'`

**On REJECTED:**
- All: rejection email sent with admin note. No user/group changes applied.

### 6.2 Frontend Payment Method Rendering

Added as a flat helper function inside each module's primary file:

```php
// Placed in: Therapy_group_admin_dashboard.php, retreat_paytabs_integration.php, tanafs_academy.php
function get_active_payment_methods(): array {
    $methods = [];
    if (get_option('payment_method_paytabs_enabled', '1') === '1') {
        $methods[] = 'paytabs';
    }
    if (get_option('payment_method_bank_transfer_enabled', '0') === '1') {
        $methods[] = 'bank_transfer';
    }
    return $methods;
}
```

Passed to frontend JS via `wp_localize_script` in the existing enqueue hooks to determine which buttons appear active.

Payment Selection Modal rendering rules:
- **Both buttons always rendered** — the modal always shows both payment options regardless of admin settings
- If a method is **enabled** → button is active and clickable (gradient `#C3DDD2 → #6059A6`)
- If a method is **disabled** → button is greyed out (`opacity: 0.5`, `cursor: not-allowed`, pointer-events disabled) with label below:
  - English page: **"Coming Soon"**
  - Arabic page: **"قريباً"**
- Active/disabled state determined by `get_active_payment_methods()` return value passed as a JS var via `wp_localize_script`

Existing PayTabs submit path executes unchanged once the user clicks the Online Payment button.

### 6.3 BuddyPress Enrollment — Deferred Until Approval

Current: `enroll_*_to_bp_chat_group()` called inside `process_*_from_ipn()`.  
Bank transfer: enrollment is **moved to the approval handler** inside the dashboard file.  
`group_id` stored in `wp_manual_payments.group_id` and as `bank_transfer_group_pending` user meta (safety backup).  
**Academy has no BuddyPress enrollment** for either payment method — no change needed.

### 6.4 User Creation for Bank Transfer

**Therapy & Retreat** (mirrors existing IPN processor logic in their respective files):
```
Does WP user with this email exist?
  YES → use existing, set payment meta
  NO  → wp_create_user() → set meta → wp_set_auth_cookie()
```
User is created at form submission, not at approval, so an acknowledgement email is sent and the user is identifiable in the dashboard immediately.

**Academy** (mirrors `ajax_academy_initiate_payment`):
```
No WP user creation — user_id = get_current_user_id() ?: 0
Row inserted into wp_academy_registrations at submission (status='pending')
Identified by email + program_id duplicate check (same as PayTabs path)
```

### 6.5 Shared `submit_bank_transfer` AJAX Handler

Located in `retreat/retreat_paytabs_integration.php` — consistent with how `paytabs_initiate_payment` and `paytabs_verify_payment` are already shared from this file across all three modules.

```
add_action('wp_ajax_submit_bank_transfer',        'ajax_submit_bank_transfer');
add_action('wp_ajax_nopriv_submit_bank_transfer', 'ajax_submit_bank_transfer');

function ajax_submit_bank_transfer() {
    // nonce check
    // validate payment_id ownership
    // wp_handle_upload() per file (MIME: jpg/png/webp/pdf, max 5 MB each, up to 5 files)
    // UPDATE wp_manual_payments with transfer details
    // INSERT wp_manual_payment_receipts rows
    // UPDATE receipt_urls JSON
    // send user "pending review" email
    // send admin notification email
    // wp_send_json_success()
}
```

### 6.6 Receipt Upload Security

- `wp_handle_upload()` with `test_form => false`
- Allowed MIME types: `image/jpeg`, `image/png`, `image/webp`, `application/pdf`
- Max 5 MB per file, up to 5 files
- Filenames sanitised by WordPress upload handler
- File URLs stored in `wp_manual_payment_receipts` and denormalised into `wp_manual_payments.receipt_urls` (JSON)

---

## 7. Task Breakdown

### Phase 1 – Admin Configuration (Foundation)

| # | Task | File(s) | Notes |
|---|------|---------|-------|
| 1.1 | Rename `add_menu_page` call: `PayTabs (Retreat)` → `Payment Configuration`; update slug and callback | `retreat/paytabs_admin.php` | One function edit |
| 1.2 | Add `Payment Methods` submenu with toggle checkboxes + bank account fields; add `PayTabs Settings` submenu for existing form | `retreat/paytabs_admin.php` | Two `add_submenu_page` calls + new `render_payment_methods_page()` in same file; Bootstrap 5 `.settings-card` style |
| 1.3 | Register `Manual Payments` top-level menu + 3 submenu registrations (render callbacks in dashboard files) | `retreat/paytabs_admin.php` | `add_menu_page` + 3 `add_submenu_page` calls |

---

### Phase 2 – Database Setup

| # | Task | File to Modify | Notes |
|---|------|----------------|-------|
| 2.1 | Create `wp_manual_payments` table via `dbDelta` on `init` | `retreat/retreat_paytabs_integration.php` | Consistent with how `retreat_system.php` creates its own tables |
| 2.2 | Create `wp_manual_payment_receipts` table in same hook | `retreat/retreat_paytabs_integration.php` | Same `init` callback |
| 2.3 | ALTER `wp_academy_registrations` — add `payment_method`, `payment_status`, `manual_payment_id` | `tanafs_academy/tanafs_academy.php` | Inside/alongside existing `academy_upgrade_arabic_columns()` pattern |

---

### Phase 3 – Shared Bank Transfer Submission Handler

| # | Task | File to Modify | Notes |
|---|------|----------------|-------|
| 3.1 | `submit_bank_transfer` AJAX handler | `retreat/retreat_paytabs_integration.php` | Validates receipt uploads, saves to DB, sends emails — shared by all three modules |
| 3.2 | Receipt file upload helper (MIME check, size limit) | Same file | Called inside handler above |
| 3.3 | Admin notification email on submission | Same file | Sent to `get_option('admin_email')` |
| 3.4 | User acknowledgement email on submission | Same file | "Pending review" message, HTML style matches existing emails |

---

### Phase 4 – Per-Module Admin Approval/Rejection

Each dashboard file contains its own AJAX handlers. No shared approver file.

| # | Task | File | Notes |
|---|------|------|-------|
| 4.1 | `approve_therapy_manual_payment` AJAX — set user meta, `enroll_user_to_bp_chat_group()`, send approval email | `therapy_session_booking/therapy_manual_payments_dashboard.php` | Calls existing function |
| 4.2 | `reject_therapy_manual_payment` AJAX — update status, save `admin_note` (remarks from Details modal), send rejection email with remarks text | Same file | Remarks field submitted from the inline textarea in the Details modal |
| 4.3 | `approve_retreat_manual_payment` AJAX — set user meta, `enroll_retreat_user_to_bp_chat_group()`, send approval email | `retreat/retreat_manual_payments_dashboard.php` | Calls existing function in `retreat_system.php` |
| 4.4 | `reject_retreat_manual_payment` AJAX — update status, save `admin_note`, send rejection email with remarks text | Same file | Same remarks-from-modal pattern |
| 4.5 | `approve_academy_manual_payment` AJAX — UPDATE `wp_academy_registrations`, call `academy_send_registration_confirmation()` | `tanafs_academy/academy_manual_payments_dashboard.php` | Calls existing function |
| 4.6 | `reject_academy_manual_payment` AJAX — UPDATE `wp_academy_registrations` (cancelled), save `admin_note`, send rejection email with remarks text | Same file | Same remarks-from-modal pattern |

---

### Phase 5 – Therapy Bank Transfer Frontend

| # | Task | File to Modify | Notes |
|---|------|----------------|-------|
| 5.1 | Payment Selection Modal HTML + JS — intercepts form submit; always shows both buttons; language-aware disabled state ("Coming Soon" / "قريباً") | `therapy_session_booking/Therapy_group_admin_dashboard.php` | Replaces form area in-page; no redirect; `wp_localize_script` vars used for active/disabled state |
| 5.2 | `save_therapy_bank_transfer_data` AJAX handler | `therapy_session_booking/therapy_paytabs_integration.php` | Validate, create/find user, auto-login, INSERT `wp_manual_payments` |
| 5.3 | Bank Transfer expanded view HTML + JS (bank details panel, transfer form, receipt uploader, success message, language-aware "Return to Homepage" button) | `therapy_session_booking/Therapy_group_admin_dashboard.php` | Inline with existing therapy shortcode HTML block; success message stays in modal; no auto-redirect |
| 5.4 | Post-submission success state + "Return to Homepage" button | Same JS block | User must click; EN → `/en/home-life-coach/`, AR → `/`; `window.location` only on button click |

---

### Phase 6 – Retreat Bank Transfer Frontend

| # | Task | File to Modify | Notes |
|---|------|----------------|-------|
| 6.1 | Payment Selection Modal HTML + JS — intercepts retreat modal form submit; always shows both buttons; language-aware disabled state | `retreat/retreat_paytabs_integration.php` | Matches retreat modal HTML style; `wp_localize_script` vars for active/disabled state |
| 6.2 | `save_retreat_bank_transfer_data` AJAX handler | `retreat/retreat_paytabs_integration.php` | Validate, create/find user, save questionnaire, INSERT `wp_manual_payments` |
| 6.3 | Bank Transfer expanded view HTML + JS (bank details, transfer form, receipt uploader, success message, language-aware "Return to Homepage" button) | `retreat/retreat_paytabs_integration.php` | Same receipt uploader UI pattern; success message in-modal; no auto-redirect |
| 6.4 | Post-submission success state + "Return to Homepage" button | Same JS block | EN → `/en/home-life-coach/`, AR → `/`; button click only |

---

### Phase 7 – Academy Bank Transfer Frontend

> PayTabs flow (lines 2487–2928 in `tanafs_academy.php`) stays completely untouched. Only the bank transfer branch is added.

| # | Task | File to Modify | Notes |
|---|------|----------------|-------|
| 7.1 | Payment Selection Modal HTML + JS — intercepts academy registration modal submit; always shows both buttons; language-aware disabled state | `tanafs_academy/tanafs_academy.php` | Inline within academy shortcode; `wp_localize_script` vars for active/disabled state |
| 7.2 | `save_academy_bank_transfer_data` AJAX handler | `tanafs_academy/tanafs_academy.php` | Validate, INSERT `wp_academy_registrations` (pending) + `wp_manual_payments` |
| 7.3 | Bank Transfer expanded view HTML + JS (bank details, transfer form, receipt uploader, success message, language-aware "Return to Homepage" button) | `tanafs_academy/tanafs_academy.php` | Success message mirrors `academy_verify_payment` UX (in-page, no redirect); EN → `/en/home-life-coach/`, AR → `/` |

---

### Phase 8 – Manual Payments Admin Dashboard UI (3 new files)

| # | Task | File | Notes |
|---|------|------|-------|
| 8.1 | Therapy Payments sub-page: minimal-column table (User, Booking Type, Group, Amount, Submitted, Status, Details button) + status filter + search | `therapy_session_booking/therapy_manual_payments_dashboard.php` | Approve/Reject actions are inside the Details modal, not in the table row |
| 8.2 | Retreat Payments sub-page: same structure | `retreat/retreat_manual_payments_dashboard.php` | Same pattern |
| 8.3 | Academy Payments sub-page: same structure | `tanafs_academy/academy_manual_payments_dashboard.php` | Same pattern |
| 8.4 | Details modal: full booking snapshot (all user + transfer + receipt fields) + **Approve** button + **Reject** button | Each dashboard file respectively | Single Bootstrap 5 modal per page reused for all rows; populated via JS from row data attributes or AJAX fetch |
| 8.5 | Reject flow — on Reject click: reveal inline **Remarks** `<textarea>` inside modal + **Confirm Rejection** button; remarks POSTed to reject AJAX handler and included in rejection email | Each dashboard file respectively | Textarea hidden by default; shown via `$('#remarks-section').show()`; Confirm button submits the AJAX |
| 8.6 | Status filter + search field AJAX (inline JS, no separate JS file) | Each dashboard file respectively | LIKE query on name/email/phone |

UI style for all 3: Bootstrap 5, `.settings-card` + gradient `.btn-save` (`#C3DDD2` → `#6059A6`) matching `paytabs_admin.php`.

---

### Phase 9 – Testing & Hardening

| # | Test |
|---|------|
| 9.1 | End-to-end: therapy bank transfer → admin approval → BP group enrollment confirmed |
| 9.2 | End-to-end: retreat bank transfer → admin approval → BP group enrollment confirmed |
| 9.3 | End-to-end: academy bank transfer → admin approval → `registration_status = 'registered'` |
| 9.24 | Only PayTabs enabled → bank transfer button greyed out with "Coming Soon" / "قريباً" |
| 9.25 | Only bank transfer enabled → PayTabs button greyed out with "Coming Soon" / "قريباً" |
| 9.26 | Both enabled → both buttons active and clickable |
| 9.27 | MIME restriction on receipt upload (reject .exe, .zip) |
| 9.28 | Receipt upload > 5 MB rejected |
| 9.29 | Existing user bank transfer (no duplicate `wp_create_user`) |
| 9.30 | Logged-in user bank transfer |
| 9.31 | **Regression**: all existing PayTabs flows unaffected |
| 9.32 | Admin panel: search by name / email / phone |
| 9.33 | Admin panel: filter by status |
| 9.34 | Security: nonce on every AJAX handler |
| 9.35 | Security: `manage_options` cap check on all admin actions |
| 9.36 | Security: `sanitize_*` / `esc_html` / `esc_url` on all user-supplied data (including remarks field) |
| 9.17 | Admin Details modal opens with correct data for selected payment row |
| 9.18 | Admin Details modal shows all receipt thumbnails with working full-size links |
| 9.19 | Approve button in Details modal → booking confirmed + approval email sent + row badge → Approved |
| 9.20 | Reject button in Details modal → Remarks textarea revealed; Confirm Rejection hidden until clicked |
| 9.21 | Rejection with remarks → `admin_note` saved to DB; rejection email body contains the remarks text |
| 9.22 | Rejection with empty remarks → AJAX validation rejects submission (remarks required) |
| 9.23 | Payment Selection Modal: both buttons always visible even when one method is disabled |
| 9.18 | Disabled button shows "Coming Soon" on English page (`lang='en'`) |
| 9.19 | Disabled button shows "قريباً" on Arabic page (`dir='rtl'` or `lang='ar'`) |
| 9.20 | Language detection correct when `<html dir='rtl'>` is set (Arabic site) |
| 9.21 | "Return to Homepage" button (EN page) links to `/en/home-life-coach/` |
| 9.22 | "Return to Homepage" button (AR page) links to `/` |
| 9.23 | No automatic redirect after bank transfer submission — only button click navigates |
| 9.24 | Academy bank transfer success stays on academy page (mirrors `academy_verify_payment` UX) |

---

## 8. Effort Estimation

> Single mid-senior WordPress/PHP developer. Bootstrap 5 UI consistent with existing style. Manual QA only.

| Phase | Description | File(s) Touched | Est. Hours |
|-------|-------------|-----------------|-----------|
| 1 | Admin menu restructure + Payment Methods page | `paytabs_admin.php` | **5 h** |
| 2 | DB table creation + academy table ALTER | `retreat_paytabs_integration.php`, `tanafs_academy.php` | **3 h** |
| 3 | Shared `submit_bank_transfer` handler + file upload | `retreat_paytabs_integration.php` | **7 h** |
| 4 | Per-module approval/rejection AJAX (3 dashboard files) | 3 new dashboard files | **8 h** |
| 5 | Therapy bank transfer frontend + AJAX (incl. Payment Selection Modal) | `therapy_paytabs_integration.php`, `Therapy_group_admin_dashboard.php` | **10 h** |
| 6 | Retreat bank transfer frontend + AJAX (incl. Payment Selection Modal) | `retreat_paytabs_integration.php` | **7 h** |
| 7 | Academy bank transfer frontend + AJAX (incl. Payment Selection Modal) | `tanafs_academy.php` | **7 h** |
| 8 | Admin dashboard UI × 3 (table, filter, details) | 3 new dashboard files | **9 h** |
| 9 | Testing & hardening (incl. modal + language + redirect tests) | — | **5 h** |
| | **TOTAL** | | **60 h** |

### Key Assumptions

- All integration work goes **into existing files** — no new architectural layers introduced.
- The 3 new admin dashboard files are purely UI + their own AJAX handlers. No shared base.
- `submit_bank_transfer` shared handler in `retreat_paytabs_integration.php` is the highest-risk single item; built first (Phase 3).
- `tanafs_academy.php` PayTabs payment block (lines 2487–2928) is left entirely untouched.
- Registration + auto-login logic in Therapy and Retreat stays unchanged; bank transfer mirrors the same `wp_create_user` / `wp_set_auth_cookie` sequence already in the IPN processors.
- BuddyPress enrollment for bank transfer is triggered by admin approval — deferred from the existing IPN-triggered path.
- Bilingual (Arabic/English) content for the Payment Selection Modal and success messages **is included** in the 60 h estimate. Email templates remain single-language unless separately requested.

### Suggested Delivery Order

**Week 1:** Phase 1 → Phase 2 → Phase 3 → Phase 4  
**Week 2:** Phase 5 → Phase 6 → Phase 7 → Phase 8 → Phase 9
