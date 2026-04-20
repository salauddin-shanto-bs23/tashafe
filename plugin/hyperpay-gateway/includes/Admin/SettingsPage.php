<?php
/**
 * Admin settings helper page.
 *
 * @package HyperPayGateway\Admin
 */

namespace HyperPayGateway\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renders a lightweight admin page with integration diagnostics.
 */
class SettingsPage {

    /**
     * Register hooks.
     *
     * @return void
     */
    public function register() {
        add_action('admin_menu', array($this, 'register_page'));
    }

    /**
     * Register submenu page under WooCommerce.
     *
     * @return void
     */
    public function register_page() {
        add_submenu_page(
            'woocommerce',
            __('HyperPay Gateway', 'hyperpay-gateway'),
            __('HyperPay Gateway', 'hyperpay-gateway'),
            'manage_woocommerce',
            'hyperpay-gateway',
            array($this, 'render_page')
        );
    }

    /**
     * Render admin page.
     *
     * @return void
     */
    public function render_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'hyperpay-gateway'));
        }

        $webhook_url = rest_url('hyperpay/v1/webhook');
        $wc_settings_url = admin_url('admin.php?page=wc-settings&tab=checkout&section=hyperpay');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('HyperPay Gateway', 'hyperpay-gateway'); ?></h1>
            <p><?php echo esc_html__('Configure credentials from WooCommerce payment settings.', 'hyperpay-gateway'); ?></p>

            <table class="widefat striped" style="max-width: 960px; margin-top: 20px;">
                <tbody>
                    <tr>
                        <th style="width: 220px;"><?php echo esc_html__('WooCommerce Settings', 'hyperpay-gateway'); ?></th>
                        <td>
                            <a class="button button-primary" href="<?php echo esc_url($wc_settings_url); ?>">
                                <?php echo esc_html__('Open Gateway Settings', 'hyperpay-gateway'); ?>
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <th><?php echo esc_html__('Webhook URL', 'hyperpay-gateway'); ?></th>
                        <td>
                            <code><?php echo esc_html($webhook_url); ?></code>
                            <p style="margin-top: 8px; color: #555;">
                                <?php echo esc_html__('Set this URL inside your HyperPay dashboard for asynchronous payment updates.', 'hyperpay-gateway'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php echo esc_html__('Verification Model', 'hyperpay-gateway'); ?></th>
                        <td>
                            <?php echo esc_html__('Server-to-server verification is always used before changing order status.', 'hyperpay-gateway'); ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php
    }
}
