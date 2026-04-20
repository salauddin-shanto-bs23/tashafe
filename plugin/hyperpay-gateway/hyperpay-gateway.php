<?php
/**
 * Plugin Name: HyperPay Gateway for WooCommerce
 * Plugin URI: https://example.com/
 * Description: WooCommerce payment gateway integration for HyperPay (OPPWA Copy and Pay).
 * Version: 1.0.0
 * Author: Tanafs
 * Author URI: https://example.com/
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: hyperpay-gateway
 * Domain Path: /languages
 *
 * @package HyperPayGateway
 */

if (!defined('ABSPATH')) {
    exit;
}

define('HYPERPAY_GATEWAY_VERSION', '1.0.0');
define('HYPERPAY_GATEWAY_FILE', __FILE__);
define('HYPERPAY_GATEWAY_PATH', plugin_dir_path(__FILE__));
define('HYPERPAY_GATEWAY_URL', plugin_dir_url(__FILE__));

require_once HYPERPAY_GATEWAY_PATH . 'includes/Core/HyperPayClient.php';
require_once HYPERPAY_GATEWAY_PATH . 'includes/Core/PaymentService.php';
require_once HYPERPAY_GATEWAY_PATH . 'includes/Core/WebhookHandler.php';
require_once HYPERPAY_GATEWAY_PATH . 'includes/API/RestRoutes.php';
require_once HYPERPAY_GATEWAY_PATH . 'includes/Admin/SettingsPage.php';

/**
 * Build a logger callback used by plugin services.
 *
 * @return callable
 */
function hyperpay_gateway_get_logger() {
    return static function ($level, $message, $context = array()) {
        $safe_level = sanitize_key((string) $level);
        $safe_message = sanitize_text_field((string) $message);

        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->log(
                $safe_level,
                $safe_message . ' ' . wp_json_encode($context),
                array('source' => 'hyperpay-gateway')
            );
            return;
        }

        error_log('[hyperpay-gateway][' . $safe_level . '] ' . $safe_message . ' ' . wp_json_encode($context));
    };
}

/**
 * Bootstrap plugin after plugins are loaded.
 *
 * @return void
 */
function hyperpay_gateway_bootstrap() {
    if (!class_exists('WooCommerce')) {
        add_action(
            'admin_notices',
            static function () {
                echo '<div class="notice notice-error"><p>'
                    . esc_html__('HyperPay Gateway requires WooCommerce to be installed and active.', 'hyperpay-gateway')
                    . '</p></div>';
            }
        );
        return;
    }

    require_once HYPERPAY_GATEWAY_PATH . 'includes/WooCommerce/WC_Gateway_HyperPay.php';

    $logger = hyperpay_gateway_get_logger();

    $admin_page = new \HyperPayGateway\Admin\SettingsPage();
    $admin_page->register();

    $payment_service = new \HyperPayGateway\Core\PaymentService(
        new \HyperPayGateway\Core\HyperPayClient(array(), $logger),
        $logger
    );

    $webhook_handler = new \HyperPayGateway\Core\WebhookHandler($payment_service, $logger);

    $routes = new \HyperPayGateway\API\RestRoutes($webhook_handler, $logger);
    $routes->register();

    add_filter(
        'woocommerce_payment_gateways',
        static function ($gateways) {
            $gateways[] = '\\HyperPayGateway\\WooCommerce\\WC_Gateway_HyperPay';
            return $gateways;
        }
    );
}
add_action('plugins_loaded', 'hyperpay_gateway_bootstrap', 20);

/**
 * Flush rewrite rules on activation.
 *
 * @return void
 */
function hyperpay_gateway_activate() {
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'hyperpay_gateway_activate');

/**
 * Flush rewrite rules on deactivation.
 *
 * @return void
 */
function hyperpay_gateway_deactivate() {
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'hyperpay_gateway_deactivate');
