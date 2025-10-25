<?php if (!defined('ABSPATH')) exit; ?>

<?php
// Handle form submissions for provider settings, test connection and imports
if (!current_user_can('manage_options')) {
    wp_die(__('Permissão negada', '7k-coupons-importer'));
}

$provider_slug = isset($_GET['provider']) ? sanitize_key($_GET['provider']) : 'rakuten';
$available_providers = array(
    'rakuten' => array(
        'class' => '\\CouponImporter\\Providers\\Rakuten',
        'label' => __('Rakuten', '7k-coupons-importer')
    ),
    'awin' => array(
        'class' => '\\CouponImporter\\Providers\\Awin',
        'label' => __('Awin', '7k-coupons-importer')
    ),
    'lomadee' => array(
        'class' => '\\CouponImporter\\Providers\\Lomadee',
        'label' => __('Lomadee', '7k-coupons-importer')
    ),
    'admitad' => array(
        'class' => '\\CouponImporter\\Providers\\Admitad',
        'label' => __('Admitad', '7k-coupons-importer')
    ),
);

if (!isset($available_providers[$provider_slug])) {
    $provider_slug = 'rakuten';
}

$current_provider_class = $available_providers[$provider_slug]['class'];
$provider_instance = class_exists($current_provider_class) ? new $current_provider_class() : null;

