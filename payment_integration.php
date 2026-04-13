<?php
/**
 * Unified Payment Integration System for Tanafs
 * Shared HyperPay integration serving therapy, retreat, and academy bookings
 * 
 * Features:
 * - Single payment configuration page for HyperPay credentials
 * - Unified payment processing for all booking types (therapy, retreat, academy)
 * - booking_type field tracking for payment routing
 * - IPN/webhook handler with server-side verification
 * - Payment tracking database table
 * - Admin interface to list all payments with filters
 * 
 * Architecture:
 * - All payments recorded as 'pending' at initiation
 * - IPN callback updates status to 'complete', 'failed', or remains 'pending'
 * - Idempotent webhook handling prevents duplicate processing
 * - Module-specific booking fulfillment functions called from IPN
 * 
 * @package Tanafs
 * @version 2.0.0
 * @gateway HyperPay (OPPWA Copy and Pay)
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// ============================================================================
// DATABASE SCHEMA - PAYMENTS TABLE
// ============================================================================

/**
 * Create unified payments tracking table
 * Stores all payment transactions across therapy, retreat, and academy modules
 */
add_action('init', 'tanafs_create_payments_table');
function tanafs_create_payments_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'tanafs_payments';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        transaction_id VARCHAR(100) NOT NULL COMMENT 'Gateway transaction identifier',
        booking_token VARCHAR(100) NOT NULL COMMENT 'Unique booking identifier (cart_id)',
        booking_reference VARCHAR(100) DEFAULT NULL COMMENT 'External booking reference',
        booking_type VARCHAR(50) NOT NULL COMMENT 'therapy, retreat, academy, etc.',
        hyperpay_checkout_id VARCHAR(120) DEFAULT NULL COMMENT 'HyperPay checkout/session id',
        hyperpay_entity_id VARCHAR(64) DEFAULT NULL COMMENT 'Entity id used during checkout creation',
        customer_name VARCHAR(255) NOT NULL,
        customer_email VARCHAR(255) NOT NULL,
        customer_phone VARCHAR(50) DEFAULT NULL,
        amount DECIMAL(10,2) NOT NULL,
        currency VARCHAR(10) NOT NULL DEFAULT 'SAR',
        status VARCHAR(20) DEFAULT NULL COMMENT 'Compatibility status mirror: pending, complete, failed',
        payment_status VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending, complete, failed',
        payment_method VARCHAR(50) DEFAULT NULL COMMENT 'VISA, MASTERCARD, etc.',
        aps_response_code VARCHAR(20) DEFAULT NULL,
        aps_response_message VARCHAR(255) DEFAULT NULL,
        response_data LONGTEXT DEFAULT NULL COMMENT 'Full APS response JSON',
        ip_address VARCHAR(50) DEFAULT NULL,
        user_agent TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY transaction_id (transaction_id),
        KEY booking_token (booking_token),
        KEY booking_reference (booking_reference),
        KEY booking_type (booking_type),
        KEY hyperpay_checkout_id (hyperpay_checkout_id),
        KEY status (status),
        KEY payment_status (payment_status),
        KEY customer_email (customer_email),
        KEY created_at (created_at)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

/**
 * Additive schema upgrades for existing installations.
 *
 * Keeps old data intact while ensuring HyperPay-required columns exist.
 */
add_action('init', 'tanafs_upgrade_payments_table_schema', 20);
function tanafs_upgrade_payments_table_schema() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'tanafs_payments';
    $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));

    if ($table_exists !== $table_name) {
        return;
    }

    $required_columns = [
        'booking_reference' => "VARCHAR(100) DEFAULT NULL COMMENT 'External booking reference'",
        'hyperpay_checkout_id' => "VARCHAR(120) DEFAULT NULL COMMENT 'HyperPay checkout/session id'",
        'hyperpay_entity_id' => "VARCHAR(64) DEFAULT NULL COMMENT 'Entity id used during checkout creation'",
        'status' => "VARCHAR(20) DEFAULT NULL COMMENT 'Compatibility status mirror: pending, complete, failed'",
        'payment_status' => "VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending, complete, failed'",
        'response_data' => "LONGTEXT DEFAULT NULL COMMENT 'Full APS response JSON'",
        'updated_at' => "DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
    ];

    foreach ($required_columns as $column_name => $column_sql) {
        $column_exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table_name} LIKE %s", $column_name));
        if (!$column_exists) {
            $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN {$column_name} {$column_sql}");
        }
    }

    // Keep old rows compatible with new status checks.
    $wpdb->query("UPDATE {$table_name} SET payment_status = 'pending' WHERE payment_status IS NULL OR payment_status = ''");
    $wpdb->query("UPDATE {$table_name} SET status = payment_status WHERE (status IS NULL OR status = '') AND payment_status IS NOT NULL");
}

// ============================================================================
// ADMIN MENU - PAYMENT INTEGRATION
// ============================================================================

/**
 * Register admin menu and submenus
 */
add_action('admin_menu', 'tanafs_payment_admin_menu');
function tanafs_payment_admin_menu() {
    // Main menu: Payment Integration
    add_menu_page(
        'Payment Integration',           // Page title
        'Payment Integration',           // Menu title
        'manage_options',                // Capability
        'tanafs-payment-integration',    // Menu slug
        'tanafs_render_payment_config_page', // Callback for first submenu
        'dashicons-money-alt',           // Icon
        30                               // Position
    );

    // Submenu 1: Payment Configuration
    add_submenu_page(
        'tanafs-payment-integration',
        'Payment Configuration',
        'Payment Configuration',
        'manage_options',
        'tanafs-payment-integration', // Same as parent to make it default
        'tanafs_render_payment_config_page'
    );

    // Submenu 2: All Payments
    add_submenu_page(
        'tanafs-payment-integration',
        'All Payments',
        'All Payments',
        'manage_options',
        'tanafs-all-payments',
        'tanafs_render_all_payments_page'
    );
}

// ============================================================================
// ADMIN PAGE 1: PAYMENT CONFIGURATION
// ============================================================================

/**
 * Render HyperPay configuration page
 */
function tanafs_render_payment_config_page() {
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    // Save settings if form submitted
    if (isset($_POST['tanafs_save_hyperpay_settings']) && check_admin_referer('tanafs_hyperpay_settings_nonce')) {
        $entity_id = sanitize_text_field($_POST['entity_id'] ?? '');
        $access_token = sanitize_text_field($_POST['access_token'] ?? '');
        $mode = sanitize_text_field($_POST['mode'] ?? 'sandbox');
        $currency = sanitize_text_field($_POST['currency'] ?? 'SAR');
        $force_external_test_mode = isset($_POST['force_external_test_mode']) ? '1' : '0';
        $sandbox_minimal_payload = isset($_POST['sandbox_minimal_payload']) ? '1' : '0';
        $enable_card = isset($_POST['enable_card']) ? '1' : '0';
        $enable_tamara = isset($_POST['enable_tamara']) ? '1' : '0';
        $enable_applepay = isset($_POST['enable_applepay']) ? '1' : '0';

        // Keep compatibility with existing mode checks that use "live".
        if ($mode === 'production') {
            $mode = 'live';
        }

        update_option('tanafs_hyperpay_entity_id', $entity_id);
        update_option('tanafs_hyperpay_access_token', $access_token);
        update_option('tanafs_hyperpay_mode', $mode);
        update_option('tanafs_hyperpay_currency', $currency);
        update_option('tanafs_hyperpay_force_external_test_mode', $force_external_test_mode);
        update_option('tanafs_hyperpay_sandbox_minimal_payload', $sandbox_minimal_payload);
        update_option('tanafs_hyperpay_enable_card', $enable_card);
        update_option('tanafs_hyperpay_enable_tamara', $enable_tamara);
        update_option('tanafs_hyperpay_enable_applepay', $enable_applepay);

        // Keep mode/currency mirrored during migration so legacy reads do not break.
        update_option('tanafs_aps_mode', $mode);
        update_option('tanafs_aps_currency', $currency);

        echo '<div class="notice notice-success is-dismissible"><p>HyperPay settings saved successfully!</p></div>';
    }

    // Get current settings
    $entity_id = get_option('tanafs_hyperpay_entity_id', '');
    $access_token = get_option('tanafs_hyperpay_access_token', '');
    $mode = get_option('tanafs_hyperpay_mode', get_option('tanafs_aps_mode', 'sandbox'));
    $currency = get_option('tanafs_hyperpay_currency', get_option('tanafs_aps_currency', 'SAR'));
    $force_external_test_mode = get_option('tanafs_hyperpay_force_external_test_mode', '0');
    $sandbox_minimal_payload = get_option('tanafs_hyperpay_sandbox_minimal_payload', '1');
    $enable_card = get_option('tanafs_hyperpay_enable_card', '1');
    $enable_tamara = get_option('tanafs_hyperpay_enable_tamara', '1');
    $enable_applepay = get_option('tanafs_hyperpay_enable_applepay', '1');
    
    ?>
    <div class="wrap">
        <h1>HyperPay Configuration</h1>
        <p class="description">Configure HyperPay Copy and Pay for Therapy, Retreat, and Academy bookings</p>

        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        
        <style>
            .gateway-settings-container {
                max-width: 900px;
                margin-top: 30px;
            }
            .settings-card {
                background: #fff;
                border-radius: 10px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.08);
                padding: 30px;
                margin-bottom: 25px;
            }
            .settings-card h2 {
                color: #6059A6;
                font-size: 20px;
                margin-bottom: 20px;
                border-bottom: 2px solid #f0f0f0;
                padding-bottom: 10px;
            }
            .form-label {
                font-weight: 600;
                color: #333;
                margin-bottom: 8px;
            }
            .mode-badge {
                display: inline-block;
                padding: 4px 12px;
                border-radius: 20px;
                font-size: 12px;
                font-weight: 600;
                margin-left: 10px;
            }
            .mode-sandbox {
                background: #fff3cd;
                color: #856404;
            }
            .mode-live {
                background: #d4edda;
                color: #155724;
            }
            .btn-save {
                background: linear-gradient(135deg, #C3DDD2, #6059A6);
                border: none;
                color: #fff;
                padding: 12px 30px;
                border-radius: 8px;
                font-weight: 600;
                font-size: 15px;
            }
            .btn-save:hover {
                opacity: 0.9;
                color: #fff;
            }
            .info-box {
                background: #e7f3ff;
                border-left: 4px solid #0066cc;
                padding: 15px;
                margin: 20px 0;
                border-radius: 4px;
            }
            .warning-box {
                background: #fff3cd;
                border-left: 4px solid #ffc107;
                padding: 15px;
                margin: 20px 0;
                border-radius: 4px;
            }
        </style>

        <div class="gateway-settings-container">
            <div class="info-box">
                <strong>About HyperPay (OPPWA Copy and Pay)</strong><br>
                HyperPay checkout is used as the shared payment gateway across therapy, retreat, and academy booking flows.
            </div>

            <!-- API Credentials Card -->
            <div class="settings-card">
                <h2>API Credentials</h2>
                <form method="post" action="">
                      <?php wp_nonce_field('tanafs_hyperpay_settings_nonce'); ?>
                    
                    <div class="mb-4">
                       <label class="form-label">Entity ID <span class="text-danger">*</span></label>
                       <input type="text" name="entity_id" class="form-control"
                           value="<?php echo esc_attr($entity_id); ?>" required
                           placeholder="e.g., 8ac7a4c793...">
                       <small class="text-muted">Found in HyperPay dashboard credentials</small>
                    </div>

                    <div class="mb-4">
                       <label class="form-label">Access Token <span class="text-danger">*</span></label>
                       <input type="text" name="access_token" class="form-control"
                           value="<?php echo esc_attr($access_token); ?>" required
                           placeholder="Bearer token">
                       <small class="text-muted">Used for server-to-server checkout and verification requests</small>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Mode <span class="text-danger">*</span></label>
                        <select name="mode" class="form-control" required>
                            <option value="sandbox" <?php selected($mode, 'sandbox'); ?>>Sandbox (Testing)</option>
                            <option value="live" <?php selected($mode, 'live'); ?>>Production (Live)</option>
                        </select>
                        <small class="text-muted">Use Sandbox for testing and Production for live transactions</small>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Default Currency <span class="text-danger">*</span></label>
                        <select name="currency" class="form-control" required>
                            <option value="SAR" <?php selected($currency, 'SAR'); ?>>SAR - Saudi Riyal</option>
                            <option value="AED" <?php selected($currency, 'AED'); ?>>AED - UAE Dirham</option>
                            <option value="USD" <?php selected($currency, 'USD'); ?>>USD - US Dollar</option>
                            <option value="EUR" <?php selected($currency, 'EUR'); ?>>EUR - Euro</option>
                        </select>
                    </div>

                    <div class="mb-4 form-check">
                        <input type="checkbox" class="form-check-input" id="force_external_test_mode" name="force_external_test_mode" value="1" <?php checked($force_external_test_mode, '1'); ?>>
                        <label class="form-check-label" for="force_external_test_mode">Force Sandbox EXTERNAL + 3DS2 Challenge Parameters</label>
                        <small class="text-muted d-block">Keep this disabled unless HyperPay explicitly confirms your test entity requires these parameters for Copy and Pay checkout sessions.</small>
                    </div>

                    <div class="mb-4 form-check">
                        <input type="checkbox" class="form-check-input" id="sandbox_minimal_payload" name="sandbox_minimal_payload" value="1" <?php checked($sandbox_minimal_payload, '1'); ?>>
                        <label class="form-check-label" for="sandbox_minimal_payload">Use Sandbox Minimal Checkout Payload</label>
                        <small class="text-muted d-block">Recommended for debugging when checkouts are created but no transaction is materialized in portal. This keeps only core fields used in known-working manual tests.</small>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Enabled Payment Methods</label>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="enable_card" name="enable_card" value="1" <?php checked($enable_card, '1'); ?>>
                            <label class="form-check-label" for="enable_card">Mada / Visa / Mastercard</label>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="enable_tamara" name="enable_tamara" value="1" <?php checked($enable_tamara, '1'); ?>>
                            <label class="form-check-label" for="enable_tamara">Tamara</label>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="enable_applepay" name="enable_applepay" value="1" <?php checked($enable_applepay, '1'); ?>>
                            <label class="form-check-label" for="enable_applepay">Apple Pay</label>
                        </div>
                        <small class="text-muted d-block mt-2">All methods are enabled by default.</small>
                    </div>

                    <div class="warning-box">
                        <strong>Security Note:</strong> Never expose HyperPay access tokens in frontend code.
                        Keep credentials server-side only and rotate them if compromised.
                    </div>

                    <button type="submit" name="tanafs_save_hyperpay_settings" class="btn btn-save">
                        Save Configuration
                    </button>
                </form>
            </div>

            <!-- Documentation Card -->
            <div class="settings-card">
                <h2>Integration Endpoints</h2>
                <div class="mb-3">
                    <label class="form-label">Return URL (Success/Failure)</label>
                    <input type="text" class="form-control" readonly 
                           value="<?php echo esc_url(home_url('/payment-return/')); ?>">
                    <small class="text-muted">Customer browser returns here after HyperPay checkout</small>
                </div>
                <div class="mb-3">
                    <label class="form-label">IPN/Webhook URL (Server Notification)</label>
                    <input type="text" class="form-control" readonly 
                           value="<?php echo esc_url(home_url('/payment-callback/')); ?>">
                    <small class="text-muted">Configure this endpoint in HyperPay for asynchronous server notifications</small>
                </div>
            </div>
        </div>
    </div>
    <?php
}

// ============================================================================
// ADMIN PAGE 2: ALL PAYMENTS LISTING
// ============================================================================

/**
 * Build compact diagnostics for a payment row from structured payment logs.
 *
 * @param object $payment Payment row.
 * @return array
 */
