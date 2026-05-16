<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait OISCL_Admin_Custom_Dashboards_Trait {

    // ==========================================
    // MÓDULO 6: CUSTOM DASHBOARDS BUILDER (Drag & Drop)
    // ==========================================
    public function display_custom_dashboards_page() {
        global $wpdb; $table_name = $wpdb->prefix . 'oiscl_block_metrics';
        $active_tab = isset( $_GET[ 'tab' ] ) ? sanitize_text_field($_GET[ 'tab' ]) : 'dashboards';
        $user_id = get_current_user_id(); $today = current_time('Y-m-d');
        
        // LÓGICA DE FECHAS SINCRONIZADA
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
            else { $start_date = $today; $end_date = $today; $preset_label = "Today"; }
        }

        $date_cap = OISCL_Plan::clamp_report_dates( $start_date, $end_date, $today );
        $start_date = $date_cap['start_date'];
        $end_date   = $date_cap['end_date'];

        $dict = $this->get_dashboard_dictionary();
        $dynamic_js = ""; // Almacenará los gráficos a renderizar

        echo '<div class="wrap oiscl-layout-root">';
        echo '<h1 class="oiscl-admin-page-title">🛠️ Custom Dashboards Builder</h1>';
        echo '<h2 class="nav-tab-wrapper oiscl-wp-tabstrip">';
            echo '<a href="?page=oiscl-custom-dashboards&tab=dashboards" class="nav-tab ' . ( $active_tab == 'dashboards' ? 'nav-tab-active' : '' ) . '">📊 Tableros Creados</a>';
            echo '<a href="?page=oiscl-custom-dashboards&tab=templates" class="nav-tab ' . ( $active_tab == 'templates' ? 'nav-tab-active' : '' ) . '">🎨 Plantillas Automáticas</a>';
        echo '</h2>';

        if ( $active_tab == 'dashboards' ) {
            $dashboards = get_option('oiscl_custom_dashboards', []);
            
            echo '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; background:#fff; padding:15px; border:1px solid #ccd0d4; border-radius:4px;">';
                echo '<button id="btn-new-custom-dash" class="button button-primary button-large" style="font-weight:bold;">➕ Construir Nuevo Dashboard</button>';
                $this->render_ois_component('date_selector', array('page_slug'=>'oiscl-custom-dashboards', 'start_date'=>$start_date, 'end_date'=>$end_date, 'preset'=>$preset_label));
            echo '</div>';

            if (empty($dashboards)) {
                echo '<div style="background:#f0f6fb; padding:40px; text-align:center; border:1px dashed #1a73e8; border-radius:6px;">';
                echo '<h3 class="ois-block-title ois-block-title--accent" style="margin:0 0 10px 0;">No tienes tableros personalizados</h3>';
                echo '<p style="color:#666; margin:0;">Haz clic en "Construir" para diseñar tu primer panel usando Drag & Drop.</p></div>';
            } else {
                foreach ($dashboards as $id => $dash) {
                    echo '<div id="wrap-dash-'.$id.'" style="background:#f9f9f9; border:1px solid #ccd0d4; border-radius:4px; margin-bottom:30px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">';
                        echo '<div class="oiscl-dash-header" data-id="'.$id.'" style="display:flex; justify-content:space-between; align-items:center; background:#fff; padding:15px 20px; cursor:pointer; border-bottom:1px solid #e2e4e7;">';
                            echo '<div><h2 class="ois-block-title ois-block-title--panel-lg ois-block-title--accent" style="margin:0;"><span class="dashicons dashicons-arrow-right-alt2" id="icon-'.$id.'" style="transition:0.3s; margin-top:2px;"></span> '.esc_html($dash['title']).'</h2></div>';
                            echo '<div style="display:flex; gap:10px; align-items:center;" class="oiscl-no-propagate">';
                                $dash_pdf_slug = preg_replace( '/[^A-Za-z0-9_-]+/', '_', $dash['title'] );
                                if ( $dash_pdf_slug === '' ) {
                                    $dash_pdf_slug = 'Dashboard_' . $id;
                                }
                                $csv_dash_url = admin_url(
                                    'admin.php?' . http_build_query(
                                        array(
                                            'page'             => 'oiscl-custom-dashboards',
                                            'export_csv_dash'  => $id,
                                            'start_date'       => $start_date,
                                            'end_date'         => $end_date,
                                        )
                                    )
                                );
                                $this->render_ois_component(
                                    'export_menu',
                                    array(
                                        'id'                  => 'exp-cd-' . sanitize_html_class( (string) $id ),
                                        'csv_url'             => $csv_dash_url,
                                        'wrap_pdf_id'         => 'dash-content-' . esc_attr( (string) $id ),
                                        'wrap_pdf_filename'   => $dash_pdf_slug,
                                    )
                                );
                                echo '<div style="display:flex; align-items:center; gap:6px; padding:6px 14px; border-radius:4px; color:#fff; font-weight:bold; font-size:11px; text-transform:uppercase; background:#d63638;"><span class="dashicons dashicons-email-alt"></span> Report OFF</div>';
                                echo '<button class="button btn-delete-dash" data-id="'.$id.'" style="color:#d63638; border-color:#d63638;">🗑️ Borrar</button>';
                            echo '</div>';
                        echo '</div>';

                        echo '<div id="dash-content-'.$id.'" style="display:none; padding:20px;">';
                        
                        $elements = $dash['elements'];
                        $table_cols = []; $donut_grid_open = false;
                        
                        // RENDERIZADO DINÁMICO DE ELEMENTOS
                        foreach ($elements as $el) {
                            if (strpos($el, 'chart_') === 0) {
                                if($donut_grid_open) { echo '</div>'; $donut_grid_open = false; }
                                echo '<div style="background:#fff; border:1px solid #ccc; padding:15px; border-radius:4px; margin-bottom:15px;">';
                                echo '<h4 class="ois-block-title ois-block-title--subcard">' . esc_html( $dict['charts'][ $el ]['label'] ) . '</h4>';
                                
                                if ($el === 'chart_hourly') {
                                    $hourly_data = $wpdb->get_results($wpdb->prepare("SELECT HOUR(created_at) as hr, SUM(clicks) as total FROM $table_name WHERE anchor_text NOT IN ('[Pageview]', '[Vista de Bloque]') AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY hr ORDER BY hr ASC", $start_date, $end_date));
                                    $h_vals = array_fill(0, 24, 0); foreach($hourly_data as $h) { $h_vals[(int)$h->hr] = (int)$h->total; }
                                    $h_json = json_encode(array_values($h_vals)); $l_json = json_encode(array_values(array_map(function($i){return str_pad($i,2,'0',STR_PAD_LEFT).":00";}, range(0,23))));
                                    
                                    echo '<div style="height:200px; position:relative;"><canvas id="cd-'.$el.'-'.$id.'"></canvas></div>';
                                    $dynamic_js .= "if(document.getElementById('cd-{$el}-{$id}')) { new Chart(document.getElementById('cd-{$el}-{$id}').getContext('2d'), { type: 'bar', data: { labels: $l_json, datasets: [{ label: 'Clics', data: $h_json, backgroundColor: '#1a73e8', borderRadius: 3 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } } }); }\n";
                                } elseif ($el === 'chart_traffic') {
                                    $traf_data = $wpdb->get_results($wpdb->prepare("SELECT HOUR(created_at) as hr, SUM(clicks) as total FROM $table_name WHERE anchor_text='[Pageview]' AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY hr ORDER BY hr ASC", $start_date, $end_date));
                                    $t_vals = array_fill(0, 24, 0); foreach($traf_data as $h) { $t_vals[(int)$h->hr] = (int)$h->total; }
                                    $t_json = json_encode(array_values($t_vals)); $l_json = json_encode(array_values(array_map(function($i){return str_pad($i,2,'0',STR_PAD_LEFT).":00";}, range(0,23))));
                                    
                                    echo '<div style="height:200px; position:relative;"><canvas id="cd-'.$el.'-'.$id.'"></canvas></div>';
                                    $dynamic_js .= "if(document.getElementById('cd-{$el}-{$id}')) { new Chart(document.getElementById('cd-{$el}-{$id}').getContext('2d'), { type: 'line', data: { labels: $l_json, datasets: [{ label: 'Views', data: $t_json, borderColor: '#d63638', backgroundColor: 'rgba(214, 54, 56, 0.1)', fill: true, tension: 0.3 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } } }); }\n";
                                }
                                echo '</div>';
                            }
                            elseif (strpos($el, 'donut_') === 0) {
                                if(!$donut_grid_open) { echo '<div style="display:grid; grid-template-columns: repeat(3, 1fr); gap:15px; margin-bottom:15px;">'; $donut_grid_open = true; }
                                echo '<div style="background:#fff; border:1px solid #ccc; padding:15px; border-radius:4px; text-align:center;">';
                                echo '<h4 class="ois-block-title ois-block-title--compact ois-block-title--subcard-tight" style="margin-top:0;">' . esc_html( $dict['donuts'][ $el ]['label'] ) . '</h4>';
                                
                                $donut_map = ['donut_source' => 'destination_url', 'donut_device' => 'device', 'donut_os' => 'os', 'donut_browser' => 'browser'];
                                $col = isset($donut_map[$el]) ? $donut_map[$el] : 'device';
                                $donut_db = $wpdb->get_results($wpdb->prepare("SELECT $col as name, COUNT(DISTINCT session_id) as total FROM $table_name WHERE anchor_text='[Pageview]' AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY name ORDER BY total DESC LIMIT 5", $start_date, $end_date));
                                $l=[]; $d=[]; if($donut_db){ foreach($donut_db as $r){ $l[]=esc_html(empty($r->name)?'Unknown':$r->name); $d[]=(int)$r->total; } } else { $l=['No Data']; $d=[0]; }
                                $l_json = json_encode(array_values($l)); $d_json = json_encode(array_values($d));
                                
                                echo '<div style="height:150px; position:relative;"><canvas id="cd-'.$el.'-'.$id.'"></canvas></div>';
                                $dynamic_js .= "if(document.getElementById('cd-{$el}-{$id}')) { new Chart(document.getElementById('cd-{$el}-{$id}').getContext('2d'), { type: 'doughnut', data: { labels: $l_json, datasets: [{ data: $d_json, backgroundColor: ['#1a73e8', '#46b450', '#f56e28', '#722ed1', '#faad14'] }] }, options: { responsive: true, maintainAspectRatio: false, plugins:{legend:{position:'bottom', labels:{boxWidth:10, font:{size:10}}}} } }); }\n";
                                
                                echo '</div>';
                            }
                            elseif (strpos($el, 'col_') === 0) {
                                $table_cols[] = str_replace('col_', '', $el);
                            }
                        }
                        if($donut_grid_open) { echo '</div>'; }

                        if (!empty($table_cols)) {
                            $dimensions = []; $metrics = []; $select_sql = [];
                            foreach ($table_cols as $col_key) {
                                if (!isset($dict['columns'][$col_key])) continue;
                                if ($dict['columns'][$col_key]['type'] === 'metric') { $metrics[] = $dict['columns'][$col_key]['sql']; $select_sql[] = $dict['columns'][$col_key]['sql']; } 
                                else { $dimensions[] = $col_key; $select_sql[] = $col_key; }
                            }

                            $sql = "SELECT " . implode(', ', $select_sql) . " FROM $table_name WHERE DATE(created_at) >= %s AND DATE(created_at) <= %s";
                            if (!empty($dimensions)) { $sql .= " GROUP BY " . implode(', ', $dimensions); }
                            $sql .= " ORDER BY " . (!empty($metrics) ? explode(' as ', $metrics[0])[0] . " DESC" : "id DESC") . " LIMIT 1000"; 
                            $results = $wpdb->get_results($wpdb->prepare($sql, $start_date, $end_date), ARRAY_A);

                            echo '<div style="background:#fff; border:1px solid #ccc; padding:20px; border-radius:4px; margin-top:20px;">';
                            echo '<h3 class="ois-block-title">📋 Datos Desglosados</h3>';
                            
                            echo '<style>.oiscl-sortable th.sortable{cursor:pointer; transition:0.2s;} .oiscl-sortable th.sortable:hover{background:#f0f0f1;}</style>';
                            echo '<table class="wp-list-table widefat fixed striped oiscl-sortable" id="table-'.$id.'">';
                            echo '<thead><tr>';
                            foreach ($table_cols as $col_key) {
                                $is_num = ($dict['columns'][$col_key]['type'] === 'metric') ? 'numeric' : 'string';
                                echo '<th class="sortable" data-type="'.$is_num.'">'.$dict['columns'][$col_key]['label'].' <span class="sort-icon"></span></th>';
                            }
                            echo '</tr></thead><tbody class="ois-paginated-body">';
                            if ($results) {
                                foreach ($results as $row) {
                                    echo '<tr class="ois-row">';
                                    foreach ($table_cols as $col_key) {
                                        $val = isset($row[$col_key]) ? $row[$col_key] : '';
                                        if ($col_key === 'is_bot') $val = ($val == 1) ? '🤖 Bot' : '👤 Humano';
                                        if ($col_key === 'avg_time' && $val > 0) $val = round($val, 1) . 's';
                                        echo '<td>'.esc_html($val).'</td>';
                                    }
                                    echo '</tr>';
                                }
                            } else { echo '<tr><td colspan="'.count($table_cols).'" style="text-align:center;">No hay datos.</td></tr>'; }
                            echo '</tbody></table>';

                            echo '<div class="ois-pag-controls" style="display:flex; justify-content:flex-end; align-items:center; gap:15px; margin-top:15px;">';
                                echo '<div style="display:flex; align-items:center; gap:5px;"><span style="font-size:11px; color:#666;">Show:</span><select class="oiscl-row-selector" data-target="table-'.$id.'" style="border-radius:4px; font-size:12px; padding:2px 24px 2px 8px; min-height:28px;"><option value="25">25 filas</option><option value="50">50 filas</option><option value="100">100 filas</option></select></div>';
                                echo '<div class="j-paginator" data-target="table-'.$id.'"></div>';
                            echo '</div>';

                            echo '</div>'; 
                        }
                        
                        echo '</div>'; 
                    echo '</div>'; 
                }
            }

            // MODAL 2 COLUMNAS DRAG & DROP
            echo '<div id="oiscl-builder-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.8); z-index:9999; align-items:center; justify-content:center;">';
                echo '<div style="background:#fff; border-radius:8px; width:900px; height:80vh; max-height:750px; display:flex; flex-direction:column; box-shadow:0 10px 40px rgba(0,0,0,0.4);">';
                    echo '<div style="padding:20px; border-bottom:1px solid #eee; display:flex; justify-content:space-between; align-items:center;">';
                        echo '<div><h2 class="ois-block-title ois-block-title--panel-lg" style="margin:0;">🛠️ Constructor de Dashboard</h2><p style="margin:5px 0 0 0; color:#666; font-size:12px;">Haz clic en los elementos de la izquierda. Arrastra a la derecha para ordenarlos.</p></div>';
                        echo '<input type="text" id="cd-title" class="regular-text" style="width:300px; padding:10px;" placeholder="Título del Dashboard...">';
                    echo '</div>';
                    echo '<div style="display:flex; flex-grow:1; overflow:hidden;">';
                        echo '<div style="flex:1; padding:20px; border-right:1px solid #eee; overflow-y:auto; background:#fbfbfb;">';
                            echo '<h4 class="ois-block-title ois-block-title--aside" style="border-bottom:2px solid #1a73e8; padding-bottom:5px;">📈 Secciones de Gráficos</h4>';
                            foreach ($dict['charts'] as $key => $data) { echo '<div class="cd-item-source" data-id="'.$key.'" style="background:#fff; border:1px solid #ccc; padding:10px; margin-bottom:8px; border-radius:4px; cursor:pointer; display:flex; justify-content:space-between; align-items:center;"><div><b>'.$data['label'].'</b><br><span style="font-size:10px; color:#888;">[Full Size] '.$data['desc'].'</span></div><span class="dashicons dashicons-plus-alt2" style="color:#46b450;"></span></div>'; }
                            echo '<h4 class="ois-block-title ois-block-title--aside" style="margin-top:20px; color:#f56e28; border-bottom:2px solid #f56e28; padding-bottom:5px;">🍩 Gráficos de Torta</h4>';
                            foreach ($dict['donuts'] as $key => $data) { echo '<div class="cd-item-source" data-id="'.$key.'" style="background:#fff; border:1px solid #ccc; padding:10px; margin-bottom:8px; border-radius:4px; cursor:pointer; display:flex; justify-content:space-between; align-items:center;"><div><b>'.$data['label'].'</b><br><span style="font-size:10px; color:#888;">[Grid 3 Col] '.$data['desc'].'</span></div><span class="dashicons dashicons-plus-alt2" style="color:#46b450;"></span></div>'; }
                            echo '<h4 class="ois-block-title ois-block-title--aside" style="margin-top:20px; color:#722ed1; border-bottom:2px solid #722ed1; padding-bottom:5px;">📊 Variables de Tabla</h4>';
                            foreach ($dict['columns'] as $key => $data) { $bg = ($data['type'] == 'metric') ? '#f3e8ff' : '#fff'; echo '<div class="cd-item-source" data-id="col_'.$key.'" style="background:'.$bg.'; border:1px solid #ccc; padding:10px; margin-bottom:8px; border-radius:4px; cursor:pointer; display:flex; justify-content:space-between; align-items:center;"><b>'.$data['label'].'</b><span class="dashicons dashicons-plus-alt2" style="color:#46b450;"></span></div>'; }
                        echo '</div>';
                        echo '<div style="flex:1; padding:20px; overflow-y:auto; background:#fff;">';
                            echo '<h4 class="ois-block-title ois-block-title--subcard" style="margin:0 0 15px 0;">Tu Dashboard (Arrastra para ordenar)</h4>';
                            echo '<ul id="cd-dropzone" style="min-height:300px; padding-bottom:50px; list-style:none; margin:0;"></ul>';
                        echo '</div>';
                    echo '</div>';
                    echo '<div style="padding:15px 20px; border-top:1px solid #eee; text-align:right; background:#f1f1f1;">';
                        echo '<button class="button btn-close-modal" style="margin-right:10px;">Cancelar</button>';
                        echo '<button id="btn-cd-save" class="button button-primary button-large" style="background:#46b450; border-color:#46b450;">💾 Guardar Dashboard</button>';
                    echo '</div>';
                echo '</div>';
            echo '</div>'; 
        } elseif ( $active_tab == 'templates' ) {
            $templates = get_option('oiscl_report_templates', ['default' => "[header]\n[report_1]\n[footer]"]);
            
            echo '<div style="display:flex; gap:20px;">';
                echo '<div style="flex:2;">';
                    echo '<div style="background:#fff; border:1px solid #ccd0d4; padding:20px; border-radius:4px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">';
                    echo '<h3 class="ois-block-title">📝 Editor de Plantillas</h3>';
                    echo '<p style="color:#666; font-size:13px;">Usa los tags para estructurar el orden en que se imprimirán los reportes. Puedes mezclar texto, HTML básico y variables de reportes.</p>';
                    echo '<input type="text" id="tpl-name" placeholder="Nombre del Template (Ej: Resumen Mensual)" style="width:100%; margin-bottom:10px; padding:8px;">';
                    echo '<textarea id="tpl-content" style="width:100%; height:250px; font-family:monospace; padding:10px; background:#fafafa; border:1px solid #ccc;"></textarea>';
                    echo '<div style="margin-top:15px; text-align:right;"><button id="btn-save-template" class="button button-primary button-large">💾 Guardar Plantilla</button></div>';
                    echo '</div>';
                echo '</div>';

                echo '<div style="flex:1; background:#f0f6fb; padding:20px; border-radius:4px; border:1px solid #bce0fd;">';
                    echo '<h4 class="ois-block-title ois-block-title--aside" style="margin-top:0;">🧩 Tags Disponibles</h4>';
                    echo '<ul style="font-family:monospace; font-size:12px; line-height:1.8;">';
                    echo '<li><code>[header_default]</code> - Cabecera con logo OIS</li>';
                    echo '<li><code>[header_custom]</code> - Solo título de la fecha</li>';
                    echo '<li><code>[report_clicks]</code> - Tabla de Click Tracker</li>';
                    echo '<li><code>[report_reading]</code> - Reading Map</li>';
                    $custom_dashboards = get_option('oiscl_custom_dashboards', []);
                    if (!empty($custom_dashboards)) {
                        echo '<li style="margin-top:10px; border-top:1px solid #ccc; padding-top:10px;"><b>Tus Dashboards:</b></li>';
                        foreach($custom_dashboards as $id => $r) {
                            echo '<li style="color:#d63638;"><code>[report_'.$id.']</code> - '.$r['title'].'</li>';
                        }
                    }
                    echo '<li style="margin-top:10px;"><code>[footer]</code> - Cierre del doc</li>';
                    echo '</ul>';
                echo '</div>';
            echo '</div>';
            
            echo '<h3 class="ois-block-title ois-block-title--spaced-top">📂 Plantillas Guardadas</h3>';
            echo '<div style="display:flex; gap:15px; flex-wrap:wrap;">';
            foreach($templates as $name => $content) {
                echo '<div style="background:#fff; border:1px solid #ccd0d4; padding:15px; border-radius:4px; width:30%; min-width:250px;">';
                echo '<h4 class="ois-block-title ois-block-title--subcard">' . esc_html( $name ) . '</h4>';
                echo '<pre style="background:#f0f0f0; padding:10px; font-size:10px; max-height:80px; overflow:hidden;">'.esc_html($content).'</pre>';
                echo '<button type="button" class="button button-small" disabled title="' . esc_attr__( 'Template editor coming soon', 'ois-conversion-suite' ) . '">✏️</button> ';
                echo '<button class="button button-small" style="color:#d63638; border-color:#d63638;">Borrar</button>';
                echo '</div>';
            }
            echo '</div>';
        }
        echo '</div>'; 

        ?>
        <style>
        .cd-item-drag { background:#fff; border:2px dashed #1a73e8; padding:12px; margin-bottom:10px; border-radius:4px; cursor:move; display:flex; justify-content:space-between; align-items:center; font-weight:bold; box-shadow:0 2px 5px rgba(0,0,0,0.05); }
        .cd-item-drag.over { border-color:#d63638; background:#fff5f5; transform:scale(1.02); }
        </style>
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
        <script>
        window.addEventListener('load', function() {
            var $ = jQuery;
            
            // Renderizado Dinámico de Gráficos de Dashboards Creados
            <?php echo $dynamic_js; ?>

            $('.oiscl-dash-header').on('click', function(e) {
                if ($(e.target).closest('.oiscl-no-propagate').length) return; 
                var id = $(this).data('id');
                var icon = $('#icon-' + id);
                $('#dash-content-' + id).slideToggle();
                if (icon.css('transform') !== 'none' && icon.css('transform') !== 'matrix(1, 0, 0, 1, 0, 0)') {
                    icon.css('transform', 'rotate(0deg)');
                } else {
                    icon.css('transform', 'rotate(90deg)');
                }
            });

            $('#btn-new-custom-dash').on('click', function(e) { e.preventDefault(); $('#oiscl-builder-modal').css('display','flex'); $('#cd-dropzone').empty(); $('#cd-title').val(''); });
            $('.btn-close-modal').on('click', function(e) { e.preventDefault(); $('#oiscl-builder-modal').hide(); });
            $('.cd-item-source').on('click', function() { var id = $(this).data('id'); var text = $(this).find('b').text(); var html = `<li class="cd-item-drag" draggable="true" data-id="${id}"><div><span style="color:#999; margin-right:10px;">☰</span> ${text}</div><span class="dashicons dashicons-trash cd-remove" style="color:#d63638; cursor:pointer;"></span></li>`; $('#cd-dropzone').append(html); attachDragEvents(); });
            $(document).on('click', '.cd-remove', function() { $(this).closest('li').remove(); });

            var dragSrcEl = null;
            function handleDragStart(e) { this.style.opacity = '0.4'; dragSrcEl = this; e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/html', this.innerHTML); }
            function handleDragOver(e) { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; return false; }
            function handleDragEnter(e) { this.classList.add('over'); }
            function handleDragLeave(e) { this.classList.remove('over'); }
            function handleDrop(e) { e.stopPropagation(); if (dragSrcEl !== this) { var tempHtml = dragSrcEl.innerHTML; var tempId = dragSrcEl.dataset.id; dragSrcEl.innerHTML = this.innerHTML; dragSrcEl.dataset.id = this.dataset.id; this.innerHTML = tempHtml; this.dataset.id = tempId; } return false; }
            function handleDragEnd(e) { this.style.opacity = '1'; document.querySelectorAll('#cd-dropzone li').forEach(item => item.classList.remove('over')); }
            function attachDragEvents() { var items = document.querySelectorAll('#cd-dropzone li'); items.forEach(function(item) { item.removeEventListener('dragstart', handleDragStart); item.removeEventListener('dragover', handleDragOver); item.removeEventListener('dragenter', handleDragEnter); item.removeEventListener('dragleave', handleDragLeave); item.removeEventListener('drop', handleDrop); item.removeEventListener('dragend', handleDragEnd); item.addEventListener('dragstart', handleDragStart, false); item.addEventListener('dragover', handleDragOver, false); item.addEventListener('dragenter', handleDragEnter, false); item.addEventListener('dragleave', handleDragLeave, false); item.addEventListener('drop', handleDrop, false); item.addEventListener('dragend', handleDragEnd, false); }); }

            $('#btn-cd-save').on('click', function() {
                var title = $('#cd-title').val(); var elements = []; $('#cd-dropzone li').each(function() { elements.push($(this).data('id')); });
                if (title.trim() === '') { alert('Ingresa un título'); return; } if (elements.length === 0) { alert('Agrega elementos'); return; }
                var btn = $(this); btn.prop('disabled', true).text('Guardando...');
                $.post(ajaxurl, { action: 'oiscl_save_custom_dash', title: title, elements: JSON.stringify(elements), nonce: '<?php echo wp_create_nonce("oiscl_admin_nonce"); ?>' }, function(r) { if(r.success) location.reload(); else { alert('Error al guardar'); btn.prop('disabled', false).text('Guardar Dashboard'); } });
            });

            $('.btn-delete-dash').on('click', function() { if(!confirm('¿Eliminar dashboard?')) return; $.post(ajaxurl, { action: 'oiscl_delete_custom_dash', id: $(this).data('id'), nonce: '<?php echo wp_create_nonce("oiscl_admin_nonce"); ?>' }, function(r) { location.reload(); }); });
            $('.oiscl-export-pdf').on('click', function(e) {
                e.preventDefault();
                var btn = $(this);
                var originalText = btn.text();
                btn.text('⏳ PDF...').prop('disabled', true);
                var element = document.getElementById(btn.data('target'));
                if (!element) {
                    btn.text(originalText).prop('disabled', false);
                    return;
                }
                var exportMenus = element.querySelectorAll('details.ois-export-menu');
                var exportMenuDisplay = [];
                exportMenus.forEach(function(m) {
                    exportMenuDisplay.push(m.style.display);
                    m.style.display = 'none';
                });
                html2pdf().set({ margin: 0.4, filename: btn.data('filename') + '.pdf', html2canvas: { scale: 2 }, jsPDF: { orientation: 'landscape' } }).from(element).save().then(function() {
                    btn.text(originalText).prop('disabled', false);
                    exportMenus.forEach(function(m, i) {
                        m.style.display = exportMenuDisplay[i] || '';
                    });
                });
            });

            function renderPaginator($wrap, $table, rowsPerPage) {
                var $rows = $table.find('tbody tr.ois-row');
                var totalPages = Math.ceil($rows.length / rowsPerPage) || 1;
                var currentPage = 1;
                
                function draw() {
                    $rows.hide(); $rows.slice((currentPage - 1) * rowsPerPage, currentPage * rowsPerPage).show();
                    let html = `<button class="button p-prev" style="font-weight:bold;" ${currentPage === 1 ? 'disabled' : ''}>&lsaquo;</button>`;
                    let start = Math.max(1, currentPage - 2); let end = Math.min(totalPages, currentPage + 2);
                    for (let i = start; i <= end; i++) { html += `<button class="button p-num" style="${i === currentPage ? 'background:#1a73e8; color:#fff; border-color:#1a73e8;' : ''}" data-page="${i}">${i}</button>`; }
                    html += `<button class="button p-next" style="font-weight:bold;" ${currentPage === totalPages ? 'disabled' : ''}>&rsaquo;</button>`;
                    $wrap.html(html);
                }
                
                draw();
                
                $wrap.off('click').on('click', '.p-prev', function(e) { e.preventDefault(); if(currentPage > 1) { currentPage--; draw(); } });
                $wrap.on('click', '.p-next', function(e) { e.preventDefault(); if(currentPage < totalPages) { currentPage++; draw(); } });
                $wrap.on('click', '.p-num', function(e) { e.preventDefault(); currentPage = $(this).data('page'); draw(); });
            }

            $('.oiscl-row-selector').on('change', function() {
                var targetTable = $(this).data('target'); var rows = parseInt($(this).val());
                var $paginator = $('.j-paginator[data-target="'+targetTable+'"]'); renderPaginator($paginator, $('#' + targetTable), rows);
            });
            $('.oiscl-row-selector').trigger('change');

            $('.oiscl-sortable th.sortable').on('click', function() {
                const table = $(this).closest('table')[0]; const tbody = table.querySelector('tbody');
                const headers = Array.from(table.querySelectorAll('th.sortable')); const index = headers.indexOf(this);
                const type = this.dataset.type; const isAsc = this.classList.contains('asc'); const direction = isAsc ? -1 : 1;
                
                headers.forEach(h => { h.classList.remove('asc', 'desc'); if(h.querySelector('.sort-icon')) h.querySelector('.sort-icon').innerText = ''; });
                this.classList.add(isAsc ? 'desc' : 'asc'); this.querySelector('.sort-icon').innerText = isAsc ? ' ⬇️' : ' ⬆️';
                
                const rowsArray = Array.from(tbody.querySelectorAll('tr.ois-row'));
                rowsArray.sort((a, b) => {
                    let aVal = a.cells[index].innerText; let bVal = b.cells[index].innerText;
                    if (type === 'numeric') { aVal = parseFloat(aVal.replace(/[^0-9.-]+/g,"")) || 0; bVal = parseFloat(bVal.replace(/[^0-9.-]+/g,"")) || 0; }
                    return aVal > bVal ? (1 * direction) : (aVal < bVal ? (-1 * direction) : 0);
                });
                tbody.append(...rowsArray);
                $(table).closest('.ois-box, div[id^="dash-content-"]').find('.oiscl-row-selector').trigger('change');
            });
            
            // Templates Save/Load JS
            $('#btn-save-template').on('click', function() {
                var name = $('#tpl-name').val(); var content = $('#tpl-content').val();
                if(name === '' || content === '') { alert('Llena ambos campos'); return; }
                $(this).prop('disabled', true).text('Guardando...');
                $.post(ajaxurl, { action: 'oiscl_save_template', name: name, content: content, nonce: '<?php echo wp_create_nonce("oiscl_admin_nonce"); ?>' }, function(r) { location.reload(); });
            });
            $('.btn-load-tpl').on('click', function() {
                $('#tpl-name').val($(this).data('name')); $('#tpl-content').val($(this).siblings('pre').text()); $('html, body').animate({scrollTop:0}, 'fast');
            });
        });
        </script>
        <?php
    }

}
