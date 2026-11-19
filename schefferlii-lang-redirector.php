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

add_action('admin_enqueue_scripts', function($hook_suffix) {
    $stylesheets = array(
        'settings_page_slr-mappings' => 'admin-mappings',
        'settings_page_slr-diagnostics' => 'admin-diagnostics',
    );

    if (!isset($stylesheets[$hook_suffix])) {
        return;
    }

    $stylesheet = $stylesheets[$hook_suffix];

    wp_enqueue_style(
        'slr-' . $stylesheet,
        plugins_url('assets/css/' . $stylesheet . '.css', __FILE__),
        array(),
        SLR_VERSION
    );
});
