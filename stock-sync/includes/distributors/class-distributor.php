<?php
/**
 * Abstract Distributor
 * All distributor implementations must extend this class.
 */
abstract class StockSync_Distributor {

	private $category_url_cache = null;

	/**
	 * Human-readable distributor name
	 */
	abstract public function get_name();

	/**
	 * Machine-safe slug (lowercase, no spaces)
	 */
	abstract public function get_slug();

	/**
	 * XLSX worksheet XML path inside the ZIP
	 */
	public function get_sheet_name() {
		return 'xl/worksheets/sheet1.xml';
	}

	/**
	 * Row number that contains column headers
	 */
	abstract public function get_header_row();

	/**
	 * Column map: standard_field => column_index (1-based)
	 * Required keys: distributor_ref, ean, availability, product_name, vintage
	 */
	abstract public function get_column_map();

	/**
	 * Determine if a parsed row represents a real product
	 *
	 * @param array $row_data One-indexed row array from parser
	 * @return bool
	 */
	abstract public function is_product_row($row_data);

	/**
	 * Determine if an availability value means the product is unavailable
	 *
	 * @param string $value Raw availability string from XLSX
	 * @return bool
	 */
	abstract public function is_unavailable($value);

	/**
	 * Get the meta key used to store supplier reference on WC products
	 */
	public function get_meta_key() {
		return '_supplier_ref_' . sanitize_key($this->get_slug());
	}

	/**
	 * Get the WooCommerce product category to filter by during bootstrap.
	 * Return null to match against all products.
	 *
	 * @return string|null Category name (exact match against product_cat taxonomy)
	 */
	public function get_category_filter() {
		return null;
	}

	/**
	 * Return the cached category URL for this distributor's wine category.
	 * Only performs the WordPress lookup once per distributor instance.
	 *
	 * @return string|false Category URL or false if not found.
	 */
	protected function get_category_url() {
		if ($this->category_url_cache !== null) {
			return $this->category_url_cache;
		}

		$category_name = 'Wina ' . $this->get_name();
		$term = get_term_by('name', $category_name, 'product_cat');

		if ($term && !is_wp_error($term)) {
			$url = get_term_link($term, 'product_cat');
			if (!is_wp_error($url)) {
				$this->category_url_cache = $url;
				return $url;
			}
		}

		$this->category_url_cache = false;
		return false;
	}

	/**
	 * Generate the suffix text used after the '>' in the unavailable short description.
	 *
	 * The link text is always "Wina {distributor_name}".
	 * When a product-specific category URL is provided (from the product's own terms),
	 * it is used directly. Otherwise falls back to the cached distributor-level category,
	 * and finally to a generic text without a link.
	 *
	 * @param int    $product_id     Unused, kept for future per-product overrides.
	 * @param string $category_url   Optional URL of the product's category.
	 * @return string
	 */
	public function get_unavailable_suffix($product_id = 0, $category_url = null) {
		$link_text = esc_html(__('Wina ' . $this->get_name(), 'stock-sync'));

		if ($category_url) {
			$link = '<a href="' . esc_url($category_url) . '">' . esc_html($link_text) . '</a>';
			return sprintf(
				/* translators: %s: link to category */
				__('Produkt wycofany z naszej oferty. Podobne produkty znajdziesz w kategorii \'%s\'. U nas zawsze znajdziesz produkt, którego szukasz. Zamów online!', 'stock-sync'),
				$link
			);
		}

		$category_url = $this->get_category_url();

		if ($category_url) {
			$link = '<a href="' . esc_url($category_url) . '">' . esc_html($link_text) . '</a>';
			return sprintf(
				/* translators: %s: link to category */
				__('Produkt wycofany z naszej oferty. Podobne produkty znajdziesz w kategorii \'%s\'. U nas zawsze znajdziesz produkt, którego szukasz. Zamów online!', 'stock-sync'),
				$link
			);
		}

		return __('Produkt wycofany z naszej oferty. Podobne produkty znajdziesz w naszej ofercie. Zamów online!', 'stock-sync');
	}
}
