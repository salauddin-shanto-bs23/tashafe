# HyperPay Migration Issue Report

Date: 2026-03-25
Prepared by: Engineering
Project: Tanafs payment gateway migration (APS to HyperPay Copy and Pay)

## Executive Summary
The migration is functionally close to complete, but retreat payment completion is blocked by an upstream behavior where checkout submission appears to happen while no transaction is materialized on HyperPay for some attempts.

Main symptom:
- Verification returns result code 200.300.404 (no payment session found) repeatedly after shopper submit.
- No corresponding transaction appears in HyperPay portal for those attempts.

This indicates a provider-side processing or routing gap, or an account/entity setup mismatch, rather than only local frontend flow issues.

## What Is Working
1. Checkout creation succeeds and returns checkout_id.
2. Copy and Pay widget loads successfully.
3. Client submit callbacks fire (including before-submit hooks).
4. Return redirect includes payment_return and checkout identifiers.
5. System keeps payment status transitions idempotent and does not falsely confirm bookings from redirect alone.

## Current Blocking Issues
1. Session not found after submit:
- Server verification receives 200.300.404 repeatedly.
- Query fallback can also show no materialized transaction.

2. Missing provider-side transaction record:
- For affected attempts, no payment appears in HyperPay portal.
- This blocks completion verification and therefore blocks fulfillment.

3. Environment/account uncertainty:
- Potential mismatch between checkout entity/account path and verification context.
- Possible brand/entity capability configuration gap.

## Technical Risk
1. Business flow risk:
- Users can complete card input but remain in pending verification loops.

2. Support/ops risk:
- Team cannot finalize affected bookings because source of truth verification is missing.

3. Conversion risk:
- Drop-off and support tickets increase while payment confidence drops.

## Mitigations Already Implemented
1. Resource path hardening:
- Robust parsing and normalization of resourcePath (including encoded variants).

2. Verification fallback hardening:
- When resourcePath returns 200.300.404, fallback verification via checkout endpoint is attempted.

3. Entity consistency:
- Entity ID used at checkout creation is now persisted per payment and reused during verification.

4. Observability improvements:
- Added request correlation fields in logs including mode, entity suffix, and HyperPay request IDs.
- Added admin diagnostics in All Payments to display key verification traces.

5. Safety preserved:
- Pending -> complete or failed transitions remain server-verified and idempotent.
- Fulfillment still executes only on verified complete status.

## Evidence Snapshot (Latest Example)
- booking_token: e97c0c3642ad88fa48c109f59456ef4a
- merchant transaction id: TANAFS_RETREAT_1774428423_6463
- checkout_id: EA8AC028719B9A98135FFBC5EEB7F26D.uat01-vm-tx03
- widget_before_submit_card: present
- verification result: 200.300.404 repeatedly
- HyperPay portal transaction: not visible for the affected attempts

## What Help Is Needed From PM
1. Provider escalation ownership:
- Open priority support case with HyperPay including request IDs and timeline.

2. Account/config verification:
- Confirm with HyperPay account manager that entity, brand routing, and Copy and Pay permissions are fully enabled for this flow.

3. Operational contingency:
- Approve temporary customer support playbook for pending states during investigation.

4. Coordination:
- Align with business stakeholders on temporary expectations for retreat payment completion timelines.

## Requested PM Actions (Immediate)
1. Approve and send escalation package to HyperPay support today.
2. Request provider response SLA and named technical contact.
3. Confirm whether test card/brand matrix used in QA matches enabled brands on the configured entity.
4. Share escalation updates daily until provider confirms root cause and fix.

## Recommended Communication to Stakeholders
- Migration is still controlled and safe (no false confirmations).
- The blocker is isolated to upstream payment materialization on specific attempts.
- Engineering has implemented defensive verification and diagnostics; remaining resolution requires provider-level validation.
