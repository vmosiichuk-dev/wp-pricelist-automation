<?php
class StockSync_WP_Transient_Store implements Transient_Store_Interface {

    public function set($key, $value, $expiration = 0) {
        return set_transient($key, $value, $expiration);
    }

    public function get($key) {
        return get_transient($key);
    }

    public function delete($key) {
        return delete_transient($key);
    }
}
