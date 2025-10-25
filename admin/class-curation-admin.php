<?php

namespace CouponImporter\Admin;

if (!defined('ABSPATH')) {
    exit;
}

class CurationAdmin {

    public function __construct() {
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Registrar hooks AJAX
        add_action('wp_ajax_ci7k_approve_coupon', array($this, 'ajax_approve_coupon'));
        add_action('wp_ajax_ci7k_reject_coupon', array($this, 'ajax_reject_coupon'));
        add_action('wp_ajax_ci7k_publish_coupon', array($this, 'ajax_publish_coupon'));
        add_action('wp_ajax_ci7k_delete_coupon', array($this, 'ajax_delete_coupon'));
        add_action('wp_ajax_ci7k_rewrite_title', array($this, 'ajax_rewrite_title'));
        add_action('wp_ajax_ci7k_rewrite_description', array($this, 'ajax_rewrite_description'));
        add_action('wp_ajax_ci7k_bulk_action', array($this, 'ajax_bulk_action'));
    }

    public function render() {
        $filter_status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $filter_provider = isset($_GET['provider']) ? sanitize_text_field($_GET['provider']) : '';
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;

        $args = array(
            'paged' => $paged,
            'status_filter' => $filter_status,
            'provider_filter' => $filter_provider,
            's' => $search
        );

        $query = $this->get_coupons_query($args);
        $stats = $this->get_stats();

        // Incluir a view
        require_once CI7K_PLUGIN_DIR . 'admin/views/curation.php';
    }

    private function get_coupons_query($args = array()) {
        $defaults = array(
            'post_type' => 'imported_coupon',
            'post_status' => 'any',
            'posts_per_page' => 20,
            'paged' => 1,
            'meta_query' => array(),
            's' => '',
            'orderby' => 'meta_value',
            'meta_key' => '_ci7k_status',
            'order' => 'ASC'
        );

        $args = wp_parse_args($args, $defaults);

        // Filtro por status de curadoria (não post_status)
        if (!empty($args['status_filter'])) {
            $args['meta_query'][] = array(
                'key' => '_ci7k_status',
                'value' => $args['status_filter'],
                'compare' => '='
            );
        }

        // Filtro por provedor
        if (!empty($args['provider_filter'])) {
            $args['meta_query'][] = array(
                'key' => '_ci7k_provider',
                'value' => $args['provider_filter'],
                'compare' => '='
            );
        }

        // Ordenação customizada para colocar publicados no final
        add_filter('posts_clauses', array($this, 'order_published_last'), 10, 2);

        // Remover filtros customizados antes de passar para WP_Query
        unset($args['status_filter'], $args['provider_filter']);

        $query = new \WP_Query($args);

        // Remover o filtro após a query
        remove_filter('posts_clauses', array($this, 'order_published_last'), 10);

        return $query;
    }

    public function order_published_last($clauses, $query) {
        global $wpdb;

        if ($query->get('post_type') === 'imported_coupon') {
            $clauses['orderby'] = "CASE WHEN {$wpdb->postmeta}.meta_value = 'published' THEN 2 ELSE 1 END ASC, {$wpdb->posts}.post_date DESC";
        }

        return $clauses;
    }

