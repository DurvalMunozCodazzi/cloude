<?php
class RT_Admin {
    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function add_menu() {
        add_menu_page(
            'Reserva Total', 'Reserva Total',
            'manage_options', 'reserva-total',
            [$this, 'render_page'],
            'dashicons-calendar-alt', 4
        );
    }

    public function enqueue($hook) {
        if (strpos($hook, 'reserva-total') === false) return;
        wp_enqueue_style('rt-admin',  RT_PLUGIN_URL . 'admin/admin.css', [], RT_VERSION);
        wp_enqueue_script('rt-admin', RT_PLUGIN_URL . 'admin/admin.js',  [], RT_VERSION, true);
    }

    public function render_page() {
        $app_url = RT_APP_URL . 'index.html';
        echo '<div class="rt-admin-wrap">';
        echo '<h1>🗓 Reserva Total <span class="rt-version">v' . RT_VERSION . '</span></h1>';
        echo '<p>La aplicación corre de forma independiente.</p>';
        echo '<a href="' . esc_url($app_url) . '" target="_blank" class="button button-primary button-hero">Abrir Reserva Total →</a>';
        echo '</div>';
    }
}
