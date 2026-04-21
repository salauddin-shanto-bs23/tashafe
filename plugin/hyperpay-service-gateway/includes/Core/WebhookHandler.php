<?php
/**
 * Webhook handler.
 *
 * @package HyperPayServiceGateway\Core
 */

namespace HyperPayServiceGateway\Core;

use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles webhook verification and status transitions.
 */
class WebhookHandler {

    /**
     * Payment service.
     *
     * @var PaymentService
     */
    protected $payment_service;

    /**
     * Logger callback.
     *
     * @var callable|null
     */
    protected $logger;

    /**
     * Constructor.
     *
     * @param PaymentService $payment_service Payment service.
     * @param callable|null  $logger Logger callback.
     */
    public function __construct(PaymentService $payment_service, $logger = null) {
        $this->payment_service = $payment_service;
        $this->logger = is_callable($logger) ? $logger : null;
    }

    /**
     * Validate webhook authenticity.
     *
     * Uses shared secret + HMAC signature validation when available.
     *
     * @param string $raw_body Raw request body.
     * @param array  $headers Request headers.
     * @return bool
     */
    public function is_authentic($raw_body, $headers = array()) {
        $settings = hyperpay_service_gateway_get_settings();
        $secret = sanitize_text_field((string) ($settings['shared_secret'] ?? ''));

        if (empty($secret)) {
            return false;
        }

        $headers = is_array($headers) ? $headers : array();
        $normalized_headers = array();
        foreach ($headers as $key => $value) {
            $normalized_headers[strtolower((string) $key)] = $value;
        }

        $provided = $this->read_header($normalized_headers, 'x-hyperpay-signature');
        if ('' === $provided) {
            $provided = $this->read_header($normalized_headers, 'x-signature');
        }

        if (empty($provided)) {
            return false;
        }

        $provided = trim($provided);
        $expected = hash_hmac('sha256', (string) $raw_body, $secret);

        return hash_equals($expected, $provided);
    }

    /**
     * Read header value from normalized header map.
     *
     * @param array  $headers Headers.
     * @param string $key Header key.
     * @return string
     */
    protected function read_header($headers, $key) {
        if (!isset($headers[$key])) {
            return '';
        }

        $value = $headers[$key];

        if (is_array($value)) {
            if (empty($value[0])) {
                return '';
            }
            return trim((string) $value[0]);
        }

        return trim((string) $value);
    }

    /**
     * Process webhook payload.
     *
     * @param array $payload Webhook payload.
     * @return array|WP_Error
     */
    public function process($payload) {
        $payload = is_array($payload) ? $payload : array();

        $resource_path = sanitize_text_field((string) ($payload['resourcePath'] ?? ($payload['resource_path'] ?? '')));
        $checkout_id = sanitize_text_field((string) ($payload['id'] ?? ($payload['checkoutId'] ?? '')));
        $merchant_transaction_id = sanitize_text_field((string) ($payload['merchantTransactionId'] ?? ''));

        $transaction = null;

        if (!empty($merchant_transaction_id)) {
            $transaction = $this->payment_service->get_transaction_by_merchant_transaction_id($merchant_transaction_id);
        }

        if (!$transaction && !empty($checkout_id)) {
            $transaction = $this->payment_service->get_transaction_by_checkout_id($checkout_id);
        }

        if (!$transaction) {
            return new WP_Error('transaction_not_found', 'Unable to resolve transaction for webhook payload.');
        }

        if ('success' === sanitize_key((string) $transaction->status)) {
            return array(
                'success' => true,
                'idempotent' => true,
                'status' => 'success',
                'id' => (int) $transaction->id,
            );
        }

        $this->log('info', 'webhook_received', array(
            'id' => (int) $transaction->id,
            'checkout_id' => $checkout_id,
            'merchant_transaction_id' => (string) $transaction->merchant_transaction_id,
        ));

        $verification = $this->payment_service->verifyPayment(
            !empty($checkout_id) ? $checkout_id : (string) $transaction->checkout_id,
            array(
                'resource_path' => $resource_path,
                'merchant_transaction_id' => (string) $transaction->merchant_transaction_id,
                'allow_query_fallback' => true,
            )
        );

        if (empty($verification['success'])) {
            return new WP_Error(
                'verification_failed',
                sanitize_text_field((string) ($verification['message'] ?? 'Webhook verification failed.'))
            );
        }

        $result = $this->payment_service->apply_verified_result($transaction, $verification, 'webhook');

        if (empty($result['success'])) {
            return new WP_Error('status_update_failed', sanitize_text_field((string) ($result['message'] ?? 'Status update failed.')));
        }

        $this->log('info', 'webhook_verified', array(
            'id' => (int) $transaction->id,
            'status' => (string) ($verification['payment_status'] ?? 'pending'),
            'result_code' => (string) ($verification['result_code'] ?? ''),
        ));

        return array(
            'success' => true,
            'id' => (int) $transaction->id,
            'status' => (string) ($verification['payment_status'] ?? 'pending'),
            'result_code' => (string) ($verification['result_code'] ?? ''),
        );
    }

    /**
     * Log helper.
     *
     * @param string $level Level.
     * @param string $message Message.
     * @param array  $context Context.
     * @return void
     */
    protected function log($level, $message, $context = array()) {
        if (is_callable($this->logger)) {
            call_user_func($this->logger, $level, $message, $context);
        }
    }
}
