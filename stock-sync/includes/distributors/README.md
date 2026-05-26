# Adding a New Distributor

## Quick Start (3 Steps)

### 1. Create a new class

**Option A: Copy the template**

Copy `class-distributor-template.php` and rename it:

```bash
cp class-distributor-template.php class-distributor-mycorp.php
```

Then edit the class name and implement the 6 required methods.

**Option B: Copy the minimal example**

```bash
cp class-distributor-example.php class-distributor-mycorp.php
```

### 2. Customize the class

```php
<?php
class StockSync_Distributor_MyCorp extends StockSync_Distributor {

    public function get_name() {
        return 'MyCorp Distribution';
    }

    public function get_slug() {
        return 'mycorp'; // Will create meta key: _supplier_ref_mycorp
    }

    public function get_header_row() {
        return 3; // Headers on row 3
    }

    public function get_column_map() {
        return [
            'distributor_ref' => 1,  // Column A: SKU
            'ean'             => 2,  // Column B: Barcode
            'availability'    => 5,  // Column E: Stock status
            'product_name'    => 3,  // Column C: Name
            'vintage'         => 4,  // Column D: Year
        ];
    }

    public function is_product_row($row_data) {
        // Skip empty rows and section headers
        $ref = isset($row_data[1]) ? trim($row_data[1]) : '';
        return !empty($ref) && is_numeric($ref);
    }

    public function is_unavailable($value) {
        $flags = ['out of stock', 'oos', '0'];
        return in_array(trim(strtolower($value)), $flags, true);
    }
}
```

### 3. Register it

Open `includes/class-plugin.php` and add to `register_distributors()`:

```php
$registry->register(new StockSync_Distributor_MyCorp());
```

Done. The admin dropdown and sync tabs will automatically recognize the new distributor.

---

## Reference: Required Methods

| Method | Returns | Description |
|--------|---------|-------------|
| `get_name()` | `string` | Human name shown in admin dropdown |
| `get_slug()` | `string` | Machine ID; used for meta keys and AJAX |
| `get_header_row()` | `int` | Which row has column headers |
| `get_column_map()` | `array` | Maps standard fields to XLSX column indices (1-based) |
| `is_product_row($row)` | `bool` | Does this row contain a real product? |
| `is_unavailable($value)` | `bool` | Is this availability string an out-of-stock flag? |

## Optional Overrides

| Method | Default | Override When... |
|--------|---------|-------------------|
| `get_sheet_name()` | `xl/worksheets/sheet1.xml` | Data is on a different sheet |
| `get_unavailable_description($name)` | Generic text | You want custom messaging per distributor |
| `get_category_filter()` | `null` (no filter) | You want to limit bootstrap matching to a specific WC product category |

## Testing a New Distributor

1. Upload an XLSX via **Bootstrap Mapping** tab
2. Check that product rows are correctly identified
3. Review the match confidence scores
4. Run a **Preview sync** before applying changes
5. Verify in WooCommerce that visibility + prices + excerpt are updated
