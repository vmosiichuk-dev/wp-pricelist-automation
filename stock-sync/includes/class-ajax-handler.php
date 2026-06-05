<?php
/**
 * AJAX Handler for batch processing
 */
class StockSync_AJAX_Handler {
    private $transient_store;

    /**
     * Initialize the handler and register WordPress AJAX action hooks.
     *
     * Initializes the transient store (using the provided implementation or a default)
     * and registers AJAX endpoints used by the stock sync workflow.
     *
     * @param Transient_Store_Interface|null $transient_store Optional transient store implementation; if null a StockSync_WP_Transient_Store is used.
     * @return void
     */
    public function __construct(?Transient_Store_Interface $transient_store = null) {
        $this->transient_store = $transient_store ?: new StockSync_WP_Transient_Store();

        add_action('wp_ajax_stock_sync_init', [$this, 'init_sync']);
        add_action('wp_ajax_stock_sync_batch', [$this, 'process_batch']);
        add_action('wp_ajax_stock_sync_bootstrap_analyze', [$this, 'bootstrap_analyze']);
        add_action('wp_ajax_stock_sync_bootstrap_save', [$this, 'bootstrap_save']);
        add_action('wp_ajax_stock_sync_filter_queue', [$this, 'filter_queue']);
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
        $real_temp = rtrim($real_temp, '/') . '/';

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
     * Start an import run by parsing a provided XLSX, collecting products marked unavailable,
     * storing the work queue in a transient, and returning run metadata.
     *
     * Expects POST fields:
     * - `distributor_slug` (string): distributor identifier.
     * - `file_path` (string): uploaded XLSX path to validate and parse.
     * - `dry_run` (truthy): if present, indicates a dry run.
     *
     * On success sends a JSON response containing:
     * - `total_batches` (int): number of 50-item batches.
     * - `total_items` (int): total queued items.
     * - `dry_run` (bool): whether the run is a dry run.
     * - `run_id` (string): UUID for this run (used to retrieve the queue).
     *
     * On failure sends a JSON error response for permission checks, unknown distributor,
     * invalid file path, or parser errors.
     */
    public function init_sync() {
        check_ajax_referer('stock_sync_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission denied', 'stock-sync'));
        }

        $slug      = sanitize_text_field($_POST['distributor_slug'] ?? '');
        $file_path = sanitize_text_field($_POST['file_path'] ?? '');
        $dry_run   = isset($_POST['dry_run']) ? filter_var($_POST['dry_run'], FILTER_VALIDATE_BOOLEAN) : false;

        $distributor = StockSync_Distributor_Registry::instance()->get($slug);
        if (!$distributor) {
            wp_send_json_error(__('Unknown distributor: ', 'stock-sync') . $slug);
        }

        $validated_path = $this->validate_uploaded_file_path($file_path);
        if (is_wp_error($validated_path)) {
            wp_send_json_error($validated_path->get_error_message());
        }

        $parser   = StockSync_Service_Factory::xlsx_parser($validated_path, $distributor);
        $products = $parser->parse();

        if (is_wp_error($products)) {
            wp_send_json_error($products->get_error_message());
        }

        $warnings = [];
        $unrecognized = $parser->get_unrecognized_availability();
        if (!empty($unrecognized)) {
            $warnings[] = sprintf(
                /* translators: 1: count, 2: comma-separated list */
                _n(
                    'Warning: unrecognized availability value found: %2$s. These products were treated as available.',
                    'Warning: unrecognized availability values found (%1$d): %2$s. These products were treated as available.',
                    count($unrecognized),
                    'stock-sync'
                ),
                count($unrecognized),
                implode(', ', $unrecognized)
            );
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

        $run_id        = wp_generate_uuid4();
        $transient_key = 'stock_sync_queue_' . $slug . '_' . $run_id;
        $this->transient_store->set($transient_key, $to_process, HOUR_IN_SECONDS);

        wp_send_json_success([
            'total_batches' => ceil(count($to_process) / 50),
            'total_items'   => count($to_process),
            'dry_run'       => $dry_run,
            'run_id'        => $run_id,
            'warnings'      => $warnings,
        ]);
    }

