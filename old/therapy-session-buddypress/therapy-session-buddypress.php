<?php
/**
 * Plugin Name: Therapy Session BuddyPress
 * Plugin URI: https://yoursite.com
 * Description: BuddyPress Groups integration for therapy sessions. Auto-creates BP groups and manages membership. Better Messages provides chat UI automatically.
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://yoursite.com
 * License: GPL v2 or later
 * Text Domain: therapy-session-bp
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('TSBP_VERSION', '1.0.0');
define('TSBP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TSBP_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Main Plugin Class
 */
final class Therapy_Session_BuddyPress {

    /**
     * Single instance
     */
    private static $instance = null;

    /**
     * Get instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Check dependencies on admin
        add_action('admin_notices', [$this, 'check_dependencies']);
        
        // Initialize plugin after BuddyPress is loaded
        add_action('bp_init', [$this, 'init'], 20);
        
        // Fallback init if bp_init doesn't fire (BP not active)
        add_action('init', [$this, 'init_fallback'], 99);
        
        // Activation/Deactivation hooks
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
    }

    /**
     * Check if required plugins are active
     */
    public function check_dependencies() {
        $missing = [];
        
        // Check BuddyPress
        if (!function_exists('buddypress') && !class_exists('BuddyPress')) {
            $missing[] = 'BuddyPress';
        }
        
        // Check if BP Groups component is active
        if (function_exists('bp_is_active') && !bp_is_active('groups')) {
            $missing[] = 'BuddyPress Groups Component';
        }
        
        if (!empty($missing)) {
            ?>
            <div class="notice notice-error">
                <p><strong>Therapy Session BuddyPress:</strong> The following are required: <?php echo esc_html(implode(', ', $missing)); ?>. Please install and activate them.</p>
            </div>
            <?php
        }
    }

    /**
     * Check if BuddyPress is ready
     */
    private function is_buddypress_ready() {
        return function_exists('buddypress') && 
               function_exists('bp_is_active') && 
               bp_is_active('groups') &&
               function_exists('groups_create_group');
    }

    /**
     * Initialize plugin (called from bp_init)
     */
    public function init() {
        if (!$this->is_buddypress_ready()) {
            return;
        }
        
        $this->load_files();
        
        // Mark as initialized
        define('TSBP_INITIALIZED', true);
    }

    /**
     * Fallback initialization
     */
    public function init_fallback() {
        if (defined('TSBP_INITIALIZED')) {
            return;
        }
        
        // Load files anyway for admin pages even if BP not ready
        if (is_admin()) {
            $this->load_admin_files();
        }
    }

    /**
     * Load plugin files
     */
    private function load_files() {
        // Core BP group functions
        require_once TSBP_PLUGIN_DIR . 'includes/bp-group-functions.php';
        
        // Better Messages thread functions (direct BM integration)
        require_once TSBP_PLUGIN_DIR . 'includes/bm-thread-functions.php';
        
        // Better Messages restrictions (no new chats for users)
        require_once TSBP_PLUGIN_DIR . 'includes/bm-restrictions.php';
        
        // BP group membership functions
        require_once TSBP_PLUGIN_DIR . 'includes/bp-group-membership.php';
        
        // Hooks for automation
        require_once TSBP_PLUGIN_DIR . 'includes/bp-group-hooks.php';
        
        // Expiry handler
        require_once TSBP_PLUGIN_DIR . 'includes/bp-group-expiry.php';
        
        // Frontend shortcodes
        require_once TSBP_PLUGIN_DIR . 'includes/chat-shortcodes.php';
        
        // Admin functions
        if (is_admin()) {
            $this->load_admin_files();
        }
    }

    /**
     * Load admin files
     */
    private function load_admin_files() {
        require_once TSBP_PLUGIN_DIR . 'includes/admin-functions.php';
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Schedule cron job for expiry check
        if (!wp_next_scheduled('tsbp_group_expiry_check')) {
            wp_schedule_event(time(), 'daily', 'tsbp_group_expiry_check');
        }
        
        // Set default options
        add_option('tsbp_expiry_action', 'archive'); // archive, delete, or hide
        add_option('tsbp_default_expiry_days', 30);
        add_option('tsbp_group_status', 'private'); // private or hidden
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Remove cron job
        $timestamp = wp_next_scheduled('tsbp_group_expiry_check');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'tsbp_group_expiry_check');
        }
    }
}

// Initialize plugin
Therapy_Session_BuddyPress::get_instance();
