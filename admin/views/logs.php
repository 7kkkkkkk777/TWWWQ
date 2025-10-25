<?php
if (!defined('ABSPATH')) exit;

// Processar limpeza de logs
if (isset($_POST['ci7k_clear_logs'])) {
    check_admin_referer('ci7k_logs_nonce');

    global $wpdb;
    $table_name = $wpdb->prefix . 'ci7k_logs';

    $deleted = $wpdb->query("TRUNCATE TABLE {$table_name}");

    if ($deleted !== false) {
        echo '<div class="notice notice-success is-dismissible"><p>' . __('Todos os logs foram removidos com sucesso!', '7k-coupons-importer') . '</p></div>';
    } else {
        echo '<div class="notice notice-error is-dismissible"><p>' . __('Erro ao limpar logs.', '7k-coupons-importer') . '</p></div>';
    }
}

// Obter logs
global $wpdb;
$table_name = $wpdb->prefix . 'ci7k_logs';

$per_page = 50;
$page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$offset = ($page - 1) * $per_page;

$filter_type = isset($_GET['filter_type']) ? sanitize_text_field($_GET['filter_type']) : '';
$search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';

$where_conditions = array();
$where_values = array();

if (!empty($filter_type)) {
    $where_conditions[] = "type = %s";
    $where_values[] = $filter_type;
}

if (!empty($search)) {
    $where_conditions[] = "message LIKE %s";
    $where_values[] = '%' . $wpdb->esc_like($search) . '%';
}

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
}

// Contar total
$count_query = "SELECT COUNT(*) FROM {$table_name} {$where_clause}";
if (!empty($where_values)) {
    $total_logs = $wpdb->get_var($wpdb->prepare($count_query, $where_values));
} else {
    $total_logs = $wpdb->get_var($count_query);
}

// Obter logs
$logs_query = "SELECT * FROM {$table_name} {$where_clause} ORDER BY created_at DESC LIMIT %d OFFSET %d";
$query_values = array_merge($where_values, array($per_page, $offset));

if (!empty($where_values)) {
    $logs = $wpdb->get_results($wpdb->prepare($logs_query, $query_values));
} else {
    $logs = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table_name} ORDER BY created_at DESC LIMIT %d OFFSET %d", $per_page, $offset));
}

$total_pages = ceil($total_logs / $per_page);
?>

