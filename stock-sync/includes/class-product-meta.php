<?php
/**
 * Product Meta — Adds supplier reference fields to the WooCommerce product edit screen.
 */
class StockSync_Product_Meta {

    /**
     * Register WooCommerce product meta hooks.
     */
    public function __construct() {
        add_filter('woocommerce_product_data_tabs', [$this, 'add_supplier_refs_tab']);
        add_action('woocommerce_product_data_panels', [$this, 'render_supplier_refs_panel']);
        add_action('woocommerce_process_product_meta', [$this, 'save_supplier_refs']);

        // Register meta keys so they are available via REST and standard WP APIs
        $this->register_meta_keys();
    }

    /**
     * Register post meta keys for all distributors.
     */
    private function register_meta_keys() {
        $registry = StockSync_Distributor_Registry::instance();
        foreach ($registry->get_all() as $distributor) {
            register_post_meta('product', $distributor->get_meta_key(), [
                'show_in_rest' => true,
                'single'       => true,
                'type'         => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'auth_callback'     => function () {
                    return current_user_can('manage_woocommerce');
                },
            ]);
        }
    }

    /**
     * Add the Supplier References tab to the product data box.
     *
     * @param array $tabs Existing WooCommerce product data tabs.
     * @return array
     */
    public function add_supplier_refs_tab($tabs) {
        $tabs['stock_sync_supplier_refs'] = [
            'label'    => __('StockSync Refs', 'stock-sync'),
            'target'   => 'stock_sync_supplier_refs_data',
            'class'    => ['stock-sync-refs-tab'],
            'priority' => 90,
        ];
        return $tabs;
    }

    /**
     * Render the Supplier References panel content.
     */
    public function render_supplier_refs_panel() {
        global $post;

        $registry      = StockSync_Distributor_Registry::instance();
        $distributors  = $registry->get_all();

        if (empty($distributors)) {
            return;
        }

        echo '<div id="stock_sync_supplier_refs_data" class="panel woocommerce_options_panel hidden">';
        echo '<div class="options_group">';

        foreach ($distributors as $distributor) {
            $meta_key = $distributor->get_meta_key();
            $value    = get_post_meta($post->ID, $meta_key, true);
            $label    = $distributor->get_name();

            woocommerce_wp_text_input([
                'id'          => esc_attr($meta_key),
                'label'       => esc_html($label),
                'description' => sprintf(
                    /* translators: %s: distributor name */
                    __('Supplier reference for %s.', 'stock-sync'),
                    esc_html($label)
                ),
                'desc_tip'    => true,
                'value'       => esc_attr($value),
            ]);
        }

        echo '</div>';
        echo '</div>';
    }

    /**
     * Save supplier reference fields when the product is saved.
     *
     * @param int $post_id Product ID.
     */
    public function save_supplier_refs($post_id) {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        if (!check_admin_referer('woocommerce_save_data', 'woocommerce_meta_nonce')) {
            return;
        }

        $registry     = StockSync_Distributor_Registry::instance();
        $distributors = $registry->get_all();

        foreach ($distributors as $distributor) {
            $meta_key = $distributor->get_meta_key();
            if (isset($_POST[$meta_key])) {
                update_post_meta(
                    $post_id,
                    $meta_key,
                    sanitize_text_field(wp_unslash($_POST[$meta_key]))
                );
            }
        }
    }
}
