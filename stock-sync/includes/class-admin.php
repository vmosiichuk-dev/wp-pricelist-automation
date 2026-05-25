<?php
/**
 * Admin UI Controller
 */
class StockSync_Admin {

    private $plugin_slug = 'stock-sync';

    /**
     * Register admin menu, assets and AJAX hooks.
     *
     * @return void
     */
    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_stock_sync_upload_file', [$this, 'ajax_upload_file']);
    }

    /**
     * Add top-level admin menu
     */
    public function add_menu_page() {
        add_menu_page(
            __('Stock Sync', 'stock-sync'),
            __('Stock Sync', 'stock-sync'),
            'manage_woocommerce',
            $this->plugin_slug,
            [$this, 'render_page'],
            'dashicons-update',
            58
        );
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_assets($hook) {
        if ($hook !== 'toplevel_page_' . $this->plugin_slug) {
            return;
        }

        wp_enqueue_style(
            'stock-sync-admin',
            STOCK_SYNC_PLUGIN_URL . 'assets/css/admin.css',
            [],
            STOCK_SYNC_VERSION
        );

        wp_enqueue_script(
            'stock-sync-admin',
            STOCK_SYNC_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            STOCK_SYNC_VERSION,
            true
        );

        wp_localize_script('stock-sync-admin', 'stockSync', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('stock_sync_nonce'),
        ]);
    }

    /**
     * AJAX file upload handler
     * Receives XLSX, moves to temp, returns path for next AJAX calls
     */
    public function ajax_upload_file() {
        check_ajax_referer('stock_sync_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission denied', 'stock-sync'));
        }

        if (!isset($_FILES['xlsx_file'])) {
            wp_send_json_error(__('No file uploaded', 'stock-sync'));
        }

        $uploaded = $_FILES['xlsx_file'];

        if ($uploaded['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(__('Upload failed', 'stock-sync'));
        }

        $max_bytes = 5 * 1024 * 1024; // 5 MB
        if ($uploaded['size'] > $max_bytes) {
            wp_send_json_error(__('File too large. Maximum size is 5 MB.', 'stock-sync'));
        }

        // Validate MIME type
        $mime_type = '';
        $finfo     = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $mime_type = finfo_file($finfo, $uploaded['tmp_name']);
            finfo_close($finfo);
        } elseif (function_exists('mime_content_type')) {
            $mime_type = mime_content_type($uploaded['tmp_name']);
        }

        $valid_types = [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/octet-stream',
        ];

        if (!in_array($mime_type, $valid_types, true)) {
            wp_send_json_error(__('Invalid file type. Please upload an XLSX file.', 'stock-sync'));
        }

        // Move to a persistent temp location with unique name
        $upload_dir = wp_upload_dir();
        $temp_dir   = trailingslashit($upload_dir['basedir']) . 'stock-sync-temp';

        if (!file_exists($temp_dir)) {
            wp_mkdir_p($temp_dir);
        }

        $temp_name = 'stock_' . wp_generate_uuid4() . '.xlsx';
        $temp_path = $temp_dir . '/' . $temp_name;

        if (!move_uploaded_file($uploaded['tmp_name'], $temp_path)) {
            wp_send_json_error(__('Failed to save uploaded file', 'stock-sync'));
        }

        // Set restrictive permissions and schedule cleanup
        chmod($temp_path, 0600);
        wp_schedule_single_event(time() + HOUR_IN_SECONDS, 'stock_sync_cleanup_temp', ['path' => $temp_path]);

        wp_send_json_success([
            'file_path' => $temp_path,
            'file_name' => sanitize_file_name($uploaded['name']),
        ]);
    }

    /**
     * Render the admin page
     */
    public function render_page() {
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'sync';
        $registry   = StockSync_Distributor_Registry::instance();
        $distributors = $registry->get_options();
        $current_dist = isset($_GET['distributor']) ? sanitize_text_field($_GET['distributor']) : 'vininova';

        include STOCK_SYNC_PLUGIN_DIR . 'admin/views/page-wrapper.php';
    }
}
