<?php
class StockSync_Service_Factory {
    private static $instances = [];

    /**
     * Retrieve the shared database-backed change logger.
     *
     * Returns a cached singleton instance and creates it if it does not yet exist.
     *
     * @return StockSync_WP_Database_Logger The singleton logger instance.
     */
    public static function logger() {
        if (!isset(self::$instances['logger'])) {
            self::$instances['logger'] = new StockSync_WP_Database_Logger(new StockSync_Change_Logger());
        }
        return self::$instances['logger'];
    }

    /**
     * Provide the shared product repository instance used for product lookups and persistence.
     *
     * @return StockSync_WC_Product_Repository The cached product repository instance.
     */
    public static function product_repository() {
        if (!isset(self::$instances['product_repository'])) {
            self::$instances['product_repository'] = new StockSync_WC_Product_Repository();
        }
        return self::$instances['product_repository'];
    }

    /**
     * Provides a shared singleton transient store instance.
     *
     * @return StockSync_WP_Transient_Store The cached transient store instance.
     */
    public static function transient_store() {
        if (!isset(self::$instances['transient_store'])) {
            self::$instances['transient_store'] = new StockSync_WP_Transient_Store();
        }
        return self::$instances['transient_store'];
    }

    /**
     * Create a product matcher configured with the shared product repository.
     *
     * @return StockSync_Product_Matcher A product matcher configured to use the shared product repository.
     */
    public static function product_matcher() {
        return new StockSync_Product_Matcher(self::product_repository());
    }

    /**
     * Create a product updater configured with the shared logger.
     *
     * @return StockSync_Product_Updater A product updater instance.
     */
    public static function product_updater() {
        return new StockSync_Product_Updater(self::logger());
    }

    /**
     * Create a bootstrap matcher configured with the shared product repository.
     *
     * @return StockSync_Bootstrap_Matcher The new bootstrap matcher instance.
     */
    public static function bootstrap_matcher() {
        return new StockSync_Bootstrap_Matcher(self::product_repository());
    }

    /**
     * Create an XLSX parser for the given file and distributor.
     *
     * @param string $file_path Path to the XLSX file to be parsed.
     * @param mixed  $distributor Distributor identifier or object used to interpret file contents.
     * @return StockSync_XLSX_Parser An XLSX parser configured for the specified file and distributor.
     */
    public static function xlsx_parser($file_path, $distributor) {
        return new StockSync_XLSX_Parser($file_path, $distributor);
    }
}
