<?php
/**
 * Plugin Name: Stock Sync
 * Description: Automate WooCommerce product availability updates from distributor price lists. Multi-distributor support.
 * Version: 1.0.0
 * Author: vmosiichuk.dev
 * Text Domain: stock-sync
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

define('STOCK_SYNC_VERSION', '1.0.0');
define('STOCK_SYNC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('STOCK_SYNC_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Check if WooCommerce is active
 */
function stock_sync_woocommerce_active() {
    return class_exists('WooCommerce');
}

/**
 * Activation hook
 */
register_activation_hook(__FILE__, 'stock_sync_activate');

/**
 * Plugin activation hook: verify WooCommerce and create tables.
 *
 * @return void
 */
function stock_sync_activate() {
    if (!stock_sync_woocommerce_active()) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html__('Stock Sync requires WooCommerce to be installed and active.', 'stock-sync'),
            esc_html__('Plugin Activation Error', 'stock-sync'),
            ['back_link' => true]
        );
    }

    require_once STOCK_SYNC_PLUGIN_DIR . 'includes/class-database.php';
    StockSync_Database::create_tables();
}

/**
 * Deactivation hook
 */
register_deactivation_hook(__FILE__, 'stock_sync_deactivate');

/**
 * Plugin deactivation hook: clear scheduled events.
 *
 * @return void
 */
function stock_sync_deactivate() {
    wp_clear_scheduled_hook('stock_sync_cron');
}

/**
 * Cleanup temp files
 */
add_action('stock_sync_cleanup_temp', 'stock_sync_cleanup_temp');

/**
 * Delete a temporary uploaded file inside the plugin temp directory.
 *
 * @param string $file_path Path to the temp file.
 * @return void
 */
function stock_sync_cleanup_temp($file_path) {
    $upload_dir = wp_upload_dir();
    $temp_dir   = trailingslashit(wp_normalize_path($upload_dir['basedir'])) . 'stock-sync-temp';

    $real_file = realpath($file_path);
    $real_temp = realpath($temp_dir);

    if (!$real_file || !$real_temp) {
        return;
    }

    $real_file = wp_normalize_path($real_file);
    $real_temp = wp_normalize_path($real_temp);
    $real_temp = rtrim($real_temp, '/') . '/';

    if (strpos($real_file, $real_temp) !== 0) {
        return;
    }

    $basename = basename($real_file);
    if (strpos($basename, 'stock_') !== 0) {
        return;
    }

    if (file_exists($real_file)) {
        unlink($real_file);
    }
}

/**
 * Load plugin classes
 */
add_action('plugins_loaded', 'stock_sync_load');

/**
 * Load plugin classes and initialize the plugin controller.
 *
 * @return void
 */
function stock_sync_load() {
    if (!stock_sync_woocommerce_active()) {
        add_action('admin_notices', function () {
            echo '<div class="error"><p>';
            esc_html_e('Stock Sync requires WooCommerce. Please install and activate WooCommerce.', 'stock-sync');
            echo '</p></div>';
        });
        return;
    }

    $includes = [
        'includes/class-standard-product.php',
        'includes/distributors/class-distributor.php',
        'includes/distributors/class-distributor-registry.php',
        'includes/distributors/class-distributor-vininova.php',
        'includes/class-xlsx-parser.php',
        'includes/interfaces/class-logger-interface.php',
        'includes/interfaces/class-product-repository-interface.php',
        'includes/interfaces/class-transient-store-interface.php',
        'includes/adapters/class-wp-database-logger.php',
        'includes/adapters/class-wc-product-repository.php',
        'includes/adapters/class-wp-transient-store.php',
        'includes/class-service-factory.php',
        'includes/class-product-matcher.php',
        'includes/class-bootstrap-matcher.php',
        'includes/class-product-updater.php',
        'includes/class-product-meta.php',
        'includes/class-logger.php',
        'includes/class-database.php',
        'includes/class-ajax-handler.php',
        'includes/class-admin.php',
        'includes/class-plugin.php',
    ];

    foreach ($includes as $file) {
        $path = STOCK_SYNC_PLUGIN_DIR . $file;
        if (file_exists($path)) {
            require_once $path;
        }
    }

    // Initialize main plugin controller
    StockSync_Plugin::instance()->init();
}
