<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class OISCL_Activator {
    public static function activate() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

        // 1. Tablas de m�tricas (Estructura SQL Corregida con screen_res)
        $table_metrics = $wpdb->prefix . 'oiscl_block_metrics';
        $sql_metrics = "CREATE TABLE $table_metrics (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            session_id varchar(32) NOT NULL,
            origin_url varchar(255) NOT NULL,
            context_text varchar(255) NOT NULL,
            anchor_text varchar(255) NOT NULL,
            destination_url varchar(255) NOT NULL,
            time_spent int(11) NOT NULL DEFAULT 0,
            country varchar(100) DEFAULT 'Desconocido',
            city varchar(100) DEFAULT 'Desconocido',
            os varchar(50) DEFAULT 'Desconocido',
            browser varchar(50) DEFAULT 'Desconocido',
            device varchar(50) DEFAULT 'Desconocido',
            traffic_source varchar(255) DEFAULT 'Direct Traffic',
            screen_res varchar(48) NOT NULL DEFAULT 'N/A',
            language varchar(10) DEFAULT 'en',
            is_bot tinyint(1) NOT NULL DEFAULT 1,
            utm_campaign varchar(100) DEFAULT '',
            utm_term varchar(100) DEFAULT '',
            utm_source varchar(100) DEFAULT '',
            utm_medium varchar(100) DEFAULT '',
            clicks int(11) NOT NULL DEFAULT 0,
            user_id bigint(20) NOT NULL DEFAULT 0,
            is_guest tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY session_id (session_id)
        ) $charset_collate;";
        dbDelta( $sql_metrics );

        // 2. Tabla de configuraciones de p�gina
        $table_pages = $wpdb->prefix . 'oiscl_page_settings';
        $sql_pages = "CREATE TABLE $table_pages (
            post_id bigint(20) NOT NULL,
            active_tags text NOT NULL,
            PRIMARY KEY  (post_id)
        ) $charset_collate;";
        dbDelta( $sql_pages );

        // 3. Opciones por defecto
        if ( false === get_option( 'oiscl_settings' ) ) {
            add_option( 'oiscl_settings', array( 'trackpro_enabled' => true, 'target_urls' => array(), 'separator_tags' => array('h2', 'h3', 'section') ) );
        }

        // 4. Gesti�n de Roles y Capacidades
        $admin = get_role('administrator');
        if ($admin) { $admin->add_cap('view_ois_analytics'); $admin->add_cap('manage_ois_marketing'); }
        
        $editor = get_role('editor');
        if ($editor) { $editor->add_cap('view_ois_analytics'); $editor->add_cap('manage_ois_marketing'); }
        
        add_role('ois_client', 'Cliente OIS', array('read' => true, 'view_ois_analytics' => true));
        // 5. Tabla para Guardar Enlaces UTM (Referencias)
        $table_utm = $wpdb->prefix . 'oiscl_utm_references';
        $sql_utm = "CREATE TABLE $table_utm (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            label_name varchar(100) NOT NULL,
            target_url varchar(255) NOT NULL,
            utm_campaign varchar(100) NOT NULL,
            utm_term varchar(100) DEFAULT '',
            utm_source varchar(100) NOT NULL DEFAULT 'google',
            utm_medium varchar(100) NOT NULL DEFAULT 'cpc',
            conv_anchor varchar(120) NOT NULL DEFAULT '',
            spend decimal(12,2) NOT NULL DEFAULT 0.00,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_label_campaign_term (label_name, utm_campaign, utm_term)
        ) $charset_collate;";
        dbDelta( $sql_utm );
        self::maybe_upgrade_metrics_utm_sm_columns();
        self::maybe_upgrade_metrics_screen_res_column();
        self::maybe_upgrade_utm_refs_unique_key();
        self::maybe_upgrade_utm_refs_google_columns();
        self::maybe_upgrade_utm_refs_spend_column();
        require_once __DIR__ . '/class-oiscl-utm-alerts.php';
        OISCL_Utm_Alerts::maybe_schedule();
    }

    /**
     * Ensures screen_res exists and is wide enough (older installs or dbDelta gaps).
     */
    public static function maybe_upgrade_metrics_screen_res_column() {
        global $wpdb;
        $table = $wpdb->prefix . 'oiscl_block_metrics';
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $table !== $exists ) {
            return;
        }
        $has = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'screen_res'" );
        if ( empty( $has ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted prefix.
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `screen_res` varchar(48) NOT NULL DEFAULT 'N/A' AFTER `device`" );
            update_option( 'oiscl_metrics_screen_res_schema', '48', false );
            return;
        }
        if ( '48' === get_option( 'oiscl_metrics_screen_res_schema', '' ) ) {
            return;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( "ALTER TABLE `{$table}` MODIFY COLUMN `screen_res` varchar(48) NOT NULL DEFAULT 'N/A'" );
        update_option( 'oiscl_metrics_screen_res_schema', '48', false );
    }

    /**
     * Adds utm_source / utm_medium to metrics for existing installs (dbDelta does not add columns on old tables).
     */
    public static function maybe_upgrade_metrics_utm_sm_columns() {
        global $wpdb;
        $table = $wpdb->prefix . 'oiscl_block_metrics';
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $table !== $exists ) {
            return;
        }
        $has = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'utm_source'" );
        if ( ! empty( $has ) ) {
            return;
        }
        $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN utm_source varchar(100) NOT NULL DEFAULT '' AFTER utm_term" );
        $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN utm_medium varchar(100) NOT NULL DEFAULT '' AFTER utm_source" );
    }

    /**
     * Unique (label_name, utm_campaign, utm_term) on UTM references for existing installs.
     */
    public static function maybe_upgrade_utm_refs_unique_key() {
        global $wpdb;
        $table = $wpdb->prefix . 'oiscl_utm_references';
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $table !== $exists ) {
            return;
        }
        if ( '1' === get_option( 'oiscl_utm_refs_unique_schema', '' ) ) {
            return;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $has_index = $wpdb->get_results( "SHOW INDEX FROM `{$table}` WHERE Key_name = 'uq_label_campaign_term'" );
        if ( ! empty( $has_index ) ) {
            update_option( 'oiscl_utm_refs_unique_schema', '1', false );
            return;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $dupes = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM (
                SELECT label_name, utm_campaign, utm_term, COUNT(*) AS c
                FROM `{$table}`
                GROUP BY label_name, utm_campaign, utm_term
                HAVING c > 1
            ) AS d"
        );
        if ( $dupes > 0 ) {
            update_option( 'oiscl_utm_refs_unique_pending', '1', false );
            return;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( "ALTER TABLE `{$table}` ADD UNIQUE KEY uq_label_campaign_term (label_name, utm_campaign, utm_term)" );
        update_option( 'oiscl_utm_refs_unique_schema', '1', false );
        delete_option( 'oiscl_utm_refs_unique_pending' );
    }

    /**
     * instance_id column for per-element Click Tracker instances.
     */
    public static function maybe_upgrade_metrics_instance_column() {
        global $wpdb;
        $table = $wpdb->prefix . 'oiscl_block_metrics';
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $table !== $exists ) {
            return;
        }
        if ( '1' === get_option( 'oiscl_metrics_instance_schema', '' ) ) {
            return;
        }
        $has = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'instance_id'" );
        if ( empty( $has ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN instance_id varchar(32) NOT NULL DEFAULT '' AFTER destination_url" );
        }
        update_option( 'oiscl_metrics_instance_schema', '1', false );
    }

    /**
     * utm_source / utm_medium / conv_anchor on saved UTM links (Google Ads ready).
     */
    public static function maybe_upgrade_utm_refs_google_columns() {
        global $wpdb;
        $table = $wpdb->prefix . 'oiscl_utm_references';
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $table !== $exists ) {
            return;
        }
        if ( '1' === get_option( 'oiscl_utm_refs_google_schema', '' ) ) {
            return;
        }
        $cols = array(
            'utm_source'  => "ADD COLUMN utm_source varchar(100) NOT NULL DEFAULT 'google' AFTER utm_term",
            'utm_medium'  => "ADD COLUMN utm_medium varchar(100) NOT NULL DEFAULT 'cpc' AFTER utm_source",
            'conv_anchor' => "ADD COLUMN conv_anchor varchar(120) NOT NULL DEFAULT '' AFTER utm_medium",
        );
        foreach ( $cols as $name => $ddl ) {
            $has = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", $name ) );
            if ( empty( $has ) ) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query( "ALTER TABLE `{$table}` {$ddl}" );
            }
        }
        update_option( 'oiscl_utm_refs_google_schema', '1', false );
    }

    /**
     * Manual ad spend per saved UTM link (CPA / efficiency estimates).
     */
    public static function maybe_upgrade_utm_refs_spend_column() {
        global $wpdb;
        $table = $wpdb->prefix . 'oiscl_utm_references';
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $table !== $exists ) {
            return;
        }
        if ( '1' === get_option( 'oiscl_utm_refs_spend_schema', '' ) ) {
            return;
        }
        $has = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'spend'" );
        if ( empty( $has ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN spend decimal(12,2) NOT NULL DEFAULT 0.00 AFTER conv_anchor" );
        }
        update_option( 'oiscl_utm_refs_spend_schema', '1', false );
    }
}