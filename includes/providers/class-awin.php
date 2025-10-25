<?php
/**
 * Awin Provider Class
 * Implements Awin API, parsing data, mapping to CPT
 */

namespace CouponImporter\Providers;

if (!defined('ABSPATH')) {
    exit;
}

class Awin {

    private $api_base_url = 'https://api.awin.com';
    private $logger;

    public function __construct() {
        $this->logger = new \CouponImporter\Logger();
    }

    public function get_name() {
        return __('Awin', '7k-coupons-importer');
    }

    public function get_settings_fields() {
        return array(
            'api_token' => array(
                'label' => __('API Token', '7k-coupons-importer'),
                'type' => 'password',
                'required' => true,
                'description' => __('Token de autenticação da API Awin', '7k-coupons-importer')
            ),
            'publisher_id' => array(
                'label' => __('Publisher ID', '7k-coupons-importer'),
                'type' => 'text',
                'required' => true,
                'description' => __('Seu Publisher ID da Awin (necessário para buscar categorias)', '7k-coupons-importer')
            ),
            'import_limit' => array(
                'label' => __('Limite de Importação', '7k-coupons-importer'),
                'type' => 'number',
                'default' => 50,
                'description' => __('Número máximo de cupons por importação (Recomendado: 50-200 para evitar timeout)', '7k-coupons-importer')
            ),
            'filter_type' => array(
                'label' => __('Tipo de Promoção', '7k-coupons-importer'),
                'type' => 'select',
                'default' => 'all',
                'options' => array(
                    'all' => __('Todos', '7k-coupons-importer'),
                    'promotion' => __('Promotion', '7k-coupons-importer'),
                    'voucher' => __('Voucher', '7k-coupons-importer')
                ),
                'description' => __('Filtrar por tipo de promoção', '7k-coupons-importer')
            ),
            'filter_membership' => array(
                'label' => __('Membership', '7k-coupons-importer'),
                'type' => 'select',
                'default' => 'joined',
                'options' => array(
                    'all' => __('All', '7k-coupons-importer'),
                    'joined' => __('Joined', '7k-coupons-importer'),
                    'notJoined' => __('Not Joined', '7k-coupons-importer')
                ),
                'description' => __('Filtrar por status de membership com o anunciante', '7k-coupons-importer')
            ),
            'enable_cron' => array(
                'label' => __('Importação Automática', '7k-coupons-importer'),
                'type' => 'checkbox',
                'default' => 0,
                'description' => __('Ativar importação automática via cron', '7k-coupons-importer')
            ),
            'cron_schedule' => array(
                'label' => __('Frequência', '7k-coupons-importer'),
                'type' => 'select',
                'default' => 'daily',
                'options' => array(
                    'hourly' => __('A cada hora', '7k-coupons-importer'),
                    'twicedaily' => __('Duas vezes ao dia', '7k-coupons-importer'),
                    'daily' => __('Diariamente', '7k-coupons-importer')
                ),
                'description' => __('Com que frequência executar a importação automática', '7k-coupons-importer')
            )
        );
    }

    public function validate_settings($settings) {
        if (empty($settings['api_token'])) {
            return false;
        }

        if (empty($settings['publisher_id'])) {
            return false;
        }

        return true;
    }

    public function test_connection($settings) {
        try {
            $endpoint = '/publishers/' . $settings['publisher_id'] . '/programmes';
            $params = array(
                'relationship' => 'joined'
            );
            $response = $this->make_api_request($endpoint, $settings, $params);

            if (is_wp_error($response)) {
                $this->logger->log('error', 'Awin test connection failed: ' . $response->get_error_message());
                return false;
            }

            return true;
        } catch (Exception $e) {
            $this->logger->log('error', 'Awin test connection exception: ' . $e->getMessage());
            return false;
        }
    }

