<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
/**
 * Tests for StockSync_Product_Updater.
 */


class Test_Product_Updater extends \PHPUnit\Framework\TestCase {

	/**
	 * Prepare the test environment before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when('wp_kses_post')->justReturn('test excerpt');
		Functions\when('sanitize_title')->alias(function ($s) {
			return str_replace(' ', '-', strtolower($s));
		});
		Functions\when('get_the_terms')->justReturn(false);
	}
    /**
     * Clean up Brain Monkey and Mockery after each test.
     */

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * Verifies that mark_unavailable updates a product's visibility, excerpt, prices, name, and saves the product.
	 */
	public function test_mark_unavailable_calls_correct_setters() {
		$mockProduct = Mockery::mock('WC_Product');
		$mockProduct->shouldReceive('get_status')->andReturn('publish');
		$mockProduct->shouldReceive('get_catalog_visibility')->andReturn('visible');
		$mockProduct->shouldReceive('get_short_description')->andReturn('Old excerpt > previous text');
		$mockProduct->shouldReceive('get_regular_price')->andReturn('100');
		$mockProduct->shouldReceive('get_sale_price')->andReturn('90');
		$mockProduct->shouldReceive('get_sku')->andReturn('SKU123');
		$mockProduct->shouldReceive('get_name')->andReturn('Product Alpha - 108 zł.**');
		$mockProduct->shouldReceive('get_slug')->andReturn('product-alpha-108-zl');
		$mockProduct->shouldReceive('set_name')->with('Product Alpha')->once();
		$mockProduct->shouldReceive('set_catalog_visibility')->with('search')->once();
		$mockProduct->shouldReceive('set_short_description')->with('Old excerpt > test excerpt')->once();
		$mockProduct->shouldReceive('set_regular_price')->with('')->once();
		$mockProduct->shouldReceive('set_sale_price')->with('')->once();
		$mockProduct->shouldReceive('save')->once();

		Functions\when('wc_get_product')->justReturn($mockProduct);

		$mockDistributor = Mockery::mock('StockSync_Distributor');
		$mockDistributor->shouldReceive('get_name')->andReturn('Vininova');
		$mockDistributor->shouldReceive('get_unavailable_suffix')
			->andReturn('test excerpt');

		$updater = new StockSync_Product_Updater();

		$product = new StockSync_Standard_Product([
			'distributor_slug' => 'vininova',
			'distributor_ref'  => 'REF123',
			'product_name'     => 'Product Alpha',
		]);

		$result = $updater->mark_unavailable(1, $product, $mockDistributor);

		$this->assertTrue($result);
	}
    /**
     * Verify that mark_unavailable returns a WP_Error when the product is not found.
     */

	public function test_mark_unavailable_returns_wp_error_when_product_not_found() {
		Functions\when('wc_get_product')->justReturn(false);

		$updater = new StockSync_Product_Updater();

		$product = new StockSync_Standard_Product([
			'distributor_slug' => 'vininova',
			'distributor_ref'  => 'REF123',
			'product_name'     => 'Product Alpha',
		]);

		$mockDistributor = Mockery::mock('StockSync_Distributor');

		$result = $updater->mark_unavailable(1, $product, $mockDistributor);

		$this->assertInstanceOf('WP_Error', $result);
	}
    /**
     * Verify that leading Polish price patterns are removed from the product name.
     */

	public function test_mark_unavailable_cleans_leading_price() {
		$mockProduct = Mockery::mock('WC_Product');
		$mockProduct->shouldReceive('get_status')->andReturn('publish');
		$mockProduct->shouldReceive('get_catalog_visibility')->andReturn('visible');
		$mockProduct->shouldReceive('get_short_description')->andReturn('Old excerpt > previous text');
		$mockProduct->shouldReceive('get_regular_price')->andReturn('100');
		$mockProduct->shouldReceive('get_sale_price')->andReturn('90');
		$mockProduct->shouldReceive('get_sku')->andReturn('SKU123');
		$mockProduct->shouldReceive('get_name')->andReturn('57,72 zł.** Dry Hills');
		$mockProduct->shouldReceive('get_slug')->andReturn('5772-zl-dry-hills');
		$mockProduct->shouldReceive('set_name')->with('Dry Hills')->once();
		$mockProduct->shouldReceive('set_catalog_visibility')->with('search')->once();
		$mockProduct->shouldReceive('set_short_description')->with('Old excerpt > test excerpt')->once();
		$mockProduct->shouldReceive('set_regular_price')->with('')->once();
		$mockProduct->shouldReceive('set_sale_price')->with('')->once();
		$mockProduct->shouldReceive('save')->once();

		Functions\when('wc_get_product')->justReturn($mockProduct);

		$mockDistributor = Mockery::mock('StockSync_Distributor');
		$mockDistributor->shouldReceive('get_name')->andReturn('Vininova');
		$mockDistributor->shouldReceive('get_unavailable_suffix')
			->andReturn('test excerpt');

		$updater = new StockSync_Product_Updater();

		$product = new StockSync_Standard_Product([
			'distributor_slug' => 'vininova',
			'distributor_ref'  => 'REF456',
			'product_name'     => '57,72 zł.** Dry Hills',
		]);

		$result = $updater->mark_unavailable(2, $product, $mockDistributor);

		$this->assertTrue($result);
	}
    /**
     * Verify that the excerpt uses the product name as prefix when no delimiter exists.
     */