    /**
     * Handle an AJAX request to process a single batch of distributor products (up to 50) from a stored sync queue.
     *
     * Validates the AJAX nonce and that the current user can manage WooCommerce, then reads `distributor_slug`,
     * `run_id`, `offset` and `dry_run` from POST. Loads the queue transient for the given distributor and run ID,
     * processes up to 50 items starting at `offset`, and for each item attempts to locate the corresponding WC product
     * by distributor reference and either records a `not_found` entry, a `would_update` entry when `dry_run` is enabled,
     * or calls the product updater to mark the product unavailable and records `updated` or `error` entries.
     *
     * On error (missing permission, unknown distributor, missing run ID, or missing queue) the handler sends a JSON error.
     * On success the handler sends a JSON success payload containing an associative array with these keys:
     * - `processed`: number of items processed
     * - `updated`: number of items updated (or would be updated when `dry_run` is true)
     * - `not_found`: number of distributor refs with no matching product
     * - `errors`: number of update errors
     * - `dry_run`: boolean echoing the request's dry run flag
     * - `details`: list of per-item records; each record contains `distributor_ref`, `name`, `status` and, when applicable, `product_id` or `error`.
     */
    public function process_batch() {
        check_ajax_referer('stock_sync_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission denied', 'stock-sync'));
        }

        $slug    = sanitize_text_field($_POST['distributor_slug'] ?? '');
        $dry_run = isset($_POST['dry_run']) ? filter_var($_POST['dry_run'], FILTER_VALIDATE_BOOLEAN) : false;
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
        $queue         = $this->transient_store->get($transient_key);

        if (!is_array($queue)) {
            wp_send_json_error(__('No sync queue found', 'stock-sync'));
        }

        $batch     = array_slice($queue, $offset, $limit);
        $matcher   = StockSync_Service_Factory::product_matcher();
        $updater   = StockSync_Service_Factory::product_updater();
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
     * Produce fuzzy-match suggestions from an uploaded XLSX and return them as a JSON response.
     *
     * Parses the provided XLSX for distributor items, runs the bootstrap matcher against
     * WooCommerce products (optionally filtered by the distributor's category), and sends a
     * JSON success payload containing:
     *  - `matches`: suggested mappings between distributor items and WC product IDs
     *  - `total_xlsx`: number of parsed XLSX items
     *  - `total_wc`: number of WC products considered
     *  - `category_filter`: the distributor's category filter used (may be null or empty)
     *  - `warnings`: array of parser warnings (e.g., unrecognized availability values)
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

        $custom_labels = [];
        $header_ref    = isset($_POST['header_label_ref']) ? sanitize_text_field($_POST['header_label_ref']) : '';
        $header_avail  = isset($_POST['header_label_avail']) ? sanitize_text_field($_POST['header_label_avail']) : '';
        if ($header_ref !== '') {
            $custom_labels[] = $header_ref;
        }
        if ($header_avail !== '') {
            $custom_labels[] = $header_avail;
        }
        if (!empty($custom_labels)) {
            $distributor->set_header_labels($custom_labels);
        }

        $validated_path = $this->validate_uploaded_file_path($file_path);
        if (is_wp_error($validated_path)) {
            wp_send_json_error($validated_path->get_error_message());
        }

        $parser   = StockSync_Service_Factory::xlsx_parser($validated_path, $distributor);
        $products = $parser->parse();

        if (is_wp_error($products)) {
            wp_send_json_error($products->get_error_message());
        }

        $warnings = [];
        $unrecognized = $parser->get_unrecognized_availability();
        if (!empty($unrecognized)) {
            $warnings[] = sprintf(
                /* translators: 1: count, 2: comma-separated list */
                _n(
                    'Warning: unrecognized availability value found: %2$s. These products were treated as available.',
                    'Warning: unrecognized availability values found (%1$d): %2$s. These products were treated as available.',
                    count($unrecognized),
                    'stock-sync'
                ),
                count($unrecognized),
                implode(', ', $unrecognized)
            );
        }

        // Separate already-mapped from unmapped products
        $meta_key    = $distributor->get_meta_key();
        $unmapped    = [];
        $already_mapped_items = [];

        // Preload all mapped products in a single query to avoid N+1
        $all_refs = array_map(fn($p) => $p->distributor_ref, $products);
        $existing_posts = get_posts([
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'meta_query'     => [
                [
                    'key'     => $meta_key,
                    'value'   => $all_refs,
                    'compare' => 'IN',
                ],
            ],
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
        ]);

        $ref_map = [];
        foreach ($existing_posts as $post) {
            $ref = get_post_meta($post->ID, $meta_key, true);
            $ref_map[$ref] = (int) $post->ID;
        }

        foreach ($products as $product) {
            if (isset($ref_map[$product->distributor_ref])) {
                $post_id    = $ref_map[$product->distributor_ref];
                $wc_product = wc_get_product($post_id);
                $already_mapped_items[] = [
                    'distributor_ref' => $product->distributor_ref,
                    'xlsx_name'       => $product->product_name,
                    'wc_id'           => $post_id,
                    'wc_name'         => $wc_product ? $wc_product->get_name() : '',
                    'wc_sku'          => $wc_product ? $wc_product->get_sku() : '',
                    'confidence'      => 100,
                    'status'          => 'auto',
                ];
                continue;
            }

            $unmapped[] = $product;
        }

        $bootstrap = StockSync_Service_Factory::bootstrap_matcher();
        $category  = $distributor->get_category_filter();
        $wc_products = $bootstrap->get_all_wc_products($category);
        $matches     = $bootstrap->match_all($unmapped, $wc_products);

        // Drop 0% confidence matches entirely — nothing to show or auto-save
        $matches = array_values(array_filter($matches, function ($m) {
            return $m['confidence'] > 0;
        }));

        wp_send_json_success([
            'matches'              => $matches,
            'total_xlsx'           => count($products),
            'total_wc'             => count($wc_products),
            'category_filter'      => $category,
            'warnings'             => $warnings,
            'already_mapped_count' => count($already_mapped_items),
            'already_mapped_items' => $already_mapped_items,
        ]);
    }

    /**
     * Persist bootstrap match mappings for a distributor and respond with the save result.
     *
     * Expects POST fields `distributor_slug` and `matches` (each match must include
     * `distributor_ref` and `wc_id`). Sanitizes inputs, saves the mappings using the
     * bootstrap matcher against the distributor's meta key, and returns a JSON
     * success response containing `saved` with the saver result.
     */
    public function bootstrap_save() {
        check_ajax_referer('stock_sync_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission denied', 'stock-sync'));
        }

        $slug    = sanitize_text_field($_POST['distributor_slug'] ?? '');
        $matches = isset($_POST['matches']) ? (array) $_POST['matches'] : [];

        $matches_sanitized = [];
        foreach ($matches as $m) {
            if (!isset($m['distributor_ref'], $m['wc_id'])) {
                continue;
            }
            $matches_sanitized[] = [
                'distributor_ref' => sanitize_text_field($m['distributor_ref']),
                'wc_id'           => absint($m['wc_id']),
            ];
        }

        $distributor = StockSync_Distributor_Registry::instance()->get($slug);
        if (!$distributor) {
            wp_send_json_error(__('Unknown distributor', 'stock-sync'));
        }

        $bootstrap = StockSync_Service_Factory::bootstrap_matcher();
        $saved     = $bootstrap->save_mappings($matches_sanitized, $distributor->get_meta_key());

        wp_send_json_success([
            'saved'   => $saved,
            'matches' => $matches_sanitized,
        ]);
    }

    /**
     * Filter a sync queue to only the selected refs and return a new run_id.
     *
     * Reads the original queue transient, keeps only items whose distributor_ref is
     * in the include_refs list, stores the subset under a new run_id, and returns
     * the new run_id and batch count.
     */
    public function filter_queue() {
        check_ajax_referer('stock_sync_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission denied', 'stock-sync'));
        }

        $slug         = sanitize_text_field($_POST['distributor_slug'] ?? '');
        $run_id       = sanitize_text_field($_POST['run_id'] ?? '');
        $include_refs = isset($_POST['include_refs']) ? (array) $_POST['include_refs'] : [];

        $distributor = StockSync_Distributor_Registry::instance()->get($slug);
        if (!$distributor) {
            wp_send_json_error(__('Unknown distributor', 'stock-sync'));
        }

        if (empty($run_id)) {
            wp_send_json_error(__('Missing run ID', 'stock-sync'));
        }

        $transient_key = 'stock_sync_queue_' . $slug . '_' . $run_id;
        $queue         = $this->transient_store->get($transient_key);

        if (!is_array($queue)) {
            wp_send_json_error(__('No sync queue found', 'stock-sync'));
        }

        $allowed_refs = array_map('sanitize_text_field', $include_refs);
        $allowed_set  = array_flip($allowed_refs);

        $filtered = [];
        foreach ($queue as $item) {
            if (isset($allowed_set[$item['distributor_ref']])) {
                $filtered[] = $item;
            }
        }

        $new_run_id        = wp_generate_uuid4();
        $new_transient_key = 'stock_sync_queue_' . $slug . '_' . $new_run_id;
        $this->transient_store->set($new_transient_key, $filtered, HOUR_IN_SECONDS);

        wp_send_json_success([
            'run_id'        => $new_run_id,
            'total_batches' => ceil(count($filtered) / 50),
            'total_items'   => count($filtered),
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
        $limit   = max(1, min(intval($_POST['limit'] ?? 10), 20));

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

        $distributor_ref = get_post_meta($product_id, $distributor->get_meta_key(), true);
        $preview = $this->compute_preview($product, $distributor, $distributor_ref);

        wp_send_json_success($preview);
    }

    /**
     * Apply a distributor-specific "unavailable" state to a single WooCommerce product and return the result as JSON.
     *
     * Reads `product_id` and `distributor_slug` from POST, applies the distributor's unavailable transformation to the product,
     * and sends a JSON success response with the updated product fields (`product_id`, `new_visibility`, `new_price`, `new_sale`, `new_excerpt`).
     * Sends a JSON error response if the request is unauthorized, the distributor is unknown, the product is not found, or the updater returns an error.
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

        $distributor_ref = get_post_meta($product_id, $distributor->get_meta_key(), true);

        $standard = new StockSync_Standard_Product([
            'distributor_ref'  => $distributor_ref,
            'product_name'     => $product->get_name(),
            'distributor_slug' => $distributor->get_slug(),
        ]);

        $updater = StockSync_Service_Factory::product_updater();
        $result  = $updater->mark_unavailable($product_id, $standard, $distributor);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        // Refresh product data after update
        $updated = wc_get_product($product_id);

        wp_send_json_success([
            'message'        => __('Product updated successfully.', 'stock-sync'),
            'product_id'     => $product_id,
            'new_visibility' => $updated->get_catalog_visibility(),
            'new_price'      => $updated->get_regular_price(),
            'new_sale'       => $updated->get_sale_price(),
            'new_excerpt'    => $updated->get_short_description(),
            'new_name'       => $updated->get_name(),
            'new_slug'       => $updated->get_slug(),
        ]);
    }

    /**
     * Compute preview values for the Test Product tab.
     *
     * @param \WC_Product $product
     * @param StockSync_Distributor $distributor
     * @param string $distributor_ref
     * @return array
     */
    private function compute_preview($product, $distributor, $distributor_ref) {
        $current_name   = $product->get_name();
        $new_name       = $this->clean_name_preview($current_name);
        $new_slug       = sanitize_title($new_name);
        $cat_url        = $this->find_product_category_url($product->get_id());
        $suffix         = wp_kses_post($distributor->get_unavailable_suffix($product->get_id(), $cat_url));
        $new_excerpt    = $this->build_new_excerpt_preview($product->get_short_description(), $new_name, $suffix);

        return [
            'id'            => $product->get_id(),
            'name'          => $current_name,
            'new_name'      => $new_name,
            'slug'          => $product->get_slug(),
            'new_slug'      => $new_slug,
            'sku'           => $product->get_sku(),
            'visibility'    => $product->get_catalog_visibility(),
            'price'         => $product->get_regular_price(),
            'sale_price'    => $product->get_sale_price(),
            'excerpt'       => $product->get_short_description(),
            'new_excerpt'   => $new_excerpt,
        ];
    }

    /**
     * Preview-only name cleaner.
     *
     * @param string $name
     * @return string
     */
    private function clean_name_preview($name) {
        $name = preg_replace('/\s*-\s*\d+(?:,\d+)?\s*zł\.\*\*\s*$/iu', '', $name);
        $name = preg_replace('/^\d+(?:,\d+)?\s*zł\.\*\*\s*/iu', '', $name);
        return trim($name);
    }

    /**
     * Preview-only excerpt builder.
     *
     * @param string $current_excerpt
     * @param string $product_name
     * @param string $suffix
     * @return string
     */
    private function build_new_excerpt_preview($current_excerpt, $product_name, $suffix) {
        $prefix = '';
        if (preg_match('/^(.*?)\s*(?:>|&gt;)\s*/u', $current_excerpt, $matches)) {
            $prefix = trim($matches[1]);
        }

        if (empty($prefix)) {
            $prefix = $product_name;
        }

        return $prefix . ' > ' . $suffix;
    }

    /**
     * Get the URL of the first product category assigned to a product.
     *
     * @param int $product_id WooCommerce product ID.
     * @return string|false Category URL or false if not found.
     */
    private function find_product_category_url($product_id) {
        $terms = get_the_terms($product_id, 'product_cat');
        if (empty($terms) || is_wp_error($terms)) {
            return false;
        }

        foreach ($terms as $term) {
            $url = get_term_link($term, 'product_cat');
            if (!is_wp_error($url)) {
                return $url;
            }
        }

        return false;
    }
}
