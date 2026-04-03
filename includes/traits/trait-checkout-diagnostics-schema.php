<?php
if (!defined('ABSPATH')) {
    exit;
}

trait Checkout_Diagnostics_Schema_Trait
{
    private static function ensure_database_schema($force = false)
    {
        $stored_version = get_option(self::OPTION_DB_VERSION, '');

        if (!$force && self::VERSION === $stored_version) {
            return;
        }

        self::create_events_table();
        self::create_sessions_table();
        self::rebuild_session_summaries();

        update_option(self::OPTION_DB_VERSION, self::VERSION, false);
    }

    /**
     * Create the checkout diagnostics events table.
     *
     * @return void
     */
    private static function create_events_table()
    {
        global $wpdb;

        $table_name = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL,
            session_key varchar(64) NOT NULL DEFAULT '',
            event_type varchar(64) NOT NULL DEFAULT '',
            order_id bigint(20) unsigned NULL,
            error_code varchar(191) NULL,
            field_key varchar(191) NULL,
            shipping_method varchar(191) NULL,
            payment_method varchar(191) NULL,
            meta longtext NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY event_type (event_type),
            KEY session_key (session_key),
            KEY order_id (order_id)
        ) {$charset_collate};";

        dbDelta($sql);
    }

    /**
     * Create the checkout diagnostics sessions table.
     *
     * @return void
     */
    private static function create_sessions_table()
    {
        global $wpdb;

        $table_name = self::sessions_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            session_key varchar(64) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            last_seen_at datetime NOT NULL,
            first_name varchar(191) NOT NULL DEFAULT '',
            ip_address varchar(45) NOT NULL DEFAULT '',
            device_type varchar(32) NOT NULL DEFAULT '',
            os_name varchar(64) NOT NULL DEFAULT '',
            language varchar(32) NOT NULL DEFAULT '',
            cart_total varchar(64) NOT NULL DEFAULT '',
            cart_items longtext NULL,
            view_count bigint(20) unsigned NOT NULL DEFAULT 0,
            refresh_count bigint(20) unsigned NOT NULL DEFAULT 0,
            place_order_clicks bigint(20) unsigned NOT NULL DEFAULT 0,
            validation_failures bigint(20) unsigned NOT NULL DEFAULT 0,
            validation_errors bigint(20) unsigned NOT NULL DEFAULT 0,
            successful_orders bigint(20) unsigned NOT NULL DEFAULT 0,
            shipping_method varchar(191) NOT NULL DEFAULT '',
            payment_method varchar(191) NOT NULL DEFAULT '',
            last_error_message text NULL,
            status varchar(64) NOT NULL DEFAULT '',
            order_id bigint(20) unsigned NULL,
            meta longtext NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY session_key (session_key),
            KEY created_at (created_at),
            KEY last_seen_at (last_seen_at),
            KEY status (status)
        ) {$charset_collate};";

        dbDelta($sql);
    }
}
