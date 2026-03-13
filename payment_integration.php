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

        // Keep compatibility with existing mode checks that use "live".
        if ($mode === 'production') {
            $mode = 'live';
        }

        update_option('tanafs_hyperpay_entity_id', $entity_id);
        update_option('tanafs_hyperpay_access_token', $access_token);
        update_option('tanafs_hyperpay_mode', $mode);
        update_option('tanafs_hyperpay_currency', $currency);

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
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($payments)): ?>
                        <tr>
                            <td colspan="9" style="text-align: center; padding: 40px;">
                                No payments found matching your criteria.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($payments as $payment): ?>
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
        return 'https://oppwa.com';
    }

    return 'https://eu-test.oppwa.com';
}

/**
 * Get HyperPay checkout creation endpoint.
 *
 * @return string
 */
function tanafs_hyperpay_get_checkout_endpoint() {
    return tanafs_hyperpay_get_base_url() . '/v1/checkouts';
}

/**
 * Build HyperPay status endpoint from resource path.
 *
 * @param string $resource_path HyperPay resource path returned from checkout/payment response.
 * @return string
 */
function tanafs_hyperpay_get_status_endpoint($resource_path) {
    $resource_path = trim((string) $resource_path);

    if (strpos($resource_path, 'http') === 0) {
        return $resource_path;
    }

    if (strpos($resource_path, '/') !== 0) {
        $resource_path = '/' . $resource_path;
    }

    return tanafs_hyperpay_get_base_url() . $resource_path;
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
function tanafs_insert_pending_payment($transaction_id, $booking_token, $booking_type, $amount, $currency, $customer_details) {
    global $wpdb;

    $table_name = $wpdb->prefix . 'tanafs_payments';
    $payment_data = [
        'transaction_id' => $transaction_id,
        'booking_token' => $booking_token,
        'booking_reference' => $booking_token,
        'booking_type' => $booking_type,
        'customer_name' => sanitize_text_field($customer_details['name'] ?? ''),
        'customer_email' => sanitize_email($customer_details['email'] ?? ''),
        'customer_phone' => sanitize_text_field($customer_details['phone'] ?? ''),
        'amount' => (float) $amount,
        'currency' => sanitize_text_field($currency),
        'status' => 'pending',
        'payment_status' => 'pending',
        'ip_address' => sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? ''),
        'user_agent' => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
    ];

    $inserted = $wpdb->insert($table_name, $payment_data);

    if (!$inserted) {
        tanafs_log_payment('initiation_failed', [
            'event' => 'initiation_failed',
            'reason' => 'db_insert_failed',
            'booking_token' => $booking_token,
            'booking_type' => $booking_type,
            'transaction_id' => $transaction_id,
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
    $inserted = tanafs_insert_pending_payment($transaction_id, $booking_token, $booking_type, $amount, $currency, $customer_details);
    if (!$inserted) {
        return [
            'success' => false,
            'error' => 'Failed to create payment record. Please try again.',
        ];
    }

    $name_parts = tanafs_hyperpay_split_name($customer_details['name'] ?? '');

    $request_body = [
        'entityId' => $entity_id,
        'amount' => $amount_formatted,
        'currency' => $currency,
        'paymentType' => 'DB',
        'merchantTransactionId' => $transaction_id,
        'customer.email' => sanitize_email($customer_details['email'] ?? ''),
        'customer.givenName' => $name_parts['first_name'],
        'customer.surname' => $name_parts['last_name'],
        'shopperResultUrl' => esc_url_raw($return_url),
        'customParameters[booking_token]' => sanitize_text_field($booking_token),
        'customParameters[booking_type]' => sanitize_text_field($booking_type),
    ];

    tanafs_log_payment('initiation_requested', [
        'event' => 'initiation_requested',
        'booking_token' => $booking_token,
        'booking_type' => $booking_type,
        'transaction_id' => $transaction_id,
        'amount' => $amount_formatted,
        'currency' => $currency,
    ]);

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

        return [
            'success' => false,
            'error' => 'Unable to connect to payment gateway. Please try again.',
        ];
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);
    $decoded = json_decode($response_body, true);

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

    $widget_url = tanafs_hyperpay_get_base_url() . '/v1/paymentWidgets.js?checkoutId=' . rawurlencode($checkout_id);

    tanafs_log_payment('initiation_created', [
        'event' => 'initiation_created',
        'booking_token' => $booking_token,
        'booking_type' => $booking_type,
        'transaction_id' => $transaction_id,
        'checkout_id' => $checkout_id,
    ]);

    return [
        'success' => true,
        'checkout_id' => $checkout_id,
        'widget_url' => $widget_url,
        'transaction_id' => $transaction_id,
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

    if (preg_match('/^(000\.000\.|000\.100\.1|000\.[36])/', $result_code)) {
        return 'complete';
    }

    if (preg_match('/^(000\.200)/', $result_code)) {
        return 'pending';
    }

    return 'failed';
}

/**
 * Verify HyperPay payment status using status endpoint.
 *
 * @param array $args Verification context.
 * @return array
 */
function tanafs_hyperpay_verify_payment($args = []) {
    $entity_id = get_option('tanafs_hyperpay_entity_id', '');
    $access_token = get_option('tanafs_hyperpay_access_token', '');

    if (empty($entity_id) || empty($access_token)) {
        return [
            'success' => false,
            'message' => 'HyperPay credentials are missing',
        ];
    }

    $resource_path = sanitize_text_field($args['resource_path'] ?? '');
    $checkout_id = sanitize_text_field($args['checkout_id'] ?? '');

    if (!empty($resource_path)) {
        $endpoint = tanafs_hyperpay_get_status_endpoint($resource_path);
    } elseif (!empty($checkout_id)) {
        $endpoint = tanafs_hyperpay_get_base_url() . '/v1/checkouts/' . rawurlencode($checkout_id) . '/payment';
    } else {
        return [
            'success' => false,
            'message' => 'No payment reference for verification',
        ];
    }

    $endpoint = add_query_arg(['entityId' => $entity_id], $endpoint);

    tanafs_log_payment('verification_requested', [
        'event' => 'verification_requested',
        'booking_token' => $args['booking_token'] ?? '',
        'booking_type' => $args['booking_type'] ?? '',
        'checkout_id' => $checkout_id,
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
        return [
            'success' => false,
            'message' => $response->get_error_message(),
        ];
    }

    $status_code = (int) wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $decoded = json_decode($body, true);

    if ($status_code < 200 || $status_code >= 300 || !is_array($decoded)) {
        return [
            'success' => false,
            'message' => 'Invalid verification response',
            'http_status' => $status_code,
            'response_raw' => $body,
        ];
    }

    $result_code = sanitize_text_field($decoded['result']['code'] ?? '');
    $internal_status = tanafs_hyperpay_map_result_status($result_code);
    $payment_id = sanitize_text_field($decoded['id'] ?? '');

    tanafs_log_payment('verification_result', [
        'event' => 'verification_result',
        'booking_token' => $args['booking_token'] ?? '',
        'booking_type' => $args['booking_type'] ?? '',
        'checkout_id' => $checkout_id,
        'transaction_id' => $payment_id,
        'result_code' => $result_code,
        'internal_status' => $internal_status,
    ]);

    return [
        'success' => true,
        'payment_status' => $internal_status,
        'result_code' => $result_code,
        'transaction_id' => $payment_id,
        'response_data' => $decoded,
        'resource_path' => sanitize_text_field($decoded['id'] ?? $resource_path),
    ];
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
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE booking_token = %s AND booking_type = %s ORDER BY id DESC LIMIT 1",
            $booking_token,
            $booking_type
        ));
    }

    if (!empty($booking_token)) {
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE booking_token = %s ORDER BY id DESC LIMIT 1",
            $booking_token
        ));
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
}
add_action('init', 'tanafs_add_payment_rewrite_rules');

