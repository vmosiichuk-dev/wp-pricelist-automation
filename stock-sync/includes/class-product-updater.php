<?php
/**
 * Product Updater
 * Applies unavailable state: visibility, prices, excerpt, name.
 * Distributor-agnostic — same actions for all distributors.
 */
class StockSync_Product_Updater {

	private $logger;

	/**
	 * Create a product updater configured with the provided logger.
	 *
	 * @param Logger_Interface $logger Logger used to record product change events.
	 */
	public function __construct(Logger_Interface $logger) {
		$this->logger = $logger;
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
			return new WP_Error('product_not_found', sprintf(__('Product not found: %d', 'stock-sync'), $product_id));
		}

		$old_visibility = $wc_product->get_catalog_visibility();
		$old_excerpt    = $wc_product->get_short_description();
		$old_price      = $wc_product->get_regular_price();
		$old_sale_price = $wc_product->get_sale_price();
		$old_name       = $wc_product->get_name();

		// 1. Clean name and update
		$new_name = StockSync_Product_Utils::clean_name($old_name);
		if ($new_name !== $old_name) {
			$wc_product->set_name($new_name);
		}

		// 2. Build new excerpt preserving prefix, replacing suffix
		$cat_term    = StockSync_Product_Utils::find_first_product_category($product_id);
		$cat_url     = $cat_term ? $cat_term['url'] : null;
		$cat_name    = $cat_term ? $cat_term['name'] : null;
		$suffix      = wp_kses_post($distributor->get_unavailable_suffix($product_id, $cat_url, $cat_name));
		$new_excerpt = StockSync_Product_Utils::build_new_excerpt($old_excerpt, $new_name, $suffix);
		$wc_product->set_short_description($new_excerpt);

		// 3. Visibility: search only
		$wc_product->set_catalog_visibility('search');

		// 4. Remove prices
		$wc_product->set_regular_price('');
		$wc_product->set_sale_price('');

		// 5. Save
		$wc_product->save();

		// 6. Log
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
			'old_name'         => $old_name,
			'new_name'         => $new_name,
			'distributor_slug' => $product->distributor_slug,
			'distributor_ref'  => $product->distributor_ref,
		]);

		return true;
	}
}
