<?php
/**
 * Plugin Name:  Reserva Total
 * Plugin URI:   https://reservatotal.com.ar
 * Description:  Sistema de reservas para hoteles, cabañas, vehículos y herramientas.
 * Version:      2.4.0
 * Author:       Durval Muñoz Codazzi
 * Author URI:   https://websobreruedas.ar
 * License:      Proprietary
 * Text Domain:  reserva-total
 */

if (!defined('ABSPATH')) exit;

define('RT_VERSION',    '2.4.0');
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
