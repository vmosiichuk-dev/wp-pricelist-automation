<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
/**
 * Tests for StockSync_Product_Matcher.
 */


class Test_Product_Matcher extends \PHPUnit\Framework\TestCase {

    /**
     * Prepare the test environment for each test.
     *
     * Calls the parent setup, initializes Brain Monkey, and resets WP_Query's
     * static test state (`$test_posts`, `$test_have_posts`, `$last_args`) to
     * their defaults.
     */
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        WP_Query::$test_posts = [];
        WP_Query::$test_have_posts = false;
        WP_Query::$last_args = [];
    }

    /**
     * Clean up test doubles and global state after each test.
     *
     * Tears down Brain Monkey, closes Mockery expectations and mocks, and then calls the parent tearDown.
     */
    protected function tearDown(): void {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }
    /**
     * Verify that a matching distributor reference returns the correct product ID.
     */

    public function test_find_by_distributor_ref_found() {
        WP_Query::$test_posts = [123];
        WP_Query::$test_have_posts = true;

        $repository = Mockery::mock('Product_Repository_Interface');
        $matcher = new StockSync_Product_Matcher($repository);

        $result = $matcher->find_by_distributor_ref('REF123', '_supplier_ref_vininova');

        $this->assertSame(123, $result);
        $this->assertSame('product', WP_Query::$last_args['post_type']);
        $this->assertSame('_supplier_ref_vininova', WP_Query::$last_args['meta_query'][0]['key']);
        $this->assertSame('REF123', WP_Query::$last_args['meta_query'][0]['value']);
    }
    /**
     * Verify that a missing distributor reference returns false.
     */

    public function test_find_by_distributor_ref_not_found() {
        WP_Query::$test_posts = [];
        WP_Query::$test_have_posts = false;

        $repository = Mockery::mock('Product_Repository_Interface');
        $matcher = new StockSync_Product_Matcher($repository);

        $result = $matcher->find_by_distributor_ref('REF123', '_supplier_ref_vininova');

        $this->assertFalse($result);
    }
    /**
     * Verify that empty inputs return false without querying.
     */

    public function test_find_by_distributor_ref_empty_inputs() {
        $repository = Mockery::mock('Product_Repository_Interface');
        $matcher = new StockSync_Product_Matcher($repository);

        $this->assertFalse($matcher->find_by_distributor_ref('', '_supplier_ref_vininova'));
        $this->assertFalse($matcher->find_by_distributor_ref('REF123', ''));
        $this->assertEmpty(WP_Query::$last_args);
    }
    /**
     * Verify that a matching SKU returns the correct product ID.
     */

    public function test_find_by_sku_found() {
        $repository = Mockery::mock('Product_Repository_Interface');
        $repository->shouldReceive('find_by_sku')
            ->with('SKU123')
            ->andReturn(456);

        $matcher = new StockSync_Product_Matcher($repository);

        $this->assertSame(456, $matcher->find_by_sku('SKU123'));
    }
    /**
     * Verify that a missing SKU returns 0.
     */

    public function test_find_by_sku_not_found() {
        $repository = Mockery::mock('Product_Repository_Interface');
        $repository->shouldReceive('find_by_sku')
            ->with('SKU123')
            ->andReturn(0);

        $matcher = new StockSync_Product_Matcher($repository);

        $this->assertSame(0, $matcher->find_by_sku('SKU123'));
    }
}
