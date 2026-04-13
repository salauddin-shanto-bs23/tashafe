# Tashafe Knowledge Transfer(KT)

## 1. Objective

This document discusses some of the core features developed for the Tashafe project using WordPress code snippets from wp-admin.

This document uses snippet names exactly as used in wp-admin and focuses on:
- responsibilities per snippet,
- how the full registration and payment lifecycle works,
- where to make changes safely,
- operational procedures and troubleshooting.

## 2. Snippet Inventory (wp-admin Names)

1. Therapy group registration
- Therapy-group admin dashboard
- User Registration

2. Retreat registration
- Retreat Group Management
- Retreat System
- Retreat Group Buttons Active Inactive

3. Tanafs Academy registration
- Tanafs Academy

4. HyperPay payment
- Payment Integration

5. Buddypress UI
- BuddyPress Groups visibility
- BuddyPress chat ui styling
- BuddyPress /en/groups Redirect

6. Store pages
- Store Page with ajax product filtering by category
- WooCommerce default pages colors

7. Add article page string translation
- Add your article arabic


## 3. Platform-Level Architecture

### 3.1 Functional Split

- Registration snippets handle UI, validation, and booking/session data collection.
- Payment Integration handles centralized payment orchestration and status lifecycle.
- Fulfillment handlers run only after verified successful payment.
- BuddyPress enrollment is tied to successful fulfillment for paid flows.

### 3.2 Golden Rule

Payment success must be confirmed server-side before confirming booking.

Never treat browser redirect as final payment truth.

## 4. Domain-Wise discussion

## 4.1 Therapy Group Registrations

Primary snippets:
- Therapy-group admin dashboard
- User Registration

Responsibilities:
- Therapy group creation and management.
- Therapy registration for guest and logged-in users.
- Group assignment and enrollment into BuddyPress chat group.
- Post-payment fulfillment for therapy bookings.

Key operational flow:
1. User submits therapy registration details.
2. Booking session is saved.
3. Payment is initiated through Payment Integration.
4. On verified success, user/group assignment + chat enrollment is completed.

Important note:
- Keep therapy fulfillment behavior idempotent, so duplicate callback/verify attempts do not duplicate user actions.

## 4.2 Retreat Programs

Primary snippets:
- Retreat Group Management
- Retreat System
- Retreat Group Buttons Active Inactive

Responsibilities:
- Retreat group CRUD/admin settings.
- Retreat registration UI and package/schedule selection.
- Payment and post-payment registration finalization.
- Retreat BuddyPress chat enrollment.
- Inactive state handling and user messaging.

Key operational flow:
1. User selects retreat type/schedule and submits registration.
2. Booking data is stored.
3. Payment is initiated.
4. Payment is verified server-side.
5. Registration is finalized and user is placed in the proper retreat flow/chat.

Important note:
- Retreat completion can include extra completion steps after payment verification; keep that sequence intact.

## 4.3 Tanafs Academy

Primary snippet:
- Tanafs Academy

Responsibilities:
- Academy admin management and frontend program registration UI.
- Bilingual display/labels.
- Program registration persistence and post-payment completion.

Key operational flow:
1. User selects academy program and submits registration details.
2. Payment flow runs through Payment Integration.
3. Verified payment triggers academy registration completion.

Important note:
- Keep academy registration semantics as currently implemented; do not force therapy/retreat assumptions onto academy logic.

## 4.4 HyperPay Shared Payments

Primary snippet:
- Payment Integration

Responsibilities:
- HyperPay credentials and mode setup.
- Checkout initiation endpoints.
- Unified verify endpoints per booking type.
- Callback/webhook handling.
- Idempotent payment status transitions.
- Triggering booking fulfillment only after verified success.
- Payment analytics/listing in All Payments.

Status lifecycle:
- pending -> complete
- pending -> failed

Do not allow:
- complete status from frontend redirect alone.
- fulfillment before verified complete.

## 4.5 Chat system (BuddyPress)

Primary snippets:
- BuddyPress Groups visibility
- BuddyPress chat ui styling
- BuddyPress /en/groups Redirect (Polylang Fix)

Responsibilities:
- Group visibility rules.
- Chat UI presentation adjustments.
- Language/path redirect correction for group pages in multilingual setup.

Note:
- Keep multilingual behavior consistent between Arabic and English group access.

## 4.6 Store (WooCommerce)

Primary snippets:
- Store Page with ajax product filtering by category(Custom store page)
- WooCommerce pages colors(for changing woocommerce plugin page ui colors)

Responsibilities:
- Custom storefront filtering UX (AJAX category filtering).
- Consistent Tashafe styling/theme adaptation for WooCommerce pages.

Note:
- Keep custom filtering behavior and color/UI consistency aligned with current front-end expectations.

## 4.7 Content Submission (USP)

Primary domain:
- USP plugin settings(for post/article submission configuration)

Responsibilities:
- Configure submitted posts behavior.
- Configure email alert target(s) and change email address for post submission email.
- Configure custom fields and frontend display order.

Operational settings shortcuts:
- Settings > Submitted Posts
- Plugin Settings > Email Alerts
- Plugin Settings > Custom Field 1(or Custom Field 2)
- Front-end Display

## 4.8 Translation Item

Primary snippet:
- Add your article arabic (for social media link label translation)

Responsibility:
- Ensure target add-article labels are localized for Arabic UX consistency.

## 5. Unified Payment and Fulfillment Sequence

1. Registration snippet saves booking/session context.
2. Payment Integration creates pending payment record and creates HyperPay checkout.
3. User completes payment in HyperPay flow.
4. Verify endpoint and/or callback validates payment server-to-server.
5. Payment status transitions idempotently.
6. Fulfillment logic executes only when status is complete.
7. User sees completion/redirect UX after fulfillment.

## 6. wp-admin Operating Guide

## 6.1 HyperPay Setup

Menu:
- Payment Integration > Payment Configuration

Set:
- HyperPay Entity ID
- HyperPay Access Token
- Mode (Sandbox/Production)
- Currency
- Payment method enable/disable toggles

## 6.2 Payment Monitoring

Menu:
- Payment Integration > All Payments

Use:
- booking type filter
- payment status filter
- search and analytics views

## 6.3 Retreat Operations

Menu:
- Retreat Dashboard
- Group Display Settings

## 6.4 Therapy Waiting List Operations

Menu:
- Therapy Group Waiting List

## 7. Safe Change Boundaries

1. Do not alter booking semantics for therapy, retreat, and academy flows.
2. Do not confirm bookings on redirect-only success.
3. Do not bypass server-to-server verification.
4. Keep callback and verify handlers idempotent.
5. Keep payment status transitions auditable and deterministic.
6. Keep fulfillment post-verify only.

## 8. Troubleshooting Guide

## 8.1 Payment remains pending
- Recheck HyperPay credentials and environment mode.
- Confirm verify endpoint is receiving required payment references.
- Confirm callback/webhook is reachable.
- Review payment logs around initiation, verify, callback, and transition.

## 8.2 Payment completed but registration not finalized
- Confirm payment status is complete in All Payments.
- Check fulfillment stage logs for the booking type.
- Confirm booking/session context still exists at finalization time.

## 8.3 Therapy or retreat user not in chat group
- Confirm BuddyPress groups are active.
- Confirm enrollment flow runs after verified payment.
- Confirm the correct target group mapping and visibility rules.


Prepared on: 2026-04-10
Project: Tashafe
