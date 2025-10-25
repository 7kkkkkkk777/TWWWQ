<?php

namespace CouponImporter;

if (!defined('ABSPATH')) {
    exit;
}

class TaxonomyHelper {

    public static function get_stores() {
        $stores = get_terms(array(
            'taxonomy' => 'coupon-store',
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC'
        ));

        if (is_wp_error($stores)) {
            return array();
        }

        return $stores;
    }

    public static function get_categories() {
        $categories = get_terms(array(
            'taxonomy' => 'coupon-category',
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC'
        ));

        if (is_wp_error($categories)) {
            return array();
        }

        // Organizar categorias hierarquicamente
        return self::sort_categories_hierarchically($categories);
    }

    /**
     * Ordena categorias hierarquicamente: mães em ordem alfabética, seguidas de suas filhas
     */
    private static function sort_categories_hierarchically($categories) {
        $sorted = array();
        $parent_categories = array();
        $child_categories = array();

        // Separar categorias mães e filhas
        foreach ($categories as $category) {
            if ($category->parent == 0) {
                $parent_categories[] = $category;
            } else {
                if (!isset($child_categories[$category->parent])) {
                    $child_categories[$category->parent] = array();
                }
                $child_categories[$category->parent][] = $category;
            }
        }

        // Ordenar categorias mães alfabeticamente
        usort($parent_categories, function($a, $b) {
            return strcmp($a->name, $b->name);
        });

        // Ordenar categorias filhas alfabeticamente dentro de cada grupo
        foreach ($child_categories as $parent_id => $children) {
            usort($child_categories[$parent_id], function($a, $b) {
                return strcmp($a->name, $b->name);
            });
        }

        // Montar array final: mãe seguida de suas filhas
        foreach ($parent_categories as $parent) {
            $sorted[] = $parent;
            
            // Adicionar filhas desta mãe
            if (isset($child_categories[$parent->term_id])) {
                foreach ($child_categories[$parent->term_id] as $child) {
                    $sorted[] = $child;
                    
                    // Adicionar netas (se houver)
                    if (isset($child_categories[$child->term_id])) {
                        foreach ($child_categories[$child->term_id] as $grandchild) {
                            $sorted[] = $grandchild;
                        }
                    }
                }
            }
        }

        return $sorted;
    }

    public static function get_stores_for_select() {
        $stores = self::get_stores();
        $options = array();

        foreach ($stores as $store) {
            $options[$store->slug] = $store->name;
        }

        return $options;
    }

    public static function get_categories_for_select() {
        $categories = self::get_categories();
        $options = array();

        foreach ($categories as $category) {
            $indent = str_repeat('&nbsp;&nbsp;', count(get_ancestors($category->term_id, 'coupon-category')));
            $options[$category->slug] = $indent . $category->name;
        }

        return $options;
    }
}