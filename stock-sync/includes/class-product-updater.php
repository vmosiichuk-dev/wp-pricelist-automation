<?php
/**
 * Product Updater
 * Applies unavailable state: visibility, prices, excerpt, name.
 * Distributor-agnostic — same actions for all distributors.
 */
class StockSync_Product_Updater {

	/**
	 * Create a product updater.
	 */
	public function __construct() {
	}

	/**
	 * Mark a product as unavailable
	 *
	 * @param int $product_id
	 * @param StockSync_Standard_Product $product
	 * @param StockSync_Distributor $distributor
	 * @return true|WP_Error
	 */
	/**
	 * Mark a product as published (available for sale)
	 *
	 * @param int $product_id
	 * @param StockSync_Standard_Product $product
	 * @param StockSync_Distributor $distributor
	 * @return true|WP_Error
	 */
	public function mark_published($product_id, StockSync_Standard_Product $product, StockSync_Distributor $distributor) {
		$wc_product = wc_get_product($product_id);

		if (!$wc_product) {
			return new WP_Error('product_not_found', sprintf(__('Product not found: %d', 'stock-sync'), $product_id));
		}

		// Skip drafts
		if ($wc_product->get_status() === 'draft') {
			return new WP_Error('product_is_draft', sprintf(__('Product is a draft and cannot be published: %d', 'stock-sync'), $product_id));
		}

		$old_name = $wc_product->get_name();
		$new_name = StockSync_Product_Utils::clean_name($old_name);

		// 1. Clean name
		if ($new_name !== $old_name) {
			$wc_product->set_name($new_name);
			// Update Yoast SEO title if it contains the old name
			$yoast_title = get_post_meta($product_id, '_yoast_wpseo_title', true);
			if ($yoast_title && strpos($yoast_title, $old_name) !== false) {
				update_post_meta($product_id, '_yoast_wpseo_title', str_replace($old_name, $new_name, $yoast_title));
			}
		}

		// 2. Set prices
		$final_price = $product->price;
		if ($final_price !== null && $final_price > 0) {
			$wc_product->set_regular_price(number_format($final_price, 2, '.', ''));
		}
		if ($product->sale_price !== null && $product->sale_price > 0) {
			$wc_product->set_sale_price(number_format($product->sale_price, 2, '.', ''));
		}

		// 3. Update excerpt
		$suffix = wp_kses_post($distributor->get_listed_suffix($new_name, $distributor->get_name()));
		$new_excerpt = StockSync_Product_Utils::build_new_excerpt($wc_product->get_short_description(), $new_name, $suffix);
		$wc_product->set_short_description($new_excerpt);

		// 4. Visibility: catalog and search
		$wc_product->set_catalog_visibility('visible');

		// 5. Save
		$wc_product->save();

		return true;
	}

	public function mark_unavailable($product_id, StockSync_Standard_Product $product, StockSync_Distributor $distributor) {
		$wc_product = wc_get_product($product_id);

		if (!$wc_product) {
			return new WP_Error('product_not_found', sprintf(__('Product not found: %d', 'stock-sync'), $product_id));
		}

		// Skip drafts
		if ($wc_product->get_status() === 'draft') {
			return new WP_Error('product_is_draft', sprintf(__('Product is a draft and cannot be delisted: %d', 'stock-sync'), $product_id));
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
			// Update Yoast SEO title if it contains the old name
			$yoast_title = get_post_meta($product_id, '_yoast_wpseo_title', true);
			if ($yoast_title && strpos($yoast_title, $old_name) !== false) {
				update_post_meta($product_id, '_yoast_wpseo_title', str_replace($old_name, $new_name, $yoast_title));
			}
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

		return true;
	}
}
