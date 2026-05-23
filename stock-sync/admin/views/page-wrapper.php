<?php
/**
 * Main admin page wrapper
 */
?>
<div class="wrap stock-sync-wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <form method="get" class="stock-distributor-selector">
        <input type="hidden" name="page" value="stock-sync" />
        <input type="hidden" name="tab" value="<?php echo esc_attr($active_tab); ?>" />

        <label for="stock-distributor"><?php _e('Distributor:', 'stock-sync'); ?></label>
        <select id="stock-distributor" name="distributor" onchange="this.form.submit()">
            <?php foreach ($distributors as $slug => $name) : ?>
                <option value="<?php echo esc_attr($slug); ?>" <?php selected($current_dist, $slug); ?>>
                    <?php echo esc_html($name); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>

    <h2 class="nav-tab-wrapper">
        <a href="<?php echo esc_url(admin_url('admin.php?page=stock-sync&tab=sync&distributor=' . $current_dist)); ?>"
           class="nav-tab <?php echo $active_tab === 'sync' ? 'nav-tab-active' : ''; ?>">
            <?php _e('Sync Products', 'stock-sync'); ?>
        </a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=stock-sync&tab=test&distributor=' . $current_dist)); ?>"
           class="nav-tab <?php echo $active_tab === 'test' ? 'nav-tab-active' : ''; ?>">
            <?php _e('Test Product', 'stock-sync'); ?>
        </a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=stock-sync&tab=bootstrap&distributor=' . $current_dist)); ?>"
           class="nav-tab <?php echo $active_tab === 'bootstrap' ? 'nav-tab-active' : ''; ?>">
            <?php _e('Bootstrap Mapping', 'stock-sync'); ?>
        </a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=stock-sync&tab=log&distributor=' . $current_dist)); ?>"
           class="nav-tab <?php echo $active_tab === 'log' ? 'nav-tab-active' : ''; ?>">
            <?php _e('Sync Log', 'stock-sync'); ?>
        </a>
    </h2>

    <div class="stock-tab-content">
        <?php
        $view_file = STOCK_SYNC_PLUGIN_DIR . 'admin/views/tab-' . $active_tab . '.php';
        if (file_exists($view_file)) {
            include $view_file;
        } else {
            include STOCK_SYNC_PLUGIN_DIR . 'admin/views/tab-sync.php';
        }
        ?>
    </div>
</div>
