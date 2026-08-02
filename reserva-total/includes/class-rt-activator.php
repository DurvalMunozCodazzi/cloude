<?php
class RT_Activator {

    public static function activate() {
        self::create_tables();
        self::seed_initial_data();
        self::write_app_config();
        self::create_app_page();
        // Token permanente para "Entrar como administrador" (bypass del login de la app)
        if (!get_option('rt_entry_token')) {
            update_option('rt_entry_token', bin2hex(random_bytes(24)));
        }
        if (!wp_next_scheduled('rt_cron_reminders')) {
            wp_schedule_event(time(), 'hourly', 'rt_cron_reminders');
        }
        flush_rewrite_rules();
    }

    public static function deactivate() {
        wp_clear_scheduled_hook('rt_cron_reminders');
        flush_rewrite_rules();
    }

    private static function create_tables() {
        global $wpdb;
        $charset = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        $wpdb->query("CREATE TABLE IF NOT EXISTS `rt_users` (
            `id`         INT NOT NULL AUTO_INCREMENT,
            `username`   VARCHAR(80)  NOT NULL,
            `name`       VARCHAR(200) NOT NULL,
            `email`      VARCHAR(200) DEFAULT NULL,
            `password`   VARCHAR(255) NOT NULL,
            `role`       ENUM('admin','member') DEFAULT 'member',
            `active`     TINYINT DEFAULT 1,
            `color`      VARCHAR(20)  DEFAULT '#0d9488',
            `photo`      VARCHAR(255) DEFAULT NULL,
            `phone`      VARCHAR(30)  DEFAULT NULL,
            `notes`      TEXT         DEFAULT NULL,
            `last_login` DATETIME     DEFAULT NULL,
            `reset_token`   VARCHAR(64) DEFAULT NULL,
            `reset_expires` DATETIME    DEFAULT NULL,
            `created_at` DATETIME     DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `username` (`username`)
        ) $charset");

        $wpdb->query("CREATE TABLE IF NOT EXISTS `rt_sessions` (
            `id`         INT NOT NULL AUTO_INCREMENT,
            `user_id`    INT NOT NULL,
            `token`      VARCHAR(64) NOT NULL,
            `expires_at` DATETIME NOT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `token` (`token`)
        ) $charset");

        $wpdb->query("CREATE TABLE IF NOT EXISTS `resources` (
            `id`             INT NOT NULL AUTO_INCREMENT,
            `name`           VARCHAR(200) NOT NULL,
            `description`    TEXT         DEFAULT NULL,
            `type`           VARCHAR(50)  DEFAULT 'room',
            `price_per_day`  DECIMAL(10,2) DEFAULT 0,
            `price_per_hour` DECIMAL(10,2) DEFAULT NULL,
            `capacity`       INT DEFAULT 1,
            `color`          VARCHAR(20)  DEFAULT '#0d9488',
            `photo`          TEXT         DEFAULT NULL,
            `amenities`      TEXT         DEFAULT NULL,
            `active`         TINYINT DEFAULT 1,
            `position`       INT DEFAULT 0,
            `created_at`     DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at`     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) $charset");

        $wpdb->query("CREATE TABLE IF NOT EXISTS `reservations` (
            `id`             INT NOT NULL AUTO_INCREMENT,
            `resource_id`    INT NOT NULL,
            `client_id`      INT          DEFAULT NULL,
            `guest_name`     VARCHAR(200) NOT NULL,
            `guest_email`    VARCHAR(200) DEFAULT NULL,
            `guest_phone`    VARCHAR(50)  DEFAULT NULL,
            `guest_doc`      VARCHAR(50)  DEFAULT NULL,
            `check_in`       DATETIME NOT NULL,
            `check_out`      DATETIME NOT NULL,
            `is_hourly`      TINYINT DEFAULT 0,
            `adults`         INT DEFAULT 1,
            `children`       INT DEFAULT 0,
            `total_price`    DECIMAL(10,2) DEFAULT 0,
            `status`         VARCHAR(20) DEFAULT 'confirmed',
            `payment_method` VARCHAR(50)  DEFAULT 'cash',
            `payment_id`     VARCHAR(200) DEFAULT NULL,
            `notes`          TEXT         DEFAULT NULL,
            `internal_notes` TEXT         DEFAULT NULL,
            `created_by`     INT          DEFAULT NULL,
            `created_at`     DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at`     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `resource_id` (`resource_id`),
            KEY `client_id`   (`client_id`),
            KEY `check_in`    (`check_in`),
            KEY `status`      (`status`)
        ) $charset");

        $wpdb->query("CREATE TABLE IF NOT EXISTS `clients` (
            `id`         INT NOT NULL AUTO_INCREMENT,
            `first_name` VARCHAR(120) NOT NULL,
            `last_name`  VARCHAR(120) DEFAULT '',
            `dni`        VARCHAR(30)  DEFAULT '',
            `address`    VARCHAR(300) DEFAULT '',
            `email`      VARCHAR(200) DEFAULT '',
            `phone`      VARCHAR(50)  DEFAULT '',
            `notes`      TEXT         DEFAULT NULL,
            `active`     TINYINT DEFAULT 1,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `dni`   (`dni`),
            KEY `email` (`email`),
            KEY `phone` (`phone`)
        ) $charset");

        $wpdb->query("CREATE TABLE IF NOT EXISTS `client_companions` (
            `id`           INT NOT NULL AUTO_INCREMENT,
            `client_id`    INT NOT NULL,
            `first_name`   VARCHAR(120) NOT NULL,
            `last_name`    VARCHAR(120) DEFAULT '',
            `dni`          VARCHAR(30)  DEFAULT '',
            `relationship` VARCHAR(80)  DEFAULT '',
            `created_at`   DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `client_id` (`client_id`)
        ) $charset");

        $wpdb->query("CREATE TABLE IF NOT EXISTS `reservation_guests` (
            `reservation_id` INT NOT NULL,
            `companion_id`   INT NOT NULL,
            PRIMARY KEY (`reservation_id`,`companion_id`)
        ) $charset");

        $wpdb->query("CREATE TABLE IF NOT EXISTS `rt_settings` (
            `id`         INT NOT NULL AUTO_INCREMENT,
            `meta_key`   VARCHAR(100) NOT NULL,
            `meta_value` LONGTEXT DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `meta_key` (`meta_key`)
        ) $charset");

        $wpdb->query("CREATE TABLE IF NOT EXISTS `reservation_extras` (
            `id`             INT NOT NULL AUTO_INCREMENT,
            `reservation_id` INT NOT NULL,
            `name`           VARCHAR(200) NOT NULL,
            `price`          DECIMAL(10,2) DEFAULT 0,
            `qty`            INT DEFAULT 1,
            `created_at`     DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `reservation_id` (`reservation_id`)
        ) $charset");

        $wpdb->query("CREATE TABLE IF NOT EXISTS `extras_catalog` (
            `id`          INT NOT NULL AUTO_INCREMENT,
            `name`        VARCHAR(200) NOT NULL,
            `description` VARCHAR(500) DEFAULT NULL,
            `price`       DECIMAL(10,2) DEFAULT 0,
            `sku`         VARCHAR(50)  DEFAULT '',
            `stock`       INT          DEFAULT NULL,
            `category`    VARCHAR(100) DEFAULT 'General',
            `icon`        VARCHAR(50)  DEFAULT 'fa-star',
            `active`      TINYINT DEFAULT 1,
            `position`    INT DEFAULT 0,
            `created_at`  DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) $charset");

        $wpdb->query("CREATE TABLE IF NOT EXISTS `reservation_payments` (
            `id`             INT NOT NULL AUTO_INCREMENT,
            `reservation_id` INT NOT NULL,
            `amount`         DECIMAL(10,2) NOT NULL DEFAULT 0,
            `method`         VARCHAR(50)  DEFAULT 'cash',
            `payment_date`   DATE         DEFAULT NULL,
            `notes`          VARCHAR(500) DEFAULT NULL,
            `created_by`     INT          DEFAULT NULL,
            `created_at`     DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `reservation_id` (`reservation_id`)
        ) $charset");

        $wpdb->query("CREATE TABLE IF NOT EXISTS `rt_seasonal_rates` (
            `id`             INT NOT NULL AUTO_INCREMENT,
            `resource_id`    INT          DEFAULT NULL,
            `name`           VARCHAR(200) NOT NULL,
            `date_start`     DATE NOT NULL,
            `date_end`       DATE NOT NULL,
            `price_per_day`  DECIMAL(10,2) DEFAULT 0,
            `price_per_hour` DECIMAL(10,2) DEFAULT NULL,
            `created_at`     DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `resource_id` (`resource_id`),
            KEY `date_start`  (`date_start`)
        ) $charset");

        $wpdb->query("CREATE TABLE IF NOT EXISTS `rt_blocked_dates` (
            `id`          INT NOT NULL AUTO_INCREMENT,
            `resource_id` INT NOT NULL,
            `date_start`  DATETIME NOT NULL,
            `date_end`    DATETIME NOT NULL,
            `reason`      VARCHAR(300) DEFAULT '',
            `source`      VARCHAR(100) DEFAULT 'manual',
            `created_by`  INT          DEFAULT NULL,
            `created_at`  DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `resource_id` (`resource_id`),
            KEY `source`      (`source`)
        ) $charset");

        $wpdb->query("CREATE TABLE IF NOT EXISTS `rt_ical_imports` (
            `id`             INT NOT NULL AUTO_INCREMENT,
            `resource_id`    INT NOT NULL,
            `name`           VARCHAR(200) DEFAULT '',
            `url`            TEXT NOT NULL,
            `last_synced_at` DATETIME DEFAULT NULL,
            `last_status`    VARCHAR(300) DEFAULT NULL,
            `created_at`     DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `resource_id` (`resource_id`)
        ) $charset");

        $wpdb->query("CREATE TABLE IF NOT EXISTS `rt_long_stay_discounts` (
            `id`           INT NOT NULL AUTO_INCREMENT,
            `min_nights`   INT NOT NULL,
            `discount_pct` DECIMAL(5,2) NOT NULL DEFAULT 0,
            `created_at`   DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `min_nights` (`min_nights`)
        ) $charset");

        $wpdb->query("CREATE TABLE IF NOT EXISTS `resource_photos` (
            `id`          INT NOT NULL AUTO_INCREMENT,
            `resource_id` INT NOT NULL,
            `filename`    VARCHAR(255) NOT NULL,
            `caption`     VARCHAR(200) DEFAULT '',
            `position`    INT DEFAULT 0,
            `created_at`  DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `resource_id` (`resource_id`)
        ) $charset");

        $wpdb->query("CREATE TABLE IF NOT EXISTS `rt_checkin_submissions` (
            `id`             INT NOT NULL AUTO_INCREMENT,
            `reservation_id` INT NOT NULL,
            `dni_photo_path` VARCHAR(255) DEFAULT NULL,
            `signature_name` VARCHAR(200) DEFAULT '',
            `accepted_terms` TINYINT DEFAULT 0,
            `submitted_at`   DATETIME DEFAULT NULL,
            `ip_address`     VARCHAR(64) DEFAULT '',
            PRIMARY KEY (`id`),
            UNIQUE KEY `reservation_id` (`reservation_id`)
        ) $charset");

        self::migrate_extras_catalog();
        self::migrate_users();
        self::migrate_reservations();
        self::migrate_resources();
    }