    private function get_stats() {
        $stats = array(
            'total' => 0,
            'pending' => 0,
            'approved' => 0,
            'published' => 0,
            'published_last_7_days' => 0,
            'rejected' => 0
        );

        // Contar por status de curadoria, não post_status
        $status_counts = array();
        
        global $wpdb;
        $results = $wpdb->get_results("
            SELECT pm.meta_value as status, COUNT(*) as count 
            FROM {$wpdb->posts} p 
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_ci7k_status'
            WHERE p.post_type = 'imported_coupon' 
            AND p.post_status != 'trash'
            GROUP BY pm.meta_value
        ");

        foreach ($results as $result) {
            $status = $result->status ?: 'pending';
            $status_counts[$status] = (int) $result->count;
        }

        $stats['total'] = array_sum($status_counts);
        $stats['pending'] = $status_counts['pending'] ?? 0;
        $stats['approved'] = $status_counts['approved'] ?? 0;
        $stats['published'] = $status_counts['published'] ?? 0;
        $stats['rejected'] = $status_counts['rejected'] ?? 0;

        // Contar cupons publicados nos últimos 7 dias
        $seven_days_ago = date('Y-m-d H:i:s', strtotime('-7 days'));
        $stats['published_last_7_days'] = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) 
            FROM {$wpdb->posts} p 
            INNER JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_ci7k_status'
            INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_ci7k_published_at'
            WHERE p.post_type = 'imported_coupon' 
            AND p.post_status != 'trash'
            AND pm1.meta_value = 'published'
            AND pm2.meta_value >= %s
        ", $seven_days_ago));

        return $stats;
    }

