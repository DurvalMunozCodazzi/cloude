<?php
/**
 * Plugin Name:  Reserva Total
 * Plugin URI:   https://reservatotal.com.ar
 * Description:  Sistema de reservas para hoteles, cabañas, vehículos y herramientas.
 * Version:      2.12.0
 * Author:       Durval Muñoz Codazzi
 * Author URI:   https://websobreruedas.ar
 * License:      Proprietary
 * Text Domain:  reserva-total
 */

if (!defined('ABSPATH')) exit;

define('RT_VERSION',    '2.12.0');
define('RT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('RT_APP_DIR',    RT_PLUGIN_DIR . 'app/');
define('RT_APP_URL',    RT_PLUGIN_URL . 'app/');

require_once RT_PLUGIN_DIR . 'includes/class-rt-activator.php';
require_once RT_PLUGIN_DIR . 'includes/class-rt-admin.php';
require_once RT_PLUGIN_DIR . 'includes/class-rt-license.php';

register_activation_hook(__FILE__,   ['RT_Activator', 'activate']);
register_deactivation_hook(__FILE__, ['RT_Activator', 'deactivate']);

// Autoreparación: WordPress solo dispara register_activation_hook al pasar de
// desactivado a activado — actualizar el plugin reemplazando sus archivos
// (sin desactivar primero) NO vuelve a correrlo, así que app/rt-config.php
// puede quedar sin generarse tras una actualización. Esto lo verifica en
// cada carga de WordPress y lo regenera solo si falta, sin depender de que
// alguien recuerde desactivar/reactivar manualmente.
add_action('plugins_loaded', 'rt_maybe_regenerate_config');
function rt_maybe_regenerate_config() {
    if (!file_exists(RT_APP_DIR . 'rt-config.php')) {
        RT_Activator::regenerate_app_config();
    }
}

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

// ── AJAX: regenerar el token de "Entrar como administrador" ──────────────────
add_action('wp_ajax_rt_regenerate_entry_token', 'rt_ajax_regenerate_entry_token');
function rt_ajax_regenerate_entry_token() {
    check_ajax_referer('rt_admin_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
    $token = bin2hex(random_bytes(24));
    update_option('rt_entry_token', $token);
    wp_send_json_success(['url' => home_url('/?rt_enter=' . $token)]);
}

// ── AJAX: cambiar la contraseña del admin de la app desde WordPress ──────────
add_action('wp_ajax_rt_reset_admin_password', 'rt_ajax_reset_admin_password');
function rt_ajax_reset_admin_password() {
    check_ajax_referer('rt_admin_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

    $pass = trim($_POST['password'] ?? '');
    if (strlen($pass) < 4) wp_send_json_error('La contraseña debe tener al menos 4 caracteres');

    global $wpdb;
    $admin = $wpdb->get_row("SELECT * FROM rt_users WHERE role='admin' AND active=1 ORDER BY id LIMIT 1", ARRAY_A);
    if (!$admin) {
        RT_Activator::activate();
        $admin = $wpdb->get_row("SELECT * FROM rt_users WHERE role='admin' AND active=1 ORDER BY id LIMIT 1", ARRAY_A);
    }
    if (!$admin) wp_send_json_error('No se pudo crear ni encontrar un usuario admin');

    $wpdb->update('rt_users', ['password' => password_hash($pass, PASSWORD_DEFAULT)], ['id' => $admin['id']]);
    wp_send_json_success(['message' => 'Contraseña actualizada', 'username' => $admin['username']]);
}

// ── "Entrar como administrador" — bypass del login de la app usando un token
// secreto generado en la activación. Si no existe ningún usuario admin en
// rt_users (p.ej. base de datos vacía o recién reconectada), lo crea antes
// de abrir sesión. Mismo mecanismo que ya usa Luna Workspace en producción.
add_action('init', 'rt_handle_admin_enter');
function rt_handle_admin_enter() {
    if (!isset($_GET['rt_enter'])) return;

    $stored = get_option('rt_entry_token', '');
    if (!$stored || !hash_equals($stored, (string) $_GET['rt_enter'])) {
        wp_die('Token inválido o vencido. Regenerá el enlace en Reserva Total → Inicio.');
    }

    global $wpdb;

    $admin = $wpdb->get_row("SELECT * FROM rt_users WHERE role='admin' AND active=1 ORDER BY id LIMIT 1", ARRAY_A);
    if (!$admin) {
        // Base de datos sin usuarios (o recién reconectada) — recrear el admin por defecto
        RT_Activator::activate();
        $admin = $wpdb->get_row("SELECT * FROM rt_users WHERE role='admin' AND active=1 ORDER BY id LIMIT 1", ARRAY_A);
    }
    if (!$admin) {
        wp_die('No se pudo crear ni encontrar un usuario admin. Revisá la conexión a la base de datos (Reserva Total → Inicio).');
    }

    $token   = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + 24 * 3600);
    $wpdb->insert('rt_sessions', ['user_id' => $admin['id'], 'token' => $token, 'expires_at' => $expires]);
    $wpdb->update('rt_users', ['last_login' => current_time('mysql')], ['id' => $admin['id']]);

    wp_redirect(RT_APP_URL . 'index.html?rt_bootstrap_token=' . $token);
    exit;
}
