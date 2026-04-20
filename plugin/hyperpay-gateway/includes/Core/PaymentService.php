<?php
/**
 * Payment orchestration service.
 *
 * @package HyperPayGateway\Core
 */

namespace HyperPayGateway\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles checkout creation and payment verification.
 */
class PaymentService {

    /**
     * HyperPay API client.
     *
     * @var HyperPayClient
     */
    protected $client;

    /**
     * Logger callback.
     *
     * @var callable|null
     */
    protected $logger;

    /**
     * Constructor.
     *
     * @param HyperPayClient  $client HyperPay client.
     * @param callable|null   $logger Logger callback.
     */
    public function __construct(HyperPayClient $client, $logger = null) {
        $this->client = $client;
        $this->logger = is_callable($logger) ? $logger : null;
    }

    /**
     * Update runtime settings on underlying API client.
     *
     * @param array $settings Settings array.
     * @return void
     */
    public function set_settings($settings) {
        $this->client->set_settings($settings);
    }

    /**
     * Create a HyperPay checkout session.
     *
     * Refactored from the existing Tanafs HyperPay integration.
     *
     * @param float $amount Amount.
     * @param string $currency Currency code.
     * @param int $order_id WooCommerce order id.
     * @param array $args Additional context.
     * @return array
     */
    public function createCheckout($amount, $currency, $order_id, $args = array()) {
        $order_id = absint($order_id);
        $amount_formatted = number_format((float) $amount, 2, '.', '');
        $currency = strtoupper(sanitize_text_field((string) $currency));
        $payment_method = $this->normalize_payment_method($args['payment_method'] ?? 'card');

        if ($order_id <= 0 || (float) $amount <= 0) {
            return array(
                'success' => false,
                'message' => 'Invalid checkout payload.',
            );
        }

        $merchant_transaction_id = sanitize_text_field((string) ($args['merchant_transaction_id'] ?? ''));
        if (empty($merchant_transaction_id)) {
            $merchant_transaction_id = 'WC_ORDER_' . $order_id . '_' . wp_rand(1000, 9999) . '_' . time();
        }

        $customer = is_array($args['customer'] ?? array()) ? $args['customer'] : array();
        $name_parts = $this->split_name($customer['name'] ?? '');
        $billing_country = $this->normalize_country_code($customer['country'] ?? 'SA');
        $customer_phone = $this->normalize_phone($customer['phone'] ?? '');

        $payload = array(
            'entityId' => $this->client->get_entity_id(),
            'amount' => $amount_formatted,
            'currency' => $currency,
            'paymentType' => 'DB',
            'merchantTransactionId' => $merchant_transaction_id,
            'customer.email' => sanitize_email($customer['email'] ?? ''),
            'customer.phone' => $customer_phone,
            'customer.givenName' => $name_parts['first_name'],
            'customer.surname' => $name_parts['last_name'],
            'billing.street1' => sanitize_text_field($customer['street1'] ?? 'N/A'),
            'billing.city' => sanitize_text_field($customer['city'] ?? 'Riyadh'),
            'billing.state' => sanitize_text_field($customer['state'] ?? 'Riyadh'),
            'billing.country' => $billing_country,
            'billing.postcode' => sanitize_text_field($customer['postcode'] ?? '11564'),
            'customParameters[order_id]' => (string) $order_id,
            'customParameters[payment_method]' => $payment_method,
        );

        $brands = $this->get_brands_for_method($payment_method);
        if (!empty($brands)) {
            $payload['paymentBrand'] = $brands;
        }

        $shopper_result_url = esc_url_raw((string) ($args['shopper_result_url'] ?? ''));
        if (!empty($shopper_result_url)) {
            $payload['shopperResultUrl'] = $shopper_result_url;
        }

        if (!empty($args['test_mode'])) {
            $payload['integrity'] = true;
        }

        $this->log('info', 'HyperPay checkout initiation requested', array(
            'order_id' => $order_id,
            'merchant_transaction_id' => $merchant_transaction_id,
            'currency' => $currency,
            'amount' => $amount_formatted,
            'payment_method' => $payment_method,
        ));

        $response = $this->client->create_checkout($payload);

        if (empty($response['success'])) {
            return array(
                'success' => false,
                'message' => sanitize_text_field((string) ($response['message'] ?? 'Unable to connect to HyperPay.')),
            );
        }

        $decoded = is_array($response['body'] ?? null) ? $response['body'] : array();
        $status_code = (int) ($response['http_status'] ?? 0);

        if ($status_code < 200 || $status_code >= 300 || empty($decoded['id'])) {
            return array(
                'success' => false,
                'message' => 'HyperPay checkout was rejected.',
                'http_status' => $status_code,
                'response_data' => $decoded,
            );
        }

        $checkout_id = sanitize_text_field((string) $decoded['id']);
        $widget_url = rtrim($this->client->get_base_url(), '/')
            . '/v1/paymentWidgets.js?checkoutId=' . rawurlencode($checkout_id);

        $this->log('info', 'HyperPay checkout created', array(
            'order_id' => $order_id,
            'checkout_id' => $checkout_id,
            'merchant_transaction_id' => $merchant_transaction_id,
            'http_status' => $status_code,
        ));

        return array(
            'success' => true,
            'checkout_id' => $checkout_id,
            'widget_url' => $widget_url,
            'widget_integrity' => sanitize_text_field((string) ($decoded['integrity'] ?? '')),
            'merchant_transaction_id' => $merchant_transaction_id,
            'payment_method' => $payment_method,
            'brands' => $brands,
            'response_data' => $decoded,
        );
    }