function tanafs_get_payment_diagnostics($payment) {
    global $wpdb;

    if (!$payment || empty($payment->booking_token)) {
        return [];
    }

    $logs_table = $wpdb->prefix . 'tanafs_payment_logs';
    $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $logs_table));
    if ($table_exists !== $logs_table) {
        return [];
    }

    $booking_token = sanitize_text_field((string) $payment->booking_token);
    $checkout_id = sanitize_text_field((string) ($payment->hyperpay_checkout_id ?? ''));
    $merchant_transaction_id = sanitize_text_field((string) ($payment->transaction_id ?? ''));

    $like_booking = '%' . $wpdb->esc_like('"booking_token":"' . $booking_token . '"') . '%';
    $where_sql = 'log_data LIKE %s';
    $params = [$like_booking];

    if (!empty($checkout_id)) {
        $like_checkout = '%' . $wpdb->esc_like('"checkout_id":"' . $checkout_id . '"') . '%';
        $where_sql .= ' OR log_data LIKE %s';
        $params[] = $like_checkout;
    }

    if (!empty($merchant_transaction_id)) {
        $like_transaction = '%' . $wpdb->esc_like('"transaction_id":"' . $merchant_transaction_id . '"') . '%';
        $where_sql .= ' OR log_data LIKE %s';
        $params[] = $like_transaction;
    }

    $sql = "SELECT log_type, log_data, created_at
        FROM {$logs_table}
        WHERE ({$where_sql})
          AND log_type IN ('initiation_created', 'verification_requested', 'verification_result')
        ORDER BY id DESC
        LIMIT 60";

    $query = $wpdb->prepare($sql, ...$params);
    $rows = $wpdb->get_results($query, ARRAY_A);
    if (empty($rows)) {
        return [];
    }

    $diagnostics = [
        'initiation_request_id' => '',
        'verification_request_id' => '',
        'result_code' => '',
        'verification_source' => '',
        'entity_id_suffix' => '',
        'last_verification_at' => '',
    ];

    foreach ($rows as $row) {
        $log_type = sanitize_text_field((string) ($row['log_type'] ?? ''));
        $payload = json_decode((string) ($row['log_data'] ?? ''), true);
        if (!is_array($payload)) {
            continue;
        }

        if ($log_type === 'initiation_created' && $diagnostics['initiation_request_id'] === '') {
            $diagnostics['initiation_request_id'] = sanitize_text_field((string) ($payload['hyperpay_request_id'] ?? ''));
            if ($diagnostics['entity_id_suffix'] === '') {
                $diagnostics['entity_id_suffix'] = sanitize_text_field((string) ($payload['entity_id_suffix'] ?? ''));
            }
        }

        if ($log_type === 'verification_result' && $diagnostics['result_code'] === '') {
            $diagnostics['verification_request_id'] = sanitize_text_field((string) ($payload['hyperpay_request_id'] ?? ''));
            $diagnostics['result_code'] = sanitize_text_field((string) ($payload['result_code'] ?? ''));
            $diagnostics['verification_source'] = sanitize_text_field((string) ($payload['verification_source'] ?? ''));
            if ($diagnostics['entity_id_suffix'] === '') {
                $diagnostics['entity_id_suffix'] = sanitize_text_field((string) ($payload['entity_id_suffix'] ?? ''));
            }
            $diagnostics['last_verification_at'] = sanitize_text_field((string) ($row['created_at'] ?? ''));
        }
    }

    return $diagnostics;
}

/**
 * Render all payments listing page with filters
 */
