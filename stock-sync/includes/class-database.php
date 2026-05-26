<?php
/**
 * Database Schema Management
 */
class StockSync_Database {

    /**
     * Create the stock sync log database table.
     *
     * @return void
     */
    public static function create_tables() {
        global $wpdb;
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $charset_collate = $wpdb->get_charset_collate();
        $table_name      = $wpdb->prefix . 'stock_sync_log';

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            product_id bigint(20) unsigned NOT NULL,
            sku varchar(100) DEFAULT NULL,
            action varchar(50) NOT NULL,
            old_visibility varchar(20) DEFAULT NULL,
            new_visibility varchar(20) DEFAULT NULL,
            old_excerpt text DEFAULT NULL,
            new_excerpt text DEFAULT NULL,
            old_price decimal(19,4) DEFAULT NULL,
            old_sale_price decimal(19,4) DEFAULT NULL,
            distributor_slug varchar(50) DEFAULT NULL,
            distributor_ref varchar(100) DEFAULT NULL,
            sync_run_id varchar(32) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY sync_run_id (sync_run_id),
            KEY created_at (created_at),
            KEY distributor_slug (distributor_slug)
        ) {$charset_collate};";

        dbDelta($sql);
    }
}
