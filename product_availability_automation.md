
# AUTOMATING WOOCOMMERCE PRODUCT UPDATES FROM XLSX PRICE LISTS
## Deep Research & Implementation Guide

---

## EXECUTIVE SUMMARY

Based on analysis of your Vininova WINO price list (1,410 wine products), I've identified
64 products with explicit availability issues (brak/chwilowy brak/OS) out of 243 flagged items.
The core challenge is the SKU mismatch: your WP uses custom SKUs (e.g., VN0095) while the
Excel uses supplier reference codes (e.g., FR22003).

---

## PART 1: CRITICAL PREPARATION STEPS

### 1.1 SKU Mapping Strategy (THE HARDEST PART)

Your Excel file contains:
- NR REF (e.g., FR22003) - supplier internal code
- KOD KRESKOWY (e.g., 3332418000899) - EAN/Barcode
- Product Name (e.g., "La Pierrelee Chablis AOC")
- Vintage (e.g., 2020)

Your WordPress uses:
- Custom SKU (e.g., VN0095)

**Options for mapping:**

| Approach | Pros | Cons | Recommendation |
|----------|------|------|----------------|
| A. Add supplier NR_REF as custom meta | One-time setup, reliable | Requires updating all products | **BEST** |
| B. Match by EAN barcode | Precise if EANs are in WP | EANs may not be stored in WP | Good if EANs exist |
| C. Fuzzy match by name+vintage | No product changes needed | Risk of false matches | Risky for 1000+ products |
| D. Manual mapping table | 100% accurate | Time-consuming initially | Most reliable |

**RECOMMENDED: Approach A + D hybrid**
1. Add a custom field `_supplier_ref` to all WooCommerce products
2. Populate it with the NR_REF from Excel (one-time bulk operation)
3. Future updates use this field for matching

### 1.2 Understanding Availability Flags in Your Data

From your Excel analysis:

| Flag | Count | Meaning | Action in WP |
|------|-------|---------|--------------|
| `brak` / `brak ` | 3 | Out of stock | Visibility -> "Szukaj", Update short description |
| `chwilowy brak` / `chilowy brak` | 6 | Temporarily out | Same as above |
| `OS` / `OS ` | 55 | Last pieces (ostatnie sztuki) | Same as above |
| `wkrótce` | 3 | Coming soon | Could keep visible or hide |
| `nowość` | 243 | New product | Keep visible |
| Numeric (7, 11, 107, etc.) | 911 | Catalog page number | In stock, no action |
| Empty | 181 | Region headers or in-stock | No action |

**Total products requiring action: ~64** (brak/chwilowy brak/OS)

### 1.3 What "Visibility: Szukaj" Actually Means in WooCommerce

In WooCommerce, catalog visibility is controlled via the `product_visibility` taxonomy:

| Visibility Setting | Taxonomy Terms | Effect |
|-------------------|----------------|--------|
| **Sklep i wyniki wyszukiwania** (visible) | none | Appears everywhere |
| **Sklep** (catalog) | `exclude-from-search` | Only in shop, not search |
| **Wyniki wyszukiwania** (search) | `exclude-from-catalog` | Only in search, not shop |
| **Ukryty** (hidden) | `exclude-from-catalog`, `exclude-from-search` | Nowhere |

**Your requirement: "Szukaj" = Search only**
This corresponds to: `exclude-from-catalog` (remove from shop, keep in search)

---

## PART 2: TECHNICAL IMPLEMENTATION

### 2.1 Architecture Overview

```
Admin User -> WP Admin Page (Custom Plugin) -> File Upload (XLSX/CSV)
                                                    |
                                                    v
                                            Parse XLSX
                                            - Read NR_REF
                                            - Check avail.
                                            - Extract name
                                                    |
                                                    v
                                            Match to WP
                                            - By _supplier_ref
                                            - Or by SKU
                                                    |
                                                    v
                                            Update Products
                                            - Visibility
                                            - Short desc
                                            - Log changes
```

### 2.2 Plugin Structure (Custom WordPress Plugin)

```
wp-content/plugins/
└── stock-sync/
    ├── stock-sync.php          # Main plugin file
    ├── admin/
    │   ├── class-admin.php             # Admin page handler
    │   └── views/
    │       └── sync-page.php           # Admin UI template
    ├── includes/
    │   ├── class-xlsx-parser.php       # XLSX parsing logic
    │   ├── class-product-matcher.php   # SKU matching logic
    │   ├── class-product-updater.php   # WooCommerce update logic
    │   └── class-logger.php            # Change logging
    └── assets/
        ├── css/
        └── js/
```

### 2.3 Core PHP Implementation

#### A. XLSX Parsing (Without External Libraries)

Since you can't install Composer packages, use PHP's built-in ZipArchive + XML parsing:

```php
<?php
/**
 * Parse XLSX file using native PHP
 * XLSX is a ZIP containing XML files
 */
class StockSync_XLSX_Parser {

    private $file_path;
    private $sheet_data = [];

    public function __construct($file_path) {
        $this->file_path = $file_path;
    }

    /**
     * Parse the XLSX and extract product rows
     */
    public function parse() {
        $zip = new ZipArchive();
        if ($zip->open($this->file_path) !== true) {
            return new WP_Error('parse_error', 'Cannot open XLSX file');
        }

        // Read shared strings (all text values are stored here)
        $shared_strings = $this->get_shared_strings($zip);

        // Read the first worksheet
        $sheet_xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if (!$sheet_xml) {
            return new WP_Error('parse_error', 'Cannot read worksheet');
        }

        $xml = simplexml_load_string($sheet_xml);

        // Parse rows
        $row_index = 0;
        foreach ($xml->sheetData->row as $row) {
            $row_index++;
            $row_data = [];
            $col_index = 0;

            foreach ($row->c as $cell) {
                $col_index++;
                $cell_type = (string)$cell['t']; // 's' = shared string

                if ($cell_type === 's') {
                    $string_index = (int)$cell->v;
                    $value = isset($shared_strings[$string_index])
                        ? $shared_strings[$string_index]
                        : '';
                } else {
                    $value = isset($cell->v) ? (string)$cell->v : '';
                }

                $row_data[$col_index] = $this->clean_value($value);
            }

            $this->sheet_data[$row_index] = $row_data;
        }

        return $this->extract_products();
    }

    /**
     * Extract shared strings from XLSX
     */
    private function get_shared_strings($zip) {
        $strings_xml = $zip->getFromName('xl/sharedStrings.xml');
        if (!$strings_xml) return [];

        $xml = simplexml_load_string($strings_xml);
        $strings = [];

        foreach ($xml->si as $si) {
            if (isset($si->t)) {
                $strings[] = (string)$si->t;
            } elseif (isset($si->r)) {
                $text = '';
                foreach ($si->r as $run) {
                    $text .= (string)$run->t;
                }
                $strings[] = $text;
            }
        }

        return $strings;
    }

    /**
     * Clean cell value
     */
    private function clean_value($value) {
        return trim(preg_replace('/\s+/', ' ', $value));
    }

    /**
     * Extract actual wine products from parsed data
     */
    private function extract_products() {
        $products = [];
        $header_row = 10; // Row 10 contains headers in your file

        foreach ($this->sheet_data as $row_num => $row) {
            if ($row_num <= $header_row) continue;

            // Check if this is a product row (has NR_REF starting with letters)
            $nr_ref = isset($row[1]) ? $row[1] : ''; // Column A

            if (!preg_match('/^[A-Z]{2}/', $nr_ref)) {
                continue; // Skip region/producer headers
            }

            $availability = isset($row[3]) ? $row[3] : ''; // Column C
            $product_name = isset($row[4]) ? $row[4] : '';   // Column D
            $vintage = isset($row[5]) ? $row[5] : '';        // Column E
            $ean = isset($row[2]) ? $row[2] : '';            // Column B

            $products[] = [
                'nr_ref'       => $nr_ref,
                'ean'          => $ean,
                'availability' => $availability,
                'product_name' => $product_name,
                'vintage'      => $vintage,
                'row_num'      => $row_num
            ];
        }

        return $products;
    }
}
```

