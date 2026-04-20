# HyperPay Integration in Tanafs (Implementation Walkthrough)

This document explains how HyperPay (OPPWA Copy and Pay) is implemented in Tanafs and how to use this implementation as a foundation for a reusable WordPress plugin.

## 1) High-Level Architecture

Tanafs uses a single shared payment orchestration file:
- payment_integration.php

Business fulfillment logic stays in module files:
- therapy/User_Registration.php
- retreat/retreat_system.php
- tanafs_academy/tanafs_academy.php

Core pattern:
1. Booking data is staged in transient storage per module.
2. Checkout initiation creates a pending payment row first.
3. HyperPay checkout is created server-side.
4. Shopper is redirected to hosted payment widget.
5. Payment status is verified server-to-server.
6. Status transition is applied idempotently.
7. Fulfillment is executed only after verified complete status.

## 2) Database Layer

Payments table: wp_tanafs_payments (created/upgraded by payment_integration.php).

Important fields used by HyperPay flow:
- booking_token
- booking_reference
- booking_type
- hyperpay_checkout_id
- hyperpay_entity_id
- transaction_id
- payment_status (pending, complete, failed)
- status (compatibility mirror)
- response_data
- updated_at

Compatibility is intentionally preserved for APS-era fields and reads.

## 3) Admin Configuration

Admin menu: Payment Integration.

Config options stored as wp_options:
- tanafs_hyperpay_entity_id
- tanafs_hyperpay_access_token
- tanafs_hyperpay_mode (sandbox or live)
- tanafs_hyperpay_currency
- tanafs_hyperpay_force_external_test_mode
- tanafs_hyperpay_sandbox_minimal_payload
- tanafs_hyperpay_enable_card
- tanafs_hyperpay_enable_tamara
- tanafs_hyperpay_enable_applepay

Migration compatibility mirrors:
- tanafs_aps_mode
- tanafs_aps_currency

Displayed endpoints in admin:
- Return URL: /payment-return/
- Webhook URL: /payment-callback/

## 4) Endpoint and Routing Model

Rewrite/query var endpoints:
- /payment-return/
- /payment-callback/
- /payment-widget/

Key handlers:
- tanafs_render_payment_widget_page
- tanafs_handle_payment_callback
- tanafs_ajax_verify_payment_status

## 5) Step-by-Step Payment Flow

### Step 1: Stage booking data before payment

Each module stores booking payload in transient storage and returns a booking token.

Examples:
- Therapy: save_therapy_booking_data
- Retreat: save_retreat_booking_data
- Academy: stores transient in tanafs_initiate_academy_payment

### Step 2: Frontend initiates checkout via AJAX

Module JS calls module-specific initiation action:
- tanafs_initiate_therapy_payment
- tanafs_initiate_retreat_payment
- tanafs_initiate_academy_payment

Payload includes booking token, nonce, payment method, and return page URL as needed.

### Step 3: Server validates nonce and loads staged booking data

Each initiation handler:
- validates nonce
- fetches transient booking data
- normalizes selected payment method
- builds customer details structure

### Step 4: Create pending payment record first

All initiation handlers call:
- tanafs_hyperpay_create_checkout

Inside this function:
- a unique merchant transaction id is generated
- tanafs_insert_pending_payment is called before gateway request

This enforces the core security rule: no checkout request without a pending DB record.

### Step 5: Build HyperPay checkout request

Request goes to:
- {base_url}/v1/checkouts

Includes:
- entityId
- amount
- currency
- paymentType=DB
- merchantTransactionId
- customer and billing fields

Method-specific behavior:
- card => brands: MADA VISA MASTER
- tamara => brands: TAMARA + Tamara details API step
- applepay => brands: APPLEPAY

### Step 6: Persist checkout id and return frontend contract

On success:
- hyperpay_checkout_id is saved to payment row
- response_data is stored
- widget_url is generated
- hosted_checkout_url is generated

Frontend response contains:
- gateway=hyperpay
- checkout_id
- widget_url
- result_url
- brands
- hosted_checkout_url
- transaction_id

### Step 7: Shopper pays on hosted widget page

Preferred path is redirecting to hosted_checkout_url.

Hosted page behavior:
- validates booking token and checkout id against DB
- loads paymentWidgets.js
- renders form with selected brands
- posts shopper result back to result_url

### Step 8: Shopper return does not finalize payment by itself

Module frontend reads URL params:
- payment_return
- resourcePath/resource_path/resourcepath
- id/checkoutId/checkout_id

Then calls verify action:
- tanafs_verify_therapy_payment
- tanafs_verify_retreat_payment
- tanafs_verify_academy_payment

### Step 9: Server-side verification

Shared verify handler resolves payment row and calls:
- tanafs_hyperpay_verify_payment

