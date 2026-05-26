<?php
interface Logger_Interface {
    public function log(array $data);
    public function get_recent($limit = 50);
    public function get_sync_runs($limit = 20);
}
