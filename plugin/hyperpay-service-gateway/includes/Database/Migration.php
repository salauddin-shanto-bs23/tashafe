<?php
/**
 * Database migration.
 *
 * @package HyperPayServiceGateway\Database
 */

namespace HyperPayServiceGateway\Database;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles table creation and schema updates.
 */
class Migration {

    /**
     * Register migration on init.
     *
     * @return void
     */
    public function register() {
        add_action('init', array(__CLASS__, 'run'), 20);
    }

    /**
     * Create or upgrade transactions table.
     *
     * @return void
     */
    public static function run() {
        global $wpdb;

        $table_name = hyperpay_service_gateway_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            transaction_id VARCHAR(120) DEFAULT NULL,
            checkout_id VARCHAR(120) DEFAULT NULL,
            merchant_transaction_id VARCHAR(120) NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            currency VARCHAR(10) NOT NULL DEFAULT 'SAR',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            customer_name VARCHAR(190) NOT NULL,
            customer_email VARCHAR(190) NOT NULL,
            customer_phone VARCHAR(60) DEFAULT NULL,
            billing_country VARCHAR(10) NOT NULL,
            billing_city VARCHAR(120) DEFAULT NULL,
            billing_street VARCHAR(255) NOT NULL,
            service_type VARCHAR(80) NOT NULL,
            service_reference_id VARCHAR(120) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY merchant_transaction_id (merchant_transaction_id),
            KEY checkout_id (checkout_id),
            KEY customer_email (customer_email),
            KEY status (status),
            KEY service_type (service_type),
            KEY created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
}
