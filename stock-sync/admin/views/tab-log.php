<?php
/**
 * Sync Log Tab
 */
$logger     = new StockSync_Change_Logger();
$sync_runs  = $logger->get_sync_runs(20);
?>
<div class="stock-log-panel">

    <div class="stock-info-banner">
        <div class="stock-info-content">
            <h2 class="stock-info-title"><?php esc_html_e('Dziennik', 'stock-sync'); ?></h2>
            <div class="stock-info-text">
                <ul>
                    <li><svg class="stock-info-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg><?php esc_html_e('Review past sync operations and their outcomes', 'stock-sync'); ?></li>
                    <li><svg class="stock-info-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg><?php esc_html_e('See how many products were updated, not found, or caused errors', 'stock-sync'); ?></li>
                </ul>
            </div>
        </div>
    </div>

    <div class="stock-card">
        <h2 class="stock-card-title"><?php esc_html_e('Dziennik', 'stock-sync'); ?></h2>

        <?php if (empty($sync_runs)) : ?>
            <div class="stock-log-empty">
                <div class="stock-log-empty-icon">&#128203;</div>
                <p><?php esc_html_e('No sync operations logged yet. Run your first sync to see results here.', 'stock-sync'); ?></p>
            </div>
        <?php else : ?>
            <table class="stock-card-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Date', 'stock-sync'); ?></th>
                        <th><?php esc_html_e('Distributor', 'stock-sync'); ?></th>
                        <th><?php esc_html_e('Total Changes', 'stock-sync'); ?></th>
                        <th><?php esc_html_e('Unavailable', 'stock-sync'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sync_runs as $run) : ?>
                        <tr>
                            <td><?php echo esc_html($run['run_date']); ?></td>
                            <td><?php echo esc_html($run['distributor_slug']); ?></td>
                            <td><?php echo intval($run['total_changes']); ?></td>
                            <td><?php echo intval($run['unavailable_count']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
