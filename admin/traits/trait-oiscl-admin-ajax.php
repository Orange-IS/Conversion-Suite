<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait OISCL_Admin_Ajax_Trait {

    // --- ACCIONES AJAX AUXILIARES (SE MANTIENEN INTACTAS) ---
    public function ajax_save_page_tags() {
        check_ajax_referer( 'oiscl_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error();
        }
        $post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
        $raw     = isset( $_POST['tags'] ) ? wp_unslash( $_POST['tags'] ) : '';
        $payload = json_decode( $raw, true );
        if ( ! is_array( $payload ) || $post_id <= 0 ) {
            wp_send_json_error();
        }
        $config = OISCL_Tracking::get_page_config( $post_id );
        if ( ! $config || ! is_array( $config ) ) {
            $config = array(
                'instances' => array(),
                'version'   => OISCL_Tracking::CONFIG_VERSION,
            );
        }
        if ( isset( $payload['tracking_mode'] ) ) {
            $mode = sanitize_key( $payload['tracking_mode'] );
            if ( 'automatic' === $mode && ! OISCL_Tracking::is_automatic_global_enabled() ) {
                wp_send_json_error( array( 'message' => 'automatic_global_disabled' ) );
            }
            $config['tracking_mode'] = ( 'automatic' === $mode ) ? 'automatic' : 'custom';
        }
        if ( array_key_exists( 'auto_tags', $payload ) ) {
            $config['auto_tags'] = sanitize_text_field( (string) $payload['auto_tags'] );
        }
        if ( ! empty( $payload['instances'] ) && is_array( $payload['instances'] ) && ! empty( $config['instances'] ) ) {
            $flags = array();
            foreach ( $payload['instances'] as $row ) {
                if ( ! empty( $row['id'] ) ) {
                    $flags[ $row['id'] ] = $row;
                }
            }
            foreach ( $config['instances'] as $idx => $inst ) {
                $id = isset( $inst['id'] ) ? $inst['id'] : '';
                if ( ! $id || ! isset( $flags[ $id ] ) ) {
                    continue;
                }
                $row = $flags[ $id ];
                if ( array_key_exists( 'track_view', $row ) ) {
                    $config['instances'][ $idx ]['track_view'] = ! empty( $row['track_view'] );
                }
                if ( array_key_exists( 'track_click', $row ) ) {
                    $config['instances'][ $idx ]['track_click'] = ! empty( $row['track_click'] );
                }
                if ( array_key_exists( 'custom_label', $row ) ) {
                    $label = trim( sanitize_text_field( (string) $row['custom_label'] ) );
                    if ( '' === $label ) {
                        unset( $config['instances'][ $idx ]['custom_label'] );
                    } else {
                        $config['instances'][ $idx ]['custom_label'] = $label;
                    }
                }
            }
        }
        OISCL_Tracking::save_page_config( $post_id, $config );
        OISCL_Activity::sync_page_config_state( $post_id );
        $settings    = get_option( 'oiscl_settings', array() );
        $target_urls = isset( $settings['target_urls'] ) && is_array( $settings['target_urls'] ) ? $settings['target_urls'] : array();
        wp_send_json_success(
            array(
                'tracking_state' => OISCL_Activity::get_tracking_state( $post_id, $target_urls ),
            )
        );
    }

    public function ajax_save_automatic_global() {
        check_ajax_referer( 'oiscl_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error();
        }
        $settings = get_option( 'oiscl_settings', array() );
        if ( ! is_array( $settings ) ) {
            $settings = array();
        }
        $prev_enabled = ! empty( $settings['trackpro_enabled'] );
        if ( isset( $_POST['enabled'] ) ) {
            $settings['trackpro_enabled'] = (int) $_POST['enabled'] ? 1 : 0;
        }
        if ( isset( $_POST['separator_tags'] ) ) {
            $tags_raw = sanitize_text_field( wp_unslash( $_POST['separator_tags'] ) );
            $tags     = array_filter( array_map( 'trim', explode( ',', $tags_raw ) ) );
            $settings['separator_tags'] = ! empty( $tags ) ? $tags : array( 'h2', 'h3', 'section', 'article' );
        }
        if ( isset( $_POST['activity_pause_on_global_off'] ) ) {
            $settings['activity_pause_on_global_off'] = (int) $_POST['activity_pause_on_global_off'] ? 1 : 0;
        }
        $target_urls = isset( $settings['target_urls'] ) && is_array( $settings['target_urls'] ) ? $settings['target_urls'] : array();
        if ( isset( $_POST['enabled'] ) && (int) $_POST['enabled'] !== ( $prev_enabled ? 1 : 0 ) ) {
            OISCL_Activity::sync_global_toggle( ! empty( $settings['trackpro_enabled'] ), $target_urls );
        }
        update_option( 'oiscl_settings', $settings );
        wp_send_json_success(
            array(
                'enabled' => ! empty( $settings['trackpro_enabled'] ),
                'tags'    => OISCL_Tracking::get_global_automatic_tags(),
            )
        );
    }

    public function ajax_save_target_pages() {
        check_ajax_referer( 'oiscl_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'forbidden' ) );
        }
        $raw     = isset( $_POST['target_urls'] ) ? wp_unslash( $_POST['target_urls'] ) : '[]';
        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) {
            wp_send_json_error( array( 'message' => 'invalid_payload' ) );
        }
        $out = array();
        $max = OISCL_Plan::get_page_slot_limit();
        foreach ( $decoded as $item ) {
            if ( count( $out ) >= $max ) {
                break;
            }
            $s = is_scalar( $item ) ? (string) $item : '';
            if ( $s !== '' && ctype_digit( $s ) ) {
                $pid = (int) $s;
                if ( $pid > 0 ) {
                    $out[] = (string) $pid;
                }
            }
        }
        $settings = get_option( 'oiscl_settings', array() );
        if ( ! is_array( $settings ) ) {
            $settings = array();
        }
        $old_urls = isset( $settings['target_urls'] ) && is_array( $settings['target_urls'] ) ? $settings['target_urls'] : array();
        $settings['target_urls'] = array_values( array_unique( $out ) );
        OISCL_Activity::sync_slots_change( $old_urls, $settings['target_urls'] );
        update_option( 'oiscl_settings', $settings );
        wp_send_json_success( array( 'saved' => $settings['target_urls'] ) );
    }

    /**
     * 60-minute pulse buckets (clicks + pageview views/uniques vs yesterday).
     *
     * @param int    $offset            Hour offset for Prev/Next navigation.
     * @param string $filter_sql_stats  UTM filter SQL fragment (empty = global).
     * @param bool   $require_utm       When true, only rows with non-empty utm_campaign.
     * @return array
     */
    private function oiscl_build_pulse_60m_payload( $offset = 0, $filter_sql_stats = '', $require_utm = false ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'oiscl_block_metrics';
        $offset     = (int) $offset;
        $now_ts     = strtotime( current_time( 'mysql' ) ) + ( $offset * 3600 );
        $one_hour_ago = date( 'Y-m-d H:i:s', $now_ts - 3600 );
        $now_str    = date( 'Y-m-d H:i:s', $now_ts );
        $yest_now_ts = $now_ts - 86400;
        $yest_hour_ago = date( 'Y-m-d H:i:s', $yest_now_ts - 3600 );
        $yest_now_str  = date( 'Y-m-d H:i:s', $yest_now_ts );

        $utm_clause = '';
        if ( $require_utm || $filter_sql_stats !== '' ) {
            $utm_clause = " AND utm_campaign != '' {$filter_sql_stats}";
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name + internal filter fragment.
        $clicks = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT created_at FROM {$table_name} WHERE created_at BETWEEN %s AND %s AND anchor_text NOT IN ('[Pageview]', '[Vista de Bloque]'){$utm_clause}",
                $one_hour_ago,
                $now_str
            )
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $v_today = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT created_at, session_id FROM {$table_name} WHERE anchor_text='[Pageview]' AND created_at BETWEEN %s AND %s{$utm_clause}",
                $one_hour_ago,
                $now_str
            )
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $v_yest = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT created_at, session_id FROM {$table_name} WHERE anchor_text='[Pageview]' AND created_at BETWEEN %s AND %s{$utm_clause}",
                $yest_hour_ago,
                $yest_now_str
            )
        );

        $arr_clicks = array_fill( 0, 60, 0 );
        $arr_v_tod  = array_fill( 0, 60, 0 );
        $arr_u_tod  = array_fill( 0, 60, 0 );
        $arr_v_yes  = array_fill( 0, 60, 0 );
        $arr_u_yes  = array_fill( 0, 60, 0 );
        $u_raw_tod  = array_fill( 0, 60, array() );
        $u_raw_yes  = array_fill( 0, 60, array() );
        $labels     = array();
        for ( $i = 59; $i >= 0; $i-- ) {
            $labels[] = date( 'H:i', $now_ts - ( $i * 60 ) );
        }
        foreach ( $clicks as $c ) {
            $diff = floor( ( $now_ts - strtotime( $c->created_at ) ) / 60 );
            if ( $diff >= 0 && $diff < 60 ) {
                $arr_clicks[ 59 - $diff ]++;
            }
        }
        foreach ( $v_today as $v ) {
            $diff = floor( ( $now_ts - strtotime( $v->created_at ) ) / 60 );
            if ( $diff >= 0 && $diff < 60 ) {
                $arr_v_tod[ 59 - $diff ]++;
                $u_raw_tod[ 59 - $diff ][] = $v->session_id;
            }
        }
        foreach ( $v_yest as $v ) {
            $diff = floor( ( $yest_now_ts - strtotime( $v->created_at ) ) / 60 );
            if ( $diff >= 0 && $diff < 60 ) {
                $arr_v_yes[ 59 - $diff ]++;
                $u_raw_yes[ 59 - $diff ][] = $v->session_id;
            }
        }
        for ( $i = 0; $i < 60; $i++ ) {
            $arr_u_tod[ $i ] = count( array_unique( $u_raw_tod[ $i ] ) );
            $arr_u_yes[ $i ] = count( array_unique( $u_raw_yes[ $i ] ) );
        }
        $since_online = date( 'Y-m-d H:i:s', $now_ts - 300 );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $online_users = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT session_id) FROM {$table_name} WHERE created_at >= %s{$utm_clause}",
                $since_online
            )
        );

        return array(
            'labels'       => $labels,
            'clicks'       => $arr_clicks,
            'v_today'      => $arr_v_tod,
            'u_today'      => $arr_u_tod,
            'v_yest'       => $arr_v_yes,
            'u_yest'       => $arr_u_yes,
            'total_clicks' => array_sum( $arr_clicks ),
            'total_views'  => array_sum( $arr_v_tod ),
            'online_users' => $online_users,
        );
    }

    public function ajax_get_pulse_data() {
        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'oiscl_admin_nonce' ) && ! wp_verify_nonce( $nonce, 'oiscl_track_nonce' ) ) {
            wp_send_json_error( array( 'message' => 'invalid_nonce' ) );
        }
        if ( ! current_user_can( 'view_ois_analytics' ) ) {
            wp_send_json_error( array( 'message' => 'forbidden' ) );
        }
        $offset = isset( $_POST['offset'] ) ? (int) $_POST['offset'] : 0;
        $scope  = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : 'global';
        $filter_sql_stats = '';
        $require_utm      = false;
        if ( 'utm' === $scope && method_exists( $this, 'get_oiscl_utm_dashboard_filters' ) ) {
            $require_utm = true;
            $utm_filter  = isset( $_POST['utm_filter'] ) ? sanitize_text_field( wp_unslash( $_POST['utm_filter'] ) ) : 'all';
            $filters     = $this->get_oiscl_utm_dashboard_filters( $utm_filter );
            $filter_sql_stats = $filters['filter_sql_stats'];
        }
        wp_send_json_success( $this->oiscl_build_pulse_60m_payload( $offset, $filter_sql_stats, $require_utm ) );
    }
    public function ajax_scan_page_html() {
        check_ajax_referer('oiscl_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error( __( 'Insufficient permissions.', 'ois-conversion-suite' ) );
        }
        $post_id = intval($_POST['post_id']);
        if (!$post_id) {
            wp_send_json_error( __( 'Invalid post ID.', 'ois-conversion-suite' ) );
        }
        $url = get_permalink($post_id);
        $response = wp_remote_get($url, array('timeout' => 15));
        if (is_wp_error($response)) wp_send_json_error($response->get_error_message());
        $html = wp_remote_retrieve_body($response);
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xpath = new DOMXPath($dom);
        $data = array('h1' => $xpath->query('//h1')->length, 'h2' => $xpath->query('//h2')->length, 'h3' => $xpath->query('//h3')->length, 'text' => $xpath->query('//p')->length, 'media' => $xpath->query('//img | //video')->length);
        $interactive = array();
        $links = $xpath->query('//a | //button');
        foreach ($links as $link) {
            $text = trim($link->nodeValue);
            if (empty($text)) continue;
            $type = strtolower($link->tagName);
            $dest = ($type == 'a') ? $link->getAttribute('href') : '';
            $interactive[] = array('text' => substr($text, 0, 30), 'type' => $type, 'dest' => $dest ?: 'Action');
        }
        $tree = array();
        $nodes = $xpath->query('//h1 | //h2 | //a | //button');
        foreach ($nodes as $node) {
            $tag = strtoupper($node->tagName);
            $text = trim($node->nodeValue);
            if (empty($text)) {
                continue;
            }
            $tag_label = ($tag === 'A') ? 'Link' : ($tag === 'BUTTON' ? 'Button' : $tag);
            $tree[] = $tag_label . ': ' . substr($text, 0, 40);
        }
        $focus_kw = isset($_POST['focus_key']) ? sanitize_text_field($_POST['focus_key']) : '';
        $is_deduced = false;
        if (empty($focus_kw)) {
            $h1_node = $xpath->query('//h1')->item(0);
            if ($h1_node) {
                $title_words = explode(' ', strtolower(trim($h1_node->nodeValue)));
                $stop_words = array('el', 'la', 'los', 'las', 'un', 'una', 'en', 'de', 'para', 'por', 'con', 'y', 'o', 'a', 'tu', 'te');
                $keywords = array_diff($title_words, $stop_words);
                $focus_kw = implode(' ', array_slice($keywords, 0, 3));
                $is_deduced = true;
            } else {
                $focus_kw = 'General';
            }
        }
        $plain_text = strtolower(strip_tags($html));
        $kw_count = substr_count($plain_text, strtolower($focus_kw));
        $words_total = str_word_count($plain_text);
        $density = ($words_total > 0) ? round(($kw_count / $words_total) * 100, 2) : 0;
        
        $broken_links = 0;
        $checklist = array();
        
        if ($data['h1'] == 1) { $checklist[] = array('msg' => 'H1 Único detectado.', 'status' => 'pass'); $seo_score = 20; }
        elseif ($data['h1'] == 0) { $checklist[] = array('msg' => 'No hay etiqueta H1.', 'status' => 'fail'); $seo_score = 0; }
        else { $checklist[] = array('msg' => 'Múltiples H1 detectados (Mala práctica).', 'status' => 'warning'); $seo_score = 10; }
        
        if ($density >= 1 && $density <= 3) { $checklist[] = array('msg' => "Densidad de KW ideal ($density%).", 'status' => 'pass'); $seo_score += 30; }
        elseif ($density > 3) { $checklist[] = array('msg' => "Posible Keyword Stuffing ($density%).", 'status' => 'warning'); $seo_score += 15; }
        else { $checklist[] = array('msg' => "Densidad de KW muy baja ($density%).", 'status' => 'fail'); $seo_score += 5; }
        
        if ($data['text'] > 3) { $checklist[] = array('msg' => 'Cantidad de texto adecuada.', 'status' => 'pass'); $seo_score += 20; }
        else { $checklist[] = array('msg' => 'Poco texto (Thin Content).', 'status' => 'fail'); $seo_score += 5; }
        
        $imgs = $xpath->query('//img');
        $alt_missing = 0;
        foreach ($imgs as $img) { if (!$img->hasAttribute('alt') || empty($img->getAttribute('alt'))) { $alt_missing++; } }
        if ($alt_missing == 0 && $imgs->length > 0) { $checklist[] = array('msg' => 'Todas las imágenes tienen atributo ALT.', 'status' => 'pass'); $seo_score += 15; }
        elseif ($imgs->length == 0) { $checklist[] = array('msg' => 'No hay imágenes en el contenido.', 'status' => 'warning'); $seo_score += 5; }
        else { $checklist[] = array('msg' => "Faltan $alt_missing atributos ALT en imágenes.", 'status' => 'fail'); $seo_score += 5; }
        
        $meta_desc = $xpath->query('//meta[@name="description"]/@content');
        if ($meta_desc->length > 0 && !empty($meta_desc->item(0)->nodeValue)) { $checklist[] = array('msg' => 'Meta Descripción detectada.', 'status' => 'pass'); $seo_score += 15; }
        else { $checklist[] = array('msg' => 'Falta Meta Descripción.', 'status' => 'fail'); $seo_score += 0; }
        
        $load_time = round(timer_stop(), 2);
        
        $audit_data = array('dom' => $data, 'interactive' => $interactive, 'tree' => $tree, 'focus_kw' => $focus_kw, 'is_deduced' => $is_deduced, 'density' => $density, 'words_total' => $words_total, 'seo_score' => $seo_score, 'checklist' => $checklist, 'broken_links' => $broken_links, 'load_time' => $load_time, 'last_scan' => current_time('mysql'));
        update_post_meta($post_id, '_oiscl_seo_audit', $audit_data);

        $tracking_config = OISCL_Tracking::build_instances_from_xpath( $xpath );
        $tracking_config['instances'] = OISCL_Tracking::augment_instances_from_page( $html, $post_id, $tracking_config['instances'] );
        $prev_config       = OISCL_Tracking::get_page_config( $post_id );
        $prev_instances    = ( $prev_config && ! empty( $prev_config['instances'] ) && is_array( $prev_config['instances'] ) ) ? $prev_config['instances'] : array();
        $merged_instances  = OISCL_Tracking::merge_scan_preferences( $tracking_config['instances'], $prev_instances );
        $review_payload    = OISCL_Tracking::build_rescan_review_payload( $prev_instances, $merged_instances );
        $requires_review   = ! empty( $prev_instances ) && OISCL_Tracking::has_structural_diff( $review_payload );

        $stored = is_array( $prev_config ) ? $prev_config : array(
            'instances' => array(),
            'version'   => OISCL_Tracking::CONFIG_VERSION,
        );
        if ( ! empty( $prev_config['tracking_mode'] ) ) {
            $tracking_config['tracking_mode'] = $prev_config['tracking_mode'];
        }
        if ( ! empty( $prev_config['auto_tags'] ) ) {
            $tracking_config['auto_tags'] = $prev_config['auto_tags'];
        }
        OISCL_Tracking::ensure_config_meta( $stored );

        if ( $requires_review ) {
            $stored['pending_rescan'] = array(
                'scanned_at' => isset( $tracking_config['scanned_at'] ) ? $tracking_config['scanned_at'] : current_time( 'mysql' ),
                'dom_hash'   => isset( $tracking_config['dom_hash'] ) ? $tracking_config['dom_hash'] : '',
                'instances'  => $merged_instances,
                'diff'       => $review_payload,
            );
            OISCL_Tracking::save_page_config( $post_id, $stored );
            $review_html = $this->oiscl_render_rescan_review_html( $post_id, $stored['pending_rescan'] );
            wp_send_json_success(
                array(
                    'message'          => __( 'Structural changes detected — review before applying.', 'ois-conversion-suite' ),
                    'requires_review'  => true,
                    'review_html'      => $review_html,
                    'diff'             => $review_payload,
                    'config_revision'  => OISCL_Tracking::get_config_revision( $stored ),
                    'scanned_at'       => $stored['pending_rescan']['scanned_at'],
                    'seo_score'        => $seo_score,
                    'load_time'        => $load_time,
                )
            );
        }

        $tracking_config['instances'] = $merged_instances;
        if ( empty( $prev_instances ) ) {
            $tracking_config['config_revision'] = 1;
            $tracking_config['version_history'] = isset( $stored['version_history'] ) ? $stored['version_history'] : array();
            $tracking_config['revision_started_at'] = isset( $tracking_config['scanned_at'] ) ? $tracking_config['scanned_at'] : current_time( 'mysql' );
        } else {
            $tracking_config['config_revision'] = OISCL_Tracking::get_config_revision( $stored );
            $tracking_config['version_history'] = isset( $stored['version_history'] ) ? $stored['version_history'] : array();
            if ( ! empty( $stored['revision_started_at'] ) ) {
                $tracking_config['revision_started_at'] = $stored['revision_started_at'];
            }
        }
        unset( $tracking_config['pending_rescan'] );
        OISCL_Tracking::save_page_config( $post_id, $tracking_config );
        OISCL_Activity::sync_page_config_state( $post_id );

        $accordion_html = $this->oiscl_get_accordion_panel_html( $post_id );

        wp_send_json_success(
            array(
                'message'         => __( 'Scan complete.', 'ois-conversion-suite' ),
                'requires_review' => false,
                'accordion_html'  => $accordion_html,
                'diff'            => $review_payload,
                'config_revision' => OISCL_Tracking::get_config_revision( $tracking_config ),
                'scanned_at'      => isset( $tracking_config['scanned_at'] ) ? $tracking_config['scanned_at'] : '',
                'seo_score'       => $seo_score,
                'load_time'       => $load_time,
            )
        );
    }

    public function ajax_apply_rescan_review() {
        check_ajax_referer( 'oiscl_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'ois-conversion-suite' ) ) );
        }
        $post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
        $action  = isset( $_POST['review_action'] ) ? sanitize_key( wp_unslash( $_POST['review_action'] ) ) : '';
        if ( $post_id <= 0 || ! in_array( $action, array( 'apply', 'discard' ), true ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid request.', 'ois-conversion-suite' ) ) );
        }
        $config = OISCL_Tracking::get_page_config( $post_id );
        if ( ! $config || empty( $config['pending_rescan'] ) ) {
            wp_send_json_error( array( 'message' => __( 'No pending scan to review.', 'ois-conversion-suite' ) ) );
        }
        if ( 'discard' === $action ) {
            OISCL_Tracking::discard_pending_rescan( $config );
            OISCL_Tracking::save_page_config( $post_id, $config );
            wp_send_json_success(
                array(
                    'message'        => __( 'Scan discarded. Previous configuration kept.', 'ois-conversion-suite' ),
                    'accordion_html' => $this->oiscl_get_accordion_panel_html( $post_id ),
                    'config_revision'=> OISCL_Tracking::get_config_revision( $config ),
                )
            );
        }
        $track_raw = isset( $_POST['track_added'] ) ? wp_unslash( $_POST['track_added'] ) : '{}';
        $track_map = json_decode( $track_raw, true );
        if ( ! is_array( $track_map ) ) {
            $track_map = array();
        }
        $track_added = array();
        foreach ( $track_map as $id => $flag ) {
            $track_added[ sanitize_key( $id ) ] = ! empty( $flag );
        }
        if ( ! OISCL_Tracking::apply_pending_rescan( $config, $track_added ) ) {
            wp_send_json_error( array( 'message' => __( 'Could not apply scan.', 'ois-conversion-suite' ) ) );
        }
        OISCL_Tracking::save_page_config( $post_id, $config );
        OISCL_Activity::sync_page_config_state( $post_id );
        wp_send_json_success(
            array(
                'message'         => __( 'New configuration version saved.', 'ois-conversion-suite' ),
                'accordion_html'  => $this->oiscl_get_accordion_panel_html( $post_id ),
                'config_revision' => OISCL_Tracking::get_config_revision( $config ),
                'tracking_state'  => OISCL_Activity::get_tracking_state( $post_id ),
            )
        );
    }
    public function save_general_settings() { check_admin_referer('oiscl_general_nonce', 'oiscl_general_nonce'); if (!current_user_can('manage_options')) wp_die('No tienes permisos.'); $options = array('api_key' => sanitize_text_field($_POST['api_key']), 'rep_clicks' => isset($_POST['rep_clicks']) ? 1 : 0, 'rep_reads' => isset($_POST['rep_reads']) ? 1 : 0, 'rep_format' => sanitize_text_field($_POST['rep_format'])); update_option('oiscl_general_settings', $options); wp_redirect(admin_url('admin.php?page=oiscl-settings&tab=basic&updated=true')); exit; }

    public function save_uninstall_preference() {
        if ( ! isset( $_POST['oiscl_save_uninstall_pref_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['oiscl_save_uninstall_pref_nonce'] ) ), 'oiscl_save_uninstall_pref' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'ois-conversion-suite' ) );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'ois-conversion-suite' ) );
        }
        $value = ( isset( $_POST['oiscl_delete_on_uninstall'] ) && '1' === $_POST['oiscl_delete_on_uninstall'] ) ? '1' : '0';
        update_option( 'oiscl_delete_data_on_uninstall', $value );
        wp_safe_redirect( admin_url( 'admin.php?page=oiscl-settings&tab=maintenance&oiscl_uninstall_pref=saved' ) );
        exit;
    }
    public function ajax_handle_export() {
        check_ajax_referer( 'oiscl_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'ois-conversion-suite' ), '', array( 'response' => 403 ) );
        }

        @ini_set( 'memory_limit', '512M' );
        @set_time_limit( 0 );

        if ( ! class_exists( 'ZipArchive' ) ) {
            wp_die( esc_html__( 'The PHP zip extension (ZipArchive) is required to export backups. Ask your host to enable php-zip.', 'ois-conversion-suite' ) );
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'oiscl_block_metrics';

        $scope = isset( $_REQUEST['oiscl_export_scope'] ) ? sanitize_key( wp_unslash( $_REQUEST['oiscl_export_scope'] ) ) : 'all';
        if ( ! in_array( $scope, array( 'all', 'range' ), true ) ) {
            $scope = 'all';
        }

        $metrics_export = array( 'scope' => $scope );
        $range_start    = '';
        $range_end      = '';
        if ( 'range' === $scope ) {
            $range_start = isset( $_REQUEST['oiscl_export_start'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['oiscl_export_start'] ) ) : '';
            $range_end   = isset( $_REQUEST['oiscl_export_end'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['oiscl_export_end'] ) ) : '';
            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $range_start ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $range_end ) ) {
                wp_die( esc_html__( 'Invalid export dates. Use YYYY-MM-DD.', 'ois-conversion-suite' ), '', array( 'response' => 400 ) );
            }
            if ( strcmp( $range_start, $range_end ) > 0 ) {
                wp_die( esc_html__( 'Export start date must be before or equal to end date.', 'ois-conversion-suite' ), '', array( 'response' => 400 ) );
            }
            $metrics_export['start_date'] = $range_start;
            $metrics_export['end_date']   = $range_end;
        }

        $manifest = $this->oiscl_backup_build_manifest_payload( $metrics_export );

        $tmp_jsonl = wp_tempnam( 'oiscl-metrics-' );
        if ( ! $tmp_jsonl ) {
            wp_die( esc_html__( 'Could not create a temporary file for export.', 'ois-conversion-suite' ) );
        }

        $jh = fopen( $tmp_jsonl, 'wb' );
        if ( ! $jh ) {
            wp_delete_file( $tmp_jsonl );
            wp_die( esc_html__( 'Could not open temporary file for writing.', 'ois-conversion-suite' ) );
        }

        $last_id = 0;
        $batch   = 3000;
        while ( true ) {
            if ( 'range' === $scope ) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT * FROM `{$table_name}` WHERE id > %d AND DATE(created_at) >= %s AND DATE(created_at) <= %s ORDER BY id ASC LIMIT %d",
                        $last_id,
                        $range_start,
                        $range_end,
                        $batch
                    ),
                    ARRAY_A
                );
            } else {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT * FROM `{$table_name}` WHERE id > %d ORDER BY id ASC LIMIT %d",
                        $last_id,
                        $batch
                    ),
                    ARRAY_A
                );
            }
            if ( empty( $rows ) ) {
                break;
            }
            foreach ( $rows as $row ) {
                unset( $row['id'] );
                fwrite( $jh, wp_json_encode( $row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE ) . "\n" );
            }
            $last_row = end( $rows );
            $last_id  = isset( $last_row['id'] ) ? (int) $last_row['id'] : $last_id;
            reset( $rows );
            if ( count( $rows ) < $batch ) {
                break;
            }
        }
        fclose( $jh );

        $tmp_zip = wp_tempnam( 'oiscl-backup-' );
        if ( ! $tmp_zip ) {
            wp_delete_file( $tmp_jsonl );
            wp_die( esc_html__( 'Could not create a temporary ZIP file.', 'ois-conversion-suite' ) );
        }
        wp_delete_file( $tmp_zip );
        $tmp_zip .= '.zip';

        $zip = new ZipArchive();
        if ( true !== $zip->open( $tmp_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
            wp_delete_file( $tmp_jsonl );
            wp_die( esc_html__( 'Could not open ZIP archive for writing.', 'ois-conversion-suite' ) );
        }
        $zip->addFromString( 'manifest.json', wp_json_encode( $manifest, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE ) );
        $zip->addFile( $tmp_jsonl, 'metrics.jsonl' );
        $zip->close();
        wp_delete_file( $tmp_jsonl );

        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }

        header( 'Content-Type: application/zip' );
        $fname = 'ois_suite_backup_' . gmdate( 'Y-m-d_H-i' );
        if ( 'range' === $scope ) {
            $fname .= '_' . $range_start . '_to_' . $range_end;
        }
        header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $fname ) . '.oiscl"' );
        header( 'Content-Length: ' . (string) filesize( $tmp_zip ) );
        readfile( $tmp_zip );
        wp_delete_file( $tmp_zip );
        exit;
    }

    public function ajax_handle_import() {
        check_ajax_referer( 'oiscl_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'ois-conversion-suite' ) ) );
        }
        if ( empty( $_FILES['backup_file'] ) ) {
            wp_send_json_error( array( 'message' => __( 'No file uploaded.', 'ois-conversion-suite' ) ) );
        }
        $f = $_FILES['backup_file'];
        if ( ! isset( $f['error'] ) || UPLOAD_ERR_OK !== (int) $f['error'] ) {
            $code = isset( $f['error'] ) ? (int) $f['error'] : -1;
            wp_send_json_error( array( 'message' => $this->oiscl_backup_upload_error_message( $code ) ) );
        }
        if ( empty( $f['tmp_name'] ) || ! is_uploaded_file( $f['tmp_name'] ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid or empty temporary file (check upload_max_filesize / post_max_size).', 'ois-conversion-suite' ) ) );
        }

        @set_time_limit( 0 );
        @ini_set( 'memory_limit', '512M' );

        $sig = @file_get_contents( $f['tmp_name'], false, null, 0, 4 );
        if ( false !== $sig && strncmp( $sig, 'PK', 2 ) === 0 ) {
            if ( ! class_exists( 'ZipArchive' ) ) {
                wp_send_json_error( array( 'message' => __( 'This backup is a ZIP (.oiscl). Enable the php-zip extension on this server.', 'ois-conversion-suite' ) ) );
            }
            $log = array(
                __( '✅ Detected OISCL v2 backup (ZIP + JSONL).', 'ois-conversion-suite' ),
                __( 'Importing metrics in batches (low memory usage)…', 'ois-conversion-suite' ),
            );
            $err = $this->oiscl_backup_import_from_zip( $f['tmp_name'], $log );
            if ( is_wp_error( $err ) ) {
                wp_send_json_error( array( 'message' => $err->get_error_message() ) );
            }
            $log[] = __( '🚀 Import finished.', 'ois-conversion-suite' );
            wp_send_json_success( array( 'log' => $log ) );
            return;
        }

        @ini_set( 'memory_limit', '2048M' );
        $legacy_size = @filesize( $f['tmp_name'] );
        if ( false !== $legacy_size && $legacy_size > 80 * 1024 * 1024 ) {
            wp_send_json_error(
                array(
                    'message' => __( 'This legacy JSON export is too large to import reliably in PHP. Export again from a server running this plugin to get a streamed .oiscl ZIP, or raise PHP memory temporarily.', 'ois-conversion-suite' ),
                )
            );
        }
        $content = @file_get_contents( $f['tmp_name'] );
        if ( false === $content || $content === '' ) {
            wp_send_json_error( array( 'message' => __( 'Could not read the backup file.', 'ois-conversion-suite' ) ) );
        }
        $data = json_decode( $content, true );
        if ( ! $data || ! is_array( $data ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid file format or corrupt JSON. For large exports use the ZIP .oiscl format from this plugin version.', 'ois-conversion-suite' ) ) );
        }

        $log = array(
            __( '✅ Validated legacy OISCL JSON export.', 'ois-conversion-suite' ),
            __( 'Starting import…', 'ois-conversion-suite' ),
        );
        global $wpdb;
        $table_metrics = $wpdb->prefix . 'oiscl_block_metrics';

        if ( isset( $data['metrics'] ) && is_array( $data['metrics'] ) ) {
            if ( class_exists( 'OISCL_Activator' ) ) {
                OISCL_Activator::maybe_upgrade_metrics_utm_sm_columns();
                OISCL_Activator::maybe_upgrade_metrics_screen_res_column();
            }
            $wpdb->query( "TRUNCATE TABLE $table_metrics" );
            $count = 0;
            foreach ( array_chunk( $data['metrics'], 150 ) as $chunk ) {
                $ins = $this->oiscl_backup_insert_metrics_chunk( $chunk );
                if ( is_wp_error( $ins ) ) {
                    wp_send_json_error( array( 'message' => __( 'SQL error while importing: ', 'ois-conversion-suite' ) . $ins->get_error_message() ) );
                }
                $count += $ins;
            }
            $log[] = sprintf(
                /* translators: %d: number of metric rows */
                __( '📈 Metrics restored: %d rows processed.', 'ois-conversion-suite' ),
                $count
            );
        } else {
            $log[] = __( '⚠️ The file contained no metric rows.', 'ois-conversion-suite' );
        }

        $this->oiscl_backup_apply_manifest_settings( $data, $log );

        $log[] = __( '🚀 Import finished.', 'ois-conversion-suite' );
        wp_send_json_success( array( 'log' => $log ) );
    }

    /**
     * @param array<string,mixed> $metrics_export Scope metadata written into manifest.json (metrics row filter).
     * @return array<string,mixed>
     */
    private function oiscl_backup_build_manifest_payload( array $metrics_export = array() ) {
        global $wpdb;

        $track_settings = get_option( 'oiscl_settings', array() );
        if ( isset( $track_settings['target_urls'] ) && is_array( $track_settings['target_urls'] ) ) {
            $map = array();
            foreach ( $track_settings['target_urls'] as $id ) {
                $p = get_post( (int) $id );
                if ( $p ) {
                    $map[ (string) $id ] = $p->post_name;
                }
            }
            $track_settings['target_urls_map'] = $map;
        }

        $cro_rules = array();
        $rules_db  = $wpdb->get_results( "SELECT post_id, active_tags FROM {$wpdb->prefix}oiscl_page_settings" );
        if ( $rules_db ) {
            foreach ( $rules_db as $r ) {
                $post = get_post( $r->post_id );
                if ( $post ) {
                    $cro_rules[] = array(
                        'old_id' => (int) $r->post_id,
                        'slug'   => $post->post_name,
                        'tags'   => $r->active_tags,
                    );
                }
            }
        }

        $manifest = array(
            'format_version'      => 2,
            'source'              => get_site_url(),
            'date'                => current_time( 'mysql' ),
            'custom_dashboards'   => get_option( 'oiscl_custom_dashboards' ),
            'report_templates'    => get_option( 'oiscl_report_templates' ),
            'general_settings'    => get_option( 'oiscl_general_settings' ),
            'trackpro_settings'   => $track_settings,
            'cro_rules'           => $cro_rules,
        );
        if ( ! empty( $metrics_export ) ) {
            $manifest['metrics_export'] = $metrics_export;
        }
        return $manifest;
    }

    /**
     * @param int $code UPLOAD_ERR_* code.
     */
    private function oiscl_backup_upload_error_message( $code ) {
        switch ( (int) $code ) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return __( 'File exceeds server upload limits (upload_max_filesize / post_max_size). Upload via SFTP to wp-content/ois-backups/ or ask your host to raise limits.', 'ois-conversion-suite' );
            case UPLOAD_ERR_PARTIAL:
                return __( 'Upload was interrupted (timeout or network). Retry or upload via SFTP.', 'ois-conversion-suite' );
            case UPLOAD_ERR_NO_FILE:
                return __( 'No file was selected.', 'ois-conversion-suite' );
            default:
                /* translators: %d: PHP upload error code */
                return sprintf( __( 'Upload error (code %d).', 'ois-conversion-suite' ), (int) $code );
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array<int,mixed>|null
     */
    private function oiscl_backup_metric_row_to_values( $row ) {
        if ( ! is_array( $row ) ) {
            return null;
        }
        return array(
            isset( $row['session_id'] ) ? (string) $row['session_id'] : '',
            isset( $row['origin_url'] ) ? (string) $row['origin_url'] : '',
            isset( $row['context_text'] ) ? (string) $row['context_text'] : '',
            isset( $row['anchor_text'] ) ? (string) $row['anchor_text'] : '',
            isset( $row['destination_url'] ) ? (string) $row['destination_url'] : '',
            isset( $row['time_spent'] ) ? (int) $row['time_spent'] : 0,
            isset( $row['country'] ) ? (string) $row['country'] : 'Desconocido',
            isset( $row['city'] ) ? (string) $row['city'] : 'Desconocido',
            isset( $row['os'] ) ? (string) $row['os'] : 'Desconocido',
            isset( $row['browser'] ) ? (string) $row['browser'] : 'Desconocido',
            isset( $row['device'] ) ? (string) $row['device'] : 'Desktop',
            isset( $row['language'] ) ? (string) $row['language'] : 'en',
            isset( $row['is_bot'] ) ? (int) $row['is_bot'] : 0,
            isset( $row['utm_campaign'] ) ? (string) $row['utm_campaign'] : '',
            isset( $row['utm_term'] ) ? (string) $row['utm_term'] : '',
            isset( $row['utm_source'] ) ? (string) $row['utm_source'] : '',
            isset( $row['utm_medium'] ) ? (string) $row['utm_medium'] : '',
            isset( $row['clicks'] ) ? (int) $row['clicks'] : 0,
            isset( $row['user_id'] ) ? (int) $row['user_id'] : 0,
            isset( $row['is_guest'] ) ? (int) $row['is_guest'] : 1,
            isset( $row['created_at'] ) ? (string) $row['created_at'] : current_time( 'mysql' ),
        );
    }

    /**
     * @param array<int,array<string,mixed>> $chunk
     * @return int|WP_Error Number of rows inserted.
     */
    private function oiscl_backup_insert_metrics_chunk( $chunk ) {
        global $wpdb;
        $table_metrics = $wpdb->prefix . 'oiscl_block_metrics';
        $values        = array();
        $placeholders  = array();
        foreach ( $chunk as $row ) {
            $tuple = $this->oiscl_backup_metric_row_to_values( $row );
            if ( null === $tuple ) {
                continue;
            }
            $values       = array_merge( $values, $tuple );
            $placeholders[] = "('%s', '%s', '%s', '%s', '%s', %d, '%s', '%s', '%s', '%s', '%s', '%s', %d, '%s', '%s', '%s', '%s', %d, %d, %d, '%s')";
        }
        if ( empty( $placeholders ) ) {
            return 0;
        }
        $query = "INSERT INTO {$table_metrics} (session_id, origin_url, context_text, anchor_text, destination_url, time_spent, country, city, os, browser, device, language, is_bot, utm_campaign, utm_term, utm_source, utm_medium, clicks, user_id, is_guest, created_at) VALUES " . implode( ', ', $placeholders );
        $sql   = call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( $query ), $values ) );
        $ok    = $wpdb->query( $sql );
        if ( false === $ok ) {
            return new WP_Error( 'oiscl_db', $wpdb->last_error ? $wpdb->last_error : 'INSERT failed' );
        }
        return count( $placeholders );
    }

    /**
     * @param array<string,mixed> $data Manifest or legacy root export.
     * @param array<int,string>   $log
     */
    private function oiscl_backup_apply_manifest_settings( $data, &$log ) {
        global $wpdb;

        if ( isset( $data['custom_dashboards'] ) ) {
            update_option( 'oiscl_custom_dashboards', $data['custom_dashboards'] );
            $log[] = __( '📊 Dashboards restored.', 'ois-conversion-suite' );
        }
        if ( isset( $data['report_templates'] ) ) {
            update_option( 'oiscl_report_templates', $data['report_templates'] );
            $log[] = __( '📝 Report templates restored.', 'ois-conversion-suite' );
        }
        if ( isset( $data['general_settings'] ) ) {
            update_option( 'oiscl_general_settings', $data['general_settings'] );
        }

        $find_real_id = function ( $old_id, $slug ) use ( $wpdb ) {
            $check = get_post( (int) $old_id );
            if ( $check && $check->post_name === $slug ) {
                return (int) $old_id;
            }
            $found_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_status IN ('publish', 'draft') LIMIT 1",
                    $slug
                )
            );
            return $found_id ? (int) $found_id : (int) $old_id;
        };

        if ( isset( $data['trackpro_settings'] ) && is_array( $data['trackpro_settings'] ) ) {
            $track_settings = $data['trackpro_settings'];
            if ( isset( $track_settings['target_urls_map'] ) && is_array( $track_settings['target_urls_map'] ) ) {
                $valid_ids = array();
                foreach ( $track_settings['target_urls_map'] as $old_id => $slug ) {
                    $valid_ids[] = $find_real_id( $old_id, $slug );
                }
                $track_settings['target_urls'] = array_values( array_unique( array_filter( $valid_ids ) ) );
                unset( $track_settings['target_urls_map'] );
            }
            update_option( 'oiscl_settings', $track_settings );
        }

        if ( isset( $data['cro_rules'] ) && is_array( $data['cro_rules'] ) ) {
            $table_pages = $wpdb->prefix . 'oiscl_page_settings';
            $wpdb->query( "TRUNCATE TABLE $table_pages" );
            foreach ( $data['cro_rules'] as $key => $rule ) {
                if ( is_array( $rule ) ) {
                    $real_id = $find_real_id( $rule['old_id'], $rule['slug'] );
                    $tags    = $rule['tags'];
                } else {
                    $real_id = $find_real_id( 0, $key );
                    $tags    = $rule;
                }
                if ( $real_id ) {
                    $wpdb->replace(
                        $table_pages,
                        array(
                            'post_id'     => $real_id,
                            'active_tags' => $tags,
                        ),
                        array( '%d', '%s' )
                    );
                }
            }
            $log[] = __( '✅ Page tracking rules applied.', 'ois-conversion-suite' );
        }
    }

    /**
     * @param string              $zip_path Server path to uploaded .oiscl (zip).
     * @param array<int,string>   $log
     * @return true|WP_Error
     */
    private function oiscl_backup_import_from_zip( $zip_path, &$log ) {
        global $wpdb;
        $table_metrics = $wpdb->prefix . 'oiscl_block_metrics';

        $dir = trailingslashit( get_temp_dir() ) . 'oiscl-unpack-' . wp_generate_password( 12, false, false );
        if ( ! wp_mkdir_p( $dir ) ) {
            return new WP_Error( 'oiscl_mkdir', __( 'Could not create a temporary folder to unpack the backup.', 'ois-conversion-suite' ) );
        }

        $zip = new ZipArchive();
        if ( true !== $zip->open( $zip_path ) ) {
            $this->oiscl_backup_rmdir_recursive( $dir );
            return new WP_Error( 'oiscl_zip', __( 'Could not open the ZIP file.', 'ois-conversion-suite' ) );
        }
        if ( ! $zip->extractTo( $dir ) ) {
            $zip->close();
            $this->oiscl_backup_rmdir_recursive( $dir );
            return new WP_Error( 'oiscl_zip', __( 'Could not extract the ZIP.', 'ois-conversion-suite' ) );
        }
        $zip->close();

        $manifest_path = $dir . '/manifest.json';
        $jsonl_path    = $dir . '/metrics.jsonl';
        if ( ! file_exists( $manifest_path ) || ! file_exists( $jsonl_path ) ) {
            $this->oiscl_backup_rmdir_recursive( $dir );
            return new WP_Error( 'oiscl_zip', __( 'Invalid ZIP: manifest.json or metrics.jsonl is missing.', 'ois-conversion-suite' ) );
        }

        $base = realpath( $dir );
        $m    = realpath( $manifest_path );
        $j    = realpath( $jsonl_path );
        if ( false === $base || false === $m || false === $j || strpos( $m, $base ) !== 0 || strpos( $j, $base ) !== 0 ) {
            $this->oiscl_backup_rmdir_recursive( $dir );
            return new WP_Error( 'oiscl_zip', __( 'Invalid extracted paths (security check).', 'ois-conversion-suite' ) );
        }

        $manifest = json_decode( file_get_contents( $manifest_path ), true );
        if ( ! is_array( $manifest ) ) {
            $this->oiscl_backup_rmdir_recursive( $dir );
            return new WP_Error( 'oiscl_manifest', __( 'manifest.json could not be read.', 'ois-conversion-suite' ) );
        }

        if ( class_exists( 'OISCL_Activator' ) ) {
            OISCL_Activator::maybe_upgrade_metrics_utm_sm_columns();
            OISCL_Activator::maybe_upgrade_metrics_screen_res_column();
        }

        $wpdb->query( "TRUNCATE TABLE $table_metrics" );

        $fh = fopen( $jsonl_path, 'rb' );
        if ( ! $fh ) {
            $this->oiscl_backup_rmdir_recursive( $dir );
            return new WP_Error( 'oiscl_io', __( 'Could not open metrics.jsonl.', 'ois-conversion-suite' ) );
        }

        $buffer = array();
        $total  = 0;
        $line_n = 0;
        while ( ! feof( $fh ) ) {
            $line = fgets( $fh );
            if ( false === $line ) {
                break;
            }
            $line = trim( $line );
            if ( '' === $line ) {
                continue;
            }
            ++$line_n;
            $row = json_decode( $line, true );
            if ( ! is_array( $row ) ) {
                fclose( $fh );
                $this->oiscl_backup_rmdir_recursive( $dir );
                return new WP_Error(
                    'oiscl_jsonl',
                    sprintf(
                        /* translators: %d: line number */
                        __( 'Invalid line in metrics.jsonl (line %d).', 'ois-conversion-suite' ),
                        $line_n
                    )
                );
            }
            unset( $row['id'] );
            $buffer[] = $row;
            if ( count( $buffer ) >= 150 ) {
                $ins = $this->oiscl_backup_insert_metrics_chunk( $buffer );
                if ( is_wp_error( $ins ) ) {
                    fclose( $fh );
                    $this->oiscl_backup_rmdir_recursive( $dir );
                    return $ins;
                }
                $total += $ins;
                $buffer = array();
            }
        }
        fclose( $fh );

        if ( ! empty( $buffer ) ) {
            $ins = $this->oiscl_backup_insert_metrics_chunk( $buffer );
            if ( is_wp_error( $ins ) ) {
                $this->oiscl_backup_rmdir_recursive( $dir );
                return $ins;
            }
            $total += $ins;
        }

        $this->oiscl_backup_apply_manifest_settings( $manifest, $log );
        $this->oiscl_backup_rmdir_recursive( $dir );

        $log[] = sprintf(
            /* translators: %d: metric rows */
            __( '📈 Metrics restored: %d rows (streaming).', 'ois-conversion-suite' ),
            $total
        );
        return true;
    }

    /**
     * @param string $dir Absolute path.
     */
    private function oiscl_backup_rmdir_recursive( $dir ) {
        if ( ! is_dir( $dir ) ) {
            return;
        }
        foreach ( scandir( $dir ) as $item ) {
            if ( '.' === $item || '..' === $item ) {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if ( is_dir( $path ) ) {
                $this->oiscl_backup_rmdir_recursive( $path );
            } else {
                wp_delete_file( $path );
            }
        }
        @rmdir( $dir );
    }
    public function ajax_full_uninstall_cleanup() {
        check_ajax_referer('oiscl_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error();
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}oiscl_block_metrics");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}oiscl_page_settings");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}oiscl_utm_references");
        delete_option('oiscl_settings');
        delete_option('oiscl_general_settings');
        delete_option('oiscl_custom_dashboards');
        delete_option('oiscl_report_templates');
        delete_option('oiscl_delete_data_on_uninstall');
        $like = $wpdb->esc_like('oiscl_rules_') . '%';
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like));
        $this->recursive_rmdir(WP_CONTENT_DIR . '/ois-logs/');
        require_once(ABSPATH . 'wp-admin/includes/plugin.php');
        if ( defined( 'OISCL_PLUGIN_FILE' ) ) {
            deactivate_plugins( plugin_basename( OISCL_PLUGIN_FILE ) );
        }
        wp_send_json_success();
    }
    public function ajax_purge_old_logs() {
        check_ajax_referer('oiscl_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(array('message' => __('Insufficient permissions.', 'ois-conversion-suite')));
        $log_dir = WP_CONTENT_DIR . '/ois-logs/';
        if (file_exists($log_dir)) {
            $this->recursive_rmdir($log_dir);
            wp_send_json_success(__('Legacy log folder removed.', 'ois-conversion-suite'));
        } else {
            wp_send_json_error(array('message' => __('Log folder was already absent.', 'ois-conversion-suite')));
        }
    }
    
    // --- ACCIONES AJAX CUSTOM DASHBOARD ---
    public function ajax_save_custom_dash() {
        check_ajax_referer('oiscl_admin_nonce', 'nonce');
        if ( ! current_user_can('manage_ois_marketing') ) wp_send_json_error();
        
        $title = sanitize_text_field($_POST['title']);
        $elements = json_decode(stripslashes($_POST['elements']), true);
        if (!$title || !is_array($elements)) wp_send_json_error();

        $dashboards = get_option('oiscl_custom_dashboards', []);
        $id = substr(md5($title . time()), 0, 8); 
        $dashboards[$id] = ['title' => $title, 'elements' => $elements, 'created' => current_time('mysql')];
        
        update_option('oiscl_custom_dashboards', $dashboards);
        wp_send_json_success();
    }

    public function ajax_delete_custom_dash() {
        check_ajax_referer('oiscl_admin_nonce', 'nonce');
        if ( ! current_user_can('manage_ois_marketing') ) wp_send_json_error();
        
        $id = sanitize_text_field($_POST['id']);
        $dashboards = get_option('oiscl_custom_dashboards', []);
        if (isset($dashboards[$id])) {
            unset($dashboards[$id]);
            update_option('oiscl_custom_dashboards', $dashboards);
        }
        wp_send_json_success();
    }

    public function ajax_save_template() {
        check_ajax_referer('oiscl_admin_nonce', 'nonce');
        if ( ! current_user_can('manage_options') ) wp_send_json_error();
        
        $name = sanitize_text_field($_POST['name']);
        $content = wp_kses_post(stripslashes($_POST['content'])); 
        
        $templates = get_option('oiscl_report_templates', []);
        $templates[$name] = $content;
        
        update_option('oiscl_report_templates', $templates);
        wp_send_json_success();
    }
    
    private function recursive_rmdir($dir) {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    if (is_dir($dir . DIRECTORY_SEPARATOR . $object) && !is_link($dir . "/" . $object))
                        $this->recursive_rmdir($dir . DIRECTORY_SEPARATOR . $object);
                    else
                        unlink($dir . DIRECTORY_SEPARATOR . $object);
                }
            }
            rmdir($dir);
        }
    }

}
