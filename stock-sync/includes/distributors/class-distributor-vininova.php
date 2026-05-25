<?php
/**
 * Vininova Distributor — Concrete Implementation
 */
class StockSync_Distributor_Vininova extends StockSync_Distributor {

    /**
     * Return the human-readable distributor name.
     *
     * @return string
     */
    public function get_name() {
        return 'Vininova';
    }

    /**
     * Return the machine-safe distributor slug.
     *
     * @return string
     */
    public function get_slug() {
        return 'vininova';
    }

    /**
     * Return the XLSX worksheet XML path inside the ZIP.
     *
     * @return string
     */
    public function get_sheet_name() {
        return 'xl/worksheets/sheet1.xml';
    }

    /**
     * Return the row number containing column headers.
     *
     * @return int
     */
    public function get_header_row() {
        return 10;
    }

    /**
     * Return the column mapping for standard fields.
     *
     * @return array
     */
    public function get_column_map() {
        return [
            'distributor_ref' => 1,  // Column A: NR_REF
            'ean'             => 2,  // Column B: KOD_KRESKOWY
            'availability'    => 3,  // Column C
            'product_name'    => 4,  // Column D
            'vintage'         => 5,  // Column E
        ];
    }

    /**
     * Determine if a parsed row represents a real product.
     *
     * @param array $row_data One-indexed row array from parser.
     * @return bool
     */
    public function is_product_row($row_data) {
        $nr_ref = isset($row_data[1]) ? $row_data[1] : '';
        return (bool) preg_match('/^[A-Z]{2}/', $nr_ref);
    }

    /**
     * Determine if an availability value means the product is unavailable.
     *
     * @param string $value Raw availability string.
     * @return bool
     */
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
