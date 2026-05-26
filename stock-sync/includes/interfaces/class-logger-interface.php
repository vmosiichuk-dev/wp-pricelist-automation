<?php
interface Logger_Interface {
    /**
 * Record a structured log entry.
 *
 * @param array $data Associative array of log details (for example: message, level, context, timestamp).
 */
public function log(array $data);
    /**
 * Retrieve the most recent log entries.
 *
 * @param int $limit Maximum number of entries to return (default 50).
 * @return array An array of recent log entries. Each entry is a structured log record.
 */
public function get_recent($limit = 50);
    /**
 * Retrieve recent synchronization run records.
 *
 * @param int $limit Maximum number of sync run records to return. Defaults to 20.
 * @return array An array of sync run records (most recent first).
 */
public function get_sync_runs($limit = 20);
}
