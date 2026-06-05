<?php
/**
 * Product Repository Interface
 * Defines the contract for WooCommerce product retrieval and persistence.
 */

interface Product_Repository_Interface {

    /**
     * Retrieve a product by its identifier.
     *
     * @param int|string $product_id The product identifier.
     * @return object|null The product object if found, or null if no matching product exists.
     */
    public function find_by_id($product_id);

    /**
     * Retrieve all products, optionally filtered by a category.
     *
     * @param mixed|null $category Optional category identifier or slug to filter results; pass `null` to fetch all products.
     * @return array An array of product entities; an empty array if no products match.
     */
    public function find_all($category = null);

    /**
     * Retrieve products that match a given metadata key and value.
     *
     * @param string $meta_key Metadata key to match.
     * @param mixed  $meta_value Metadata value to compare against.
     * @return array Array of product entities that match the provided metadata (may be empty).
     */
    public function find_by_meta($meta_key, $meta_value);

    /**
     * Retrieve a product by its SKU.
     *
     * @param string $sku The product SKU to look up.
     * @return object|null The product matching the SKU, or `null` if no product was found.
     */
    public function find_by_sku($sku);

    /**
     * Persist a product entity in the repository.
     *
     * @param mixed $product The product entity or data to persist.
     * @return mixed The persisted product entity or a repository-specific result.
     */
    public function save($product);
}
