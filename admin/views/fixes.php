<?php
if (!defined('ABSPATH')) {
    exit;
}

// Obter estatísticas dos cupons publicados
$core = \CouponImporter\Core::get_instance();

// Contar cupons publicados pelo plugin
global $wpdb;
$published_count = $wpdb->get_var("
    SELECT COUNT(*) 
    FROM {$wpdb->posts} p 
    INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
    WHERE p.post_type = 'coupon' 
    AND p.post_status = 'publish' 
    AND pm.meta_key = '_ci7k_source' 
    AND pm.meta_value = 'imported'
");

// Verificar cupons com possíveis problemas
$problematic_coupons = $wpdb->get_results("
    SELECT p.ID, p.post_title, 
           CASE 
               WHEN p.post_title LIKE '%<%' OR p.post_title LIKE '%>%' THEN 'HTML no título'
               WHEN p.post_content LIKE '%<%' OR p.post_content LIKE '%>%' THEN 'HTML no conteúdo'
               ELSE 'OK'
           END as issue_type
    FROM {$wpdb->posts} p 
    INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
    WHERE p.post_type = 'coupon' 
    AND p.post_status = 'publish' 
    AND pm.meta_key = '_ci7k_source' 
    AND pm.meta_value = 'imported'
    AND (p.post_title LIKE '%<%' OR p.post_title LIKE '%>%' OR p.post_content LIKE '%<%' OR p.post_content LIKE '%>%')
    LIMIT 10
");
?>

<div class="wrap">
    <h1><?php _e('Correções de Cupons', '7k-coupons-importer'); ?></h1>
    
    <div class="card">
        <h2><?php _e('Estatísticas', '7k-coupons-importer'); ?></h2>
        <table class="widefat">
            <tr>
                <td><strong><?php _e('Total de cupons publicados pelo plugin:', '7k-coupons-importer'); ?></strong></td>
                <td><?php echo number_format($published_count); ?></td>
            </tr>
            <tr>
                <td><strong><?php _e('Cupons com possíveis problemas:', '7k-coupons-importer'); ?></strong></td>
                <td><?php echo count($problematic_coupons); ?></td>
            </tr>
        </table>
    </div>

    <?php if (!empty($problematic_coupons)): ?>
    <div class="card">
        <h2><?php _e('Cupons com Problemas Detectados', '7k-coupons-importer'); ?></h2>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php _e('ID', '7k-coupons-importer'); ?></th>
                    <th><?php _e('Título', '7k-coupons-importer'); ?></th>
                    <th><?php _e('Problema', '7k-coupons-importer'); ?></th>
                    <th><?php _e('Ações', '7k-coupons-importer'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($problematic_coupons as $coupon): ?>
                <tr>
                    <td><?php echo $coupon->ID; ?></td>
                    <td><?php echo esc_html(substr($coupon->post_title, 0, 50)) . (strlen($coupon->post_title) > 50 ? '...' : ''); ?></td>
                    <td><?php echo $coupon->issue_type; ?></td>
                    <td>
                        <a href="<?php echo admin_url('post.php?post=' . $coupon->ID . '&action=edit'); ?>" class="button button-small">
                            <?php _e('Editar', '7k-coupons-importer'); ?>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <div class="card">
        <h2><?php _e('Correção Automática', '7k-coupons-importer'); ?></h2>
        <p><?php _e('Esta ferramenta irá corrigir automaticamente todos os cupons publicados pelo plugin, removendo HTML inválido e sanitizando os dados.', '7k-coupons-importer'); ?></p>
        
        <div class="notice notice-warning">
            <p><strong><?php _e('Atenção:', '7k-coupons-importer'); ?></strong> <?php _e('Esta operação irá modificar todos os cupons publicados pelo plugin. Recomendamos fazer um backup antes de prosseguir.', '7k-coupons-importer'); ?></p>
        </div>

        <form method="post" onsubmit="return confirm('<?php _e('Tem certeza que deseja corrigir todos os cupons? Esta ação não pode ser desfeita.', '7k-coupons-importer'); ?>');">
            <?php wp_nonce_field('ci7k_fix_coupons'); ?>
            <p>
                <input type="submit" name="fix_all_coupons" class="button button-primary" value="<?php _e('Corrigir Todos os Cupons', '7k-coupons-importer'); ?>">
            </p>
        </form>
    </div>

    <div class="card">
        <h2><?php _e('Problemas Comuns e Soluções', '7k-coupons-importer'); ?></h2>
        <ul>
            <li><strong><?php _e('HTML no título ou descrição:', '7k-coupons-importer'); ?></strong> <?php _e('A correção remove todas as tags HTML e decodifica entidades HTML.', '7k-coupons-importer'); ?></li>
            <li><strong><?php _e('URLs inválidas:', '7k-coupons-importer'); ?></strong> <?php _e('A correção valida e sanitiza todas as URLs antes de salvar.', '7k-coupons-importer'); ?></li>
            <li><strong><?php _e('Códigos de cupom com caracteres especiais:', '7k-coupons-importer'); ?></strong> <?php _e('A correção remove caracteres inválidos dos códigos.', '7k-coupons-importer'); ?></li>
            <li><strong><?php _e('Datas de expiração inválidas:', '7k-coupons-importer'); ?></strong> <?php _e('A correção valida e formata corretamente as datas.', '7k-coupons-importer'); ?></li>
        </ul>
    </div>
</div>

<style>
.card {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    padding: 20px;
    margin: 20px 0;
}

.card h2 {
    margin-top: 0;
}

.widefat td {
    padding: 8px 10px;
}
</style>