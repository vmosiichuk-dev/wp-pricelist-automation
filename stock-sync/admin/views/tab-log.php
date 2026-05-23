<?php
/**
 * Sync Log Tab
 */
$logger     = new StockSync_Change_Logger();
$sync_runs  = $logger->get_sync_runs(20);
?>
<div class="stock-log-panel">
    <h2><?php _e('Sync Log', 'stock-sync'); ?></h2>

    <?php if (empty($sync_runs)) : ?>
        <p><?php _e('No sync operations logged yet.', 'stock-sync'); ?></p>
    <?php else : ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php _e('Date', 'stock-sync'); ?></th>
                    <th><?php _e('Distributor', 'stock-sync'); ?></th>
                    <th><?php _e('Total Changes', 'stock-sync'); ?></th>
                    <th><?php _e('Unavailable', 'stock-sync'); ?></th>
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
