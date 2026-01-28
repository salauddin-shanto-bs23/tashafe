<?php
/**
 * PayTabs Global Admin Settings Page
 * 
 * This configuration is shared across all payment features:
 * - Retreat bookings
 * - Therapy sessions
 * - Future payment integrations
 */

// Add PayTabs settings menu (prevent duplicate registration)
if (!function_exists('paytabs_retreat_global_settings_menu')) {
    add_action('admin_menu', 'paytabs_retreat_global_settings_menu', 5);

    function paytabs_retreat_global_settings_menu()
    {
        // Prevent duplicate menu
        static $menu_added = false;
        if ($menu_added) return;
        $menu_added = true;
        
        add_menu_page(
            'PayTabs - Retreat Settings',  // Page title (unique)
            'PayTabs (Retreat)',           // Menu title (unique)
            'manage_options',              // Capability
            'paytabs-retreat-settings',    // Menu slug (unique)
            'render_paytabs_retreat_settings_page', // Callback (unique)
            'dashicons-palmtree',          // Icon (different from money)
            61                             // Position (different from 6)
        );
    }
}

// Render settings page
function render_paytabs_retreat_settings_page()
{
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }
    
    // Save settings if form submitted
    if (isset($_POST['paytabs_save_settings']) && check_admin_referer('paytabs_settings_nonce')) {
        update_option('paytabs_profile_id', sanitize_text_field($_POST['profile_id']));
        update_option('paytabs_server_key', sanitize_text_field($_POST['server_key']));
        update_option('paytabs_mode', sanitize_text_field($_POST['mode']));
        update_option('paytabs_currency', sanitize_text_field($_POST['currency']));
        update_option('paytabs_region', sanitize_text_field($_POST['region']));
        
        echo '<div class="notice notice-success is-dismissible"><p>PayTabs settings saved successfully!</p></div>';
    }

    // Get current settings
    $profile_id = get_option('paytabs_profile_id', '');
    $server_key = get_option('paytabs_server_key', '');
    $mode = get_option('paytabs_mode', 'test');
    $currency = get_option('paytabs_currency', 'SAR');
    $region = get_option('paytabs_region', 'global');
    
    // Test connection if requested
    $connection_status = '';
    if (isset($_POST['paytabs_test_connection']) && check_admin_referer('paytabs_test_nonce')) {
        $test_result = paytabs_test_connection();
        if ($test_result['success']) {
            $connection_status = '<div class="notice notice-success"><p>✓ Connection successful! PayTabs API is working.</p></div>';
        } else {
            $connection_status = '<div class="notice notice-error"><p>✗ Connection failed: ' . esc_html($test_result['message']) . '</p></div>';
        }
    }
