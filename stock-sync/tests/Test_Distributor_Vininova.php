<?php

class Test_Distributor_Vininova extends PHPUnit\Framework\TestCase {

	private StockSync_Distributor_Vininova $distributor;

	/**
	 * Prepare the test fixture by instantiating the Vininova distributor.
	 *
	 * Assigns a new StockSync_Distributor_Vininova to $this->distributor before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->distributor = new StockSync_Distributor_Vininova();
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
		$this->assertFalse($this->distributor->is_product_row([1 => '123']));
		$this->assertFalse($this->distributor->is_product_row([1 => 'aB123']));
		$this->assertFalse($this->distributor->is_product_row([1 => '']));
		$this->assertFalse($this->distributor->is_product_row([1 => 'A1']));
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
}
