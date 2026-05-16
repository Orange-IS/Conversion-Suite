<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait OISCL_Admin_Trackpro_Trait {

    /**
     * Append origin_url IN clause for scoped Click Tracker queries.
     *
     * @param string              $sql       SQL with existing placeholders.
     * @param array<int,mixed>    $base_args Values for existing placeholders.
     * @param array<string,mixed> $scope     Output of resolve_report_scope().
     * @return string|false
     */
    private function oiscl_trackpro_prepare_sql( $sql, array $base_args, array $scope ) {
        global $wpdb;
        if ( empty( $scope['origin_urls'] ) || ! is_array( $scope['origin_urls'] ) ) {
            return $wpdb->prepare( $sql, $base_args );
        }
        $placeholders = implode( ',', array_fill( 0, count( $scope['origin_urls'] ), '%s' ) );
        $sql         .= " AND SUBSTRING_INDEX(origin_url, '?', 1) IN ($placeholders)";
        return $wpdb->prepare( $sql, array_merge( $base_args, $scope['origin_urls'] ) );
    }

    // ==========================================
    // MÓDULO 3: CLICK TRACKER
    // ==========================================
    // ==========================================
    // MÓDULO 3: TRACK PRO REPORT (v0.7.18)
    // ==========================================
    public function display_trackpro_report() {
        global $wpdb; $table_name = $wpdb->prefix . 'oiscl_block_metrics';
        $user_id = get_current_user_id();
        $today   = current_time('Y-m-d');

        // --- 1. LÓGICA DE FECHAS SINCRONIZADA ---
        $date_ctx = OISCL_Activity::resolve_user_report_dates( $user_id, $today, $_GET );
        $start_date   = $date_ctx['start_date'];
        $end_date     = $date_ctx['end_date'];
        $preset_label = $date_ctx['preset_label'];
        $preset       = $date_ctx['preset'];
        $retention_clamped = ! empty( $date_ctx['retention_clamped'] );

        $report_scope = OISCL_Tracking::resolve_report_scope( $_GET, $start_date, $end_date, $today );
        $scope_cap    = OISCL_Plan::clamp_report_dates( $report_scope['start_date'], $report_scope['end_date'], $today );
        $report_scope['start_date'] = $scope_cap['start_date'];
        $report_scope['end_date']   = $scope_cap['end_date'];
        $start_date   = $report_scope['start_date'];
        $end_date     = $report_scope['end_date'];
        $tp_page      = (int) $report_scope['post_id'];
        $tp_revision  = (int) $report_scope['revision'];
        $scope_qs     = array();
        if ( $tp_page > 0 ) {
            $scope_qs['tp_page'] = $tp_page;
        }
        if ( $tp_revision > 0 ) {
            $scope_qs['tp_revision'] = $tp_revision;
        }
        $configured_pages = OISCL_Tracking::get_configured_pages_for_reports();
        $revision_windows = $tp_page > 0 ? OISCL_Tracking::get_revision_windows( $tp_page ) : array();

        // --- 2. LÓGICA DE MÉTRICAS GLOBALES PARA KPIs ---
        $diff_days = round((strtotime($end_date) - strtotime($start_date)) / 86400);
        $prev_end = date('Y-m-d', strtotime($start_date . ' - 1 day'));
        $prev_start = date('Y-m-d', strtotime($prev_end . ' - ' . $diff_days . ' days'));
        $sql_exclude_actions = OISCL_Plan::sql_exclude_actions_not_in();
        $sql_dwell_in        = OISCL_Plan::sql_dwell_anchor_in();
        $sql_block_in        = OISCL_Plan::sql_block_view_anchor_in();
        $ct_tab              = isset( $_GET['ct_tab'] ) ? sanitize_key( wp_unslash( $_GET['ct_tab'] ) ) : 'overview';
        if ( ! in_array( $ct_tab, array( 'overview', 'clicks', 'reading' ), true ) ) {
            $ct_tab = 'overview';
        }

        $ois_now = date('Y-m-d H:i:s', strtotime(current_time('mysql')) - 300);
        $live_views = $wpdb->get_var( $this->oiscl_trackpro_prepare_sql( "SELECT COUNT(DISTINCT session_id) FROM $table_name WHERE created_at >= %s", array( $ois_now ), $report_scope ) ) ?: 0;
        
        $total_views = $wpdb->get_var( $this->oiscl_trackpro_prepare_sql( "SELECT SUM(clicks) FROM $table_name WHERE anchor_text='[Pageview]' AND DATE(created_at) >= %s AND DATE(created_at) <= %s", array( $start_date, $end_date ), $report_scope ) ) ?: 0;
        $prev_views = $wpdb->get_var( $this->oiscl_trackpro_prepare_sql( "SELECT SUM(clicks) FROM $table_name WHERE anchor_text='[Pageview]' AND DATE(created_at) >= %s AND DATE(created_at) <= %s", array( $prev_start, $prev_end ), $report_scope ) ) ?: 0;
        $unique_users = $wpdb->get_var( $this->oiscl_trackpro_prepare_sql( "SELECT COUNT(DISTINCT session_id) FROM $table_name WHERE anchor_text='[Pageview]' AND DATE(created_at) >= %s AND DATE(created_at) <= %s", array( $start_date, $end_date ), $report_scope ) ) ?: 0;
        $prev_uniques = $wpdb->get_var( $this->oiscl_trackpro_prepare_sql( "SELECT COUNT(DISTINCT session_id) FROM $table_name WHERE anchor_text='[Pageview]' AND DATE(created_at) >= %s AND DATE(created_at) <= %s", array( $prev_start, $prev_end ), $report_scope ) ) ?: 0;
        $total_clicks = $wpdb->get_var( $this->oiscl_trackpro_prepare_sql( "SELECT SUM(clicks) FROM $table_name WHERE anchor_text NOT IN ($sql_exclude_actions) AND DATE(created_at) >= %s AND DATE(created_at) <= %s", array( $start_date, $end_date ), $report_scope ) ) ?: 0;
        $prev_clicks = $wpdb->get_var( $this->oiscl_trackpro_prepare_sql( "SELECT SUM(clicks) FROM $table_name WHERE anchor_text NOT IN ($sql_exclude_actions) AND DATE(created_at) >= %s AND DATE(created_at) <= %s", array( $prev_start, $prev_end ), $report_scope ) ) ?: 0;
        $actions_per_pv = $total_views > 0 ? round($total_clicks / $total_views, 2) : 0;
        $prev_actions_per_pv = $prev_views > 0 ? round($prev_clicks / $prev_views, 2) : 0;
        $avg_time = $wpdb->get_var( $this->oiscl_trackpro_prepare_sql( "SELECT AVG(time_spent) FROM $table_name WHERE time_spent > 0 AND anchor_text IN ($sql_dwell_in) AND DATE(created_at) >= %s AND DATE(created_at) <= %s", array( $start_date, $end_date ), $report_scope ) ) ?: 0;
        $prev_time = $wpdb->get_var( $this->oiscl_trackpro_prepare_sql( "SELECT AVG(time_spent) FROM $table_name WHERE time_spent > 0 AND anchor_text IN ($sql_dwell_in) AND DATE(created_at) >= %s AND DATE(created_at) <= %s", array( $prev_start, $prev_end ), $report_scope ) ) ?: 0;

        

        // --- 3. DATOS ESPECÍFICOS DE GRÁFICOS ---
        $clicks_data = $wpdb->get_results( $this->oiscl_trackpro_prepare_sql( "SELECT origin_url, anchor_text, destination_url, context_text, SUM(clicks) as total_clicks, AVG(time_spent) as avg_time FROM $table_name WHERE anchor_text NOT IN ($sql_exclude_actions) AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY origin_url, anchor_text, destination_url, context_text ORDER BY total_clicks DESC", array( $start_date, $end_date ), $report_scope ) );
        $hourly_data = $wpdb->get_results( $this->oiscl_trackpro_prepare_sql( "SELECT HOUR(created_at) as hr, SUM(clicks) as total FROM $table_name WHERE anchor_text NOT IN ($sql_exclude_actions) AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY hr ORDER BY hr ASC", array( $start_date, $end_date ), $report_scope ) );
        $hours_values = array_fill(0, 24, 0); foreach($hourly_data as $h) { $hours_values[(int)$h->hr] = (int)$h->total; } $total_overall_clicks = array_sum($hours_values);

        $sevendays_ago = date('Y-m-d', strtotime('-6 days'));
        $daily_traffic = $wpdb->get_results( $this->oiscl_trackpro_prepare_sql( "SELECT DATE(created_at) as dt, SUM(CASE WHEN anchor_text='[Pageview]' THEN clicks ELSE 0 END) as views, SUM(CASE WHEN anchor_text NOT IN ($sql_exclude_actions) THEN clicks ELSE 0 END) as actions FROM $table_name WHERE DATE(created_at) >= %s GROUP BY dt ORDER BY dt ASC", array( $sevendays_ago ), $report_scope ) );
        $period_7d = new DatePeriod(new DateTime($sevendays_ago), new DateInterval('P1D'), (new DateTime($today))->modify('+1 day'));
        $d7_labels = []; $d7_views = []; $d7_actions = [];
        foreach($period_7d as $dt) { $d = $dt->format('Y-m-d'); $d7_labels[] = $dt->format('d M'); $d7_views[$d] = 0; $d7_actions[$d] = 0; }
        foreach($daily_traffic as $r) { if(isset($d7_views[$r->dt])) { $d7_views[$r->dt] = (int)$r->views; $d7_actions[$r->dt] = (int)$r->actions; } }
        $d7_v_arr = array_values($d7_views); $d7_a_arr = array_values($d7_actions);

        // --- 4. RENDERIZADO UI ---
        $this->render_ois_component('layout_start', array('id' => 'oiscl-trackpro-wrap'));
        
        $this->render_ois_component('header', array(
            'title'      => '🎯 OIS Click Tracker',
            'start_date' => $start_date,
            'end_date'   => $end_date,
            'preset'     => $preset_label,
            'page_slug'  => 'oiscl-trackpro-report',
            'live_val'   => $live_views,
            'kpis'       => array(
                array('label' => 'LIVE NOW', 'value' => $live_views, 'color' => ($live_views > 0 ? '#46b450' : '#d63638'), 'is_live' => true),
                array('label' => 'TOTAL VISITS', 'value' => number_format($total_views), 'color' => '#1a73e8', 'delta' => $this->format_kpi_delta($total_views, $prev_views), 'icon' => '👁️'),
                array('label' => 'UNIQUE USERS', 'value' => number_format($unique_users), 'color' => '#46b450', 'delta' => $this->format_kpi_delta($unique_users, $prev_uniques), 'icon' => '👤'),
                array('label' => 'ACTIONS / PV', 'value' => (string) $actions_per_pv, 'color' => '#f56e28', 'delta' => $this->format_kpi_delta($actions_per_pv, $prev_actions_per_pv), 'icon' => '🖱️'),
                array('label' => 'AVG RETENTION', 'value' => ($avg_time >= 60 ? round($avg_time/60, 1).'m' : round($avg_time).'s'), 'color' => '#722ed1', 'delta' => $this->format_kpi_delta($avg_time, $prev_time), 'icon' => '⏱️')
            )
        ));

        if ( $retention_clamped && OISCL_Plan::has_metrics_retention_cap() ) {
            echo '<p class="description" style="margin:0 0 16px; padding:10px 14px; background:#f0f9ff; border:1px solid #bae6fd; border-radius:4px; color:#075985;">' . sprintf(
                /* translators: %d: retention days */
                esc_html__( 'Lite plan: your selected range was adjusted to the last %d days of available history.', 'ois-conversion-suite' ),
                (int) OISCL_Plan::get_metrics_retention_days()
            ) . '</p>';
        }

        $chart_export_nonce = wp_create_nonce( 'oiscl_export_chart' );
        $tp_csv_hourly      = admin_url(
            'admin.php?' . http_build_query(
                array_merge(
                    array(
                        'page'         => 'oiscl-trackpro-report',
                        'export_chart' => 'trackpro_hourly_clicks',
                        'start_date'   => $start_date,
                        'end_date'     => $end_date,
                        'oiscl_nonce'  => $chart_export_nonce,
                    ),
                    $scope_qs
                )
            )
        );
        $tp_csv_7d          = admin_url(
            'admin.php?' . http_build_query(
                array_merge(
                    array(
                        'page'         => 'oiscl-trackpro-report',
                        'export_chart' => 'trackpro_7day_overview',
                        'start_date'   => $start_date,
                        'end_date'     => $end_date,
                        'oiscl_nonce'  => $chart_export_nonce,
                    ),
                    $scope_qs
                )
            )
        );

       
        $ct_tab_base = add_query_arg(
            array_merge(
                array(
                    'page'       => 'oiscl-trackpro-report',
                    'start_date' => $start_date,
                    'end_date'   => $end_date,
                ),
                $scope_qs
            ),
            admin_url( 'admin.php' )
        );
        $ct_tabs = array(
            'overview' => __( 'Overview', 'ois-conversion-suite' ),
            'clicks'   => __( 'Clicks', 'ois-conversion-suite' ),
            'reading'  => __( 'Reading Map', 'ois-conversion-suite' ),
        );

        if ( ! empty( $configured_pages ) ) {
            $scope_base = array(
                'page'       => 'oiscl-trackpro-report',
                'start_date' => $start_date,
                'end_date'   => $end_date,
            );
            if ( $ct_tab ) {
                $scope_base['ct_tab'] = $ct_tab;
            }
            echo '<div class="ois-box" style="margin:0 0 20px 0; padding:14px 16px; display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end;">';
            echo '<div><label for="oiscl-tp-page" style="display:block; font-size:11px; color:#646970; margin-bottom:4px;">' . esc_html__( 'Tracked page', 'ois-conversion-suite' ) . '</label>';
            echo '<select id="oiscl-tp-page" class="oiscl-tp-scope" style="min-width:220px;">';
            echo '<option value="">' . esc_html__( 'All pages', 'ois-conversion-suite' ) . '</option>';
            foreach ( $configured_pages as $pid => $title ) {
                echo '<option value="' . esc_attr( (string) $pid ) . '"' . selected( $tp_page, (int) $pid, false ) . '>' . esc_html( $title ) . '</option>';
            }
            echo '</select></div>';
            echo '<div><label for="oiscl-tp-revision" style="display:block; font-size:11px; color:#646970; margin-bottom:4px;">' . esc_html__( 'Config version', 'ois-conversion-suite' ) . '</label>';
            echo '<select id="oiscl-tp-revision" class="oiscl-tp-scope" style="min-width:220px;"' . ( $tp_page <= 0 ? ' disabled' : '' ) . '>';
            echo '<option value="">' . esc_html__( 'All versions (date range only)', 'ois-conversion-suite' ) . '</option>';
            if ( $tp_page > 0 && ! empty( $revision_windows ) ) {
                foreach ( $revision_windows as $window ) {
                    $rev = (int) $window['revision'];
                    $range_label = date_i18n( 'M j, Y', strtotime( $window['start_date'] ) ) . ' – ' . date_i18n( 'M j, Y', strtotime( $window['end_date'] ) );
                    echo '<option value="' . esc_attr( (string) $rev ) . '"' . selected( $tp_revision, $rev, false ) . '>' . esc_html( $window['label'] . ' · ' . $range_label ) . '</option>';
                }
            }
            echo '</select></div>';
            $clear_url = esc_url( add_query_arg( $scope_base, admin_url( 'admin.php' ) ) );
            echo '<a href="' . $clear_url . '" class="button" style="height:30px; line-height:28px;' . ( $tp_page <= 0 && $tp_revision <= 0 ? ' visibility:hidden;' : '' ) . '">' . esc_html__( 'Clear scope', 'ois-conversion-suite' ) . '</a>';
            echo '</div>';
            if ( $tp_page > 0 ) {
                $scope_note = esc_html( $report_scope['page_title'] );
                if ( $tp_revision > 0 && ! empty( $report_scope['window'] ) ) {
                    $w = $report_scope['window'];
                    $scope_note .= ' · ' . esc_html( $report_scope['revision_label'] ) . ' · ' . esc_html( date_i18n( 'M j, Y', strtotime( $w['start_date'] ) ) . ' – ' . date_i18n( 'M j, Y', strtotime( $w['end_date'] ) ) );
                }
                echo '<p class="description" style="margin:-10px 0 18px; color:#50575e;">' . sprintf(
                    esc_html__( 'Metrics scoped to: %s. Version windows limit dates to when that configuration was active.', 'ois-conversion-suite' ),
                    '<strong>' . $scope_note . '</strong>'
                ) . '</p>';
            }
            ?>
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                var pageSel = document.getElementById('oiscl-tp-page');
                var revSel = document.getElementById('oiscl-tp-revision');
                if (!pageSel) return;
                function navigateScope() {
                    var base = <?php echo wp_json_encode( add_query_arg( $scope_base, admin_url( 'admin.php' ) ) ); ?>;
                    var url = new URL(base, window.location.origin);
                    var pid = pageSel.value;
                    if (pid) url.searchParams.set('tp_page', pid);
                    else url.searchParams.delete('tp_page');
                    if (revSel && revSel.value && pid) url.searchParams.set('tp_revision', revSel.value);
                    else url.searchParams.delete('tp_revision');
                    window.location.href = url.toString();
                }
                pageSel.addEventListener('change', function() {
                    if (revSel) {
                        revSel.disabled = !pageSel.value;
                        if (!pageSel.value) revSel.value = '';
                    }
                    navigateScope();
                });
                if (revSel) revSel.addEventListener('change', navigateScope);
            });
            </script>
            <?php
        }

        echo '<div class="oiscl-ct-tabstrip oiscl-wp-tabstrip nav-tab-wrapper" style="margin:0 0 20px 0;">';
        foreach ( $ct_tabs as $slug => $label ) {
            $active = ( $ct_tab === $slug ) ? ' nav-tab-active' : '';
            $href   = esc_url( add_query_arg( 'ct_tab', $slug, $ct_tab_base ) );
            echo '<a href="' . $href . '" class="nav-tab' . esc_attr( $active ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</div>';

        echo '<style> .oiscl-toolbar { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 15px; border-bottom: 2px solid #f0f0f1; padding-bottom: 15px; } .filter-dropdown-container { position: relative; } #btn-filter-main { background: #fff; border: 1px solid #ccd0d4; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-weight: 600; display: flex; align-items: center; gap: 8px; } .filter-menu { position: absolute; top: 110%; right: 0; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 999; width: 220px; padding: 10px; display: none; } .filter-menu.active { display: block; } .badge-cat { font-size: 9px; padding: 2px 5px; border-radius: 3px; text-transform: uppercase; font-weight: bold; margin-right: 8px; display: inline-block; min-width: 55px; text-align: center; } .cat-contact { background: #e6fffa; color: #047481; border: 1px solid #b2f5ea; } .cat-forms { background: #ebf4ff; color: #2b6cb0; border: 1px solid #bee3f8; } .cat-pages { background: #e9d8fd; color: #553c9a; border: 1px solid #d6bcfa; } .cat-media { background: #fff5f5; color: #c53030; border: 1px solid #feb2b2; } .cat-external { background: #fffaf0; color: #9c4221; border: 1px solid #feebc8; } .cat-interface { background: #f7fafc; color: #4a5568; border: 1px solid #edf2f7; } </style>';


        if ( 'overview' === $ct_tab ) {
        // Bloque 1: Clicks by Hour
        echo '<div style="background:#fff; border:1px solid #ccd0d4; padding:20px; border-radius:4px; margin-bottom:25px;"><div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;"><h3 class="ois-block-title">📊 Clicks by Hour <span style="color:#1a73e8; font-weight:normal;">(Total: '.number_format($total_overall_clicks).' clicks)</span></h3>';
        $this->render_ois_component(
            'export_menu',
            array(
                'id'               => 'ois-export-trackpro-hourly',
                'csv_url'          => $tp_csv_hourly,
                'png_canvas_id'    => 'oisclHourlyChart',
                'png_filename'     => 'clicks-by-hour.png',
                'pdf_chart_title'  => __( 'Clicks by Hour', 'ois-conversion-suite' ),
                'show_pdf_chart'   => true,
            )
        );
        echo '</div><div style="height:220px; position:relative;"><canvas id="oisclHourlyChart"></canvas></div></div>';
        
        // Bloque 2: 7 Days Mixed
        echo '<div style="background:#fff; border:1px solid #ccd0d4; padding:20px; border-radius:4px; margin-bottom:25px;"><div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;"><h3 class="ois-block-title">📅 Last 7 Days Overview <span style="color:#666; font-weight:normal; font-size:13px;">(Views vs Actions)</span></h3>';
        $this->render_ois_component(
            'export_menu',
            array(
                'id'               => 'ois-export-trackpro-7d',
                'csv_url'          => $tp_csv_7d,
                'png_canvas_id'    => 'oiscl7DaysMixedChart',
                'png_filename'     => 'trackpro-7day-overview.png',
                'pdf_chart_title'  => __( 'Last 7 Days Overview', 'ois-conversion-suite' ),
                'show_pdf_chart'   => true,
            )
        );
        echo '</div><div style="height:250px; position:relative;"><canvas id="oiscl7DaysMixedChart"></canvas></div></div>';
        }

        

        // --- 2. PREPARAR DATOS Y LLAMAR AL TEMPLATE PARA CONVERSION CLICKS ---
        $filter_toolbar = '<div class="filter-dropdown-container"><button type="button" id="btn-filter-main" class="button">📂 <span id="filter-text">Filter: All</span> ▾</button><div class="filter-menu" id="ois-filter-menu"><label class="filter-item" style="border-bottom:1px solid #eee; margin-bottom:5px; font-weight:bold;"><input type="checkbox" id="ois-master-filter" checked> Toggle All</label><label class="filter-item"><input type="checkbox" class="oiscl-filter-trigger" data-cat="contact" checked> 📞 Leads & Contact</label><label class="filter-item"><input type="checkbox" class="oiscl-filter-trigger" data-cat="forms" checked> 📩 Form Clicks</label><label class="filter-item"><input type="checkbox" class="oiscl-filter-trigger" data-cat="pages" checked> 📄 Internal Navigation</label><label class="filter-item"><input type="checkbox" class="oiscl-filter-trigger" data-cat="media" checked> 🖼️ Media & Downloads</label><label class="filter-item"><input type="checkbox" class="oiscl-filter-trigger" data-cat="external" checked> 🔗 External Links</label><label class="filter-item"><input type="checkbox" class="oiscl-filter-trigger" data-cat="interface" checked> ⚙️ Technical Noise</label></div></div>';
        
        $click_rows_data = [];
        if($clicks_data) {
            $site_url = get_site_url();
            foreach($clicks_data as $row) {
                $dest = strtolower($row->destination_url); $anchor = strtolower($row->anchor_text);
                $is_noise = (empty($anchor) || $anchor === 'botón' || strpos($anchor, 'next') !== false || strpos($anchor, 'prev') !== false || strpos($anchor, 'gallery') !== false || strpos($dest, 'gad_source') !== false || strpos($dest, 'gclid') !== false || strpos($dest, 'google') !== false || strpos($dest, 'doubleclick') !== false);
                if ($is_noise) { $cat = 'interface'; $label = 'Noise'; } elseif (strpos($dest, 'tel:') !== false || strpos($dest, 'wa.me') !== false || strpos($dest, 'mailto:') !== false) { $cat = 'contact'; $label = 'Lead'; } elseif (preg_match('/\.(pdf|jpg|jpeg|png|gif|mp4|webm|svg)$/i', $dest)) { $cat = 'media'; $label = 'Media'; } elseif (strpos($anchor, 'submit') !== false || strpos($anchor, 'send') !== false || strpos($dest, 'form') !== false) { $cat = 'forms'; $label = 'Form'; } elseif (strpos($dest, $site_url) !== false) { $cat = 'pages'; $label = 'Page'; } else { $cat = 'external'; $label = 'Link'; }
                
                $full_url = esc_html($row->destination_url);
                $display_dest = (strlen($full_url) > 40) ? "<details style='cursor:pointer;'><summary style='color:#722ed1; font-size:11px; outline:none; font-family:monospace;' title='Click to expand'>".substr($full_url, 0, 40)."...</summary><div style='margin-top:5px; padding:6px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:4px; word-break:break-all; font-size:10px; color:#334155; font-family:monospace;'>{$full_url}</div></details>" : "<code style='color:#722ed1; font-size:11px;'>{$full_url}</code>";

                $click_rows_data[] = [
                    'category' => $cat,
                    'cols' => [
                        esc_html(basename($row->origin_url)),
                        "<span class='badge-cat cat-{$cat}'>{$label}</span> " . esc_html($row->anchor_text ?: '[Technical Hit]'),
                        $display_dest,
                        "<strong style='color:#1a73e8; font-size:14px;'>" . intval($row->total_clicks) . "</strong>",
                        "<span style='color:#666;'>" . ($row->avg_time > 0 ? round($row->avg_time, 1).'s' : '—') . "</span>",
                        esc_html($row->context_text)
                    ]
                ];
            }
        }

        if ( 'clicks' === $ct_tab ) {
        $this->render_ois_component('advanced_table', [
            'id'       => 'table-clicks',
            'title'    => 'Conversion Clicks',
            'subtitle' => 'Feed de actividad. Usa los filtros para aislar conversiones clave.',
            'icon'     => '🖱️',
            'toolbar'  => $filter_toolbar,
            'pdf'      => 'Conversion_Clicks',
            'csv'      => "?page=oiscl-trackpro-report&export_csv=clicks&start_date={$start_date}&end_date={$end_date}",
            'headers'  => [
                ['label' => 'Source', 'width' => '15%', 'type' => 'string'],
                ['label' => 'Anchor', 'width' => '25%', 'type' => 'string'],
                ['label' => 'Destination URL', 'width' => '30%', 'type' => 'string'],
                ['label' => 'Clicks', 'width' => '10%', 'type' => 'numeric', 'align' => 'right'],
                ['label' => 'Time (s)', 'width' => '10%', 'type' => 'numeric', 'align' => 'center'],
                ['label' => 'Context', 'width' => '10%', 'type' => 'string', 'align' => 'center']
            ],
            'rows'     => $click_rows_data
        ]);
        }

        // --- 3. PREPARAR DATOS Y LLAMAR AL TEMPLATE PARA MAPA DE LECTURA ---
        $reading_data = $wpdb->get_results( $this->oiscl_trackpro_prepare_sql( "SELECT origin_url, context_text, SUM(clicks) as total_views, AVG(time_spent) as avg_read_time FROM $table_name WHERE anchor_text IN ($sql_block_in) AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY origin_url, context_text ORDER BY total_views DESC", array( $start_date, $end_date ), $report_scope ) );
        
        $reading_rows_data = [];
        if($reading_data) { 
            foreach($reading_data as $row) { 
                $time_fmt = ($row->avg_read_time >= 60) ? round($row->avg_read_time / 60, 1) . 'm' : round($row->avg_read_time) . 's'; 
                $reading_rows_data[] = [
                    'cols' => [
                        esc_html(basename($row->origin_url)),
                        "<code style='color:#666;'>" . esc_html($row->context_text) . "</code>",
                        "<b>{$row->total_views}</b>",
                        "<strong style='color:#722ed1;'>{$time_fmt}</strong>"
                    ]
                ];
            } 
        }

        if ( 'reading' === $ct_tab ) {
        $this->render_ois_component('advanced_table', [
            'id'       => 'table-reading',
            'title'    => 'Reading Map: Dwell Time by Block', // <-- Traducido
            'icon'     => '⏱️',
            'pdf'      => 'Reading_Map_Report',
            'csv'      => "?page=oiscl-trackpro-report&export_csv=lectura&start_date={$start_date}&end_date={$end_date}",
            'headers'  => [
                ['label' => 'Page Path', 'width' => '30%', 'type' => 'string'], // Traducido
                ['label' => 'Block / Section', 'width' => '40%', 'type' => 'string'], // Traducido
                ['label' => 'Total Views', 'width' => '15%', 'type' => 'numeric', 'align' => 'center'], // Traducido
                ['label' => 'Avg Dwell Time', 'width' => '15%', 'type' => 'numeric', 'align' => 'center'] // Traducido
            ],
            'rows'     => $reading_rows_data
        ]);
        }

        $this->render_ois_component('layout_end');
        ?>

        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var $ = jQuery;
            Chart.register(ChartDataLabels);
            
            // 1. Chart Hourly
            const hourlyEl = document.getElementById('oisclHourlyChart');
            if (hourlyEl) {
            const chartCtx = hourlyEl.getContext('2d');
            new Chart(chartCtx, { 
                type: 'bar', 
                data: { labels: <?php echo json_encode(array_map(function($i){return str_pad($i,2,'0',STR_PAD_LEFT).":00";}, range(0,23))); ?>, datasets: [{ data: <?php echo json_encode($hours_values); ?>, backgroundColor: 'rgba(26, 115, 232, 0.7)', borderRadius: 3 }] }, 
                options: { responsive: true, maintainAspectRatio: false, layout: { padding: { top: 20 } }, plugins: { legend: {display: false}, datalabels: { anchor: 'end', align: 'top', color: '#444', font: {weight: 'bold'}, formatter: function(v){ return v > 0 ? v : ''; } } } } 
            });
            }

            // 2. Chart 7 Days
            if (document.getElementById('oiscl7DaysMixedChart')) {
                new Chart(document.getElementById('oiscl7DaysMixedChart').getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: <?php echo json_encode($d7_labels); ?>,
                        datasets: [
                            { type: 'line', label: 'Actions (Clicks)', data: <?php echo json_encode($d7_a_arr); ?>, borderColor: '#f56e28', backgroundColor: '#f56e28', tension: 0.4, pointRadius: 3, fill: false },
                            { type: 'bar', label: 'Views', data: <?php echo json_encode($d7_v_arr); ?>, backgroundColor: 'rgba(26, 115, 232, 0.8)', borderRadius: 4 }
                        ]
                    },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 10 } } } }, scales: { y: { beginAtZero:true, grid: { color: '#f0f0f1' } }, x: { grid: { display: false } } } }
                });
            }

            // Paginación avanzada: setupAdvancedTable vive en layout_end; no duplicar aquí (evita handlers dobles).

            // Filtros 
            const btnFilter = document.getElementById('btn-filter-main'); 
            const filterMenu = document.getElementById('ois-filter-menu'); 
            const masterFilter = document.getElementById('ois-master-filter'); 
            const triggers = document.querySelectorAll('.oiscl-filter-trigger'); 

            if (btnFilter) {
                btnFilter.addEventListener('click', (e) => { e.stopPropagation(); filterMenu.classList.toggle('active'); }); 
                document.addEventListener('click', (e) => { if (!filterMenu.contains(e.target) && e.target !== btnFilter) filterMenu.classList.remove('active'); });
                
                function applyFilters() { 
                    let activeCount = 0; 
                    triggers.forEach(t => { if(t.checked) activeCount++; }); 
                    document.getElementById('filter-text').innerText = (activeCount === triggers.length) ? 'Filter: All' : 'Filter: Custom ('+activeCount+')'; 
                    
                    document.querySelectorAll('#table-clicks tbody tr.ois-row').forEach(row => { 
                        const cat = row.dataset.category; 
                        if (cat) {
                            const trigger = document.querySelector(`.oiscl-filter-trigger[data-cat="${cat}"]`);
                            if (trigger && trigger.checked) { row.classList.remove('ois-filtered-out'); } 
                            else { row.classList.add('ois-filtered-out'); }
                        }
                    }); 
                    $('#table-clicks').data('setPage')(1);
                    $('#table-clicks').data('drawFn')();
                }
                
                masterFilter.addEventListener('change', () => { triggers.forEach(t => t.checked = masterFilter.checked); applyFilters(); }); 
                triggers.forEach(checkbox => { checkbox.addEventListener('change', applyFilters); }); 
            }

            // Motor de Sorting (Igual a User Journey)
            $('.j-sortable').on('click', function() {
                var table = $(this).closest('table');
                var tbody = table.find('tbody');
                var rows = tbody.find('tr.ois-row').toArray();
                var colIndex = $(this).data('col');
                var type = $(this).data('type');
                var isAsc = $(this).hasClass('asc');

                table.find('th').removeClass('asc desc');
                table.find('.sort-icon').text('');
                $(this).addClass(isAsc ? 'desc' : 'asc');
                $(this).find('.sort-icon').text(isAsc ? ' ▼' : ' ▲');

                rows.sort(function(a, b) {
                    var valA = $(a).find('td').eq(colIndex).text().trim();
                    var valB = $(b).find('td').eq(colIndex).text().trim();

                    if (type === 'numeric') {
                        var numA = parseFloat(valA.replace(/[^0-9.-]+/g,"")) || 0;
                        var numB = parseFloat(valB.replace(/[^0-9.-]+/g,"")) || 0;
                        return isAsc ? numA - numB : numB - numA;
                    }
                    return isAsc ? valA.localeCompare(valB) : valB.localeCompare(valA);
                });

                tbody.empty().append(rows);
                table.data('setPage')(1);
                table.data('drawFn')();
            });

        });
        </script>
        <?php
    }

}
