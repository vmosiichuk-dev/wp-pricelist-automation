<?php

use Brain\Monkey;
use Brain\Monkey\Functions;

class Test_Product_Updater extends \PHPUnit\Framework\TestCase {

    /**
     * Prepare the test environment before each test.
     *
     * Initializes Brain Monkey and stubs WordPress translation-related helpers,
     * forcing `wp_kses_post` to return the fixed value `'test excerpt'`.
     */
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\stubTranslationFunctions();
        Functions\when('wp_kses_post')->justReturn('test excerpt');
    }

    /**
     * Tear down the test environment and restore global state after each test.
     *
     * Calls out to test helpers to remove any stubs/mocks and then invokes the parent tearDown.
     *
     * @return void
     */
    protected function tearDown(): void {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Verifies that mark_unavailable updates a product's visibility, excerpt and prices, saves the product, and logs a `marked_unavailable` event with expected product and distributor details.
     *
     * The test expects:
     * - catalog visibility set to `search`
     * - short description replaced with the distributor-provided unavailable description
     * - regular and sale prices cleared
     * - `save()` called on the product
     * - logger called once with an array containing product id, SKU, action, old/new visibility, old/new excerpts, old prices, and distributor slug/ref.
     *
     * @return void
     */
    public function test_mark_unavailable_calls_correct_setters() {
        $mockProduct = Mockery::mock('WC_Product');
        $mockProduct->shouldReceive('get_catalog_visibility')->andReturn('visible');
        $mockProduct->shouldReceive('get_short_description')->andReturn('Old excerpt');
        $mockProduct->shouldReceive('get_regular_price')->andReturn('100');
        $mockProduct->shouldReceive('get_sale_price')->andReturn('90');
        $mockProduct->shouldReceive('get_sku')->andReturn('SKU123');
        $mockProduct->shouldReceive('set_catalog_visibility')->with('search')->once();
        $mockProduct->shouldReceive('set_short_description')->with('test excerpt')->once();
        $mockProduct->shouldReceive('set_regular_price')->with('')->once();
        $mockProduct->shouldReceive('set_sale_price')->with('')->once();
        $mockProduct->shouldReceive('save')->once();

        Functions\when('wc_get_product')->justReturn($mockProduct);

        $mockDistributor = Mockery::mock('StockSync_Distributor');
        $mockDistributor->shouldReceive('get_unavailable_description')
            ->with('Product Alpha')
            ->andReturn('test excerpt');

        $mockLogger = Mockery::mock('Logger_Interface');
        $mockLogger->shouldReceive('log')
            ->once()
            ->with(Mockery::on(function ($data) {
                return $data['product_id'] === 1
                    && $data['sku'] === 'SKU123'
                    && $data['action'] === 'marked_unavailable'
                    && $data['old_visibility'] === 'visible'
                    && $data['new_visibility'] === 'search'
                    && $data['old_excerpt'] === 'Old excerpt'
                    && $data['new_excerpt'] === 'test excerpt'
                    && $data['old_price'] === '100'
                    && $data['old_sale_price'] === '90'
                    && $data['distributor_slug'] === 'vininova'
                    && $data['distributor_ref'] === 'REF123';
            }));

        $updater = new StockSync_Product_Updater($mockLogger);

        $product = new StockSync_Standard_Product([
            'distributor_slug' => 'vininova',
            'distributor_ref'  => 'REF123',
            'product_name'     => 'Product Alpha',
        ]);

        $result = $updater->mark_unavailable(1, $product, $mockDistributor);

        $this->assertTrue($result);
    }

    public function test_mark_unavailable_returns_wp_error_when_product_not_found() {
        Functions\when('wc_get_product')->justReturn(false);

        $mockLogger = Mockery::mock('Logger_Interface');
        $mockLogger->shouldNotReceive('log');

        $updater = new StockSync_Product_Updater($mockLogger);

        $product = new StockSync_Standard_Product([
            'distributor_slug' => 'vininova',
            'distributor_ref'  => 'REF123',
            'product_name'     => 'Product Alpha',
        ]);

        $mockDistributor = Mockery::mock('StockSync_Distributor');

        $result = $updater->mark_unavailable(1, $product, $mockDistributor);

        $this->assertInstanceOf('WP_Error', $result);
    }
}
