<?php
/**
 * Product Updater
 * Applies unavailable state: visibility, prices, excerpt.
 * Distributor-agnostic — same actions for all distributors.
 */
class StockSync_Product_Updater {

    private $logger;

    /**
     * Initialize the change logger.
     *
     * @return void
     */
    public function __construct() {
        $this->logger = new StockSync_Change_Logger();
    }

    /**
     * Mark a product as unavailable
     *
     * @param int $product_id
     * @param StockSync_Standard_Product $product
     * @param StockSync_Distributor $distributor
     * @return true|WP_Error
     */
    public function mark_unavailable($product_id, StockSync_Standard_Product $product, StockSync_Distributor $distributor) {
        $wc_product = wc_get_product($product_id);

        if (!$wc_product) {
            return new WP_Error('product_not_found', __('Product not found: ', 'stock-sync') . $product_id);
        }

        $old_visibility = $wc_product->get_catalog_visibility();
        $old_excerpt    = $wc_product->get_short_description();
        $old_price      = $wc_product->get_regular_price();
        $old_sale_price = $wc_product->get_sale_price();

        // 1. Visibility: search only
        $wc_product->set_catalog_visibility('search');

        // 2. Update short description (distributor can override text)
        $new_excerpt = wp_kses_post($distributor->get_unavailable_description($product->product_name));
        $wc_product->set_short_description($new_excerpt);

        // 3. Remove prices
        $wc_product->set_regular_price('');
        $wc_product->set_sale_price('');

        // 4. Save
        $wc_product->save();

        // 5. Log
        $this->logger->log([
            'product_id'       => $product_id,
            'sku'              => $wc_product->get_sku(),
            'action'           => 'marked_unavailable',
            'old_visibility'   => $old_visibility,
            'new_visibility'   => 'search',
            'old_excerpt'      => $old_excerpt,
            'new_excerpt'      => $new_excerpt,
            'old_price'        => $old_price,
            'old_sale_price'   => $old_sale_price,
            'distributor_slug' => $product->distributor_slug,
            'distributor_ref'  => $product->distributor_ref,
        ]);

        return true;
    }
}
