<?php
class StockSync_Service_Factory {
    private static $instances = [];

    public static function logger() {
        if (!isset(self::$instances['logger'])) {
            self::$instances['logger'] = new StockSync_WP_Database_Logger(new StockSync_Change_Logger());
        }
        return self::$instances['logger'];
    }

    public static function product_repository() {
        if (!isset(self::$instances['product_repository'])) {
            self::$instances['product_repository'] = new StockSync_WC_Product_Repository();
        }
        return self::$instances['product_repository'];
    }

    public static function transient_store() {
        if (!isset(self::$instances['transient_store'])) {
            self::$instances['transient_store'] = new StockSync_WP_Transient_Store();
        }
        return self::$instances['transient_store'];
    }

    public static function product_matcher() {
        return new StockSync_Product_Matcher(self::product_repository());
    }

    public static function product_updater() {
        return new StockSync_Product_Updater(self::logger());
    }

    public static function bootstrap_matcher() {
        return new StockSync_Bootstrap_Matcher(self::product_repository());
    }

    public static function xlsx_parser($file_path, $distributor) {
        return new StockSync_XLSX_Parser($file_path, $distributor);
    }

    /**
     * Reset singleton instances. For testing only.
     */
    public static function reset() {
        self::$instances = [];
    }
}
