<?php
/**
 * Abstract Distributor
 * All distributor implementations must extend this class.
 */
abstract class StockSync_Distributor {

	private $category_url_cache = null;

	/**
	 * Custom header labels set from the UI before scanning.
	 *
	 * @var array
	 */
	private $custom_header_labels = [];

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
	 * Determine if an availability value is a known indicator (available, new, stock count, etc.)
	 * and should NOT be tracked as unrecognized.
	 *
	 * @param string $value Raw availability string from XLSX
	 * @return bool
	 */
	public function is_known_availability($value) {
		return false;
	}

	/**
	 * Get the meta key used to store supplier reference on WC products
	 */
	public function get_meta_key() {
		return '_supplier_ref_' . sanitize_key($this->get_slug());
	}

	/**
	 * Provide the product category name used to filter products during bootstrap.
	 *
	 * @return string|null Category name (exact match against the `product_cat` taxonomy), or `null` to match all products.
	 */
	public function get_category_filter() {
		return null;
	}

	/**
	 * Provide expected header column labels for XLSX header auto-detection.
	 *
	 * If the returned array is empty, auto-detection is disabled and get_header_row()
	 * will be used directly.
	 *
	 * @return string[] Array of expected header labels (empty to disable auto-detection).
	 */
	public function get_header_labels() {
		return [];
	}

	/**
	 * Set custom header labels to override get_header_labels() during scanning.
	 *
	 * @param array $labels Array of header label strings.
	 * @return void
	 */
	public function set_header_labels(array $labels) {
		$this->custom_header_labels = $labels;
	}

	/**
	 * Return the effective header labels for scanning.
	 * Uses custom labels when set via the UI, otherwise falls back to get_header_labels().
	 *
	 * @return array
	 */
	public function get_effective_header_labels() {
		if (!empty($this->custom_header_labels)) {
			return $this->custom_header_labels;
		}
		return $this->get_header_labels();
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
		$link_text = sprintf(__('Wina %s', 'stock-sync'), $this->get_name());

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
