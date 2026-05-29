<?php

use Brain\Monkey;
use Brain\Monkey\Functions;

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

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * Verifies that mark_unavailable updates a product's visibility, excerpt, prices, name, slug, saves the product, and logs a `marked_unavailable` event with expected product and distributor details.
	 */
	public function test_mark_unavailable_calls_correct_setters() {
		$wp_update_post_called = false;
		Functions\when('wp_update_post')->alias(function ($args) use (&$wp_update_post_called) {
			$wp_update_post_called = true;
			$this->assertSame(1, $args['ID']);
			$this->assertSame('product-alpha', $args['post_name']);
			return 1;
		});

		$mockProduct = Mockery::mock('WC_Product');
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

		$mockLogger = Mockery::mock('Logger_Interface');
		$mockLogger->shouldReceive('log')
			->once()
			->with(Mockery::on(function ($data) {
				return $data['product_id'] === 1
					&& $data['sku'] === 'SKU123'
					&& $data['action'] === 'marked_unavailable'
					&& $data['old_visibility'] === 'visible'
					&& $data['new_visibility'] === 'search'
					&& $data['old_excerpt'] === 'Old excerpt > previous text'
					&& $data['new_excerpt'] === 'Old excerpt > test excerpt'
					&& $data['old_price'] === '100'
					&& $data['old_sale_price'] === '90'
					&& $data['old_name'] === 'Product Alpha - 108 zł.**'
					&& $data['new_name'] === 'Product Alpha'
					&& $data['old_slug'] === 'product-alpha-108-zl'
					&& $data['new_slug'] === 'product-alpha'
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
		$this->assertTrue($wp_update_post_called, 'wp_update_post should be called when slug changes');
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

	public function test_mark_unavailable_cleans_leading_price() {
		$wp_update_post_called = false;
		Functions\when('wp_update_post')->alias(function ($args) use (&$wp_update_post_called) {
			$wp_update_post_called = true;
			$this->assertSame(2, $args['ID']);
			$this->assertSame('dry-hills', $args['post_name']);
			return 1;
		});

		$mockProduct = Mockery::mock('WC_Product');
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

		$mockLogger = Mockery::mock('Logger_Interface');
		$mockLogger->shouldReceive('log')->once();

		$updater = new StockSync_Product_Updater($mockLogger);

		$product = new StockSync_Standard_Product([
			'distributor_slug' => 'vininova',
			'distributor_ref'  => 'REF456',
			'product_name'     => '57,72 zł.** Dry Hills',
		]);

		$result = $updater->mark_unavailable(2, $product, $mockDistributor);

		$this->assertTrue($result);
		$this->assertTrue($wp_update_post_called, 'wp_update_post should be called when slug changes');
	}

	public function test_mark_unavailable_builds_excerpt_from_name_when_no_gt() {
		$wp_update_post_called = false;
		Functions\when('wp_update_post')->alias(function ($args) use (&$wp_update_post_called) {
			$wp_update_post_called = true;
			return 1;
		});

		$mockProduct = Mockery::mock('WC_Product');
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

		$mockLogger = Mockery::mock('Logger_Interface');
		$mockLogger->shouldReceive('log')->once();

		$updater = new StockSync_Product_Updater($mockLogger);

		$product = new StockSync_Standard_Product([
			'distributor_slug' => 'vininova',
			'distributor_ref'  => 'REF789',
			'product_name'     => 'Clean Name',
		]);

		$result = $updater->mark_unavailable(3, $product, $mockDistributor);

		$this->assertTrue($result);
		$this->assertFalse($wp_update_post_called, 'wp_update_post should NOT be called when slug does not change');
	}
}