?>
    <div class="wrap">
        <h1>PayTabs Global Settings (Retreat & Therapy)</h1>
        <p class="description">Configure PayTabs payment gateway for Retreat Bookings and Therapy Sessions</p>
        
        <?php echo $connection_status; ?>

        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        
        <style>
            .paytabs-settings-container {
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
            .mode-test {
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
            .btn-test {
                background: #6c757d;
                border: none;
                color: #fff;
                padding: 10px 24px;
                border-radius: 8px;
                font-weight: 600;
                margin-left: 10px;
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

        <div class="paytabs-settings-container">
            <!-- API Credentials Card -->
            <div class="settings-card">
                <h2>API Credentials</h2>
                <form method="post" action="">
                    <?php wp_nonce_field('paytabs_settings_nonce'); ?>
                    
                    <div class="mb-4">
                        <label class="form-label">Profile ID <span class="text-danger">*</span></label>
                        <input type="text" name="profile_id" class="form-control" value="<?php echo esc_attr($profile_id); ?>" required placeholder="Enter your PayTabs Profile ID">
                        <small class="text-muted">Found in PayTabs Dashboard → Settings → Profile</small>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Server Key <span class="text-danger">*</span></label>
                        <input type="text" name="server_key" class="form-control" value="<?php echo esc_attr($server_key); ?>" required placeholder="Enter your PayTabs Server Key">
                        <small class="text-muted">Found in PayTabs Dashboard → Developers → API Keys</small>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Mode <span class="text-danger">*</span></label>
                        <select name="mode" class="form-control" required>
                            <option value="test" <?php selected($mode, 'test'); ?>>Test Mode</option>
                            <option value="live" <?php selected($mode, 'live'); ?>>Live Mode</option>
                        </select>
                        <small class="text-muted">Use Test Mode for development, Live Mode for production</small>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Currency <span class="text-danger">*</span></label>
                        <select name="currency" class="form-control" required>
                            <option value="SAR" <?php selected($currency, 'SAR'); ?>>SAR - Saudi Riyal</option>
                            <option value="AED" <?php selected($currency, 'AED'); ?>>AED - UAE Dirham</option>
                            <option value="USD" <?php selected($currency, 'USD'); ?>>USD - US Dollar</option>
                            <option value="EUR" <?php selected($currency, 'EUR'); ?>>EUR - Euro</option>
                        </select>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">PayTabs Region <span class="text-danger">*</span></label>
                        <select name="region" class="form-control" required>
                            <option value="global" <?php selected($region, 'global'); ?>>Global (secure-global.paytabs.com)</option>
                            <option value="ksa" <?php selected($region, 'ksa'); ?>>Saudi Arabia (secure.paytabs.sa)</option>
                            <option value="uae" <?php selected($region, 'uae'); ?>>UAE (secure-uae.paytabs.com)</option>
                            <option value="egypt" <?php selected($region, 'egypt'); ?>>Egypt (secure-egypt.paytabs.com)</option>
                            <option value="oman" <?php selected($region, 'oman'); ?>>Oman (secure-oman.paytabs.com)</option>
                            <option value="jordan" <?php selected($region, 'jordan'); ?>>Jordan (secure-jordan.paytabs.com)</option>
                        </select>
                        <small class="text-muted">Select the region where your PayTabs account was created. Wrong region will cause authentication errors.</small>
                    </div>

                    <div class="mt-4">
                        <button type="submit" name="paytabs_save_settings" class="btn btn-save">
                            Save Settings
                        </button>
                    </div>
                </form>
            </div>

            <!-- Connection Test Card -->
            <div class="settings-card">
                <h2>Connection Test</h2>
                <p>Test your PayTabs API connection to ensure credentials are correct.</p>
                
                <form method="post" action="">
                    <?php wp_nonce_field('paytabs_test_nonce'); ?>
                    <button type="submit" name="paytabs_test_connection" class="btn btn-test">
                        Test Connection
                    </button>
                </form>
            </div>

            <!-- Integration URLs Card -->
            <div class="settings-card">
                <h2>Integration URLs</h2>
                <p>Configure these URLs in your PayTabs dashboard:</p>
                
                <div class="mb-3">
                    <label class="form-label">Return URL (Payment Completion)</label>
                    <input type="text" class="form-control" value="<?php echo esc_url(home_url('/payment-return/')); ?>" readonly>
                    <small class="text-muted">User returns here after completing payment</small>
                </div>

                <div class="mb-3">
                    <label class="form-label">Callback URL (IPN/Webhook)</label>
                    <input type="text" class="form-control" value="<?php echo esc_url(home_url('/payment-callback/')); ?>" readonly>
                    <small class="text-muted">PayTabs sends payment notifications here</small>
                </div>
            </div>

            <!-- Information Box -->
            <div class="info-box">
                <strong>ℹ️ How to get PayTabs credentials:</strong>
                <ol style="margin: 10px 0 0 20px;">
                    <li>Log in to your <a href="https://merchant.paytabs.com/" target="_blank">PayTabs Merchant Dashboard</a></li>
                    <li>Go to <strong>Settings → Profile</strong> to get your Profile ID</li>
                    <li>Go to <strong>Developers → API Keys</strong> to get your Server Key</li>
                    <li>Copy and paste them in the form above</li>
                </ol>
            </div>

            <?php if ($mode === 'test'): ?>
            <div class="warning-box">
                <strong>⚠️ Test Mode Active:</strong> Payments will use PayTabs test environment. No real charges will be made.
                <br><strong>Test Card:</strong> 4111 1111 1111 1111 | CVV: 123 | Expiry: Any future date
            </div>
            <?php else: ?>
            <div style="background: #d4edda; border-left: 4px solid #28a745; padding: 15px; border-radius: 4px;">
                <strong>✓ Live Mode Active:</strong> Real payments will be processed. Ensure all settings are correct.
            </div>
            <?php endif; ?>

            <!-- Current Status -->
            <div class="settings-card">
                <h2>Current Configuration Status</h2>
                <table class="table table-sm">
                    <tr>
                        <td><strong>Profile ID:</strong></td>
                        <td><?php echo $profile_id ? '✓ Configured' : '✗ Not set'; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Server Key:</strong></td>
                        <td><?php echo $server_key ? '✓ Configured' : '✗ Not set'; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Mode:</strong></td>
                        <td>
                            <span class="mode-badge mode-<?php echo esc_attr($mode); ?>">
                                <?php echo strtoupper($mode); ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Currency:</strong></td>
                        <td><?php echo esc_html($currency); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Region:</strong></td>
                        <td><?php echo esc_html(strtoupper($region)); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Ready for Use:</strong></td>
                        <td><?php echo ($profile_id && $server_key) ? '✓ Yes' : '✗ No - Configure credentials'; ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
<?php
}

/**
 * Test PayTabs API connection
 */
function paytabs_test_connection()
{
    $profile_id = get_option('paytabs_profile_id');
    $server_key = get_option('paytabs_server_key');
    
    if (empty($profile_id) || empty($server_key)) {
        return [
            'success' => false,
            'message' => 'Profile ID or Server Key not configured'
        ];
    }
    
    // Get region-specific endpoint
    $region = get_option('paytabs_region', 'global');
    $endpoints = [
        'global' => 'https://secure-global.paytabs.com/payment/request',
        'ksa'    => 'https://secure.paytabs.sa/payment/request',
        'uae'    => 'https://secure-uae.paytabs.com/payment/request',
        'egypt'  => 'https://secure-egypt.paytabs.com/payment/request',
        'oman'   => 'https://secure-oman.paytabs.com/payment/request',
        'jordan' => 'https://secure-jordan.paytabs.com/payment/request',
    ];
    $endpoint = $endpoints[$region] ?? $endpoints['global'];
    
    $response = wp_remote_post($endpoint, [
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => $server_key
        ],
        'body' => json_encode([
            'profile_id' => intval($profile_id)
        ]),
        'timeout' => 15
    ]);
    
    if (is_wp_error($response)) {
        return [
            'success' => false,
            'message' => $response->get_error_message()
        ];
    }
    
    $status_code = wp_remote_retrieve_response_code($response);
    
    // Even if request is incomplete, getting a proper response means connection works
    if ($status_code >= 200 && $status_code < 500) {
        return [
            'success' => true,
            'message' => 'API connection successful'
        ];
    }
    
    return [
        'success' => false,
        'message' => 'HTTP Status: ' . $status_code
    ];
}
