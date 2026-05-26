<?php
/**
 * Distributor Template — Copy and customize for each new distributor
 *
 * Steps:
 * 1. Rename class to StockSync_Distributor_{YourName}
 * 2. Implement all abstract methods
 * 3. Register in includes/class-plugin.php
 */
class StockSync_Distributor_Template extends StockSync_Distributor {

    /**
     * Human-readable distributor name (shown in dropdown)
     */
    public function get_name() {
        return 'Template Distributor';
    }

    /**
     * Machine-safe slug (lowercase, no spaces, no special chars)
     * Used for meta keys: _supplier_ref_{slug}
     */
    public function get_slug() {
        return 'template';
    }

    /**
     * XLSX worksheet XML path inside the ZIP
     * Default is sheet1. Change if data is on a different sheet.
     */
    public function get_sheet_name() {
        return 'xl/worksheets/sheet1.xml';
    }

    /**
     * Row number that contains column headers
     * Everything before this row is skipped.
     */
    public function get_header_row() {
        return 1; // Adjust based on file structure
    }

    /**
     * Column map: standard_field => column_index (1-based)
     *
     * Standard fields:
     *   distributor_ref — supplier's internal reference code (used for matching)
     *   ean             — barcode/EAN
     *   availability    — raw availability string from XLSX
     *   product_name    — product name for fuzzy matching
     *   vintage         — vintage/year (optional)
     */
    public function get_column_map() {
        return [
            'distributor_ref' => 1,  // Column A
            'ean'             => 2,  // Column B
            'availability'    => 3,  // Column C
            'product_name'    => 4,  // Column D
            'vintage'         => 5,  // Column E
        ];
    }

    /**
     * Determine if a parsed row represents a real product
     *
     * Return false for:
     *   - Region/producer headers
     *   - Empty rows
     *   - Totals/summary rows
     *
     * @param array $row_data Zero-indexed row array from parser (keys are 1-based column indices)
     * @return bool
     */
    public function is_product_row($row_data) {
        $ref = isset($row_data[1]) ? trim($row_data[1]) : '';

        // Example: require non-empty reference starting with letters
        return !empty($ref) && preg_match('/^[A-Z]{2}/', $ref);

        // Alternative examples:
        // return !empty($ref); // Any non-empty first column
        // return is_numeric($ref); // Numeric SKU
        // return strlen($ref) >= 3; // Minimum length
    }

    /**
     * Determine if an availability value means the product is unavailable
     *
     * @param string $value Raw availability string from XLSX
     * @return bool
     */
    public function is_unavailable($value) {
        $value = trim(strtolower($value));

        $unavailable_flags = [
            'out',
            'none',
            'unavailable',
            '0',
            'brak',           // Polish
            'chwilowy brak',  // Polish
            'os',             // Polish: ostatnie sztuki
        ];

        return in_array($value, $unavailable_flags, true);
    }

    /**
     * Optional: Override the unavailable description text
     * If not overridden, uses the default from StockSync_Distributor
     */
    public function get_unavailable_description($product_name) {
        return parent::get_unavailable_description($product_name);
    }
}
