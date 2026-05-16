<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait OISCL_Admin_Seo_Trait {

    // ==========================================
    // MÓDULO 4: SEO AUDIT PRO
    // ==========================================
    public function display_seo_page() {
        global $wpdb; $user_id = get_current_user_id(); $today = current_time('Y-m-d');
        if (isset($_GET['preset'])) {
            $preset = sanitize_text_field($_GET['preset']);
            switch($preset) { case 'yesterday': $start_date = date('Y-m-d', strtotime($today . ' - 1 days')); $end_date = $start_date; $preset_label = "Yesterday"; break; case '7days': $start_date = date('Y-m-d', strtotime($today . ' - 6 days')); $end_date = $today; $preset_label = "Last 7 Days"; break; case '30days': $start_date = date('Y-m-d', strtotime($today . ' - 29 days')); $end_date = $today; $preset_label = "Last 30 Days"; break; default: $start_date = $today; $end_date = $today; $preset_label = "Today"; break; }
        } else { $start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : $today; $end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : $today; $preset_label = ($start_date === $end_date) ? "Selected Day" : "Custom Range"; }
        
        $args = array('post_type' => array('post', 'page'), 'posts_per_page' => -1); $all_posts = get_posts($args); $total_pages = count($all_posts); $sum_seo = 0; $total_broken = 0; $scanned = 0; $pages_data = [];
        foreach ($all_posts as $p) { $audit = get_post_meta($p->ID, '_oiscl_seo_audit', true); if ($audit) { $scanned++; $sum_seo += $audit['seo_score']; $total_broken += $audit['broken_links']; $pages_data[] = array_merge(['title' => $p->post_title, 'id' => $p->ID, 'url' => get_permalink($p->ID)], $audit); } else { $pages_data[] = ['title' => $p->post_title, 'id' => $p->ID, 'url' => get_permalink($p->ID), 'seo_score' => 0, 'broken_links' => 0, 'last_scan' => 'Never']; } }
        $avg_health = ($scanned > 0) ? round($sum_seo / $scanned) : 0; $h_color = ($avg_health >= 80 ? '#46b450' : ($avg_health >= 50 ? '#f56e28' : '#d63638'));
        
        // 1. INICIO LAYOUT UNIFICADO
        $this->render_ois_component('layout_start', array('id' => 'oiscl-seo-wrap'));

        // 2. HEADER UNIFICADO (Título, Reloj y Calendario automático)
        $this->render_ois_component('header', array(
            'title'      => '🔍 OIS SEO Content Intelligence',
            'start_date' => $start_date,
            'end_date'   => $end_date,
            'preset'     => $preset_label,
            'page_slug'  => 'oiscl-seo'
        ));



        // 3. BARRA DE SALUD DEL SERVIDOR (Bloque Independiente)
        $this->render_ois_component('server_health_bar');

        // 4. KPIs ESPECÍFICOS DE SEO (Mantenemos tu tabla de salud SEO anterior pero limpia)
        echo '<div style="background:#fff; border:1px solid #ccd0d4; border-radius:4px; margin-bottom:25px; overflow:hidden;"><table style="width:100%; border-collapse:collapse; table-layout:fixed;"><tr><td style="padding:20px; border-right:1px solid #eee; border-top:4px solid '.$h_color.';"><h4 style="margin:0; color:#666; font-size:11px; text-transform:uppercase;">GLOBAL SEO HEALTH</h4><span style="font-size:24px; font-weight:bold; color:'.$h_color.';">'.$avg_health.'%</span></td><td style="padding:20px; border-right:1px solid #eee; border-top:4px solid #1a73e8;"><h4 style="margin:0; color:#666; font-size:11px; text-transform:uppercase;">PAGES SCANNED</h4><span style="font-size:24px; font-weight:bold; color:#1a73e8;">'.$scanned.' / '.$total_pages.'</span></td><td style="padding:20px; border-right:1px solid #eee; border-top:4px solid #d63638;"><h4 style="margin:0; color:#666; font-size:11px; text-transform:uppercase;">BROKEN LINKS</h4><span style="font-size:24px; font-weight:bold; color:#d63638;">'.$total_broken.'</span></td><td style="padding:20px; border-top:4px solid #722ed1;"><h4 style="margin:0; color:#666; font-size:11px; text-transform:uppercase;">UNAUDITED</h4><span style="font-size:24px; font-weight:bold; color:#722ed1;">'.($total_pages - $scanned).'</span></td></tr></table></div>';
        echo '<div style="background:#fff; border:1px solid #ccd0d4; padding:20px; border-radius:4px;"><div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">';
        $table_name = $wpdb->prefix . 'oiscl_block_metrics'; $errors_404 = $wpdb->get_results($wpdb->prepare("SELECT destination_url as url, COUNT(*) as hits, MAX(created_at) as last_hit FROM $table_name WHERE anchor_text = '[Error 404]' AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY url ORDER BY hits DESC LIMIT 5", $start_date, $end_date));
        if (!empty($errors_404)) { echo '<div style="background:#fff5f5; border:1px solid #feb2b2; padding:20px; border-radius:4px; margin-bottom:20px;"><h3 class="ois-block-title ois-block-title--danger">🚨 Alerta de Errores 404 (Páginas no encontradas)</h3><p style="font-size:12px; margin-bottom:10px;">Los visitantes reales están aterrizando en estas URLs muertas. ¡Considera crear redirecciones 301!</p><table class="ois-table-dashboard" style="background:#fff;"><thead><tr><th>URL Solicitada</th><th style="text-align:center;">Impactos</th><th style="text-align:right;">Último Intento</th></tr></thead><tbody>'; foreach($errors_404 as $e404) { echo "<tr><td><code style='color:#d63638;'>".esc_html($e404->url)."</code></td><td style='text-align:center; font-weight:bold;'>{$e404->hits}</td><td style='text-align:right; font-size:11px; color:#666;'>{$e404->last_hit}</td></tr>"; } echo '</tbody></table></div>'; }
        echo '<h3 class="ois-block-title">📄 Audit Report & Strategy</h3><div style="display:flex; gap:10px;"><input type="text" id="seo-search" placeholder="Buscar página..." style="padding:5px 12px; width:200px; border-radius:4px; border:1px solid #ccd0d4;"><button id="oiscl-bulk-scan" class="button button-primary" style="font-weight:bold; background:#1a73e8;">⚡ Auto-Scan (Bulk)</button></div></div>';
            
        echo '<style>.oiscl-table-hover tbody tr.seo-row:hover { background-color: #f0f7ff !important; } .ois-dot { display:inline-block; width:10px; height:10px; border-radius:50%; margin-right:8px; }</style>';
        echo '<table class="wp-list-table widefat fixed striped oiscl-sortable oiscl-table-hover" id="table-seo"><thead><tr><th class="sortable" style="width:30%;">Page Title / Focus Keyword</th><th class="sortable" style="width:10%; text-align:center;">Score</th><th style="width:10%; text-align:center;">Links</th><th style="width:10%; text-align:center;">Load</th><th style="width:15%; text-align:center;">Last Update</th><th style="width:25%; text-align:right;">Acción</th></tr></thead><tbody id="seo-tbody">';
        foreach ($pages_data as $page) {
            $score = (int)$page['seo_score']; $s_color = ($score >= 80 ? '#46b450' : ($score >= 50 ? '#f56e28' : ($score > 0 ? '#d63638' : '#ccc'))); $broken_display = ($page['broken_links'] > 0) ? '<strong style="color:#d63638;">🔴 '.$page['broken_links'].'</strong>' : '0'; $load_display = isset($page['load_time']) ? $page['load_time'].'s' : '-'; $focus_kw = isset($page['focus_kw']) && !empty($page['focus_kw']) ? esc_html($page['focus_kw']) : '<i style="color:#999; font-weight:normal;">Sin analizar</i>'; $is_deduced = isset($page['is_deduced']) && $page['is_deduced'] ? '<span style="background:#eee; font-size:9px; padding:2px 4px; border-radius:3px;">Autodetectado</span>' : ''; $last_update = ($page['last_scan'] !== 'Never') ? date('d/m/Y H:i', strtotime($page['last_scan'])) : '<i style="color:#999;">Pendiente</i>'; $is_scanned = ($page['last_scan'] !== 'Never'); $btn_detalles_bg = $is_scanned ? '#46b450' : '#d63638'; $btn_detalles_text = $is_scanned ? 'Detalles ▾' : 'No Auditada'; $btn_detalles_disabled = $is_scanned ? '' : 'disabled';
            echo "<tr class='seo-row'><td><strong>".esc_html($page['title'])."</strong><br><small style='color:#1a73e8; font-weight:bold;'>🔑 {$focus_kw} {$is_deduced}</small></td><td style='text-align:center;'><span style='background:{$s_color}; color:#fff; padding:3px 8px; border-radius:10px; font-weight:bold; font-size:11px;'>{$score}/100</span></td><td style='text-align:center;'>{$broken_display}</td><td style='text-align:center;'>{$load_display}</td><td style='text-align:center; font-size:11px; color:#666;'>{$last_update}</td><td style='text-align:right;'><button class='button oiscl-toggle-seo' data-id='{$page['id']}' style='background:{$btn_detalles_bg}; color:#fff; border:none;' {$btn_detalles_disabled}>{$btn_detalles_text}</button> <button class='button button-secondary oiscl-open-modal' data-id='{$page['id']}' data-title='".esc_attr($page['title'])."'>Scan</button></td></tr>";
            if (isset($page['checklist']) && is_array($page['checklist'])) {
                echo "<tr id='seo-details-{$page['id']}' style='display:none; background:#f9fbff;'><td colspan='6' style='padding:20px; border-left:4px solid {$s_color};'><div style='display:flex; gap:20px;'><div style='flex:2; background:#fff; padding:15px; border-radius:4px; border:1px solid #ccd0d4;'><h4 style='margin-top:0; color:#333; border-bottom:1px solid #eee; padding-bottom:10px;'>📊 SEO Content Checklist</h4>";
                foreach($page['checklist'] as $check) { $dot = ($check['status'] === 'pass') ? '<span class="ois-dot" style="background:#46b450;"></span>' : (($check['status'] === 'warning') ? '<span class="ois-dot" style="background:#f56e28;"></span>' : '<span class="ois-dot" style="background:#d63638;"></span>'); echo "<div style='margin-bottom:8px; font-size:13px; line-height:1.4;'>{$dot} {$check['msg']}</div>"; }
                echo "</div><div style='flex:1; background:#fff; padding:15px; border-radius:4px; border:1px solid #ccd0d4;'><h4 style='margin-top:0; color:#333; border-bottom:1px solid #eee; padding-bottom:10px;'>📋 Stats Adicionales</h4><p style='font-size:12px; margin:5px 0;'><strong>Target Keyword:</strong> <br><code style='color:#1a73e8'>".(isset($page['focus_kw']) ? $page['focus_kw'] : '')."</code></p><p style='font-size:12px; margin:5px 0;'><strong>Tiempo Respuesta:</strong> {$load_display}</p><p style='font-size:12px; margin:5px 0;'><strong>Enlaces Rotos:</strong> {$page['broken_links']}</p>";
                if (isset($page['density'])) { echo "<p style='font-size:12px; margin:5px 0;'><strong>Total Palabras:</strong> {$page['words_total']}</p><p style='font-size:12px; margin:5px 0;'><strong>Densidad KW:</strong> {$page['density']}%</p>"; } echo "</div></div></td></tr>";
            }
        }
        echo '</tbody></table></div>'; // Cierra el contenedor de la tabla
        
        // 5. CERRAR LAYOUT UNIFICADO
        $this->render_ois_component('layout_end'); 
        
        echo '<div id="oiscl-seo-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:9999; align-items:center; justify-content:center;"><div style="background:#fff; padding:25px; border-radius:6px; width:400px; box-shadow:0 5px 15px rgba(0,0,0,0.3);"><h3 class="ois-block-title">🎯 Asistente de Enfoque SEO</h3><p style="font-size:13px; color:#666;">Define tu objetivo para la página: <br><strong id="modal-page-title" style="color:#1a73e8;"></strong></p><div style="margin:20px 0;"><label style="font-weight:bold; font-size:12px;">Palabra Clave Objetivo (Focus Keyword)</label><input type="text" id="modal-focus-key" class="regular-text" style="width:100%; margin-top:5px; padding:8px;" placeholder="Ej: restaurante miami"></div><div style="background:#f0f6fb; border-left:3px solid #1a73e8; padding:10px; font-size:11px; color:#555; margin-bottom:20px;">💡 <b>Tip:</b> Si dejas este campo en blanco, nuestro motor analizará tu título y contenido para deducir la palabra clave dominante automáticamente.</div><div style="display:flex; justify-content:flex-end; gap:10px;"><button id="btn-close-modal" class="button">Cancelar</button><button id="btn-start-scan" class="button button-primary" style="font-weight:bold;">Comenzar Auditoría ⚡</button></div><input type="hidden" id="modal-post-id" value=""></div></div>';
        ?>
        <script>
        jQuery(document).ready(function($) {
            $('#seo-search').on('keyup', function() { var value = $(this).val().toLowerCase(); $("#seo-tbody tr.seo-row").filter(function() { $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1) }); });
            $('.oiscl-toggle-seo').on('click', function(e) { e.preventDefault(); var id = $(this).data('id'); $('#seo-details-' + id).slideToggle(); $(this).text($(this).text() === 'Detalles ▾' ? 'Cerrar ▴' : 'Detalles ▾'); });
            $('.oiscl-open-modal').on('click', function(e) { e.preventDefault(); $('#modal-post-id').val($(this).data('id')); $('#modal-page-title').text($(this).data('title')); $('#modal-focus-key').val(''); $('#oiscl-seo-modal').css('display', 'flex'); });
            $('#btn-close-modal').on('click', function() { $('#oiscl-seo-modal').hide(); });
            $('#btn-start-scan').on('click', function(e) { e.preventDefault(); var btn = $(this); var postId = $('#modal-post-id').val(); var focusKey = $('#modal-focus-key').val(); btn.prop('disabled', true).text('Analizando HTML...'); $.post(ajaxurl, { action: 'oiscl_scan_page_html', post_id: postId, focus_key: focusKey, nonce: '<?php echo wp_create_nonce("oiscl_admin_nonce"); ?>' }, function(res) { if(res.success) { location.reload(); } else { alert('Error escaneando la página'); btn.prop('disabled', false).text('Comenzar Auditoría ⚡'); } }); });
            $('#oiscl-bulk-scan').on('click', function() { if(!confirm('¿Escanear automáticamente todas las páginas pendientes? El motor deducirá las palabras clave.')) return; var btns = $('.oiscl-open-modal'); var c = 0; var scanLimit = 50; $(this).prop('disabled', true).text('Escaneando en segundo plano...'); function next() { if(c < btns.length && c < scanLimit) { var pId = $(btns[c]).data('id'); $.post(ajaxurl, { action: 'oiscl_scan_page_html', post_id: pId, focus_key: '', nonce: '<?php echo wp_create_nonce("oiscl_admin_nonce"); ?>' }, function() { c++; next(); }); } else { if(c >= scanLimit) alert('Límite de Auto-Scan alcanzado ('+scanLimit+' páginas).'); location.reload(); } } next(); });
        });
        </script>
        <?php
    }

}
