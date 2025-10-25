<?php if (!defined('ABSPATH')) exit; ?>

<?php 
// Processar ações AJAX
if (isset($_POST['ci7k_ajax_toggle_provider'])) {
    check_ajax_referer('ci7k_import_nonce');
    
    $provider = sanitize_text_field($_POST['provider']);
    $enabled = isset($_POST['enabled']) ? 1 : 0;
    update_option("ci7k_{$provider}_enabled", $enabled);
    
    wp_send_json_success(array(
        'message' => __('Configuração salva com sucesso!', '7k-coupons-importer'),
        'enabled' => $enabled
    ));
}

// Processar importação rápida
if (isset($_POST['ci7k_quick_import'])) {
    $provider = sanitize_text_field($_POST['provider']);
    $settings = ci7k_get_provider_settings($provider);
    
    if (empty($settings)) {
        ci7k_admin_notice(__('Provedor não configurado. Configure primeiro nas configurações.', '7k-coupons-importer'), 'error');
    } else {
        try {
            $core = \CouponImporter\Core::get_instance();
            $provider_class = "\\CouponImporter\\Providers\\" . ucfirst($provider);
            $provider_instance = new $provider_class();
            
            $limit = isset($settings['import_limit']) ? intval($settings['import_limit']) : 50;
            $coupons = $provider_instance->get_coupons($settings, $limit);
            
            $imported = 0;
            foreach ($coupons as $c) {
                if ($core->import_coupon($c, $provider)) {
                    $imported++;
                }
            }
            
            ci7k_admin_notice(sprintf(__('Importação concluída: %d cupons importados de %d obtidos.', '7k-coupons-importer'), $imported, count($coupons)));
        } catch (Exception $e) {
            ci7k_admin_notice(__('Erro na importação: ' . $e->getMessage(), '7k-coupons-importer'), 'error');
        }
    }
}

// Verificar status dos provedores
$providers = array(
    'rakuten' => array(
        'name' => 'Rakuten Advertising',
        'description' => 'Importar cupons do Rakuten',
        'enabled' => get_option('ci7k_rakuten_enabled', 0),
        'configured' => !empty(ci7k_get_provider_settings('rakuten')),
        'auto_import' => get_option('ci7k_rakuten_auto_import', 0)
    ),
    'awin' => array(
        'name' => 'Awin',
        'description' => 'Importar cupons do Awin',
        'enabled' => get_option('ci7k_awin_enabled', 0),
        'configured' => !empty(ci7k_get_provider_settings('awin')),
        'auto_import' => get_option('ci7k_awin_auto_import', 0)
    ),
    'lomadee' => array(
        'name' => 'Lomadee',
        'description' => 'Importar cupons do Lomadee',
        'enabled' => get_option('ci7k_lomadee_enabled', 0),
        'configured' => !empty(ci7k_get_provider_settings('lomadee')),
        'auto_import' => get_option('ci7k_lomadee_auto_import', 0)
    ),
    'admitad' => array(
        'name' => 'Admitad',
        'description' => 'Importar cupons do Admitad',
        'enabled' => get_option('ci7k_admitad_enabled', 0),
        'configured' => !empty(ci7k_get_provider_settings('admitad')),
        'auto_import' => get_option('ci7k_admitad_auto_import', 0)
    )
);
?>