if (!$provider_instance) {
    ci7k_admin_notice(__('Erro ao carregar provedor.', '7k-coupons-importer'), 'error');
} else {
    // Save / Test / Import via POST
    if (isset($_POST['ci7k_provider_submit']) || isset($_POST['ci7k_provider_test']) || isset($_POST['ci7k_provider_import'])) {
        check_admin_referer('ci7k_provider_settings_' . $provider_slug);

        $fields = $provider_instance->get_settings_fields();
        $new_settings = array();

        foreach ($fields as $key => $field) {
            $type = isset($field['type']) ? $field['type'] : 'text';
            if ($type === 'checkbox') {
                $new_settings[$key] = isset($_POST[$key]) ? 1 : 0;
            } elseif ($type === 'number') {
                $new_settings[$key] = isset($_POST[$key]) ? intval($_POST[$key]) : (isset($field['default']) ? intval($field['default']) : 0);
            } else {
                $new_settings[$key] = isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : '';
            }
        }

        // Save settings
        if (isset($_POST['ci7k_provider_submit'])) {
            $is_valid = method_exists($provider_instance, 'validate_settings') ? $provider_instance->validate_settings($new_settings) : true;

            if (!$is_valid) {
                ci7k_admin_notice(__('Preencha todos os campos obrigatórios.', '7k-coupons-importer'), 'error');
            } else {
                ci7k_update_provider_settings($provider_slug, $new_settings);
                ci7k_admin_notice(__('Configurações salvas com sucesso!', '7k-coupons-importer'));
            }
        }

        // Test connection
        if (isset($_POST['ci7k_provider_test'])) {
            $test_settings = !empty($new_settings) ? $new_settings : ci7k_get_provider_settings($provider_slug);
            $ok = method_exists($provider_instance, 'test_connection') ? $provider_instance->test_connection($test_settings) : false;
            if ($ok) {
                ci7k_admin_notice(__('Conexão estabelecida com sucesso!', '7k-coupons-importer'));
            } else {
                ci7k_admin_notice(__('Falha ao conectar com a API. Verifique as credenciais.', '7k-coupons-importer'), 'error');
            }
        }

        // Manual import trigger
        if (isset($_POST['ci7k_provider_import'])) {
            $saved_settings = !empty($new_settings) ? $new_settings : ci7k_get_provider_settings($provider_slug);

            if (empty($saved_settings) || (method_exists($provider_instance, 'validate_settings') && !$provider_instance->validate_settings($saved_settings))) {
                ci7k_admin_notice(__('Provedor não configurado corretamente. Salve as configurações antes de importar.', '7k-coupons-importer'), 'error');
            } else {
                try {
                    $core = \CouponImporter\Core::get_instance();
                    $limit = isset($saved_settings['import_limit']) ? intval($saved_settings['import_limit']) : 50;
                    
                    // Log do início da importação
                    $core->get_logger()->log('import', sprintf('Iniciando importação manual: %s, limite: %d', $provider_slug, $limit));
                    
                    $coupons = $provider_instance->get_coupons($saved_settings, $limit);

                    $imported = 0;
                    $skipped = 0;
                    $errors = 0;
                    
                    foreach ($coupons as $c) {
                        $res = $core->import_coupon($c, $provider_slug);
                        if ($res) {
                            $imported++;
                        } else {
                            $errors++;
                        }
                    }

                    $message = sprintf(
                        __('Importação concluída: %d cupons obtidos da API, %d importados com sucesso, %d erros.', '7k-coupons-importer'), 
                        count($coupons), 
                        $imported, 
                        $errors
                    );
                    
                    ci7k_admin_notice($message);
                    
                    // Log detalhado
                    $core->get_logger()->log('import', $message);
                    
                } catch (\Exception $e) {
                    $error_msg = sprintf(__('Erro ao importar: %s', '7k-coupons-importer'), $e->getMessage());
                    ci7k_admin_notice($error_msg, 'error');
                    $core->get_logger()->log('error', $error_msg);
                }
            }
        }
    }

    // If GET import=1 was requested, attempt automatic import if provider configured
    if (isset($_GET['import']) && $_GET['import'] === '1') {
        $saved_settings = ci7k_get_provider_settings($provider_slug);
        if (empty($saved_settings) || (method_exists($provider_instance, 'validate_settings') && !$provider_instance->validate_settings($saved_settings))) {
            ci7k_admin_notice(__('Provedor não configurado. Preencha as configurações antes de importar.', '7k-coupons-importer'), 'error');
        } else {
            // perform import
            try {
                $core = \CouponImporter\Core::get_instance();
                $limit = isset($saved_settings['import_limit']) ? intval($saved_settings['import_limit']) : 50;
                
                // Log do início da importação automática
                $core->get_logger()->log('import', sprintf('Iniciando importação automática: %s, limite: %d', $provider_slug, $limit));
                
                $coupons = $provider_instance->get_coupons($saved_settings, $limit);

                $imported = 0;
                $errors = 0;
                
                foreach ($coupons as $c) {
                    $res = $core->import_coupon($c, $provider_slug);
                    if ($res) {
                        $imported++;
                    } else {
                        $errors++;
                    }
                }

                $message = sprintf(
                    __('Importação automática concluída: %d cupons obtidos da API, %d importados com sucesso, %d erros.', '7k-coupons-importer'), 
                    count($coupons), 
                    $imported, 
                    $errors
                );
                
                ci7k_admin_notice($message);
                
                // Log detalhado
                $core->get_logger()->log('import', $message);
                
            } catch (\Exception $e) {
                $error_msg = sprintf(__('Erro na importação automática: %s', '7k-coupons-importer'), $e->getMessage());
                ci7k_admin_notice($error_msg, 'error');
                $core->get_logger()->log('error', $error_msg);
            }
        }
    }

    $saved_settings = ci7k_get_provider_settings($provider_slug);
}
?>

