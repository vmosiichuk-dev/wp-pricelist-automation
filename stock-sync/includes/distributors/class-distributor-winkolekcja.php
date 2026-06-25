<?php
/**
 * Winkolekcja Distributor — Concrete Implementation
 *
 * Parses a 5-sheet XLSX price list:
 *   - WINKOLEKCJA (sheet1): wine collection with Symbol column
 *   - TERROIRYŚCI (sheet2): terroir wines with Symbol column
 *   - SZAMPANY (sheet3): champagnes without Symbol column
 *   - SPIRITS (sheet4): spirits with Symbol column
 *   - WYPRZEDAŻ (sheet5): clearance without Symbol column
 */
class StockSync_Distributor_Winkolekcja extends StockSync_Distributor {

	/**
	 * Return the human-readable distributor name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'Winkolekcja';
	}

	/**
	 * Return the machine-safe distributor slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'winkolekcja';
	}

	/**
	 * Return the row number containing column headers.
	 * Defaults to the first sheet's header row for backward compatibility.
	 *
	 * @return int
	 */
	public function get_header_row() {
		return 5;
	}

	/**
	 * Return the column mapping for standard fields.
	 * Defaults to the first sheet's column map for backward compatibility.
	 *
	 * @return array
	 */
	public function get_column_map() {
		return [
			'distributor_ref' => 2,
			'ean'             => null,
			'availability'    => 6,
			'product_name'    => 3,
			'vintage'         => null,
		];
	}

	/**
	 * Return sheet configurations for multi-sheet parsing.
	 *
	 * @return array
	 */
	public function get_sheet_configs() {
		return [
			[
				'sheet_name'          => 'xl/worksheets/sheet1.xml',
				'header_row'          => 5,
				'column_map'          => [
					'distributor_ref' => 2,
					'ean'             => null,
					'availability'    => 6,
					'product_name'    => 3,
					'vintage'         => null,
				],
				'header_labels'       => ['Symbol', 'Nazwa', 'Cena netto'],
				'price_header_labels' => ['Cena netto'],
				'use_name_as_ref'     => false,
			],
			[
				'sheet_name'          => 'xl/worksheets/sheet2.xml',
				'header_row'          => 5,
				'column_map'          => [
					'distributor_ref' => 2,
					'ean'             => null,
					'availability'    => 7,
					'product_name'    => 3,
					'vintage'         => null,
				],
				'header_labels'       => ['Symbol', 'Nazwa', 'Cena netto', 'ograniczona dostępność'],
				'price_header_labels' => ['Cena netto'],
				'use_name_as_ref'     => false,
			],
			[
				'sheet_name'          => 'xl/worksheets/sheet3.xml',
				'header_row'          => 5,
				'column_map'          => [
					'distributor_ref' => 1,
					'ean'             => null,
					'availability'    => 6,
					'product_name'    => 2,
					'vintage'         => null,
				],
				'header_labels'       => ['Nazwa', 'Styl', 'Cena netto', 'ograniczona dostępność'],
				'price_header_labels' => ['Cena netto'],
				'use_name_as_ref'     => true,
				'price_col'           => 5,
			],
			[
				'sheet_name'          => 'xl/worksheets/sheet4.xml',
				'header_row'          => 5,
				'column_map'          => [
					'distributor_ref' => 2,
					'ean'             => null,
					'availability'    => 7,
					'product_name'    => 3,
					'vintage'         => null,
				],
				'header_labels'       => ['Symbol', 'Nazwa', 'Cena netto', 'Cena brutto'],
				'price_header_labels' => ['Cena netto'],
				'use_name_as_ref'     => false,
			],
			[
				'sheet_name'          => 'xl/worksheets/sheet5.xml',
				'header_row'          => 5,
				'column_map'          => [
					'distributor_ref' => 1,
					'ean'             => null,
					'availability'    => 5,
					'product_name'    => 2,
					'vintage'         => null,
				],
				'header_labels'       => ['Nazwa', 'Styl', 'Cena netto'],
				'price_header_labels' => ['Cena netto'],
				'use_name_as_ref'     => true,
				'price_col'           => 5,
			],
		];
	}

