<?php
/**
 * AJAX Handler for batch processing
 */
class StockSync_AJAX_Handler {

    /**
     * Register AJAX action hooks.
     *
     * @return void
     */
    public function __construct() {
        add_action('wp_ajax_stock_sync_init', [$this, 'init_sync']);
        add_action('wp_ajax_stock_sync_batch', [$this, 'process_batch']);
        add_action('wp_ajax_stock_sync_bootstrap_analyze', [$this, 'bootstrap_analyze']);
        add_action('wp_ajax_stock_sync_bootstrap_save', [$this, 'bootstrap_save']);
        add_action('wp_ajax_stock_sync_test_search', [$this, 'test_search']);
        add_action('wp_ajax_stock_sync_test_get_product', [$this, 'test_get_product']);
        add_action('wp_ajax_stock_sync_test_apply', [$this, 'test_apply']);
    }

    /**
     * Validate that a file path is inside the plugin's temp uploads dir and is an XLSX
     */
    private function validate_uploaded_file_path($file_path) {
        $upload_dir = wp_upload_dir();
        $temp_dir   = trailingslashit(wp_normalize_path($upload_dir['basedir'])) . 'stock-sync-temp';

        $real_file = realpath($file_path);
        $real_temp = realpath($temp_dir);

        if (!$real_file || !$real_temp) {
            return new WP_Error('invalid_path', __('Invalid file path', 'stock-sync'));
        }

        $real_file = wp_normalize_path($real_file);
        $real_temp = wp_normalize_path($real_temp);

        if (strpos($real_file, $real_temp) !== 0) {
            return new WP_Error('invalid_path', __('File is not in the allowed temp directory', 'stock-sync'));
        }

        $ext = strtolower(pathinfo($real_file, PATHINFO_EXTENSION));
        if ($ext !== 'xlsx') {
            return new WP_Error('invalid_type', __('File must be an XLSX', 'stock-sync'));
        }

        return $real_file;
    }