    public function get_coupons($settings, $limit = null) {
        $limit = $limit ?: (isset($settings['import_limit']) ? intval($settings['import_limit']) : 50);
        $all_promotions = array();

        $this->logger->log('info', sprintf('Awin: Iniciando busca de cupons, limite: %d', $limit));

        // Correct endpoint according to Awin API documentation
        $endpoint = '/publisher/' . $settings['publisher_id'] . '/promotions';

        // Build filters from settings
        $filters = array(
            'membership' => isset($settings['filter_membership']) ? $settings['filter_membership'] : 'joined',
            'status' => isset($settings['filter_status']) ? $settings['filter_status'] : 'active',
            'type' => isset($settings['filter_type']) ? $settings['filter_type'] : 'all'
        );

        // Add exclusive filter if enabled
        if (isset($settings['filter_exclusive_only']) && $settings['filter_exclusive_only']) {
            $filters['exclusiveOnly'] = true;
        }

        // Add advertiser IDs if specified
        if (!empty($settings['filter_advertiser_ids'])) {
            $advertiser_ids = array_map('intval', array_map('trim', explode(',', $settings['filter_advertiser_ids'])));
            $filters['advertiserIds'] = array_filter($advertiser_ids);
        }

        // Add region codes if specified
        if (!empty($settings['filter_region_codes'])) {
            $region_codes = array_map('trim', array_map('strtoupper', explode(',', $settings['filter_region_codes'])));
            $filters['regionCodes'] = array_filter($region_codes);
        }

        $this->logger->log('debug', 'Awin: Filtros aplicados: ' . json_encode($filters));

        // Buscar cupons em múltiplas páginas até atingir o limite
        $page = 1;
        $page_size = min(200, $limit); // API permite até 200 por página
        $parsed_count = 0;
        $total_fetched = 0;

        while ($parsed_count < $limit) {
            // Build request body according to API documentation
            $body = array(
                'filters' => $filters,
                'pagination' => array(
                    'page' => $page,
                    'pageSize' => $page_size
                )
            );

            $this->logger->log('debug', sprintf('Awin: Buscando página %d (pageSize: %d)', $page, $page_size));

            $response = $this->make_api_post_request($endpoint, $settings, $body);

            if (is_wp_error($response)) {
                $this->logger->log('error', 'Awin API error: ' . $response->get_error_message());
                break;
            }

            // The API returns promotions in 'data' key
            $promotions_data = array();
            
            if (isset($response['data']) && is_array($response['data'])) {
                $promotions_data = $response['data'];
            } elseif (isset($response['promotions']) && is_array($response['promotions'])) {
                $promotions_data = $response['promotions'];
            } elseif (isset($response['items']) && is_array($response['items'])) {
                $promotions_data = $response['items'];
            } elseif (is_array($response) && !empty($response)) {
                // If response is directly an array of promotions
                $promotions_data = $response;
            }

            $total_fetched += count($promotions_data);
            $this->logger->log('info', sprintf('Awin: Página %d - %d promoções encontradas', $page, count($promotions_data)));

            if (empty($promotions_data)) {
                $this->logger->log('info', sprintf('Awin: Nenhuma promoção na página %d, finalizando busca', $page));
                break;
            }

            // Parsear cupons desta página
            $page_parsed = $this->parse_coupons($promotions_data);
            
            foreach ($page_parsed as $coupon) {
                if ($parsed_count >= $limit) {
                    break;
                }
                $all_promotions[] = $coupon;
                $parsed_count++;
            }

            $this->logger->log('debug', sprintf('Awin: Página %d processada - %d cupons novos adicionados (total: %d/%d)', 
                $page, count($page_parsed), $parsed_count, $limit));

            // Se já atingiu o limite ou não há mais páginas
            if ($parsed_count >= $limit || count($promotions_data) < $page_size) {
                break;
            }

            $page++;
        }

        $this->logger->log('info', sprintf('Awin: Busca finalizada - %d cupons buscados da API, %d cupons novos importados', 
            $total_fetched, count($all_promotions)));

        return $all_promotions;
    }

