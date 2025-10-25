<?php

namespace CouponImporter;

if (!defined('ABSPATH')) {
    exit;
}

class Logger {

    public function log($type, $message, $context = array()) {
        // Verificar se logs estão habilitados
        $settings = get_option('ci7k_settings', array());
        $logs_enabled = isset($settings['logs_enabled']) ? $settings['logs_enabled'] : 1;
        
        if (!$logs_enabled) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'ci7k_logs';

        $wpdb->insert(
            $table_name,
            array(
                'type' => $type,
                'message' => $message,
                'context' => maybe_serialize($context),
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s')
        );
    }

    public function log_api_request($provider, $endpoint, $response_code, $response_time, $error_message = null) {
        // Verificar se logs estão habilitados
        $settings = get_option('ci7k_settings', array());
        $logs_enabled = isset($settings['logs_enabled']) ? $settings['logs_enabled'] : 1;
        
        if (!$logs_enabled) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ci7k_api_logs';

        $wpdb->insert(
            $table,
            array(
                'provider' => $provider,
                'endpoint' => $endpoint,
                'response_code' => $response_code,
                'response_time' => $response_time,
                'error_message' => $error_message,
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s', '%d', '%f', '%s', '%s')
        );
    }

    public function get_logs($args = array()) {
        global $wpdb;
        $table = $wpdb->prefix . 'ci7k_logs';

        $defaults = array(
            'limit' => 50,
            'offset' => 0,
            'type' => null,
            'provider' => null,
            'severity' => null,
            'order' => 'DESC'
        );

        $args = wp_parse_args($args, $defaults);

        $where = array('1=1');
        $params = array();

        if ($args['type']) {
            $where[] = 'log_type = %s';
            $params[] = $args['type'];
        }

        if ($args['provider']) {
            $where[] = 'provider = %s';
            $params[] = $args['provider'];
        }

        if ($args['severity']) {
            $where[] = 'severity = %s';
            $params[] = $args['severity'];
        }

        $where_clause = implode(' AND ', $where);

        $query = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY created_at {$args['order']} LIMIT %d OFFSET %d";
        $params[] = $args['limit'];
        $params[] = $args['offset'];

        if (!empty($params)) {
            $query = $wpdb->prepare($query, $params);
        }

        return $wpdb->get_results($query);
    }

    public function get_api_logs($provider = null, $limit = 50) {
        global $wpdb;
        $table = $wpdb->prefix . 'ci7k_api_logs';

        if ($provider) {
            $query = $wpdb->prepare(
                "SELECT * FROM {$table} WHERE provider = %s ORDER BY created_at DESC LIMIT %d",
                $provider,
                $limit
            );
        } else {
            $query = $wpdb->prepare(
                "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d",
                $limit
            );
        }

        return $wpdb->get_results($query);
    }

    public function get_stats() {
        global $wpdb;
        $table = $wpdb->prefix . 'ci7k_logs';

        $stats = array();

        $stats['total_logs'] = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

        $stats['by_severity'] = $wpdb->get_results(
            "SELECT severity, COUNT(*) as count FROM {$table} GROUP BY severity",
            OBJECT_K
        );

        $stats['by_provider'] = $wpdb->get_results(
            "SELECT provider, COUNT(*) as count FROM {$table} WHERE provider IS NOT NULL GROUP BY provider",
            OBJECT_K
        );

        $stats['recent_errors'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} WHERE severity = 'error' AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );

        return $stats;
    }

    public function clear_old_logs($days = 30) {
        global $wpdb;
        $table = $wpdb->prefix . 'ci7k_logs';

        return $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            )
        );
    }
}