<?php
/**
 * REST API routes.
 *
 * @package HyperPayGateway\API
 */

namespace HyperPayGateway\API;

use HyperPayGateway\Core\WebhookHandler;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers plugin REST endpoints.
 */
class RestRoutes {

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
     * @param WebhookHandler $webhook_handler Webhook handler.
     * @param callable|null  $logger Logger callback.
     */
    public function __construct(WebhookHandler $webhook_handler, $logger = null) {
        $this->webhook_handler = $webhook_handler;
        $this->logger = is_callable($logger) ? $logger : null;
    }

    /**
     * Register WP hooks.
     *
     * @return void
     */
    public function register() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    /**
     * Register routes.
     *
     * @return void
     */
    public function register_routes() {
        register_rest_route(
            'hyperpay/v1',
            '/webhook',
            array(
                'methods' => 'POST',
                'callback' => array($this, 'handle_webhook'),
                'permission_callback' => '__return_true',
            )
        );
    }

    /**
     * Handle webhook callback.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function handle_webhook(WP_REST_Request $request) {
        $payload = $request->get_json_params();

        if (!is_array($payload)) {
            $payload = $request->get_params();
        }

        $payload = $this->sanitize_array($payload);

        if (!$this->is_valid_payload($payload)) {
            return new WP_REST_Response(
                array(
                    'success' => false,
                    'message' => 'Invalid webhook payload.',
                ),
                400
            );
        }

        $result = $this->webhook_handler->process($payload);

        if ($result instanceof WP_Error) {
            $this->log('warning', 'Webhook processing failed', array(
                'code' => $result->get_error_code(),
                'message' => $result->get_error_message(),
            ));

            return new WP_REST_Response(
                array(
                    'success' => false,
                    'code' => sanitize_key($result->get_error_code()),
                    'message' => sanitize_text_field($result->get_error_message()),
                ),
                400
            );
        }

        return new WP_REST_Response(
            array(
                'success' => true,
                'data' => $result,
            ),
            200
        );
    }

    /**
     * Validate minimum webhook payload attributes.
     *
     * @param array $payload Payload.
     * @return bool
     */
    protected function is_valid_payload($payload) {
        if (!is_array($payload) || empty($payload)) {
            return false;
        }

        $resource_path = (string) ($payload['resourcePath'] ?? ($payload['resource_path'] ?? ''));
        $checkout_id = (string) ($payload['id'] ?? ($payload['checkoutId'] ?? ''));
        $merchant_tx_id = (string) ($payload['merchantTransactionId'] ?? '');

        return !empty($resource_path) || !empty($checkout_id) || !empty($merchant_tx_id);
    }

    /**
     * Recursively sanitize payload values.
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

    /**
     * Write logs.
     *
     * @param string $level Log level.
     * @param string $message Log message.
     * @param array  $context Context data.
     * @return void
     */
    protected function log($level, $message, $context = array()) {
        if (is_callable($this->logger)) {
            call_user_func($this->logger, $level, $message, $context);
        }
    }
}
