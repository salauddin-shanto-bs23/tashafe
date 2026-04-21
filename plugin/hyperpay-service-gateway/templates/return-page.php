<?php
/**
 * Return page template.
 *
 * @var array|null  $verification Verification response.
 * @var object|null $transaction Transaction row.
 */

if (!defined('ABSPATH')) {
    exit;
}

$status = $transaction ? sanitize_key((string) $transaction->status) : 'pending';
$message = '';

if (is_array($verification) && !empty($verification['message'])) {
    $message = sanitize_text_field((string) $verification['message']);
}

if ('' === $message) {
    if ('success' === $status) {
        $message = __('Payment completed successfully.', 'hyperpay-service-gateway');
    } elseif ('failed' === $status || 'cancelled' === $status) {
        $message = __('Payment was not completed.', 'hyperpay-service-gateway');
    } else {
        $message = __('Payment is pending confirmation.', 'hyperpay-service-gateway');
    }
}
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo esc_html__('Payment Status', 'hyperpay-service-gateway'); ?></title>
    <style>
        body{font-family:Arial,sans-serif;background:#f5f7fb;margin:0;padding:20px}
        .hyperpay-result{max-width:640px;margin:30px auto;background:#fff;border-radius:12px;padding:24px;box-shadow:0 8px 24px rgba(0,0,0,.08)}
        .status{font-weight:700;text-transform:uppercase}
    </style>
</head>
<body>
    <div class="hyperpay-result">
        <h2><?php echo esc_html__('Payment Result', 'hyperpay-service-gateway'); ?></h2>
        <p class="status"><?php echo esc_html($status); ?></p>
        <p><?php echo esc_html($message); ?></p>
    </div>
</body>
</html>
