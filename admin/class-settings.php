<?php

namespace CouponImporter\Admin;

if (!defined('ABSPATH')) {
    exit;
}

class Settings {

    public function __construct() {
        add_action('admin_init', array($this, 'register_settings'));
        add_action('wp_ajax_ci7k_test_ai_connection', array($this, 'ajax_test_ai_connection'));
    }

    public function register_settings() {
        register_setting('ci7k_settings_group', 'ci7k_settings', array($this, 'sanitize_settings'));
    }

    public function sanitize_settings($input) {
        $sanitized = array();

        if (isset($input['ai_provider'])) {
            $sanitized['ai_provider'] = sanitize_text_field($input['ai_provider']);
        }

        // Sanitizar múltiplas API keys OpenAI
        if (isset($input['openai_api_keys']) && is_array($input['openai_api_keys'])) {
            $sanitized['openai_api_keys'] = array();
            foreach ($input['openai_api_keys'] as $key) {
                $key = sanitize_text_field($key);
                if (!empty($key)) {
                    $sanitized['openai_api_keys'][] = $key;
                }
            }
        }

        if (isset($input['openai_model'])) {
            $sanitized['openai_model'] = sanitize_text_field($input['openai_model']);
        }

        // Sanitizar múltiplas API keys Gemini
        if (isset($input['gemini_api_keys']) && is_array($input['gemini_api_keys'])) {
            $sanitized['gemini_api_keys'] = array();
            foreach ($input['gemini_api_keys'] as $key) {
                $key = sanitize_text_field($key);
                if (!empty($key)) {
                    $sanitized['gemini_api_keys'][] = $key;
                }
            }
        }

        if (isset($input['gemini_model'])) {
            $sanitized['gemini_model'] = sanitize_text_field($input['gemini_model']);
        }

        // Intervalo de rotação de APIs
        if (isset($input['api_rotation_interval'])) {
            $sanitized['api_rotation_interval'] = max(1, intval($input['api_rotation_interval']));
        }

        // Prompts de IA
        if (isset($input['ai_title_prompt'])) {
            $sanitized['ai_title_prompt'] = wp_kses_post($input['ai_title_prompt']);
        }

        if (isset($input['ai_description_prompt'])) {
            $sanitized['ai_description_prompt'] = wp_kses_post($input['ai_description_prompt']);
        }

        $sanitized['auto_publish'] = isset($input['auto_publish']) ? 1 : 0;
        $sanitized['require_approval'] = isset($input['require_approval']) ? 1 : 0;
        $sanitized['ai_rewrite_enabled'] = isset($input['ai_rewrite_enabled']) ? 1 : 0;
        $sanitized['delete_on_publish'] = isset($input['delete_on_publish']) ? 1 : 0;
        $sanitized['logs_enabled'] = isset($input['logs_enabled']) ? 1 : 0;

        return $sanitized;
    }

    public function render() {
        if (isset($_POST['ci7k_settings_submit'])) {
            check_admin_referer('ci7k_settings_nonce');

            $settings = array();

            if (isset($_POST['ai_provider'])) {
                $settings['ai_provider'] = sanitize_text_field($_POST['ai_provider']);
            }

            // Processar múltiplas API keys OpenAI
            if (isset($_POST['openai_api_keys']) && is_array($_POST['openai_api_keys'])) {
                $settings['openai_api_keys'] = array();
                foreach ($_POST['openai_api_keys'] as $key) {
                    $key = sanitize_text_field($key);
                    if (!empty($key)) {
                        $settings['openai_api_keys'][] = $key;
                    }
                }
            }

            if (isset($_POST['openai_model'])) {
                $settings['openai_model'] = sanitize_text_field($_POST['openai_model']);
            }

            // Processar múltiplas API keys Gemini
            if (isset($_POST['gemini_api_keys']) && is_array($_POST['gemini_api_keys'])) {
                $settings['gemini_api_keys'] = array();
                foreach ($_POST['gemini_api_keys'] as $key) {
                    $key = sanitize_text_field($key);
                    if (!empty($key)) {
                        $settings['gemini_api_keys'][] = $key;
                    }
                }
            }

            if (isset($_POST['gemini_model'])) {
                $settings['gemini_model'] = sanitize_text_field($_POST['gemini_model']);
            }

            // Intervalo de rotação
            if (isset($_POST['api_rotation_interval'])) {
                $settings['api_rotation_interval'] = max(1, intval($_POST['api_rotation_interval']));
            }

            // Prompts
            if (isset($_POST['ai_title_prompt'])) {
                $settings['ai_title_prompt'] = wp_kses_post($_POST['ai_title_prompt']);
            }

            if (isset($_POST['ai_description_prompt'])) {
                $settings['ai_description_prompt'] = wp_kses_post($_POST['ai_description_prompt']);
            }

            // Prompts específicos OpenAI
            if (isset($_POST['openai_title_prompt'])) {
                $settings['openai_title_prompt'] = wp_kses_post($_POST['openai_title_prompt']);
            }

            if (isset($_POST['openai_description_prompt'])) {
                $settings['openai_description_prompt'] = wp_kses_post($_POST['openai_description_prompt']);
            }

            // Prompts específicos Gemini
            if (isset($_POST['gemini_title_prompt'])) {
                $settings['gemini_title_prompt'] = wp_kses_post($_POST['gemini_title_prompt']);
            }

            if (isset($_POST['gemini_description_prompt'])) {
                $settings['gemini_description_prompt'] = wp_kses_post($_POST['gemini_description_prompt']);
            }

            $settings['auto_publish'] = isset($_POST['auto_publish']) ? 1 : 0;
            $settings['require_approval'] = isset($_POST['require_approval']) ? 1 : 0;
            $settings['ai_rewrite_enabled'] = isset($_POST['ai_rewrite_enabled']) ? 1 : 0;
            $settings['delete_on_publish'] = isset($_POST['delete_on_publish']) ? 1 : 0;
            $settings['logs_enabled'] = isset($_POST['logs_enabled']) ? 1 : 0;
            $settings['auto_publish_cron_interval'] = isset($_POST['auto_publish_cron_interval']) ? sanitize_text_field($_POST['auto_publish_cron_interval']) : 'hourly';
            $settings['auto_publish_limit'] = isset($_POST['auto_publish_limit']) ? intval($_POST['auto_publish_limit']) : 10;

            update_option('ci7k_settings', $settings);

            echo '<div class="notice notice-success"><p>' . __('Configurações salvas com sucesso!', '7k-coupons-importer') . '</p></div>';
        }

        $settings = get_option('ci7k_settings', array());

        require_once CI7K_PLUGIN_DIR . 'admin/views/settings.php';
    }

    public function ajax_test_ai_connection() {
        check_ajax_referer('ci7k_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permissão negada', '7k-coupons-importer')));
        }

        $provider = isset($_POST['provider']) ? sanitize_text_field($_POST['provider']) : '';

        if (empty($provider)) {
            wp_send_json_error(array('message' => __('Provedor não especificado', '7k-coupons-importer')));
        }

        $core = \CouponImporter\Core::get_instance();
        $ai = $core->get_ai_rewriter();

        $result = $ai->test_connection($provider);

        if ($result) {
            wp_send_json_success(array('message' => __('Conexão estabelecida com sucesso!', '7k-coupons-importer')));
        } else {
            wp_send_json_error(array('message' => __('Falha ao conectar com a API', '7k-coupons-importer')));
        }
    }
}

?>