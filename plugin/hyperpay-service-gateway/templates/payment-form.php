<?php
/**
 * Payment form template.
 *
 * @var array $atts Shortcode attributes.
 * @var array $fields Form fields.
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<form class="hyperpay-service-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <input type="hidden" name="action" value="hyperpay_submit_form">
    <input type="hidden" name="amount" value="<?php echo esc_attr((string) $atts['amount']); ?>">
    <input type="hidden" name="currency" value="<?php echo esc_attr((string) $atts['currency']); ?>">
    <input type="hidden" name="service_type" value="<?php echo esc_attr((string) $atts['service_type']); ?>">
    <input type="hidden" name="service_reference_id" value="<?php echo esc_attr((string) $atts['reference_id']); ?>">
    <?php wp_nonce_field('hyperpay_submit_form', 'hyperpay_form_nonce'); ?>

    <?php foreach ($fields as $key => $config) : ?>
        <p class="hyperpay-field">
            <label for="hyperpay-field-<?php echo esc_attr((string) $key); ?>">
                <?php echo esc_html((string) ($config['label'] ?? $key)); ?>
                <?php if (!empty($config['required'])) : ?>
                    <span class="hyperpay-required">*</span>
                <?php endif; ?>
            </label>
            <input
                id="hyperpay-field-<?php echo esc_attr((string) $key); ?>"
                name="<?php echo esc_attr((string) $key); ?>"
                type="<?php echo esc_attr((string) ($config['type'] ?? 'text')); ?>"
                <?php echo !empty($config['required']) ? 'required' : ''; ?>
            >
        </p>
    <?php endforeach; ?>

    <button type="submit" class="hyperpay-submit-button"><?php echo esc_html__('Pay Now', 'hyperpay-service-gateway'); ?></button>
</form>
