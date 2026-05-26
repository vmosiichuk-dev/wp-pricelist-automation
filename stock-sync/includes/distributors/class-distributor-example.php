<?php
/**
 * Example Distributor — Minimal concrete implementation
 * Copy this as a starting point for real distributors.
 */
class StockSync_Distributor_Example extends StockSync_Distributor {

    /**
     * Return the human-readable distributor name.
     *
     * @return string
     */
    public function get_name() {
        return 'Example Corp';
    }

    /**
     * Return the machine-safe distributor slug.
     *
     * @return string
     */
    public function get_slug() {
        return 'example';
    }

    /**
     * Return the row number containing column headers.
     *
     * @return int
     */
    public function get_header_row() {
        return 1;
    }

    /**
     * Return the column mapping for standard fields.
     *
     * @return array
     */
    public function get_column_map() {
        return [
            'distributor_ref' => 1,
            'ean'             => 2,
            'availability'    => 3,
            'product_name'    => 4,
            'vintage'         => 5,
        ];
    }

    /**
     * Determine if a parsed row represents a real product.
     *
     * @param array $row_data One-indexed row array from parser.
     * @return bool
     */
    public function is_product_row($row_data) {
        $ref = isset($row_data[1]) ? trim($row_data[1]) : '';
        return !empty($ref);
    }

    /**
     * Determine if an availability value means the product is unavailable.
     *
     * @param string $value Raw availability string.
     * @return bool
     */
    public function is_unavailable($value) {
        $flags = ['out', 'none', 'unavailable', '0'];
        return in_array(trim(strtolower($value)), $flags, true);
    }
}