    // client_id, para instalaciones de reservations creadas antes del ABM
    // de clientes.
    private static function migrate_reservations() {
        global $wpdb;
        $cols = array_column($wpdb->get_results("SHOW COLUMNS FROM `reservations`", ARRAY_A), 'Field');
        if (!in_array('client_id', $cols)) {
            $wpdb->query("ALTER TABLE `reservations` ADD COLUMN `client_id` INT DEFAULT NULL, ADD KEY `client_id` (`client_id`)");
        }
        if (!in_array('checkin_reminder_sent', $cols)) {
            $wpdb->query("ALTER TABLE `reservations` ADD COLUMN `checkin_reminder_sent` TINYINT DEFAULT 0");
        }
        if (!in_array('overdue_reminder_sent', $cols)) {
            $wpdb->query("ALTER TABLE `reservations` ADD COLUMN `overdue_reminder_sent` TINYINT DEFAULT 0");
        }
        if (!in_array('housekeeping_flagged', $cols)) {
            $wpdb->query("ALTER TABLE `reservations` ADD COLUMN `housekeeping_flagged` TINYINT DEFAULT 0");
        }
        if (!in_array('checkin_token', $cols)) {
            $wpdb->query("ALTER TABLE `reservations` ADD COLUMN `checkin_token` VARCHAR(64) DEFAULT NULL, ADD KEY `checkin_token` (`checkin_token`)");
        }
        if (!in_array('guest_address', $cols)) {
            $wpdb->query("ALTER TABLE `reservations` ADD COLUMN `guest_address` VARCHAR(300) DEFAULT ''");
        }
        if (!in_array('wapp_reminder_sent', $cols)) {
            $wpdb->query("ALTER TABLE `reservations` ADD COLUMN `wapp_reminder_sent` TINYINT DEFAULT 0");
        }
        if (!in_array('booking_source', $cols)) {
            $wpdb->query("ALTER TABLE `reservations` ADD COLUMN `booking_source` VARCHAR(20) DEFAULT 'manual'");
        }
        if (!in_array('mp_preference_id', $cols)) {
            $wpdb->query("ALTER TABLE `reservations` ADD COLUMN `mp_preference_id` VARCHAR(100) DEFAULT NULL");
        }
    }