	/**
	 * Determine if a parsed row represents a real product.
	 *
	 * Sheet-aware: WINKOLEKCJA / TERROIRYŚCI / SPIRITS require a valid Symbol.
	 * SZAMPANY / WYPRZEDAŻ require a product name with a non-empty price.
	 *
	 * @param array $row_data One-indexed row array from parser.
	 * @return bool
	 */
	public function is_product_row($row_data) {
		$context = $this->get_sheet_context();
		$use_name_as_ref = isset($context['use_name_as_ref']) ? $context['use_name_as_ref'] : false;
		$col_map = isset($context['column_map']) ? $context['column_map'] : $this->get_column_map();

		$product_name_col = $col_map['product_name'] ?? 3;
		$ref_col          = $col_map['distributor_ref'] ?? 2;

		$product_name = isset($row_data[$product_name_col]) ? trim($row_data[$product_name_col]) : '';
		if (empty($product_name)) {
			return false;
		}

		if ($use_name_as_ref) {
			// SZAMPANY / WYPRZEDAŻ: no Symbol column.
			// Product rows have a name and a numeric price (or "TEL").
			// Producer/category headers have a name but empty/non-numeric price.
			$price_col = $context['price_col'] ?? null;
			if ($price_col !== null) {
				$price_value = isset($row_data[$price_col]) ? trim($row_data[$price_col]) : '';
				$dot_price   = str_replace(',', '.', $price_value);
				if (!is_numeric($dot_price) && mb_strtolower($price_value) !== 'tel') {
					return false;
				}
			}

			return true;
		}

		// Symbol-based sheets (WINKOLEKCJA, TERROIRYŚCI, SPIRITS)
		$ref = isset($row_data[$ref_col]) ? trim($row_data[$ref_col]) : '';
		if (empty($ref)) {
			return false;
		}

		// Valid refs start with 2+ letters and contain at least one digit
		// (e.g. FRBBGV01, SIRWH09/BOX, ITCPB05/2023)
		if (!preg_match('/^[A-Z]{2,}.*\d.*$/i', $ref)) {
			return false;
		}

		return true;
	}

	/**
	 * Determine if an availability value means the product is unavailable.
	 *
	 * @param string $value Raw availability string.
	 * @return bool
	 */
	public function is_unavailable($value) {
		$normalized = mb_strtolower(trim($value));
		return in_array($normalized, ['tel', 'chwilowo niedostępne'], true);
	}

	/**
	 * Recognize known availability values to suppress warnings.
	 *
	 * @param string $value Raw availability string.
	 * @return bool
	 */
	public function is_known_availability($value) {
		$normalized = mb_strtolower(trim($value));

		// Empty or null
		if ($normalized === '' || $normalized === null) {
			return true;
		}

		// Numeric price (including Polish comma decimal)
		$dot_value = str_replace(',', '.', $normalized);
		if (is_numeric($dot_value)) {
			return true;
		}

		return false;
	}

	/**
	 * Bootstrap only against Winkolekcja wine category.
	 *
	 * @return string
	 */
	public function get_category_filter() {
		return 'A - Oferta Winkolekcja';
	}

	/**
	 * Return expected header column labels for auto-detection.
	 *
	 * @return array
	 */
	public function get_header_labels() {
		return ['Symbol', 'Nazwa', 'Cena netto'];
	}

	/**
	 * Return the price column header labels for auto-detection.
	 *
	 * @return array
	 */
	public function get_price_header_labels() {
		return ['Cena netto'];
	}

	/**
	 * Clean a product name by stripping distributor notes after " - ".
	 *
	 * Uses a product-specification marker heuristic: only strip if the suffix
	 * contains no technical markers (digits, %, volume units, age statements,
	 * appellations, years).
	 *
	 * @param string $name Raw product name.
	 * @return string
	 */
	public function clean_product_name($name) {
		$name = trim($name);
		if (strpos($name, ' - ') === false) {
			return $name;
		}

		$parts = explode(' - ', $name, 2);
		$suffix = isset($parts[1]) ? trim($parts[1]) : '';

		if (empty($suffix)) {
			return $name;
		}

		// Product specification markers:
		// digits, %, volume units, age statements, appellations, years
		$spec_pattern = '/\d|%|\b(cl|ml|L|YO|DOC|AOC|IGP|DOCG|AOP|IGT|VDP|GIV)\b|\b20\d{2}\b/i';
		if (preg_match($spec_pattern, $suffix)) {
			return $name;
		}

		return trim($parts[0]);
	}

	/**
	 * Generate a deterministic distributor reference from a product name.
	 *
	 * @param string $name Product name.
	 * @return string
	 */
	public function generate_ref_from_name($name) {
		$clean = $this->clean_product_name($name);
		$hash  = strtoupper(substr(md5(trim($clean)), 0, 8));
		return 'WNKL-' . $hash;
	}
}
