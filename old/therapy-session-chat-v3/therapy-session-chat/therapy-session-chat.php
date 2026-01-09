<?php
/**
 * Plugin Name: Therapy Session Chat
 * Plugin URI: https://yoursite.com
 * Description: Better Messages Chat Rooms integration for therapy session group chats. Auto-creates chat rooms and enrolls users automatically.
 * Version: 2.0.0
 * Author: Your Name
 * Author URI: https://yoursite.com
 * License: GPL v2 or later
 * Text Domain: therapy-session-chat
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('TSC_VERSION', '2.0.0');
define('TSC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TSC_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Main Plugin Class
 */
final class Therapy_Session_Chat {

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
        
        // Initialize plugin - wait for Better Messages to register post type
        add_action('init', [$this, 'init'], 20);
        
        // Activation/Deactivation hooks
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
    }

    /**
     * Check if required plugins are active
     */
    public function check_dependencies() {
        // Better Messages registers bpbm-chat post type
        if (!post_type_exists('bpbm-chat') && !class_exists('Better_Messages')) {
            ?>
            <div class="notice notice-error">
                <p><strong>Therapy Session Chat:</strong> Better Messages plugin is required for chat functionality. Please install and activate Better Messages.</p>
            </div>
            <?php
        }
    }

    /**
     * Initialize plugin
     */
    public function init() {
        $this->load_files();
    }

    /**
     * Load plugin files
     */
    private function load_files() {
        // Core functions
        require_once TSC_PLUGIN_DIR . 'includes/chat-group-functions.php';
        
        // Hooks for automation
        require_once TSC_PLUGIN_DIR . 'includes/chat-group-hooks.php';
        
        // Expiry handler
        require_once TSC_PLUGIN_DIR . 'includes/chat-group-expiry.php';
        
        // Admin functions
        if (is_admin()) {
            require_once TSC_PLUGIN_DIR . 'includes/chat-admin-functions.php';
        }
        
        // Frontend shortcodes
        require_once TSC_PLUGIN_DIR . 'includes/chat-shortcodes.php';
        
        // Settings page
        require_once TSC_PLUGIN_DIR . 'includes/chat-settings.php';
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Schedule cron job
        if (!wp_next_scheduled('tsc_chat_expiry_check')) {
            wp_schedule_event(time(), 'daily', 'tsc_chat_expiry_check');
        }
        
        // Set default options
        add_option('tsc_expiry_action', 'archive');
        add_option('tsc_default_expiry_days', 30);
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Remove cron job
        $timestamp = wp_next_scheduled('tsc_chat_expiry_check');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'tsc_chat_expiry_check');
        }
    }
}

// Initialize plugin
Therapy_Session_Chat::get_instance();
