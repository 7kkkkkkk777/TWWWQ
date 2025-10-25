<?php

namespace CouponImporter;

if (!defined('ABSPATH')) {
    exit;
}

class Mapper {

    private $logger;

    public function __construct() {
        $this->logger = new Logger();
    }

    public function approve_and_publish($imported_coupon_id) {
        $imported_post = get_post($imported_coupon_id);

        if (!$imported_post || $imported_post->post_type !== 'imported_coupon') {
            return new \WP_Error('invalid_coupon', __('Cupom importado não encontrado', '7k-coupons-importer'));
        }

        update_post_meta($imported_coupon_id, '_ci7k_status', 'approved');

        try {
            $coupon_id = $this->publish_coupon($imported_coupon_id);
            return $coupon_id;
        } catch (\Exception $e) {
            return new \WP_Error('publish_error', $e->getMessage());
        }
    }

    public function publish_coupon($imported_coupon_id) {
        $imported_post = get_post($imported_coupon_id);

        if (!$imported_post || $imported_post->post_type !== 'imported_coupon') {
            throw new \Exception(__('Cupom importado não encontrado', '7k-coupons-importer'));
        }

        $status = get_post_meta($imported_coupon_id, '_ci7k_status', true);
        if ($status !== 'approved' && $status !== 'pending') {
            throw new \Exception(__('Apenas cupons aprovados ou pendentes podem ser publicados', '7k-coupons-importer'));
        }

        $external_id = get_post_meta($imported_coupon_id, '_ci7k_external_id', true);

        if (empty($external_id)) {
            throw new \Exception(__('ID externo não encontrado', '7k-coupons-importer'));
        }

        $existing_published = $this->find_published_coupon($external_id);
        if ($existing_published) {
            throw new \Exception(sprintf(__('Cupom já publicado (ID: %d)', '7k-coupons-importer'), $existing_published));
        }

        $coupon_type = get_post_meta($imported_coupon_id, '_ci7k_coupon_type', true);
        $coupon_type = $coupon_type ? intval($coupon_type) : 3;

        $provider = get_post_meta($imported_coupon_id, '_ci7k_provider', true);
        $advertiser_name = get_post_meta($imported_coupon_id, '_ci7k_advertiser', true);
        $categories = get_post_meta($imported_coupon_id, '_ci7k_categories', true);

        $mapped_data = $this->apply_mappings($provider, $advertiser_name, $categories);
        $mapped_advertiser = $mapped_data['advertiser'];
        $mapped_categories = $mapped_data['categories'];

        $store_term = $this->get_or_create_store($mapped_advertiser);

        $category_terms = array();
        if (is_array($mapped_categories)) {
            $this->logger->log('debug', sprintf('Processando %d categorias mapeadas: %s',
                count($mapped_categories),
                implode(', ', $mapped_categories)
            ));

            foreach ($mapped_categories as $cat_name) {
                $cat_term = $this->get_or_create_category($cat_name);
                if ($cat_term) {
                    $category_terms[] = $cat_term;
                    $this->logger->log('debug', sprintf('Term ID %d criado/encontrado para categoria "%s"',
                        $cat_term,
                        $cat_name
                    ));
                } else {
                    $this->logger->log('warning', sprintf('Falha ao criar/encontrar categoria "%s"', $cat_name));
                }
            }

            $this->logger->log('info', sprintf('Total de %d term IDs coletados: %s',
                count($category_terms),
                implode(', ', $category_terms)
            ));
        }

        // Sanitizar título e conteúdo
        $title = wp_strip_all_tags($imported_post->post_title);
        $title = html_entity_decode($title, ENT_QUOTES, 'UTF-8');
        $title = sanitize_text_field($title);

        $content = wp_strip_all_tags($imported_post->post_content);
        $content = html_entity_decode($content, ENT_QUOTES, 'UTF-8');
        $content = sanitize_textarea_field($content);

        $coupon_data = array(
            'post_type' => 'coupon',
            'post_title' => $title,
            'post_content' => $content,
            'post_status' => 'publish',
            'post_author' => get_current_user_id() ? get_current_user_id() : 1
        );

        $coupon_id = wp_insert_post($coupon_data);

        if (is_wp_error($coupon_id)) {
            throw new \Exception($coupon_id->get_error_message());
        }

        // Sanitizar todos os meta fields
        $code = get_post_meta($imported_coupon_id, '_ci7k_code', true);
        $expiration = get_post_meta($imported_coupon_id, '_ci7k_expiration', true);
        $deeplink = get_post_meta($imported_coupon_id, '_ci7k_deeplink', true);
        $link = get_post_meta($imported_coupon_id, '_ci7k_link', true);
        $is_exclusive = get_post_meta($imported_coupon_id, '_ci7k_is_exclusive', true);

        // Sanitizar código do cupom
        if ($coupon_type == 1 && !empty($code)) {
            $code = wp_strip_all_tags($code);
            $code = html_entity_decode($code, ENT_QUOTES, 'UTF-8');
            $code = sanitize_text_field($code);
            update_post_meta($coupon_id, 'coupon_code', $code);
        }

        // Sanitizar e validar URL para ofertas
        if ($coupon_type == 3 && !empty($link)) {
            $link = wp_strip_all_tags($link);
            $link = html_entity_decode($link, ENT_QUOTES, 'UTF-8');
            $link = esc_url_raw($link);
            if (filter_var($link, FILTER_VALIDATE_URL)) {
                update_post_meta($coupon_id, 'coupon_url', $link);
            }
        }

        // Sanitizar e validar data de expiração
        if (!empty($expiration)) {
            $expiration = wp_strip_all_tags($expiration);
            $expiration = html_entity_decode($expiration, ENT_QUOTES, 'UTF-8');
            $expire_timestamp = strtotime($expiration);
            if ($expire_timestamp && $expire_timestamp > time()) {
                update_post_meta($coupon_id, 'expire', $expire_timestamp);
            }
        }

        // Sanitizar e validar deeplink
        if (!empty($deeplink)) {
            $deeplink = wp_strip_all_tags($deeplink);
            $deeplink = html_entity_decode($deeplink, ENT_QUOTES, 'UTF-8');
            $deeplink = esc_url_raw($deeplink);
            if (filter_var($deeplink, FILTER_VALIDATE_URL)) {
                update_post_meta($coupon_id, 'coupon_affiliate', $deeplink);
                update_post_meta($coupon_id, 'coupon_spec_link', $deeplink);
            }
        }

        // Garantir que os valores sejam inteiros
        update_post_meta($coupon_id, 'ctype', intval($coupon_type));
        update_post_meta($coupon_id, 'exclusive', $is_exclusive ? 1 : 0);

        // Meta fields de controle
        update_post_meta($coupon_id, '_ci7k_external_id', sanitize_text_field($external_id));
        update_post_meta($coupon_id, '_ci7k_source', 'imported');
        update_post_meta($coupon_id, '_ci7k_published_at', current_time('mysql'));

        // Associar taxonomias
        if ($store_term) {
            wp_set_object_terms($coupon_id, $store_term, 'coupon-store', false);
        }

        if (!empty($category_terms)) {
            // Log para debug
            $this->logger->log('debug', sprintf('Associando %d categorias ao cupom %d: %s',
                count($category_terms),
                $coupon_id,
                implode(', ', $category_terms)
            ));

            // O terceiro parâmetro FALSE indica que os termos devem ser ADICIONADOS, não substituídos
            $result = wp_set_object_terms($coupon_id, $category_terms, 'coupon-category', false);

            if (is_wp_error($result)) {
                $this->logger->log('error', sprintf('Erro ao associar categorias: %s', $result->get_error_message()));
            } else {
                $this->logger->log('info', sprintf('Categorias associadas com sucesso ao cupom %d', $coupon_id));
            }
        }

        // Salvar na tabela do Couponis com dados sanitizados
        $this->save_to_couponis_table($coupon_id, array(
            'expire' => $expiration,
            'ctype' => intval($coupon_type),
            'exclusive' => $is_exclusive ? 1 : 0
        ));

        // Atualizar status do cupom importado
        update_post_meta($imported_coupon_id, '_ci7k_status', 'published');
        update_post_meta($imported_coupon_id, '_ci7k_published_coupon_id', $coupon_id);
        update_post_meta($imported_coupon_id, '_ci7k_published_at', current_time('mysql'));

        $this->logger->log('publish', sprintf(__('Cupom publicado: %s (imported_id: %d, coupon_id: %d)', '7k-coupons-importer'), $title, $imported_coupon_id, $coupon_id));

        return $coupon_id;
    }