function tanafs_render_all_payments_page() {
    global $wpdb;
    
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    $table_name = $wpdb->prefix . 'tanafs_payments';
    
    // Get filter parameters
    $filter_booking_type = isset($_GET['filter_booking_type']) ? sanitize_text_field($_GET['filter_booking_type']) : '';
    $filter_payment_status = isset($_GET['filter_payment_status']) ? sanitize_text_field($_GET['filter_payment_status']) : '';
    $search_query = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
    
    // Build SQL query with filters
    $where_clauses = ['1=1'];
    
    if (!empty($filter_booking_type)) {
        $where_clauses[] = $wpdb->prepare("booking_type = %s", $filter_booking_type);
    }
    
    if (!empty($filter_payment_status)) {
        $where_clauses[] = $wpdb->prepare("payment_status = %s", $filter_payment_status);
    }
    
    if (!empty($search_query)) {
        $where_clauses[] = $wpdb->prepare(
            "(customer_name LIKE %s OR customer_email LIKE %s OR transaction_id LIKE %s OR booking_token LIKE %s)",
            '%' . $wpdb->esc_like($search_query) . '%',
            '%' . $wpdb->esc_like($search_query) . '%',
            '%' . $wpdb->esc_like($search_query) . '%',
            '%' . $wpdb->esc_like($search_query) . '%'
        );
    }
    
    $where_sql = implode(' AND ', $where_clauses);
    
    // Pagination
    $per_page = 20;
    $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($current_page - 1) * $per_page;
    
    // Get total count
    $total_payments = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE {$where_sql}");
    $total_pages = ceil($total_payments / $per_page);
    
    // Get payments
    $payments = $wpdb->get_results(
        "SELECT * FROM {$table_name} WHERE {$where_sql} ORDER BY created_at DESC LIMIT {$per_page} OFFSET {$offset}"
    );
    
    // Get statistics
    $stats_pending = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE payment_status = 'pending'");
    $stats_complete = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE payment_status = 'complete'");
    $stats_failed = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE payment_status = 'failed'");
    $stats_total_amount = (float) ($wpdb->get_var("SELECT SUM(amount) FROM {$table_name} WHERE payment_status = 'complete'") ?? 0);
    
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">All Payments</h1>
        <hr class="wp-header-end">

        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        
        <style>
            .stats-cards {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 15px;
                margin: 20px 0;
            }
            .stat-card {
                background: #fff;
                border-radius: 8px;
                padding: 20px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            }
            .stat-card h3 {
                font-size: 14px;
                color: #666;
                margin: 0 0 10px 0;
                text-transform: uppercase;
            }
            .stat-card .value {
                font-size: 28px;
                font-weight: bold;
                color: #333;
            }
            .stat-card.pending .value { color: #ffc107; }
            .stat-card.complete .value { color: #28a745; }
            .stat-card.failed .value { color: #dc3545; }
            .stat-card.revenue .value { color: #6059A6; }
            
            .filters-box {
                background: #fff;
                padding: 20px;
                border-radius: 8px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.08);
                margin-bottom: 20px;
            }
            .payments-table {
                background: #fff;
                border-radius: 8px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.08);
                overflow: hidden;
            }
            .payments-table table {
                width: 100%;
                margin: 0;
            }
            .payments-table th {
                background: #f8f9fa;
                font-weight: 600;
                padding: 12px;
                text-align: left;
                border-bottom: 2px solid #dee2e6;
            }
            .payments-table td {
                padding: 12px;
                border-bottom: 1px solid #f0f0f0;
            }
            .badge {
                padding: 4px 12px;
                border-radius: 20px;
                font-size: 12px;
                font-weight: 600;
            }
            .badge-pending { background: #fff3cd; color: #856404; }
            .badge-complete { background: #d4edda; color: #155724; }
            .badge-failed { background: #f8d7da; color: #721c24; }
            .badge-therapy { background: #e7f3ff; color: #004085; }
            .badge-retreat { background: #d1ecf1; color: #0c5460; }
            .badge-academy { background: #d4edda; color: #155724; }
            .diag-cell {
                font-size: 12px;
                line-height: 1.4;
                color: #444;
                max-width: 320px;
                white-space: normal;
            }
            .diag-kv {
                display: block;
                margin-bottom: 2px;
            }
            .diag-kv strong {
                color: #111;
            }
        </style>

        <!-- Statistics Cards -->
        <div class="stats-cards">
            <div class="stat-card pending">
                <h3>Pending</h3>
                <div class="value"><?php echo number_format((float) $stats_pending); ?></div>
            </div>
            <div class="stat-card complete">
                <h3>Completed</h3>
                <div class="value"><?php echo number_format((float) $stats_complete); ?></div>
            </div>
            <div class="stat-card failed">
                <h3>Failed</h3>
                <div class="value"><?php echo number_format((float) $stats_failed); ?></div>
            </div>
            <div class="stat-card revenue">
                <h3>Total Revenue (SAR)</h3>
                <div class="value"><?php echo number_format((float) $stats_total_amount, 2); ?></div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-box">
            <form method="get" action="">
                <input type="hidden" name="page" value="tanafs-all-payments">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Booking Type</label>
                        <select name="filter_booking_type" class="form-control">
                            <option value="">All Types</option>
                            <option value="therapy" <?php selected($filter_booking_type, 'therapy'); ?>>Therapy</option>
                            <option value="retreat" <?php selected($filter_booking_type, 'retreat'); ?>>Retreat</option>
                            <option value="academy" <?php selected($filter_booking_type, 'academy'); ?>>Academy</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Payment Status</label>
                        <select name="filter_payment_status" class="form-control">
                            <option value="">All Statuses</option>
                            <option value="pending" <?php selected($filter_payment_status, 'pending'); ?>>Pending</option>
                            <option value="complete" <?php selected($filter_payment_status, 'complete'); ?>>Complete</option>
                            <option value="failed" <?php selected($filter_payment_status, 'failed'); ?>>Failed</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Search</label>
                        <input type="text" name="search" class="form-control" 
                               placeholder="Name, Email, Transaction ID..." 
                               value="<?php echo esc_attr($search_query); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100">🔍 Filter</button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Payments Table -->
        <div class="payments-table">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Transaction ID</th>
                        <th>Customer Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Booking Type</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Diagnostics</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($payments)): ?>
                        <tr>
                            <td colspan="10" style="text-align: center; padding: 40px;">
                                No payments found matching your criteria.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($payments as $payment): ?>
                            <?php $diag = tanafs_get_payment_diagnostics($payment); ?>
                            <tr>
                                <td><?php echo intval($payment->id); ?></td>
                                <td>
                                    <code><?php echo esc_html($payment->transaction_id); ?></code>
                                    <br><small style="color: #888;">Token: <?php echo esc_html(substr($payment->booking_token, 0, 20)); ?>...</small>
                                </td>
                                <td><?php echo esc_html($payment->customer_name); ?></td>
                                <td><?php echo esc_html($payment->customer_email); ?></td>
                                <td><?php echo esc_html($payment->customer_phone ?: 'N/A'); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo esc_attr($payment->booking_type); ?>">
                                        <?php echo esc_html(ucfirst($payment->booking_type)); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html($payment->currency); ?> <?php echo number_format((float) ($payment->amount ?? 0), 2); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo esc_attr($payment->payment_status); ?>">
                                        <?php echo esc_html(ucfirst($payment->payment_status)); ?>
                                    </span>
                                </td>
                                <td class="diag-cell">
                                    <?php if (empty($diag)): ?>
                                        <span style="color:#888;">No gateway diagnostics yet</span>
                                    <?php else: ?>
                                        <?php if (!empty($diag['result_code'])): ?>
                                            <span class="diag-kv"><strong>result_code:</strong> <?php echo esc_html($diag['result_code']); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($diag['verification_source'])): ?>
                                            <span class="diag-kv"><strong>source:</strong> <?php echo esc_html($diag['verification_source']); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($diag['entity_id_suffix'])): ?>
                                            <span class="diag-kv"><strong>entity_suffix:</strong> <?php echo esc_html($diag['entity_id_suffix']); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($diag['initiation_request_id'])): ?>
                                            <span class="diag-kv"><strong>init_request_id:</strong> <?php echo esc_html($diag['initiation_request_id']); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($diag['verification_request_id'])): ?>
                                            <span class="diag-kv"><strong>verify_request_id:</strong> <?php echo esc_html($diag['verification_request_id']); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($diag['last_verification_at'])): ?>
                                            <span class="diag-kv"><strong>verified_at:</strong> <?php echo esc_html(date('Y-m-d H:i', strtotime($diag['last_verification_at']))); ?></span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html(date('Y-m-d H:i', strtotime($payment->created_at))); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div style="margin-top: 20px; text-align: center;">
                <?php
                echo paginate_links([
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'prev_text' => '« Previous',
                    'next_text' => 'Next »',
                    'total' => $total_pages,
                    'current' => $current_page,
                ]);
                ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

// ============================================================================
// SECTION 2: CORE PAYMENT GATEWAY FUNCTIONS
// ============================================================================

/**
 * Get normalized gateway mode value.
 *
 * @return string sandbox|live
 */
function tanafs_gateway_get_mode() {
    $mode = get_option('tanafs_hyperpay_mode', get_option('tanafs_aps_mode', 'sandbox'));
    return ($mode === 'production') ? 'live' : $mode;
}

/**
 * Check if HyperPay credentials are configured.
 *
 * @return bool
 */
function tanafs_hyperpay_is_configured() {
    $entity_id = get_option('tanafs_hyperpay_entity_id');
    $access_token = get_option('tanafs_hyperpay_access_token');

    return !empty($entity_id) && !empty($access_token);
}

/**
 * Get HyperPay API base URL by mode.
 *
 * @return string
 */
function tanafs_hyperpay_get_base_url() {
    $mode = tanafs_gateway_get_mode();

    if ($mode === 'live') {
        return 'https://eu-prod.oppwa.com/';
    }

    return 'https://eu-test.oppwa.com/';
}

/**
 * Get HyperPay checkout creation endpoint.
 *
 * @return string
 */
function tanafs_hyperpay_get_checkout_endpoint() {
    return tanafs_hyperpay_get_base_url() . 'v1/checkouts';
}

/**
 * Check whether a payment method is enabled from HyperPay admin settings.
 *
 * @param string $payment_method Normalized method key.
 * @return bool
 */
function tanafs_hyperpay_is_method_enabled($payment_method) {
    $method = strtolower(trim((string) $payment_method));

    if ($method === 'tamara') {
        return get_option('tanafs_hyperpay_enable_tamara', '1') === '1';
    }

    if ($method === 'applepay') {
        return get_option('tanafs_hyperpay_enable_applepay', '1') === '1';
    }

    return get_option('tanafs_hyperpay_enable_card', '1') === '1';
}

/**
 * Return enabled payment methods in display order.
 *
 * @return array
 */
function tanafs_hyperpay_get_enabled_methods() {
    $methods = [];

    if (tanafs_hyperpay_is_method_enabled('card')) {
        $methods[] = 'card';
    }
    if (tanafs_hyperpay_is_method_enabled('tamara')) {
        $methods[] = 'tamara';
    }
    if (tanafs_hyperpay_is_method_enabled('applepay')) {
        $methods[] = 'applepay';
    }

    return $methods;
}

/**
 * Normalize HTTP headers object/array for structured logging.
 *
 * @param mixed $headers Headers from wp_remote_* response.
 * @return array
 */
function tanafs_normalize_http_headers_for_log($headers) {
    if (is_array($headers)) {
        return $headers;
    }

    if (is_object($headers)) {
        if (method_exists($headers, 'getAll')) {
            $all = $headers->getAll();
            if (is_array($all)) {
                return $all;
            }
        }

        if (method_exists($headers, 'toArray')) {
            $all = $headers->toArray();
            if (is_array($all)) {
                return $all;
            }
        }

        return (array) $headers;
    }

    return [];
}

/**
 * Normalize requested payment method to supported keys.
 *
 * @param string $payment_method Raw method key from frontend.
 * @return string card|tamara|applepay
 */
function tanafs_hyperpay_normalize_payment_method($payment_method) {
    $method = strtolower(trim((string) $payment_method));

    $aliases = [
        'card' => 'card',
        'cards' => 'card',
        'mada_visa_mastercard' => 'card',
        'mada-visa-mastercard' => 'card',
        'mada' => 'card',
        'tamara' => 'tamara',
        'applepay' => 'applepay',
        'apple_pay' => 'applepay',
        'apple-pay' => 'applepay',
    ];

    return $aliases[$method] ?? 'card';
}

/**
 * Map payment method to HyperPay brand string.
 *
 * @param string $payment_method Normalized method.
 * @return string
 */
function tanafs_hyperpay_get_brands_for_method($payment_method) {
    $method = tanafs_hyperpay_normalize_payment_method($payment_method);

    if ($method === 'tamara') {
        return 'TAMARA';
    }

    if ($method === 'applepay') {
        return 'APPLEPAY';
    }

    return 'MADA VISA MASTER';
}

/**
 * Get Tamara description endpoint URL by mode.
 *
 * @param string $entity_id HyperPay entity id.
 * @return string
 */
function tanafs_hyperpay_get_tamara_description_endpoint($entity_id) {
    $entity_id = rawurlencode((string) $entity_id);
    if (tanafs_gateway_get_mode() === 'live') {
        return 'https://tamara-prod.hyperpay.com/api/tamara-description/' . $entity_id;
    }

    return 'https://tamara-dev.hyperpay.com/api/tamara-description/' . $entity_id;
}

/**
 * Fetch Tamara payment details from HyperPay Tamara API.
 *
 * @param string $entity_id HyperPay entity id.
 * @param string $amount_formatted Amount with 2 decimals.
 * @param string $currency Currency code.
 * @return array
 */
function tanafs_hyperpay_get_tamara_payment_details($entity_id, $amount_formatted, $currency, $context = []) {
    if (tanafs_gateway_get_mode() !== 'live') {
        $static_response = [
            'payment_type' => 'PAY_BY_INSTALMENTS',
            'instalment' => 4,
            'description_en' => 'Monthly payments. Sharia-compliant.',
            'description_ar' => 'دفعات شهرية. متوافقة مع الشريعة الإسلامية.',
        ];

        tanafs_log_payment('tamara_description_response', [
            'event' => 'tamara_description_response',
            'booking_token' => sanitize_text_field((string) ($context['booking_token'] ?? '')),
            'booking_type' => sanitize_text_field((string) ($context['booking_type'] ?? '')),
            'source' => 'sandbox_static',
            'request_payload' => [
                'country' => 'SA',
                'order_value' => [
                    'amount' => (string) $amount_formatted,
                    'currency' => (string) $currency,
                ],
            ],
            'response_raw' => wp_json_encode($static_response),
            'response_decoded' => $static_response,
            'success' => true,
        ]);

        return [
            'success' => true,
            'payment_type' => 'PAY_BY_INSTALMENTS',
            'instalments' => 4,
            'instalment' => 4,
            'description_en' => $static_response['description_en'],
            'description_ar' => $static_response['description_ar'],
            'raw' => $static_response,
            'source' => 'sandbox_static',
        ];
    }

    $endpoint = tanafs_hyperpay_get_tamara_description_endpoint($entity_id);
    $payload = [
        'country' => 'SA',
        'order_value' => [
            'amount' => (string) $amount_formatted,
            'currency' => (string) $currency,
        ],
    ];

    $response = wp_remote_post($endpoint, [
        'headers' => [
            'Content-Type' => 'application/json',
        ],
        'body' => wp_json_encode($payload),
        'timeout' => 20,
    ]);

    if (is_wp_error($response)) {
        tanafs_log_payment('tamara_description_response', [
            'event' => 'tamara_description_response',
            'booking_token' => sanitize_text_field((string) ($context['booking_token'] ?? '')),
            'booking_type' => sanitize_text_field((string) ($context['booking_type'] ?? '')),
            'endpoint' => $endpoint,
            'request_payload' => $payload,
            'transport_error' => $response->get_error_message(),
            'success' => false,
        ]);

        return [
            'success' => false,
            'error' => 'Unable to fetch Tamara payment details.',
            'details' => $response->get_error_message(),
        ];
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);
    $response_headers = tanafs_normalize_http_headers_for_log(wp_remote_retrieve_headers($response));
    $decoded = json_decode($response_body, true);

    tanafs_log_payment('tamara_description_response', [
        'event' => 'tamara_description_response',
        'booking_token' => sanitize_text_field((string) ($context['booking_token'] ?? '')),
        'booking_type' => sanitize_text_field((string) ($context['booking_type'] ?? '')),
        'endpoint' => $endpoint,
        'request_payload' => $payload,
        'http_status' => (int) $status_code,
        'response_headers' => $response_headers,
        'response_raw' => $response_body,
        'response_decoded' => $decoded,
        'success' => ($status_code >= 200 && $status_code < 300),
    ]);

    if ($status_code < 200 || $status_code >= 300 || !is_array($decoded)) {
        return [
            'success' => false,
            'error' => 'Tamara payment details request was rejected.',
            'details' => $decoded,
        ];
    }

    if (empty($decoded['has_available_payment_options'])) {
        return [
            'success' => false,
            'error' => 'Tamara is currently unavailable for this amount.',
            'details' => $decoded,
        ];
    }

    $labels = $decoded['available_payment_labels'] ?? [];
    $label = is_array($labels) && !empty($labels[0]) && is_array($labels[0]) ? $labels[0] : [];

    $payment_type = sanitize_text_field((string) ($label['payment_type'] ?? 'PAY_BY_INSTALMENTS'));
    $instalments = intval($label['instalment'] ?? 0);

    if ($payment_type !== 'PAY_BY_INSTALMENTS' || $instalments <= 0) {
        return [
            'success' => false,
            'error' => 'Tamara installment details are invalid.',
            'details' => $decoded,
        ];
    }

    return [
        'success' => true,
        'payment_type' => $payment_type,
        'instalments' => $instalments,
        'description_en' => sanitize_text_field((string) ($label['description_en'] ?? '')),
        'description_ar' => sanitize_text_field((string) ($label['description_ar'] ?? '')),
        'raw' => $decoded,
    ];
}

/**
 * Build hosted checkout page URL for rendering a minimal Copy and Pay form.
 *
 * @param string $booking_token Booking token.
 * @param string $booking_type Booking type.
 * @param string $checkout_id HyperPay checkout id.
 * @param string $return_url Shopper return URL.
 * @return string
 */
function tanafs_build_hosted_checkout_url($booking_token, $booking_type, $checkout_id, $return_url = '', $payment_method = 'card') {
    $args = [
        'payment_widget' => 1,
        'booking_token' => sanitize_text_field((string) $booking_token),
        'booking_type' => sanitize_text_field((string) $booking_type),
        'checkout_id' => sanitize_text_field((string) $checkout_id),
        'payment_method' => tanafs_hyperpay_normalize_payment_method($payment_method),
    ];

    if (!empty($return_url)) {
        $args['return_url'] = rawurlencode(esc_url_raw((string) $return_url));
    }

    return add_query_arg($args, home_url('/payment-widget/'));
}

/**
 * Build HyperPay status endpoint from resource path.
 *
 * @param string $resource_path HyperPay resource path returned from checkout/payment response.
 * @return string
 */
function tanafs_hyperpay_get_status_endpoint($resource_path) {
    $resource_path = tanafs_hyperpay_normalize_resource_path($resource_path);

    if (strpos($resource_path, 'http') === 0) {
        return $resource_path;
    }

    if (strpos($resource_path, '/') !== 0) {
        $resource_path = '/' . $resource_path;
    }

    return rtrim(tanafs_hyperpay_get_base_url(), '/') . $resource_path;
}

/**
 * Normalize resourcePath returned by Copy and Pay redirects/webhooks.
 *
 * Handles double-encoded values and full URLs while preserving query args.
 *
 * @param string $resource_path Raw resource path value.
 * @return string
 */
function tanafs_hyperpay_normalize_resource_path($resource_path) {
    $normalized = trim((string) $resource_path);
    if ($normalized === '') {
        return '';
    }

    // Decode repeatedly because some redirects can pass resourcePath double-encoded.
    for ($i = 0; $i < 3; $i++) {
        $decoded = rawurldecode($normalized);
        if ($decoded === $normalized) {
            break;
        }
        $normalized = $decoded;
    }

    // If a full URL is passed, keep only path and query to avoid host mismatch.
    if (strpos($normalized, 'http://') === 0 || strpos($normalized, 'https://') === 0) {
        $parts = wp_parse_url($normalized);
        $normalized = (string) ($parts['path'] ?? '');
        if (!empty($parts['query'])) {
            $normalized .= '?' . $parts['query'];
        }
    }

    $normalized = preg_replace('/[\r\n]+/', '', $normalized);

    if ($normalized !== '' && strpos($normalized, '/') !== 0) {
        $normalized = '/' . ltrim($normalized, '/');
    }

    return sanitize_text_field($normalized);
}

/**
 * Split a full name into first and last name parts.
 *
 * @param string $full_name Full customer name.
 * @return array
 */
function tanafs_hyperpay_split_name($full_name) {
    $full_name = trim((string) $full_name);
    if ($full_name === '') {
        return ['first_name' => 'Customer', 'last_name' => 'Customer'];
    }

    $parts = preg_split('/\s+/', $full_name);
    $first_name = array_shift($parts);
    $last_name = !empty($parts) ? implode(' ', $parts) : $first_name;

    return [
        'first_name' => sanitize_text_field($first_name),
        'last_name' => sanitize_text_field($last_name),
    ];
}

/**
 * Normalize customer country to ISO-3166 alpha-2 used by HyperPay billing.country.
 *
 * @param string $country Raw country value.
 * @return string
 */
function tanafs_hyperpay_normalize_country_code($country) {
    $country = strtoupper(trim((string) $country));
    if (preg_match('/^[A-Z]{2}$/', $country)) {
        return $country;
    }

    $map = [
        'SAUDI ARABIA' => 'SA',
        'KSA' => 'SA',
        'KINGDOM OF SAUDI ARABIA' => 'SA',
        'SAUDI' => 'SA',
        'UNITED ARAB EMIRATES' => 'AE',
        'UAE' => 'AE',
        'EGYPT' => 'EG',
        'OMAN' => 'OM',
        'JORDAN' => 'JO',
        'QATAR' => 'QA',
        'KUWAIT' => 'KW',
        'BAHRAIN' => 'BH',
    ];

    return $map[$country] ?? 'SA';
}

/**
 * Normalize customer phone for HyperPay customer.phone.
 *
 * @param string $phone Raw phone value.
 * @return string
 */
function tanafs_hyperpay_normalize_phone($phone) {
    $phone = trim((string) $phone);

    // If malformed input (e.g. email entered in phone field), use a safe test fallback.
    if ($phone === '' || strpos($phone, '@') !== false) {
        return '+966500000000';
    }

    $normalized = preg_replace('/[^0-9\+]/', '', $phone);

    if ($normalized === '') {
        return '+966500000000';
    }

    if ($normalized[0] !== '+') {
        if (strpos($normalized, '00') === 0) {
            $normalized = '+' . substr($normalized, 2);
        } elseif (strpos($normalized, '0') === 0) {
            $normalized = '+966' . ltrim($normalized, '0');
        } else {
            $normalized = '+966' . $normalized;
        }
    }

    // Keep maximum length aligned with common gateway/phone constraints.
    $digits = preg_replace('/[^0-9]/', '', $normalized);
    if (strlen($digits) < 8) {
        return '+966500000000';
    }

    return substr($normalized, 0, 25);
}

/**
 * Insert pending payment row before checkout creation.
 *
 * @param string $transaction_id Gateway transaction id.
 * @param string $booking_token Unique booking token.
 * @param string $booking_type Booking type.
 * @param float  $amount Payment amount.
 * @param string $currency Currency code.
 * @param array  $customer_details Customer details.
 * @return bool
 */
function tanafs_insert_pending_payment($transaction_id, $booking_token, $booking_type, $amount, $currency, $customer_details, $payment_method = 'card') {
    global $wpdb;

    $table_name = $wpdb->prefix . 'tanafs_payments';
    $column_meta = $wpdb->get_results("SHOW COLUMNS FROM {$table_name}", ARRAY_A);
    if (empty($column_meta)) {
        tanafs_create_payments_table();
        tanafs_upgrade_payments_table_schema();
        $column_meta = $wpdb->get_results("SHOW COLUMNS FROM {$table_name}", ARRAY_A);
    }

    if (empty($column_meta)) {
        tanafs_log_payment('initiation_failed', [
            'event' => 'initiation_failed',
            'reason' => 'payments_table_unavailable',
            'booking_token' => $booking_token,
            'booking_type' => $booking_type,
            'transaction_id' => $transaction_id,
            'db_error' => $wpdb->last_error,
        ]);
        return false;
    }

    $preferred_data = [
        'transaction_id' => $transaction_id,
        'booking_token' => $booking_token,
        'cart_id' => $booking_token,
        'booking_reference' => $booking_token,
        'booking_type' => $booking_type,
        'hyperpay_entity_id' => sanitize_text_field(get_option('tanafs_hyperpay_entity_id', '')),
        'customer_name' => sanitize_text_field($customer_details['name'] ?? ''),
        'name' => sanitize_text_field($customer_details['name'] ?? ''),
        'customer_email' => sanitize_email($customer_details['email'] ?? ''),
        'email' => sanitize_email($customer_details['email'] ?? ''),
        'customer_phone' => sanitize_text_field($customer_details['phone'] ?? ''),
        'phone' => sanitize_text_field($customer_details['phone'] ?? ''),
        'amount' => (float) $amount,
        'currency' => sanitize_text_field($currency),
        'status' => 'pending',
        'payment_status' => 'pending',
        'payment_method' => tanafs_hyperpay_normalize_payment_method($payment_method),
        'ip_address' => sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? ''),
        'user_agent' => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
    ];

    $payment_data = [];

    foreach ($column_meta as $column) {
        $column_name = (string) ($column['Field'] ?? '');
        if ($column_name === '') {
            continue;
        }

        if (array_key_exists($column_name, $preferred_data)) {
            $payment_data[$column_name] = $preferred_data[$column_name];
            continue;
        }

        // Satisfy legacy required columns without defaults.
        $is_required = (($column['Null'] ?? '') === 'NO')
            && ($column['Default'] === null)
            && (stripos((string) ($column['Extra'] ?? ''), 'auto_increment') === false);

        if ($is_required) {
            $column_type = strtolower((string) ($column['Type'] ?? ''));
            if (strpos($column_type, 'int') !== false || strpos($column_type, 'decimal') !== false || strpos($column_type, 'float') !== false || strpos($column_type, 'double') !== false) {
                $payment_data[$column_name] = 0;
            } elseif (strpos($column_type, 'date') !== false || strpos($column_type, 'time') !== false) {
                $payment_data[$column_name] = current_time('mysql');
            } else {
                $payment_data[$column_name] = '';
            }
        }
    }

    $inserted = $wpdb->insert($table_name, $payment_data);

    if (!$inserted) {
        tanafs_log_payment('initiation_failed', [
            'event' => 'initiation_failed',
            'reason' => 'db_insert_failed',
            'booking_token' => $booking_token,
            'booking_type' => $booking_type,
            'transaction_id' => $transaction_id,
            'insert_columns' => array_keys($payment_data),
            'db_error' => $wpdb->last_error,
        ]);
        return false;
    }

    return true;
}

/**
 * Create HyperPay checkout and persist checkout id on pending payment row.
 *
 * @param string $booking_token Unique booking token.
 * @param string $booking_type Booking type.
 * @param float  $amount Payment amount.
 * @param array  $customer_details Customer details.
 * @param array  $options Additional options.
 * @return array
 */
function tanafs_hyperpay_create_checkout($booking_token, $booking_type, $amount, $customer_details, $options = []) {
    global $wpdb;

    if (!tanafs_hyperpay_is_configured()) {
        return [
            'success' => false,
            'error' => 'HyperPay payment gateway is not configured. Please contact support.',
        ];
    }

    $entity_id = get_option('tanafs_hyperpay_entity_id', '');
    $access_token = get_option('tanafs_hyperpay_access_token', '');
    $currency = sanitize_text_field($options['currency'] ?? get_option('tanafs_hyperpay_currency', get_option('tanafs_aps_currency', 'SAR')));
    $payment_method = tanafs_hyperpay_normalize_payment_method($options['payment_method'] ?? 'card');

    if (!tanafs_hyperpay_is_method_enabled($payment_method)) {
        return [
            'success' => false,
            'error' => 'Selected payment method is currently unavailable. Please choose another method.',
        ];
    }

    $selected_brands = tanafs_hyperpay_get_brands_for_method($payment_method);
    $amount_formatted = number_format((float) $amount, 2, '.', '');
    $transaction_id = 'TANAFS_' . strtoupper($booking_type) . '_' . time() . '_' . wp_rand(1000, 9999);

    $return_url = $options['return_url'] ?? home_url('/payment-return/');
    $query_args = [];
    if (!empty($booking_token)) {
        $query_args['payment_return'] = sanitize_text_field($booking_token);
    }
    if (!empty($booking_type)) {
        $query_args['booking_type'] = sanitize_text_field($booking_type);
    }
    if (!empty($query_args)) {
        $return_url = add_query_arg($query_args, $return_url);
    }

    // Security rule: always create pending row before checkout creation.
    $inserted = tanafs_insert_pending_payment($transaction_id, $booking_token, $booking_type, $amount, $currency, $customer_details, $payment_method);
    if (!$inserted) {
        return [
            'success' => false,
            'error' => 'Failed to create payment record. Please try again.',
        ];
    }

    $name_parts = tanafs_hyperpay_split_name($customer_details['name'] ?? '');
    $is_sandbox_mode = (tanafs_gateway_get_mode() !== 'live');
    $force_external_test_mode = (get_option('tanafs_hyperpay_force_external_test_mode', '0') === '1');
    $billing_country = tanafs_hyperpay_normalize_country_code($customer_details['country'] ?? 'SA');
    $customer_phone = tanafs_hyperpay_normalize_phone($customer_details['phone'] ?? '');

    $request_body = [
        'entityId' => $entity_id,
        'amount' => $amount_formatted,
        'currency' => $currency,
        'paymentType' => 'DB',
        'merchantTransactionId' => $transaction_id,
        // 'notificationUrl' => esc_url_raw(home_url('/payment-callback/')),
        'customer.email' => sanitize_email($customer_details['email'] ?? ''),
        'customer.phone' => $customer_phone,
        'customer.givenName' => $name_parts['first_name'],
        'customer.surname' => $name_parts['last_name'],
        'billing.street1' => sanitize_text_field($customer_details['street1'] ?? 'N/A'),
        'billing.city' => sanitize_text_field($customer_details['city'] ?? 'Riyadh'),
        'billing.state' => sanitize_text_field($customer_details['state'] ?? 'Riyadh'),
        'billing.country' => $billing_country,
        'billing.postcode' => sanitize_text_field($customer_details['postcode'] ?? '11564'),
        // 'shopperResultUrl' => esc_url_raw($return_url),
        // 'customParameters[booking_token]' => sanitize_text_field($booking_token),
        // 'customParameters[booking_type]' => sanitize_text_field($booking_type),
    ];

    if ($is_sandbox_mode) {
        $request_body['integrity'] = true;
    }

    if ($is_sandbox_mode && $force_external_test_mode) {
        $request_body['testMode'] = 'EXTERNAL';
        $request_body['customParameters[3DS2_enrolled]'] = true;
        $request_body['customParameters[3DS2_flow]'] = 'challenge';
    }

    if ($payment_method === 'tamara') {
        $tamara_details = tanafs_hyperpay_get_tamara_payment_details($entity_id, $amount_formatted, $currency, [
            'booking_token' => $booking_token,
            'booking_type' => $booking_type,
        ]);
        if (!$tamara_details['success']) {
            $table_name = $wpdb->prefix . 'tanafs_payments';
            $wpdb->update(
                $table_name,
                [
                    'status' => 'failed',
                    'payment_status' => 'failed',
                    'aps_response_message' => sanitize_text_field((string) $tamara_details['error']),
                ],
                ['transaction_id' => $transaction_id],
                ['%s', '%s', '%s'],
                ['%s']
            );

            tanafs_log_payment('initiation_failed', [
                'event' => 'initiation_failed',
                'reason' => 'tamara_description_unavailable',
                'booking_token' => $booking_token,
                'booking_type' => $booking_type,
                'transaction_id' => $transaction_id,
                'error' => $tamara_details['error'] ?? 'Tamara unavailable',
                'details' => $tamara_details['details'] ?? [],
            ]);

            return [
                'success' => false,
                'error' => $tamara_details['error'] ?? 'Tamara is currently unavailable.',
            ];
        }

        $request_body['customParameters[tamara_payment_type]'] = $tamara_details['payment_type'];
        $request_body['customParameters[instalments]'] = (string) $tamara_details['instalments'];
        $request_body['cart.items[0].name'] = ucfirst($booking_type) . ' booking';
        $request_body['cart.items[0].type'] = 'service';
        $request_body['cart.items[0].sku'] = strtoupper($booking_type) . '-001';
        $request_body['cart.items[0].quantity'] = '1';
        $request_body['cart.items[0].merchantItemId'] = sanitize_text_field((string) $booking_token);
        $request_body['cart.items[0].totalAmount'] = $amount_formatted;

        // Required in sandbox for Tamara redirect flow.
        if ($is_sandbox_mode) {
            $request_body['testMode'] = 'EXTERNAL';
        }
    }

   

    tanafs_log_payment('initiation_requested', $request_body);

    $response = wp_remote_post(
        tanafs_hyperpay_get_checkout_endpoint(),
        [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => $request_body,
            'timeout' => 25,
        ]
    );

    if (is_wp_error($response)) {
        $table_name = $wpdb->prefix . 'tanafs_payments';
        $wpdb->update(
            $table_name,
            [
                'status' => 'failed',
                'payment_status' => 'failed',
                'aps_response_message' => sanitize_text_field($response->get_error_message()),
            ],
            ['transaction_id' => $transaction_id],
            ['%s', '%s', '%s'],
            ['%s']
        );

        tanafs_log_payment('initiation_failed', [
            'event' => 'initiation_failed',
            'reason' => 'gateway_request_error',
            'booking_token' => $booking_token,
            'booking_type' => $booking_type,
            'transaction_id' => $transaction_id,
            'error' => $response->get_error_message(),
        ]);

        tanafs_log_payment('hyperpay_checkout_response', [
            'event' => 'hyperpay_checkout_response',
            'booking_token' => $booking_token,
            'booking_type' => $booking_type,
            'transaction_id' => $transaction_id,
            'endpoint' => tanafs_hyperpay_get_checkout_endpoint(),
            'request_payload' => $request_body,
            'transport_error' => $response->get_error_message(),
            'success' => false,
        ]);

        return [
            'success' => false,
            'error' => 'Unable to connect to payment gateway. Please try again.',
        ];
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);
    $response_headers = wp_remote_retrieve_headers($response);
    $response_headers_log = tanafs_normalize_http_headers_for_log($response_headers);
    $decoded = json_decode($response_body, true);

    tanafs_log_payment('hyperpay_checkout_response', [
        'event' => 'hyperpay_checkout_response',
        'booking_token' => $booking_token,
        'booking_type' => $booking_type,
        'transaction_id' => $transaction_id,
        'endpoint' => tanafs_hyperpay_get_checkout_endpoint(),
        'request_payload' => $request_body,
        'http_status' => (int) $status_code,
        'response_headers' => $response_headers_log,
        'response_raw' => $response_body,
        'response_decoded' => $decoded,
        'success' => ($status_code >= 200 && $status_code < 300),
    ]);

    if ($status_code < 200 || $status_code >= 300 || empty($decoded['id'])) {
        $table_name = $wpdb->prefix . 'tanafs_payments';
        $wpdb->update(
            $table_name,
            [
                'status' => 'failed',
                'payment_status' => 'failed',
                'response_data' => $response_body,
            ],
            ['transaction_id' => $transaction_id],
            ['%s', '%s', '%s'],
            ['%s']
        );

        tanafs_log_payment('initiation_failed', [
            'event' => 'initiation_failed',
            'reason' => 'gateway_response_invalid',
            'booking_token' => $booking_token,
            'booking_type' => $booking_type,
            'transaction_id' => $transaction_id,
            'http_status' => $status_code,
            'response' => $decoded,
        ]);

        return [
            'success' => false,
            'error' => 'Payment gateway rejected checkout creation.',
        ];
    }

    $checkout_id = sanitize_text_field($decoded['id']);
    $table_name = $wpdb->prefix . 'tanafs_payments';
    $wpdb->update(
        $table_name,
        [
            'hyperpay_checkout_id' => $checkout_id,
            'response_data' => $response_body,
        ],
        ['transaction_id' => $transaction_id],
        ['%s', '%s'],
        ['%s']
    );

    $widget_url = tanafs_hyperpay_get_base_url() . 'v1/paymentWidgets.js?checkoutId=' . rawurlencode($checkout_id);

    tanafs_log_payment('initiation_created', [
        'event' => 'initiation_created',
        'booking_token' => $booking_token,
        'booking_type' => $booking_type,
        'transaction_id' => $transaction_id,
        'checkout_id' => $checkout_id,
        'mode' => tanafs_gateway_get_mode(),
        'base_url' => tanafs_hyperpay_get_base_url(),
        'entity_id_suffix' => substr((string) $entity_id, -6),
        'hyperpay_request_id' => sanitize_text_field((string) ($response_headers['x-request-id'] ?? ($response_headers['X-Request-Id'] ?? ''))),
    ]);

    return [
        'success' => true,
        'checkout_id' => $checkout_id,
        'widget_url' => $widget_url,
        'widget_integrity' => sanitize_text_field($decoded['integrity'] ?? ''),
        'transaction_id' => $transaction_id,
        'payment_method' => $payment_method,
        'brands' => $selected_brands,
        'return_url' => $return_url,
    ];
}

/**
 * Map HyperPay result code to internal status.
 *
 * @param string $result_code HyperPay result code.
 * @return string pending|complete|failed
 */
function tanafs_hyperpay_map_result_status($result_code) {
    $result_code = (string) $result_code;

    if ($result_code === '') {
        return 'pending';
    }

    if (preg_match('/^(000\.000\.|000\.100\.1|000\.[36])/', $result_code)) {
        return 'complete';
    }

    // Codes observed during asynchronous completion or before final capture settles.
    if (preg_match('/^(000\.200|200\.300\.404|200\.300\.403|200\.300\.000|800\.120\.100|800\.400\.5|100\.400\.500)/', $result_code)) {
        return 'pending';
    }

    return 'failed';
}

/**
 * Normalize a HyperPay query response into a payment payload when possible.
 *
 * @param array $decoded Query response body.
 * @return array|null
 */
function tanafs_hyperpay_extract_payment_from_query($decoded) {
    if (!is_array($decoded)) {
        return null;
    }

    if (isset($decoded['payments']) && is_array($decoded['payments']) && !empty($decoded['payments'][0]) && is_array($decoded['payments'][0])) {
        return $decoded['payments'][0];
    }

    if (isset($decoded['records']) && is_array($decoded['records']) && !empty($decoded['records'][0]) && is_array($decoded['records'][0])) {
        return $decoded['records'][0];
    }

    if (isset($decoded['data']) && is_array($decoded['data']) && !empty($decoded['data'][0]) && is_array($decoded['data'][0])) {
        return $decoded['data'][0];
    }

    if (isset($decoded['id']) && isset($decoded['result']) && is_array($decoded['result'])) {
        return $decoded;
    }

    return null;
}

/**
 * Fallback verification through HyperPay query endpoint.
 *
 * @param array $args Verification context.
 * @param string $entity_id HyperPay entity id.
 * @param string $access_token HyperPay access token.
 * @return array
 */
function tanafs_hyperpay_verify_via_query($args, $entity_id, $access_token) {
    $merchant_transaction_id = sanitize_text_field($args['merchant_transaction_id'] ?? '');

    if (empty($merchant_transaction_id)) {
        return [
            'success' => false,
            'message' => 'Missing merchant transaction id for query fallback',
        ];
    }

    $endpoint = tanafs_hyperpay_get_base_url() . 'v1/query';
    $endpoint = add_query_arg(
        [
            'entityId' => $entity_id,
            'merchantTransactionId' => $merchant_transaction_id,
        ],
        $endpoint
    );

    tanafs_log_payment('verification_requested', [
        'event' => 'verification_requested',
        'booking_token' => $args['booking_token'] ?? '',
        'booking_type' => $args['booking_type'] ?? '',
        'checkout_id' => sanitize_text_field($args['checkout_id'] ?? ''),
        'merchant_transaction_id' => $merchant_transaction_id,
        'resource_path' => sanitize_text_field($args['resource_path'] ?? ''),
        'endpoint' => $endpoint,
        'fallback' => 'query_by_merchant_transaction_id',
    ]);

    $response = wp_remote_get(
        $endpoint,
        [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
            ],
            'timeout' => 25,
        ]
    );

    if (is_wp_error($response)) {
        return [
            'success' => false,
            'message' => $response->get_error_message(),
        ];
    }

    $status_code = (int) wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $response_headers = tanafs_normalize_http_headers_for_log(wp_remote_retrieve_headers($response));
    $decoded = json_decode($body, true);
    $query_result_code = sanitize_text_field($decoded['result']['code'] ?? '');

    tanafs_log_payment('verification_gateway_response', [
        'event' => 'verification_gateway_response',
        'booking_token' => $args['booking_token'] ?? '',
        'booking_type' => $args['booking_type'] ?? '',
        'checkout_id' => sanitize_text_field($args['checkout_id'] ?? ''),
        'merchant_transaction_id' => $merchant_transaction_id,
        'resource_path' => sanitize_text_field($args['resource_path'] ?? ''),
        'endpoint' => $endpoint,
        'verification_fallback' => 'query_by_merchant_transaction_id',
        'http_status' => $status_code,
        'response_headers' => $response_headers,
        'response_raw' => $body,
        'response_decoded' => $decoded,
    ]);

    if ($query_result_code === '700.400.580') {
        tanafs_log_payment('verification_result', [
            'event' => 'verification_result',
            'booking_token' => $args['booking_token'] ?? '',
            'booking_type' => $args['booking_type'] ?? '',
            'checkout_id' => sanitize_text_field($args['checkout_id'] ?? ''),
            'merchant_transaction_id' => $merchant_transaction_id,
            'result_code' => $query_result_code,
            'internal_status' => 'failed',
            'success' => false,
            'fallback' => 'query_by_merchant_transaction_id',
            'http_status' => $status_code,
            'response_raw' => $body,
            'message' => 'No payment transaction found for this checkout.',
        ]);

        return [
            'success' => false,
            'message' => 'No payment transaction found for this checkout.',
            'result_code' => $query_result_code,
            'http_status' => $status_code,
            'response_raw' => $body,
        ];
    }

    if ($status_code < 200 || $status_code >= 300 || !is_array($decoded)) {
        tanafs_log_payment('verification_result', [
            'event' => 'verification_result',
            'booking_token' => $args['booking_token'] ?? '',
            'booking_type' => $args['booking_type'] ?? '',
            'checkout_id' => sanitize_text_field($args['checkout_id'] ?? ''),
            'merchant_transaction_id' => $merchant_transaction_id,
            'internal_status' => 'pending',
            'success' => false,
            'fallback' => 'query_by_merchant_transaction_id',
            'http_status' => $status_code,
            'response_raw' => $body,
            'message' => 'Query fallback failed',
        ]);

        return [
            'success' => false,
            'message' => 'Query fallback failed',
            'result_code' => $query_result_code,
            'http_status' => $status_code,
            'response_raw' => $body,
        ];
    }

    $payment = tanafs_hyperpay_extract_payment_from_query($decoded);
    if (empty($payment)) {
        tanafs_log_payment('verification_result', [
            'event' => 'verification_result',
            'booking_token' => $args['booking_token'] ?? '',
            'booking_type' => $args['booking_type'] ?? '',
            'checkout_id' => sanitize_text_field($args['checkout_id'] ?? ''),
            'merchant_transaction_id' => $merchant_transaction_id,
            'internal_status' => 'pending',
            'success' => false,
            'fallback' => 'query_by_merchant_transaction_id',
            'message' => 'No payment record found via query fallback',
        ]);

        return [
            'success' => false,
            'message' => 'No payment record found via query fallback',
            'result_code' => $query_result_code,
            'response_data' => $decoded,
        ];
    }

    $result_code = sanitize_text_field($payment['result']['code'] ?? '');
    $internal_status = tanafs_hyperpay_map_result_status($result_code);

    tanafs_log_payment('verification_result', [
        'event' => 'verification_result',
        'booking_token' => $args['booking_token'] ?? '',
        'booking_type' => $args['booking_type'] ?? '',
        'checkout_id' => sanitize_text_field($args['checkout_id'] ?? ''),
        'merchant_transaction_id' => $merchant_transaction_id,
        'transaction_id' => sanitize_text_field($payment['id'] ?? ''),
        'result_code' => $result_code,
        'internal_status' => $internal_status,
        'success' => true,
        'fallback' => 'query_by_merchant_transaction_id',
    ]);

    return [
        'success' => true,
        'payment_status' => $internal_status,
        'result_code' => $result_code,
        'transaction_id' => sanitize_text_field($payment['id'] ?? ''),
        'response_data' => $payment,
        'resource_path' => sanitize_text_field($args['resource_path'] ?? ''),
        'message' => sanitize_text_field($payment['result']['description'] ?? ''),
        'verification_source' => 'query_fallback',
    ];
}

/**
 * Verify HyperPay payment status using status endpoint.
 *
 * @param array $args Verification context.
 * @return array
 */
function tanafs_hyperpay_verify_payment($args = []) {
    $entity_id = sanitize_text_field($args['entity_id'] ?? get_option('tanafs_hyperpay_entity_id', ''));
    $access_token = get_option('tanafs_hyperpay_access_token', '');

    if (empty($entity_id) || empty($access_token)) {
        return [
            'success' => false,
            'message' => 'HyperPay credentials are missing',
        ];
    }

    $resource_path = tanafs_hyperpay_normalize_resource_path($args['resource_path'] ?? '');
    $checkout_id = sanitize_text_field($args['checkout_id'] ?? '');
    $merchant_transaction_id = sanitize_text_field($args['merchant_transaction_id'] ?? '');
    $allow_query_fallback = !empty($args['allow_query_fallback']);
    $skip_checkout_fallback = !empty($args['skip_checkout_fallback']);

    if (!empty($resource_path)) {
        $endpoint = tanafs_hyperpay_get_status_endpoint($resource_path);
    } elseif (!empty($checkout_id)) {
        $endpoint = tanafs_hyperpay_get_base_url() . 'v1/checkouts/' . rawurlencode($checkout_id) . '/payment';
    } else {
        return [
            'success' => false,
            'message' => 'No payment reference for verification',
        ];
    }

    // For /v1/checkouts/{id}/payment verification, HyperPay expects entityId only.
    // merchantTransactionId is valid on checkout creation but rejected here.
    $endpoint = add_query_arg(['entityId' => $entity_id], $endpoint);

    tanafs_log_payment('verification_requested', [
        'event' => 'verification_requested',
        'booking_token' => $args['booking_token'] ?? '',
        'booking_type' => $args['booking_type'] ?? '',
        'checkout_id' => $checkout_id,
        'merchant_transaction_id' => $merchant_transaction_id,
        'resource_path' => $resource_path,
        'endpoint' => $endpoint,
    ]);

    $response = wp_remote_get(
        $endpoint,
        [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
            ],
            'timeout' => 25,
        ]
    );

    if (is_wp_error($response)) {
        tanafs_log_payment('verification_result', [
            'event' => 'verification_result',
            'booking_token' => $args['booking_token'] ?? '',
            'booking_type' => $args['booking_type'] ?? '',
            'checkout_id' => $checkout_id,
            'merchant_transaction_id' => $merchant_transaction_id,
            'internal_status' => 'pending',
            'success' => false,
            'message' => $response->get_error_message(),
        ]);

        return [
            'success' => false,
            'message' => $response->get_error_message(),
        ];
    }

    $status_code = (int) wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $response_headers = wp_remote_retrieve_headers($response);
    $response_headers_log = tanafs_normalize_http_headers_for_log($response_headers);
    $decoded = json_decode($body, true);

    tanafs_log_payment('verification_gateway_response', [
        'event' => 'verification_gateway_response',
        'booking_token' => $args['booking_token'] ?? '',
        'booking_type' => $args['booking_type'] ?? '',
        'checkout_id' => $checkout_id,
        'merchant_transaction_id' => $merchant_transaction_id,
        'resource_path' => $resource_path,
        'endpoint' => $endpoint,
        'http_status' => $status_code,
        'response_headers' => $response_headers_log,
        'response_raw' => $body,
        'response_decoded' => $decoded,
        'hyperpay_request_id' => sanitize_text_field((string) ($response_headers['x-request-id'] ?? ($response_headers['X-Request-Id'] ?? ''))),
    ]);

    if (!is_array($decoded)) {
        tanafs_log_payment('verification_result', [
            'event' => 'verification_result',
            'booking_token' => $args['booking_token'] ?? '',
            'booking_type' => $args['booking_type'] ?? '',
            'checkout_id' => $checkout_id,
            'merchant_transaction_id' => $merchant_transaction_id,
            'internal_status' => 'pending',
            'success' => false,
            'http_status' => $status_code,
            'response_raw' => $body,
            'message' => 'Invalid verification response',
        ]);

        return [
            'success' => false,
            'message' => 'Invalid verification response',
            'http_status' => $status_code,
            'response_raw' => $body,
        ];
    }

    // HyperPay may return non-2xx while still sending structured result codes.
    // Parse these responses and map them to internal status instead of treating
    // every non-2xx as a transport failure.
    if ($status_code < 200 || $status_code >= 300) {
        $result_code = sanitize_text_field($decoded['result']['code'] ?? '');
        $internal_status = tanafs_hyperpay_map_result_status($result_code);

        if ($allow_query_fallback && $result_code === '200.300.404' && !empty($merchant_transaction_id)) {
            $query_fallback = tanafs_hyperpay_verify_via_query($args, $entity_id, $access_token);
            if (!empty($query_fallback['success'])) {
                return $query_fallback;
            }

            $query_result_code = sanitize_text_field($query_fallback['result_code'] ?? '');
            if ($query_result_code === '700.400.580') {
                tanafs_log_payment('verification_result', [
                    'event' => 'verification_result',
                    'booking_token' => $args['booking_token'] ?? '',
                    'booking_type' => $args['booking_type'] ?? '',
                    'checkout_id' => $checkout_id,
                    'merchant_transaction_id' => $merchant_transaction_id,
                    'result_code' => $result_code,
                    'query_result_code' => $query_result_code,
                    'internal_status' => 'pending',
                    'success' => true,
                    'http_status' => $status_code,
                    'message' => 'No payment transaction found yet for this checkout.',
                ]);

                return [
                    'success' => true,
                    'payment_status' => 'pending',
                    'result_code' => $result_code,
                    'query_result_code' => $query_result_code,
                    'transaction_id' => '',
                    'response_data' => $decoded,
                    'resource_path' => sanitize_text_field($resource_path),
                    'message' => 'No payment transaction found yet for this checkout.',
                ];
            }
        }

        tanafs_log_payment('verification_result', [
            'event' => 'verification_result',
            'booking_token' => $args['booking_token'] ?? '',
            'booking_type' => $args['booking_type'] ?? '',
            'checkout_id' => $checkout_id,
            'merchant_transaction_id' => $merchant_transaction_id,
            'result_code' => $result_code,
            'internal_status' => $internal_status,
            'success' => true,
            'http_status' => $status_code,
            'response_raw' => $body,
            'message' => sanitize_text_field($decoded['result']['description'] ?? 'Verification response pending'),
        ]);

        return [
            'success' => true,
            'payment_status' => $internal_status,
            'result_code' => $result_code,
            'transaction_id' => sanitize_text_field($decoded['id'] ?? ''),
            'response_data' => $decoded,
            'resource_path' => sanitize_text_field($resource_path),
            'message' => sanitize_text_field($decoded['result']['description'] ?? ''),
        ];
    }

    $result_code = sanitize_text_field($decoded['result']['code'] ?? '');
    $internal_status = tanafs_hyperpay_map_result_status($result_code);
    $payment_id = sanitize_text_field($decoded['id'] ?? '');

    // Some HyperPay responses return HTTP 200 with business-level no-session code.
    // In this case, query by merchantTransactionId before keeping it pending.
    if ($allow_query_fallback && $result_code === '200.300.404' && !empty($merchant_transaction_id)) {
        $query_fallback = tanafs_hyperpay_verify_via_query($args, $entity_id, $access_token);
        if (!empty($query_fallback['success'])) {
            return $query_fallback;
        }

        $query_result_code = sanitize_text_field($query_fallback['result_code'] ?? '');
        if ($query_result_code === '700.400.580') {
            tanafs_log_payment('verification_result', [
                'event' => 'verification_result',
                'booking_token' => $args['booking_token'] ?? '',
                'booking_type' => $args['booking_type'] ?? '',
                'checkout_id' => $checkout_id,
                'merchant_transaction_id' => $merchant_transaction_id,
                'result_code' => $result_code,
                'query_result_code' => $query_result_code,
                'internal_status' => 'pending',
                'success' => true,
                'http_status' => $status_code,
                'message' => 'No payment transaction found yet for this checkout.',
            ]);

            return [
                'success' => true,
                'payment_status' => 'pending',
                'result_code' => $result_code,
                'query_result_code' => $query_result_code,
                'transaction_id' => '',
                'response_data' => $decoded,
                'resource_path' => sanitize_text_field($resource_path),
                'message' => 'No payment transaction found yet for this checkout.',
            ];
        }
    }

    tanafs_log_payment('verification_result', [
        'event' => 'verification_result',
        'booking_token' => $args['booking_token'] ?? '',
        'booking_type' => $args['booking_type'] ?? '',
        'checkout_id' => $checkout_id,
        'transaction_id' => $payment_id,
        'result_code' => $result_code,
        'internal_status' => $internal_status,
            'mode' => tanafs_gateway_get_mode(),
            'entity_id_suffix' => substr((string) $entity_id, -6),
            'hyperpay_request_id' => sanitize_text_field((string) ($response_headers['x-request-id'] ?? ($response_headers['X-Request-Id'] ?? ''))),
    ]);

    // If resourcePath lookup returns no session, retry once via checkout endpoint.
    if (
        !$skip_checkout_fallback
        && !empty($resource_path)
        && !empty($checkout_id)
        && $result_code === '200.300.404'
    ) {
        $fallback_args = $args;
        $fallback_args['resource_path'] = '';
        $fallback_args['skip_checkout_fallback'] = true;
        $checkout_fallback = tanafs_hyperpay_verify_payment($fallback_args);

        if (!empty($checkout_fallback['success']) && ($checkout_fallback['payment_status'] ?? 'pending') !== 'pending') {
            $checkout_fallback['verification_source'] = 'checkout_fallback';
            return $checkout_fallback;
        }
    }

    return [
        'success' => true,
        'payment_status' => $internal_status,
        'result_code' => $result_code,
        'transaction_id' => $payment_id,
        'response_data' => $decoded,
        'resource_path' => sanitize_text_field($resource_path),
    ];
}

