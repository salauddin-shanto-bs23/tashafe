<?php
/**
 * Plugin Name: HyperPay Service Gateway
 * Description: Standalone HyperPay (OPPWA Copy and Pay) gateway for service payments without WooCommerce.
 * Version: 1.0.0
 * Author: Tanafs
 * License: GPL-2.0+
 * Text Domain: hyperpay-service-gateway
 *
 * @package HyperPayServiceGateway
 */

if (!defined('ABSPATH')) {
    exit;
}

define('HYPERPAY_SERVICE_GATEWAY_VERSION', '1.0.0');
define('HYPERPAY_SERVICE_GATEWAY_FILE', __FILE__);
define('HYPERPAY_SERVICE_GATEWAY_PATH', plugin_dir_path(__FILE__));
define('HYPERPAY_SERVICE_GATEWAY_URL', plugin_dir_url(__FILE__));
define('HYPERPAY_SERVICE_GATEWAY_OPTION_KEY', 'hyperpay_service_gateway_settings');
define('HYPERPAY_SERVICE_GATEWAY_TABLE', 'hyperpay_transactions');

require_once HYPERPAY_SERVICE_GATEWAY_PATH . 'includes/Core/HyperPayClient.php';
require_once HYPERPAY_SERVICE_GATEWAY_PATH . 'includes/Core/PaymentService.php';
require_once HYPERPAY_SERVICE_GATEWAY_PATH . 'includes/Core/WebhookHandler.php';
require_once HYPERPAY_SERVICE_GATEWAY_PATH . 'includes/Database/Migration.php';
require_once HYPERPAY_SERVICE_GATEWAY_PATH . 'includes/API/RestRoutes.php';
require_once HYPERPAY_SERVICE_GATEWAY_PATH . 'includes/Admin/SettingsPage.php';
require_once HYPERPAY_SERVICE_GATEWAY_PATH . 'includes/Admin/TransactionsPage.php';
require_once HYPERPAY_SERVICE_GATEWAY_PATH . 'includes/Shortcodes/PaymentFormShortcode.php';

/**
 * Build plugin logger callback.
 *
 * @return callable
 */
function hyperpay_service_gateway_get_logger() {
    return static function ($level, $message, $context = array()) {
        $safe_level = sanitize_key((string) $level);
        $safe_message = sanitize_text_field((string) $message);

        error_log('[hyperpay-service-gateway][' . $safe_level . '] ' . $safe_message . ' ' . wp_json_encode($context));
    };
}

/**
 * Read plugin settings.
 *
 * @return array
 */
function hyperpay_service_gateway_get_settings() {
    $stored = get_option(HYPERPAY_SERVICE_GATEWAY_OPTION_KEY, array());
    $stored = is_array($stored) ? $stored : array();

    return array(
        'entity_id' => sanitize_text_field((string) ($stored['entity_id'] ?? '')),
        'access_token' => sanitize_text_field((string) ($stored['access_token'] ?? '')),
        'test_mode' => !empty($stored['test_mode']),
        'currency' => strtoupper(sanitize_text_field((string) ($stored['currency'] ?? 'SAR'))),
        'shared_secret' => sanitize_text_field((string) ($stored['shared_secret'] ?? '')),
    );
}

/**
 * Build payment service.
 *
 * @return \HyperPayServiceGateway\Core\PaymentService
 */
function hyperpay_service_gateway_payment_service() {
    static $service = null;

    if ($service instanceof \HyperPayServiceGateway\Core\PaymentService) {
        $service->set_settings(hyperpay_service_gateway_get_settings());
        return $service;
    }

    $logger = hyperpay_service_gateway_get_logger();
    $client = new \HyperPayServiceGateway\Core\HyperPayClient(hyperpay_service_gateway_get_settings(), $logger);
    $service = new \HyperPayServiceGateway\Core\PaymentService($client, $logger);

    return $service;
}

/**
 * Get transactions table name.
 *
 * @return string
 */
function hyperpay_service_gateway_table_name() {
    global $wpdb;
    return $wpdb->prefix . HYPERPAY_SERVICE_GATEWAY_TABLE;
}

/**
 * Render checkout and return pages.
 *
 * @return void
 */
