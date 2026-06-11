<?php
/**
 * Single Product Tab
 */
?>
<div class="stock-test-panel">

    <div class="stock-info-banner">
        <div class="stock-info-content">
            <h2 class="stock-info-title"><?php esc_html_e('Single Product', 'stock-sync'); ?></h2>
            <div class="stock-info-text">
                <ul>
                    <li><svg class="stock-info-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg><?php esc_html_e('Manually apply unavailable-state changes to one product', 'stock-sync'); ?></li>
                    <li><svg class="stock-info-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg><?php esc_html_e('Search by name or SKU to preview the product and apply updates', 'stock-sync'); ?></li>
                    <li><svg class="stock-info-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg><?php esc_html_e('Use this to verify how the plugin affects a product before running a full sync', 'stock-sync'); ?></li>
                </ul>
            </div>
        </div>
    </div>

    <div class="stock-card">
        <h2 class="stock-card-title"><?php esc_html_e('Single Product', 'stock-sync'); ?></h2>
        <p class="stock-card-desc"><?php esc_html_e('Manually apply unavailable-state changes to one product. Search by name or SKU.', 'stock-sync'); ?></p>

        <div class="stock-test-search-wrap">
            <label for="stock-test-search" class="screen-reader-text"><?php esc_html_e('Search product name or SKU', 'stock-sync'); ?></label>
            <input type="text" id="stock-test-search" placeholder="<?php esc_attr_e('Type product name or SKU...', 'stock-sync'); ?>" />
            <button type="button" class="button" id="stock-test-search-btn"><?php esc_html_e('Search', 'stock-sync'); ?></button>
        </div>
        <div id="stock-test-search-results" class="stock-search-results hidden"></div>
    </div>

    <div id="stock-test-selected" class="hidden">
        <div class="stock-card">
            <h2 class="stock-card-title"><?php esc_html_e('Product Details', 'stock-sync'); ?></h2>

            <table class="stock-before-after stock-card-table" id="stock-test-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Field', 'stock-sync'); ?></th>
                        <th><?php esc_html_e('Current', 'stock-sync'); ?></th>
                        <th><?php esc_html_e('After Update', 'stock-sync'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?php esc_html_e('SKU', 'stock-sync'); ?></td>
                        <td id="test-current-sku">—</td>
                        <td class="stock-unchanged"><?php esc_html_e('(no change)', 'stock-sync'); ?></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e('Name', 'stock-sync'); ?></td>
                        <td id="test-current-name">—</td>
                        <td id="test-new-name">—</td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e('Visibility', 'stock-sync'); ?></td>
                        <td id="test-current-visibility">—</td>
                        <td id="test-new-visibility"><?php esc_html_e('Search results only', 'stock-sync'); ?></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e('Regular Price', 'stock-sync'); ?></td>
                        <td id="test-current-price">—</td>
                        <td id="test-new-price"><?php esc_html_e('(no change)', 'stock-sync'); ?></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e('Sale Price', 'stock-sync'); ?></td>
                        <td id="test-current-sale">—</td>
                        <td id="test-new-sale"><?php esc_html_e('(no change)', 'stock-sync'); ?></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e('Short Description', 'stock-sync'); ?></td>
                        <td id="test-current-excerpt">—</td>
                        <td id="test-new-excerpt">—</td>
                    </tr>
                </tbody>
            </table>

            <div class="stock-sticky-bar">
                <button type="button" class="button button-primary" id="stock-test-apply" disabled>
                    <?php esc_html_e('Apply Update to This Product', 'stock-sync'); ?>
                </button>
                <span id="stock-test-status" class="stock-test-status"></span>
                <div id="stock-test-success" class="stock-success-notice hidden"></div>
            </div>
        </div>
    </div>
</div>