    private function make_api_request($endpoint, $settings, $params = array()) {
        $start_time = microtime(true);

        $url = $this->api_base_url . $endpoint;

        if (!empty($params)) {
            $url = add_query_arg($params, $url);
        }

        $args = array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . trim($settings['api_token']),
                'User-Agent' => 'WordPress/7K-Coupons-Importer',
                'Accept' => 'application/json'
            )
        );

        $this->logger->log('debug', sprintf('Awin API Request: %s', $url));

        $response = wp_remote_get($url, $args);
        $response_time = (microtime(true) - $start_time) * 1000;

        if (is_wp_error($response)) {
            $error_msg = $response->get_error_message();
            $this->logger->log_api_request('awin', $endpoint, 0, $response_time, $error_msg);
            $this->logger->log('error', sprintf('Awin request error: %s', $error_msg));
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        $this->logger->log_api_request('awin', $endpoint, $response_code, $response_time);

        if ($response_code !== 200) {
            $error_message = sprintf('API returned status code: %d. Response: %s', $response_code, substr($body, 0, 500));
            $this->logger->log('error', 'Awin API Error: ' . $error_message);
            return new \WP_Error('api_error', $error_message);
        }

        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->log('error', 'Awin JSON decode error: ' . json_last_error_msg());
            return new \WP_Error('json_error', 'Invalid JSON response: ' . json_last_error_msg());
        }

        return $data;
    }

    private function make_api_post_request($endpoint, $settings, $body = array()) {
        $start_time = microtime(true);

        $url = $this->api_base_url . $endpoint;

        $args = array(
            'timeout' => 30,
            'method' => 'POST',
            'headers' => array(
                'Authorization' => 'Bearer ' . trim($settings['api_token']),
                'User-Agent' => 'WordPress/7K-Coupons-Importer',
                'Accept' => 'application/json',
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($body)
        );

        $this->logger->log('debug', sprintf('Awin API POST Request: %s | Body: %s', $url, json_encode($body)));

        $response = wp_remote_post($url, $args);
        $response_time = (microtime(true) - $start_time) * 1000;

        if (is_wp_error($response)) {
            $error_msg = $response->get_error_message();
            $this->logger->log_api_request('awin', $endpoint, 0, $response_time, $error_msg);
            $this->logger->log('error', sprintf('Awin request error: %s', $error_msg));
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $body_response = wp_remote_retrieve_body($response);

        // Log completo da resposta para debug
        $this->logger->log('debug', sprintf('Awin API Response Code: %d', $response_code));
        $this->logger->log('debug', sprintf('Awin API Response Body (first 2000 chars): %s', substr($body_response, 0, 2000)));

        $this->logger->log_api_request('awin', $endpoint, $response_code, $response_time);

        if ($response_code !== 200) {
            $error_message = sprintf('API returned status code: %d. Response: %s', $response_code, substr($body_response, 0, 500));
            $this->logger->log('error', 'Awin API Error: ' . $error_message);
            return new \WP_Error('api_error', $error_message);
        }

        $data = json_decode($body_response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->log('error', 'Awin JSON decode error: ' . json_last_error_msg());
            return new \WP_Error('json_error', 'Invalid JSON response: ' . json_last_error_msg());
        }

        // Log da estrutura decodificada
        $this->logger->log('debug', 'Awin API Response decoded keys: ' . implode(', ', array_keys($data)));
        if (isset($data['promotions'])) {
            $this->logger->log('debug', sprintf('Awin API: Found %d promotions in response', count($data['promotions'])));
        }

        return $data;
    }

    private function parse_coupons($api_coupons) {
        $parsed_coupons = array();

        if (!is_array($api_coupons)) {
            $this->logger->log('error', 'Awin parse_coupons: Expected array, got ' . gettype($api_coupons));
            return $parsed_coupons;
        }

        $this->logger->log('debug', sprintf('Awin: Parsing %d coupons', count($api_coupons)));

        $skipped_count = 0;

        foreach ($api_coupons as $index => $api_coupon) {
            if (!is_array($api_coupon)) {
                $this->logger->log('warning', sprintf('Awin: Coupon at index %d is not an array', $index));
                continue;
            }

            // Gerar external_id antes de parsear
            $external_id = $this->generate_external_id($api_coupon);

            // Verificar se o cupom já foi importado (existe em imported_coupon)
            if ($this->coupon_already_imported($external_id)) {
                $skipped_count++;
                $this->logger->log('debug', sprintf('Awin: ⏭️ Cupom "%s" (ID: %s) já importado, pulando...', 
                    isset($api_coupon['title']) ? substr($api_coupon['title'], 0, 50) : 'N/A',
                    $external_id
                ));
                continue;
            }

            $parsed_coupon = $this->parse_single_coupon($api_coupon);
            if ($parsed_coupon) {
                $parsed_coupons[] = $parsed_coupon;
            } else {
                $this->logger->log('debug', sprintf('Awin: Coupon at index %d failed validation (title: %s)', 
                    $index, 
                    isset($api_coupon['title']) ? substr($api_coupon['title'], 0, 50) : 'N/A'
                ));
            }
        }

        $this->logger->log('info', sprintf('Awin: Successfully parsed %d out of %d coupons (%d skipped - already imported)', 
            count($parsed_coupons), 
            count($api_coupons),
            $skipped_count
        ));

        return $parsed_coupons;
    }

    /**
     * Verifica se um cupom já foi importado
     */
    private function coupon_already_imported($external_id) {
        global $wpdb;

        // Verificar em imported_coupon
        $imported_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE p.post_type = 'imported_coupon'
            AND pm.meta_key = '_ci7k_external_id'
            AND pm.meta_value = %s",
            $external_id
        ));

        if ($imported_exists > 0) {
            return true;
        }

        // Verificar em coupon (publicado)
        $published_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE p.post_type = 'coupon'
            AND pm.meta_key = '_ci7k_external_id'
            AND pm.meta_value = %s",
            $external_id
        ));

        return $published_exists > 0;
    }

    private function parse_single_coupon($api_coupon) {
        $coupon = array();

        // Log para debug
        if (!isset($api_coupon['title']) || empty($api_coupon['title'])) {
            $this->logger->log('debug', 'Awin: Cupom sem título. Keys: ' . implode(', ', array_keys($api_coupon)));
        }

        $coupon['title'] = isset($api_coupon['title']) ? sanitize_text_field($api_coupon['title']) : '';
        $coupon['description'] = $this->build_description($api_coupon);
        
        // Use urlTracking if available, otherwise use url
        $coupon['link'] = isset($api_coupon['urlTracking']) ? esc_url_raw($api_coupon['urlTracking']) : 
                         (isset($api_coupon['url']) ? esc_url_raw($api_coupon['url']) : '');

        // Extract voucher code from voucher object
        $coupon['code'] = '';
        if (isset($api_coupon['voucher']['code'])) {
            $coupon['code'] = sanitize_text_field($api_coupon['voucher']['code']);
        }

        $coupon['advertiser'] = '';
        $coupon['advertiser_id'] = '';
        if (isset($api_coupon['advertiser']['name'])) {
            $coupon['advertiser'] = sanitize_text_field($api_coupon['advertiser']['name']);
        }
        if (isset($api_coupon['advertiser']['id'])) {
            $coupon['advertiser_id'] = intval($api_coupon['advertiser']['id']);
        }

        $coupon['start_date'] = '';
        if (isset($api_coupon['startDate'])) {
            $coupon['start_date'] = coupon_importer_parse_date($api_coupon['startDate']);
        }
        $coupon['expiration'] = '';
        if (isset($api_coupon['endDate'])) {
            $coupon['expiration'] = coupon_importer_parse_date($api_coupon['endDate']);
        }

        $coupon['discount'] = $this->extract_discount($api_coupon);

        $coupon['promotion_type'] = isset($api_coupon['type']) ? sanitize_text_field($api_coupon['type']) : '';

        $coupon['tags'] = array();
        $coupon['category'] = array();

        // Buscar categoria do anunciante via API - SEMPRE buscar para todos os anunciantes
        if (!empty($coupon['advertiser_id'])) {
            $settings = ci7k_get_provider_settings('awin');
            
            if (!empty($settings) && !empty($settings['api_token']) && !empty($settings['publisher_id'])) {
                $advertiser_category = $this->get_advertiser_category($coupon['advertiser_id'], $settings);
                
                if ($advertiser_category) {
                    $coupon['awin_category'] = $advertiser_category;
                    // Adicionar categoria ao array de categorias
                    $coupon['category'][] = $advertiser_category;
                    $this->logger->log('info', sprintf('Awin: ✅ Categoria "%s" ADICIONADA ao cupom "%s" (Anunciante: %s - ID: %d)', 
                        $advertiser_category, 
                        substr($coupon['title'], 0, 50),
                        $coupon['advertiser'],
                        $coupon['advertiser_id']));
                } else {
                    $this->logger->log('warning', sprintf('Awin: ❌ Nenhuma categoria encontrada para anunciante "%s" (ID: %d) - Cupom: %s', 
                        $coupon['advertiser'],
                        $coupon['advertiser_id'],
                        substr($coupon['title'], 0, 50)));
                }
            } else {
                $this->logger->log('error', 'Awin: Configurações de API não encontradas ou incompletas para buscar categorias');
            }
        } else {
            $this->logger->log('warning', sprintf('Awin: Cupom "%s" sem advertiser_id', substr($coupon['title'], 0, 50)));
        }

        // Add regions as tags (not categories)
        if (isset($api_coupon['regions']['list']) && is_array($api_coupon['regions']['list'])) {
            foreach ($api_coupon['regions']['list'] as $region) {
                if (isset($region['name'])) {
                    $region_name = sanitize_text_field($region['name']);
                    $coupon['tags'][] = $region_name;
                }
            }
        }

        $coupon['coupon_type'] = $this->determine_coupon_type($api_coupon);

        // Log para debug do tipo do cupom
        $this->logger->log('debug', sprintf('Awin: Cupom "%s" - Type: %s, Has Code: %s, Coupon Type: %d',
            substr($coupon['title'], 0, 50),
            isset($api_coupon['type']) ? $api_coupon['type'] : 'N/A',
            !empty($coupon['code']) ? 'Yes' : 'No',
            $coupon['coupon_type']
        ));

        // Check if voucher is exclusive
        $coupon['is_exclusive'] = 0;
        if (isset($api_coupon['voucher']['exclusive']) && $api_coupon['voucher']['exclusive']) {
            $coupon['is_exclusive'] = 1;
        }
        
        $coupon['deeplink'] = $coupon['link'];

        // Use promotionId as external_id
        $coupon['external_id'] = $this->generate_external_id($api_coupon);

        if ($coupon['is_exclusive'] && !in_array('exclusive', $coupon['tags'])) {
            $coupon['tags'][] = 'exclusive';
        }

        if (!empty($coupon['code'])) {
            $coupon['tags'][] = 'cupom';
        } else {
            $coupon['tags'][] = 'oferta';
        }

        // Validação: título e link são obrigatórios
        if (empty($coupon['title'])) {
            $this->logger->log('warning', 'Awin: Cupom rejeitado - título vazio. ID: ' . ($api_coupon['promotionId'] ?? 'N/A'));
            return null;
        }
        
        if (empty($coupon['link'])) {
            $this->logger->log('warning', 'Awin: Cupom rejeitado - link vazio. Título: ' . substr($coupon['title'], 0, 50));
            return null;
        }

        // Log final do cupom parseado
        $this->logger->log('debug', sprintf('Awin: Cupom parseado - Título: "%s" | Categorias: [%s] | Anunciante: %s (%d)', 
            substr($coupon['title'], 0, 50),
            implode(', ', $coupon['category']),
            $coupon['advertiser'],
            $coupon['advertiser_id']
        ));

        return $coupon;
    }

    private function generate_external_id($api_coupon) {
        // Use promotionId as the primary identifier
        if (isset($api_coupon['promotionId'])) {
            return 'awin_' . $api_coupon['promotionId'];
        }

        if (isset($api_coupon['id'])) {
            return 'awin_' . $api_coupon['id'];
        }

        $advertiser_id = isset($api_coupon['advertiser']['id']) ? $api_coupon['advertiser']['id'] : '';
        $title = isset($api_coupon['title']) ? $api_coupon['title'] : '';

        if (!empty($advertiser_id) && !empty($title)) {
            return 'awin_' . $advertiser_id . '_' . substr(md5($title), 0, 8);
        }

        return 'awin_' . md5(serialize($api_coupon));
    }

    private function build_description($api_coupon) {
        $description_parts = array();

        if (isset($api_coupon['description']) && !empty($api_coupon['description'])) {
            $description_parts[] = sanitize_textarea_field($api_coupon['description']);
        }

        if (isset($api_coupon['terms']) && !empty($api_coupon['terms']) && $api_coupon['terms'] !== '...') {
            $description_parts[] = "Termos: " . sanitize_textarea_field($api_coupon['terms']);
        }

        if (isset($api_coupon['restrictions']) && !empty($api_coupon['restrictions'])) {
            $description_parts[] = "Restrições: " . sanitize_textarea_field($api_coupon['restrictions']);
        }

        return !empty($description_parts) ? implode("\n\n", $description_parts) : '';
    }

    private function extract_discount($api_coupon) {
        if (!empty($api_coupon['discount'])) {
            return $api_coupon['discount'];
        }

        if (!empty($api_coupon['discountAmount'])) {
            return $api_coupon['discountAmount'];
        }

        if (!empty($api_coupon['discountPercentage'])) {
            return $api_coupon['discountPercentage'] . '% OFF';
        }

        $text = ($api_coupon['title'] ?? '') . ' ' . ($api_coupon['description'] ?? '');

        if (preg_match('/(\d+)%\s*(off|desconto|discount)/i', $text, $matches)) {
            return $matches[1] . '% OFF';
        }

        if (preg_match('/£(\d+(?:\.\d{2})?)\s*(off|desconto|discount)/i', $text, $matches)) {
            return '£' . $matches[1] . ' OFF';
        }

        if (preg_match('/\$(\d+(?:\.\d{2})?)\s*(off|desconto|discount)/i', $text, $matches)) {
            return '$' . $matches[1] . ' OFF';
        }

        return '';
    }

    private function determine_coupon_type($api_coupon) {
        // Verificar se tem código de voucher
        if (isset($api_coupon['voucher']['code']) && !empty($api_coupon['voucher']['code'])) {
            return 1; // Cupom com código
        }

        // Verificar pelo tipo da promoção
        if (isset($api_coupon['type']) && $api_coupon['type'] === 'voucher') {
            return 1; // É voucher/cupom
        }

        return 3; // Oferta/deal
    }

    private function is_exclusive_offer($api_coupon) {
        if (isset($api_coupon['exclusive']) && $api_coupon['exclusive']) {
            return 1;
        }

        $exclusive_indicators = array('exclusive', 'exclusivo', 'especial', 'vip', 'limited');

        $text_to_check = strtolower(
            ($api_coupon['title'] ?? '') . ' ' .
            ($api_coupon['description'] ?? '') . ' ' .
            ($api_coupon['type'] ?? '')
        );

        foreach ($exclusive_indicators as $indicator) {
            if (strpos($text_to_check, $indicator) !== false) {
                return 1;
            }
        }

        return 0;
    }

    public function get_advertisers($settings) {
        $endpoint = '/publishers/' . $settings['publisher_id'] . '/programmes';
        $params = array(
            'relationship' => 'joined'
        );

        $response = $this->make_api_request($endpoint, $settings, $params);

        if (is_wp_error($response)) {
            $this->logger->log('error', 'Awin get_advertisers error: ' . $response->get_error_message());
            return array();
        }

        $programmes = isset($response['programmes']) ? $response['programmes'] : (is_array($response) ? $response : array());

        if (!is_array($programmes)) {
            return array();
        }

        $advertisers = array();
        foreach ($programmes as $programme) {
            if (isset($programme['advertiserId']) && isset($programme['advertiserName'])) {
                $advertisers[] = array(
                    'id' => $programme['advertiserId'],
                    'name' => $programme['advertiserName']
                );
            }
        }

        return $advertisers;
    }

    public function get_categories($settings) {
        return array();
    }

    public function get_commission_groups($settings) {
        $endpoint = '/' . $settings['publisher_id'] . '/commissiongroups';
        $response = $this->make_api_request($endpoint, $settings);

        if (is_wp_error($response)) {
            return array();
        }

        return isset($response['commissionGroups']) ? $response['commissionGroups'] : array();
    }

    /**
     * Busca a categoria do anunciante via API
     */
    private function get_advertiser_category($advertiser_id, $settings) {
        if (empty($advertiser_id)) {
            $this->logger->log('warning', 'Awin: get_advertiser_category chamado sem advertiser_id');
            return null;
        }

        // Verificar cache local
        $cache_key = 'awin_advertiser_' . $advertiser_id;
        $cached_category = get_transient($cache_key);
        
        if ($cached_category !== false) {
            $this->logger->log('debug', sprintf('Awin: 🔄 Categoria do anunciante %d obtida do CACHE: %s', 
                $advertiser_id, $cached_category));
            return $cached_category;
        }

        $this->logger->log('debug', sprintf('Awin: 🌐 Buscando categoria do anunciante %d via API...', $advertiser_id));

        // Buscar via API
        $publisher_id = isset($settings['publisher_id']) ? $settings['publisher_id'] : '';
        if (empty($publisher_id)) {
            $this->logger->log('error', 'Awin: Publisher ID não configurado para buscar categoria');
            return null;
        }

        $url = sprintf(
            'https://api.awin.com/publishers/%s/programmedetails?advertiserId=%s',
            $publisher_id,
            $advertiser_id
        );

        $this->logger->log('debug', sprintf('Awin: Fazendo requisição para: %s', $url));

        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $settings['api_token']
            ),
            'timeout' => 15
        ));

        if (is_wp_error($response)) {
            $this->logger->log('error', sprintf('Awin: Erro ao buscar categoria do anunciante %d: %s', 
                $advertiser_id, $response->get_error_message()));
            return null;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            $body = wp_remote_retrieve_body($response);
            $this->logger->log('error', sprintf('Awin: API retornou código %d ao buscar anunciante %d. Response: %s', 
                $response_code, $advertiser_id, substr($body, 0, 500)));
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->log('error', sprintf('Awin: Erro ao decodificar JSON do anunciante %d: %s. Body: %s', 
                $advertiser_id, json_last_error_msg(), substr($body, 0, 500)));
            return null;
        }

        // Log da estrutura completa da resposta para debug
        $this->logger->log('debug', sprintf('Awin: Resposta completa do anunciante %d: %s', 
            $advertiser_id, json_encode($data)));

        // Tentar diferentes estruturas de resposta
        $primary_sector = null;
        
        // Caso 1: primarySector dentro de programmeInfo (AWIN API v2)
        if (isset($data['programmeInfo']['primarySector']) && !empty($data['programmeInfo']['primarySector'])) {
            $primary_sector = $data['programmeInfo']['primarySector'];
            $this->logger->log('debug', sprintf('Awin: ✅ Categoria encontrada em $data[programmeInfo][primarySector] para anunciante %d: %s', 
                $advertiser_id, $primary_sector));
        }
        // Caso 2: primarySector direto no root
        elseif (isset($data['primarySector']) && !empty($data['primarySector'])) {
            $primary_sector = $data['primarySector'];
            $this->logger->log('debug', sprintf('Awin: ✅ Categoria encontrada em $data[primarySector] para anunciante %d: %s', 
                $advertiser_id, $primary_sector));
        }
        // Caso 3: primarySector dentro de um array no índice 0
        elseif (isset($data[0]['primarySector']) && !empty($data[0]['primarySector'])) {
            $primary_sector = $data[0]['primarySector'];
            $this->logger->log('debug', sprintf('Awin: ✅ Categoria encontrada em $data[0][primarySector] para anunciante %d: %s', 
                $advertiser_id, $primary_sector));
        }
        // Caso 4: primarySector dentro de 'programme'
        elseif (isset($data['programme']['primarySector']) && !empty($data['programme']['primarySector'])) {
            $primary_sector = $data['programme']['primarySector'];
            $this->logger->log('debug', sprintf('Awin: ✅ Categoria encontrada em $data[programme][primarySector] para anunciante %d: %s', 
                $advertiser_id, $primary_sector));
        }
        // Caso 5: Verificar se há um campo 'sector' ou 'category'
        elseif (isset($data['sector']) && !empty($data['sector'])) {
            $primary_sector = $data['sector'];
            $this->logger->log('debug', sprintf('Awin: ✅ Categoria encontrada em $data[sector] para anunciante %d: %s', 
                $advertiser_id, $primary_sector));
        }
        elseif (isset($data['category']) && !empty($data['category'])) {
            $primary_sector = $data['category'];
            $this->logger->log('debug', sprintf('Awin: ✅ Categoria encontrada em $data[category] para anunciante %d: %s', 
                $advertiser_id, $primary_sector));
        }
        // Caso 6: Verificar dentro de primarySectors (plural)
        elseif (isset($data['primarySectors']) && is_array($data['primarySectors']) && !empty($data['primarySectors'][0])) {
            $primary_sector = $data['primarySectors'][0];
            $this->logger->log('debug', sprintf('Awin: ✅ Categoria encontrada em $data[primarySectors][0] para anunciante %d: %s', 
                $advertiser_id, $primary_sector));
        }
        // Caso 7: Verificar dentro de 'verticals' (algumas APIs usam este campo)
        elseif (isset($data['verticals']) && is_array($data['verticals']) && !empty($data['verticals'][0])) {
            $primary_sector = is_array($data['verticals'][0]) && isset($data['verticals'][0]['name']) 
                ? $data['verticals'][0]['name'] 
                : $data['verticals'][0];
            $this->logger->log('debug', sprintf('Awin: ✅ Categoria encontrada em $data[verticals][0] para anunciante %d: %s', 
                $advertiser_id, $primary_sector));
        }

        if (empty($primary_sector)) {
            // Log detalhado quando não encontrar
            $available_keys = is_array($data) ? implode(', ', array_keys($data)) : 'N/A';
            
            // Log da estrutura completa apenas quando não encontrar
            $this->logger->log('warning', sprintf('Awin: ❌ Campo primarySector não encontrado para anunciante %d. Keys disponíveis: %s. Response completa: %s', 
                $advertiser_id, 
                $available_keys,
                json_encode($data)
            ));
            
            // Cachear resultado vazio por 1 hora para evitar requisições repetidas
            set_transient($cache_key, '', HOUR_IN_SECONDS);
            return null;
        }

        // Sanitizar a categoria
        $primary_sector = sanitize_text_field($primary_sector);

        $this->logger->log('info', sprintf('Awin: ✅ Categoria "%s" encontrada e cacheada para anunciante %d', 
            $primary_sector, $advertiser_id));
        
        // Cachear por 7 dias
        set_transient($cache_key, $primary_sector, 7 * DAY_IN_SECONDS);

        return $primary_sector;
    }

    private function map_promotion_type($type) {
        $type_map = array(
            'voucher' => 'Cupom',
            'deal' => 'Oferta',
            'promotion' => 'Promoção'
        );
        
        return isset($type_map[$type]) ? $type_map[$type] : 'Oferta';
    }
}