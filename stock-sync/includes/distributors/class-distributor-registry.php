<?php
/**
 * Distributor Registry - Singleton
 * Holds all registered distributor instances.
 */
class StockSync_Distributor_Registry {

    private static $instance = null;
    private $distributors = [];

    /**
     * Private constructor for singleton pattern.
     *
     * @return void
     */
    private function __construct() {}

    /**
     * Return the singleton registry instance.
     *
     * @return StockSync_Distributor_Registry
     */
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Register a distributor instance
     */
    public function register(StockSync_Distributor $distributor) {
        $slug = $distributor->get_slug();
        if (isset($this->distributors[$slug])) {
            throw new InvalidArgumentException(sprintf('Distributor with slug "%s" is already registered.', $slug));
        }
        $this->distributors[$slug] = $distributor;
    }

    /**
     * Get a distributor by slug
     */
    public function get($slug) {
        return $this->distributors[$slug] ?? null;
    }

    /**
     * Get all registered distributors
     */
    public function get_all() {
        return $this->distributors;
    }

    /**
     * Get distributor names for dropdowns
     */
    public function get_options() {
        $options = [];
        foreach ($this->distributors as $slug => $distributor) {
            $options[$slug] = $distributor->get_name();
        }
        return $options;
    }
}