    // Token público (calendario de disponibilidad + feed iCal) + estado de
    // limpieza, para instalaciones de resources creadas antes de que
    // existiera esta funcionalidad.
    private static function migrate_resources() {
        global $wpdb;
        $cols = array_column($wpdb->get_results("SHOW COLUMNS FROM `resources`", ARRAY_A), 'Field');
        if (!in_array('public_token', $cols)) {
            $wpdb->query("ALTER TABLE `resources` ADD COLUMN `public_token` VARCHAR(64) DEFAULT NULL, ADD KEY `public_token` (`public_token`)");
        }
        if (!in_array('housekeeping_status', $cols)) {
            $wpdb->query("ALTER TABLE `resources` ADD COLUMN `housekeeping_status` VARCHAR(20) DEFAULT 'listo'");
        }
    }

    // Columnas de recuperación de contraseña, para instalaciones de rt_users
    // creadas antes de que existiera este mecanismo.
    private static function migrate_users() {
        global $wpdb;
        $cols = array_column($wpdb->get_results("SHOW COLUMNS FROM `rt_users`", ARRAY_A), 'Field');
        if (!in_array('reset_token', $cols)) {
            $wpdb->query("ALTER TABLE `rt_users` ADD COLUMN `reset_token` VARCHAR(64) DEFAULT NULL");
        }
        if (!in_array('reset_expires', $cols)) {
            $wpdb->query("ALTER TABLE `rt_users` ADD COLUMN `reset_expires` DATETIME DEFAULT NULL");
        }
    }

