<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait OISCL_Admin_Core_Trait {

    public function init() {
        add_action( 'wp_ajax_oiscl_get_pulse_data', array( $this, 'ajax_get_pulse_data' ) );
        add_action( 'wp_ajax_oiscl_purge_old_logs', array( $this, 'ajax_purge_old_logs' ) );
        add_action( 'admin_menu', array( $this, 'add_plugin_admin_menus' ) );
        add_action( 'admin_head', array( $this, 'oiscl_addon_admin_menu_styles' ) );
        add_action( 'admin_init', array( $this, 'handle_csv_export' ) );
        add_action( 'wp_ajax_oiscl_save_target_pages', array( $this, 'ajax_save_target_pages' ) );
        add_action( 'wp_ajax_oiscl_scan_page', array( $this, 'ajax_scan_page_html' ) );
        add_action( 'wp_ajax_oiscl_save_page_tags', array( $this, 'ajax_save_page_tags' ) );
        add_action( 'wp_ajax_oiscl_save_automatic_global', array( $this, 'ajax_save_automatic_global' ) );
        add_action( 'wp_ajax_oiscl_scan_page_html', array( $this, 'ajax_scan_page_html' ) );
        add_action( 'wp_ajax_oiscl_apply_rescan_review', array( $this, 'ajax_apply_rescan_review' ) );
        add_action( 'admin_post_oiscl_save_general_settings', array( $this, 'save_general_settings' ) );
        add_action( 'admin_post_oiscl_save_trackpro_settings', array( $this, 'save_trackpro_settings' ) );
        add_action( 'admin_post_oiscl_save_uninstall_pref', array( $this, 'save_uninstall_preference' ) );
        add_action( 'wp_ajax_oiscl_export_data', array( $this, 'ajax_handle_export' ) );
        add_action( 'wp_ajax_oiscl_import_data', array( $this, 'ajax_handle_import' ) );
        add_action( 'wp_ajax_oiscl_full_uninstall_cleanup', array( $this, 'ajax_full_uninstall_cleanup' ) );
        
        add_action( 'wp_ajax_oiscl_v2_inspect_url', array( $this, 'ajax_v2_inspect_url' ) );
        add_action( 'wp_ajax_oiscl_v2_save_settings', array( $this, 'ajax_v2_save_settings' ) );
        add_action( 'wp_ajax_oiscl_v2_get_site_content', array( $this, 'ajax_v2_get_site_content' ) );
        add_action( 'wp_ajax_oiscl_v2_delete_page', array( $this, 'ajax_v2_delete_page' ) );
        add_action( 'wp_ajax_oiscl_v2_get_content_list', array( $this, 'ajax_v2_get_content_list' ) );
        add_action( 'wp_ajax_oiscl_v2_save_page_to_list', array( $this, 'ajax_v2_save_page_to_list' ) );
        
        // ACCIONES CUSTOM DASHBOARDS
        add_action( 'wp_ajax_oiscl_save_custom_dash', array( $this, 'ajax_save_custom_dash' ) );
        add_action( 'wp_ajax_oiscl_delete_custom_dash', array( $this, 'ajax_delete_custom_dash' ) );
        add_action( 'wp_ajax_oiscl_save_template', array( $this, 'ajax_save_template' ) );
        add_action( 'wp_ajax_oiscl_analytics_defer', array( $this, 'ajax_oiscl_analytics_defer' ) );
        add_action( 'wp_ajax_oiscl_utm_raw_log', array( $this, 'ajax_oiscl_utm_raw_log' ) );
        add_action( 'wp_ajax_oiscl_host_health_ping', array( $this, 'ajax_oiscl_host_health_ping' ) );
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        $admin = get_role( 'administrator' );
        if ( $admin ) {
            if ( ! $admin->has_cap( 'view_ois_analytics' ) ) {
                $admin->add_cap( 'view_ois_analytics' );
            }
            if ( ! $admin->has_cap( 'manage_ois_marketing' ) ) {
                $admin->add_cap( 'manage_ois_marketing' );
            }
        }
    }
    
    public function enqueue_admin_assets($hook) {
    // Para que no cargue el CSS en todo WordPress, verificamos que estemos en una página de OIS
    if (strpos($hook, 'oiscl') === false) return;

    $css_file = plugin_dir_path( dirname( __FILE__, 2 ) ) . 'assets/css/oiscl-admin.css';
    $css_ver  = file_exists( $css_file ) ? filemtime( $css_file ) : ( defined( 'OISCL_VERSION' ) ? OISCL_VERSION : '0.73.6' );

    wp_enqueue_style( 'oiscl-admin-style', plugin_dir_url( dirname( __FILE__, 2 ) ) . 'assets/css/oiscl-admin.css', array(), $css_ver );

        // UTM Manager + several report tabs use Chart inline scripts; load before page HTML so `Chart` exists when DOMContentLoaded runs.
        if ( isset( $_GET['page'] ) && 'oiscl-utm-tracker' === $_GET['page'] ) {
            wp_enqueue_script(
                'oiscl-chart-js',
                'https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js',
                array(),
                '3.9.1',
                false
            );
        }
    }

    public function oiscl_addon_admin_menu_styles() {
        $map = array(
            'oiscl-trackpro-report'   => 'click_tracker',
            'oiscl-utm-tracker'       => 'utm_tracker',
            'oiscl-analytics'         => 'analytics',
        );
        $rules = array();
        foreach ( $map as $page => $addon ) {
            if ( ! OISCL_Plan::is_addon_active( $addon ) ) {
                $rules[] = '#adminmenu a[href*="page=' . esc_attr( $page ) . '"] { opacity: 0.45; }';
            }
        }
        if ( empty( $rules ) ) {
            return;
        }
        echo '<style id="oiscl-addon-menu">' . implode( ' ', $rules ) . '</style>';
    }

    public function add_plugin_admin_menus() {
        add_menu_page( __( 'OIS Conversion Suite', 'ois-conversion-suite' ), __( 'Conversion Lab', 'ois-conversion-suite' ), 'view_ois_analytics', 'oiscl-intro', array( $this, 'display_dashboard_page' ), 'dashicons-chart-area', 85 );
        add_submenu_page( 'oiscl-intro', __( 'Global Dashboard', 'ois-conversion-suite' ), __( 'Dashboard Global', 'ois-conversion-suite' ), 'view_ois_analytics', 'oiscl-intro', array( $this, 'display_dashboard_page' ) );
        add_submenu_page( 'oiscl-intro', __( 'Custom Dashboards', 'ois-conversion-suite' ), __( 'Dashboard Custom', 'ois-conversion-suite' ), 'manage_ois_marketing', 'oiscl-custom-dashboards', array( $this, 'display_custom_dashboards_page' ) );
        add_submenu_page( 'oiscl-intro', __( 'OIS Analytics', 'ois-conversion-suite' ), __( 'OIS Analytics', 'ois-conversion-suite' ), 'view_ois_analytics', 'oiscl-analytics', array( $this, 'display_analytics_page' ) );
        add_submenu_page( 'oiscl-intro', __( 'Click Tracker', 'ois-conversion-suite' ), __( 'OIS Click Tracker', 'ois-conversion-suite' ), 'view_ois_analytics', 'oiscl-trackpro-report', array( $this, 'display_trackpro_report' ) );
        
        // add_submenu_page( 'oiscl-intro', 'UTM Campaigns', 'OIS UTM Tracker', 'manage_ois_marketing', 'oiscl-utm-tracker', array( $this, 'display_campaigns_page' ) );
        add_submenu_page( 'oiscl-intro', __( 'UTM Manager', 'ois-conversion-suite' ), __( 'OIS UTM Manager', 'ois-conversion-suite' ), 'view_ois_analytics', 'oiscl-utm-tracker', array( $this, 'display_campaigns_page' ) );
        
        add_submenu_page( 'oiscl-intro', __( 'OIS SEO Audit', 'ois-conversion-suite' ), __( 'OIS SEO Audit', 'ois-conversion-suite' ), 'manage_ois_marketing', 'oiscl-seo', array( $this, 'display_seo_page' ) );
        add_submenu_page( 'oiscl-intro', __( 'Custom Reports', 'ois-conversion-suite' ), __( 'Send Reports', 'ois-conversion-suite' ), 'manage_ois_marketing', 'oiscl-custom-reports', array( $this, 'display_custom_reports_page' ) );
        add_submenu_page( 'oiscl-intro', __( 'Suite Settings', 'ois-conversion-suite' ), __( 'Suite Settings', 'ois-conversion-suite' ), 'manage_options', 'oiscl-settings', array( $this, 'display_settings_page' ) );
    }

    public function handle_csv_export() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'oiscl_block_metrics';
        $today       = wp_date( 'Y-m-d' );
        $start_date  = isset( $_GET['start_date'] ) ? sanitize_text_field( wp_unslash( $_GET['start_date'] ) ) : $today;
        $end_date    = isset( $_GET['end_date'] ) ? sanitize_text_field( wp_unslash( $_GET['end_date'] ) ) : $start_date;

        if ( isset( $_GET['page'] ) && 'oiscl-analytics' === $_GET['page'] && ! empty( $_GET['export_chart'] ) ) {
            if ( ! current_user_can( 'view_ois_analytics' ) ) {
                wp_die( esc_html__( 'Insufficient permissions.', 'ois-conversion-suite' ) );
            }
            if ( ! isset( $_GET['oiscl_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['oiscl_nonce'] ) ), 'oiscl_export_chart' ) ) {
                wp_die( esc_html__( 'Invalid export link.', 'ois-conversion-suite' ) );
            }
            $chart = sanitize_key( wp_unslash( $_GET['export_chart'] ) );
            if ( in_array( $chart, array( 'daily_traffic', 'hourly_traffic' ), true ) ) {
                $this->oiscl_export_analytics_chart_csv( $chart, $start_date, $end_date );
            }
            return;
        }

        if ( isset( $_GET['page'] ) && 'oiscl-analytics' === $_GET['page'] && isset( $_GET['export_csv'] ) && 'journey' === sanitize_text_field( wp_unslash( $_GET['export_csv'] ) ) ) {
            if ( ! current_user_can( 'view_ois_analytics' ) ) {
                wp_die( esc_html__( 'Insufficient permissions.', 'ois-conversion-suite' ) );
            }
            $this->oiscl_export_journey_csv( $start_date, $end_date );
            return;
        }

        if ( isset( $_GET['page'] ) && 'oiscl-utm-tracker' === $_GET['page'] && isset( $_GET['export_csv'] ) && 'utm_journey' === sanitize_text_field( wp_unslash( $_GET['export_csv'] ) ) ) {
            if ( ! current_user_can( 'view_ois_analytics' ) ) {
                wp_die( esc_html__( 'Insufficient permissions.', 'ois-conversion-suite' ) );
            }
            $utm_filter = isset( $_GET['utm_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['utm_filter'] ) ) : 'all';
            $utm_attr   = isset( $_GET['utm_attr'] ) ? sanitize_key( wp_unslash( $_GET['utm_attr'] ) ) : 'first';
            $this->oiscl_export_utm_journey_csv( $start_date, $end_date, $utm_filter, $utm_attr );
            return;
        }

        if ( isset( $_GET['page'] ) && 'oiscl-utm-tracker' === $_GET['page'] && isset( $_GET['export_csv'] ) && 'utm_audience' === sanitize_text_field( wp_unslash( $_GET['export_csv'] ) ) ) {
            if ( ! current_user_can( 'view_ois_analytics' ) ) {
                wp_die( esc_html__( 'Insufficient permissions.', 'ois-conversion-suite' ) );
            }
            $utm_filter    = isset( $_GET['utm_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['utm_filter'] ) ) : 'all';
            $audience_list = isset( $_GET['audience_list'] ) ? sanitize_key( wp_unslash( $_GET['audience_list'] ) ) : '';
            if ( '' === $audience_list ) {
                wp_die( esc_html__( 'Invalid audience export.', 'ois-conversion-suite' ) );
            }
            $this->oiscl_export_utm_audience_csv( $start_date, $end_date, $utm_filter, $audience_list );
            return;
        }

        if ( isset( $_GET['page'] ) && 'oiscl-utm-tracker' === $_GET['page'] && isset( $_GET['export_csv'] ) && 'utm_funnel' === sanitize_text_field( wp_unslash( $_GET['export_csv'] ) ) ) {
            if ( ! current_user_can( 'view_ois_analytics' ) ) {
                wp_die( esc_html__( 'Insufficient permissions.', 'ois-conversion-suite' ) );
            }
            $utm_filter = isset( $_GET['utm_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['utm_filter'] ) ) : 'all';
            $scope      = isset( $_GET['funnel_scope'] ) ? sanitize_key( wp_unslash( $_GET['funnel_scope'] ) ) : 'both';
            if ( ! in_array( $scope, array( 'company', 'campaign', 'both', 'global', 'complete' ), true ) ) {
                $scope = 'both';
            }
            $this->oiscl_export_utm_funnel_csv( $start_date, $end_date, $utm_filter, $scope );
            return;
        }

        if ( isset( $_GET['page'] ) && 'oiscl-trackpro-report' === $_GET['page'] && ! empty( $_GET['export_chart'] ) ) {
            if ( ! current_user_can( 'view_ois_analytics' ) ) {
                wp_die( esc_html__( 'Insufficient permissions.', 'ois-conversion-suite' ) );
            }
            if ( ! isset( $_GET['oiscl_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['oiscl_nonce'] ) ), 'oiscl_export_chart' ) ) {
                wp_die( esc_html__( 'Invalid export link.', 'ois-conversion-suite' ) );
            }
            $chart = sanitize_key( wp_unslash( $_GET['export_chart'] ) );
            if ( in_array( $chart, array( 'trackpro_hourly_clicks', 'trackpro_7day_overview' ), true ) ) {
                $this->oiscl_export_trackpro_chart_csv( $chart, $start_date, $end_date );
            }
            return;
        }

        if ( isset( $_GET['export_csv'] ) && isset( $_GET['page'] ) && 'oiscl-trackpro-report' === $_GET['page'] ) {
            if ( ! current_user_can( 'view_ois_analytics' ) ) {
                wp_die( esc_html__( 'Insufficient permissions.', 'ois-conversion-suite' ) );
            }
            $type = sanitize_text_field( wp_unslash( $_GET['export_csv'] ) );
            if ( ob_get_length() ) {
                ob_clean();
            }
            nocache_headers();
            header( 'Content-Type: text/csv; charset=utf-8' );
            header( 'Content-Disposition: attachment; filename=OIS_Report_' . ucfirst( $type ) . '_' . $start_date . '_to_' . $end_date . '.csv' );
            $output = fopen( 'php://output', 'w' );
            fwrite( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );
            if ( 'clicks' === $type ) {
                fputcsv( $output, array( 'Origin Page', 'Section / Block', 'Link / Button', 'Destination', 'Decision Time (sec)', 'Total Clicks' ) );
                $query   = $wpdb->prepare( "SELECT origin_url, context_text, anchor_text, destination_url, SUM(clicks) as total_clicks, AVG(time_spent) as avg_time FROM $table_name WHERE is_guest = 1 AND anchor_text NOT IN ('[Pageview]', '[Vista de Bloque]') AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY origin_url, context_text, anchor_text, destination_url ORDER BY total_clicks DESC", $start_date, $end_date );
                $results = $wpdb->get_results( $query );
                if ( $results ) {
                    foreach ( $results as $row ) {
                        fputcsv( $output, array( $row->origin_url, $row->context_text, $row->anchor_text, $row->destination_url, round( $row->avg_time ), $row->total_clicks ) );
                    }
                }
            } elseif ( 'lectura' === $type ) {
                fputcsv( $output, array( 'Origin Page', 'Block Name', 'Average Read Time (sec)', 'Section Views' ) );
                $query   = $wpdb->prepare( "SELECT origin_url, context_text, SUM(clicks) as total_views, AVG(time_spent) as avg_read_time FROM $table_name WHERE is_guest = 1 AND anchor_text = '[Vista de Bloque]' AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY origin_url, context_text ORDER BY total_views DESC", $start_date, $end_date );
                $results = $wpdb->get_results( $query );
                if ( $results ) {
                    foreach ( $results as $row ) {
                        fputcsv( $output, array( $row->origin_url, $row->context_text, round( $row->avg_read_time ), $row->total_views ) );
                    }
                }
            }
            fclose( $output );
            exit();
        }

        if ( isset($_GET['export_csv_dash']) && $_GET['page'] === 'oiscl-custom-dashboards') {
            if ( ! current_user_can( 'manage_ois_marketing' ) ) {
                return;
            }
            $dash_id = sanitize_text_field($_GET['export_csv_dash']);
            $dashboards = get_option('oiscl_custom_dashboards', []);
            if (!isset($dashboards[$dash_id])) return;
            $dash = $dashboards[$dash_id];
            $cols = []; foreach($dash['elements'] as $el) { if (strpos($el, 'col_') === 0) { $cols[] = str_replace('col_', '', $el); } }
            if (empty($cols)) return; 
            
            $dict = OISCL_Dashboard_Dictionary::all();
            $headers = []; $dimensions = []; $metrics = []; $select_sql = [];
            foreach ($cols as $col_key) {
                if (!isset($dict['columns'][$col_key])) continue;
                $headers[] = $dict['columns'][$col_key]['label'];
                if ($dict['columns'][$col_key]['type'] === 'metric') {
                    $metrics[] = $dict['columns'][$col_key]['sql']; $select_sql[] = $dict['columns'][$col_key]['sql'];
                } else { $dimensions[] = $col_key; $select_sql[] = $col_key; }
            }
            if (ob_get_length()) ob_clean(); header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename=OIS_CustomDash_'.preg_replace('/[^A-Za-z0-9]/', '', $dash['title']).'_'.$start_date.'.csv');
            $output = fopen('php://output', 'w'); fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); fputcsv($output, $headers);
            $sql = "SELECT " . implode(', ', $select_sql) . " FROM $table_name WHERE DATE(created_at) >= %s AND DATE(created_at) <= %s";
            if (!empty($dimensions)) { $sql .= " GROUP BY " . implode(', ', $dimensions); }
            $sql .= " ORDER BY " . (!empty($metrics) ? explode(' as ', $metrics[0])[0] . " DESC" : "id DESC") . " LIMIT 1000";
            $results = $wpdb->get_results($wpdb->prepare($sql, $start_date, $end_date), ARRAY_A);
            if ($results) { foreach($results as $row) { $csv_row = []; foreach($cols as $k) { $val = isset($row[$k]) ? $row[$k] : ''; if ($k === 'is_bot') $val = ($val == 1) ? 'Bot' : 'Humano'; $csv_row[] = $val; } fputcsv($output, $csv_row); } }
            fclose($output); exit();
        }
    }

    /**
     * CSV de charts de Analytics: filas por fecha (y hora para serie horaria), mismo rango que la pantalla.
     */
    private function oiscl_export_analytics_chart_csv( $chart, $start_date, $end_date ) {
        global $wpdb;
        $table = $wpdb->prefix . 'oiscl_block_metrics';
        $slug  = ( 'daily_traffic' === $chart ) ? 'Daily_Traffic' : 'Hourly_Traffic';
        if ( ob_get_length() ) {
            ob_clean();
        }
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . sanitize_file_name( "OIS_{$slug}_{$start_date}_to_{$end_date}.csv" ) );
        $out = fopen( 'php://output', 'w' );
        fwrite( $out, chr(0xEF) . chr(0xBB) . chr(0xBF) );

        if ( 'daily_traffic' === $chart ) {
            fputcsv(
                $out,
                array(
                    __( 'Date', 'ois-conversion-suite' ),
                    __( 'Pageviews', 'ois-conversion-suite' ),
                    __( 'Unique sessions', 'ois-conversion-suite' ),
                )
            );
            $q = $wpdb->prepare(
                "SELECT DATE(created_at) AS d, SUM(clicks) AS views, COUNT(DISTINCT session_id) AS uniques
                FROM {$table} WHERE anchor_text = %s AND DATE(created_at) >= %s AND DATE(created_at) <= %s
                GROUP BY d ORDER BY d ASC",
                '[Pageview]',
                $start_date,
                $end_date
            );
            foreach ( (array) $wpdb->get_results( $q ) as $row ) {
                fputcsv( $out, array( $row->d, (int) $row->views, (int) $row->uniques ) );
            }
        } else {
            fputcsv(
                $out,
                array(
                    __( 'Date', 'ois-conversion-suite' ),
                    __( 'Hour (0-23)', 'ois-conversion-suite' ),
                    __( 'Pageviews', 'ois-conversion-suite' ),
                    __( 'Unique sessions', 'ois-conversion-suite' ),
                )
            );
            $q = $wpdb->prepare(
                "SELECT DATE(created_at) AS d, HOUR(created_at) AS hr, SUM(clicks) AS views, COUNT(DISTINCT session_id) AS uniques
                FROM {$table} WHERE anchor_text = %s AND DATE(created_at) >= %s AND DATE(created_at) <= %s
                GROUP BY d, hr ORDER BY d ASC, hr ASC",
                '[Pageview]',
                $start_date,
                $end_date
            );
            foreach ( (array) $wpdb->get_results( $q ) as $row ) {
                fputcsv( $out, array( $row->d, (int) $row->hr, (int) $row->views, (int) $row->uniques ) );
            }
        }
        fclose( $out );
        exit;
    }

    /**
     * CSV para gráficos de Track Pro (mismo nonce que Analytics).
     *
     * @param string $chart trackpro_hourly_clicks|trackpro_7day_overview.
     */
    private function oiscl_export_trackpro_chart_csv( $chart, $start_date, $end_date ) {
        global $wpdb;
        $table = $wpdb->prefix . 'oiscl_block_metrics';
        if ( ob_get_length() ) {
            ob_clean();
        }
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        $slug = ( 'trackpro_hourly_clicks' === $chart ) ? 'TrackPro_Hourly_Clicks' : 'TrackPro_7Day_Overview';
        header( 'Content-Disposition: attachment; filename=' . sanitize_file_name( "OIS_{$slug}.csv" ) );
        $out = fopen( 'php://output', 'w' );
        fwrite( $out, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

        if ( 'trackpro_hourly_clicks' === $chart ) {
            fputcsv(
                $out,
                array(
                    __( 'Date', 'ois-conversion-suite' ),
                    __( 'Hour (0-23)', 'ois-conversion-suite' ),
                    __( 'Clicks (non-pageview)', 'ois-conversion-suite' ),
                )
            );
            $q = $wpdb->prepare(
                "SELECT DATE(created_at) AS d, HOUR(created_at) AS hr, SUM(clicks) AS total
                FROM {$table} WHERE anchor_text NOT IN ('[Pageview]', '[Vista de Bloque]')
                AND DATE(created_at) >= %s AND DATE(created_at) <= %s
                GROUP BY d, hr ORDER BY d ASC, hr ASC",
                $start_date,
                $end_date
            );
            foreach ( (array) $wpdb->get_results( $q ) as $row ) {
                fputcsv( $out, array( $row->d, (int) $row->hr, (int) $row->total ) );
            }
        } else {
            $today         = current_time( 'Y-m-d' );
            $sevendays_ago = date( 'Y-m-d', strtotime( '-6 days' ) );
            fputcsv(
                $out,
                array(
                    __( 'Date', 'ois-conversion-suite' ),
                    __( 'Pageviews', 'ois-conversion-suite' ),
                    __( 'Actions (clicks)', 'ois-conversion-suite' ),
                )
            );
            $q = $wpdb->prepare(
                "SELECT DATE(created_at) AS dt,
                SUM(CASE WHEN anchor_text = %s THEN clicks ELSE 0 END) AS views,
                SUM(CASE WHEN anchor_text NOT IN ('[Pageview]', '[Vista de Bloque]') THEN clicks ELSE 0 END) AS actions
                FROM {$table} WHERE DATE(created_at) >= %s AND DATE(created_at) <= %s
                GROUP BY dt ORDER BY dt ASC",
                '[Pageview]',
                $sevendays_ago,
                $today
            );
            foreach ( (array) $wpdb->get_results( $q ) as $row ) {
                fputcsv( $out, array( $row->dt, (int) $row->views, (int) $row->actions ) );
            }
        }
        fclose( $out );
        exit;
    }

    /**
     * When export URL includes &full=1, CSV includes every session in the date range (chunked; may be slow).
     */
    private function oiscl_journey_csv_is_full_census() {
        return isset( $_GET['full'] ) && '1' === (string) $_GET['full'];
    }

    /**
     * @param resource $out
     * @param array    $s Normalized analytics session.
     */
    private function oiscl_fput_analytics_journey_csv_row( $out, $s ) {
        $paths = array();
        if ( ! empty( $s['steps'] ) && is_array( $s['steps'] ) ) {
            foreach ( $s['steps'] as $st ) {
                $url  = isset( $st['url'] ) ? $st['url'] : '';
                $path = parse_url( $url, PHP_URL_PATH );
                $paths[] = $path ? basename( $path ) : 'Home';
            }
        }
        $route_str = implode( ' > ', array_slice( array_values( array_unique( $paths ) ), 0, 20 ) );
        $bot_label = ! empty( $s['is_bot'] ) ? __( 'Bot', 'ois-conversion-suite' ) : __( 'Human', 'ois-conversion-suite' );
        fputcsv(
            $out,
            array(
                isset( $s['ip'] ) ? $s['ip'] : '',
                isset( $s['date'] ) ? $s['date'] : '',
                isset( $s['time'] ) ? $s['time'] : '',
                $route_str,
                isset( $s['duration'] ) ? $s['duration'] : '',
                isset( $s['total_clicks'] ) ? (int) $s['total_clicks'] : 0,
                isset( $s['location'] ) ? $s['location'] : '',
                isset( $s['screen_res'] ) ? $s['screen_res'] : '',
                isset( $s['device_name'] ) ? $s['device_name'] : '',
                isset( $s['os_name'] ) ? $s['os_name'] : '',
                isset( $s['browser_name'] ) ? $s['browser_name'] : '',
                isset( $s['lang'] ) ? $s['lang'] : '',
                $bot_label,
            )
        );
    }

    /**
     * @param resource $out
     * @param array    $s Normalized UTM session.
     */
    private function oiscl_fput_utm_journey_csv_row( $out, $s ) {
        $paths = array();
        if ( ! empty( $s['steps'] ) && is_array( $s['steps'] ) ) {
            foreach ( $s['steps'] as $st ) {
                $url  = isset( $st['url'] ) ? $st['url'] : '';
                $path = parse_url( $url, PHP_URL_PATH );
                $paths[] = $path ? basename( $path ) : 'Home';
            }
        }
        $route_str = implode( ' > ', array_slice( array_values( array_unique( $paths ) ), 0, 20 ) );
        $bot_label = ! empty( $s['is_bot'] ) ? __( 'Bot', 'ois-conversion-suite' ) : __( 'Human', 'ois-conversion-suite' );
        fputcsv(
            $out,
            array(
                isset( $s['session_id'] ) ? $s['session_id'] : '',
                isset( $s['identity_label'] ) ? $s['identity_label'] : '',
                isset( $s['utm_campaign'] ) ? $s['utm_campaign'] : '',
                isset( $s['utm_term'] ) ? $s['utm_term'] : '',
                isset( $s['utm_source'] ) ? $s['utm_source'] : '',
                isset( $s['utm_medium'] ) ? $s['utm_medium'] : '',
                isset( $s['attr_touch_label'] ) ? $s['attr_touch_label'] : '',
                isset( $s['attr_utm_at_display'] ) ? $s['attr_utm_at_display'] : ( isset( $s['first_utm_at_display'] ) ? $s['first_utm_at_display'] : '' ),
                isset( $s['utm_distinct_campaigns'] ) ? (int) $s['utm_distinct_campaigns'] : 1,
                isset( $s['date'] ) ? $s['date'] : '',
                isset( $s['time'] ) ? $s['time'] : '',
                $route_str,
                isset( $s['duration'] ) ? $s['duration'] : '',
                isset( $s['total_clicks'] ) ? (int) $s['total_clicks'] : 0,
                isset( $s['location'] ) ? $s['location'] : '',
                isset( $s['screen_res'] ) ? $s['screen_res'] : '',
                isset( $s['device_name'] ) ? $s['device_name'] : '',
                isset( $s['os_name'] ) ? $s['os_name'] : '',
                isset( $s['browser_name'] ) ? $s['browser_name'] : '',
                isset( $s['lang'] ) ? $s['lang'] : '',
                $bot_label,
            )
        );
    }

    /**
     * CSV de User Journey (Analytics): mismas sesiones que la tabla, o todas con &full=1.
     *
     * @param string $start_date Y-m-d.
     * @param string $end_date   Y-m-d.
     */
    private function oiscl_export_journey_csv( $start_date, $end_date ) {
        $full = $this->oiscl_journey_csv_is_full_census();
        if ( ! method_exists( $this, 'get_oiscl_user_sessions' ) || ! method_exists( $this, 'oiscl_query_analytics_journey_batch' ) || ! method_exists( $this, 'oiscl_normalize_analytics_journey_row' ) ) {
            wp_die( esc_html__( 'Export not available.', 'ois-conversion-suite' ) );
        }
        if ( ob_get_length() ) {
            ob_clean();
        }
        if ( $full && function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 );
        }
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        $fname = $full ? 'OIS_User_Journey_FULL_' : 'OIS_User_Journey_';
        header(
            'Content-Disposition: attachment; filename=' . sanitize_file_name(
                $fname . $start_date . '_to_' . $end_date . '.csv'
            )
        );
        $out = fopen( 'php://output', 'w' );
        fwrite( $out, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );
        fputcsv(
            $out,
            array(
                __( 'Session', 'ois-conversion-suite' ),
                __( 'Date', 'ois-conversion-suite' ),
                __( 'Entry time', 'ois-conversion-suite' ),
                __( 'Route (pages)', 'ois-conversion-suite' ),
                __( 'Duration', 'ois-conversion-suite' ),
                __( 'Clicks (non-view)', 'ois-conversion-suite' ),
                __( 'Location', 'ois-conversion-suite' ),
                __( 'Screen', 'ois-conversion-suite' ),
                __( 'Device', 'ois-conversion-suite' ),
                __( 'OS', 'ois-conversion-suite' ),
                __( 'Browser', 'ois-conversion-suite' ),
                __( 'Language', 'ois-conversion-suite' ),
                __( 'Traffic type', 'ois-conversion-suite' ),
            )
        );
        if ( $full ) {
            $batch  = $this->oiscl_get_journey_export_batch_size();
            $offset = 0;
            while ( true ) {
                $rows = $this->oiscl_query_analytics_journey_batch( $start_date, $end_date, $batch, $offset );
                if ( empty( $rows ) ) {
                    break;
                }
                foreach ( $rows as $r ) {
                    $s = $this->oiscl_normalize_analytics_journey_row( $r );
                    $this->oiscl_fput_analytics_journey_csv_row( $out, $s );
                }
                if ( count( $rows ) < $batch ) {
                    break;
                }
                $offset += $batch;
            }
        } else {
            foreach ( (array) $this->get_oiscl_user_sessions( $start_date, $end_date ) as $s ) {
                $this->oiscl_fput_analytics_journey_csv_row( $out, $s );
            }
        }
        fclose( $out );
        exit;
    }

    /**
     * CSV UTM Journey: mismas sesiones que la pestaña, o todas con &full=1.
     *
     * @param string $start_date Y-m-d.
     * @param string $end_date   Y-m-d.
     * @param string $utm_filter Valor `utm_filter` (all, lbl_*, o utm_campaign).
     * @param string $utm_attr   first|last|session.
     */
    private function oiscl_export_utm_journey_csv( $start_date, $end_date, $utm_filter = 'all', $utm_attr = 'first' ) {
        $full = $this->oiscl_journey_csv_is_full_census();
        $attr_mode = method_exists( $this, 'oiscl_sanitize_utm_attr_mode' )
            ? $this->oiscl_sanitize_utm_attr_mode( $utm_attr )
            : 'first';
        if ( ! method_exists( $this, 'get_oiscl_utm_journey_sessions' )
            || ! method_exists( $this, 'get_oiscl_utm_dashboard_filters' )
            || ! method_exists( $this, 'oiscl_fetch_utm_journey_batch_with_context' )
            || ! method_exists( $this, 'oiscl_normalize_utm_journey_aggregate_row' ) ) {
            wp_die( esc_html__( 'Export not available.', 'ois-conversion-suite' ) );
        }
        $filters      = $this->get_oiscl_utm_dashboard_filters( $utm_filter );
        $filter_stats = $filters['filter_sql_stats'];
        if ( ob_get_length() ) {
            ob_clean();
        }
        if ( $full && function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 );
        }
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        $fname = $full ? 'OIS_UTM_Journey_FULL_' : 'OIS_UTM_Journey_';
        header(
            'Content-Disposition: attachment; filename=' . sanitize_file_name(
                $fname . $start_date . '_to_' . $end_date . '.csv'
            )
        );
        $out = fopen( 'php://output', 'w' );
        fwrite( $out, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );
        fputcsv(
            $out,
            array(
                __( 'Session id', 'ois-conversion-suite' ),
                __( 'Identity (label)', 'ois-conversion-suite' ),
                __( 'Campaign ID', 'ois-conversion-suite' ),
                __( 'UTM term', 'ois-conversion-suite' ),
                __( 'UTM source', 'ois-conversion-suite' ),
                __( 'UTM medium', 'ois-conversion-suite' ),
                __( 'Attribution model', 'ois-conversion-suite' ),
                __( 'Attributed UTM time (local)', 'ois-conversion-suite' ),
                __( 'Distinct campaigns in range', 'ois-conversion-suite' ),
                __( 'Date', 'ois-conversion-suite' ),
                __( 'Entry time', 'ois-conversion-suite' ),
                __( 'Route (pages)', 'ois-conversion-suite' ),
                __( 'Duration', 'ois-conversion-suite' ),
                __( 'Clicks (non-view)', 'ois-conversion-suite' ),
                __( 'Location', 'ois-conversion-suite' ),
                __( 'Screen', 'ois-conversion-suite' ),
                __( 'Device', 'ois-conversion-suite' ),
                __( 'OS', 'ois-conversion-suite' ),
                __( 'Browser', 'ois-conversion-suite' ),
                __( 'Language', 'ois-conversion-suite' ),
                __( 'Traffic type', 'ois-conversion-suite' ),
            )
        );
        if ( $full ) {
            $batch    = $this->oiscl_get_journey_export_batch_size();
            $offset   = 0;
            $camp_map = null;
            while ( true ) {
                $ctx = $this->oiscl_fetch_utm_journey_batch_with_context( $start_date, $end_date, $filter_stats, $batch, $offset, $camp_map );
                if ( null === $camp_map ) {
                    $camp_map = $ctx['camp_to_label'];
                }
                if ( empty( $ctx['rows'] ) ) {
                    break;
                }
                foreach ( $ctx['rows'] as $r ) {
                    $s = $this->oiscl_normalize_utm_journey_aggregate_row( $r, $ctx['camp_to_label'], $ctx['has_utm_sm'], $attr_mode );
                    $this->oiscl_fput_utm_journey_csv_row( $out, $s );
                }
                if ( count( $ctx['rows'] ) < $batch ) {
                    break;
                }
                $offset += $batch;
            }
        } else {
            foreach ( (array) $this->get_oiscl_utm_journey_sessions( $start_date, $end_date, $filter_stats, $attr_mode ) as $s ) {
                $this->oiscl_fput_utm_journey_csv_row( $out, $s );
            }
        }
        fclose( $out );
        exit;
    }

    /**
     * Settings → Maintenance: confirms browser can reach admin-ajax.php (nonce + caps).
     */
    public function ajax_oiscl_host_health_ping() {
        check_ajax_referer( 'oiscl_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'ois-conversion-suite' ) ), 403 );
        }
        global $wpdb;
        $db_ver = method_exists( $wpdb, 'db_version' ) ? $wpdb->db_version() : '';
        wp_send_json_success(
            array(
                'message' => __( 'admin-ajax.php responded successfully.', 'ois-conversion-suite' ),
                'db'      => $db_ver ? (string) $db_ver : '',
            )
        );
    }

}
