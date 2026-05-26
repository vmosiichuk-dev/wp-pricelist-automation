<?php

use Brain\Monkey;
use Brain\Monkey\Functions;

class Test_XLSX_Parser extends \PHPUnit\Framework\TestCase {

    /**
     * Prepare the test environment by invoking the parent setup, initializing Brain Monkey, and stubbing translation functions.
     */
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\stubTranslationFunctions();
    }

    /**
     * Clean up test environment after each test.
     *
     * Performs framework and mocking teardown: restores Brain Monkey state, closes Mockery, and invokes the parent tearDown.
     */
    protected function tearDown(): void {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Verifies that the XLSX parser extracts the expected number of products and marks unavailable items.
     *
     * Parses the sample-vininova.xlsx for the Vininova distributor, asserts parsing succeeded, that exactly 4 products are returned, and that 3 of them have a truthy `is_unavailable` property.
     */
    public function test_parses_correct_number_of_products() {
        $distributor = new StockSync_Distributor_Vininova();
        $parser = new StockSync_XLSX_Parser(
            __DIR__ . '/fixtures/sample-vininova.xlsx',
            $distributor
        );

        $products = $parser->parse();

        $this->assertNotInstanceOf('WP_Error', $products);
        $this->assertCount(4, $products);

        $unavailable = array_filter($products, function ($p) {
            return $p->is_unavailable;
        });

        $this->assertCount(3, $unavailable);
    }

    /**
     * Verifies that the XLSX parser maps missing (sparse) cell values to empty strings.
     *
     * Parses the sample Vininova spreadsheet, asserts exactly four products are returned,
     * and checks that for distributor refs `CD456`, `EF789`, and `AB124` the `ean`,
     * `product_name`, and `vintage` fields respectively are empty strings when the
     * source cells are missing.
     *
     * @return void
     */
    public function test_sparse_row_handling() {
        $distributor = new StockSync_Distributor_Vininova();
        $parser = new StockSync_XLSX_Parser(
            __DIR__ . '/fixtures/sample-vininova.xlsx',
            $distributor
        );

        $products = $parser->parse();

        $this->assertNotInstanceOf('WP_Error', $products);
        $this->assertCount(4, $products);

        $cd456 = array_values(array_filter($products, function ($p) {
            return $p->distributor_ref === 'CD456';
        }))[0] ?? null;
        $this->assertNotNull($cd456);
        $this->assertSame('', $cd456->ean);

        $ef789 = array_values(array_filter($products, function ($p) {
            return $p->distributor_ref === 'EF789';
        }))[0] ?? null;
        $this->assertNotNull($ef789);
        $this->assertSame('', $ef789->product_name);

        $ab124 = array_values(array_filter($products, function ($p) {
            return $p->distributor_ref === 'AB124';
        }))[0] ?? null;
        $this->assertNotNull($ab124);
        $this->assertSame('', $ab124->vintage);
    }

    public function test_invalid_zip_returns_wp_error() {
        $tmpFile = tempnam(sys_get_temp_dir(), 'stock_sync_test_');
        file_put_contents($tmpFile, 'this is not a zip file');

        $distributor = new StockSync_Distributor_Vininova();
        $parser = new StockSync_XLSX_Parser($tmpFile, $distributor);

        $result = $parser->parse();

        unlink($tmpFile);

        $this->assertInstanceOf('WP_Error', $result);
    }

    /**
     * Verifies that parsing an .xlsx containing malformed worksheet XML produces a WP_Error.
     *
     * Creates a temporary .xlsx with an intentionally broken worksheet XML, enables libxml
     * internal error handling for the parse, invokes the parser, cleans up created files,
     * and asserts the result is an instance of `WP_Error`.
     */
    public function test_malformed_xml_returns_wp_error() {
        $tmpDir = sys_get_temp_dir() . '/stock_sync_test_' . uniqid();
        mkdir($tmpDir, 0777, true);
        mkdir($tmpDir . '/xl/worksheets', 0777, true);

        file_put_contents($tmpDir . '/xl/worksheets/sheet1.xml', '<broken>');
        file_put_contents(
            $tmpDir . '/xl/sharedStrings.xml',
            '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"></sst>'
        );

        $zipFile = $tmpDir . '.xlsx';
        $zip = new ZipArchive();
        $zip->open($zipFile, ZipArchive::CREATE);
        $zip->addFile($tmpDir . '/xl/worksheets/sheet1.xml', 'xl/worksheets/sheet1.xml');
        $zip->addFile($tmpDir . '/xl/sharedStrings.xml', 'xl/sharedStrings.xml');
        $zip->close();

        $distributor = new StockSync_Distributor_Vininova();
        $parser = new StockSync_XLSX_Parser($zipFile, $distributor);

        $previous_libxml = libxml_use_internal_errors(true);
        $result = $parser->parse();
        libxml_clear_errors();
        libxml_use_internal_errors($previous_libxml);

        unlink($zipFile);
        foreach (glob($tmpDir . '/xl/worksheets/*') as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        foreach (glob($tmpDir . '/xl/*') as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($tmpDir . '/xl/worksheets');
        rmdir($tmpDir . '/xl');
        rmdir($tmpDir);

        $this->assertInstanceOf('WP_Error', $result);
    }

    /**
     * Verifies that only rows representing valid products are kept when parsing an XLSX worksheet.
     *
     * Builds a minimal .xlsx containing header and candidate rows, parses it with StockSync_XLSX_Parser,
     * and asserts that exactly one product is returned with `distributor_ref` equal to "AB123".
     */
    public function test_is_product_row_filtering() {
        $tmpDir = sys_get_temp_dir() . '/stock_sync_test_' . uniqid();
        mkdir($tmpDir, 0777, true);
        mkdir($tmpDir . '/xl/worksheets', 0777, true);

        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
            '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . "\n" .
            '    <sheetData>' . "\n" .
            '        <row r="1"/>' . "\n" .
            '        <row r="2"/>' . "\n" .
            '        <row r="3"/>' . "\n" .
            '        <row r="4"/>' . "\n" .
            '        <row r="5"/>' . "\n" .
            '        <row r="6"/>' . "\n" .
            '        <row r="7"/>' . "\n" .
            '        <row r="8"/>' . "\n" .
            '        <row r="9"/>' . "\n" .
            '        <row r="10">' . "\n" .
            '            <c r="A10"><v>NR_REF</v></c>' . "\n" .
            '            <c r="B10"><v>KOD</v></c>' . "\n" .
            '            <c r="C10"><v>AVAIL</v></c>' . "\n" .
            '            <c r="D10"><v>NAME</v></c>' . "\n" .
            '            <c r="E10"><v>YEAR</v></c>' . "\n" .
            '        </row>' . "\n" .
            '        <row r="11">' . "\n" .
            '            <c r="A11"><v>AB123</v></c>' . "\n" .
            '            <c r="C11"><v>brak</v></c>' . "\n" .
            '            <c r="D11"><v>Alpha</v></c>' . "\n" .
            '        </row>' . "\n" .
            '        <row r="12">' . "\n" .
            '            <c r="A12"><v>bad</v></c>' . "\n" .
            '            <c r="C12"><v>brak</v></c>' . "\n" .
            '            <c r="D12"><v>Beta</v></c>' . "\n" .
            '        </row>' . "\n" .
            '    </sheetData>' . "\n" .
            '</worksheet>';

        file_put_contents($tmpDir . '/xl/worksheets/sheet1.xml', $sheetXml);
        file_put_contents(
            $tmpDir . '/xl/sharedStrings.xml',
            '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"></sst>'
        );

        $zipFile = $tmpDir . '.xlsx';
        $zip = new ZipArchive();
        $zip->open($zipFile, ZipArchive::CREATE);
        $zip->addFile($tmpDir . '/xl/worksheets/sheet1.xml', 'xl/worksheets/sheet1.xml');
        $zip->addFile($tmpDir . '/xl/sharedStrings.xml', 'xl/sharedStrings.xml');
        $zip->close();

        $distributor = new StockSync_Distributor_Vininova();
        $parser = new StockSync_XLSX_Parser($zipFile, $distributor);

        $products = $parser->parse();

        unlink($zipFile);
        foreach (glob($tmpDir . '/xl/worksheets/*') as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        foreach (glob($tmpDir . '/xl/*') as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($tmpDir . '/xl/worksheets');
        rmdir($tmpDir . '/xl');
        rmdir($tmpDir);

        $this->assertNotInstanceOf('WP_Error', $products);
        $this->assertCount(1, $products);
        $this->assertSame('AB123', $products[0]->distributor_ref);
    }
}
