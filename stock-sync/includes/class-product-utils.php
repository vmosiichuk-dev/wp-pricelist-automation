<?php
/**
 * Shared product utilities used by both preview and apply paths.
 */
class StockSync_Product_Utils {

    /**
     * Remove Polish price patterns from a product name.
     *
     * @param string $name
     * @return string
     */
    public static function clean_name($name) {
        // Remove trailing price: " - 108 zł.**"
        $name = preg_replace('/\s*-\s*\d+(?:,\d+)?\s*zł\.\*\*\s*$/iu', '', $name);
        // Remove leading price: "57,72 zł.** Dry Hills..."
        $name = preg_replace('/^\d+(?:,\d+)?\s*zł\.\*\*\s*/iu', '', $name);
        return trim($name);
    }

    /**
     * Build the new excerpt preserving the prefix before the first '>'.
     *
     * @param string $current_excerpt
     * @param string $product_name  Fallback prefix if no '>' is found.
     * @param string $suffix        Text to place after '>'.
     * @return string
     */
    public static function build_new_excerpt($current_excerpt, $product_name, $suffix) {
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
     * Get the first product category assigned to a product.
     *
     * @param int $product_id WooCommerce product ID.
     * @return array|false Array with 'url' and 'name' keys, or false if not found.
     */
    public static function find_first_product_category($product_id) {
        $terms = get_the_terms($product_id, 'product_cat');
        if (empty($terms) || is_wp_error($terms)) {
            return false;
        }

        foreach ($terms as $term) {
            $url = get_term_link($term, 'product_cat');
            if (!is_wp_error($url)) {
                return [
                    'url'  => $url,
                    'name' => $term->name,
                ];
            }
        }

        return false;
    }
}
