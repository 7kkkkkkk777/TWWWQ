<?php

namespace CouponImporter;

if (!defined('ABSPATH')) {
    exit;
}

class ProductImageFetcher {

    private $logger;
    private $last_awin_request = 0;
    private $current_offer_title = '';

    public function __construct() {
        $this->logger = new Logger();
    }

    public function fetch_and_apply_image($imported_coupon_id, $provider_name) {
        $settings = get_option('ci7k_settings', array());

        // Debug temporário
        $this->logger->log('debug', 'Configurações completas: ' . print_r($settings, true));
        $this->logger->log('debug', 'enable_product_images value: ' . var_export($settings['enable_product_images'] ?? 'NOT SET', true));

        if (!isset($settings['enable_product_images']) || $settings['enable_product_images'] != 1) {
            $this->logger->log('debug', 'Busca de imagens de produtos está desativada nas configurações');
            return false;
        }

        $coupon_type = get_post_meta($imported_coupon_id, '_ci7k_coupon_type', true);

        if ($coupon_type == 1) {
            $this->logger->log('debug', sprintf('Cupom ID %d é tipo 1 (cupom), pulando busca de imagem', $imported_coupon_id));
            return false;
        }

        $this->logger->log('info', sprintf('Iniciando busca de imagem para oferta ID %d (Provider: %s)', $imported_coupon_id, $provider_name));

        $image_url = null;

        if ($provider_name === 'awin') {
            $image_url = $this->fetch_awin_product_image($imported_coupon_id);
        } elseif ($provider_name === 'rakuten') {
            $image_url = $this->fetch_rakuten_product_image($imported_coupon_id);
        }

        if ($image_url) {
            update_post_meta($imported_coupon_id, '_ci7k_product_image_url', $image_url);
            $this->logger->log('success', sprintf('Imagem de produto encontrada e salva para oferta ID %d: %s', $imported_coupon_id, $image_url));
            return $image_url;
        } else {
            $this->logger->log('info', sprintf('Nenhuma imagem de produto encontrada para oferta ID %d', $imported_coupon_id));
            return false;
        }
    }

    public function fetch_and_attach_image($post_id, $offer_data, $provider) {
        $this->logger->log('info', sprintf('Iniciando busca de imagem para oferta ID %d (Provider: %s)', $post_id, $provider));

        // Armazenar título da oferta para uso posterior
        $this->current_offer_title = $offer_data['title'] ?? '';

        $settings = get_option('ci7k_settings', array());

        // Debug temporário
        $this->logger->log('debug', 'Configurações completas: ' . print_r($settings, true));
        $this->logger->log('debug', 'enable_product_images value: ' . var_export($settings['enable_product_images'] ?? 'NOT SET', true));

        if (!isset($settings['enable_product_images']) || $settings['enable_product_images'] != 1) {
            $this->logger->log('debug', 'Busca de imagens de produtos está desativada nas configurações');
            return false;
        }

        $coupon_type = get_post_meta($post_id, '_ci7k_coupon_type', true);

        if ($coupon_type == 1) {
            $this->logger->log('debug', sprintf('Cupom ID %d é tipo 1 (cupom), pulando busca de imagem', $post_id));
            return false;
        }

        $image_url = null;

        if ($provider === 'awin') {
            $image_url = $this->fetch_awin_product_image($post_id);
        } elseif ($provider === 'rakuten') {
            $image_url = $this->fetch_rakuten_product_image($post_id);
        }

        if ($image_url) {
            update_post_meta($post_id, '_ci7k_product_image_url', $image_url);
            $this->logger->log('success', sprintf('Imagem de produto encontrada e salva para oferta ID %d: %s', $post_id, $image_url));
            return $image_url;
        } else {
            $this->logger->log('info', sprintf('Nenhuma imagem de produto encontrada para oferta ID %d', $post_id));
            return false;
        }
    }

