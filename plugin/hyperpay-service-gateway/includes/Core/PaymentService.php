<?php
/**
 * Payment service.
 *
 * @package HyperPayServiceGateway\Core
 */

namespace HyperPayServiceGateway\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles transaction lifecycle operations.
 */
class PaymentService {

    /**
     * API client.
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
     * @param HyperPayClient $client API client.
     * @param callable|null  $logger Logger callback.
     */
    public function __construct(HyperPayClient $client, $logger = null) {
        $this->client = $client;
        $this->logger = is_callable($logger) ? $logger : null;
    }

    /**
     * Set client settings.
     *
     * @param array $settings Runtime settings.
     * @return void
     */
    public function set_settings($settings) {
        $this->client->set_settings($settings);
    }

    /**
     * Create a payment transaction and checkout.
     *
     * @param array $data Payment input.
     * @return array
     */
    public function createPayment($data) {
        global $wpdb;

        $data = is_array($data) ? $data : array();

        $amount = number_format((float) ($data['amount'] ?? 0), 2, '.', '');
        $currency = strtoupper(sanitize_text_field((string) ($data['currency'] ?? 'SAR')));
        $service_type = sanitize_key((string) ($data['service_type'] ?? 'service'));
        $service_reference_id = sanitize_text_field((string) ($data['service_reference_id'] ?? ''));
        $checkout_redirect_url = esc_url_raw((string) ($data['checkout_redirect_url'] ?? ''));

        if ((float) $amount <= 0 || empty($service_type) || empty($service_reference_id)) {
            return array(
                'success' => false,
                'message' => 'Invalid payment payload.',
            );
        }

        $customer = is_array($data['customer'] ?? array()) ? $data['customer'] : array();
        $customer_name = sanitize_text_field((string) ($customer['name'] ?? ''));
        $customer_email = sanitize_email((string) ($customer['email'] ?? ''));
        $customer_country = $this->normalize_country_code($customer['country'] ?? '');
        $customer_street = sanitize_text_field((string) ($customer['street'] ?? ''));

        if (empty($customer_name) || empty($customer_email) || empty($customer_street) || empty($customer_country)) {
            return array(
                'success' => false,
                'message' => 'Missing required customer fields.',
            );
        }

        $name_parts = $this->split_name($customer['name'] ?? '');

        $merchant_transaction_id = sanitize_text_field((string) ($data['merchant_transaction_id'] ?? ''));
        if (empty($merchant_transaction_id)) {
            $merchant_transaction_id = 'HP_' . strtoupper($service_type) . '_' . time() . '_' . wp_rand(1000, 9999);
        }

        $inserted = $wpdb->insert(
            $this->table_name(),
            array(
                'transaction_id' => wp_generate_uuid4(),
                'checkout_id' => '',
                'merchant_transaction_id' => $merchant_transaction_id,
                'amount' => $amount,
                'currency' => $currency,
                'status' => 'pending',
                'customer_name' => $customer_name,
                'customer_email' => $customer_email,
                'customer_phone' => $this->normalize_phone($customer['phone'] ?? ''),
                'billing_country' => $customer_country,
                'billing_city' => sanitize_text_field((string) ($customer['city'] ?? '')),
                'billing_street' => $customer_street,
                'service_type' => $service_type,
                'service_reference_id' => $service_reference_id,
            ),
            array('%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        if (false === $inserted) {
            return array(
                'success' => false,
                'message' => 'Could not create transaction record.',
            );
        }

        $transaction_id = (int) $wpdb->insert_id;
        $shopper_result_url = add_query_arg(
            array(
                'hyperpay_return' => '1',
                'transaction_id' => $merchant_transaction_id,
            ),
            home_url('/')
        );

        $payload = array(
            'entityId' => $this->client->get_entity_id(),
            'amount' => $amount,
            'currency' => $currency,
            'paymentType' => 'DB',
            'merchantTransactionId' => $merchant_transaction_id,
            'customer.email' => $customer_email,
            'customer.phone' => $this->normalize_phone($customer['phone'] ?? ''),
            'customer.givenName' => $name_parts['first_name'],
            'customer.surname' => $name_parts['last_name'],
            'billing.street1' => $customer_street,
            'billing.city' => sanitize_text_field((string) ($customer['city'] ?? 'Riyadh')),
            'billing.state' => sanitize_text_field((string) ($customer['city'] ?? 'Riyadh')),
            'billing.country' => $customer_country,
            'billing.postcode' => sanitize_text_field((string) ($customer['postcode'] ?? '11564')),
            'customParameters[service_type]' => $service_type,
            'customParameters[service_reference_id]' => $service_reference_id,
            'shopperResultUrl' => esc_url_raw($shopper_result_url),
        );

        if (!empty($data['test_mode'])) {
            $payload['integrity'] = true;
        }

        $this->log('info', 'initiation_requested', array(
            'merchant_transaction_id' => $merchant_transaction_id,
            'service_type' => $service_type,
            'service_reference_id' => $service_reference_id,
            'amount' => $amount,
            'currency' => $currency,
        ));

        $response = $this->client->create_checkout($payload);

        if (empty($response['success'])) {
            $this->mark_status($transaction_id, 'failed');

            return array(
                'success' => false,
                'message' => sanitize_text_field((string) ($response['message'] ?? 'Checkout creation failed.')),
                'transaction_id' => $transaction_id,
            );
        }

        $decoded = is_array($response['body'] ?? null) ? $response['body'] : array();
        $status_code = (int) ($response['http_status'] ?? 0);

        if ($status_code < 200 || $status_code >= 300 || empty($decoded['id'])) {
            $this->mark_status($transaction_id, 'failed');
            return array(
                'success' => false,
                'message' => 'HyperPay rejected checkout creation.',
                'transaction_id' => $transaction_id,
            );
        }

        $checkout_id = sanitize_text_field((string) $decoded['id']);

        $wpdb->update(
            $this->table_name(),
            array('checkout_id' => $checkout_id),
            array('id' => $transaction_id),
            array('%s'),
            array('%d')
        );

        $this->log('info', 'initiation_created', array(
            'id' => $transaction_id,
            'checkout_id' => $checkout_id,
            'merchant_transaction_id' => $merchant_transaction_id,
        ));

        if (empty($checkout_redirect_url)) {
            $checkout_redirect_url = add_query_arg(
                array(
                    'hyperpay_checkout' => '1',
                    'transaction_id' => $merchant_transaction_id,
                ),
                home_url('/')
            );
        }

        return array(
            'success' => true,
            'id' => $transaction_id,
            'checkout_id' => $checkout_id,
            'merchant_transaction_id' => $merchant_transaction_id,
            'redirect_url' => esc_url_raw($checkout_redirect_url),
            'widget_url' => $this->get_widget_url($checkout_id),
            'response_data' => $decoded,
        );
    }

    /**
     * Verify payment from HyperPay.
     *
     * @param string $checkout_id Checkout id.
     * @param array  $args Verification args.
     * @return array
     */
    public function verifyPayment($checkout_id, $args = array()) {
        $checkout_id = sanitize_text_field((string) $checkout_id);
        $resource_path = sanitize_text_field((string) ($args['resource_path'] ?? ''));
        $merchant_transaction_id = sanitize_text_field((string) ($args['merchant_transaction_id'] ?? ''));
        $allow_query_fallback = !empty($args['allow_query_fallback']);

        $this->log('info', 'verification_requested', array(
            'checkout_id' => $checkout_id,
            'resource_path' => $resource_path,
            'merchant_transaction_id' => $merchant_transaction_id,
        ));

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

        $result = array(
            'success' => true,
            'payment_status' => $status,
            'result_code' => $result_code,
            'transaction_id' => sanitize_text_field((string) ($decoded['id'] ?? '')),
            'merchant_transaction_id' => sanitize_text_field((string) ($decoded['merchantTransactionId'] ?? '')),
            'amount' => sanitize_text_field((string) ($decoded['amount'] ?? '')),
            'currency' => strtoupper(sanitize_text_field((string) ($decoded['currency'] ?? ''))),
            'checkout_id' => sanitize_text_field((string) ($decoded['ndc'] ?? $checkout_id)),
            'message' => sanitize_text_field((string) ($decoded['result']['description'] ?? '')),
            'response_data' => $decoded,
        );

        $this->log('info', 'verification_result', array(
            'checkout_id' => $checkout_id,
            'merchant_transaction_id' => $merchant_transaction_id,
            'payment_status' => $status,
            'result_code' => $result_code,
        ));

        return $result;
    }

    /**
     * Apply verification result to a transaction with integrity checks.
     *
     * @param object $transaction Existing transaction.
     * @param array  $verification Verification response.
     * @param string $source Source type.
     * @return array
     */
    public function apply_verified_result($transaction, $verification, $source = 'webhook') {
        global $wpdb;

        if (!$transaction || empty($verification['success'])) {
            return array('success' => false, 'message' => 'Invalid verification payload.');
        }

        $current_status = sanitize_key((string) ($transaction->status ?? 'pending'));
        $new_status = sanitize_key((string) ($verification['payment_status'] ?? 'pending'));

        if ($current_status === $new_status && in_array($new_status, array('success', 'failed', 'cancelled'), true)) {
            return array('success' => true, 'idempotent' => true);
        }

        $integrity = $this->assert_integrity($transaction, $verification);
        if (empty($integrity['success'])) {
            $this->log('warning', 'payment_integrity_failed', array(
                'id' => (int) $transaction->id,
                'merchant_transaction_id' => (string) $transaction->merchant_transaction_id,
                'reason' => (string) ($integrity['message'] ?? 'integrity_failed'),
            ));

            return $integrity;
        }

        $update = array(
            'status' => $new_status,
        );
        $formats = array('%s');

        $updated = $wpdb->update(
            $this->table_name(),
            $update,
            array('id' => (int) $transaction->id),
            $formats,
            array('%d')
        );

        if (false === $updated) {
            return array(
                'success' => false,
                'message' => 'Could not update transaction status.',
            );
        }

        $fresh = $this->get_transaction((int) $transaction->id);

        $this->log('info', 'status_transition', array(
            'id' => (int) $transaction->id,
            'from' => $current_status,
            'to' => $new_status,
            'source' => sanitize_key($source),
        ));

        do_action('hyperpay_payment_processed', $fresh);

        return array(
            'success' => true,
            'transaction' => $fresh,
        );
    }

    /**
     * Verify integrity against local transaction.
     *
     * @param object $transaction Stored row.
     * @param array  $verification Verification payload.
     * @return array
     */
    protected function assert_integrity($transaction, $verification) {
        $verification_status = sanitize_key((string) ($verification['payment_status'] ?? 'pending'));

        if ('success' !== $verification_status) {
            return array('success' => true);
        }

        $stored_checkout = sanitize_text_field((string) ($transaction->checkout_id ?? ''));
        $stored_merchant = sanitize_text_field((string) ($transaction->merchant_transaction_id ?? ''));
        $stored_amount = number_format((float) ($transaction->amount ?? 0), 2, '.', '');
        $stored_currency = strtoupper(sanitize_text_field((string) ($transaction->currency ?? '')));

        $remote_checkout = sanitize_text_field((string) ($verification['checkout_id'] ?? ''));
        $remote_merchant = sanitize_text_field((string) ($verification['merchant_transaction_id'] ?? ''));
        $remote_amount = number_format((float) ($verification['amount'] ?? 0), 2, '.', '');
        $remote_currency = strtoupper(sanitize_text_field((string) ($verification['currency'] ?? '')));

        if (!empty($remote_checkout) && $stored_checkout !== $remote_checkout) {
            return array('success' => false, 'message' => 'Checkout id mismatch.');
        }

        if (!empty($remote_merchant) && $stored_merchant !== $remote_merchant) {
            return array('success' => false, 'message' => 'Merchant transaction id mismatch.');
        }

        if ((float) $stored_amount !== (float) $remote_amount) {
            return array('success' => false, 'message' => 'Amount mismatch.');
        }

        if (!empty($remote_currency) && $stored_currency !== $remote_currency) {
            return array('success' => false, 'message' => 'Currency mismatch.');
        }

        return array('success' => true);
    }

    /**
     * Get widget url.
     *
     * @param string $checkout_id Checkout id.
     * @return string
     */
    public function get_widget_url($checkout_id) {
        return rtrim($this->client->get_base_url(), '/') . '/v1/paymentWidgets.js?checkoutId=' . rawurlencode((string) $checkout_id);
    }

    /**
     * Get transaction by id.
     *
     * @param int $id Row id.
     * @return object|null
     */
    public function get_transaction($id) {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . $this->table_name() . ' WHERE id = %d',
                absint($id)
            )
        );
    }

    /**
     * Get transaction by checkout id.
     *
     * @param string $checkout_id Checkout id.
     * @return object|null
     */
    public function get_transaction_by_checkout_id($checkout_id) {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . $this->table_name() . ' WHERE checkout_id = %s ORDER BY id DESC LIMIT 1',
                sanitize_text_field((string) $checkout_id)
            )
        );
    }

    /**
     * Get transaction by merchant transaction id.
     *
     * @param string $merchant_transaction_id Merchant transaction id.
     * @return object|null
     */
    public function get_transaction_by_merchant_transaction_id($merchant_transaction_id) {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . $this->table_name() . ' WHERE merchant_transaction_id = %s ORDER BY id DESC LIMIT 1',
                sanitize_text_field((string) $merchant_transaction_id)
            )
        );
    }

    /**
     * List transactions by email.
     *
     * @param string $email Customer email.
     * @return array
     */
    public function get_transactions_by_email($email) {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . $this->table_name() . ' WHERE customer_email = %s ORDER BY id DESC',
                sanitize_email((string) $email)
            )
        );
    }

    /**
     * List transactions for admin table.
     *
     * @param array $filters Filters.
     * @return array
     */
    public function list_transactions($filters = array()) {
        global $wpdb;

        $where = array('1=1');
        $params = array();

        if (!empty($filters['status'])) {
            $where[] = 'status = %s';
            $params[] = sanitize_key((string) $filters['status']);
        }

        if (!empty($filters['service_type'])) {
            $where[] = 'service_type = %s';
            $params[] = sanitize_key((string) $filters['service_type']);
        }

        $sql = 'SELECT * FROM ' . $this->table_name() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT 250';

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, $params);
        }

        return $wpdb->get_results($sql);
    }

    /**
     * Mark transaction status.
     *
     * @param int    $id Transaction id.
     * @param string $status New status.
     * @return void
     */
    protected function mark_status($id, $status) {
        global $wpdb;

        $wpdb->update(
            $this->table_name(),
            array('status' => sanitize_key($status)),
            array('id' => absint($id)),
            array('%s'),
            array('%d')
        );
    }

    /**
     * Map HyperPay code to internal status.
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
            return 'success';
        }

        if (preg_match('/^(000\.200|200\.300\.404|200\.300\.403|200\.300\.000|800\.120\.100|800\.400\.5|100\.400\.500)/', $result_code)) {
            return 'pending';
        }

        if (preg_match('/^(800\.400\.1|800\.400\.2|800\.400\.3|800\.700|900\.)/', $result_code)) {
            return 'cancelled';
        }

        return 'failed';
    }

    /**
     * Extract payment object from query endpoint.
     *
     * @param array $decoded Decoded body.
     * @return array|null
     */
    protected function extract_payment_from_query($decoded) {
        if (!is_array($decoded)) {
            return null;
        }

        if (isset($decoded['payments'][0]) && is_array($decoded['payments'][0])) {
            return $decoded['payments'][0];
        }

        if (isset($decoded['records'][0]) && is_array($decoded['records'][0])) {
            return $decoded['records'][0];
        }

        if (isset($decoded['data'][0]) && is_array($decoded['data'][0])) {
            return $decoded['data'][0];
        }

        if (isset($decoded['id'], $decoded['result']) && is_array($decoded['result'])) {
            return $decoded;
        }

        return null;
    }

    /**
     * Split full name.
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
     * Normalize country code.
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
     * Normalize phone number.
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
     * Get transaction table name.
     *
     * @return string
     */
    protected function table_name() {
        return hyperpay_service_gateway_table_name();
    }

    /**
     * Log helper.
     *
     * @param string $level Log level.
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
