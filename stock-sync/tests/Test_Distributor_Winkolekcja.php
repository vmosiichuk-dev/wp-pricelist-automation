<?php

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * Tests for StockSync_Distributor_Winkolekcja.
 */
class Test_Distributor_Winkolekcja extends PHPUnit\Framework\TestCase {

	private StockSync_Distributor_Winkolekcja $distributor;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubEscapeFunctions();
		Functions\stubTranslationFunctions();
		$this->distributor = new StockSync_Distributor_Winkolekcja();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_get_name() {
		$this->assertSame('Winkolekcja', $this->distributor->get_name());
	}

	public function test_get_slug() {
		$this->assertSame('winkolekcja', $this->distributor->get_slug());
	}

	public function test_get_sheet_configs_returns_five_sheets() {
		$configs = $this->distributor->get_sheet_configs();
		$this->assertIsArray($configs);
		$this->assertCount(5, $configs);

		$expected_sheets = [
			'xl/worksheets/sheet1.xml',
			'xl/worksheets/sheet2.xml',
			'xl/worksheets/sheet3.xml',
			'xl/worksheets/sheet4.xml',
			'xl/worksheets/sheet5.xml',
		];
		foreach ($expected_sheets as $i => $sheet) {
			$this->assertSame($sheet, $configs[$i]['sheet_name']);
		}
	}

	public function test_is_product_row_with_valid_symbol_ref() {
		$this->distributor->set_sheet_context([
			'column_map'      => ['distributor_ref' => 2, 'product_name' => 3, 'availability' => 6],
			'use_name_as_ref' => false,
		]);
		$this->assertTrue($this->distributor->is_product_row([2 => 'FRBBGV01', 3 => 'RIESLING']));
		$this->assertTrue($this->distributor->is_product_row([2 => 'SIRWH09/BOX', 3 => 'WHISKEY']));
		$this->distributor->clear_sheet_context();
	}

	public function test_is_product_row_with_invalid_symbol_ref() {
		$this->distributor->set_sheet_context([
			'column_map'      => ['distributor_ref' => 2, 'product_name' => 3, 'availability' => 6],
			'use_name_as_ref' => false,
		]);
		$this->assertFalse($this->distributor->is_product_row([2 => '', 3 => 'RIESLING']));
		$this->assertFalse($this->distributor->is_product_row([2 => 'FRANCJA', 3 => 'BESTHEIM']));
		$this->assertFalse($this->distributor->is_product_row([2 => 'Alzacja', 3 => 'DOMAINE']));
		$this->distributor->clear_sheet_context();
	}

	public function test_is_product_row_name_based_with_price() {
		$this->distributor->set_sheet_context([
			'column_map'      => ['distributor_ref' => 1, 'product_name' => 2, 'availability' => 6],
			'use_name_as_ref' => true,
			'price_col'       => 5,
		]);
		// Product with numeric price
		$this->assertTrue($this->distributor->is_product_row([2 => 'BENOIT LAHAYE BRUT NATURE', 5 => 200.1]));
		// Product with TEL
		$this->assertTrue($this->distributor->is_product_row([2 => 'CHAMPAGNE DELISTED', 5 => 'TEL']));
		$this->distributor->clear_sheet_context();
	}

	public function test_is_product_row_name_based_without_price() {
		$this->distributor->set_sheet_context([
			'column_map'      => ['distributor_ref' => 1, 'product_name' => 2, 'availability' => 6],
			'use_name_as_ref' => true,
			'price_col'       => 5,
		]);
		// Producer header: name present but no price
		$this->assertFalse($this->distributor->is_product_row([2 => 'BENOIT LAHAYE', 5 => '']));
		// Category header: name present but no price
		$this->assertFalse($this->distributor->is_product_row([2 => 'GROWER CHAMPAGNE', 5 => '']));
		$this->distributor->clear_sheet_context();
	}

	public function test_is_unavailable_with_tel() {
		$this->assertTrue($this->distributor->is_unavailable('TEL'));
		$this->assertTrue($this->distributor->is_unavailable('tel'));
		$this->assertTrue($this->distributor->is_unavailable('  tel  '));
	}

	public function test_is_unavailable_with_chwilowo_niedostepne() {
		$this->assertTrue($this->distributor->is_unavailable('chwilowo niedostępne'));
		$this->assertTrue($this->distributor->is_unavailable('CHWILOWO NIEDOSTĘPNE'));
		$this->assertTrue($this->distributor->is_unavailable('  chwilowo niedostępne  '));
	}

	public function test_is_unavailable_rejects_available_values() {
		$this->assertFalse($this->distributor->is_unavailable(''));
		$this->assertFalse($this->distributor->is_unavailable('43.5'));
		$this->assertFalse($this->distributor->is_unavailable('200,1'));
	}

	public function test_is_known_availability_with_numeric() {
		$this->assertTrue($this->distributor->is_known_availability('43.5'));
		$this->assertTrue($this->distributor->is_known_availability('200,1'));
		$this->assertTrue($this->distributor->is_known_availability('0'));
		$this->assertTrue($this->distributor->is_known_availability(''));
	}

	public function test_is_known_availability_rejects_unknown() {
		$this->assertFalse($this->distributor->is_known_availability('brak'));
		$this->assertFalse($this->distributor->is_known_availability('wkrótce'));
	}

	public function test_get_category_filter() {
		$this->assertSame('A - Oferta Winkolekcja', $this->distributor->get_category_filter());
	}

