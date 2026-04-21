<?php
/**
 * REST routes.
 *
 * @package HyperPayServiceGateway\API
 */

namespace HyperPayServiceGateway\API;

use HyperPayServiceGateway\Core\PaymentService;
use HyperPayServiceGateway\Core\WebhookHandler;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers REST endpoints.
 */
class RestRoutes {

    /**
     * Payment service.
     *
     * @var PaymentService
     */
    protected $payment_service;

    /**
     * Webhook handler.
     *
     * @var WebhookHandler
     */
    protected $webhook_handler;

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
     * @param WebhookHandler $webhook_handler Webhook handler.
     * @param callable|null  $logger Logger callback.
     */
    public function __construct(PaymentService $payment_service, WebhookHandler $webhook_handler, $logger = null) {
        $this->payment_service = $payment_service;
        $this->webhook_handler = $webhook_handler;
        $this->logger = is_callable($logger) ? $logger : null;
    }

    /**
     * Register hooks.
     *
     * @return void
     */
    public function register() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    /**
     * Register route definitions.
     *
     * @return void
     */
    public function register_routes() {
        register_rest_route(
            'hyperpay/v1',
            '/create-payment',
            array(
                'methods' => 'POST',
                'callback' => array($this, 'create_payment'),
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            'hyperpay/v1',
            '/verify-payment',
            array(
                'methods' => 'GET',
                'callback' => array($this, 'verify_payment'),
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            'hyperpay/v1',
            '/transaction/(?P<id>\\d+)',
            array(
                'methods' => 'GET',
                'callback' => array($this, 'get_transaction'),
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            'hyperpay/v1',
            '/webhook',
            array(
                'methods' => 'POST',
                'callback' => array($this, 'webhook'),
                'permission_callback' => '__return_true',
            )
        );
    }

    /**
     * Create payment endpoint.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function create_payment(WP_REST_Request $request) {
        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            $payload = $request->get_params();
        }

        $data = array(
            'amount' => (float) ($payload['amount'] ?? 0),
            'currency' => sanitize_text_field((string) ($payload['currency'] ?? hyperpay_service_gateway_get_settings()['currency'])),
            'service_type' => sanitize_key((string) ($payload['service_type'] ?? '')),
            'service_reference_id' => sanitize_text_field((string) ($payload['service_reference_id'] ?? '')),
            'merchant_transaction_id' => sanitize_text_field((string) ($payload['merchant_transaction_id'] ?? '')),
            'customer' => array(
                'name' => sanitize_text_field((string) ($payload['name'] ?? ($payload['customer_name'] ?? ''))),
                'email' => sanitize_email((string) ($payload['email'] ?? ($payload['customer_email'] ?? ''))),
                'phone' => sanitize_text_field((string) ($payload['phone'] ?? ($payload['customer_phone'] ?? ''))),
                'country' => sanitize_text_field((string) ($payload['country'] ?? ($payload['billing_country'] ?? ''))),
                'city' => sanitize_text_field((string) ($payload['city'] ?? ($payload['billing_city'] ?? ''))),
                'street' => sanitize_text_field((string) ($payload['street'] ?? ($payload['billing_street'] ?? ''))),
            ),
            'test_mode' => !empty(hyperpay_service_gateway_get_settings()['test_mode']),
        );

        $result = $this->payment_service->createPayment($data);

        if (empty($result['success'])) {
            return new WP_REST_Response(
                array('success' => false, 'message' => sanitize_text_field((string) ($result['message'] ?? 'Payment creation failed.'))),
                400
            );
        }

        return new WP_REST_Response(array('success' => true, 'data' => $result), 200);
    }

    /**
     * Verify payment endpoint.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function verify_payment(WP_REST_Request $request) {
        $checkout_id = sanitize_text_field((string) $request->get_param('checkout_id'));
        $resource_path = sanitize_text_field((string) $request->get_param('resource_path'));
        $merchant_transaction_id = sanitize_text_field((string) $request->get_param('transaction_id'));

        $verification = $this->payment_service->verifyPayment(
            $checkout_id,
            array(
                'resource_path' => $resource_path,
                'merchant_transaction_id' => $merchant_transaction_id,
                'allow_query_fallback' => true,
            )
        );

        if (empty($verification['success'])) {
            return new WP_REST_Response(
                array('success' => false, 'message' => sanitize_text_field((string) ($verification['message'] ?? 'Verification failed.'))),
                400
            );
        }

        if (!empty($merchant_transaction_id)) {
            $transaction = $this->payment_service->get_transaction_by_merchant_transaction_id($merchant_transaction_id);
            if ($transaction) {
                $this->payment_service->apply_verified_result($transaction, $verification, 'api_verify');
            }
        }

        return new WP_REST_Response(array('success' => true, 'data' => $verification), 200);
    }

    /**
     * Get transaction endpoint.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function get_transaction(WP_REST_Request $request) {
        $id = absint($request->get_param('id'));
        $transaction = $this->payment_service->get_transaction($id);

        if (!$transaction) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Transaction not found.'), 404);
        }

        return new WP_REST_Response(array('success' => true, 'data' => $transaction), 200);
    }

    /**
     * Webhook endpoint.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function webhook(WP_REST_Request $request) {
        $raw_body = (string) $request->get_body();
        $headers = $request->get_headers();

        if (!$this->webhook_handler->is_authentic($raw_body, $headers)) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Invalid webhook signature.'), 401);
        }

        $payload = $request->get_json_params();

        if (!is_array($payload)) {
            $payload = $request->get_params();
        }

        $payload = $this->sanitize_array($payload);

        $result = $this->webhook_handler->process($payload);

        if ($result instanceof WP_Error) {
            return new WP_REST_Response(
                array(
                    'success' => false,
                    'code' => sanitize_key($result->get_error_code()),
                    'message' => sanitize_text_field($result->get_error_message()),
                ),
                400
            );
        }

        return new WP_REST_Response(array('success' => true, 'data' => $result), 200);
    }

    /**
     * Recursively sanitize arrays.
     *
     * @param mixed $value Value.
     * @return mixed
     */
    protected function sanitize_array($value) {
        if (is_array($value)) {
            $sanitized = array();
            foreach ($value as $key => $nested_value) {
                $safe_key = is_string($key) ? sanitize_text_field($key) : $key;
                $sanitized[$safe_key] = $this->sanitize_array($nested_value);
            }
            return $sanitized;
        }

        if (is_scalar($value)) {
            return sanitize_text_field((string) $value);
        }

        return '';
    }
}