#### B. Product Matching Logic

```php
<?php
/**
 * Match Excel products to WooCommerce products
 */
class StockSync_Product_Matcher {

    /**
     * Find WooCommerce product by supplier reference
     */
    public function find_by_supplier_ref($nr_ref) {
        $args = [
            'post_type'      => 'product',
            'posts_per_page' => 1,
            'meta_query'     => [
                [
                    'key'     => '_supplier_ref',
                    'value'   => $nr_ref,
                    'compare' => '='
                ]
            ],
            'fields' => 'ids'
        ];

        $query = new WP_Query($args);

        if ($query->have_posts()) {
            return $query->posts[0];
        }

        return false;
    }

    /**
     * Find by SKU (fallback)
     */
    public function find_by_sku($sku) {
        $product_id = wc_get_product_id_by_sku($sku);
        return $product_id ? $product_id : false;
    }

    /**
     * Check if availability flag means "unavailable"
     */
    public function is_unavailable($availability) {
        $unavailable_flags = [
            'brak',
            'brak ',
            'chwilowy brak',
            'chilowy brak',
            'OS',
            'OS ',
            'wkrótce'
        ];

        return in_array(trim($availability), $unavailable_flags, true);
    }
}
```

#### C. Product Updater (Visibility + Short Description)

```php
<?php
/**
 * Update WooCommerce product visibility and short description
 */
class StockSync_Product_Updater {

    private $logger;

    public function __construct() {
        $this->logger = new StockSync_Change_Logger();
    }

    /**
     * Update product to "unavailable" state
     */
    public function mark_unavailable($product_id, $product_name) {
        $product = wc_get_product($product_id);

        if (!$product) {
            return new WP_Error('product_not_found', 'Product not found: ' . $product_id);
        }

        $old_visibility = $product->get_catalog_visibility();
        $old_excerpt = $product->get_short_description();

        // 1. Set visibility to "Search" only (Szukaj)
        $product->set_catalog_visibility('search');

        // 2. Update short description
        $new_excerpt = $this->generate_unavailable_description($product_name);
        $product->set_short_description($new_excerpt);

        // 3. Save changes
        $product->save();

        // 4. Log the change
        $this->logger->log([
            'product_id'      => $product_id,
            'sku'             => $product->get_sku(),
            'action'          => 'marked_unavailable',
            'old_visibility'  => $old_visibility,
            'new_visibility'  => 'search',
            'old_excerpt'     => $old_excerpt,
            'new_excerpt'     => $new_excerpt,
            'timestamp'       => current_time('mysql')
        ]);

        return true;
    }

    /**
     * Generate unavailable product description
     */
    private function generate_unavailable_description($product_name) {
        $parts = explode(' - ', $product_name, 2);
        $brand = isset($parts[0]) ? $parts[0] : $product_name;

        return sprintf(
            '%s - VININOVA > Produkt wycofany z naszej oferty. Podobne produkty znajdziesz w kategorii Steki Grill BBQ. U nas zawsze znajdziesz produkt, ktorego szukasz. Zamow online!',
            $brand
        );
    }
}
```

#### D. Admin Page Handler

```php
<?php
/**
 * Admin page for stock synchronization
 */
class StockSync_Admin {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_init', [$this, 'handle_form_submission']);
    }

    public function add_menu_page() {
        add_menu_page(
            'Stock Sync',
            'Stock Sync',
            'manage_woocommerce',
            'stock-sync',
            [$this, 'render_page'],
            'dashicons-update',
            58
        );
    }

    public function render_page() {
        include plugin_dir_path(__FILE__) . '../admin/views/sync-page.php';
    }

    public function handle_form_submission() {
        if (!isset($_POST['stock_sync_nonce'])) return;

        if (!wp_verify_nonce($_POST['stock_sync_nonce'], 'stock_sync_action')) {
            wp_die('Security check failed');
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_die('Permission denied');
        }

        if (!isset($_FILES['xlsx_file'])) {
            stock_sync', 'no_file', 'No file uploaded', 'error');
            return;
        }

        $uploaded_file = $_FILES['xlsx_file'];

        if ($uploaded_file['error'] !== UPLOAD_ERR_OK) {
            stock_sync', 'upload_error', 'Upload failed', 'error');
            return;
        }

        $this->process_file($uploaded_file['tmp_name']);
    }

    private function process_file($file_path) {
        $parser = new StockSync_XLSX_Parser($file_path);
        $products = $parser->parse();

        if (is_wp_error($products)) {
            stock_sync', 'parse_error', $products->get_error_message(), 'error');
            return;
        }

        $matcher = new StockSync_Product_Matcher();
        $updater = new StockSync_Product_Updater();

        $stats = [
            'total'       => count($products),
            'unavailable' => 0,
            'updated'     => 0,
            'not_found'   => 0,
            'errors'      => 0
        ];

        foreach ($products as $product) {
            if (!$matcher->is_unavailable($product['availability'])) {
                continue;
            }

            $stats['unavailable']++;

            $product_id = $matcher->find_by_supplier_ref($product['nr_ref']);

            if (!$product_id) {
                $stats['not_found']++;
                continue;
            }

            $result = $updater->mark_unavailable($product_id, $product['product_name']);

            if (is_wp_error($result)) {
                $stats['errors']++;
            } else {
                $stats['updated']++;
            }
        }

        set_transient('stock_sync_results', $stats, HOUR_IN_SECONDS);

        stock_sync', 'success',
            sprintf(
                'Sync complete! Processed %d products. Updated %d, not found %d, errors %d.',
                $stats['total'],
                $stats['updated'],
                $stats['not_found'],
                $stats['errors']
            ),
            'success'
        );
    }
}
```

---

## PART 3: CRITICAL IMPLEMENTATION CONSIDERATIONS

### 3.1 Performance at Scale (1000+ Products)

| Concern | Solution |
|---------|----------|
| Memory limits | Process in batches of 50-100 products |
| Timeout | Use AJAX processing or WP Cron |
| Database load | Use direct wpdb updates for bulk operations |
| Cache invalidation | Clear WooCommerce transients after updates |

### 3.2 Data Integrity & Safety

| Risk | Mitigation |
|------|------------|
| Wrong product updated | Require supplier_ref mapping, never match by name alone |
| Accidental mass updates | Preview mode: show what WILL change before applying |
| No undo | Log all changes with before/after values |
| File format changes | Validate expected columns before processing |

### 3.3 Alternative: WP-CLI Command (For Power Users)

```php
<?php
if (defined('WP_CLI') && WP_CLI) {
    class StockSync_CLI_Command extends WP_CLI_Command {

        public function sync($args, $assoc_args) {
            $file = $assoc_args['file'] ?? null;

            if (!$file || !file_exists($file)) {
                WP_CLI::error('Please provide a valid XLSX file path using --file=');
            }

            WP_CLI::log('Parsing XLSX file...');

            $parser = new StockSync_XLSX_Parser($file);
            $products = $parser->parse();

            if (is_wp_error($products)) {
                WP_CLI::error($products->get_error_message());
            }

            $matcher = new StockSync_Product_Matcher();
            $updater = new StockSync_Product_Updater();

            $progress = WP_CLI\Utils\make_progress_bar('Processing products', count($products));

            $updated = 0;
            $not_found = 0;

            foreach ($products as $product) {
                $progress->tick();

                if (!$matcher->is_unavailable($product['availability'])) {
                    continue;
                }

                $product_id = $matcher->find_by_supplier_ref($product['nr_ref']);

                if (!$product_id) {
                    $not_found++;
                    WP_CLI::warning("Not found: {$product['nr_ref']} - {$product['product_name']}");
                    continue;
                }

                $updater->mark_unavailable($product_id, $product['product_name']);
                $updated++;
            }

            $progress->finish();

            WP_CLI::success("Updated {$updated} products. {$not_found} not found.");
        }
    }

    WP_CLI::add_command('stock-sync', 'StockSync_CLI_Command');
}
```

