<?php
/**
 * HyperPay API client.
 *
 * @package HyperPayGateway\Core
 */

namespace HyperPayGateway\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles low-level HTTP requests to HyperPay APIs.
 */
class HyperPayClient {

    /**
     * Runtime settings.
     *
     * @var array
     */
    protected $settings = array();

    /**
     * Logger callback.
     *
     * @var callable|null
     */
    protected $logger;

    /**
     * Constructor.
     *
     * @param array         $settings Client settings.
     * @param callable|null $logger Logger callback.
     */
    public function __construct($settings = array(), $logger = null) {
        $this->settings = is_array($settings) ? $settings : array();
        $this->logger   = is_callable($logger) ? $logger : null;
    }

    /**
     * Update client settings.
     *
     * @param array $settings Settings array.
     * @return void
     */
    public function set_settings($settings) {
        $this->settings = is_array($settings) ? $settings : array();
    }

    /**
     * Create HyperPay checkout.
     *
     * @param array $payload Request payload.
     * @return array
     */
    public function create_checkout($payload) {
        return $this->request('POST', 'v1/checkouts', $payload, true);
    }

    /**
     * Verify payment by resource path or checkout id.
     *
     * @param string $checkout_id Checkout id.
     * @param string $resource_path Resource path.
     * @return array
     */
    public function verify_payment($checkout_id = '', $resource_path = '') {
        $entity_id = $this->get_entity_id();

        if (empty($entity_id)) {
            return array(
                'success' => false,
                'message' => 'Missing HyperPay entity id.',
            );
        }

        $normalized_resource_path = $this->normalize_resource_path($resource_path);

        if (!empty($normalized_resource_path)) {
            $endpoint = $this->build_status_endpoint($normalized_resource_path);
            if (false !== strpos($endpoint, '?')) {
                $endpoint = $endpoint . '&entityId=' . rawurlencode($entity_id);
            } else {
                $endpoint = $endpoint . '?entityId=' . rawurlencode($entity_id);
            }
            return $this->request('GET', $endpoint, array(), false, true);
        }

        if (empty($checkout_id)) {
            return array(
                'success' => false,
                'message' => 'Missing checkout id for verification.',
            );
        }

        $endpoint = 'v1/checkouts/' . rawurlencode($checkout_id) . '/payment?entityId=' . rawurlencode($entity_id);

        return $this->request('GET', $endpoint, array(), false);
    }

    /**
     * Query payment by merchant transaction id.
     *
     * @param string $merchant_transaction_id Merchant transaction id.
     * @return array
     */
    public function query_by_merchant_transaction_id($merchant_transaction_id) {
        $entity_id = $this->get_entity_id();

        if (empty($entity_id) || empty($merchant_transaction_id)) {
            return array(
                'success' => false,
                'message' => 'Missing query inputs.',
            );
        }

        $endpoint = 'v1/query?entityId=' . rawurlencode($entity_id)
            . '&merchantTransactionId=' . rawurlencode($merchant_transaction_id);

        return $this->request('GET', $endpoint, array(), false);
    }

    /**
     * Get configured entity id.
     *
     * @return string
     */
    public function get_entity_id() {
        return sanitize_text_field((string) ($this->settings['entity_id'] ?? ''));
    }

    /**
     * Get configured access token.
     *
     * @return string
     */
    public function get_access_token() {
        return sanitize_text_field((string) ($this->settings['access_token'] ?? ''));
    }

    /**
     * Get API base URL based on mode.
     *
     * Reused from the existing integration implementation.
     *
     * @return string
     */
    public function get_base_url() {
        $test_mode = !empty($this->settings['test_mode']);

        if ($test_mode) {
            return 'https://eu-test.oppwa.com/';
        }

        return 'https://eu-prod.oppwa.com/';
    }

    /**
     * Build status endpoint from resource path.
     *
     * @param string $resource_path Resource path.
     * @return string
     */
    public function build_status_endpoint($resource_path) {
        if (0 === strpos($resource_path, 'http://') || 0 === strpos($resource_path, 'https://')) {
            return $resource_path;
        }

        if ('/' !== substr($resource_path, 0, 1)) {
            $resource_path = '/' . ltrim($resource_path, '/');
        }

        return rtrim($this->get_base_url(), '/') . $resource_path;
    }

