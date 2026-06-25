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
        add_filter('admin_footer_text', [$this, 'custom_admin_footer_text']);
        add_filter('update_footer', [$this, 'custom_admin_footer_version'], 11);
    }

    /**
     * Add top-level admin menu
     */
    public function add_menu_page() {
        add_menu_page(
            __('StockSync', 'stock-sync'),
            __('StockSync', 'stock-sync'),
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

        $registry = StockSync_Distributor_Registry::instance();

        wp_localize_script('stock-sync-admin', 'stockSync', [
            'ajaxUrl'           => admin_url('admin-ajax.php'),
            'adminUrl'          => admin_url(),
            'nonce'             => wp_create_nonce('stock_sync_nonce'),
            'defaultDistributor'=> $registry->get_default_slug() ?: 'vininova',
            'strings' => [
                'uploading' => __('Uploading...', 'stock-sync'),
                'analyzing' => __('Analyzing...', 'stock-sync'),
                'upload' => __('Upload', 'stock-sync'),
                'saving' => __('Saving...', 'stock-sync'),
                'confirmMappingsContinue' => __('Confirm Mappings & Continue', 'stock-sync'),
                'applySync' => __('Apply Sync', 'stock-sync'),
                'filtering' => __('Filtering...', 'stock-sync'),
                'syncing' => __('Syncing...', 'stock-sync'),
                'applyProduct' => __('Apply', 'stock-sync'),
                'applying' => __('Applying...', 'stock-sync'),
                'erasing' => __('Erasing...', 'stock-sync'),
                'eraseAllRefs' => __('Erase All Supplier References', 'stock-sync'),
                'searching' => __('Searching...', 'stock-sync'),
                'searchPlaceholder' => __('Search product name or SKU...', 'stock-sync'),
                'change' => __('Change', 'stock-sync'),
                'cancel' => __('Cancel', 'stock-sync'),
                'revert' => __('Revert', 'stock-sync'),
                'alreadyMapped' => __('Already mapped', 'stock-sync'),
                'noProductsFound' => __('No products found.', 'stock-sync'),
                'networkError' => __('Network error.', 'stock-sync'),
                'noChange' => __('(no change)', 'stock-sync'),
                'cleared' => __('(removed)', 'stock-sync'),
                'searchResultsOnly' => __('Search results only', 'stock-sync'),
                'catalogAndSearch' => __('Catalog & search', 'stock-sync'),
                'visible' => __('Catalog & search', 'stock-sync'),
                'search' => __('Search results only', 'stock-sync'),
                'sku' => __('SKU', 'stock-sync'),
                'name' => __('Name', 'stock-sync'),
                'ref' => __('Ref', 'stock-sync'),
                'status' => __('Status', 'stock-sync'),
                'action' => __('Action', 'stock-sync'),
                'delist' => __('delist', 'stock-sync'),
                'publish' => __('publish', 'stock-sync'),
                'publikuj' => __('publish', 'stock-sync'),
                'delisted' => __('delisted', 'stock-sync'),
                'published' => __('published', 'stock-sync'),
                'notFound' => __('not_found', 'stock-sync'),
                'error' => __('error', 'stock-sync'),
                'more' => __('... and %s more', 'stock-sync'),
                'syncResults' => __('Sync Results', 'stock-sync'),
                'totalProcessed' => __('Total Processed', 'stock-sync'),
                'delistedLabel' => __('Delisted', 'stock-sync'),
                'publishedLabel' => __('Published', 'stock-sync'),
                'errorsLabel' => __('Errors', 'stock-sync'),
                'publishMode' => __('Publish', 'stock-sync'),
                'delistMode' => __('Delist', 'stock-sync'),
                'markup' => __('Markup', 'stock-sync'),
                'markupLabel' => __('Markup %', 'stock-sync'),
                'wouldPublish' => __('would_publish', 'stock-sync'),
                'editPrice' => __('Edit price', 'stock-sync'),
                'save' => __('Save', 'stock-sync'),
                'priceValidationRules' => __('Positive numbers only, max 2 decimals, zero not allowed, at least one price required.', 'stock-sync'),
                'price' => __('Price', 'stock-sync'),
                'salePrice' => __('Sale price', 'stock-sync'),
                'currentVisibility' => __('Current visibility', 'stock-sync'),
                'field' => __('Field', 'stock-sync'),
                'current' => __('Current', 'stock-sync'),
                'afterUpdate' => __('After update', 'stock-sync'),
                'regularPrice' => __('Regular price', 'stock-sync'),
'editProduct' => __('Edit product', 'stock-sync'),
                'product' => __('Produkt', 'stock-sync'),
                'updatedSuccessfully' => __('zaktualizowany pomyślnie.', 'stock-sync'),
                'pleaseSelectFile' => __('Please select a file.', 'stock-sync'),
                'pleaseSelectOneMatch' => __('Please select at least one match to save.', 'stock-sync'),
                'pleaseSelectOneProduct' => __('Please select at least one product to update.', 'stock-sync'),
                'noSyncData' => __('No sync data available. Please start over.', 'stock-sync'),
                'uploadError' => __('Upload error', 'stock-sync'),
                'scanFailed' => __('Scan failed', 'stock-sync'),
                'saveFailed' => __('Save failed', 'stock-sync'),
                'filterFailed' => __('Filter failed', 'stock-sync'),
                'batchFailed' => __('Batch failed', 'stock-sync'),
                'syncStopped' => __('Sync stopped', 'stock-sync'),
                'eraseFailed' => __('Erase failed', 'stock-sync'),
                'eraseNetworkError' => __('Erase network error', 'stock-sync'),
                'networkErrorUpload' => __('Network error during upload.', 'stock-sync'),
                'networkErrorReadFile' => __('Network error while reading the file. Please try again.', 'stock-sync'),
                'networkErrorFilter' => __('Filter network error', 'stock-sync'),
                'networkErrorLoadProduct' => __('Network error loading product details.', 'stock-sync'),
                'failedToLoadProduct' => __('Failed to load product: %s', 'stock-sync'),
                'confirmUpdateSingle' => __('Are you sure you want to update this single product?', 'stock-sync'),
                'confirmEraseRefs' => __('Are you sure you want to erase all supplier references for %s? Any ongoing sync will be aborted.', 'stock-sync'),
                'erasedRefs' => __('Erased %s references for %s.', 'stock-sync'),
                'reviewMappings' => __('Review mappings...', 'stock-sync'),
                'startingScan' => __('Starting scan...', 'stock-sync'),
                'savingMappings' => __('Saving mappings...', 'stock-sync'),
                'preparingSync' => __('Preparing sync...', 'stock-sync'),
                'scanningBatch' => __('Scanning batch %1$s of %2$s', 'stock-sync'),
                'syncingBatch' => __('Syncing batch %1$s of %2$s', 'stock-sync'),
                'uploadErrorHeader' => __('We could not locate the required column headers. If you set custom names under Advanced options, please double-check them. Otherwise, verify the file contains the reference and availability columns, then try uploading again.', 'stock-sync'),
                'uploadErrorGeneric' => __('Expected column headers have not been found. Check if the correct distributor file has been uploaded or modify column names under Advanced options.', 'stock-sync'),
                'pleaseEnterTwoChars' => __('Please enter at least 2 characters.', 'stock-sync'),
                'duplicateMappingDetected' => __('Duplicate mapping detected. Please adjust your selections.', 'stock-sync'),
                'duplicateMappingsDetected' => __('Duplicate mappings detected. Please adjust your selections.', 'stock-sync'),
                'mappedToRefs' => __('%1$s (SKU: %2$s) is mapped to refs: %3$s', 'stock-sync'),
                'productUpdated' => __('Product updated successfully.', 'stock-sync'),
                'unknownServerError' => __('Unknown server error', 'stock-sync'),
                'headerRowNotFound' => __('Header row not found', 'stock-sync'),
                'unknown' => __('Unknown', 'stock-sync'),
            ],
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
        $default_dist = $registry->get_default_slug() ?: 'vininova';
        $current_dist = isset($_GET['distributor']) ? sanitize_text_field($_GET['distributor']) : $default_dist;

        include STOCK_SYNC_PLUGIN_DIR . 'admin/views/page-wrapper.php';
    }

    /**
     * Custom admin footer text (left side) on plugin pages.
     *
     * @param string $text Default footer text.
     * @return string
     */
    public function custom_admin_footer_text($text) {
        if (!$this->is_plugin_screen()) {
            return $text;
        }
        $link = sprintf(
            '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
            esc_url('https://vmosiichuk.dev'),
            esc_html('vmosiichuk.dev')
        );
        return sprintf(
            /* translators: %s: link to developer website */
            __('Plugin developed by Vladyslav Mosiichuk – %s', 'stock-sync'),
            $link
        );
    }

    /**
     * Custom admin footer version (right side) on plugin pages.
     *
     * @param string $version Default version text.
     * @return string
     */
    public function custom_admin_footer_version($version) {
        if (!$this->is_plugin_screen()) {
            return $version;
        }
        return 'StockSync v' . STOCK_SYNC_VERSION;
    }

    /**
     * Check if the current screen is a plugin admin page.
     *
     * @return bool
     */
    private function is_plugin_screen() {
        $screen = get_current_screen();
        return $screen && $screen->id === 'toplevel_page_' . $this->plugin_slug;
    }
}
