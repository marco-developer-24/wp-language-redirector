<?php
/**
 * Plugin Name: Schefferlii Language Redirector
 * Description: Mappings por idioma + fallback con WP Multilang. Intercepta el click en #wpm-languages y redirige a la URL definida.
 * Version: 1.0.0
 * Author: Schefferlii Dev
 * Text Domain: schefferlii-lang-redirector
 */

if (!defined('ABSPATH')) exit;

define('SLR_PATH', plugin_dir_path(__FILE__));
define('SLR_FILE', __FILE__);
define('SLR_BASENAME', plugin_basename(__FILE__));
define('SLR_VERSION', '1.0.0');

require_once SLR_PATH . 'includes/class-slr-redirector.php';

register_activation_hook( __FILE__, array('SLR_Redirector', 'activate') );
register_deactivation_hook( __FILE__, array('SLR_Redirector', 'deactivate') );

add_action('plugins_loaded', function() {
    SLR_Redirector::instance();
});
