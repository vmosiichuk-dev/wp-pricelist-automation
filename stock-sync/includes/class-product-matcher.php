<?php
/**
 * Product Matcher
 * Looks up WooCommerce products by distributor-specific meta key.
 */
class StockSync_Product_Matcher {
    private $repository;

    /**
     * Store the product repository used for SKU-based product lookups.
     *
     * @param Product_Repository_Interface $repository Repository used to resolve product IDs by SKU.
     */
    public function __construct(Product_Repository_Interface $repository) {
        $this->repository = $repository;
    }

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
     * Resolve a product ID from a SKU using the configured product repository.
     *
     * @param string $sku The product SKU to look up.
     * @return int|false The product ID if a product with the given SKU exists, `false` otherwise.
     */
    public function find_by_sku($sku) {
        return $this->repository->find_by_sku($sku);
    }
}