    private function fetch_awin_product_image($imported_coupon_id) {
        $settings = ci7k_get_provider_settings('awin');

        if (!$settings || empty($settings['api_token']) || empty($settings['publisher_id'])) {
            $this->logger->log('error', 'Awin: Configurações incompletas para busca de imagem');
            return null;
        }

        $advertiser_id = get_post_meta($imported_coupon_id, '_ci7k_advertiser_id', true);
        $offer_title = get_post($imported_coupon_id)->post_title;

        if (empty($advertiser_id)) {
            $this->logger->log('warning', sprintf('Awin: Oferta ID %d não possui advertiser_id', $imported_coupon_id));
            return null;
        }

        $cache_key = 'awin_feed_' . $advertiser_id;
        $feed_data = get_transient($cache_key);

        if ($feed_data === false) {
            $feed_data = $this->download_awin_feed($settings, $advertiser_id);

            if ($feed_data && is_array($feed_data)) {
                set_transient($cache_key, $feed_data, $this->cache_duration);
                $this->logger->log('info', sprintf('Awin: Feed baixado e cacheado para advertiser %s (%d produtos)', $advertiser_id, count($feed_data)));
            } else {
                $this->logger->log('warning', sprintf('Awin: Falha ao baixar feed para advertiser %s', $advertiser_id));
                return null;
            }
        } else {
            $this->logger->log('debug', sprintf('Awin: Usando feed em cache para advertiser %s', $advertiser_id));
        }

        return $this->find_image_in_awin_feed($feed_data, array(
            'title' => $offer_title,
            'description' => get_post_meta($imported_coupon_id, '_ci7k_description', true)
        ));
    }