    /**
     * Verify payment server-to-server.
     *
     * @param string $checkout_id Checkout id.
     * @param array  $args Verification context.
     * @return array
     */
    public function verifyPayment($checkout_id, $args = array()) {
        $checkout_id = sanitize_text_field((string) $checkout_id);
        $resource_path = sanitize_text_field((string) ($args['resource_path'] ?? ''));
        $merchant_transaction_id = sanitize_text_field((string) ($args['merchant_transaction_id'] ?? ''));
        $allow_query_fallback = !empty($args['allow_query_fallback']);

        $response = $this->client->verify_payment($checkout_id, $resource_path);

        if (empty($response['success'])) {
            return array(
                'success' => false,
                'message' => sanitize_text_field((string) ($response['message'] ?? 'Verification failed.')),
            );
        }

        $decoded = is_array($response['body'] ?? null) ? $response['body'] : array();

        $result_code = sanitize_text_field((string) ($decoded['result']['code'] ?? ''));
        $status = self::map_result_status($result_code);

        if ('pending' === $status && $allow_query_fallback && '200.300.404' === $result_code && !empty($merchant_transaction_id)) {
            $query = $this->client->query_by_merchant_transaction_id($merchant_transaction_id);
            if (!empty($query['success'])) {
                $query_decoded = is_array($query['body'] ?? null) ? $query['body'] : array();
                $payment = $this->extract_payment_from_query($query_decoded);
                if (!empty($payment)) {
                    $result_code = sanitize_text_field((string) ($payment['result']['code'] ?? ''));
                    $status = self::map_result_status($result_code);
                    $decoded = $payment;
                }
            }
        }

        return array(
            'success' => true,
            'payment_status' => $status,
            'result_code' => $result_code,
            'transaction_id' => sanitize_text_field((string) ($decoded['id'] ?? '')),
            'message' => sanitize_text_field((string) ($decoded['result']['description'] ?? '')),
            'response_data' => $decoded,
        );
    }

    /**
     * Map HyperPay result code to internal status.
     *
     * Reused from existing integration behavior.
     *
     * @param string $result_code Result code.
     * @return string
     */
    public static function map_result_status($result_code) {
        $result_code = (string) $result_code;

        if ('' === $result_code) {
            return 'pending';
        }

        if (preg_match('/^(000\.000\.|000\.100\.1|000\.[36])/', $result_code)) {
            return 'complete';
        }

        if (preg_match('/^(000\.200|200\.300\.404|200\.300\.403|200\.300\.000|800\.120\.100|800\.400\.5|100\.400\.500)/', $result_code)) {
            return 'pending';
        }

        return 'failed';
    }