---

## PART 4: MIGRATION PATH (RECOMMENDED SEQUENCE)

### Phase 1: Preparation (Week 1)
1. Backup your WordPress database
2. Install the custom plugin (basic structure)
3. Add `_supplier_ref` meta field to all products
4. Create mapping: Export WP products (SKU + name), match to Excel NR_REF
5. Populate `_supplier_ref` via bulk import or direct SQL

### Phase 2: Testing (Week 2)
1. Test on 5-10 products first
2. Verify visibility changes in WP Admin
3. Check frontend behavior (shop vs search)
4. Validate short description updates

### Phase 3: Production (Week 3)
1. Run first sync with preview mode
2. Review all proposed changes
3. Apply changes
4. Monitor for issues

### Phase 4: Automation (Week 4+)
1. Set up scheduled sync (if needed)
2. Add email notifications for results
3. Create dashboard for monitoring

---

## PART 5: COMPARISON WITH PLUGIN ALTERNATIVES

| Solution | Cost | Setup | Flexibility | Best For |
|----------|------|-------|-------------|----------|
| Custom Plugin (this guide) | Free (dev time) | Medium | Maximum | Your exact use case |
| WP All Import + WooCommerce Add-on | ~$200/year | Low | Medium | Standard imports |
| WP Sheet Editor | ~$80/year | Low | Low | Simple bulk edits |
| Make/Zapier + Google Sheets | ~$20/month | Low | Low | Simple automations |

**Recommendation**: Custom plugin is best because:
- Your SKU mapping is non-standard
- You need specific visibility logic ("Szukaj")
- You need custom short description generation
- You want full control without recurring costs

---

## PART 6: RISK ASSESSMENT

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Wrong product updated | Medium | High | Supplier ref mapping + preview mode |
| Plugin conflict | Low | Medium | Test on staging first |
| Timeout on large files | Medium | Medium | Batch processing + AJAX |
| File format changes | Medium | High | Version validation + alerts |
| SEO impact from hidden products | Low | Medium | Monitor search console |

---

## CONCLUSION

This automation is fully feasible with a custom WordPress plugin. The critical success factors are:

1. Accurate SKU mapping - invest time in the `_supplier_ref` setup
2. Preview mode - always show changes before applying
3. Logging - track every change for auditability
4. Batch processing - handle 1000+ products without timeouts
5. Validation - verify file format before processing

The implementation requires approximately 20-40 hours of development for a WordPress developer, or can be done incrementally starting with a simple proof-of-concept.

---

---

# PHASE 2: OPTIMIZED IMPLEMENTATION PLAN
## Product Availability Automation — Refined Architecture

> This section builds upon the original plan above, incorporating feedback and optimizing the approach for core-PHP execution, bootstrap name matching, and scalable architecture focused on disabling products first with price removal.

---

## 1. REFINED REQUIREMENTS

### 1.1 Functional Requirements

| ID | Requirement | Priority |
|----|-------------|----------|
| R1 | Parse XLSX distributor price lists using core PHP only | Must |
| R2 | Identify unavailable products via flags (`brak`, `chwilowy brak`, `OS`, `wkrótce`) | Must |
| R3 | Match XLSX products to WooCommerce products by supplier reference (`_supplier_ref`) | Must |
| R4 | **Bootstrap `_supplier_ref` via fuzzy name matching with manual verification** | Must |
| R5 | Set product visibility to "Search only" (`exclude-from-catalog`) | Must |
| R6 | **Remove regular price and sale price** when disabling | Must |
| R7 | Update short description with unavailable message | Must |
| R8 | Process in AJAX batches to avoid timeouts | Must |
| R9 | Dry-run / preview mode before applying changes | Must |
| R10 | Log all changes with before/after values | Must |
| R11 | Architecture extensible for future "restore on restock" | Should |

### 1.2 Constraints

- **Core PHP only** — no Composer, no external libraries (ZipArchive + SimpleXML for XLSX)
- **WordPress plugin** — standard `wp-content/plugins/` installation
- **Bootstrap by name** — one-time fuzzy match to populate `_supplier_ref`, then use refs forever
- **Disable-first focus** — architecture scalable for "restore" later but not implementing it now

---

## 2. OPTIMIZED ARCHITECTURE

### 2.1 Plugin File Structure

```
wp-content/plugins/
└── stock-sync/
    ├── stock-sync.php              # Main plugin: headers, activation hooks, class loader
    ├── includes/
    │   ├── class-plugin.php                  # Main plugin controller: registers hooks, coordinates modules
    │   ├── class-admin.php                   # Admin menu, page rendering, nonce verification
    │   ├── class-ajax-handler.php            # wp_ajax_* endpoints for batch processing
    │   ├── class-xlsx-parser.php             # Core-PHP XLSX reader (ZipArchive + XML)
    │   ├── class-product-matcher.php         # Matching: by _supplier_ref + fuzzy name bootstrap
    │   ├── class-bootstrap-matcher.php         # One-time fuzzy name matching for initial mapping
    │   ├── class-product-updater.php         # Apply visibility, price, excerpt changes
    │   ├── class-logger.php                  # Custom DB table for audit trail
    │   └── class-database.php                # Table creation, schema management
    ├── admin/
    │   └── views/
    │       ├── tab-bootstrap.php             # Bootstrap: upload XLSX, review fuzzy matches
    │       ├── tab-sync.php                  # Sync: upload XLSX, preview/apply, progress
    │       └── tab-log.php                   # Log: view/download recent sync history
    └── assets/
        ├── css/
        │   └── admin.css                     # Clean tables, progress bars, match confidence badges
        └── js/
            └── admin.js                      # AJAX batch loop, file upload, confirm buttons
```

### 2.2 Data Flow

```
┌─────────────────┐
│  WP Admin User  │
└────────┬────────┘
         │
    ┌────┴────┐
    │         │
┌───▼───┐   ┌─▼─────────────┐
│Bootstrap│   │    Sync Run   │
│  Tab    │   │    Tab        │
└───┬───┘   └─┬─────────────┘
    │         │
    ▼         ▼
┌─────────────────────────────┐
│  1. Upload XLSX             │
│  2. Parse (ZipArchive)      │
└─────────────┬───────────────┘
              │
    ┌─────────┴──────────┐
    ▼                    ▼
┌─────────────┐    ┌──────────────┐
│ Fuzzy Name  │    │ _supplier_ref│
│  Matching   │    │   Lookup     │
│ (bootstrap) │    │  (sync loop) │
└──────┬──────┘    └──────┬───────┘
       │                  │
       ▼                  ▼
┌─────────────────────────────┐
│  Review UI (admin table)    │
│  Confidence scoring +       │
│  check/uncheck + confirm    │
└─────────────┬───────────────┘
              │
              ▼
┌─────────────────────────────┐
│  Save _supplier_ref meta     │
│  to matched WC products      │
└─────────────┬───────────────┘
              │
              ▼
┌─────────────────────────────┐
│  Sync: Find unavailable     │
│  by flags → _supplier_ref   │
│  lookup → batch update      │
│  (visibility + prices +     │
│   excerpt)                  │
└─────────────┬───────────────┘
              │
              ▼
┌─────────────────────────────┐
│  Log changes to DB table    │
│  Show stats + download log  │
└─────────────────────────────┘
```

