<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait OISCL_Admin_Analytics_Trait {

    // ==========================================
    // MÓDULO 2: OIS ANALYTICS (Advanced Edition) version 0.53
    // ==========================================
    public function display_analytics_page() {
        if (!current_user_can('view_ois_analytics')) wp_die(esc_html__('Insufficient permissions.', 'ois-conversion-suite'));

        $user_id = get_current_user_id(); 
        $today = current_time('Y-m-d');
        
        // --- LÓGICA DE FECHAS SINCRONIZADA ---
        if (isset($_GET['preset'])) {
            $preset = sanitize_text_field($_GET['preset']);
            switch($preset) {
                case 'yesterday': $start_date = date('Y-m-d', strtotime($today . ' - 1 days')); $end_date = $start_date; $preset_label = "Yesterday"; break;
                case '7days': $start_date = date('Y-m-d', strtotime($today . ' - 6 days')); $end_date = $today; $preset_label = "Last 7 Days"; break;
                case '30days': $start_date = date('Y-m-d', strtotime($today . ' - 29 days')); $end_date = $today; $preset_label = "Last 30 Days"; break;
                default: $start_date = $today; $end_date = $today; $preset_label = "Today"; break;
            }
            set_transient('oiscl_pref_' . $user_id, ['start' => $start_date, 'end' => $end_date, 'label' => $preset_label, 'preset' => $preset], DAY_IN_SECONDS);
        } elseif (isset($_GET['start_date']) && isset($_GET['end_date'])) {
            $start_date = sanitize_text_field($_GET['start_date']); $end_date = sanitize_text_field($_GET['end_date']); $preset_label = ($start_date === $end_date) ? "Selected Day" : "Custom Range";
            set_transient('oiscl_pref_' . $user_id, ['start' => $start_date, 'end' => $end_date, 'label' => $preset_label, 'preset' => 'custom'], DAY_IN_SECONDS);
        } else {
            $saved = get_transient('oiscl_pref_' . $user_id);
            if ($saved) { $start_date = $saved['start']; $end_date = $saved['end']; $preset_label = $saved['label']; } 
            else { $start_date = date('Y-m-d', strtotime($today . ' - 6 days')); $end_date = $today; $preset_label = "Last 7 Days"; }
        }

        $date_cap = OISCL_Plan::clamp_report_dates( $start_date, $end_date, $today );
        $start_date = $date_cap['start_date'];
        $end_date   = $date_cap['end_date'];
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'oiscl_block_metrics';
        $oiscl_analytics_defer_nonce = wp_create_nonce( 'oiscl_analytics_defer' );

        // 1. CÁLCULOS DE DATOS Y DELTAS
        // Periodo previo (misma lógica que Global Dashboard: días inclusivos).
        $diff_days = max( 1, (int) round( ( strtotime( $end_date ) - strtotime( $start_date ) ) / 86400 ) + 1 );
        $prev_end   = date( 'Y-m-d', strtotime( $start_date . ' - 1 day' ) );
        $prev_start = date( 'Y-m-d', strtotime( $prev_end . ' - ' . ( $diff_days - 1 ) . ' days' ) );

        // Solución definitiva al "Doble Offset" de zonas horarias en WordPress
        $ois_now = date('Y-m-d H:i:s', strtotime(current_time('mysql')) - 300);
        $live_views = $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT session_id) FROM $table_name WHERE created_at >= %s", $ois_now)) ?: 0;
        
        $total_views = $wpdb->get_var($wpdb->prepare("SELECT SUM(clicks) FROM $table_name WHERE anchor_text='[Pageview]' AND DATE(created_at) >= %s AND DATE(created_at) <= %s", $start_date, $end_date)) ?: 0;
        $unique_users = $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT session_id) FROM $table_name WHERE anchor_text='[Pageview]' AND DATE(created_at) >= %s AND DATE(created_at) <= %s", $start_date, $end_date)) ?: 0;
        $total_clicks = $wpdb->get_var($wpdb->prepare("SELECT SUM(clicks) FROM $table_name WHERE anchor_text NOT IN ('[Pageview]', '[Vista de Bloque]') AND DATE(created_at) >= %s AND DATE(created_at) <= %s", $start_date, $end_date)) ?: 0;
        $actions_per_pv = $total_views > 0 ? round($total_clicks / $total_views, 2) : 0;
        $avg_time = $wpdb->get_var($wpdb->prepare("SELECT AVG(time_spent) FROM $table_name WHERE time_spent > 0 AND anchor_text IN ('[Pageview]', '[Vista de Bloque]') AND DATE(created_at) >= %s AND DATE(created_at) <= %s", $start_date, $end_date)) ?: 0;

        $prev_views = $wpdb->get_var($wpdb->prepare("SELECT SUM(clicks) FROM $table_name WHERE anchor_text='[Pageview]' AND DATE(created_at) >= %s AND DATE(created_at) <= %s", $prev_start, $prev_end)) ?: 0;
        $prev_uniques = $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT session_id) FROM $table_name WHERE anchor_text='[Pageview]' AND DATE(created_at) >= %s AND DATE(created_at) <= %s", $prev_start, $prev_end)) ?: 0;
        $prev_clicks = $wpdb->get_var($wpdb->prepare("SELECT SUM(clicks) FROM $table_name WHERE anchor_text NOT IN ('[Pageview]', '[Vista de Bloque]') AND DATE(created_at) >= %s AND DATE(created_at) <= %s", $prev_start, $prev_end)) ?: 0;
        $prev_actions_per_pv = $prev_views > 0 ? round($prev_clicks / $prev_views, 2) : 0;
        $prev_time = $wpdb->get_var($wpdb->prepare("SELECT AVG(time_spent) FROM $table_name WHERE time_spent > 0 AND anchor_text IN ('[Pageview]', '[Vista de Bloque]') AND DATE(created_at) >= %s AND DATE(created_at) <= %s", $prev_start, $prev_end)) ?: 0;

        

        // 2. DATOS PARA GRÁFICOS (HOURLY Y DAILY)
        $hourly_data = $wpdb->get_results($wpdb->prepare("SELECT HOUR(created_at) as hr, SUM(clicks) as views, COUNT(DISTINCT session_id) as uniques FROM $table_name WHERE anchor_text='[Pageview]' AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY hr", $start_date, $end_date));
        $bar_views = array_fill(0, 24, 0); $bar_uniques = array_fill(0, 24, 0);
        foreach($hourly_data as $h) { $bar_views[(int)$h->hr] = (int)$h->views; $bar_uniques[(int)$h->hr] = (int)$h->uniques; }

        $prev_hourly = $wpdb->get_results($wpdb->prepare("SELECT HOUR(created_at) as hr, SUM(clicks) as views, COUNT(DISTINCT session_id) as uniques FROM $table_name WHERE anchor_text='[Pageview]' AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY hr", $prev_start, $prev_end));
        $prev_bar_views = array_fill(0, 24, 0); $prev_bar_uniques = array_fill(0, 24, 0);
        foreach($prev_hourly as $h) { $prev_bar_views[(int)$h->hr] = (int)$h->views; $prev_bar_uniques[(int)$h->hr] = (int)$h->uniques; }

        // Lógica Inteligente para el Gráfico Diario (Daily)
        $chart_start_date = $start_date;
        $chart_end_date = $end_date;
        
        // Regla UX: Si el usuario selecciona 1 solo día (ej. "Hoy"), retrocedemos 6 días.
        // Un gráfico de barras con 1 sola barra gigante se ve mal, necesita contexto semanal.
        if (strtotime($chart_start_date) >= strtotime($chart_end_date)) {
            $chart_start_date = date('Y-m-d', strtotime($chart_end_date . ' - 6 days'));
        }

        $daily_db = $wpdb->get_results($wpdb->prepare("SELECT DATE(created_at) as dt, SUM(clicks) as views, COUNT(DISTINCT session_id) as uniques FROM $table_name WHERE anchor_text='[Pageview]' AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY dt ORDER BY dt ASC", $chart_start_date, $chart_end_date));
        
        // Rellenar días vacíos con 0 para que el eje X siempre coincida con el selector
        $period = new DatePeriod(new DateTime($chart_start_date), new DateInterval('P1D'), (new DateTime($chart_end_date))->modify('+1 day'));
        $daily_labels = []; $daily_views_map = []; $daily_uniques_map = [];
        
        foreach($period as $dt) { 
            $d = $dt->format('Y-m-d'); 
            $daily_labels[] = $dt->format('d M'); 
            $daily_views_map[$d] = 0; 
            $daily_uniques_map[$d] = 0; 
        }
        
        foreach($daily_db as $r) { 
            if(isset($daily_views_map[$r->dt])) { 
                $daily_views_map[$r->dt] = (int)$r->views; 
                $daily_uniques_map[$r->dt] = (int)$r->uniques; 
            } 
        }
        
        $daily_v_arr = array_values($daily_views_map); 
        $daily_u_arr = array_values($daily_uniques_map);

        // Content, Journey y Audience: datos al abrir cada pestaña (admin-ajax).

        // --- LÓGICA PARA TABLAS COMPARATIVAS (OVERVIEW) ---
        // 1. Fuentes de Tráfico (Current vs Past)
        $overview_sources = $wpdb->get_results($wpdb->prepare("SELECT destination_url as source, SUM(clicks) as views, COUNT(DISTINCT session_id) as uniques FROM $table_name WHERE anchor_text='[Pageview]' AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY source ORDER BY views DESC LIMIT 100", $start_date, $end_date));
        $prev_src_db = $wpdb->get_results($wpdb->prepare("SELECT destination_url as source, SUM(clicks) as views, COUNT(DISTINCT session_id) as uniques FROM $table_name WHERE anchor_text='[Pageview]' AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY source", $prev_start, $prev_end));
        $prev_src_map = []; foreach($prev_src_db as $p) { $prev_src_map[$p->source] = $p; }

        // 2. Páginas de Entrada (Current vs Past)
        $overview_pages = $wpdb->get_results($wpdb->prepare("SELECT SUBSTRING_INDEX(origin_url, '?', 1) as clean_url, SUM(clicks) as views, COUNT(DISTINCT session_id) as uniques FROM $table_name WHERE anchor_text='[Pageview]' AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY clean_url ORDER BY views DESC LIMIT 100", $start_date, $end_date));
        $prev_pg_db = $wpdb->get_results($wpdb->prepare("SELECT SUBSTRING_INDEX(origin_url, '?', 1) as clean_url, SUM(clicks) as views, COUNT(DISTINCT session_id) as uniques FROM $table_name WHERE anchor_text='[Pageview]' AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY clean_url", $prev_start, $prev_end));
        $prev_pg_map = []; foreach($prev_pg_db as $p) { $prev_pg_map[$p->clean_url] = $p; }
        
        // 3. Ciudades y Ubicaciones (Current vs Past)
        $overview_cities = $wpdb->get_results($wpdb->prepare("SELECT city, country, SUM(clicks) as views, COUNT(DISTINCT session_id) as uniques FROM $table_name WHERE anchor_text='[Pageview]' AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY city, country ORDER BY views DESC LIMIT 100", $start_date, $end_date));
        $prev_city_db = $wpdb->get_results($wpdb->prepare("SELECT city, country, SUM(clicks) as views, COUNT(DISTINCT session_id) as uniques FROM $table_name WHERE anchor_text='[Pageview]' AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY city, country", $prev_start, $prev_end));
        $prev_city_map = []; foreach($prev_city_db as $c) { $prev_city_map[$c->city . '|' . $c->country] = $c; }
        
        // Helper para mini-deltas en tablas
        $mini_delta = function($curr, $prev) {
            $prev = (float) $prev;
            $curr = (float) $curr;
            if ($prev == 0.0 && $curr == 0.0) {
                return '';
            }
            if ($prev == 0.0) {
                return "<span style='font-size:10px; color:#46b450; margin-left:6px;' title='Past: 0'>▲ 100%</span>";
            }
            $pct = (($curr - $prev) / $prev) * 100;
            $color = $pct > 0 ? '#46b450' : ($pct < 0 ? '#d63638' : '#999');
            $icon = $pct > 0 ? '▲' : ($pct < 0 ? '▼' : '—');
            return "<span style='font-size:10px; color:{$color}; margin-left:6px; font-weight:bold;' title='Past: {$prev}'>{$icon} ".round(abs($pct))."%</span>";
        };
        
        // 1. ABRIR LAYOUT UNIFICADO
        $this->render_ois_component('layout_start', array('id' => 'oiscl-analytics-wrap'));

        // 2. HEADER UNIFICADO Y KPIs
        $this->render_ois_component('header', array(
            'title'      => '📊 OIS Analytics',
            'start_date' => $start_date,
            'end_date'   => $end_date,
            'preset'     => $preset_label,
            'page_slug'  => 'oiscl-analytics',
            'live_val'   => $live_views,
            'kpis'       => array(
                array('label' => 'LIVE NOW', 'value' => $live_views, 'color' => ($live_views > 0 ? '#46b450' : '#d63638'), 'is_live' => true),
                array('label' => 'TOTAL VISITS', 'value' => number_format($total_views), 'color' => '#1a73e8', 'delta' => $this->format_kpi_delta($total_views, $prev_views), 'icon' => '👁️'),
                array('label' => 'UNIQUE USERS', 'value' => number_format($unique_users), 'color' => '#46b450', 'delta' => $this->format_kpi_delta($unique_users, $prev_uniques), 'icon' => '👤'),
                array('label' => 'ACTIONS / PV', 'value' => (string) $actions_per_pv, 'color' => '#f56e28', 'delta' => $this->format_kpi_delta($actions_per_pv, $prev_actions_per_pv), 'icon' => '🖱️'),
                array('label' => 'AVG RETENTION', 'value' => ($avg_time >= 60 ? round($avg_time/60, 1).'m' : round($avg_time).'s'), 'color' => '#722ed1', 'delta' => $this->format_kpi_delta($avg_time, $prev_time), 'icon' => '⏱️')
            )
        ));
        
        

        echo '<div class="oiscl-tabstrip ois-tabs" role="tablist">';
            echo '<button type="button" role="tab" class="ois-tab-btn active" data-target="tab-overview">📊 Overview</button>';
            echo '<button type="button" role="tab" class="ois-tab-btn" data-target="tab-content">🎯 Content & CRO</button>';
            echo '<button type="button" role="tab" class="ois-tab-btn" data-target="tab-audience">👥 Audience</button>';
            echo '<button type="button" role="tab" class="ois-tab-btn" data-target="tab-journey">👣 User Journey</button>';
        echo '</div>';

        // TAB 1: OVERVIEW
        // TAB 1: OVERVIEW v0.3
        echo '<div id="tab-overview" class="ois-tab-pane active">';
            
            // Lógica de colores para Hourly Chart
            $v_color = ($total_views >= $prev_views) ? '#46b450' : '#d63638';
            $v_icon  = ($total_views >= $prev_views) ? '▲' : '▼';
            $u_color = ($unique_users >= $prev_uniques) ? '#46b450' : '#d63638';
            $u_icon  = ($unique_users >= $prev_uniques) ? '▲' : '▼';

            $chart_export_nonce = wp_create_nonce( 'oiscl_export_chart' );
            $csv_hourly_url     = esc_url(
                add_query_arg(
                    array(
                        'page'         => 'oiscl-analytics',
                        'export_chart' => 'hourly_traffic',
                        'start_date'   => $start_date,
                        'end_date'     => $end_date,
                        'oiscl_nonce'  => $chart_export_nonce,
                    ),
                    admin_url( 'admin.php' )
                )
            );
            $csv_daily_url      = esc_url(
                add_query_arg(
                    array(
                        'page'         => 'oiscl-analytics',
                        'export_chart' => 'daily_traffic',
                        'start_date'   => $start_date,
                        'end_date'     => $end_date,
                        'oiscl_nonce'  => $chart_export_nonce,
                    ),
                    admin_url( 'admin.php' )
                )
            );

            // 1. LLAMADA AL WIDGET: HOURLY TRAFFIC
            $this->render_ois_chart_widget(array(
                'id'    => 'mainTrafficChart',
                'title' => '🕒 Hourly Traffic Insights',
                'export_csv_url' => $csv_hourly_url,
                'export_png_filename' => 'hourly-traffic.png',
                'export_pdf_title' => __( 'Hourly traffic', 'ois-conversion-suite' ),
                'stats' => array(
                    array(
                        'value'     => number_format($total_views) . ' Views',
                        'val_color' => '#1a73e8',
                        'subtext'   => $v_icon . ' ' . number_format($prev_views) . ' (Past)',
                        'sub_color' => $v_color
                    ),
                    array(
                        'value'     => number_format($unique_users) . ' Uniques',
                        'val_color' => '#d63638',
                        'subtext'   => $u_icon . ' ' . number_format($prev_uniques) . ' (Past)',
                        'sub_color' => $u_color
                    )
                ),
                'tip'   => '💡 <strong>Note:</strong> Each hour (0–23) sums <strong>all</strong> pageviews in that clock hour across <em>every day</em> in the selected range, then compares to the previous period. For a single day’s shape, pick a 1-day range. Legend clicks toggle series.'
            ));

            // 2. LLAMADA AL WIDGET: DAILY TRAFFIC (Preparado para Barras)
            $daily_v_total = array_sum($daily_v_arr);
            $daily_u_total = array_sum($daily_u_arr);

            $this->render_ois_chart_widget(array(
                'id'    => 'dailyTrafficChart',
                'title' => '📅 Daily Traffic (Volume & Uniques)',
                'export_csv_url' => $csv_daily_url,
                'export_png_filename' => 'daily-traffic.png',
                'export_pdf_title' => __( 'Daily traffic', 'ois-conversion-suite' ),
                'stats' => array(
                    array(
                        'value'     => number_format($daily_v_total) . ' Views',
                        'val_color' => '#1a73e8',
                        'subtext'   => 'Total Range',
                        'sub_color' => '#94a3b8'
                    ),
                    array(
                        'value'     => number_format($daily_u_total) . ' Uniques',
                        'val_color' => '#d63638',
                        'subtext'   => 'Total Range',
                        'sub_color' => '#94a3b8'
                    )
                ),
                'tip'   => '💡 <strong>Tip:</strong> Visualización en barras comparativas. Útil para identificar rápidamente los mejores días de la semana o del mes.'
            ));
            
            // --- COMPARATIVE TABLES (OVERVIEW): misma grilla y columnas que tab Audience (Top Traffic Sources) ---
            echo '<div class="ois-analytics-card-grid">';

        $headers_ov_src = [
            ['label' => 'Source', 'sortable' => true],
            ['label' => 'Views', 'align' => 'right', 'sortable' => true],
            ['label' => 'vs Past', 'align' => 'right'],
            ['label' => 'Uniques', 'align' => 'right', 'sortable' => true],
            ['label' => 'vs Past', 'align' => 'right'],
        ];
        $headers_ov_pg = [
            ['label' => 'Page', 'sortable' => true],
            ['label' => 'Views', 'align' => 'right', 'sortable' => true],
            ['label' => 'vs Past', 'align' => 'right'],
            ['label' => 'Uniques', 'align' => 'right', 'sortable' => true],
            ['label' => 'vs Past', 'align' => 'right'],
        ];
        $headers_ov_loc = [
            ['label' => 'Location', 'sortable' => true],
            ['label' => 'Views', 'align' => 'right', 'sortable' => true],
            ['label' => 'vs Past', 'align' => 'right'],
            ['label' => 'Uniques', 'align' => 'right', 'sortable' => true],
            ['label' => 'vs Past', 'align' => 'right'],
        ];
        $src_rows                 = [];
        foreach ( $overview_sources as $s ) {
            $p_v      = isset( $prev_src_map[ $s->source ] ) ? $prev_src_map[ $s->source ]->views : 0;
            $p_u      = isset( $prev_src_map[ $s->source ] ) ? $prev_src_map[ $s->source ]->uniques : 0;
            $src_name = empty( $s->source ) ? 'Direct / Unknown' : esc_html( $s->source );
            $src_rows[] = [
                ['value' => '<strong>' . $src_name . '</strong>'],
                ['value' => '<b>' . $s->views . '</b>', 'align' => 'right'],
                ['value' => $mini_delta( $s->views, $p_v ), 'align' => 'right'],
                ['value' => $s->uniques, 'align' => 'right', 'bold' => true],
                ['value' => $mini_delta( $s->uniques, $p_u ), 'align' => 'right'],
            ];
        }
        $this->render_ois_component( 'data_table', [ 'id' => 'tbl-ov-src', 'title' => 'Top Traffic Sources', 'icon' => '🔗', 'headers' => $headers_ov_src, 'rows' => $src_rows ] );

        // 2. Top Pages
        $pg_rows = [];
        foreach ( $overview_pages as $p ) {
            $p_v = isset( $prev_pg_map[ $p->clean_url ] ) ? $prev_pg_map[ $p->clean_url ]->views : 0;
            $p_u = isset( $prev_pg_map[ $p->clean_url ] ) ? $prev_pg_map[ $p->clean_url ]->uniques : 0;
            $pg_rows[] = [
                ['value' => '<strong>' . esc_html( basename( $p->clean_url ) ?: 'Home' ) . '</strong>'],
                ['value' => '<b>' . $p->views . '</b>', 'align' => 'right'],
                ['value' => $mini_delta( $p->views, $p_v ), 'align' => 'right'],
                ['value' => $p->uniques, 'align' => 'right', 'bold' => true],
                ['value' => $mini_delta( $p->uniques, $p_u ), 'align' => 'right'],
            ];
        }
        $this->render_ois_component( 'data_table', [ 'id' => 'tbl-ov-pg', 'title' => 'Top Pages', 'icon' => '📄', 'headers' => $headers_ov_pg, 'rows' => $pg_rows ] );

        // 3. Top Locations
        $cit_rows = [];
        foreach ( $overview_cities as $c ) {
            $key      = $c->city . '|' . $c->country;
            $p_v      = isset( $prev_city_map[ $key ] ) ? $prev_city_map[ $key ]->views : 0;
            $p_u      = isset( $prev_city_map[ $key ] ) ? $prev_city_map[ $key ]->uniques : 0;
            $loc_name = empty( $c->city ) ? 'Unknown' : esc_html( $c->city . ', ' . $c->country );
            $cit_rows[] = [
                ['value' => '<strong>' . $loc_name . '</strong>'],
                ['value' => '<b>' . $c->views . '</b>', 'align' => 'right'],
                ['value' => $mini_delta( $c->views, $p_v ), 'align' => 'right'],
                ['value' => $c->uniques, 'align' => 'right', 'bold' => true],
                ['value' => $mini_delta( $c->uniques, $p_u ), 'align' => 'right'],
            ];
        }
        $this->render_ois_component( 'data_table', [ 'id' => 'tbl-ov-cit', 'title' => 'Top Locations', 'icon' => '📍', 'headers' => $headers_ov_loc, 'rows' => $cit_rows ] );

        echo '</div>';
    echo '</div>'; // <-- CIERRE DEL TAB OVERVIEW

    // TAB 2: CONTENT & CRO (fragmento vía AJAX)
    echo '<div id="tab-content" class="ois-tab-pane">';
        echo '<div id="oiscl-defer-content-inner" class="oiscl-defer-skel"><span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span> ' . esc_html__( 'Loading Content & CRO…', 'ois-conversion-suite' ) . '</div>';
    echo '</div>';

    // TAB 3: AUDIENCE (fragmento vía AJAX; scripts de gráficos se ejecutan tras inyección)
    echo '<div id="tab-audience" class="ois-tab-pane">';
        echo '<div id="oiscl-defer-audience-inner" class="oiscl-defer-skel"><span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span> ' . esc_html__( 'Loading Audience…', 'ois-conversion-suite' ) . '</div>';
    echo '</div>';

    // TAB 4: USER JOURNEY (fragmento vía AJAX; advanced_table se enlaza tras la inyección)
    echo '<div id="tab-journey" class="ois-tab-pane">';
        echo '<div id="oiscl-defer-journey-inner" class="oiscl-defer-skel"><span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span> ' . esc_html__( 'Loading User Journey…', 'ois-conversion-suite' ) . '</div>';
    echo '</div>'; // FIN TAB JOURNEY

    // 4. CERRAR LAYOUT UNIFICADO
    $this->render_ois_component('layout_end');

        // SCRIPTS
        ?>
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script>
        (function($) {
            $(function() {
                
                // Navegación de Tabs con Persistencia v0.3
                // Navegación de Tabs con Persistencia de URL y Enlaces de Fecha
                var oisclDeferLoaded = { content: false, journey: false, audience: false };

                function oisclMaybeLoadDeferredAnalyticsTab(targetId) {
                    if (targetId === 'tab-content' && !oisclDeferLoaded.content) {
                        oisclDeferLoaded.content = true;
                        oisclLoadDeferredTab('content', $('#oiscl-defer-content-inner'));
                    } else if (targetId === 'tab-audience' && !oisclDeferLoaded.audience) {
                        oisclDeferLoaded.audience = true;
                        oisclLoadDeferredTab('audience', $('#oiscl-defer-audience-inner'));
                    } else if (targetId === 'tab-journey' && !oisclDeferLoaded.journey) {
                        oisclDeferLoaded.journey = true;
                        oisclLoadDeferredTab('journey', $('#oiscl-defer-journey-inner'));
                    }
                }

                $('.ois-tab-btn').on('click', function(e) {
                    e.preventDefault();
                    var targetId = $(this).data('target');
                    $('.ois-tab-btn, .ois-tab-pane').removeClass('active');
                    $(this).addClass('active');
                    $('#' + targetId).addClass('active');

                    oisclMaybeLoadDeferredAnalyticsTab(targetId);
                    
                    var newUrl = new URL(window.location.href);
                    newUrl.searchParams.set('tab', targetId);
                    window.history.replaceState({path: newUrl.href}, '', newUrl.href);

                    // --- NUEVO: Sincronizar links de fecha con el TAB activo ---
                    $('.ois-date-selector-container').find('a, form').each(function() {
                        if ($(this).is('a')) {
                            var linkUrl = new URL(this.href, window.location.origin);
                            linkUrl.searchParams.set('tab', targetId);
                            this.href = linkUrl.toString();
                        } else if ($(this).is('form')) {
                            $(this).find('.ois-hidden-tab-input').val(targetId);
                        }
                    });

                    if (typeof Chart !== 'undefined' && typeof Chart.getChart === 'function') {
                        document.querySelectorAll('canvas').forEach(function(c) {
                            var ch = Chart.getChart(c);
                            if (ch) { ch.resize(); }
                        });
                    }
                });

                // Detectar Pestaña Activa al cargar la página
                var urlParams = new URLSearchParams(window.location.search);
                var activeTab = urlParams.get('tab');
                if (activeTab && $('#' + activeTab).length) {
                    $('.ois-tab-btn[data-target="' + activeTab + '"]').click();
                }

                // Inicialización de Gráficos
                // Gráfico por Horas (Vectores)
                const ctxH = document.getElementById('mainTrafficChart').getContext('2d');
                new Chart(ctxH, {
                    type: 'line',
                    data: {
                        labels: <?php echo json_encode(array_map(fn($i) => "$i:00", range(0,23))); ?>,
                        datasets: [
                            { 
                                label: 'Views', 
                                data: <?php echo json_encode($bar_views); ?>, 
                                borderColor: '#1a73e8', 
                                backgroundColor: 'rgba(26, 115, 232, 0.1)',
                                fill: true,
                                tension: 0.4,
                                pointRadius: 3
                            },
                            { 
                                label: 'Uniques', 
                                data: <?php echo json_encode($bar_uniques); ?>, 
                                borderColor: '#d63638', 
                                backgroundColor: 'transparent',
                                tension: 0.4,
                                pointRadius: 3
                            },
                            { 
                                label: 'Past Views', 
                                data: <?php echo json_encode($prev_bar_views); ?>, 
                                borderColor: '#1a73e8', 
                                borderDash: [5, 5], // Línea punteada
                                backgroundColor: 'transparent',
                                fill: false,
                                tension: 0.4,
                                pointRadius: 0 // Sin puntos para no ensuciar
                            },
                            { 
                                label: 'Past Uniques', 
                                data: <?php echo json_encode($prev_bar_uniques); ?>, 
                                borderColor: '#d63638', 
                                borderDash: [5, 5], // Línea punteada
                                backgroundColor: 'transparent',
                                fill: false,
                                tension: 0.4,
                                pointRadius: 0
                            }
                        ]
                    },
                    options: { 
                        responsive: true, 
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: { padding: 20, usePointStyle: true }
                            },
                            tooltip: { mode: 'index', intersect: false }
                        },
                        scales: {
                            y: { beginAtZero: true, grid: { color: '#f0f0f1' } },
                            x: { grid: { display: false } }
                        }
                    }
                });

                const ctxD = document.getElementById('dailyTrafficChart').getContext('2d');
                new Chart(ctxD, {
                    type: 'bar', // ¡Cambiado a barras!
                    data: {
                        labels: <?php echo json_encode($daily_labels); ?>,
                        datasets: [
                            { 
                                label: 'Views', 
                                data: <?php echo json_encode($daily_v_arr); ?>, 
                                backgroundColor: '#1a73e8', 
                                borderRadius: 4 // Bordes redondeados para que se vea moderno
                            },
                            { 
                                label: 'Uniques', 
                                data: <?php echo json_encode($daily_u_arr); ?>, 
                                backgroundColor: '#d63638', 
                                borderRadius: 4
                            }
                        ]
                    },
                    options: { 
                        responsive: true, 
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { position: 'bottom', labels: { padding: 20, usePointStyle: true } },
                            tooltip: { mode: 'index', intersect: false }
                        },
                        scales: {
                            y: { beginAtZero: true, grid: { color: '#f0f0f1' } },
                            x: { grid: { display: false } }
                        }
                    }
                });

                // --- MOTOR DE TABLAS WIDGET ---
                function updatePageTotals(tableId) {
                    var $table = $('#' + tableId);
                    var $footer = $table.find('.ois-page-totals-row');
                    if(!$footer.length) return;

                    // En lugar de :visible, calculamos matemáticamente el segmento activo
                    var pageSize = parseInt($table.attr('data-page-size')) || 6;
                    var currentPage = parseInt($table.attr('data-current-page')) || 1;
                    var $activeRows = $table.find('tbody tr').slice((currentPage - 1) * pageSize, currentPage * pageSize);

                    var totals = [];
                    $activeRows.each(function() {
                        $(this).find('td').each(function(idx) {
                            var text = $(this).text().replace(/▲|▼|—/g, '').replace(/Past: \d+/g, '').replace(/,/g, '').trim();
                            var val = parseFloat(text);
                            if (!totals[idx]) totals[idx] = 0;
                            // Sumamos solo si es número, no es un porcentaje y no es tiempo
                            if (!isNaN(val) && !text.includes('%') && !text.includes('s') && !text.includes('m') && text !== '-') {
                                totals[idx] += val;
                            }
                        });
                    });

                    $footer.find('td').each(function(idx) {
                        if (idx === 0) return; // Saltamos 'PAGE TOTAL'
                        if (idx === 1 || idx === 3) { // Columnas exactas de Views y Actions
                            $(this).html('<b>' + totals[idx].toLocaleString() + '</b>');
                        }
                    });
                }

                function updateTablePagination(tableId) {
                    var $table = $('#' + tableId);
                    var pageSize = parseInt($table.attr('data-page-size'));
                    var currentPage = parseInt($table.attr('data-current-page'));
                    var $rows = $table.find('tbody tr');
                    var totalRows = $rows.length;
                    var totalPages = Math.ceil(totalRows / pageSize) || 1;

                    if (currentPage > totalPages) currentPage = totalPages;
                    if (currentPage < 1) currentPage = 1;
                    $table.attr('data-current-page', currentPage);

                    $rows.hide();
                    $rows.slice((currentPage - 1) * pageSize, currentPage * pageSize).show();
                    $('#pag-cur-' + tableId).text(currentPage);
                    
                    updatePageTotals(tableId); // Calcular subtotales
                }

                window.oisclAnalyticsRefreshDataTables = function() {
                    $('.ois-table-dashboard').each(function() {
                        var id = $(this).attr('id');
                        if (id) updateTablePagination(id);
                    });
                };

                // Sorting Engine
                $('.ois-table-dashboard th.ois-sortable').on('click', function() {
                    var table = $(this).closest('table');
                    var tbody = table.find('tbody');
                    var rows = tbody.find('tr').toArray();
                    var colIndex = $(this).data('col');
                    var isAsc = $(this).hasClass('asc');

                    table.find('th').removeClass('asc desc');
                    $(this).addClass(isAsc ? 'desc' : 'asc');

                    rows.sort(function(a, b) {
                        var valA = $(a).find('td').eq(colIndex).text().replace(/▲|▼|—/g, '').replace(/Past: \d+/g, '').trim();
                        var valB = $(b).find('td').eq(colIndex).text().replace(/▲|▼|—/g, '').replace(/Past: \d+/g, '').trim();

                        function parse(v) {
                            if(v.includes('m')) return parseFloat(v)*60;
                            if(v.includes('s')) return parseFloat(v);
                            return parseFloat(v.replace(/,/g, '')) || v;
                        }

                        var numA = parse(valA), numB = parse(valB);
                        if($.isNumeric(numA) && $.isNumeric(numB)) {
                            return isAsc ? numA - numB : numB - numA;
                        }
                        return isAsc ? valA.localeCompare(valB) : valB.localeCompare(valA);
                    });

                    tbody.empty().append(rows);
                    table.attr('data-current-page', 1);
                    updateTablePagination(table.attr('id'));
                });

                // Interacciones Paginador
                $('.ois-row-selector').on('change', function() {
                    var target = $(this).data('target');
                    $('#' + target).attr('data-page-size', $(this).val()).attr('data-current-page', 1);
                    updateTablePagination(target);
                });
                $('.ois-pag-prev').on('click', function() {
                    var target = $(this).data('target');
                    var cur = parseInt($('#' + target).attr('data-current-page'));
                    if (cur > 1) { $('#' + target).attr('data-current-page', cur - 1); updateTablePagination(target); }
                });
                $('.ois-pag-next').on('click', function() {
                    var target = $(this).data('target');
                    var $table = $('#' + target);
                    var cur = parseInt($table.attr('data-current-page'));
                    var max = Math.ceil($table.find('tbody tr').length / parseInt($table.attr('data-page-size')));
                    if (cur < max) { $table.attr('data-current-page', cur + 1); updateTablePagination(target); }
                });

                // Inicializar Paginación Front-end
                $('.ois-table-dashboard').each(function() { updateTablePagination($(this).attr('id')); });

                var oisclDeferCfg = <?php echo wp_json_encode(
                    array(
                        'ajaxurl' => admin_url( 'admin-ajax.php' ),
                        'action'  => 'oiscl_analytics_defer',
                        'nonce'   => $oiscl_analytics_defer_nonce,
                        'start'   => $start_date,
                        'end'     => $end_date,
                    )
                ); ?>;

                function oisclLoadDeferredTab(part, $inner) {
                    if (!$inner.length) return;
                    $.post(
                        oisclDeferCfg.ajaxurl,
                        {
                            action: oisclDeferCfg.action,
                            nonce: oisclDeferCfg.nonce,
                            part: part,
                            start_date: oisclDeferCfg.start,
                            end_date: oisclDeferCfg.end
                        }
                    ).done(function(res) {
                        if (!res || !res.success || !res.data || !res.data.html) {
                            $inner.html('<p class="description">' + <?php echo wp_json_encode( esc_html__( 'Could not load this tab.', 'ois-conversion-suite' ) ); ?> + '</p>');
                            return;
                        }
                        $inner.html(res.data.html);
                        if (part === 'audience') {
                            $inner.find('script').each(function() {
                                var code = (this.text || this.textContent || '').trim();
                                if (code && typeof jQuery !== 'undefined' && jQuery.globalEval) {
                                    jQuery.globalEval(code);
                                } else if (code) {
                                    window.eval(code);
                                }
                            });
                        }
                        if (typeof window.oisclAnalyticsRefreshDataTables === 'function') {
                            window.oisclAnalyticsRefreshDataTables();
                        }
                        if (part === 'journey' && typeof window.oisclSetupAdvancedTable === 'function' && document.getElementById('table-journey')) {
                            window.oisclSetupAdvancedTable('table-journey');
                        }
                        if (typeof Chart !== 'undefined' && typeof Chart.getChart === 'function') {
                            document.querySelectorAll('canvas').forEach(function(c) {
                                var ch = Chart.getChart(c);
                                if (ch) { ch.resize(); }
                            });
                        }
                    }).fail(function() {
                        $inner.html('<p class="description">' + <?php echo wp_json_encode( esc_html__( 'Network error.', 'ois-conversion-suite' ) ); ?> + '</p>');
                    });
                }

                // Lógica de Descarga (CSV / PDF)
                $('.ois-export-btn').on('click', function(e) {
                    e.preventDefault();
                    var type = $(this).data('type');
                    var tableId = $(this).data('target');
                    var $table = $('#' + tableId);
                    var title = $(this).closest('.ois-box').find('h3').text().replace(/[^a-zA-Z0-9]/g, '_');
                    
                    if (type === 'csv') {
                        var csv = [];
                        $table.find('tr').each(function() {
                            var row = [];
                            $(this).find('th, td').each(function() {
                                // Limpia flechas, tooltips ocultos y doble espacios
                                var text = $(this).text().replace(/▲|▼|—/g, '').replace(/Past: \d+/g, '').replace(/\s+/g, ' ').trim();
                                row.push('"' + text + '"');
                            });
                            csv.push(row.join(','));
                        });
                        var blob = new Blob([csv.join('\n')], { type: 'text/csv' });
                        var link = document.createElement('a');
                        link.href = window.URL.createObjectURL(blob);
                        link.download = 'OIS_' + title + '.csv';
                        link.click();
                    } 
                    else if (type === 'pdf') {
                        var printWin = window.open('', '', 'width=800,height=600');
                        printWin.document.write('<html><head><title>Export Report</title>');
                        printWin.document.write('<style>body{font-family:sans-serif;} table{width:100%; border-collapse:collapse; margin-top:20px;} th,td{border:1px solid #ccc; padding:10px; text-align:left;} th{background:#f1f5f9;}</style>');
                        printWin.document.write('</head><body><h2>' + title.replace(/_/g, ' ') + '</h2>');
                        var $clone = $table.clone();
                        $clone.find('tr').show(); // Imprimir todas las filas, no solo la página actual
                        printWin.document.write($clone.prop('outerHTML'));
                        printWin.document.write('</body></html>');
                        printWin.document.close();
                        printWin.focus();
                        setTimeout(function(){ printWin.print(); printWin.close(); }, 500);
                    }
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    /**
     * AJAX: fragmentos HTML para pestañas pesadas de Analytics.
     */
    public function ajax_oiscl_analytics_defer() {
        if ( ! current_user_can( 'view_ois_analytics' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'ois-conversion-suite' ) ), 403 );
        }
        check_ajax_referer( 'oiscl_analytics_defer', 'nonce' );

        $part = isset( $_POST['part'] ) ? sanitize_key( wp_unslash( $_POST['part'] ) ) : '';
        $start_date = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
        $end_date   = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';

        if ( '' === $start_date || '' === $end_date ) {
            wp_send_json_error( array( 'message' => __( 'Missing date range.', 'ois-conversion-suite' ) ), 400 );
        }

        ob_start();
        if ( 'content' === $part ) {
            $this->oiscl_render_analytics_deferred_content( $start_date, $end_date );
        } elseif ( 'journey' === $part ) {
            $this->oiscl_render_analytics_deferred_journey( $start_date, $end_date );
        } elseif ( 'audience' === $part ) {
            $this->oiscl_render_analytics_deferred_audience( $start_date, $end_date );
        } else {
            ob_end_clean();
            wp_send_json_error( array( 'message' => __( 'Invalid request.', 'ois-conversion-suite' ) ), 400 );
        }
        $html = (string) ob_get_clean();
        wp_send_json_success( array( 'html' => $html ) );
    }

    /**
     * HTML de la pestaña Content & CRO (solo vía AJAX).
     */
    private function oiscl_render_analytics_deferred_content( $start_date, $end_date ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'oiscl_block_metrics';

        $diff_days = max( 1, (int) round( ( strtotime( $end_date ) - strtotime( $start_date ) ) / 86400 ) + 1 );
        $prev_end   = date( 'Y-m-d', strtotime( $start_date . ' - 1 day' ) );
        $prev_start = date( 'Y-m-d', strtotime( $prev_end . ' - ' . ( $diff_days - 1 ) . ' days' ) );

        $top_pages_cro = $wpdb->get_results( $wpdb->prepare( "SELECT SUBSTRING_INDEX(origin_url, '?', 1) as clean_url, SUM(CASE WHEN anchor_text='[Pageview]' THEN clicks ELSE 0 END) as views, SUM(CASE WHEN anchor_text NOT IN ('[Pageview]', '[Vista de Bloque]') THEN clicks ELSE 0 END) as actions, AVG(CASE WHEN time_spent > 0 THEN time_spent ELSE NULL END) as avg_dwell FROM $table_name WHERE DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY clean_url HAVING views > 0 ORDER BY views DESC LIMIT 100", $start_date, $end_date ) );

        $prev_pages_cro_db = $wpdb->get_results( $wpdb->prepare( "SELECT SUBSTRING_INDEX(origin_url, '?', 1) as clean_url, SUM(CASE WHEN anchor_text='[Pageview]' THEN clicks ELSE 0 END) as views, SUM(CASE WHEN anchor_text NOT IN ('[Pageview]', '[Vista de Bloque]') THEN clicks ELSE 0 END) as actions, AVG(CASE WHEN time_spent > 0 THEN time_spent ELSE NULL END) as avg_dwell FROM $table_name WHERE DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY clean_url", $prev_start, $prev_end ) );
        $prev_pages_cro_map = array();
        foreach ( $prev_pages_cro_db as $p ) {
            $prev_pages_cro_map[ $p->clean_url ] = $p;
        }

        $top_blocks = $wpdb->get_results( $wpdb->prepare( "SELECT context_text as block_name, SUM(clicks) as total_clicks, AVG(time_spent) as avg_time FROM $table_name WHERE anchor_text NOT IN ('[Pageview]', '[Vista de Bloque]') AND context_text != '' AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY context_text ORDER BY total_clicks DESC LIMIT 100", $start_date, $end_date ) );

        $top_anchors = $wpdb->get_results( $wpdb->prepare( "SELECT anchor_text as anchor, SUM(clicks) as total_clicks FROM $table_name WHERE anchor_text NOT IN ('[Pageview]', '[Vista de Bloque]') AND anchor_text != '' AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY anchor_text ORDER BY total_clicks DESC LIMIT 100", $start_date, $end_date ) );

        $top_campaigns = $wpdb->get_results( $wpdb->prepare( "SELECT utm_campaign, COUNT(DISTINCT session_id) as uniques FROM $table_name WHERE utm_campaign != '' AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY utm_campaign ORDER BY uniques DESC LIMIT 50", $start_date, $end_date ) );

        $exit_pages_db = $wpdb->get_results(
            $wpdb->prepare(
                "
            SELECT SUBSTRING_INDEX(origin_url, '?', 1) as clean_url, COUNT(*) as exit_count 
            FROM $table_name 
            WHERE id IN (
                SELECT MAX(id) FROM $table_name 
                WHERE anchor_text='[Pageview]' AND DATE(created_at) >= %s AND DATE(created_at) <= %s 
                GROUP BY session_id
            )
            GROUP BY clean_url 
            ORDER BY exit_count DESC 
            LIMIT 50",
                $start_date,
                $end_date
            )
        );

        $format_time = function ( $sec ) {
            if ( $sec <= 0 ) {
                return '—';
            }
            return ( $sec >= 60 ) ? round( $sec / 60, 1 ) . 'm' : round( $sec ) . 's';
        };

        $mini_delta = function ( $curr, $prev ) {
            $prev = (float) $prev;
            $curr = (float) $curr;
            if ( 0.0 === $prev && 0.0 === $curr ) {
                return '';
            }
            if ( 0.0 === $prev ) {
                return "<span style='font-size:10px; color:#46b450; margin-left:6px;' title='Past: 0'>▲ 100%</span>";
            }
            $pct   = ( ( $curr - $prev ) / $prev ) * 100;
            $color = $pct > 0 ? '#46b450' : ( $pct < 0 ? '#d63638' : '#999' );
            $icon  = $pct > 0 ? '▲' : ( $pct < 0 ? '▼' : '—' );
            return "<span style='font-size:10px; color:{$color}; margin-left:6px; font-weight:bold;' title='Past: {$prev}'>{$icon} " . round( abs( $pct ) ) . '%</span>';
        };

        echo '<style>
            #tbl-pages-cro-v2 th:nth-child(2), #tbl-pages-cro-v2 td:nth-child(2),
            #tbl-pages-cro-v2 th:nth-child(4), #tbl-pages-cro-v2 td:nth-child(4),
            #tbl-pages-cro-v2 th:nth-child(6), #tbl-pages-cro-v2 td:nth-child(6),
            #tbl-pages-cro-v2 th:nth-child(8), #tbl-pages-cro-v2 td:nth-child(8) { border-left: 2px solid #e2e8f0; }
            #tbl-pages-cro-v2 th:nth-child(3), #tbl-pages-cro-v2 td:nth-child(3),
            #tbl-pages-cro-v2 th:nth-child(5), #tbl-pages-cro-v2 td:nth-child(5),
            #tbl-pages-cro-v2 th:nth-child(7), #tbl-pages-cro-v2 td:nth-child(7),
            #tbl-pages-cro-v2 th:nth-child(9), #tbl-pages-cro-v2 td:nth-child(9) { border-right: 2px solid #e2e8f0; background-color: #f8fafc; }
        </style>';

        echo '<div style="margin-bottom:20px;">';
        $page_rows    = array();
        $global_views = 0;
        $global_actions = 0;
        foreach ( $top_pages_cro as $p ) {
            $global_views += $p->views;
            $global_actions += $p->actions;
            $curr_apr = ( $p->views > 0 ) ? round( ( $p->actions / $p->views ), 2 ) : 0;
            $prev     = $prev_pages_cro_map[ $p->clean_url ] ?? null;
            $prev_v   = $prev->views ?? 0;
            $prev_a   = $prev->actions ?? 0;
            $prev_d   = $prev->avg_dwell ?? 0;
            $prev_apr = ( $prev_v > 0 ) ? round( ( $prev_a / $prev_v ), 2 ) : 0;
            $ctr_bg   = $curr_apr >= 0.05 ? '#46b450' : ( $curr_apr >= 0.02 ? '#f56e28' : '#94a3b8' );

            $page_rows[] = array(
                array( 'value' => '<strong>' . esc_html( basename( $p->clean_url ) ?: 'Home' ) . '</strong>' ),
                array( 'value' => $p->views, 'align' => 'right', 'bold' => true ),
                array( 'value' => $mini_delta( $p->views, $prev_v ), 'align' => 'right' ),
                array( 'value' => $p->actions, 'align' => 'right', 'bold' => true ),
                array( 'value' => $mini_delta( $p->actions, $prev_a ), 'align' => 'right' ),
                array( 'value' => '<span style="background:' . $ctr_bg . '20; padding:2px 6px; border-radius:4px; font-weight:bold;">' . $curr_apr . '</span>', 'align' => 'right' ),
                array( 'value' => $mini_delta( $curr_apr, $prev_apr ), 'align' => 'right' ),
                array( 'value' => $format_time( $p->avg_dwell ), 'align' => 'right', 'bold' => true ),
                array( 'value' => $mini_delta( $p->avg_dwell, $prev_d ), 'align' => 'right' ),
            );
        }
        $global_apr = ( $global_views > 0 ) ? round( ( $global_actions / $global_views ), 2 ) : 0;

        $this->render_ois_component(
            'data_table',
            array(
                'id'      => 'tbl-pages-cro-v2',
                'title'   => 'Page Performance & Conversion Trends',
                'icon'    => '📄',
                'headers' => array(
                    array( 'label' => 'Page Path', 'sortable' => true ),
                    array( 'label' => 'Views', 'align' => 'right', 'sortable' => true ),
                    array( 'label' => 'vs Past', 'align' => 'right' ),
                    array( 'label' => 'Actions', 'align' => 'right', 'sortable' => true ),
                    array( 'label' => 'vs Past', 'align' => 'right' ),
                    array( 'label' => 'Act./view', 'align' => 'right', 'sortable' => true ),
                    array( 'label' => 'vs Past', 'align' => 'right' ),
                    array( 'label' => 'Avg Dwell', 'align' => 'right', 'sortable' => true ),
                    array( 'label' => 'vs Past', 'align' => 'right' ),
                ),
                'rows'   => $page_rows,
                'totals' => array( 'GRAND TOTAL', number_format( $global_views ), '', number_format( $global_actions ), '', (string) $global_apr, '', '-', '' ),
            )
        );
        echo '</div>';

        echo '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px;">';

        $block_rows = array();
        foreach ( $top_blocks as $b ) {
            $block_rows[] = array(
                array( 'value' => '<code style="color:#f56e28;">' . esc_html( $b->block_name ) . '</code>' ),
                array( 'value' => $b->total_clicks, 'align' => 'right', 'bold' => true ),
                array( 'value' => $format_time( $b->avg_time ), 'align' => 'right' ),
            );
        }
        $this->render_ois_component(
            'data_table',
            array(
                'id'      => 'tbl-blocks',
                'title'   => 'Top Converting Blocks',
                'icon'    => '🧱',
                'headers' => array(
                    array( 'label' => 'Section', 'sortable' => true ),
                    array( 'label' => 'Clicks', 'align' => 'right', 'sortable' => true ),
                    array( 'label' => 'Avg Time', 'align' => 'right', 'sortable' => true ),
                ),
                'rows'    => $block_rows,
            )
        );

        $exit_rows = array();
        foreach ( $exit_pages_db as $e ) {
            $exit_rows[] = array(
                array( 'value' => '<strong>' . esc_html( basename( $e->clean_url ) ?: 'Home' ) . '</strong>' ),
                array( 'value' => $e->exit_count, 'align' => 'right', 'color' => '#d63638', 'bold' => true ),
            );
        }
        $this->render_ois_component(
            'data_table',
            array(
                'id'      => 'tbl-exits',
                'title'   => 'Top Exit Pages',
                'icon'    => '🚪',
                'headers' => array(
                    array( 'label' => 'Page Path', 'sortable' => true ),
                    array( 'label' => 'Exits', 'align' => 'right', 'sortable' => true ),
                ),
                'rows'    => $exit_rows,
            )
        );

        $anchor_rows = array();
        foreach ( $top_anchors as $a ) {
            $anchor_rows[] = array(
                array( 'value' => '<strong>' . esc_html( $a->anchor ) . '</strong>' ),
                array( 'value' => $a->total_clicks, 'align' => 'right', 'bold' => true ),
            );
        }
        $this->render_ois_component(
            'data_table',
            array(
                'id'      => 'tbl-anchors',
                'title'   => 'Top Button Texts',
                'icon'    => '🖱️',
                'headers' => array(
                    array( 'label' => 'Anchor Text', 'sortable' => true ),
                    array( 'label' => 'Clicks', 'align' => 'right', 'sortable' => true ),
                ),
                'rows'    => $anchor_rows,
            )
        );

        $camp_rows = array();
        foreach ( $top_campaigns as $c ) {
            $camp_rows[] = array(
                array( 'value' => '<code style="color:#722ed1;">' . esc_html( $c->utm_campaign ) . '</code>' ),
                array( 'value' => $c->uniques, 'align' => 'right', 'bold' => true ),
            );
        }
        $this->render_ois_component(
            'data_table',
            array(
                'id'      => 'tbl-campaigns',
                'title'   => 'UTM Campaigns',
                'icon'    => '🚀',
                'headers' => array(
                    array( 'label' => 'Name', 'sortable' => true ),
                    array( 'label' => 'Uniques', 'align' => 'right', 'sortable' => true ),
                ),
                'rows'    => $camp_rows,
            )
        );

        echo '</div>';
    }

    /**
     * HTML de la pestaña Audience (solo vía AJAX).
     */
    private function oiscl_render_analytics_deferred_audience( $start_date, $end_date ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'oiscl_block_metrics';

        $diff_days = max( 1, (int) round( ( strtotime( $end_date ) - strtotime( $start_date ) ) / 86400 ) + 1 );
        $prev_end   = date( 'Y-m-d', strtotime( $start_date . ' - 1 day' ) );
        $prev_start = date( 'Y-m-d', strtotime( $prev_end . ' - ' . ( $diff_days - 1 ) . ' days' ) );

        $aud_countries = $wpdb->get_results( $wpdb->prepare( "SELECT country, SUM(clicks) as views, COUNT(DISTINCT session_id) as uniques FROM $table_name WHERE anchor_text='[Pageview]' AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY country ORDER BY views DESC LIMIT 100", $start_date, $end_date ) );
        $prev_countries_db = $wpdb->get_results( $wpdb->prepare( "SELECT country, SUM(clicks) as views, COUNT(DISTINCT session_id) as uniques FROM $table_name WHERE anchor_text='[Pageview]' AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY country", $prev_start, $prev_end ) );
        $prev_countries_map = array();
        foreach ( $prev_countries_db as $p ) {
            $prev_countries_map[ $p->country ] = $p;
        }

        $aud_cities = $wpdb->get_results( $wpdb->prepare( "SELECT city, country, SUM(clicks) as views, COUNT(DISTINCT session_id) as uniques FROM $table_name WHERE anchor_text='[Pageview]' AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY city, country ORDER BY views DESC LIMIT 100", $start_date, $end_date ) );
        $prev_cities_db = $wpdb->get_results( $wpdb->prepare( "SELECT CONCAT(city, '|', country) as loc, SUM(clicks) as views, COUNT(DISTINCT session_id) as uniques FROM $table_name WHERE anchor_text='[Pageview]' AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY loc", $prev_start, $prev_end ) );
        $prev_cities_map = array();
        foreach ( $prev_cities_db as $p ) {
            $prev_cities_map[ $p->loc ] = $p;
        }

        $aud_devices_raw = $wpdb->get_results( $wpdb->prepare( "SELECT device, SUM(clicks) as views, COUNT(DISTINCT session_id) as uniques FROM $table_name WHERE anchor_text='[Pageview]' AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY device", $start_date, $end_date ) );
        $prev_devices_raw = $wpdb->get_results( $wpdb->prepare( "SELECT device, SUM(clicks) as views, COUNT(DISTINCT session_id) as uniques FROM $table_name WHERE anchor_text='[Pageview]' AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY device", $prev_start, $prev_end ) );

        $norm_dev = function ( $dev ) {
            $d = strtolower( $dev );
            if ( strpos( $d, 'tv' ) !== false || strpos( $d, 'smart' ) !== false ) {
                return 'TV';
            }
            if ( strpos( $d, 'tablet' ) !== false || strpos( $d, 'ipad' ) !== false ) {
                return 'Tablet';
            }
            if ( strpos( $d, 'mobile' ) !== false || strpos( $d, 'iphone' ) !== false || strpos( $d, 'android' ) !== false ) {
                return 'Mobile';
            }
            return 'PC';
        };

        $dev_stats = array(
            'Mobile' => array( 'v' => 0, 'u' => 0, 'pv' => 0, 'pu' => 0 ),
            'PC'     => array( 'v' => 0, 'u' => 0, 'pv' => 0, 'pu' => 0 ),
            'Tablet' => array( 'v' => 0, 'u' => 0, 'pv' => 0, 'pu' => 0 ),
            'TV'     => array( 'v' => 0, 'u' => 0, 'pv' => 0, 'pu' => 0 ),
        );
        foreach ( $aud_devices_raw as $d ) {
            $type = $norm_dev( $d->device );
            $dev_stats[ $type ]['v'] += $d->views;
            $dev_stats[ $type ]['u'] += $d->uniques;
        }
        foreach ( $prev_devices_raw as $d ) {
            $type = $norm_dev( $d->device );
            $dev_stats[ $type ]['pv'] += $d->views;
            $dev_stats[ $type ]['pu'] += $d->uniques;
        }

        $overview_sources = $wpdb->get_results( $wpdb->prepare( "SELECT destination_url as source, SUM(clicks) as views, COUNT(DISTINCT session_id) as uniques FROM $table_name WHERE anchor_text='[Pageview]' AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY source ORDER BY views DESC LIMIT 100", $start_date, $end_date ) );
        $prev_src_db = $wpdb->get_results( $wpdb->prepare( "SELECT destination_url as source, SUM(clicks) as views, COUNT(DISTINCT session_id) as uniques FROM $table_name WHERE anchor_text='[Pageview]' AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY source", $prev_start, $prev_end ) );
        $prev_src_map = array();
        foreach ( $prev_src_db as $p ) {
            $prev_src_map[ $p->source ] = $p;
        }

        $aud_os = $wpdb->get_results( $wpdb->prepare( "SELECT os, SUM(clicks) as views, COUNT(DISTINCT session_id) as uniques FROM $table_name WHERE anchor_text='[Pageview]' AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY os ORDER BY views DESC LIMIT 20", $start_date, $end_date ) );
        $aud_browser = $wpdb->get_results( $wpdb->prepare( "SELECT browser, SUM(clicks) as views, COUNT(DISTINCT session_id) as uniques FROM $table_name WHERE anchor_text='[Pageview]' AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY browser ORDER BY views DESC LIMIT 30", $start_date, $end_date ) );
        $aud_res = $wpdb->get_results( $wpdb->prepare( "SELECT screen_res, SUM(clicks) as views, COUNT(DISTINCT session_id) as uniques FROM $table_name WHERE anchor_text='[Pageview]' AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY screen_res ORDER BY views DESC LIMIT 20", $start_date, $end_date ) );

        $mini_delta = function ( $curr, $prev ) {
            $prev = (float) $prev;
            $curr = (float) $curr;
            if ( 0.0 === $prev && 0.0 === $curr ) {
                return '';
            }
            if ( 0.0 === $prev ) {
                return "<span style='font-size:10px; color:#46b450; margin-left:6px;' title='Past: 0'>▲ 100%</span>";
            }
            $pct   = ( ( $curr - $prev ) / $prev ) * 100;
            $color = $pct > 0 ? '#46b450' : ( $pct < 0 ? '#d63638' : '#999' );
            $icon  = $pct > 0 ? '▲' : ( $pct < 0 ? '▼' : '—' );
            return "<span style='font-size:10px; color:{$color}; margin-left:6px; font-weight:bold;' title='Past: {$prev}'>{$icon} " . round( abs( $pct ) ) . '%</span>';
        };

        $os_lbl = array();
        $os_val = array();
        if ( ! empty( $aud_os ) ) {
            foreach ( array_slice( $aud_os, 0, 5 ) as $o ) {
                $os_lbl[] = $o->os ?: 'Unknown';
                $os_val[] = $o->views;
            }
        }

        $bro_lbl = array();
        $bro_val = array();
        if ( ! empty( $aud_browser ) ) {
            foreach ( array_slice( $aud_browser, 0, 5 ) as $b ) {
                $bro_lbl[] = $b->browser ?: 'Unknown';
                $bro_val[] = $b->views;
            }
        }

        $res_lbl = array();
        $res_val = array();
        if ( ! empty( $aud_res ) ) {
            foreach ( array_slice( $aud_res, 0, 5 ) as $r ) {
                $res_lbl[] = $r->screen_res ?: 'Unknown';
                $res_val[] = $r->views;
            }
        }

        $dev_lbl = array_keys( $dev_stats );
        $dev_val = array_column( $dev_stats, 'v' );

        $top_dev_n = 'N/A';
        $top_dev_v = 0;
        foreach ( $dev_stats as $k => $v ) {
            if ( $v['v'] >= $top_dev_v ) {
                $top_dev_v = $v['v'];
                $top_dev_n = $k;
            }
        }

        $top_os_n = isset( $aud_os[0]->os ) && ! empty( $aud_os[0]->os ) ? $aud_os[0]->os : 'N/A';
        $top_os_v = isset( $aud_os[0]->views ) ? $aud_os[0]->views : 0;

        $top_bro_n = isset( $aud_browser[0]->browser ) && ! empty( $aud_browser[0]->browser ) ? $aud_browser[0]->browser : 'N/A';
        $top_bro_v = isset( $aud_browser[0]->views ) ? $aud_browser[0]->views : 0;

        $top_res_n = isset( $aud_res[0]->screen_res ) && ! empty( $aud_res[0]->screen_res ) ? $aud_res[0]->screen_res : 'N/A';
        $top_res_v = isset( $aud_res[0]->views ) ? $aud_res[0]->views : 0;

        echo '<div class="oiscl-audience-chart-grid">';
        $this->render_ois_audience_chart( 'pie-devices', '📱 Devices Share', 'pie', $dev_lbl, $dev_val );
        $this->render_ois_audience_chart( 'pie-os', '💻 OS Share', 'pie', $os_lbl, $os_val );
        $this->render_ois_audience_chart( 'pie-browsers', '🌐 Browsers', 'pie', $bro_lbl, $bro_val );
        $this->render_ois_audience_chart( 'pie-resolutions', '🖥️ Screen Res', 'pie', $res_lbl, $res_val );
        echo '</div>';

        echo '<div class="ois-analytics-card-grid">';
        $this->render_ois_audience_chart( 'bar-ux', '🏆 Top User Profile', 'bar', array( "Dev: $top_dev_n", "OS: $top_os_n", "Bro: $top_bro_n", "Res: $top_res_n" ), array( $top_dev_v, $top_os_v, $top_bro_v, $top_res_v ) );

        $src_rows = array();
        foreach ( $overview_sources as $s ) {
            $prev     = $prev_src_map[ $s->source ] ?? null;
            $src_name = empty( $s->source ) ? 'Direct / Unknown' : esc_html( $s->source );
            $src_rows[] = array(
                array( 'value' => '<strong>' . $src_name . '</strong>' ),
                array( 'value' => '<b>' . $s->views . '</b>', 'align' => 'right' ),
                array( 'value' => $mini_delta( $s->views, $prev->views ?? 0 ), 'align' => 'right' ),
                array( 'value' => $s->uniques, 'align' => 'right', 'bold' => true ),
                array( 'value' => $mini_delta( $s->uniques, $prev->uniques ?? 0 ), 'align' => 'right' ),
            );
        }
        $this->render_ois_component(
            'data_table',
            array(
                'id'      => 'tbl-aud-src',
                'title'   => 'Top Traffic Sources',
                'icon'    => '🔗',
                'headers' => array(
                    array( 'label' => 'Source', 'sortable' => true ),
                    array( 'label' => 'Views', 'align' => 'right', 'sortable' => true ),
                    array( 'label' => 'vs Past', 'align' => 'right' ),
                    array( 'label' => 'Uniques', 'align' => 'right', 'sortable' => true ),
                    array( 'label' => 'vs Past', 'align' => 'right' ),
                ),
                'rows'    => $src_rows,
            )
        );

        $country_rows = array();
        foreach ( $aud_countries as $c ) {
            $prev = $prev_countries_map[ $c->country ] ?? null;
            $country_rows[] = array(
                array( 'value' => '🏳️ <strong>' . esc_html( $c->country ?: 'Unknown' ) . '</strong>' ),
                array( 'value' => '<b>' . $c->views . '</b>', 'align' => 'right' ),
                array( 'value' => $mini_delta( $c->views, $prev->views ?? 0 ), 'align' => 'right' ),
                array( 'value' => $c->uniques, 'align' => 'right', 'bold' => true ),
                array( 'value' => $mini_delta( $c->uniques, $prev->uniques ?? 0 ), 'align' => 'right' ),
            );
        }
        $this->render_ois_component(
            'data_table',
            array(
                'id'      => 'tbl-aud-countries',
                'title'   => 'Top Countries',
                'icon'    => '🌍',
                'headers' => array(
                    array( 'label' => 'Country', 'sortable' => true ),
                    array( 'label' => 'Views', 'align' => 'right', 'sortable' => true ),
                    array( 'label' => 'vs Past', 'align' => 'right' ),
                    array( 'label' => 'Uniques', 'align' => 'right', 'sortable' => true ),
                    array( 'label' => 'vs Past', 'align' => 'right' ),
                ),
                'rows'    => $country_rows,
            )
        );

        $city_rows = array();
        foreach ( $aud_cities as $c ) {
            $prev = $prev_cities_map[ $c->city . '|' . $c->country ] ?? null;
            $city_rows[] = array(
                array( 'value' => '📍 <strong>' . esc_html( $c->city ?: 'Unknown' ) . '</strong> <small style="color:#999;">(' . $c->country . ')</small>' ),
                array( 'value' => '<b>' . $c->views . '</b>', 'align' => 'right' ),
                array( 'value' => $mini_delta( $c->views, $prev->views ?? 0 ), 'align' => 'right' ),
                array( 'value' => $c->uniques, 'align' => 'right', 'bold' => true ),
                array( 'value' => $mini_delta( $c->uniques, $prev->uniques ?? 0 ), 'align' => 'right' ),
            );
        }
        $this->render_ois_component(
            'data_table',
            array(
                'id'      => 'tbl-aud-cities',
                'title'   => 'Top Cities',
                'icon'    => '🏙️',
                'headers' => array(
                    array( 'label' => 'City', 'sortable' => true ),
                    array( 'label' => 'Views', 'align' => 'right', 'sortable' => true ),
                    array( 'label' => 'vs Past', 'align' => 'right' ),
                    array( 'label' => 'Uniques', 'align' => 'right', 'sortable' => true ),
                    array( 'label' => 'vs Past', 'align' => 'right' ),
                ),
                'rows'    => $city_rows,
            )
        );

        echo '</div>';
    }

    /**
     * HTML de la pestaña User Journey (solo vía AJAX).
     */
    private function oiscl_render_analytics_deferred_journey( $start_date, $end_date ) {
        $user_sessions = $this->get_oiscl_user_sessions( $start_date, $end_date );
        $journey_rows   = $this->oiscl_build_journey_advanced_table_rows( $user_sessions );

        $journey_csv_args = array(
            'page'        => 'oiscl-analytics',
            'export_csv'  => 'journey',
            'start_date'  => $start_date,
            'end_date'    => $end_date,
        );
        $journey_csv_url      = admin_url( 'admin.php?' . http_build_query( $journey_csv_args ) );
        $journey_csv_full_url = admin_url( 'admin.php?' . http_build_query( array_merge( $journey_csv_args, array( 'full' => '1' ) ) ) );

        $this->render_ois_component(
            'advanced_table',
            array(
                'id'        => 'table-journey',
                'title'     => 'Detailed User Journey',
                'subtitle'  => sprintf(
                    /* translators: %d: max table rows (journey session limit) */
                    __( 'Table and default CSV (Export menu) list up to %d rows. For every session in the range, use Export → Download CSV (full census); large sites may be slow.', 'ois-conversion-suite' ),
                    $this->oiscl_get_journey_session_limit()
                ),
                'icon'    => '🕵️‍♂️',
                'toolbar' => '<button type="button" class="button" onclick="jQuery(\'.ois-row-details\').hide(); jQuery(\'.j-arrow\').css(\'transform\', \'rotate(0deg)\');">' . esc_html__( 'Collapse All ▴', 'ois-conversion-suite' ) . '</button>',
                'csv'     => $journey_csv_url,
                'csv_full_census_url' => $journey_csv_full_url,
                'pdf'     => 'User_Journey',
                'headers' => array(
                    array( 'label' => 'Identity & ID', 'width' => '12%', 'type' => 'string' ),
                    array( 'label' => 'Date', 'width' => '8%', 'type' => 'string', 'align' => 'center' ),
                    array( 'label' => 'Entry Time', 'width' => '10%', 'type' => 'string', 'align' => 'center' ),
                    array( 'label' => 'Navigation Route', 'width' => '20%', 'type' => 'string' ),
                    array( 'label' => 'Time', 'width' => '8%', 'type' => 'string', 'align' => 'center' ),
                    array( 'label' => 'Clicks', 'width' => '8%', 'type' => 'numeric', 'align' => 'center' ),
                    array( 'label' => 'Location', 'width' => '12%', 'type' => 'string' ),
                    array( 'label' => 'Resolution', 'width' => '10%', 'type' => 'string', 'align' => 'center' ),
                    array( 'label' => 'Tech', 'width' => '12%', 'type' => 'string', 'align' => 'center' ),
                ),
                'rows'    => $journey_rows,
            )
        );
    }

}
