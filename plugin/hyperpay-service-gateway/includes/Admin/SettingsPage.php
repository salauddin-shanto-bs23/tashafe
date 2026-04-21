<?php
/**
 * Settings page.
 *
 * @package HyperPayServiceGateway\Admin
 */

namespace HyperPayServiceGateway\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renders plugin settings page.
 */
class SettingsPage {

    /**
     * Register hooks.
     *
     * @return void
     */
    public function register() {
        add_action('admin_menu', array($this, 'register_page'));
        add_action('admin_init', array($this, 'register_settings'));
    }

    /**
     * Register settings.
     *
     * @return void
     */
    public function register_settings() {
        register_setting(
            'hyperpay_service_gateway',
            HYPERPAY_SERVICE_GATEWAY_OPTION_KEY,
            array($this, 'sanitize_settings')
        );
    }

    /**
     * Sanitize settings payload.
     *
     * @param array $input Raw settings.
     * @return array
     */
    public function sanitize_settings($input) {
        $input = is_array($input) ? $input : array();

        return array(
            'entity_id' => sanitize_text_field((string) ($input['entity_id'] ?? '')),
            'access_token' => sanitize_text_field((string) ($input['access_token'] ?? '')),
            'test_mode' => !empty($input['test_mode']) ? 1 : 0,
            'currency' => strtoupper(sanitize_text_field((string) ($input['currency'] ?? 'SAR'))),
            'shared_secret' => sanitize_text_field((string) ($input['shared_secret'] ?? '')),
        );
    }

    /**
     * Register admin menu.
     *
     * @return void
     */
    public function register_page() {
        add_menu_page(
            __('HyperPay Service Gateway', 'hyperpay-service-gateway'),
            __('HyperPay Gateway', 'hyperpay-service-gateway'),
            'manage_options',
            'hyperpay-service-gateway',
            array($this, 'render_page'),
            'dashicons-money-alt',
            30
        );

        add_submenu_page(
            'hyperpay-service-gateway',
            __('Settings', 'hyperpay-service-gateway'),
            __('Settings', 'hyperpay-service-gateway'),
            'manage_options',
            'hyperpay-service-gateway',
            array($this, 'render_page')
        );
    }

    /**
     * Render settings page.
     *
     * @return void
     */
    public function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'hyperpay-service-gateway'));
        }

        $settings = hyperpay_service_gateway_get_settings();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('HyperPay Service Gateway', 'hyperpay-service-gateway'); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields('hyperpay_service_gateway'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="hyperpay_entity_id"><?php echo esc_html__('Entity ID', 'hyperpay-service-gateway'); ?></label></th>
                        <td><input name="<?php echo esc_attr(HYPERPAY_SERVICE_GATEWAY_OPTION_KEY); ?>[entity_id]" id="hyperpay_entity_id" class="regular-text" value="<?php echo esc_attr($settings['entity_id']); ?>" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hyperpay_access_token"><?php echo esc_html__('Access Token', 'hyperpay-service-gateway'); ?></label></th>
                        <td><input name="<?php echo esc_attr(HYPERPAY_SERVICE_GATEWAY_OPTION_KEY); ?>[access_token]" id="hyperpay_access_token" class="regular-text" value="<?php echo esc_attr($settings['access_token']); ?>" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hyperpay_currency"><?php echo esc_html__('Currency', 'hyperpay-service-gateway'); ?></label></th>
                        <td><input name="<?php echo esc_attr(HYPERPAY_SERVICE_GATEWAY_OPTION_KEY); ?>[currency]" id="hyperpay_currency" class="small-text" value="<?php echo esc_attr($settings['currency']); ?>" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Test Mode', 'hyperpay-service-gateway'); ?></th>
                        <td><label><input type="checkbox" name="<?php echo esc_attr(HYPERPAY_SERVICE_GATEWAY_OPTION_KEY); ?>[test_mode]" value="1" <?php checked(!empty($settings['test_mode'])); ?>> <?php echo esc_html__('Use HyperPay sandbox environment', 'hyperpay-service-gateway'); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hyperpay_shared_secret"><?php echo esc_html__('Shared Secret', 'hyperpay-service-gateway'); ?></label></th>
                        <td>
                            <input name="<?php echo esc_attr(HYPERPAY_SERVICE_GATEWAY_OPTION_KEY); ?>[shared_secret]" id="hyperpay_shared_secret" class="regular-text" value="<?php echo esc_attr($settings['shared_secret']); ?>" required>
                            <p class="description"><?php echo esc_html__('Used to validate webhook signatures via HMAC SHA-256.', 'hyperpay-service-gateway'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Webhook URL', 'hyperpay-service-gateway'); ?></th>
                        <td><code><?php echo esc_html(rest_url('hyperpay/v1/webhook')); ?></code></td>
                    </tr>
                </table>
                <?php submit_button(__('Save Settings', 'hyperpay-service-gateway')); ?>
            </form>

            <hr>
            <h2><?php echo esc_html__('Developer Quickstart', 'hyperpay-service-gateway'); ?></h2>
            <p><?php echo esc_html__('Use the built-in shortcode to render a payment form for any service.', 'hyperpay-service-gateway'); ?></p>
            <p><code>[hyperpay_form amount="199.00" currency="SAR" service_type="course" reference_id="COURSE-101"]</code></p>

            <p><?php echo esc_html__('Helper functions available in theme/plugin code:', 'hyperpay-service-gateway'); ?></p>
            <ul style="list-style: disc; padding-left: 20px;">
                <li><code>hyperpay_get_transaction($id)</code></li>
                <li><code>hyperpay_get_transaction_by_checkout_id($checkout_id)</code></li>
                <li><code>hyperpay_get_transactions_by_email($email)</code></li>
                <li><code>hyperpay_is_payment_successful($transaction)</code></li>
                <li><code>hyperpay_is_payment_failed($transaction)</code></li>
            </ul>

            <p><?php echo esc_html__('Unified processed hook:', 'hyperpay-service-gateway'); ?></p>
            <pre style="background:#f6f7f7;padding:12px;overflow:auto;"><code><?php echo esc_html("add_action('hyperpay_payment_processed', function(\$transaction) {\n    if (\$transaction->status === 'success') {\n        // fulfill service\n    }\n});"); ?></code></pre>
        </div>
        <?php
    }
}
