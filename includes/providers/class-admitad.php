<?php
/**
 * Admitad Provider Class
 * Implements Admitad API for coupon/offer importing
 */

namespace CouponImporter\Providers;

if (!defined('ABSPATH')) {
    exit;
}

class Admitad {

    private $api_base_url = 'https://api.admitad.com';
    private $logger;

    public function __construct() {
        $this->logger = new \CouponImporter\Logger();
    }

    public function get_name() {
        return 'admitad';
    }

    public function get_label() {
        return 'Admitad';
    }

    public function get_settings_fields() {
        return array(
            'client_id' => array(
                'label' => __('Client ID', '7k-coupons-importer'),
                'type' => 'text',
                'required' => true,
                'description' => __('Seu Client ID da Admitad', '7k-coupons-importer')
            ),
            'client_secret' => array(
                'label' => __('Client Secret', '7k-coupons-importer'),
                'type' => 'password',
                'required' => true,
                'description' => __('Seu Client Secret da Admitad', '7k-coupons-importer')
            ),
            'website_id' => array(
                'label' => __('Website ID', '7k-coupons-importer'),
                'type' => 'text',
                'required' => false,
                'description' => __('ID do seu website/ad space no Admitad (opcional, mas recomendado para links de afiliado)', '7k-coupons-importer')
            ),
            'import_limit' => array(
                'label' => __('Limite de Importação', '7k-coupons-importer'),
                'type' => 'number',
                'default' => 50,
                'description' => __('Número máximo de cupons por importação (Recomendado: 50-200)', '7k-coupons-importer')
            ),
            'auth_method' => array(
                'label' => __('Método de Autenticação', '7k-coupons-importer'),
                'type' => 'select',
                'required' => false,
                'options' => array(
                    'oauth2' => 'OAuth 2.0',
                    'basic' => 'Basic Auth'
                ),
                'default' => 'oauth2',
                'description' => __('Método de autenticação (recomendado: OAuth 2.0)', '7k-coupons-importer')
            )
        );
    }

    public function validate_settings($settings) {
        // Validar todos os campos obrigatórios
        if (empty($settings['client_id'])) {
            return false;
        }

        if (empty($settings['client_secret'])) {
            return false;
        }

        // Website ID é opcional mas recomendado
        return true;
    }

    public function test_connection($settings) {
        $this->logger->log('info', 'Admitad: Testando conexão...');

        $endpoint = '/coupons/';
        $params = array('limit' => 1);

        $response = $this->make_api_request($endpoint, $settings, $params);

        if (is_wp_error($response)) {
            $this->logger->log('error', 'Admitad test connection failed: ' . $response->get_error_message());
            return $response;
        }

        $this->logger->log('info', 'Admitad: Conexão estabelecida com sucesso!');

        if (empty($settings['website_id'])) {
            $this->logger->log('warning', 'Admitad: Website ID não configurado. Configure para gerar deeplinks automaticamente.');
        }

        return true;
    }

