<?php
/**
 * WordPress Transient Store
 * Adapts WordPress transients to the Transient_Store_Interface.
 */

class StockSync_WP_Transient_Store implements Transient_Store_Interface {

    /**
     * Store a value in the WordPress transient cache under the given key.
     *
     * @param string $key The transient key.
     * @param mixed  $value The value to store.
     * @param int    $expiration Expiration time in seconds; 0 for no expiration.
     * @return bool True on success, false on failure.
     */
    public function set($key, $value, $expiration = 0) {
        return set_transient($key, $value, $expiration);
    }

    /**
     * Retrieve a value from the WordPress transient store for the given key.
     *
     * @param string $key Transient key to retrieve.
     * @return mixed The value stored for `$key`, or `false` if the transient does not exist.
     */
    public function get($key) {
        return get_transient($key);
    }

    /**
     * Delete a transient by its key.
     *
     * @param string $key The transient key to delete.
     * @return bool `true` if the transient was successfully deleted, `false` otherwise.
     */
    public function delete($key) {
        return delete_transient($key);
    }
}