    /**
     * Normalize payment method key.
     *
     * @param string $payment_method Raw method.
     * @return string
     */
    public function normalize_payment_method($payment_method) {
        $method = strtolower(trim((string) $payment_method));

        $aliases = array(
            'card' => 'card',
            'cards' => 'card',
            'mada_visa_mastercard' => 'card',
            'mada-visa-mastercard' => 'card',
            'mada' => 'card',
            'tamara' => 'tamara',
            'applepay' => 'applepay',
            'apple_pay' => 'applepay',
            'apple-pay' => 'applepay',
        );

        return $aliases[$method] ?? 'card';
    }

    /**
     * Get HyperPay brands string from normalized method.
     *
     * @param string $payment_method Method.
     * @return string
     */
    public function get_brands_for_method($payment_method) {
        $method = $this->normalize_payment_method($payment_method);

        if ('tamara' === $method) {
            return 'TAMARA';
        }

        if ('applepay' === $method) {
            return 'APPLEPAY';
        }

        return 'MADA VISA MASTER';
    }

    /**
     * Split full name into given/surname.
     *
     * @param string $full_name Full name.
     * @return array
     */
    protected function split_name($full_name) {
        $full_name = trim((string) $full_name);
        if ('' === $full_name) {
            return array(
                'first_name' => 'Customer',
                'last_name' => 'Customer',
            );
        }

        $parts = preg_split('/\s+/', $full_name);
        $first_name = array_shift($parts);
        $last_name = !empty($parts) ? implode(' ', $parts) : $first_name;

        return array(
            'first_name' => sanitize_text_field($first_name),
            'last_name' => sanitize_text_field($last_name),
        );
    }

    /**
     * Normalize country to ISO-3166 alpha-2.
     *
     * @param string $country Country input.
     * @return string
     */
    protected function normalize_country_code($country) {
        $country = strtoupper(trim((string) $country));
        if (preg_match('/^[A-Z]{2}$/', $country)) {
            return $country;
        }

        $map = array(
            'SAUDI ARABIA' => 'SA',
            'KSA' => 'SA',
            'UNITED ARAB EMIRATES' => 'AE',
            'UAE' => 'AE',
            'EGYPT' => 'EG',
            'OMAN' => 'OM',
            'JORDAN' => 'JO',
            'QATAR' => 'QA',
            'KUWAIT' => 'KW',
            'BAHRAIN' => 'BH',
            'UNITED STATES' => 'US',
        );

        return $map[$country] ?? 'SA';
    }

    /**
     * Normalize phone number for HyperPay payload.
     *
     * @param string $phone Raw phone.
     * @return string
     */
    protected function normalize_phone($phone) {
        $phone = trim((string) $phone);
        if ('' === $phone) {
            return '+966500000000';
        }

        $phone = preg_replace('/[^0-9+]/', '', $phone);

        if (0 === strpos($phone, '00')) {
            $phone = '+' . substr($phone, 2);
        }

        if ('+' !== substr($phone, 0, 1)) {
            if (0 === strpos($phone, '0')) {
                $phone = '+966' . substr($phone, 1);
            } else {
                $phone = '+' . $phone;
            }
        }

        return sanitize_text_field($phone);
    }

    /**
     * Extract first payment object from query response.
     *
     * @param array $decoded Query body.
     * @return array|null
     */
    protected function extract_payment_from_query($decoded) {
        if (!is_array($decoded)) {
            return null;
        }

        if (isset($decoded['payments']) && is_array($decoded['payments']) && !empty($decoded['payments'][0])) {
            return is_array($decoded['payments'][0]) ? $decoded['payments'][0] : null;
        }

        if (isset($decoded['records']) && is_array($decoded['records']) && !empty($decoded['records'][0])) {
            return is_array($decoded['records'][0]) ? $decoded['records'][0] : null;
        }

        if (isset($decoded['data']) && is_array($decoded['data']) && !empty($decoded['data'][0])) {
            return is_array($decoded['data'][0]) ? $decoded['data'][0] : null;
        }

        if (isset($decoded['id'], $decoded['result']) && is_array($decoded['result'])) {
            return $decoded;
        }

        return null;
    }

    /**
     * Write service logs.
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