    /**
     * Normalize resource path from HyperPay callbacks.
     *
     * @param string $resource_path Raw resource path.
     * @return string
     */
    public function normalize_resource_path($resource_path) {
        $normalized = trim((string) $resource_path);

        if ('' === $normalized) {
            return '';
        }

        for ($i = 0; $i < 3; $i++) {
            $decoded = rawurldecode($normalized);
            if ($decoded === $normalized) {
                break;
            }
            $normalized = $decoded;
        }

        if (0 === strpos($normalized, 'http://') || 0 === strpos($normalized, 'https://')) {
            $parts = wp_parse_url($normalized);
            $normalized = (string) ($parts['path'] ?? '');
            if (!empty($parts['query'])) {
                $normalized .= '?' . $parts['query'];
            }
        }

        $normalized = preg_replace('/[\r\n]+/', '', $normalized);

        if ('' !== $normalized && '/' !== substr($normalized, 0, 1)) {
            $normalized = '/' . ltrim($normalized, '/');
        }

        return sanitize_text_field($normalized);
    }

    /**
     * Execute an HTTP request.
     *
     * @param string $method HTTP method.
     * @param string $endpoint Endpoint path or absolute URL.
     * @param array  $data Request body.
     * @param bool   $form_encoded Whether to send x-www-form-urlencoded body.
     * @param bool   $absolute_endpoint Whether endpoint is absolute URL.
     * @return array
     */
    protected function request($method, $endpoint, $data = array(), $form_encoded = true, $absolute_endpoint = false) {
        $access_token = $this->get_access_token();

        if (empty($access_token)) {
            return array(
                'success' => false,
                'message' => 'Missing HyperPay access token.',
            );
        }

        $method = strtoupper(sanitize_text_field((string) $method));

        $url = $absolute_endpoint
            ? esc_url_raw((string) $endpoint)
            : $this->get_base_url() . ltrim((string) $endpoint, '/');

        $headers = array(
            'Authorization' => 'Bearer ' . $access_token,
        );

        if ($form_encoded) {
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        }

        $args = array(
            'method'  => $method,
            'headers' => $headers,
            'timeout' => 25,
        );

        if ('POST' === $method || 'PUT' === $method || 'PATCH' === $method) {
            $args['body'] = $form_encoded ? $data : wp_json_encode($data);
            if (!$form_encoded) {
                $args['headers']['Content-Type'] = 'application/json';
            }
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            $this->log('error', 'HyperPay HTTP transport error', array(
                'endpoint' => $url,
                'error'    => $response->get_error_message(),
            ));

            return array(
                'success' => false,
                'message' => $response->get_error_message(),
            );
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $raw_body    = wp_remote_retrieve_body($response);
        $decoded     = json_decode($raw_body, true);

        $headers_raw = wp_remote_retrieve_headers($response);
        $headers_log = $this->normalize_http_headers($headers_raw);

        $this->log('info', 'HyperPay HTTP response', array(
            'endpoint'    => $url,
            'method'      => $method,
            'http_status' => $status_code,
            'headers'     => $headers_log,
        ));

        return array(
            'success'     => true,
            'http_status' => $status_code,
            'body'        => is_array($decoded) ? $decoded : array(),
            'raw_body'    => (string) $raw_body,
            'headers'     => $headers_log,
        );
    }

    /**
     * Normalize response headers for logs.
     *
     * @param mixed $headers Response headers.
     * @return array
     */
    protected function normalize_http_headers($headers) {
        if (is_array($headers)) {
            return $headers;
        }

        if (is_object($headers)) {
            if (method_exists($headers, 'getAll')) {
                $all = $headers->getAll();
                if (is_array($all)) {
                    return $all;
                }
            }

            if (method_exists($headers, 'toArray')) {
                $all = $headers->toArray();
                if (is_array($all)) {
                    return $all;
                }
            }

            return (array) $headers;
        }

        return array();
    }

    /**
     * Write a log entry.
     *
     * @param string $level Log level.
     * @param string $message Message.
     * @param array  $context Context values.
     * @return void
     */
    protected function log($level, $message, $context = array()) {
        if (is_callable($this->logger)) {
            call_user_func($this->logger, $level, $message, $context);
        }
    }
}
