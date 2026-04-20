<?php
/**
 * WooCommerce HyperPay gateway class.
 *
 * @package HyperPayGateway\WooCommerce
 */

namespace HyperPayGateway\WooCommerce;

use HyperPayGateway\Core\HyperPayClient;
use HyperPayGateway\Core\PaymentService;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * HyperPay payment gateway for WooCommerce.
 */
class WC_Gateway_HyperPay extends \WC_Payment_Gateway {

    /**
     * Gateway id.
     *
     * @var string
     */
    public $id = 'hyperpay';

    /**
     * API entity id.
     *
     * @var string
     */
    protected $entity_id = '';

    /**
     * API access token.
     *
     * @var string
     */
    protected $access_token = '';

    /**
     * Test mode flag.
     *
     * @var string
     */
    protected $test_mode = 'yes';

    /**
     * Constructor.
     */
    public function __construct() {
        $this->method_title       = __('HyperPay', 'hyperpay-gateway');
        $this->method_description = __('Accept card payments through HyperPay.', 'hyperpay-gateway');
        $this->has_fields         = false;
        $this->supports           = array('products');

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option('title', __('Credit Card (HyperPay)', 'hyperpay-gateway'));
        $this->description = $this->get_option('description', __('Pay securely via HyperPay.', 'hyperpay-gateway'));
        $this->enabled     = $this->get_option('enabled', 'no');

        $this->entity_id    = sanitize_text_field((string) $this->get_option('entity_id', ''));
        $this->access_token = sanitize_text_field((string) $this->get_option('access_token', ''));
        $this->test_mode    = ('yes' === $this->get_option('test_mode', 'yes')) ? 'yes' : 'no';

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_api_' . strtolower(get_class($this)), array($this, 'handle_callback'));
        add_action('template_redirect', array($this, 'maybe_render_payment_page'));
    }

