<?php

namespace CouponImporter;

if (!defined('ABSPATH')) {
    exit;
}

class Installer {

    public static function activate() {
        self::create_tables();
        self::create_default_options();
        self::schedule_cron();

        flush_rewrite_rules();
    }

    private static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql_queue = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ci7k_queue (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            coupon_id bigint(20) UNSIGNED NOT NULL,
            action varchar(50) NOT NULL,
            priority int(11) DEFAULT 10,
            status varchar(20) DEFAULT 'pending',
            attempts int(11) DEFAULT 0,
            error_message text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            processed_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY coupon_id (coupon_id),
            KEY status (status),
            KEY priority (priority)
        ) $charset_collate;";

        $sql_logs = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ci7k_logs (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            log_type varchar(50) NOT NULL,
            provider varchar(50) DEFAULT NULL,
            message text NOT NULL,
            context text,
            severity varchar(20) DEFAULT 'info',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY log_type (log_type),
            KEY provider (provider),
            KEY severity (severity),
            KEY created_at (created_at)
        ) $charset_collate;";

        $sql_api_logs = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ci7k_api_logs (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            provider varchar(50) NOT NULL,
            endpoint varchar(255) NOT NULL,
            response_code int(11) DEFAULT NULL,
            response_time decimal(10,2) DEFAULT NULL,
            error_message text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY provider (provider),
            KEY created_at (created_at)
        ) $charset_collate;";

        $sql_import_history = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ci7k_import_history (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            provider varchar(50) NOT NULL,
            total_fetched int(11) DEFAULT 0,
            total_imported int(11) DEFAULT 0,
            total_skipped int(11) DEFAULT 0,
            total_errors int(11) DEFAULT 0,
            status varchar(20) DEFAULT 'running',
            started_at datetime DEFAULT CURRENT_TIMESTAMP,
            completed_at datetime DEFAULT NULL,
            error_message text,
            PRIMARY KEY (id),
            KEY provider (provider),
            KEY status (status)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_queue);
        dbDelta($sql_logs);
        dbDelta($sql_api_logs);
        dbDelta($sql_import_history);
    }

    private static function create_default_options() {
        $defaults = array(
            'ai_provider' => 'openai',
            'openai_api_key' => '',
            'openai_model' => 'gpt-3.5-turbo',
            'gemini_api_key' => '',
            'gemini_model' => 'gemini-pro',
            'auto_publish' => false,
            'require_approval' => true,
            'ai_rewrite_enabled' => true,
            'delete_on_publish' => false
        );

        $existing = get_option('ci7k_settings', array());
        $settings = wp_parse_args($existing, $defaults);
        update_option('ci7k_settings', $settings);
    }

    private static function schedule_cron() {
        // Registrar intervalos personalizados
        add_filter('cron_schedules', 'ci7k_add_custom_cron_intervals');
        
        // Agendar crons dos provedores se já estiverem configurados
        $providers = array('rakuten', 'awin');
        foreach ($providers as $provider) {
            $settings = ci7k_get_provider_settings($provider);
            if (!empty($settings) && isset($settings['enable_cron']) && $settings['enable_cron']) {
                ci7k_manage_provider_cron($provider, $settings);
            }
        }
        
        // Agendar cron de publicação automática se já estiver configurado
        $general_settings = get_option('ci7k_settings', array());
        if (isset($general_settings['auto_publish']) && $general_settings['auto_publish']) {
            $interval = isset($general_settings['auto_publish_cron_interval']) ? $general_settings['auto_publish_cron_interval'] : 'hourly';
            if (!wp_next_scheduled('ci7k_auto_publish_cron')) {
                wp_schedule_event(time(), $interval, 'ci7k_auto_publish_cron');
            }
        }
    }
}