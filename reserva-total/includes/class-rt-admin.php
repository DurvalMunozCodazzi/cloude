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
        add_submenu_page('reserva-total', 'Reserva Total', 'Inicio', 'manage_options', 'reserva-total', [$this, 'render_page']);
        add_submenu_page('reserva-total', 'Licencia', 'Licencia', 'manage_options', 'reserva-total-license', [$this, 'render_license_page']);
    }

    public function enqueue($hook) {
        if (strpos($hook, 'reserva-total') === false) return;
        wp_enqueue_style('rt-admin',  RT_PLUGIN_URL . 'admin/admin.css', [], RT_VERSION);
        wp_enqueue_script('rt-admin', RT_PLUGIN_URL . 'admin/admin.js',  [], RT_VERSION, true);
        wp_localize_script('rt-admin', 'RT_ADMIN', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('rt_admin_nonce'),
        ]);
    }

    public function render_page() {
        $app_url = RT_APP_URL . 'index.html';
        echo '<div class="rt-admin-wrap">';
        echo '<h1>🗓 Reserva Total <span class="rt-version">v' . RT_VERSION . '</span></h1>';
        echo '<p>La aplicación corre de forma independiente.</p>';
        echo '<a href="' . esc_url($app_url) . '" target="_blank" class="button button-primary button-hero">Abrir Reserva Total →</a>';
        echo '</div>';
    }

    public function render_license_page() {
        $key = get_option('rt_license_key', '');
        ?>
        <div class="rt-admin-wrap">
            <h1>🔑 Licencia de Reserva Total</h1>
            <div class="rt-license-box">
                <label for="rt-license-key"><strong>Clave de licencia</strong></label>
                <input type="text" id="rt-license-key" value="<?= esc_attr($key) ?>" placeholder="RT-XXXX-XXXX-XXXX-XXXX" class="rt-license-input">
                <div class="rt-license-actions">
                    <button type="button" id="rt-license-save" class="button button-primary">Guardar</button>
                    <button type="button" id="rt-license-check" class="button">Verificar estado</button>
                </div>
                <div id="rt-license-status" class="rt-license-status"></div>
            </div>
        </div>
        <?php
    }
}