    private function find_published_coupon($external_id) {
        $args = array(
            'post_type' => 'coupon',
            'meta_key' => '_ci7k_external_id',
            'meta_value' => $external_id,
            'posts_per_page' => 1,
            'post_status' => 'any',
            'fields' => 'ids'
        );

        $posts = get_posts($args);

        return !empty($posts) ? $posts[0] : false;
    }

    private function get_or_create_store($store_name) {
        if (empty($store_name)) {
            return null;
        }

        $term = term_exists($store_name, 'coupon-store');

        if ($term) {
            return intval($term['term_id']);
        }

        $new_term = wp_insert_term($store_name, 'coupon-store');

        if (is_wp_error($new_term)) {
            $this->logger->log('error', sprintf(__('Erro ao criar loja: %s', '7k-coupons-importer'), $new_term->get_error_message()));
            return null;
        }

        return $new_term['term_id'];
    }

    private function get_or_create_category($category_name) {
        if (empty($category_name)) {
            return null;
        }

        // Verificar se é uma hierarquia (ex: "Pai > Filho" ou "Pai/Filho")
        $hierarchy = array();
        if (strpos($category_name, '>') !== false) {
            $hierarchy = array_map('trim', explode('>', $category_name));
        } elseif (strpos($category_name, '/') !== false) {
            $hierarchy = array_map('trim', explode('/', $category_name));
        }

        // Se for hierarquia, processar pai e filho
        if (!empty($hierarchy) && count($hierarchy) > 1) {
            $parent_id = 0;
            $last_term_id = null;

            foreach ($hierarchy as $level_name) {
                // Verificar se termo existe neste nível
                $term = term_exists($level_name, 'coupon-category', $parent_id);

                if ($term) {
                    $last_term_id = intval($term['term_id']);
                } else {
                    // Criar novo termo com pai
                    $new_term = wp_insert_term($level_name, 'coupon-category', array(
                        'parent' => $parent_id
                    ));

                    if (is_wp_error($new_term)) {
                        $this->logger->log('error', sprintf(__('Erro ao criar categoria "%s": %s', '7k-coupons-importer'),
                            $level_name,
                            $new_term->get_error_message()
                        ));
                        return null;
                    }

                    $last_term_id = $new_term['term_id'];
                    $this->logger->log('debug', sprintf('Categoria "%s" criada com ID %d (parent: %d)',
                        $level_name,
                        $last_term_id,
                        $parent_id
                    ));
                }

                // Este termo será o pai do próximo nível
                $parent_id = $last_term_id;
            }

            return $last_term_id;
        }

        // Categoria simples (sem hierarquia)
        $term = term_exists($category_name, 'coupon-category');

        if ($term) {
            return intval($term['term_id']);
        }

        $new_term = wp_insert_term($category_name, 'coupon-category');

        if (is_wp_error($new_term)) {
            $this->logger->log('error', sprintf(__('Erro ao criar categoria: %s', '7k-coupons-importer'), $new_term->get_error_message()));
            return null;
        }

        return $new_term['term_id'];
    }

