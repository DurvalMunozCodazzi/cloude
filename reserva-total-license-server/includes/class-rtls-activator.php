<?php
defined('ABSPATH') || exit;

class RTLS_Activator {

    public static function activate(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $t_lic   = $wpdb->prefix . 'rtls_licenses';
        $t_log   = $wpdb->prefix . 'rtls_verify_log';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE {$t_lic} (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            license_key   VARCHAR(64)  NOT NULL,
            customer_name VARCHAR(120) NOT NULL DEFAULT '',
            customer_email VARCHAR(120) NOT NULL DEFAULT '',
            domain        VARCHAR(255) NOT NULL DEFAULT '',
            plan          VARCHAR(32)  NOT NULL DEFAULT 'starter',
            status        ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active',
            max_resources SMALLINT UNSIGNED NOT NULL DEFAULT 3,
            expires_at    DATE         NULL DEFAULT NULL,
            notes         TEXT         NULL,
            created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY   uq_key (license_key),
            KEY          idx_domain (domain),
            KEY          idx_status (status)
        ) {$charset};");

        $t_req = $wpdb->prefix . 'rtls_requests';
        dbDelta("CREATE TABLE {$t_req} (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            nombre         VARCHAR(120) NOT NULL DEFAULT '',
            email          VARCHAR(120) NOT NULL DEFAULT '',
            telefono       VARCHAR(30)  NOT NULL DEFAULT '',
            dominio        VARCHAR(255) NOT NULL DEFAULT '',
            status         ENUM('pending','sent','rejected') NOT NULL DEFAULT 'pending',
            created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_email (email),
            KEY idx_status (status)
        ) {$charset};");

        dbDelta("CREATE TABLE {$t_log} (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            license_key VARCHAR(64)  NOT NULL,
            domain      VARCHAR(255) NOT NULL DEFAULT '',
            result      ENUM('valid','invalid','expired','suspended','not_found') NOT NULL,
            ip          VARCHAR(45)  NOT NULL DEFAULT '',
            verified_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_key (license_key),
            KEY idx_date (verified_at)
        ) {$charset};");

        update_option('rtls_version', RTLS_VERSION);
    }

    public static function deactivate(): void {}
}