	public function test_clean_product_name_strips_polish_note_suffix() {
		$this->assertSame(
			'BOLLINGER 007 LIMITED EDITION BOX',
			$this->distributor->clean_product_name('BOLLINGER 007 LIMITED EDITION BOX - limitowana edycja, ilości ograniczone')
		);
		$this->assertSame(
			'CHABLIS 1ER CRU VAILLON AOC 2024',
			$this->distributor->clean_product_name('CHABLIS 1ER CRU VAILLON AOC 2024 - ilości ograniczone')
		);
	}

	public function test_clean_product_name_preserves_spec_markers() {
		$this->assertSame(
			'Pearse - 12 year Single Malt, 43% 70cl',
			$this->distributor->clean_product_name('Pearse - 12 year Single Malt, 43% 70cl')
		);
		$this->assertSame(
			"Kinahan's Whiskey The Kasc Project M, 45% 70cl",
			$this->distributor->clean_product_name("Kinahan's Whiskey The Kasc Project M, 45% 70cl")
		);
		$this->assertSame(
			'DUE LUNE NERELLO MASCALESE - NERO D\'AVOLA DOC 2023',
			$this->distributor->clean_product_name("DUE LUNE NERELLO MASCALESE - NERO D'AVOLA DOC 2023")
		);
	}

	public function test_clean_product_name_no_dash() {
		$this->assertSame(
			'RIESLING CLASSIC AOC 2023',
			$this->distributor->clean_product_name('RIESLING CLASSIC AOC 2023')
		);
	}

	public function test_generate_ref_from_name_is_deterministic() {
		$name = 'BENOIT LAHAYE BRUT NATURE';
		$ref1 = $this->distributor->generate_ref_from_name($name);
		$ref2 = $this->distributor->generate_ref_from_name($name);

		$this->assertSame($ref1, $ref2);
		$this->assertStringStartsWith('WNKL-', $ref1);
		$this->assertSame(13, strlen($ref1)); // WNKL- + 8 chars
	}

	public function test_generate_ref_from_name_strips_suffixes() {
		$ref1 = $this->distributor->generate_ref_from_name('BOLLINGER SPECIAL CUVÉE');
		$ref2 = $this->distributor->generate_ref_from_name('BOLLINGER SPECIAL CUVÉE - limitowana edycja, ilości ograniczone');

		$this->assertSame($ref1, $ref2);
	}

	public function test_parses_fixture_xlsx() {
		$parser = new StockSync_XLSX_Parser(
			__DIR__ . '/fixtures/sample-winkolekcja.xlsx',
			$this->distributor
		);

		$products = $parser->parse();

		$this->assertNotInstanceOf('WP_Error', $products);
		$this->assertGreaterThan(0, count($products));

		// Verify at least one product from each sheet type
		$refs = array_map(fn($p) => $p->distributor_ref, $products);

		// WINKOLEKCJA: symbol-based refs
		$this->assertContains('FRBBGV01', $refs);
		$this->assertContains('FRBBGV03', $refs);

		// TERROIRYŚCI: symbol-based refs
		$this->assertContains('NBBO01', $refs);
		$this->assertContains('FRCDB01/2023', $refs);

		// SZAMPANY: name-based WNKL refs
		$wnkl_refs = array_filter($refs, fn($r) => strpos($r, 'WNKL-') === 0);
		$this->assertNotEmpty($wnkl_refs);

		// SPIRITS: symbol-based refs
		$this->assertContains('SPLOK01', $refs);
		$this->assertContains('SPLBR01', $refs);

		// WYPRZEDAŻ: name-based WNKL refs
		$wnkl_refs2 = array_filter($refs, fn($r) => strpos($r, 'WNKL-') === 0);
		$this->assertNotEmpty($wnkl_refs2);

		// Check unavailable products
		$unavailable = array_filter($products, fn($p) => $p->is_unavailable);
		$unavailable_refs = array_map(fn($p) => $p->distributor_ref, $unavailable);

		// FRBGW05 has TEL in WINKOLEKCJA
		$this->assertContains('FRBGW05', $unavailable_refs);
		// FRBDB03/2024 has 'chwilowo niedostępne' in TERROIRYŚCI
		$this->assertContains('FRBDB03/2024', $unavailable_refs);

		// Check name cleaning: suffix should be stripped
		$limited = array_values(array_filter($products, fn($p) => strpos($p->product_name, 'BOLLINGER 007') !== false));
		if (!empty($limited)) {
			$this->assertStringNotContainsString('ilości ograniczone', $limited[0]->product_name);
		}
	}

	public function test_parses_fixture_correct_product_count() {
		$parser = new StockSync_XLSX_Parser(
			__DIR__ . '/fixtures/sample-winkolekcja.xlsx',
			$this->distributor
		);

		$products = $parser->parse();

		$this->assertNotInstanceOf('WP_Error', $products);

		// Expected products from fixture:
		// WINKOLEKCJA: FRBBGV01, FRBBGV03, FRBBGV02 (with cleaned name), FRBGW05 (TEL) = 4
		// TERROIRYŚCI: NBBO01, FRCDB01/2023, FRBDB03/2024 (unavailable) = 3
		// SZAMPANY: BENOIT LAHAYE BRUT NATURE, BOLLINGER SPECIAL CUVÉE, BOLLINGER 007 = 3
		// SPIRITS: SPLOK01, SPLBR01, SIRWH09, SIRWH12 = 4
		// WYPRZEDAŻ: ANDRE HEUCQ BLANC DE BLANCS, MONTAUDON ROSE = 2
		// Total = 16
		$this->assertCount(16, $products);
	}

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

		$this->distributor->get_unavailable_suffix();
		$this->distributor->get_unavailable_suffix();

		$this->assertSame(1, $callCount, 'get_term_by should only be called once due to caching');
	}
}