/**
 * Verify HyperPay status with short retries for transient pending responses.
 *
 * @param array $args Verification context.
 * @param int   $max_attempts Number of attempts.
 * @param int   $retry_delay_ms Delay between attempts in milliseconds.
 * @return array
 */
function tanafs_hyperpay_verify_with_retries($args = [], $max_attempts = 3, $retry_delay_ms = 1200) {
    $attempt = 0;
    $last = [
        'success' => false,
        'message' => 'Verification was not completed',
    ];

    while ($attempt < $max_attempts) {
        $attempt++;
        $last = tanafs_hyperpay_verify_payment($args);

        if (empty($last['success']) && !empty($args['resource_path']) && !empty($args['checkout_id'])) {
            $fallback_args = $args;
            $fallback_args['resource_path'] = '';
            $last = tanafs_hyperpay_verify_payment($fallback_args);
        }

        if (!empty($last['success'])) {
            $status = $last['payment_status'] ?? '';
            if ($status !== 'pending') {
                $last['attempts'] = $attempt;
                return $last;
            }
        }

        if ($attempt < $max_attempts) {
            usleep(max(100, (int) $retry_delay_ms) * 1000);
        }
    }

    $last['attempts'] = $attempt;
    return $last;
}

/**
 * Locate payment row by available identifiers.
 *
 * @param array $identifiers Search context.
 * @return object|null
 */
