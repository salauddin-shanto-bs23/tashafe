# HyperPay Migration Plan and Task Tracker (APS to HyperPay)

## Objective

Replace APS gateway behavior with HyperPay (OPPWA Copy and Pay) while preserving existing booking fulfillment semantics for therapy, retreat, and academy.

## Scope Boundaries

- Shared gateway logic remains centralized in [payment_integration.php](../payment_integration.php).
- Booking fulfillment contracts remain intact:
  - [therapy/User_Registration.php](../therapy/User_Registration.php)
  - [retreat/retreat_system.php](../retreat/retreat_system.php)
  - [tanafs_academy/tanafs_academy.php](../tanafs_academy/tanafs_academy.php)
- Frontend redirect is never source of truth; only verified server-side payment can confirm bookings.

## Status Legend

- `not_started`: Task not yet started.
- `in_progress`: Task actively being implemented.
- `blocked`: Task cannot continue due to dependency or external blocker.
- `done`: Task completed and validated.

## How To Update This Tracker

1. Check the checklist item (`[ ]` -> `[x]`) only when acceptance criteria are met.
2. Update only `Status` as tasks progress (`not_started` -> `in_progress` -> `done`, or `blocked`).
3. Do not start a task until all IDs listed in `Depends On` are `done`.
4. If blocked, set `Status=blocked` and add a short blocker note in commit/PR text.

## Phase Gates

- Analysis complete before implementation tasks begin.
- Implementation complete before verification tasks begin.
- Verification complete before testing/sign-off tasks begin.

## Master Task Table

| Phase | Task ID | Checklist | Task Description | Depends On | Status |
|---|---|---|---|---|---|
| Analysis | A-01 | [x] | Confirm active APS and legacy PayTabs touchpoints in shared and module files. | - | done |
| Analysis | A-02 | [x] | Map exact function boundaries to preserve (`tanafs_fulfill_booking_from_ipn`, module `process_*_from_ipn`). | A-01 | done |
| Analysis | A-03 | [x] | Define HyperPay API mode matrix (sandbox/production URLs, auth model, status interpretation). | A-01 | done |
| Analysis | A-04 | [x] | Validate callback route strategy (`payment-callback`) and return verification strategy per module. | A-02,A-03 | done |
| Implementation - Config | I-01 | [x] | Replace APS admin options with HyperPay options (`entity_id`, `access_token`, `mode`, `currency`). | A-03 | done |
| Implementation - Config | I-02 | [x] | Update Payment Configuration UI labels/help text and webhook URL display for HyperPay. | I-01 | done |
| Implementation - Data | I-03 | [x] | Add additive schema fields to `wp_tanafs_payments` (`booking_reference`, `hyperpay_checkout_id`, compatibility-safe status fields). | A-02 | done |
| Implementation - Data | I-04 | [x] | Backward-compatible read/write handling for existing APS-era records. | I-03 | done |
| Implementation - Gateway | I-05 | [x] | Implement HyperPay configuration validator and endpoint helper replacements for APS helpers. | I-01,A-03 | done |
| Implementation - Gateway | I-06 | [x] | Implement checkout creation request to HyperPay and persist checkout ID. | I-03,I-05 | done |
| Implementation - Gateway | I-07 | [x] | Ensure payment row is created as `pending` before checkout creation and safely updated on failure. | I-03,I-06 | done |
| Implementation - Frontend | I-08 | [x] | Update therapy initiation consumer to use HyperPay response payload (Copy and Pay flow). | I-06 | done |
| Implementation - Frontend | I-09 | [x] | Update retreat initiation consumer to use HyperPay response payload (Copy and Pay flow). | I-06 | done |
| Implementation - Frontend | I-10 | [x] | Update academy initiation consumer to use HyperPay response payload (Copy and Pay flow). | I-06 | done |
| Verification - Server | V-01 | [x] | Implement server-side verification helper using HyperPay status endpoint. | I-06 | done |
| Verification - Server | V-02 | [x] | Wire return-flow verification endpoints to use shared HyperPay verification helper. | V-01,I-08,I-09,I-10 | done |
| Verification - Server | V-03 | [x] | Ensure only verified successful status triggers transition to `complete`. | V-01,V-02 | done |
| Verification - Webhook | V-04 | [x] | Replace APS callback parser with HyperPay webhook handler at shared callback endpoint. | I-05,V-01 | done |
| Verification - Webhook | V-05 | [x] | Implement webhook authenticity validation and rejected-request handling. | V-04 | done |
| Verification - Webhook | V-06 | [x] | Apply idempotency guard for webhook and verification race conditions. | V-03,V-04 | done |
| Verification - Fulfillment | V-07 | [x] | Trigger `tanafs_fulfill_booking_from_ipn` only once after verified success. | V-03,V-06 | done |
| Implementation - Admin | I-11 | [x] | Update All Payments list to show HyperPay transaction_id and required fields. | I-03 | done |
| Implementation - Admin | I-12 | [x] | Validate booking type and payment status filters with new status mapping. | I-11 | done |
| Verification - Observability | V-08 | [x] | Add structured log events and correlation keys (`booking_token`, `booking_type`, `checkout_id`, `transaction_id`). | I-06,V-04 | done |
| Testing - Functional | T-01 | [ ] | Therapy success flow: initiate -> pay -> verify -> fulfilled. | V-02,V-07 | blocked |
| Testing - Functional | T-02 | [ ] | Retreat success flow: initiate -> pay -> verify -> fulfilled. | V-02,V-07 | blocked |
| Testing - Functional | T-03 | [ ] | Academy success flow: initiate -> pay -> verify -> fulfilled. | V-02,V-07 | blocked |
| Testing - Reliability | T-04 | [ ] | Replay webhook (duplicate notification) and confirm no duplicate fulfillment. | V-06,V-07 | blocked |
| Testing - Reliability | T-05 | [ ] | Verify return-before-webhook and webhook-before-return both converge to one final state. | V-06,V-07 | blocked |
| Testing - Security | T-06 | [ ] | Confirm invalid webhook auth/signature paths are rejected and logged. | V-05,V-08 | blocked |
| Testing - Admin/Data | T-07 | [ ] | Confirm pending-before-checkout persistence and final status transitions in DB/admin view. | I-07,I-11,I-12 | blocked |
| Sign-off | S-01 | [ ] | Regression check that therapy/retreat/academy fulfillment semantics are unchanged. | T-01,T-02,T-03 | blocked |
| Sign-off | S-02 | [ ] | Disable or remove obsolete APS-only gateway paths in shared flow. | S-01,T-04,T-05,T-06 | blocked |
| Sign-off | S-03 | [ ] | Final migration sign-off and production rollout checklist approval. | S-02,T-07 | blocked |

## High-Risk Areas To Monitor

- Mixed gateway code paths (APS + legacy PayTabs remnants) causing split state handling.
- Callback and return race conditions causing duplicate fulfillment if idempotency is incomplete.
- Incorrect status mapping from HyperPay response model to internal `pending/complete/failed` states.


## Definition of Done

1. HyperPay checkout creation, verification, and webhook handling are active in shared payment flow.
2. Payment status updates are idempotent and only mark `complete` after verified success.
3. Booking fulfillment triggers only after verified success and remains behaviorally unchanged.
4. Admin configuration and All Payments pages reflect HyperPay fields and workflows.
5. All tasks `S-01` to `S-03` are `done`.
