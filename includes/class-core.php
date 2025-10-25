<?php

namespace CouponImporter;

if (!defined('ABSPATH')) {
    exit;
}

class Core {

    private static $instance = null;
    private $cpt;
    private $logger;
    private $queue;
    private $mapper;
    private $ai_rewriter;
    private $image_fetcher;
    private $admin;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    private function load_dependencies() {
        require_once CI7K_PLUGIN_DIR . 'includes/class-cpt.php';
        require_once CI7K_PLUGIN_DIR . 'includes/class-logger.php';
        require_once CI7K_PLUGIN_DIR . 'includes/class-queue.php';
        require_once CI7K_PLUGIN_DIR . 'includes/class-mapper.php';
        require_once CI7K_PLUGIN_DIR . 'includes/class-ai-rewriter.php';
        require_once CI7K_PLUGIN_DIR . 'includes/class-taxonomy-helper.php';
        require_once CI7K_PLUGIN_DIR . 'includes/class-product-image-fetcher.php';
        require_once CI7K_PLUGIN_DIR . 'includes/providers/class-provider-interface.php';
        require_once CI7K_PLUGIN_DIR . 'includes/providers/class-rakuten.php';
        require_once CI7K_PLUGIN_DIR . 'includes/providers/class-awin.php';
        require_once CI7K_PLUGIN_DIR . 'includes/providers/class-lomadee.php';
        require_once CI7K_PLUGIN_DIR . 'includes/providers/class-admitad.php';

        if (is_admin()) {
            require_once CI7K_PLUGIN_DIR . 'admin/class-admin-menu.php';
            require_once CI7K_PLUGIN_DIR . 'admin/class-settings.php';
            require_once CI7K_PLUGIN_DIR . 'admin/class-curation-admin.php';
        }

        $this->cpt = new CPT();
        $this->logger = new Logger();
        $this->queue = new Queue();
        $this->mapper = new Mapper();
        $this->ai_rewriter = new AIRewriter();
        $this->image_fetcher = new ProductImageFetcher();

        if (is_admin()) {
            $this->admin = new \CouponImporter\Admin\AdminMenu();
            // Instanciar CurationAdmin para registrar hooks AJAX
            new \CouponImporter\Admin\CurationAdmin();
        }
    }

    private function init_hooks() {
        add_action('init', array($this, 'load_textdomain'));
        add_action('ci7k_import_cron', array($this, 'run_scheduled_import'));
        add_action('ci7k_process_queue', array($this, 'process_queue'));
        add_action('ci7k_auto_publish_cron', array($this, 'run_auto_publish'));
        
        // Gerenciar crons quando as configurações mudarem
        add_action('update_option_ci7k_settings', array($this, 'manage_auto_publish_cron'), 10, 2);
        
        // Cron para publicação automática de cupons aprovados
        add_action('ci7k_auto_publish_coupons', array($this, 'auto_publish_approved_coupons'));
        if (!wp_next_scheduled('ci7k_auto_publish_coupons')) {
            wp_schedule_event(time(), 'hourly', 'ci7k_auto_publish_coupons');
        }

        // Cron para limpar cupons publicados após 7 dias
        add_action('ci7k_cleanup_published_coupons', array($this, 'cleanup_old_published_coupons'));
        if (!wp_next_scheduled('ci7k_cleanup_published_coupons')) {
            wp_schedule_event(time(), 'daily', 'ci7k_cleanup_published_coupons');
        }

        // Registrar ações dos crons de importação dos provedores
        add_action('ci7k_import_rakuten_cron', array($this, 'run_rakuten_import'));
        add_action('ci7k_import_awin_cron', array($this, 'run_awin_import'));
        add_action('ci7k_import_lomadee_cron', array($this, 'run_lomadee_import'));
        add_action('ci7k_import_admitad_cron', array($this, 'run_admitad_import'));

        // Cron para publicação automática de cupons aprovados
        add_action('ci7k_auto_publish_coupons', array($this, 'auto_publish_approved_coupons'));
        if (!wp_next_scheduled('ci7k_auto_publish_coupons')) {
            wp_schedule_event(time(), 'hourly', 'ci7k_auto_publish_coupons');
        }

        // Cron para limpar cupons publicados após 7 dias
        add_action('ci7k_cleanup_published_coupons', array($this, 'cleanup_old_published_coupons'));
        if (!wp_next_scheduled('ci7k_cleanup_published_coupons')) {
            wp_schedule_event(time(), 'daily', 'ci7k_cleanup_published_coupons');
        }
    }

