<?php

namespace CouponImporter;

if (!defined('ABSPATH')) {
    exit;
}

class CPT {

    public function __construct() {
        add_action('init', array($this, 'register_cpt'));
    }

    public function register_cpt() {
        $labels = array(
            'name' => __('Cupons Importados', '7k-coupons-importer'),
            'singular_name' => __('Cupom Importado', '7k-coupons-importer'),
            'menu_name' => __('Cupons Importados', '7k-coupons-importer'),
            'add_new' => __('Adicionar Novo', '7k-coupons-importer'),
            'add_new_item' => __('Adicionar Novo Cupom', '7k-coupons-importer'),
            'edit_item' => __('Editar Cupom', '7k-coupons-importer'),
            'new_item' => __('Novo Cupom', '7k-coupons-importer'),
            'view_item' => __('Ver Cupom', '7k-coupons-importer'),
            'search_items' => __('Buscar Cupons', '7k-coupons-importer'),
            'not_found' => __('Nenhum cupom encontrado', '7k-coupons-importer'),
            'not_found_in_trash' => __('Nenhum cupom na lixeira', '7k-coupons-importer')
        );

        $args = array(
            'labels' => $labels,
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'capability_type' => 'post',
            'hierarchical' => false,
            'supports' => array('title', 'editor'),
            'has_archive' => false,
            'rewrite' => false,
            'query_var' => false
        );

        register_post_type('imported_coupon', $args);
    }
}
