<?php
/**
 * Plugin Name: Reserva Total License Server
 * Plugin URI:  https://reservatotal.ar
 * Description: Servidor de licencias para Reserva Total. Gestiona claves, dominios y planes.
 * Version:     1.0.0
 * Author:      Web Sobre Ruedas
 * License:     Proprietary
 * Text Domain: reserva-total-license-server
 */

defined('ABSPATH') || exit;

define('RTLS_VERSION',    '1.0.0');
define('RTLS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RTLS_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once RTLS_PLUGIN_DIR . 'includes/class-rtls-activator.php';
require_once RTLS_PLUGIN_DIR . 'includes/class-rtls-license.php';
require_once RTLS_PLUGIN_DIR . 'includes/class-rtls-admin.php';
require_once RTLS_PLUGIN_DIR . 'includes/class-rtls-api.php';

register_activation_hook(__FILE__,   ['RTLS_Activator', 'activate']);
register_deactivation_hook(__FILE__, ['RTLS_Activator', 'deactivate']);

add_action('plugins_loaded', function () {
    if (is_admin()) new RTLS_Admin();
    $api = new RTLS_Api();
    add_action('rest_api_init', [$api, 'register_routes']);
});
