<?php
/**
 * Sync Products Tab
 */
$upload_url = admin_url('admin-post.php');
?>
<div class="stock-sync-panel">
    <h2><?php _e('Sync Products', 'stock-sync'); ?></h2>
    <p><?php _e('Upload the latest price list to mark unavailable products.', 'stock-sync'); ?></p>

    <form id="stock-sync-form" method="post" enctype="multipart/form-data" class="stock-upload-form">
        <?php wp_nonce_field('stock_sync_upload_action', 'stock_sync_upload_nonce'); ?>

        <table class="form-table">
            <tr>
                <th scope="row"><?php _e('XLSX File', 'stock-sync'); ?></th>
                <td>
                    <input type="file" name="xlsx_file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required />
                </td>
            </tr>
            <tr>
                <th scope="row"><?php _e('Mode', 'stock-sync'); ?></th>
                <td>
                    <label>
                        <input type="radio" name="sync_mode" value="preview" checked />
                        <?php _e('Preview (dry-run)', 'stock-sync'); ?>
                    </label><br/>
                    <label>
                        <input type="radio" name="sync_mode" value="apply" />
                        <?php _e('Apply Changes', 'stock-sync'); ?>
                    </label>
                </td>
            </tr>
        </table>

        <p class="submit">
            <button type="submit" class="button button-primary" id="stock-sync-start">
                <?php _e('Start Sync', 'stock-sync'); ?>
            </button>
        </p>
    </form>

    <div id="stock-sync-progress" class="stock-progress" style="display:none;">
        <div class="stock-progress-bar">
            <div class="stock-progress-fill" style="width:0%"></div>
        </div>
        <p class="stock-progress-text">0%</p>
    </div>

    <div id="stock-sync-results" class="stock-results" style="display:none;">
        <h3><?php _e('Sync Results', 'stock-sync'); ?></h3>
        <table class="widefat">
            <tbody>
                <tr><td><?php _e('Total Processed', 'stock-sync'); ?></td><td id="res-total">0</td></tr>
                <tr><td><?php _e('Updated', 'stock-sync'); ?></td><td id="res-updated">0</td></tr>
                <tr><td><?php _e('Not Found', 'stock-sync'); ?></td><td id="res-notfound">0</td></tr>
                <tr><td><?php _e('Errors', 'stock-sync'); ?></td><td id="res-errors">0</td></tr>
            </tbody>
        </table>
        <div id="res-details"></div>
    </div>
</div>
