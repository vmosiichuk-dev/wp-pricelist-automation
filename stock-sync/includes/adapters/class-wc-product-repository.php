<?php
class StockSync_WC_Product_Repository implements Product_Repository_Interface {

    public function find_by_id($product_id) {
        return wc_get_product($product_id);
    }

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

    public function find_by_sku($sku) {
        $product_id = wc_get_product_id_by_sku($sku);
        return $product_id ? $product_id : false;
    }

    public function save($product) {
        $product->save();
    }
}