<div class="wrap couponis7k-wrap">
    <h1><?php _e('Logs do Sistema', '7k-coupons-importer'); ?></h1>
    
    <div class="couponis7k-logs-header">
        <div class="logs-stats">
            <span class="stat-item">
                <strong><?php echo number_format($total_logs); ?></strong> 
                <?php _e('logs registrados', '7k-coupons-importer'); ?>
            </span>
        </div>
        
        <div class="logs-actions">
            <form method="post" style="display: inline-block;">
                <?php wp_nonce_field('ci7k_logs_nonce'); ?>
                <button type="submit" name="ci7k_clear_logs" class="button button-secondary" 
                        onclick="return confirm('<?php _e('Tem certeza que deseja limpar todos os logs? Esta ação não pode ser desfeita.', '7k-coupons-importer'); ?>')">
                    <span class="dashicons dashicons-trash"></span>
                    <?php _e('Limpar Todos os Logs', '7k-coupons-importer'); ?>
                </button>
            </form>
        </div>
    </div>

    <!-- Filtros -->
    <div class="couponis7k-logs-filters">
        <form method="get">
            <input type="hidden" name="page" value="ci7k-logs">
            
            <select name="filter_type">
                <option value=""><?php _e('Todos os tipos', '7k-coupons-importer'); ?></option>
                <option value="info" <?php selected($filter_type, 'info'); ?>><?php _e('Info', '7k-coupons-importer'); ?></option>
                <option value="error" <?php selected($filter_type, 'error'); ?>><?php _e('Erro', '7k-coupons-importer'); ?></option>
                <option value="import" <?php selected($filter_type, 'import'); ?>><?php _e('Importação', '7k-coupons-importer'); ?></option>
                <option value="ai_rewrite" <?php selected($filter_type, 'ai_rewrite'); ?>><?php _e('IA Rewrite', '7k-coupons-importer'); ?></option>
                <option value="publish" <?php selected($filter_type, 'publish'); ?>><?php _e('Publicação', '7k-coupons-importer'); ?></option>
            </select>
            
            <input type="text" name="search" value="<?php echo esc_attr($search); ?>" placeholder="<?php _e('Buscar nas mensagens...', '7k-coupons-importer'); ?>">
            
            <button type="submit" class="button"><?php _e('Filtrar', '7k-coupons-importer'); ?></button>
            
            <?php if (!empty($filter_type) || !empty($search)): ?>
                <a href="<?php echo admin_url('admin.php?page=ci7k-logs'); ?>" class="button"><?php _e('Limpar Filtros', '7k-coupons-importer'); ?></a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Tabela de logs -->
    <div class="couponis7k-logs-table">
        <?php if (empty($logs)): ?>
            <div class="couponis7k-empty-state">
                <p><?php _e('Nenhum log encontrado.', '7k-coupons-importer'); ?></p>
            </div>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 120px;"><?php _e('Data/Hora', '7k-coupons-importer'); ?></th>
                        <th style="width: 80px;"><?php _e('Tipo', '7k-coupons-importer'); ?></th>
                        <th><?php _e('Mensagem', '7k-coupons-importer'); ?></th>
                        <th style="width: 100px;"><?php _e('Contexto', '7k-coupons-importer'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td>
                                <strong><?php echo date('d/m/Y', strtotime($log->created_at)); ?></strong><br>
                                <small><?php echo date('H:i:s', strtotime($log->created_at)); ?></small>
                            </td>
                            <td>
                                <span class="log-type log-type-<?php echo esc_attr($log->log_type ?? 'info'); ?>">
                                    <?php echo esc_html(ucfirst($log->log_type ?? 'info')); ?>
                                </span>
                            </td>
                            <td>
                                <div class="log-message">
                                    <?php echo esc_html($log->message); ?>
                                </div>
                            </td>
                            <td>
                                <?php if (!empty($log->context)): ?>
                                    <details>
                                        <summary><?php _e('Ver contexto', '7k-coupons-importer'); ?></summary>
                                        <pre><?php echo esc_html($log->context); ?></pre>
                                    </details>
                                <?php else: ?>
                                    <span class="description"><?php _e('Sem contexto', '7k-coupons-importer'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Paginação -->
            <?php if ($total_pages > 1): ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <?php
                        $pagination_args = array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => __('&laquo;'),
                            'next_text' => __('&raquo;'),
                            'total' => $total_pages,
                            'current' => $page
                        );
                        
                        if (!empty($filter_type)) {
                            $pagination_args['add_args'] = array('filter_type' => $filter_type);
                        }
                        
                        if (!empty($search)) {
                            $pagination_args['add_args']['search'] = $search;
                        }
                        
                        echo paginate_links($pagination_args);
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<style>
.couponis7k-logs-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding: 15px;
    background: #f9f9f9;
    border: 1px solid #ddd;
    border-radius: 4px;
}

.logs-stats .stat-item {
    margin-right: 20px;
}

.logs-actions .button {
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.couponis7k-logs-filters {
    margin-bottom: 20px;
    padding: 15px;
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 4px;
}

.couponis7k-logs-filters form {
    display: flex;
    gap: 10px;
    align-items: center;
}

.couponis7k-logs-filters input[type="text"] {
    min-width: 200px;
}

.log-type {
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: bold;
    text-transform: uppercase;
}

.log-type-info {
    background: #0073aa;
    color: white;
}

.log-type-error {
    background: #dc3232;
    color: white;
}

.log-type-import {
    background: #46b450;
    color: white;
}

.log-type-ai_rewrite {
    background: #9b59b6;
    color: white;
}

.log-type-publish {
    background: #00a32a;
    color: white;
}

.log-message {
    max-width: 500px;
    word-wrap: break-word;
}

details summary {
    cursor: pointer;
    color: #0073aa;
}

details pre {
    background: #f1f1f1;
    padding: 10px;
    border-radius: 3px;
    font-size: 11px;
    max-height: 200px;
    overflow-y: auto;
    margin-top: 5px;
}

.couponis7k-empty-state {
    text-align: center;
    padding: 40px;
    background: #f9f9f9;
    border: 1px solid #ddd;
    border-radius: 4px;
}

.tablenav-pages {
    float: right;
}
</style>