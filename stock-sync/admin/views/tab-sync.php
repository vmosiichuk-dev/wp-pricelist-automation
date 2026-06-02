<?php
/**
 * Sync Products Tab — Unified upload, mapping, preview & apply
 */
?>
<div class="stock-sync-panel">

    <!-- STEP 1: Upload -->
    <div id="sync-step-upload">
        <h2><?php _e('Sync Products', 'stock-sync'); ?></h2>
        <p><?php _e('Upload the latest price list. Unmapped products will be shown for review before syncing.', 'stock-sync'); ?></p>

        <form id="stock-sync-form" method="post" enctype="multipart/form-data" class="stock-upload-form">
            <?php wp_nonce_field('stock_sync_upload_action', 'stock_sync_upload_nonce'); ?>

            <div class="stock-upload-stack">
                <div class="stock-upload-dropzone" id="stock-upload-dropzone">
                    <input type="file" name="xlsx_file" id="stock-xlsx-file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required />
                    <svg class="stock-upload-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    <span class="stock-upload-text"><?php _e('Drop your .xlsx here or click to browse', 'stock-sync'); ?></span>
                    <span class="stock-upload-filename" id="stock-upload-filename"></span>
                </div>

                <details class="stock-upload-options">
                    <summary><?php _e('Advanced options', 'stock-sync'); ?></summary>
                    <div class="stock-advanced-inner">
                        <label for="header_label_ref"><?php _e('Header label for reference column', 'stock-sync'); ?></label>
                        <input type="text" id="header_label_ref" name="header_label_ref" placeholder="e.g. NR REF" />
                        <p class="description"><?php _e('Optional. Overrides the expected header label for the reference column.', 'stock-sync'); ?></p>
                        <label for="header_label_avail"><?php _e('Header label for availability column', 'stock-sync'); ?></label>
                        <input type="text" id="header_label_avail" name="header_label_avail" placeholder="e.g. STR. W KAT." />
                        <p class="description"><?php _e('Optional. Overrides the expected header label for the availability column.', 'stock-sync'); ?></p>
                    </div>
                </details>

                <button type="submit" class="button button-primary" id="stock-sync-upload" disabled>
                    <?php _e('Upload', 'stock-sync'); ?>
                </button>
            </div>
        </form>
    </div>

    <!-- STEP 2: Mapping Review (hidden by default) -->
    <div id="sync-step-mapping" style="display:none;">
        <h2><?php _e('Review Product Mappings', 'stock-sync'); ?></h2>
        <p><?php _e('Some products need to be mapped to WooCommerce products. Review the suggestions below, change incorrect matches, then confirm.', 'stock-sync'); ?></p>

        <div id="sync-duplicate-notice" class="stock-duplicate-notice" style="display:none;"></div>

        <table class="widefat striped stock-match-table" id="sync-mapping-table">
            <thead>
                <tr>
                    <th><input type="checkbox" id="check-all-mapping" checked aria-label="Select all entries" /></th>
                    <th><?php _e('Ref', 'stock-sync'); ?></th>
                    <th><?php _e('XLSX Name', 'stock-sync'); ?></th>
                    <th><?php _e('WC Product', 'stock-sync'); ?></th>
                    <th><?php _e('Confidence', 'stock-sync'); ?></th>
                    <th><?php _e('Status', 'stock-sync'); ?></th>
                    <th><?php _e('Action', 'stock-sync'); ?></th>
                </tr>
            </thead>
            <tbody id="sync-mapping-body">
            </tbody>
        </table>

        <details id="sync-unmatched-details" class="stock-unmatched-details" style="display:none;">
            <summary id="sync-unmatched-summary">
                <?php _e('Unmatched positions', 'stock-sync'); ?>
                <span class="stock-unmatched-count"></span>
            </summary>
            <table class="widefat striped stock-match-table">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="check-all-unmatched" aria-label="Select all unmatched entries" /></th>
                        <th><?php _e('Ref', 'stock-sync'); ?></th>
                        <th><?php _e('XLSX Name', 'stock-sync'); ?></th>
                        <th><?php _e('WC Product', 'stock-sync'); ?></th>
                        <th><?php _e('Confidence', 'stock-sync'); ?></th>
                        <th><?php _e('Status', 'stock-sync'); ?></th>
                        <th><?php _e('Action', 'stock-sync'); ?></th>
                    </tr>
                </thead>
                <tbody id="sync-unmatched-body">
                </tbody>
            </table>
        </details>

        <p class="submit">
            <button type="button" class="button button-primary" id="stock-sync-confirm-mappings" disabled>
                <?php _e('Confirm Mappings & Continue', 'stock-sync'); ?>
            </button>
            <button type="button" class="button" id="stock-sync-skip-mapping" style="display:none;">
                <?php _e('Skip Mapping & Start Sync', 'stock-sync'); ?>
            </button>
        </p>
    </div>

    <!-- STEP 3: Sync Preview (hidden by default) -->
    <div id="sync-step-preview" style="display:none;">
        <h2><?php _e('Sync Preview', 'stock-sync'); ?></h2>
        <p><?php _e('Review the products that will be updated. Uncheck any you want to exclude, then apply the sync.', 'stock-sync'); ?></p>

        <div id="stock-sync-progress" class="stock-progress" style="display:none;">
            <div class="stock-progress-bar">
                <div class="stock-progress-fill" style="width:0%"></div>
            </div>
            <p class="stock-progress-text">0%</p>
        </div>

        <div id="sync-preview-summary"></div>

        <table class="widefat striped" id="sync-preview-table">
            <thead>
                <tr>
                    <th><input type="checkbox" id="check-all-preview" checked aria-label="Select all preview entries" /></th>
                    <th><?php _e('Ref', 'stock-sync'); ?></th>
                    <th><?php _e('Name', 'stock-sync'); ?></th>
                    <th><?php _e('Status', 'stock-sync'); ?></th>
                </tr>
            </thead>
            <tbody id="sync-preview-body">
            </tbody>
        </table>

        <details id="sync-preview-unmatched-details" class="stock-unmatched-details" style="display:none;">
            <summary id="sync-preview-unmatched-summary">
                <?php _e('Unmatched products', 'stock-sync'); ?>
                <span class="stock-unmatched-count"></span>
            </summary>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php _e('Ref', 'stock-sync'); ?></th>
                        <th><?php _e('Name', 'stock-sync'); ?></th>
                        <th><?php _e('Status', 'stock-sync'); ?></th>
                    </tr>
                </thead>
                <tbody id="sync-preview-unmatched-body">
                </tbody>
            </table>
        </details>

        <p class="submit">
            <button type="button" class="button button-primary" id="stock-sync-apply" disabled>
                <?php _e('Apply Sync', 'stock-sync'); ?>
            </button>
        </p>
    </div>

    <div id="stock-sync-results" class="stock-results" style="display:none;">
        <h3 id="stock-sync-results-title"><?php _e('Sync Results', 'stock-sync'); ?></h3>
        <table class="widefat">
            <tbody>
                <tr><td><?php _e('Total Processed', 'stock-sync'); ?></td><td id="res-total">0</td></tr>
                <tr><td id="res-updated-label"><?php _e('Updated', 'stock-sync'); ?></td><td id="res-updated">0</td></tr>
                <tr><td><?php _e('Not Found', 'stock-sync'); ?></td><td id="res-notfound">0</td></tr>
                <tr><td><?php _e('Errors', 'stock-sync'); ?></td><td id="res-errors">0</td></tr>
            </tbody>
        </table>
        <div id="res-details"></div>
    </div>
</div>
