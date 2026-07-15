<?php
defined('ABSPATH') || exit;

class RTLS_Admin {

    public function __construct() {
        add_action('admin_menu',            [$this, 'add_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('admin_post_rtls_create', [$this, 'handle_create']);
        add_action('admin_post_rtls_update', [$this, 'handle_update']);
        add_action('admin_post_rtls_delete', [$this, 'handle_delete']);
        add_action('admin_post_rtls_toggle', [$this, 'handle_toggle']);
        add_action('admin_init', [$this, 'maybe_migrate']);
    }

    public function maybe_migrate(): void {
        global $wpdb;
        $t_lic = $wpdb->prefix . 'rtls_licenses';
        $lic_cols = array_column($wpdb->get_results("SHOW COLUMNS FROM `{$t_lic}`", ARRAY_A), 'Field');
        if (!in_array('max_resources', $lic_cols))
            $wpdb->query("ALTER TABLE `{$t_lic}` ADD COLUMN `max_resources` SMALLINT UNSIGNED NOT NULL DEFAULT 3");
        if (!in_array('notes', $lic_cols))
            $wpdb->query("ALTER TABLE `{$t_lic}` ADD COLUMN `notes` TEXT NULL");
        if (!in_array('updated_at', $lic_cols))
            $wpdb->query("ALTER TABLE `{$t_lic}` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
        $wpdb->query("ALTER TABLE `{$t_lic}` MODIFY COLUMN `expires_at` DATE NULL DEFAULT NULL");
    }

    public function add_menu(): void {
        add_menu_page(
            'RT Licenses',
            'RT Licenses',
            'manage_options',
            'reserva-total-licenses',
            [$this, 'page_list'],
            'dashicons-calendar-alt',
            59
        );
        add_submenu_page('reserva-total-licenses', 'Todas las Licencias', 'Todas las Licencias', 'manage_options', 'reserva-total-licenses',         [$this, 'page_list']);
        add_submenu_page('reserva-total-licenses', 'Nueva Licencia',      'Nueva Licencia',      'manage_options', 'reserva-total-licenses-new',      [$this, 'page_new']);
        add_submenu_page('reserva-total-licenses', 'Log de Verificaciones','Log',                'manage_options', 'reserva-total-licenses-log',      [$this, 'page_log']);
        add_submenu_page('reserva-total-licenses', 'Configuración',       'Configuración',       'manage_options', 'reserva-total-licenses-settings', [$this, 'page_settings']);
    }

    public function enqueue(string $hook): void {
        if (!str_contains($hook, 'reserva-total-licenses')) return;
        wp_enqueue_style('rtls-admin', RTLS_PLUGIN_URL . 'admin/admin.css', [], RTLS_VERSION);
    }

    public function page_list(): void {
        $search = sanitize_text_field($_GET['s']      ?? '');
        $status = sanitize_text_field($_GET['status'] ?? '');
        $page   = max(1, (int)($_GET['paged'] ?? 1));
        $data   = RTLS_License::get_all(25, $page, $search, $status);
        require RTLS_PLUGIN_DIR . 'admin/views/list.php';
    }

    public function page_new(): void {
        $editing = null;
        if (!empty($_GET['edit'])) {
            $editing = RTLS_License::get_by_key(sanitize_text_field($_GET['edit']));
        }
        require RTLS_PLUGIN_DIR . 'admin/views/form.php';
    }

    public function page_log(): void {
        $key  = sanitize_text_field($_GET['key'] ?? '');
        $logs = RTLS_License::get_log($key, 100);
        require RTLS_PLUGIN_DIR . 'admin/views/log.php';
    }

    public function page_settings(): void {
        if (isset($_POST['rtls_settings_nonce']) && wp_verify_nonce($_POST['rtls_settings_nonce'], 'rtls_settings')) {
            update_option('rtls_callmebot_apikey', sanitize_text_field($_POST['callmebot_apikey'] ?? ''));
            update_option('rtls_callmebot_phone',  sanitize_text_field($_POST['callmebot_phone']  ?? ''));
            if (!empty($_POST['rtls_private_key'])) {
                update_option('rtls_private_key', trim($_POST['rtls_private_key']));
            }
            echo '<div class="notice notice-success"><p>Configuración guardada.</p></div>';
        }
        $callmebot_apikey = get_option('rtls_callmebot_apikey', '');
        $callmebot_phone  = get_option('rtls_callmebot_phone', '');
        $private_key_set  = !empty(get_option('rtls_private_key', ''));
        require RTLS_PLUGIN_DIR . 'admin/views/settings.php';
    }

    public function handle_create(): void {
        check_admin_referer('rtls_create');
        if (!current_user_can('manage_options')) wp_die('Sin permisos');

        $id = RTLS_License::create([
            'customer_name'  => $_POST['customer_name']  ?? '',
            'customer_email' => $_POST['customer_email'] ?? '',
            'domain'         => $_POST['domain']         ?? '',
            'plan'           => $_POST['plan']           ?? 'starter',
            'expires_at'     => !empty($_POST['expires_at']) ? $_POST['expires_at'] : null,
            'notes'          => $_POST['notes']          ?? '',
        ]);

        $redirect = admin_url('admin.php?page=reserva-total-licenses');
        if ($id) {
            wp_redirect($redirect . '&msg=created');
        } else {
            global $wpdb;
            $err = urlencode($wpdb->last_error ?: 'Error desconocido al insertar en la base de datos.');
            wp_redirect($redirect . '&msg=error&dberr=' . $err);
        }
        exit;
    }

    public function handle_update(): void {
        check_admin_referer('rtls_update');
        if (!current_user_can('manage_options')) wp_die('Sin permisos');

        $id = (int)($_POST['id'] ?? 0);
        RTLS_License::update($id, [
            'customer_name'  => $_POST['customer_name']  ?? '',
            'customer_email' => $_POST['customer_email'] ?? '',
            'domain'         => $_POST['domain']         ?? '',
            'plan'           => $_POST['plan']           ?? 'starter',
            'status'         => $_POST['status']         ?? 'active',
            'expires_at'     => !empty($_POST['expires_at']) ? $_POST['expires_at'] : null,
            'notes'          => $_POST['notes']          ?? '',
        ]);

        wp_redirect(admin_url('admin.php?page=reserva-total-licenses&msg=updated'));
        exit;
    }

    public function handle_delete(): void {
        check_admin_referer('rtls_delete');
        if (!current_user_can('manage_options')) wp_die('Sin permisos');

        $id = (int)($_POST['id'] ?? 0);
        RTLS_License::delete($id);
        wp_redirect(admin_url('admin.php?page=reserva-total-licenses&msg=deleted'));
        exit;
    }

    public function handle_toggle(): void {
        check_admin_referer('rtls_toggle');
        if (!current_user_can('manage_options')) wp_die('Sin permisos');

        $id     = (int)($_POST['id'] ?? 0);
        $status = sanitize_text_field($_POST['status'] ?? 'active');
        RTLS_License::update($id, ['status' => $status === 'active' ? 'inactive' : 'active']);
        wp_redirect(admin_url('admin.php?page=reserva-total-licenses&msg=updated'));
        exit;
    }
}