    public function load_textdomain() {
        load_plugin_textdomain('7k-coupons-importer', false, dirname(plugin_basename(CI7K_PLUGIN_FILE)) . '/languages');
    }

    public function run_scheduled_import() {
        $providers = array('rakuten', 'awin', 'lomadee', 'admitad');

        foreach ($providers as $provider_name) {
            $settings = ci7k_get_provider_settings($provider_name);

            if (empty($settings) || !isset($settings['enable_cron']) || !$settings['enable_cron']) {
                continue;
            }

            try {
                $provider_class = $this->get_provider_instance($provider_name);
                if (!$provider_class) {
                    continue;
                }

                $coupons = $provider_class->get_coupons($settings);

                foreach ($coupons as $coupon_data) {
                    $this->import_coupon($coupon_data, $provider_name);
                }

                $this->logger->log('import', sprintf(__('Importação automática concluída: %d cupons do %s', '7k-coupons-importer'), count($coupons), $provider_name));

            } catch (\Exception $e) {
                $this->logger->log('error', sprintf(__('Erro na importação automática %s: %s', '7k-coupons-importer'), $provider_name, $e->getMessage()), array('provider' => $provider_name));
            }
        }
    }

    public function import_coupon($coupon_data, $provider_name) {
        $external_id = isset($coupon_data['external_id']) ? $coupon_data['external_id'] : '';

        if (empty($external_id)) {
            $this->logger->log('error', 'Cupom sem external_id, pulando importação');
            return false;
        }

        $existing = get_posts(array(
            'post_type' => 'imported_coupon',
            'meta_key' => '_ci7k_external_id',
            'meta_value' => $external_id,
            'posts_per_page' => 1,
            'post_status' => 'any'
        ));

        if (!empty($existing)) {
            $this->logger->log('info', sprintf('Cupom já existe: %s (ID: %d)', $coupon_data['title'], $existing[0]->ID));
            return $existing[0]->ID;
        }

        $post_data = array(
            'post_type' => 'imported_coupon',
            'post_title' => $coupon_data['title'],
            'post_content' => isset($coupon_data['description']) ? $coupon_data['description'] : '',
            'post_status' => 'publish',
            'meta_input' => array(
                '_ci7k_provider' => $provider_name,
                '_ci7k_external_id' => $external_id,
                '_ci7k_status' => 'pending',
                '_ci7k_code' => isset($coupon_data['code']) ? $coupon_data['code'] : '',
                '_ci7k_link' => isset($coupon_data['link']) ? $coupon_data['link'] : '',
                '_ci7k_deeplink' => isset($coupon_data['deeplink']) ? $coupon_data['deeplink'] : '',
                '_ci7k_expiration' => isset($coupon_data['expiration']) ? $coupon_data['expiration'] : '',
                '_ci7k_advertiser' => isset($coupon_data['advertiser']) ? $coupon_data['advertiser'] : '',
                '_ci7k_advertiser_id' => isset($coupon_data['advertiser_id']) ? $coupon_data['advertiser_id'] : '',
                '_ci7k_coupon_type' => isset($coupon_data['coupon_type']) ? $coupon_data['coupon_type'] : 3,
                '_ci7k_is_exclusive' => isset($coupon_data['is_exclusive']) ? $coupon_data['is_exclusive'] : 0,
                '_ci7k_discount' => isset($coupon_data['discount']) ? $coupon_data['discount'] : '',
                '_ci7k_imported_at' => current_time('mysql')
            )
        );

        $post_id = wp_insert_post($post_data);

        if (is_wp_error($post_id)) {
            $this->logger->log('error', sprintf('Erro ao importar cupom: %s', $post_id->get_error_message()));
            return false;
        }

        if (isset($coupon_data['tags']) && is_array($coupon_data['tags'])) {
            update_post_meta($post_id, '_ci7k_tags', $coupon_data['tags']);
        }

        // Processar categorias
        $categories = array();

        // Adicionar categorias normais
        if (isset($coupon_data['category']) && is_array($coupon_data['category'])) {
            $categories = $coupon_data['category'];
        }

        // Se for Awin e tiver categoria do anunciante, adicionar ao array de categorias
        if ($provider_name === 'awin' && isset($coupon_data['awin_category']) && !empty($coupon_data['awin_category'])) {
            $categories[] = $coupon_data['awin_category'];
            $this->logger->log('info', sprintf('Categoria Awin "%s" adicionada ao cupom ID: %d',
                $coupon_data['awin_category'], $post_id));
        }

        // Salvar todas as categorias no meta
        if (!empty($categories)) {
            $categories = array_unique($categories); // Remover duplicatas
            update_post_meta($post_id, '_ci7k_categories', $categories);
        }

        $this->logger->log('import', sprintf('Cupom importado com sucesso: %s (ID: %d, External ID: %s)', $coupon_data['title'], $post_id, $external_id));

        $coupon_type = isset($coupon_data['coupon_type']) ? $coupon_data['coupon_type'] : 3;
        if ($coupon_type == 3) {
            $this->image_fetcher->fetch_and_apply_image($post_id, $provider_name);
        }

        return $post_id;
    }

