<?php
/**
 * Single Product Tab
 */
?>
<div class="stock-product-panel">

    <div class="stock-info-banner">
        <div class="stock-info-content">
            <h2 class="stock-info-title"><?php esc_html_e('Single Product', 'stock-sync'); ?></h2>
            <div class="stock-info-text">
                <ul>
                    <li><svg class="stock-info-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 7H4"/><path d="M7 4L4 7l3 3"/><path d="M4 17h16"/><path d="M17 14l3 3-3 3"/></svg><?php esc_html_e('Delist or republish any product from the catalog', 'stock-sync'); ?></li>
                    <li><svg class="stock-info-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg><?php esc_html_e('Search by name or SKU to preview the product', 'stock-sync'); ?></li>
                    <li><svg class="stock-info-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8 15 3"/></svg><?php esc_html_e('Apply changes to the product according to the selected mode', 'stock-sync'); ?></li>
                </ul>
            </div>
        </div>
    </div>

    <div class="stock-card">
        <div class="stock-card-header-row">
            <div class="stock-card-header-text">
                <h2 class="stock-card-title"><?php esc_html_e('Single Product', 'stock-sync'); ?></h2>
                <p class="stock-card-desc"><?php esc_html_e('Manually apply availability changes (delist or publish) to one product. Search by name or SKU.', 'stock-sync'); ?></p>
            </div>
            <div class="stock-mode-toggle">
                <button type="button" class="stock-toggle-switch" id="stock-mode-toggle" data-mode="delist" role="switch" aria-checked="false">
                    <span class="stock-toggle-label active" data-mode="delist"><?php esc_html_e('Delist', 'stock-sync'); ?></span>
                    <span class="stock-toggle-label" data-mode="publish"><?php esc_html_e('Publish', 'stock-sync'); ?></span>
                </button>
            </div>
        </div>

        <div class="stock-product-search-wrap">
            <label for="stock-product-search" class="screen-reader-text"><?php esc_html_e('Search product name or SKU', 'stock-sync'); ?></label>
            <input type="text" id="stock-product-search" placeholder="<?php esc_attr_e('Type product name or SKU...', 'stock-sync'); ?>" />
            <button type="button" class="button" id="stock-product-search-btn"><?php esc_html_e('Search', 'stock-sync'); ?></button>
        </div>
        <div id="stock-product-search-results" class="stock-search-results hidden"></div>
    </div>

    <div id="stock-product-selected" class="hidden">
        <div class="stock-card">
            <h2 class="stock-card-title"><?php esc_html_e('Product Details', 'stock-sync'); ?></h2>

            <table class="stock-before-after stock-card-table" id="stock-product-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Field', 'stock-sync'); ?></th>
                        <th><?php esc_html_e('Current', 'stock-sync'); ?></th>
                        <th><?php esc_html_e('After Update', 'stock-sync'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?php esc_html_e('Name', 'stock-sync'); ?></td>
                        <td id="product-current-name">—</td>
                        <td id="product-new-name">—</td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e('Visibility', 'stock-sync'); ?></td>
                        <td id="product-current-visibility">—</td>
                        <td id="product-new-visibility"><?php esc_html_e('Search results only', 'stock-sync'); ?></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e('Regular Price', 'stock-sync'); ?></td>
                        <td id="product-current-price">—</td>
                        <td id="product-new-price"><?php esc_html_e('(no change)', 'stock-sync'); ?></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e('Sale Price', 'stock-sync'); ?></td>
                        <td id="product-current-sale">—</td>
                        <td id="product-new-sale"><?php esc_html_e('(no change)', 'stock-sync'); ?></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e('Short Description', 'stock-sync'); ?></td>
                        <td id="product-current-excerpt">—</td>
                        <td id="product-new-excerpt">—</td>
                    </tr>
                </tbody>
            </table>

            <div class="stock-sticky-bar">
                <button type="button" class="button button-primary" id="stock-product-apply" disabled>
                    <?php esc_html_e('Apply', 'stock-sync'); ?>
                </button>
                <span id="stock-product-status" class="stock-product-status"></span>
                <div id="stock-product-success" class="stock-success-notice hidden"></div>
            </div>
        </div>
    </div>
</div>
