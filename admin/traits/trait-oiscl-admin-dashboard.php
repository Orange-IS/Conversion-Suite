<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait OISCL_Admin_Dashboard_Trait {

    // ==========================================
    // MÓDULO 1: GLOBAL DASHBOARD  v 0.50
    // ==========================================
    public function display_dashboard_page() {
        global $wpdb; $table_name = $wpdb->prefix . 'oiscl_block_metrics';
        $user_id = get_current_user_id(); $today = current_time('Y-m-d');
        
        // --- LÓGICA DE FECHAS SINCRONIZADA ---
        $date_ctx = OISCL_Activity::resolve_user_report_dates( $user_id, $today, $_GET );
        $start_date   = $date_ctx['start_date'];
        $end_date     = $date_ctx['end_date'];
        $preset_label = $date_ctx['preset_label'];
        $preset       = $date_ctx['preset'];

        $diff_days = round((strtotime($end_date) - strtotime($start_date)) / 86400) + 1;
        $prev_end = date('Y-m-d', strtotime($start_date . ' - 1 days')); 
        $prev_start = date('Y-m-d', strtotime($prev_end . ' - ' . ($diff_days - 1) . ' days'));

        // Solución del doble offset para los gráficos de carga inicial
        $now_ts = strtotime(current_time('mysql')); $one_hour_ago = date('Y-m-d H:i:s', $now_ts - 3600); $yest_now_ts = $now_ts - 86400; $yest_hour_ago = date('Y-m-d H:i:s', $yest_now_ts - 3600);
        
        $recent_clicks = $wpdb->get_results($wpdb->prepare("SELECT created_at FROM $table_name WHERE created_at >= %s AND anchor_text NOT IN ('[Pageview]', '[Vista de Bloque]')", $one_hour_ago));
        $v_today = $wpdb->get_results($wpdb->prepare("SELECT created_at, session_id FROM $table_name WHERE anchor_text='[Pageview]' AND created_at >= %s", $one_hour_ago));
        $v_yest = $wpdb->get_results($wpdb->prepare("SELECT created_at, session_id FROM $table_name WHERE anchor_text='[Pageview]' AND created_at BETWEEN %s AND %s", $yest_hour_ago, date('Y-m-d H:i:s', $yest_now_ts)));

        $data_60m = array_fill(0, 60, 0); $line_v_today = array_fill(0, 60, 0); $line_u_today = array_fill(0, 60, 0); $line_v_yest = array_fill(0, 60, 0); $line_u_yest = array_fill(0, 60, 0);
        $u_raw_today = array_fill(0, 60, []); $u_raw_yest = array_fill(0, 60, []);
        $labels_60m = []; for($i=59; $i>=0; $i--) { $labels_60m[] = date('H:i', $now_ts - ($i * 60)); }

        foreach($recent_clicks as $c) { $diff = floor(($now_ts - strtotime($c->created_at)) / 60); if($diff >= 0 && $diff < 60) { $data_60m[59-$diff]++; } }
        foreach($v_today as $v) { $diff = floor(($now_ts - strtotime($v->created_at)) / 60); if($diff >= 0 && $diff < 60) { $line_v_today[59-$diff]++; $u_raw_today[59-$diff][] = $v->session_id; } }
        foreach($v_yest as $v) { $diff = floor(($yest_now_ts - strtotime($v->created_at)) / 60); if($diff >= 0 && $diff < 60) { $line_v_yest[59-$diff]++; $u_raw_yest[59-$diff][] = $v->session_id; } }
        for($i=0; $i<60; $i++) { $line_u_today[$i] = count(array_unique($u_raw_today[$i])); $line_u_yest[$i] = count(array_unique($u_raw_yest[$i])); }

        // Solución definitiva al "Doble Offset" de zonas horarias en WordPress
        // Solución definitiva al desfase horario (5 minutos)
        $ois_now = date('Y-m-d H:i:s', strtotime(current_time('mysql')) - 300);
        $live_views = $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT session_id) FROM $table_name WHERE created_at >= %s", $ois_now)) ?: 0;
        
        $total_views = $wpdb->get_var($wpdb->prepare("SELECT SUM(clicks) FROM $table_name WHERE anchor_text='[Pageview]' AND DATE(created_at) >= %s AND DATE(created_at) <= %s", $start_date, $end_date)) ?: 0;
        $prev_views = $wpdb->get_var($wpdb->prepare("SELECT SUM(clicks) FROM $table_name WHERE anchor_text='[Pageview]' AND DATE(created_at) >= %s AND DATE(created_at) <= %s", $prev_start, $prev_end)) ?: 0;
        $unique_users = $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT session_id) FROM $table_name WHERE anchor_text='[Pageview]' AND DATE(created_at) >= %s AND DATE(created_at) <= %s", $start_date, $end_date)) ?: 0;
        $prev_uniques = $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT session_id) FROM $table_name WHERE anchor_text='[Pageview]' AND DATE(created_at) >= %s AND DATE(created_at) <= %s", $prev_start, $prev_end)) ?: 0;
        $total_clicks = $wpdb->get_var($wpdb->prepare("SELECT SUM(clicks) FROM $table_name WHERE anchor_text NOT IN ('[Pageview]', '[Vista de Bloque]') AND DATE(created_at) >= %s AND DATE(created_at) <= %s", $start_date, $end_date)) ?: 0;
        $prev_clicks = $wpdb->get_var($wpdb->prepare("SELECT SUM(clicks) FROM $table_name WHERE anchor_text NOT IN ('[Pageview]', '[Vista de Bloque]') AND DATE(created_at) >= %s AND DATE(created_at) <= %s", $prev_start, $prev_end)) ?: 0;
        // Ratio of weighted action clicks to weighted pageview clicks (NOT a classical 0–100% CTR).
        $actions_per_pv = ( $total_views > 0 ) ? round( $total_clicks / $total_views, 2 ) : 0;
        $prev_actions_per_pv = ( $prev_views > 0 ) ? round( $prev_clicks / $prev_views, 2 ) : 0;
        $avg_time = $wpdb->get_var($wpdb->prepare("SELECT AVG(time_spent) FROM $table_name WHERE time_spent > 0 AND anchor_text IN ('[Pageview]', '[Vista de Bloque]') AND DATE(created_at) >= %s AND DATE(created_at) <= %s", $start_date, $end_date)) ?: 0;
        $prev_time = $wpdb->get_var($wpdb->prepare("SELECT AVG(time_spent) FROM $table_name WHERE time_spent > 0 AND anchor_text IN ('[Pageview]', '[Vista de Bloque]') AND DATE(created_at) >= %s AND DATE(created_at) <= %s", $prev_start, $prev_end)) ?: 0;

        

        $src_db = $wpdb->get_results($wpdb->prepare("SELECT destination_url as source, SUM(clicks) as total FROM $table_name WHERE anchor_text='[Pageview]' AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY source ORDER BY total DESC LIMIT 6", $start_date, $end_date));
        $src_labels = []; $src_data = []; if($src_db) { foreach($src_db as $s) { $src_labels[] = esc_html($s->source); $src_data[] = (int)$s->total; } } else { $src_labels = ['No Data']; $src_data = [0]; }
        $os_db = $wpdb->get_results($wpdb->prepare("SELECT os, COUNT(DISTINCT session_id) as total FROM $table_name WHERE anchor_text='[Pageview]' AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY os ORDER BY total DESC LIMIT 6", $start_date, $end_date));
        $os_labels = []; $os_data = []; if($os_db) { foreach($os_db as $o) { $os_labels[] = esc_html($o->os); $os_data[] = (int)$o->total; } } else { $os_labels = ['No Data']; $os_data = [0]; }

        // Corrección de Dwell Time global
        $top_pages = $wpdb->get_results($wpdb->prepare("SELECT SUBSTRING_INDEX(origin_url, '?', 1) as clean_url, SUM(CASE WHEN anchor_text='[Pageview]' THEN clicks ELSE 0 END) as views, AVG(CASE WHEN time_spent > 0 THEN time_spent ELSE NULL END) as avg_dwell FROM $table_name WHERE DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY clean_url HAVING views > 0 ORDER BY views DESC LIMIT 50", $start_date, $end_date));
        $top_convs = $wpdb->get_results($wpdb->prepare("SELECT SUBSTRING_INDEX(origin_url, '?', 1) as clean_url, SUM(CASE WHEN anchor_text NOT IN ('[Pageview]', '[Vista de Bloque]') THEN clicks ELSE 0 END) as total_clicks, SUM(CASE WHEN anchor_text = '[Pageview]' THEN clicks ELSE 0 END) as total_views FROM $table_name WHERE DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY clean_url HAVING total_clicks > 0 ORDER BY total_clicks DESC LIMIT 50", $start_date, $end_date));
        $top_locs = $wpdb->get_results($wpdb->prepare("SELECT country, city, COUNT(*) as total FROM $table_name WHERE anchor_text='[Pageview]' AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY country, city ORDER BY total DESC LIMIT 50", $start_date, $end_date));

        $block_in = OISCL_Plan::sql_block_view_anchor_in();
        $block_views_sum = (int) ( $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(clicks), 0) FROM {$table_name} WHERE anchor_text IN ($block_in) AND DATE(created_at) >= %s AND DATE(created_at) <= %s",
            $start_date,
            $end_date
        ) ) ?: 0 );
        $prev_block_views_sum = (int) ( $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(clicks), 0) FROM {$table_name} WHERE anchor_text IN ($block_in) AND DATE(created_at) >= %s AND DATE(created_at) <= %s",
            $prev_start,
            $prev_end
        ) ) ?: 0 );

        $top_ix_rows_db = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT SUBSTRING_INDEX(origin_url, '?', 1) AS clean_origin,
                        MAX(origin_url) AS origin_url,
                        anchor_text,
                        context_text,
                        SUM(clicks) AS total,
                        MAX(NULLIF(TRIM(COALESCE(utm_campaign, '')), '')) AS utm_c
                 FROM {$table_name}
                 WHERE DATE(created_at) >= %s AND DATE(created_at) <= %s
                   AND anchor_text NOT IN ('[Pageview]', '[Error 404]', '[Vista de Bloque]')
                 GROUP BY SUBSTRING_INDEX(origin_url, '?', 1), anchor_text, context_text
                 ORDER BY total DESC
                 LIMIT 120",
                $start_date,
                $end_date
            )
        );

        $adv_ix_rows = array();
        if ( $top_ix_rows_db ) {
            foreach ( $top_ix_rows_db as $ix ) {
                $icon          = ( $ix->context_text === 'Reading' ) ? '👁️' : '👆';
                $anchor_safe   = esc_html( $ix->anchor_text );
                $bn_page       = basename( $ix->clean_origin );
                $page_label    = esc_html( ( $bn_page !== '' && $bn_page !== '.' ) ? $bn_page : 'Home' );
                $type_lbl      = ( $ix->context_text === 'Reading' )
                    ? '<span style="font-size:11px; background:#ede9fe; color:#5b21b6; padding:2px 8px; border-radius:4px;">' . esc_html__( 'Read', 'ois-conversion-suite' ) . '</span>'
                    : '<span style="font-size:11px; background:#fff7ed; color:#c2410c; padding:2px 8px; border-radius:4px;">' . esc_html__( 'Click', 'ois-conversion-suite' ) . '</span>';
                $traffic       = $ix->utm_c
                    ? '<code style="background:#e0f2fe; color:#0369a1; padding:2px 6px; border-radius:4px;">' . esc_html( $ix->utm_c ) . '</code>'
                    : '<span style="color:#64748b;">' . esc_html__( 'Direct / Organic', 'ois-conversion-suite' ) . '</span>';
                $origin_esc    = esc_html( $ix->origin_url );
                $full_url_href = esc_url( $ix->origin_url );

                $details  = '<div style="padding:16px 20px; border-left:4px solid #1a73e8; background:#f8fafc;">';
                $details .= '<p style="margin:0 0 10px;"><strong>' . esc_html__( 'Full page URL', 'ois-conversion-suite' ) . '</strong><br>';
                $details .= '<a href="' . $full_url_href . '" target="_blank" rel="noopener noreferrer">' . $origin_esc . '</a></p>';
                $details .= '<p style="margin:0 0 10px;"><strong>' . esc_html__( 'Interaction', 'ois-conversion-suite' ) . '</strong><br>' . $anchor_safe . '</p>';
                $details .= '<p style="margin:0;"><strong>' . esc_html__( 'Context', 'ois-conversion-suite' ) . '</strong><br>' . esc_html( $ix->context_text ) . '</p>';
                $details .= '</div>';

                $adv_ix_rows[] = array(
                    'class'          => 'ois-row-accordion',
                    'details_html'   => $details,
                    'cols'           => array(
                        '<span style="color:#1a73e8; font-size:12px; display:inline-block; margin-right:8px; transition:0.2s;" class="j-arrow">▶</span><strong>' . $icon . ' ' . $anchor_safe . '</strong>',
                        '<span style="color:#334155;">' . $page_label . '</span>',
                        $type_lbl,
                        $traffic,
                        '<b style="color:#1a73e8;">' . number_format_i18n( (int) $ix->total ) . '</b>',
                    ),
                );
            }
        }

        // 1. LLAMADA AL TEMPLATE PARA EMPEZAR LA PÁGINA
        $this->render_ois_component('layout_start', array('id' => 'oiscl-dashboard-wrap'));
        
        

        // 2. HEADER UNIFICADO Y KPIs
        $this->render_ois_component('header', array(
            'title'      => '🚀 OIS Global Dashboard',
            'start_date' => $start_date,
            'end_date'   => $end_date,
            'preset'     => $preset_label,
            'page_slug'  => 'oiscl-intro',
            'live_val'   => $live_views,
            'kpis'       => array(
                array('label' => 'LIVE NOW', 'value' => $live_views, 'color' => ($live_views > 0 ? '#46b450' : '#d63638'), 'is_live' => true),
                array('label' => 'TOTAL VISITS', 'value' => number_format($total_views), 'color' => '#1a73e8', 'delta' => $this->format_kpi_delta($total_views, $prev_views), 'icon' => '👁️'),
                array('label' => 'UNIQUE USERS', 'value' => number_format($unique_users), 'color' => '#46b450', 'delta' => $this->format_kpi_delta($unique_users, $prev_uniques), 'icon' => '👤'),
                array('label' => 'ACTIONS / PV', 'value' => (string) $actions_per_pv, 'color' => '#f56e28', 'delta' => $this->format_kpi_delta($actions_per_pv, $prev_actions_per_pv), 'icon' => '🖱️'),
                array('label' => 'AVG RETENTION', 'value' => ($avg_time >= 60 ? round($avg_time/60, 1).'m' : round($avg_time).'s'), 'color' => '#722ed1', 'delta' => $this->format_kpi_delta($avg_time, $prev_time), 'icon' => '⏱️')
            )
        ));
        
        
        // 3. BARRA DE SALUD DEL SERVIDOR
        $this->render_ois_component('server_health_bar');
        
        
        
        $pulse_total_now = array_sum($data_60m);
        echo '<div class="oiscl-dash-chart-card" style="background:#fff; border:1px solid #ccd0d4; padding:20px; border-radius:4px; margin-bottom:20px; width:100%; box-sizing:border-box;">';
            echo '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;"><h3 class="ois-block-title"><a href="?page=oiscl-trackpro-report">⚡ Activity Pulse <span id="pulse-total-clicks" style="color:#f56e28; font-weight:bold; background:#fff5f5; padding:2px 8px; border-radius:4px; margin:0 5px;">' . $pulse_total_now . ' Clics</span> <span class="oiscl-hour-label" style="font-weight:normal; color:#666; font-size:14px;">(Last 60 Minutes)</span> <span class="dashicons dashicons-external" style="font-size:14px; margin-top:4px;"></span></a></h3><div style="display:flex; gap:5px;"><button class="button button-small btn-pulse-prev">◀</button><button class="button button-small btn-pulse-reset">Ahora</button><button class="button button-small btn-pulse-next" disabled>▶</button></div></div>';
            echo '<div style="height:150px;"><canvas id="oisclRealTimeChart"></canvas></div></div>';

        $vector_total_views = array_sum($line_v_today);
        echo '<div class="oiscl-dash-chart-card" style="background:#fff; border:1px solid #ccd0d4; padding:20px; border-radius:4px; margin-bottom:20px; width:100%; box-sizing:border-box;">';
            echo '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:5px;"><h3 class="ois-block-title"><a href="?page=oiscl-analytics">📈 Views vs Uniques <span id="vector-total-views" style="color:#1a73e8; font-weight:bold; background:#eaf3ff; padding:2px 8px; border-radius:4px; margin:0 5px;">' . $vector_total_views . ' Vistas</span> <span class="oiscl-hour-label" style="font-weight:normal; color:#666; font-size:14px;">(Last 60 Minutes)</span> <span class="dashicons dashicons-external" style="font-size:14px; margin-top:4px;"></span></a></h3><div style="display:flex; gap:5px;"><button class="button button-small btn-pulse-prev">◀</button><button class="button button-small btn-pulse-reset">Ahora</button><button class="button button-small btn-pulse-next" disabled>▶</button></div></div>';
            echo '<div style="margin-bottom:15px;"><span style="font-size:11px; color:#666;"><b style="color:#1a73e8">Vistas</b> / <b style="color:#d63638">Uniques</b> / <span style="border-bottom:2px dashed #999;">Ayer (Punteado)</span></span></div>';
            echo '<div style="height:150px;"><canvas id="oisclVectorChart"></canvas></div></div>';

        // --- LÓGICA HORARIA (24H) DE CLICKS Y TRAFFIC PARA DASHBOARD ---
        $clicks_h_today = $wpdb->get_results($wpdb->prepare("SELECT HOUR(created_at) as hr, SUM(clicks) as total FROM $table_name WHERE anchor_text NOT IN ('[Pageview]', '[Vista de Bloque]') AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY hr", $start_date, $end_date));
        $clicks_h_yest  = $wpdb->get_results($wpdb->prepare("SELECT HOUR(created_at) as hr, SUM(clicks) as total FROM $table_name WHERE anchor_text NOT IN ('[Pageview]', '[Vista de Bloque]') AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY hr", $prev_start, $prev_end));
        
        $h_clicks_today = array_fill(0, 24, 0); $h_clicks_yest = array_fill(0, 24, 0);
        foreach($clicks_h_today as $h) { $h_clicks_today[(int)$h->hr] = (int)$h->total; }
        foreach($clicks_h_yest as $h) { $h_clicks_yest[(int)$h->hr] = (int)$h->total; }

        $traffic_h_today = $wpdb->get_results($wpdb->prepare("SELECT HOUR(created_at) as hr, SUM(clicks) as views, COUNT(DISTINCT session_id) as uniques FROM $table_name WHERE anchor_text='[Pageview]' AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY hr", $start_date, $end_date));
        $traffic_h_yest  = $wpdb->get_results($wpdb->prepare("SELECT HOUR(created_at) as hr, SUM(clicks) as views, COUNT(DISTINCT session_id) as uniques FROM $table_name WHERE anchor_text='[Pageview]' AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY hr", $prev_start, $prev_end));
        
        $h_views_today = array_fill(0, 24, 0); $h_uniques_today = array_fill(0, 24, 0);
        $h_views_yest  = array_fill(0, 24, 0); $h_uniques_yest  = array_fill(0, 24, 0);
        foreach($traffic_h_today as $h) { $h_views_today[(int)$h->hr] = (int)$h->views; $h_uniques_today[(int)$h->hr] = (int)$h->uniques; }
        foreach($traffic_h_yest  as $h) { $h_views_yest[(int)$h->hr]  = (int)$h->views; $h_uniques_yest[(int)$h->hr]  = (int)$h->uniques; }

        // --- RENDER HTML DE LAS 2 COLUMNAS (Con Tips en Inglés) ---
        $this->render_ois_component('row_start', array('pattern' => '1-1'));
            echo '<div class="oiscl-dash-chart-card" style="background:#fff; border:1px solid #ccd0d4; padding:20px; border-radius:4px; display:flex; flex-direction:column; justify-content:space-between;">';
                echo '<div>';
                echo '<h3 class="ois-block-title">🖱️ Hourly Clicks (Current vs Past)</h3>';
                echo '<div style="height:180px; margin-top:15px;"><canvas id="dashHourlyClicksChart"></canvas></div>';
                echo '</div>';
                echo '<div style="background:#f8fafc; padding:8px 15px; border-radius:4px; font-size:11px; color:#475569; border-left:4px solid #f56e28; text-align:center; margin-top:15px;">💡 <strong>Tip:</strong> Click on the legend names to enable or disable lines.</div>';
            echo '</div>';
            
            echo '<div class="oiscl-dash-chart-card" style="background:#fff; border:1px solid #ccd0d4; padding:20px; border-radius:4px; display:flex; flex-direction:column; justify-content:space-between;">';
                echo '<div>';
                echo '<h3 class="ois-block-title">📈 Hourly Traffic (Current vs Past)</h3>';
                echo '<div style="height:180px; margin-top:15px;"><canvas id="dashHourlyTrafficChart"></canvas></div>';
                echo '</div>';
                echo '<div style="background:#f8fafc; padding:8px 15px; border-radius:4px; font-size:11px; color:#475569; border-left:4px solid #1a73e8; text-align:center; margin-top:15px;">💡 <strong>Tip:</strong> Click on the legend names to enable or disable lines.</div>';
            echo '</div>';
        $this->render_ois_component('row_end');

        // --- Estilos: tablas widget + secciones (títulos vía .ois-block-title en oiscl-admin.css) ---
        echo '<style>
        .ois-table-dashboard { width:100%; border-collapse:collapse; } .ois-table-dashboard th { text-align:left; font-size:11px; color:#999; text-transform:uppercase; padding:8px 0; border-bottom:1px solid #eee; } .ois-table-dashboard td { padding:10px 0; border-bottom:1px solid #f9f9f9; font-size:12px; }
        </style>';

        // --- 1. PREPARAR DATOS + grilla de cuatro tablas (encima de Activity summary) ---
        $rows_sources = array();
        if($src_db) { foreach($src_db as $item) {
            $src_name = empty($item->source) ? 'Direct / Unknown' : esc_html($item->source);
            $rows_sources[] = array( 
                array('value' => '<strong>' . $src_name . '</strong>'), 
                array('value' => '<b>' . $item->total . '</b>', 'align' => 'right', 'color' => '#1a73e8') 
            );
        } }

        $rows_pages = array();
        if($top_pages) { foreach($top_pages as $item) {
            $dwell = ($item->avg_dwell >= 60) ? round($item->avg_dwell/60, 1).'m' : round($item->avg_dwell).'s';
            $rows_pages[] = array( 
                array('value' => '<strong>' . esc_html(basename($item->clean_url) ?: 'Home') . '</strong>'), 
                array('value' => $dwell, 'align' => 'center', 'color' => '#666'), 
                array('value' => '<b>' . $item->views . '</b>', 'align' => 'right', 'color' => '#1a73e8') 
            );
        } }

        $rows_convs = array();
        if($top_convs) { foreach($top_convs as $item) {
            $page_apr = ($item->total_views > 0) ? round(($item->total_clicks / $item->total_views), 2) : 0;
            $ctr_bg = $page_apr >= 0.05 ? '#46b450' : ($page_apr >= 0.02 ? '#f56e28' : '#94a3b8'); 
            $rows_convs[] = array( 
                array('value' => '<strong>' . esc_html(basename($item->clean_url) ?: 'Home') . '</strong>'), 
                array('value' => '<span style="background:'.$ctr_bg.'20; color:'.$ctr_bg.'; padding:2px 6px; border-radius:4px; font-weight:bold;">' . $page_apr . '</span>', 'align' => 'center'), 
                array('value' => '<b>' . $item->total_clicks . '</b>', 'align' => 'right', 'color' => '#f56e28') 
            );
        } }

        $rows_locs = array();
        if($top_locs) { foreach($top_locs as $item) {
            $rows_locs[] = array( 
                array('value' => '📍 <strong>' . esc_html($item->city ?: 'Unknown') . '</strong> <small style="color:#999;">(' . esc_html($item->country) . ')</small>'), 
                array('value' => '<b>' . $item->total . '</b>', 'align' => 'right', 'color' => '#1a73e8') 
            );
        } }

        echo '<div id="oiscl-dashboard-widget-tables" class="oiscl-dash-widget-tables" style="margin-bottom:22px;">';
        echo '<h3 class="ois-block-title ois-block-title--section">📑 ' . esc_html__( 'Quick overview', 'ois-conversion-suite' ) . '</h3>';
        echo '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 4px;">';
        $this->render_ois_component('data_table', array('id' => 'dash-tbl-src', 'title' => 'Traffic Sources', 'icon' => '🔗', 'link' => '?page=oiscl-analytics&tab=tab-overview', 'headers' => array( array('label' => 'Source'), array('label' => 'Views', 'align' => 'right') ), 'rows' => $rows_sources));
        $this->render_ois_component('data_table', array('id' => 'dash-tbl-pages', 'title' => 'Top Viewed Pages', 'icon' => '📄', 'link' => '?page=oiscl-analytics&tab=tab-content', 'headers' => array( array('label' => 'Page Path'), array('label' => 'Dwell', 'align' => 'center'), array('label' => 'Views', 'align' => 'right') ), 'rows' => $rows_pages));
        $this->render_ois_component('data_table', array('id' => 'dash-tbl-convs', 'title' => 'Highest Conversions', 'icon' => '🎯', 'link' => '?page=oiscl-trackpro-report', 'headers' => array( array('label' => 'Target Page'), array('label' => 'Act./view', 'align' => 'center'), array('label' => 'Clicks', 'align' => 'right') ), 'rows' => $rows_convs));
        $this->render_ois_component('data_table', array('id' => 'dash-tbl-locs', 'title' => 'Audience Cities', 'icon' => '🌍', 'link' => '?page=oiscl-analytics&tab=tab-audience', 'headers' => array( array('label' => 'Location'), array('label' => 'Views', 'align' => 'right') ), 'rows' => $rows_locs));
        echo '</div></div>';

        // --- Activity summary + Top interactions (debajo del quick overview) ---
        echo '<div class="oiscl-dash-activity-cluster" style="margin-bottom:20px;">';
        echo '<div class="ois-box" style="margin-bottom:20px; width:100%; box-sizing:border-box;">';
        echo '<h3 class="ois-block-title ois-block-title--section">📊 ' . esc_html__( 'Activity summary', 'ois-conversion-suite' ) . '</h3>';
        echo '<p style="margin:0 0 14px 0; font-size:13px; color:#475569; line-height:1.5;">' . esc_html__( 'Totals from wp_oiscl_block_metrics for the selected range: visits (sum of pageview clicks), tracked actions, actions-per-pageview ratio (tracked action clicks ÷ pageview clicks; not a 0–100% ad CTR), and block-view rows ([Vista de Bloque]). Deltas compare to the previous period of equal length.', 'ois-conversion-suite' ) . '</p>';
        $range_line = esc_html( $preset_label ) . ' — ' . esc_html( $start_date );
        if ( $start_date !== $end_date ) {
            $range_line .= ' → ' . esc_html( $end_date );
        }
        echo '<p style="margin:0 0 18px 0; font-size:12px; color:#64748b;">' . $range_line . '</p>';
        echo '<div style="display:flex; gap:20px; flex-wrap:wrap;">';
        $this->render_utm_kpi_card( __( 'Total visits', 'ois-conversion-suite' ), number_format_i18n( (int) $total_views ), '#1a73e8', '👁️', $this->format_kpi_delta( $total_views, $prev_views ) );
        $this->render_utm_kpi_card( __( 'Tracked actions', 'ois-conversion-suite' ), number_format_i18n( (int) $total_clicks ), '#f56e28', '🖱️', $this->format_kpi_delta( $total_clicks, $prev_clicks ) );
        $this->render_utm_kpi_card( __( 'Actions / pageview', 'ois-conversion-suite' ), (string) $actions_per_pv, '#ea580c', '📈', $this->format_kpi_delta( $actions_per_pv, $prev_actions_per_pv ) );
        $this->render_utm_kpi_card( __( 'Block view events', 'ois-conversion-suite' ), number_format_i18n( $block_views_sum ), '#722ed1', '📖', $this->format_kpi_delta( $block_views_sum, $prev_block_views_sum ) );
        echo '</div></div>';

        $this->render_ois_component(
            'advanced_table',
            array(
                'id'         => 'dash-top-interactions',
                'title'      => __( 'Top interactions', 'ois-conversion-suite' ),
                'subtitle'   => __( 'Each row groups rows in wp_oiscl_block_metrics by page path (no query string), anchor text, and context_text. Actions = SUM(clicks) in that group. Pageviews, 404s, and block-metric rows ([Vista de Bloque]) are excluded so this matches what users clicked or read outside raw page loads.', 'ois-conversion-suite' ),
                'icon'       => '🔥',
                'pdf'        => 'Dashboard_Top_Interactions',
                'headers'    => array(
                    array( 'label' => __( 'Element', 'ois-conversion-suite' ), 'width' => '28%', 'type' => 'string' ),
                    array( 'label' => __( 'Page', 'ois-conversion-suite' ), 'width' => '18%', 'type' => 'string' ),
                    array( 'label' => __( 'Type', 'ois-conversion-suite' ), 'width' => '12%', 'type' => 'string', 'align' => 'center' ),
                    array( 'label' => __( 'Traffic', 'ois-conversion-suite' ), 'width' => '22%', 'type' => 'string' ),
                    array( 'label' => __( 'Actions (Σ clicks)', 'ois-conversion-suite' ), 'width' => '12%', 'type' => 'numeric', 'align' => 'right' ),
                ),
                'rows'       => $adv_ix_rows,
            )
        );
        echo '</div>';

        // 3. CERRAR CONTENEDOR PRINCIPAL
        $this->render_ois_component('layout_end');

        $this->render_dashboard_scripts(
            $labels_60m, $data_60m, $line_v_today, $line_u_today, $line_v_yest, $line_u_yest, $src_labels, $src_data, $os_labels, $os_data,
            $h_clicks_today, $h_clicks_yest, $h_views_today, $h_uniques_today, $h_views_yest, $h_uniques_yest
        );
    }

    private function render_dashboard_scripts($labels, $data_60m, $v_today, $u_today, $v_yest, $u_yest, $src_labels, $src_data, $os_labels, $os_data, $h_c_t, $h_c_y, $h_v_t, $h_u_t, $h_v_y, $h_u_y) {
        ?>
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
        <script>
        jQuery(document).ready(function($) {
            window.rtChart = new Chart(document.getElementById('oisclRealTimeChart'), { type: 'bar', data: { labels: <?php echo json_encode($labels); ?>, datasets: [{ data: <?php echo json_encode($data_60m); ?>, backgroundColor: '#f56e28', borderRadius: 2 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { display: true, beginAtZero: true, ticks: { stepSize: 1, precision: 0 } }, x: { display: true, ticks: { maxTicksLimit: 12, maxRotation: 0, font: { size: 9 } } } } } });
            window.vecChart = new Chart(document.getElementById('oisclVectorChart'), { type: 'line', data: { labels: <?php echo json_encode($labels); ?>, datasets: [ { label: 'Views (Hoy)', data: <?php echo json_encode($v_today); ?>, borderColor: '#1a73e8', backgroundColor: 'rgba(26,115,232,0.1)', fill: true, tension: 0, pointRadius: 3, pointHoverRadius: 5 }, { label: 'Uniques (Hoy)', data: <?php echo json_encode($u_today); ?>, borderColor: '#d63638', tension: 0, pointRadius: 3, pointHoverRadius: 5 }, { label: 'Views (Ayer)', data: <?php echo json_encode($v_yest); ?>, borderColor: '#1a73e8', borderDash: [5, 5], fill: false, tension: 0, pointRadius: 3, pointHoverRadius: 5 }, { label: 'Uniques (Ayer)', data: <?php echo json_encode($u_yest); ?>, borderColor: '#d63638', borderDash: [5, 5], fill: false, tension: 0, pointRadius: 3, pointHoverRadius: 5 } ] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } }, x: { ticks: { maxTicksLimit: 12, maxRotation: 0, font: { size: 9 } } } } } });
            // --- MOTOR DE TABLAS WIDGET (Heredado de Analytics) ---
            function updateTablePagination(tableId) {
                var $table = $('#' + tableId);
                if(!$table.length) return;
                var pageSize = parseInt($table.attr('data-page-size')) || 6;
                var currentPage = parseInt($table.attr('data-current-page')) || 1;
                var $rows = $table.find('tbody tr');
                var totalRows = $rows.length;
                var totalPages = Math.ceil(totalRows / pageSize) || 1;

                if (currentPage > totalPages) currentPage = totalPages;
                if (currentPage < 1) currentPage = 1;
                $table.attr('data-current-page', currentPage);

                $rows.hide();
                $rows.slice((currentPage - 1) * pageSize, currentPage * pageSize).show();
                
                $('#pag-cur-' + tableId).text(currentPage);
                $('#pag-wrap-' + tableId + ' .pag-prev').prop('disabled', currentPage === 1);
                $('#pag-wrap-' + tableId + ' .pag-next').prop('disabled', currentPage === totalPages || totalPages === 0);
            }

            $('.ois-row-selector').on('change', function() {
                var target = $(this).data('target');
                $('#' + target).attr('data-page-size', $(this).val()).attr('data-current-page', 1);
                updateTablePagination(target);
            });

            $(document).on('click', '.pag-prev', function(e) {
                e.preventDefault();
                var target = $(this).data('target');
                var cur = parseInt($('#' + target).attr('data-current-page'));
                if (cur > 1) { $('#' + target).attr('data-current-page', cur - 1); updateTablePagination(target); }
            });

            $(document).on('click', '.pag-next', function(e) {
                e.preventDefault();
                var target = $(this).data('target');
                var $table = $('#' + target);
                var cur = parseInt($table.attr('data-current-page'));
                var max = Math.ceil($table.find('tbody tr').length / parseInt($table.attr('data-page-size')));
                if (cur < max) { $table.attr('data-current-page', cur + 1); updateTablePagination(target); }
            });

            // Inicializar las tablas al cargar
            $('.ois-table-dashboard').each(function() { updateTablePagination($(this).attr('id')); });

            let hourOffset = 0;
            function updateCharts(offsetChange) {
                if (offsetChange === 0) hourOffset = 0; else hourOffset += offsetChange; $('.btn-pulse-next').prop('disabled', hourOffset >= 0); let labelText = hourOffset === 0 ? '(Last 60 Minutes)' : '(' + Math.abs(hourOffset) + 'h atrás)'; $('.oiscl-hour-label').text(labelText); $('#pulse-total-clicks, #vector-total-views').text('...');
                $.post(ajaxurl, { action: 'oiscl_get_pulse_data', offset: hourOffset, nonce: '<?php echo wp_create_nonce("oiscl_admin_nonce"); ?>' }, function(r) { if(r.success) { if (window.rtChart) { window.rtChart.data.labels = r.data.labels; window.rtChart.data.datasets[0].data = r.data.clicks; window.rtChart.update(); } if (window.vecChart) { window.vecChart.data.labels = r.data.labels; window.vecChart.data.datasets[0].data = r.data.v_today; window.vecChart.data.datasets[1].data = r.data.u_today; window.vecChart.data.datasets[2].data = r.data.v_yest; window.vecChart.data.datasets[3].data = r.data.u_yest; window.vecChart.update(); } $('#pulse-total-clicks').text(r.data.total_clicks + ' Clics'); $('#vector-total-views').text(r.data.total_views + ' Vistas'); } });
            }
            $('.btn-pulse-prev').on('click', function(e) { e.preventDefault(); updateCharts(-1); }); $('.btn-pulse-next').on('click', function(e) { e.preventDefault(); updateCharts(1); }); $('.btn-pulse-reset').on('click', function(e) { e.preventDefault(); updateCharts(0); });
            $('#oiscl-export-pdf-btn').on('click', function(e) { e.preventDefault(); var btn = $(this); var originalText = btn.html(); btn.text('⌛ Procesando...').prop('disabled', true); var element = document.getElementById('oiscl-dashboard-wrap'); html2pdf().set({ margin: 0.3, filename: 'OIS_Dashboard.pdf', image: { type: 'jpeg', quality: 0.98 }, html2canvas: { scale: 2 }, jsPDF: { unit: 'in', format: 'letter', orientation: 'landscape' } }).from(element).save().then(function() { btn.html(originalText).prop('disabled', false); }); });
        
            // --- // --- NUEVOS GRÁFICOS DE 24 HORAS ---
            const hLabels = ['0:00','1:00','2:00','3:00','4:00','5:00','6:00','7:00','8:00','9:00','10:00','11:00','12:00','13:00','14:00','15:00','16:00','17:00','18:00','19:00','20:00','21:00','22:00','23:00'];

            // Chart: Hourly Clicks
            if (document.getElementById('dashHourlyClicksChart')) {
                new Chart(document.getElementById('dashHourlyClicksChart').getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: hLabels,
                        datasets: [
                            { label: 'Clicks', data: <?php echo json_encode($h_c_t); ?>, borderColor: '#f56e28', backgroundColor: 'rgba(245, 110, 40, 0.1)', fill: true, tension: 0.4, pointRadius: 3 },
                            { label: 'Past Clicks', data: <?php echo json_encode($h_c_y); ?>, borderColor: '#f56e28', borderDash: [5, 5], backgroundColor: 'transparent', fill: false, tension: 0.4, pointRadius: 0 }
                        ]
                    },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 10 } } }, tooltip: { mode: 'index', intersect: false } }, scales: { y: { display: false, beginAtZero:true }, x: { grid: { display: false } } } }
                });
            }

            // Chart: Hourly Traffic
            if (document.getElementById('dashHourlyTrafficChart')) {
                new Chart(document.getElementById('dashHourlyTrafficChart').getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: hLabels,
                        datasets: [
                            { label: 'Views', data: <?php echo json_encode($h_v_t); ?>, borderColor: '#1a73e8', backgroundColor: 'rgba(26,115,232,0.1)', fill: true, tension: 0.4, pointRadius: 3 },
                            { label: 'Uniques', data: <?php echo json_encode($h_u_t); ?>, borderColor: '#d63638', backgroundColor: 'transparent', fill: false, tension: 0.4, pointRadius: 3 },
                            { label: 'Past Views', data: <?php echo json_encode($h_v_y); ?>, borderColor: '#1a73e8', borderDash: [5, 5], backgroundColor: 'transparent', fill: false, tension: 0.4, pointRadius: 0 },
                            { label: 'Past Uniques', data: <?php echo json_encode($h_u_y); ?>, borderColor: '#d63638', borderDash: [5, 5], backgroundColor: 'transparent', fill: false, tension: 0.4, pointRadius: 0 }
                        ]
                    },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 10 } } }, tooltip: { mode: 'index', intersect: false } }, scales: { y: { display: false, beginAtZero:true }, x: { grid: { display: false } } } }
                });
            }
        
        }); // Cierra el document.ready de jQuery
        </script>
        <?php
    } // Cierra la función render_dashboard_script

}
