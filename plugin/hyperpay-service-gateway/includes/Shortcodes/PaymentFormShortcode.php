<?php
/**
 * Payment form shortcode.
 *
 * @package HyperPayServiceGateway\Shortcodes
 */

namespace HyperPayServiceGateway\Shortcodes;

use HyperPayServiceGateway\Core\PaymentService;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles [hyperpay_form] output and submit flow.
 */
class PaymentFormShortcode {

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
     * Register hooks.
     *
     * @return void
     */
    public function register() {
        add_shortcode('hyperpay_form', array($this, 'render'));
        add_action('admin_post_nopriv_hyperpay_submit_form', array($this, 'handle_submit'));
        add_action('admin_post_hyperpay_submit_form', array($this, 'handle_submit'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
    }

    /**
     * Enqueue assets.
     *
     * @return void
     */
    public function enqueue_assets() {
        wp_register_style(
            'hyperpay-service-gateway-form',
            HYPERPAY_SERVICE_GATEWAY_URL . 'assets/form.css',
            array(),
            HYPERPAY_SERVICE_GATEWAY_VERSION
        );
    }

    /**
     * Render shortcode.
     *
     * @param array $atts Attributes.
     * @return string
     */
    public function render($atts) {
        wp_enqueue_style('hyperpay-service-gateway-form');

        $atts = shortcode_atts(
            array(
                'amount' => '',
                'currency' => hyperpay_service_gateway_get_settings()['currency'],
                'service_type' => '',
                'reference_id' => '',
            ),
            $atts,
            'hyperpay_form'
        );

        $fields = array(
            'name' => array('label' => __('Name', 'hyperpay-service-gateway'), 'required' => true, 'type' => 'text'),
            'email' => array('label' => __('Email', 'hyperpay-service-gateway'), 'required' => true, 'type' => 'email'),
            'phone' => array('label' => __('Phone', 'hyperpay-service-gateway'), 'required' => false, 'type' => 'text'),
            'country' => array('label' => __('Country', 'hyperpay-service-gateway'), 'required' => true, 'type' => 'text'),
            'city' => array('label' => __('City', 'hyperpay-service-gateway'), 'required' => false, 'type' => 'text'),
            'street' => array('label' => __('Street', 'hyperpay-service-gateway'), 'required' => true, 'type' => 'text'),
        );

        $fields = apply_filters('hyperpay_form_fields', $fields);

        ob_start();
        include HYPERPAY_SERVICE_GATEWAY_PATH . 'templates/payment-form.php';
        return ob_get_clean();
    }

    /**
     * Handle form submit.
     *
     * @return void
     */
    public function handle_submit() {
        if (!isset($_POST['hyperpay_form_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['hyperpay_form_nonce'])), 'hyperpay_submit_form')) {
            wp_die(esc_html__('Invalid request.', 'hyperpay-service-gateway'));
        }

        $amount = number_format((float) ($_POST['amount'] ?? 0), 2, '.', '');
        $currency = strtoupper(sanitize_text_field((string) ($_POST['currency'] ?? hyperpay_service_gateway_get_settings()['currency'])));
        $service_type = sanitize_key((string) ($_POST['service_type'] ?? ''));
        $service_reference_id = sanitize_text_field((string) ($_POST['service_reference_id'] ?? ''));

        $name = sanitize_text_field((string) ($_POST['name'] ?? ''));
        $email = sanitize_email((string) ($_POST['email'] ?? ''));
        $phone = sanitize_text_field((string) ($_POST['phone'] ?? ''));
        $country = sanitize_text_field((string) ($_POST['country'] ?? ''));
        $city = sanitize_text_field((string) ($_POST['city'] ?? ''));
        $street = sanitize_text_field((string) ($_POST['street'] ?? ''));

        $required = array(
            'amount' => (float) $amount > 0,
            'service_type' => !empty($service_type),
            'service_reference_id' => !empty($service_reference_id),
            'name' => !empty($name),
            'email' => !empty($email),
            'country' => !empty($country),
            'street' => !empty($street),
        );

        foreach ($required as $is_valid) {
            if (!$is_valid) {
                wp_die(esc_html__('Missing required fields.', 'hyperpay-service-gateway'));
            }
        }

        $result = $this->payment_service->createPayment(
            array(
                'amount' => (float) $amount,
                'currency' => $currency,
                'service_type' => $service_type,
                'service_reference_id' => $service_reference_id,
                'customer' => array(
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                    'country' => $country,
                    'city' => $city,
                    'street' => $street,
                ),
                'test_mode' => !empty(hyperpay_service_gateway_get_settings()['test_mode']),
            )
        );

        if (empty($result['success'])) {
            wp_die(esc_html((string) ($result['message'] ?? __('Could not create payment.', 'hyperpay-service-gateway'))));
        }

        wp_safe_redirect(esc_url_raw((string) $result['redirect_url']));
        exit;
    }
}
