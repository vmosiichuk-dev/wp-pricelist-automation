<?php
/**
 * Change Logger
 * Stores all sync operations in a custom database table.
 */
class StockSync_Change_Logger {

    private $table_name;
    private $current_sync_run_id = null;

    /**
     * Set the log table name.
     *
     * @return void
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'stock_sync_log';
    }

    /**
     * Log a single change
     */
    public function log(array $data) {
        global $wpdb;

        if ($this->current_sync_run_id === null) {
            $this->current_sync_run_id = $this->generate_sync_run_id();
        }

        $defaults = [
            'product_id'       => 0,
            'sku'              => null,
            'action'           => '',
            'old_visibility'   => null,
            'new_visibility'   => null,
            'old_excerpt'      => null,
            'new_excerpt'      => null,
            'old_price'        => null,
            'old_sale_price'   => null,
            'distributor_slug' => null,
            'distributor_ref'  => null,
            'sync_run_id'      => $this->current_sync_run_id,
            'created_at'       => current_time('mysql'),
        ];

        $record = wp_parse_args($data, $defaults);

        $wpdb->insert($this->table_name, [
            'product_id'       => absint($record['product_id']),
            'sku'              => sanitize_text_field($record['sku']),
            'action'           => sanitize_text_field($record['action']),
            'old_visibility'   => sanitize_text_field($record['old_visibility']),
            'new_visibility'   => sanitize_text_field($record['new_visibility']),
            'old_excerpt'      => $record['old_excerpt'],
            'new_excerpt'      => $record['new_excerpt'],
            'old_price'        => is_numeric($record['old_price']) ? $record['old_price'] : null,
            'old_sale_price'   => is_numeric($record['old_sale_price']) ? $record['old_sale_price'] : null,
            'distributor_slug' => sanitize_text_field($record['distributor_slug']),
            'distributor_ref'  => sanitize_text_field($record['distributor_ref']),
            'sync_run_id'      => sanitize_text_field($record['sync_run_id']),
            'created_at'       => $record['created_at'],
        ]);

        return $wpdb->insert_id;
    }

    /**
     * Generate a unique sync run ID
     */
    private function generate_sync_run_id() {
        return md5(uniqid('sync_', true));
    }

    /**
     * Get recent log entries
     */
    public function get_recent($limit = 50) {
        global $wpdb;

        $limit = max(1, min(intval($limit), 1000));

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} ORDER BY created_at DESC LIMIT %d",
            $limit
        ), ARRAY_A);
    }

    /**
     * Get unique sync runs for summary display
     */
    public function get_sync_runs($limit = 20) {
        global $wpdb;

        $limit = max(1, min(intval($limit), 1000));

        return $wpdb->get_results($wpdb->prepare(
            "SELECT 
                sync_run_id,
                distributor_slug,
                MIN(created_at) as run_date,
                COUNT(*) as total_changes,
                SUM(CASE WHEN action = 'marked_unavailable' THEN 1 ELSE 0 END) as unavailable_count
            FROM {$this->table_name}
            GROUP BY sync_run_id, distributor_slug
            ORDER BY run_date DESC
            LIMIT %d",
            $limit
        ), ARRAY_A);
    }
}