    // MySQL 5.x-compatible ADD COLUMN: solo altera si la columna todavía no existe.
    // Necesario para instalaciones ya existentes de extras_catalog (creada antes
    // de que sku/stock formaran parte del CREATE TABLE).
    private static function migrate_extras_catalog() {
        global $wpdb;
        $cols = array_column($wpdb->get_results("SHOW COLUMNS FROM `extras_catalog`", ARRAY_A), 'Field');
        if (!in_array('sku', $cols)) {
            $wpdb->query("ALTER TABLE `extras_catalog` ADD COLUMN `sku` VARCHAR(50) DEFAULT '' AFTER `price`");
        }
        if (!in_array('stock', $cols)) {
            $wpdb->query("ALTER TABLE `extras_catalog` ADD COLUMN `stock` INT DEFAULT NULL AFTER `sku`");
        }
    }

    private static function seed_initial_data() {
        global $wpdb;

        // Admin por defecto si no existe
        $exists = $wpdb->get_var("SELECT COUNT(*) FROM rt_users WHERE role='admin'");
        if (!$exists) {
            $hash = password_hash('admin123', PASSWORD_DEFAULT);
            $wpdb->insert('rt_users', [
                'username' => 'admin',
                'name'     => 'Administrador',
                'email'    => get_option('admin_email'),
                'password' => $hash,
                'role'     => 'admin',
                'color'    => '#0d9488',
            ]);
        }
    }

