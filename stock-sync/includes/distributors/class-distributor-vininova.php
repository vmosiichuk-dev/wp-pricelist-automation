<?php
/**
 * Vininova Distributor — Concrete Implementation
 */
class StockSync_Distributor_Vininova extends StockSync_Distributor {

    public function get_name() {
        return 'Vininova';
    }

    public function get_slug() {
        return 'vininova';
    }

    public function get_sheet_name() {
        return 'xl/worksheets/sheet1.xml';
    }

    public function get_header_row() {
        return 10;
    }

    public function get_column_map() {
        return [
            'distributor_ref' => 1,  // Column A: NR_REF
            'ean'             => 2,  // Column B: KOD_KRESKOWY
            'availability'    => 3,  // Column C
            'product_name'    => 4,  // Column D
            'vintage'         => 5,  // Column E
        ];
    }

    public function is_product_row($row_data) {
        $nr_ref = isset($row_data[1]) ? $row_data[1] : '';
        return (bool) preg_match('/^[A-Z]{2}/', $nr_ref);
    }

    public function is_unavailable($value) {
        $flags = [
            'brak',
            'chwilowy brak',
            'chilowy brak',
            'os',
            'wkrótce',
        ];
        $normalized = mb_strtolower(trim($value));
        return in_array($normalized, $flags, true);
    }

    /**
     * Bootstrap only against Vininova wine category
     */
    public function get_category_filter() {
        return 'A - Oferta Vininova';
    }
}
