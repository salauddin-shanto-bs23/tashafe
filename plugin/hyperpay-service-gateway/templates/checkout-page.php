<?php
/**
 * Hosted checkout page.
 *
 * @var object $transaction Transaction row.
 * @var string $result_url Return URL.
 * @var string $brands HyperPay brands.
 * @var string $widget_url Widget JS URL.
 */

if (!defined('ABSPATH')) {
    exit;
}

status_header(200);
nocache_headers();
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo esc_html__('Secure Payment', 'hyperpay-service-gateway'); ?></title>
    <script src="<?php echo esc_url($widget_url); ?>"></script>
    <style>
        body{font-family:Arial,sans-serif;background:#f5f7fb;margin:0;padding:20px}
        .hyperpay-wrap{max-width:720px;margin:30px auto;background:#fff;border-radius:12px;padding:24px;box-shadow:0 8px 24px rgba(0,0,0,.08)}
    </style>
</head>
<body>
    <div class="hyperpay-wrap">
        <h2><?php echo esc_html__('Secure Payment', 'hyperpay-service-gateway'); ?></h2>
        <p><?php echo esc_html__('Please complete your payment below.', 'hyperpay-service-gateway'); ?></p>
        <form action="<?php echo esc_url($result_url); ?>" class="paymentWidgets" data-brands="<?php echo esc_attr($brands); ?>"></form>
    </div>
</body>
</html>
