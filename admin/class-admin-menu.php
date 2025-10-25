<?php

namespace CouponImporter\Admin;

if (!defined('ABSPATH')) {
    exit;
}

class AdminMenu {

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_pages'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        
        // Adicionar handler AJAX para toggle de provedor
        add_action('wp_ajax_ci7k_toggle_provider', array($this, 'ajax_toggle_provider'));
    }

    public function ajax_toggle_provider() {
        check_ajax_referer('ci7k_import_nonce', '_ajax_nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permissão negada', '7k-coupons-importer')));
        }
        
        $provider = sanitize_text_field($_POST['provider']);
        $enabled = intval($_POST['enabled']);
        
        if (!in_array($provider, array('rakuten', 'awin'))) {
            wp_send_json_error(array('message' => __('Provedor inválido', '7k-coupons-importer')));
        }
        
        update_option("ci7k_{$provider}_enabled", $enabled);
        
        wp_send_json_success(array(
            'message' => $enabled ? 
                __('Provedor ativado com sucesso!', '7k-coupons-importer') : 
                __('Provedor desativado com sucesso!', '7k-coupons-importer'),
            'enabled' => $enabled
        ));
    }

    public function add_admin_pages() {
        $main_notification_count = $this->get_main_notification_count();
        $main_menu_title = __('7K Coupons', '7k-coupons-importer');
        if ($main_notification_count > 0) {
            $main_menu_title .= sprintf(' <span class="ci7k-notification-badge">!</span>');
        }

        add_menu_page(
            __('7K Coupons Importer', '7k-coupons-importer'),
            $main_menu_title,
            'manage_options',
            'ci7k-dashboard',
            array($this, 'render_dashboard'),
            'dashicons-tickets-alt',
            30
        );

        add_submenu_page(
            'ci7k-dashboard',
            __('Dashboard', '7k-coupons-importer'),
            __('Dashboard', '7k-coupons-importer'),
            'manage_options',
            'ci7k-dashboard',
            array($this, 'render_dashboard')
        );

        add_submenu_page(
            'ci7k-dashboard',
            __('Importar', '7k-coupons-importer'),
            __('Importar', '7k-coupons-importer'),
            'manage_options',
            'ci7k-import',
            array($this, 'render_import')
        );

        $pending_count = $this->get_pending_coupons_count();
        $curation_title = __('Curadoria', '7k-coupons-importer');
        if ($pending_count > 0) {
            $curation_title .= sprintf(' <span class="ci7k-notification-badge">%d</span>', $pending_count);
        }

        add_submenu_page(
            'ci7k-dashboard',
            __('Curadoria', '7k-coupons-importer'),
            $curation_title,
            'manage_options',
            'ci7k-curation',
            array($this, 'render_curation')
        );

        add_submenu_page(
            'ci7k-dashboard',
            __('Provedores', '7k-coupons-importer'),
            __('Provedores', '7k-coupons-importer'),
            'manage_options',
            'ci7k-providers',
            array($this, 'render_providers')
        );

        $unmapped_count = $this->get_unmapped_count();
        $mappings_title = __('Mapeamentos', '7k-coupons-importer');
        if ($unmapped_count > 0) {
            $mappings_title .= sprintf(' <span class="ci7k-notification-badge">!</span>');
        }

        add_submenu_page(
            'ci7k-dashboard',
            __('Mapeamentos', '7k-coupons-importer'),
            $mappings_title,
            'manage_options',
            'ci7k-mappings',
            array($this, 'render_mappings')
        );

        // Adicionar menu Logs apenas se estiver habilitado
        $settings = get_option('ci7k_settings', array());
        $logs_enabled = isset($settings['logs_enabled']) ? $settings['logs_enabled'] : 1;
        
        if ($logs_enabled) {
            add_submenu_page(
                'ci7k-dashboard',
                __('Logs', '7k-coupons-importer'),
                __('Logs', '7k-coupons-importer'),
                'manage_options',
                'ci7k-logs',
                array($this, 'render_logs')
            );
        }

        add_submenu_page(
            'ci7k-dashboard',
            __('Configurações', '7k-coupons-importer'),
            __('Configurações', '7k-coupons-importer'),
            'manage_options',
            'ci7k-settings',
            array($this, 'render_settings')
        );
    }

    private function get_pending_coupons_count() {
        global $wpdb;
        $count = $wpdb->get_var("
            SELECT COUNT(*)
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_ci7k_status'
            WHERE p.post_type = 'imported_coupon'
            AND p.post_status != 'trash'
            AND (pm.meta_value = 'pending' OR pm.meta_value IS NULL OR pm.meta_value = '')
        ");
        return intval($count);
    }

    private function get_unmapped_count() {
        $mapper = new \CouponImporter\Mapper();
        $has_unmapped = false;

        foreach (array('rakuten', 'awin', 'lomadee', 'admitad') as $provider) {
            $imported_stores = $mapper->get_imported_stores($provider);
            $store_mappings = $mapper->get_all_store_mappings($provider);

            $imported_categories = $mapper->get_imported_categories($provider);
            $category_mappings = $mapper->get_all_category_mappings($provider);

            foreach ($imported_stores as $store) {
                if (!isset($store_mappings[$store])) {
                    $has_unmapped = true;
                    break 2;
                }
            }

            foreach ($imported_categories as $category) {
                if (!isset($category_mappings[$category])) {
                    $has_unmapped = true;
                    break 2;
                }
            }
        }

        return $has_unmapped ? 1 : 0;
    }

    private function get_main_notification_count() {
        $pending = $this->get_pending_coupons_count();
        $unmapped = $this->get_unmapped_count();
        return ($pending > 0 || $unmapped > 0) ? 1 : 0;
    }

    public function enqueue_assets($hook) {
        if (strpos($hook, 'ci7k-') === false) {
            return;
        }

        wp_enqueue_style('ci7k-admin', CI7K_PLUGIN_URL . 'assets/css/admin.css', array(), CI7K_VERSION);
        wp_enqueue_script('ci7k-admin', CI7K_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), CI7K_VERSION, true);

        wp_localize_script('ci7k-admin', 'ci7k_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ci7k_nonce'),
            'strings' => array(
                'confirm_delete' => __('Tem certeza que deseja remover este cupom?', '7k-coupons-importer'),
                'confirm_bulk' => __('Tem certeza que deseja executar esta ação em massa?', '7k-coupons-importer'),
                'processing' => __('Processando...', '7k-coupons-importer'),
                'error' => __('Erro ao processar requisição', '7k-coupons-importer'),
                'success' => __('Operação concluída com sucesso', '7k-coupons-importer')
            )
        ));
    }

    public function render_dashboard() {
        require_once CI7K_PLUGIN_DIR . 'admin/views/dashboard.php';
    }

    public function render_curation() {
        // Obter a instância da CurationAdmin do Core
        $core = \CouponImporter\Core::get_instance();
        $curation = new \CouponImporter\Admin\CurationAdmin();
        $curation->render();
    }

    public function render_import() {
        require_once CI7K_PLUGIN_DIR . 'admin/views/import.php';
    }

    public function render_providers() {
        require_once CI7K_PLUGIN_DIR . 'admin/views/providers.php';
    }

    public function render_settings() {
        $settings = new Settings();
        $settings->render();
    }

    public function render_logs() {
        require_once CI7K_PLUGIN_DIR . 'admin/views/logs.php';
    }

    public function render_mappings() {
        require_once CI7K_PLUGIN_DIR . 'admin/views/mappings.php';
    }

    public function render_fixes() {
        // Processar ação de correção se solicitada
        if (isset($_POST['fix_all_coupons']) && wp_verify_nonce($_POST['_wpnonce'], 'ci7k_fix_coupons')) {
            $core = \CouponImporter\Core::get_instance();
            $mapper = $core->get_mapper();
            $fixed_count = $mapper->fix_all_published_coupons();
            
            echo '<div class="notice notice-success"><p>';
            printf(__('%d cupons foram corrigidos com sucesso!', '7k-coupons-importer'), $fixed_count);
            echo '</p></div>';
        }

        require_once CI7K_PLUGIN_DIR . 'admin/views/fixes.php';
    }
}