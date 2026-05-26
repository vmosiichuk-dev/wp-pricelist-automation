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
     * Match all XLSX products to WC products
     */
    public function match_all($xlsx_products, $wc_products) {
        $matches = [];

        foreach ($xlsx_products as $xlsx_product) {
            $best_match = null;
            $best_score = 0;

            foreach ($wc_products as $wc_product) {
                $score = $this->calculate_confidence(
                    $xlsx_product->product_name,
                    $wc_product['name']
                );

                if ($score > $best_score) {
                    $best_score = $score;
                    $best_match = $wc_product;
                }
            }

            $matches[] = [
                'distributor_ref' => $xlsx_product->distributor_ref,
                'xlsx_name'       => $xlsx_product->product_name,
                'wc_id'           => $best_match ? $best_match['id'] : null,
                'wc_name'         => $best_match ? $best_match['name'] : null,
                'wc_sku'          => $best_match ? $best_match['sku'] : null,
                'confidence'      => $best_score,
                'status'          => $this->get_status_from_confidence($best_score),
            ];
        }

        return $matches;
    }

    /**
     * Translate confidence to status label
     */
    private function get_status_from_confidence($score) {
        if ($score >= 95) {
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