---

## 3. CORE IMPLEMENTATION CHANGES

### 3.1 Product Updater — Now Includes Price Removal

The original `StockSync_Product_Updater::mark_unavailable()` is extended to also clear prices. Old prices are **logged but not stored for automatic restore** (restore logic is future Phase 3).

```php
<?php
/**
 * Update WooCommerce product to unavailable state
 * Phase 2: visibility + excerpt + price removal
 */
class StockSync_Product_Updater {

    private $logger;

    public function __construct() {
        $this->logger = new StockSync_Change_Logger();
    }

    /**
     * Update product to "unavailable" state
     */
    public function mark_unavailable($product_id, $product_name) {
        $product = wc_get_product($product_id);

        if (!$product) {
            return new WP_Error('product_not_found', 'Product not found: ' . $product_id);
        }

        $old_visibility = $product->get_catalog_visibility();
        $old_excerpt    = $product->get_short_description();
        $old_price      = $product->get_regular_price();
        $old_sale_price = $product->get_sale_price();

        // 1. Set visibility to "Search" only (Szukaj = exclude-from-catalog)
        $product->set_catalog_visibility('search');

        // 2. Update short description
        $new_excerpt = $this->generate_unavailable_description($product_name);
        $product->set_short_description($new_excerpt);

        // 3. PHASE 2 ADDITION: Remove prices
        $product->set_regular_price('');
        $product->set_sale_price('');

        // 4. Save changes
        $product->save();

        // 5. Log the change
        $this->logger->log([
            'product_id'       => $product_id,
            'sku'              => $product->get_sku(),
            'action'           => 'marked_unavailable',
            'old_visibility'   => $old_visibility,
            'new_visibility'   => 'search',
            'old_excerpt'      => $old_excerpt,
            'new_excerpt'      => $new_excerpt,
            'old_price'        => $old_price,
            'old_sale_price'   => $old_sale_price,
            'timestamp'        => current_time('mysql')
        ]);

        return true;
    }

    /**
     * Generate unavailable product description
     */
    private function generate_unavailable_description($product_name) {
        $parts = explode(' - ', $product_name, 2);
        $brand = isset($parts[0]) ? $parts[0] : $product_name;

        return sprintf(
            '%s - VININOVA > Produkt wycofany z naszej oferty. Podobne produkty znajdziesz w kategorii Steki Grill BBQ. U nas zawsze znajdziesz produkt, ktorego szukasz. Zamow online!',
            $brand
        );
    }
}
```

### 3.2 Bootstrap Matcher — Fuzzy Name Matching (One-Time)

This new class runs once (or on-demand) to map XLSX names to WooCommerce products without pre-existing `_supplier_ref`.

```php
<?php
/**
 * One-time bootstrap: match XLSX products to WC products by fuzzy name
 */
class StockSync_Bootstrap_Matcher {

    /**
     * Get all WooCommerce product names + IDs
     */
    public function get_all_wc_products() {
        $args = [
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ];

        $query = new WP_Query($args);
        $products = [];

        foreach ($query->posts as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product) continue;

            $products[] = [
                'id'   => $product_id,
                'name' => $product->get_name(),
                'sku'  => $product->get_sku(),
            ];
        }

        return $products;
    }

    /**
     * Normalize a product name for comparison
     */
    public function normalize_name($name) {
        $name = strtolower($name);
        $name = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name); // Strip accents
        $name = preg_replace('/[^a-z0-9\s]/', '', $name);        // Remove special chars
        $name = preg_replace('/\s+/', ' ', $name);               // Collapse whitespace
        $name = trim($name);
        return $name;
    }

    /**
     * Calculate match confidence between two names
     * Returns 0-100 score
     */
    public function calculate_confidence($xlsx_name, $wc_name) {
        $norm_xlsx = $this->normalize_name($xlsx_name);
        $norm_wc   = $this->normalize_name($wc_name);

        // Tier 1: Exact normalized match
        if ($norm_xlsx === $norm_wc) {
            return 100;
        }

        // Tier 2: One contains the other
        if (strpos($norm_wc, $norm_xlsx) !== false || strpos($norm_xlsx, $norm_wc) !== false) {
            return 90;
        }

        // Tier 3: Jaccard word overlap
        $xlsx_words = array_filter(explode(' ', $norm_xlsx));
        $wc_words   = array_filter(explode(' ', $norm_wc));

        if (empty($xlsx_words) || empty($wc_words)) {
            return 0;
        }

        $intersection = count(array_intersect($xlsx_words, $wc_words));
        $union        = count(array_unique(array_merge($xlsx_words, $wc_words)));
        $jaccard      = $union > 0 ? ($intersection / $union) : 0;

        if ($jaccard >= 0.8) {
            return 80;
        }
        if ($jaccard >= 0.6) {
            return 60;
        }

        // Tier 4: Levenshtein distance (only for shorter names to save perf)
        if (strlen($norm_xlsx) < 100 && strlen($norm_wc) < 100) {
            $lev = levenshtein($norm_xlsx, $norm_wc);
            $max_len = max(strlen($norm_xlsx), strlen($norm_wc));
            if ($max_len > 0 && ($lev / $max_len) < 0.15) {
                return 70;
            }
        }

        return 0;
    }

    /**
     * Find best WC match for each XLSX product
     * Returns array of matches with confidence scores
     */
    public function match_all($xlsx_products, $wc_products) {
        $matches = [];

        foreach ($xlsx_products as $xlsx_product) {
            $best_match = null;
            $best_score = 0;

            foreach ($wc_products as $wc_product) {
                $score = $this->calculate_confidence(
                    $xlsx_product['product_name'],
                    $wc_product['name']
                );

                if ($score > $best_score) {
                    $best_score = $score;
                    $best_match = $wc_product;
                }
            }

            $matches[] = [
                'nr_ref'       => $xlsx_product['nr_ref'],
                'xlsx_name'    => $xlsx_product['product_name'],
                'wc_id'        => $best_match ? $best_match['id'] : null,
                'wc_name'      => $best_match ? $best_match['name'] : null,
                'wc_sku'       => $best_match ? $best_match['sku'] : null,
                'confidence'   => $best_score,
                'status'       => $this->get_status_from_confidence($best_score),
            ];
        }

        return $matches;
    }

    /**
     * Translate confidence score to status
     */
    private function get_status_from_confidence($score) {
        if ($score >= 95) return 'auto';
        if ($score >= 70) return 'suggest';
        return 'manual';
    }

    /**
     * Save confirmed matches as _supplier_ref meta
     */
    public function save_mappings($confirmed_matches) {
        $saved = 0;

        foreach ($confirmed_matches as $match) {
            if (empty($match['wc_id']) || empty($match['nr_ref'])) {
                continue;
            }

            update_post_meta($match['wc_id'], '_supplier_ref', sanitize_text_field($match['nr_ref']));
            $saved++;
        }

        return $saved;
    }
}
```

### 3.3 AJAX Batch Processing

Replaces the original synchronous loop to avoid timeouts on 1,400 products.

