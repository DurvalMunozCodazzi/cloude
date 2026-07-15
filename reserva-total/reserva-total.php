<?php
/**
 * Plugin Name:  Reserva Total
 * Plugin URI:   https://reservatotal.com.ar
 * Description:  Sistema de reservas para hoteles, cabañas, vehículos y herramientas.
 * Version:      2.5.0
 * Author:       Durval Muñoz Codazzi
 * Author URI:   https://websobreruedas.ar
 * License:      Proprietary
 * Text Domain:  reserva-total
 */

if (!defined('ABSPATH')) exit;

define('RT_VERSION',    '2.5.0');
define('RT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('RT_APP_DIR',    RT_PLUGIN_DIR . 'app/');
define('RT_APP_URL',    RT_PLUGIN_URL . 'app/');

require_once RT_PLUGIN_DIR . 'includes/class-rt-activator.php';
require_once RT_PLUGIN_DIR . 'includes/class-rt-admin.php';
require_once RT_PLUGIN_DIR . 'includes/class-rt-license.php';

register_activation_hook(__FILE__,   ['RT_Activator', 'activate']);
register_deactivation_hook(__FILE__, ['RT_Activator', 'deactivate']);

if (is_admin()) {
    new RT_Admin();
}

add_action('wp_ajax_rt_save_license',         'rt_ajax_save_license');
add_action('wp_ajax_rt_check_license_status', 'rt_ajax_check_license_status');

// ── AJAX: save license key ───────────────────────────────────────────────────
function rt_ajax_save_license() {
    check_ajax_referer('rt_admin_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
    $key = sanitize_text_field($_POST['license_key'] ?? '');
    update_option('rt_license_key', $key);
    // También escribe app/rt-config.php para que la API pueda leerlo sin WP
    RT_Activator::regenerate_app_config();
    // Borra la cache de licencia para que se re-verifique con la nueva clave
    RT_License::clear_cache($key);
    wp_send_json_success(['message' => 'Licencia guardada']);
}

// ── AJAX: verify license status ──────────────────────────────────────────────
function rt_ajax_check_license_status() {
    check_ajax_referer('rt_admin_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
    $key    = get_option('rt_license_key', '');
    $result = RT_License::verify($key, $_SERVER['HTTP_HOST'] ?? '');
    wp_send_json($result);
}