    private function get_oauth_token($settings) {
        $transient_key = 'admitad_oauth_token_' . md5($settings['client_id']);
        $cached_token = get_transient($transient_key);

        if ($cached_token) {
            $this->logger->log('debug', 'Admitad: Using cached OAuth token');
            return $cached_token;
        }

        $token_url = 'https://api.admitad.com/token/';

        $args = array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($settings['client_id'] . ':' . $settings['client_secret']),
                'Content-Type' => 'application/x-www-form-urlencoded'
            ),
            'body' => http_build_query(array(
                'grant_type' => 'client_credentials',
                'client_id' => $settings['client_id'],
                'scope' => 'public_data coupons coupons_for_website websites deeplink_generator'
            ))
        );

        $this->logger->log('debug', 'Admitad OAuth: Requesting token with scopes: public_data coupons coupons_for_website websites deeplink_generator');

        $response = wp_remote_post($token_url, $args);

        if (is_wp_error($response)) {
            $this->logger->log('error', 'Admitad OAuth error: ' . $response->get_error_message());
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($response_code !== 200) {
            $this->logger->log('error', sprintf('Admitad OAuth failed with code %d: %s', $response_code, $body));
            return false;
        }

        $token_data = json_decode($body, true);

        if (!isset($token_data['access_token'])) {
            $this->logger->log('error', 'Admitad OAuth: No access_token in response: ' . $body);
            return false;
        }

        $expires_in = isset($token_data['expires_in']) ? intval($token_data['expires_in']) : 3600;
        // Cachear com margem de segurança de 60 segundos
        set_transient($transient_key, $token_data['access_token'], $expires_in - 60);

        $this->logger->log('info', sprintf('Admitad OAuth token obtained successfully (expires in %d seconds)', $expires_in));

        return $token_data['access_token'];
    }

    private function make_api_request($endpoint, $settings, $params = array()) {
        $start_time = microtime(true);

        $url = $this->api_base_url . $endpoint;

        if (!empty($params)) {
            $url = add_query_arg($params, $url);
        }

        $auth_method = isset($settings['auth_method']) ? $settings['auth_method'] : 'oauth2';

        $headers = array(
            'User-Agent' => 'WordPress/7K-Coupons-Importer',
            'Accept' => 'application/json'
        );

        if ($auth_method === 'oauth2') {
            $access_token = $this->get_oauth_token($settings);
            if (!$access_token) {
                return new \WP_Error('auth_error', 'Failed to obtain OAuth token');
            }
            $headers['Authorization'] = 'Bearer ' . $access_token;
        } else {
            $auth_string = base64_encode($settings['client_id'] . ':' . $settings['client_secret']);
            $headers['Authorization'] = 'Basic ' . $auth_string;
        }

        $args = array(
            'timeout' => 30,
            'headers' => $headers
        );

        $this->logger->log('debug', sprintf('Admitad API Request: %s', $url));

        $response = wp_remote_get($url, $args);
        $response_time = (microtime(true) - $start_time) * 1000;

        if (is_wp_error($response)) {
            $error_msg = $response->get_error_message();
            $this->logger->log_api_request('admitad', $endpoint, 0, $response_time, $error_msg);
            $this->logger->log('error', sprintf('Admitad request error: %s', $error_msg));
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        $this->logger->log_api_request('admitad', $endpoint, $response_code, $response_time);

        // Detectar token expirado (401 ou 403 com insufficient_scope) e renovar automaticamente
        if (($response_code === 401 || $response_code === 403) && $auth_method === 'oauth2') {
            $error_data = json_decode($body, true);
            
            // Verificar se é erro de token expirado ou scope insuficiente
            if (isset($error_data['error']) && 
                ($error_data['error'] === 'invalid_token' || 
                 $error_data['error'] === 'insufficient_scope' ||
                 $response_code === 401)) {
                
                $this->logger->log('warning', 'Admitad token expirado ou inválido, renovando...');
                
                // Apagar token expirado
                $transient_key = 'admitad_oauth_token_' . md5($settings['client_id']);
                delete_transient($transient_key);
                
                // Tentar novamente com novo token
                $access_token = $this->get_oauth_token($settings);
                if ($access_token) {
                    $args['headers']['Authorization'] = 'Bearer ' . $access_token;
                    
                    $this->logger->log('info', 'Admitad: Refazendo requisição com novo token...');
                    $response = wp_remote_get($url, $args);
                    $response_code = wp_remote_retrieve_response_code($response);
                    $body = wp_remote_retrieve_body($response);
                    
                    $this->logger->log('info', sprintf('Admitad: Token renovado - Nova resposta: %d', $response_code));
                }
            }
        }

        if ($response_code !== 200) {
            $error_message = sprintf('API returned status code: %d. Response: %s', $response_code, substr($body, 0, 500));
            $this->logger->log('error', 'Admitad API Error: ' . $error_message);
            return new \WP_Error('api_error', $error_message);
        }

        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->log('error', 'Admitad JSON decode error: ' . json_last_error_msg());
            return new \WP_Error('json_error', 'Invalid JSON response: ' . json_last_error_msg());
        }

        // Log detalhado da resposta
        $results_count = isset($data['results']) ? count($data['results']) : (is_array($data) ? count($data) : 0);
        $this->logger->log('debug', sprintf('Admitad API Response: %d items, Response time: %.2fms', 
            $results_count, 
            $response_time
        ));

        return $data;
    }

    /**
     * Gera deeplink de afiliado usando a API do Admitad
     */
    private function generate_deeplink($settings, $base_link, $campaign_id) {
        if (empty($settings['website_id'])) {
            $this->logger->log('debug', 'Admitad: Website ID não configurado, não é possível gerar deeplink');
            return null;
        }

        if (empty($base_link)) {
            $this->logger->log('debug', 'Admitad: Link base vazio, não é possível gerar deeplink');
            return null;
        }

        $endpoint = '/deeplink/';
        $params = array(
            'website' => $settings['website_id'],
            'advcampaign' => $campaign_id,
            'ulink' => $base_link
        );

        $this->logger->log('debug', sprintf('Admitad: Gerando deeplink para campanha %s com link %s', 
            $campaign_id, substr($base_link, 0, 50)));

        $response = $this->make_api_request($endpoint, $settings, $params);

        if (is_wp_error($response)) {
            $this->logger->log('warning', sprintf('Admitad: Erro ao gerar deeplink: %s', $response->get_error_message()));
            return null;
        }

        if (isset($response['deeplink'])) {
            $this->logger->log('info', sprintf('Admitad: Deeplink gerado com sucesso: %s', substr($response['deeplink'], 0, 50)));
            return esc_url_raw($response['deeplink']);
        }

        $this->logger->log('warning', 'Admitad: Resposta da API de deeplink não contém campo "deeplink"');
        return null;
    }

    private function parse_single_coupon($api_coupon, $settings = array()) {
        $coupon = array();

        // Log detalhado do cupom sendo processado
        $this->logger->log('debug', sprintf('Admitad: Processando cupom - ID: %s, Name: %s, Campaign: %s',
            $api_coupon['id'] ?? 'N/A',
            isset($api_coupon['name']) ? substr($api_coupon['name'], 0, 50) : 'N/A',
            isset($api_coupon['campaign']['name']) ? $api_coupon['campaign']['name'] : 'N/A'
        ));

        $coupon['title'] = isset($api_coupon['name']) ? sanitize_text_field($api_coupon['name']) : '';

        $description = '';
        if (isset($api_coupon['description'])) {
            $description = sanitize_textarea_field($api_coupon['description']);
        }
        if (isset($api_coupon['short_name'])) {
            $short_name = sanitize_text_field($api_coupon['short_name']);
            if (!empty($short_name) && $short_name !== $coupon['title']) {
                $description = $short_name . (!empty($description) ? "\n\n" . $description : '');
            }
        }
        $coupon['description'] = $description;

        // Prioridade de links: goto_link > frameset_link > link > gerar deeplink
        $coupon['link'] = '';
        
        // 1. Tentar goto_link (link direto de afiliado)
        if (isset($api_coupon['goto_link']) && !empty($api_coupon['goto_link'])) {
            $coupon['link'] = esc_url_raw($api_coupon['goto_link']);
            $this->logger->log('debug', 'Admitad: Usando goto_link');
        }
        // 2. Tentar frameset_link (alternativo com header)
        elseif (isset($api_coupon['frameset_link']) && !empty($api_coupon['frameset_link'])) {
            $coupon['link'] = esc_url_raw($api_coupon['frameset_link']);
            $this->logger->log('debug', 'Admitad: Usando frameset_link');
        }
        // 3. Tentar link direto
        elseif (isset($api_coupon['link']) && !empty($api_coupon['link'])) {
            $coupon['link'] = esc_url_raw($api_coupon['link']);
            $this->logger->log('debug', 'Admitad: Usando link direto');
        }

        // 4. Se ainda não tem link e temos website_id + default_link da campanha, gerar deeplink
        if (empty($coupon['link']) && 
            !empty($settings['website_id']) && 
            isset($api_coupon['campaign']['default_link']) && 
            !empty($api_coupon['campaign']['default_link']) &&
            isset($api_coupon['campaign']['id'])) {
            
            $this->logger->log('info', 'Admitad: Nenhum link disponível, tentando gerar deeplink...');
            $generated_link = $this->generate_deeplink(
                $settings, 
                $api_coupon['campaign']['default_link'], 
                $api_coupon['campaign']['id']
            );
            
            if ($generated_link) {
                $coupon['link'] = $generated_link;
                $this->logger->log('info', 'Admitad: Deeplink gerado com sucesso!');
            } else {
                $this->logger->log('warning', 'Admitad: Falha ao gerar deeplink');
            }
        }

        $this->logger->log('debug', sprintf('Admitad: Link final: %s', 
            !empty($coupon['link']) ? substr($coupon['link'], 0, 50) : 'VAZIO'));

        $coupon['code'] = '';
        if (isset($api_coupon['promocode'])) {
            $coupon['code'] = sanitize_text_field($api_coupon['promocode']);
            $this->logger->log('debug', sprintf('Admitad: Código encontrado: %s', $coupon['code']));
        }

        $coupon['advertiser'] = '';
        $coupon['advertiser_id'] = '';
        if (isset($api_coupon['campaign']['name'])) {
            $coupon['advertiser'] = sanitize_text_field($api_coupon['campaign']['name']);
        }
        if (isset($api_coupon['campaign']['id'])) {
            $coupon['advertiser_id'] = intval($api_coupon['campaign']['id']);
        }

        $coupon['start_date'] = '';
        if (isset($api_coupon['date_start'])) {
            $coupon['start_date'] = coupon_importer_parse_date($api_coupon['date_start']);
        }
        $coupon['expiration'] = '';
        if (isset($api_coupon['date_end'])) {
            $coupon['expiration'] = coupon_importer_parse_date($api_coupon['date_end']);
        }

        $coupon['discount'] = $this->extract_discount($api_coupon);

        $coupon['tags'] = array();
        $coupon['category'] = array();

        if (isset($api_coupon['categories']) && is_array($api_coupon['categories'])) {
            foreach ($api_coupon['categories'] as $category) {
                if (isset($category['name'])) {
                    $coupon['category'][] = sanitize_text_field($category['name']);
                }
            }
        }

        if (isset($api_coupon['regions']) && is_array($api_coupon['regions'])) {
            foreach ($api_coupon['regions'] as $region) {
                if (is_string($region)) {
                    $coupon['tags'][] = $region;
                }
            }
        }

        $coupon['coupon_type'] = $this->determine_coupon_type($api_coupon);

        $coupon['is_exclusive'] = isset($api_coupon['exclusive']) && $api_coupon['exclusive'] ? 1 : 0;

        $coupon['deeplink'] = $coupon['link'];

        $coupon['external_id'] = $this->generate_external_id($api_coupon);

        if ($coupon['is_exclusive']) {
            $coupon['tags'][] = 'exclusive';
        }

        if (!empty($coupon['code'])) {
            $coupon['tags'][] = 'cupom';
        } else {
            $coupon['tags'][] = 'oferta';
        }

        if (empty($coupon['title'])) {
            $this->logger->log('warning', 'Admitad: Cupom rejeitado - título vazio. ID: ' . ($api_coupon['id'] ?? 'N/A'));
            return null;
        }

        if (empty($coupon['link'])) {
            $this->logger->log('warning', sprintf('Admitad: Cupom rejeitado - link vazio após todas as tentativas. Título: %s', 
                substr($coupon['title'], 0, 50)
            ));
            return null;
        }

        $this->logger->log('debug', sprintf('Admitad: ✅ Cupom parseado com sucesso - Título: %s, Código: %s, Link: %s',
            substr($coupon['title'], 0, 50),
            $coupon['code'] ?: 'N/A',
            substr($coupon['link'], 0, 50)
        ));

        return $coupon;
    }

    public function parse_coupons($coupons_data, $settings = array()) {
        if (!is_array($coupons_data)) {
            return array();
        }

        $this->logger->log('debug', sprintf('Admitad: Parsing %d coupons', count($coupons_data)));

        $parsed = array();
        $skipped = 0;

        foreach ($coupons_data as $api_coupon) {
            $external_id = $this->generate_external_id($api_coupon);

            if ($this->coupon_already_imported($external_id)) {
                $coupon_title = isset($api_coupon['name']) ? $api_coupon['name'] : 'N/A';
                $this->logger->log('debug', sprintf('Admitad: Cupom "%s" (ID: %s) já importado, pulando...', 
                    substr($coupon_title, 0, 50), 
                    $external_id
                ));
                $skipped++;
                continue;
            }

            $coupon = $this->parse_single_coupon($api_coupon, $settings);

            if ($coupon) {
                $parsed[] = $coupon;
            }
        }

        $this->logger->log('info', sprintf('Admitad: Successfully parsed %d out of %d coupons (%d skipped - already imported)', 
            count($parsed), 
            count($coupons_data),
            $skipped
        ));

        return $parsed;
    }

    public function get_coupons($settings, $limit = 100) {
        // Usar limite das configurações se não for passado
        if ($limit === 100 && isset($settings['import_limit'])) {
            $limit = intval($settings['import_limit']);
        }

        $this->logger->log('info', sprintf('Admitad: Iniciando busca de cupons, limite: %d', $limit));

        $params = array(
            'limit' => min($limit, 500),
            'offset' => 0,
            'region' => 'BR',
            'language' => 'en'
        );

        $this->logger->log('debug', 'Admitad: Parâmetros de busca: ' . json_encode($params));

        $endpoint = '/coupons/';
        $response = $this->make_api_request($endpoint, $settings, $params);

        if (is_wp_error($response)) {
            return $response;
        }

        if (!isset($response['results']) || !is_array($response['results'])) {
            $this->logger->log('error', 'Admitad: Resposta inválida da API');
            return array();
        }

        $this->logger->log('info', sprintf('Admitad: %d cupons encontrados', count($response['results'])));

        $parsed_coupons = $this->parse_coupons($response['results'], $settings);

        $this->logger->log('info', sprintf('Admitad: Busca finalizada - %d cupons importados', count($parsed_coupons)));

        return $parsed_coupons;
    }

    private function generate_external_id($api_coupon) {
        if (isset($api_coupon['id'])) {
            return 'admitad_' . $api_coupon['id'];
        }

        $campaign_id = isset($api_coupon['campaign']['id']) ? $api_coupon['campaign']['id'] : '';
        $name = isset($api_coupon['name']) ? $api_coupon['name'] : '';

        if (!empty($campaign_id) && !empty($name)) {
            return 'admitad_' . $campaign_id . '_' . substr(md5($name), 0, 8);
        }

        return 'admitad_' . md5(serialize($api_coupon));
    }

    private function coupon_already_imported($external_id) {
        global $wpdb;

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

    private function extract_discount($api_coupon) {
        if (isset($api_coupon['discount'])) {
            return sanitize_text_field($api_coupon['discount']);
        }

        $text = ($api_coupon['name'] ?? '') . ' ' . ($api_coupon['description'] ?? '');

        if (preg_match('/(\d+)%\s*(off|desconto|discount)/i', $text, $matches)) {
            return $matches[1] . '% OFF';
        }

        if (preg_match('/R\$\s*(\d+(?:,\d{2})?)\s*(off|desconto|discount)/i', $text, $matches)) {
            return 'R$ ' . $matches[1] . ' OFF';
        }

        return '';
    }

    private function determine_coupon_type($api_coupon) {
        if (isset($api_coupon['promocode']) && !empty($api_coupon['promocode'])) {
            return 1;
        }
        return 3;
    }

    /**
     * Busca o primeiro website_id disponível automaticamente
     * Usa cache para evitar requisições repetidas
     */
    private function get_first_website_id($settings) {
        // Se já está configurado manualmente, usar ele
        if (!empty($settings['website_id'])) {
            $this->logger->log('debug', sprintf('Admitad: Usando Website ID configurado: %s', $settings['website_id']));
            return $settings['website_id'];
        }

        // Verificar cache
        $cache_key = 'admitad_website_id_' . md5($settings['client_id']);
        $cached_website_id = get_transient($cache_key);

        if ($cached_website_id) {
            $this->logger->log('debug', sprintf('Admitad: Website ID do cache: %s', $cached_website_id));
            return $cached_website_id;
        }

        $this->logger->log('info', 'Admitad: Website ID não configurado. Configure manualmente nas configurações do provedor para gerar deeplinks.');
        
        return null;
    }

    public function get_advertisers($settings) {
        return array();
    }

    public function get_categories($settings) {
        return array();
    }
}