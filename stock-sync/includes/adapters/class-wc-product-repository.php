<?php
class StockSync_WC_Product_Repository implements Product_Repository_Interface {

    /**
     * Fetches the WooCommerce product for the given product ID.
     *
     * @param int $product_id The product ID.
     * @return \WC_Product|false|null The `WC_Product` instance for the ID, or `false`/`null` if not found.
     */
    public function find_by_id($product_id) {
        return wc_get_product($product_id);
    }

    / **
     * Retrieve all WooCommerce products, optionally filtered by category name.
     *
     * @param string|null $category Product category name to filter by, or null to include all categories.
     * @return array[] List of product summary arrays. Each array contains:
     *                 - 'id'   (int)           Product ID.
     *                 - 'name' (string)        Product name.
     *                 - 'sku'  (string|null)   Product SKU (or null if not set).
     */
    public function find_all($category = null) {
        $args = [
            'post_type'              => 'product',
            'posts_per_page'         => -1,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ];

        if (!empty($category)) {
            $args['tax_query'] = [
                [
                    'taxonomy' => 'product_cat',
                    'field'    => 'name',
                    'terms'    => sanitize_text_field($category),
                ],
            ];
        }

        $query    = new WP_Query($args);
        $products = [];

        foreach ($query->posts as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product) {
                continue;
            }

            $products[] = [
                'id'   => $product_id,
                'name' => $product->get_name(),
                'sku'  => $product->get_sku(),
            ];
        }

        return $products;
    }

    /**
     * Find a product ID by a post meta key/value pair.
     *
     * Searches products for the first post whose meta key equals the provided value and returns its ID.
     *
     * @param string $meta_key   Meta key to match.
     * @param string $meta_value Meta value to match.
     * @return int|false Product ID of the first matching product, or `false` if no match is found or if either parameter is empty.
     */
    public function find_by_meta($meta_key, $meta_value) {
        $meta_key   = sanitize_key($meta_key);
        $meta_value = sanitize_text_field($meta_value);

        if (empty($meta_key) || empty($meta_value)) {
            return false;
        }

        $args = [
            'post_type'      => 'product',
            'posts_per_page' => 1,
            'meta_query'     => [
                [
                    'key'     => $meta_key,
                    'value'   => $meta_value,
                    'compare' => '=',
                ],
            ],
            'fields' => 'ids',
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ];

        $query = new WP_Query($args);

        if ($query->have_posts()) {
            return $query->posts[0];
        }

        return false;
    }

    /**
     * Finds a WooCommerce product ID by SKU.
     *
     * @param string $sku The product SKU to look up.
     * @return int|false The product ID if found, `false` otherwise.
     */
    public function find_by_sku($sku) {
        $product_id = wc_get_product_id_by_sku($sku);
        return $product_id ? $product_id : false;
    }

    /**
     * Persist changes made to a WooCommerce product.
     *
     * @param \WC_Product $product The product instance to save.
     */
    public function save($product) {
        $product->save();
    }
}