```php
<?php
/**
 * Handle AJAX batch requests for sync processing
 */
class StockSync_AJAX_Handler {

    public function __construct() {
        add_action('wp_ajax_stock_sync_batch', [$this, 'process_batch']);
        add_action('wp_ajax_stock_sync_init', [$this, 'init_sync']);
    }

    /**
     * Initialize sync: parse XLSX, store in transient, return stats
     */
    public function init_sync() {
        check_ajax_referer('stock_sync_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied');
        }

        $file_path = sanitize_text_field($_POST['file_path'] ?? '');
        $dry_run   = !empty($_POST['dry_run']);

        if (!file_exists($file_path)) {
            wp_send_json_error('File not found');
        }

        $parser   = new StockSync_XLSX_Parser($file_path);
        $products = $parser->parse();

        if (is_wp_error($products)) {
            wp_send_json_error($products->get_error_message());
        }

        // Filter only unavailable
        $matcher     = new StockSync_Product_Matcher();
        $to_process  = [];

        foreach ($products as $product) {
            if ($matcher->is_unavailable($product['availability'])) {
                $to_process[] = $product;
            }
        }

        // Store in transient for batch retrieval
        set_transient('stock_sync_queue', $to_process, HOUR_IN_SECONDS);

        wp_send_json_success([
            'total_batches' => ceil(count($to_process) / 50),
            'total_items'   => count($to_process),
            'dry_run'       => $dry_run,
        ]);
    }

    /**
     * Process one batch of 50 products
     */
    public function process_batch() {
        check_ajax_referer('stock_sync_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied');
        }

        $queue   = get_transient('stock_sync_queue');
        $dry_run = !empty($_POST['dry_run']);
        $offset  = intval($_POST['offset'] ?? 0);
        $limit   = 50;

        if (!is_array($queue)) {
            wp_send_json_error('No sync queue found');
        }

        $batch     = array_slice($queue, $offset, $limit);
        $matcher   = new StockSync_Product_Matcher();
        $updater   = new StockSync_Product_Updater();
        $results   = [
            'processed' => 0,
            'updated'   => 0,
            'not_found' => 0,
            'errors'    => 0,
            'dry_run'   => $dry_run,
            'details'   => [],
        ];

        foreach ($batch as $product) {
            $results['processed']++;

            $product_id = $matcher->find_by_supplier_ref($product['nr_ref']);

            if (!$product_id) {
                $results['not_found']++;
                $results['details'][] = [
                    'nr_ref' => $product['nr_ref'],
                    'name'   => $product['product_name'],
                    'status' => 'not_found',
                ];
                continue;
            }

            if ($dry_run) {
                $results['updated']++;
                $results['details'][] = [
                    'nr_ref'     => $product['nr_ref'],
                    'name'       => $product['product_name'],
                    'status'     => 'would_update',
                    'product_id' => $product_id,
                ];
                continue;
            }

            $result = $updater->mark_unavailable($product_id, $product['product_name']);

            if (is_wp_error($result)) {
                $results['errors']++;
                $results['details'][] = [
                    'nr_ref' => $product['nr_ref'],
                    'name'   => $product['product_name'],
                    'status' => 'error',
                    'error'  => $result->get_error_message(),
                ];
            } else {
                $results['updated']++;
                $results['details'][] = [
                    'nr_ref'     => $product['nr_ref'],
                    'name'       => $product['product_name'],
                    'status'     => 'updated',
                    'product_id' => $product_id,
                ];
            }
        }

        wp_send_json_success($results);
    }
}
```

---

## 4. WP ADMIN UI SPECIFICATION

### 4.1 Menu Location

```php
add_menu_page(
    'Stock Sync',
    'Stock Sync',
    'manage_woocommerce',
    'stock-sync',
    [$this, 'render_page'],
    'dashicons-update',
    58
);
```

### 4.2 Tab 1: Bootstrap Mapping

**Purpose**: One-time operation to populate `_supplier_ref` using fuzzy name matching.

**Flow**:
1. Admin uploads XLSX → plugin parses and extracts names + NR_REF
2. Plugin exports all WC product names
3. Plugin runs `StockSync_Bootstrap_Matcher::match_all()`
4. Results displayed in a sortable admin table:

| NR_REF | XLSX Name | Matched WC Product | Confidence | Status | Action |
|--------|-----------|-------------------|------------|--------|--------|
| FR22003 | La Pierrelee Chablis | La Pierrelee Chablis 2020 | 90 | Suggest | [x] |
| FR22004 | Chateau Margaux 2015 | Chateau Margaux | 95 | Auto | [x] |
| FR22005 | Unknown Brand | — | 0 | Manual | — |

**Status badges**:
- `auto` (green) — score >= 95, pre-checked
- `suggest` (yellow) — score 70-94, unchecked, requires admin tick
- `manual` (red) — score < 70, no match suggested

**Action**: Admin checks desired matches → clicks "Confirm & Save Mappings" → plugin runs `save_mappings()`.

### 4.3 Tab 2: Sync Products

**Purpose**: Recurring use to mark unavailable products based on latest XLSX.

**Flow**:
1. Upload latest XLSX
2. Select mode: Preview (dry-run) or Apply
3. Click "Start Sync"
4. JS sends `stock_sync_init` AJAX → gets batch count
5. JS loops: `stock_sync_batch` for each batch of 50
6. Progress bar updates in real time
7. Final summary displayed:

```
Sync Complete
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Total unavailable in XLSX: 64
Products updated:          52
Not found (no mapping):    10
Errors:                     2
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
[Download Detailed Log]
```

### 4.4 Tab 3: Sync Log

Displays recent operations from the custom log table:

| Date | User | Total | Updated | Not Found | Errors | Actions |
|------|------|-------|---------|-----------|--------|---------|
| 2024-05-23 10:00 | admin | 64 | 52 | 10 | 2 | [Details] [CSV] |
| 2024-05-16 09:30 | admin | 71 | 60 | 8 | 3 | [Details] [CSV] |

---

## 5. TEST SINGLE PRODUCT (SAFETY FEATURE)

### 5.1 Purpose

Before running a full sync on 1,000+ products, admins can test the unavailable-state update on a **single product** to verify the plugin behaves correctly. This prevents accidental bulk mistakes.

### 5.2 How It Works

```
Admin opens "Test Product" tab
    │
    ▼
┌─────────────────────────────┐
│  1. Search by name or SKU     │
│     (filtered by distributor  │
│      category if configured)  │
└────────┬──────────────────────┘
         │
         ▼
┌─────────────────────────────┐
│  2. Select product from     │
│     dropdown results        │
└────────┬──────────────────────┘
         │
         ▼
┌─────────────────────────────┐
│  3. Review Before/After   │
│     table showing:          │
│     - visibility            │
│     - regular price         │
│     - sale price            │
│     - short description     │
└────────┬──────────────────────┘
         │
         ▼
┌─────────────────────────────┐
│  4. Click "Apply" → one    │
│     product updated, same  │
│     logging as batch sync  │
└─────────────────────────────┘
```

### 5.3 AJAX Endpoints

| Endpoint | Input | Output |
|----------|-------|--------|
| `stock_sync_test_search` | `q` (search string), `distributor_slug` | Array of matching products (id, name, sku) |
| `stock_sync_test_get_product` | `product_id`, `distributor_slug` | Current state + new_excerpt preview |
| `stock_sync_test_apply` | `product_id`, `distributor_slug` | Success confirmation + refreshed state |

### 5.4 UI Components

- **Search field**: Debounced/live search against product names and SKUs
- **Results dropdown**: Click to select
- **Before/After table**: Side-by-side comparison of all affected fields
- **Apply button**: Confirms with `window.confirm()` before executing

---

## 5. DATABASE SCHEMA

### 5.1 Custom Log Table