    public function get_provider_instance($provider_name) {
        $class_map = array(
            'rakuten' => '\CouponImporter\Providers\Rakuten',
            'awin' => '\CouponImporter\Providers\Awin',
            'lomadee' => '\CouponImporter\Providers\Lomadee',
            'admitad' => '\CouponImporter\Providers\Admitad'
        );

        if (!isset($class_map[$provider_name])) {
            return null;
        }

        $class_name = $class_map[$provider_name];
        if (!class_exists($class_name)) {
            return null;
        }

        return new $class_name();
    }

    public function process_queue() {
        $this->queue->process();
    }

    public function get_logger() {
        return $this->logger;
    }

    public function get_queue() {
        return $this->queue;
    }

    public function get_mapper() {
        return $this->mapper;
    }

    public function get_ai_rewriter() {
        return $this->ai_rewriter;
    }

    public function get_image_fetcher() {
        return $this->image_fetcher;
    }

    public function run_auto_publish() {
        $settings = get_option('ci7k_settings', array());
        
        // Verificar se a publicação automática está ativada
        if (empty($settings['auto_publish'])) {
            $this->logger->log('info', 'Publicação automática desativada, pulando execução do cron');
            return;
        }
        
        $limit = isset($settings['auto_publish_limit']) ? intval($settings['auto_publish_limit']) : 10;
        
        $this->logger->log('info', sprintf('Iniciando publicação automática de cupons aprovados (limite: %d)', $limit));
        
        // Buscar cupons aprovados
        $approved_coupons = get_posts(array(
            'post_type' => 'imported_coupon',
            'posts_per_page' => $limit,
            'post_status' => 'publish',
            'meta_query' => array(
                array(
                    'key' => '_ci7k_status',
                    'value' => 'approved',
                    'compare' => '='
                )
            ),
            'orderby' => 'date',
            'order' => 'ASC'
        ));
        
        if (empty($approved_coupons)) {
            $this->logger->log('info', 'Nenhum cupom aprovado encontrado para publicação automática');
            return;
        }
        
        $published_count = 0;
        $error_count = 0;
        
        foreach ($approved_coupons as $coupon) {
            try {
                $result = $this->mapper->publish_coupon($coupon->ID);
                
                if ($result) {
                    $published_count++;
                    $this->logger->log('info', sprintf('Cupom publicado automaticamente: %s (ID: %d)', $coupon->post_title, $coupon->ID));
                } else {
                    $error_count++;
                    $this->logger->log('error', sprintf('Falha ao publicar cupom: %s (ID: %d)', $coupon->post_title, $coupon->ID));
                }
            } catch (\Exception $e) {
                $error_count++;
                $this->logger->log('error', sprintf('Erro ao publicar cupom %d: %s', $coupon->ID, $e->getMessage()));
            }
        }
        
        $this->logger->log('info', sprintf('Publicação automática concluída: %d publicados, %d erros', $published_count, $error_count));
    }
    
