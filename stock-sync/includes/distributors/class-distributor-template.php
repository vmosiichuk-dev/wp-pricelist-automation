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
     * Determine whether a parsed row represents a product row.
     *
     * Evaluates the value in column 1 (reference) and returns false for empty references
     * or for references made up only of letters and whitespace longer than five characters
     * (typical section/producer/header rows).
     *
     * @param array $row_data Row data array where keys are 1-based column indices.
     * @return bool `true` if the row appears to be a product row, `false` otherwise.
     */
    public function is_product_row($row_data) {
        $ref = isset($row_data[1]) ? trim($row_data[1]) : '';

        // Must have a value in the reference column.
        if (empty($ref)) {
            return false;
        }

        // Reject section headers (country/producer names) that are pure text and long.
        // This allows reference formats to change without breaking the sync.
        if (preg_match('/^[\p{L}\s]+$/u', $ref) && mb_strlen($ref) > 5) {
            return false;
        }

        return true;

        // Alternative examples for stricter matching:
        // return preg_match('/^[A-Z]{2}/', $ref); // Must start with two uppercase letters
        // return is_numeric($ref);                  // Numeric SKU only
        // return strlen($ref) >= 3;                 // Minimum length
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
     * Optional: Override the unavailable description suffix text
     * If not overridden, uses the default from StockSync_Distributor
     */
    public function get_unavailable_suffix($product_id = 0, $category_url = null) {
        return parent::get_unavailable_suffix($product_id, $category_url);
    }
}