```sql
CREATE TABLE {$wpdb->prefix}stock_sync_log (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    product_id bigint(20) unsigned NOT NULL,
    sku varchar(100) DEFAULT NULL,
    action varchar(50) NOT NULL,
    old_visibility varchar(20) DEFAULT NULL,
    new_visibility varchar(20) DEFAULT NULL,
    old_excerpt text DEFAULT NULL,
    new_excerpt text DEFAULT NULL,
    old_price decimal(19,4) DEFAULT NULL,
    old_sale_price decimal(19,4) DEFAULT NULL,
    sync_run_id varchar(32) DEFAULT NULL,
    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY product_id (product_id),
    KEY sync_run_id (sync_run_id),
    KEY created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 5.2 Post Meta Used

| Meta Key | Type | Purpose |
|----------|------|---------|
| `_supplier_ref` | string | Maps WC product to XLSX NR_REF |

---

## 6. REFINED MIGRATION PATH

### Phase 1: Bootstrap (One-Time Setup)
1. Install plugin via WP Admin → Plugins → Add New → Upload
2. Activate plugin
3. Navigate to **Stock Sync → Bootstrap Mapping**
4. Upload XLSX and review fuzzy matches
5. Confirm matches → plugin populates `_supplier_ref` on all matched products
6. Verify a few products in WP Admin → Products to confirm `_supplier_ref` is set

### Phase 2: Sync Testing
1. Navigate to **Stock Sync → Sync Products**
2. Upload a test XLSX
3. Select **Preview (dry-run)** mode
4. Review the proposed changes list
5. If satisfied, run again with **Apply Changes**
6. Check 5-10 products in WooCommerce: visibility = "Search", prices cleared, excerpt updated

### Phase 3: Production Use
1. Each time a new price list arrives, upload to Sync tab
2. Use Preview first if uncertain about file format changes
3. Apply changes → review summary
4. Download log if needed for audit

### Phase 4: Future Extensibility (Architecture Ready)
- Add "Restore on Restock" logic using the same matcher/updater pattern
- Add scheduled WP Cron sync
- Add email notifications on completion
- Add WP-CLI commands for power users

---

## 7. RISK ASSESSMENT — UPDATED

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Wrong product updated | Low | High | `_supplier_ref` mapping + bootstrap review + preview mode |
| Plugin conflict | Low | Medium | Test on staging; standard WC hooks only |
| Timeout on large files | Low | Medium | AJAX batch processing (50 per request) |
| File format changes | Medium | High | Header validation + graceful errors |
| SEO impact from hidden products | Low | Medium | Products remain searchable; monitor console |
| Fuzzy match false positive | Medium | High | Manual review step; confidence scoring |

---

## 8. CONCLUSION — PHASE 2

This refined plan delivers a production-ready WordPress plugin using **core PHP only**:

1. **Bootstrap by name matching** eliminates manual SKU mapping of 1,400 products
2. **One-time verification UI** ensures mapping accuracy before automation
3. **AJAX batch processing** handles any file size without timeouts
4. **Dry-run mode** gives confidence before every sync
5. **Price removal** is now included alongside visibility and excerpt changes
6. **Extensible architecture** allows "restore on restock" in a future phase without rewriting the core

Next step: Supply a sample XLSX to validate column positions and tune the fuzzy matching thresholds.

---

---

# PHASE 3: MULTI-DISTRIBUTOR ARCHITECTURE
## Core Entity for Scalable Distributor Support

> This section extends the plugin to support 5–7 distributors with different XLSX structures. The actions (visibility → search, price removal, excerpt update) remain identical across all distributors. Only the parsing and availability-flag logic vary.

---

## 1. DESIGN PRINCIPLES

| Principle | Rationale |
|-----------|-----------|
| **One dropdown, one UI** | Admin selects distributor → same Bootstrap + Sync tabs behave correctly for that file format |
| **Distributor-agnostic pipeline** | Parser → Standard Product → Matcher → Updater → Logger; only the Parser changes per distributor |
| **Core PHP only** | No Composer; ZipArchive + SimpleXML for all XLSX parsing |
| **Bootstrap once per distributor** | Each distributor gets its own `_supplier_ref_{slug}` meta key on WC products |
| **Actions are universal** | All unavailable products get the same treatment regardless of source |

---

## 2. ARCHITECTURE OVERVIEW

```
┌─────────────────────────────────────────────────────────────────────┐
│                         WP ADMIN UI                                │
│  [Distributor: ▼ Vininova]  [Upload XLSX]                            │
└─────────────────────┬───────────────────────────────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────────────────────────────┐
│              VINNOVA STOCK SYNC — CORE PIPELINE                    │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│   ┌──────────────────┐      ┌──────────────────────┐               │
│   │ 1. XLSX PARSER    │─────▶│ 2. STANDARD PRODUCT │               │
│   │    (ZipArchive)   │      │    (normalized DTO) │               │
│   └──────────────────┘      └──────────┬───────────┘               │
│         ▲                                │                          │
│         │                                │                          │
│   ┌─────┴─────┐                  ┌───────▼────────┐                │
│   │ Distributor│                  │ 3. MATCHER    │                │
│   │ Config    │                  │    (lookup    │                │
│   │ (columns, │                  │    _supplier_ │                │
│   │  flags,   │                  │    ref_{slug})│                │
│   │  sheet)   │                  └───────┬────────┘                │
│   └───────────┘                          │                        │
│                                          ▼                        │
│                                  ┌───────────────┐                │
│                                  │ 4. UPDATER    │                │
│                                  │ (universal)   │                │
│                                  │ - visibility  │                │
│                                  │ - clear prices│                │
│                                  │ - excerpt     │                │
│                                  └───────┬───────┘                │
│                                          │                        │
│                                          ▼                        │
│                                  ┌───────────────┐                │
│                                  │ 5. LOGGER     │                │
│                                  │ (DB table)    │                │
│                                  └───────────────┘                │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

---

## 3. DISTRIBUTOR CONTRACT (ABSTRACT CLASS)

Each distributor extends `StockSync_Distributor` and implements:

| Method | Purpose |
|--------|---------|
| `get_name()` | Human name (e.g., "Vininova") |
| `get_slug()` | Machine ID (e.g., `vininova`) |
| `get_sheet_name()` | XLSX worksheet to read |
| `get_header_row()` | Which row contains column headers |
| `get_column_map()` | Map: `['nr_ref' => 1, 'ean' => 2, ...]` |
| `is_product_row($row)` | Does this row represent a real product? |
| `is_unavailable($value)` | Is this availability string an out-of-stock flag? |
| `get_meta_key()` | Meta key for `_supplier_ref` (default: `_supplier_ref_{slug}`) |
| `get_category_filter()` | Optional: WC product category to limit bootstrap matching |
| `get_unavailable_description($name)` | Optional: custom excerpt text per distributor |

**Example: Vininova Distributor**

```php
class StockSync_Distributor_Vininova extends StockSync_Distributor {

    public function get_name() { return 'Vininova'; }
    public function get_slug() { return 'vininova'; }
    public function get_sheet_name() { return 'xl/worksheets/sheet1.xml'; }
    public function get_header_row() { return 10; }

    public function get_column_map() {
        return [
            'nr_ref'       => 1,  // Column A
            'ean'          => 2,  // Column B
            'availability' => 3,  // Column C
            'product_name' => 4,  // Column D
            'vintage'      => 5,  // Column E
        ];
    }

    public function is_product_row($row) {
        $nr_ref = isset($row[1]) ? $row[1] : '';
        return preg_match('/^[A-Z]{2}/', $nr_ref);
    }

    public function is_unavailable($value) {
        $flags = ['brak', 'chwilowy brak', 'chilowy brak', 'OS', 'wkrótce'];
        return in_array(trim($value), $flags, true);
    }
}
```

### 3.1 Category Filtering for Bootstrap

Each distributor can optionally declare a WooCommerce product category filter via `get_category_filter()`. When set, the bootstrap matcher only compares XLSX products against WC products in that category, dramatically reducing noise and false positives.

**Example:** Vininova products are categorized under `A - Oferta Vininova`. During bootstrap, only these ~630 products are considered instead of the full ~4,260 catalog.

```php
class StockSync_Distributor_Vininova extends StockSync_Distributor {
    // ... other methods ...

    public function get_category_filter() {
        return 'A - Oferta Vininova';
    }
}
```

**Benefits:**
- Faster matching (fewer comparisons)
- Higher accuracy (no bread/pastry products polluting wine matches)
- Scales cleanly as more distributors are added

