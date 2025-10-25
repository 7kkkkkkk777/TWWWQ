<?php
/**
 * Lomadee Provider Class
 * Implements Lomadee API for coupon/offer importing
 */

namespace CouponImporter\Providers;

if (!defined('ABSPATH')) {
    exit;
}

class Lomadee {

    private $api_base_url = 'https://api.lomadee.com.br';
    private $logger;

    public function __construct() {
        $this->logger = new \CouponImporter\Logger();
    }

    public function get_name() {
        return 'lomadee';
    }

    public function get_label() {
        return 'Lomadee';
    }

    public function get_settings_fields() {
        return array(
            'api_key' => array(
                'label' => __('App Token', '7k-coupons-importer'),
                'type' => 'text',
                'required' => true,
                'description' => __('Seu App Token da Lomadee (obtido no painel de desenvolvedor)', '7k-coupons-importer')
            ),
            'source_id' => array(
                'label' => __('Source ID', '7k-coupons-importer'),
                'type' => 'text',
                'required' => true,
                'description' => __('Seu Source ID / ID de afiliado da Lomadee', '7k-coupons-importer')
            ),
            'import_limit' => array(
                'label' => __('Limite de Importação', '7k-coupons-importer'),
                'type' => 'number',
                'default' => 50,
                'description' => __('Número máximo de cupons por importação (Recomendado: 50-200)', '7k-coupons-importer')
            ),
            'category_filter' => array(
                'label' => __('Filtro de Categoria', '7k-coupons-importer'),
                'type' => 'text',
                'required' => false,
                'description' => __('IDs de categorias separados por vírgula (opcional)', '7k-coupons-importer')
            ),
            'store_filter' => array(
                'label' => __('Filtro de Loja', '7k-coupons-importer'),
                'type' => 'text',
                'required' => false,
                'description' => __('IDs de lojas separados por vírgula (opcional)', '7k-coupons-importer')
            )
        );
    }

    public function validate_settings($settings) {
        // Retornar true/false ao invés de array de erros
        if (empty($settings['api_key'])) {
            return false;
        }

        if (empty($settings['source_id'])) {
            return false;
        }

        return true;
    }

