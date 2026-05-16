<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait OISCL_Admin_Settings_Trait {

    public function display_settings_page() {
        $this->oiscl_process_utm_settings_request();
        $active_tab = isset( $_GET[ 'tab' ] ) ? sanitize_text_field($_GET[ 'tab' ]) : 'basic';
        echo '<div class="wrap oiscl-layout-root"><h1 class="oiscl-admin-page-title">⚙️ ' . esc_html__( 'OIS Suite Settings', 'ois-conversion-suite' ) . '</h1><h2 class="nav-tab-wrapper oiscl-wp-tabstrip">';
        echo '<a href="?page=oiscl-settings&tab=basic" class="nav-tab ' . ( $active_tab === 'basic' ? 'nav-tab-active' : '' ) . '">' . esc_html__( 'General & Reports', 'ois-conversion-suite' ) . '</a>';
        echo '<a href="?page=oiscl-settings&tab=trackpro" class="nav-tab ' . ( $active_tab === 'trackpro' ? 'nav-tab-active' : '' ) . '">' . esc_html__( 'Click Tracker Setup', 'ois-conversion-suite' ) . '</a>';
        echo '<a href="?page=oiscl-settings&tab=utmtracker" class="nav-tab ' . ( $active_tab === 'utmtracker' ? 'nav-tab-active' : '' ) . '">' . esc_html__( 'UTM Manager', 'ois-conversion-suite' ) . '</a>';
        echo '<a href="?page=oiscl-settings&tab=maintenance" class="nav-tab ' . ( $active_tab === 'maintenance' ? 'nav-tab-active' : '' ) . '">' . esc_html__( 'Maintenance', 'ois-conversion-suite' ) . '</a>';
        echo '<a href="?page=oiscl-settings&tab=backup" class="nav-tab ' . ( $active_tab === 'backup' ? 'nav-tab-active' : '' ) . '">' . esc_html__( 'Backup / Restore', 'ois-conversion-suite' ) . '</a>';
        echo '<a href="?page=oiscl-settings&tab=help" class="nav-tab ' . ( $active_tab === 'help' ? 'nav-tab-active' : '' ) . '">' . esc_html__( 'Help & Support', 'ois-conversion-suite' ) . '</a>';
        echo '</h2>';
        if ( $active_tab === 'basic' ) {
            $this->render_general_settings();
        } elseif ( $active_tab === 'trackpro' ) {
            $this->render_trackpro_settings();
        } elseif ( $active_tab === 'utmtracker' ) {
            $this->render_utmtracker_settings();
        } elseif ( $active_tab === 'backup' ) {
            $this->render_backup_restore_settings();
        } elseif ( $active_tab === 'help' ) {
            $this->render_help_support_settings();
        } elseif ( $active_tab === 'maintenance' ) {
            $this->render_maintenance_settings();
        }
        echo '</div>';
    }

    private function render_general_settings() {
        $options = get_option('oiscl_general_settings', array()); $api_key = isset($options['api_key']) ? esc_attr($options['api_key']) : ''; $rep_clicks = isset($options['rep_clicks']) ? checked(1, $options['rep_clicks'], false) : 'checked'; $rep_reads = isset($options['rep_reads']) ? checked(1, $options['rep_reads'], false) : 'checked'; $rep_format = isset($options['rep_format']) ? $options['rep_format'] : 'single'; $days_left = empty($api_key) ? 0 : 45; $status_color = "#d63638"; $status_text = "No hay una licencia activa.";
        if (!empty($api_key)) { if ($days_left > 30) { $status_color = "#46b450"; $status_text = "Licencia Activa. Expira en $days_left días."; } elseif ($days_left > 10) { $status_color = "#f56e28"; $status_text = "Atención: La licencia expira en $days_left días."; } else { $status_color = "#d63638"; $status_text = "Licencia Expirada. Por favor renueva tu suscripción."; } }
        echo '<form method="post" action="admin-post.php"><input type="hidden" name="action" value="oiscl_save_general_settings">'; wp_nonce_field('oiscl_general_nonce', 'oiscl_general_nonce');
        echo '<div style="background:#fff; border:1px solid #ccd0d4; padding:20px; margin-top:20px; max-width:800px; border-left:4px solid '.$status_color.';"><h3 class="ois-block-title">🔑 Licencia OIS Conversion Suite</h3><table class="form-table"><tr><th scope="row">API Key</th><td><input type="text" name="api_key" value="'.$api_key.'" class="regular-text" placeholder="Ingresa tu clave..."></td></tr></table><p style="color:'.$status_color.'; font-weight:bold;">'.$status_text.'</p></div>';
        echo '<div style="background:#fff; border:1px solid #ccd0d4; padding:20px; margin-top:20px; max-width:800px;"><h3 class="ois-block-title">📧 Configuración de Envío de Reportes</h3><table class="form-table"><tr><th scope="row">Incluir en el Reporte</th><td><label><input type="checkbox" name="rep_clicks" value="1" '.$rep_clicks.'> Reporte de Clics (Conversiones)</label><br><br><label><input type="checkbox" name="rep_reads" value="1" '.$rep_reads.'> Reporte de Retención (Reading Map)</label></td></tr><tr><th scope="row">Formato de Archivo</th><td><label><input type="radio" name="rep_format" value="single" '.checked('single', $rep_format, false).'> Todo en 1 solo PDF</label><br><br><label><input type="radio" name="rep_format" value="separated" '.checked('separated', $rep_format, false).'> PDFs Separados por sección</label></td></tr></table></div>';
        echo '<p class="submit"><input type="submit" name="submit" id="submit" class="button button-primary" value="Guardar Configuración General"></p></form>';
    }

    public function save_trackpro_settings() {
        if (!isset($_POST['oiscl_trackpro_nonce']) || !wp_verify_nonce($_POST['oiscl_trackpro_nonce'], 'oiscl_trackpro_nonce')) wp_die('Security check failed');
        
        $settings = get_option('oiscl_settings', array());
        
        // Tags only — master on/off via AJAX (trackpro_enabled).
        if ( isset( $_POST['separator_tags'] ) ) {
            $tags_raw = sanitize_text_field( wp_unslash( $_POST['separator_tags'] ) );
            $settings['separator_tags'] = array_filter( array_map( 'trim', explode( ',', $tags_raw ) ) );
            if ( empty( $settings['separator_tags'] ) ) {
                $settings['separator_tags'] = array( 'h2', 'h3', 'section', 'article' );
            }
        }
        
        update_option('oiscl_settings', $settings);
        wp_redirect(admin_url('admin.php?page=oiscl-settings&tab=trackpro&updated=true'));
        exit;
    }

    private function render_trackpro_settings() {
        OISCL_Activity::maybe_bootstrap_periods();
        global $wpdb; $settings = get_option('oiscl_settings', array()); $global_on = OISCL_Tracking::is_automatic_global_enabled(); $target_urls = isset($settings['target_urls']) && is_array($settings['target_urls']) ? $settings['target_urls'] : array();
        $pause_on_global_off = OISCL_Activity::should_pause_on_global_off();
        $slot_limit = OISCL_Plan::get_page_slot_limit();
        $post_types = get_post_types(array('public' => true), 'names'); unset($post_types['attachment']); $all_posts = get_posts(array('post_type' => array_keys($post_types), 'posts_per_page' => -1, 'post_status' => 'publish', 'orderby' => 'post_type', 'order' => 'ASC'));
        $selected_posts = array(); foreach ($all_posts as $p) { if (in_array($p->ID, $target_urls)) { $selected_posts[] = $p; } }
        $explorer_visible = !empty($target_urls) ? 'display:block;' : 'display:none;'; $btn_visible = !empty($target_urls) ? 'display:none;' : 'display:block;';

        $this->render_ois_component( 'layout_start', array( 'id' => 'oiscl-trackpro-settings-wrap' ) );

        echo '<div class="ois-box"><h2 class="ois-block-title ois-block-title--panel ois-block-title--flush-top">🔍 1. Page Explorer</h2><p class="description" style="margin:0 0 16px;">' . esc_html__( 'Pick the pages you want to track (up to your plan limit). Only checked pages load the Click Tracker script on the front end. Search the list, select targets, then click Update selection.', 'ois-conversion-suite' ) . '</p><button id="oiscl-start-scan-btn" class="button button-primary button-large" style="margin-bottom:15px; '.$btn_visible.'">' . esc_html__( 'Browse site pages', 'ois-conversion-suite' ) . '</button><div id="oiscl-explorer-container" style="'.$explorer_visible.' border-top:1px solid #eee; padding-top:20px; margin-top:10px;"><div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;"><input type="text" id="oiscl-search-pages" placeholder="' . esc_attr__( 'Filter pages…', 'ois-conversion-suite' ) . '" style="width:350px; padding:6px 10px; border-radius:4px; border:1px solid #ccc;"><span id="oiscl-selection-count" style="font-size:13px; font-weight:bold; color:#666;">' . esc_html( sprintf( __( 'Selected: %1$d / %2$d (max)', 'ois-conversion-suite' ), count( $target_urls ), (int) $slot_limit ) ) . '</span></div><div style="max-height: 350px; overflow-y: auto; border: 1px solid #c3c4c7; border-radius:4px; background:#fff;"><table class="wp-list-table widefat striped" id="oiscl-pages-table" style="margin:0; border:none;"><thead style="position: sticky; top: 0; z-index: 1; background: #f6f7f7; box-shadow: 0 1px 0 #c3c4c7;"><tr><th style="width:60px; text-align:center;">Sel.</th><th>' . esc_html__( 'Title', 'ois-conversion-suite' ) . '</th><th style="width:100px; text-align:center;">' . esc_html__( 'Preset', 'ois-conversion-suite' ) . '</th><th style="width:150px; text-align:center;">' . esc_html__( 'Type', 'ois-conversion-suite' ) . '</th></tr></thead><tbody id="oiscl-pages-tbody">';
        foreach ( $all_posts as $p ) {
            $is_checked = in_array( $p->ID, $target_urls ) ? 'checked' : '';
            $pt_obj = get_post_type_object( $p->post_type );
            $type_name = $pt_obj ? $pt_obj->labels->singular_name : $p->post_type;
            $preset_cell = OISCL_Activity::page_has_saved_config( $p->ID )
                ? '<span style="font-size:10px;font-weight:600;color:#2271b1;">' . esc_html__( 'Saved', 'ois-conversion-suite' ) . '</span>'
                : '<span style="color:#bbb;">—</span>';
            echo '<tr class="oiscl-row" data-post-id="' . esc_attr( $p->ID ) . '"><td style="text-align:center; vertical-align:middle;"><input type="checkbox" class="oiscl-page-checkbox" value="' . esc_attr( $p->ID ) . '" ' . $is_checked . '></td><td class="oiscl-search-content" style="vertical-align:middle;"><strong>' . esc_html( $p->post_title ) . '</strong></td><td style="text-align:center;vertical-align:middle;">' . $preset_cell . '</td><td style="text-align:center; vertical-align:middle;"><span style="color:#888; font-size:11px; text-transform:uppercase;">' . esc_html( $type_name ) . '</span></td></tr>';
        }
        echo '</tbody></table></div><div style="margin-top:20px; text-align:right;"><button id="oiscl-save-pages" class="button button-primary button-large">💾 ' . esc_html__( 'Update selection', 'ois-conversion-suite' ) . '</button></div></div></div>';

        echo '<div class="ois-box"><h2 class="ois-block-title ois-block-title--panel-lg ois-block-title--flush-top" style="color:#1a1a1a;">🎯 2. CRO audit & configuration</h2>';
        $tags_val   = ! empty( $settings['separator_tags'] ) ? implode( ', ', $settings['separator_tags'] ) : 'h2, h3, section, article';
        $master_on  = $global_on;
        echo '<div class="oiscl-automatic-global-section" style="margin:16px 0 24px;padding-bottom:22px;border-bottom:1px solid #dcdcde;">';
        echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin:0 0 8px;">';
        echo '<h3 class="ois-block-title" style="margin:0;color:#1a1a1a;font-size:16px;">⚡ ' . esc_html__( 'Automatic global rules', 'ois-conversion-suite' ) . '</h3>';
        echo '<div class="oiscl-global-master-wrap" style="display:flex;align-items:center;gap:8px;">';
        echo '<span class="oiscl-global-master-label" style="font-size:12px;font-weight:600;min-width:24px;text-align:right;">' . ( $master_on ? esc_html__( 'On', 'ois-conversion-suite' ) : esc_html__( 'Off', 'ois-conversion-suite' ) ) . '</span>';
        echo '<label class="oiscl-mode-switch oiscl-global-master-switch" title="' . esc_attr__( 'Enable Click Tracker for selected pages', 'ois-conversion-suite' ) . '"><input type="checkbox" id="oiscl-automatic-global-master" ' . ( $master_on ? 'checked' : '' ) . '><span class="oiscl-mode-slider"></span></label>';
        echo '</div></div>';
        echo '<p class="description" style="margin:0 0 18px;">' . esc_html__( 'Master switch for Global collection on all pages. When On, pages set to Global (green) collect automatically. Custom (blue) pages use saved scan rules and can stay active even when this master is Off.', 'ois-conversion-suite' ) . '</p>';
        if ( ! $global_on ) {
            echo '<div class="oiscl-global-paused-notice" style="margin:0 0 14px;padding:10px 12px;background:#fff3e0;border:1px solid #f0c36d;border-radius:4px;font-size:12px;color:#6b4e16;">' . esc_html__( 'Automatic global rules are off — pages in Global mode are paused. Custom pages with saved rules keep collecting.', 'ois-conversion-suite' ) . '</div>';
        }
        echo '<label style="display:flex;align-items:center;gap:8px;font-size:12px;margin:0 0 14px;max-width:520px;cursor:pointer;"><input type="checkbox" id="oiscl-activity-pause-on-global-off" ' . ( $pause_on_global_off ? 'checked' : '' ) . '> ' . esc_html__( 'Close activity periods when tracker is turned off', 'ois-conversion-suite' ) . '</label>';
        echo '<div class="oiscl-automatic-global-controls" style="max-width:520px;">';
        echo '<label style="display:block;font-weight:600;font-size:12px;margin-bottom:6px;">' . esc_html__( 'Default DOM tags', 'ois-conversion-suite' ) . '</label>';
        echo '<input type="text" id="oiscl-global-auto-tags" class="regular-text" value="' . esc_attr( $tags_val ) . '" placeholder="h2, h3, section, article" style="width:100%;max-width:520px;">';
        echo '<p class="description" style="margin:8px 0 14px;">' . esc_html__( 'Leave as-is if unsure — defaults work for most sites.', 'ois-conversion-suite' ) . '</p>';
        echo '<button type="button" id="oiscl-save-global-auto-tags" class="button button-primary">' . esc_html__( 'Save automatic global rules', 'ois-conversion-suite' ) . '</button>';
        echo '</div>';
        echo '</div>';
        echo '<h3 class="ois-block-title" style="margin:0 0 8px;color:#1a1a1a;font-size:16px;">' . esc_html__( 'Custom Setup', 'ois-conversion-suite' ) . '</h3>';
        echo '<p class="description" style="margin:0 0 16px;">' . esc_html__( 'Per-page switch: Global (green) uses automatic rules — needs Automatic global rules On. Custom (blue) scans links and buttons and can stay active even when global rules are off, once you save settings.', 'ois-conversion-suite' ) . '</p>';
        if (empty($selected_posts)) { echo '<div style="padding:30px; text-align:center; background:#f6f7f7; border:1px dashed #c3c4c7; border-radius:4px; color:#50575e; font-weight:bold;">' . esc_html__( 'Select pages in the explorer above to enable analysis.', 'ois-conversion-suite' ) . '</div>'; } else {
            echo '<div style="overflow-x:auto;"><table class="wp-list-table widefat striped" style="border:none; margin-top:15px; border-collapse: collapse; width:100%;"><thead><tr><th style="width:40px;"></th><th>' . esc_html__( 'Target page', 'ois-conversion-suite' ) . '</th><th style="width:90px; text-align:center;">' . esc_html__( 'Tracking', 'ois-conversion-suite' ) . '</th><th style="width:80px; text-align:center;">' . esc_html__( 'Setup', 'ois-conversion-suite' ) . '</th><th style="width:120px; text-align:center;">' . esc_html__( 'Last active', 'ois-conversion-suite' ) . '</th><th style="width:100px; text-align:center;">' . esc_html__( 'Last scan', 'ois-conversion-suite' ) . '</th><th style="width:70px; text-align:center;">' . esc_html__( 'Load', 'ois-conversion-suite' ) . '</th><th style="width:60px; text-align:center;">SEO</th><th style="width:280px; text-align:right;">' . esc_html__( 'Action', 'ois-conversion-suite' ) . '</th></tr></thead><tbody>';
            foreach($selected_posts as $sp) {
                $audit = get_post_meta($sp->ID, '_oiscl_seo_audit', true); $has_data = (is_array($audit) && isset($audit['dom'])); $arrow_color = $has_data ? '#000' : '#ccc'; $arrow_class = $has_data ? 'ois-arrow-active' : 'ois-arrow-disabled';
                $tracking_badge = OISCL_Activity::render_tracking_badge_html( $sp->ID, $target_urls, $global_on );
                $setup_badge = $has_data
                    ? '<span style="font-size:10px;font-weight:600;color:#46b450;">' . esc_html__( 'Ready', 'ois-conversion-suite' ) . '</span>'
                    : '<span style="font-size:10px;font-weight:600;color:#f56e28;">' . esc_html__( 'Pending', 'ois-conversion-suite' ) . '</span>';
                $last_active_cell = esc_html( OISCL_Activity::format_period_label( OISCL_Activity::get_last_active_period( $sp->ID ) ) );
                $load_time = (isset($audit['load_time']) && $audit['load_time'] > 0) ? $audit['load_time'] . 's' : 'N/A'; $seo_score = isset($audit['seo_score']) ? $audit['seo_score'] . '%' : 'N/A';
                $page_cfg = OISCL_Tracking::get_page_config( $sp->ID );
                $scanned_at = ( $page_cfg && ! empty( $page_cfg['scanned_at'] ) ) ? $page_cfg['scanned_at'] : '';
                $scanned_cell = $scanned_at ? esc_html( mysql2date( 'M j, Y', $scanned_at ) ) : '<span style="color:#999;">—</span>';
                $track_mode = OISCL_Tracking::get_page_tracking_mode( $sp->ID );
                $is_auto    = ( 'automatic' === $track_mode );
                echo '<tr class="oiscl-custom-setup-row" data-post-id="'.esc_attr($sp->ID).'" data-tracking-mode="'.esc_attr($track_mode).'" data-has-config="'.( OISCL_Activity::page_has_saved_config( $sp->ID ) ? '1' : '0' ).'" style="background:#fff;"><td style="vertical-align:middle; text-align:center;"><span class="dashicons dashicons-arrow-right-alt2 oiscl-accordion-arrow '.$arrow_class.'" data-id="'.esc_attr($sp->ID).'" style="color:'.$arrow_color.'; cursor:pointer; font-size:24px; transition: 0.3s;"></span></td><td style="vertical-align:middle;"><strong>'.esc_html($sp->post_title).'</strong></td><td style="vertical-align:middle; text-align:center;" class="oiscl-tracking-badge-cell" data-post-id="'.esc_attr($sp->ID).'">'.$tracking_badge.'</td><td style="vertical-align:middle; text-align:center;">'.$setup_badge.'</td><td style="vertical-align:middle; text-align:center;font-size:11px;" class="oiscl-last-active-cell" data-post-id="'.esc_attr($sp->ID).'">'.$last_active_cell.'</td><td style="vertical-align:middle; text-align:center;font-size:11px;" class="oiscl-scanned-at-cell" data-post-id="'.esc_attr($sp->ID).'">'.$scanned_cell.'</td><td style="vertical-align:middle; text-align:center;" class="oiscl-load-time-cell" data-post-id="'.esc_attr($sp->ID).'">'.$load_time.'</td><td style="vertical-align:middle; text-align:center;" class="oiscl-seo-score-cell" data-post-id="'.esc_attr($sp->ID).'">'.$seo_score.'</td><td style="vertical-align:middle; text-align:right;"><div style="display:inline-flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end;">';
                $panel_btn = '<button type="button" class="oiscl-icon-btn oiscl-toggle-accordion-btn" data-id="' . esc_attr( $sp->ID ) . '" title="' . esc_attr__( 'Open settings panel', 'ois-conversion-suite' ) . '" aria-label="' . esc_attr__( 'Open settings panel', 'ois-conversion-suite' ) . '"><span class="dashicons dashicons-admin-generic"></span></button>';
                $preview_btn = ' <a class="oiscl-icon-btn oiscl-preview-link" href="' . esc_url( get_permalink( $sp->ID ) ) . '" target="_blank" rel="noopener" title="' . esc_attr__( 'Open live page', 'ois-conversion-suite' ) . '" aria-label="' . esc_attr__( 'Open live page', 'ois-conversion-suite' ) . '"><span class="dashicons dashicons-external"></span></a>';
                if ( $is_auto ) {
                    if ( $has_data ) {
                        echo $panel_btn . $preview_btn;
                    }
                } elseif ( $has_data ) {
                    echo $panel_btn . $preview_btn . ' <button type="button" class="oiscl-icon-btn oiscl-analyze-dom-btn oiscl-analyze-icon-btn" data-id="' . esc_attr( $sp->ID ) . '" title="' . esc_attr__( 'Rescan structure (after design changes)', 'ois-conversion-suite' ) . '" aria-label="' . esc_attr__( 'Rescan', 'ois-conversion-suite' ) . '"><span class="dashicons dashicons-update"></span></button>';
                } else {
                    echo '<button type="button" class="button button-primary oiscl-analyze-dom-btn" data-id="' . esc_attr( $sp->ID ) . '">⚙️ ' . esc_html__( 'Analyze (scan)', 'ois-conversion-suite' ) . '</button>';
                }
                echo '<div class="oiscl-page-mode-wrap" style="display:inline-flex;align-items:center;gap:6px;margin-left:6px;padding-left:8px;border-left:1px solid #dcdcde;"><label class="oiscl-mode-switch oiscl-page-mode-switch" title="' . esc_attr__( 'On = Global (green). Off = Custom (blue).', 'ois-conversion-suite' ) . '"><input type="checkbox" class="oiscl-tracking-mode-toggle" data-post-id="' . esc_attr( $sp->ID ) . '" ' . ( $is_auto ? 'checked' : '' ) . '><span class="oiscl-mode-slider"></span></label><span class="oiscl-mode-label" style="font-size:10px;font-weight:600;color:#50575e;text-transform:uppercase;letter-spacing:.03em;min-width:42px;">' . ( $is_auto ? esc_html__( 'Global', 'ois-conversion-suite' ) : esc_html__( 'Custom', 'ois-conversion-suite' ) ) . '</span></div>';
                echo '</div></td></tr><tr id="accordion-row-'.esc_attr($sp->ID).'" style="display:none; background:#f9f9f9;"><td colspan="9" style="padding:0; border-bottom:1px solid #dcdcde;"><div id="accordion-content-'.esc_attr($sp->ID).'" class="oiscl-accordion-panel" data-tracking-mode="' . esc_attr( $track_mode ) . '" style="padding:25px; border-left:3px solid #dcdcde;">';
                $this->render_accordion_content_html($audit, $sp->ID);
                echo '</div></td></tr>';
            }
            echo '</tbody></table></div>';
            
        }

        $paused_ids = OISCL_Activity::get_configured_paused_ids( $target_urls );
        if ( ! empty( $paused_ids ) ) {
            echo '<div class="oiscl-configured-paused-section" style="margin-top:28px;padding-top:22px;border-top:1px solid #dcdcde;">';
            echo '<h3 class="ois-block-title" style="margin:0 0 8px;color:#1a1a1a;font-size:16px;">' . esc_html__( 'Configured (paused)', 'ois-conversion-suite' ) . '</h3>';
            echo '<p class="description" style="margin:0 0 14px;max-width:820px;">' . esc_html__( 'These pages have a saved scan or rules but are not in your current slot selection. Data is kept; reactivate to resume live tracking.', 'ois-conversion-suite' ) . '</p>';
            echo '<div style="overflow-x:auto;"><table class="wp-list-table widefat striped" style="border:none;margin-top:10px;border-collapse:collapse;width:100%;"><thead><tr><th>' . esc_html__( 'Page', 'ois-conversion-suite' ) . '</th><th style="width:180px;text-align:center;">' . esc_html__( 'Last active', 'ois-conversion-suite' ) . '</th><th style="width:140px;text-align:right;">' . esc_html__( 'Action', 'ois-conversion-suite' ) . '</th></tr></thead><tbody>';
            foreach ( $paused_ids as $pid ) {
                $post = get_post( (int) $pid );
                if ( ! $post ) {
                    continue;
                }
                $last_label = esc_html( OISCL_Activity::format_period_label( OISCL_Activity::get_last_active_period( (int) $pid ) ) );
                echo '<tr><td style="vertical-align:middle;"><strong>' . esc_html( $post->post_title ) . '</strong></td><td style="vertical-align:middle;text-align:center;font-size:11px;">' . $last_label . '</td><td style="vertical-align:middle;text-align:right;"><button type="button" class="button button-primary oiscl-reactivate-page" data-post-id="' . esc_attr( $pid ) . '">' . esc_html__( 'Reactivate', 'ois-conversion-suite' ) . '</button></td></tr>';
            }
            echo '</tbody></table></div>';
        }

        echo '</div>';

        $this->render_ois_component( 'layout_end' );
        
        
        ?>
        <div id="oiscl-toast" style="display:none;position:fixed;bottom:24px;right:24px;z-index:100001;max-width:360px;padding:12px 16px;background:#1d2327;color:#fff;border-radius:6px;font-size:12px;box-shadow:0 6px 24px rgba(0,0,0,0.25);"></div>
        <div id="oiscl-alias-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:100000;align-items:center;justify-content:center;">
            <div style="background:#fff;padding:22px;border-radius:8px;max-width:400px;width:92%;box-shadow:0 8px 30px rgba(0,0,0,0.2);">
                <h3 style="margin:0 0 12px;font-size:15px;color:#1a1a1a;"><?php echo esc_html__( 'Rename element', 'ois-conversion-suite' ); ?></h3>
                <p class="description" style="margin:0 0 10px;font-size:12px;"><?php echo esc_html__( 'Custom label for reports when the same link text appears more than once.', 'ois-conversion-suite' ); ?></p>
                <input type="text" id="oiscl-alias-input" class="regular-text" style="width:100%;margin-bottom:14px;" maxlength="80" placeholder="<?php echo esc_attr__( 'e.g. Header CTA — Buy now', 'ois-conversion-suite' ); ?>">
                <div style="display:flex;gap:8px;justify-content:flex-end;">
                    <button type="button" class="button" id="oiscl-alias-cancel"><?php echo esc_html__( 'Cancel', 'ois-conversion-suite' ); ?></button>
                    <button type="button" class="button button-primary" id="oiscl-alias-save"><?php echo esc_html__( 'Save', 'ois-conversion-suite' ); ?></button>
                </div>
            </div>
        </div>
        <style>.oiscl-icon-btn { display:inline-flex;align-items:center;justify-content:center;padding:0 !important;margin:0;border:none !important;background:transparent !important;box-shadow:none !important;color:#2271b1;cursor:pointer;text-decoration:none;line-height:1;vertical-align:middle;min-width:0; } .oiscl-icon-btn:hover, .oiscl-icon-btn:focus { background:transparent !important;border:none !important;color:#135e96;box-shadow:none !important;outline:none; } .oiscl-icon-btn .dashicons { font-size:18px;width:18px;height:18px;line-height:18px;margin:0; } .oiscl-accordion-arrow { transform: rotate(0deg); } .oiscl-accordion-arrow.open { transform: rotate(90deg); color: #46b450 !important; } .ois-arrow-disabled { cursor: not-allowed !important; } .oiscl-spinner-anim { display: inline-block; font-size: 40px; width: 40px; height: 40px; line-height: 40px; text-align: center; transform-origin: center center; animation: rotation 1s infinite linear; } @keyframes rotation { from { transform: rotate(0deg); } to { transform: rotate(359deg); } } .oiscl-mode-switch { position:relative;display:inline-block;width:40px;height:22px;vertical-align:middle; } .oiscl-mode-switch input { opacity:0;width:0;height:0; } .oiscl-mode-slider { position:absolute;cursor:pointer;inset:0;background:#d63638;border-radius:22px;transition:.2s; } .oiscl-mode-slider:before { position:absolute;content:"";height:16px;width:16px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.2s; } .oiscl-mode-switch input:checked + .oiscl-mode-slider { background:#46b450; } .oiscl-mode-switch input:checked + .oiscl-mode-slider:before { transform:translateX(18px); } .oiscl-page-mode-switch .oiscl-mode-slider { background:#2271b1; } .oiscl-page-mode-switch input:checked + .oiscl-mode-slider { background:#46b450; } #oiscl-alias-modal.is-open { display:flex !important; } .oiscl-mode-switch input:disabled + .oiscl-mode-slider { opacity:0.55; cursor:not-allowed; } #oiscl-trackpro-settings-wrap .button.button-primary { background:#2271b1 !important; border-color:#2271b1 !important; color:#fff !important; font-weight:700 !important; } #oiscl-trackpro-settings-wrap .button.button-primary:hover { background:#135e96 !important; border-color:#135e96 !important; color:#fff !important; } #oiscl-alias-modal .button.button-primary { background:#2271b1 !important; border-color:#2271b1 !important; color:#fff !important; font-weight:700 !important; }</style>
        <script>
        jQuery(document).ready(function($) {
            var oisclGlobalOn = <?php echo $global_on ? 'true' : 'false'; ?>;
            function oisclTrackingBadgeHtml(state) {
                if (state === 'global') return '<span class="oiscl-badge oiscl-badge--global" style="display:inline-block;font-size:10px;font-weight:700;text-transform:uppercase;padding:2px 8px;border-radius:10px;background:#edfaef;color:#1e7e34;"><?php echo esc_js( __( 'Global', 'ois-conversion-suite' ) ); ?></span>';
                if (state === 'custom') return '<span class="oiscl-badge oiscl-badge--custom" style="display:inline-block;font-size:10px;font-weight:700;text-transform:uppercase;padding:2px 8px;border-radius:10px;background:#e8f4fd;color:#135e96;"><?php echo esc_js( __( 'Custom', 'ois-conversion-suite' ) ); ?></span>';
                if (state === 'inactive') return '<span class="oiscl-badge oiscl-badge--inactive" style="display:inline-block;font-size:10px;font-weight:700;text-transform:uppercase;padding:2px 8px;border-radius:10px;background:#f0f0f1;color:#646970;"><?php echo esc_js( __( 'Inactive', 'ois-conversion-suite' ) ); ?></span>';
                return '<span class="oiscl-badge oiscl-badge--paused" style="display:inline-block;font-size:10px;font-weight:700;text-transform:uppercase;padding:2px 8px;border-radius:10px;background:#fff3e0;color:#b45309;"><?php echo esc_js( __( 'Paused', 'ois-conversion-suite' ) ); ?></span>';
            }
            function oisclRowTrackingState(postId) {
                var mode = $('.oiscl-custom-setup-row[data-post-id="'+postId+'"]').attr('data-tracking-mode') || $('#accordion-content-'+postId).attr('data-tracking-mode') || 'custom';
                var hasCustom = $('.oiscl-custom-setup-row[data-post-id="'+postId+'"]').attr('data-has-config') === '1';
                if (mode === 'automatic') return oisclGlobalOn ? 'global' : 'paused';
                return hasCustom ? 'custom' : 'paused';
            }
            function oisclUpdateTrackingBadge(postId) { $('.oiscl-tracking-badge-cell[data-post-id="'+postId+'"]').html(oisclTrackingBadgeHtml(oisclRowTrackingState(postId))); }
            function oisclSyncTrackingBadges() { $('.oiscl-tracking-badge-cell').each(function() { oisclUpdateTrackingBadge($(this).data('post-id')); }); }
            function oisclTogglePausedNotice(show) {
                var $sec = $('.oiscl-automatic-global-section');
                var $n = $sec.find('.oiscl-global-paused-notice');
                var msg = '<?php echo esc_js( __( 'Automatic global rules are off — pages in Global mode are paused. Custom pages with saved rules keep collecting.', 'ois-conversion-suite' ) ); ?>';
                if (show) {
                    if (!$n.length) {
                        $sec.find('p.description').first().after('<div class="oiscl-global-paused-notice" style="margin:0 0 14px;padding:10px 12px;background:#fff3e0;border:1px solid #f0c36d;border-radius:4px;font-size:12px;color:#6b4e16;">' + msg + '</div>');
                    } else { $n.text(msg).slideDown(150); }
                } else if ($n.length) { $n.slideUp(150); }
            }
            $('#oiscl-automatic-global-master').on('change', function() { var $t = $(this); var next = $t.is(':checked') ? 1 : 0; $t.prop('disabled', true); $.post(ajaxurl, { action: 'oiscl_save_automatic_global', enabled: next, nonce: '<?php echo wp_create_nonce("oiscl_admin_nonce"); ?>' }, function(r) { $t.prop('disabled', false); if (r.success) { oisclGlobalOn = !!r.data.enabled; $t.prop('checked', oisclGlobalOn); $('.oiscl-global-master-label').text(oisclGlobalOn ? '<?php echo esc_js( __( 'On', 'ois-conversion-suite' ) ); ?>' : '<?php echo esc_js( __( 'Off', 'ois-conversion-suite' ) ); ?>'); oisclSyncTrackingBadges(); oisclTogglePausedNotice(!oisclGlobalOn); } else { $t.prop('checked', !next); } }); });
            $('#oiscl-activity-pause-on-global-off').on('change', function() { $.post(ajaxurl, { action: 'oiscl_save_automatic_global', activity_pause_on_global_off: $(this).is(':checked') ? 1 : 0, nonce: '<?php echo wp_create_nonce("oiscl_admin_nonce"); ?>' }); });
            $('#oiscl-save-global-auto-tags').on('click', function() { var btn = $(this); var tags = $('#oiscl-global-auto-tags').val(); btn.prop('disabled', true).text('<?php echo esc_js( __( 'Saving…', 'ois-conversion-suite' ) ); ?>'); $.post(ajaxurl, { action: 'oiscl_save_automatic_global', separator_tags: tags, nonce: '<?php echo wp_create_nonce("oiscl_admin_nonce"); ?>' }, function() { btn.prop('disabled', false).text('<?php echo esc_js( __( 'Save automatic global rules', 'ois-conversion-suite' ) ); ?>'); }); });

            $('#oiscl-start-scan-btn').on('click', function(e) { e.preventDefault(); $(this).fadeOut(200, function() { $('#oiscl-explorer-container').slideDown(); }); });
            $('#oiscl-search-pages').on('keyup', function() { var v = $(this).val().toLowerCase(); $("#oiscl-pages-tbody .oiscl-row").filter(function() { $(this).toggle($(this).find('.oiscl-search-content').text().toLowerCase().indexOf(v) > -1) }); });
            var pageLimit = <?php echo (int) $slot_limit; ?>; $('.oiscl-page-checkbox').on('change', function() { var count = $('.oiscl-page-checkbox:checked').length; if(count > pageLimit) { alert('<?php echo esc_js( __( 'Page limit reached.', 'ois-conversion-suite' ) ); ?> ' + pageLimit); $(this).prop('checked', false); count = pageLimit; } $('#oiscl-selection-count').text('<?php echo esc_js( __( 'Selected:', 'ois-conversion-suite' ) ); ?> ' + count + ' / ' + pageLimit + ' <?php echo esc_js( __( '(max)', 'ois-conversion-suite' ) ); ?>'); });
            $('#oiscl-save-pages').on('click', function(e) { e.preventDefault(); var selected = []; $('.oiscl-page-checkbox:checked').each(function() { selected.push($(this).val()); }); var btn = $(this); btn.prop('disabled', true).text('<?php echo esc_js( __( 'Updating…', 'ois-conversion-suite' ) ); ?>'); $.post(ajaxurl, { action: 'oiscl_save_target_pages', target_urls: JSON.stringify(selected), nonce: '<?php echo wp_create_nonce("oiscl_admin_nonce"); ?>' }, function() { location.reload(); }); });
            $(document).on('click', '.oiscl-reactivate-page', function(e) {
                e.preventDefault();
                var id = String($(this).data('post-id'));
                var $cb = $('.oiscl-page-checkbox[value="' + id + '"]');
                if (!$cb.length) return;
                $('#oiscl-start-scan-btn').hide();
                $('#oiscl-explorer-container').slideDown();
                if (!$cb.is(':checked')) {
                    var count = $('.oiscl-page-checkbox:checked').length;
                    if (count >= pageLimit) { alert('<?php echo esc_js( __( 'Page limit reached.', 'ois-conversion-suite' ) ); ?> ' + pageLimit); return; }
                    $cb.prop('checked', true);
                    $('#oiscl-selection-count').text('<?php echo esc_js( __( 'Selected:', 'ois-conversion-suite' ) ); ?> ' + (count + 1) + ' / ' + pageLimit + ' <?php echo esc_js( __( '(max)', 'ois-conversion-suite' ) ); ?>');
                }
                var $row = $cb.closest('.oiscl-row');
                if ($row.length && $row[0].scrollIntoView) { $row[0].scrollIntoView({ behavior: 'smooth', block: 'center' }); $row.css('background', '#f0f7ff'); setTimeout(function() { $row.css('background', ''); }, 2200); }
                oisclShowToast('<?php echo esc_js( __( 'Page selected in the explorer — click Update selection to reactivate tracking.', 'ois-conversion-suite' ) ); ?>');
            });
            $('.oiscl-toggle-accordion-btn').on('click', function(e) { e.preventDefault(); var id = $(this).data('id'); $('#accordion-row-' + id).slideToggle(300); $('.oiscl-accordion-arrow[data-id="'+id+'"]').toggleClass('open'); });
            $('.oiscl-accordion-arrow').on('click', function() { if ($(this).hasClass('ois-arrow-disabled')) return; var id = $(this).data('id'); $('#accordion-row-' + id).slideToggle(300); $(this).toggleClass('open'); });
            var oisclToastTimer = null;
            function oisclShowToast(msg) { var $t = $('#oiscl-toast'); $t.text(msg).fadeIn(150); if (oisclToastTimer) clearTimeout(oisclToastTimer); oisclToastTimer = setTimeout(function() { $t.fadeOut(200); }, 4200); }
            function oisclRemindSave(postId) { oisclShowToast('<?php echo esc_js( __( 'Selection changed — click Save tracking rules to apply.', 'ois-conversion-suite' ) ); ?>'); $('#accordion-content-' + postId).attr('data-unsaved', '1'); }
            function oisclBuildScanDiffNotice(diff) {
                if (!diff) return '';
                var parts = [];
                if (diff.added) parts.push(diff.added + ' <?php echo esc_js( __( 'new', 'ois-conversion-suite' ) ); ?>');
                if (diff.removed) parts.push(diff.removed + ' <?php echo esc_js( __( 'removed', 'ois-conversion-suite' ) ); ?>');
                if (!parts.length) return '<div class="oiscl-scan-diff-notice" style="margin:0 0 14px;padding:10px 12px;background:#f0f7ff;border:1px solid #c5d9f5;border-radius:4px;font-size:12px;"><?php echo esc_js( __( 'Scan complete — no structural changes since the last scan.', 'ois-conversion-suite' ) ); ?></div>';
                var html = '<div class="oiscl-scan-diff-notice" style="margin:0 0 14px;padding:10px 12px;background:#f0f7ff;border:1px solid #c5d9f5;border-radius:4px;font-size:12px;"><strong><?php echo esc_js( __( 'Scan complete:', 'ois-conversion-suite' ) ); ?></strong> ' + parts.join(', ') + '.';
                if (diff.added_labels && diff.added_labels.length) html += ' <span style="color:#50575e;"><?php echo esc_js( __( 'New:', 'ois-conversion-suite' ) ); ?> ' + diff.added_labels.slice(0, 4).join('; ') + '</span>';
                return html + '</div>';
            }
            function oisclInitPanelBehaviors($panel) {
                if (!$panel || !$panel.length) return;
                var postId = $panel.attr('id').replace('accordion-content-', '');
                $panel.find('.oiscl-interactive-filter').each(function() { var $sel = $(this), saved = null; try { saved = localStorage.getItem('oiscl_noise_filter_' + postId); } catch (e) {} if (saved && $sel.find('option[value="' + saved + '"]').length) $sel.val(saved); oisclApplyInteractiveFilter($sel); });
                $panel.find('.oiscl-reading-filter').each(function() { var $sel = $(this), saved = null; try { saved = localStorage.getItem('oiscl_reading_filter_' + postId); } catch (e) {} if (saved && $sel.find('option[value="' + saved + '"]').length) $sel.val(saved); oisclApplyReadingFilter($sel); });
                oisclSyncBulkMaster($panel, '.ois-track-view', '.oiscl-select-all-view');
                oisclSyncBulkMaster($panel, '.ois-track-click', '.oiscl-select-all-click');
            }
            function oisclCollectRescanTrackMap(postId) { var map = {}; $('#accordion-content-'+postId+' .oiscl-rescan-track-added').each(function() { map[$(this).data('id')] = $(this).is(':checked') ? 1 : 0; }); return map; }
            $(document).on('click', '.oiscl-rescan-accept-all', function(e) { e.preventDefault(); var postId = $(this).data('post-id'); $('#accordion-content-'+postId+' .oiscl-rescan-track-added').prop('checked', true); });
            $(document).on('click', '.oiscl-rescan-discard', function(e) { e.preventDefault(); var postId = $(this).data('post-id'); var $btn = $(this); $btn.prop('disabled', true); $.post(ajaxurl, { action: 'oiscl_apply_rescan_review', post_id: postId, review_action: 'discard', nonce: '<?php echo wp_create_nonce("oiscl_admin_nonce"); ?>' }, function(r) { $btn.prop('disabled', false); if (r.success && r.data && r.data.accordion_html) { $('#accordion-content-'+postId).html(r.data.accordion_html); if (typeof oisclInitPanelBehaviors==='function') oisclInitPanelBehaviors($('#accordion-content-'+postId)); if (typeof oisclShowToast==='function') oisclShowToast(r.data.message || '<?php echo esc_js( __( 'Scan discarded.', 'ois-conversion-suite' ) ); ?>'); } }); });
            $(document).on('click', '.oiscl-rescan-apply', function(e) { e.preventDefault(); var postId = $(this).data('post-id'); var $btn = $(this); $btn.prop('disabled', true); $.post(ajaxurl, { action: 'oiscl_apply_rescan_review', post_id: postId, review_action: 'apply', track_added: JSON.stringify(oisclCollectRescanTrackMap(postId)), nonce: '<?php echo wp_create_nonce("oiscl_admin_nonce"); ?>' }, function(r) { $btn.prop('disabled', false); if (r.success && r.data && r.data.accordion_html) { $('#accordion-content-'+postId).html(r.data.accordion_html); if (typeof oisclInitPanelBehaviors==='function') oisclInitPanelBehaviors($('#accordion-content-'+postId)); if (r.data.tracking_state) { $('.oiscl-tracking-badge-cell[data-post-id="'+postId+'"]').html(oisclTrackingBadgeHtml(r.data.tracking_state)); $('.oiscl-custom-setup-row[data-post-id="'+postId+'"]').attr('data-has-config','1'); } if (typeof oisclShowToast==='function') oisclShowToast(r.data.message || '<?php echo esc_js( __( 'New configuration version saved.', 'ois-conversion-suite' ) ); ?>'); } }); });
            $('.oiscl-analyze-dom-btn').on('click', function(e) { e.preventDefault(); var btn = $(this); var postId = btn.data('id'); var $row = $('#accordion-row-' + postId); var $content = $('#accordion-content-' + postId); var $arrow = $('.oiscl-accordion-arrow[data-id="'+postId+'"]'); var isIcon = btn.hasClass('oiscl-analyze-icon-btn'); if (isIcon) { btn.prop('disabled', true).find('.dashicons').addClass('oiscl-spinner-anim'); } else { btn.text('⌛'); } $row.show(); $arrow.addClass('open').removeClass('ois-arrow-disabled').css('color', '#46b450'); $content.html('<div style="text-align:center; padding:40px;"><span class="dashicons dashicons-update oiscl-spinner-anim" style="color:#1a73e8;"></span><p style="margin-top:15px; color:#666;">Scanning DOM and building tracking map…</p></div>'); $.post(ajaxurl, { action: 'oiscl_scan_page_html', post_id: postId, nonce: '<?php echo wp_create_nonce("oiscl_admin_nonce"); ?>' }, function(response) { if(response.success && response.data) { if (response.data.requires_review && response.data.review_html) { $content.html(response.data.review_html); if(typeof oisclShowToast==='function') oisclShowToast('<?php echo esc_js( __( 'Review structural changes before saving a new version.', 'ois-conversion-suite' ) ); ?>'); } else if (response.data.accordion_html) { $content.html((typeof oisclBuildScanDiffNotice==='function'?oisclBuildScanDiffNotice(response.data.diff||{}):'')+response.data.accordion_html); if(typeof oisclInitPanelBehaviors==='function') oisclInitPanelBehaviors($content); } if(response.data.scanned_at) $('.oiscl-scanned-at-cell[data-post-id="'+postId+'"]').text(response.data.scanned_at.substring(0,10)); if(response.data.load_time) $('.oiscl-load-time-cell[data-post-id="'+postId+'"]').text(response.data.load_time+'s'); if(response.data.seo_score!==undefined) $('.oiscl-seo-score-cell[data-post-id="'+postId+'"]').text(response.data.seo_score+'%'); if (!response.data.requires_review && typeof oisclShowToast==='function') oisclShowToast('<?php echo esc_js( __( 'Scan complete.', 'ois-conversion-suite' ) ); ?>'); if (isIcon) { btn.prop('disabled', false).find('.dashicons').removeClass('oiscl-spinner-anim'); } } else { if (isIcon) { btn.prop('disabled', false).find('.dashicons').removeClass('oiscl-spinner-anim'); } else { btn.text('⚙️ <?php echo esc_js( __( 'Error', 'ois-conversion-suite' ) ); ?>'); } $content.html('<p style="color:#d63638;text-align:center;"><?php echo esc_js( __( 'Scan failed. Please try again.', 'ois-conversion-suite' ) ); ?></p>'); } }); });
            $(document).on('click', '.oiscl-save-dom-rules', function() { var btn = $(this); var postId = btn.data('post-id'); btn.text('<?php echo esc_js( __( 'Saving…', 'ois-conversion-suite' ) ); ?>'); var instances = []; $('#accordion-content-'+postId+' .ois-inst-row').each(function() { instances.push({ id: $(this).data('id'), track_view: $(this).find('.ois-track-view').is(':checked'), track_click: $(this).find('.ois-track-click').is(':checked'), custom_label: $(this).data('custom-label') || '' }); }); $.post(ajaxurl, { action: 'oiscl_save_page_tags', post_id: postId, tags: JSON.stringify({ instances: instances }), nonce: '<?php echo wp_create_nonce("oiscl_admin_nonce"); ?>' }, function(r) { btn.text('<?php echo esc_js( __( 'Saved', 'ois-conversion-suite' ) ); ?>'); $('#accordion-content-' + postId).removeAttr('data-unsaved'); if (r.success && r.data && r.data.tracking_state) { $('.oiscl-tracking-badge-cell[data-post-id="'+postId+'"]').html(oisclTrackingBadgeHtml(r.data.tracking_state)); $('.oiscl-custom-setup-row[data-post-id="'+postId+'"]').attr('data-has-config','1'); } setTimeout(function() { btn.text('<?php echo esc_js( __( 'Save tracking rules', 'ois-conversion-suite' ) ); ?>'); }, 2000); }); });
            function oisclSetPageModeUI(postId, isAuto) { var $p = $('#accordion-content-' + postId); $p.attr('data-tracking-mode', isAuto ? 'automatic' : 'custom'); $('.oiscl-custom-setup-row[data-post-id="'+postId+'"]').attr('data-tracking-mode', isAuto ? 'automatic' : 'custom'); $p.find('.oiscl-auto-tracking-panel').toggle(isAuto); $p.find('.oiscl-custom-tracking-panel, .oiscl-custom-save-row, .oiscl-dom-map-panel').toggle(!isAuto); oisclUpdateTrackingBadge(postId); }
            $(document).on('change', '.oiscl-tracking-mode-toggle', function() {
                var $t = $(this);
                var postId = $t.data('post-id');
                var isAuto = $t.is(':checked');
                if (isAuto && !oisclGlobalOn) {
                    $t.prop('checked', false);
                    oisclShowToast('<?php echo esc_js( __( 'Turn Automatic global rules On first to use Global mode on a page.', 'ois-conversion-suite' ) ); ?>');
                    return;
                }
                if (!isAuto) {
                    var hasConfig = $('.oiscl-custom-setup-row[data-post-id="'+postId+'"]').attr('data-has-config') === '1';
                    if (!hasConfig) {
                        oisclShowToast('<?php echo esc_js( __( 'This page is Paused in Custom mode. Scan and save tracking rules, remove it from the explorer, or switch to Global if you prefer automatic setup.', 'ois-conversion-suite' ) ); ?>');
                    }
                }
                var mode = isAuto ? 'automatic' : 'custom';
                $t.closest('.oiscl-page-mode-wrap').find('.oiscl-mode-label').text(isAuto ? '<?php echo esc_js( __( 'Global', 'ois-conversion-suite' ) ); ?>' : '<?php echo esc_js( __( 'Custom', 'ois-conversion-suite' ) ); ?>');
                oisclSetPageModeUI(postId, isAuto);
                $.post(ajaxurl, { action: 'oiscl_save_page_tags', post_id: postId, tags: JSON.stringify({ tracking_mode: mode }), nonce: '<?php echo wp_create_nonce("oiscl_admin_nonce"); ?>' }, function(r) { if (r.success && r.data && r.data.tracking_state) { $('.oiscl-tracking-badge-cell[data-post-id="'+postId+'"]').html(oisclTrackingBadgeHtml(r.data.tracking_state)); } });
            });
            $(document).on('click', '.oiscl-save-auto-tags', function() { var btn = $(this); var postId = btn.data('post-id'); var tags = $('#accordion-content-' + postId + ' .oiscl-auto-tags-input').val(); btn.prop('disabled', true).text('<?php echo esc_js( __( 'Saving…', 'ois-conversion-suite' ) ); ?>'); $.post(ajaxurl, { action: 'oiscl_save_page_tags', post_id: postId, tags: JSON.stringify({ tracking_mode: 'automatic', auto_tags: tags }), nonce: '<?php echo wp_create_nonce("oiscl_admin_nonce"); ?>' }, function() { btn.prop('disabled', false).text('<?php echo esc_js( __( 'Save automatic tags', 'ois-conversion-suite' ) ); ?>'); }); });
            var oisclAliasCtx = { postId: 0, instId: '', $row: null };
            $(document).on('click', '.ois-edit-label-btn', function(e) { e.preventDefault(); e.stopPropagation(); var $row = $(this).closest('.ois-inst-click'); oisclAliasCtx.postId = $(this).data('post-id'); oisclAliasCtx.instId = $(this).data('id'); oisclAliasCtx.$row = $row; $('#oiscl-alias-input').val($row.data('custom-label') || ''); $('#oiscl-alias-modal').addClass('is-open'); $('#oiscl-alias-input').focus(); });
            $('#oiscl-alias-cancel').on('click', function() { $('#oiscl-alias-modal').removeClass('is-open'); });
            $('#oiscl-alias-modal').on('click', function(e) { if (e.target === this) { $(this).removeClass('is-open'); } });
            $('#oiscl-alias-save').on('click', function() { var label = $.trim($('#oiscl-alias-input').val()); var $row = oisclAliasCtx.$row; if (!$row || !oisclAliasCtx.postId) return; $row.data('custom-label', label); var $disp = $row.find('.ois-custom-label-display'); if (label) { $disp.text(label).show(); } else { $disp.text('').hide(); } $('#oiscl-alias-modal').removeClass('is-open'); $.post(ajaxurl, { action: 'oiscl_save_page_tags', post_id: oisclAliasCtx.postId, tags: JSON.stringify({ instances: [{ id: oisclAliasCtx.instId, custom_label: label }] }), nonce: '<?php echo wp_create_nonce("oiscl_admin_nonce"); ?>' }); });
            var oisclNoiseRank = { none: 0, low: 1, medium: 2, high: 3 };
            var oisclNoiseHideFrom = { low: 3, medium: 2, high: 1 };
            function oisclTierVisible(tier, level) {
                if (!level || level === 'all') return true;
                var rank = oisclNoiseRank[tier] !== undefined ? oisclNoiseRank[tier] : 0;
                var hideFrom = oisclNoiseHideFrom[level];
                return hideFrom === undefined ? true : rank < hideFrom;
            }
            function oisclApplyInteractiveFilter($select) {
                var postId = $select.data('post-id');
                var level = $select.val();
                var $panel = $('#accordion-content-' + postId);
                var $rows = $panel.find('.ois-inst-click');
                var visible = 0;
                $rows.each(function() {
                    var tier = $(this).data('noise-tier') || 'none';
                    var show = oisclTierVisible(tier, level);
                    $(this).toggle(show);
                    if (show) visible++;
                });
                $panel.find('.oiscl-interactive-filter-count').text(visible + ' / ' + $rows.length + ' shown');
                try { localStorage.setItem('oiscl_noise_filter_' + postId, level); } catch (e) {}
            }
            function oisclApplyReadingFilter($select) {
                var postId = $select.data('post-id');
                var val = $select.val() || 'all';
                var $panel = $('#accordion-content-' + postId);
                var $rows = $panel.find('.ois-inst-view');
                var visible = 0;
                $rows.each(function() {
                    var $row = $(this);
                    var blockTag = String($row.data('block-tag') || '').toLowerCase();
                    var tagFilter = String($row.data('tag-filter') || blockTag || '').toLowerCase();
                    var show = true;
                    if (val !== 'all') {
                        if (val === 'others') {
                            show = tagFilter === 'others';
                        } else {
                            show = blockTag === val;
                        }
                    }
                    $row.toggle(show);
                    if (show) visible++;
                });
                $panel.find('.oiscl-reading-filter-count').text(visible + ' / ' + $rows.length + ' shown');
                try { localStorage.setItem('oiscl_reading_filter_' + postId, val); } catch (e) {}
            }
            $('.oiscl-reading-filter').each(function() {
                var $sel = $(this);
                var postId = $sel.data('post-id');
                var saved = null;
                try { saved = localStorage.getItem('oiscl_reading_filter_' + postId); } catch (e) {}
                if (saved && $sel.find('option[value="' + saved + '"]').length) { $sel.val(saved); }
                oisclApplyReadingFilter($sel);
            });
            $(document).on('change', '.oiscl-reading-filter', function() { oisclApplyReadingFilter($(this)); });
            $('.oiscl-interactive-filter').each(function() {
                var $sel = $(this);
                var postId = $sel.data('post-id');
                var saved = null;
                try { saved = localStorage.getItem('oiscl_noise_filter_' + postId); } catch (e) {}
                if (saved && $sel.find('option[value="' + saved + '"]').length) {
                    $sel.val(saved);
                }
                oisclApplyInteractiveFilter($sel);
            });
            $(document).on('change', '.oiscl-interactive-filter', function() { oisclApplyInteractiveFilter($(this)); });
            function oisclSyncBulkMaster($panel, itemSel, masterSel) {
                var $items = $panel.find(itemSel);
                var $master = $panel.find(masterSel);
                if (!$master.length || !$items.length) return;
                var checked = $items.filter(':checked').length;
                $master.prop('checked', checked === $items.length);
                $master.prop('indeterminate', checked > 0 && checked < $items.length);
            }
            $(document).on('change', '.oiscl-select-all-view', function() { var postId = $(this).data('post-id'); var on = $(this).is(':checked'); $('#accordion-content-' + postId).find('.ois-track-view').prop('checked', on); $(this).prop('indeterminate', false); oisclRemindSave(postId); });
            $(document).on('change', '.oiscl-select-all-click', function() { var postId = $(this).data('post-id'); var on = $(this).is(':checked'); $('#accordion-content-' + postId).find('.ois-track-click').prop('checked', on); $(this).prop('indeterminate', false); oisclRemindSave(postId); });
            $(document).on('click', '.oiscl-bulk-click-all', function(e) { e.preventDefault(); var postId = $(this).data('post-id'); $('#accordion-content-' + postId).find('.ois-track-click').prop('checked', true); oisclSyncBulkMaster($('#accordion-content-' + postId), '.ois-track-click', '.oiscl-select-all-click'); oisclRemindSave(postId); });
            $(document).on('click', '.oiscl-bulk-click-none', function(e) { e.preventDefault(); var postId = $(this).data('post-id'); $('#accordion-content-' + postId).find('.ois-track-click').prop('checked', false); oisclSyncBulkMaster($('#accordion-content-' + postId), '.ois-track-click', '.oiscl-select-all-click'); oisclRemindSave(postId); });
            $(document).on('click', '.oiscl-bulk-click-visible', function(e) { e.preventDefault(); var postId = $(this).data('post-id'); var $panel = $('#accordion-content-' + postId); $panel.find('.ois-inst-click:visible .ois-track-click').prop('checked', true); oisclSyncBulkMaster($panel, '.ois-track-click', '.oiscl-select-all-click'); oisclRemindSave(postId); });
            $(document).on('change', '.ois-track-view', function() { oisclSyncBulkMaster($(this).closest('.oiscl-accordion-panel'), '.ois-track-view', '.oiscl-select-all-view'); oisclRemindSave($(this).closest('.oiscl-accordion-panel').attr('id').replace('accordion-content-', '')); });
            $(document).on('change', '.ois-track-click', function() { oisclSyncBulkMaster($(this).closest('.oiscl-accordion-panel'), '.ois-track-click', '.oiscl-select-all-click'); oisclRemindSave($(this).closest('.oiscl-accordion-panel').attr('id').replace('accordion-content-', '')); });
            function oisclInitBulkMasters() {
                $('.oiscl-accordion-panel').each(function() {
                    var $p = $(this);
                    oisclSyncBulkMaster($p, '.ois-track-view', '.oiscl-select-all-view');
                    oisclSyncBulkMaster($p, '.ois-track-click', '.oiscl-select-all-click');
                });
            }
            oisclInitBulkMasters();
        });
        </script>
        <?php
    }

    public function oiscl_get_accordion_panel_html( $post_id ) {
        $audit = get_post_meta( (int) $post_id, '_oiscl_seo_audit', true );
        if ( ! is_array( $audit ) ) {
            $audit = array();
        }
        ob_start();
        $this->render_accordion_content_html( $audit, (int) $post_id );
        return ob_get_clean();
    }

    /**
     * Rescan review panel (Phase 3) — shown when structural DOM diff is pending approval.
     *
     * @param int   $post_id Post ID.
     * @param array $pending pending_rescan payload.
     */
    public function oiscl_render_rescan_review_html( $post_id, array $pending ) {
        $post_id = (int) $post_id;
        $diff    = isset( $pending['diff'] ) && is_array( $pending['diff'] ) ? $pending['diff'] : array();
        $added   = isset( $diff['added_items'] ) && is_array( $diff['added_items'] ) ? $diff['added_items'] : array();
        $removed = isset( $diff['removed_items'] ) && is_array( $diff['removed_items'] ) ? $diff['removed_items'] : array();
        $unchanged = isset( $diff['unchanged'] ) ? (int) $diff['unchanged'] : 0;
        $next_rev  = OISCL_Tracking::get_config_revision( OISCL_Tracking::get_page_config( $post_id ) ) + 1;

        echo '<div class="oiscl-rescan-review" data-post-id="' . esc_attr( $post_id ) . '" style="margin:0 0 20px;padding:18px;background:#fff;border:1px solid #c5d9f5;border-left:4px solid #2271b1;border-radius:6px;">';
        echo '<h4 style="margin:0 0 8px;color:#1a1a1a;font-size:14px;">' . esc_html__( 'Review scan changes', 'ois-conversion-suite' ) . '</h4>';
        echo '<p class="description" style="margin:0 0 14px;font-size:12px;">' . esc_html__( 'The page structure changed. Choose what to track in the new version, then save. Unchecked new items are ignored.', 'ois-conversion-suite' ) . '</p>';
        echo '<p style="margin:0 0 14px;font-size:12px;color:#50575e;"><strong>' . esc_html( (string) ( isset( $diff['added'] ) ? (int) $diff['added'] : count( $added ) ) ) . '</strong> ' . esc_html__( 'added', 'ois-conversion-suite' ) . ' · <strong>' . esc_html( (string) ( isset( $diff['removed'] ) ? (int) $diff['removed'] : count( $removed ) ) ) . '</strong> ' . esc_html__( 'removed', 'ois-conversion-suite' ) . ' · <strong>' . esc_html( (string) $unchanged ) . '</strong> ' . esc_html__( 'unchanged', 'ois-conversion-suite' ) . '</p>';

        if ( ! empty( $added ) ) {
            echo '<div style="margin-bottom:14px;"><div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px;"><strong style="font-size:12px;color:#1e7e34;">' . esc_html__( 'Added', 'ois-conversion-suite' ) . '</strong>';
            echo '<button type="button" class="button-link oiscl-rescan-accept-all" data-post-id="' . esc_attr( $post_id ) . '">' . esc_html__( 'Track all new', 'ois-conversion-suite' ) . '</button></div>';
            echo '<div style="max-height:180px;overflow-y:auto;display:flex;flex-direction:column;gap:6px;">';
            foreach ( $added as $inst ) {
                $id    = esc_attr( $inst['id'] );
                $label = esc_html( OISCL_Tracking::instance_review_label( $inst ) );
                $dest  = isset( $inst['dest'] ) ? esc_html( $inst['dest'] ) : '';
                echo '<label class="oiscl-rescan-added-row" style="display:flex;align-items:flex-start;gap:8px;font-size:12px;background:#edfaef;padding:8px;border-radius:4px;"><input type="checkbox" class="oiscl-rescan-track-added" data-id="' . $id . '" checked> <span><strong>' . $label . '</strong>';
                if ( $dest ) {
                    echo '<br><small style="color:#666;">' . $dest . '</small>';
                }
                echo '</span></label>';
            }
            echo '</div></div>';
        }

        if ( ! empty( $removed ) ) {
            echo '<div style="margin-bottom:14px;"><strong style="font-size:12px;color:#b45309;display:block;margin-bottom:8px;">' . esc_html__( 'Removed', 'ois-conversion-suite' ) . '</strong>';
            echo '<div style="max-height:140px;overflow-y:auto;display:flex;flex-direction:column;gap:6px;">';
            foreach ( $removed as $inst ) {
                $label = esc_html( OISCL_Tracking::instance_review_label( $inst ) );
                echo '<div style="font-size:12px;background:#fff3e0;padding:8px;border-radius:4px;color:#6b4e16;text-decoration:line-through;">' . $label . '</div>';
            }
            echo '</div></div>';
        }

        echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end;margin-top:6px;">';
        echo '<button type="button" class="button oiscl-rescan-discard" data-post-id="' . esc_attr( $post_id ) . '">' . esc_html__( 'Discard scan', 'ois-conversion-suite' ) . '</button>';
        echo '<button type="button" class="button button-primary oiscl-rescan-apply" data-post-id="' . esc_attr( $post_id ) . '">' . esc_html( sprintf( __( 'Save as version %d', 'ois-conversion-suite' ), $next_rev ) ) . '</button>';
        echo '</div></div>';
    }

    private function render_accordion_content_html($audit, $post_id) {
        $tree        = isset( $audit['tree'] ) ? $audit['tree'] : array();
        $config      = OISCL_Tracking::get_page_config( $post_id );
        $instances   = ( $config && ! empty( $config['instances'] ) ) ? $config['instances'] : array();
        $scanned     = ( $config && ! empty( $config['scanned_at'] ) ) ? $config['scanned_at'] : '';
        $track_mode  = OISCL_Tracking::get_page_tracking_mode( $post_id );
        $is_auto     = ( 'automatic' === $track_mode );
        $auto_tags   = OISCL_Tracking::get_page_auto_tags( $post_id );
        $has_data    = ( is_array( $audit ) && isset( $audit['dom'] ) );

        $auto_style  = $is_auto ? '' : 'display:none;';
        $custom_style = $is_auto ? 'display:none;' : '';
        $config_revision = OISCL_Tracking::get_config_revision( $config );

        echo '<div class="oiscl-auto-tracking-panel" data-post-id="' . esc_attr( $post_id ) . '" style="margin-bottom:20px;padding:16px;background:#fff;border:1px solid #dcdcde;border-radius:6px;' . esc_attr( $auto_style ) . '">';
        echo '<h4 style="margin:0 0 8px;color:#1a1a1a;font-size:13px;">' . esc_html__( 'Automatic tracking — DOM tags', 'ois-conversion-suite' ) . '</h4>';
        $auto_source = OISCL_Tracking::page_has_auto_tags_override( $post_id )
            ? esc_html__( 'Using page-specific tags (override).', 'ois-conversion-suite' )
            : esc_html__( 'Using Automatic global rules.', 'ois-conversion-suite' );
        echo '<p style="font-size:11px;color:#2271b1;margin:0 0 8px;">' . $auto_source . '</p>';
        echo '<p class="description" style="margin:0 0 10px;font-size:12px;">' . esc_html__( 'Optional page-specific DOM tags. Leave empty to use Automatic global rules. Switch to Custom and scan for per-link tracking.', 'ois-conversion-suite' ) . '</p>';
        echo '<input type="text" class="oiscl-auto-tags-input regular-text" data-post-id="' . esc_attr( $post_id ) . '" value="' . esc_attr( $auto_tags ) . '" placeholder="h2, h3, section, article" style="width:100%;max-width:480px;">';
        echo '<p style="margin:10px 0 0;"><button type="button" class="button button-primary oiscl-save-auto-tags" data-post-id="' . esc_attr( $post_id ) . '">' . esc_html__( 'Save automatic tags', 'ois-conversion-suite' ) . '</button></p>';
        echo '</div>';

        echo '<div class="oiscl-custom-tracking-panel" style="' . esc_attr( $custom_style ) . '">';

        if ( $config && ! empty( $config['pending_rescan'] ) && is_array( $config['pending_rescan'] ) ) {
            $this->oiscl_render_rescan_review_html( $post_id, $config['pending_rescan'] );
        }

        if ( $scanned ) {
            echo '<p style="font-size:12px;color:#666;margin:0 0 6px;">' . esc_html( sprintf( __( 'Last scan: %s', 'ois-conversion-suite' ), $scanned ) ) . ' · <span style="font-weight:600;color:#2271b1;">' . esc_html( sprintf( __( 'Config v%d', 'ois-conversion-suite' ), $config_revision ) ) . '</span></p>';
        } elseif ( $config_revision > 1 ) {
            echo '<p style="font-size:12px;color:#666;margin:0 0 6px;"><span style="font-weight:600;color:#2271b1;">' . esc_html( sprintf( __( 'Config v%d', 'ois-conversion-suite' ), $config_revision ) ) . '</span></p>';
        }
        echo '<p style="font-size:12px;color:#50575e;margin:0 0 16px;">' . esc_html__( 'If the page layout changed, use Rescan to remap tracking instances. Structural changes open a review step before a new config version is saved.', 'ois-conversion-suite' ) . '</p>';

        if ( ! $has_data ) {
            echo '<p style="color:#999;text-align:center;margin-bottom:20px;">' . esc_html__( 'No scan data yet. Click Analyze (scan) to map this page.', 'ois-conversion-suite' ) . '</p>';
        } elseif ( empty( $instances ) ) {
            echo '<p style="color:#999;text-align:center;margin-bottom:20px;">' . esc_html__( 'No scan rules yet. Run Analyze (Scan) to auto-detect blocks and links.', 'ois-conversion-suite' ) . '</p>';
        } else {
            $view_rows  = array();
            $click_rows = array();
            $reading_classic_tags = array( 'H1', 'H2', 'H3', 'SECTION', 'ARTICLE' );
            $click_tags           = array( 'A', 'BUTTON', 'INPUT' );
            foreach ( $instances as $inst ) {
                $tag = isset( $inst['tag'] ) ? strtoupper( $inst['tag'] ) : '';
                if ( in_array( $tag, $click_tags, true ) ) {
                    $inst['noise_tier'] = OISCL_Tracking::resolve_instance_noise_tier( $inst );
                    $click_rows[]       = $inst;
                } elseif ( '' !== $tag ) {
                    $inst['noise_tier'] = OISCL_Tracking::resolve_instance_noise_tier( $inst );
                    $view_rows[]        = $inst;
                }
            }
            $reading_tags_present = array();
            $reading_has_others   = false;
            foreach ( $view_rows as $vr ) {
                $vt = isset( $vr['tag'] ) ? strtoupper( (string) $vr['tag'] ) : '';
                if ( in_array( $vt, $reading_classic_tags, true ) ) {
                    $reading_tags_present[ $vt ] = true;
                } else {
                    $reading_has_others = true;
                }
            }
            echo '<div style="display:flex;gap:20px;flex-wrap:wrap;margin-bottom:20px;">';
            echo '<div style="flex:1;min-width:250px;background:#fff;padding:20px;border-radius:6px;border:1px solid #ccd0d4;"><h3 class="ois-block-title" style="color:#722ed1;">' . esc_html__( 'Reading Map', 'ois-conversion-suite' ) . '</h3>';
            echo '<p class="description" style="font-size:12px;margin:0 0 10px;">' . esc_html__( 'Checked blocks record scroll-into-view and dwell time in the Reading Map report.', 'ois-conversion-suite' ) . '</p>';
            echo '<div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;flex-wrap:wrap;"><label style="font-size:12px;font-weight:600;">' . esc_html__( 'Filter by tag', 'ois-conversion-suite' ) . '</label>';
            echo '<select class="oiscl-reading-filter" data-post-id="' . esc_attr( $post_id ) . '" style="min-width:140px;">';
            echo '<option value="all">' . esc_html__( 'All tags', 'ois-conversion-suite' ) . '</option>';
            foreach ( $reading_classic_tags as $classic_tag ) {
                if ( ! empty( $reading_tags_present[ $classic_tag ] ) ) {
                    echo '<option value="' . esc_attr( strtolower( $classic_tag ) ) . '">' . esc_html( $classic_tag ) . '</option>';
                }
            }
            if ( $reading_has_others ) {
                echo '<option value="others">' . esc_html__( 'Others', 'ois-conversion-suite' ) . '</option>';
            }
            echo '</select><span class="oiscl-reading-filter-count" style="font-size:11px;color:#666;"></span></div>';
            echo '<label class="oiscl-bulk-toggle-row" style="display:flex;align-items:center;gap:6px;font-size:11px;font-weight:600;margin-bottom:8px;cursor:pointer;"><input type="checkbox" class="oiscl-select-all-view" data-post-id="' . esc_attr( $post_id ) . '"> ' . esc_html__( 'Select all / Deselect all', 'ois-conversion-suite' ) . '</label>';
            echo '<div class="oiscl-reading-list" style="display:flex;flex-direction:column;gap:8px;max-height:240px;overflow-y:auto;">';
            if ( empty( $view_rows ) ) {
                echo '<p style="color:#999;font-size:12px;">' . esc_html__( 'No heading or section blocks detected.', 'ois-conversion-suite' ) . '</p>';
            }
            foreach ( $view_rows as $inst ) {
                $id         = esc_attr( $inst['id'] );
                $block_tag  = strtolower( isset( $inst['tag'] ) ? (string) $inst['tag'] : '' );
                $tag_upper  = strtoupper( $block_tag );
                $is_classic = in_array( $tag_upper, $reading_classic_tags, true );
                $tag_filter = $is_classic ? $block_tag : 'others';
                $alias      = esc_html( isset( $inst['alias'] ) ? $inst['alias'] : $inst['text'] );
                $checked    = ! empty( $inst['track_view'] ) ? 'checked' : '';
                $tag_badge  = '<span class="ois-block-tag-badge" style="font-size:9px;text-transform:uppercase;color:#722ed1;background:#f3e8ff;padding:1px 6px;border-radius:8px;margin-left:6px;">' . esc_html( $tag_upper ) . '</span>';
                echo '<label class="ois-inst-row ois-inst-view" data-id="' . $id . '" data-block-tag="' . esc_attr( $block_tag ) . '" data-tag-filter="' . esc_attr( $tag_filter ) . '" style="background:#f9f9f9;padding:8px;border:1px solid #eee;border-radius:4px;"><input type="checkbox" class="ois-track-view" ' . $checked . '> <b style="font-size:12px;">' . $alias . '</b>' . $tag_badge . '</label>';
            }
            echo '</div></div>';
            echo '<div style="flex:1;min-width:300px;background:#fff;padding:20px;border-radius:6px;border:1px solid #ccd0d4;"><h3 class="ois-block-title" style="color:#f56e28;">' . esc_html__( 'Interactive elements', 'ois-conversion-suite' ) . '</h3>';
            echo '<p class="description" style="font-size:12px;margin:0 0 10px;">' . esc_html__( 'All links and buttons are kept on scan. Use the noise filter to focus the list — choose All to review languages, a11y widgets, and other extras.', 'ois-conversion-suite' ) . '</p>';
            echo '<div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;flex-wrap:wrap;"><label style="font-size:12px;font-weight:600;">' . esc_html__( 'Noise filter', 'ois-conversion-suite' ) . '</label>';
            echo '<select class="oiscl-interactive-filter" data-post-id="' . esc_attr( $post_id ) . '" style="min-width:140px;">';
            echo '<option value="all">' . esc_html__( 'All', 'ois-conversion-suite' ) . '</option>';
            echo '<option value="low">' . esc_html__( 'Low', 'ois-conversion-suite' ) . '</option>';
            echo '<option value="medium" selected>' . esc_html__( 'Medium', 'ois-conversion-suite' ) . '</option>';
            echo '<option value="high">' . esc_html__( 'High', 'ois-conversion-suite' ) . '</option>';
            echo '</select><span class="oiscl-interactive-filter-count" style="font-size:11px;color:#666;"></span></div>';
            echo '<label class="oiscl-bulk-toggle-row" style="display:flex;align-items:center;gap:6px;font-size:11px;font-weight:600;margin-bottom:8px;cursor:pointer;"><input type="checkbox" class="oiscl-select-all-click" data-post-id="' . esc_attr( $post_id ) . '"> ' . esc_html__( 'Select all / Deselect all', 'ois-conversion-suite' ) . '</label>';
            echo '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:11px;margin-bottom:8px;"><span style="font-weight:600;">' . esc_html__( 'Bulk:', 'ois-conversion-suite' ) . '</span>';
            echo '<button type="button" class="button-link oiscl-bulk-click-all" data-post-id="' . esc_attr( $post_id ) . '">' . esc_html__( 'Select all', 'ois-conversion-suite' ) . '</button>';
            echo '<button type="button" class="button-link oiscl-bulk-click-none" data-post-id="' . esc_attr( $post_id ) . '">' . esc_html__( 'Deselect all', 'ois-conversion-suite' ) . '</button>';
            echo '<button type="button" class="button-link oiscl-bulk-click-visible" data-post-id="' . esc_attr( $post_id ) . '">' . esc_html__( 'Select visible', 'ois-conversion-suite' ) . '</button></div>';
            echo '<div class="oiscl-interactive-list" style="max-height:240px;overflow-y:auto;display:flex;flex-direction:column;gap:8px;">';
            if ( empty( $click_rows ) ) {
                echo '<p class="oiscl-interactive-empty" style="color:#999;font-size:12px;">' . esc_html__( 'No links or buttons found. Run Analyze (scan) or Rescan (🔄).', 'ois-conversion-suite' ) . '</p>';
            }
            foreach ( $click_rows as $inst ) {
                $id            = esc_attr( $inst['id'] );
                $tier          = esc_attr( $inst['noise_tier'] );
                $scan_alias    = esc_html( isset( $inst['alias'] ) ? $inst['alias'] : $inst['text'] );
                $custom_label  = isset( $inst['custom_label'] ) ? esc_attr( $inst['custom_label'] ) : '';
                $custom_display = $custom_label ? esc_html( $custom_label ) : '';
                $dest          = isset( $inst['dest'] ) ? esc_html( $inst['dest'] ) : '';
                $checked       = ! empty( $inst['track_click'] ) ? 'checked' : '';
                $tier_badge    = '<span class="ois-noise-tier-badge" style="font-size:9px;text-transform:uppercase;color:#888;margin-left:6px;">' . esc_html( $tier ) . '</span>';
                $source_key    = isset( $inst['source'] ) ? $inst['source'] : 'dom';
                $source_label  = OISCL_Tracking::instance_source_badge_label( $source_key );
                $source_badge  = $source_label ? '<span class="ois-source-badge" style="font-size:9px;background:#e8f4fd;color:#2271b1;padding:1px 6px;border-radius:8px;margin-left:6px;" title="' . esc_attr__( 'Detected via form plugin API (JS-rendered)', 'ois-conversion-suite' ) . '">' . esc_html( $source_label ) . '</span>' : '';
                $edit_btn      = '<button type="button" class="ois-edit-label-btn" data-id="' . $id . '" data-post-id="' . esc_attr( $post_id ) . '" title="' . esc_attr__( 'Rename element', 'ois-conversion-suite' ) . '" style="border:none;background:transparent;cursor:pointer;padding:0 4px;line-height:1;vertical-align:middle;"><span class="dashicons dashicons-edit" style="font-size:14px;width:14px;height:14px;color:#646970;"></span></button>';
                $label_style   = $custom_display ? '' : 'display:none;';
                echo '<label class="ois-inst-row ois-inst-click" data-id="' . $id . '" data-noise-tier="' . $tier . '" data-custom-label="' . $custom_label . '" style="display:flex;gap:10px;background:#fafafa;padding:8px;border:1px solid #eaeaea;border-radius:4px;"><input type="checkbox" class="ois-track-click" ' . $checked . '><div style="flex:1;min-width:0;"><div style="display:flex;align-items:center;flex-wrap:wrap;gap:2px;"><b class="ois-inst-scan-label" style="font-size:12px;">' . $scan_alias . '</b>' . $tier_badge . $source_badge . $edit_btn . '</div><small class="ois-custom-label-display" style="display:block;font-size:10px;color:#2271b1;margin-top:2px;' . $label_style . '">' . $custom_display . '</small>';
                if ( $dest ) {
                    echo '<code style="display:block;font-size:10px;color:#50575e;margin-top:2px;">' . $dest . '</code>';
                }
                echo '</div></label>';
            }
            echo '</div></div></div>';
        }

        if ( $has_data ) {
            echo '<div class="oiscl-dom-map-panel" style="' . esc_attr( $custom_style ) . 'background:#282c34;padding:20px;border-radius:6px;margin-top:10px;"><h3 class="ois-block-title" style="color:#61afef;">' . esc_html__( 'DOM map', 'ois-conversion-suite' ) . '</h3>';
            echo '<p style="font-size:12px;color:#abb2bf;margin:0 0 10px;">' . esc_html__( 'Top-to-bottom snapshot of detected structure. Labels match what visitors see on the live page.', 'ois-conversion-suite' ) . '</p>';
            echo '<div style="display:flex;flex-wrap:wrap;gap:8px;padding:15px;background:#1e2227;border-radius:4px;max-height:200px;overflow-y:auto;">';
            foreach ( $tree as $node ) {
                $bg = strpos( $node, 'H1' ) === 0 ? '#e06c75' : ( strpos( $node, 'H2' ) === 0 ? '#d19a66' : '#5c6370' );
                echo '<span style="background:' . esc_attr( $bg ) . ';color:#fff;padding:4px 10px;border-radius:12px;font-size:11px;">' . esc_html( $node ) . '</span>';
            }
            echo '</div></div>';
        }
        echo '<div class="oiscl-custom-save-row" style="text-align:right;margin-top:15px;' . esc_attr( $custom_style ) . '"><button class="button button-primary oiscl-save-dom-rules" data-post-id="' . esc_attr( $post_id ) . '">' . esc_html__( 'Save tracking rules', 'ois-conversion-suite' ) . '</button></div>';
        echo '</div>';
    }

    /**
     * Try create dir + write/delete tiny file (backup / log folders).
     *
     * @param string $dir Absolute path.
     * @return array{0:bool,1:string} ok, error message (localized).
     */
    private function oiscl_health_try_write_dir( $dir ) {
        $dir = wp_normalize_path( $dir );
        if ( ! wp_mkdir_p( $dir ) ) {
            return array( false, __( 'Cannot create directory.', 'ois-conversion-suite' ) );
        }
        $file = trailingslashit( $dir ) . '.oiscl-wtest-' . wp_generate_password( 8, false, false );
        $ok   = @file_put_contents( $file, '1' );
        if ( false === $ok ) {
            return array( false, __( 'Cannot write test file — check permissions.', 'ois-conversion-suite' ) );
        }
        @unlink( $file );
        return array( true, '' );
    }

    /**
     * Collect hosting + plugin compatibility checks (no CLI).
     *
     * @return array{pass:int,warn:int,fail:int,sections:array<int,array{title:string,items:array<int,array{status:string,label:string,detail:string}>}>}
     */
    private function oiscl_build_hosting_health_report() {
        global $wpdb;

        $pass = 0;
        $warn = 0;
        $fail = 0;

        $sections = array();

        $add = static function ( array &$sections, $title ) {
            $sections[] = array(
                'title' => $title,
                'items' => array(),
            );
            return count( $sections ) - 1;
        };

        $push = function ( &$sections, $idx, $status, $label, $detail = '' ) use ( &$pass, &$warn, &$fail ) {
            if ( 'pass' === $status ) {
                ++$pass;
            } elseif ( 'warn' === $status ) {
                ++$warn;
            } else {
                ++$fail;
            }
            $sections[ $idx ]['items'][] = array(
                'status' => $status,
                'label'  => $label,
                'detail' => $detail,
            );
        };

        // --- WordPress ---
        $idx = $add( $sections, __( 'WordPress', 'ois-conversion-suite' ) );
        $parsed = wp_parse_url( home_url() );
        $host   = isset( $parsed['host'] ) ? $parsed['host'] : '';
        $push( $sections, $idx, 'pass', __( 'Site host', 'ois-conversion-suite' ), $host ? esc_html( $host ) : esc_url( home_url( '/' ) ) );
        $push( $sections, $idx, 'pass', __( 'WordPress version', 'ois-conversion-suite' ), esc_html( get_bloginfo( 'version' ) ) );
        $tz = function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : '';
        $push( $sections, $idx, 'pass', __( 'Timezone', 'ois-conversion-suite' ), $tz ? esc_html( $tz ) : __( '(default)', 'ois-conversion-suite' ) );
        if ( is_multisite() ) {
            $push( $sections, $idx, 'warn', __( 'Multisite', 'ois-conversion-suite' ), __( 'Network install — confirm metrics per subsite.', 'ois-conversion-suite' ) );
        }
        if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
            $push( $sections, $idx, 'warn', 'DISABLE_WP_CRON', __( 'True — ensure a real cron hits wp-cron.php.', 'ois-conversion-suite' ) );
        }

        // --- PHP ---
        $idx  = $add( $sections, __( 'PHP runtime', 'ois-conversion-suite' ) );
        $phpv = PHP_VERSION;
        if ( version_compare( $phpv, '8.0', '>=' ) ) {
            $push( $sections, $idx, 'pass', __( 'PHP version', 'ois-conversion-suite' ), esc_html( $phpv ) );
        } elseif ( version_compare( $phpv, '7.4', '>=' ) ) {
            $push( $sections, $idx, 'warn', __( 'PHP version', 'ois-conversion-suite' ), esc_html( $phpv ) . ' — ' . __( 'PHP 8.0+ recommended.', 'ois-conversion-suite' ) );
        } else {
            $push( $sections, $idx, 'fail', __( 'PHP version', 'ois-conversion-suite' ), esc_html( $phpv ) . ' — ' . __( 'Upgrade PHP for modern WordPress.', 'ois-conversion-suite' ) );
        }

        foreach ( array( 'json' => 'JSON', 'mysqli' => 'MySQL (wpdb)' ) as $ext => $lbl ) {
            $ok = extension_loaded( $ext );
            $push( $sections, $idx, $ok ? 'pass' : 'fail', sprintf( /* translators: %s: php extension */ __( 'Extension: %s', 'ois-conversion-suite' ), $ext ), $lbl );
        }
        foreach ( array( 'mbstring', 'openssl', 'curl' ) as $ext ) {
            $ok = extension_loaded( $ext );
            $push( $sections, $idx, $ok ? 'pass' : 'warn', sprintf( /* translators: %s: php extension */ __( 'Extension: %s', 'ois-conversion-suite' ), $ext ), __( 'Recommended on hosting.', 'ois-conversion-suite' ) );
        }
        $zip_ok = class_exists( 'ZipArchive' );
        $push( $sections, $idx, $zip_ok ? 'pass' : 'warn', 'ZipArchive', $zip_ok ? __( 'OK for .oiscl backups.', 'ois-conversion-suite' ) : __( 'Enable php-zip for backup ZIP.', 'ois-conversion-suite' ) );

        $push( $sections, $idx, 'pass', 'memory_limit', esc_html( (string) ini_get( 'memory_limit' ) ) );
        $push( $sections, $idx, 'pass', 'max_execution_time', esc_html( (string) ini_get( 'max_execution_time' ) ) );
        $push( $sections, $idx, 'pass', 'upload_max_filesize', esc_html( (string) ini_get( 'upload_max_filesize' ) ) );
        $push( $sections, $idx, 'pass', 'post_max_size', esc_html( (string) ini_get( 'post_max_size' ) ) );

        // --- Database ---
        $idx = $add( $sections, __( 'Database', 'ois-conversion-suite' ) );
        $db_ver = method_exists( $wpdb, 'db_version' ) ? $wpdb->db_version() : '';
        $push( $sections, $idx, ( is_string( $db_ver ) && $db_ver !== '' ) ? 'pass' : 'fail', __( 'MySQL / MariaDB', 'ois-conversion-suite' ), $db_ver ? esc_html( $db_ver ) : __( 'Unknown', 'ois-conversion-suite' ) );
        $charset = isset( $wpdb->charset ) ? (string) $wpdb->charset : '';
        $push( $sections, $idx, $charset ? 'pass' : 'warn', __( 'wpdb charset', 'ois-conversion-suite' ), $charset ? esc_html( $charset ) : '—' );
        $one = $wpdb->get_var( 'SELECT 1' );
        $push( $sections, $idx, ( '1' === (string) $one ) ? 'pass' : 'fail', __( 'Query test', 'ois-conversion-suite' ), 'SELECT 1' );

        $opt_key = '_oiscl_hchk_' . wp_generate_password( 16, false, false );
        $wrote   = add_option( $opt_key, '1', '', 'no' );
        if ( $wrote ) {
            delete_option( $opt_key );
        }
        $push( $sections, $idx, $wrote ? 'pass' : 'fail', __( 'Write test (options)', 'ois-conversion-suite' ), $wrote ? __( 'Temporary row OK.', 'ois-conversion-suite' ) : __( 'Cannot INSERT into options — check DB user grants.', 'ois-conversion-suite' ) );

        // --- Filesystem ---
        $idx = $add( $sections, __( 'Filesystem', 'ois-conversion-suite' ) );
        $wcw = wp_is_writable( WP_CONTENT_DIR );
        $push( $sections, $idx, $wcw ? 'pass' : 'warn', 'wp-content', esc_html( wp_normalize_path( WP_CONTENT_DIR ) ) );

        $ud = wp_upload_dir();
        if ( ! empty( $ud['error'] ) ) {
            $push( $sections, $idx, 'fail', __( 'Uploads directory', 'ois-conversion-suite' ), esc_html( $ud['error'] ) );
        } else {
            $ub = isset( $ud['basedir'] ) ? $ud['basedir'] : '';
            $uw = $ub && wp_is_writable( $ub );
            $push( $sections, $idx, $uw ? 'pass' : 'warn', __( 'Uploads directory', 'ois-conversion-suite' ), esc_html( wp_normalize_path( $ub ) ) );
        }

        foreach ( array( 'ois-backups', 'ois-logs' ) as $sub ) {
            $full = WP_CONTENT_DIR . '/' . $sub;
            list( $okw, $msg ) = $this->oiscl_health_try_write_dir( $full );
            $push( $sections, $idx, $okw ? 'pass' : 'warn', sprintf( /* translators: %s folder */ __( 'Folder: %s', 'ois-conversion-suite' ), $sub ), $okw ? esc_html( wp_normalize_path( $full ) ) : esc_html( $msg ) );
        }

        // --- HTTP loopback ---
        $idx      = $add( $sections, __( 'HTTP (loopback)', 'ois-conversion-suite' ) );
        $home_get = wp_remote_get(
            home_url( '/' ),
            array(
                'timeout'     => 4,
                'redirection' => 3,
                'sslverify'   => apply_filters( 'oiscl_health_sslverify_loopback', true ),
            )
        );
        if ( is_wp_error( $home_get ) ) {
            $push( $sections, $idx, 'warn', __( 'GET front page', 'ois-conversion-suite' ), esc_html( $home_get->get_error_message() ) );
        } else {
            $code = (int) wp_remote_retrieve_response_code( $home_get );
            if ( $code >= 200 && $code < 500 ) {
                $push( $sections, $idx, 'pass', __( 'GET front page', 'ois-conversion-suite' ), sprintf( /* translators: %d HTTP */ __( 'HTTP %d', 'ois-conversion-suite' ), $code ) );
            } else {
                $push( $sections, $idx, 'warn', __( 'GET front page', 'ois-conversion-suite' ), sprintf( /* translators: %d HTTP */ __( 'HTTP %d — some hosts block self-requests.', 'ois-conversion-suite' ), $code ) );
            }
        }

        // --- Disk ---
        if ( function_exists( 'disk_free_space' ) ) {
            $free = @disk_free_space( WP_CONTENT_DIR );
            if ( false !== $free ) {
                $mb  = round( $free / 1048576, 1 );
                $idx = $add( $sections, __( 'Disk space', 'ois-conversion-suite' ) );
                $st  = ( $mb >= 100 ) ? 'pass' : ( ( $mb >= 50 ) ? 'warn' : 'fail' );
                $push( $sections, $idx, $st, __( 'Free near wp-content', 'ois-conversion-suite' ), sprintf( /* translators: %s MB */ __( '~%s MB free', 'ois-conversion-suite' ), esc_html( (string) $mb ) ) );
            }
        }

        // --- OIS plugin ---
        $idx        = $add( $sections, __( 'OIS Conversion Suite', 'ois-conversion-suite' ) );
        $plugin_ver = defined( 'OISCL_VERSION' ) ? OISCL_VERSION : '—';
        $push( $sections, $idx, 'pass', 'OISCL_VERSION', esc_html( (string) $plugin_ver ) );

        $tables = array(
            $wpdb->prefix . 'oiscl_block_metrics'  => __( 'Metrics table', 'ois-conversion-suite' ),
            $wpdb->prefix . 'oiscl_page_settings'  => __( 'Page settings table', 'ois-conversion-suite' ),
            $wpdb->prefix . 'oiscl_utm_references' => __( 'UTM references table', 'ois-conversion-suite' ),
        );
        foreach ( $tables as $tbl => $label ) {
            $exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) === $tbl );
            $push( $sections, $idx, $exists ? 'pass' : 'fail', $label, $exists ? esc_html( $tbl ) : __( 'Missing — re-activate plugin.', 'ois-conversion-suite' ) );
        }

        $metrics_table = $wpdb->prefix . 'oiscl_block_metrics';
        $refs_table    = $wpdb->prefix . 'oiscl_utm_references';
        $metrics_ok    = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $metrics_table ) ) === $metrics_table );
        if ( $metrics_ok ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $total_rows = (int) ( $wpdb->get_var( "SELECT COUNT(*) FROM `{$metrics_table}`" ) ?: 0 );
            $today_g    = current_time( 'Y-m-d' );
            $since_g    = wp_date( 'Y-m-d', strtotime( '-7 days', current_time( 'timestamp' ) ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $utm_7d = (int) ( $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM `{$metrics_table}` WHERE utm_campaign != '' AND DATE(created_at) >= %s AND DATE(created_at) <= %s",
                    $since_g,
                    $today_g
                )
            ) ?: 0 );
            $push( $sections, $idx, 'pass', __( 'Metric rows (total)', 'ois-conversion-suite' ), esc_html( number_format_i18n( $total_rows ) ) );
            $push( $sections, $idx, 'pass', __( 'UTM rows (7 days)', 'ois-conversion-suite' ), esc_html( number_format_i18n( $utm_7d ) ) );
        }

        $refs_ok = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $refs_table ) ) === $refs_table );
        if ( $refs_ok ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $refs_count = (int) ( $wpdb->get_var( "SELECT COUNT(*) FROM `{$refs_table}`" ) ?: 0 );
            $push( $sections, $idx, 'pass', __( 'Saved UTM links', 'ois-conversion-suite' ), esc_html( number_format_i18n( $refs_count ) ) );
        }

        $sql_helper_ok = class_exists( 'OISCL_Utm_Query_Helper' )
            && OISCL_Utm_Query_Helper::inject_before_group_order_limit(
                'SELECT * FROM t WHERE x = 1 ORDER BY y DESC',
                ' AND z = 2'
            ) === 'SELECT * FROM t WHERE x = 1 AND z = 2 ORDER BY y DESC';
        $push( $sections, $idx, $sql_helper_ok ? 'pass' : 'fail', __( 'UTM SQL helper self-test', 'ois-conversion-suite' ), $sql_helper_ok ? 'OK' : __( 'Incomplete plugin files?', 'ois-conversion-suite' ) );

        if ( defined( 'OISCL_UTM_DIAG' ) && OISCL_UTM_DIAG ) {
            $push( $sections, $idx, 'warn', 'OISCL_UTM_DIAG', __( 'Enabled — turn off after debugging.', 'ois-conversion-suite' ) );
        }

        return compact( 'pass', 'warn', 'fail', 'sections' );
    }

    /**
     * Hosting + plugin health panel (FTP-friendly). Rendered under Settings → Maintenance.
     */
    private function oiscl_render_maintenance_site_health_panel() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $report = $this->oiscl_build_hosting_health_report();
        $pass   = (int) $report['pass'];
        $warn   = (int) $report['warn'];
        $fail   = (int) $report['fail'];

        $border = '#46b450';
        $bg     = '#f6fffa';
        if ( $fail > 0 ) {
            $border = '#d63638';
            $bg     = '#fff5f5';
        } elseif ( $warn > 0 ) {
            $border = '#dba617';
            $bg     = '#fffbeb';
        }

        $today = current_time( 'Y-m-d' );
        $nonce = wp_create_nonce( 'oiscl_admin_nonce' );

        $utm_home   = admin_url( 'admin.php?page=oiscl-utm-tracker' );
        $utm_funnel = admin_url(
            'admin.php?' . http_build_query(
                array(
                    'page'       => 'oiscl-utm-tracker',
                    'tab'        => 'funnel',
                    'start_date' => $today,
                    'end_date'   => $today,
                )
            )
        );
        $funnel_csv = admin_url(
            'admin.php?' . http_build_query(
                array(
                    'page'         => 'oiscl-utm-tracker',
                    'tab'          => 'funnel',
                    'export_csv'   => 'utm_funnel',
                    'funnel_scope' => 'both',
                    'start_date'   => $today,
                    'end_date'     => $today,
                )
            )
        );

        echo '<div class="oiscl-hosting-health" style="background:' . esc_attr( $bg ) . ';border:1px solid ' . esc_attr( $border ) . ';padding:20px;margin-bottom:22px;max-width:960px;border-radius:4px;">';
        echo '<h3 class="ois-block-title" style="margin-top:0;">🏥 ' . esc_html__( 'Hosting & plugin health check', 'ois-conversion-suite' ) . '</h3>';
        echo '<p style="margin:0 0 12px;font-size:13px;line-height:1.55;color:#1d2327;">';
        echo esc_html__( 'Runs automatically when you open this tab. Use it after uploading via FTP — no Composer, SSH, or cPanel skills required.', 'ois-conversion-suite' );
        echo '</p>';
        echo '<p style="margin:0 0 18px;font-size:13px;font-weight:600;color:#1d2327;">';
        printf(
            /* translators: 1: passed count, 2: warnings, 3: failures */
            esc_html__( 'Summary: %1$d OK · %2$d warnings · %3$d failures', 'ois-conversion-suite' ),
            $pass,
            $warn,
            $fail
        );
        echo '</p>';

        foreach ( $report['sections'] as $section ) {
            echo '<h4 style="margin:18px 0 8px;font-size:13px;text-transform:uppercase;letter-spacing:0.04em;color:#64748b;">' . esc_html( $section['title'] ) . '</h4>';
            echo '<ul style="margin:0 0 0 1.15em;font-size:13px;line-height:1.65;color:#1d2327;">';
            foreach ( $section['items'] as $item ) {
                $icon = '❌';
                if ( 'pass' === $item['status'] ) {
                    $icon = '✅';
                } elseif ( 'warn' === $item['status'] ) {
                    $icon = '⚠️';
                }
                echo '<li style="margin-bottom:4px;"><span style="margin-right:6px;">' . esc_html( $icon ) . '</span><strong>' . esc_html( $item['label'] ) . '</strong>';
                if ( $item['detail'] !== '' ) {
                    echo ': ' . $item['detail'];
                }
                echo '</li>';
            }
            echo '</ul>';
        }

        echo '<hr style="margin:20px 0;border:none;border-top:1px solid #e2e8f0;">';
        echo '<p style="margin:0 0 10px;font-weight:600;font-size:13px;">' . esc_html__( 'Browser checks', 'ois-conversion-suite' ) . '</p>';
        echo '<div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:12px;">';
        echo '<button type="button" class="button button-small" id="oiscl-btn-health-ajax">' . esc_html__( 'Ping admin-ajax', 'ois-conversion-suite' ) . '</button>';
        echo '<span id="oiscl-health-ajax-result" style="font-size:12px;color:#64748b;">' . esc_html__( 'Not run yet.', 'ois-conversion-suite' ) . '</span>';
        echo '</div>';
        echo '<div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:8px;">';
        echo '<a class="button button-small button-primary" href="' . esc_url( $utm_home ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'UTM Manager', 'ois-conversion-suite' ) . '</a>';
        echo '<a class="button button-small" href="' . esc_url( $utm_funnel ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'UTM Funnel (today)', 'ois-conversion-suite' ) . '</a>';
        echo '<a class="button button-small" href="' . esc_url( $funnel_csv ) . '">' . esc_html__( 'Download funnel CSV (today)', 'ois-conversion-suite' ) . '</a>';
        echo '</div>';
        echo '<p style="margin:0;font-size:11px;color:#64748b;">';
        echo esc_html__( 'Yellow items are common on shared hosting and may still be fine. Red failures mean you should fix PHP/DB/files before trusting exports or tracking. CSV needs capability view_ois_analytics.', 'ois-conversion-suite' );
        echo '</p>';
        echo '<script>jQuery(function($){$("#oiscl-btn-health-ajax").on("click",function(){var $b=$(this),$o=$("#oiscl-health-ajax-result");$b.prop("disabled",true);$o.text("' . esc_js( __( 'Waiting…', 'ois-conversion-suite' ) ) . '");$.post(ajaxurl,{action:"oiscl_host_health_ping",nonce:' . wp_json_encode( $nonce ) . '},function(r){$b.prop("disabled",false);if(r.success){var db=r.data&&r.data.db?" DB "+r.data.db:"";$o.css("color","#166534").text((r.data.message||"OK")+db);}else{$o.css("color","#b91c1c").text((r.data&&r.data.message)||"' . esc_js( __( 'Failed', 'ois-conversion-suite' ) ) . '");}}).fail(function(){$b.prop("disabled",false);$o.css("color","#b91c1c").text("' . esc_js( __( 'Network error', 'ois-conversion-suite' ) ) . '");});});});</script>';
        echo '</div>';
    }

    /**
     * Settings tab: Backup / Restore (.oiscl).
     */
    private function render_backup_restore_settings() {
        $nonce      = wp_create_nonce( 'oiscl_admin_nonce' );
        $today      = current_time( 'Y-m-d' );
        $month_ago  = wp_date( 'Y-m-d', strtotime( '-30 days', current_time( 'timestamp' ) ) );
        $export_tip = __( 'Manifest.json carries dashboard/report settings; metrics.jsonl carries hits. Import clears metric rows on this site before loading the file (destructive). A date-range export contains only rows in that window — importing it removes metrics outside the range as well.', 'ois-conversion-suite' );
        ?>
        <div style="background:#fff;border:1px solid #ccd0d4;padding:25px;margin-top:20px;max-width:800px;border-radius:4px;">
            <h2 class="ois-block-title"><?php esc_html_e( 'Backup / Restore', 'ois-conversion-suite' ); ?></h2>
            <p class="description" style="max-width:720px;">
                <?php esc_html_e( 'Current backups use a .oiscl ZIP (manifest.json + metrics.jsonl): efficient for large sites and streamed imports.', 'ois-conversion-suite' ); ?>
                <?php esc_html_e( ' The PHP zip extension must be enabled on both servers.', 'ois-conversion-suite' ); ?>
                <?php esc_html_e( ' Legacy single-file JSON exports still import below ~80 MB; for larger archives re-export from a server running this plugin version.', 'ois-conversion-suite' ); ?>
            </p>
            <div style="display:flex;gap:15px;margin-top:20px;padding-bottom:20px;border-bottom:1px solid #eee;">
                <div style="flex:1;">
                    <button type="button" id="oiscl-btn-export" class="button button-large" style="width:100%;height:50px;font-weight:bold;">
                        <?php esc_html_e( 'Export metrics (.oiscl)', 'ois-conversion-suite' ); ?>
                    </button>
                </div>
                <div style="flex:1;">
                    <button type="button" id="oiscl-btn-import-trigger" class="button button-large" style="width:100%;height:50px;font-weight:bold;">
                        <?php esc_html_e( 'Import backup', 'ois-conversion-suite' ); ?>
                    </button>
                    <input type="file" id="oiscl-import-file" style="display:none;" accept=".json,.oiscl">
                </div>
            </div>
            <p class="description" style="margin-top:12px;font-size:12px;"><?php echo esc_html( $export_tip ); ?></p>
        </div>

        <dialog id="oiscl-export-backup-dialog" style="border:1px solid #ccd0d4;border-radius:10px;padding:0;max-width:460px;width:92vw;">
            <div style="padding:22px 24px;">
                <h3 style="margin:0 0 12px;font-size:16px;"><?php esc_html_e( 'Export scope', 'ois-conversion-suite' ); ?></h3>
                <p style="margin:0 0 16px;font-size:13px;color:#50575e;"><?php esc_html_e( 'Choose whether to export every metric row or only rows whose date falls in the range (based on created_at, site timezone).', 'ois-conversion-suite' ); ?></p>
                <fieldset style="border:none;margin:0;padding:0;">
                    <label style="display:flex;align-items:center;gap:8px;margin-bottom:10px;font-weight:600;">
                        <input type="radio" name="oiscl_export_scope_choice" value="all" checked>
                        <?php esc_html_e( 'All data', 'ois-conversion-suite' ); ?>
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;margin-bottom:14px;font-weight:600;">
                        <input type="radio" name="oiscl_export_scope_choice" value="range">
                        <?php esc_html_e( 'Date range', 'ois-conversion-suite' ); ?>
                    </label>
                    <div id="oiscl-export-range-fields" style="display:none;margin-left:26px;padding:12px;background:#f6f7f7;border-radius:6px;">
                        <div style="margin-bottom:10px;">
                            <label style="display:block;font-size:12px;margin-bottom:4px;"><?php esc_html_e( 'Start date', 'ois-conversion-suite' ); ?></label>
                            <input type="date" id="oiscl-export-start" class="regular-text" value="<?php echo esc_attr( $month_ago ); ?>">
                        </div>
                        <div>
                            <label style="display:block;font-size:12px;margin-bottom:4px;"><?php esc_html_e( 'End date', 'ois-conversion-suite' ); ?></label>
                            <input type="date" id="oiscl-export-end" class="regular-text" value="<?php echo esc_attr( $today ); ?>">
                        </div>
                    </div>
                </fieldset>
                <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:22px;padding-top:16px;border-top:1px solid #f0f0f1;">
                    <button type="button" class="button" id="oiscl-export-dialog-cancel"><?php esc_html_e( 'Cancel', 'ois-conversion-suite' ); ?></button>
                    <button type="button" class="button button-primary" id="oiscl-export-dialog-download"><?php esc_html_e( 'Download', 'ois-conversion-suite' ); ?></button>
                </div>
            </div>
        </dialog>
        <style>#oiscl-export-backup-dialog::backdrop{background:rgba(15,23,42,.45)}</style>

        <div id="oiscl-modal-overlay" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:99999;align-items:center;justify-content:center;">
            <div id="oiscl-modal-box" style="background:#fff;padding:30px;border-radius:8px;max-width:520px;width:90%;text-align:center;box-shadow:0 10px 25px rgba(0,0,0,0.5);">
                <div id="oiscl-modal-content"></div>
                <div id="oiscl-debug-console" style="display:none;margin-top:20px;background:#1e1e1e;color:#00ff00;font-family:monospace;font-size:11px;padding:15px;border-radius:4px;text-align:left;max-height:150px;overflow-y:auto;border:1px solid #333;">
                    <div id="oiscl-debug-steps"></div>
                </div>
            </div>
        </div>
        <script>
        jQuery(document).ready(function($) {
            var backupNonce = <?php echo wp_json_encode( $nonce ); ?>;
            var dlg = document.getElementById('oiscl-export-backup-dialog');
            $('#oiscl-btn-export').on('click', function(e) {
                e.preventDefault();
                if (dlg && dlg.showModal) dlg.showModal();
                else alert('<?php echo esc_js( __( 'Your browser does not support export dialogs. Use a current browser.', 'ois-conversion-suite' ) ); ?>');
            });
            $('input[name="oiscl_export_scope_choice"]').on('change', function() {
                $('#oiscl-export-range-fields').toggle($('input[name="oiscl_export_scope_choice"]:checked').val() === 'range');
            });
            $('#oiscl-export-dialog-cancel').on('click', function() { if (dlg) dlg.close(); });
            $('#oiscl-export-dialog-download').on('click', function() {
                var scope = $('input[name="oiscl_export_scope_choice"]:checked').val();
                var params = { action: 'oiscl_export_data', nonce: backupNonce, oiscl_export_scope: scope };
                if (scope === 'range') {
                    var s = $('#oiscl-export-start').val(), en = $('#oiscl-export-end').val();
                    if (!s || !en || !/^\d{4}-\d{2}-\d{2}$/.test(s) || !/^\d{4}-\d{2}-\d{2}$/.test(en)) {
                        alert('<?php echo esc_js( __( 'Please choose valid start and end dates (YYYY-MM-DD).', 'ois-conversion-suite' ) ); ?>');
                        return;
                    }
                    if (s > en) {
                        alert('<?php echo esc_js( __( 'Start date must be before or equal to end date.', 'ois-conversion-suite' ) ); ?>');
                        return;
                    }
                    params.oiscl_export_start = s;
                    params.oiscl_export_end = en;
                }
                var url = (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php') + '?' + $.param(params);
                if (dlg) dlg.close();
                window.location.href = url;
            });
            $('#oiscl-btn-import-trigger').on('click', function() { $('#oiscl-import-file').click(); });
            $('#oiscl-import-file').on('change', function() {
                var file = this.files[0];
                if (!file) return;
                if (!confirm('<?php echo esc_js( __( 'Warning: this replaces all metric data on this site with the backup. Continue?', 'ois-conversion-suite' ) ); ?>')) { $(this).val(''); return; }
                var formData = new FormData();
                formData.append('action', 'oiscl_import_data');
                formData.append('nonce', backupNonce);
                formData.append('backup_file', file);
                var btn = $('#oiscl-btn-import-trigger');
                var originalText = btn.text();
                btn.text('<?php echo esc_js( __( 'Importing…', 'ois-conversion-suite' ) ); ?>').prop('disabled', true);
                function importErrMsg(data) {
                    if (!data) return '<?php echo esc_js( __( 'Unknown error', 'ois-conversion-suite' ) ); ?>';
                    if (typeof data === 'string') return data;
                    if (data.message) return data.message;
                    try { return JSON.stringify(data); } catch (e) { return 'Error'; }
                }
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    timeout: 0,
                    beforeSend: function() {
                        $('#oiscl-modal-overlay').css('display', 'flex');
                        $('#oiscl-modal-content').html('<h3><?php echo esc_js( __( 'Importing backup', 'ois-conversion-suite' ) ); ?></h3><p><?php echo esc_js( __( 'Large files may take several minutes — keep this tab open.', 'ois-conversion-suite' ) ); ?></p>');
                        $('#oiscl-debug-console').show();
                        $('#oiscl-debug-steps').html('<p>> <?php echo esc_js( __( 'Uploading and processing…', 'ois-conversion-suite' ) ); ?></p>');
                    },
                    success: function(response) {
                        if (response.success && response.data && response.data.log) {
                            var lines = response.data.log;
                            lines.forEach(function(line, i) {
                                setTimeout(function() {
                                    $('#oiscl-debug-steps').append('<p>> ' + $('<div/>').text(line).html() + '</p>');
                                    var c = document.getElementById('oiscl-debug-console');
                                    if (c) c.scrollTop = c.scrollHeight;
                                }, i * 200);
                            });
                            setTimeout(function() {
                                alert('<?php echo esc_js( __( 'Import finished successfully.', 'ois-conversion-suite' ) ); ?>');
                                location.reload();
                            }, (lines.length * 200) + 600);
                        } else {
                            $('#oiscl-debug-steps').append('<p style="color:#ff4d4f;">> ' + importErrMsg(response.data) + '</p>');
                            btn.text(originalText).prop('disabled', false);
                            $('#oiscl-import-file').val('');
                        }
                    },
                    error: function(xhr) {
                        var m = '<?php echo esc_js( __( 'Network error, proxy timeout, or connection closed (502/504).', 'ois-conversion-suite' ) ); ?>';
                        if (xhr.responseJSON && xhr.responseJSON.data) m = importErrMsg(xhr.responseJSON.data);
                        else if (xhr.status) m += ' HTTP ' + xhr.status;
                        $('#oiscl-modal-overlay').css('display', 'flex');
                        $('#oiscl-debug-console').show();
                        $('#oiscl-debug-steps').append('<p style="color:#ff4d4f;">> ' + m + '</p>');
                        btn.text(originalText).prop('disabled', false);
                        $('#oiscl-import-file').val('');
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Help & Support tab: overview of menus, settings, exports, and bundled docs.
     */
    private function render_help_support_settings() {
        $docs_base = '';
        if ( defined( 'OISCL_PLUGIN_FILE' ) ) {
            $docs_base = trailingslashit( plugin_dir_url( OISCL_PLUGIN_FILE ) ) . 'docs/';
        }
        ?>
        <div style="background:#fff; border:1px solid #ccd0d4; padding:25px; margin-top:20px; max-width:960px; border-radius:4px;">
            <p class="description"><?php esc_html_e( 'High-level reference for what Conversion Lab does and where to find things in wp-admin. Support tickets should include dates, URLs, and whether Click Tracker / UTM recording is involved.', 'ois-conversion-suite' ); ?></p>

            <h3 class="ois-block-title ois-block-title--spaced-top"><?php esc_html_e( 'Admin menu (Conversion Lab)', 'ois-conversion-suite' ); ?></h3>
            <ul style="list-style:disc; margin-left:1.25em;">
                <li><strong><?php esc_html_e( 'Dashboard', 'ois-conversion-suite' ); ?></strong> — <?php esc_html_e( 'Overview KPIs, traffic trends, top targets and clicks.', 'ois-conversion-suite' ); ?></li>
                <li><strong><?php esc_html_e( 'Analytics', 'ois-conversion-suite' ); ?></strong> — <?php esc_html_e( 'Explorer by dimension (blocks, targets, dates).', 'ois-conversion-suite' ); ?></li>
                <li><strong><?php esc_html_e( 'Click Tracker', 'ois-conversion-suite' ); ?></strong> — <?php esc_html_e( 'Recording setup, scan test, block metrics.', 'ois-conversion-suite' ); ?></li>
                <li><strong><?php esc_html_e( 'UTM Manager', 'ois-conversion-suite' ); ?></strong> — <?php esc_html_e( 'Campaign intelligence: Overview, Company rollups, Campaign performance, URL Builder, Click Tracker & Audience (scoped KPIs), Conversion funnel (session funnel + CSV exports).', 'ois-conversion-suite' ); ?></li>
                <li><strong><?php esc_html_e( 'SEO', 'ois-conversion-suite' ); ?></strong> — <?php esc_html_e( 'SEO-focused reports.', 'ois-conversion-suite' ); ?></li>
                <li><strong><?php esc_html_e( 'Rules engine', 'ois-conversion-suite' ); ?></strong> — <?php esc_html_e( 'Automation rules.', 'ois-conversion-suite' ); ?></li>
                <li><strong><?php esc_html_e( 'Suite Settings', 'ois-conversion-suite' ); ?></strong> — <?php esc_html_e( 'Plugin configuration (this screen).', 'ois-conversion-suite' ); ?></li>
            </ul>

            <h3 class="ois-block-title ois-block-title--spaced-top"><?php esc_html_e( 'Suite Settings tabs', 'ois-conversion-suite' ); ?></h3>
            <ul style="list-style:disc; margin-left:1.25em;">
                <li><strong><?php esc_html_e( 'General & Reports', 'ois-conversion-suite' ); ?></strong> — <?php esc_html_e( 'Privacy options; dashboard defaults.', 'ois-conversion-suite' ); ?></li>
                <li><strong><?php esc_html_e( 'Click Tracker', 'ois-conversion-suite' ); ?></strong> — <?php esc_html_e( 'Recording toggle and technical controls.', 'ois-conversion-suite' ); ?></li>
                <li><strong><?php esc_html_e( 'UTM Manager', 'ois-conversion-suite' ); ?></strong> — <?php esc_html_e( 'UTM persistence and tracking defaults.', 'ois-conversion-suite' ); ?></li>
                <li><strong><?php esc_html_e( 'Maintenance', 'ois-conversion-suite' ); ?></strong> — <?php esc_html_e( 'Hosting health report, diagnostics, uninstall data preference.', 'ois-conversion-suite' ); ?></li>
                <li><strong><?php esc_html_e( 'Backup / Restore', 'ois-conversion-suite' ); ?></strong> — <?php esc_html_e( 'Export JSON (all data or date range) and import (can overwrite existing metrics).', 'ois-conversion-suite' ); ?></li>
            </ul>

            <h3 class="ois-block-title ois-block-title--spaced-top"><?php esc_html_e( 'UTM Manager — funnel CSV exports', 'ois-conversion-suite' ); ?></h3>
            <p><?php esc_html_e( 'From UTM Manager → Conversion funnel, CSV downloads respect the selected date range and UTM filter.', 'ois-conversion-suite' ); ?></p>
            <ul style="list-style:disc; margin-left:1.25em;">
                <li><strong><?php esc_html_e( 'Global', 'ois-conversion-suite' ); ?></strong> — <?php esc_html_e( 'One row matching the “Global UTM funnel” card (sessions with any stored utm_campaign).', 'ois-conversion-suite' ); ?></li>
                <li><strong><?php esc_html_e( 'Company', 'ois-conversion-suite' ); ?></strong> — <?php esc_html_e( 'Per utm_company bucket.', 'ois-conversion-suite' ); ?></li>
                <li><strong><?php esc_html_e( 'Campaign', 'ois-conversion-suite' ); ?></strong> — <?php esc_html_e( 'Per utm_campaign bucket.', 'ois-conversion-suite' ); ?></li>
                <li><strong><?php esc_html_e( 'Both sections', 'ois-conversion-suite' ); ?></strong> — <?php esc_html_e( 'Company table then Campaign table.', 'ois-conversion-suite' ); ?></li>
                <li><strong><?php esc_html_e( 'Complete report', 'ois-conversion-suite' ); ?></strong> — <?php esc_html_e( 'Global row, blank separator, Company table, blank separator, Campaign table — single stakeholder-friendly file.', 'ois-conversion-suite' ); ?></li>
            </ul>

            <h3 class="ois-block-title ois-block-title--spaced-top"><?php esc_html_e( 'Backup / restore cautions', 'ois-conversion-suite' ); ?></h3>
            <ul style="list-style:disc; margin-left:1.25em;">
                <li><?php esc_html_e( 'Import merges into existing tables and can duplicate rows if you import the same backup twice.', 'ois-conversion-suite' ); ?></li>
                <li><?php esc_html_e( 'Use date-range export when you only need a slice (smaller files, faster restores).', 'ois-conversion-suite' ); ?></li>
            </ul>

            <h3 class="ois-block-title ois-block-title--spaced-top"><?php esc_html_e( 'Diagnostics', 'ois-conversion-suite' ); ?></h3>
            <p><?php esc_html_e( 'Enable verbose UTM SQL logging by defining OISCL_UTM_DIAG in wp-config.php (constant true). Disable on production after troubleshooting.', 'ois-conversion-suite' ); ?></p>

            <?php if ( $docs_base ) : ?>
            <h3 class="ois-block-title ois-block-title--spaced-top"><?php esc_html_e( 'Bundled documentation (browser)', 'ois-conversion-suite' ); ?></h3>
            <p><?php esc_html_e( 'Markdown files ship with the plugin; open via URL (may download depending on server):', 'ois-conversion-suite' ); ?></p>
            <ul style="list-style:disc; margin-left:1.25em;">
                <li><a href="<?php echo esc_url( $docs_base . 'utm-manager-business-map.md' ); ?>" target="_blank" rel="noopener noreferrer">utm-manager-business-map.md</a></li>
                <li><a href="<?php echo esc_url( $docs_base . 'utm-manager-qa-checklist.md' ); ?>" target="_blank" rel="noopener noreferrer">utm-manager-qa-checklist.md</a></li>
            </ul>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_maintenance_settings() {
        if ( isset( $_GET['oiscl_uninstall_pref'] ) && 'saved' === sanitize_text_field( wp_unslash( $_GET['oiscl_uninstall_pref'] ) ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Uninstall preference saved.', 'ois-conversion-suite' ) . '</p></div>';
        }
        global $wpdb;
        $scan_table = $wpdb->prefix . 'oiscl_block_metrics';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $debug_total = (int) ( $wpdb->get_var( "SELECT COUNT(*) FROM `{$scan_table}`" ) ?: 0 );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $debug_pv = (int) ( $wpdb->get_var( "SELECT COUNT(*) FROM `{$scan_table}` WHERE anchor_text='[Pageview]'" ) ?: 0 );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $debug_date = $wpdb->get_var( "SELECT MAX(created_at) FROM `{$scan_table}`" );
        ?>
        <div style="background:#fff; border:1px solid #ccd0d4; padding:25px; margin-top:20px; max-width:800px; border-radius:4px;">
            <?php $this->oiscl_render_maintenance_site_health_panel(); ?>

            <h3 class="ois-block-title ois-block-title--spaced-top"><?php esc_html_e( 'Advanced diagnostics', 'ois-conversion-suite' ); ?></h3>
            <p class="description"><?php esc_html_e( 'Optional checks beyond the hosting report above: raw row counts and a write test through the same AJAX scan used by Click Tracker setup.', 'ois-conversion-suite' ); ?></p>
            <div style="display:flex; gap:10px; margin-top:15px;">
                <button type="button" id="oiscl-btn-toggle-scanner" class="button"><?php esc_html_e( 'Database quick scan', 'ois-conversion-suite' ); ?></button>
                <button type="button" id="oiscl-btn-toggle-io" class="button"><?php esc_html_e( 'Write test (scan & save)', 'ois-conversion-suite' ); ?></button>
            </div>
            <div id="oiscl-box-scanner" style="display:none; margin-top:20px; background:#fff5f5; border:1px solid #d63638; padding:15px; border-radius:4px;">
                <h4 style="margin-top:0; color:#d63638;"><?php esc_html_e( 'Scan results', 'ois-conversion-suite' ); ?></h4>
                <p style="margin:6px 0;">
                    <strong><?php esc_html_e( 'Metrics table', 'ois-conversion-suite' ); ?></strong>
                    <code><?php echo esc_html( $scan_table ); ?></code>:
                    <?php
                    echo $debug_total
                        ? '✅ ' . esc_html( sprintf( /* translators: %s: row count */ __( '%s rows', 'ois-conversion-suite' ), number_format_i18n( $debug_total ) ) )
                        : '❌ ' . esc_html__( '0 rows (empty or disconnected)', 'ois-conversion-suite' );
                    ?>
                </p>
                <p style="margin:6px 0;">
                    <strong><?php esc_html_e( 'Pageview rows', 'ois-conversion-suite' ); ?>:</strong>
                    <?php echo esc_html( number_format_i18n( $debug_pv ) ); ?>
                </p>
                <p style="margin:6px 0;">
                    <strong><?php esc_html_e( 'Latest created_at', 'ois-conversion-suite' ); ?>:</strong>
                    <?php echo esc_html( $debug_date ? (string) $debug_date : '—' ); ?>
                </p>
            </div>
            <div id="oiscl-box-io" style="display:none; margin-top:20px; background:#f0f7ff; border:1px solid #1a73e8; padding:15px; border-radius:4px;">
                <div style="flex:1;">
                    <h4 style="margin:0; color:#1a73e8; font-size:13px;"><?php esc_html_e( 'Write test', 'ois-conversion-suite' ); ?></h4>
                    <p style="margin:8px 0 0 0; font-size:12px; color:#50575e;">
                        <?php esc_html_e( 'Runs the page HTML scan endpoint for one post/page — verifies admin AJAX can persist scanner output.', 'ois-conversion-suite' ); ?>
                    </p>
                </div>
                <div style="display:flex; flex-wrap:wrap; gap:10px; margin-top:12px; align-items:center;">
                    <select id="oiscl-test-selector" style="min-width:220px;">
                        <?php
                        $test_posts = get_posts( array( 'post_type' => array( 'post', 'page' ), 'posts_per_page' => 15 ) );
                        foreach ( $test_posts as $tp ) {
                            echo '<option value="' . (int) $tp->ID . '">' . esc_html( $tp->post_title ) . '</option>';
                        }
                        ?>
                    </select>
                    <button type="button" id="oiscl-btn-test-scan" class="button button-primary"><?php esc_html_e( 'Run write test', 'ois-conversion-suite' ); ?></button>
                </div>
                <div id="oiscl-test-result" style="margin-top:12px;font-family:monospace;font-size:12px;padding:10px;background:#fff;border:1px solid #ccd0d4;min-height:22px;">
                    <?php esc_html_e( 'Idle.', 'ois-conversion-suite' ); ?>
                </div>
            </div>

            <div style="margin-top:28px; padding:15px; border:1px solid #ffe7ba; background:#fffbe6; border-radius:4px;">
                <h4 style="margin:0 0 8px 0; color:#d46b08;"><?php esc_html_e( 'Free disk / legacy JSON logs', 'ois-conversion-suite' ); ?></h4>
                <p style="font-size:12px;margin:0 0 12px;">
                    <?php esc_html_e( 'Removes legacy JSON files under wp-content if present. Current metrics stay in the database.', 'ois-conversion-suite' ); ?>
                </p>
                <button type="button" id="oiscl-btn-purge" class="button" style="border-color:#fa8c16; color:#fa8c16;">
                    <?php esc_html_e( 'Purge legacy log files', 'ois-conversion-suite' ); ?>
                </button>
            </div>

            <h3 class="ois-block-title ois-block-title--spaced-top ois-block-title--danger"><?php esc_html_e( 'Danger zone: uninstall', 'ois-conversion-suite' ); ?></h3>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:16px; padding:15px; background:#f6f7f7; border:1px solid #c3c4c7; border-radius:4px; max-width:720px;">
                <input type="hidden" name="action" value="oiscl_save_uninstall_pref">
                <?php wp_nonce_field( 'oiscl_save_uninstall_pref', 'oiscl_save_uninstall_pref_nonce' ); ?>
                <p style="margin-top:0;"><strong><?php echo esc_html__( 'Plugin removal (WordPress)', 'ois-conversion-suite' ); ?></strong></p>
                <p class="description" style="max-width:640px;">
                    <?php esc_html_e( 'When you delete the plugin from Plugins → Installed Plugins, WordPress normally keeps OIS tables and options. Enable the option below only if you want the uninstall hook to drop all OIS tables and options when the plugin files are removed.', 'ois-conversion-suite' ); ?>
                </p>
                <label style="display:block; margin:12px 0;">
                    <input type="checkbox" name="oiscl_delete_on_uninstall" value="1" <?php checked( get_option( 'oiscl_delete_data_on_uninstall', '0' ), '1' ); ?>>
                    <?php echo esc_html__( 'Delete all OIS tables (metrics, page tracking rules, UTM references) and plugin options when the plugin is deleted (irreversible).', 'ois-conversion-suite' ); ?>
                </label>
                <?php submit_button( __( 'Save uninstall preference', 'ois-conversion-suite' ), 'secondary', 'submit', false ); ?>
            </form>
            <p class="description" style="max-width:720px;margin-bottom:12px;">
                <?php esc_html_e( 'Starts a short wizard: optional full export (.oiscl), then deletes all OIS metric tables, UTM reference table, page-rule options, and wp-content/ois-logs/. The plugin is deactivated automatically; delete plugin files from Plugins when you are ready.', 'ois-conversion-suite' ); ?>
            </p>
            <button type="button" id="oiscl-btn-uninstall" class="button" style="color:#d63638; border-color:#d63638; font-weight:bold;">
                <?php esc_html_e( 'Start total data removal…', 'ois-conversion-suite' ); ?>
            </button>
        </div>

        <div id="oiscl-modal-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); z-index:99999; align-items:center; justify-content:center;">
            <div id="oiscl-modal-box" style="background:#fff; padding:30px; border-radius:8px; max-width:500px; width:90%; text-align:center; box-shadow:0 10px 25px rgba(0,0,0,0.5);">
                <div id="oiscl-modal-content"></div>
                <div id="oiscl-debug-console" style="display:none; margin-top:20px; background:#1e1e1e; color:#00ff00; font-family:monospace; font-size:11px; padding:15px; border-radius:4px; text-align:left; max-height:150px; overflow-y:auto; border:1px solid #333;">
                    <div id="oiscl-debug-steps"></div>
                </div>
            </div>
        </div>
        <script>
        jQuery(document).ready(function($) {
            $('#oiscl-btn-toggle-scanner').on('click', function(e) { e.preventDefault(); $('#oiscl-box-scanner').slideToggle(); });
            $('#oiscl-btn-toggle-io').on('click', function(e) { e.preventDefault(); $('#oiscl-box-io').slideToggle(); });
            $(document).on('click', '#oiscl-btn-test-scan', function(e) {
                e.preventDefault();
                var pId = $('#oiscl-test-selector').val();
                var $res = $('#oiscl-test-result');
                var $btn = $(this);
                $btn.prop('disabled', true).text('<?php echo esc_js( __( 'Running…', 'ois-conversion-suite' ) ); ?>');
                $res.css('color', '#666').text('<?php echo esc_js( __( 'Connecting…', 'ois-conversion-suite' ) ); ?>');
                $.ajax({
                    url: window.ajaxurl || '/wp-admin/admin-ajax.php',
                    type: 'POST',
                    data: { action: 'oiscl_scan_page_html', post_id: pId, nonce: '<?php echo wp_create_nonce( 'oiscl_admin_nonce' ); ?>' },
                    success: function(r) {
                        $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Run write test', 'ois-conversion-suite' ) ); ?>');
                        if (r.success) { $res.css('color', '#166534').text('<?php echo esc_js( __( 'Success: response saved.', 'ois-conversion-suite' ) ); ?>'); }
                        else { $res.css('color', '#b91c1c').text('<?php echo esc_js( __( 'Failed: ', 'ois-conversion-suite' ) ); ?>' + (r.data || 'Error')); }
                    },
                    error: function() {
                        $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Run write test', 'ois-conversion-suite' ) ); ?>');
                        $res.css('color', '#b91c1c').text('<?php echo esc_js( __( 'Network or server error.', 'ois-conversion-suite' ) ); ?>');
                    }
                });
            });
            const overlay = $('#oiscl-modal-overlay');
            const content = $('#oiscl-modal-content');
            const backupUrlAll = '<?php echo esc_url( admin_url( 'admin-ajax.php?action=oiscl_export_data&nonce=' . wp_create_nonce( 'oiscl_admin_nonce' ) . '&oiscl_export_scope=all' ) ); ?>';
            $('#oiscl-btn-uninstall').on('click', function() {
                overlay.css('display', 'flex');
                content.html('<h2 style="color:#d63638;margin-top:0;"><?php echo esc_js( __( 'Step 1 — Backup', 'ois-conversion-suite' ) ); ?></h2>' +
                    '<p><?php echo esc_js( __( 'Download a full metric export (.oiscl) before wiping data?', 'ois-conversion-suite' ) ); ?></p>' +
                    '<div style="margin-top:20px;display:flex;flex-direction:column;gap:10px;">' +
                    '<button type="button" id="step-backup" class="button button-primary"><?php echo esc_js( __( 'Download backup & continue', 'ois-conversion-suite' ) ); ?></button>' +
                    '<button type="button" id="step-no-backup" class="button"><?php echo esc_js( __( 'Continue without backup', 'ois-conversion-suite' ) ); ?></button>' +
                    '<button type="button" id="step-cancel" class="button button-link"><?php echo esc_js( __( 'Cancel', 'ois-conversion-suite' ) ); ?></button></div>');
            });
            $(document).on('click', '#step-no-backup, #step-backup', function() {
                if ($(this).attr('id') === 'step-backup') { window.location.href = backupUrlAll; return; }
                content.html('<h2 style="color:#d63638;margin-top:0;"><?php echo esc_js( __( 'Critical confirmation', 'ois-conversion-suite' ) ); ?></h2>' +
                    '<p><?php echo esc_js( __( 'This permanently deletes all OIS metric tables, the UTM references table, related options, and wp-content/ois-logs/. It cannot be undone.', 'ois-conversion-suite' ) ); ?></p>' +
                    '<div style="margin-top:20px;display:flex;justify-content:center;gap:10px;flex-wrap:wrap;">' +
                    '<button type="button" id="step-final-delete" class="button" style="background:#d63638;color:#fff;border:none;padding:10px 20px;"><?php echo esc_js( __( 'Yes, delete all OIS data', 'ois-conversion-suite' ) ); ?></button>' +
                    '<button type="button" id="step-cancel2" class="button" style="padding:10px 20px;"><?php echo esc_js( __( 'Cancel', 'ois-conversion-suite' ) ); ?></button></div>');
            });
            $(document).on('click', '#step-cancel, #step-cancel2', function() { overlay.hide(); });
            $(document).on('click', '#step-final-delete', function() {
                $(this).prop('disabled', true).text('<?php echo esc_js( __( 'Deleting…', 'ois-conversion-suite' ) ); ?>');
                $.post(ajaxurl, { action: 'oiscl_full_uninstall_cleanup', nonce: '<?php echo wp_create_nonce( 'oiscl_admin_nonce' ); ?>' }, function() {
                    window.location.href = '<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>';
                });
            });
            $('#oiscl-btn-purge').on('click', function() {
                if (!confirm('<?php echo esc_js( __( 'Remove legacy JSON log files under wp-content? Database metrics are not deleted.', 'ois-conversion-suite' ) ); ?>')) return;
                var btn = $(this);
                var lbl = '<?php echo esc_js( __( 'Purge legacy log files', 'ois-conversion-suite' ) ); ?>';
                btn.prop('disabled', true).text('<?php echo esc_js( __( 'Purging…', 'ois-conversion-suite' ) ); ?>');
                $.post(ajaxurl, { action: 'oiscl_purge_old_logs', nonce: '<?php echo wp_create_nonce( 'oiscl_admin_nonce' ); ?>' }, null, 'json')
                    .done(function(r) {
                        var msg;
                        if (r && r.success && r.data) {
                            msg = r.data;
                        } else if (r && r.data && r.data.message) {
                            msg = r.data.message;
                        } else {
                            msg = '<?php echo esc_js( __( 'Something went wrong.', 'ois-conversion-suite' ) ); ?>';
                        }
                        alert(msg);
                        if (r && r.success) {
                            location.reload();
                        } else {
                            btn.prop('disabled', false).text(lbl);
                        }
                    })
                    .fail(function() {
                        btn.prop('disabled', false).text(lbl);
                        alert('<?php echo esc_js( __( 'Request failed.', 'ois-conversion-suite' ) ); ?>');
                    });
            });
        });
        </script>
        <?php
    }

    /**
     * Max sessions for Analytics / UTM journey tables and their CSV exports.
     * Override with add_filter( 'oiscl_journey_session_limit', fn() => 2000 ); (clamped 100–5000).
     *
     * @return int
     */
    public function oiscl_get_journey_session_limit() {
        $lim = (int) apply_filters( 'oiscl_journey_session_limit', 1500 );
        return max( 100, min( 5000, $lim ) );
    }

    /**
     * Batch size when exporting all sessions (full census CSV). Smaller = less memory per query.
     *
     * @return int Clamped 50–2000.
     */
    public function oiscl_get_journey_export_batch_size() {
        $n = (int) apply_filters( 'oiscl_journey_export_batch_size', 400 );
        return max( 50, min( 2000, $n ) );
    }

    /**
     * Normalize screen resolution for journey tables / CSV (empty or placeholder → em dash).
     *
     * @param mixed $raw Value from SQL aggregate.
     * @return string
     */
    public function oiscl_format_journey_screen_res_display( $raw ) {
        $s = ( is_string( $raw ) || is_numeric( $raw ) ) ? trim( (string) $raw ) : '';
        if ( '' === $s || 'N/A' === strtoupper( $s ) ) {
            return '—';
        }
        return $s;
    }

    /**
     * Raw aggregate rows for Analytics journey (one row per session_id).
     *
     * @param string $start_date Y-m-d
     * @param string $end_date   Y-m-d
     * @param int    $limit
     * @param int    $offset
     * @return array<int, object>
     */
    public function oiscl_query_analytics_journey_batch( $start_date, $end_date, $limit, $offset ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'oiscl_block_metrics';
        $has_res    = $wpdb->get_results( "SHOW COLUMNS FROM `{$table_name}` LIKE 'screen_res'" );
        $res_query  = ! empty( $has_res )
            ? "MAX(CASE WHEN TRIM(IFNULL(screen_res,'')) NOT IN ('', 'N/A') THEN screen_res END) as screen_res"
            : "'N/A' as screen_res";
        $sql        = "SELECT session_id, country, city, MAX(os) as os_name, MAX(browser) as browser_name, MAX(device) as device_name, {$res_query}, MAX(language) as language, MIN(is_bot) as bot_status, MIN(created_at) as entry_time, GROUP_CONCAT(CONCAT(created_at, '|', origin_url, '|', anchor_text, '|', destination_url) ORDER BY created_at ASC SEPARATOR '||') as steps_raw FROM {$table_name} WHERE DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY session_id ORDER BY entry_time DESC LIMIT %d OFFSET %d";
        $rows       = $wpdb->get_results( $wpdb->prepare( $sql, $start_date, $end_date, $limit, $offset ) );
        return $rows ? $rows : array();
    }

    /**
     * Turn one SQL aggregate row into the session array used by journey UI / CSV.
     *
     * @param object $r DB row.
     * @return array<string, mixed>
     */
    public function oiscl_normalize_analytics_journey_row( $r ) {
        $steps        = array();
        $raw_steps    = explode( '||', $r->steps_raw );
        $first_ts     = null;
        $last_ts      = null;
        $total_clicks = 0;

        foreach ( $raw_steps as $rs ) {
            $p      = explode( '|', $rs );
            $ts     = strtotime( $p[0] );
            $anchor = $p[2] ?? '';

            if ( null === $first_ts ) {
                $first_ts = $ts;
            }
            $last_ts = $ts;

            if ( '[Pageview]' !== $anchor && '[Vista de Bloque]' !== $anchor && '[Error 404]' !== $anchor ) {
                $total_clicks++;
            }

            $steps[] = array(
                'time'   => date( 'H:i:s', $ts ),
                'url'    => $p[1],
                'anchor' => $anchor,
                'dest'   => $p[3] ?? '',
            );
        }

        $duration_sec = $last_ts - $first_ts;
        $duration_fmt = ( $duration_sec >= 60 ) ? round( $duration_sec / 60, 1 ) . 'm' : $duration_sec . 's';
        $date_fmt     = date( 'd/m/Y', strtotime( $r->entry_time ) );

        $dev_icon = stripos( $r->device_name, 'Mobile' ) !== false ? '📱' : ( stripos( $r->device_name, 'Tablet' ) !== false ? '💊' : '💻' );
        $os_icon  = stripos( $r->os_name, 'Windows' ) !== false ? '🪟' : ( stripos( $r->os_name, 'Mac' ) !== false || stripos( $r->os_name, 'iOS' ) !== false ? '🍎' : ( stripos( $r->os_name, 'Android' ) !== false ? '🤖' : '🐧' ) );
        $bro_icon = stripos( $r->browser_name, 'Chrome' ) !== false ? '🌐' : ( stripos( $r->browser_name, 'Safari' ) !== false ? '🧭' : ( stripos( $r->browser_name, 'Firefox' ) !== false ? '🦊' : '🌍' ) );

        return array(
            'ip'             => 'User ' . substr( $r->session_id, 0, 6 ),
            'time'           => date( 'H:i:s', strtotime( $r->entry_time ) ),
            'date'           => $date_fmt,
            'duration'       => $duration_fmt,
            'total_clicks'   => $total_clicks,
            'dev_icon'       => $dev_icon,
            'os_icon'        => $os_icon,
            'bro_icon'       => $bro_icon,
            'device_name'    => $r->device_name ? $r->device_name : 'Unknown',
            'os_name'        => $r->os_name ? $r->os_name : 'Unknown',
            'browser_name'   => $r->browser_name ? $r->browser_name : 'Unknown',
            'screen_res'     => $this->oiscl_format_journey_screen_res_display( isset( $r->screen_res ) ? $r->screen_res : null ),
            'lang'           => strtoupper( $r->language ),
            'is_bot'         => $r->bot_status,
            'steps'          => $steps,
            'location'       => $r->city . ', ' . $r->country,
        );
    }

    // lógica matemática del tiempo y la fecha (Bulletproof + Resolución + Clics)
    public function get_oiscl_user_sessions( $start_date, $end_date ) {
        $jlim     = $this->oiscl_get_journey_session_limit();
        $results  = $this->oiscl_query_analytics_journey_batch( $start_date, $end_date, $jlim, 0 );
        $sessions = array();
        foreach ( $results as $r ) {
            $sessions[] = $this->oiscl_normalize_analytics_journey_row( $r );
        }
        return $sessions;
    }

    /**
     * SQL fragments for UTM Tracker dashboard queries (campaign filter dropdown).
     *
     * @param string $selected_filter Raw `utm_filter` value (e.g. all, lbl_Acme, summer_sale).
     * @return array{filter_sql_stats: string, filter_sql_refs: string}
     */
    public function get_oiscl_utm_dashboard_filters( $selected_filter ) {
        global $wpdb;
        $table_refs         = $wpdb->prefix . 'oiscl_utm_references';
        $filter_sql_stats   = '';
        $filter_sql_refs    = '';
        $selected_filter    = is_string( $selected_filter ) ? sanitize_text_field( $selected_filter ) : 'all';

        if ( 'all' !== $selected_filter ) {
            if ( 0 === strpos( $selected_filter, 'lbl_' ) ) {
                $label_query = substr( $selected_filter, 4 );
                $filter_sql_refs = $wpdb->prepare( ' AND label_name = %s ', $label_query );
                $camps           = $wpdb->get_col( $wpdb->prepare( "SELECT utm_campaign FROM {$table_refs} WHERE label_name = %s", $label_query ) );
                if ( $camps ) {
                    $filter_sql_stats = " AND utm_campaign IN ('" . implode( "','", array_map( 'esc_sql', $camps ) ) . "') ";
                } else {
                    $filter_sql_stats = ' AND 1=0 ';
                }
            } else {
                $filter_sql_refs  = $wpdb->prepare( ' AND utm_campaign = %s ', $selected_filter );
                $filter_sql_stats = $wpdb->prepare( ' AND utm_campaign = %s ', $selected_filter );
            }
        }

        return array(
            'filter_sql_stats' => $filter_sql_stats,
            'filter_sql_refs'  => $filter_sql_refs,
        );
    }

    /**
     * UTM Journey attribution mode from query string.
     *
     * @param string|null $mode Optional override.
     * @return string first|last|session
     */
    public function oiscl_sanitize_utm_attr_mode( $mode ) {
        $mode = sanitize_key( (string) $mode );
        return in_array( $mode, array( 'first', 'last', 'session' ), true ) ? $mode : 'first';
    }

    /**
     * @return string
     */
    public function oiscl_get_utm_journey_attribution_mode() {
        if ( isset( $_GET['utm_attr'] ) ) {
            return $this->oiscl_sanitize_utm_attr_mode( wp_unslash( $_GET['utm_attr'] ) );
        }
        return 'first';
    }

    /**
     * @return array<string,string>
     */
    private function oiscl_utm_journey_attr_labels() {
        return array(
            'first'   => __( 'First touch', 'ois-conversion-suite' ),
            'last'    => __( 'Last touch', 'ois-conversion-suite' ),
            'session' => __( 'Session landing', 'ois-conversion-suite' ),
        );
    }

    /**
     * @param string $bundle     ###SEP###-delimited bundle segment.
     * @param bool   $has_utm_sm Whether source/medium columns exist.
     * @return array{utm_campaign:string,utm_term:string,utm_source:string,utm_medium:string}
     */
    private function oiscl_utm_parse_bundle_fields( $bundle, $has_utm_sm ) {
        $utm_campaign = '';
        $utm_term     = '';
        $utm_source   = '';
        $utm_medium   = '';
        if ( '' !== (string) $bundle ) {
            $parts        = explode( '|||', (string) $bundle );
            $utm_campaign = isset( $parts[0] ) ? (string) $parts[0] : '';
            $utm_term     = isset( $parts[1] ) ? (string) $parts[1] : '';
            if ( $has_utm_sm ) {
                $utm_source = isset( $parts[2] ) ? (string) $parts[2] : '';
                $utm_medium = isset( $parts[3] ) ? (string) $parts[3] : '';
            }
        }
        return array(
            'utm_campaign' => $utm_campaign,
            'utm_term'     => $utm_term,
            'utm_source'   => $utm_source,
            'utm_medium'   => $utm_medium,
        );
    }

    /**
     * Raw UTM journey batch plus label map (shared by UI table and full CSV export).
     *
     * @param string $start_date Y-m-d
     * @param string $end_date   Y-m-d
     * @param string $filter_sql_stats SQL fragment (escaped).
     * @param int    $limit
     * @param int                    $offset
     * @param array<string, string>|null $reuse_label_map Pass map from a previous call to skip re-reading refs (full CSV batches).
     * @return array{rows: array<int, object>, camp_to_label: array<string, string>, has_utm_sm: bool}
     */
    public function oiscl_fetch_utm_journey_batch_with_context( $start_date, $end_date, $filter_sql_stats, $limit, $offset, $reuse_label_map = null ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'oiscl_block_metrics';
        $table_refs = $wpdb->prefix . 'oiscl_utm_references';

        if ( is_array( $reuse_label_map ) ) {
            $camp_to_label = $reuse_label_map;
        } else {
            $camp_to_label = array();
            $refs           = $wpdb->get_results( "SELECT utm_campaign, label_name FROM {$table_refs}" );
            foreach ( (array) $refs as $ref ) {
                if ( ! empty( $ref->utm_campaign ) ) {
                    $camp_to_label[ $ref->utm_campaign ] = $ref->label_name;
                }
            }
        }

        $has_utm_sm = ! empty( $wpdb->get_results( "SHOW COLUMNS FROM `{$table_name}` LIKE 'utm_source'" ) );

        $has_res   = $wpdb->get_results( "SHOW COLUMNS FROM `{$table_name}` LIKE 'screen_res'" );
        $res_query = ! empty( $has_res )
            ? "MAX(CASE WHEN TRIM(IFNULL(screen_res,'')) NOT IN ('', 'N/A') THEN screen_res END) as screen_res"
            : "'N/A' as screen_res";

        if ( $has_utm_sm ) {
            $bundle_inner     = "IF(utm_campaign <> '', CONCAT(utm_campaign, '|||', IFNULL(utm_term,''), '|||', IFNULL(utm_source,''), '|||', IFNULL(utm_medium,'')), NULL)";
            $first_utm_bundle = "SUBSTRING_INDEX(GROUP_CONCAT({$bundle_inner} ORDER BY created_at ASC SEPARATOR '###SEP###'), '###SEP###', 1) as first_utm_bundle";
            $last_utm_bundle  = "SUBSTRING_INDEX(GROUP_CONCAT({$bundle_inner} ORDER BY created_at DESC SEPARATOR '###SEP###'), '###SEP###', 1) as last_utm_bundle";
        } else {
            $bundle_inner     = "IF(utm_campaign <> '', CONCAT(utm_campaign, '|||', IFNULL(utm_term,'')), NULL)";
            $first_utm_bundle = "SUBSTRING_INDEX(GROUP_CONCAT({$bundle_inner} ORDER BY created_at ASC SEPARATOR '###SEP###'), '###SEP###', 1) as first_utm_bundle";
            $last_utm_bundle  = "SUBSTRING_INDEX(GROUP_CONCAT({$bundle_inner} ORDER BY created_at DESC SEPARATOR '###SEP###'), '###SEP###', 1) as last_utm_bundle";
        }
        $pv_event       = esc_sql( OISCL_Plan::EVENT_PAGEVIEW );
        $pv_utm_bundle  = "SUBSTRING_INDEX(GROUP_CONCAT(IF(anchor_text = '{$pv_event}' AND utm_campaign <> '', {$bundle_inner}, NULL) ORDER BY created_at ASC SEPARATOR '###SEP###'), '###SEP###', 1) as pv_utm_bundle";
        $utm_distinct_sql = 'COUNT(DISTINCT CASE WHEN utm_campaign <> \'\' THEN utm_campaign END) as utm_distinct_campaigns';
        $first_utm_at_sql = 'MIN(CASE WHEN utm_campaign <> \'\' THEN created_at END) as first_utm_at';
        $last_utm_at_sql  = 'MAX(CASE WHEN utm_campaign <> \'\' THEN created_at END) as last_utm_at';
        $pv_utm_at_sql    = "MIN(CASE WHEN anchor_text = '{$pv_event}' AND utm_campaign <> '' THEN created_at END) as pv_utm_at";

        $sql = "SELECT session_id, country, city, MAX(os) as os_name, MAX(browser) as browser_name, MAX(device) as device_name, {$res_query}, MAX(language) as language, MIN(is_bot) as bot_status, MIN(created_at) as entry_time, GROUP_CONCAT(CONCAT(created_at, '|', origin_url, '|', anchor_text, '|', destination_url) ORDER BY created_at ASC SEPARATOR '||') as steps_raw, {$first_utm_bundle}, {$last_utm_bundle}, {$pv_utm_bundle}, {$utm_distinct_sql}, {$first_utm_at_sql}, {$last_utm_at_sql}, {$pv_utm_at_sql} FROM {$table_name} WHERE DATE(created_at) >= %s AND DATE(created_at) <= %s AND session_id IN ( SELECT DISTINCT session_id FROM {$table_name} WHERE utm_campaign != '' {$filter_sql_stats} AND DATE(created_at) >= %s AND DATE(created_at) <= %s ) GROUP BY session_id ORDER BY entry_time DESC LIMIT %d OFFSET %d";

        $results = $wpdb->get_results( $wpdb->prepare( $sql, $start_date, $end_date, $start_date, $end_date, $limit, $offset ) );

        return array(
            'rows'            => $results ? $results : array(),
            'camp_to_label'   => $camp_to_label,
            'has_utm_sm'      => $has_utm_sm,
        );
    }

    /**
     * @param object               $r DB aggregate row.
     * @param array<string,string> $camp_to_label
     * @param bool                 $has_utm_sm
     * @param string               $attr_mode     first|last|session
     * @return array<string, mixed>
     */
    public function oiscl_normalize_utm_journey_aggregate_row( $r, $camp_to_label, $has_utm_sm, $attr_mode = 'first' ) {
        $attr_mode   = $this->oiscl_sanitize_utm_attr_mode( $attr_mode );
        $attr_labels = $this->oiscl_utm_journey_attr_labels();

        $bundle  = '';
        $attr_at = '';
        switch ( $attr_mode ) {
            case 'last':
                $bundle  = isset( $r->last_utm_bundle ) ? (string) $r->last_utm_bundle : '';
                $attr_at = isset( $r->last_utm_at ) ? (string) $r->last_utm_at : '';
                break;
            case 'session':
                $bundle  = ! empty( $r->pv_utm_bundle ) ? (string) $r->pv_utm_bundle : ( isset( $r->first_utm_bundle ) ? (string) $r->first_utm_bundle : '' );
                $attr_at = ! empty( $r->pv_utm_at ) ? (string) $r->pv_utm_at : ( isset( $r->first_utm_at ) ? (string) $r->first_utm_at : '' );
                break;
            default:
                $bundle  = isset( $r->first_utm_bundle ) ? (string) $r->first_utm_bundle : '';
                $attr_at = isset( $r->first_utm_at ) ? (string) $r->first_utm_at : '';
                break;
        }

        $fields       = $this->oiscl_utm_parse_bundle_fields( $bundle, $has_utm_sm );
        $utm_campaign = $fields['utm_campaign'];
        $utm_term     = $fields['utm_term'];
        $utm_source   = $fields['utm_source'];
        $utm_medium   = $fields['utm_medium'];

        $label_name = isset( $camp_to_label[ $utm_campaign ] ) ? $camp_to_label[ $utm_campaign ] : '';

        $first_utm_at_display = '';
        if ( ! empty( $r->first_utm_at ) ) {
            $first_utm_at_display = date_i18n( 'Y-m-d H:i', strtotime( $r->first_utm_at ) );
        }
        $attr_utm_at_display = '';
        if ( '' !== $attr_at ) {
            $attr_utm_at_display = date_i18n( 'Y-m-d H:i', strtotime( $attr_at ) );
        }
        $attr_touch_label = isset( $attr_labels[ $attr_mode ] ) ? $attr_labels[ $attr_mode ] : $attr_labels['first'];

        $steps        = array();
        $raw_steps    = explode( '||', $r->steps_raw );
        $first_ts     = null;
        $last_ts      = null;
        $total_clicks = 0;

        foreach ( $raw_steps as $rs ) {
            $p = explode( '|', $rs );
            $ts = strtotime( $p[0] );
            if ( null === $first_ts ) {
                $first_ts = $ts;
            }
            $last_ts = $ts;
            $anchor  = isset( $p[2] ) ? $p[2] : '';

            if ( ! in_array( $anchor, array( '[Pageview]', '[Vista de Bloque]', '[Error 404]' ), true ) ) {
                $total_clicks++;
            }

            $steps[] = array(
                'time'   => date( 'H:i:s', $ts ),
                'url'    => $p[1],
                'anchor' => $anchor,
                'dest'   => isset( $p[3] ) ? $p[3] : '',
            );
        }

        $duration_sec = $last_ts - $first_ts;
        $duration_fmt = ( $duration_sec >= 60 ) ? round( $duration_sec / 60, 1 ) . 'm' : $duration_sec . 's';
        $date_fmt     = date( 'd/m/Y', strtotime( $r->entry_time ) );

        $dev_icon = stripos( $r->device_name, 'Mobile' ) !== false ? '📱' : ( stripos( $r->device_name, 'Tablet' ) !== false ? '💊' : '💻' );
        $os_icon  = stripos( $r->os_name, 'Windows' ) !== false ? '🪟' : ( stripos( $r->os_name, 'Mac' ) !== false || stripos( $r->os_name, 'iOS' ) !== false ? '🍎' : ( stripos( $r->os_name, 'Android' ) !== false ? '🤖' : '🐧' ) );
        $bro_icon = stripos( $r->browser_name, 'Chrome' ) !== false ? '🌐' : ( stripos( $r->browser_name, 'Safari' ) !== false ? '🧭' : ( stripos( $r->browser_name, 'Firefox' ) !== false ? '🦊' : '🌍' ) );

        $utm_distinct_campaigns = isset( $r->utm_distinct_campaigns ) ? (int) $r->utm_distinct_campaigns : 1;

        return array(
            'session_id'             => $r->session_id,
            'identity_label'         => $label_name ? $label_name : ( $utm_campaign ? $utm_campaign : '—' ),
            'utm_campaign'           => $utm_campaign,
            'utm_term'               => $utm_term,
            'utm_source'             => $utm_source,
            'utm_medium'             => $utm_medium,
            'first_utm_at_display'   => $first_utm_at_display,
            'attr_mode'              => $attr_mode,
            'attr_touch_label'       => $attr_touch_label,
            'attr_utm_at_display'    => $attr_utm_at_display,
            'utm_distinct_campaigns' => max( 1, $utm_distinct_campaigns ),
            'ip'                     => 'User ' . substr( $r->session_id, 0, 6 ),
            'time'                   => date( 'H:i:s', strtotime( $r->entry_time ) ),
            'date'                   => $date_fmt,
            'duration'               => $duration_fmt,
            'total_clicks'           => $total_clicks,
            'dev_icon'               => $dev_icon,
            'os_icon'                => $os_icon,
            'bro_icon'               => $bro_icon,
            'device_name'            => $r->device_name ? $r->device_name : 'Unknown',
            'os_name'                => $r->os_name ? $r->os_name : 'Unknown',
            'browser_name'           => $r->browser_name ? $r->browser_name : 'Unknown',
            'screen_res'             => $this->oiscl_format_journey_screen_res_display( isset( $r->screen_res ) ? $r->screen_res : null ),
            'lang'                   => strtoupper( $r->language ),
            'is_bot'                 => $r->bot_status,
            'steps'                  => $steps,
            'location'               => $r->city . ', ' . $r->country,
        );
    }

    /**
     * Sesiones cuyo recorrido incluye al menos un hit con UTM en el rango (y filtro de campaña opcional).
     * first-touch: primera fila con utm_campaign no vacío define campaign, term y label (vía referencias).
     *
     * @param string $start_date Y-m-d
     * @param string $end_date   Y-m-d
     * @param string $filter_sql_stats Fragmento SQL ya escapado (ej. " AND utm_campaign = 'x' "), igual que en UTM Tracker.
     * @param string|null $attr_mode   first|last|session (default: query param utm_attr).
     * @return array<int, array<string, mixed>>
     */
    public function get_oiscl_utm_journey_sessions( $start_date, $end_date, $filter_sql_stats = '', $attr_mode = null ) {
        if ( null === $attr_mode ) {
            $attr_mode = $this->oiscl_get_utm_journey_attribution_mode();
        } else {
            $attr_mode = $this->oiscl_sanitize_utm_attr_mode( $attr_mode );
        }
        $jlim     = $this->oiscl_get_journey_session_limit();
        $ctx      = $this->oiscl_fetch_utm_journey_batch_with_context( $start_date, $end_date, $filter_sql_stats, $jlim, 0 );
        $sessions = array();
        foreach ( $ctx['rows'] as $r ) {
            $sessions[] = $this->oiscl_normalize_utm_journey_aggregate_row( $r, $ctx['camp_to_label'], $ctx['has_utm_sm'], $attr_mode );
        }
        return $sessions;
    }

    /**
     * Filas para advanced_table "User Journey" (Analytics y UTM Tracker).
     *
     * @param array  $user_sessions Resultado de get_oiscl_user_sessions() o get_oiscl_utm_journey_sessions().
     * @param string $format        'analytics' (9 columnas) o 'utm' (Identity + campaign + term + source/medium + resto).
     * @return array<int, array<string, mixed>>
     */
    public function oiscl_build_journey_advanced_table_rows( $user_sessions, $format = 'analytics' ) {
        $journey_rows = array();
        if ( empty( $user_sessions ) || ! is_array( $user_sessions ) ) {
            return $journey_rows;
        }
        $is_utm = ( 'utm' === $format );
        foreach ( $user_sessions as $s ) {
            $paths = array_map(
                function ( $st ) {
                    return basename( parse_url( $st['url'], PHP_URL_PATH ) ?: 'Home' );
                },
                $s['steps']
            );
            $uniq_paths = array_unique( $paths );
            $route_str    = implode( ' ➔ ', array_slice( $uniq_paths, 0, 3 ) ) . ( count( $uniq_paths ) > 3 ? '...' : '' );
            $arrow_color  = ( 1 === (int) $s['is_bot'] ) ? '#d63638' : '#46b450';

            if ( $is_utm ) {
                $camp_disp = ! empty( $s['utm_campaign'] ) ? esc_html( $s['utm_campaign'] ) : '—';
                $term_disp = isset( $s['utm_term'] ) && '' !== $s['utm_term'] ? esc_html( $s['utm_term'] ) : '—';
                $src_disp  = isset( $s['utm_source'] ) && '' !== trim( (string) $s['utm_source'] ) ? esc_html( trim( (string) $s['utm_source'] ) ) : '—';
                $med_disp  = isset( $s['utm_medium'] ) && '' !== trim( (string) $s['utm_medium'] ) ? esc_html( trim( (string) $s['utm_medium'] ) ) : '—';
                $touch_lbl = ! empty( $s['attr_touch_label'] ) ? (string) $s['attr_touch_label'] : __( 'First touch', 'ois-conversion-suite' );
                $touch_at  = ! empty( $s['attr_utm_at_display'] ) ? (string) $s['attr_utm_at_display'] : ( ! empty( $s['first_utm_at_display'] ) ? (string) $s['first_utm_at_display'] : '' );
                $ft_note   = '' !== $touch_at
                    ? ' <span style="color:#64748b;">·</span> <span style="font-size:11px; color:#0369a1; font-weight:600;">' . esc_html( $touch_lbl ) . '</span> <code style="background:#fff; padding:2px 6px; font-size:11px;">' . esc_html( $touch_at ) . '</code>'
                    : '';
                $traffic   = "<span style='font-size:12px; line-height:1.55;'>";
                $traffic  .= "<code style='background:#fff; padding:2px 6px;'>{$camp_disp}</code> <span style='color:#64748b;'>· term:</span> <code style='background:#fff; padding:2px 6px;'>{$term_disp}</code>";
                $traffic  .= "<br><span style='color:#64748b;'>utm_source</span> <code style='background:#fff; padding:2px 6px;'>{$src_disp}</code> <span style='color:#64748b;'>· utm_medium</span> <code style='background:#fff; padding:2px 6px;'>{$med_disp}</code>";
                $traffic  .= $ft_note . '</span>';
            } else {
                $traffic = "<code style='background:#fff; padding:2px 6px;'>Direct / Unknown</code>";
            }

            $multi_note = '';
            if ( $is_utm && isset( $s['utm_distinct_campaigns'] ) && (int) $s['utm_distinct_campaigns'] > 1 ) {
                $n_multi  = (int) $s['utm_distinct_campaigns'];
                $mode_lbl = ! empty( $s['attr_touch_label'] ) ? (string) $s['attr_touch_label'] : __( 'First touch', 'ois-conversion-suite' );
                $multi_note = '<div style="margin-bottom:12px; padding:10px 12px; background:#fef3c7; border-radius:6px; font-size:12px; color:#92400e;">' . esc_html(
                    sprintf(
                        /* translators: 1: number of campaigns, 2: attribution mode label */
                        __( 'Multi-campaign: this session recorded %1$d different utm_campaign values in the date range. Columns use %2$s attribution.', 'ois-conversion-suite' ),
                        $n_multi,
                        $mode_lbl
                    )
                ) . '</div>';
            }

            $details = "<div style='padding:20px; border-left:4px solid {$arrow_color};'>{$multi_note}<div style='display:flex; justify-content:space-between; background:#e2e8f0; padding:15px; border-radius:4px; margin-bottom:15px;'><div><strong>📥 Origen (Tráfico):</strong> {$traffic}</div><div><strong>📤 Última Acción (Salida):</strong> <code style='background:#fff; padding:2px 6px; color:#d63638;'>" . esc_html( end( $paths ) ) . "</code></div></div><div style='display:flex; justify-content:space-between; margin-bottom:15px;'><h4 style='margin:0;'>Activity Log:</h4><button class='button' disabled>Ver Heatmap de Sesión 🎨</button></div>";
            foreach ( $s['steps'] as $step ) {
                $is_click = ! in_array( $step['anchor'], array( '[Pageview]', '[Vista de Bloque]', '[Bloque]', 'Reading', '[Error 404]' ), true );
                $badge    = $is_click ? "<span style='background:#f56e28; color:#fff; padding:2px 6px; border-radius:3px; font-size:10px;'>Click</span>" : "<span style='background:#eee; padding:2px 6px; border-radius:3px; font-size:10px;'>View</span>";
                $details .= "<div style='padding:8px; background:#fff; border:1px solid #e2e8f0; border-radius:4px; margin-bottom:5px; display:flex; justify-content:space-between; align-items:center;'><div><strong style='margin-right:10px; color:#666;'>{$step['time']}</strong> <code>" . esc_html( basename( $step['url'] ) ) . '</code> ' . ( $is_click ? '➔ <b>' . esc_html( $step['anchor'] ) . '</b>' : '' ) . "</div>{$badge}</div>";
            }
            $details .= '</div>';

            $multi_badge = '';
            if ( $is_utm && isset( $s['utm_distinct_campaigns'] ) && (int) $s['utm_distinct_campaigns'] > 1 ) {
                $n_badge = (int) $s['utm_distinct_campaigns'];
                $tip     = esc_attr(
                    sprintf(
                        /* translators: 1: number of campaigns, 2: attribution mode */
                        __( 'This session had %1$d different utm_campaign values in the range. Table columns use %2$s attribution.', 'ois-conversion-suite' ),
                        $n_badge,
                        ! empty( $s['attr_touch_label'] ) ? (string) $s['attr_touch_label'] : __( 'First touch', 'ois-conversion-suite' )
                    )
                );
                $multi_badge = ' <span style="font-size:10px; background:#fef3c7; color:#92400e; padding:2px 7px; border-radius:4px; font-weight:600; margin-left:6px; white-space:nowrap;" title="' . $tip . '">' . esc_html( sprintf( /* translators: %d: count */ __( 'Multi · %d', 'ois-conversion-suite' ), $n_badge ) ) . '</span>';
            }

            $sid_full = isset( $s['session_id'] ) ? (string) $s['session_id'] : '';
            $sub_id   = '';
            if ( $is_utm && '' !== $sid_full ) {
                $sub_id  = '<div style="margin-top:6px; font-size:11px; color:#64748b; line-height:1.45;">';
                $sub_id .= '<span style="cursor:help;border-bottom:1px dotted #cbd5e1;" title="' . esc_attr( sprintf( __( 'Session id: %s', 'ois-conversion-suite' ), $sid_full ) ) . '">';
                $sub_id .= esc_html( 'User ' . substr( $sid_full, 0, 6 ) ) . '</span>';
                if ( ! empty( $s['attr_utm_at_display'] ) || ! empty( $s['first_utm_at_display'] ) ) {
                    $sub_touch = ! empty( $s['attr_touch_label'] ) ? (string) $s['attr_touch_label'] : __( 'First touch', 'ois-conversion-suite' );
                    $sub_at    = ! empty( $s['attr_utm_at_display'] ) ? (string) $s['attr_utm_at_display'] : (string) $s['first_utm_at_display'];
                    $sub_id .= ' <span style="color:#cbd5e1;">·</span> <span style="font-size:9px; font-weight:700; letter-spacing:0.04em; text-transform:uppercase; color:#0369a1;">' . esc_html( $sub_touch ) . '</span> ';
                    $sub_id .= '<span title="' . esc_attr__( 'Time of the attributed UTM hit for the selected model.', 'ois-conversion-suite' ) . '">' . esc_html( $sub_at ) . '</span>';
                }
                $sub_id .= '</div>';
            }

            $identity_cell = $is_utm
                ? "<div><div style='display:flex; flex-wrap:wrap; align-items:center; gap:2px 6px;'><span style='color:{$arrow_color}; font-size:12px; display:inline-block; transition:0.3s; margin-right:4px;' class='j-arrow'>▶</span><strong>" . esc_html( isset( $s['identity_label'] ) ? $s['identity_label'] : $s['ip'] ) . '</strong>' . $multi_badge . '</div>' . $sub_id . '</div>'
                : "<span style='color:{$arrow_color}; font-size:12px; display:inline-block; transition:0.3s; margin-right:8px;' class='j-arrow'>▶</span> <strong>{$s['ip']}</strong>";

            $cols_tail = array(
                $s['date'],
                $s['time'],
                "<span style='font-size:13px; color:#1a73e8; font-weight:600;'>{$route_str}</span>",
                "<b>{$s['duration']}</b>",
                '<b style="color:' . ( $is_utm ? '#1a73e8' : '#f56e28' ) . ';">' . esc_html( (string) $s['total_clicks'] ) . '</b>',
                "📍 {$s['location']}",
                "<code style='font-size:10px;'>" . esc_html( (string) $s['screen_res'] ) . '</code>',
                "<span title='Device: {$s['device_name']}'>{$s['dev_icon']}</span> <span title='OS: {$s['os_name']}'>{$s['os_icon']}</span> <span title='Browser: {$s['browser_name']}'>{$s['bro_icon']}</span> <code style='font-size:9px; margin-left:3px;'>{$s['lang']}</code>",
            );

            if ( $is_utm ) {
                $camp_cell = '<code style="font-size:11px;">' . esc_html( ! empty( $s['utm_campaign'] ) ? $s['utm_campaign'] : '—' ) . '</code>';
                $term_cell = '<code style="font-size:11px;">' . esc_html( isset( $s['utm_term'] ) && '' !== $s['utm_term'] ? $s['utm_term'] : '—' ) . '</code>';
                $us        = isset( $s['utm_source'] ) ? trim( (string) $s['utm_source'] ) : '';
                $um        = isset( $s['utm_medium'] ) ? trim( (string) $s['utm_medium'] ) : '';
                $sm_cell   = ( '' === $us && '' === $um )
                    ? '<span style="color:#94a3b8;">—</span>'
                    : '<code style="font-size:10px;">' . esc_html( $us ) . '</code> <span style="color:#94a3b8;">/</span> <code style="font-size:10px;">' . esc_html( $um ) . '</code>';
                $cols      = array_merge( array( $identity_cell, $camp_cell, $term_cell, $sm_cell ), $cols_tail );
            } else {
                $cols = array_merge( array( $identity_cell ), $cols_tail );
            }

            $journey_rows[] = array(
                'class'        => 'ois-row-accordion',
                'details_html' => $details,
                'cols'         => $cols,
            );
        }
        return $journey_rows;
    }

}
