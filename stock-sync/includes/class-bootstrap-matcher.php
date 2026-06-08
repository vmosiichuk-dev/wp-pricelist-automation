<?php
/**
 * Bootstrap Matcher — One-time fuzzy name matching per distributor
 */
class StockSync_Bootstrap_Matcher {
    private $repository;

    /**
     * Initialize the matcher with a product repository used to retrieve WooCommerce products.
     *
     * @param Product_Repository_Interface $repository Repository used to find WooCommerce products.
     */
    public function __construct(Product_Repository_Interface $repository) {
        $this->repository = $repository;
    }

    /**
     * Retrieve WooCommerce products, optionally filtered by category.
     *
     * @param string|null $category Optional category name used to filter by the `product_cat` taxonomy.
     * @return array The repository result set of WooCommerce product data.
     */
    public function get_all_wc_products($category = null) {
        return $this->repository->find_all($category);
    }

    /**
     * Normalize a product name for comparison
     */
    public function normalize_name($name) {
        $name = strtolower($name);
        $name = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        $name = preg_replace('/[^a-z0-9\s]/', '', $name);
        $name = preg_replace('/\s+/', ' ', $name);
        $name = trim($name);
        return $name;
    }

    /**
     * Calculate match confidence (0-100)
     */
    public function calculate_confidence($xlsx_name, $wc_name) {
        $norm_xlsx = $this->normalize_name($xlsx_name);
        $norm_wc   = $this->normalize_name($wc_name);

        if ($norm_xlsx === '' || $norm_wc === '') {
            return 0;
        }

        if ($norm_xlsx === $norm_wc) {
            return 100;
        }

        if (strpos($norm_wc, $norm_xlsx) !== false || strpos($norm_xlsx, $norm_wc) !== false) {
            return 90;
        }

        $xlsx_words = array_values(array_filter(explode(' ', $norm_xlsx)));
        $wc_words   = array_values(array_filter(explode(' ', $norm_wc)));

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

        // Tier 2b: all words from the shorter name appear in the longer name (any order)
        $short_words = count($xlsx_words) <= count($wc_words) ? $xlsx_words : $wc_words;
        $long_words  = count($xlsx_words) <= count($wc_words) ? $wc_words : $xlsx_words;
        $short_in_long = count(array_intersect($short_words, $long_words));
        if ($short_in_long === count($short_words)) {
            return 85;
        }

        if (strlen($norm_xlsx) < 100 && strlen($norm_wc) < 100) {
            $lev     = levenshtein($norm_xlsx, $norm_wc);
            $max_len = max(strlen($norm_xlsx), strlen($norm_wc));
            if ($max_len > 0 && ($lev / $max_len) < 0.15) {
                return 70;
            }
        }

        return 0;
    }

    /**
     * Extract all 4-digit years starting with "20" from a product name.
     * Returns an empty array if none found.
     *
     * We only accept years starting with "20" because this is a wine distributor
     * and no pre-2000 vintages are sold. This prevents false positives from
     * other 4-digit numbers (e.g. "3781" in "Ribolla 3781").
     */
    public function extract_years_from_name($name) {
        $cleaned = $this->clean_name_for_year_extraction($name);
        if (preg_match_all('/\b20\d{2}\b/', $cleaned, $matches)) {
            return $matches[0]; // Array of strings like ['2016', '2018']
        }
        return [];
    }

    private function clean_name_for_year_extraction($name) {
        // Strip Polish price patterns (same regex as Product_Updater::clean_name)
        $name = preg_replace('/\s*-\s*\d+(?:,\d+)?\s*zł\.\*\*\s*$/iu', '', $name);
        $name = preg_replace('/^\d+(?:,\d+)?\s*zł\.\*\*\s*/iu', '', $name);
        return $name;
    }

