<?php
/**
 * XLSX Parser — Core PHP (ZipArchive + SimpleXML)
 * Accepts a distributor config to handle different file structures.
 */
class StockSync_XLSX_Parser {

    private $file_path;
    private $distributor;
    private $unrecognized_availability = [];
    private $price_col_index = null;

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
     * Get unrecognized availability values encountered during parsing.
     *
     * @return array
     */
    public function get_unrecognized_availability() {
        return array_keys($this->unrecognized_availability);
    }

    /**
     * Parse the XLSX and return an array of StockSync_Standard_Product objects
     */
    public function parse() {
        $this->unrecognized_availability = [];
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

        $expected_labels = array_map([$this, 'clean_value'], $this->distributor->get_effective_header_labels());
        $header_found    = empty($expected_labels);
        $header_row_num  = $this->distributor->get_header_row();
        $max_scan_rows   = 20;

        foreach ($xml->sheetData->row as $row) {
            $row_index++;
            $row_num = isset($row['r']) ? (int) $row['r'] : $row_index;

            if (!$header_found) {
                if ($row_index > $max_scan_rows) {
                    return new WP_Error(
                        'header_not_found',
                        sprintf(
                            /* translators: 1: number of rows scanned, 2: comma-separated expected labels */
                            __('Header row not found. Scanned first %1$d rows for labels: %2$s.', 'stock-sync'),
                            $max_scan_rows,
                            implode(', ', $expected_labels)
                        )
                    );
                }

                $found = 0;
                foreach ($row->c as $cell) {
                    $value = $this->clean_value($this->get_cell_value($cell, $shared_strings));
                    if (in_array($value, $expected_labels, true)) {
                        $found++;
                    }
                }

                $min_required = max(2, ceil(count($expected_labels) / 2));
                if ($found >= $min_required) {
                    $header_found   = true;
                    $header_row_num = $row_num;

                    // Scan header row for price column
                    $price_labels = array_map([$this, 'clean_value'], $this->distributor->get_effective_price_header_labels());
                    if (!empty($price_labels)) {
                        foreach ($row->c as $cell) {
                            $cell_ref = isset($cell['r']) ? (string) $cell['r'] : '';
                            $col_index = 0;
                            if ($cell_ref) {
                                $col_index = $this->excel_col_to_index($cell_ref);
                            }
                            $value = $this->clean_value($this->get_cell_value($cell, $shared_strings));
                            if (in_array($value, $price_labels, true)) {
                                $this->price_col_index = $col_index;
                                break;
                            }
                        }
                    }
                }
                continue;
            }

            if ($row_num <= $header_row_num) {
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

                $value = $this->clean_value($this->get_cell_value($cell, $shared_strings));
                $row_data[$col_index] = $value;
            }

            if (!$this->distributor->is_product_row($row_data)) {
                continue;
            }

            $availability = isset($row_data[$col_map['availability']])
                ? $row_data[$col_map['availability']]
                : '';

            if ($availability !== '' && !$this->distributor->is_unavailable($availability) && !$this->distributor->is_known_availability($availability)) {
                $this->unrecognized_availability[$availability] = true;
            }

            $price = null;
            if ($this->price_col_index !== null && isset($row_data[$this->price_col_index])) {
                $raw_price = $row_data[$this->price_col_index];
                // Normalize Polish comma decimal to dot
                $raw_price = str_replace(',', '.', $raw_price);
                if (is_numeric($raw_price)) {
                    $price = floatval($raw_price);
                }
            }

            $products[] = new StockSync_Standard_Product([
                'distributor_ref'  => isset($row_data[$col_map['distributor_ref']]) ? $row_data[$col_map['distributor_ref']] : '',
                'ean'              => isset($row_data[$col_map['ean']]) ? $row_data[$col_map['ean']] : '',
                'product_name'     => isset($row_data[$col_map['product_name']]) ? $row_data[$col_map['product_name']] : '',
                'vintage'          => isset($row_data[$col_map['vintage']]) ? $row_data[$col_map['vintage']] : '',
                'availability_raw' => $availability,
                'is_unavailable'   => $this->distributor->is_unavailable($availability),
                'distributor_slug' => $this->distributor->get_slug(),
                'price'            => $price,
            ]);
        }

        if (!$header_found) {
            return new WP_Error(
                'header_not_found',
                sprintf(
                    /* translators: 1: number of rows scanned, 2: comma-separated expected labels */
                    __('Header row not found. Scanned first %1$d rows for labels: %2$s.', 'stock-sync'),
                    $max_scan_rows,
                    implode(', ', $expected_labels)
                )
            );
        }

        if (empty($products)) {
            return new WP_Error(
                'no_products',
                __('No valid product rows found after the header. The reference format or file structure may have changed.', 'stock-sync')
            );
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
     * Read the value of a single cell, handling shared strings.
     */
    private function get_cell_value($cell, $shared_strings) {
        $cell_type = (string) $cell['t'];
        if ($cell_type === 's') {
            $string_index = (int) $cell->v;
            return isset($shared_strings[$string_index]) ? $shared_strings[$string_index] : '';
        }
        if ($cell_type === 'inlineStr') {
            if (isset($cell->is->t)) {
                return (string) $cell->is->t;
            }
            if (isset($cell->is->r)) {
                $text = '';
                foreach ($cell->is->r as $run) {
                    $text .= (string) $run->t;
                }
                return $text;
            }
            return '';
        }
        return isset($cell->v) ? (string) $cell->v : '';
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
