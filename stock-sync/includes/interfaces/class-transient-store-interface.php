<?php
interface Transient_Store_Interface {
    public function set($key, $value, $expiration = 0);
    public function get($key);
    public function delete($key);
}
