<?php
/**
 * Test Single Product Tab
 */
?>
<div class="stock-test-panel">
    <h2><?php _e('Test Single Product', 'stock-sync'); ?></h2>
    <p><?php _e('Search for a single product and preview or apply the unavailable-state update to verify the plugin works correctly before running a full sync.', 'stock-sync'); ?></p>

    <table class="form-table">
        <tr>
            <th scope="row"><?php _e('Search Product', 'stock-sync'); ?></th>
            <td>
                <input type="text" id="stock-test-search" placeholder="<?php esc_attr_e('Type product name or SKU...', 'stock-sync'); ?>" style="width: 400px;" />
                <button type="button" class="button" id="stock-test-search-btn"><?php _e('Search', 'stock-sync'); ?></button>
                <div id="stock-test-search-results" class="stock-search-results" style="display:none;"></div>
            </td>
        </tr>
    </table>

    <div id="stock-test-selected" style="display:none;">
        <h3><?php _e('Product Details', 'stock-sync'); ?></h3>
        <table class="widefat stock-before-after">
            <thead>
                <tr>
                    <th style="width: 20%;"><?php _e('Field', 'stock-sync'); ?></th>
                    <th style="width: 40%;"><?php _e('Current', 'stock-sync'); ?></th>
                    <th style="width: 40%;"><?php _e('After Update', 'stock-sync'); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><?php _e('ID / SKU', 'stock-sync'); ?></td>
                    <td id="test-current-id-sku">—</td>
                    <td class="stock-unchanged"><?php _e('— (no change)', 'stock-sync'); ?></td>
                </tr>
                <tr>
                    <td><?php _e('Name', 'stock-sync'); ?></td>
                    <td id="test-current-name">—</td>
                    <td class="stock-unchanged"><?php _e('— (no change)', 'stock-sync'); ?></td>
                </tr>
                <tr>
                    <td><?php _e('Visibility', 'stock-sync'); ?></td>
                    <td id="test-current-visibility">—</td>
                    <td id="test-new-visibility"><?php _e('Search results only', 'stock-sync'); ?></td>
                </tr>
                <tr>
                    <td><?php _e('Regular Price', 'stock-sync'); ?></td>
                    <td id="test-current-price">—</td>
                    <td id="test-new-price"><?php _e('(cleared)', 'stock-sync'); ?></td>
                </tr>
                <tr>
                    <td><?php _e('Sale Price', 'stock-sync'); ?></td>
                    <td id="test-current-sale">—</td>
                    <td id="test-new-sale"><?php _e('(cleared)', 'stock-sync'); ?></td>
                </tr>
                <tr>
                    <td><?php _e('Short Description', 'stock-sync'); ?></td>
                    <td id="test-current-excerpt">—</td>
                    <td id="test-new-excerpt">—</td>
                </tr>
            </tbody>
        </table>

        <p class="submit">
            <button type="button" class="button button-primary" id="stock-test-apply" disabled>
                <?php _e('Apply Test Update to This Product', 'stock-sync'); ?>
            </button>
            <span id="stock-test-status" style="margin-left: 10px;"></span>
        </p>
    </div>
</div>
