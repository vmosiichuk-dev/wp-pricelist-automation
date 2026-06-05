<?php
/**
 * Standard Product Data Transfer Object
 * All distributors normalize to this before entering the sync pipeline.
 */
class StockSync_Standard_Product {

    public $distributor_ref;
    public $ean;
    public $product_name;
    public $vintage;
    public $availability_raw;
    public $is_unavailable;
    public $distributor_slug;
    public $base_ref;       // Ref with variant suffix stripped, e.g. WO5502
    public $variant_year;   // 4-digit year from ref suffix, or null (informational only)

    /**
     * Initialize standard product properties from data array.
     *
     * @param array $data Product data.
     * @return void
     */
    public function __construct(array $data = []) {
        $this->distributor_ref  = $data['distributor_ref'] ?? '';
        $this->ean              = $data['ean'] ?? '';
        $this->product_name     = $data['product_name'] ?? '';
        $this->vintage          = $data['vintage'] ?? '';
        $this->availability_raw = $data['availability_raw'] ?? '';
        $this->is_unavailable   = $data['is_unavailable'] ?? false;
        $this->distributor_slug = $data['distributor_slug'] ?? '';
        $this->base_ref      = $data['base_ref']    ?? $this->compute_base_ref();
        $this->variant_year = $data['variant_year'] ?? null;
    }

    /**
     * Get the meta key used to store this distributor's reference on a WC product
     */
    public function get_meta_key() {
        $slug = sanitize_key($this->distributor_slug);
        if (empty($slug)) {
            return null;
        }
        return '_supplier_ref_' . $slug;
    }

    private function compute_base_ref() {
        return preg_replace('/-\d{2,4}$/', '', $this->distributor_ref);
    }
}
