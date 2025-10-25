<?php

if (!defined('ABSPATH')) {
    exit;
}

function coupon_importer_parse_date($date_string) {
    if (empty($date_string)) {
        return '';
    }

    $timestamp = strtotime($date_string);
    if (!$timestamp) {
        return '';
    }

    return date('Y-m-d H:i:s', $timestamp);
}

function coupon_importer_sanitize_data($data) {
    if (!is_array($data)) {
        return $data;
    }

    $sanitized = array();
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            $sanitized[$key] = coupon_importer_sanitize_data($value);
        } elseif (is_string($value)) {
            $sanitized[$key] = sanitize_text_field($value);
        } else {
            $sanitized[$key] = $value;
        }
    }

    return $sanitized;
}

function ci7k_get_option($key, $default = null) {
    $options = get_option('ci7k_settings', array());
    return isset($options[$key]) ? $options[$key] : $default;
}

function ci7k_update_option($key, $value) {
    $options = get_option('ci7k_settings', array());
    $options[$key] = $value;
    return update_option('ci7k_settings', $options);
}

function ci7k_get_provider_settings($provider_name) {
    $key = 'ci7k_provider_' . sanitize_key($provider_name);
    return get_option($key, array());
}

function ci7k_update_provider_settings($provider_name, $settings) {
    $key = 'ci7k_provider_' . sanitize_key($provider_name);
    $result = update_option($key, $settings);
    
    // Gerenciar cron de importação do provedor
    ci7k_manage_provider_cron($provider_name, $settings);
    
    return $result;
}

function ci7k_manage_provider_cron($provider_name, $settings) {
    $hook_name = 'ci7k_import_' . $provider_name . '_cron';
    
    // Limpar cron existente
    $timestamp = wp_next_scheduled($hook_name);
    if ($timestamp) {
        wp_unschedule_event($timestamp, $hook_name);
    }
    
    // Criar novo cron se ativado
    if (isset($settings['enable_cron']) && $settings['enable_cron']) {
        $schedule = isset($settings['cron_schedule']) ? $settings['cron_schedule'] : 'daily';
        
        // Adicionar intervalos personalizados se necessário
        add_filter('cron_schedules', 'ci7k_add_custom_cron_intervals');
        
        wp_schedule_event(time(), $schedule, $hook_name);
        
        // Log simples sem usar a classe Logger
        error_log(sprintf('[7K Coupons Importer] Cron de importação agendado para %s: %s', $provider_name, $schedule));
    }
}

function ci7k_add_custom_cron_intervals($schedules) {
    if (!isset($schedules['every_15_minutes'])) {
        $schedules['every_15_minutes'] = array(
            'interval' => 15 * 60,
            'display' => __('A cada 15 minutos', '7k-coupons-importer')
        );
    }
    
    if (!isset($schedules['every_30_minutes'])) {
        $schedules['every_30_minutes'] = array(
            'interval' => 30 * 60,
            'display' => __('A cada 30 minutos', '7k-coupons-importer')
        );
    }
    
    if (!isset($schedules['weekly'])) {
        $schedules['weekly'] = array(
            'interval' => 7 * 24 * 60 * 60,
            'display' => __('Uma vez por semana', '7k-coupons-importer')
        );
    }
    
    return $schedules;
}

function ci7k_run_provider_import($provider_name) {
    $core = \CouponImporter\Core::get_instance();
    $settings = ci7k_get_provider_settings($provider_name);
    
    if (empty($settings) || !isset($settings['enable_cron']) || !$settings['enable_cron']) {
        return;
    }
    
    try {
        $provider_instance = $core->get_provider_instance($provider_name);
        if (!$provider_instance) {
            return;
        }
        
        $limit = isset($settings['import_limit']) ? intval($settings['import_limit']) : 50;
        $coupons = $provider_instance->get_coupons($settings, $limit);
        
        $imported = 0;
        foreach ($coupons as $coupon_data) {
            $result = $core->import_coupon($coupon_data, $provider_name);
            if ($result) {
                $imported++;
            }
        }
        
        $core->get_logger()->log('import', sprintf(
            'Importação automática %s concluída: %d cupons obtidos, %d importados',
            $provider_name,
            count($coupons),
            $imported
        ));
        
    } catch (\Exception $e) {
        $core->get_logger()->log('error', sprintf(
            'Erro na importação automática %s: %s',
            $provider_name,
            $e->getMessage()
        ));
    }
}

function ci7k_format_date_for_display($date) {
    if (empty($date)) {
        return __('N/A', '7k-coupons-importer');
    }

    $timestamp = is_numeric($date) ? $date : strtotime($date);
    if (!$timestamp) {
        return $date;
    }

    return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
}

function ci7k_time_ago($time) {
    $time = is_numeric($time) ? $time : strtotime($time);
    $time_diff = time() - $time;

    if ($time_diff < 60) {
        return sprintf(_n('%s segundo atrás', '%s segundos atrás', $time_diff, '7k-coupons-importer'), $time_diff);
    }

    $time_diff = round($time_diff / 60);
    if ($time_diff < 60) {
        return sprintf(_n('%s minuto atrás', '%s minutos atrás', $time_diff, '7k-coupons-importer'), $time_diff);
    }

    $time_diff = round($time_diff / 60);
    if ($time_diff < 24) {
        return sprintf(_n('%s hora atrás', '%s horas atrás', $time_diff, '7k-coupons-importer'), $time_diff);
    }

    $time_diff = round($time_diff / 24);
    return sprintf(_n('%s dia atrás', '%s dias atrás', $time_diff, '7k-coupons-importer'), $time_diff);
}

function ci7k_get_coupon_status_label($status) {
    $labels = array(
        'pending' => __('Pendente', '7k-coupons-importer'),
        'approved' => __('Aprovado', '7k-coupons-importer'),
        'rejected' => __('Rejeitado', '7k-coupons-importer'),
        'published' => __('Publicado', '7k-coupons-importer'),
        'ignored' => __('Ignorado', '7k-coupons-importer')
    );

    return isset($labels[$status]) ? $labels[$status] : $status;
}

function ci7k_get_coupon_type_label($ctype) {
    return $ctype == 1 ? __('Cupom', '7k-coupons-importer') : __('Oferta', '7k-coupons-importer');
}

function ci7k_admin_notice($message, $type = 'success') {
    $class = $type === 'error' ? 'notice-error' : 'notice-success';
    echo '<div class="notice ' . esc_attr($class) . ' is-dismissible ci7k-notice"><p>' . esc_html($message) . '</p></div>';
}