    private static function write_app_config() {
        global $wpdb;
        $file = RT_APP_DIR . 'rt-config.php';
        // No pisar un secreto de cron ya generado
        $existing_secret = null;
        if (file_exists($file)) {
            $prev = file_get_contents($file);
            if (preg_match("/define\('RT_CRON_SECRET',\s*'([^']*)'\)/", $prev, $m)) {
                $existing_secret = $m[1];
            }
        }
        $secret = $existing_secret ?: bin2hex(random_bytes(16));
        if (!get_option('rt_cron_secret')) {
            update_option('rt_cron_secret', $secret);
        }
        self::write_config_content($secret);
    }

    // Regenera rt-config.php con las credenciales de DB + estado actual de licencia.
    // Se llama al activar el plugin y cada vez que se guarda una clave de licencia.
    public static function regenerate_app_config() {
        $secret = get_option('rt_cron_secret', '') ?: bin2hex(random_bytes(16));
        update_option('rt_cron_secret', $secret);
        self::write_config_content($secret);
    }

    private static function write_config_content($secret) {
        $host = DB_HOST;
        $db   = DB_NAME;
        // Use WordPress DB for now — admin can reconfigure via rt-setup.php
        $siteUrl       = get_site_url() . '/wp-content/plugins/reserva-total/app/';
        $uploadUrl     = $siteUrl . 'uploads/';
        $uploadDir     = RT_APP_DIR . 'uploads/';
        $license_key   = get_option('rt_license_key', '');
        $license_srv   = get_option('rt_license_server_url', RT_License::SERVER);

        $c  = "<?php\n// Reserva Total — generado automáticamente\n// " . date('Y-m-d H:i:s') . "\n\n";
        $c .= "define('RT_DB_HOST',    " . var_export($host,      true) . ");\n";
        $c .= "define('RT_DB_NAME',    " . var_export($db,        true) . ");\n";
        $c .= "define('RT_DB_USER',    " . var_export(DB_USER,    true) . ");\n";
        $c .= "define('RT_DB_PASS',    " . var_export(DB_PASSWORD,true) . ");\n";
        $c .= "define('RT_DB_CHARSET', 'utf8mb4');\n";
        $c .= "define('RT_SITE_URL',   " . var_export($siteUrl,   true) . ");\n";
        $c .= "define('RT_UPLOAD_URL', " . var_export($uploadUrl, true) . ");\n";
        $c .= "define('RT_UPLOAD_DIR', " . var_export($uploadDir, true) . ");\n";
        $c .= "define('RT_CRON_SECRET'," . var_export($secret,    true) . ");\n";
        $c .= "define('RT_SESSION_HOURS', 24);\n";
        $c .= "define('RT_LICENSE_KEY',    " . var_export($license_key, true) . ");\n";
        $c .= "define('RT_LICENSE_SERVER', " . var_export($license_srv, true) . ");\n";

        $file   = RT_APP_DIR . 'rt-config.php';
        $result = @file_put_contents($file, $c);
        if ($result === false) {
            add_action('admin_notices', function() use ($file) {
                echo '<div class="notice notice-error"><p><strong>Reserva Total:</strong> no se pudo escribir <code>' . esc_html($file) . '</code>. '
                   . 'Revisá los permisos de escritura de esa carpeta en el hosting — sin ese archivo, la app no puede conectarse a la base de datos.</p></div>';
            });
        }
    }

    private static function create_app_page() {
        $slug = 'reserva-total-app';
        if (get_page_by_path($slug)) return;
        wp_insert_post([
            'post_title'   => 'Reserva Total',
            'post_name'    => $slug,
            'post_content' => '',
            'post_status'  => 'publish',
            'post_type'    => 'page',
        ]);
    }
}
