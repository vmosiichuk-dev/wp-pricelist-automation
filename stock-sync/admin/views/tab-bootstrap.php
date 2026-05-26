<?php
/**
 * Bootstrap Mapping Tab
 */
?>
<div class="stock-bootstrap-panel">
    <h2><?php _e('Bootstrap Mapping', 'stock-sync'); ?></h2>
    <p><?php _e('Upload a price list to automatically match products by name. Review and confirm matches to populate supplier references.', 'stock-sync'); ?></p>

    <form id="stock-bootstrap-form" method="post" enctype="multipart/form-data" class="stock-upload-form">
        <?php wp_nonce_field('stock_sync_upload_action', 'stock_sync_upload_nonce'); ?>

        <table class="form-table">
            <tr>
                <th scope="row"><?php _e('XLSX File', 'stock-sync'); ?></th>
                <td>
                    <input type="file" name="xlsx_file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required />
                </td>
            </tr>
        </table>

        <p class="submit">
            <button type="submit" class="button button-primary" id="stock-bootstrap-analyze">
                <?php _e('Analyze & Match', 'stock-sync'); ?>
            </button>
        </p>
    </form>

    <div id="stock-bootstrap-progress" class="stock-progress" style="display:none;">
        <p><?php _e('Analyzing... this may take a moment.', 'stock-sync'); ?></p>
    </div>

    <div id="stock-bootstrap-results" style="display:none;">
        <h3><?php _e('Review Matches', 'stock-sync'); ?></h3>
        <p id="bootstrap-summary"></p>

        <table class="widefat striped stock-match-table">
            <thead>
                <tr>
                    <th><input type="checkbox" id="check-all-auto" aria-label="Select all entries" /></th>
                    <th><?php _e('Ref', 'stock-sync'); ?></th>
                    <th><?php _e('XLSX Name', 'stock-sync'); ?></th>
                    <th><?php _e('WC Product', 'stock-sync'); ?></th>
                    <th><?php _e('Confidence', 'stock-sync'); ?></th>
                    <th><?php _e('Status', 'stock-sync'); ?></th>
                </tr>
            </thead>
            <tbody id="bootstrap-matches-body">
            </tbody>
        </table>

        <p class="submit">
            <button type="button" class="button button-primary" id="stock-bootstrap-save" disabled>
                <?php _e('Confirm & Save Mappings', 'stock-sync'); ?>
            </button>
        </p>
    </div>
</div>