	public function test_mark_unavailable_builds_excerpt_from_name_when_no_gt() {
		$mockProduct = Mockery::mock('WC_Product');
		$mockProduct->shouldReceive('get_status')->andReturn('publish');
		$mockProduct->shouldReceive('get_catalog_visibility')->andReturn('visible');
		$mockProduct->shouldReceive('get_short_description')->andReturn('Some plain text without delimiter');
		$mockProduct->shouldReceive('get_regular_price')->andReturn('100');
		$mockProduct->shouldReceive('get_sale_price')->andReturn('90');
		$mockProduct->shouldReceive('get_sku')->andReturn('SKU789');
		$mockProduct->shouldReceive('get_name')->andReturn('Clean Name');
		$mockProduct->shouldReceive('get_slug')->andReturn('clean-name');
		$mockProduct->shouldReceive('set_catalog_visibility')->with('search')->once();
		$mockProduct->shouldReceive('set_short_description')->with('Clean Name > test excerpt')->once();
		$mockProduct->shouldReceive('set_regular_price')->with('')->once();
		$mockProduct->shouldReceive('set_sale_price')->with('')->once();
		$mockProduct->shouldReceive('save')->once();

		Functions\when('wc_get_product')->justReturn($mockProduct);

		$mockDistributor = Mockery::mock('StockSync_Distributor');
		$mockDistributor->shouldReceive('get_name')->andReturn('Vininova');
		$mockDistributor->shouldReceive('get_unavailable_suffix')
			->andReturn('test excerpt');

		$updater = new StockSync_Product_Updater();

		$product = new StockSync_Standard_Product([
			'distributor_slug' => 'vininova',
			'distributor_ref'  => 'REF789',
			'product_name'     => 'Clean Name',
		]);

		$result = $updater->mark_unavailable(3, $product, $mockDistributor);

		$this->assertTrue($result);
	}

	/**
	 * Verify that mark_unavailable skips draft products.
	 */
	public function test_mark_unavailable_skips_drafts() {
		$mockProduct = Mockery::mock('WC_Product');
		$mockProduct->shouldReceive('get_status')->andReturn('draft');

		Functions\when('wc_get_product')->justReturn($mockProduct);

		$updater = new StockSync_Product_Updater();

		$product = new StockSync_Standard_Product([
			'distributor_slug' => 'vininova',
			'distributor_ref'  => 'REF123',
			'product_name'     => 'Product Alpha',
		]);

		$mockDistributor = Mockery::mock('StockSync_Distributor');

		$result = $updater->mark_unavailable(1, $product, $mockDistributor);

		$this->assertInstanceOf('WP_Error', $result);
		$this->assertSame('product_is_draft', $result->get_error_code());
	}

	/**
	 * Verifies that mark_published updates a product's visibility, prices, excerpt, name, and saves the product.
	 */
	public function test_mark_published_calls_correct_setters() {
		$mockProduct = Mockery::mock('WC_Product');
		$mockProduct->shouldReceive('get_status')->andReturn('publish');
		$mockProduct->shouldReceive('get_catalog_visibility')->andReturn('search');
		$mockProduct->shouldReceive('get_short_description')->andReturn('Old excerpt > previous text');
		$mockProduct->shouldReceive('get_regular_price')->andReturn('');
		$mockProduct->shouldReceive('get_sale_price')->andReturn('');
		$mockProduct->shouldReceive('get_sku')->andReturn('SKU123');
		$mockProduct->shouldReceive('get_name')->andReturn('Product Alpha - 108 zł.**');
		$mockProduct->shouldReceive('get_slug')->andReturn('product-alpha-108-zl');
		$mockProduct->shouldReceive('set_name')->with('Product Alpha')->once();
		$mockProduct->shouldReceive('set_catalog_visibility')->with('visible')->once();
		$mockProduct->shouldReceive('set_short_description')->with(Mockery::on(function($arg) {
			return strpos($arg, 'Old excerpt >') === 0;
		}))->once();
		$mockProduct->shouldReceive('set_regular_price')->with('108.00')->once();
		$mockProduct->shouldReceive('save')->once();

		Functions\when('wc_get_product')->justReturn($mockProduct);

		$mockDistributor = Mockery::mock('StockSync_Distributor');
		$mockDistributor->shouldReceive('get_name')->andReturn('Vininova');
		$mockDistributor->shouldReceive('get_listed_suffix')
			->andReturnUsing(function($name, $dist_name) {
				return $name . ' - ' . $dist_name . ' > test listed suffix';
			});

		$updater = new StockSync_Product_Updater();

		$product = new StockSync_Standard_Product([
			'distributor_slug' => 'vininova',
			'distributor_ref'  => 'REF123',
			'product_name'     => 'Product Alpha',
			'price'            => 12.50,
		]);

		$result = $updater->mark_published(1, $product, $mockDistributor);

		$this->assertTrue($result);
	}

	/**
	 * Verify that mark_published returns a WP_Error for draft products.
	 */
	public function test_mark_published_skips_drafts() {
		$mockProduct = Mockery::mock('WC_Product');
		$mockProduct->shouldReceive('get_status')->andReturn('draft');

		Functions\when('wc_get_product')->justReturn($mockProduct);

		$updater = new StockSync_Product_Updater();

		$product = new StockSync_Standard_Product([
			'distributor_slug' => 'vininova',
			'distributor_ref'  => 'REF123',
			'product_name'     => 'Product Alpha',
			'price'            => 12.50,
		]);

		$mockDistributor = Mockery::mock('StockSync_Distributor');

		$result = $updater->mark_published(1, $product, $mockDistributor);

		$this->assertInstanceOf('WP_Error', $result);
		$this->assertSame('product_is_draft', $result->get_error_code());
	}
}