    /**
     * Lista todos os feeds disponíveis para o publisher
     */
    private function get_awin_feeds_list($settings) {
        // Verificar cache primeiro
        $cache_key = 'awin_feeds_list_' . $settings['publisher_id'];
        $cached = get_transient($cache_key);
        
        if ($cached !== false) {
            $this->logger->log('debug', 'Awin: Usando lista de feeds do cache');
            return $cached;
        }

        $this->check_awin_rate_limit();

        $url = sprintf(
            'https://api.awin.com/publishers/%s/product-feeds',
            $settings['publisher_id']
        );

        $args = array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . trim($settings['api_token']),
                'User-Agent' => 'WordPress/7K-Coupons-Importer'
            )
        );

        $this->logger->log('debug', 'Awin: Listando feeds disponíveis');

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            $this->logger->log('error', 'Awin Feed List Error: ' . $response->get_error_message());
            return array();
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            $this->logger->log('error', sprintf('Awin Feed List HTTP Error: %d', $response_code));
            return array();
        }

        $body = wp_remote_retrieve_body($response);
        $feeds = json_decode($body, true);

        if (!is_array($feeds)) {
            $this->logger->log('error', 'Awin: Resposta inválida ao listar feeds');
            return array();
        }

        // Organizar feeds por advertiser ID
        $available = array();
        foreach ($feeds as $feed) {
            if (isset($feed['advertiserId'])) {
                $available[$feed['advertiserId']] = $feed;
            }
        }

        $this->logger->log('info', sprintf('Awin: %d feeds disponíveis', count($available)));

        // Cachear por 24 horas
        set_transient($cache_key, $available, 24 * HOUR_IN_SECONDS);

        return $available;
    }

    /**
     * Busca produtos usando a API de Product Search da Awin
     */
    private function search_awin_products($settings, $advertiser_id, $keywords) {
        $this->check_awin_rate_limit();

        // API de Product Search da Awin
        $url = sprintf(
            'https://api.awin.com/advertisers/%s/products',
            $advertiser_id
        );

        // Parâmetros de busca
        $params = array(
            'query' => implode(' ', array_slice($keywords, 0, 3)), // Primeiras 3 palavras-chave
            'limit' => 10
        );

        $url_with_params = add_query_arg($params, $url);

        $args = array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . trim($settings['api_token']),
                'User-Agent' => 'WordPress/7K-Coupons-Importer'
            )
        );

        $this->logger->log('debug', sprintf('Awin: Buscando produtos com query: %s', $params['query']));

        $response = wp_remote_get($url_with_params, $args);

        if (is_wp_error($response)) {
            $this->logger->log('error', 'Awin Product Search Error: ' . $response->get_error_message());
            return array();
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            $this->logger->log('error', sprintf('Awin Product Search HTTP Error: %d', $response_code));
            return array();
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!isset($data['products']) || !is_array($data['products'])) {
            $this->logger->log('error', 'Awin: Resposta inválida da Product Search API');
            return array();
        }

        $this->logger->log('info', sprintf('Awin: %d produtos encontrados', count($data['products'])));

        return $data['products'];
    }

    /**
     * Baixa o feed de produto (fallback para método antigo)
     */
    private function download_awin_feed($settings, $advertiser_id) {
        // Tentar Product Search API primeiro
        $offer_title = $this->current_offer_title ?? '';
        $keywords = $this->extract_keywords($offer_title);

        if (!empty($keywords)) {
            $products = $this->search_awin_products($settings, $advertiser_id, $keywords);
            if (!empty($products)) {
                return $products;
            }
        }

        // Se Product Search falhar, tentar feed tradicional
        $this->logger->log('info', 'Awin: Product Search não retornou resultados, tentando feed tradicional');

        // Primeiro, obter a lista de feeds disponíveis
        $feeds = $this->get_awin_feeds_list($settings);

        if (!isset($feeds[$advertiser_id])) {
            $this->logger->log('error', sprintf('Awin: Advertiser %s não tem feed disponível', $advertiser_id));
            return null;
        }

        $feed_info = $feeds[$advertiser_id];
        $download_url = $feed_info['downloadUrl'] ?? null;

        if (!$download_url) {
            $this->logger->log('error', sprintf('Awin: downloadUrl não encontrado para advertiser %s', $advertiser_id));
            return null;
        }

        // Verificar cache do feed
        $cache_key = 'awin_feed_' . $advertiser_id;
        $cached = get_transient($cache_key);
        
        if ($cached !== false) {
            $this->logger->log('debug', sprintf('Awin: Usando feed cacheado para advertiser %s', $advertiser_id));
            return $cached;
        }

        $this->check_awin_rate_limit();

        $this->logger->log('info', sprintf('Awin: Baixando feed para advertiser %s (%s)', $advertiser_id, $feed_info['advertiserName'] ?? 'N/A'));
        $this->logger->log('debug', sprintf('Awin: URL do feed: %s', $download_url));

        $args = array(
            'timeout' => 60,
            'headers' => array(
                'User-Agent' => 'WordPress/7K-Coupons-Importer'
            )
        );

        $response = wp_remote_get($download_url, $args);

        if (is_wp_error($response)) {
            $this->logger->log('error', sprintf('Awin Feed HTTP Error para advertiser %s: %s', $advertiser_id, $response->get_error_message()));
            return null;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            $this->logger->log('error', sprintf('Awin Feed HTTP Error: %d para advertiser %s', $response_code, $advertiser_id));
            return null;
        }

        $body = wp_remote_retrieve_body($response);

        if (empty($body)) {
            $this->logger->log('error', 'Awin: Feed retornou vazio');
            return null;
        }

        // Parse CSV
        $lines = explode("\n", trim($body));
        
        if (empty($lines) || count($lines) < 2) {
            $this->logger->log('error', 'Awin: Feed vazio ou sem dados');
            return null;
        }

        // Primeira linha é o header
        $header = str_getcsv(array_shift($lines));
        
        $this->logger->log('debug', 'Awin Feed Headers: ' . implode(', ', $header));
        
        $products = array();
        foreach ($lines as $line_num => $line) {
            if (empty(trim($line))) continue;
            
            $values = str_getcsv($line);
            
            if (count($values) === count($header)) {
                $product = array_combine($header, $values);
                $products[] = $product;
            }
        }

        $this->logger->log('info', sprintf('Awin: Feed processado com %d produtos', count($products)));

        // Cachear por 24 horas
        set_transient($cache_key, $products, 24 * HOUR_IN_SECONDS);

        return $products;
    }

    private function find_image_in_awin_feed($products, $offer_data) {
        if (empty($products)) {
            return null;
        }

        // Verificar se é resposta da Product Search API (tem estrutura diferente)
        $first_product = reset($products);
        $is_search_api = isset($first_product['imageUrl']) || isset($first_product['images']);

        if ($is_search_api) {
            // Formato da Product Search API
            foreach ($products as $product) {
                // Tentar diferentes campos de imagem
                if (isset($product['imageUrl']) && !empty($product['imageUrl'])) {
                    $image_url = $product['imageUrl'];
                    if (filter_var($image_url, FILTER_VALIDATE_URL)) {
                        $this->logger->log('info', sprintf('Awin: Imagem encontrada via Product Search API: %s', $image_url));
                        return $image_url;
                    }
                }

                if (isset($product['images']) && is_array($product['images']) && !empty($product['images'])) {
                    $image_url = $product['images'][0];
                    if (filter_var($image_url, FILTER_VALIDATE_URL)) {
                        $this->logger->log('info', sprintf('Awin: Imagem encontrada via Product Search API (array): %s', $image_url));
                        return $image_url;
                    }
                }

                if (isset($product['merchantImageUrl']) && !empty($product['merchantImageUrl'])) {
                    $image_url = $product['merchantImageUrl'];
                    if (filter_var($image_url, FILTER_VALIDATE_URL)) {
                        $this->logger->log('info', sprintf('Awin: Imagem encontrada via Product Search API: %s', $image_url));
                        return $image_url;
                    }
                }
            }
        } else {
            // Formato do Feed CSV
            $image_fields = array('merchantImageURL', 'aw_image_url', 'merchant_image_url', 'image_url', 'aw_thumb_url');
            $name_fields = array('productName', 'product_name', 'name', 'title');

            $offer_title = $offer_data['title'] ?? '';
            $keywords = $this->extract_keywords($offer_title);
            
            $this->logger->log('debug', sprintf('Awin: Buscando imagem com palavras-chave: %s', implode(', ', $keywords)));

            // Tentar encontrar produto com correspondência
            foreach ($products as $product) {
                $product_name = '';
                foreach ($name_fields as $field) {
                    if (isset($product[$field]) && !empty($product[$field])) {
                        $product_name = $product[$field];
                        break;
                    }
                }

                if (empty($product_name)) {
                    continue;
                }

                $match_score = 0;
                foreach ($keywords as $keyword) {
                    if (stripos($product_name, $keyword) !== false) {
                        $match_score++;
                    }
                }

                if ($match_score > 0) {
                    foreach ($image_fields as $field) {
                        if (isset($product[$field]) && !empty($product[$field])) {
                            $image_url = $product[$field];
                            
                            if (filter_var($image_url, FILTER_VALIDATE_URL)) {
                                $this->logger->log('info', sprintf('Awin: Imagem encontrada no campo "%s" (score: %d): %s', $field, $match_score, $image_url));
                                return $image_url;
                            }
                        }
                    }
                }
            }

            // Fallback: primeira imagem disponível
            $this->logger->log('debug', 'Awin: Nenhuma correspondência encontrada, usando primeira imagem disponível');
            
            foreach ($products as $product) {
                foreach ($image_fields as $field) {
                    if (isset($product[$field]) && !empty($product[$field])) {
                        $image_url = $product[$field];
                        
                        if (filter_var($image_url, FILTER_VALIDATE_URL)) {
                            $this->logger->log('info', sprintf('Awin: Usando primeira imagem disponível do campo "%s": %s', $field, $image_url));
                            return $image_url;
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Extrai palavras-chave relevantes de um texto
     */
    private function extract_keywords($text) {
        // Remover palavras comuns em português
        $stopwords = array('de', 'da', 'do', 'em', 'para', 'com', 'por', 'na', 'no', 'e', 'o', 'a', 'os', 'as');
        
        // Limpar e dividir em palavras
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9\s]/i', ' ', $text);
        $words = array_filter(explode(' ', $text));
        
        // Remover stopwords e palavras muito curtas
        $keywords = array();
        foreach ($words as $word) {
            if (strlen($word) > 3 && !in_array($word, $stopwords)) {
                $keywords[] = $word;
            }
        }
        
        return array_slice($keywords, 0, 5); // Limitar a 5 palavras-chave
    }

    private function fetch_rakuten_product_image($imported_coupon_id) {
        $this->check_rakuten_rate_limit();

        $settings = ci7k_get_provider_settings('rakuten');

        if (!$settings) {
            $this->logger->log('error', 'Rakuten: Configurações não encontradas');
            return null;
        }

        $offer_title = get_post($imported_coupon_id)->post_title;
        $advertiser_id = get_post_meta($imported_coupon_id, '_ci7k_advertiser_id', true);

        if (empty($offer_title)) {
            return null;
        }

        $cache_key = 'rakuten_product_' . md5($offer_title . $advertiser_id);
        $cached_image = get_transient($cache_key);

        if ($cached_image !== false) {
            $this->logger->log('debug', 'Rakuten: Usando imagem em cache');
            return $cached_image;
        }

        $bearer_token = $this->get_rakuten_bearer_token($settings);

        if (!$bearer_token) {
            $this->logger->log('error', 'Rakuten: Falha ao obter token de autenticação');
            return null;
        }

        $url = 'https://api.linksynergy.com/productsearch/1.0';

        $params = array(
            'keyword' => $offer_title,
            'max' => 10,
            'pagenumber' => 1
        );

        if (!empty($advertiser_id)) {
            $params['mid'] = $advertiser_id;
        }

        $url_with_params = add_query_arg($params, $url);

        $args = array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $bearer_token,
                'Accept' => 'application/xml',
                'User-Agent' => 'WordPress/7K-Coupons-Importer'
            )
        );

        $this->logger->log('debug', sprintf('Rakuten: Buscando produto: %s', substr($offer_title, 0, 50)));

        $response = wp_remote_get($url_with_params, $args);

        if (is_wp_error($response)) {
            $this->logger->log('error', 'Rakuten Product Search Error: ' . $response->get_error_message());
            return null;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            $this->logger->log('error', sprintf('Rakuten Product Search HTTP Error: %d', $response_code));
            return null;
        }

        $body = wp_remote_retrieve_body($response);

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);

        if (!$xml) {
            $this->logger->log('error', 'Rakuten: Falha ao parsear XML');
            return null;
        }

        $image_url = $this->extract_best_image_from_rakuten_xml($xml, $offer_title);

        if ($image_url) {
            set_transient($cache_key, $image_url, $this->cache_duration);
            $this->logger->log('info', sprintf('Rakuten: Imagem encontrada e cacheada: %s', substr($image_url, 0, 100)));
        }

        return $image_url;
    }

    private function extract_best_image_from_rakuten_xml($xml, $offer_title) {
        if (!isset($xml->item)) {
            return null;
        }

        $offer_title_clean = $this->clean_title_for_comparison($offer_title);
        $best_match = null;
        $best_similarity = 0;

        foreach ($xml->item as $item) {
            $product_name = (string) $item->productname;
            $image_url = (string) $item->imageurl;

            if (empty($image_url) || !filter_var($image_url, FILTER_VALIDATE_URL)) {
                continue;
            }

            $product_name_clean = $this->clean_title_for_comparison($product_name);

            similar_text($offer_title_clean, $product_name_clean, $similarity);

            if ($similarity > $best_similarity) {
                $best_similarity = $similarity;
                $best_match = $image_url;
            }
        }

        if ($best_match && $best_similarity > 30) {
            $this->logger->log('debug', sprintf('Rakuten: Melhor correspondência (similaridade: %.2f%%)', $best_similarity));
            return $best_match;
        }

        if (isset($xml->item[0]->imageurl)) {
            $first_image = (string) $xml->item[0]->imageurl;
            if (filter_var($first_image, FILTER_VALIDATE_URL)) {
                $this->logger->log('debug', 'Rakuten: Usando primeira imagem disponível');
                return $first_image;
            }
        }

        return null;
    }

    private function get_rakuten_bearer_token($settings) {
        $auth_method = isset($settings['auth_method']) ? $settings['auth_method'] : 'bearer';

        if ($auth_method === 'oauth2') {
            $transient_key = 'rakuten_oauth_token_' . md5($settings['client_id']);
            $cached_token = get_transient($transient_key);

            if ($cached_token) {
                return $cached_token;
            }

            $token_key = base64_encode($settings['client_id'] . ':' . $settings['client_secret']);
            $token_url = 'https://api.linksynergy.com/token';

            $args = array(
                'timeout' => 30,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token_key,
                    'Content-Type' => 'application/x-www-form-urlencoded'
                ),
                'body' => 'scope=' . urlencode($settings['scope'])
            );

            $response = wp_remote_post($token_url, $args);

            if (is_wp_error($response)) {
                return false;
            }

            $response_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);

            if ($response_code !== 200) {
                return false;
            }

            $token_data = json_decode($body, true);

            if (!isset($token_data['access_token'])) {
                return false;
            }

            $expires_in = isset($token_data['expires_in']) ? intval($token_data['expires_in']) : 3600;
            set_transient($transient_key, $token_data['access_token'], $expires_in - 60);

            return $token_data['access_token'];
        }

        return isset($settings['bearer_token']) ? $settings['bearer_token'] : '';
    }

    private function clean_title_for_comparison($title) {
        $title = strtolower($title);
        $title = preg_replace('/[^a-z0-9\s]/', '', $title);
        $title = preg_replace('/\s+/', ' ', $title);
        return trim($title);
    }

    private function get_locale_code() {
        $wp_locale = get_locale();

        $locale_map = array(
            'pt_BR' => 'pt_BR',
            'pt_PT' => 'pt_PT',
            'en_US' => 'en_US',
            'en_GB' => 'en_GB',
            'es_ES' => 'es_ES',
            'fr_FR' => 'fr_FR',
            'de_DE' => 'de_DE',
            'it_IT' => 'it_IT'
        );

        if (isset($locale_map[$wp_locale])) {
            return $locale_map[$wp_locale];
        }

        $language = substr($wp_locale, 0, 2);
        $country = strtoupper(substr($wp_locale, 3, 2));

        return $language . '_' . $country;
    }

    private function check_awin_rate_limit() {
        $transient_key = 'awin_api_calls_minute';
        $calls = get_transient($transient_key);

        if ($calls === false) {
            set_transient($transient_key, 1, 60);
        } else {
            if ($calls >= 5) {
                $this->logger->log('warning', 'Awin: Limite de 5 requisições/minuto atingido, aguardando...');
                sleep(12);
                delete_transient($transient_key);
                set_transient($transient_key, 1, 60);
            } else {
                set_transient($transient_key, $calls + 1, 60);
            }
        }
    }

    private function check_rakuten_rate_limit() {
        $transient_key = 'rakuten_api_calls_minute';
        $calls = get_transient($transient_key);

        if ($calls === false) {
            set_transient($transient_key, 1, 60);
        } else {
            if ($calls >= 100) {
                $this->logger->log('warning', 'Rakuten: Limite de 100 requisições/minuto atingido, aguardando...');
                sleep(1);
                delete_transient($transient_key);
                set_transient($transient_key, 1, 60);
            } else {
                set_transient($transient_key, $calls + 1, 60);
            }
        }
    }

    public function apply_image_to_published_coupon($coupon_id) {
        $external_id = get_post_meta($coupon_id, '_ci7k_external_id', true);

        if (empty($external_id)) {
            return false;
        }

        $imported_posts = get_posts(array(
            'post_type' => 'imported_coupon',
            'meta_key' => '_ci7k_external_id',
            'meta_value' => $external_id,
            'posts_per_page' => 1,
            'post_status' => 'any'
        ));

        if (empty($imported_posts)) {
            return false;
        }

        $imported_id = $imported_posts[0]->ID;
        $image_url = get_post_meta($imported_id, '_ci7k_product_image_url', true);

        if (empty($image_url)) {
            return false;
        }

        // Validar e corrigir URL da imagem
        if (!filter_var($image_url, FILTER_VALIDATE_URL)) {
            $this->logger->log('error', sprintf('URL de imagem inválido: %s', $image_url));
            return false;
        }

        // Adicionar extensão .jpg se a URL não tiver extensão de imagem
        if (!preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $image_url)) {
            $this->logger->log('debug', sprintf('URL sem extensão detectada: %s', $image_url));
            
            // Tentar detectar o tipo de imagem fazendo uma requisição HEAD
            $response = wp_remote_head($image_url, array('timeout' => 10));
            
            if (!is_wp_error($response)) {
                $content_type = wp_remote_retrieve_header($response, 'content-type');
                
                // Mapear content-type para extensão
                $extension_map = array(
                    'image/jpeg' => '.jpg',
                    'image/jpg' => '.jpg',
                    'image/png' => '.png',
                    'image/gif' => '.gif',
                    'image/webp' => '.webp'
                );
                
                if (isset($extension_map[$content_type])) {
                    $image_url .= $extension_map[$content_type];
                    $this->logger->log('info', sprintf('Extensão adicionada baseada no content-type (%s): %s', $content_type, $image_url));
                } else {
                    // Se não conseguir detectar, adiciona .jpg como padrão
                    $image_url .= '.jpg';
                    $this->logger->log('info', sprintf('Extensão .jpg adicionada como padrão: %s', $image_url));
                }
            } else {
                // Se falhar a requisição HEAD, adiciona .jpg como padrão
                $image_url .= '.jpg';
                $this->logger->log('info', sprintf('Extensão .jpg adicionada (HEAD request falhou): %s', $image_url));
            }
        }

        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $attachment_id = media_sideload_image($image_url, $coupon_id, null, 'id');

        if (is_wp_error($attachment_id)) {
            $this->logger->log('error', sprintf('Erro ao fazer upload da imagem: %s', $attachment_id->get_error_message()));
            return false;
        }

        set_post_thumbnail($coupon_id, $attachment_id);
        $this->logger->log('success', sprintf('Imagem aplicada ao cupom publicado ID %d (Attachment ID: %d)', $coupon_id, $attachment_id));

        return true;
    }
}