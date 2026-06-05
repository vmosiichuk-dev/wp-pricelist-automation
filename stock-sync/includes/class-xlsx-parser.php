<?php
/**
 * XLSX Parser — Core PHP (ZipArchive + SimpleXML)
 * Accepts a distributor config to handle different file structures.
 */
class StockSync_XLSX_Parser {

    private $file_path;
    private $distributor;
    private $unrecognized_availability = [];

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
     * Retrieve distinct availability strings encountered during parsing that the distributor did not mark as unavailable.
     *
     * @return string[] An array of distinct availability strings recorded during parsing.
     */
    public function get_unrecognized_availability() {
        return array_keys($this->unrecognized_availability);
    }

    /**
     * Parse the XLSX file and extract product rows into StockSync_Standard_Product objects.
     *
     * Populates the parser's unrecognized availability map with any non-empty availability
     * strings that the distributor does not classify as unavailable.
     *
     * @return StockSync_Standard_Product[]|WP_Error Array of product objects on success, or a WP_Error on failure
     *         (e.g., cannot open file, invalid XML, header not found, or no products found).
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
         * Load the workbook's shared strings from the XLSX ZIP and return them as an indexed array.
         *
         * Reads xl/sharedStrings.xml from the given ZIP archive and extracts each shared string.
         *
         * @param ZipArchive $zip ZIP archive instance opened for the XLSX file.
         * @return string[]|WP_Error An indexed array of shared strings if present; an empty array if the shared strings part is missing; or a WP_Error with code `parse_error` when the shared strings XML cannot be parsed.
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
     * Retrieve the textual value of a worksheet cell, resolving shared-string references.
     *
     * @param SimpleXMLElement $cell Cell XML element from the worksheet.
     * @param string[] $shared_strings Array of shared strings indexed by position.
     * @return string The cell text, or an empty string if the cell is empty or the shared-string index is not found.
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
