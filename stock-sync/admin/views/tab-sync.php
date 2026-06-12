<?php
/**
 * Distributor Sync Tab — Unified upload, mapping, preview & apply
 */
?>
<div class="stock-sync-panel">

    <!-- Info Banner -->
    <div class="stock-info-banner">
        <div class="stock-info-content">
            <h2 class="stock-info-title"><?php esc_html_e('Distributor sync', 'stock-sync'); ?></h2>
            <div class="stock-info-text">
                <ul>
                    <li><svg class="stock-info-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg><?php esc_html_e('Upload a distributor price list and automatically assign product references', 'stock-sync'); ?></li>
                    <li><svg class="stock-info-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg><?php esc_html_e('Preview product availability changes (delist or publish) before applying them', 'stock-sync'); ?></li>
                    <li><svg class="stock-info-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg><?php esc_html_e('Keep your catalog in sync with one click', 'stock-sync'); ?></li>
                </ul>
            </div>
        </div>
        <form method="get" class="stock-info-controls">
            <input type="hidden" name="page" value="stock-sync" />
            <input type="hidden" name="tab" value="sync" />
            <label for="stock-distributor"><?php esc_html_e('Distributor', 'stock-sync'); ?></label>
            <select id="stock-distributor" name="distributor" onchange="this.form.submit()">
                <?php foreach ($distributors as $slug => $name) : ?>
                    <option value="<?php echo esc_attr($slug); ?>" <?php selected($current_dist, $slug); ?>>
                        <?php echo esc_html($name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="button" class="button" id="stock-erase-refs-btn">
                <?php esc_html_e('Erase All Supplier References', 'stock-sync'); ?>
            </button>
        </form>
    </div>

    <!-- Step Indicator -->
    <div class="stock-stepper" id="stock-stepper">
        <div class="stock-step active" data-step="1">
            <span class="stock-step-icon">1</span>
            <span class="stock-step-label"><?php esc_html_e('Upload', 'stock-sync'); ?></span>
        </div>
        <div class="stock-step pending" data-step="2">
            <span class="stock-step-icon">2</span>
            <span class="stock-step-label"><?php esc_html_e('Synchronize', 'stock-sync'); ?></span>
        </div>
        <div class="stock-step pending" data-step="3">
            <span class="stock-step-icon">3</span>
            <span class="stock-step-label"><?php esc_html_e('Preview', 'stock-sync'); ?></span>
        </div>
        <div class="stock-step pending" data-step="4">
            <span class="stock-step-icon">4</span>
            <span class="stock-step-label"><?php esc_html_e('Apply', 'stock-sync'); ?></span>
        </div>
    </div>

    <!-- STEP 1: Upload -->
    <div id="sync-step-upload">
        <div class="stock-card">
            <h2 class="stock-card-title"><?php esc_html_e('Upload Price List', 'stock-sync'); ?></h2>
            <p class="stock-card-desc"><?php esc_html_e('Drag and drop your .xlsx file or click to browse your system. Unsynchronized products will be shown for review before syncing.', 'stock-sync'); ?></p>

            <form id="stock-sync-form" method="post" enctype="multipart/form-data" class="stock-upload-form">
                <?php wp_nonce_field('stock_sync_upload_action', 'stock_sync_upload_nonce'); ?>

                <div class="stock-upload-stack">
                    <div class="stock-upload-dropzone" id="stock-upload-dropzone">
                        <input type="file" name="xlsx_file" id="stock-xlsx-file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required />
                        <svg class="stock-upload-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                        <span class="stock-upload-text"><?php esc_html_e('Drop your .xlsx here or click to browse', 'stock-sync'); ?></span>
                        <span class="stock-upload-filename" id="stock-upload-filename"></span>
                    </div>

                    <details class="stock-upload-options">
                        <summary>
                            <span><?php esc_html_e('Advanced options', 'stock-sync'); ?></span>
                            <span class="stock-advanced-arrow">
                                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                            </span>
                        </summary>
                        <div class="stock-advanced-inner">
                            <label for="header_label_ref"><?php esc_html_e('Reference column', 'stock-sync'); ?></label>
                            <input type="text" id="header_label_ref" name="header_label_ref" placeholder="NR REF" />
                            <p class="description"><?php esc_html_e('Optional. Overrides the expected header for the reference column.', 'stock-sync'); ?></p>
                            <label for="header_label_avail"><?php esc_html_e('Availability column', 'stock-sync'); ?></label>
                            <input type="text" id="header_label_avail" name="header_label_avail" placeholder="STR. W KAT." />
                            <p class="description"><?php esc_html_e('Optional. Overrides the expected header for the availability column.', 'stock-sync'); ?></p>

                            <label for="header_label_price"><?php esc_html_e('Price column', 'stock-sync'); ?></label>
                            <input type="text" id="header_label_price" name="header_label_price" placeholder="HURT NETTO" />
                            <p class="description"><?php esc_html_e('Optional. Overrides the expected header for the price column.', 'stock-sync'); ?></p>

                            <label for="stock_markup"><?php esc_html_e('Markup %', 'stock-sync'); ?></label>
                            <input type="number" id="stock_markup" name="stock_markup" value="25" min="0" step="0.01" />
                            <p class="description"><?php esc_html_e('Markup percentage applied to the distributor price. Default is 25%.', 'stock-sync'); ?></p>
                        </div>
                    </details>

                    <div id="sync-upload-error" class="stock-upload-error" style="display:none;"></div>

                    <button type="submit" class="button button-primary" id="stock-sync-upload" disabled>
                        <?php esc_html_e('Upload', 'stock-sync'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- STEP 2: Mapping Review (hidden by default) -->
    <div id="sync-step-mapping" style="display:none;">
        <div class="stock-card">
            <button type="button" class="button stock-reset-btn" id="stock-sync-reset-mapping" title="<?php esc_attr_e('Start new sync', 'stock-sync'); ?>">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 0-9-9 9 9 0 0 0-6 2.3L3 13"/></svg>
                <?php esc_html_e('Start new sync', 'stock-sync'); ?>
            </button>
            <h2 class="stock-card-title"><?php esc_html_e('Verify References', 'stock-sync'); ?></h2>
            <p class="stock-card-desc"><?php echo wp_kses_post(__('Distributor references must be mapped to WooCommerce products.<br>Review the suggestions below, change incorrect matches, then confirm.', 'stock-sync')); ?></p>
            <details id="sync-mapping-details" class="stock-mapping-details" open>
                <summary id="sync-mapping-summary">
                    <?php esc_html_e('Suggested matches', 'stock-sync'); ?>
                    <span class="stock-mapping-count"></span>
                </summary>
                <table class="stock-card-table stock-match-table" id="sync-mapping-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="check-all-mapping" checked aria-label="<?php esc_attr_e('Select all entries', 'stock-sync'); ?>" /></th>
                            <th><?php esc_html_e('Ref', 'stock-sync'); ?></th>
                            <th><?php esc_html_e('Distributor name', 'stock-sync'); ?></th>
                            <th><?php esc_html_e('Product name', 'stock-sync'); ?></th>
                            <th><?php esc_html_e('Match', 'stock-sync'); ?></th>
                            <th><?php esc_html_e('Action', 'stock-sync'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="sync-mapping-body">
                    </tbody>
                </table>
            </details>

            <details id="sync-already-mapped-details" class="stock-already-mapped-details hidden">
                <summary id="sync-already-mapped-summary">
                    <?php esc_html_e('Already mapped', 'stock-sync'); ?>
                    <span class="stock-already-mapped-count"></span>
                </summary>
                <table class="stock-card-table stock-match-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="check-all-already" checked disabled aria-label="<?php esc_attr_e('Select all already mapped entries', 'stock-sync'); ?>" /></th>
                            <th><?php esc_html_e('Ref', 'stock-sync'); ?></th>
                            <th><?php esc_html_e('Distributor name', 'stock-sync'); ?></th>
                            <th><?php esc_html_e('Product name', 'stock-sync'); ?></th>
                            <th><?php esc_html_e('Match', 'stock-sync'); ?></th>
                            <th><?php esc_html_e('Action', 'stock-sync'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="sync-already-mapped-body">
                    </tbody>
                </table>
            </details>

            <details id="sync-unmatched-details" class="stock-unmatched-details hidden" open>
                <summary id="sync-unmatched-summary">
                    <?php esc_html_e('Unmatched positions', 'stock-sync'); ?>
                    <span class="stock-unmatched-count"></span>
                </summary>
                <table class="stock-card-table stock-match-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="check-all-unmatched" aria-label="<?php esc_attr_e('Select all unmatched entries', 'stock-sync'); ?>" /></th>
                            <th><?php esc_html_e('Ref', 'stock-sync'); ?></th>
                            <th><?php esc_html_e('Distributor name', 'stock-sync'); ?></th>
                            <th><?php esc_html_e('Product name', 'stock-sync'); ?></th>
                            <th><?php esc_html_e('Match', 'stock-sync'); ?></th>
                            <th><?php esc_html_e('Action', 'stock-sync'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="sync-unmatched-body">
                    </tbody>
                </table>
            </details>

            <div class="stock-sticky-bar">
                <button type="button" class="button button-primary" id="stock-sync-confirm-mappings" disabled>
                    <?php esc_html_e('Confirm Mappings & Continue', 'stock-sync'); ?>
                </button>
                <button type="button" class="button" id="stock-sync-skip-mapping" style="display:none;">
                    <?php esc_html_e('Skip Mapping & Start Sync', 'stock-sync'); ?>
                </button>
                <div id="sync-duplicate-notice" class="stock-duplicate-notice" style="display:none;"></div>
            </div>
        </div>
    </div>

    <!-- STEP 3: Sync Preview (hidden by default) -->
    <div id="sync-step-preview" style="display:none;">
        <div class="stock-card">
            <button type="button" class="button stock-reset-btn" id="stock-sync-reset-preview" title="<?php esc_attr_e('Start new sync', 'stock-sync'); ?>">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 0-9-9 9 9 0 0 0-6 2.3L3 13"/></svg>
                <?php esc_html_e('Start new sync', 'stock-sync'); ?>
            </button>

            <h2 class="stock-card-title"><?php esc_html_e('Sync Preview', 'stock-sync'); ?></h2>
            <p class="stock-card-desc"><?php echo wp_kses_post(__('Review the products that will be updated.<br>Uncheck any you want to exclude, then apply the sync.', 'stock-sync')); ?></p>

            <div id="stock-sync-progress" class="stock-progress" style="display:none;">
                <div class="stock-progress-bar">
                    <div class="stock-progress-fill" style="width:0%"></div>
                </div>
                <p class="stock-progress-text">0%</p>
            </div>

            <table class="stock-card-table" id="sync-preview-table">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="check-all-preview" checked aria-label="<?php esc_attr_e('Select all preview entries', 'stock-sync'); ?>" /></th>
                        <th><?php esc_html_e('SKU', 'stock-sync'); ?></th>
                        <th><?php esc_html_e('Ref', 'stock-sync'); ?></th>
                        <th><?php esc_html_e('Name', 'stock-sync'); ?></th>
                        <th><?php esc_html_e('Action', 'stock-sync'); ?></th>
                    </tr>
                </thead>
                <tbody id="sync-preview-body">
                </tbody>
            </table>

            <div class="stock-sticky-bar">
                <button type="button" class="button button-primary" id="stock-sync-apply" disabled>
                    <?php esc_html_e('Apply Sync', 'stock-sync'); ?>
                </button>
            </div>
        </div>
    </div>

    <!-- STEP 4: Results (hidden by default) -->
    <div id="stock-sync-results" class="stock-results" style="display:none;">
        <div class="stock-card">
            <button type="button" class="button stock-reset-btn" id="stock-sync-reset-results" title="<?php esc_attr_e('Start new sync', 'stock-sync'); ?>">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 0-9-9 9 9 0 0 0-6 2.3L3 13"/></svg>
                <?php esc_html_e('Start new sync', 'stock-sync'); ?>
            </button>

            <h2 class="stock-card-title" id="stock-sync-results-title"><?php esc_html_e('Sync Results', 'stock-sync'); ?></h2>

            <div class="stock-stat-grid">
                <div class="stock-stat-item neutral">
                    <div class="stock-stat-value" id="res-total">0</div>
                    <div class="stock-stat-label"><?php esc_html_e('Total Processed', 'stock-sync'); ?></div>
                </div>
                <div class="stock-stat-item delisted">
                    <div class="stock-stat-value" id="res-delisted">0</div>
                    <div class="stock-stat-label" id="res-delisted-label"><?php esc_html_e('Delisted', 'stock-sync'); ?></div>
                </div>
                <div class="stock-stat-item published">
                    <div class="stock-stat-value" id="res-published">0</div>
                    <div class="stock-stat-label"><?php esc_html_e('Published', 'stock-sync'); ?></div>
                </div>
                <div class="stock-stat-item stat-error">
                    <div class="stock-stat-value" id="res-errors">0</div>
                    <div class="stock-stat-label"><?php esc_html_e('Errors', 'stock-sync'); ?></div>
                </div>

            </div>

            <div id="res-details"></div>
        </div>
    </div>
</div>