    public function ajax_approve_coupon() {
        check_ajax_referer('ci7k_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permissão negada', '7k-coupons-importer')));
        }

        $post_id = isset($_POST['coupon_id']) ? intval($_POST['coupon_id']) : 0;
        $post = get_post($post_id);

        if (!$post || $post->post_type !== 'imported_coupon') {
            wp_send_json_error(array('message' => __('Cupom não encontrado', '7k-coupons-importer')));
        }

        // Apenas aprovar, não publicar
        update_post_meta($post_id, '_ci7k_status', 'approved');
        update_post_meta($post_id, '_ci7k_approved_at', current_time('mysql'));
        update_post_meta($post_id, '_ci7k_approved_by', get_current_user_id());

        $core = \CouponImporter\Core::get_instance();
        $core->get_logger()->log('approve', sprintf(__('Cupom aprovado: %s (ID: %d)', '7k-coupons-importer'), $post->post_title, $post_id));

        wp_send_json_success(array(
            'message' => __('Cupom aprovado com sucesso!', '7k-coupons-importer'),
            'removed' => false
        ));
    }

    public function ajax_reject_coupon() {
        check_ajax_referer('ci7k_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(__('Permissão negada', '7k-coupons-importer'));
        }

        $coupon_id = intval($_POST['coupon_id']);
        
        if (!$coupon_id) {
            wp_send_json_error(array('message' => __('ID do cupom inválido', '7k-coupons-importer')));
        }

        $post = get_post($coupon_id);
        if (!$post || $post->post_type !== 'imported_coupon') {
            wp_send_json_error(array('message' => __('Cupom não encontrado', '7k-coupons-importer')));
        }

        update_post_meta($coupon_id, '_ci7k_status', 'rejected');
        update_post_meta($coupon_id, '_ci7k_rejected_at', current_time('mysql'));
        update_post_meta($coupon_id, '_ci7k_rejected_by', get_current_user_id());

        $core = \CouponImporter\Core::get_instance();
        $core->get_logger()->log('reject', sprintf(__('Cupom rejeitado: %s (ID: %d)', '7k-coupons-importer'), $post->post_title, $coupon_id));

        wp_send_json_success(array('message' => __('Cupom rejeitado com sucesso', '7k-coupons-importer')));
    }

    public function ajax_publish_coupon() {
        check_ajax_referer('ci7k_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(__('Permissão negada', '7k-coupons-importer'));
        }

        $coupon_id = intval($_POST['coupon_id']);
        
        if (!$coupon_id) {
            wp_send_json_error(array('message' => __('ID do cupom inválido', '7k-coupons-importer')));
        }

        try {
            $core = \CouponImporter\Core::get_instance();
            $mapper = $core->get_mapper();
            
            $published_id = $mapper->publish_coupon($coupon_id);
            
            wp_send_json_success(array(
                'message' => __('Cupom publicado com sucesso', '7k-coupons-importer'),
                'published_id' => $published_id
            ));
            
        } catch (\Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    public function ajax_delete_coupon() {
        check_ajax_referer('ci7k_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(__('Permissão negada', '7k-coupons-importer'));
        }

        $coupon_id = intval($_POST['coupon_id']);
        
        if (!$coupon_id) {
            wp_send_json_error(array('message' => __('ID do cupom inválido', '7k-coupons-importer')));
        }

        $post = get_post($coupon_id);
        if (!$post || $post->post_type !== 'imported_coupon') {
            wp_send_json_error(array('message' => __('Cupom não encontrado', '7k-coupons-importer')));
        }

        $result = wp_delete_post($coupon_id, true);
        
        if ($result) {
            $core = \CouponImporter\Core::get_instance();
            $core->get_logger()->log('delete', sprintf(__('Cupom removido: %s (ID: %d)', '7k-coupons-importer'), $post->post_title, $coupon_id));
            
            wp_send_json_success(array('message' => __('Cupom removido com sucesso', '7k-coupons-importer')));
        } else {
            wp_send_json_error(array('message' => __('Erro ao remover cupom', '7k-coupons-importer')));
        }
    }

    public function ajax_rewrite_title() {
        check_ajax_referer('ci7k_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(__('Permissão negada', '7k-coupons-importer'));
        }

        $coupon_id = intval($_POST['coupon_id']);
        
        if (!$coupon_id) {
            wp_send_json_error(array('message' => __('ID do cupom inválido', '7k-coupons-importer')));
        }

        try {
            $post = get_post($coupon_id);
            if (!$post || $post->post_type !== 'imported_coupon') {
                wp_send_json_error(array('message' => __('Cupom não encontrado', '7k-coupons-importer')));
            }

            $core = \CouponImporter\Core::get_instance();
            $ai_rewriter = $core->get_ai_rewriter();
            
            $new_title = $ai_rewriter->rewrite_title($post->post_title);
            
            if (!empty($new_title) && $new_title !== $post->post_title) {
                wp_update_post(array(
                    'ID' => $coupon_id,
                    'post_title' => $new_title
                ));
                
                $core->get_logger()->log('ai_rewrite', sprintf(
                    __('Título reescrito com IA: %s -> %s (ID: %d)', '7k-coupons-importer'),
                    $post->post_title,
                    $new_title,
                    $coupon_id
                ));
                
                wp_send_json_success(array(
                    'message' => __('Título reescrito com sucesso!', '7k-coupons-importer'),
                    'new_title' => $new_title
                ));
            } else {
                wp_send_json_error(array('message' => __('Não foi possível reescrever o título', '7k-coupons-importer')));
            }
            
        } catch (\Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    public function ajax_rewrite_description() {
        check_ajax_referer('ci7k_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(__('Permissão negada', '7k-coupons-importer'));
        }

        $coupon_id = intval($_POST['coupon_id']);
        
        if (!$coupon_id) {
            wp_send_json_error(array('message' => __('ID do cupom inválido', '7k-coupons-importer')));
        }

        try {
            $post = get_post($coupon_id);
            if (!$post || $post->post_type !== 'imported_coupon') {
                wp_send_json_error(array('message' => __('Cupom não encontrado', '7k-coupons-importer')));
            }

            $core = \CouponImporter\Core::get_instance();
            $ai_rewriter = $core->get_ai_rewriter();
            
            $new_description = $ai_rewriter->rewrite_description($post->post_content);
            
            if (!empty($new_description) && $new_description !== $post->post_content) {
                wp_update_post(array(
                    'ID' => $coupon_id,
                    'post_content' => $new_description
                ));
                
                $core->get_logger()->log('ai_rewrite', sprintf(
                    __('Descrição reescrita com IA: %s (ID: %d)', '7k-coupons-importer'),
                    $post->post_title,
                    $coupon_id
                ));
                
                wp_send_json_success(array(
                    'message' => __('Descrição reescrita com sucesso!', '7k-coupons-importer'),
                    'new_description' => $new_description
                ));
            } else {
                wp_send_json_error(array('message' => __('Não foi possível reescrever a descrição', '7k-coupons-importer')));
            }
            
        } catch (\Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    public function ajax_bulk_action() {
        check_ajax_referer('ci7k_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(__('Permissão negada', '7k-coupons-importer'));
        }

        $action_type = sanitize_text_field($_POST['action_type']);
        $coupon_ids = array_map('intval', $_POST['coupon_ids']);
        
        if (empty($action_type) || empty($coupon_ids)) {
            wp_send_json_error(array('message' => __('Parâmetros inválidos', '7k-coupons-importer')));
        }

        $processed = 0;
        $errors = array();

        // Ações especiais de reescrita com IA
        if ($action_type === 'rewrite_titles' || $action_type === 'rewrite_descriptions') {
            try {
                $result = $this->process_bulk_ai_rewrite($coupon_ids, $action_type);
                wp_send_json_success(array('message' => $result['message']));
            } catch (\Exception $e) {
                wp_send_json_error(array('message' => $e->getMessage()));
            }
            return;
        }

        // Ações normais
        foreach ($coupon_ids as $coupon_id) {
            try {
                switch ($action_type) {
                    case 'approve':
                        update_post_meta($coupon_id, '_ci7k_status', 'approved');
                        break;
                    case 'reject':
                        update_post_meta($coupon_id, '_ci7k_status', 'rejected');
                        break;
                    case 'publish':
                        $core = \CouponImporter\Core::get_instance();
                        $mapper = $core->get_mapper();
                        $mapper->publish_coupon($coupon_id);
                        break;
                    case 'delete':
                        wp_delete_post($coupon_id, true);
                        break;
                }
                $processed++;
            } catch (\Exception $e) {
                $errors[] = sprintf(__('Erro no cupom %d: %s', '7k-coupons-importer'), $coupon_id, $e->getMessage());
            }
        }

        $message = sprintf(__('%d cupons processados com sucesso', '7k-coupons-importer'), $processed);
        if (!empty($errors)) {
            $message .= '. ' . __('Erros:', '7k-coupons-importer') . ' ' . implode(', ', $errors);
        }

        wp_send_json_success(array('message' => $message));
    }

    private function process_bulk_ai_rewrite($coupon_ids, $action_type) {
        $settings = get_option('ci7k_settings', array());
        
        // Verificar se IA está habilitada
        if (empty($settings['ai_rewrite_enabled'])) {
            throw new \Exception(__('Reescrita com IA não está habilitada nas configurações', '7k-coupons-importer'));
        }

        $provider = isset($settings['ai_provider']) ? $settings['ai_provider'] : 'openai';
        $field_type = $action_type === 'rewrite_titles' ? 'title' : 'description';
        
        // Obter prompt configurado
        $prompt_key = $provider . '_' . $field_type . '_prompt';
        $prompt = isset($settings[$prompt_key]) ? $settings[$prompt_key] : $this->get_default_prompt($field_type);
        
        // Validar se prompt contém %s
        if (strpos($prompt, '%s') === false) {
            throw new \Exception(__('O prompt configurado deve conter o marcador %s para inserir a lista de cupons. Por favor, verifique as configurações de IA.', '7k-coupons-importer'));
        }

        // Coletar dados dos cupons
        $coupon_data = array();
        foreach ($coupon_ids as $coupon_id) {
            $post = get_post($coupon_id);
            if (!$post || $post->post_type !== 'imported_coupon') {
                continue;
            }

            $advertiser = get_post_meta($coupon_id, '_ci7k_advertiser', true);
            $text = $field_type === 'title' ? $post->post_title : $post->post_content;
            
            $coupon_data[] = array(
                'id' => $coupon_id,
                'advertiser' => $advertiser,
                'text' => $text,
                'line' => $advertiser . ', ' . $text
            );
        }

        if (empty($coupon_data)) {
            throw new \Exception(__('Nenhum cupom válido encontrado para reescrita', '7k-coupons-importer'));
        }

        // Montar lista para IA
        $lines = array_map(function($item) { return $item['line']; }, $coupon_data);
        $list_text = implode("\n", $lines);
        
        // Substituir placeholder no prompt
        $final_prompt = str_replace('%s', $list_text, $prompt);

        // Chamar IA
        $core = \CouponImporter\Core::get_instance();
        $ai_rewriter = $core->get_ai_rewriter();
        
        try {
            if ($provider === 'openai') {
                $rewritten_text = $this->call_openai_bulk($final_prompt, $settings);
            } elseif ($provider === 'gemini') {
                $rewritten_text = $this->call_gemini_bulk($final_prompt, $settings);
            } else {
                throw new \Exception(__('Provedor de IA não configurado', '7k-coupons-importer'));
            }

            // Processar resposta da IA
            $rewritten_lines = explode("\n", trim($rewritten_text));
            
            // Filtrar e limpar linhas
            $rewritten_lines = array_values(array_filter(array_map(function($line) {
                $line = trim($line);
                
                // Remover numeração (1., 2., 1), 2), etc)
                $line = preg_replace('/^\d+[\.\)]\s*/', '', $line);
                
                // Remover marcadores (-, *, •, etc)
                $line = preg_replace('/^[\-\*\•]\s*/', '', $line);
                
                // Remover aspas no início e fim
                $line = trim($line, '"\'');
                
                return $line;
            }, $rewritten_lines), function($line) {
                // Filtrar linhas vazias ou muito curtas
                return !empty($line) && strlen($line) > 3;
            }));

            if (count($rewritten_lines) !== count($coupon_data)) {
                // Log para debug
                $core->get_logger()->log('error', sprintf(
                    __('Erro de contagem na reescrita: esperado %d, recebido %d. Linhas: %s', '7k-coupons-importer'),
                    count($coupon_data),
                    count($rewritten_lines),
                    implode(' | ', $rewritten_lines)
                ));
                
                throw new \Exception(sprintf(
                    __('A IA retornou %d linhas, mas esperávamos %d. Tente novamente.', '7k-coupons-importer'),
                    count($rewritten_lines),
                    count($coupon_data)
                ));
            }

            // Atualizar cupons
            $updated = 0;
            foreach ($coupon_data as $index => $item) {
                $new_text = trim($rewritten_lines[$index]);
                if (!empty($new_text)) {
                    if ($field_type === 'title') {
                        wp_update_post(array(
                            'ID' => $item['id'],
                            'post_title' => $new_text
                        ));
                    } else {
                        wp_update_post(array(
                            'ID' => $item['id'],
                            'post_content' => $new_text
                        ));
                    }
                    $updated++;
                }
            }

            $core->get_logger()->log('ai_rewrite', sprintf(
                __('Reescrita em massa concluída: %d %s reescritos com %s', '7k-coupons-importer'),
                $updated,
                $field_type === 'title' ? 'títulos' : 'descrições',
                $provider
            ));

            return array(
                'message' => sprintf(__('%d cupons reescritos com sucesso!', '7k-coupons-importer'), $updated)
            );

        } catch (\Exception $e) {
            throw new \Exception(__('Erro na reescrita com IA: ', '7k-coupons-importer') . $e->getMessage());
        }
    }

    private function call_openai_bulk($prompt, $settings) {
        $core = \CouponImporter\Core::get_instance();
        $ai_rewriter = $core->get_ai_rewriter();

        $api_keys = isset($settings['openai_api_keys']) ? $settings['openai_api_keys'] : array();

        if (empty($api_keys)) {
            $api_key = isset($settings['openai_api_key']) ? $settings['openai_api_key'] : '';
        } else {
            $api_key = is_array($api_keys) ? $api_keys[0] : $api_keys;
        }

        $model = isset($settings['openai_model']) ? $settings['openai_model'] : 'gpt-3.5-turbo';

        if (empty($api_key)) {
            throw new \Exception(__('OpenAI API Key não configurada', '7k-coupons-importer'));
        }

        $body = array(
            'model' => $model,
            'messages' => array(
                array(
                    'role' => 'system',
                    'content' => 'Você é um especialista em marketing de afiliados e copywriting para cupons de desconto. Reescreva os textos de forma profissional e atraente em português brasileiro. IMPORTANTE: Retorne APENAS a lista reescrita, uma linha por item, SEM numeração, SEM marcadores, SEM comentários adicionais.'
                ),
                array(
                    'role' => 'user',
                    'content' => $prompt
                )
            ),
            'temperature' => 0.7,
            'max_tokens' => 2000
        );

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'timeout' => 60,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($body)
        ));

        if (is_wp_error($response)) {
            throw new \Exception($response->get_error_message());
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            $body = wp_remote_retrieve_body($response);
            throw new \Exception(sprintf(__('OpenAI API retornou erro %d: %s', '7k-coupons-importer'), $response_code, $body));
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (!isset($data['choices'][0]['message']['content'])) {
            throw new \Exception(__('Resposta inválida da OpenAI API', '7k-coupons-importer'));
        }

        return $data['choices'][0]['message']['content'];
    }

    private function call_gemini_bulk($prompt, $settings) {
        $api_keys = isset($settings['gemini_api_keys']) ? $settings['gemini_api_keys'] : array();

        if (empty($api_keys)) {
            $api_key = isset($settings['gemini_api_key']) ? $settings['gemini_api_key'] : '';
        } else {
            $api_key = is_array($api_keys) ? $api_keys[0] : $api_keys;
        }

        $model = isset($settings['gemini_model']) ? $settings['gemini_model'] : 'gemini-pro';

        if (empty($api_key)) {
            throw new \Exception(__('Gemini API Key não configurada', '7k-coupons-importer'));
        }

        $system_instruction = 'Você é um especialista em marketing de afiliados e copywriting para cupons de desconto. Reescreva os textos de forma profissional e atraente em português brasileiro. IMPORTANTE: Retorne APENAS a lista reescrita, uma linha por item, SEM numeração, SEM marcadores, SEM comentários adicionais.';

        $body = array(
            'contents' => array(
                array(
                    'parts' => array(
                        array('text' => $system_instruction . "\n\n" . $prompt)
                    )
                )
            ),
            'generationConfig' => array(
                'temperature' => 0.7,
                'maxOutputTokens' => 2000
            )
        );

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$api_key}";

        $response = wp_remote_post($url, array(
            'timeout' => 60,
            'headers' => array(
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($body)
        ));

        if (is_wp_error($response)) {
            throw new \Exception($response->get_error_message());
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            $body = wp_remote_retrieve_body($response);
            throw new \Exception(sprintf(__('Gemini API retornou erro %d: %s', '7k-coupons-importer'), $response_code, $body));
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (!isset($data['candidates'][0]['content']['parts'][0]['text'])) {
            throw new \Exception(__('Resposta inválida da Gemini API', '7k-coupons-importer'));
        }

        return $data['candidates'][0]['content']['parts'][0]['text'];
    }

    private function get_default_prompt($type) {
        if ($type === 'title') {
            return 'Reescreva de forma natural e profissional a seguinte lista %s e me devolva apenas a lista reescrita, sem comentários, explicações ou numeração.';
        } else {
            return 'Reescreva de forma natural e profissional a seguinte lista %s e me devolva apenas a lista reescrita, sem comentários, explicações ou numeração.';
        }
    }

    public function enqueue_scripts($hook) {
        if (strpos($hook, 'ci7k-curation') === false) {
            return;
        }

        wp_enqueue_script('ci7k-admin', CI7K_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), CI7K_VERSION, true);

        wp_localize_script('ci7k-admin', 'ci7k_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ci7k_nonce'),
            'strings' => array(
                'processing' => __('Processando...', '7k-coupons-importer'),
                'error' => __('Erro ao processar requisição', '7k-coupons-importer'),
                'confirm_delete' => __('Tem certeza que deseja remover este cupom?', '7k-coupons-importer'),
                'confirm_bulk' => __('Tem certeza que deseja aplicar esta ação aos cupons selecionados?', '7k-coupons-importer')
            )
        ));
    }
}