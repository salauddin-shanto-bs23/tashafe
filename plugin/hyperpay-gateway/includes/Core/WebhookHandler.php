<?php
/**
 * HyperPay webhook handler.
 *
 * @package HyperPayGateway\Core
 */

namespace HyperPayGateway\Core;

use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Processes webhook payloads and updates WooCommerce order status.
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
     * Process webhook payload.
     *
     * @param array $payload Webhook body.
     * @return array|WP_Error
     */
    public function process($payload) {
        if (!function_exists('wc_get_order')) {
            return new WP_Error('woocommerce_missing', 'WooCommerce is required.');
        }

        $this->payment_service->set_settings($this->get_gateway_settings());

        $payload = is_array($payload) ? $payload : array();

        $resource_path = sanitize_text_field(
            (string) ($payload['resourcePath'] ?? ($payload['resource_path'] ?? ''))
        );
        $checkout_id = sanitize_text_field((string) ($payload['id'] ?? ($payload['checkoutId'] ?? '')));
        $merchant_tx_id = sanitize_text_field((string) ($payload['merchantTransactionId'] ?? ''));

        $order_id = $this->resolve_order_id($payload, $merchant_tx_id, $checkout_id);

        if ($order_id <= 0) {
            return new WP_Error('order_not_found', 'Unable to resolve order for webhook payload.');
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('order_not_found', 'Order not found.');
        }

        $stored_checkout_id = (string) $order->get_meta('_hyperpay_checkout_id', true);
        $stored_merchant_tx = (string) $order->get_meta('_hyperpay_merchant_transaction_id', true);

        $verify = $this->payment_service->verifyPayment(
            !empty($checkout_id) ? $checkout_id : $stored_checkout_id,
            array(
                'resource_path' => $resource_path,
                'merchant_transaction_id' => !empty($merchant_tx_id) ? $merchant_tx_id : $stored_merchant_tx,
                'allow_query_fallback' => true,
            )
        );

        if (empty($verify['success'])) {
            $this->log('warning', 'Webhook verification failed', array(
                'order_id' => $order_id,
                'checkout_id' => $checkout_id,
            ));

            return new WP_Error(
                'verification_failed',
                sanitize_text_field((string) ($verify['message'] ?? 'Webhook verification failed.'))
            );
        }

        $status = sanitize_key((string) ($verify['payment_status'] ?? 'pending'));
        $result_code = sanitize_text_field((string) ($verify['result_code'] ?? ''));
        $transaction_id = sanitize_text_field((string) ($verify['transaction_id'] ?? ''));

        $order->update_meta_data('_hyperpay_last_result_code', $result_code);
        if (!empty($transaction_id)) {
            $order->update_meta_data('_hyperpay_transaction_id', $transaction_id);
        }

        if ('complete' === $status) {
            if (!$order->is_paid()) {
                $order->payment_complete($transaction_id);
            }
            $order->add_order_note(
                sprintf('HyperPay webhook verified payment. Result code: %s', $result_code)
            );
        } elseif ('failed' === $status) {
            $order->update_status('failed', 'HyperPay webhook marked payment as failed.');
        } else {
            if (!in_array($order->get_status(), array('on-hold', 'processing', 'completed'), true)) {
                $order->update_status('on-hold', 'HyperPay payment is pending confirmation.');
            }
        }

        $order->save();

        $this->log('info', 'Webhook processed', array(
            'order_id' => $order_id,
            'status' => $status,
            'result_code' => $result_code,
        ));

        return array(
            'success' => true,
            'order_id' => $order_id,
            'payment_status' => $status,
            'result_code' => $result_code,
        );
    }

    /**
     * Resolve order id from webhook context.
     *
     * @param array  $payload Payload.
     * @param string $merchant_tx_id Merchant transaction id.
     * @param string $checkout_id Checkout id.
     * @return int
     */
    protected function resolve_order_id($payload, $merchant_tx_id, $checkout_id) {
        $custom_params = array();
        if (!empty($payload['customParameters']) && is_array($payload['customParameters'])) {
            $custom_params = $payload['customParameters'];
        }

        $order_id = absint($custom_params['order_id'] ?? 0);

        if ($order_id > 0) {
            return $order_id;
        }

        if (!empty($merchant_tx_id) && preg_match('/WC_ORDER_(\d+)_/', $merchant_tx_id, $matches)) {
            return absint($matches[1]);
        }

        if (!empty($checkout_id) && function_exists('wc_get_orders')) {
            $orders = wc_get_orders(
                array(
                    'limit' => 1,
                    'return' => 'ids',
                    'meta_key' => '_hyperpay_checkout_id',
                    'meta_value' => $checkout_id,
                )
            );

            if (!empty($orders[0])) {
                return absint($orders[0]);
            }
        }

        return 0;
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

    /**
     * Load credentials from WooCommerce gateway settings.
     *
     * @return array
     */
    protected function get_gateway_settings() {
        $settings = get_option('woocommerce_hyperpay_settings', array());

        return array(
            'entity_id' => sanitize_text_field((string) ($settings['entity_id'] ?? '')),
            'access_token' => sanitize_text_field((string) ($settings['access_token'] ?? '')),
            'test_mode' => ('yes' === sanitize_text_field((string) ($settings['test_mode'] ?? 'yes'))),
        );
    }
}
