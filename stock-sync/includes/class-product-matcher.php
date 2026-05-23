<?php
/**
 * Product Matcher
 * Looks up WooCommerce products by distributor-specific meta key.
 */
class StockSync_Product_Matcher {

    /**
     * Find WC product by distributor reference
     */
    public function find_by_distributor_ref($distributor_ref, $meta_key) {
        if (empty($distributor_ref) || empty($meta_key)) {
            return false;
        }

        $args = [
            'post_type'      => 'product',
            'posts_per_page' => 1,
            'meta_query'     => [
                [
                    'key'     => $meta_key,
                    'value'   => $distributor_ref,
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
     * Find by SKU (fallback)
     */
    public function find_by_sku($sku) {
        $product_id = wc_get_product_id_by_sku($sku);
        return $product_id ? $product_id : false;
    }
}
