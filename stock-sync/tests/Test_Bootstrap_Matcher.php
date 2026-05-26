<?php

use Brain\Monkey\Functions;

class Test_Bootstrap_Matcher extends PHPUnit\Framework\TestCase {

	private StockSync_Bootstrap_Matcher $matcher;

	/ **
	 * Prepare the test environment and instantiate a StockSync_Bootstrap_Matcher with a stubbed product repository.
	 *
	 * Sets up Brain Monkey and registers stubs for WordPress functions (`update_post_meta`, `sanitize_text_field`),
	 * provides an in-memory anonymous implementation of Product_Repository_Interface that returns a fixed product list,
	 * and stores the matcher instance on `$this->matcher` for use in tests.
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();

		Functions\when('update_post_meta')->alias(function ($post_id, $meta_key, $meta_value) {
			return true;
		});
		Functions\when('sanitize_text_field')->alias(function ($text) {
			return $text;
		});

		$repository = new class implements Product_Repository_Interface {
			public function find_by_id($product_id) { return null; }
			public function find_all($category = null) {
				return [
					['id' => 1, 'name' => 'Red Wine', 'sku' => 'RW001'],
					['id' => 2, 'name' => 'White Wine', 'sku' => 'WW002'],
				];
			}
			public function find_by_meta($meta_key, $meta_value) { return null; }
			public function find_by_sku($sku) { return null; }
			public function save($product) { return true; }
		};

		$this->matcher = new StockSync_Bootstrap_Matcher($repository);
	}

	/**
	 * Restore Brain Monkey and invoke the parent teardown to clean up the test environment.
	 */
	protected function tearDown(): void {
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	public function test_normalize_name_accents() {
		$this->assertSame('cafe', $this->matcher->normalize_name('café'));
		$this->assertSame('naive', $this->matcher->normalize_name('naïve'));
	}

	public function test_normalize_name_special_chars() {
		$this->assertSame('product', $this->matcher->normalize_name('product @#$%'));
	}

	public function test_normalize_name_extra_spaces() {
		$this->assertSame('hello world', $this->matcher->normalize_name('  hello   world  '));
	}

	public function test_calculate_confidence_exact_match() {
		$this->assertSame(100, $this->matcher->calculate_confidence('Red Wine', 'Red Wine'));
	}

	public function test_calculate_confidence_substring() {
		$this->assertSame(90, $this->matcher->calculate_confidence('Red Wine', 'Fine Red Wine'));
	}

	/**
	 * Verifies that calculating confidence for two strings with an 80% token overlap
	 * (Jaccard similarity) returns 80.
	 */
	public function test_calculate_confidence_jaccard_80() {
		$xlsx = 'one two three four five';
		$wc   = 'one two four three';
		$this->assertSame(80, $this->matcher->calculate_confidence($xlsx, $wc));
	}

	public function test_calculate_confidence_jaccard_60() {
		$xlsx = 'one two three four';
		$wc   = 'one two three five';
		$this->assertSame(60, $this->matcher->calculate_confidence($xlsx, $wc));
	}

	public function test_calculate_confidence_levenshtein_70() {
		$this->assertSame(70, $this->matcher->calculate_confidence('hello world', 'hello wrld'));
	}

	public function test_calculate_confidence_empty_names() {
		$this->assertSame(0, $this->matcher->calculate_confidence('', 'Red Wine'));
		$this->assertSame(0, $this->matcher->calculate_confidence('Red Wine', ''));
	}

	/**
	 * Verifies that calculate_confidence returns 0 when two strings have no matching characters or tokens.
	 *
	 * @return void
	 */
	public function test_calculate_confidence_no_match() {
		$this->assertSame(0, $this->matcher->calculate_confidence('abcdefgh', 'xyz12345'));
	}

	/**
	 * Verifies that confidence values map to the expected post-match status.
	 *
	 * Confirms the mapping: 100 and 95 => 'auto', 80 and 70 => 'suggest', 60 and 0 => 'manual'.
	 */
	public function test_get_status_from_confidence_thresholds() {
		$method = new ReflectionMethod(StockSync_Bootstrap_Matcher::class, 'get_status_from_confidence');

		$this->assertSame('auto', $method->invoke($this->matcher, 100));
		$this->assertSame('auto', $method->invoke($this->matcher, 95));
		$this->assertSame('suggest', $method->invoke($this->matcher, 80));
		$this->assertSame('suggest', $method->invoke($this->matcher, 70));
		$this->assertSame('manual', $method->invoke($this->matcher, 60));
		$this->assertSame('manual', $method->invoke($this->matcher, 0));
	}

	public function test_save_mappings_empty_inputs() {
		$this->assertSame(0, $this->matcher->save_mappings([], '_supplier_ref_test'));
	}

	public function test_save_mappings_missing_fields() {
		$matches = [
			['wc_id' => 1, 'distributor_ref' => ''],
			['wc_id' => null, 'distributor_ref' => 'REF001'],
			['wc_id' => 2, 'distributor_ref' => 'REF002'],
		];
		$this->assertSame(1, $this->matcher->save_mappings($matches, '_supplier_ref_test'));
	}

	public function test_save_mappings_valid_save() {
		$matches = [
			['wc_id' => 1, 'distributor_ref' => 'REF001'],
			['wc_id' => 2, 'distributor_ref' => 'REF002'],
		];
		$this->assertSame(2, $this->matcher->save_mappings($matches, '_supplier_ref_test'));
	}
}