function tanafs_find_payment_record($identifiers = []) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'tanafs_payments';

    $booking_token = sanitize_text_field($identifiers['booking_token'] ?? '');
    $booking_type = sanitize_text_field($identifiers['booking_type'] ?? '');
    $transaction_id = sanitize_text_field($identifiers['transaction_id'] ?? '');
    $checkout_id = sanitize_text_field($identifiers['checkout_id'] ?? '');

    if (!empty($booking_token) && !empty($booking_type)) {
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE booking_token = %s AND booking_type = %s ORDER BY id DESC LIMIT 1",
            $booking_token,
            $booking_type
        ));

        if ($row) {
            return $row;
        }
    }

    if (!empty($booking_token)) {
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE booking_token = %s ORDER BY id DESC LIMIT 1",
            $booking_token
        ));

        if ($row) {
            return $row;
        }

        // Backward-compatible token lookup for legacy token storage.
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE booking_reference = %s ORDER BY id DESC LIMIT 1",
            $booking_token
        ));

        if ($row) {
            return $row;
        }
    }

    if (!empty($transaction_id)) {
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE transaction_id = %s OR booking_reference = %s ORDER BY id DESC LIMIT 1",
            $transaction_id,
            $transaction_id
        ));
    }

    if (!empty($checkout_id)) {
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE hyperpay_checkout_id = %s ORDER BY id DESC LIMIT 1",
            $checkout_id
        ));
    }

    return null;
}

/**
 * Apply payment status transition safely and keep status mirrors in sync.
 *
 * @param object $payment Existing payment row.
 * @param string $new_status New internal status.
 * @param array  $verification Verification response data.
 * @return bool
 */
function tanafs_apply_payment_transition($payment, $new_status, $verification = []) {
    global $wpdb;

    if (!$payment) {
        return false;
    }

    $table_name = $wpdb->prefix . 'tanafs_payments';
    $transaction_id = sanitize_text_field($verification['transaction_id'] ?? '');
    $response_data = isset($verification['response_data']) ? wp_json_encode($verification['response_data']) : '';

    $update_data = [
        'status' => $new_status,
        'payment_status' => $new_status,
    ];
    $formats = ['%s', '%s'];

    if (!empty($response_data)) {
        $update_data['response_data'] = $response_data;
        $formats[] = '%s';
    }

    // Keep old merchant reference in booking_reference while setting transaction_id to HyperPay payment id.
    if (!empty($transaction_id) && $transaction_id !== $payment->transaction_id) {
        $existing = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} WHERE transaction_id = %s AND id != %d",
            $transaction_id,
            (int) $payment->id
        ));

        if ($existing === 0) {
            $update_data['booking_reference'] = $payment->transaction_id;
            $update_data['transaction_id'] = $transaction_id;
            $formats[] = '%s';
            $formats[] = '%s';
        }
    }

    $updated = $wpdb->update(
        $table_name,
        $update_data,
        ['id' => (int) $payment->id],
        $formats,
        ['%d']
    );

    if ($updated !== false) {
        tanafs_log_payment('status_transition', [
            'event' => 'status_transition',
            'booking_token' => $payment->booking_token,
            'booking_type' => $payment->booking_type,
            'from_status' => $payment->payment_status,
            'to_status' => $new_status,
            'checkout_id' => $payment->hyperpay_checkout_id,
            'transaction_id' => !empty($transaction_id) ? $transaction_id : $payment->transaction_id,
        ]);
    }

    return ($updated !== false);
}

/**
 * Log payment transaction for debugging
 * 
 * @param string $type Log type (initiate, callback, verify, etc.)
 * @param array $data Log data
 */
function tanafs_log_payment($type, $data) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'tanafs_payment_logs';
    
    // Create log table if it doesn't exist
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        log_type varchar(50) NOT NULL,
        log_data longtext NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY log_type (log_type),
        KEY created_at (created_at)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    // Insert log entry
    $wpdb->insert(
        $table_name,
        [
            'log_type' => $type,
            'log_data' => json_encode($data),
        ],
        ['%s', '%s']
    );
}

// ============================================================================
// SECTION 3: REWRITE RULES FOR PAYMENT ENDPOINTS
// ============================================================================

/**
 * Add custom rewrite rules for payment endpoints
 */
function tanafs_add_payment_rewrite_rules() {
    add_rewrite_rule('^payment-return/?', 'index.php?payment_return=1', 'top');
    add_rewrite_rule('^payment-callback/?', 'index.php?payment_callback=1', 'top');
    add_rewrite_rule('^payment-widget/?', 'index.php?payment_widget=1', 'top');
}
add_action('init', 'tanafs_add_payment_rewrite_rules');

/**
 * Add query vars for payment endpoints
 */
function tanafs_payment_query_vars($vars) {
    $vars[] = 'payment_return';
    $vars[] = 'payment_callback';
    $vars[] = 'payment_widget';
    return $vars;
}
add_filter('query_vars', 'tanafs_payment_query_vars');

/**
 * Render a minimal hosted HyperPay widget page.
 *
 * Isolates Copy and Pay from page-level scripts/styles that may interfere
 * with form submission and transaction creation.
 */
