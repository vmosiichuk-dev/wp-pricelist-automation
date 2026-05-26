<?php
class StockSync_WP_Database_Logger implements Logger_Interface {
    private $logger;

    public function __construct(StockSync_Change_Logger $logger) {
        $this->logger = $logger;
    }

    public function log(array $data) {
        return $this->logger->log($data);
    }

    public function get_recent($limit = 50) {
        return $this->logger->get_recent($limit);
    }

    public function get_sync_runs($limit = 20) {
        return $this->logger->get_sync_runs($limit);
    }
}
