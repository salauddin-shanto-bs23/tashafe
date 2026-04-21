<?php
/**
 * Transactions page.
 *
 * @package HyperPayServiceGateway\Admin
 */

namespace HyperPayServiceGateway\Admin;

use HyperPayServiceGateway\Core\PaymentService;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renders transaction list in wp-admin.
 */
class TransactionsPage {

    /**
     * Payment service.
     *
     * @var PaymentService
     */
    protected $payment_service;

    /**
     * Constructor.
     *
     * @param PaymentService $payment_service Payment service.
     */
    public function __construct(PaymentService $payment_service) {
        $this->payment_service = $payment_service;
    }

    /**
     * Register menu.
     *
     * @return void
     */
    public function register() {
        add_action('admin_menu', array($this, 'register_page'));
    }

    /**
     * Register submenu page.
     *
     * @return void
     */
    public function register_page() {
        add_submenu_page(
            'hyperpay-service-gateway',
            __('Transactions', 'hyperpay-service-gateway'),
            __('Transactions', 'hyperpay-service-gateway'),
            'manage_options',
            'hyperpay-service-gateway-transactions',
            array($this, 'render_page')
        );
    }

    /**
     * Render transactions page.
     *
     * @return void
     */
    public function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'hyperpay-service-gateway'));
        }

        $status = sanitize_key((string) ($_GET['status'] ?? ''));
        $service_type = sanitize_key((string) ($_GET['service_type'] ?? ''));

        $transactions = $this->payment_service->list_transactions(
            array(
                'status' => $status,
                'service_type' => $service_type,
            )
        );
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('HyperPay Transactions', 'hyperpay-service-gateway'); ?></h1>

            <form method="get" style="margin: 16px 0;">
                <input type="hidden" name="page" value="hyperpay-service-gateway-transactions">
                <select name="status">
                    <option value=""><?php echo esc_html__('All Statuses', 'hyperpay-service-gateway'); ?></option>
                    <option value="pending" <?php selected($status, 'pending'); ?>><?php echo esc_html__('Pending', 'hyperpay-service-gateway'); ?></option>
                    <option value="success" <?php selected($status, 'success'); ?>><?php echo esc_html__('Success', 'hyperpay-service-gateway'); ?></option>
                    <option value="failed" <?php selected($status, 'failed'); ?>><?php echo esc_html__('Failed', 'hyperpay-service-gateway'); ?></option>
                    <option value="cancelled" <?php selected($status, 'cancelled'); ?>><?php echo esc_html__('Cancelled', 'hyperpay-service-gateway'); ?></option>
                </select>
                <input type="text" name="service_type" value="<?php echo esc_attr($service_type); ?>" placeholder="<?php echo esc_attr__('Service type', 'hyperpay-service-gateway'); ?>">
                <button class="button"><?php echo esc_html__('Filter', 'hyperpay-service-gateway'); ?></button>
            </form>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('Transaction ID', 'hyperpay-service-gateway'); ?></th>
                        <th><?php echo esc_html__('Name', 'hyperpay-service-gateway'); ?></th>
                        <th><?php echo esc_html__('Email', 'hyperpay-service-gateway'); ?></th>
                        <th><?php echo esc_html__('Phone', 'hyperpay-service-gateway'); ?></th>
                        <th><?php echo esc_html__('Service Type', 'hyperpay-service-gateway'); ?></th>
                        <th><?php echo esc_html__('Status', 'hyperpay-service-gateway'); ?></th>
                        <th><?php echo esc_html__('Amount', 'hyperpay-service-gateway'); ?></th>
                        <th><?php echo esc_html__('Date', 'hyperpay-service-gateway'); ?></th>
                        <th><?php echo esc_html__('Details', 'hyperpay-service-gateway'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($transactions)) : ?>
                        <tr>
                            <td colspan="9"><?php echo esc_html__('No transactions found.', 'hyperpay-service-gateway'); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($transactions as $transaction) : ?>
                            <?php $dialog_id = 'hyperpay-transaction-dialog-' . (int) $transaction->id; ?>
                            <tr>
                                <td><?php echo esc_html((string) $transaction->transaction_id); ?></td>
                                <td><?php echo esc_html((string) $transaction->customer_name); ?></td>
                                <td><?php echo esc_html((string) $transaction->customer_email); ?></td>
                                <td><?php echo esc_html((string) $transaction->customer_phone); ?></td>
                                <td><?php echo esc_html((string) $transaction->service_type); ?></td>
                                <td><?php echo esc_html(ucfirst((string) $transaction->status)); ?></td>
                                <td><?php echo esc_html((string) $transaction->amount . ' ' . (string) $transaction->currency); ?></td>
                                <td><?php echo esc_html((string) $transaction->created_at); ?></td>
                                <td>
                                    <button type="button" class="button button-secondary hyperpay-open-modal" data-dialog-id="<?php echo esc_attr($dialog_id); ?>">
                                        <?php echo esc_html__('View', 'hyperpay-service-gateway'); ?>
                                    </button>
                                </td>
                            </tr>

                            <dialog id="<?php echo esc_attr($dialog_id); ?>" class="hyperpay-transaction-dialog">
                                <h2 style="margin-top:0;"><?php echo esc_html__('Transaction Details', 'hyperpay-service-gateway'); ?></h2>
                                <table class="widefat striped" style="margin-bottom:12px;">
                                    <tbody>
                                        <tr><th><?php echo esc_html__('ID', 'hyperpay-service-gateway'); ?></th><td><?php echo esc_html((string) $transaction->id); ?></td></tr>
                                        <tr><th><?php echo esc_html__('Internal Transaction ID', 'hyperpay-service-gateway'); ?></th><td><?php echo esc_html((string) $transaction->transaction_id); ?></td></tr>
                                        <tr><th><?php echo esc_html__('Merchant Transaction ID', 'hyperpay-service-gateway'); ?></th><td><?php echo esc_html((string) $transaction->merchant_transaction_id); ?></td></tr>
                                        <tr><th><?php echo esc_html__('Checkout ID', 'hyperpay-service-gateway'); ?></th><td><?php echo esc_html((string) $transaction->checkout_id); ?></td></tr>
                                        <tr><th><?php echo esc_html__('Status', 'hyperpay-service-gateway'); ?></th><td><?php echo esc_html(ucfirst((string) $transaction->status)); ?></td></tr>
                                        <tr><th><?php echo esc_html__('Amount', 'hyperpay-service-gateway'); ?></th><td><?php echo esc_html((string) $transaction->amount . ' ' . (string) $transaction->currency); ?></td></tr>
                                        <tr><th><?php echo esc_html__('Customer Name', 'hyperpay-service-gateway'); ?></th><td><?php echo esc_html((string) $transaction->customer_name); ?></td></tr>
                                        <tr><th><?php echo esc_html__('Customer Email', 'hyperpay-service-gateway'); ?></th><td><?php echo esc_html((string) $transaction->customer_email); ?></td></tr>
                                        <tr><th><?php echo esc_html__('Customer Phone', 'hyperpay-service-gateway'); ?></th><td><?php echo esc_html((string) $transaction->customer_phone); ?></td></tr>
                                        <tr><th><?php echo esc_html__('Billing Country', 'hyperpay-service-gateway'); ?></th><td><?php echo esc_html((string) $transaction->billing_country); ?></td></tr>
                                        <tr><th><?php echo esc_html__('Billing City', 'hyperpay-service-gateway'); ?></th><td><?php echo esc_html((string) $transaction->billing_city); ?></td></tr>
                                        <tr><th><?php echo esc_html__('Billing Street', 'hyperpay-service-gateway'); ?></th><td><?php echo esc_html((string) $transaction->billing_street); ?></td></tr>
                                        <tr><th><?php echo esc_html__('Service Type', 'hyperpay-service-gateway'); ?></th><td><?php echo esc_html((string) $transaction->service_type); ?></td></tr>
                                        <tr><th><?php echo esc_html__('Service Reference ID', 'hyperpay-service-gateway'); ?></th><td><?php echo esc_html((string) $transaction->service_reference_id); ?></td></tr>
                                        <tr><th><?php echo esc_html__('Created At', 'hyperpay-service-gateway'); ?></th><td><?php echo esc_html((string) $transaction->created_at); ?></td></tr>
                                        <tr><th><?php echo esc_html__('Updated At', 'hyperpay-service-gateway'); ?></th><td><?php echo esc_html((string) $transaction->updated_at); ?></td></tr>
                                    </tbody>
                                </table>
                                <button type="button" class="button hyperpay-close-modal"><?php echo esc_html__('Close', 'hyperpay-service-gateway'); ?></button>
                            </dialog>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <style>
                .hyperpay-transaction-dialog {
                    width: min(900px, 92vw);
                    border: 1px solid #ccd0d4;
                    border-radius: 8px;
                    padding: 16px;
                }
                .hyperpay-transaction-dialog::backdrop {
                    background: rgba(0, 0, 0, 0.45);
                }
            </style>
            <script>
                (function() {
                    var openButtons = document.querySelectorAll('.hyperpay-open-modal');
                    for (var i = 0; i < openButtons.length; i++) {
                        openButtons[i].addEventListener('click', function() {
                            var id = this.getAttribute('data-dialog-id');
                            var dialog = document.getElementById(id);
                            if (dialog && typeof dialog.showModal === 'function') {
                                dialog.showModal();
                            }
                        });
                    }

                    var closeButtons = document.querySelectorAll('.hyperpay-close-modal');
                    for (var j = 0; j < closeButtons.length; j++) {
                        closeButtons[j].addEventListener('click', function() {
                            var dialog = this.closest('dialog');
                            if (dialog) {
                                dialog.close();
                            }
                        });
                    }
                })();
            </script>
        </div>
        <?php
    }
}