    /**
     * Initialize sync: parse XLSX, filter unavailable, store queue
     */
    public function init_sync() {
        check_ajax_referer('stock_sync_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission denied', 'stock-sync'));
        }

        $slug      = sanitize_text_field($_POST['distributor_slug'] ?? '');
        $file_path = sanitize_text_field($_POST['file_path'] ?? '');
        $dry_run   = !empty($_POST['dry_run']);

        $distributor = StockSync_Distributor_Registry::instance()->get($slug);
        if (!$distributor) {
            wp_send_json_error(__('Unknown distributor: ', 'stock-sync') . $slug);
        }

        $validated_path = $this->validate_uploaded_file_path($file_path);
        if (is_wp_error($validated_path)) {
            wp_send_json_error($validated_path->get_error_message());
        }

        $parser   = new StockSync_XLSX_Parser($validated_path, $distributor);
        $products = $parser->parse();

        if (is_wp_error($products)) {
            wp_send_json_error($products->get_error_message());
        }

        $to_process = [];
        foreach ($products as $product) {
            if ($product->is_unavailable) {
                $to_process[] = [
                    'distributor_ref'  => $product->distributor_ref,
                    'product_name'     => $product->product_name,
                    'distributor_slug' => $product->distributor_slug,
                ];
            }
        }

        $run_id        = wp_create_nonce('stock_sync_run_' . $slug);
        $transient_key = 'stock_sync_queue_' . $slug . '_' . $run_id;
        set_transient($transient_key, $to_process, HOUR_IN_SECONDS);

        wp_send_json_success([
            'total_batches' => ceil(count($to_process) / 50),
            'total_items'   => count($to_process),
            'dry_run'       => $dry_run,
            'run_id'        => $run_id,
        ]);
    }

    /**
     * Process one batch of 50 products
     */
    public function process_batch() {
        check_ajax_referer('stock_sync_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission denied', 'stock-sync'));
        }

        $slug    = sanitize_text_field($_POST['distributor_slug'] ?? '');
        $dry_run = !empty($_POST['dry_run']);
        $offset  = intval($_POST['offset'] ?? 0);
        $run_id  = sanitize_text_field($_POST['run_id'] ?? '');
        $limit   = 50;

        $distributor = StockSync_Distributor_Registry::instance()->get($slug);
        if (!$distributor) {
            wp_send_json_error(__('Unknown distributor', 'stock-sync'));
        }

        if (empty($run_id)) {
            wp_send_json_error(__('Missing run ID', 'stock-sync'));
        }

        $transient_key = 'stock_sync_queue_' . $slug . '_' . $run_id;
        $queue         = get_transient($transient_key);

        if (!is_array($queue)) {
            wp_send_json_error(__('No sync queue found', 'stock-sync'));
        }

        $batch     = array_slice($queue, $offset, $limit);
        $matcher   = new StockSync_Product_Matcher();
        $updater   = new StockSync_Product_Updater();
        $meta_key  = $distributor->get_meta_key();
        $results   = [
            'processed' => 0,
            'updated'   => 0,
            'not_found' => 0,
            'errors'    => 0,
            'dry_run'   => $dry_run,
            'details'   => [],
        ];

        foreach ($batch as $item) {
            $results['processed']++;

            $product_id = $matcher->find_by_distributor_ref($item['distributor_ref'], $meta_key);

            if (!$product_id) {
                $results['not_found']++;
                $results['details'][] = [
                    'distributor_ref' => $item['distributor_ref'],
                    'name'            => $item['product_name'],
                    'status'          => 'not_found',
                ];
                continue;
            }

            if ($dry_run) {
                $results['updated']++;
                $results['details'][] = [
                    'distributor_ref' => $item['distributor_ref'],
                    'name'            => $item['product_name'],
                    'status'          => 'would_update',
                    'product_id'      => $product_id,
                ];
                continue;
            }

            $standard = new StockSync_Standard_Product($item);
            $result   = $updater->mark_unavailable($product_id, $standard, $distributor);

            if (is_wp_error($result)) {
                $results['errors']++;
                $results['details'][] = [
                    'distributor_ref' => $item['distributor_ref'],
                    'name'            => $item['product_name'],
                    'status'          => 'error',
                    'error'           => $result->get_error_message(),
                ];
            } else {
                $results['updated']++;
                $results['details'][] = [
                    'distributor_ref' => $item['distributor_ref'],
                    'name'            => $item['product_name'],
                    'status'          => 'updated',
                    'product_id'      => $product_id,
                ];
            }
        }

        wp_send_json_success($results);
    }

    /**
     * Analyze XLSX for bootstrap fuzzy matching
     */
    public function bootstrap_analyze() {
        check_ajax_referer('stock_sync_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission denied', 'stock-sync'));
        }

        $slug      = sanitize_text_field($_POST['distributor_slug'] ?? '');
        $file_path = sanitize_text_field($_POST['file_path'] ?? '');

        $distributor = StockSync_Distributor_Registry::instance()->get($slug);
        if (!$distributor) {
            wp_send_json_error(__('Unknown distributor', 'stock-sync'));
        }

        $validated_path = $this->validate_uploaded_file_path($file_path);
        if (is_wp_error($validated_path)) {
            wp_send_json_error($validated_path->get_error_message());
        }

        $parser   = new StockSync_XLSX_Parser($validated_path, $distributor);
        $products = $parser->parse();

        if (is_wp_error($products)) {
            wp_send_json_error($products->get_error_message());
        }

        $bootstrap = new StockSync_Bootstrap_Matcher();
        $category  = $distributor->get_category_filter();
        $wc_products = $bootstrap->get_all_wc_products($category);
        $matches     = $bootstrap->match_all($products, $wc_products);

        wp_send_json_success([
            'matches'         => $matches,
            'total_xlsx'      => count($products),
            'total_wc'        => count($wc_products),
            'category_filter' => $category,
        ]);
    }

    /**
     * Save confirmed bootstrap mappings
     */
    public function bootstrap_save() {
        check_ajax_referer('stock_sync_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission denied', 'stock-sync'));
        }

        $slug    = sanitize_text_field($_POST['distributor_slug'] ?? '');
        $matches = isset($_POST['matches']) ? (array) $_POST['matches'] : [];

        $distributor = StockSync_Distributor_Registry::instance()->get($slug);
        if (!$distributor) {
            wp_send_json_error(__('Unknown distributor', 'stock-sync'));
        }

        $bootstrap = new StockSync_Bootstrap_Matcher();
        $saved     = $bootstrap->save_mappings($matches, $distributor->get_meta_key());

        wp_send_json_success([
            'saved' => $saved,
        ]);
    }

    /**
     * Test: Search products by name or SKU
     */
    public function test_search() {
        check_ajax_referer('stock_sync_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission denied', 'stock-sync'));
        }

        $query   = sanitize_text_field($_POST['q'] ?? '');
        $slug    = sanitize_text_field($_POST['distributor_slug'] ?? '');
        $limit   = min(intval($_POST['limit'] ?? 10), 20);

        if (strlen($query) < 2) {
            wp_send_json_error(__('Query too short', 'stock-sync'));
        }

        $distributor = StockSync_Distributor_Registry::instance()->get($slug);
        $category    = $distributor ? $distributor->get_category_filter() : null;

        $args = [
            'post_type'      => 'product',
            'posts_per_page' => $limit,
            'fields'         => 'ids',
            's'              => $query,
            'no_found_rows'  => true,
        ];

        // Also search by SKU via meta query
        $sku_args = [
            'post_type'      => 'product',
            'posts_per_page' => $limit,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => '_sku',
                    'value'   => $query,
                    'compare' => 'LIKE',
                ],
            ],
            'no_found_rows'  => true,
        ];

        if (!empty($category)) {
            $tax_query = [
                [
                    'taxonomy' => 'product_cat',
                    'field'    => 'name',
                    'terms'    => $category,
                ],
            ];
            $args['tax_query']     = $tax_query;
            $sku_args['tax_query'] = $tax_query;
        }

        $name_query = new WP_Query($args);
        $sku_query  = new WP_Query($sku_args);

        $ids = array_unique(array_merge($name_query->posts, $sku_query->posts));
        $results = [];

        foreach (array_slice($ids, 0, $limit) as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product) {
                continue;
            }
            $results[] = [
                'id'   => $product_id,
                'name' => $product->get_name(),
                'sku'  => $product->get_sku(),
            ];
        }

        wp_send_json_success([
            'products' => $results,
        ]);
    }

    /**
     * Test: Get single product details
     */
    public function test_get_product() {
        check_ajax_referer('stock_sync_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission denied', 'stock-sync'));
        }

        $product_id = intval($_POST['product_id'] ?? 0);
        $slug       = sanitize_text_field($_POST['distributor_slug'] ?? '');

        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error(__('Product not found', 'stock-sync'));
        }

        $distributor = StockSync_Distributor_Registry::instance()->get($slug);
        if (!$distributor) {
            wp_send_json_error(__('Unknown distributor', 'stock-sync'));
        }

        $new_excerpt = $distributor->get_unavailable_description($product->get_name());

        wp_send_json_success([
            'id'           => $product_id,
            'name'         => $product->get_name(),
            'sku'          => $product->get_sku(),
            'visibility'   => $product->get_catalog_visibility(),
            'price'        => $product->get_regular_price(),
            'sale_price'   => $product->get_sale_price(),
            'excerpt'      => $product->get_short_description(),
            'new_excerpt'  => $new_excerpt,
        ]);
    }

    /**
     * Test: Apply unavailable state to a single product
     */
    public function test_apply() {
        check_ajax_referer('stock_sync_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission denied', 'stock-sync'));
        }

        $product_id = intval($_POST['product_id'] ?? 0);
        $slug       = sanitize_text_field($_POST['distributor_slug'] ?? '');

        $distributor = StockSync_Distributor_Registry::instance()->get($slug);
        if (!$distributor) {
            wp_send_json_error(__('Unknown distributor', 'stock-sync'));
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error(__('Product not found', 'stock-sync'));
        }

        $standard = new StockSync_Standard_Product([
            'distributor_ref'  => '',
            'product_name'     => $product->get_name(),
            'distributor_slug' => $distributor->get_slug(),
        ]);

        $updater = new StockSync_Product_Updater();
        $result  = $updater->mark_unavailable($product_id, $standard, $distributor);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        // Refresh product data after update
        $updated = wc_get_product($product_id);

        wp_send_json_success([
            'message'     => __('Product updated successfully.', 'stock-sync'),
            'product_id'  => $product_id,
            'new_visibility' => $updated->get_catalog_visibility(),
            'new_price'   => $updated->get_regular_price(),
            'new_sale'    => $updated->get_sale_price(),
            'new_excerpt' => $updated->get_short_description(),
        ]);
    }
}
