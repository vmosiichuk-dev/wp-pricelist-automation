<?php

use Brain\Monkey\Functions;

/**
 * Tests for StockSync_Bootstrap_Matcher.
 */
class Test_Bootstrap_Matcher extends PHPUnit\Framework\TestCase {

	private StockSync_Bootstrap_Matcher $matcher;

	/**
	 * Create a stub Product_Repository_Interface for testing.
	 *
	 * @param array $products List of products to return from find_all().
	 * @return Product_Repository_Interface
	 */
	private function create_stub_repository(array $products): Product_Repository_Interface {
		return new class($products) implements Product_Repository_Interface {
			private array $products;

			public function __construct(array $products) {
				$this->products = $products;
			}

			public function find_by_id($product_id) { return null; }
			public function find_all($category = null) {
				return $this->products;
			}
			public function find_by_meta($meta_key, $meta_value) { return null; }
			public function find_by_sku($sku) { return null; }
			public function save($product) { return true; }
		};
	}

	/**
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

		$repository = $this->create_stub_repository([
			['id' => 1, 'name' => 'Red Wine', 'sku' => 'RW001'],
			['id' => 2, 'name' => 'White Wine', 'sku' => 'WW002'],
		]);

		$this->matcher = new StockSync_Bootstrap_Matcher($repository);
	}

	/**
	 * Restore Brain Monkey and invoke the parent teardown to clean up the test environment.
	 */
	protected function tearDown(): void {
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Verify that accented characters are normalized to ASCII.
	 */
	public function test_normalize_name_accents() {
		$this->assertSame('cafe', $this->matcher->normalize_name('café'));
		$this->assertSame('naive', $this->matcher->normalize_name('naïve'));
	}

	/**
	 * Verify that special characters are stripped during normalization.
	 */
	public function test_normalize_name_special_chars() {
		$this->assertSame('product', $this->matcher->normalize_name('product @#$%'));
	}

	/**
	 * Verify that extra whitespace is collapsed during normalization.
	 */
	public function test_normalize_name_extra_spaces() {
		$this->assertSame('hello world', $this->matcher->normalize_name('  hello   world  '));
	}

	/**
	 * Verify that identical names return a confidence of 100.
	 */
	public function test_calculate_confidence_exact_match() {
		$this->assertSame(100, $this->matcher->calculate_confidence('Red Wine', 'Red Wine'));
	}

	/**
	 * Verify that substring matches return a confidence of 90.
	 */
	public function test_calculate_confidence_substring() {
		$this->assertSame(90, $this->matcher->calculate_confidence('Red Wine', 'Fine Red Wine'));
	}

	/**
	 * Verifies that all words from the shorter name appearing in the longer name
	 * (in any order) returns 85.
	 */
	public function test_calculate_confidence_word_set_containment() {
		$xlsx = 'Domus Riserva';
		$wc   = 'Selection Nova Domus Terlaner Cuvee Riserva Alto Adige DOC';
		$this->assertSame(85, $this->matcher->calculate_confidence($xlsx, $wc));
		$this->assertSame(85, $this->matcher->calculate_confidence($wc, $xlsx));
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

	/**
	 * Verify that Jaccard similarity of 60% returns a confidence of 60.
	 */
	public function test_calculate_confidence_jaccard_60() {
		$xlsx = 'one two three four';
		$wc   = 'one two three five';
		$this->assertSame(60, $this->matcher->calculate_confidence($xlsx, $wc));
	}

	/**
	 * Verify that small Levenshtein distances return a confidence of 70.
	 */
	public function test_calculate_confidence_levenshtein_70() {
		$this->assertSame(70, $this->matcher->calculate_confidence('hello world', 'hello wrld'));
	}

	/**
	 * Verify that empty names return a confidence of 0.
	 */
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
		$this->assertSame('auto', $method->invoke($this->matcher, 90));
		$this->assertSame('suggest', $method->invoke($this->matcher, 89));
		$this->assertSame('suggest', $method->invoke($this->matcher, 70));
		$this->assertSame('manual', $method->invoke($this->matcher, 69));
		$this->assertSame('manual', $method->invoke($this->matcher, 0));
	}

	/**
	 * Verify that saving an empty mapping array returns 0.
	 */
	public function test_save_mappings_empty_inputs() {
		$this->assertSame(0, $this->matcher->save_mappings([], '_supplier_ref_test'));
	}

	/**
	 * Verify that mappings with missing fields are skipped during save.
	 */
	public function test_save_mappings_missing_fields() {
		$matches = [
			['wc_id' => 1, 'distributor_ref' => ''],
			['wc_id' => null, 'distributor_ref' => 'REF001'],
			['wc_id' => 2, 'distributor_ref' => 'REF002'],
		];
		$this->assertSame(1, $this->matcher->save_mappings($matches, '_supplier_ref_test'));
	}

	/**
	 * Verify that valid mappings are saved and counted correctly.
	 */
	public function test_save_mappings_valid_save() {
		$matches = [
			['wc_id' => 1, 'distributor_ref' => 'REF001'],
			['wc_id' => 2, 'distributor_ref' => 'REF002'],
		];
		$this->assertSame(2, $this->matcher->save_mappings($matches, '_supplier_ref_test'));
	}

	/**
	 * Verify that 4-digit years starting with 20 are extracted from product names.
	 */
	public function test_extract_years_from_name() {
		$this->assertSame(['2012'], $this->matcher->extract_years_from_name('Brunello 2012'));
		$this->assertSame([], $this->matcher->extract_years_from_name('No year'));
		$this->assertSame(['2007'], $this->matcher->extract_years_from_name('Ribolla 3781 2007'));
	}

	/**
	 * Verify that Polish price patterns are stripped before year extraction.
	 */
	public function test_extract_years_from_name_cleans_prices() {
		$this->assertSame(['2016'], $this->matcher->extract_years_from_name('Chateau Nenin 2016 - 108 zł.**'));
		$this->assertSame(['2018'], $this->matcher->extract_years_from_name('57,72 zł.** Chateau Nenin 2018'));
	}

	/**
	 * Verify that matching vintage years are correctly linked during reverse matching.
	 */
	public function test_reversed_match_year_hit() {
		$repository = $this->create_stub_repository([
			['id' => 1, 'name' => 'Chateau Nenin 2016', 'sku' => 'CN2016'],
		]);

		$matcher = new StockSync_Bootstrap_Matcher($repository);

		$xlsx = [
			new StockSync_Standard_Product([
				'distributor_ref'  => 'FR001',
				'product_name'     => 'Chateau Nenin',
				'vintage'          => '2016',
				'distributor_slug' => 'vininova',
			]),
		];

		$wc_products = $repository->find_all();
		$results = $matcher->match_all($xlsx, $wc_products);

		$this->assertCount(1, $results);
		$this->assertSame('FR001', $results[0]['distributor_ref']);
		$this->assertSame(1, $results[0]['wc_id']);
		$this->assertGreaterThanOrEqual(70, $results[0]['confidence']);
	}

	/**
	 * Verify that mismatched vintage years result in no WC product match.
	 */
	public function test_reversed_match_year_miss() {
		$repository = $this->create_stub_repository([
			['id' => 1, 'name' => 'Chateau Nenin 2016', 'sku' => 'CN2016'],
		]);

		$matcher = new StockSync_Bootstrap_Matcher($repository);

		$xlsx = [
			new StockSync_Standard_Product([
				'distributor_ref'  => 'FR001',
				'product_name'     => 'Chateau Nenin',
				'vintage'          => '2018',
				'distributor_slug' => 'vininova',
			]),
		];

		$wc_products = $repository->find_all();
		$results = $matcher->match_all($xlsx, $wc_products);

		$this->assertCount(1, $results);
		$this->assertSame('FR001', $results[0]['distributor_ref']);
		// Should be unmatched because year mismatch
		$this->assertNull($results[0]['wc_id']);
	}

	/**
	 * Verify that products without vintage years are matched without year penalty.
	 */
	public function test_reversed_match_no_year_no_penalty() {
		$repository = $this->create_stub_repository([
			['id' => 1, 'name' => 'Radikon Sivi Venezia Giulia IGT', 'sku' => 'RS001'],
		]);

		$matcher = new StockSync_Bootstrap_Matcher($repository);

		$xlsx = [
			new StockSync_Standard_Product([
				'distributor_ref'  => 'WO5501',
				'product_name'     => 'Radikon Sivi Venezia Giulia IGT',
				'vintage'          => '2021',
				'distributor_slug' => 'vininova',
			]),
		];

		$wc_products = $repository->find_all();
		$results = $matcher->match_all($xlsx, $wc_products);

		$this->assertCount(1, $results);
		$this->assertSame('WO5501', $results[0]['distributor_ref']);
		$this->assertSame(1, $results[0]['wc_id']);
		$this->assertGreaterThanOrEqual(70, $results[0]['confidence']);
	}

	/**
	 * Verify that conflicting matches are downgraded to manual status.
	 */
	public function test_reversed_conflict_downgrade() {
		$repository = $this->create_stub_repository([
			['id' => 1, 'name' => 'Chateau Nenin 2016', 'sku' => 'CN2016'],
			['id' => 2, 'name' => 'Chateau Nenin 2016 Special', 'sku' => 'CN2016S'],
		]);

		$matcher = new StockSync_Bootstrap_Matcher($repository);

		$xlsx = [
			new StockSync_Standard_Product([
				'distributor_ref'  => 'FR001',
				'product_name'     => 'Chateau Nenin',
				'vintage'          => '2016',
				'distributor_slug' => 'vininova',
			]),
		];

		$wc_products = $repository->find_all();
		$results = $matcher->match_all($xlsx, $wc_products);

		// Should return 2 conflict rows, both manual
		$conflict_rows = array_filter($results, function($r) {
			return $r['wc_id'] !== null && $r['status'] === 'manual' && $r['confidence'] === 0;
		});
		$this->assertCount(2, $conflict_rows);
	}

	/**
	 * Verify that generic refs are preferred over variant refs when scores tie.
	 */
	public function test_reversed_prefer_generic_ref() {
		$repository = $this->create_stub_repository([
			['id' => 1, 'name' => 'Radikon Sivi Venezia Giulia IGT', 'sku' => 'RS001'],
		]);

		$matcher = new StockSync_Bootstrap_Matcher($repository);

		// Generic ref and variant ref have the same name, so same fuzzy score
		$xlsx = [
			new StockSync_Standard_Product([
				'distributor_ref'  => 'WO5501',
				'base_ref'         => 'WO5501',
				'product_name'     => 'Radikon Sivi Venezia Giulia IGT',
				'vintage'          => '',
				'distributor_slug' => 'vininova',
			]),
			new StockSync_Standard_Product([
				'distributor_ref'  => 'WO5501-21',
				'base_ref'         => 'WO5501',
				'product_name'     => 'Radikon Sivi Venezia Giulia IGT',
				'vintage'          => '2021',
				'distributor_slug' => 'vininova',
			]),
		];

		$wc_products = $repository->find_all();
		$results = $matcher->match_all($xlsx, $wc_products);

		// Find the row that got matched (should be the generic one)
		$matched = array_filter($results, function($r) {
			return $r['wc_id'] !== null;
		});
		$this->assertCount(1, $matched);
		$first = array_values($matched)[0];
		$this->assertSame('WO5501', $first['distributor_ref']);
	}
}