/**
 * Add query vars for payment endpoints
 */
function tanafs_payment_query_vars($vars) {
    $vars[] = 'payment_return';
    $vars[] = 'payment_callback';
    return $vars;
}
add_filter('query_vars', 'tanafs_payment_query_vars');

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

        $resource_path = sanitize_text_field($callback_data['resourcePath'] ?? ($callback_data['resource_path'] ?? ''));
        $checkout_id = sanitize_text_field($callback_data['id'] ?? ($callback_data['checkoutId'] ?? ''));
        $transaction_id = sanitize_text_field($callback_data['merchantTransactionId'] ?? '');
        $booking_token = sanitize_text_field(
            $callback_data['customParameters']['booking_token']
            ?? $callback_data['customParameters[booking_token]']
            ?? ''
        );
        $booking_type = sanitize_text_field(
            $callback_data['customParameters']['booking_type']
            ?? $callback_data['customParameters[booking_type]']
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
    $resource_path = sanitize_text_field($_POST['resourcePath'] ?? ($_GET['resourcePath'] ?? ''));

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
    ]);

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

    $verification = tanafs_hyperpay_verify_payment([
        'booking_token' => $payment->booking_token,
        'booking_type' => $payment->booking_type,
        'resource_path' => $resource_path,
        'checkout_id' => $payment->hyperpay_checkout_id,
    ]);

    if (!$verification['success']) {
        wp_send_json_error([
            'status' => 'pending',
            'message' => 'Could not verify payment yet. Please try again.',
        ]);
        return;
    }

    $new_status = $verification['payment_status'];
    tanafs_apply_payment_transition($payment, $new_status, $verification);

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
            'message' => 'Payment failed or was declined.',
        ]);
        return;
    }

    wp_send_json_error([
        'status' => 'pending',
        'payment_status' => 'pending',
        'message' => 'Payment is still pending. Please wait and retry.',
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
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'therapy_registration_nonce')) {
        wp_send_json_error(['message' => 'Security verification failed']);
        return;
    }
    
    $booking_token = sanitize_text_field($_POST['booking_token'] ?? '');
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
        ]
    );
    
    if ($result['success']) {
        wp_send_json_success([
            'gateway' => 'hyperpay',
            'checkout_id' => $result['checkout_id'],
            'widget_url' => $result['widget_url'],
            'result_url' => $result['return_url'],
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
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'retreat_booking_nonce')) {
        wp_send_json_error(['message' => 'Security verification failed']);
        return;
    }
    
    $booking_token = sanitize_text_field($_POST['booking_token'] ?? ($_POST['token'] ?? ''));
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
    ];
    
    $retreat_page_url = $booking_data['retreat_page_url'] ?? home_url('/retreats/');
    
    // Initiate HyperPay checkout
    $result = tanafs_hyperpay_create_checkout(
        $booking_token,
        'retreat',
        $booking_data['amount'],
        $customer_details,
        [
            'currency' => $booking_data['currency'],
            'return_url' => $retreat_page_url,
        ]
    );
    
    if ($result['success']) {
        wp_send_json_success([
            'gateway' => 'hyperpay',
            'checkout_id' => $result['checkout_id'],
            'widget_url' => $result['widget_url'],
            'result_url' => $result['return_url'],
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
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'therapy_registration_nonce')) {
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
    
    // Store using therapy helper function if available
    if (function_exists('therapy_booking_save')) {
        therapy_booking_save($booking_token, $booking_data, 4 * HOUR_IN_SECONDS);
    } else {
        set_transient($booking_token, $booking_data, 4 * HOUR_IN_SECONDS);
    }
    
    // Prepare customer details
    $customer_details = [
        'name' => $current_user->display_name ?: $current_user->user_login,
        'email' => $current_user->user_email,
        'phone' => get_user_meta($current_user->ID, 'phone', true) ?: '',
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
        ]
    );
    
    if ($result['success']) {
        wp_send_json_success([
            'gateway' => 'hyperpay',
            'checkout_id' => $result['checkout_id'],
            'widget_url' => $result['widget_url'],
            'result_url' => $result['return_url'],
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
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'academy_registration_nonce')) {
        wp_send_json_error(['message' => 'Security verification failed']);
        return;
    }
    
    $program_id = intval($_POST['program_id'] ?? 0);
    $full_name = sanitize_text_field($_POST['full_name'] ?? '');
    $email = sanitize_email($_POST['email'] ?? '');
    $phone = sanitize_text_field($_POST['phone'] ?? '');
    
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
    ];
    
    // Initiate HyperPay checkout
    $result = tanafs_hyperpay_create_checkout(
        $booking_token,
        'academy',
        $amount,
        $customer_details,
        [
            'currency' => 'SAR',
            'return_url' => home_url('/academy/'),
        ]
    );
    
    if ($result['success']) {
        wp_send_json_success([
            'gateway' => 'hyperpay',
            'checkout_id' => $result['checkout_id'],
            'widget_url' => $result['widget_url'],
            'result_url' => $result['return_url'],
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
