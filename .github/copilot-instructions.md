# Tanafs HyperPay Migration Instructions

## Project Context

This repository contains WordPress PHP snippets (not full plugins) for three booking systems:

- Therapy booking
- Retreat booking
- Tanafs Academy registration

Current shared payment orchestration is implemented in [payment_integration.php](payment_integration.php).

The existing booking fulfillment logic in module files must stay intact:

- [therapy/User_Registration.php](therapy/User_Registration.php)
- [retreat/retreat_system.php](retreat/retreat_system.php)
- [tanafs_academy/tanafs_academy.php](tanafs_academy/tanafs_academy.php)

Only gateway-related behavior is migrated from APS to HyperPay.

## Primary Objective

Replace Amazon Payment Services (APS) with HyperPay (OPPWA Copy and Pay) while preserving existing booking state transitions and post-payment fulfillment handlers.

## Implementation Protocol

Follow this order during implementation to minimize regressions:

1. Configuration and schema compatibility updates in [payment_integration.php](payment_integration.php).
2. HyperPay checkout creation helpers and shared verification helpers.
3. Webhook/callback handling and idempotent status transitions.
4. Minimal frontend integration updates in module files.
5. Admin listing and observability updates.
6. End-to-end validation per booking type.

Do not change multiple phases in one large commit when avoidable.

## Required Payment Security Rules

All implementations must enforce these rules:

1. Never trust frontend redirect as payment truth.
2. Create payment record as `pending` before checkout creation.
3. Verify payment server-to-server using HyperPay status endpoint.
4. Support webhook/IPN and verify authenticity/signature mechanism supported by HyperPay integration pattern.
5. Apply idempotency on all callbacks and verification updates.
6. Update status to `complete` only after verified success.
7. Trigger booking confirmation only after verified success.

## File Structure Rules

### Shared payment file

Keep shared gateway logic in [payment_integration.php](payment_integration.php), including:

- Payment config admin page
- All payments listing
- Checkout initiation APIs
- Webhook endpoint handling
- Verification helpers
- Booking type routing (`therapy`, `retreat`, `academy`)

### Module files

Do not rewrite booking business logic in module files. Only adjust minimal integration points when needed:

- Frontend initiation flow should consume HyperPay checkout/session response.
- Redirect return pages must call server verification endpoint.
- Keep existing function names and signatures for fulfillment handlers unchanged.

## Current APS Functions To Replace (Reference)

Replace APS-specific parts but preserve function boundaries where feasible:

- `tanafs_aps_is_configured`
- `tanafs_aps_get_endpoint`
- `tanafs_aps_calculate_signature`
- `tanafs_aps_initiate_payment`
- `tanafs_handle_payment_callback`
- APS options in configuration page (`tanafs_aps_*`)

Keep existing fulfillment router contract:

- `tanafs_fulfill_booking_from_ipn`
- `process_therapy_booking_from_ipn`
- `process_retreat_booking_from_ipn`
- `process_academy_booking_from_ipn`

Do not rename these functions or alter their booking semantics.

## Database Guidance

Use/upgrade `wp_tanafs_payments` schema to include HyperPay fields:

- `booking_reference`
- `hyperpay_checkout_id`
- `transaction_id` (HyperPay payment ID)
- `status` as `pending|complete|failed`
- `updated_at`

Avoid destructive migrations. Prefer additive `ALTER TABLE` and backwards-compatible reads.

When old APS-era fields remain, keep them readable until migration sign-off is complete.

## Coding Standards

- Follow WordPress coding standards and secure coding practices.
- Sanitize all `$_POST`/`$_GET`/headers.
- Use `$wpdb->prepare()` for all dynamic SQL reads.
- Log structured payment events with consistent keys.
- Keep functions small and reusable; prefix with `tanafs_`.
- Do not introduce plugin dependencies for payments.

## Reliability and Observability

- Add structured logs for initiation, webhook receive, signature check, verification request, status transition, and fulfillment result.
- Include correlation identifiers (`booking_token`, `booking_type`, `checkout_id`, `transaction_id`).
- Ensure repeated webhook/verification calls are safe.

Recommended log event keys:

- `initiation_requested`
- `initiation_created`
- `verification_requested`
- `verification_result`
- `webhook_received`
- `webhook_verified`
- `status_transition`
- `fulfillment_result`

## Admin Requirements

Payment Integration admin must provide:

- HyperPay Entity ID
- HyperPay Access Token
- Mode (Sandbox/Production)
- Currency
- Webhook URL display

All Payments table must show:

- transaction_id
- name
- email
- phone
- booking_type
- payment_status
- amount
- date

Keep filters for booking type and payment status.

## Scope Guardrails

- Do not alter therapy/retreat/academy booking semantics.
- Do not confirm bookings on redirect alone.
- Do not remove idempotency checks.
- Do not delete historical payment data.
- Do not keep dual active gateway paths (APS/PayTabs/HyperPay) in final runtime flow.

## Acceptance Checklist (Per Phase)

Before marking a phase done, verify:

1. Security requirements are enforced (verification, webhook authenticity, idempotency).
2. Payment status transitions are consistent (`pending` -> `complete|failed`) and auditable.
3. Fulfillment happens only after verified success.
4. Therapy, retreat, and academy flows still use existing fulfillment contracts.
5. Admin configuration and payments listing remain usable.