function tanafs_render_payment_widget_page() {
    if (!get_query_var('payment_widget')) {
        return;
    }

    $booking_token = sanitize_text_field($_GET['booking_token'] ?? '');
    $booking_type = sanitize_text_field($_GET['booking_type'] ?? '');
    $checkout_id = sanitize_text_field($_GET['checkout_id'] ?? '');
    $payment_method = tanafs_hyperpay_normalize_payment_method($_GET['payment_method'] ?? 'card');
    $selected_brands = tanafs_hyperpay_get_brands_for_method($payment_method);
    $encoded_return_url = sanitize_text_field($_GET['return_url'] ?? '');
    $return_url = urldecode((string) $encoded_return_url);

    if (empty($booking_token) || empty($checkout_id)) {
        status_header(400);
        echo 'Invalid payment session.';
        exit;
    }

    $payment = tanafs_find_payment_record([
        'booking_token' => $booking_token,
        'booking_type' => $booking_type,
        'checkout_id' => $checkout_id,
    ]);

    if (!$payment || empty($payment->hyperpay_checkout_id) || $payment->hyperpay_checkout_id !== $checkout_id) {
        status_header(404);
        echo 'Payment checkout not found.';
        exit;
    }

    $home_host = wp_parse_url(home_url('/'), PHP_URL_HOST);
    $return_host = wp_parse_url($return_url, PHP_URL_HOST);
    if (empty($return_url) || empty($return_host) || $return_host !== $home_host) {
        $return_url = home_url('/payment-return/');
    }

    $result_url = add_query_arg([
        'payment_return' => $booking_token,
        'booking_type' => !empty($booking_type) ? $booking_type : sanitize_text_field((string) ($payment->booking_type ?? '')),
    ], $return_url);

    $widget_url = tanafs_hyperpay_get_base_url() . 'v1/paymentWidgets.js?checkoutId=' . rawurlencode($checkout_id);
    $widget_integrity = '';
    if (!empty($payment->response_data)) {
        $creation_payload = json_decode((string) $payment->response_data, true);
        if (is_array($creation_payload)) {
            $widget_integrity = sanitize_text_field((string) ($creation_payload['integrity'] ?? ''));
        }
    }

    tanafs_log_payment('widget_hosted_render', [
        'event' => 'widget_hosted_render',
        'booking_token' => $booking_token,
        'booking_type' => $payment->booking_type,
        'checkout_id' => $checkout_id,
        'result_url' => $result_url,
        'payment_method' => $payment_method,
        'brands' => $selected_brands,
        'widget_integrity_present' => !empty($widget_integrity),
    ]);

    nocache_headers();
    status_header(200);
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Secure Payment</title>
        <script>
            (function() {
                var bookingToken = <?php echo wp_json_encode($booking_token); ?>;
                var checkoutId = <?php echo wp_json_encode($checkout_id); ?>;
                var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
                var widgetUrl = <?php echo wp_json_encode($widget_url); ?>;
                var resultUrl = <?php echo wp_json_encode($result_url); ?>;
                var brands = <?php echo wp_json_encode($selected_brands); ?>;

                console.log('[Tanafs HyperPay Hosted] init_payload', {
                    booking_token: bookingToken,
                    checkout_id: checkoutId,
                    brands: brands,
                    widget_url: widgetUrl,
                    result_url: resultUrl,
                    page_url: window.location.href
                });

                function logHostedEvent(eventName, note) {
                    console.log('[Tanafs HyperPay Hosted] event', {
                        event_name: eventName || 'hosted_widget_event',
                        note: note || '',
                        booking_token: bookingToken,
                        checkout_id: checkoutId,
                        page_url: window.location.href
                    });
                    try {
                        var body = new URLSearchParams();
                        body.append('action', 'tanafs_log_hyperpay_hosted_event');
                        body.append('booking_token', bookingToken || '');
                        body.append('integrity', true);
                        body.append('checkout_id', checkoutId || '');
                        body.append('event_name', eventName || 'hosted_widget_event');
                        body.append('note', note || '');
                        body.append('page_url', window.location.href || '');
                        console.log('[Tanafs HyperPay Hosted] logging event via beacon', {
                            event_name: eventName,
                            body: Object.fromEntries(body.entries())
                        });
                        navigator.sendBeacon(ajaxUrl, body);
                    } catch (e) {
                        console.error('Error logging hosted event:', e);
                        // Ignore logging transport failures.
                    }
                }

                window.__tanafsHostedLogEvent = logHostedEvent;

                window.wpwlOptions = {
                    onReady: function() {
                        logHostedEvent('hosted_widget_ready');
                        setTimeout(function() {
                            var form = document.querySelector('.wpwl-form');
                            if (!form) {
                                logHostedEvent('hosted_widget_form_missing');
                                return;
                            }

                            logHostedEvent('hosted_widget_form_detected', form.getAttribute('action') || '');

                            form.addEventListener('submit', function() {
                                logHostedEvent('hosted_widget_form_submit');
                            }, true);
                        }, 0);
                    },
                    onBeforeSubmitCard: function() {
                        logHostedEvent('hosted_widget_before_submit_card');
                        return true;
                    },
                    onError: function(error) {
                        var message = (error && error.message) ? String(error.message) : 'unknown_widget_error';
                        logHostedEvent('hosted_widget_error', message);
                    }
                };

                window.addEventListener('securitypolicyviolation', function(ev) {
                    var detail = [
                        'directive=' + (ev.violatedDirective || ''),
                        'blocked=' + (ev.blockedURI || ''),
                        'source=' + (ev.sourceFile || ''),
                        'line=' + (ev.lineNumber || 0)
                    ].join('|');
                    logHostedEvent('hosted_widget_csp_violation', detail);
                });
            })();
        </script>
        <script src="<?php echo esc_url($widget_url); ?>"<?php echo !empty($widget_integrity) ? ' integrity="' . esc_attr($widget_integrity) . '" crossorigin="anonymous"' : ''; ?>></script>
        <script>
            if (window.__tanafsHostedLogEvent) {
                window.__tanafsHostedLogEvent('hosted_widget_script_tag_loaded');
            }
        </script>
        <style>
            body { font-family: Arial, sans-serif; margin: 0; padding: 24px; background: #f5f7fb; }
            .wrap { max-width: 720px; margin: 32px auto; background: #fff; border-radius: 12px; padding: 24px; box-shadow: 0 8px 24px rgba(0,0,0,0.08); }
            h2 { margin-top: 0; }
            p { color: #555; }
        </style>
    </head>
    <body>
        <div class="wrap">
            <h2>Secure Payment</h2>
            <p>Please complete your payment to continue.</p>
            <form action="<?php echo esc_url($result_url); ?>" class="paymentWidgets" data-brands="<?php echo esc_attr($selected_brands); ?>"></form>
        </div>
    </body>
    </html>
    <?php
    exit;
}
add_action('template_redirect', 'tanafs_render_payment_widget_page', 4);

// ============================================================================
// SECTION 4: IPN/WEBHOOK CALLBACK HANDLER
// ============================================================================

/**
 * Handle HyperPay webhook callback.
 */
function tanafs_handle_payment_callback() {
    if (get_query_var('payment_callback')) {
        $raw_body = file_get_contents('php://input');
        $decoded = json_decode($raw_body, true);
        $callback_data = is_array($decoded) ? $decoded : $_REQUEST;
        $callback_payload = (is_array($callback_data) && isset($callback_data['payload']) && is_array($callback_data['payload']))
            ? $callback_data['payload']
            : $callback_data;

        $resource_path = tanafs_hyperpay_normalize_resource_path($callback_payload['resourcePath'] ?? ($callback_payload['resource_path'] ?? ''));
        $checkout_id = sanitize_text_field($callback_payload['id'] ?? ($callback_payload['checkoutId'] ?? ''));
        $transaction_id = sanitize_text_field($callback_payload['merchantTransactionId'] ?? '');
        $booking_token = sanitize_text_field(
            $callback_payload['customParameters']['booking_token']
            ?? $callback_payload['customParameters[booking_token]']
            ?? ''
        );
        $booking_type = sanitize_text_field(
            $callback_payload['customParameters']['booking_type']
            ?? $callback_payload['customParameters[booking_type]']
            ?? ''
        );

        tanafs_log_payment('webhook_received', [
            'event' => 'webhook_received',
            'booking_token' => $booking_token,
            'booking_type' => $booking_type,
            'checkout_id' => $checkout_id,
            'transaction_id' => $transaction_id,
            'resource_path' => $resource_path,
        ]);

        $payment = tanafs_find_payment_record([
            'booking_token' => $booking_token,
            'booking_type' => $booking_type,
            'transaction_id' => $transaction_id,
            'checkout_id' => $checkout_id,
        ]);

        if (!$payment) {
            http_response_code(404);
            echo wp_json_encode(['status' => 'not_found']);
            exit;
        }

        if ($payment->payment_status === 'complete') {
            echo wp_json_encode(['status' => 'already_complete']);
            exit;
        }

        $verification = tanafs_hyperpay_verify_payment([
            'booking_token' => $payment->booking_token,
            'booking_type' => $payment->booking_type,
            'resource_path' => $resource_path,
            'checkout_id' => !empty($payment->hyperpay_checkout_id) ? $payment->hyperpay_checkout_id : $checkout_id,
            'entity_id' => sanitize_text_field($payment->hyperpay_entity_id ?? ''),
        ]);

        if (!$verification['success']) {
            tanafs_log_payment('webhook_verified', [
                'event' => 'webhook_verified',
                'booking_token' => $payment->booking_token,
                'booking_type' => $payment->booking_type,
                'checkout_id' => $payment->hyperpay_checkout_id,
                'verification_success' => false,
                'message' => $verification['message'] ?? 'verification_failed',
            ]);
            http_response_code(400);
            echo wp_json_encode(['status' => 'verification_failed']);
            exit;
        }

        $new_status = $verification['payment_status'];
        tanafs_apply_payment_transition($payment, $new_status, $verification);

        tanafs_log_payment('webhook_verified', [
            'event' => 'webhook_verified',
            'booking_token' => $payment->booking_token,
            'booking_type' => $payment->booking_type,
            'checkout_id' => $payment->hyperpay_checkout_id,
            'transaction_id' => $verification['transaction_id'] ?? $payment->transaction_id,
            'verification_success' => true,
            'status' => $new_status,
        ]);

        if ($new_status === 'complete') {
            $fulfillment_result = tanafs_fulfill_booking_from_ipn($payment->booking_token, $payment->booking_type, $verification['response_data'] ?? []);
            tanafs_log_payment('fulfillment_result', [
                'event' => 'fulfillment_result',
                'booking_token' => $payment->booking_token,
                'booking_type' => $payment->booking_type,
                'success' => !empty($fulfillment_result['success']),
                'message' => $fulfillment_result['message'] ?? '',
            ]);
        }

        echo wp_json_encode(['status' => 'received']);
        exit;
    }
}
add_action('template_redirect', 'tanafs_handle_payment_callback', 5);

/**
 * Shared verification handler used by module return flows.
 *
 * @param string $expected_booking_type Booking type.
 */
function tanafs_ajax_verify_payment_status($expected_booking_type) {
    $booking_token = sanitize_text_field($_POST['booking_token'] ?? ($_POST['token'] ?? ''));
    $checkout_id = sanitize_text_field($_POST['checkout_id'] ?? ($_POST['id'] ?? ($_GET['id'] ?? '')));
    $raw_resource_path = $_POST['resourcePath']
        ?? ($_POST['resource_path']
        ?? ($_POST['resourcepath']
        ?? ($_GET['resourcePath']
        ?? ($_GET['resource_path']
        ?? ($_GET['resourcepath'] ?? '')))));
    $resource_path = tanafs_hyperpay_normalize_resource_path($raw_resource_path);

    if (empty($booking_token)) {
        wp_send_json_error([
            'code' => 'session_not_found',
            'message' => 'Booking token is missing',
        ]);
        return;
    }

    $payment = tanafs_find_payment_record([
        'booking_token' => $booking_token,
        'booking_type' => $expected_booking_type,
        'checkout_id' => $checkout_id,
    ]);

    if (!$payment && !empty($checkout_id)) {
        $payment = tanafs_find_payment_record([
            'checkout_id' => $checkout_id,
        ]);
    }

    if (!$payment) {
        wp_send_json_error([
            'code' => 'session_not_found',
            'message' => 'Booking session not found. Please restart registration.',
        ]);
        return;
    }

    if (empty($resource_path) && empty($payment->hyperpay_checkout_id)) {
        wp_send_json_error([
            'status' => 'pending',
            'message' => 'Payment reference not available yet. Please try again shortly.',
        ]);
        return;
    }

    if ($payment->payment_status === 'complete') {
        wp_send_json_success([
            'status' => 'completed',
            'payment_status' => 'completed',
            'booking_token' => $payment->booking_token,
            'payment_verified' => true,
            'redirect_url' => ($expected_booking_type === 'therapy')
                ? (function_exists('pll_current_language') && pll_current_language() === 'ar' ? home_url('/thank-you-arabic/') : home_url('/en/thank-you/'))
                : '',
        ]);
        return;
    }

    $missing_transaction_grace_seconds = 600;
    $missing_tx_grace_key = 'tanafs_hp_missing_tx_' . md5(
        implode('|', [
            (string) ($payment->booking_token ?? ''),
            (string) (!empty($payment->hyperpay_checkout_id) ? $payment->hyperpay_checkout_id : $checkout_id),
            (string) ($payment->transaction_id ?? ''),
        ])
    );
    $missing_tx_first_seen_ts = (int) get_transient($missing_tx_grace_key);
    if ($missing_tx_first_seen_ts <= 0) {
        $missing_tx_first_seen_ts = time();
        set_transient($missing_tx_grace_key, $missing_tx_first_seen_ts, 10 * MINUTE_IN_SECONDS);
    }
    $missing_tx_age_seconds = max(0, time() - $missing_tx_first_seen_ts);

    $verification_args = [
        'booking_token' => $payment->booking_token,
        'booking_type' => $payment->booking_type,
        'checkout_id' => !empty($payment->hyperpay_checkout_id) ? $payment->hyperpay_checkout_id : $checkout_id,
        'merchant_transaction_id' => $payment->transaction_id,
        'entity_id' => sanitize_text_field($payment->hyperpay_entity_id ?? ''),
        'allow_query_fallback' => ($missing_tx_age_seconds >= $missing_transaction_grace_seconds),
    ];

    // Official Copy and Pay flow returns resourcePath in shopperResultUrl.
    // Prefer it when available and fall back to checkout id when missing.
    if (!empty($resource_path)) {
        $verification_args['resource_path'] = $resource_path;
    }

    $verification = tanafs_hyperpay_verify_payment($verification_args);

    if (!$verification['success']) {
        wp_send_json_error([
            'status' => 'pending',
            'message' => 'Could not verify payment yet. Please try again.',
        ]);
        return;
    }

    $new_status = $verification['payment_status'];

    // Avoid immediate hard-fail when HyperPay still hasn't materialized a
    // transaction a few seconds after submit. Keep pending briefly and retry.
    $result_code = sanitize_text_field($verification['result_code'] ?? '');
    $query_result_code = sanitize_text_field($verification['query_result_code'] ?? '');
    if (
        $new_status === 'failed'
        && $result_code === '200.300.404'
        && $query_result_code === '700.400.580'
    ) {
        $now_ts = time();
        $missing_tx_age_seconds = max(0, $now_ts - $missing_tx_first_seen_ts);

        if ($missing_tx_age_seconds < $missing_transaction_grace_seconds) {
        tanafs_log_payment('verification_result', [
            'event' => 'verification_result',
            'booking_token' => $payment->booking_token,
            'booking_type' => $payment->booking_type,
            'checkout_id' => $verification_args['checkout_id'] ?? '',
            'merchant_transaction_id' => $payment->transaction_id,
            'result_code' => $result_code,
            'query_result_code' => $query_result_code,
            'internal_status' => 'pending',
            'success' => true,
            'deferred_failure' => true,
            'missing_tx_age_seconds' => $missing_tx_age_seconds,
            'missing_tx_grace_seconds' => $missing_transaction_grace_seconds,
            'message' => 'Payment is being finalized. Please wait and retry shortly.',
        ]);

        wp_send_json_error([
            'status' => 'pending',
            'payment_status' => 'pending',
            'message' => 'Payment is being finalized. Please wait and retry shortly.',
        ]);
        return;
        }
    }

    if ($new_status === 'complete') {
        delete_transient($missing_tx_grace_key);
    }

    if ($new_status !== $payment->payment_status) {
        tanafs_apply_payment_transition($payment, $new_status, $verification);
    }

    if ($new_status === 'complete') {
        $fulfillment_result = tanafs_fulfill_booking_from_ipn($payment->booking_token, $payment->booking_type, $verification['response_data'] ?? []);
        tanafs_log_payment('fulfillment_result', [
            'event' => 'fulfillment_result',
            'booking_token' => $payment->booking_token,
            'booking_type' => $payment->booking_type,
            'success' => !empty($fulfillment_result['success']),
            'message' => $fulfillment_result['message'] ?? '',
        ]);

        if (empty($fulfillment_result['success'])) {
            wp_send_json_error([
                'status' => 'completed',
                'message' => 'Payment verified but booking fulfillment failed. Please contact support.',
            ]);
            return;
        }

        wp_send_json_success([
            'status' => 'completed',
            'payment_status' => 'completed',
            'booking_token' => $payment->booking_token,
            'redirect_url' => ($expected_booking_type === 'therapy')
                ? (function_exists('pll_current_language') && pll_current_language() === 'ar' ? home_url('/thank-you-arabic/') : home_url('/en/thank-you/'))
                : '',
            'payment_verified' => true,
        ]);
        return;
    }

    if ($new_status === 'failed') {
        wp_send_json_error([
            'status' => 'failed',
            'payment_status' => 'failed',
            'message' => sanitize_text_field($verification['message'] ?? 'Payment failed or was declined.'),
        ]);
        return;
    }

    wp_send_json_error([
        'status' => 'pending',
        'payment_status' => 'pending',
        'message' => sanitize_text_field($verification['message'] ?? 'Payment is still pending. Please wait and retry.'),
    ]);
}

add_action('wp_ajax_tanafs_verify_therapy_payment', function () {
    tanafs_ajax_verify_payment_status('therapy');
});
add_action('wp_ajax_nopriv_tanafs_verify_therapy_payment', function () {
    tanafs_ajax_verify_payment_status('therapy');
});
add_action('wp_ajax_tanafs_verify_retreat_payment', function () {
    tanafs_ajax_verify_payment_status('retreat');
});
add_action('wp_ajax_nopriv_tanafs_verify_retreat_payment', function () {
    tanafs_ajax_verify_payment_status('retreat');
});
add_action('wp_ajax_tanafs_verify_academy_payment', function () {
    tanafs_ajax_verify_payment_status('academy');
});
add_action('wp_ajax_nopriv_tanafs_verify_academy_payment', function () {
    tanafs_ajax_verify_payment_status('academy');
});

/**
 * Collect client-side HyperPay widget events for diagnostics.
 */
function tanafs_ajax_log_hyperpay_client_event() {
    $nonce = sanitize_text_field($_POST['nonce'] ?? '');
    if (empty($nonce)
        || (
            !wp_verify_nonce($nonce, 'retreat_nonce')
            && !wp_verify_nonce($nonce, 'therapy_registration_nonce')
            && !wp_verify_nonce($nonce, 'academy_registration_nonce')
        )
    ) {
        wp_send_json_error(['message' => 'Security verification failed']);
        return;
    }

    $event_name = sanitize_text_field($_POST['event_name'] ?? 'widget_event');
    $payload = [
        'event' => $event_name,
        'booking_token' => sanitize_text_field($_POST['booking_token'] ?? ''),
        'booking_type' => sanitize_text_field($_POST['booking_type'] ?? ''),
        'checkout_id' => sanitize_text_field($_POST['checkout_id'] ?? ''),
        'transaction_id' => sanitize_text_field($_POST['transaction_id'] ?? ''),
        'page_url' => esc_url_raw($_POST['page_url'] ?? ''),
        'note' => sanitize_text_field($_POST['note'] ?? ''),
    ];

    tanafs_log_payment('widget_client_event', $payload);
    wp_send_json_success(['logged' => true]);
}
add_action('wp_ajax_tanafs_log_hyperpay_client_event', 'tanafs_ajax_log_hyperpay_client_event');
add_action('wp_ajax_nopriv_tanafs_log_hyperpay_client_event', 'tanafs_ajax_log_hyperpay_client_event');

/**
 * Hosted widget runtime logger (no nonce) used by minimal payment-widget page.
 */
function tanafs_ajax_log_hyperpay_hosted_event() {
    $booking_token = sanitize_text_field($_POST['booking_token'] ?? '');
    $checkout_id = sanitize_text_field($_POST['checkout_id'] ?? '');
    $event_name = sanitize_text_field($_POST['event_name'] ?? 'hosted_widget_event');

    if (empty($booking_token) || empty($checkout_id)) {
        wp_send_json_error(['message' => 'Missing hosted diagnostics context']);
        return;
    }

    $payment = tanafs_find_payment_record([
        'booking_token' => $booking_token,
        'checkout_id' => $checkout_id,
    ]);

    if (!$payment) {
        wp_send_json_error(['message' => 'Hosted diagnostics payment not found']);
        return;
    }

    tanafs_log_payment('widget_hosted_event', [
        'event' => $event_name,
        'booking_token' => $booking_token,
        'booking_type' => sanitize_text_field((string) ($payment->booking_type ?? '')),
        'checkout_id' => $checkout_id,
        'note' => sanitize_text_field($_POST['note'] ?? ''),
        'page_url' => esc_url_raw($_POST['page_url'] ?? ''),
    ]);

    wp_send_json_success(['logged' => true]);
}
add_action('wp_ajax_tanafs_log_hyperpay_hosted_event', 'tanafs_ajax_log_hyperpay_hosted_event');
add_action('wp_ajax_nopriv_tanafs_log_hyperpay_hosted_event', 'tanafs_ajax_log_hyperpay_hosted_event');

if (!function_exists('therapy_booking_save')) {
    function therapy_booking_save($transient_key, $booking_data, $ttl = null) {
        if ($ttl === null) {
            $ttl = 4 * HOUR_IN_SECONDS;
        }

        set_transient($transient_key, $booking_data, $ttl);

        $option_name = '_tbp_' . $transient_key;
        $stored = [
            'data' => $booking_data,
            'expires' => time() + $ttl,
        ];

        update_option($option_name, $stored, false);
    }
}

if (!function_exists('therapy_booking_get')) {
    function therapy_booking_get($transient_key) {
        $data = get_transient($transient_key);
        if ($data !== false) {
            return $data;
        }

        $option_name = '_tbp_' . $transient_key;
        $stored = get_option($option_name, false);
        if ($stored && isset($stored['data'], $stored['expires'])) {
            if ($stored['expires'] > time()) {
                set_transient($transient_key, $stored['data'], $stored['expires'] - time());
                return $stored['data'];
            }

            delete_option($option_name);
        }

        return false;
    }
}

if (!function_exists('therapy_booking_delete')) {
    function therapy_booking_delete($transient_key) {
        delete_transient($transient_key);
        delete_option('_tbp_' . $transient_key);
    }
}

if (!function_exists('ajax_save_therapy_booking_data')) {
    function ajax_save_therapy_booking_data() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'therapy_registration_nonce')) {
            wp_send_json_error(['message' => 'Security verification failed']);
            return;
        }

        $booking_token = 'therapy_' . bin2hex(random_bytes(16));
        $group_id = intval($_POST['selected_group_id'] ?? 0);

        if ($group_id <= 0) {
            wp_send_json_error(['message' => 'Please select a therapy group session']);
            return;
        }

        $group_post = get_post($group_id);
        if (!$group_post || $group_post->post_type !== 'therapy_group') {
            wp_send_json_error(['message' => 'Invalid therapy group selected']);
            return;
        }

        if (function_exists('get_group_availability_status')) {
            $availability = get_group_availability_status($group_id);
            if (!empty($availability['is_full'])) {
                wp_send_json_error(['message' => 'This therapy group is full. Please select another session.']);
                return;
            }
        }

        $therapy_price = function_exists('get_field') ? get_field('therapy_price', $group_id) : 0;
        if (empty($therapy_price) || (float) $therapy_price <= 0) {
            $therapy_price = 3500;
        }

        $personal_info = [
            'first_name' => sanitize_text_field($_POST['first_name'] ?? ''),
            'last_name' => sanitize_text_field($_POST['last_name'] ?? ''),
            'email' => sanitize_email($_POST['email'] ?? ''),
            'phone' => sanitize_text_field($_POST['phone'] ?? ''),
            'passport_number' => sanitize_text_field($_POST['passport_number'] ?? ''),
            'country' => sanitize_text_field($_POST['country'] ?? ''),
            'birth_date' => sanitize_text_field($_POST['birth_date'] ?? ''),
            'password' => (string) ($_POST['password'] ?? ''),
        ];

        $required = ['first_name', 'last_name', 'email', 'phone', 'passport_number', 'country', 'birth_date', 'password'];
        foreach ($required as $field) {
            if (empty($personal_info[$field])) {
                wp_send_json_error(['message' => 'Please fill in all required fields: ' . $field]);
                return;
            }
        }

        if (!is_email($personal_info['email'])) {
            wp_send_json_error(['message' => 'Please enter a valid email address']);
            return;
        }

        $existing_user_id = email_exists($personal_info['email']);
        $is_existing_user = ($existing_user_id !== false);

        if (!$is_existing_user && strlen($personal_info['password']) < 8) {
            wp_send_json_error(['message' => 'Password must be at least 8 characters long']);
            return;
        }

        $session_data = [];
        if (!session_id() && !headers_sent()) {
            @session_start();
        }

        if (isset($_SESSION['issue'])) {
            $session_data['issue'] = $_SESSION['issue'];
        }
        if (isset($_SESSION['gender'])) {
            $session_data['gender'] = $_SESSION['gender'];
        }
        if (isset($_SESSION['user_concern_type'])) {
            $session_data['concern_type'] = $_SESSION['user_concern_type'];
        }
        if (isset($_SESSION['assessment_passed'])) {
            $session_data['assessment_passed'] = $_SESSION['assessment_passed'];
        }
        if (isset($_SESSION['posted_data'])) {
            $session_data['posted_data'] = $_SESSION['posted_data'];
        }

        $booking_data = [
            'booking_token' => $booking_token,
            'booking_type' => $is_existing_user ? 'therapy_existing_user' : 'therapy',
            'group_id' => $group_id,
            'group_title' => $group_post->post_title,
            'personal_info' => $personal_info,
            'session_data' => $session_data,
            'amount' => (float) $therapy_price,
            'currency' => get_option('tanafs_hyperpay_currency', get_option('tanafs_aps_currency', 'SAR')),
            'booking_state' => 'pending_payment',
            'created_at' => current_time('mysql'),
            'ip_address' => sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'existing_user_id' => $is_existing_user ? (int) $existing_user_id : null,
        ];

        $transient_key = 'therapy_' . str_replace('therapy_', '', $booking_token);
        therapy_booking_save($transient_key, $booking_data, 4 * HOUR_IN_SECONDS);

        wp_send_json_success([
            'booking_token' => $booking_token,
            'amount' => (float) $therapy_price,
            'currency' => get_option('tanafs_hyperpay_currency', get_option('tanafs_aps_currency', 'SAR')),
            'group_title' => $group_post->post_title,
        ]);
    }

    add_action('wp_ajax_save_therapy_booking_data', 'ajax_save_therapy_booking_data');
    add_action('wp_ajax_nopriv_save_therapy_booking_data', 'ajax_save_therapy_booking_data');
}

