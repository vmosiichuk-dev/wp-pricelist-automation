<?php
/**
 * Main admin page wrapper
 */
?>
<div class="wrap stock-sync-wrap">

    <div class="stock-header-card">
        <div class="stock-header-meta">
            <h1 class="stock-header-title"><?php echo esc_html(get_admin_page_title()); ?></h1>
            <p class="stock-header-tagline"><?php esc_html_e('Automate product availability from distributor price lists', 'stock-sync'); ?></p>
        </div>
        <div class="stock-header-tabs">
            <a href="<?php echo esc_url(add_query_arg(array('page' => 'stock-sync', 'tab' => 'sync', 'distributor' => $current_dist), admin_url('admin.php'))); ?>"
               class="<?php echo $active_tab === 'sync' ? 'active' : ''; ?>">
                <?php esc_html_e('Distributor sync', 'stock-sync'); ?>
            </a>
            <a href="<?php echo esc_url(add_query_arg(array('page' => 'stock-sync', 'tab' => 'product', 'distributor' => $current_dist), admin_url('admin.php'))); ?>"
               class="<?php echo $active_tab === 'product' ? 'active' : ''; ?>">
                <?php esc_html_e('Single Product', 'stock-sync'); ?>
            </a>

        </div>
    </div>

    <div class="stock-tab-content">
        <?php
        $allowed_tabs = [
            'sync' => 'tab-sync.php',
            'product' => 'tab-product.php',
        ];
        $view_file = isset($allowed_tabs[$active_tab]) ? $allowed_tabs[$active_tab] : 'tab-sync.php';
        include STOCK_SYNC_PLUGIN_DIR . 'admin/views/' . $view_file;
        ?>
    </div>
</div>
