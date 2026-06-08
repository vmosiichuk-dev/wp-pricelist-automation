<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
/**
 * Tests for StockSync_WP_Database_Logger.
 */


class Test_WP_Database_Logger extends \PHPUnit\Framework\TestCase {

    private $wpdb;
    private $logger;

    /**
     * Prepare the test environment for WP database logger tests.
     *
     * Sets up the test case and Brain Monkey, stubs WordPress helper functions
     * used by the logger, provides a mocked `$wpdb` with `prefix` set to `wp_`
     * and assigned to `$GLOBALS['wpdb']`, and instantiates the `StockSync_WP_Database_Logger`
     * wrapping a `StockSync_Change_Logger`.
     */
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when('sanitize_text_field')->alias(function ($str) {
            return $str;
        });
        Functions\when('current_time')->justReturn('2024-01-01 00:00:00');
        Functions\when('wp_parse_args')->alias(function ($args, $defaults = '') {
            if (is_array($args)) {
                return array_merge($defaults, $args);
            }
            return $defaults;
        });

        $this->wpdb = Mockery::mock('wpdb');
        $this->wpdb->prefix = 'wp_';
        $GLOBALS['wpdb'] = $this->wpdb;

        $inner = new StockSync_Change_Logger();
        $this->logger = new StockSync_WP_Database_Logger($inner);
    }

    /**
     * Restore test environment after each test.
     *
     * Tears down Brain Monkey, closes Mockery, unsets the global `$wpdb`, and calls the parent tearDown.
     */
    protected function tearDown(): void {
        Monkey\tearDown();
        Mockery::close();
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    /**
     * Verifies that a call to the logger inserts a row into the `wp_stock_sync_log` table with the expected fields and that the logger returns the database insert ID.
     */
    public function test_log_format() {
        $this->wpdb->shouldReceive('insert')
            ->once()
            ->andReturnUsing(function ($table, $data) {
                $this->assertSame('wp_stock_sync_log', $table);
                $this->assertArrayHasKey('product_id', $data);
                $this->assertSame(1, $data['product_id']);
                return 1;
            });
        $this->wpdb->insert_id = 99;

        $result = $this->logger->log([
            'product_id' => 1,
            'sku'        => 'SKU123',
            'action'     => 'marked_unavailable',
        ]);

        $this->assertSame(99, $result);
    }
    /**
     * Verify that multiple log calls within the same session share a sync run ID.
     */

    public function test_current_sync_run_id_is_reused() {
        $syncRunIds = [];
        $this->wpdb->shouldReceive('insert')
            ->twice()
            ->andReturnUsing(function ($table, $data) use (&$syncRunIds) {
                $syncRunIds[] = $data['sync_run_id'];
                return 1;
            });
        $this->wpdb->insert_id = 1;

        $this->logger->log(['action' => 'a']);
        $this->logger->log(['action' => 'b']);

        $this->assertSame($syncRunIds[0], $syncRunIds[1]);
    }
    /**
     * Verify that get_recent clamps the limit parameter to a valid range.
     */

    public function test_get_recent_clamps_limit() {
        $capturedLimits = [];
        $this->wpdb->shouldReceive('prepare')
            ->andReturnUsing(function ($query, $limit) use (&$capturedLimits) {
                $capturedLimits[] = $limit;
                return $query;
            });
        $this->wpdb->shouldReceive('get_results')->andReturn([]);

        $this->logger->get_recent(-5);
        $this->logger->get_recent(5000);
        $this->logger->get_recent(50);

        $this->assertSame([1, 1000, 50], $capturedLimits);
    }
    /**
     * Verify that get_sync_runs clamps the limit parameter to a valid range.
     */

    public function test_get_sync_runs_clamps_limit() {
        $capturedLimits = [];
        $this->wpdb->shouldReceive('prepare')
            ->andReturnUsing(function ($query, $limit) use (&$capturedLimits) {
                $capturedLimits[] = $limit;
                return $query;
            });
        $this->wpdb->shouldReceive('get_results')->andReturn([]);

        $this->logger->get_sync_runs(-5);
        $this->logger->get_sync_runs(5000);
        $this->logger->get_sync_runs(50);

        $this->assertSame([1, 1000, 50], $capturedLimits);
    }

    /**
     * Verifies that SQL generated for recent log and sync-run queries contains the expected ORDER BY, GROUP BY and LIMIT clauses.
     *
     * Captures the SQL passed to $wpdb->prepare() when calling get_recent(10) and get_sync_runs(10) and asserts the presence
     * of the specific substrings that define ordering, grouping, and pagination.
     */
    public function test_log_sql_structure() {
        $capturedQueries = [];
        $this->wpdb->shouldReceive('prepare')
            ->andReturnUsing(function ($query, $limit) use (&$capturedQueries) {
                $capturedQueries[] = $query;
                return $query;
            });
        $this->wpdb->shouldReceive('get_results')->andReturn([]);

        $this->logger->get_recent(10);
        $this->logger->get_sync_runs(10);

        $this->assertStringContainsString('ORDER BY created_at DESC LIMIT %d', $capturedQueries[0]);
        $this->assertStringContainsString('GROUP BY sync_run_id, distributor_slug', $capturedQueries[1]);
        $this->assertStringContainsString('ORDER BY run_date DESC', $capturedQueries[1]);
        $this->assertStringContainsString('LIMIT %d', $capturedQueries[1]);
    }
}
