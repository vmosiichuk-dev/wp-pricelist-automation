<?php
/**
 * XLSX Parser — Core PHP (ZipArchive + SimpleXML)
 * Accepts a distributor config to handle different file structures.
 */
class StockSync_XLSX_Parser {

    private $file_path;
    private $distributor;

    /**
     * Set the XLSX file path and distributor config.
     *
     * @param string                $file_path   Path to the XLSX file.
     * @param StockSync_Distributor $distributor Distributor instance.
     * @return void
     */
    public function __construct($file_path, StockSync_Distributor $distributor) {
        $this->file_path   = $file_path;
        $this->distributor = $distributor;
    }

    /**
     * Parse the XLSX and return an array of StockSync_Standard_Product objects
     */
    public function parse() {
        $zip = new ZipArchive();
        if ($zip->open($this->file_path) !== true) {
            return new WP_Error('parse_error', __('Cannot open XLSX file', 'stock-sync'));
        }

        $shared_strings = $this->get_shared_strings($zip);
        if (is_wp_error($shared_strings)) {
            $zip->close();
            return $shared_strings;
        }

        $sheet_xml = $zip->getFromName($this->distributor->get_sheet_name());
        $zip->close();

        if (!$sheet_xml) {
            return new WP_Error('parse_error', __('Cannot read worksheet', 'stock-sync'));
        }

        $xml = simplexml_load_string($sheet_xml, 'SimpleXMLElement', LIBXML_NONET);
        if ($xml === false) {
            return new WP_Error('parse_error', __('Invalid worksheet XML', 'stock-sync'));
        }

        $products  = [];
        $row_index = 0;
        $col_map   = $this->distributor->get_column_map();

        foreach ($xml->sheetData->row as $row) {
            $row_index++;

            if ($row_index <= $this->distributor->get_header_row()) {
                continue;
            }

            $row_data  = [];
            $col_index = 0;

            foreach ($row->c as $cell) {
                $cell_ref = isset($cell['r']) ? (string) $cell['r'] : '';
                if ($cell_ref) {
                    $col_index = $this->excel_col_to_index($cell_ref);
                } else {
                    $col_index++;
                }
                $cell_type = (string) $cell['t'];

                if ($cell_type === 's') {
                    $string_index = (int) $cell->v;
                    $value = isset($shared_strings[$string_index])
                        ? $shared_strings[$string_index]
                        : '';
                } else {
                    $value = isset($cell->v) ? (string) $cell->v : '';
                }

                $row_data[$col_index] = $this->clean_value($value);
            }

            if (!$this->distributor->is_product_row($row_data)) {
                continue;
            }

            $availability = isset($row_data[$col_map['availability']])
                ? $row_data[$col_map['availability']]
                : '';

            $products[] = new StockSync_Standard_Product([
                'distributor_ref'  => isset($row_data[$col_map['distributor_ref']]) ? $row_data[$col_map['distributor_ref']] : '',
                'ean'              => isset($row_data[$col_map['ean']]) ? $row_data[$col_map['ean']] : '',
                'product_name'     => isset($row_data[$col_map['product_name']]) ? $row_data[$col_map['product_name']] : '',
                'vintage'          => isset($row_data[$col_map['vintage']]) ? $row_data[$col_map['vintage']] : '',
                'availability_raw' => $availability,
                'is_unavailable'   => $this->distributor->is_unavailable($availability),
                'distributor_slug' => $this->distributor->get_slug(),
            ]);
        }

        return $products;
    }

    /**
     * Extract shared strings from XLSX
     */
    private function get_shared_strings($zip) {
        $strings_xml = $zip->getFromName('xl/sharedStrings.xml');
        if (!$strings_xml) {
            return [];
        }

        $xml = simplexml_load_string($strings_xml, 'SimpleXMLElement', LIBXML_NONET);
        if ($xml === false) {
            return new WP_Error('parse_error', __('Invalid shared strings XML', 'stock-sync'));
        }

        $strings = [];

        foreach ($xml->si as $si) {
            if (isset($si->t)) {
                $strings[] = (string) $si->t;
            } elseif (isset($si->r)) {
                $text = '';
                foreach ($si->r as $run) {
                    $text .= (string) $run->t;
                }
                $strings[] = $text;
            }
        }

        return $strings;
    }

    /**
     * Convert Excel cell reference (e.g. "B12") to 1-based column index
     */
    private function excel_col_to_index($cell_ref) {
        preg_match('/([A-Z]+)/', $cell_ref, $matches);
        $col = $matches[1] ?? 'A';

        $result = 0;
        $length = strlen($col);
        for ($i = 0; $i < $length; $i++) {
            $result = $result * 26 + (ord($col[$i]) - ord('A') + 1);
        }
        return $result;
    }

    /**
     * Clean cell value
     */
    private function clean_value($value) {
        return trim(preg_replace('/\s+/', ' ', $value));
    }
}