// --------------------------------------------------------------------------
// Compatibility fallback: retreat booking staging endpoint
// --------------------------------------------------------------------------

if (!function_exists('retreat_booking_save')) {
    function retreat_booking_save($transient_key, $booking_data, $ttl = null) {
        if ($ttl === null) {
            $ttl = 4 * HOUR_IN_SECONDS;
        }

        set_transient($transient_key, $booking_data, $ttl);

        $option_name = '_rbp_' . $transient_key;
        $stored = [
            'data' => $booking_data,
            'expires' => time() + $ttl,
        ];

        update_option($option_name, $stored, false);
    }
}

if (!function_exists('retreat_booking_get')) {
    function retreat_booking_get($transient_key) {
        $data = get_transient($transient_key);
        if ($data !== false) {
            return $data;
        }

        $option_name = '_rbp_' . $transient_key;
        $stored = get_option($option_name, false);
        if ($stored && isset($stored['data'], $stored['expires'])) {
            if ($stored['expires'] > time()) {
                set_transient($transient_key, $stored['data'], $stored['expires'] - time());
                return $stored['data'];
            }

            delete_option($option_name);
        }

        return false;
    }
}

if (!function_exists('retreat_booking_delete')) {
    function retreat_booking_delete($transient_key) {
        delete_transient($transient_key);
        delete_option('_rbp_' . $transient_key);
    }
}

if (!function_exists('ajax_save_retreat_booking_data')) {
    function ajax_save_retreat_booking_data() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'retreat_nonce')) {
            wp_send_json_error(['message' => 'Security verification failed']);
            return;
        }

        $booking_token = bin2hex(random_bytes(16));
        $currency = get_option('tanafs_hyperpay_currency', get_option('tanafs_aps_currency', 'SAR'));

        $booking_data = [
            'personal_info' => [
                'full_name' => sanitize_text_field($_POST['full_name'] ?? ''),
                'email' => sanitize_email($_POST['email'] ?? ''),
                'phone' => sanitize_text_field($_POST['phone'] ?? ''),
                'country' => sanitize_text_field($_POST['country'] ?? ''),
                'gender' => sanitize_text_field($_POST['gender'] ?? ''),
                'birth_date' => sanitize_text_field($_POST['birth_date'] ?? ''),
                'password' => $_POST['password'] ?? '',
            ],
            'retreat_type' => sanitize_text_field($_POST['retreat_type'] ?? ''),
            'group_id' => intval($_POST['group_id'] ?? 0),
            'amount' => (float) ($_POST['amount'] ?? 0),
            'currency' => sanitize_text_field($currency),
            'payment_status' => 'pending',
            'created_at' => current_time('mysql'),
            'return_url' => esc_url_raw($_POST['return_url'] ?? ''),
            'scroll_to_section' => sanitize_text_field($_POST['scroll_to_section'] ?? ''),
        ];

        if (!empty($_FILES['passport'])) {
            if (!function_exists('wp_handle_upload')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }

            $upload = wp_handle_upload($_FILES['passport'], ['test_form' => false]);
            if (isset($upload['file'])) {
                $booking_data['passport_file'] = $upload['file'];
                $booking_data['passport_url'] = $upload['url'];
            }
        }

        $transient_key = 'retreat_' . $booking_token;
        retreat_booking_save($transient_key, $booking_data, 4 * HOUR_IN_SECONDS);

        wp_send_json_success([
            'message' => 'Booking data saved successfully',
            'token' => $booking_token,
        ]);
    }

    add_action('wp_ajax_save_retreat_booking_data', 'ajax_save_retreat_booking_data');
    add_action('wp_ajax_nopriv_save_retreat_booking_data', 'ajax_save_retreat_booking_data');
}

/**
 * Route booking fulfillment to appropriate module handler
 * 
 * @param string $booking_token Unique booking identifier
 * @param string $booking_type Module type (therapy, retreat, academy)
 * @param array $payment_data APS callback data
 * @return array ['success' => bool, 'message' => string, 'user_id' => int|null]
 */
