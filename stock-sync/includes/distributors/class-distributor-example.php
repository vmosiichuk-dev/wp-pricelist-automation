<?php
/**
 * Example Distributor — Minimal concrete implementation
 * Copy this as a starting point for real distributors.
 */
class StockSync_Distributor_Example extends StockSync_Distributor {

    public function get_name() {
        return 'Example Corp';
    }

    public function get_slug() {
        return 'example';
    }

    public function get_header_row() {
        return 1;
    }

    public function get_column_map() {
        return [
            'distributor_ref' => 1,
            'ean'             => 2,
            'availability'    => 3,
            'product_name'    => 4,
            'vintage'         => 5,
        ];
    }

    public function is_product_row($row_data) {
        $ref = isset($row_data[1]) ? trim($row_data[1]) : '';
        return !empty($ref);
    }

    public function is_unavailable($value) {
        $flags = ['out', 'none', 'unavailable', '0'];
        return in_array(trim(strtolower($value)), $flags, true);
    }
}