    /**
     * Initialize admin form fields.
     *
     * @return void
     */
    public function init_form_fields() {
        $webhook_url = esc_url(rest_url('hyperpay/v1/webhook'));

        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'hyperpay-gateway'),
                'type' => 'checkbox',
                'label' => __('Enable HyperPay', 'hyperpay-gateway'),
                'default' => 'no',
            ),
            'title' => array(
                'title' => __('Title', 'hyperpay-gateway'),
                'type' => 'text',
                'description' => __('Controls the title shown at checkout.', 'hyperpay-gateway'),
                'default' => __('Credit Card (HyperPay)', 'hyperpay-gateway'),
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => __('Description', 'hyperpay-gateway'),
                'type' => 'textarea',
                'description' => __('Controls the description shown at checkout.', 'hyperpay-gateway'),
                'default' => __('Pay securely via HyperPay.', 'hyperpay-gateway'),
            ),
            'entity_id' => array(
                'title' => __('Entity ID', 'hyperpay-gateway'),
                'type' => 'text',
                'description' => __('HyperPay entity id from your account.', 'hyperpay-gateway'),
                'desc_tip' => true,
                'default' => '',
            ),
            'access_token' => array(
                'title' => __('Access Token', 'hyperpay-gateway'),
                'type' => 'password',
                'description' => __('Server-side HyperPay access token.', 'hyperpay-gateway'),
                'desc_tip' => true,
                'default' => '',
            ),
            'test_mode' => array(
                'title' => __('Test Mode', 'hyperpay-gateway'),
                'type' => 'checkbox',
                'label' => __('Enable test mode', 'hyperpay-gateway'),
                'default' => 'yes',
            ),
            'webhook_info' => array(
                'title' => __('Webhook URL', 'hyperpay-gateway'),
                'type' => 'title',
                'description' => sprintf(
                    __('Configure this URL in HyperPay dashboard: %s', 'hyperpay-gateway'),
                    '<code>' . $webhook_url . '</code>'
                ),
            ),
        );
    }

    /**
     * Validate gateway availability.
     *
     * @return bool
     */
    public function is_available() {
        if ('yes' !== $this->enabled) {
            return false;
        }

        if (empty($this->entity_id) || empty($this->access_token)) {
            return false;
        }

        return parent::is_available();
    }

    /**
     * Process checkout payment request.
     *
     * @param int $order_id Order id.
     * @return array
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);

        if (!$order) {
            wc_add_notice(__('Invalid order.', 'hyperpay-gateway'), 'error');
            return array('result' => 'fail');
        }

        $callback_url = add_query_arg(
            array(
                'wc-api' => strtolower(get_class($this)),
                'order_id' => $order->get_id(),
                'key' => $order->get_order_key(),
            ),
            home_url('/')
        );

        $customer = array(
            'name' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
            'email' => $order->get_billing_email(),
            'phone' => $order->get_billing_phone(),
            'country' => $order->get_billing_country(),
            'city' => $order->get_billing_city(),
            'state' => $order->get_billing_state(),
            'street1' => $order->get_billing_address_1(),
            'postcode' => $order->get_billing_postcode(),
        );

        $payment_service = $this->get_payment_service();

        $create = $payment_service->createCheckout(
            (float) $order->get_total(),
            $order->get_currency(),
            $order->get_id(),
            array(
                'customer' => $customer,
                'shopper_result_url' => $callback_url,
                'test_mode' => ('yes' === $this->test_mode),
                'payment_method' => 'card',
            )
        );

        if (empty($create['success'])) {
            $message = sanitize_text_field((string) ($create['message'] ?? __('Unable to initiate HyperPay checkout.', 'hyperpay-gateway')));
            wc_add_notice($message, 'error');

            $this->log('error', 'Checkout initiation failed', array(
                'order_id' => $order->get_id(),
                'message' => $message,
            ));

            return array('result' => 'fail');
        }

        $order->update_meta_data('_hyperpay_checkout_id', sanitize_text_field((string) $create['checkout_id']));
        $order->update_meta_data('_hyperpay_widget_url', esc_url_raw((string) $create['widget_url']));
        $order->update_meta_data('_hyperpay_widget_integrity', sanitize_text_field((string) ($create['widget_integrity'] ?? '')));
        $order->update_meta_data('_hyperpay_merchant_transaction_id', sanitize_text_field((string) ($create['merchant_transaction_id'] ?? '')));
        $order->update_meta_data('_hyperpay_callback_url', esc_url_raw($callback_url));
        $order->update_meta_data('_hyperpay_response_data', wp_json_encode($create['response_data'] ?? array()));

        if (!in_array($order->get_status(), array('pending', 'on-hold'), true)) {
            $order->update_status('pending', __('Awaiting HyperPay payment.', 'hyperpay-gateway'));
        } else {
            $order->add_order_note(__('HyperPay checkout session created.', 'hyperpay-gateway'));
        }

        $order->save();

        return array(
            'result' => 'success',
            'redirect' => $this->get_payment_page_url($order),
        );
    }

    /**
     * Render hosted payment page for HyperPay widget.
     *
     * @return void
     */
    public function maybe_render_payment_page() {
        $is_payment_page = isset($_GET['hyperpay_pay']) && '1' === sanitize_text_field((string) $_GET['hyperpay_pay']);

        if (!$is_payment_page) {
            return;
        }

        $order_id = absint($_GET['order_id'] ?? 0);
        $key = sanitize_text_field((string) ($_GET['key'] ?? ''));

        $order = wc_get_order($order_id);
        if (!$order || $order->get_order_key() !== $key) {
            wp_die(esc_html__('Invalid payment request.', 'hyperpay-gateway'));
        }

        $checkout_id = sanitize_text_field((string) $order->get_meta('_hyperpay_checkout_id', true));
        $widget_url = esc_url_raw((string) $order->get_meta('_hyperpay_widget_url', true));
        $integrity = sanitize_text_field((string) $order->get_meta('_hyperpay_widget_integrity', true));
        $callback_url = esc_url_raw((string) $order->get_meta('_hyperpay_callback_url', true));

        if (empty($checkout_id) || empty($widget_url) || empty($callback_url)) {
            wp_die(esc_html__('HyperPay checkout session is missing.', 'hyperpay-gateway'));
        }

        nocache_headers();
        status_header(200);
        ?>
        <!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php echo esc_html__('Secure Payment', 'hyperpay-gateway'); ?></title>
            <script src="<?php echo esc_url($widget_url); ?>"<?php echo !empty($integrity) ? ' integrity="' . esc_attr($integrity) . '" crossorigin="anonymous"' : ''; ?>></script>
            <style>
                body { font-family: Arial, sans-serif; margin: 0; padding: 24px; background: #f5f7fb; }
                .wrap { max-width: 700px; margin: 24px auto; background: #fff; border-radius: 12px; padding: 24px; box-shadow: 0 8px 24px rgba(0,0,0,0.08); }
                h2 { margin-top: 0; }
                p { color: #666; }
            </style>
        </head>
        <body>
            <div class="wrap">
                <h2><?php echo esc_html__('Secure Payment', 'hyperpay-gateway'); ?></h2>
                <p><?php echo esc_html__('Please complete your payment to finalize the order.', 'hyperpay-gateway'); ?></p>
                <form action="<?php echo esc_url($callback_url); ?>" class="paymentWidgets" data-brands="MADA VISA MASTER"></form>
            </div>
        </body>
        </html>
        <?php
        exit;
    }

    /**
     * Handle shopper return callback from HyperPay.
     *
     * @return void
     */
    public function handle_callback() {
        $order_id = absint($_REQUEST['order_id'] ?? 0);
        $key = sanitize_text_field((string) ($_REQUEST['key'] ?? ''));

        $order = wc_get_order($order_id);
        if (!$order || $order->get_order_key() !== $key) {
            wp_die(esc_html__('Invalid callback context.', 'hyperpay-gateway'));
        }

        $resource_path = sanitize_text_field(
            (string) ($_REQUEST['resourcePath']
                ?? ($_REQUEST['resource_path']
                ?? ($_REQUEST['resourcepath'] ?? '')))
        );

        $checkout_id = sanitize_text_field(
            (string) ($_REQUEST['id']
                ?? ($_REQUEST['checkoutId']
                ?? ($_REQUEST['checkout_id'] ?? $order->get_meta('_hyperpay_checkout_id', true))))
        );

        $merchant_transaction_id = sanitize_text_field((string) $order->get_meta('_hyperpay_merchant_transaction_id', true));

        $verify = $this->get_payment_service()->verifyPayment(
            $checkout_id,
            array(
                'resource_path' => $resource_path,
                'merchant_transaction_id' => $merchant_transaction_id,
                'allow_query_fallback' => true,
            )
        );

        if (empty($verify['success'])) {
            wc_add_notice(__('Could not verify payment yet. Please retry shortly.', 'hyperpay-gateway'), 'error');
            $order->add_order_note('HyperPay verification failed on return callback.');
            wp_safe_redirect($order->get_checkout_payment_url());
            exit;
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
                sprintf('HyperPay payment verified successfully. Result code: %s', $result_code)
            );
            $order->save();
            wp_safe_redirect($this->get_return_url($order));
            exit;
        }

        if ('failed' === $status) {
            $order->update_status('failed', __('HyperPay payment failed.', 'hyperpay-gateway'));
            $order->save();
            wc_add_notice(__('Payment failed. Please try another payment method.', 'hyperpay-gateway'), 'error');
            wp_safe_redirect($order->get_checkout_payment_url());
            exit;
        }

        $order->update_status('on-hold', __('HyperPay payment is pending confirmation.', 'hyperpay-gateway'));
        $order->save();
        wc_add_notice(__('Payment is pending confirmation. Please refresh this page in a moment.', 'hyperpay-gateway'), 'notice');
        wp_safe_redirect($order->get_checkout_payment_url());
        exit;
    }

    /**
     * Build local hosted payment page URL.
     *
     * @param \WC_Order $order WooCommerce order.
     * @return string
     */
    protected function get_payment_page_url($order) {
        return add_query_arg(
            array(
                'hyperpay_pay' => 1,
                'order_id' => $order->get_id(),
                'key' => $order->get_order_key(),
            ),
            home_url('/')
        );
    }

    /**
     * Build payment service instance using current settings.
     *
     * @return PaymentService
     */
    protected function get_payment_service() {
        $settings = array(
            'entity_id' => $this->entity_id,
            'access_token' => $this->access_token,
            'test_mode' => ('yes' === $this->test_mode),
        );

        $client = new HyperPayClient($settings, array($this, 'log'));

        return new PaymentService($client, array($this, 'log'));
    }

    /**
     * Log gateway events.
     *
     * @param string $level Level.
     * @param string $message Message.
     * @param array  $context Context.
     * @return void
     */
    public function log($level, $message, $context = array()) {
        $safe_level = sanitize_key((string) $level);
        $safe_message = sanitize_text_field((string) $message);

        if (function_exists('wc_get_logger')) {
            wc_get_logger()->log(
                $safe_level,
                $safe_message . ' ' . wp_json_encode($context),
                array('source' => 'hyperpay-gateway')
            );
            return;
        }

        error_log('[hyperpay-gateway][' . $safe_level . '] ' . $safe_message . ' ' . wp_json_encode($context));
    }
}
