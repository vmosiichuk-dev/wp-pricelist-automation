<?php
interface Product_Repository_Interface {
    public function find_by_id($product_id);
    public function find_all($category = null);
    public function find_by_meta($meta_key, $meta_value);
    public function find_by_sku($sku);
    public function save($product);
}
