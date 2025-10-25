<?php

namespace CouponImporter;

if (!defined('ABSPATH')) {
    exit;
}

class AIRewriter {

    private $logger;

    public function __construct() {
        $this->logger = new Logger();
    }

    public function rewrite_title($original_title) {
        $settings = get_option('ci7k_settings', array());
        $ai_provider = isset($settings['ai_provider']) ? $settings['ai_provider'] : 'none';
        
        if ($ai_provider === 'none') {
            return $original_title;
        }

        $prompt = isset($settings['ai_title_prompt']) ? $settings['ai_title_prompt'] : '';
        if (empty($prompt)) {
            return $original_title;
        }

        $full_prompt = str_replace('{title}', $original_title, $prompt);

        try {
            if ($ai_provider === 'openai') {
                return $this->rewrite_with_openai($full_prompt, 'title');
            } elseif ($ai_provider === 'gemini') {
                return $this->rewrite_with_gemini($full_prompt, 'title');
            }
        } catch (\Exception $e) {
            $this->logger->log('error', 'Erro ao reescrever título: ' . $e->getMessage());
            return $original_title;
        }

        return $original_title;
    }

    public function rewrite_description($original_description) {
        $settings = get_option('ci7k_settings', array());
        $ai_provider = isset($settings['ai_provider']) ? $settings['ai_provider'] : 'none';
        
        if ($ai_provider === 'none') {
            return $original_description;
        }

        $prompt = isset($settings['ai_description_prompt']) ? $settings['ai_description_prompt'] : '';
        if (empty($prompt)) {
            return $original_description;
        }

        $full_prompt = str_replace('{description}', $original_description, $prompt);

        try {
            if ($ai_provider === 'openai') {
                return $this->rewrite_with_openai($full_prompt, 'description');
            } elseif ($ai_provider === 'gemini') {
                return $this->rewrite_with_gemini($full_prompt, 'description');
            }
        } catch (\Exception $e) {
            $this->logger->log('error', 'Erro ao reescrever descrição: ' . $e->getMessage());
            return $original_description;
        }

        return $original_description;
    }

    /**
     * Obtém a próxima API key disponível com sistema de revezamento
     */
    private function get_next_api_key($provider) {
        $settings = get_option('ci7k_settings', array());
        $api_keys_key = $provider . '_api_keys';
        $api_keys = isset($settings[$api_keys_key]) ? $settings[$api_keys_key] : array();
        
        // Se não houver múltiplas keys, tentar a key única antiga
        if (empty($api_keys)) {
            $single_key = isset($settings[$provider . '_api_key']) ? $settings[$provider . '_api_key'] : '';
            if (!empty($single_key)) {
                return $single_key;
            }
            throw new \Exception("Nenhuma API key configurada para {$provider}");
        }

        // Filtrar keys vazias
        $api_keys = array_filter($api_keys, function($key) {
            return !empty(trim($key));
        });

        if (empty($api_keys)) {
            throw new \Exception("Nenhuma API key válida configurada para {$provider}");
        }

        // Obter contador de uso
        $usage_counter = get_option("ci7k_{$provider}_usage_counter", 0);
        $rotation_interval = isset($settings['api_rotation_interval']) ? intval($settings['api_rotation_interval']) : 10;

        // Calcular índice da API key baseado no contador
        $key_index = floor($usage_counter / $rotation_interval) % count($api_keys);
        
        // Incrementar contador
        update_option("ci7k_{$provider}_usage_counter", $usage_counter + 1);

        $selected_key = array_values($api_keys)[$key_index];
        
        $this->logger->log('debug', sprintf('AI: Usando %s API key #%d (contador: %d, intervalo: %d)', 
            $provider, $key_index + 1, $usage_counter, $rotation_interval));

        return $selected_key;
    }

    private function rewrite_with_openai($prompt, $type) {
        $api_key = $this->get_next_api_key('openai');
        $settings = get_option('ci7k_settings', array());
        $model = isset($settings['openai_model']) ? $settings['openai_model'] : 'gpt-3.5-turbo';

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array(
                'model' => $model,
                'messages' => array(
                    array('role' => 'user', 'content' => $prompt)
                ),
                'max_tokens' => $type === 'title' ? 100 : 500,
                'temperature' => 0.7
            )),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            throw new \Exception($response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            throw new \Exception($body['error']['message']);
        }

        if (!isset($body['choices'][0]['message']['content'])) {
            throw new \Exception('Resposta inválida da API OpenAI');
        }

        return trim($body['choices'][0]['message']['content']);
    }

    private function rewrite_with_gemini($prompt, $type) {
        $api_key = $this->get_next_api_key('gemini');
        $settings = get_option('ci7k_settings', array());
        $model = isset($settings['gemini_model']) ? $settings['gemini_model'] : 'gemini-pro';

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$api_key}";

        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array(
                'contents' => array(
                    array(
                        'parts' => array(
                            array('text' => $prompt)
                        )
                    )
                )
            )),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            throw new \Exception($response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            throw new \Exception($body['error']['message']);
        }

        if (!isset($body['candidates'][0]['content']['parts'][0]['text'])) {
            throw new \Exception('Resposta inválida da API Gemini');
        }

        return trim($body['candidates'][0]['content']['parts'][0]['text']);
    }

    public function test_connection($provider = null) {
        $settings = get_option('ci7k_settings', array());

        if (!$provider) {
            $provider = isset($settings['ai_provider']) ? $settings['ai_provider'] : 'openai';
        }

        try {
            $test_text = "Teste de conexão com API de IA";

            if ($provider === 'openai') {
                $result = $this->rewrite_with_openai($test_text, 'title');
            } elseif ($provider === 'gemini') {
                $result = $this->rewrite_with_gemini($test_text, 'title');
            } else {
                return false;
            }

            return !empty($result);

        } catch (\Exception $e) {
            $this->logger->log('error', 'Teste de conexão falhou: ' . $e->getMessage());
            return false;
        }
    }
}