<?php
/**
 * Product Updater
 * Applies unavailable state: visibility, prices, excerpt, name, slug.
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
			return new WP_Error('product_not_found', __('Product not found: ', 'stock-sync') . $product_id);
		}

		$old_visibility = $wc_product->get_catalog_visibility();
		$old_excerpt    = $wc_product->get_short_description();
		$old_price      = $wc_product->get_regular_price();
		$old_sale_price = $wc_product->get_sale_price();
		$old_name       = $wc_product->get_name();
		$old_slug       = $wc_product->get_slug();

		// 1. Clean name and update
		$new_name = $this->clean_name($old_name);
		if ($new_name !== $old_name) {
			$wc_product->set_name($new_name);
		}

		// 2. Build new excerpt preserving prefix, replacing suffix
		$cat_url     = $this->find_product_category_url($product_id);
		$suffix      = wp_kses_post($distributor->get_unavailable_suffix($product_id, $cat_url));
		$new_excerpt = $this->build_new_excerpt($old_excerpt, $new_name, $suffix);
		$wc_product->set_short_description($new_excerpt);

		// 3. Visibility: search only
		$wc_product->set_catalog_visibility('search');

		// 4. Remove prices
		$wc_product->set_regular_price('');
		$wc_product->set_sale_price('');

		// 5. Save (must happen before slug update so save() doesn't overwrite post_name)
		$wc_product->save();

		// 6. Update slug AFTER save to prevent WooCommerce from reverting it
		$new_slug = sanitize_title($new_name);
		if ($new_slug !== $old_slug) {
			wp_update_post([
				'ID'        => $product_id,
				'post_name' => $new_slug,
			]);
		}

		// 7. Log
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
			'old_slug'         => $old_slug,
			'new_slug'         => $new_slug,
			'distributor_slug' => $product->distributor_slug,
			'distributor_ref'  => $product->distributor_ref,
		]);

		return true;
	}

	/**
	 * Remove Polish price patterns from a product name.
	 *
	 * @param string $name
	 * @return string
	 */
	private function clean_name($name) {
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
	private function build_new_excerpt($current_excerpt, $product_name, $suffix) {
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