---

## 4. STANDARD PRODUCT OBJECT

All distributors normalize to this DTO before entering the pipeline:

```php
class StockSync_Standard_Product {
    public $distributor_ref;   // e.g., FR22003
    public $ean;
    public $product_name;
    public $vintage;
    public $availability_raw;
    public $is_unavailable;
    public $distributor_slug; // e.g., vininova
}
```

This guarantees the Matcher, Updater, and Logger never need to know which distributor produced the data.

---

## 5. MULTI-DISTRIBUTOR FILE STRUCTURE

```
wp-content/plugins/
└── stock-sync/
    ├── stock-sync.php
    ├── includes/
    │   ├── class-plugin.php
    │   ├── class-admin.php
    │   ├── class-ajax-handler.php
    │   ├── class-standard-product.php
    │   ├── class-xlsx-parser.php
    │   ├── class-product-matcher.php
    │   ├── class-bootstrap-matcher.php
    │   ├── class-product-updater.php
    │   ├── class-logger.php
    │   ├── class-database.php
    │   └── distributors/
    │       ├── class-distributor.php          # Abstract base class
    │       ├── class-distributor-registry.php  # Singleton registry
    │       ├── class-distributor-vininova.php    # Concrete: Vininova
    │       └── README.md                       # How to add a distributor
    ├── admin/
    │   └── views/
    │       ├── tab-bootstrap.php
    │       ├── tab-sync.php
    │       └── tab-log.php
    └── assets/
        ├── css/
        │   └── admin.css
        └── js/
            └── admin.js
```

### Adding a New Distributor (3 Steps)

1. **Create a new class** in `distributors/`:
   ```php
   class StockSync_Distributor_Example extends StockSync_Distributor {
       public function get_name() { return 'Example Corp'; }
       public function get_slug() { return 'example'; }
       // ... implement all required methods
   }
   ```

2. **Register it** in `class-plugin.php`:
   ```php
   StockSync_Distributor_Registry::instance()->register(
       new StockSync_Distributor_Example()
   );
   ```

3. **Done.** The admin dropdown, bootstrap, and sync tabs automatically recognize the new distributor.

---

## 6. UPDATED META KEY STRATEGY

Each distributor stores its reference under a unique meta key to avoid collisions:

| Distributor | Meta Key | Example Value |
|-------------|----------|---------------|
| Vininova | `_supplier_ref_vininova` | FR22003 |
| Distributor A | `_supplier_ref_dist_a` | ABC123 |
| Distributor B | `_supplier_ref_dist_b` | 987654 |

The Matcher always uses the **currently selected distributor's meta key** for lookups.

---

## 7. ADMIN UI CHANGES

### Distributor Selector

Placed at the top of both Bootstrap and Sync tabs:

```
┌─────────────────────────────────────────────────────────┐
│  [Distributor: ▼ Vininova]  [Refresh]                    │
└─────────────────────────────────────────────────────────┘
```

- Dropdown populated from `StockSync_Distributor_Registry::get_all()`
- Selecting a distributor refreshes the tab content via AJAX
- Each distributor's bootstrap mappings are stored independently

### Bootstrap Tab — Per-Distributor

Mappings are scoped to the selected distributor. You can bootstrap Vininova today and Distributor A tomorrow without conflicts.

### Sync Tab — Per-Distributor

Same UI, but the sync uses the selected distributor's parser config and meta key.

---

## 8. UPDATED AJAX BATCH PROCESSING

The `init_sync` and `process_batch` endpoints now accept a `distributor_slug` parameter:

```php
public function init_sync() {
    // ... nonce + permission checks ...

    $slug = sanitize_text_field($_POST['distributor_slug'] ?? '');
    $distributor = StockSync_Distributor_Registry::instance()->get($slug);

    if (!$distributor) {
        wp_send_json_error('Unknown distributor: ' . $slug);
    }

    $parser = new StockSync_XLSX_Parser($file_path, $distributor);
    $products = $parser->parse(); // Returns StockSync_Standard_Product[]

    // ... rest unchanged ...
}
```

---

## 9. CONCLUSION — PHASE 3

The multi-distributor architecture delivers:

1. **Core entity** (`StockSync_Distributor`) that any new supplier extends in ~30 lines
2. **Registry pattern** for auto-discovery in the admin UI
3. **Standardized data flow** — parser output is always the same DTO regardless of source
4. **Namespace meta keys** so multiple distributors can coexist on the same product catalog
5. **Zero UI duplication** — one tab set serves all distributors via a dropdown

When you provide the other 4–6 distributor XLSX files, we only need to create one `class-distributor-{slug}.php` file per supplier. The rest of the plugin (parser, matcher, updater, logger, UI) remains untouched.

---

---

# PHASE 4: IMPLEMENTATION DETAILS
## Upload Handler, AJAX Flow & Distributor Templates

> This section documents the concrete implementation of file uploads, the full client-server AJAX flow, and ready-to-use code templates for adding distributors.

---

## 1. FILE UPLOAD ARCHITECTURE

### 1.1 Problem

WordPress AJAX endpoints (`admin-ajax.php`) do not natively handle `multipart/form-data` file uploads in the same request as JSON responses. The standard pattern is:

1. **Client uploads file** → Server saves to secure temp location → returns temp path
2. **Client sends temp path** → Server processes (parse, match, update) → returns JSON

### 1.2 Upload Handler (`class-admin.php`)

```php
public function ajax_upload_file() {
    check_ajax_referer('stock_sync_nonce', 'nonce');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(__('Permission denied', 'stock-sync'));
    }

    if (!isset($_FILES['xlsx_file'])) {
        wp_send_json_error(__('No file uploaded', 'stock-sync'));
    }

    $uploaded = $_FILES['xlsx_file'];

    if ($uploaded['error'] !== UPLOAD_ERR_OK) {
        wp_send_json_error(__('Upload failed', 'stock-sync'));
    }

    // Validate MIME type
    $finfo      = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type  = finfo_file($finfo, $uploaded['tmp_name']);
    finfo_close($finfo);

    $valid_types = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/octet-stream',
    ];

    if (!in_array($mime_type, $valid_types, true)) {
        wp_send_json_error(__('Invalid file type. Please upload an XLSX file.', 'stock-sync'));
    }

    // Move to persistent temp location
    $upload_dir = wp_upload_dir();
    $temp_dir   = trailingslashit($upload_dir['basedir']) . 'stock-sync-temp';

    if (!file_exists($temp_dir)) {
        wp_mkdir_p($temp_dir);
    }

    $temp_name = 'stock_' . wp_create_nonce('stock_temp') . '_' . time() . '.xlsx';
    $temp_path = $temp_dir . '/' . $temp_name;

    if (!move_uploaded_file($uploaded['tmp_name'], $temp_path)) {
        wp_send_json_error(__('Failed to save uploaded file', 'stock-sync'));
    }

    chmod($temp_path, 0600);
    wp_schedule_single_event(time() + HOUR_IN_SECONDS, 'stock_sync_cleanup_temp', ['path' => $temp_path]);

    wp_send_json_success([
        'file_path' => $temp_path,
        'file_name' => sanitize_file_name($uploaded['name']),
    ]);
}
```

### 1.3 Security Measures

| Measure | Implementation |
|---------|---------------|
| Nonce verification | `check_ajax_referer()` on every request |
| Capability check | `manage_woocommerce` required |
| MIME type validation | `finfo_file()` checks for XLSX |
| Secure temp directory | `wp_upload_dir()` + unique filename |
| File permissions | `0600` (owner read/write only) |
| Auto-cleanup | `wp_schedule_single_event()` deletes after 1 hour |

---

## 2. CLIENT-SERVER FLOW (ADMIN.JS)