    /**
     * Reverse match: for each WC product, find the best XLSX row.
     * Returns array keyed by wc_id.
     */
    public function match_wc_to_xlsx($wc_products, $xlsx_products) {
        $matches = []; // wc_id => best match data

        foreach ($wc_products as $wc_product) {
            $wc_years = $this->extract_years_from_name($wc_product['name']);
            $best_match = null;
            $best_score = 0;
            $best_is_generic = false;

            foreach ($xlsx_products as $xlsx_product) {
                $score = $this->calculate_confidence(
                    $xlsx_product->product_name,
                    $wc_product['name']
                );

                // YEAR GUARD
                if (!empty($xlsx_product->vintage) && !empty($wc_years)) {
                    $xlsx_year = $xlsx_product->vintage;
                    // Vintage must be a 4-digit year starting with 20
                    if (preg_match('/^20\d{2}$/', $xlsx_year)) {
                        if (!in_array($xlsx_year, $wc_years, true)) {
                            $score = 0; // Hard discard — year mismatch
                        }
                    }
                }

                $is_generic = ($xlsx_product->distributor_ref === $xlsx_product->base_ref);

                if ($score > $best_score) {
                    $best_score = $score;
                    $best_match = $xlsx_product;
                    $best_is_generic = $is_generic;
                } elseif ($score === $best_score && $score > 0) {
                    // TIE-BREAKER: prefer generic ref when WC has no year
                    if (empty($wc_years) && $is_generic && !$best_is_generic) {
                        $best_match = $xlsx_product;
                        $best_is_generic = true;
                    }
                }
            }

            if ($best_match) {
                $matches[$wc_product['id']] = [
                    'wc_id'           => $wc_product['id'],
                    'wc_name'         => $wc_product['name'],
                    'wc_sku'          => $wc_product['sku'],
                    'distributor_ref' => $best_match->distributor_ref,
                    'xlsx_name'       => $best_match->product_name,
                    'confidence'      => $best_score,
                    'status'          => $this->get_status_from_confidence($best_score),
                ];
            }
        }

        return $matches;
    }

    /**
     * Match all XLSX products to WC products.
     * Public API keeps the same signature for backward compatibility.
     */
    public function match_all($xlsx_products, $wc_products) {
        // Step 1: Reverse match (WC → XLSX)
        $wc_matches = $this->match_wc_to_xlsx($wc_products, $xlsx_products);

        // Step 2: Invert to XLSX-first view and detect conflicts
        $ref_to_wc = [];
        foreach ($wc_matches as $wc_id => $match) {
            $ref = $match['distributor_ref'];
            if (!isset($ref_to_wc[$ref])) {
                $ref_to_wc[$ref] = [];
            }
            $ref_to_wc[$ref][] = $match;
        }

        // Step 3: Build final XLSX-first result set
        $results = [];
        $handled_refs = [];

        foreach ($xlsx_products as $xlsx_product) {
            $ref = $xlsx_product->distributor_ref;
            if (isset($handled_refs[$ref])) {
                continue;
            }
            $handled_refs[$ref] = true;

            if (isset($ref_to_wc[$ref])) {
                $claimants = $ref_to_wc[$ref];
                if (count($claimants) === 1) {
                    // Clean single match
                    $results[] = [
                        'distributor_ref' => $ref,
                        'xlsx_name'       => $xlsx_product->product_name,
                        'wc_id'           => $claimants[0]['wc_id'],
                        'wc_name'         => $claimants[0]['wc_name'],
                        'wc_sku'          => $claimants[0]['wc_sku'],
                        'confidence'      => $claimants[0]['confidence'],
                        'status'          => $claimants[0]['status'],
                    ];
                } else {
                    // CONFLICT: multiple WC products claim this XLSX row
                    foreach ($claimants as $c) {
                        $results[] = [
                            'distributor_ref' => $ref,
                            'xlsx_name'       => $xlsx_product->product_name,
                            'wc_id'           => $c['wc_id'],
                            'wc_name'         => $c['wc_name'],
                            'wc_sku'          => $c['wc_sku'],
                            'confidence'      => 0,
                            'status'            => 'manual',
                        ];
                    }
                }
            } else {
                // No WC product claimed this XLSX row
                $results[] = [
                    'distributor_ref' => $ref,
                    'xlsx_name'       => $xlsx_product->product_name,
                    'wc_id'           => null,
                    'wc_name'         => null,
                    'wc_sku'          => null,
                    'confidence'      => 0,
                    'status'            => 'manual',
                ];
            }
        }

        return $results;
    }

    /**
     * Translate confidence to status label
     *
     * >= 90: auto (silent, pre-checked)
     * 70-89: suggest (shown in review table, unchecked)
     * < 70: manual (shown in unmatched section)
     */
    private function get_status_from_confidence($score) {
        if ($score >= 90) {
            return 'auto';
        }
        if ($score >= 70) {
            return 'suggest';
        }
        return 'manual';
    }

    /**
     * Save confirmed mappings as post meta
     */
    public function save_mappings($confirmed_matches, $meta_key) {
        $saved = 0;

        foreach ($confirmed_matches as $match) {
            if (empty($match['wc_id']) || empty($match['distributor_ref'])) {
                continue;
            }

            update_post_meta(
                $match['wc_id'],
                $meta_key,
                sanitize_text_field($match['distributor_ref'])
            );
            $saved++;
        }

        return $saved;
    }
}
