<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class APB_Activator {

    public static function activate(): void {
        self::create_table();
        self::migrate_table();
        self::set_default_options();
    }

    private static function create_table(): void {
        global $wpdb;

        $table      = $wpdb->prefix . APB_TABLE_JOBS;
        $charset    = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            topic            TEXT NOT NULL,
            keywords         TEXT NULL,
            site_profile     TEXT NULL,
            category_id      BIGINT UNSIGNED NULL,
            status           VARCHAR(32) NOT NULL DEFAULT 'pending',
            generated_title  TEXT NULL,
            generated_excerpt LONGTEXT NULL,
            generated_html   LONGTEXT NULL,
            generated_json   LONGTEXT NULL,
            wp_post_id       BIGINT UNSIGNED NULL,
            error_message    LONGTEXT NULL,
            created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            processed_at     DATETIME NULL,
            PRIMARY KEY  (id),
            KEY idx_status (status),
            KEY idx_created (created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    private static function set_default_options(): void {
        if ( false === get_option( APB_OPTION_KEY ) ) {
            $defaults = array(
                'shared_secret'        => wp_generate_password( 32, false ),
                'default_post_status'  => 'draft',
                'default_post_author'  => '',
                'default_category'     => '',
                'site_tone'            => '',
                'worker_pull_enabled'  => '1',
            );
            add_option( APB_OPTION_KEY, $defaults );
        }
    }

    public static function migrate_table(): void {
        global $wpdb;

        $table = $wpdb->prefix . APB_TABLE_JOBS;

        $col = $wpdb->get_var(
            "SHOW COLUMNS FROM {$table} LIKE 'category_id'"
        );
        if ( ! $col ) {
            $wpdb->query(
                "ALTER TABLE {$table} ADD COLUMN category_id BIGINT UNSIGNED NULL AFTER site_profile"
            );
        }
    }
}
