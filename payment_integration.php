<?php
/**
 * Unified Payment Integration System for Tanafs
 * Amazon Payment Services (APS) integration serving therapy, retreat, and academy bookings
 * 
 * Features:
 * - Single payment configuration page for APS credentials
 * - Unified payment processing for all booking types (therapy, retreat, academy)
 * - booking_type field tracking for payment routing
 * - IPN/webhook handler with signature verification
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
 * @gateway Amazon Payment Services (APS / Checkout.com)
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
        transaction_id VARCHAR(100) NOT NULL COMMENT 'APS fort_id or merchant_reference',
        booking_token VARCHAR(100) NOT NULL COMMENT 'Unique booking identifier (cart_id)',
        booking_type VARCHAR(50) NOT NULL COMMENT 'therapy, retreat, academy, etc.',
        customer_name VARCHAR(255) NOT NULL,
        customer_email VARCHAR(255) NOT NULL,
        customer_phone VARCHAR(50) DEFAULT NULL,
        amount DECIMAL(10,2) NOT NULL,
        currency VARCHAR(10) NOT NULL DEFAULT 'SAR',
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
        KEY booking_type (booking_type),
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
 * Render APS configuration page
 */
function tanafs_render_payment_config_page() {
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    // Save settings if form submitted
    if (isset($_POST['tanafs_save_aps_settings']) && check_admin_referer('tanafs_aps_settings_nonce')) {
        update_option('tanafs_aps_merchant_identifier', sanitize_text_field($_POST['merchant_identifier']));
        update_option('tanafs_aps_access_code', sanitize_text_field($_POST['access_code']));
        update_option('tanafs_aps_sha_request_phrase', sanitize_text_field($_POST['sha_request_phrase']));
        update_option('tanafs_aps_sha_response_phrase', sanitize_text_field($_POST['sha_response_phrase']));
        update_option('tanafs_aps_mode', sanitize_text_field($_POST['mode']));
        update_option('tanafs_aps_currency', sanitize_text_field($_POST['currency']));
        update_option('tanafs_aps_language', sanitize_text_field($_POST['language']));
        
        echo '<div class="notice notice-success is-dismissible"><p>APS settings saved successfully!</p></div>';
    }

    // Get current settings
    $merchant_identifier = get_option('tanafs_aps_merchant_identifier', '');
    $access_code = get_option('tanafs_aps_access_code', '');
    $sha_request_phrase = get_option('tanafs_aps_sha_request_phrase', '');
    $sha_response_phrase = get_option('tanafs_aps_sha_response_phrase', '');
    $mode = get_option('tanafs_aps_mode', 'sandbox');
    $currency = get_option('tanafs_aps_currency', 'SAR');
    $language = get_option('tanafs_aps_language', 'en');
    
    ?>
    <div class="wrap">
        <h1>Amazon Payment Services (APS) Configuration</h1>
        <p class="description">Configure APS payment gateway for Therapy, Retreat, and Academy bookings</p>

        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        
        <style>
            .aps-settings-container {
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

        <div class="aps-settings-container">
            <div class="info-box">
                <strong>ℹ️ About Amazon Payment Services (APS)</strong><br>
                APS (formerly known as Payfort) is a secure payment gateway supporting multiple payment methods across the MENA region.
                This configuration applies to all Tanafs booking modules: Therapy, Retreat, and Academy.
            </div>

            <!-- API Credentials Card -->
            <div class="settings-card">
                <h2>API Credentials</h2>
                <form method="post" action="">
                    <?php wp_nonce_field('tanafs_aps_settings_nonce'); ?>
                    
                    <div class="mb-4">
                        <label class="form-label">Merchant Identifier <span class="text-danger">*</span></label>
                        <input type="text" name="merchant_identifier" class="form-control" 
                               value="<?php echo esc_attr($merchant_identifier); ?>" required 
                               placeholder="e.g., YOUR_MERCHANT_ID">
                        <small class="text-muted">Found in APS Dashboard → Integration Settings</small>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Access Code <span class="text-danger">*</span></label>
                        <input type="text" name="access_code" class="form-control" 
                               value="<?php echo esc_attr($access_code); ?>" required 
                               placeholder="e.g., YOUR_ACCESS_CODE">
                        <small class="text-muted">Found in APS Dashboard → Integration Settings</small>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">SHA Request Phrase <span class="text-danger">*</span></label>
                        <input type="text" name="sha_request_phrase" class="form-control" 
                               value="<?php echo esc_attr($sha_request_phrase); ?>" required 
                               placeholder="Enter SHA Request Passphrase">
                        <small class="text-muted">Used to generate request signature (HMAC SHA-256)</small>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">SHA Response Phrase <span class="text-danger">*</span></label>
                        <input type="text" name="sha_response_phrase" class="form-control" 
                               value="<?php echo esc_attr($sha_response_phrase); ?>" required 
                               placeholder="Enter SHA Response Passphrase">
                        <small class="text-muted">Used to verify response signature from APS</small>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Mode <span class="text-danger">*</span></label>
                        <select name="mode" class="form-control" required>
                            <option value="sandbox" <?php selected($mode, 'sandbox'); ?>>Sandbox (Testing)</option>
                            <option value="live" <?php selected($mode, 'live'); ?>>Live (Production)</option>
                        </select>
                        <small class="text-muted">Use Sandbox for testing, Live for production</small>
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

                    <div class="mb-4">
                        <label class="form-label">Payment Page Language <span class="text-danger">*</span></label>
                        <select name="language" class="form-control" required>
                            <option value="en" <?php selected($language, 'en'); ?>>English</option>
                            <option value="ar" <?php selected($language, 'ar'); ?>>Arabic</option>
                        </select>
                    </div>

                    <div class="warning-box">
                        <strong>⚠️ Security Note:</strong> Never share your SHA passphrases or access code publicly. 
                        Ensure they match exactly with your APS dashboard configuration.
                    </div>

                    <button type="submit" name="tanafs_save_aps_settings" class="btn btn-save">
                        💾 Save Configuration
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
                    <small class="text-muted">Users are redirected here after payment (configure in APS dashboard)</small>
                </div>
                <div class="mb-3">
                    <label class="form-label">IPN/Webhook URL (Server Notification)</label>
                    <input type="text" class="form-control" readonly 
                           value="<?php echo esc_url(home_url('/payment-callback/')); ?>">
                    <small class="text-muted">APS sends server-to-server notification here (configure in APS dashboard)</small>
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
    $stats_pending = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE payment_status = 'pending'");
    $stats_complete = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE payment_status = 'complete'");
    $stats_failed = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE payment_status = 'failed'");
    $stats_total_amount = $wpdb->get_var("SELECT SUM(amount) FROM {$table_name} WHERE payment_status = 'complete'");
    
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
                <div class="value"><?php echo number_format($stats_pending); ?></div>
            </div>
            <div class="stat-card complete">
                <h3>Completed</h3>
                <div class="value"><?php echo number_format($stats_complete); ?></div>
            </div>
            <div class="stat-card failed">
                <h3>Failed</h3>
                <div class="value"><?php echo number_format($stats_failed); ?></div>
            </div>
            <div class="stat-card revenue">
                <h3>Total Revenue (SAR)</h3>
                <div class="value"><?php echo number_format($stats_total_amount, 2); ?></div>
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
                                <td><?php echo esc_html($payment->currency); ?> <?php echo number_format($payment->amount, 2); ?></td>
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
// SECTION 2: CORE APS API FUNCTIONS
// ============================================================================

/**
 * Check if APS is properly configured
 * 
 * @return bool True if configured, false otherwise
 */
function tanafs_aps_is_configured() {
    $merchant_id = get_option('tanafs_aps_merchant_identifier');
    $access_code = get_option('tanafs_aps_access_code');
    $sha_request = get_option('tanafs_aps_sha_request_phrase');
    $sha_response = get_option('tanafs_aps_sha_response_phrase');
    
    return !empty($merchant_id) && !empty($access_code) && !empty($sha_request) && !empty($sha_response);
}

/**
 * Get APS API endpoint based on mode (sandbox/live)
 * 
 * @return string API base URL
 */
function tanafs_aps_get_endpoint() {
    $mode = get_option('tanafs_aps_mode', 'sandbox');
    
    if ($mode === 'live') {
        return 'https://checkout.payfort.com/FortAPI/paymentPage';
    }
    
    return 'https://sbcheckout.payfort.com/FortAPI/paymentPage';
}

/**
 * Calculate APS request signature (HMAC SHA-256)
 * 
 * @param array $params Request parameters
 * @return string Signature hash
 */
function tanafs_aps_calculate_signature($params, $is_response = false) {
    $sha_phrase = $is_response 
        ? get_option('tanafs_aps_sha_response_phrase') 
        : get_option('tanafs_aps_sha_request_phrase');
    
    // Sort parameters alphabetically
    ksort($params);
    
    // Build signature string
    $signature_string = $sha_phrase;
    foreach ($params as $key => $value) {
        if ($key !== 'signature') {
            $signature_string .= $key . '=' . $value;
        }
    }
    $signature_string .= $sha_phrase;
    
    return hash('sha256', $signature_string);
}

/**
 * Initiate APS payment session
 * 
 * Creates a payment record in pending status and redirects to APS checkout
 * 
 * @param string $booking_token Unique booking identifier (cart_id)
 * @param string $booking_type Module type (therapy, retreat, academy)
 * @param float $amount Payment amount
 * @param array $customer_details Customer information (name, email, phone)
 * @param array $options Additional options (currency, description, return_url)
 * @return array Result with success status and redirect_url or error
 */
function tanafs_aps_initiate_payment($booking_token, $booking_type, $amount, $customer_details, $options = []) {
    global $wpdb;
    
    // Check configuration
    if (!tanafs_aps_is_configured()) {
        return [
            'success' => false,
            'error' => 'APS payment gateway is not configured. Please contact support.'
        ];
    }
    
    $merchant_identifier = get_option('tanafs_aps_merchant_identifier');
    $access_code = get_option('tanafs_aps_access_code');
    $currency = $options['currency'] ?? get_option('tanafs_aps_currency', 'SAR');
    $language = $options['language'] ?? get_option('tanafs_aps_language', 'en');
    
    // Generate unique merchant reference (transaction ID)
    $merchant_reference = 'TANAFS_' . strtoupper($booking_type) . '_' . time() . '_' . rand(1000, 9999);
    
    // Convert amount to smallest currency unit (fils/halalas)
    // APS requires amount in minor units (e.g., 100 SAR = 10000)
    $amount_minor = intval($amount * 100);
    
    // Build return URL
    $return_url = $options['return_url'] ?? home_url('/payment-return/');
    
    // Only add non-empty query parameters to avoid rawurlencode() null deprecation in PHP 8.1+
    $query_args = [];
    if (!empty($booking_token)) {
        $query_args['booking_token'] = sanitize_text_field($booking_token);
    }
    if (!empty($booking_type)) {
        $query_args['booking_type'] = sanitize_text_field($booking_type);
    }
    
    if (!empty($query_args)) {
        $return_url = add_query_arg($query_args, $return_url);
    }
    
    // Prepare APS request parameters
    $params = [
        'command' => 'PURCHASE',
        'access_code' => $access_code,
        'merchant_identifier' => $merchant_identifier,
        'merchant_reference' => $merchant_reference,
        'amount' => $amount_minor,
        'currency' => $currency,
        'language' => $language,
        'customer_email' => $customer_details['email'],
        'customer_name' => $customer_details['name'],
        'return_url' => $return_url,
    ];
    
    // Calculate signature
    $params['signature'] = tanafs_aps_calculate_signature($params);
    
    // Store payment record in database (status: pending)
    $payment_data = [
        'transaction_id' => $merchant_reference,
        'booking_token' => $booking_token,
        'booking_type' => $booking_type,
        'customer_name' => $customer_details['name'],
        'customer_email' => $customer_details['email'],
        'customer_phone' => $customer_details['phone'] ?? '',
        'amount' => $amount,
        'currency' => $currency,
        'payment_status' => 'pending',
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    ];
    
    $table_name = $wpdb->prefix . 'tanafs_payments';
    $inserted = $wpdb->insert($table_name, $payment_data);
    
    if (!$inserted) {
        tanafs_log_payment('initiate_db_error', [
            'booking_token' => $booking_token,
            'error' => $wpdb->last_error,
        ]);
        
        return [
            'success' => false,
            'error' => 'Failed to create payment record. Please try again.',
        ];
    }
    
    tanafs_log_payment('initiate_success', [
        'booking_token' => $booking_token,
        'merchant_reference' => $merchant_reference,
        'amount' => $amount,
        'currency' => $currency,
    ]);
    
    // Return APS checkout form URL and parameters
    // The frontend will auto-submit a form to redirect to APS
    return [
        'success' => true,
        'redirect_url' => tanafs_aps_get_endpoint(),
        'params' => $params,
        'merchant_reference' => $merchant_reference,
    ];
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
 * Handle APS IPN/webhook callback
 * 
 * CRITICAL: This is the single source of truth for payment confirmation
 * - Verifies signature to ensure authenticity
 * - Updates payment status in database
 * - Routes to module-specific booking fulfillment based on booking_type
 * - Idempotent: safe to call multiple times with same data
 */
function tanafs_handle_payment_callback() {
    if (get_query_var('payment_callback')) {
        global $wpdb;
        
        // Get callback data (APS sends POST data)
        $callback_data = $_POST;
        
        error_log('=== TANAFS APS IPN CALLBACK RECEIVED ===');
        tanafs_log_payment('callback_raw', $callback_data);
        
        // Validate signature
        if (!isset($callback_data['signature'])) {
            error_log('APS IPN ERROR: No signature in callback');
            tanafs_log_payment('callback_error', ['error' => 'No signature']);
            http_response_code(400);
            exit;
        }
        
        $received_signature = $callback_data['signature'];
        $calculated_signature = tanafs_aps_calculate_signature($callback_data, true);
        
        if ($received_signature !== $calculated_signature) {
            error_log('APS IPN ERROR: Signature verification failed');
            tanafs_log_payment('callback_error', [
                'error' => 'Signature mismatch',
                'received' => $received_signature,
                'calculated' => $calculated_signature,
            ]);
            http_response_code(403);
            exit;
        }
        
        // Extract key fields
        $merchant_reference = sanitize_text_field($callback_data['merchant_reference'] ?? '');
        $response_code = sanitize_text_field($callback_data['response_code'] ?? '');
        $response_message = sanitize_text_field($callback_data['response_message'] ?? '');
        $fort_id = sanitize_text_field($callback_data['fort_id'] ?? ''); // APS transaction ID
        $status = sanitize_text_field($callback_data['status'] ?? '');
        $payment_method = sanitize_text_field($callback_data['payment_option'] ?? '');
        
        if (empty($merchant_reference)) {
            error_log('APS IPN ERROR: No merchant_reference in callback');
            http_response_code(400);
            exit;
        }
        
        // Get payment record from database
        $table_name = $wpdb->prefix . 'tanafs_payments';
        $payment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE transaction_id = %s",
            $merchant_reference
        ));
        
        if (!$payment) {
            error_log('APS IPN ERROR: Payment record not found: ' . $merchant_reference);
            tanafs_log_payment('callback_error', [
                'error' => 'Payment not found',
                'merchant_reference' => $merchant_reference,
            ]);
            http_response_code(404);
            exit;
        }
        
        // Check for duplicate processing (idempotency)
        if ($payment->payment_status === 'complete') {
            error_log('APS IPN: Payment already processed (idempotent): ' . $merchant_reference);
            echo 'OK'; // Acknowledge to prevent retries
            exit;
        }
        
        // Determine payment status from APS response
        // Response codes: 14000 = Success, others = failure
        $new_status = 'failed';
        if ($status === '14' || $response_code === '14000') {
            $new_status = 'complete';
        }
        
        // Update payment record
        $update_data = [
            'payment_status' => $new_status,
            'aps_response_code' => $response_code,
            'aps_response_message' => $response_message,
            'payment_method' => $payment_method,
            'response_data' => json_encode($callback_data),
        ];
        
        $wpdb->update(
            $table_name,
            $update_data,
            ['transaction_id' => $merchant_reference],
            ['%s', '%s', '%s', '%s', '%s'],
            ['%s']
        );
        
        tanafs_log_payment('callback_processed', [
            'merchant_reference' => $merchant_reference,
            'status' => $new_status,
            'response_code' => $response_code,
            'booking_type' => $payment->booking_type,
        ]);
        
        // Process booking fulfillment if payment successful
        if ($new_status === 'complete') {
            error_log('APS IPN: Payment APPROVED - fulfilling booking: ' . $payment->booking_token);
            
            // Route to module-specific fulfillment based on booking_type
            $fulfillment_result = tanafs_fulfill_booking_from_ipn($payment->booking_token, $payment->booking_type, $callback_data);
            
            if (!$fulfillment_result['success']) {
                error_log('APS IPN ERROR: Booking fulfillment failed: ' . $fulfillment_result['message']);
                
                // Send admin notification
                wp_mail(
                    get_option('admin_email'),
                    '[URGENT] Tanafs Payment IPN - Booking Fulfillment Failed',
                    "A payment was successful but booking fulfillment failed.\n\n" .
                    "Booking Type: {$payment->booking_type}\n" .
                    "Booking Token: {$payment->booking_token}\n" .
                    "Transaction ID: {$merchant_reference}\n" .
                    "Customer: {$payment->customer_name} ({$payment->customer_email})\n" .
                    "Amount: {$payment->currency} {$payment->amount}\n\n" .
                    "Error: {$fulfillment_result['message']}\n\n" .
                    "Please manually process this booking in WordPress admin.",
                    ['Content-Type: text/plain; charset=UTF-8']
                );
            }
        } else {
            error_log('APS IPN: Payment FAILED - ' . $response_message);
        }
        
        // Acknowledge receipt to APS
        echo 'OK';
        exit;
    }
}
add_action('template_redirect', 'tanafs_handle_payment_callback', 5);

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
    
    // Initiate payment
    $result = tanafs_aps_initiate_payment(
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
            'redirect_url' => $result['redirect_url'],
            'params' => $result['params'],
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
    
    $booking_token = sanitize_text_field($_POST['booking_token'] ?? '');
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
    
    // Initiate payment
    $result = tanafs_aps_initiate_payment(
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
            'redirect_url' => $result['redirect_url'],
            'params' => $result['params'],
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
    
    // Initiate payment
    $result = tanafs_aps_initiate_payment(
        $booking_token,
        'therapy',
        $therapy_price,
        $customer_details,
        [
            'currency' => get_option('tanafs_aps_currency', 'SAR'),
            'return_url' => $return_url,
        ]
    );
    
    if ($result['success']) {
        wp_send_json_success([
            'redirect_url' => $result['redirect_url'],
            'params' => $result['params'],
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
    
    // Initiate payment
    $result = tanafs_aps_initiate_payment(
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
            'redirect_url' => $result['redirect_url'],
            'params' => $result['params'],
        ]);
    } else {
        wp_send_json_error(['message' => $result['error']]);
    }
}

// ============================================================================
// HELPER: AUTO-SUBMIT FORM FOR APS REDIRECT
// ============================================================================

/**
 * Generate HTML form that auto-submits to APS checkout
 * Call this from your frontend JavaScript after receiving AJAX response
 * 
 * Example usage in JS:
 * 
 * $.post(ajaxurl, data, function(response) {
 *     if (response.success) {
 *         tanafs_redirect_to_aps(response.data.redirect_url, response.data.params);
 *     }
 * });
 * 
 * function tanafs_redirect_to_aps(url, params) {
 *     var form = $('<form method="POST" action="' + url + '"></form>');
 *     $.each(params, function(key, value) {
 *         form.append('<input type="hidden" name="' + key + '" value="' + value + '">');
 *     });
 *     $('body').append(form);
 *     form.submit();
 * }
 */

// ============================================================================
// SECTION 6: BOOKING FULFILLMENT PLACEHOLDER (ACADEMY)
// ============================================================================

/**
 * Process academy booking after successful payment
 * 
 * @param string $booking_token Unique booking identifier
 * @param array $payment_data APS callback data
 * @return array ['success' => bool, 'message' => string, 'user_id' => int|null]
 */
function process_academy_booking_from_ipn($booking_token, $payment_data) {
    global $wpdb;
    
    // Retrieve booking data from transient
    $transient_key = 'academy_' . str_replace('academy_', '', $booking_token);
    $booking_data = get_transient($transient_key);
    
    if (!$booking_data) {
        return [
            'success' => false,
            'message' => 'Academy booking data not found',
        ];
    }
    
    // Check if user already registered to prevent duplicate enrollment
    $existing_registration = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}academy_registrations WHERE program_id = %d AND email = %s",
        $booking_data['program_id'],
        $booking_data['email']
    ));
    
    if ($existing_registration) {
        return [
            'success' => true,
            'message' => 'User already registered',
            'user_id' => $existing_registration->user_id,
        ];
    }
    
    // Create or get WordPress user
    $user = get_user_by('email', $booking_data['email']);
    if (!$user) {
        $user_id = wp_create_user(
            sanitize_user($booking_data['email']),
            wp_generate_password(12, true),
            $booking_data['email']
        );
        
        if (is_wp_error($user_id)) {
            return [
                'success' => false,
                'message' => 'Failed to create user: ' . $user_id->get_error_message(),
            ];
        }
    } else {
        $user_id = $user->ID;
    }
    
    // Insert academy registration
    $wpdb->insert(
        $wpdb->prefix . 'academy_registrations',
        [
            'program_id' => $booking_data['program_id'],
            'user_id' => $user_id,
            'full_name' => $booking_data['full_name'],
            'email' => $booking_data['email'],
            'phone' => $booking_data['phone'],
            'job_title' => $booking_data['job_title'],
            'license_number' => $booking_data['license_number'],
            'country' => $booking_data['country'],
            'registration_status' => 'registered',
        ]
    );
    
    // Send confirmation email
    // (Add email sending logic here if needed)
    
    // Clean up transient
    delete_transient($transient_key);
    
    return [
        'success' => true,
        'message' => 'Academy registration completed',
        'user_id' => $user_id,
    ];
}