function tanafs_fulfill_booking_from_ipn($booking_token, $booking_type, $payment_data) {
    switch ($booking_type) {
        case 'therapy':
            // Call existing therapy booking processor
            if (function_exists('process_therapy_booking_from_ipn')) {
                $transient_key = 'therapy_' . str_replace('therapy_', '', $booking_token);
                
                if (function_exists('therapy_booking_get')) {
                    $booking_data = therapy_booking_get($transient_key);
                } else {
                    $booking_data = get_transient($transient_key);
                }

                // Backward-compatible fallback for older logged-in therapy key format.
                if (!$booking_data) {
                    if (function_exists('therapy_booking_get')) {
                        $booking_data = therapy_booking_get($booking_token);
                    } else {
                        $booking_data = get_transient($booking_token);
                    }
                }
                
                if (!$booking_data) {
                    return [
                        'success' => false,
                        'message' => 'Therapy booking data not found in transient storage',
                    ];
                }
                
                return process_therapy_booking_from_ipn($booking_data, $booking_token);
            }
            break;
            
        case 'retreat':
            // Call existing retreat booking processor
            if (function_exists('process_retreat_booking_from_ipn')) {
                $transient_key = 'retreat_' . $booking_token;
                
                if (function_exists('retreat_booking_get')) {
                    $booking_data = retreat_booking_get($transient_key);
                } else {
                    $booking_data = get_transient($transient_key);
                }
                
                if (!$booking_data) {
                    return [
                        'success' => false,
                        'message' => 'Retreat booking data not found in transient storage',
                    ];
                }
                
                return process_retreat_booking_from_ipn($booking_data, $booking_token);
            }
            break;
            
        case 'academy':
            // Call academy booking processor
            if (function_exists('process_academy_booking_from_ipn')) {
                return process_academy_booking_from_ipn($booking_token, $payment_data);
            }
            break;
            
        default:
            return [
                'success' => false,
                'message' => 'Unknown booking type: ' . $booking_type,
            ];
    }
    
    return [
        'success' => false,
        'message' => 'Booking fulfillment function not found for ' . $booking_type,
    ];
}

// ============================================================================
// SECTION 5: AJAX HANDLERS FOR MODULE-SPECIFIC PAYMENT INITIATION
// ============================================================================

/**
 * AJAX: Initiate therapy payment
 */
add_action('wp_ajax_tanafs_initiate_therapy_payment', 'tanafs_ajax_initiate_therapy_payment');
add_action('wp_ajax_nopriv_tanafs_initiate_therapy_payment', 'tanafs_ajax_initiate_therapy_payment');

function tanafs_ajax_initiate_therapy_payment() {
    // Verify nonce
    $therapy_nonce = sanitize_text_field($_POST['nonce'] ?? ($_POST['therapy_nonce'] ?? ''));
    if (empty($therapy_nonce)
        || (
            !wp_verify_nonce($therapy_nonce, 'therapy_registration_nonce')
            && !wp_verify_nonce($therapy_nonce, 'therapy_nonce')
        )
    ) {
        wp_send_json_error(['message' => 'Security verification failed']);
        return;
    }
    
    $booking_token = sanitize_text_field($_POST['booking_token'] ?? '');
    $payment_method = tanafs_hyperpay_normalize_payment_method($_POST['payment_method'] ?? 'card');
    if (empty($booking_token)) {
        wp_send_json_error(['message' => 'Invalid booking session']);
        return;
    }
    
    // Retrieve booking data from transient
    $transient_key = 'therapy_' . str_replace('therapy_', '', $booking_token);
    
    if (function_exists('therapy_booking_get')) {
        $booking_data = therapy_booking_get($transient_key);
    } else {
        $booking_data = get_transient($transient_key);
    }
    
    if (!$booking_data) {
        wp_send_json_error(['message' => 'Booking session expired. Please fill out the form again.']);
        return;
    }
    
    // Prepare customer details
    $personal_info = $booking_data['personal_info'];
    $customer_details = [
        'name' => $personal_info['first_name'] . ' ' . $personal_info['last_name'],
        'email' => $personal_info['email'],
        'phone' => $personal_info['phone'],
        'country' => $personal_info['country'] ?? 'SA',
        'city' => 'Riyadh',
        'state' => 'Riyadh',
        'street1' => 'N/A',
        'postcode' => '11564',
    ];
    
    // Determine return URL based on language
    $lang = function_exists('pll_current_language') ? pll_current_language() : 'en';
    $return_url = ($lang === 'ar') ? home_url('/register-arabic/') : home_url('/en/register/');
    
    // Initiate HyperPay checkout
    $result = tanafs_hyperpay_create_checkout(
        $booking_token,
        'therapy',
        $booking_data['amount'],
        $customer_details,
        [
            'currency' => $booking_data['currency'],
            'return_url' => $return_url,
            'payment_method' => $payment_method,
        ]
    );
    
    if ($result['success']) {
        $hosted_checkout_url = tanafs_build_hosted_checkout_url(
            $booking_token,
            'therapy',
            $result['checkout_id'],
            $result['return_url'],
            $payment_method
        );

        wp_send_json_success([
            'gateway' => 'hyperpay',
            'checkout_id' => $result['checkout_id'],
            'widget_url' => $result['widget_url'],
            'result_url' => $result['return_url'],
            'brands' => $result['brands'] ?? tanafs_hyperpay_get_brands_for_method($payment_method),
            'payment_method' => $payment_method,
            'hosted_checkout_url' => $hosted_checkout_url,
            'transaction_id' => $result['transaction_id'],
        ]);
    } else {
        wp_send_json_error(['message' => $result['error']]);
    }
}

/**
 * AJAX: Initiate retreat payment
 */
add_action('wp_ajax_tanafs_initiate_retreat_payment', 'tanafs_ajax_initiate_retreat_payment');
add_action('wp_ajax_nopriv_tanafs_initiate_retreat_payment', 'tanafs_ajax_initiate_retreat_payment');

function tanafs_ajax_initiate_retreat_payment() {
    // Verify nonce
    $retreat_nonce = sanitize_text_field($_POST['nonce'] ?? ($_POST['retreat_nonce'] ?? ''));
    if (empty($retreat_nonce)
        || (
            !wp_verify_nonce($retreat_nonce, 'retreat_booking_nonce')
            && !wp_verify_nonce($retreat_nonce, 'retreat_nonce')
        )
    ) {
        wp_send_json_error(['message' => 'Security verification failed']);
        return;
    }
    
    $booking_token = sanitize_text_field($_POST['booking_token'] ?? ($_POST['token'] ?? ''));
    $payment_method = tanafs_hyperpay_normalize_payment_method($_POST['payment_method'] ?? 'card');
    if (empty($booking_token)) {
        wp_send_json_error(['message' => 'Invalid booking session']);
        return;
    }
    
    // Retrieve booking data from transient
    $cart_key = 'retreat_' . $booking_token;
    
    if (function_exists('retreat_booking_get')) {
        $booking_data = retreat_booking_get($cart_key);
    } else {
        $booking_data = get_transient($cart_key);
    }
    
    if (!$booking_data) {
        wp_send_json_error(['message' => 'Booking session expired. Please fill out the form again.']);
        return;
    }
    
    // Prepare customer details
    $personal_info = $booking_data['personal_info'];
    $customer_details = [
        'name' => $personal_info['full_name'],
        'email' => $personal_info['email'],
        'phone' => $personal_info['phone'],
        'country' => $personal_info['country'] ?? 'SA',
        'city' => 'Riyadh',
        'state' => 'Riyadh',
        'street1' => 'N/A',
        'postcode' => '11564',
    ];
    
    $retreat_page_url = esc_url_raw($booking_data['return_url'] ?? ($booking_data['retreat_page_url'] ?? ''));
    if (empty($retreat_page_url)) {
        $retreat_page_url = (function_exists('pll_current_language') && pll_current_language() === 'ar')
            ? home_url('/retreat-arabic/')
            : home_url('/en/retreat/');
    }
    
    // Initiate HyperPay checkout
    $result = tanafs_hyperpay_create_checkout(
        $booking_token,
        'retreat',
        $booking_data['amount'],
        $customer_details,
        [
            'currency' => $booking_data['currency'],
            'return_url' => $retreat_page_url,
            'payment_method' => $payment_method,
        ]
    );
    
    if ($result['success']) {
        $hosted_checkout_url = tanafs_build_hosted_checkout_url(
            $booking_token,
            'retreat',
            $result['checkout_id'],
            $result['return_url'],
            $payment_method
        );

        wp_send_json_success([
            'gateway' => 'hyperpay',
            'checkout_id' => $result['checkout_id'],
            'widget_url' => $result['widget_url'],
            'result_url' => $result['return_url'],
            'brands' => $result['brands'] ?? tanafs_hyperpay_get_brands_for_method($payment_method),
            'payment_method' => $payment_method,
            'hosted_checkout_url' => $hosted_checkout_url,
            'transaction_id' => $result['transaction_id'],
        ]);
    } else {
        wp_send_json_error(['message' => $result['error']]);
    }
}

/**
 * AJAX: Initiate therapy payment for logged-in users
 */
add_action('wp_ajax_tanafs_initiate_therapy_payment_logged_in', 'tanafs_ajax_initiate_therapy_payment_logged_in');

function tanafs_ajax_initiate_therapy_payment_logged_in() {
    // Verify nonce
    $therapy_nonce = sanitize_text_field($_POST['nonce'] ?? ($_POST['therapy_nonce'] ?? ''));
    if (empty($therapy_nonce)
        || (
            !wp_verify_nonce($therapy_nonce, 'therapy_registration_nonce')
            && !wp_verify_nonce($therapy_nonce, 'therapy_nonce')
        )
    ) {
        wp_send_json_error(['message' => 'Security verification failed']);
        return;
    }
    
    // Must be logged in
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'You must be logged in to make a payment']);
        return;
    }
    
    $current_user = wp_get_current_user();
    $selected_group_id = intval($_POST['selected_group_id'] ?? 0);
    $payment_method = tanafs_hyperpay_normalize_payment_method($_POST['payment_method'] ?? 'card');
    
    if ($selected_group_id <= 0) {
        wp_send_json_error(['message' => 'Please select a therapy group session']);
        return;
    }
    
    // Get therapy price from ACF field
    $therapy_price = get_field('therapy_price', $selected_group_id);
    if (empty($therapy_price) || $therapy_price <= 0) {
        $therapy_price = 3500; // Default price
    }
    
    // Generate unique booking token
    $booking_token = 'therapy_logged_in_' . bin2hex(random_bytes(16));
    
    // Store minimal booking data in transient
    $booking_data = [
        'user_id' => $current_user->ID,
        'group_id' => $selected_group_id,
        'amount' => $therapy_price,
        'booking_type' => 'therapy_logged_in',
    ];

    $transient_key = 'therapy_' . str_replace('therapy_', '', $booking_token);
    
    // Store using therapy helper function if available
    if (function_exists('therapy_booking_save')) {
        therapy_booking_save($transient_key, $booking_data, 4 * HOUR_IN_SECONDS);
    } else {
        set_transient($transient_key, $booking_data, 4 * HOUR_IN_SECONDS);
    }
    
    // Prepare customer details
    $customer_details = [
        'name' => $current_user->display_name ?: $current_user->user_login,
        'email' => $current_user->user_email,
        'phone' => get_user_meta($current_user->ID, 'phone', true) ?: '',
        'country' => get_user_meta($current_user->ID, 'country', true) ?: 'SA',
        'city' => 'Riyadh',
        'state' => 'Riyadh',
        'street1' => 'N/A',
        'postcode' => '11564',
    ];
    
    // Determine return URL based on language
    $lang = function_exists('pll_current_language') ? pll_current_language() : 'en';
    $return_url = ($lang === 'ar') ? home_url('/register-arabic/') : home_url('/en/register/');
    
    // Initiate HyperPay checkout
    $result = tanafs_hyperpay_create_checkout(
        $booking_token,
        'therapy',
        $therapy_price,
        $customer_details,
        [
            'currency' => get_option('tanafs_hyperpay_currency', get_option('tanafs_aps_currency', 'SAR')),
            'return_url' => $return_url,
            'payment_method' => $payment_method,
        ]
    );
    
    if ($result['success']) {
        $hosted_checkout_url = tanafs_build_hosted_checkout_url(
            $booking_token,
            'therapy',
            $result['checkout_id'],
            $result['return_url'],
            $payment_method
        );

        wp_send_json_success([
            'gateway' => 'hyperpay',
            'checkout_id' => $result['checkout_id'],
            'widget_url' => $result['widget_url'],
            'result_url' => $result['return_url'],
            'brands' => $result['brands'] ?? tanafs_hyperpay_get_brands_for_method($payment_method),
            'payment_method' => $payment_method,
            'hosted_checkout_url' => $hosted_checkout_url,
            'transaction_id' => $result['transaction_id'],
        ]);
    } else {
        wp_send_json_error(['message' => $result['error']]);
    }
}

/**
 * AJAX: Initiate academy payment
 */
add_action('wp_ajax_tanafs_initiate_academy_payment', 'tanafs_ajax_initiate_academy_payment');
add_action('wp_ajax_nopriv_tanafs_initiate_academy_payment', 'tanafs_ajax_initiate_academy_payment');

function tanafs_ajax_initiate_academy_payment() {
    // Verify nonce
    $academy_nonce = sanitize_text_field($_POST['nonce'] ?? ($_POST['academy_nonce'] ?? ''));
    if (empty($academy_nonce) || !wp_verify_nonce($academy_nonce, 'academy_registration_nonce')) {
        wp_send_json_error(['message' => 'Security verification failed']);
        return;
    }
    
    $program_id = intval($_POST['program_id'] ?? 0);
    $payment_method = tanafs_hyperpay_normalize_payment_method($_POST['payment_method'] ?? 'card');
    $full_name = sanitize_text_field($_POST['full_name'] ?? '');
    $email = sanitize_email($_POST['email'] ?? '');
    $phone = sanitize_text_field($_POST['phone'] ?? '');
    $return_page_url = esc_url_raw($_POST['return_page_url'] ?? home_url('/academy/'));
    
    // Validate inputs
    if ($program_id <= 0 || empty($full_name) || empty($email)) {
        wp_send_json_error(['message' => 'Please fill in all required fields']);
        return;
    }
    
    // Get program details to determine price
    global $wpdb;
    $program = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}academy_programs WHERE id = %d",
        $program_id
    ));
    
    if (!$program) {
        wp_send_json_error(['message' => 'Invalid program selected']);
        return;
    }
    
    // Set academy price (you can add a custom field for this)
    $amount = 2500; // Default academy program price in SAR
    
    // Generate unique booking token
    $booking_token = 'academy_' . bin2hex(random_bytes(16));
    
    // Store booking data in transient
    $booking_data = [
        'program_id' => $program_id,
        'full_name' => $full_name,
        'email' => $email,
        'phone' => $phone,
        'job_title' => sanitize_text_field($_POST['job_title'] ?? ''),
        'license_number' => sanitize_text_field($_POST['license_number'] ?? ''),
        'country' => sanitize_text_field($_POST['country'] ?? ''),
        'amount' => $amount,
    ];
    
    set_transient('academy_' . str_replace('academy_', '', $booking_token), $booking_data, 4 * HOUR_IN_SECONDS);
    
    // Prepare customer details
    $customer_details = [
        'name' => $full_name,
        'email' => $email,
        'phone' => $phone,
        'country' => sanitize_text_field($_POST['country'] ?? 'SA'),
        'city' => 'Riyadh',
        'state' => 'Riyadh',
        'street1' => 'N/A',
        'postcode' => '11564',
    ];
    
    // Initiate HyperPay checkout
    $result = tanafs_hyperpay_create_checkout(
        $booking_token,
        'academy',
        $amount,
        $customer_details,
        [
            'currency' => 'SAR',
            'return_url' => $return_page_url,
            'payment_method' => $payment_method,
        ]
    );
    
    if ($result['success']) {
        $hosted_checkout_url = tanafs_build_hosted_checkout_url(
            $booking_token,
            'academy',
            $result['checkout_id'],
            $result['return_url'],
            $payment_method
        );

        wp_send_json_success([
            'gateway' => 'hyperpay',
            'checkout_id' => $result['checkout_id'],
            'widget_url' => $result['widget_url'],
            'result_url' => $result['return_url'],
            'brands' => $result['brands'] ?? tanafs_hyperpay_get_brands_for_method($payment_method),
            'payment_method' => $payment_method,
            'hosted_checkout_url' => $hosted_checkout_url,
            'transaction_id' => $result['transaction_id'],
        ]);
    } else {
        wp_send_json_error(['message' => $result['error']]);
    }
}

// ============================================================================
// HELPER NOTES
// ============================================================================

/**
 * Shared fulfillment handlers remain in module files.
 * Payment orchestration stays in this file.
 */

// Academy fulfillment is implemented in `tanafs_academy.php` only.
