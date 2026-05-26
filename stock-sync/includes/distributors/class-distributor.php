<?php
/**
 * Abstract Distributor
 * All distributor implementations must extend this class.
 */
abstract class StockSync_Distributor {

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
     * Generate the unavailable short description text
     * Override per distributor if messaging should differ.
     *
     * @param string $product_name
     * @return string
     */
    public function get_unavailable_description($product_name) {
        $parts = explode(' - ', $product_name, 2);
        $brand = isset($parts[0]) ? $parts[0] : $product_name;

        return sprintf(
            '%s - Produkt wycofany z naszej oferty. Podobne produkty znajdziesz w naszej ofercie. Zamów online!',
            $brand
        );
    }
}