### 2.1 Sync Flow

```
User clicks "Start Sync"
    │
    ▼
┌─────────────────┐
│ Upload XLSX     │  POST admin-ajax.php?action=stock_sync_upload_file
│ (FormData)      │  Returns: {file_path: "/tmp/stock_abc123.xlsx"}
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ Init Sync       │  POST admin-ajax.php?action=stock_sync_init
│                 │  Params: file_path, distributor_slug, dry_run
│                 │  Returns: {total_batches: 28, total_items: 1376}
└────────┬────────┘
         │
         ▼
┌─────────────────┐     ┌─────────────────┐     ┌─────────────────┐
│ Batch 1 (0-49)  │────▶│ Batch 2 (50-99) │────▶│ ... Batch 28    │
│                 │     │                 │     │                 │
│ POST            │     │ POST            │     │ POST            │
│ action=         │     │ action=         │     │ action=         │
│ stock_sync_   │     │ stock_sync_   │     │ stock_sync_   │
│ batch           │     │ batch           │     │ batch           │
└─────────────────┘     └─────────────────┘     └─────────────────┘
         │                       │                       │
         └───────────────────────┴───────────────────────┘
                             │
                             ▼
                    ┌─────────────────┐
                    │ Show Results    │
                    │ - total         │
                    │ - updated       │
                    │ - not_found     │
                    │ - errors        │
                    │ - details table │
                    └─────────────────┘
```

### 2.2 Bootstrap Flow

```
User clicks "Analyze & Match"
    │
    ▼
┌─────────────────┐
│ Upload XLSX     │  Same upload handler as sync
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ Analyze         │  POST action=stock_sync_bootstrap_analyze
│                 │  Returns: {matches: [...], total_xlsx: 1410, total_wc: 1523}
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ Render Table    │  Admin reviews matches
│ with checkboxes │  Checks/unchecks rows
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ Save Mappings   │  POST action=stock_sync_bootstrap_save
│                 │  Params: selected matches array
│                 │  Returns: {saved: 1384}
└─────────────────┘
```

---

## 3. DISTRIBUTOR CODE TEMPLATES

### 3.1 Full Template (`class-distributor-template.php`)

Located at `includes/distributors/class-distributor-template.php` — copy and customize for each new distributor. See the file for inline comments explaining each method.

### 3.2 Minimal Example (`class-distributor-example.php`)

Located at `includes/distributors/class-distributor-example.php` — the bare minimum for a working distributor.

### 3.3 Step-by-Step Registration

**Step 1**: Create your file
```bash
cp includes/distributors/class-distributor-template.php \
   includes/distributors/class-distributor-newcorp.php
```

**Step 2**: Edit the class
```php
class StockSync_Distributor_NewCorp extends StockSync_Distributor {
    public function get_name()  { return 'NewCorp'; }
    public function get_slug()  { return 'newcorp'; }
    public function get_header_row() { return 2; }
    public function get_column_map() {
        return [
            'distributor_ref' => 1,
            'ean'             => 2,
            'availability'    => 4,
            'product_name'    => 3,
            'vintage'         => 5,
        ];
    }
    public function is_product_row($row_data) {
        $ref = isset($row_data[1]) ? trim($row_data[1]) : '';
        return !empty($ref);
    }
    public function is_unavailable($value) {
        return in_array(trim(strtolower($value)), ['out', '0'], true);
    }
}
```

**Step 3**: Register in `includes/class-plugin.php`
```php
$registry->register(new StockSync_Distributor_NewCorp());
```

**Step 4**: Done. The admin dropdown now shows "NewCorp".

---

## 4. COMPLETE FILE LIST

```
wp-content/plugins/
└── stock-sync/
    ├── stock-sync.php                          # Plugin header, activation, loader
    ├── includes/
    │   ├── class-plugin.php                             # Main controller, registers distributors
    │   ├── class-admin.php                              # Admin menu, upload handler, page render
    │   ├── class-ajax-handler.php                       # Sync & bootstrap batch endpoints
    │   ├── class-standard-product.php                   # DTO: all distributors normalize to this
    │   ├── class-xlsx-parser.php                        # ZipArchive + XML parser (core PHP)
    │   ├── class-product-matcher.php                    # Lookup by _supplier_ref_{slug}
    │   ├── class-bootstrap-matcher.php                  # Fuzzy name matching with confidence
    │   ├── class-product-updater.php                    # Universal: visibility, prices, excerpt
    │   ├── class-logger.php                             # Custom DB table for audit trail
    │   ├── class-database.php                             # Table schema & creation
    │   └── distributors/
    │       ├── class-distributor.php                    # Abstract base class (core entity)
    │       ├── class-distributor-registry.php             # Singleton registry
    │       ├── class-distributor-vininova.php            # Concrete: Vininova
    │       ├── class-distributor-template.php           # Copy-this template for new distributors
    │       ├── class-distributor-example.php            # Minimal working example
    │       └── README.md                                # How to add distributors
    ├── admin/
    │   └── views/
    │       ├── page-wrapper.php                         # Main layout: dropdown + tabs
    │       ├── tab-sync.php                             # Upload + preview/apply + progress
    │       ├── tab-bootstrap.php                        # Upload + match review + confirm
    │       └── tab-log.php                              # Sync history table
    └── assets/
        ├── css/
        │   └── admin.css                                  # Progress bars, badges, tables
        └── js/
            └── admin.js                                   # AJAX upload, batch loop, bootstrap
```

---

## 5. TESTING CHECKLIST

### 5.1 Before First Sync

- [ ] Plugin activated and WooCommerce is running
- [ ] Database tables created on activation
- [ ] Distributor dropdown shows Vininova (and any added distributors)
- [ ] Bootstrap Mapping tab uploads XLSX and shows match table
- [ ] Confidence scores look reasonable (90%+ for obvious matches)
- [ ] Manual review of "suggest" and "manual" rows
- [ ] Save Mappings completes without errors
- [ ] Verify `_supplier_ref_vininova` appears on matched products in WP Admin

### 5.2 Test Single Product (Before First Full Sync)

- [ ] Open **Test Product** tab
- [ ] Search for a known product by name
- [ ] Select product from results
- [ ] Review Before/After table — all fields should show expected changes
- [ ] Click **Apply Test Update**
- [ ] Verify in WooCommerce → Products → Edit:
  - [ ] Visibility changed to "Search results only"
  - [ ] Regular price cleared
  - [ ] Sale price cleared
  - [ ] Short description updated
- [ ] Undo the test change manually (or note the original values)

### 5.3 First Sync

- [ ] Upload XLSX on Sync Products tab
- [ ] Run **Preview (dry-run)** first
- [ ] Review "would_update" list
- [ ] Run **Apply Changes**
- [ ] Check 5–10 products in WooCommerce:
  - [ ] Visibility = "Search results only"
  - [ ] Regular price is empty
  - [ ] Sale price is empty
  - [ ] Short description updated
- [ ] Check Sync Log tab shows the operation

### 5.4 Adding a New Distributor

- [ ] Copy template and customize 6 methods
- [ ] Register in `class-plugin.php`
- [ ] Dropdown shows new name
- [ ] Bootstrap works for new distributor
- [ ] Preview sync works for new distributor
- [ ] Products have correct `_supplier_ref_{slug}` meta

---

## 6. NEXT STEPS

1. **Test with real Vininova XLSX** — validate column positions, tune fuzzy matching thresholds
2. **Add remaining 4–6 distributors** — one `class-distributor-{slug}.php` file each
3. **Optional: WP-CLI commands** — `wp stock-sync sync --file=... --distributor=vininova`
4. **Optional: Scheduled sync** — WP Cron to auto-run weekly with email notifications
5. **Optional: Restore on restock** — two-way sync when products become available again

(End of document)