<div class="wrap couponis7k-wrap">
    <h1><?php _e('Provedores', '7k-coupons-importer'); ?></h1>
    <p><?php _e('Configure as credenciais e preferências de importação dos provedores de afiliados.', '7k-coupons-importer'); ?></p>

    <h2 class="nav-tab-wrapper">
        <?php foreach ($available_providers as $slug => $data): ?>
            <?php $active_class = ($slug === $provider_slug) ? ' nav-tab-active' : ''; ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=ci7k-providers&provider=' . $slug)); ?>" class="nav-tab<?php echo esc_attr($active_class); ?>">
                <?php echo esc_html($data['label']); ?>
            </a>
        <?php endforeach; ?>
    </h2>

    <?php if ($provider_instance): ?>
        <?php $fields = $provider_instance->get_settings_fields(); ?>
        <form method="post" action="">
            <?php wp_nonce_field('ci7k_provider_settings_' . $provider_slug); ?>
            <input type="hidden" name="provider" value="<?php echo esc_attr($provider_slug); ?>">

            <table class="form-table">
                <tbody>
                <?php foreach ($fields as $key => $field): ?>
                    <?php
                    $label = isset($field['label']) ? $field['label'] : ucfirst($key);
                    $type = isset($field['type']) ? $field['type'] : 'text';
                    $desc = isset($field['description']) ? $field['description'] : '';
                    $required = !empty($field['required']);
                    $default = isset($field['default']) ? $field['default'] : '';
                    $value = isset($saved_settings[$key]) ? $saved_settings[$key] : $default;
                    ?>
                    <tr <?php echo ($key === 'cron_schedule') ? 'data-dependent="enable_cron"' : ''; ?>>
                        <th scope="row">
                            <label for="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?><?php echo $required ? ' *' : ''; ?></label>
                        </th>
                        <td>
                            <?php if ($type === 'checkbox'): ?>
                                <label>
                                    <input type="checkbox" id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>" value="1" <?php checked((int)$value, 1); ?>>
                                </label>
                            <?php elseif ($type === 'number'): ?>
                                <input type="number" id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($value); ?>" class="small-text">
                            <?php elseif ($type === 'select'): ?>
                                <select id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>">
                                    <?php if (isset($field['options']) && is_array($field['options'])): ?>
                                        <?php foreach ($field['options'] as $opt_value => $opt_label): ?>
                                            <option value="<?php echo esc_attr($opt_value); ?>" <?php selected($value, $opt_value); ?>>
                                                <?php echo esc_html($opt_label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            <?php elseif ($type === 'password'): ?>
                                <input type="password" id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($value); ?>" class="regular-text" autocomplete="off">
                            <?php else: ?>
                                <input type="<?php echo esc_attr($type); ?>" id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($value); ?>" class="regular-text">
                            <?php endif; ?>
                            <?php if (!empty($desc)): ?>
                                <p class="description"><?php echo esc_html($desc); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <p class="submit">
                <button type="submit" name="ci7k_provider_submit" class="button-primary"><?php _e('Salvar Configurações', '7k-coupons-importer'); ?></button>
                <button type="submit" name="ci7k_provider_test" class="button"><?php _e('Testar Conexão', '7k-coupons-importer'); ?></button>

                <?php if (!empty($saved_settings) && (!method_exists($provider_instance, 'validate_settings') || $provider_instance->validate_settings($saved_settings))): ?>
                    <button type="submit" name="ci7k_provider_import" class="button-primary" style="margin-left:10px;"><?php _e('Importar agora', '7k-coupons-importer'); ?></button>
                <?php else: ?>
                    <a href="#" class="button" style="margin-left:10px;" onclick="window.location.href='<?php echo esc_js(admin_url('admin.php?page=ci7k-providers&provider=' . $provider_slug)); ?>';return false;"><?php _e('Configure para habilitar importação', '7k-coupons-importer'); ?></a>
                <?php endif; ?>

                <a href="<?php echo esc_url(admin_url('admin.php?page=ci7k-import')); ?>" class="button" style="margin-left:10px;"><?php _e('Voltar para Importar', '7k-coupons-importer'); ?></a>
            </p>
        </form>

        <hr>
        <h2><?php _e('Dicas', '7k-coupons-importer'); ?></h2>
        <ul>
            <li><?php _e('Ative a "Importação Automática" para executar via cron e escolha a frequência desejada.', '7k-coupons-importer'); ?></li>
            <li><?php _e('Use "Limite de Importação" para controlar a quantidade por execução.', '7k-coupons-importer'); ?></li>
            <?php if ($provider_slug === 'awin'): ?>
                <li><?php _e('Configure filtros avançados para importar apenas os cupons que atendem aos seus critérios.', '7k-coupons-importer'); ?></li>
            <?php endif; ?>
        </ul>
    <?php endif; ?>
</div>

<script>
jQuery(document).ready(function($) {
    function toggleDependentFields() {
        $('tr[data-dependent="enable_cron"]').each(function() {
            var $row = $(this);
            if ($('#enable_cron').is(':checked')) {
                $row.show();
            } else {
                $row.hide();
            }
        });
    }

    $('#enable_cron').on('change', toggleDependentFields);
    toggleDependentFields();
});
</script>