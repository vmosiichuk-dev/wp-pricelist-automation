<?php
/**
 * Main plugin controller
 */
class StockSync_Plugin {

    private static $instance = null;

    /**
     * Return the singleton plugin instance.
     *
     * @return StockSync_Plugin
     */
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor for singleton pattern.
     *
     * @return void
     */
    private function __construct() {}

    /**
     * Initialize the plugin
     */
    public function init() {
        $this->register_distributors();
        $this->init_modules();
    }

    /**
     * Register all built-in distributors
     */
    private function register_distributors() {
        $registry = StockSync_Distributor_Registry::instance();
        $registry->register(new StockSync_Distributor_Vininova());
        $registry->register(new StockSync_Distributor_Winkolekcja());
    }

    /**
     * Initialize all plugin modules
     */
    private function init_modules() {
        new StockSync_Admin();
        new StockSync_AJAX_Handler();
        new StockSync_Product_Meta();
    }

    /**
     * Get plugin directory path
     */
    public static function dir() {
        return STOCK_SYNC_PLUGIN_DIR;
    }

    /**
     * Get plugin URL
     */
    public static function url() {
        return STOCK_SYNC_PLUGIN_URL;
    }
}
