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

    public function __construct(array $data = []) {
        $this->distributor_ref  = $data['distributor_ref'] ?? '';
        $this->ean              = $data['ean'] ?? '';
        $this->product_name     = $data['product_name'] ?? '';
        $this->vintage          = $data['vintage'] ?? '';
        $this->availability_raw = $data['availability_raw'] ?? '';
        $this->is_unavailable   = $data['is_unavailable'] ?? false;
        $this->distributor_slug = $data['distributor_slug'] ?? '';
    }

    /**
     * Get the meta key used to store this distributor's reference on a WC product
     */
    public function get_meta_key() {
        return '_supplier_ref_' . sanitize_key($this->distributor_slug);
    }
}