    public function test_connection($settings) {
        if (empty($settings['source_id'])) {
            return new \WP_Error('missing_source_id', __('Source ID não informado. Configure o Source ID nas configurações do provedor.', '7k-coupons-importer'));
        }

        $endpoint = '/offer/_all';
        $response = $this->make_api_request($endpoint, $settings, array(
            'size' => 1
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        if (!isset($response['offers'])) {
            return new \WP_Error('invalid_response', __('Resposta inválida da API', '7k-coupons-importer'));
        }

        return true;
    }

    public function get_coupons($settings, $limit = 100) {
        if ($limit === 100 && isset($settings['import_limit'])) {
            $limit = intval($settings['import_limit']);
        }

        if (empty($settings['source_id'])) {
            $this->logger->log('error', 'Lomadee: Source ID não informado. Abortando importação.');
            return new \WP_Error('missing_source_id', __('Source ID não informado. Configure o Source ID nas configurações do provedor.', '7k-coupons-importer'));
        }

        $this->logger->log('info', sprintf('Lomadee: Iniciando busca de ofertas, limite: %d', $limit));

        $all_coupons = array();
        $page = 1;
        $page_size = min($limit, 100);

        $params = array(
            'size' => $page_size,
            'page' => $page
        );

        if (!empty($settings['category_filter'])) {
            $params['categoryId'] = $settings['category_filter'];
        }

        if (!empty($settings['store_filter'])) {
            $params['storeId'] = $settings['store_filter'];
        }

        $this->logger->log('debug', 'Lomadee: Parâmetros de busca: ' . json_encode($params));

        while (count($all_coupons) < $limit) {
            $this->logger->log('debug', sprintf('Lomadee: Buscando página %d (size: %d)', $page, $page_size));

            $params['page'] = $page;
            $endpoint = '/offer/_all';
            $response = $this->make_api_request($endpoint, $settings, $params);

            if (is_wp_error($response)) {
                $this->logger->log('error', 'Lomadee: Erro na requisição: ' . $response->get_error_message());
                break;
            }

            if (!isset($response['offers']) || !is_array($response['offers'])) {
                $this->logger->log('warning', 'Lomadee: Resposta sem dados válidos');
                break;
            }

            $offers_data = $response['offers'];
            $this->logger->log('debug', sprintf('Lomadee: Página %d - %d ofertas encontradas', $page, count($offers_data)));

            if (empty($offers_data)) {
                $this->logger->log('info', 'Lomadee: Nenhuma oferta encontrada nesta página, finalizando busca');
                break;
            }

            $parsed_coupons = $this->parse_coupons($offers_data);
            $new_coupons_count = count($parsed_coupons);

            $all_coupons = array_merge($all_coupons, $parsed_coupons);

            $this->logger->log('info', sprintf('Lomadee: Página %d processada - %d ofertas adicionadas (total: %d/%d)',
                $page,
                $new_coupons_count,
                count($all_coupons),
                $limit
            ));

            if (count($all_coupons) >= $limit) {
                break;
            }

            if (!isset($response['pagination']['hasNext']) || !$response['pagination']['hasNext']) {
                $this->logger->log('info', 'Lomadee: Última página alcançada');
                break;
            }

            $page++;
        }

        $final_coupons = array_slice($all_coupons, 0, $limit);

        $this->logger->log('info', sprintf('Lomadee: Busca finalizada - %d ofertas importadas', count($final_coupons)));

        return $final_coupons;
    }

    private function make_api_request($endpoint, $settings, $params = array()) {
        $start_time = microtime(true);

        $params['sourceId'] = $settings['source_id'];

        $url = $this->api_base_url . '/v3/' . $settings['api_key'] . $endpoint;

        if (!empty($params)) {
            $url = add_query_arg($params, $url);
        }

        $args = array(
            'timeout' => 30,
            'headers' => array(
                'User-Agent' => 'WordPress/7K-Coupons-Importer',
                'Accept' => 'application/json'
            )
        );

        $this->logger->log('debug', sprintf('Lomadee API Request: %s', $url));

        $response = wp_remote_get($url, $args);
        $response_time = (microtime(true) - $start_time) * 1000;

        if (is_wp_error($response)) {
            $error_msg = $response->get_error_message();
            $this->logger->log_api_request('lomadee', $endpoint, 0, $response_time, $error_msg);
            $this->logger->log('error', sprintf('Lomadee request error: %s', $error_msg));
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        $this->logger->log_api_request('lomadee', $endpoint, $response_code, $response_time);

        if ($response_code !== 200) {
            $error_message = sprintf('API returned status code: %d. Response: %s', $response_code, substr($body, 0, 500));
            $this->logger->log('error', 'Lomadee API Error: ' . $error_message);
            return new \WP_Error('api_error', $error_message);
        }

        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->log('error', 'Lomadee JSON decode error: ' . json_last_error_msg());
            return new \WP_Error('json_error', 'Invalid JSON response: ' . json_last_error_msg());
        }

        $this->logger->log('debug', sprintf('Lomadee API Response: %d items, Response time: %.2fms',
            isset($data['offers']) ? count($data['offers']) : 0,
            $response_time
        ));

        return $data;
    }

    private function parse_coupons($api_coupons) {
        $parsed_coupons = array();

        if (!is_array($api_coupons)) {
            $this->logger->log('error', 'Lomadee parse_coupons: Expected array, got ' . gettype($api_coupons));
            return $parsed_coupons;
        }

        $this->logger->log('debug', sprintf('Lomadee: Parsing %d coupons', count($api_coupons)));

        $skipped_count = 0;

        foreach ($api_coupons as $index => $api_coupon) {
            if (!is_array($api_coupon)) {
                $this->logger->log('warning', sprintf('Lomadee: Coupon at index %d is not an array', $index));
                continue;
            }

            $external_id = $this->generate_external_id($api_coupon);

            if ($this->coupon_already_imported($external_id)) {
                $skipped_count++;
                $this->logger->log('debug', sprintf('Lomadee: Cupom "%s" (ID: %s) já importado, pulando...',
                    isset($api_coupon['name']) ? substr($api_coupon['name'], 0, 50) : 'N/A',
                    $external_id
                ));
                continue;
            }

            $parsed_coupon = $this->parse_single_coupon($api_coupon);
            if ($parsed_coupon) {
                $parsed_coupons[] = $parsed_coupon;
            }
        }

        $this->logger->log('info', sprintf('Lomadee: Successfully parsed %d out of %d coupons (%d skipped - already imported)',
            count($parsed_coupons),
            count($api_coupons),
            $skipped_count
        ));

        return $parsed_coupons;
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

    private function parse_single_coupon($api_coupon) {
        $coupon = array();

        $this->logger->log('debug', sprintf('Lomadee: Processando oferta - ID: %s, Nome: %s',
            $api_coupon['id'] ?? 'N/A',
            isset($api_coupon['nome']) ? substr($api_coupon['nome'], 0, 50) : 'N/A'
        ));

        $coupon['title'] = isset($api_coupon['nome']) ? sanitize_text_field($api_coupon['nome']) : '';
        $coupon['description'] = isset($api_coupon['descricao']) ? sanitize_textarea_field($api_coupon['descricao']) : '';

        $coupon['link'] = '';
        if (isset($api_coupon['link']) && !empty($api_coupon['link'])) {
            $coupon['link'] = esc_url_raw($api_coupon['link']);
            $this->logger->log('debug', sprintf('Lomadee: Link encontrado: %s', substr($coupon['link'], 0, 50)));
        }

        $coupon['code'] = '';
        if (isset($api_coupon['cupom']) && !empty($api_coupon['cupom'])) {
            $coupon['code'] = sanitize_text_field($api_coupon['cupom']);
            $this->logger->log('debug', sprintf('Lomadee: Código encontrado: %s', $coupon['code']));
        }

        $coupon['advertiser'] = '';
        $coupon['advertiser_id'] = '';
        if (isset($api_coupon['loja'])) {
            if (isset($api_coupon['loja']['id'])) {
                $coupon['advertiser_id'] = sanitize_text_field($api_coupon['loja']['id']);
            }
            if (isset($api_coupon['loja']['nome'])) {
                $coupon['advertiser'] = sanitize_text_field($api_coupon['loja']['nome']);
            }
        }

        $coupon['start_date'] = '';
        $coupon['expiration'] = '';
        if (isset($api_coupon['vigencia'])) {
            if (isset($api_coupon['vigencia']['inicio'])) {
                $coupon['start_date'] = coupon_importer_parse_date($api_coupon['vigencia']['inicio']);
            }
            if (isset($api_coupon['vigencia']['fim'])) {
                $coupon['expiration'] = coupon_importer_parse_date($api_coupon['vigencia']['fim']);
            }
        }

        $coupon['discount'] = $this->extract_discount($api_coupon);

        $coupon['tags'] = array();
        $coupon['category'] = array();

        if (isset($api_coupon['categoria']) && is_array($api_coupon['categoria'])) {
            if (isset($api_coupon['categoria']['nome'])) {
                $coupon['category'][] = sanitize_text_field($api_coupon['categoria']['nome']);
            }
        }

        if (isset($api_coupon['preco_atual']) && !empty($api_coupon['preco_atual'])) {
            $coupon['tags'][] = 'preco: ' . $api_coupon['preco_atual'];
        }

        $coupon['coupon_type'] = !empty($coupon['code']) ? 1 : 3;

        $coupon['is_exclusive'] = 0;

        $coupon['deeplink'] = $coupon['link'];

        $coupon['external_id'] = $this->generate_external_id($api_coupon);

        if (!empty($coupon['code'])) {
            $coupon['tags'][] = 'cupom';
        } else {
            $coupon['tags'][] = 'oferta';
        }

        if (empty($coupon['title'])) {
            $this->logger->log('warning', 'Lomadee: Oferta rejeitada - título vazio. ID: ' . ($api_coupon['id'] ?? 'N/A'));
            return null;
        }

        if (empty($coupon['link'])) {
            $this->logger->log('warning', sprintf('Lomadee: Oferta rejeitada - link vazio. Título: %s',
                substr($coupon['title'], 0, 50)
            ));
            return null;
        }

        $this->logger->log('debug', sprintf('Lomadee: Oferta parseada - Título: %s, Código: %s, Link: %s',
            substr($coupon['title'], 0, 50),
            $coupon['code'] ?: 'N/A',
            substr($coupon['link'], 0, 50)
        ));

        return $coupon;
    }

    private function generate_external_id($api_coupon) {
        if (isset($api_coupon['id'])) {
            return 'lomadee_' . $api_coupon['id'];
        }

        $loja_id = isset($api_coupon['loja']['id']) ? $api_coupon['loja']['id'] : '';
        $name = isset($api_coupon['nome']) ? $api_coupon['nome'] : '';

        if (!empty($loja_id) && !empty($name)) {
            return 'lomadee_' . $loja_id . '_' . substr(md5($name), 0, 8);
        }

        return 'lomadee_' . md5(serialize($api_coupon));
    }

    private function extract_discount($api_coupon) {
        if (isset($api_coupon['desconto']) && !empty($api_coupon['desconto'])) {
            return sanitize_text_field($api_coupon['desconto']);
        }

        $text = ($api_coupon['nome'] ?? '') . ' ' . ($api_coupon['descricao'] ?? '');

        if (preg_match('/(\d+)%\s*(off|desconto|discount)/i', $text, $matches)) {
            return $matches[1] . '% OFF';
        }

        if (preg_match('/R\$\s*(\d+(?:,\d{2})?)\s*(off|desconto|discount)/i', $text, $matches)) {
            return 'R$ ' . $matches[1] . ' OFF';
        }

        return '';
    }

    private function determine_coupon_type($api_coupon) {
        if (isset($api_coupon['cupom']) && !empty($api_coupon['cupom'])) {
            return 1;
        }
        return 3;
    }

    public function get_advertisers($settings) {
        return array();
    }

    public function get_categories($settings) {
        return array();
    }
}