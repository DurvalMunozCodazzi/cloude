<?php
class RT_Activator {

    public static function activate() {
        self::create_tables();
        self::seed_initial_data();
        self::write_app_config();
        self::create_app_page();
        flush_rewrite_rules();
    }

    public static function deactivate() {
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
            KEY `check_in`    (`check_in`),
            KEY `status`      (`status`)
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
            `category`    VARCHAR(100) DEFAULT 'General',
            `icon`        VARCHAR(50)  DEFAULT 'fa-star',
            `active`      TINYINT DEFAULT 1,
            `position`    INT DEFAULT 0,
            `created_at`  DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) $charset");
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
        $host = DB_HOST;
        $db   = DB_NAME;
        // Use WordPress DB for now — admin can reconfigure via rt-setup.php
        $siteUrl   = get_site_url() . '/wp-content/plugins/reserva-total/app/';
        $uploadUrl = $siteUrl . 'uploads/';
        $uploadDir = RT_APP_DIR . 'uploads/';
        $secret    = bin2hex(random_bytes(16));

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
        $c .= "define('RT_LICENSE_KEY', '');\n";

        @file_put_contents($file, $c);
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
