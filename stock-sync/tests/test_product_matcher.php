<?php

use Brain\Monkey;
use Brain\Monkey\Functions;

class Test_Product_Matcher extends \PHPUnit\Framework\TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        WP_Query::$test_posts = [];
        WP_Query::$test_have_posts = false;
        WP_Query::$last_args = [];
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

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

    public function test_find_by_distributor_ref_not_found() {
        WP_Query::$test_posts = [];
        WP_Query::$test_have_posts = false;

        $repository = Mockery::mock('Product_Repository_Interface');
        $matcher = new StockSync_Product_Matcher($repository);

        $result = $matcher->find_by_distributor_ref('REF123', '_supplier_ref_vininova');

        $this->assertFalse($result);
    }

    public function test_find_by_distributor_ref_empty_inputs() {
        $repository = Mockery::mock('Product_Repository_Interface');
        $matcher = new StockSync_Product_Matcher($repository);

        $this->assertFalse($matcher->find_by_distributor_ref('', '_supplier_ref_vininova'));
        $this->assertFalse($matcher->find_by_distributor_ref('REF123', ''));
        $this->assertEmpty(WP_Query::$last_args);
    }

    public function test_find_by_sku_found() {
        $repository = Mockery::mock('Product_Repository_Interface');
        $repository->shouldReceive('find_by_sku')
            ->with('SKU123')
            ->andReturn(456);

        $matcher = new StockSync_Product_Matcher($repository);

        $this->assertSame(456, $matcher->find_by_sku('SKU123'));
    }

    public function test_find_by_sku_not_found() {
        $repository = Mockery::mock('Product_Repository_Interface');
        $repository->shouldReceive('find_by_sku')
            ->with('SKU123')
            ->andReturn(0);

        $matcher = new StockSync_Product_Matcher($repository);

        $this->assertSame(0, $matcher->find_by_sku('SKU123'));
    }
}
