<?php
/**
 * WordPress Database Logger
 * Adapts the StockSync_Change_Logger to the Logger_Interface.
 */

class StockSync_WP_Database_Logger implements Logger_Interface {
    private $logger;

    /**
     * Create a WP database logger that wraps and delegates to a StockSync_Change_Logger.
     *
     * Stores the provided logger instance for forwarding logging and retrieval calls.
     *
     * @param StockSync_Change_Logger $logger The underlying change logger to delegate to.
     */
    public function __construct(StockSync_Change_Logger $logger) {
        $this->logger = $logger;
    }

    /**
     * Send a log entry to the underlying change logger.
     *
     * @param array $data Associative array representing the log entry.
     * @return mixed The value returned by the underlying logger after recording the entry.
     */
    public function log(array $data) {
        return $this->logger->log($data);
    }

    /**
     * Retrieve recent log entries from the underlying change logger.
     *
     * @param int $limit Maximum number of log entries to return. Defaults to 50.
     * @return array Array of recent log entries.
     */
    public function get_recent($limit = 50) {
        return $this->logger->get_recent($limit);
    }

    /**
     * Retrieve recent sync run records, limited to the specified count.
     *
     * @param int $limit Maximum number of sync runs to return.
     * @return array List of sync run records.
     */
    public function get_sync_runs($limit = 20) {
        return $this->logger->get_sync_runs($limit);
    }
}