<div class="wrap couponis7k-wrap">
    <h1><?php _e('Importar Cupons', '7k-coupons-importer'); ?></h1>
    <p><?php _e('Gerencie e execute importações dos provedores configurados.', '7k-coupons-importer'); ?></p>

    <div class="couponis7k-import-providers">
        <?php foreach ($providers as $slug => $provider): ?>
            <div class="couponis7k-provider-card <?php echo $provider['enabled'] ? 'enabled' : 'disabled'; ?>" data-provider="<?php echo esc_attr($slug); ?>">
                <div class="provider-header">
                    <h3><?php echo esc_html($provider['name']); ?></h3>
                    <div class="provider-status">
                        <?php if ($provider['configured']): ?>
                            <span class="status-badge configured">✓ Configurado</span>
                        <?php else: ?>
                            <span class="status-badge not-configured">⚠ Não Configurado</span>
                        <?php endif; ?>
                        
                        <?php if ($provider['auto_import']): ?>
                            <span class="status-badge auto-import">🔄 Auto Import</span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <p><?php echo esc_html($provider['description']); ?></p>
                
                <div class="provider-actions">
                    <label class="toggle-switch">
                        <input type="checkbox" class="provider-toggle" data-provider="<?php echo esc_attr($slug); ?>" <?php checked($provider['enabled']); ?>>
                        <span class="slider"></span>
                    </label>
                    
                    <?php if ($provider['configured'] && $provider['enabled']): ?>
                        <form method="post" style="display: inline-block;">
                            <input type="hidden" name="provider" value="<?php echo esc_attr($slug); ?>">
                            <button type="submit" name="ci7k_quick_import" class="button button-primary">
                                <?php _e('Importar Agora', '7k-coupons-importer'); ?>
                            </button>
                        </form>
                    <?php endif; ?>
                    
                    <a href="<?php echo admin_url('admin.php?page=ci7k-providers&provider=' . $slug); ?>" class="button">
                        <?php _e('Configurar', '7k-coupons-importer'); ?>
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    $('.provider-toggle').on('change', function() {
        var $toggle = $(this);
        var provider = $toggle.data('provider');
        var enabled = $toggle.is(':checked') ? 1 : 0;
        var $card = $toggle.closest('.couponis7k-provider-card');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'ci7k_toggle_provider',
                provider: provider,
                enabled: enabled,
                _ajax_nonce: '<?php echo wp_create_nonce('ci7k_import_nonce'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    if (enabled) {
                        $card.removeClass('disabled').addClass('enabled');
                    } else {
                        $card.removeClass('enabled').addClass('disabled');
                    }
                    
                    // Mostrar mensagem de sucesso
                    $('<div class="notice notice-success is-dismissible"><p>' + response.data.message + '</p></div>')
                        .insertAfter('.wrap h1').delay(3000).fadeOut();
                } else {
                    // Reverter o toggle em caso de erro
                    $toggle.prop('checked', !enabled);
                    alert('Erro ao salvar configuração');
                }
            },
            error: function() {
                // Reverter o toggle em caso de erro
                $toggle.prop('checked', !enabled);
                alert('Erro de conexão');
            }
        });
    });
});
</script>

<style>
.couponis7k-provider-card {
    border: 2px solid #ddd;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
    background: #fff;
    transition: all 0.3s ease;
}

.couponis7k-provider-card.enabled {
    border-color: #46b450;
    background: #f9fff9;
}

.couponis7k-provider-card.disabled {
    border-color: #ccc;
    background: #f9f9f9;
    opacity: 0.7;
}

.provider-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.provider-status {
    display: flex;
    gap: 8px;
}

.status-badge {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: bold;
}

.status-badge.configured {
    background: #46b450;
    color: white;
}

.status-badge.not-configured {
    background: #ffb900;
    color: white;
}

.status-badge.auto-import {
    background: #0073aa;
    color: white;
}

.provider-actions {
    display: flex;
    gap: 10px;
    align-items: center;
    margin-top: 15px;
}

.toggle-switch {
    position: relative;
    display: inline-block;
    width: 50px;
    height: 24px;
}

.toggle-switch input {
    opacity: 0;
    width: 0;
    height: 0;
}

.slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #ccc;
    transition: .4s;
    border-radius: 24px;
}

.slider:before {
    position: absolute;
    content: "";
    height: 18px;
    width: 18px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: .4s;
    border-radius: 50%;
}

input:checked + .slider {
    background-color: #46b450;
}

input:checked + .slider:before {
    transform: translateX(26px);
}
</style>