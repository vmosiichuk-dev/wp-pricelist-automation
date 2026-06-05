<?php

use Brain\Monkey\Functions;
/**
 * Tests for StockSync_Standard_Product.
 */


class Test_Standard_Product extends PHPUnit\Framework\TestCase {

	/**
	 * Prepare the test environment and provide a deterministic `sanitize_key` implementation.
	 *
	 * Initializes the PHPUnit parent setup, starts Brain Monkey, and aliases the WordPress
	 * `sanitize_key` function to a predictable implementation that lowercases input and
	 * removes characters outside `[a-z0-9_-]` to ensure consistent behavior in tests.
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		Functions\when('sanitize_key')->alias(function ($k) {
			return preg_replace('/[^a-z0-9_-]/', '', strtolower($k));
		});
	}

	/****
	 * Restore Brain Monkey state and run the parent teardown.
	 *
	 * Calls \Brain\Monkey\tearDown() to remove any Brain Monkey function mocks/stubs,
	 * then delegates to parent::tearDown() to perform framework-level cleanup.
	 */
	protected function tearDown(): void {
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}
    /**
     * Verify that a valid distributor slug produces the correct meta key.
     */

	public function test_get_meta_key_with_valid_slug() {
		$product = new StockSync_Standard_Product([
			'distributor_slug' => 'vininova',
		]);
		$this->assertSame('_supplier_ref_vininova', $product->get_meta_key());
	}
    /**
     * Verify that an empty distributor slug returns null.
     */

	public function test_get_meta_key_with_empty_slug() {
		$product = new StockSync_Standard_Product([
			'distributor_slug' => '',
		]);
		$this->assertNull($product->get_meta_key());
	}

	/**
	 * Verifies that a distributor slug containing special characters is sanitized and used to build the meta key.
	 *
	 * Constructs a product with `distributor_slug` set to `Test-Dist!@#123`, expects the sanitized slug
	 * `test-dist123` to be appended to `_supplier_ref_` resulting in `_supplier_ref_test-dist123`.
	 */
	public function test_get_meta_key_with_special_characters() {
		$product = new StockSync_Standard_Product([
			'distributor_slug' => 'Test-Dist!@#123',
		]);
		$this->assertSame('_supplier_ref_test-dist123', $product->get_meta_key());
	}
}
