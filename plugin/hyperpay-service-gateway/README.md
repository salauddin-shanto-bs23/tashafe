# HyperPay Service Gateway

Standalone HyperPay (OPPWA Copy and Pay) payment engine for WordPress without WooCommerce.

## Features

- Generic service payment flow for any use case (courses, sessions, tickets, etc.)
- Secure server-side verification before final status transitions
- Webhook endpoint with HMAC signature validation
- Idempotent transaction updates
- Unified action hook for external business logic
- Built-in shortcode payment form
- Admin settings and transactions pages
- REST API endpoints for create/verify/query

## Installation

1. Upload plugin zip from WordPress admin.
2. Activate plugin.
3. Go to HyperPay Gateway -> Settings.
4. Configure:
- Entity ID
- Access Token
- Test Mode
- Currency
- Shared Secret

## Shortcode

Use the built-in form:

```text
[hyperpay_form amount="199.00" currency="SAR" service_type="course" reference_id="COURSE-101"]
```

### Field behavior

- Required form fields: name, email, country, street
- Optional form fields: phone, city
- Hidden context fields from shortcode attrs: service_type, reference_id, amount, currency

### Customize form fields

```php
add_filter('hyperpay_form_fields', function($fields) {
    $fields['phone']['required'] = true;
    return $fields;
});
```

## Unified Hook

Use one hook for all final statuses:

```php
add_action('hyperpay_payment_processed', function($transaction) {
    if ($transaction->status === 'success') {
        // Enroll user / unlock service
    } elseif ($transaction->status === 'failed') {
        // Handle failure
    } elseif ($transaction->status === 'cancelled') {
        // Handle cancellation
    }
});
```

## Helper Functions

```php
$transaction = hyperpay_get_transaction(12);
$transaction2 = hyperpay_get_transaction_by_checkout_id('8ac7a4c...');
$list = hyperpay_get_transactions_by_email('user@example.com');

if (hyperpay_is_payment_successful($transaction)) {
    // success path
}

if (hyperpay_is_payment_failed($transaction)) {
    // failed/cancelled path
}
```

## REST API

### Create payment

- Method: POST
- URL: /wp-json/hyperpay/v1/create-payment

Example payload:

```json
{
  "amount": 149.00,
  "currency": "SAR",
  "service_type": "therapy",
  "service_reference_id": "THERAPY-555",
  "name": "John Doe",
  "email": "john@example.com",
  "phone": "+966500000000",
  "country": "SA",
  "city": "Riyadh",
  "street": "King Fahd Road"
}
```

### Verify payment

- Method: GET
- URL: /wp-json/hyperpay/v1/verify-payment
- Query params: checkout_id, transaction_id, resource_path

### Get transaction

- Method: GET
- URL: /wp-json/hyperpay/v1/transaction/{id}

### Webhook

- Method: POST
- URL: /wp-json/hyperpay/v1/webhook
- Signature header: x-hyperpay-signature (or x-signature)
- Signature algorithm: HMAC SHA-256 over raw body using configured shared secret

## Transaction Table

Table name: wp_hyperpay_transactions (with site prefix)

Core columns:
- id
- transaction_id
- checkout_id
- merchant_transaction_id
- amount
- currency
- status
- customer_name
- customer_email
- customer_phone
- billing_country
- billing_city
- billing_street
- service_type
- service_reference_id
- created_at
- updated_at

## Notes

- The plugin never trusts frontend redirect alone.
- Successful completion requires server-side verification and integrity checks.
- Integrity checks include checkout_id, merchant_transaction_id (if present), amount, and currency.