    public function manage_auto_publish_cron($old_value, $new_value) {
        $auto_publish_enabled = isset($new_value['auto_publish']) && $new_value['auto_publish'];
        $interval = isset($new_value['auto_publish_cron_interval']) ? $new_value['auto_publish_cron_interval'] : 'hourly';
        
        // Limpar cron existente
        $timestamp = wp_next_scheduled('ci7k_auto_publish_cron');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'ci7k_auto_publish_cron');
            $this->logger->log('info', 'Cron de publicação automática removido');
        }
        
        // Criar novo cron se ativado
        if ($auto_publish_enabled) {
            // Adicionar intervalos personalizados se não existirem
            add_filter('cron_schedules', array($this, 'add_custom_cron_intervals'));
            
            wp_schedule_event(time(), $interval, 'ci7k_auto_publish_cron');
            $this->logger->log('info', sprintf('Cron de publicação automática agendado: %s', $interval));
        }
    }
    
    public function add_custom_cron_intervals($schedules) {
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
        
        return $schedules;
    }
    
    /**
     * Executar importação automática do Rakuten
     */
    public function run_rakuten_import() {
        $this->logger->log('info', '🤖 Iniciando importação automática Rakuten...');
        
        $settings = ci7k_get_provider_settings('rakuten');
        
        if (empty($settings) || !isset($settings['enable_cron']) || !$settings['enable_cron']) {
            $this->logger->log('debug', 'Cron Rakuten desabilitado nas configurações');
            return;
        }
        
        try {
            $provider = $this->get_provider_instance('rakuten');
            if (!$provider) {
                throw new \Exception('Provedor Rakuten não encontrado');
            }
            
            $limit = isset($settings['import_limit']) ? intval($settings['import_limit']) : 50;
            $coupons = $provider->get_coupons($settings, $limit);
            
            $imported = 0;
            $skipped = 0;
            $errors = 0;
            
            foreach ($coupons as $coupon_data) {
                try {
                    $result = $this->import_coupon($coupon_data, 'rakuten');
                    if ($result) {
                        $imported++;
                    } else {
                        $skipped++;
                    }
                } catch (\Exception $e) {
                    $errors++;
                    $this->logger->log('error', sprintf('Erro ao importar cupom Rakuten: %s', $e->getMessage()));
                }
            }
            
            $this->logger->log('import', sprintf(
                '✅ Importação automática Rakuten concluída: %d obtidos, %d importados, %d ignorados, %d erros',
                count($coupons),
                $imported,
                $skipped,
                $errors
            ));
            
        } catch (\Exception $e) {
            $this->logger->log('error', sprintf('❌ Erro na importação automática Rakuten: %s', $e->getMessage()));
        }
    }

    /**
     * Executar importação automática do Awin
     */
    public function run_awin_import() {
        $this->logger->log('info', '🤖 Iniciando importação automática Awin...');
        
        $settings = ci7k_get_provider_settings('awin');
        
        if (empty($settings) || !isset($settings['enable_cron']) || !$settings['enable_cron']) {
            $this->logger->log('debug', 'Cron Awin desabilitado nas configurações');
            return;
        }
        
        try {
            $provider = $this->get_provider_instance('awin');
            if (!$provider) {
                throw new \Exception('Provedor Awin não encontrado');
            }
            
            $limit = isset($settings['import_limit']) ? intval($settings['import_limit']) : 50;
            $coupons = $provider->get_coupons($settings, $limit);
            
            $imported = 0;
            $skipped = 0;
            $errors = 0;
            
            foreach ($coupons as $coupon_data) {
                try {
                    $result = $this->import_coupon($coupon_data, 'awin');
                    if ($result) {
                        $imported++;
                    } else {
                        $skipped++;
                    }
                } catch (\Exception $e) {
                    $errors++;
                    $this->logger->log('error', sprintf('Erro ao importar cupom Awin: %s', $e->getMessage()));
                }
            }
            
            $this->logger->log('import', sprintf(
                '✅ Importação automática Awin concluída: %d obtidos, %d importados, %d ignorados, %d erros',
                count($coupons),
                $imported,
                $skipped,
                $errors
            ));
            
        } catch (\Exception $e) {
            $this->logger->log('error', sprintf('❌ Erro na importação automática Awin: %s', $e->getMessage()));
        }
    }
    
    /**
     * Executar importação automática do Lomadee
     */
    public function run_lomadee_import() {
        $this->logger->log('info', '🤖 Iniciando importação automática Lomadee...');

        $settings = ci7k_get_provider_settings('lomadee');

        if (empty($settings) || !isset($settings['enable_cron']) || !$settings['enable_cron']) {
            $this->logger->log('debug', 'Cron Lomadee desabilitado nas configurações');
            return;
        }

        try {
            $provider = $this->get_provider_instance('lomadee');
            if (!$provider) {
                throw new \Exception('Provedor Lomadee não encontrado');
            }

            $limit = isset($settings['import_limit']) ? intval($settings['import_limit']) : 50;
            $coupons = $provider->get_coupons($settings, $limit);

            $imported = 0;
            $skipped = 0;
            $errors = 0;

            foreach ($coupons as $coupon_data) {
                try {
                    $result = $this->import_coupon($coupon_data, 'lomadee');
                    if ($result) {
                        $imported++;
                    } else {
                        $skipped++;
                    }
                } catch (\Exception $e) {
                    $errors++;
                    $this->logger->log('error', sprintf('Erro ao importar cupom Lomadee: %s', $e->getMessage()));
                }
            }

            $this->logger->log('import', sprintf(
                '✅ Importação automática Lomadee concluída: %d obtidos, %d importados, %d ignorados, %d erros',
                count($coupons),
                $imported,
                $skipped,
                $errors
            ));

        } catch (\Exception $e) {
            $this->logger->log('error', sprintf('❌ Erro na importação automática Lomadee: %s', $e->getMessage()));
        }
    }

    /**
     * Executar importação automática do Admitad
     */
    public function run_admitad_import() {
        $this->logger->log('info', '🤖 Iniciando importação automática Admitad...');

        $settings = ci7k_get_provider_settings('admitad');

        if (empty($settings) || !isset($settings['enable_cron']) || !$settings['enable_cron']) {
            $this->logger->log('debug', 'Cron Admitad desabilitado nas configurações');
            return;
        }

        try {
            $provider = $this->get_provider_instance('admitad');
            if (!$provider) {
                throw new \Exception('Provedor Admitad não encontrado');
            }

            $limit = isset($settings['import_limit']) ? intval($settings['import_limit']) : 50;
            $coupons = $provider->get_coupons($settings, $limit);

            $imported = 0;
            $skipped = 0;
            $errors = 0;

            foreach ($coupons as $coupon_data) {
                try {
                    $result = $this->import_coupon($coupon_data, 'admitad');
                    if ($result) {
                        $imported++;
                    } else {
                        $skipped++;
                    }
                } catch (\Exception $e) {
                    $errors++;
                    $this->logger->log('error', sprintf('Erro ao importar cupom Admitad: %s', $e->getMessage()));
                }
            }

            $this->logger->log('import', sprintf(
                '✅ Importação automática Admitad concluída: %d obtidos, %d importados, %d ignorados, %d erros',
                count($coupons),
                $imported,
                $skipped,
                $errors
            ));

        } catch (\Exception $e) {
            $this->logger->log('error', sprintf('❌ Erro na importação automática Admitad: %s', $e->getMessage()));
        }
    }

    /**
     * Publicação automática de cupons aprovados
     */
    public function auto_publish_approved_coupons() {
        $this->logger->log('info', '🤖 Iniciando publicação automática de cupons aprovados...');

        // Buscar cupons com status 'approved' que ainda não foram publicados
        $args = array(
            'post_type' => 'imported_coupon',
            'post_status' => 'any',
            'posts_per_page' => 50,
            'meta_query' => array(
                array(
                    'key' => '_ci7k_status',
                    'value' => 'approved',
                    'compare' => '='
                )
            )
        );

        $query = new \WP_Query($args);
        $published_count = 0;
        $errors = 0;

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $coupon_id = get_the_ID();

                try {
                    // Verificar se já foi publicado (tem _ci7k_published_id)
                    $published_id = get_post_meta($coupon_id, '_ci7k_published_id', true);
                    
                    if (!empty($published_id) && get_post($published_id)) {
                        $this->logger->log('debug', sprintf('Cupom %d já foi publicado (ID: %d), pulando...', $coupon_id, $published_id));
                        continue;
                    }

                    // Publicar cupom
                    $new_published_id = $this->mapper->publish_coupon($coupon_id);
                    
                    if ($new_published_id) {
                        $published_count++;
                        $this->logger->log('info', sprintf('✅ Cupom %d publicado automaticamente (ID: %d)', $coupon_id, $new_published_id));
                    }
                } catch (\Exception $e) {
                    $errors++;
                    $this->logger->log('error', sprintf('❌ Erro ao publicar cupom %d: %s', $coupon_id, $e->getMessage()));
                }
            }
            wp_reset_postdata();
        }

        $this->logger->log('info', sprintf('🤖 Publicação automática concluída: %d cupons publicados, %d erros', $published_count, $errors));
    }
    
    /**
     * Limpar cupons publicados após 7 dias na curadoria
     */
    public function cleanup_old_published_coupons() {
        $this->logger->log('info', '🧹 Iniciando limpeza de cupons publicados antigos...');

        // Buscar cupons com status 'published' que foram publicados há mais de 7 dias
        $seven_days_ago = date('Y-m-d H:i:s', strtotime('-7 days'));

        $args = array(
            'post_type' => 'imported_coupon',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => '_ci7k_status',
                    'value' => 'published',
                    'compare' => '='
                ),
                array(
                    'key' => '_ci7k_published_at',
                    'value' => $seven_days_ago,
                    'compare' => '<',
                    'type' => 'DATETIME'
                )
            )
        );

        $query = new \WP_Query($args);
        $deleted_count = 0;

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $coupon_id = get_the_ID();
                $title = get_the_title();

                // Deletar permanentemente
                $result = wp_delete_post($coupon_id, true);
                
                if ($result) {
                    $deleted_count++;
                    $this->logger->log('info', sprintf('🗑️ Cupom publicado removido após 7 dias: "%s" (ID: %d)', $title, $coupon_id));
                }
            }
            wp_reset_postdata();
        }

        $this->logger->log('info', sprintf('🧹 Limpeza concluída: %d cupons removidos', $deleted_count));
    }
}