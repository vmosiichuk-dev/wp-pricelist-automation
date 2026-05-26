<?php

use Brain\Monkey\Functions;

class Test_Standard_Product extends PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		Functions\when('sanitize_key')->alias(function ($k) {
			return preg_replace('/[^a-z0-9_-]/', '', strtolower($k));
		});
	}

	protected function tearDown(): void {
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	public function test_get_meta_key_with_valid_slug() {
		$product = new StockSync_Standard_Product([
			'distributor_slug' => 'vininova',
		]);
		$this->assertSame('_supplier_ref_vininova', $product->get_meta_key());
	}

	public function test_get_meta_key_with_empty_slug() {
		$product = new StockSync_Standard_Product([
			'distributor_slug' => '',
		]);
		$this->assertNull($product->get_meta_key());
	}

	public function test_get_meta_key_with_special_characters() {
		$product = new StockSync_Standard_Product([
			'distributor_slug' => 'Test-Dist!@#123',
		]);
		$this->assertSame('_supplier_ref_test-dist123', $product->get_meta_key());
	}
}