function hyperpay_service_gateway_template_router() {
    if (empty($_GET['hyperpay_checkout']) && empty($_GET['hyperpay_return'])) {
        return;
    }

    $service = hyperpay_service_gateway_payment_service();

    if (!empty($_GET['hyperpay_checkout'])) {
        $transaction_id = sanitize_text_field((string) ($_GET['transaction_id'] ?? ''));
        $transaction = $service->get_transaction_by_merchant_transaction_id($transaction_id);

        if (!$transaction || empty($transaction->checkout_id)) {
            status_header(404);
            wp_die(esc_html__('Payment transaction not found.', 'hyperpay-service-gateway'));
        }

        $result_url = add_query_arg(
            array(
                'hyperpay_return' => '1',
                'transaction_id' => $transaction->merchant_transaction_id,
            ),
            home_url('/')
        );

        $brands = apply_filters('hyperpay_payment_brands', 'MADA VISA MASTER', $transaction);
        $widget_url = $service->get_widget_url($transaction->checkout_id);

        include HYPERPAY_SERVICE_GATEWAY_PATH . 'templates/checkout-page.php';
        exit;
    }

    if (!empty($_GET['hyperpay_return'])) {
        $transaction_id = sanitize_text_field((string) ($_GET['transaction_id'] ?? ''));
        $resource_path = sanitize_text_field((string) ($_GET['resourcePath'] ?? ($_GET['resource_path'] ?? '')));
        $checkout_id = sanitize_text_field((string) ($_GET['id'] ?? ($_GET['checkout_id'] ?? '')));

        $verification = $service->verifyPayment(
            !empty($checkout_id) ? $checkout_id : '',
            array(
                'merchant_transaction_id' => $transaction_id,
                'resource_path' => $resource_path,
                'allow_query_fallback' => true,
            )
        );

        $transaction = $service->get_transaction_by_merchant_transaction_id($transaction_id);

        if ($transaction && !empty($verification['success'])) {
            $service->apply_verified_result($transaction, $verification, 'return');
            $transaction = $service->get_transaction((int) $transaction->id);
        }

        include HYPERPAY_SERVICE_GATEWAY_PATH . 'templates/return-page.php';
        exit;
    }
}
add_action('template_redirect', 'hyperpay_service_gateway_template_router', 5);

/**
 * Bootstrap plugin components.
 *
 * @return void
 */
function hyperpay_service_gateway_bootstrap() {
    $logger = hyperpay_service_gateway_get_logger();
    $payment_service = hyperpay_service_gateway_payment_service();
    $webhook_handler = new \HyperPayServiceGateway\Core\WebhookHandler($payment_service, $logger);

    $migration = new \HyperPayServiceGateway\Database\Migration();
    $migration->register();

    $settings_page = new \HyperPayServiceGateway\Admin\SettingsPage();
    $settings_page->register();

    $transactions_page = new \HyperPayServiceGateway\Admin\TransactionsPage($payment_service);
    $transactions_page->register();

    $shortcode = new \HyperPayServiceGateway\Shortcodes\PaymentFormShortcode($payment_service, $logger);
    $shortcode->register();

    $routes = new \HyperPayServiceGateway\API\RestRoutes($payment_service, $webhook_handler, $logger);
    $routes->register();
}
add_action('plugins_loaded', 'hyperpay_service_gateway_bootstrap');

/**
 * Plugin activation hook.
 *
 * @return void
 */
function hyperpay_service_gateway_activate() {
    \HyperPayServiceGateway\Database\Migration::run();
}
register_activation_hook(__FILE__, 'hyperpay_service_gateway_activate');

/**
 * Get a transaction by primary key.
 *
 * @param int $id Transaction row id.
 * @return object|null
 */
function hyperpay_get_transaction($id) {
    return hyperpay_service_gateway_payment_service()->get_transaction(absint($id));
}

/**
 * Get a transaction by checkout id.
 *
 * @param string $checkout_id Checkout id.
 * @return object|null
 */
function hyperpay_get_transaction_by_checkout_id($checkout_id) {
    return hyperpay_service_gateway_payment_service()->get_transaction_by_checkout_id($checkout_id);
}

/**
 * Get transactions by customer email.
 *
 * @param string $email Customer email.
 * @return array
 */
function hyperpay_get_transactions_by_email($email) {
    return hyperpay_service_gateway_payment_service()->get_transactions_by_email($email);
}

/**
 * Check if a transaction is successful.
 *
 * @param object|array $transaction Transaction payload.
 * @return bool
 */
function hyperpay_is_payment_successful($transaction) {
    $status = '';

    if (is_object($transaction)) {
        $status = (string) ($transaction->status ?? '');
    } elseif (is_array($transaction)) {
        $status = (string) ($transaction['status'] ?? '');
    }

    return 'success' === sanitize_key($status);
}

/**
 * Check if a transaction has failed.
 *
 * @param object|array $transaction Transaction payload.
 * @return bool
 */
function hyperpay_is_payment_failed($transaction) {
    $status = '';

    if (is_object($transaction)) {
        $status = (string) ($transaction->status ?? '');
    } elseif (is_array($transaction)) {
        $status = (string) ($transaction['status'] ?? '');
    }

    $status = sanitize_key($status);

    return in_array($status, array('failed', 'cancelled'), true);
}
