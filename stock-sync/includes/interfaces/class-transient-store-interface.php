<?php
/**
 * Transient Store Interface
 * Defines the contract for temporary key-value storage.
 */

interface Transient_Store_Interface {

    /**
     * Store a value under the given key in the transient store.
     *
     * @param string $key Identifier for the stored value.
     * @param mixed  $value Value to store.
     * @param int    $expiration Expiration time in seconds; 0 means the value does not expire.
     */
    public function set($key, $value, $expiration = 0);

    /**
     * Retrieve the value stored for the given transient key.
     *
     * Matches WordPress get_transient() semantics.
     *
     * @param string $key The key identifying the stored value.
     * @return mixed|false The stored value if found, or false if the transient does not exist or has expired.
     */
    public function get($key);

    /**
     * Remove the stored value identified by the given key.
     *
     * Matches WordPress delete_transient() semantics.
     *
     * @param string $key The key of the entry to remove from the transient store.
     * @return bool True if deletion succeeded, false otherwise.
     */
    public function delete($key);
}
