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
	 * Custom price column header label set from the UI.
	 *
	 * @var string
	 */
	private $custom_price_header_label = '';

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
	 * Get the WooCommerce product category to filter by during bootstrap.
	 * Return null to match against all products.
	 *
	 * @return string|null Category name (exact match against product_cat taxonomy)
	 */
	public function get_category_filter() {
		return null;
	}

	/**
	 * Return expected header column labels for auto-detection.
	 * Return empty array to disable auto-detection and use get_header_row() directly.
	 *
	 * @return array
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
	 * Return the price column header labels for auto-detection.
	 * Return empty array if the distributor file does not contain prices.
	 *
	 * @return array
	 */
	public function get_price_header_labels() {
		return [];
	}

	/**
	 * Set a custom price column header label from the UI.
	 *
	 * @param string $label
	 * @return void
	 */
	public function set_price_header_label($label) {
		$this->custom_price_header_label = sanitize_text_field($label);
	}

	/**
	 * Return the effective price header labels.
	 *
	 * @return array
	 */
	public function get_effective_price_header_labels() {
		if (!empty($this->custom_price_header_label)) {
			return [$this->custom_price_header_label];
		}
		return $this->get_price_header_labels();
	}

	/**
	 * Return the default markup percentage for price calculation.
	 *
	 * @return float
	 */
	public function get_default_markup() {
		return 25.0;
	}

	/**
	 * Generate the suffix text for published (listed) products.
	 *
	 * @param string $product_name      Clean product name.
	 * @param string $distributor_name  Distributor human-readable name.
	 * @return string
	 */
	public function get_listed_suffix($product_name, $distributor_name) {
		return __(
			'Przygotuj karton z 6 butelkami swoich ulubionych win, sam zdecyduj, które wina dodać i ile butelek każdego z nich. Nie szukaj dalej, mamy najlepsze ceny w Polsce!',
			'stock-sync'
		);
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
	 * When a product-specific category URL and name are provided (from the product's own terms),
	 * the link text is "Wina {distributor_name}" only if the category name starts with "A - ",
	 * otherwise the category name is used as-is.
	 * Otherwise falls back to the cached distributor-level category,
	 * and finally to a generic text without a link.
	 *
	 * @param int    $product_id      Unused, kept for future per-product overrides.
	 * @param string $category_url    Optional URL of the product's category.
	 * @param string $category_name   Optional name of the product's category.
	 * @return string
	 */
	public function get_unavailable_suffix($product_id = 0, $category_url = null, $category_name = null) {
		if ($category_url && $category_name) {
			$link_text = (strpos($category_name, 'A - ') === 0)
				? sprintf(__('Wina %s', 'stock-sync'), $this->get_name())
				: $category_name;
			$link = '<a href="' . esc_url($category_url) . '">' . esc_html($link_text) . '</a>';
			return sprintf(
				/* translators: %s: link to category */
				__('Produkt wycofany z naszej oferty. Podobne produkty znajdziesz w kategorii %s. U nas zawsze znajdziesz produkt, którego szukasz. Zamów online!', 'stock-sync'),
				$link
			);
		}

		$category_url = $this->get_category_url();

		if ($category_url) {
			$link_text = sprintf(__('Wina %s', 'stock-sync'), $this->get_name());
			$link = '<a href="' . esc_url($category_url) . '">' . esc_html($link_text) . '</a>';
			return sprintf(
				/* translators: %s: link to category */
				__('Produkt wycofany z naszej oferty. Podobne produkty znajdziesz w kategorii %s. U nas zawsze znajdziesz produkt, którego szukasz. Zamów online!', 'stock-sync'),
				$link
			);
		}

		return __('Produkt wycofany z naszej oferty. Podobne produkty znajdziesz w naszej ofercie. Zamów online!', 'stock-sync');
	}
}
