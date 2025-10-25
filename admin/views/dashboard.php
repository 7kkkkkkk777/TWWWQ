<?php
if (!defined('ABSPATH')) exit;

$stats = array(
    'imported' => wp_count_posts('imported_coupon')->publish,
    'published' => count(get_posts(array('post_type' => 'coupon', 'meta_key' => '_ci7k_source', 'meta_value' => 'imported', 'numberposts' => -1, 'fields' => 'ids'))),
    'providers' => 2
);

$core = \CouponImporter\Core::get_instance();
$logger = $core->get_logger();
$recent_logs = $logger->get_logs(array('limit' => 10));
?>

<div class="wrap couponis7k-wrap">
    <h1 class="couponis7k-title"><?php _e('Dashboard - 7K Cupons Importer', '7k-coupons-importer'); ?></h1>

    <div class="couponis7k-stats-row">
        <div class="couponis7k-stat-box couponis7k-stat-primary">
            <div class="couponis7k-stat-number"><?php echo esc_html($stats['imported']); ?></div>
            <div class="couponis7k-stat-label"><?php _e('Cupons Importados', '7k-coupons-importer'); ?></div>
        </div>
        <div class="couponis7k-stat-box couponis7k-stat-success">
            <div class="couponis7k-stat-number"><?php echo esc_html($stats['published']); ?></div>
            <div class="couponis7k-stat-label"><?php _e('Cupons Publicados', '7k-coupons-importer'); ?></div>
        </div>
        <div class="couponis7k-stat-box">
            <div class="couponis7k-stat-number"><?php echo esc_html($stats['providers']); ?></div>
            <div class="couponis7k-stat-label"><?php _e('Provedores Ativos', '7k-coupons-importer'); ?></div>
        </div>
    </div>

    <div class="couponis7k-quick-actions">
        <h2><?php _e('Ações Rápidas', '7k-coupons-importer'); ?></h2>
        <div class="couponis7k-actions-grid">
            <a href="<?php echo admin_url('admin.php?page=ci7k-curation'); ?>" class="couponis7k-action-card">
                <span class="dashicons dashicons-yes-alt"></span>
                <h3><?php _e('Curadoria', '7k-coupons-importer'); ?></h3>
                <p><?php _e('Aprovar e gerenciar cupons importados', '7k-coupons-importer'); ?></p>
            </a>
            <a href="<?php echo admin_url('admin.php?page=ci7k-import'); ?>" class="couponis7k-action-card">
                <span class="dashicons dashicons-download"></span>
                <h3><?php _e('Importar', '7k-coupons-importer'); ?></h3>
                <p><?php _e('Importar novos cupons dos provedores', '7k-coupons-importer'); ?></p>
            </a>
            <a href="<?php echo admin_url('admin.php?page=ci7k-settings'); ?>" class="couponis7k-action-card">
                <span class="dashicons dashicons-admin-settings"></span>
                <h3><?php _e('Configurações', '7k-coupons-importer'); ?></h3>
                <p><?php _e('Configurar IA e automação', '7k-coupons-importer'); ?></p>
            </a>
        </div>
    </div>

    <div class="couponis7k-recent-logs">
        <h2><?php _e('Logs Recentes', '7k-coupons-importer'); ?></h2>
        <?php if (!empty($recent_logs)): ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Data', '7k-coupons-importer'); ?></th>
                        <th><?php _e('Tipo', '7k-coupons-importer'); ?></th>
                        <th><?php _e('Mensagem', '7k-coupons-importer'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_logs as $log): ?>
                        <tr>
                            <td><?php echo esc_html(ci7k_format_date_for_display($log->created_at)); ?></td>
                            <td><?php echo esc_html($log->log_type); ?></td>
                            <td><?php echo esc_html($log->message); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p><?php _e('Nenhum log disponível.', '7k-coupons-importer'); ?></p>
        <?php endif; ?>
    </div>
</div>