    private function save_to_couponis_table($coupon_id, $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'couponis_coupon_data';

        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");

        if (!$table_exists) {
            $this->logger->log('warning', __('Tabela wp_couponis_coupon_data não existe', '7k-coupons-importer'));
            return false;
        }

        $expire_timestamp = 0;
        if (!empty($data['expire'])) {
            $timestamp = strtotime($data['expire']);
            if ($timestamp) {
                $expire_timestamp = $timestamp;
            }
        }

        $existing = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE post_id = %d", $coupon_id)
        );

        if ($existing) {
            $wpdb->update(
                $table,
                array(
                    'expire' => $expire_timestamp,
                    'ctype' => $data['ctype'],
                    'exclusive' => $data['exclusive']
                ),
                array('post_id' => $coupon_id),
                array('%d', '%d', '%d'),
                array('%d')
            );
        } else {
            $wpdb->insert(
                $table,
                array(
                    'post_id' => $coupon_id,
                    'expire' => $expire_timestamp,
                    'ctype' => $data['ctype'],
                    'exclusive' => $data['exclusive']
                ),
                array('%d', '%d', '%d', '%d')
            );
        }

        return true;
    }

    public function fix_published_coupon($coupon_id) {
        $post = get_post($coupon_id);

        if (!$post || $post->post_type !== 'coupon') {
            return false;
        }

        $external_id = get_post_meta($coupon_id, '_ci7k_external_id', true);

        if (empty($external_id)) {
            return false;
        }

        $imported = get_posts(array(
            'post_type' => 'imported_coupon',
            'meta_key' => '_ci7k_external_id',
            'meta_value' => $external_id,
            'posts_per_page' => 1,
            'post_status' => 'any'
        ));

        if (empty($imported)) {
            return false;
        }

        $imported_id = $imported[0]->ID;
        $imported_post = $imported[0];

        // Corrigir título e conteúdo se necessário
        $title = wp_strip_all_tags($imported_post->post_title);
        $title = html_entity_decode($title, ENT_QUOTES, 'UTF-8');
        $title = sanitize_text_field($title);

        $content = wp_strip_all_tags($imported_post->post_content);
        $content = html_entity_decode($content, ENT_QUOTES, 'UTF-8');
        $content = sanitize_textarea_field($content);

        // Verificar se título ou conteúdo precisam ser corrigidos
        if ($post->post_title !== $title || $post->post_content !== $content) {
            wp_update_post(array(
                'ID' => $coupon_id,
                'post_title' => $title,
                'post_content' => $content
            ));
        }

        // Obter dados do cupom importado
        $code = get_post_meta($imported_id, '_ci7k_code', true);
        $expiration = get_post_meta($imported_id, '_ci7k_expiration', true);
        $deeplink = get_post_meta($imported_id, '_ci7k_deeplink', true);
        $link = get_post_meta($imported_id, '_ci7k_link', true);
        $coupon_type = get_post_meta($imported_id, '_ci7k_coupon_type', true);
        $is_exclusive = get_post_meta($imported_id, '_ci7k_is_exclusive', true);

        $updated = false;

        // Corrigir código do cupom
        if ($coupon_type == 1 && !empty($code)) {
            $code = wp_strip_all_tags($code);
            $code = html_entity_decode($code, ENT_QUOTES, 'UTF-8');
            $code = sanitize_text_field($code);
            
            $current_code = get_post_meta($coupon_id, 'coupon_code', true);
            if ($current_code !== $code) {
                update_post_meta($coupon_id, 'coupon_code', $code);
                $updated = true;
            }
        }

        // Corrigir URL da oferta
        if ($coupon_type == 3 && !empty($link)) {
            $link = wp_strip_all_tags($link);
            $link = html_entity_decode($link, ENT_QUOTES, 'UTF-8');
            $link = esc_url_raw($link);
            
            if (filter_var($link, FILTER_VALIDATE_URL)) {
                $current_url = get_post_meta($coupon_id, 'coupon_url', true);
                if ($current_url !== $link) {
                    update_post_meta($coupon_id, 'coupon_url', $link);
                    $updated = true;
                }
            }
        }

        // Corrigir data de expiração
        if (!empty($expiration)) {
            $expiration = wp_strip_all_tags($expiration);
            $expiration = html_entity_decode($expiration, ENT_QUOTES, 'UTF-8');
            $expire_timestamp = strtotime($expiration);

            if ($expire_timestamp && $expire_timestamp > time()) {
                $current_expire = get_post_meta($coupon_id, 'expire', true);
                if (intval($current_expire) !== $expire_timestamp) {
                    update_post_meta($coupon_id, 'expire', $expire_timestamp);
                    $updated = true;
                }
            }
        }

        // Corrigir deeplink
        if (!empty($deeplink)) {
            $deeplink = wp_strip_all_tags($deeplink);
            $deeplink = html_entity_decode($deeplink, ENT_QUOTES, 'UTF-8');
            $deeplink = esc_url_raw($deeplink);
            
            if (filter_var($deeplink, FILTER_VALIDATE_URL)) {
                $current_affiliate = get_post_meta($coupon_id, 'coupon_affiliate', true);
                if ($current_affiliate !== $deeplink) {
                    update_post_meta($coupon_id, 'coupon_affiliate', $deeplink);
                    update_post_meta($coupon_id, 'coupon_spec_link', $deeplink);
                    $updated = true;
                }
            }
        }

        // Corrigir tipo do cupom
        $current_ctype = get_post_meta($coupon_id, 'ctype', true);
        $coupon_type = intval($coupon_type) ?: 3;
        if (intval($current_ctype) !== $coupon_type) {
            update_post_meta($coupon_id, 'ctype', $coupon_type);
            $updated = true;
        }

        // Corrigir exclusividade
        $current_exclusive = get_post_meta($coupon_id, 'exclusive', true);
        $exclusive_value = $is_exclusive ? 1 : 0;
        if (intval($current_exclusive) !== $exclusive_value) {
            update_post_meta($coupon_id, 'exclusive', $exclusive_value);
            $updated = true;
        }

        if ($updated) {
            $this->save_to_couponis_table($coupon_id, array(
                'expire' => $expiration,
                'ctype' => $coupon_type,
                'exclusive' => $exclusive_value
            ));

            $this->logger->log('fix', sprintf(__('Cupom corrigido: ID %d', '7k-coupons-importer'), $coupon_id));
        }

        return $updated;
    }

    // Função para corrigir todos os cupons publicados com problemas
    public function fix_all_published_coupons() {
        $args = array(
            'post_type' => 'coupon',
            'meta_key' => '_ci7k_source',
            'meta_value' => 'imported',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'fields' => 'ids'
        );

        $coupon_ids = get_posts($args);
        $fixed_count = 0;

        foreach ($coupon_ids as $coupon_id) {
            if ($this->fix_published_coupon($coupon_id)) {
                $fixed_count++;
            }
        }

        $this->logger->log('bulk_fix', sprintf(__('Correção em massa: %d cupons corrigidos', '7k-coupons-importer'), $fixed_count));

        return $fixed_count;
    }

    public function get_store_mapping($provider, $original_store) {
        $mappings = $this->get_provider_mappings($provider, 'stores');

        if (isset($mappings[$original_store])) {
            $this->logger->log('debug', sprintf('Store mapping found: %s -> %s', $original_store, $mappings[$original_store]));
            return $mappings[$original_store];
        }

        return $original_store;
    }

    public function get_category_mapping($provider, $original_category) {
        $mappings = $this->get_provider_mappings($provider, 'categories');

        if (isset($mappings[$original_category])) {
            $this->logger->log('debug', sprintf('Category mapping found: %s -> %s', $original_category, $mappings[$original_category]));
            return $mappings[$original_category];
        }

        return $original_category;
    }

    public function save_store_mapping($provider, $original_store, $mapped_store) {
        $mappings = $this->get_provider_mappings($provider, 'stores');
        // Remover barras invertidas de escape
        $mappings[stripslashes($original_store)] = stripslashes($mapped_store);

        return $this->save_provider_mappings($provider, 'stores', $mappings);
    }

    public function save_category_mapping($provider, $original_category, $mapped_category) {
        $mappings = $this->get_provider_mappings($provider, 'categories');
        // Remover barras invertidas de escape
        $mappings[stripslashes($original_category)] = stripslashes($mapped_category);

        return $this->save_provider_mappings($provider, 'categories', $mappings);
    }

    public function delete_store_mapping($provider, $original_store) {
        $mappings = $this->get_provider_mappings($provider, 'stores');

        if (isset($mappings[$original_store])) {
            unset($mappings[$original_store]);
            return $this->save_provider_mappings($provider, 'stores', $mappings);
        }

        return false;
    }

    public function delete_category_mapping($provider, $original_category) {
        $mappings = $this->get_provider_mappings($provider, 'categories');

        if (isset($mappings[$original_category])) {
            unset($mappings[$original_category]);
            return $this->save_provider_mappings($provider, 'categories', $mappings);
        }

        return false;
    }

    public function get_all_store_mappings($provider) {
        return $this->get_provider_mappings($provider, 'stores');
    }

    public function get_all_category_mappings($provider) {
        return $this->get_provider_mappings($provider, 'categories');
    }

    private function get_provider_mappings($provider, $type) {
        $option_key = sprintf('ci7k_mappings_%s_%s', sanitize_key($provider), sanitize_key($type));
        $mappings = get_option($option_key, array());

        return is_array($mappings) ? $mappings : array();
    }

    private function save_provider_mappings($provider, $type, $mappings) {
        $option_key = sprintf('ci7k_mappings_%s_%s', sanitize_key($provider), sanitize_key($type));
        return update_option($option_key, $mappings);
    }

    public function get_imported_stores($provider) {
        global $wpdb;

        $stores = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT pm.meta_value
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            INNER JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = p.ID AND pm2.meta_key = '_ci7k_provider'
            WHERE p.post_type = 'imported_coupon'
            AND pm.meta_key = '_ci7k_advertiser'
            AND pm2.meta_value = %s
            AND pm.meta_value != ''
            ORDER BY pm.meta_value ASC
        ", $provider));

        return array_unique($stores);
    }

    public function get_imported_categories($provider) {
        global $wpdb;

        $categories = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT pm.meta_value
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            INNER JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = p.ID AND pm2.meta_key = '_ci7k_provider'
            WHERE p.post_type = 'imported_coupon'
            AND pm.meta_key = '_ci7k_categories'
            AND pm2.meta_value = %s
            AND pm.meta_value != ''
        ", $provider));

        $all_categories = array();
        foreach ($categories as $cat_meta) {
            $cat_array = maybe_unserialize($cat_meta);
            if (is_array($cat_array)) {
                $all_categories = array_merge($all_categories, $cat_array);
            } elseif (is_string($cat_array) && !empty($cat_array)) {
                $all_categories[] = $cat_array;
            }
        }

        return array_unique($all_categories);
    }

    public function apply_mappings($provider, $advertiser, $categories = array()) {
        $mapped_advertiser = $this->get_store_mapping($provider, $advertiser);

        $mapped_categories = array();
        if (is_array($categories)) {
            $this->logger->log('debug', sprintf('Mapeando %d categorias para provedor %s: %s',
                count($categories),
                $provider,
                implode(', ', $categories)
            ));

            foreach ($categories as $category) {
                $mapped = $this->get_category_mapping($provider, $category);
                $mapped_categories[] = $mapped;

                $this->logger->log('debug', sprintf('Categoria "%s" mapeada para "%s"', $category, $mapped));
            }

            $this->logger->log('info', sprintf('Total de %d categorias mapeadas: %s',
                count($mapped_categories),
                implode(', ', $mapped_categories)
            ));
        }

        return array(
            'advertiser' => $mapped_advertiser,
            'categories' => $mapped_categories
        );
    }
}