Verification sources:
1. resourcePath endpoint when present
2. checkout endpoint fallback (/v1/checkouts/{id}/payment)
3. query fallback by merchantTransactionId for specific no-session code paths

Result code mapping:
- success-like codes => complete
- async/intermediate codes => pending
- others => failed

### Step 10: Idempotent status transition

Transition is applied via:
- tanafs_apply_payment_transition

Rules:
- keeps status and payment_status in sync
- preserves old merchant id in booking_reference when replacing transaction_id with HyperPay payment id
- logs status transition event

### Step 11: Fulfillment only after verified complete

When status becomes complete:
- tanafs_fulfill_booking_from_ipn is called

Routing:
- therapy => process_therapy_booking_from_ipn
- retreat => process_retreat_booking_from_ipn
- academy => process_academy_booking_from_ipn

No fulfillment is executed for pending or failed statuses.

### Step 12: Webhook callback path (asynchronous)

/payment-callback/ handler:
- parses JSON body and fallback request params
- resolves payment by booking token, type, transaction, or checkout id
- verifies via server-side verification function
- applies transition
- triggers fulfillment only if complete

Also idempotent:
- already complete rows short-circuit safely

## 6) Logging and Observability

Structured logging table:
- wp_tanafs_payment_logs

Key events used:
- initiation_requested
- initiation_created
- verification_requested
- verification_gateway_response
- verification_result
- webhook_received
- webhook_verified
- status_transition
- fulfillment_result
- widget_client_event
- widget_hosted_event

Correlation fields commonly logged:
- booking_token
- booking_type
- checkout_id
- transaction_id
- hyperpay_request_id

Admin All Payments view reads payment rows and diagnostics from these logs.

## 7) Frontend Integration Contracts Per Module

Therapy:
- Initiation actions: tanafs_initiate_therapy_payment, tanafs_initiate_therapy_payment_logged_in
- Verify action: tanafs_verify_therapy_payment
- Reads return params and retries verification

Retreat:
- Initiation action: tanafs_initiate_retreat_payment
- Verify action: tanafs_verify_retreat_payment
- Uses pending retry window and long polling style retries

Academy:
- Initiation action: tanafs_initiate_academy_payment
- Verify action: tanafs_verify_academy_payment
- Opens modal state and retries verification on pending

## 8) Reusable Plugin Blueprint (How to Extract)

To make this site-agnostic, keep payment orchestration generic and expose extension points.

### A. Core plugin modules

1. HyperPay Settings
- admin form
- secure option storage
- method toggles

2. Payment Repository
- table create/upgrade
- find by token, checkout id, transaction id
- status transition API

3. HyperPay Client
- checkout creation
- status verification by resourcePath/checkout
- query fallback
- result code mapper

4. Endpoint Controller
- hosted widget endpoint
- callback endpoint
- shared verification endpoint

5. Fulfillment Router Interface
- register booking-type handlers via hooks
- call handler only when payment is complete

6. Logging Service
- structured event writer
- optional log retention settings

### B. WordPress integration surface

Expose filters/actions such as:
- filter for customer data normalization
- filter for return URL strategy
- filter for request body enrichment per payment method
- action on successful transition to complete
- action on fulfillment success/failure

### C. Site-specific code should remain outside plugin

Per-site/module code should only do:
- stage booking data
- call initiate endpoint
- handle return page UX
- implement fulfillment function

Plugin should not encode therapy/retreat/academy business rules.

## 9) Security Rules Already Enforced (Keep in Plugin)

- Frontend redirect is not trusted as payment truth.
- Pending DB row is created before checkout call.
- Verification is server-to-server.
- Callback and return verification are idempotent.
- Complete status is required before fulfillment.
- Nonce checks are enforced for AJAX endpoints.
- Inputs are sanitized before use and logging.

## 10) Implementation Checklist for Reusable Plugin

1. Build generic payment table + additive migrations.
2. Implement HyperPay settings page.
3. Implement checkout creation with pending-first rule.
4. Implement hosted widget endpoint.
5. Implement verification service (resourcePath + fallback strategy).
6. Implement callback endpoint and idempotent transitions.
7. Implement fulfillment hook system for site-specific modules.
8. Add admin payments list + diagnostics.
9. Add structured logs with correlation ids.
10. Validate with at least three flows: success, pending-delay, failed.

## 11) Source Map for This Documentation

Shared orchestration:
- payment_integration.php

Module-side initiation/return UX:
- therapy/User_Registration.php
- retreat/retreat_system.php
- tanafs_academy/tanafs_academy.php

Module fulfillment handlers:
- therapy/User_Registration.php (process_therapy_booking_from_ipn)
- retreat/retreat_system.php (process_retreat_booking_from_ipn)
- tanafs_academy/tanafs_academy.php (process_academy_booking_from_ipn)
