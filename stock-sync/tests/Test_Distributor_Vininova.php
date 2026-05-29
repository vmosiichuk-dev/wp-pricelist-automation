<?php

use Brain\Monkey;
use Brain\Monkey\Functions;

class Test_Distributor_Vininova extends PHPUnit\Framework\TestCase {

	private StockSync_Distributor_Vininova $distributor;

	/**
	 * Prepare the test fixture by instantiating the Vininova distributor.
	 *
	 * Assigns a new StockSync_Distributor_Vininova to $this->distributor before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubEscapeFunctions();
		Functions\stubTranslationFunctions();
		$this->distributor = new StockSync_Distributor_Vininova();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_get_name() {
		$this->assertSame('Vininova', $this->distributor->get_name());
	}

	public function test_get_slug() {
		$this->assertSame('vininova', $this->distributor->get_slug());
	}

	public function test_get_column_map() {
		$expected = [
			'distributor_ref' => 1,
			'ean'             => 2,
			'availability'    => 3,
			'product_name'    => 4,
			'vintage'         => 5,
		];
		$this->assertSame($expected, $this->distributor->get_column_map());
	}

	public function test_is_product_row_with_valid_ref() {
		$this->assertTrue($this->distributor->is_product_row([1 => 'AB123']));
		$this->assertTrue($this->distributor->is_product_row([1 => 'XY999']));
	}

	public function test_is_product_row_with_invalid_ref() {
		$this->assertFalse($this->distributor->is_product_row([1 => '']));
		$this->assertFalse($this->distributor->is_product_row([1 => 'Włochy']));
		$this->assertFalse($this->distributor->is_product_row([1 => 'Francja']));
		$this->assertFalse($this->distributor->is_product_row([1 => 'Antinori']));
	}

	/**
	 * Verifies that is_unavailable identifies strings that indicate unavailability and rejects available-state strings.
	 *
	 * Tests multiple variants including case differences and surrounding whitespace (e.g. 'brak', 'BRak', '  brak  ')
	 * as well as related phrases (e.g. 'chwilowy brak', 'chilowy brak', 'os', 'wkrótce'), and ensures common available
	 * descriptors (e.g. 'dostępny', 'w magazynie') are not treated as unavailable.
	 *
	 * @return void
	 */
	public function test_is_unavailable_all_flag_variants() {
		$this->assertTrue($this->distributor->is_unavailable('brak'));
		$this->assertTrue($this->distributor->is_unavailable('chwilowy brak'));
		$this->assertTrue($this->distributor->is_unavailable('chilowy brak'));
		$this->assertTrue($this->distributor->is_unavailable('os'));
		$this->assertTrue($this->distributor->is_unavailable('wkrótce'));
		$this->assertTrue($this->distributor->is_unavailable('BRak'));
		$this->assertTrue($this->distributor->is_unavailable('  brak  '));
		$this->assertFalse($this->distributor->is_unavailable('dostępny'));
		$this->assertFalse($this->distributor->is_unavailable('w magazynie'));
	}

	public function test_get_category_filter() {
		$this->assertSame('A - Oferta Vininova', $this->distributor->get_category_filter());
	}

	/**
	 * Verifies get_unavailable_suffix returns the fallback text when the wine category does not exist.
	 *
	 * @return void
	 */
	public function test_get_unavailable_suffix_without_category() {
		Functions\when('get_term_by')->justReturn(false);

		$suffix = $this->distributor->get_unavailable_suffix();

		$this->assertStringContainsString('Produkt wycofany z naszej oferty.', $suffix);
		$this->assertStringContainsString('Podobne produkty znajdziesz w naszej ofercie.', $suffix);
		$this->assertStringNotContainsString('<a ', $suffix);
	}

	/**
	 * Verifies get_unavailable_suffix returns a linked category name when the wine category exists.
	 *
	 * @return void
	 */
	public function test_get_unavailable_suffix_with_category() {
		$fakeTerm = new stdClass();
		$fakeTerm->term_id = 42;

		Functions\when('get_term_by')
			->justReturn($fakeTerm);
		Functions\when('get_term_link')
			->justReturn('https://example.com/product-category/wina-vininova/');

		$suffix = $this->distributor->get_unavailable_suffix();

		$this->assertStringContainsString('Produkt wycofany z naszej oferty.', $suffix);
		$this->assertStringContainsString('Wina Vininova', $suffix);
		$this->assertStringContainsString('<a href="https://example.com/product-category/wina-vininova/">Wina Vininova</a>', $suffix);
	}

	/**
	 * Verifies that category lookup is cached and get_term_by is only called once across multiple invocations.
	 *
	 * @return void
	 */
	public function test_category_url_is_cached() {
		$fakeTerm = new stdClass();
		$fakeTerm->term_id = 99;

		$callCount = 0;
		Functions\when('get_term_by')->alias(function () use (&$callCount, $fakeTerm) {
			$callCount++;
			return $fakeTerm;
		});
		Functions\when('get_term_link')
			->justReturn('https://example.com/cat/');

		// Call twice
		$this->distributor->get_unavailable_suffix();
		$this->distributor->get_unavailable_suffix();

		$this->assertSame(1, $callCount, 'get_term_by should only be called once due to caching');
	}
}
