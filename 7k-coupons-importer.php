<?php
/**
 * Plugin Name: 7K Coupons Importer
 * Plugin URI: https://7k.com.br
 * Description: Sistema modular de importação e curadoria de cupons de múltiplos provedores (Rakuten, Awin) com IA integrada
 * Version: 1.0.0
 * Author: 7K Team
 * Author URI: https://7k.com.br
 * Text Domain: 7k-coupons-importer
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CI7K_VERSION', '1.0.0');
define('CI7K_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CI7K_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CI7K_PLUGIN_FILE', __FILE__);

require_once CI7K_PLUGIN_DIR . 'includes/functions.php';
require_once CI7K_PLUGIN_DIR . 'includes/class-core.php';

function ci7k_init() {
    \CouponImporter\Core::get_instance();
}
add_action('plugins_loaded', 'ci7k_init');

register_activation_hook(__FILE__, 'ci7k_activate');
function ci7k_activate() {
    require_once CI7K_PLUGIN_DIR . 'includes/class-installer.php';
    \CouponImporter\Installer::activate();
}

register_deactivation_hook(__FILE__, 'ci7k_deactivate');
function ci7k_deactivate() {
    // Limpar crons gerais
    wp_clear_scheduled_hook('ci7k_import_cron');
    wp_clear_scheduled_hook('ci7k_auto_publish_cron');
    wp_clear_scheduled_hook('ci7k_process_queue');
    
    // Limpar crons dos provedores
    $providers = array('rakuten', 'awin');
    foreach ($providers as $provider) {
        wp_clear_scheduled_hook('ci7k_import_' . $provider . '_cron');
    }
}