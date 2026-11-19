<?php
/**
 * Plugin Name: WP Language Redirector
 * Description: Mappings por idioma + fallback con WP Multilang. Intercepta el click en #wpm-languages y redirige a la URL definida.
 * Version: 1.0.0
 * Author: Marco Rubino
 * Text Domain: schefferlii-lang-redirector
 */

if (!defined('ABSPATH')) exit;

define('SLR_PATH', plugin_dir_path(__FILE__));
define('SLR_FILE', __FILE__);
define('SLR_BASENAME', plugin_basename(__FILE__));
define('SLR_VERSION', '1.0.0');

require_once SLR_PATH . 'includes/class-slr-redirector.php';

register_activation_hook(__FILE__, array('SLR_Redirector', 'activate'));
register_deactivation_hook(__FILE__, array('SLR_Redirector', 'deactivate'));

add_action('plugins_loaded', function() {
    $redirector = SLR_Redirector::instance();

    // The mapping screen uses a dedicated stylesheet instead of inline admin CSS.
    remove_action('admin_head', array($redirector, 'admin_styles'));
});

add_action('admin_enqueue_scripts', function($hook_suffix) {
    if ('settings_page_slr-mappings' !== $hook_suffix) {
        return;
    }

    wp_enqueue_style(
        'slr-admin-mappings',
        plugins_url('assets/css/admin-mappings.css', __FILE__),
        array(),
        SLR_VERSION
    );
});
