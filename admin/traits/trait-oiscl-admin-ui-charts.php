<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait OISCL_Admin_UI_Charts_Trait {

    /**  Renderiza un Widget de Gráfico Estandarizado * v0.62.15 - Componente Universal  */
    private function render_ois_chart_widget($args) {
        $id      = $args['id'] ?? 'chart-' . uniqid();
        $title   = $args['title'] ?? __( 'Chart', 'ois-conversion-suite' );
        $stats   = $args['stats'] ?? [];
        $tip     = $args['tip'] ?? '';
        $csv_url = isset( $args['export_csv_url'] ) ? $args['export_csv_url'] : '';
        $png_fn  = isset( $args['export_png_filename'] ) ? $args['export_png_filename'] : 'chart.png';
        $pdf_t   = isset( $args['export_pdf_title'] ) ? $args['export_pdf_title'] : __( 'Chart', 'ois-conversion-suite' );
        $show_export = empty( $args['hide_export_menu'] );

        echo '<div class="ois-box" style="margin-bottom:20px;">';
            echo '<div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:15px; flex-wrap:wrap; gap:15px;">';
                echo '<div>';
                    echo '<h3 class="ois-block-title ois-block-title--stack">' . esc_html($title) . '</h3>';
                    
                    if (!empty($stats)) {
                        echo '<div style="display:flex; gap:35px; margin-top:10px; flex-wrap:wrap;">';
                        foreach ($stats as $stat) {
                            $val_color = $stat['val_color'] ?? '#1a73e8';
                            $sub_color = $stat['sub_color'] ?? '#94a3b8';
                            echo '<div>';
                                echo '<div style="font-size:14px; font-weight:800; color:' . esc_attr($val_color) . ';">' . wp_kses_post($stat['value']) . '</div>';
                                if (!empty($stat['subtext'])) {
                                    echo '<div style="font-size:12px; font-weight:600; color:' . esc_attr($sub_color) . '; margin-top:3px;">' . wp_kses_post($stat['subtext']) . '</div>';
                                }
                            echo '</div>';
                        }
                        echo '</div>';
                    }
                echo '</div>';
                if ( $show_export ) {
                    $this->render_ois_component(
                        'export_menu',
                        array(
                            'id'               => 'exp-' . sanitize_key( $id ),
                            'csv_url'          => $csv_url,
                            'png_canvas_id'    => $id,
                            'png_filename'     => $png_fn,
                            'pdf_chart_title'  => $pdf_t,
                            'show_pdf_chart'   => true,
                        )
                    );
                }
            echo '</div>';

            echo '<div style="height:320px; margin-bottom: 15px; position: relative;"><canvas id="' . esc_attr($id) . '"></canvas></div>';

            if (!empty($tip)) {
                echo '<div style="background:#f8fafc; padding:8px 15px; border-radius:4px; font-size:11px; color:#475569; border-left:4px solid #1a73e8; text-align:center;">';
                    echo wp_kses_post($tip);
                echo '</div>';
            }
        echo '</div>';
    }
    
    /**
     * Renderiza un mini-widget con gráfico (Pie, Doughnut o Bar) y leyenda HTML interactiva
     */
    private function render_ois_audience_chart($id, $title, $type, $labels, $data) {
        $l_arr = is_array($labels) ? array_values($labels) : array();
        $d_arr = is_array($data) ? array_values($data) : array();
        if (empty($l_arr)) { $l_arr = ['No Data']; $d_arr = [0]; }

        echo '<div style="background:#fff; border:1px solid #ccd0d4; padding:15px; border-radius:4px; display:flex; flex-direction:column; justify-content:space-between; height:100%; box-sizing:border-box;">';
            
            echo '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">';
                echo '<h3 class="ois-block-title ois-block-title--compact">' . esc_html($title) . '</h3>';
                echo '<select id="sel-type-'.esc_attr($id).'" style="font-size:10px; padding:0 20px 0 5px; height:22px; min-height:22px; border-radius:3px; color:#475569; border-color:#cbd5e1;">';
                    echo '<option value="pie" '.selected($type, 'pie', false).'>Pie</option>';
                    echo '<option value="doughnut" '.selected($type, 'doughnut', false).'>Donut</option>';
                    echo '<option value="bar" '.selected($type, 'bar', false).'>Bar</option>';
                echo '</select>';
            echo '</div>';

            echo '<div style="position:relative; height:140px; width:100%;"><canvas id="' . esc_attr($id) . '"></canvas></div>';

            $colors = ['#1a73e8', '#46b450', '#f56e28', '#722ed1', '#d63638'];
            $c_json = json_encode(array_slice($colors, 0, count($l_arr)));
            $l_json = json_encode($l_arr);
            $d_json = json_encode($d_arr);
            
            echo '<div style="margin-top:15px; font-size:11px; color:#64748b; display:flex; flex-wrap:wrap; justify-content:center; gap:10px;">';
            $i = 0;
            foreach($l_arr as $idx => $lbl) {
                if($i >= 5) break; 
                $c = isset($colors[$i]) ? $colors[$i] : '#ccc';
                $val = isset($d_arr[$idx]) ? number_format((float)$d_arr[$idx]) : 0;
                echo '<span style="display:flex; align-items:center; gap:4px;"><span style="width:10px; height:10px; background:' . esc_attr($c) . '; border-radius:2px;"></span>' . esc_html($lbl) . ': <b style="color:#1d2327;">' . esc_html($val) . '</b></span>';
                $i++;
            }
            echo '</div>';

            // Limpiamos el ID para que sea un nombre de función válido en JS (sin guiones)
            $js_safe_id = str_replace('-', '_', $id);

            echo "<script>
                jQuery(document).ready(function($) {
                    if (typeof Chart !== 'undefined') {
                        window.oisclCharts = window.oisclCharts || {};
                        
                        function initChart_{$js_safe_id}(chartType) {
                            var canvas = document.getElementById('" . esc_js($id) . "');
                            if(!canvas) return;
                            
                            var borderRad = (chartType === 'bar') ? 4 : 0;
                            var scalesOpt = (chartType === 'bar') ? { x: {display: false}, y: {beginAtZero: true, display: false} } : { x: {display: false}, y: {display: false} };
                            
                            window.oisclCharts['" . esc_js($id) . "'] = new Chart(canvas.getContext('2d'), {
                                type: chartType,
                                data: { labels: {$l_json}, datasets: [{ data: {$d_json}, backgroundColor: {$c_json}, borderWidth: 0, borderRadius: borderRad }] },
                                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: scalesOpt }
                            });
                        }
                        
                        initChart_{$js_safe_id}('" . esc_js($type) . "');
                        
                        $('#sel-type-" . esc_js($id) . "').on('change', function() {
                            var newType = $(this).val();
                            if(window.oisclCharts['" . esc_js($id) . "']) {
                                window.oisclCharts['" . esc_js($id) . "'].destroy(); 
                            }
                            initChart_{$js_safe_id}(newType);
                        });
                    }
                });
            </script>";
        echo '</div>';
    }
    
    // ==========================================
    // INTELLIGENCE BAR (Mensajes Proactivos & Custom)
    // ==========================================
    public function render_intelligence_bar() {
        $settings     = get_option( 'oiscl_settings', array() );
        $target_urls  = isset( $settings['target_urls'] ) && is_array( $settings['target_urls'] ) ? $settings['target_urls'] : array();
        $slot_limit   = OISCL_Plan::get_page_slot_limit();
        $alerts       = array();
        $global_on    = OISCL_Tracking::is_automatic_global_enabled();

        if ( OISCL_Plan::has_metrics_retention_cap() ) {
            $retention_days = OISCL_Plan::get_metrics_retention_days();
            $alerts[]       = array(
                'type'   => 'info',
                'icon'   => '📅',
                'msg'    => '<strong>' . esc_html__( 'Lite plan:', 'ois-conversion-suite' ) . '</strong> ' . sprintf(
                    /* translators: %d: number of days */
                    esc_html__( 'Report history is limited to the last %d days. Older metrics remain stored but are hidden until you upgrade.', 'ois-conversion-suite' ),
                    (int) $retention_days
                ),
                'action' => __( 'License', 'ois-conversion-suite' ),
                'link'   => admin_url( 'admin.php?page=oiscl-settings&tab=basic' ),
            );
        }

        if ( empty( $target_urls ) ) {
            $alerts[] = array(
                'type'   => 'error',
                'icon'   => '🚨',
                'msg'    => '<strong>' . esc_html__( 'Click Tracker inactive:', 'ois-conversion-suite' ) . '</strong> ' . esc_html__( 'Choose pages in Click Tracker Setup (Page Explorer). Nothing is tracked until you do.', 'ois-conversion-suite' ),
                'action' => __( 'Open setup', 'ois-conversion-suite' ),
                'link'   => admin_url( 'admin.php?page=oiscl-settings&tab=trackpro' ),
            );
        } elseif ( ! $global_on ) {
            $any_collecting = false;
            foreach ( $target_urls as $pid ) {
                if ( OISCL_Activity::is_page_collecting( (int) $pid ) ) {
                    $any_collecting = true;
                    break;
                }
            }
            if ( ! $any_collecting ) {
                $alerts[] = array(
                    'type'   => 'error',
                    'icon'   => '🚨',
                    'msg'    => '<strong>' . esc_html__( 'Automatic global rules are off:', 'ois-conversion-suite' ) . '</strong> ' . esc_html__( 'Turn them on for Global pages, or save Custom rules on at least one page to keep collecting.', 'ois-conversion-suite' ),
                    'action' => __( 'Configure', 'ois-conversion-suite' ),
                    'link'   => admin_url( 'admin.php?page=oiscl-settings&tab=trackpro' ),
                );
            } else {
                $alerts[] = array(
                    'type'   => 'warning',
                    'icon'   => 'ℹ️',
                    'msg'    => '<strong>' . esc_html__( 'Automatic global rules are off:', 'ois-conversion-suite' ) . '</strong> ' . esc_html__( 'Global pages are paused. Custom pages with saved rules are still collecting.', 'ois-conversion-suite' ),
                    'action' => __( 'Configure', 'ois-conversion-suite' ),
                    'link'   => admin_url( 'admin.php?page=oiscl-settings&tab=trackpro' ),
                );
            }
        } else {
            $unconfigured = 0;
            $paused_pages = 0;
            foreach ( $target_urls as $pid ) {
                $state = OISCL_Activity::get_tracking_state( (int) $pid, $target_urls, $global_on );
                if ( 'paused' === $state ) {
                    $paused_pages++;
                }
                if ( 'custom' === OISCL_Tracking::get_page_tracking_mode( (int) $pid ) && ! OISCL_Activity::page_has_saved_config( (int) $pid ) ) {
                    $unconfigured++;
                }
            }
            if ( $paused_pages > 0 ) {
                $alerts[] = array(
                    'type'   => 'warning',
                    'icon'   => '⏸️',
                    'msg'    => '<strong>' . esc_html__( 'Pages paused:', 'ois-conversion-suite' ) . '</strong> ' . sprintf(
                        esc_html__( '%d selected page(s) are not collecting yet. Turn on Global per page or save Custom rules.', 'ois-conversion-suite' ),
                        (int) $paused_pages
                    ),
                    'action' => __( 'Configure', 'ois-conversion-suite' ),
                    'link'   => admin_url( 'admin.php?page=oiscl-settings&tab=trackpro' ),
                );
            } elseif ( $unconfigured > 0 ) {
                $alerts[] = array(
                    'type'   => 'warning',
                    'icon'   => '⚠️',
                    'msg'    => '<strong>' . esc_html__( 'Scan recommended:', 'ois-conversion-suite' ) . '</strong> ' . sprintf(
                        /* translators: %d: number of pages */
                        esc_html__( '%d selected page(s) have no tracking rules yet. Run Analyze (Scan) or we will auto-apply on scan.', 'ois-conversion-suite' ),
                        (int) $unconfigured
                    ),
                    'action' => __( 'Configure', 'ois-conversion-suite' ),
                    'link'   => admin_url( 'admin.php?page=oiscl-settings&tab=trackpro' ),
                );
            } else {
                $alerts[] = array(
                    'type'   => 'info',
                    'icon'   => '✅',
                    'msg'    => '<strong>' . esc_html__( 'Click Tracker active:', 'ois-conversion-suite' ) . '</strong> ' . sprintf(
                        /* translators: 1: count 2: limit */
                        esc_html__( '%1$d / %2$d pages configured with instance tracking.', 'ois-conversion-suite' ),
                        count( $target_urls ),
                        (int) $slot_limit
                    ),
                    'action' => __( 'Manage', 'ois-conversion-suite' ),
                    'link'   => admin_url( 'admin.php?page=oiscl-settings&tab=trackpro' ),
                );
            }
        }

        // --- 2. ¿QUIERES UN MENSAJE PERSONALIZADO CUALQUIERA? ---
        // Simplemente añade otro array como este a la lista. 
        // Ejemplo (puedes borrarlo o comentarlo si no lo necesitas ahora):
        /*
        $alerts[] = array(
            'type'   => 'warning', // Usa 'warning' para color naranja
            'icon'   => '📢',
            'msg'    => '<strong>Aviso de Mantenimiento:</strong> Actualizaremos el servidor esta noche a las 02:00 AM.',
            'action' => 'Saber más',
            'link'   => 'https://transformatuvidahoy.org' // URL externa o interna
        );
        */

        if (empty($alerts)) {
            return;
        }

        // 🎨 MAGIA CSS: Márgenes negativos para empujar la barra contra los bordes del contenedor Padre
        echo '<div id="oiscl-intelligence-bar" style="margin:0 0 20px 0;">';
        
        foreach ($alerts as $a) {
            // Paletas de color dinámicas según el 'type' del mensaje
            if ($a['type'] === 'error') {
                $bg = '#fef2f2'; $border = '#fecaca'; $color = '#991b1b';
            } elseif ($a['type'] === 'warning') {
                $bg = '#fffbeb'; $border = '#fde68a'; $color = '#92400e';
            } else { // info
                $bg = '#f0f9ff'; $border = '#bae6fd'; $color = '#075985';
            }
            
            // Eliminamos el border-radius y dejamos solo border-bottom
            echo '<div style="background:' . $bg . '; border-bottom:1px solid ' . $border . '; color:' . $color . '; padding:12px 25px; display:flex; align-items:center; justify-content:space-between; box-shadow:0 2px 5px rgba(0,0,0,0.05);">';
            echo '<span style="font-size:14px;">' . $a['icon'] . ' ' . $a['msg'] . '</span>';
            
            // Lógica inteligente para el botón: Si el link es '#', hace el salto interno. Si no, es un link normal.
            $onclick = ($a['link'] === '#') ? 'onclick="jQuery(\'.ois-nav-tab[data-target=tab-settings]\').click(); return false;"' : '';
            $href = ($a['link'] === '#') ? '#' : esc_url($a['link']);
            
            echo '<a href="' . $href . '" ' . $onclick . ' class="button button-small" style="background:' . $color . '; color:#fff; border:none; border-radius:4px; font-weight:bold;">' . $a['action'] . '</a>';
            echo '</div>';
        }
        echo '</div>';
    }
    
    // Método centralizado para formatear los deltas de los KPIs
    private function format_kpi_delta($curr, $prev) {
        $delta = ($prev == 0) ? ($curr > 0 ? 100 : 0) : round((($curr - $prev) / $prev) * 100, 1);
        $color = ($delta > 0) ? '#46b450' : ($delta < 0 ? '#d63638' : '#999');
        $icon = ($delta > 0) ? '▲' : ($delta < 0 ? '▼' : '▬');
        
        // Retornamos el HTML con el delta en Bold y sin el texto "vs previous"
        return "<div style='font-size:13px; color:$color; margin-top:8px; font-weight:bold;'>$icon " . abs($delta) . "%</div>";
    }

}
