<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait OISCL_Admin_Utm_Trait {

    // ==========================================
    // MODULE 7: OIS UTM TRACKER (MODAL + USER JOURNEY ENGINE)
    // ==========================================

    /**
     * Handle Settings → UTM Manager POST (save references) and GET delete. Requires manage_options.
     */
    public function oiscl_process_utm_settings_request() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        global $wpdb;
        $table_refs   = $wpdb->prefix . 'oiscl_utm_references';
        $settings_url = admin_url( 'admin.php?page=oiscl-settings&tab=utmtracker' );

        if ( isset( $_GET['delete_utm'] ) && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'oiscl_delete_utm_' . (int) $_GET['delete_utm'] ) ) {
            $wpdb->delete( $table_refs, array( 'id' => (int) $_GET['delete_utm'] ) );
            wp_safe_redirect( $settings_url );
            exit;
        }

        if ( ! isset( $_POST['oiscl_save_utm'] ) ) {
            return;
        }

        check_admin_referer( 'oiscl_utm_action', 'oiscl_utm_nonce' );
        if ( class_exists( 'OISCL_Activator' ) ) {
            OISCL_Activator::maybe_upgrade_utm_refs_spend_column();
        }

        $label_name      = isset( $_POST['label_name'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['label_name'] ) ) ) : '';
        $edit_label_old  = isset( $_POST['edit_label_old'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['edit_label_old'] ) ) ) : '';

        if ( '' === $label_name ) {
            wp_safe_redirect( add_query_arg( 'oiscl_utm_err', 'label', $settings_url ) );
            exit;
        }

        $built = $this->oiscl_utm_build_pending_rows_from_post( $label_name );
        if ( ! empty( $built['err'] ) ) {
            wp_safe_redirect( add_query_arg( 'oiscl_utm_err', $built['err'], $settings_url ) );
            exit;
        }
        $pending_rows = $built['rows'];

        $submitted_ids = array_values(
            array_filter(
                array_map(
                    function ( $row ) {
                        return (int) $row['id'];
                    },
                    $pending_rows
                )
            )
        );

        foreach ( $pending_rows as $row ) {
            // Exclude only this row's id so we do not match ourselves; still detect clashes with any other DB row.
            $exclude_ids = ( (int) $row['id'] > 0 ) ? array( (int) $row['id'] ) : array();
            if ( $this->oiscl_utm_ref_combo_exists_excluding_ids( $label_name, $row['utm_campaign'], $row['utm_term'], $exclude_ids ) ) {
                wp_safe_redirect( add_query_arg( 'oiscl_utm_err', 'duplicate', $settings_url ) );
                exit;
            }
        }

        if ( '' !== $edit_label_old ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $old_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM `{$table_refs}` WHERE label_name = %s", $edit_label_old ) );
            $old_ids = array_map( 'intval', (array) $old_ids );
            $remove  = array_diff( $old_ids, $submitted_ids );
            foreach ( $remove as $rid ) {
                $wpdb->delete( $table_refs, array( 'id' => (int) $rid ) );
            }
            foreach ( $pending_rows as $row ) {
                $payload = $this->oiscl_build_utm_ref_save_payload( $label_name, $row );
                if ( (int) $row['id'] > 0 ) {
                    $ok = $wpdb->update( $table_refs, $payload, array( 'id' => (int) $row['id'] ) );
                } else {
                    $ok = $wpdb->insert( $table_refs, $payload );
                }
                if ( false === $ok ) {
                    wp_safe_redirect( add_query_arg( 'oiscl_utm_err', 'db', $settings_url ) );
                    exit;
                }
            }
        } else {
            foreach ( $pending_rows as $row ) {
                if ( $this->oiscl_utm_ref_combo_exists_excluding_ids( $label_name, $row['utm_campaign'], $row['utm_term'], array() ) ) {
                    wp_safe_redirect( add_query_arg( 'oiscl_utm_err', 'duplicate', $settings_url ) );
                    exit;
                }
                $ok = $wpdb->insert( $table_refs, $this->oiscl_build_utm_ref_save_payload( $label_name, $row ) );
                if ( false === $ok ) {
                    wp_safe_redirect( add_query_arg( 'oiscl_utm_err', 'db', $settings_url ) );
                    exit;
                }
            }
        }
        wp_safe_redirect( add_query_arg( 'oiscl_utm_saved', '1', $settings_url ) );
        exit;
     }

    /**
     * DB row payload for UTM reference save (omits spend if column not migrated yet).
     *
     * @param string               $label_name Company label.
     * @param array<string,mixed>  $row        Pending row.
     * @return array<string,mixed>
     */
    private function oiscl_build_utm_ref_save_payload( $label_name, $row ) {
        $payload = array(
            'label_name'   => $label_name,
            'target_url'   => $row['target_url'],
            'utm_campaign' => $row['utm_campaign'],
            'utm_term'     => $row['utm_term'],
            'utm_source'   => $row['utm_source'],
            'utm_medium'   => $row['utm_medium'],
            'conv_anchor'  => $row['conv_anchor'],
        );
        if ( $this->oiscl_utm_refs_table_has_column( 'spend' ) ) {
            $payload['spend'] = isset( $row['spend'] ) ? (float) $row['spend'] : 0;
        }
        return $payload;
    }

    /**
     * Whether oiscl_utm_references has a column (cached per request).
     *
     * @param string $column Column name.
     * @return bool
     */
    private function oiscl_utm_refs_table_has_column( $column ) {
        static $cache = array();
        $column = sanitize_key( $column );
        if ( isset( $cache[ $column ] ) ) {
            return $cache[ $column ];
        }
        global $wpdb;
        $table = $wpdb->prefix . 'oiscl_utm_references';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $has              = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", $column ) );
        $cache[ $column ] = ! empty( $has );
        return $cache[ $column ];
    }

    /**
     * @param string $url_raw Raw URL from form.
     * @return string
     */
    private function oiscl_sanitize_utm_target_url( $url_raw ) {
        $url = esc_url_raw( trim( (string) $url_raw ) );
        if ( '' !== $url ) {
            return $url;
        }
        $candidate = trim( (string) $url_raw );
        if ( '' === $candidate ) {
            return '';
        }
        if ( 0 !== strpos( $candidate, 'http://' ) && 0 !== strpos( $candidate, 'https://' ) ) {
            $candidate = 'https://' . ltrim( $candidate, '/' );
        }
        return esc_url_raw( $candidate );
    }

    /**
     * Normalize a POST field to a zero-indexed array (single values become one-element arrays).
     *
     * @param string $key POST key.
     * @return array<int,mixed>
     */
    private function oiscl_utm_post_string_array( $key ) {
        if ( ! isset( $_POST[ $key ] ) ) {
            return array();
        }
        $raw = wp_unslash( $_POST[ $key ] );
        return is_array( $raw ) ? $raw : array( $raw );
    }

    /**
     * Build pending UTM rows from POST. Prefers JSON body `oiscl_utm_bundle` (reliable multi-row saves).
     *
     * @param string $label_name Company label (for duplicate keys only; slug rules applied per row).
     * @return array{rows:array<int,array<string,mixed>>,err:?string}
     */
    private function oiscl_utm_build_pending_rows_from_post( $label_name ) {
        $bundle_raw = isset( $_POST['oiscl_utm_bundle'] ) ? trim( (string) wp_unslash( $_POST['oiscl_utm_bundle'] ) ) : '';
        $raw_list   = null;
        if ( '' !== $bundle_raw ) {
            $decoded = json_decode( $bundle_raw, true );
            if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
                return array( 'rows' => array(), 'err' => 'bundle' );
            }
            $raw_list = $decoded;
        }

        if ( null === $raw_list ) {
            $target_urls  = $this->oiscl_utm_post_string_array( 'target_url' );
            $utm_names    = $this->oiscl_utm_post_string_array( 'utm_name' );
            $utm_terms    = $this->oiscl_utm_post_string_array( 'utm_term' );
            $utm_sources  = $this->oiscl_utm_post_string_array( 'utm_source' );
            $utm_mediums  = $this->oiscl_utm_post_string_array( 'utm_medium' );
            $conv_anchors = $this->oiscl_utm_post_string_array( 'conv_anchor' );
            $spends       = $this->oiscl_utm_post_string_array( 'spend' );
            $row_ids      = $this->oiscl_utm_post_string_array( 'row_id' );
            $count_in     = max(
                count( $target_urls ),
                count( $utm_names ),
                count( $utm_terms ),
                count( $utm_sources ),
                count( $utm_mediums ),
                count( $conv_anchors ),
                count( $spends ),
                count( $row_ids )
            );
            $raw_list = array();
            for ( $i = 0; $i < $count_in; $i++ ) {
                $raw_list[] = array(
                    'id'           => isset( $row_ids[ $i ] ) ? $row_ids[ $i ] : 0,
                    'target_url'   => isset( $target_urls[ $i ] ) ? $target_urls[ $i ] : '',
                    'utm_name'     => isset( $utm_names[ $i ] ) ? $utm_names[ $i ] : '',
                    'utm_term'     => isset( $utm_terms[ $i ] ) ? $utm_terms[ $i ] : '',
                    'utm_source'   => isset( $utm_sources[ $i ] ) ? $utm_sources[ $i ] : '',
                    'utm_medium'   => isset( $utm_mediums[ $i ] ) ? $utm_mediums[ $i ] : '',
                    'conv_anchor'  => isset( $conv_anchors[ $i ] ) ? $conv_anchors[ $i ] : '',
                    'spend'        => isset( $spends[ $i ] ) ? $spends[ $i ] : '',
                );
            }
        }

        $pending_rows = array();
        $batch_keys   = array();
        foreach ( (array) $raw_list as $row_in ) {
            if ( ! is_array( $row_in ) ) {
                continue;
            }
            $url_raw = isset( $row_in['target_url'] ) ? trim( (string) $row_in['target_url'] ) : '';
            if ( '' === $url_raw ) {
                continue;
            }
            $camp_raw = isset( $row_in['utm_name'] ) ? trim( (string) $row_in['utm_name'] ) : '';
            $term_raw = isset( $row_in['utm_term'] ) ? trim( (string) $row_in['utm_term'] ) : '';
            $camp     = $this->oiscl_normalize_utm_slug( $camp_raw );
            $term     = $this->oiscl_normalize_utm_slug( $term_raw );
            $src_raw  = isset( $row_in['utm_source'] ) ? trim( (string) $row_in['utm_source'] ) : 'google';
            $med_raw  = isset( $row_in['utm_medium'] ) ? trim( (string) $row_in['utm_medium'] ) : 'cpc';
            $conv_raw = isset( $row_in['conv_anchor'] ) ? trim( (string) $row_in['conv_anchor'] ) : '';
            $spend_raw = isset( $row_in['spend'] ) ? trim( (string) $row_in['spend'] ) : '0';
            $source   = '' !== $src_raw ? $this->oiscl_normalize_utm_slug( $src_raw ) : 'google';
            $medium   = '' !== $med_raw ? $this->oiscl_normalize_utm_slug( $med_raw ) : 'cpc';
            $conv     = substr( sanitize_text_field( $conv_raw ), 0, 120 );
            $spend    = max( 0, (float) str_replace( array( ',', ' ' ), array( '.', '' ), $spend_raw ) );
            $row_id   = isset( $row_in['id'] ) ? (int) $row_in['id'] : 0;
            if ( '' === $camp ) {
                return array( 'rows' => array(), 'err' => 'campaign' );
            }
            $target_url = $this->oiscl_sanitize_utm_target_url( $url_raw );
            if ( '' === $target_url ) {
                return array( 'rows' => array(), 'err' => 'url' );
            }
            $combo_key = strtolower( $label_name ) . '|' . $camp . '|' . $term;
            if ( isset( $batch_keys[ $combo_key ] ) ) {
                return array( 'rows' => array(), 'err' => 'duplicate' );
            }
            $batch_keys[ $combo_key ] = true;
            $pending_rows[]           = array(
                'id'           => $row_id,
                'target_url'   => $target_url,
                'utm_campaign' => $camp,
                'utm_term'     => $term,
                'utm_source'   => $source,
                'utm_medium'   => $medium,
                'conv_anchor'  => $conv,
                'spend'        => $spend,
            );
        }

        if ( empty( $pending_rows ) ) {
            return array( 'rows' => array(), 'err' => 'norows' );
        }

        return array( 'rows' => $pending_rows, 'err' => null );
    }

     public function render_utmtracker_settings() {
        global $wpdb;
        $table_refs = $wpdb->prefix . 'oiscl_utm_references';

        $saved_links = $wpdb->get_results( "SELECT * FROM $table_refs ORDER BY label_name ASC, created_at DESC" );

        $this->render_ois_component( 'layout_start', array( 'title' => __( 'UTM Manager', 'ois-conversion-suite' ) ) );

        if (!empty($_GET['oiscl_utm_err'])) {
            $code = sanitize_key(wp_unslash($_GET['oiscl_utm_err']));
            $errs = array(
                'label'    => __('Company / Label is required.', 'ois-conversion-suite'),
                'campaign' => __('Campaign ID is required for every row that has a Target URL.', 'ois-conversion-suite'),
                'norows'   => __('Add at least one Target URL to save.', 'ois-conversion-suite'),
                'duplicate' => __('This Company / Label already has that Campaign ID and UTM Term combination. Use a different term or campaign.', 'ois-conversion-suite'),
                'db'       => __('Could not save to the database. Reload this page once so migrations can run, then try again.', 'ois-conversion-suite'),
                'url'      => __('One or more Target URLs are invalid. Use a full URL including https://', 'ois-conversion-suite'),
                'bundle'   => __('Could not read the UTM form data. Reload the page and try again.', 'ois-conversion-suite'),
            );
            $msg = isset($errs[$code]) ? $errs[$code] : __('Could not save. Check required fields.', 'ois-conversion-suite');
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($msg) . '</p></div>';
        }
        if ( ! empty( $_GET['oiscl_utm_saved'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'UTM links saved successfully.', 'ois-conversion-suite' ) . '</p></div>';
        }
        if ( get_option( 'oiscl_utm_refs_unique_pending' ) ) {
            echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'UTM uniqueness could not be enforced in the database because duplicate rows already exist. Remove duplicates in this table, then reload.', 'ois-conversion-suite' ) . '</p></div>';
        }

        $utm_refs_registry = array();
        $utm_links_by_label = array();
        foreach ( (array) $saved_links as $ref_row ) {
            $utm_refs_registry[] = array(
                'id'       => (int) $ref_row->id,
                'label'    => (string) $ref_row->label_name,
                'campaign' => (string) $ref_row->utm_campaign,
                'term'     => (string) $ref_row->utm_term,
            );
            $utm_links_by_label[ $ref_row->label_name ][] = array(
                'id'           => (int) $ref_row->id,
                'target_url'   => (string) $ref_row->target_url,
                'utm_campaign' => (string) $ref_row->utm_campaign,
                'utm_term'     => (string) $ref_row->utm_term,
                'utm_source'   => isset( $ref_row->utm_source ) ? (string) $ref_row->utm_source : 'google',
                'utm_medium'   => isset( $ref_row->utm_medium ) ? (string) $ref_row->utm_medium : 'cpc',
                'conv_anchor'  => isset( $ref_row->conv_anchor ) ? (string) $ref_row->conv_anchor : '',
                'spend'        => isset( $ref_row->spend ) ? (float) $ref_row->spend : 0,
            );
        }

        $this->oiscl_render_utm_links_manager_block(
            array(
                'mode'             => 'manage',
                'saved_links'      => $saved_links,
                'filter_sql_refs'  => '',
                'filter_sql_stats' => '',
            )
        );

        if ( ! empty( $_GET['oiscl_alerts_saved'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'UTM alert settings saved.', 'ois-conversion-suite' ) . '</p></div>';
        }
        $alert_settings = OISCL_Utm_Alerts::get_settings();
        echo '<div class="oiscl-card" style="margin:30px 0 20px; padding:24px;">';
        echo '<h3 style="margin:0 0 8px;">🔔 ' . esc_html__( 'Campaign alerts', 'ois-conversion-suite' ) . '</h3>';
        echo '<p style="margin:0 0 16px; color:#64748b; font-size:13px;">' . esc_html__( 'Daily checks: click drop vs the previous period and zero hits on active campaigns. Alerts appear on OIS admin screens and optional email.', 'ois-conversion-suite' ) . '</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'oiscl_save_utm_alerts', 'oiscl_utm_alerts_nonce' );
        echo '<input type="hidden" name="action" value="oiscl_save_utm_alerts">';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row">' . esc_html__( 'Enable alerts', 'ois-conversion-suite' ) . '</th><td><label><input type="checkbox" name="utm_alerts_enabled" value="1"' . checked( ! empty( $alert_settings['enabled'] ), true, false ) . '> ' . esc_html__( 'Run checks and show admin notices', 'ois-conversion-suite' ) . '</label></td></tr>';
        echo '<tr><th scope="row">' . esc_html__( 'Email', 'ois-conversion-suite' ) . '</th><td><input type="email" name="utm_alerts_email" value="' . esc_attr( $alert_settings['email'] ) . '" class="regular-text"></td></tr>';
        echo '<tr><th scope="row">' . esc_html__( 'Drop threshold', 'ois-conversion-suite' ) . '</th><td><input type="number" name="utm_alerts_drop_pct" value="' . esc_attr( (string) $alert_settings['drop_pct'] ) . '" min="5" max="90" style="width:80px;"> % <span class="description">' . esc_html__( 'Alert when clicks fall more than this vs the prior period (min. 5 prior clicks).', 'ois-conversion-suite' ) . '</span></td></tr>';
        echo '<tr><th scope="row">' . esc_html__( 'Compare period', 'ois-conversion-suite' ) . '</th><td><input type="number" name="utm_alerts_compare_days" value="' . esc_attr( (string) $alert_settings['compare_days'] ) . '" min="3" max="30" style="width:80px;"> ' . esc_html__( 'days', 'ois-conversion-suite' ) . '</td></tr>';
        echo '<tr><th scope="row">' . esc_html__( 'Zero-traffic window', 'ois-conversion-suite' ) . '</th><td><input type="number" name="utm_alerts_zero_hours" value="' . esc_attr( (string) $alert_settings['zero_hours'] ) . '" min="12" max="168" style="width:80px;"> ' . esc_html__( 'hours', 'ois-conversion-suite' ) . '</td></tr>';
        echo '</tbody></table>';
        submit_button( __( 'Save alert settings', 'ois-conversion-suite' ) );
        echo '</form></div>';

        // ==========================================
        // 5. THE MODAL (Hidden by default)
        // ==========================================
        echo '<div id="oiscl-utm-modal" class="oiscl-utm-modal-overlay" style="display:none;">';
            echo '<div class="oiscl-utm-modal-dialog">';
                echo '<style>
                .oiscl-field-error{border-color:#d63638!important;box-shadow:0 0 0 1px #d63638!important;}
                .oiscl-utm-modal-overlay{position:fixed;inset:0;z-index:999999;padding:20px 16px;box-sizing:border-box;overflow-y:auto;background:rgba(15,23,42,0.7);backdrop-filter:blur(4px);}
                .oiscl-utm-modal-dialog{background:#fff;width:760px;max-width:calc(100vw - 32px);margin:0 auto;border-radius:12px;box-shadow:0 20px 25px -5px rgba(0,0,0,0.2);overflow:hidden;max-height:calc(100vh - 40px);display:flex;flex-direction:column;}
                .oiscl-utm-modal-header{padding:20px 30px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;flex-shrink:0;}
                .oiscl-utm-modal-form{display:flex;flex-direction:column;flex:1 1 auto;min-height:0;}
                .oiscl-utm-modal-body{padding:30px;overflow-y:auto;flex:1 1 auto;min-height:0;}
                .oiscl-utm-modal-footer{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;padding:16px 30px 20px;border-top:1px solid #e2e8f0;background:#fff;flex-shrink:0;}
                </style>';
                echo '<div class="oiscl-utm-modal-header">';
                    echo '<h2 id="modal-title" style="margin:0; font-size:20px;">➕ New Tracking Link</h2>';
                    echo '<span id="close-utm-modal" style="cursor:pointer; font-size:24px; color:#64748b;">&times;</span>';
                echo '</div>';
                
                echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=oiscl-settings&tab=utmtracker' ) ) . '" id="oiscl-utm-modal-form" class="oiscl-utm-modal-form" novalidate="novalidate">';
                    wp_nonce_field('oiscl_utm_action', 'oiscl_utm_nonce');
                    echo '<input type="hidden" name="oiscl_utm_bundle" id="oiscl-utm-bundle" value="">';
                    echo '<input type="hidden" name="edit_label_old" id="modal-edit-label-old" value="">';
                    echo '<div class="oiscl-utm-modal-body">';
                    
                    echo '<div style="margin-bottom:20px;">';
                        echo '<label style="font-weight:700; display:block; margin-bottom:5px;">' . esc_html__('Company or Label Name', 'ois-conversion-suite') . ' <span style="color:#d63638;">*</span></label>';
                        echo '<input type="text" name="label_name" id="modal-label" placeholder="' . esc_attr__('e.g. Seafood Restaurant', 'ois-conversion-suite') . '" required autocomplete="organization" style="width:100%; height:40px;">';
                    echo '</div>';

                    echo '<div id="modal-url-section"><div id="modal-url-container"></div></div>';

                    echo '</div>';
                    echo '<div class="oiscl-utm-modal-footer">';
                        echo '<button type="button" id="modal-add-url" class="button oiscl-btn oiscl-btn--outline">➕ ' . esc_html__( 'Add URL', 'ois-conversion-suite' ) . '</button>';
                        echo '<input type="submit" name="oiscl_save_utm" id="modal-save-links" class="button button-large oiscl-btn oiscl-btn--primary" value="💾 ' . esc_attr__( 'Save', 'ois-conversion-suite' ) . '">';
                    echo '</div>';
                echo '</form>';

                echo '<div id="modal-url-row-template" style="display:none;">';
                        echo '<div class="modal-url-row" style="background:#f1f5f9; padding:15px; border-radius:8px; margin-bottom:15px; position:relative;">';
                            echo '<input type="hidden" class="m-row-id" value="0">';
                            echo '<div style="display:grid; grid-template-columns: 2fr 1fr 1fr; gap:10px;">';
                                echo '<div><label style="font-size:12px; font-weight:600;">' . esc_html__('Target URL', 'ois-conversion-suite') . ' <span style="color:#d63638;">*</span></label><input type="text" class="m-url" inputmode="url" autocomplete="url" style="width:100%;"></div>';
                                echo '<div><label style="font-size:12px; font-weight:600;">' . esc_html__('Campaign ID', 'ois-conversion-suite') . ' <span style="color:#d63638;">*</span></label><input type="text" class="m-camp oiscl-utm-slug" placeholder="' . esc_attr__('summer-promo', 'ois-conversion-suite') . '" style="width:100%;"><p style="font-size:10px;color:#64748b;margin:4px 0 0;">' . esc_html__('Letters, numbers, hyphens. Spaces become hyphens.', 'ois-conversion-suite') . '</p></div>';
                                echo '<div><label style="font-size:12px; font-weight:600;">' . esc_html__('UTM Term', 'ois-conversion-suite') . ' <span style="color:#64748b; font-weight:500;">(' . esc_html__('optional', 'ois-conversion-suite') . ')</span></label><input type="text" class="m-term oiscl-utm-slug" placeholder="' . esc_attr__('seafood', 'ois-conversion-suite') . '" style="width:100%;"><p style="font-size:10px;color:#64748b;margin:4px 0 0;">' . esc_html__('Optional ad group / variant slug.', 'ois-conversion-suite') . '</p></div>';
                            echo '</div>';
                            echo '<div style="display:grid; grid-template-columns: 1fr 1fr 2fr 1fr; gap:10px; margin-top:10px;">';
                                echo '<div><label style="font-size:12px; font-weight:600;">' . esc_html__( 'UTM Source', 'ois-conversion-suite' ) . '</label><input type="text" class="m-source oiscl-utm-slug" value="google" placeholder="google" style="width:100%;"></div>';
                                echo '<div><label style="font-size:12px; font-weight:600;">' . esc_html__( 'UTM Medium', 'ois-conversion-suite' ) . '</label><input type="text" class="m-medium oiscl-utm-slug" value="cpc" placeholder="cpc" style="width:100%;"></div>';
                                echo '<div><label style="font-size:12px; font-weight:600;">' . esc_html__( 'Conversion click label', 'ois-conversion-suite' ) . ' <span style="color:#64748b;font-weight:500;">(' . esc_html__( 'optional', 'ois-conversion-suite' ) . ')</span></label><input type="text" class="m-conv" placeholder="' . esc_attr__( 'e.g. WhatsApp, Enviar, Reservar', 'ois-conversion-suite' ) . '" style="width:100%;"><p style="font-size:10px;color:#64748b;margin:4px 0 0;">' . esc_html__( 'Button/link text to count as a conversion for this campaign.', 'ois-conversion-suite' ) . '</p></div>';
                                echo '<div><label style="font-size:12px; font-weight:600;">' . esc_html__( 'Ad spend', 'ois-conversion-suite' ) . ' <span style="color:#64748b;font-weight:500;">(' . esc_html__( 'optional', 'ois-conversion-suite' ) . ')</span></label><input type="number" class="m-spend" min="0" step="0.01" placeholder="0.00" style="width:100%;"><p style="font-size:10px;color:#64748b;margin:4px 0 0;">' . esc_html__( 'Manual spend for CPA estimates in Overview.', 'ois-conversion-suite' ) . '</p></div>';
                            echo '</div>';
                        echo '</div>';
                    echo '</div>';

            echo '</div>';
        echo '</div>';

        $this->render_ois_component('layout_end');
        ?>
        <script>
        jQuery(document).ready(function($) {
            var oisclUtmRefsRegistry = <?php echo wp_json_encode( $utm_refs_registry ); ?>;
            var oisclUtmLinksByLabel = <?php echo wp_json_encode( $utm_links_by_label ); ?>;

            function normalizeUtmSlug(raw) {
                var s = (raw || '').trim().toLowerCase();
                if (!s) return '';
                s = s.replace(/\s+/g, '-').replace(/[^a-z0-9\-_]/g, '').replace(/-+/g, '-').replace(/^-+|-+$/g, '');
                return s;
            }

            function comboKey(label, camp, term) {
                return (label || '').trim().toLowerCase() + '|' + normalizeUtmSlug(camp) + '|' + normalizeUtmSlug(term);
            }

            function normalizeRowsList(rows) {
                if (!rows) return [];
                if (Array.isArray(rows)) return rows;
                if (typeof rows === 'object') return Object.values(rows);
                return [];
            }

            function oisclDecodeUtmRowsB64(b64) {
                if (!b64 || typeof b64 !== 'string') return [];
                try {
                    b64 = b64.trim().replace(/\s+/g, '');
                    var bin = atob(b64);
                    var bytes = new Uint8Array(bin.length);
                    for (var i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
                    var json = new TextDecoder('utf-8').decode(bytes);
                    var parsed = JSON.parse(json);
                    return Array.isArray(parsed) ? parsed : [];
                } catch (err) {
                    return [];
                }
            }

            function getUtmLinksForLabel(label) {
                var key = (label || '').trim();
                if (!key) return [];
                if (oisclUtmLinksByLabel[key]) {
                    return normalizeRowsList(oisclUtmLinksByLabel[key]);
                }
                var found = [];
                Object.keys(oisclUtmLinksByLabel || {}).forEach(function(k) {
                    if ((k || '').trim().toLowerCase() === key.toLowerCase()) {
                        found = normalizeRowsList(oisclUtmLinksByLabel[k]);
                    }
                });
                return found;
            }

            function wireUrlRowInputs($row) {
                $row.find('.m-row-id').attr('name', 'row_id[]');
                $row.find('.m-url').attr('name', 'target_url[]');
                $row.find('.m-camp').attr('name', 'utm_name[]');
                $row.find('.m-term').attr('name', 'utm_term[]');
                $row.find('.m-source').attr('name', 'utm_source[]');
                $row.find('.m-medium').attr('name', 'utm_medium[]');
                $row.find('.m-conv').attr('name', 'conv_anchor[]');
                $row.find('.m-spend').attr('name', 'spend[]');
            }

            function getExcludedRowIds() {
                var ids = [];
                $('#modal-url-container .m-row-id').each(function() {
                    var v = parseInt($(this).val(), 10) || 0;
                    if (v > 0) ids.push(v);
                });
                return ids;
            }

            function isDuplicateCombo(label, camp, term, excludeIds) {
                excludeIds = excludeIds || [];
                var key = comboKey(label, camp, term);
                var seenBatch = {};
                var dup = false;
                $('#modal-url-container .modal-url-row').each(function() {
                    var url = ($(this).find('.m-url').val() || '').trim();
                    if (!url) return;
                    var c = $(this).find('.m-camp').val();
                    var t = $(this).find('.m-term').val();
                    var rowKey = comboKey(label, c, t);
                    if (seenBatch[rowKey]) { dup = true; return false; }
                    seenBatch[rowKey] = true;
                });
                if (dup) return true;
                for (var i = 0; i < oisclUtmRefsRegistry.length; i++) {
                    var r = oisclUtmRefsRegistry[i];
                    if (excludeIds.indexOf(parseInt(r.id, 10)) !== -1) continue;
                    if (comboKey(r.label, r.campaign, r.term) === key) return true;
                }
                return false;
            }

            function validateModalRows() {
                var label = ($('#modal-label').val() || '').trim();
                var excludeIds = getExcludedRowIds();
                var valid = true;
                $('#modal-url-container .modal-url-row').each(function() {
                    var $row = $(this);
                    var url = ($row.find('.m-url').val() || '').trim();
                    var $camp = $row.find('.m-camp');
                    var $term = $row.find('.m-term');
                    $camp.removeClass('oiscl-field-error');
                    $term.removeClass('oiscl-field-error');
                    if (!url) return;
                    var campNorm = normalizeUtmSlug($camp.val());
                    if (!campNorm) {
                        $camp.addClass('oiscl-field-error');
                        valid = false;
                    }
                    if (label && isDuplicateCombo(label, $camp.val(), $term.val(), excludeIds)) {
                        $camp.addClass('oiscl-field-error');
                        $term.addClass('oiscl-field-error');
                        valid = false;
                    }
                });
                return valid;
            }

            function syncRemoveButtons() {
                var $rows = $('#modal-url-container .modal-url-row');
                $rows.find('.remove-m-row').remove();
                if ($rows.length > 1) {
                    $rows.each(function() {
                        $(this).append('<span class="remove-m-row" style="position:absolute; top:-5px; right:-5px; background:#ef4444; color:#fff; width:20px; height:20px; border-radius:50%; text-align:center; cursor:pointer; font-size:14px; line-height:18px;">&times;</span>');
                    });
                }
            }

            function appendUrlRow(data) {
                data = data || {};
                var $row = $('#modal-url-row-template .modal-url-row').first().clone();
                wireUrlRowInputs($row);
                $row.find('.m-row-id').val(data.id || 0);
                $row.find('.m-url').val(data.url || '');
                $row.find('.m-camp').val(data.camp || '');
                $row.find('.m-term').val(data.term || '');
                $row.find('.m-source').val(data.source || 'google');
                $row.find('.m-medium').val(data.medium || 'cpc');
                $row.find('.m-conv').val(data.conv || '');
                $row.find('.m-spend').val(data.spend !== undefined && data.spend !== null ? data.spend : '');
                $row.find('.oiscl-field-error').removeClass('oiscl-field-error');
                $row.appendTo('#oiscl-utm-modal-form #modal-url-container');
                syncRemoveButtons();
            }

            function resetUrlContainer(rows) {
                rows = normalizeRowsList(rows);
                $('#oiscl-utm-modal-form #modal-url-container').empty();
                if (!rows || !rows.length) {
                    appendUrlRow({});
                } else {
                    rows.forEach(function(item) {
                        appendUrlRow({
                            id: item.id,
                            url: item.target_url,
                            camp: item.utm_campaign,
                            term: item.utm_term,
                            source: item.utm_source,
                            medium: item.utm_medium,
                            conv: item.conv_anchor,
                            spend: item.spend
                        });
                    });
                }
            }

            function resetModalForNew() {
                $('#modal-edit-label-old').val('');
                $('#modal-label').val('').prop('readonly', false);
                $('#modal-url-section').show();
                $('#modal-add-url').show();
                resetUrlContainer([]);
                $('.oiscl-field-error').removeClass('oiscl-field-error');
            }

            $(document).on('blur', '.oiscl-utm-slug', function() {
                var $el = $(this);
                var norm = normalizeUtmSlug($el.val());
                if (norm !== ($el.val() || '').trim().toLowerCase().replace(/\s+/g, '-')) {
                    $el.val(norm);
                }
                validateModalRows();
            });

            $(document).on('input', '#modal-label, .m-camp, .m-term', function() {
                validateModalRows();
            });

            $('#open-utm-modal').click(function() {
                $('#modal-title').text('➕ ' + <?php echo wp_json_encode( __( 'New Tracking Link', 'ois-conversion-suite' ) ); ?>);
                resetModalForNew();
                $('#oiscl-utm-modal').fadeIn(200, function() { this.scrollTop = 0; });
            });

            $('#close-utm-modal, #oiscl-utm-modal').click(function(e) {
                if (e.target === this) $('#oiscl-utm-modal').fadeOut(200);
            });

            $(document).on('click', '.edit-utm-label-trigger', function(e) {
                e.stopPropagation();
                var label = $(this).attr('data-label') || '';
                var rows = oisclDecodeUtmRowsB64($(this).attr('data-utm-rows-b64'));
                if (!rows.length) {
                    var rawRows = $(this).attr('data-utm-rows');
                    if (rawRows) {
                        try { rows = JSON.parse(rawRows); } catch (err) { rows = []; }
                    }
                }
                rows = normalizeRowsList(rows);
                if (!rows.length) {
                    rows = getUtmLinksForLabel(label);
                }
                $('#modal-title').text('✏️ ' + <?php echo wp_json_encode( __( 'Edit Company / Label', 'ois-conversion-suite' ) ); ?>);
                $('#modal-edit-label-old').val(label);
                $('#modal-label').val(label).prop('readonly', false);
                $('#modal-url-section').show();
                $('#modal-add-url').show();
                resetUrlContainer(rows);
                $('.oiscl-field-error').removeClass('oiscl-field-error');
                $('#oiscl-utm-modal').fadeIn(200, function() { this.scrollTop = 0; });
            });

            $('#modal-add-url').click(function() {
                appendUrlRow({});
                validateModalRows();
            });

            $(document).on('click', '.remove-m-row', function() {
                $(this).closest('.modal-url-row').remove();
                syncRemoveButtons();
                validateModalRows();
            });

            $('#oiscl-utm-modal form').on('submit', function(e) {
                $('.oiscl-utm-slug').each(function() {
                    var norm = normalizeUtmSlug($(this).val());
                    if (norm) $(this).val(norm);
                });
                const label = ($('#modal-label').val() || '').trim();
                if (!label) {
                    e.preventDefault();
                    alert(<?php echo wp_json_encode( esc_html__( 'Company / Label is required.', 'ois-conversion-suite' ) ); ?>);
                    return false;
                }
                if (!validateModalRows()) {
                    e.preventDefault();
                    alert(<?php echo wp_json_encode( esc_html__( 'Fix Campaign ID / UTM Term: required campaign slug or duplicate combination for this company.', 'ois-conversion-suite' ) ); ?>);
                    return false;
                }
                let ok = true;
                $('#modal-url-container .modal-url-row').each(function() {
                    const url = ($(this).find('.m-url').val() || '').trim();
                    const camp = normalizeUtmSlug($(this).find('.m-camp').val());
                    if (url && !camp) { ok = false; return false; }
                });
                if (!ok) {
                    e.preventDefault();
                    alert(<?php echo wp_json_encode( esc_html__( 'Campaign ID is required for every row that has a Target URL.', 'ois-conversion-suite' ) ); ?>);
                    return false;
                }
                const hasUrl = $('#modal-url-container .modal-url-row .m-url').toArray().some(function(el) { return (el.value || '').trim() !== ''; });
                if (!hasUrl) {
                    e.preventDefault();
                    alert(<?php echo wp_json_encode( esc_html__( 'Add at least one Target URL to save.', 'ois-conversion-suite' ) ); ?>);
                    return false;
                }
                $('#modal-url-container .modal-url-row').each(function() {
                    const url = ($(this).find('.m-url').val() || '').trim();
                    if (!url) {
                        $(this).remove();
                    }
                });
                syncRemoveButtons();
                var payloadRows = [];
                $('#oiscl-utm-modal-form #modal-url-container .modal-url-row').each(function() {
                    var url = ($(this).find('.m-url').val() || '').trim();
                    if (!url) return;
                    payloadRows.push({
                        id: parseInt($(this).find('.m-row-id').val(), 10) || 0,
                        target_url: url,
                        utm_name: ($(this).find('.m-camp').val() || '').trim(),
                        utm_term: ($(this).find('.m-term').val() || '').trim(),
                        utm_source: ($(this).find('.m-source').val() || '').trim(),
                        utm_medium: ($(this).find('.m-medium').val() || '').trim(),
                        conv_anchor: ($(this).find('.m-conv').val() || '').trim(),
                        spend: ($(this).find('.m-spend').val() || '').trim()
                    });
                });
                $('#oiscl-utm-bundle').val(JSON.stringify(payloadRows));
                $('#oiscl-utm-modal-form #modal-url-container').find('input,textarea,select').each(function() {
                    $(this).removeAttr('name');
                });
            });
        });
        </script>
        <?php
    } 
    // ==========================================
    // MODULE 7: OIS UTM TRACKER - TABBED DASHBOARD
    // ==========================================
    public function display_campaigns_page() {
        global $wpdb; 
        $table_refs = $wpdb->prefix . 'oiscl_utm_references';
        $table_stats = $wpdb->prefix . 'oiscl_block_metrics';
        $user_id = get_current_user_id();
        $today   = current_time('Y-m-d');

        // --- 1. LÓGICA DE PESTAÑAS (TABS) ---
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'overview';

        // --- 2. LÓGICA DE FECHAS SINCRONIZADA ---
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
            if ($saved) { 
                $start_date = $saved['start']; $end_date = $saved['end']; $preset_label = $saved['label']; 
            } else { 
                $start_date = date('Y-m-d', strtotime($today . ' - 29 days')); $end_date = $today; $preset_label = "Last 30 Days"; 
            }
        }

        $date_cap = OISCL_Plan::clamp_report_dates( $start_date, $end_date, $today );
        $start_date = $date_cap['start_date'];
        $end_date   = $date_cap['end_date'];

        $diff_days = round((strtotime($end_date) - strtotime($start_date)) / 86400);
        $prev_end = date('Y-m-d', strtotime($start_date . ' - 1 day'));
        $prev_start = date('Y-m-d', strtotime($prev_end . ' - ' . $diff_days . ' days'));

        // --- 3. LÓGICA DE FILTRO DESPLEGABLE ---
        $selected_filter = isset($_GET['utm_filter']) ? sanitize_text_field($_GET['utm_filter']) : 'all';
        $utm_filters = $this->get_oiscl_utm_dashboard_filters($selected_filter);
        $filter_sql_stats = $utm_filters['filter_sql_stats'];
        $filter_sql_refs = $utm_filters['filter_sql_refs'];

        $refs_data = $wpdb->get_results("SELECT label_name, utm_campaign FROM $table_refs ORDER BY label_name ASC");
        $filter_hierarchy = []; foreach($refs_data as $ref) { $filter_hierarchy[$ref->label_name][] = $ref->utm_campaign; }

        // --- 4. KPIs GLOBALES (SIEMPRE SE CARGAN PARA EL HEADER) ---
        $ois_now = date('Y-m-d H:i:s', strtotime(current_time('mysql')) - 300);
        $live_views = $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT session_id) FROM $table_stats WHERE created_at >= %s", $ois_now)) ?: 0;
        
        $total_views = $wpdb->get_var($wpdb->prepare("SELECT SUM(clicks) FROM $table_stats WHERE anchor_text='[Pageview]' AND DATE(created_at) >= %s AND DATE(created_at) <= %s", $start_date, $end_date)) ?: 0;
        $prev_views = $wpdb->get_var($wpdb->prepare("SELECT SUM(clicks) FROM $table_stats WHERE anchor_text='[Pageview]' AND DATE(created_at) >= %s AND DATE(created_at) <= %s", $prev_start, $prev_end)) ?: 0;
        
        $unique_users = $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT session_id) FROM $table_stats WHERE anchor_text='[Pageview]' AND DATE(created_at) >= %s AND DATE(created_at) <= %s", $start_date, $end_date)) ?: 0;
        $prev_uniques = $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT session_id) FROM $table_stats WHERE anchor_text='[Pageview]' AND DATE(created_at) >= %s AND DATE(created_at) <= %s", $prev_start, $prev_end)) ?: 0;
        
        $total_clicks = $wpdb->get_var($wpdb->prepare("SELECT SUM(clicks) FROM $table_stats WHERE anchor_text NOT IN ('[Pageview]', '[Bloque]', 'Reading') AND DATE(created_at) >= %s AND DATE(created_at) <= %s", $start_date, $end_date)) ?: 0;
        $prev_clicks = $wpdb->get_var($wpdb->prepare("SELECT SUM(clicks) FROM $table_stats WHERE anchor_text NOT IN ('[Pageview]', '[Bloque]', 'Reading') AND DATE(created_at) >= %s AND DATE(created_at) <= %s", $prev_start, $prev_end)) ?: 0;
        
        $actions_per_pv = $total_views > 0 ? round(($total_clicks / $total_views), 2) : 0;
        $prev_actions_per_pv = $prev_views > 0 ? round(($prev_clicks / $prev_views), 2) : 0;
        
        $avg_time = $wpdb->get_var($wpdb->prepare("SELECT AVG(time_spent) FROM $table_stats WHERE time_spent > 0 AND anchor_text IN ('[Pageview]', '[Bloque]', 'Reading') AND DATE(created_at) >= %s AND DATE(created_at) <= %s", $start_date, $end_date)) ?: 0;
        $prev_time = $wpdb->get_var($wpdb->prepare("SELECT AVG(time_spent) FROM $table_stats WHERE time_spent > 0 AND anchor_text IN ('[Pageview]', '[Bloque]', 'Reading') AND DATE(created_at) >= %s AND DATE(created_at) <= %s", $prev_start, $prev_end)) ?: 0;

        // --- 5. RENDERIZADO HEADER ---
        $this->render_ois_component('layout_start', array('id' => 'oiscl-utm-tracker-wrap'));
        
        $this->render_ois_component('header', array(
            'title'      => '🚀 ' . __( 'OIS UTM Manager', 'ois-conversion-suite' ),
            'start_date' => $start_date,
            'end_date'   => $end_date,
            'preset'     => $preset_label,
            // Solo el slug de menu (sin &tab=). tab / uct_tab vienen de $_GET y del date_selector (hidden inputs).
            'page_slug'  => 'oiscl-utm-tracker',
            'live_val'   => $live_views,
            'kpis'       => array(
                array('label' => 'LIVE NOW', 'value' => $live_views, 'color' => ($live_views > 0 ? '#46b450' : '#d63638'), 'is_live' => true),
                array('label' => 'TOTAL VISITS', 'value' => number_format($total_views), 'color' => '#1a73e8', 'delta' => $this->format_kpi_delta($total_views, $prev_views), 'icon' => '👁️'),
                array('label' => 'UNIQUE USERS', 'value' => number_format($unique_users), 'color' => '#46b450', 'delta' => $this->format_kpi_delta($unique_users, $prev_uniques), 'icon' => '👤'),
                array('label' => 'ACTIONS / PV', 'value' => (string) $actions_per_pv, 'color' => '#f56e28', 'delta' => $this->format_kpi_delta($actions_per_pv, $prev_actions_per_pv), 'icon' => '🖱️'),
                array('label' => 'AVG RETENTION', 'value' => ($avg_time >= 60 ? round($avg_time/60, 1).'m' : round($avg_time).'s'), 'color' => '#722ed1', 'delta' => $this->format_kpi_delta($avg_time, $prev_time), 'icon' => '⏱️')
            )
        ));

        echo '<div style="margin-top:20px;">';

        // --- 6. Título de sección (el filtro UTM para el gráfico de Content & CRO va solo en la tarjeta del chart) ---
        echo '<div style="margin-bottom:20px;">';
            echo '<h2 style="margin:0; font-size:22px; color:#1d2327; font-weight:600;">📊 ' . esc_html__( 'Campaign Performance', 'ois-conversion-suite' ) . '</h2>';
        echo '</div>';

        // --- 7. MENÚ DE PESTAÑAS ---
        $base_url = '?page=oiscl-utm-tracker';
        if(isset($_GET['preset'])) $base_url .= '&preset=' . esc_attr($_GET['preset']);
        if(isset($_GET['start_date'])) $base_url .= '&start_date=' . esc_attr($_GET['start_date']) . '&end_date=' . esc_attr($_GET['end_date']);
        if($selected_filter !== 'all') $base_url .= '&utm_filter=' . esc_attr($selected_filter);
        $url_tp_page = isset( $_GET['tp_page'] ) ? (int) $_GET['tp_page'] : 0;
        $url_tp_revision = isset( $_GET['tp_revision'] ) ? (int) $_GET['tp_revision'] : 0;
        if ( $url_tp_page > 0 ) {
            $base_url .= '&tp_page=' . $url_tp_page;
        }
        if ( $url_tp_revision > 0 ) {
            $base_url .= '&tp_revision=' . $url_tp_revision;
        }
        $url_uct_tab = isset( $_GET['uct_tab'] ) ? sanitize_key( wp_unslash( $_GET['uct_tab'] ) ) : '';
        if ( $url_uct_tab && in_array( $url_uct_tab, array( 'overview', 'clicks', 'reading' ), true ) ) {
            $base_url .= '&uct_tab=' . esc_attr( $url_uct_tab );
        }

        echo '<h2 class="nav-tab-wrapper oiscl-wp-tabstrip">';
        echo '<a href="'.$base_url.'&tab=overview" class="nav-tab '.($active_tab == 'overview' ? 'nav-tab-active' : '').'">📈 Overview</a>';
        echo '<a href="'.$base_url.'&tab=content" class="nav-tab '.($active_tab == 'content' ? 'nav-tab-active' : '').'">🔗 ' . esc_html__( 'UTM Content & CRO', 'ois-conversion-suite' ) . '</a>';
        echo '<a href="'.$base_url.'&tab=funnel" class="nav-tab '.($active_tab == 'funnel' ? 'nav-tab-active' : '').'">🔽 ' . esc_html__( 'UTM Funnel', 'ois-conversion-suite' ) . '</a>';
        echo '<a href="'.$base_url.'&tab=click_tracker" class="nav-tab '.($active_tab == 'click_tracker' ? 'nav-tab-active' : '').'">🎯 ' . esc_html__( 'UTM Click Tracker', 'ois-conversion-suite' ) . '</a>';
        echo '<a href="'.$base_url.'&tab=audience" class="nav-tab '.($active_tab == 'audience' ? 'nav-tab-active' : '').'">👥 ' . esc_html__( 'UTM Audience', 'ois-conversion-suite' ) . '</a>';
        echo '<a href="'.$base_url.'&tab=journey" class="nav-tab '.($active_tab == 'journey' ? 'nav-tab-active' : '').'">📡 ' . esc_html__( 'UTM User Journey', 'ois-conversion-suite' ) . '</a>';
        echo '</h2>';

        // UTM-scoped KPIs + deltas (same card chrome as page header; shown on every tab).
        $utm_hits         = (int) ( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(id) FROM $table_stats WHERE utm_campaign != '' $filter_sql_stats AND DATE(created_at) >= %s AND DATE(created_at) <= %s", $start_date, $end_date ) ) ?: 0 );
        $prev_utm_hits    = (int) ( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(id) FROM $table_stats WHERE utm_campaign != '' $filter_sql_stats AND DATE(created_at) >= %s AND DATE(created_at) <= %s", $prev_start, $prev_end ) ) ?: 0 );
        $utm_actions      = (int) ( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(id) FROM $table_stats WHERE utm_campaign != '' $filter_sql_stats AND anchor_text NOT IN ('[Pageview]', '[Bloque]', 'Reading') AND DATE(created_at) >= %s AND DATE(created_at) <= %s", $start_date, $end_date ) ) ?: 0 );
        $prev_utm_actions = (int) ( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(id) FROM $table_stats WHERE utm_campaign != '' $filter_sql_stats AND anchor_text NOT IN ('[Pageview]', '[Bloque]', 'Reading') AND DATE(created_at) >= %s AND DATE(created_at) <= %s", $prev_start, $prev_end ) ) ?: 0 );
        $utm_users        = (int) ( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT session_id) FROM $table_stats WHERE utm_campaign != '' $filter_sql_stats AND DATE(created_at) >= %s AND DATE(created_at) <= %s", $start_date, $end_date ) ) ?: 0 );
        $prev_utm_users   = (int) ( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT session_id) FROM $table_stats WHERE utm_campaign != '' $filter_sql_stats AND DATE(created_at) >= %s AND DATE(created_at) <= %s", $prev_start, $prev_end ) ) ?: 0 );
        $utm_ctr          = $utm_hits > 0 ? round( ( $utm_actions / $utm_hits ) * 100, 1 ) : 0;
        $prev_utm_ctr     = $prev_utm_hits > 0 ? round( ( $prev_utm_actions / $prev_utm_hits ) * 100, 1 ) : 0;

        // ==========================================
        // TAB 1: OVERVIEW
        // ==========================================
        if ($active_tab === 'overview') {
            $this->oiscl_render_utm_tab_kpi_row( $live_views, $utm_hits, $prev_utm_hits, $utm_users, $prev_utm_users, $utm_actions, $prev_utm_actions, $utm_ctr, $prev_utm_ctr );
            $this->oiscl_render_utm_traffic_quality_panel( $table_stats, $start_date, $end_date, $filter_sql_stats );
            $this->oiscl_render_utm_roas_panel( $table_refs, $table_stats, $start_date, $end_date, $filter_sql_refs, $filter_sql_stats );

            $pulse_60           = $this->oiscl_build_pulse_60m_payload( 0, $filter_sql_stats, true );
            $pulse_total_now    = (int) $pulse_60['total_clicks'];
            $vector_total_views = (int) $pulse_60['total_views'];
            $utm_content_url    = esc_url( admin_url( 'admin.php?page=oiscl-utm-tracker&tab=content' ) );

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $clicks_h_today = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT HOUR(created_at) as hr, SUM(clicks) as total FROM `{$table_stats}` WHERE anchor_text NOT IN ('[Pageview]', '[Vista de Bloque]') AND utm_campaign != '' {$filter_sql_stats} AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY hr",
                    $start_date,
                    $end_date
                )
            );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $clicks_h_past = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT HOUR(created_at) as hr, SUM(clicks) as total FROM `{$table_stats}` WHERE anchor_text NOT IN ('[Pageview]', '[Vista de Bloque]') AND utm_campaign != '' {$filter_sql_stats} AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY hr",
                    $prev_start,
                    $prev_end
                )
            );
            $h_clicks_today = array_fill( 0, 24, 0 );
            $h_clicks_past  = array_fill( 0, 24, 0 );
            foreach ( $clicks_h_today as $h ) {
                $h_clicks_today[ (int) $h->hr ] = (int) $h->total;
            }
            foreach ( $clicks_h_past as $h ) {
                $h_clicks_past[ (int) $h->hr ] = (int) $h->total;
            }

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $traffic_h_today = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT HOUR(created_at) as hr, SUM(clicks) as views, COUNT(DISTINCT session_id) as uniques FROM `{$table_stats}` WHERE anchor_text='[Pageview]' AND utm_campaign != '' {$filter_sql_stats} AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY hr",
                    $start_date,
                    $end_date
                )
            );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $traffic_h_past = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT HOUR(created_at) as hr, SUM(clicks) as views, COUNT(DISTINCT session_id) as uniques FROM `{$table_stats}` WHERE anchor_text='[Pageview]' AND utm_campaign != '' {$filter_sql_stats} AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY hr",
                    $prev_start,
                    $prev_end
                )
            );
            $h_views_today   = array_fill( 0, 24, 0 );
            $h_uniques_today = array_fill( 0, 24, 0 );
            $h_views_past    = array_fill( 0, 24, 0 );
            $h_uniques_past  = array_fill( 0, 24, 0 );
            foreach ( $traffic_h_today as $h ) {
                $h_views_today[ (int) $h->hr ]    = (int) $h->views;
                $h_uniques_today[ (int) $h->hr ] = (int) $h->uniques;
            }
            foreach ( $traffic_h_past as $h ) {
                $h_views_past[ (int) $h->hr ]    = (int) $h->views;
                $h_uniques_past[ (int) $h->hr ] = (int) $h->uniques;
            }

            echo '<div class="oiscl-dash-chart-card" style="background:#fff; border:1px solid #ccd0d4; padding:20px; border-radius:4px; margin-bottom:20px; width:100%; box-sizing:border-box;">';
            echo '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">';
            echo '<h3 class="ois-block-title"><a href="' . $utm_content_url . '">⚡ Activity Pulse <span id="utm-pulse-total-clicks" style="color:#f56e28; font-weight:bold; background:#fff5f5; padding:2px 8px; border-radius:4px; margin:0 5px;">' . esc_html( $pulse_total_now . ' ' . __( 'Clics', 'ois-conversion-suite' ) ) . '</span> <span class="oiscl-utm-hour-label" style="font-weight:normal; color:#666; font-size:14px;">(' . esc_html__( 'Last 60 Minutes', 'ois-conversion-suite' ) . ')</span> <span class="dashicons dashicons-external" style="font-size:14px; margin-top:4px;"></span></a></h3>';
            echo '<div style="display:flex; gap:5px;"><button type="button" class="button button-small btn-utm-pulse-prev">◀</button><button type="button" class="button button-small btn-utm-pulse-reset">' . esc_html__( 'Now', 'ois-conversion-suite' ) . '</button><button type="button" class="button button-small btn-utm-pulse-next" disabled>▶</button></div>';
            echo '</div>';
            echo '<div style="height:150px;"><canvas id="oisclUtmPulseChart"></canvas></div></div>';
            echo '<div class="oiscl-dash-chart-card" style="background:#fff; border:1px solid #ccd0d4; padding:20px; border-radius:4px; margin-bottom:20px; width:100%; box-sizing:border-box;">';
            echo '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">';
            echo '<h3 class="ois-block-title"><a href="' . $utm_content_url . '">📈 Views vs Uniques <span id="utm-vector-total-views" style="color:#1a73e8; font-weight:bold; background:#eaf3ff; padding:2px 8px; border-radius:4px; margin:0 5px;">' . esc_html( $vector_total_views . ' ' . __( 'Vistas', 'ois-conversion-suite' ) ) . '</span> <span class="oiscl-utm-hour-label" style="font-weight:normal; color:#666; font-size:14px;">(' . esc_html__( 'Last 60 Minutes', 'ois-conversion-suite' ) . ')</span> <span class="dashicons dashicons-external" style="font-size:14px; margin-top:4px;"></span></a></h3>';
            echo '<div style="display:flex; gap:5px;"><button type="button" class="button button-small btn-utm-pulse-prev">◀</button><button type="button" class="button button-small btn-utm-pulse-reset">' . esc_html__( 'Now', 'ois-conversion-suite' ) . '</button><button type="button" class="button button-small btn-utm-pulse-next" disabled>▶</button></div>';
            echo '</div>';
            echo '<div style="height:150px;"><canvas id="oisclUtmVectorChart"></canvas></div></div>';

            $this->render_ois_component( 'row_start', array( 'pattern' => '1-1' ) );
            echo '<div class="oiscl-dash-chart-card" style="background:#fff; border:1px solid #ccd0d4; padding:20px; border-radius:4px; display:flex; flex-direction:column; justify-content:space-between;">';
            echo '<div><h3 class="ois-block-title">🖱️ ' . esc_html__( 'Hourly Clicks (Current vs Past)', 'ois-conversion-suite' ) . '</h3>';
            echo '<div style="height:180px; margin-top:15px;"><canvas id="oisclUtmHourlyClicksChart"></canvas></div></div>';
            echo '<div style="background:#f8fafc; padding:8px 15px; border-radius:4px; font-size:11px; color:#475569; border-left:4px solid #f56e28; text-align:center; margin-top:15px;">💡 <strong>' . esc_html__( 'Tip:', 'ois-conversion-suite' ) . '</strong> ' . esc_html__( 'Click legend items to show or hide series.', 'ois-conversion-suite' ) . '</div>';
            echo '</div>';
            echo '<div class="oiscl-dash-chart-card" style="background:#fff; border:1px solid #ccd0d4; padding:20px; border-radius:4px; display:flex; flex-direction:column; justify-content:space-between;">';
            echo '<div><h3 class="ois-block-title">📈 ' . esc_html__( 'Hourly Traffic (Current vs Past)', 'ois-conversion-suite' ) . '</h3>';
            echo '<div style="height:180px; margin-top:15px;"><canvas id="oisclUtmHourlyTrafficChart"></canvas></div></div>';
            echo '<div style="background:#f8fafc; padding:8px 15px; border-radius:4px; font-size:11px; color:#475569; border-left:4px solid #1a73e8; text-align:center; margin-top:15px;">💡 <strong>' . esc_html__( 'Tip:', 'ois-conversion-suite' ) . '</strong> ' . esc_html__( 'Click legend items to show or hide series.', 'ois-conversion-suite' ) . '</div>';
            echo '</div>';
            $this->render_ois_component( 'row_end' );

            $this->oiscl_render_utm_overview_top_tables(
                array(
                    'start_date'       => $start_date,
                    'end_date'         => $end_date,
                    'prev_start'       => $prev_start,
                    'prev_end'         => $prev_end,
                    'filter_sql_stats' => $filter_sql_stats,
                )
            );

            $utm_pulse_nonce = wp_create_nonce( 'oiscl_admin_nonce' );
            ?>
            <script>
            jQuery(document).ready(function($) {
                function oisclUtmUpdateTablePagination(tableId) {
                    var $table = $('#' + tableId);
                    if (!$table.length) return;
                    var pageSize = parseInt($table.attr('data-page-size'), 10) || 6;
                    var currentPage = parseInt($table.attr('data-current-page'), 10) || 1;
                    var $rows = $table.find('tbody tr');
                    var totalPages = Math.ceil($rows.length / pageSize) || 1;
                    if (currentPage > totalPages) currentPage = totalPages;
                    if (currentPage < 1) currentPage = 1;
                    $table.attr('data-current-page', currentPage);
                    $rows.hide();
                    $rows.slice((currentPage - 1) * pageSize, currentPage * pageSize).show();
                    $('#pag-cur-' + tableId).text(currentPage);
                    $('#pag-wrap-' + tableId + ' .pag-prev').prop('disabled', currentPage === 1);
                    $('#pag-wrap-' + tableId + ' .pag-next').prop('disabled', currentPage === totalPages || totalPages === 0);
                }
                if (!window.__oisclUtmDashPagBound) {
                    window.__oisclUtmDashPagBound = 1;
                $('.ois-row-selector').on('change', function() {
                    var target = $(this).data('target');
                    var $table = $('#' + target);
                    if (!$table.length || $table.hasClass('oiscl-utm-journey-events-table')) return;
                    $('#' + target).attr('data-page-size', $(this).val()).attr('data-current-page', 1);
                    oisclUtmUpdateTablePagination(target);
                });
                $(document).on('click', '.pag-prev', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    var target = $(this).data('target');
                    var $table = $('#' + target);
                    if (!$table.length || $table.hasClass('oiscl-utm-journey-events-table')) return;
                    var cur = parseInt($table.attr('data-current-page'), 10);
                    if (cur > 1) {
                        $table.attr('data-current-page', cur - 1);
                        oisclUtmUpdateTablePagination(target);
                    }
                });
                $(document).on('click', '.pag-next', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    var target = $(this).data('target');
                    var $table = $('#' + target);
                    if (!$table.length || $table.hasClass('oiscl-utm-journey-events-table')) return;
                    var cur = parseInt($table.attr('data-current-page'), 10);
                    var max = Math.ceil($table.find('tbody tr').length / parseInt($table.attr('data-page-size'), 10));
                    if (cur < max) {
                        $table.attr('data-current-page', cur + 1);
                        oisclUtmUpdateTablePagination(target);
                    }
                });
                }
                $('.ois-table-dashboard').each(function() {
                    oisclUtmUpdateTablePagination($(this).attr('id'));
                });

                if (typeof Chart === 'undefined') { return; }
                var utmLegend = { position: 'bottom', labels: { padding: 16, usePointStyle: true, font: { size: 10 } } };
                var utmFilter = <?php echo wp_json_encode( $selected_filter ); ?>;
                var lblClicks = <?php echo wp_json_encode( __( 'Clicks', 'ois-conversion-suite' ) ); ?>;
                var lblPastClicks = <?php echo wp_json_encode( __( 'Past Clicks', 'ois-conversion-suite' ) ); ?>;
                var lblViews = <?php echo wp_json_encode( __( 'Views', 'ois-conversion-suite' ) ); ?>;
                var lblUniques = <?php echo wp_json_encode( __( 'Uniques', 'ois-conversion-suite' ) ); ?>;
                var lblPastViews = <?php echo wp_json_encode( __( 'Past Views', 'ois-conversion-suite' ) ); ?>;
                var lblPastUniques = <?php echo wp_json_encode( __( 'Past Uniques', 'ois-conversion-suite' ) ); ?>;
                var lblViewsToday = <?php echo wp_json_encode( __( 'Views (today)', 'ois-conversion-suite' ) ); ?>;
                var lblUniquesToday = <?php echo wp_json_encode( __( 'Uniques (today)', 'ois-conversion-suite' ) ); ?>;
                var lblViewsYest = <?php echo wp_json_encode( __( 'Views (yesterday)', 'ois-conversion-suite' ) ); ?>;
                var lblUniquesYest = <?php echo wp_json_encode( __( 'Uniques (yesterday)', 'ois-conversion-suite' ) ); ?>;
                var txtClics = <?php echo wp_json_encode( __( 'Clics', 'ois-conversion-suite' ) ); ?>;
                var txtVistas = <?php echo wp_json_encode( __( 'Vistas', 'ois-conversion-suite' ) ); ?>;
                var txtLast60 = <?php echo wp_json_encode( '(' . __( 'Last 60 Minutes', 'ois-conversion-suite' ) . ')' ); ?>;
                var hLabels = ['0:00','1:00','2:00','3:00','4:00','5:00','6:00','7:00','8:00','9:00','10:00','11:00','12:00','13:00','14:00','15:00','16:00','17:00','18:00','19:00','20:00','21:00','22:00','23:00'];

                var pulseEl = document.getElementById('oisclUtmPulseChart');
                if (pulseEl) {
                    window.utmRtChart = new Chart(pulseEl.getContext('2d'), {
                        type: 'bar',
                        data: { labels: <?php echo wp_json_encode( $pulse_60['labels'] ); ?>, datasets: [{ data: <?php echo wp_json_encode( $pulse_60['clicks'] ); ?>, backgroundColor: '#f56e28', borderRadius: 2 }] },
                        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { display: true, beginAtZero: true, ticks: { stepSize: 1, precision: 0 } }, x: { display: true, ticks: { maxTicksLimit: 12, maxRotation: 0, font: { size: 9 } } } } }
                    });
                }
                var vecEl = document.getElementById('oisclUtmVectorChart');
                if (vecEl) {
                    window.utmVecChart = new Chart(vecEl.getContext('2d'), {
                        type: 'line',
                        data: {
                            labels: <?php echo wp_json_encode( $pulse_60['labels'] ); ?>,
                            datasets: [
                                { label: lblViewsToday, data: <?php echo wp_json_encode( $pulse_60['v_today'] ); ?>, borderColor: '#1a73e8', backgroundColor: 'rgba(26,115,232,0.1)', fill: true, tension: 0, pointRadius: 3, pointHoverRadius: 5 },
                                { label: lblUniquesToday, data: <?php echo wp_json_encode( $pulse_60['u_today'] ); ?>, borderColor: '#d63638', tension: 0, pointRadius: 3, pointHoverRadius: 5 },
                                { label: lblViewsYest, data: <?php echo wp_json_encode( $pulse_60['v_yest'] ); ?>, borderColor: '#1a73e8', borderDash: [5, 5], fill: false, tension: 0, pointRadius: 3, pointHoverRadius: 5 },
                                { label: lblUniquesYest, data: <?php echo wp_json_encode( $pulse_60['u_yest'] ); ?>, borderColor: '#d63638', borderDash: [5, 5], fill: false, tension: 0, pointRadius: 3, pointHoverRadius: 5 }
                            ]
                        },
                        options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, plugins: { legend: utmLegend, tooltip: { mode: 'index', intersect: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } }, x: { ticks: { maxTicksLimit: 12, maxRotation: 0, font: { size: 9 } } } } }
                    });
                }
                var clicksEl = document.getElementById('oisclUtmHourlyClicksChart');
                if (clicksEl) {
                    new Chart(clicksEl.getContext('2d'), {
                        type: 'line',
                        data: {
                            labels: hLabels,
                            datasets: [
                                { label: lblClicks, data: <?php echo wp_json_encode( $h_clicks_today ); ?>, borderColor: '#f56e28', backgroundColor: 'rgba(245, 110, 40, 0.1)', fill: true, tension: 0.4, pointRadius: 3 },
                                { label: lblPastClicks, data: <?php echo wp_json_encode( $h_clicks_past ); ?>, borderColor: '#f56e28', borderDash: [5, 5], backgroundColor: 'transparent', fill: false, tension: 0.4, pointRadius: 0 }
                            ]
                        },
                        options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, plugins: { legend: utmLegend, tooltip: { mode: 'index', intersect: false } }, scales: { y: { display: false, beginAtZero: true }, x: { grid: { display: false } } } }
                    });
                }
                var trafficEl = document.getElementById('oisclUtmHourlyTrafficChart');
                if (trafficEl) {
                    new Chart(trafficEl.getContext('2d'), {
                        type: 'line',
                        data: {
                            labels: hLabels,
                            datasets: [
                                { label: lblViews, data: <?php echo wp_json_encode( $h_views_today ); ?>, borderColor: '#1a73e8', backgroundColor: 'rgba(26,115,232,0.1)', fill: true, tension: 0.4, pointRadius: 3 },
                                { label: lblUniques, data: <?php echo wp_json_encode( $h_uniques_today ); ?>, borderColor: '#d63638', backgroundColor: 'transparent', fill: false, tension: 0.4, pointRadius: 3 },
                                { label: lblPastViews, data: <?php echo wp_json_encode( $h_views_past ); ?>, borderColor: '#1a73e8', borderDash: [5, 5], backgroundColor: 'transparent', fill: false, tension: 0.4, pointRadius: 0 },
                                { label: lblPastUniques, data: <?php echo wp_json_encode( $h_uniques_past ); ?>, borderColor: '#d63638', borderDash: [5, 5], backgroundColor: 'transparent', fill: false, tension: 0.4, pointRadius: 0 }
                            ]
                        },
                        options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, plugins: { legend: utmLegend, tooltip: { mode: 'index', intersect: false } }, scales: { y: { display: false, beginAtZero: true }, x: { grid: { display: false } } } }
                    });
                }

                var utmHourOffset = 0;
                function utmUpdatePulseCharts(offsetChange) {
                    if (offsetChange === 0) { utmHourOffset = 0; } else { utmHourOffset += offsetChange; }
                    $('.btn-utm-pulse-next').prop('disabled', utmHourOffset >= 0);
                    var labelText = utmHourOffset === 0 ? txtLast60 : '(' + Math.abs(utmHourOffset) + 'h)';
                    $('.oiscl-utm-hour-label').text(labelText);
                    $('#utm-pulse-total-clicks, #utm-vector-total-views').text('…');
                    $.post(ajaxurl, {
                        action: 'oiscl_get_pulse_data',
                        scope: 'utm',
                        utm_filter: utmFilter,
                        offset: utmHourOffset,
                        nonce: '<?php echo esc_js( $utm_pulse_nonce ); ?>'
                    }, function(r) {
                        if (!r.success) { return; }
                        if (window.utmRtChart) {
                            window.utmRtChart.data.labels = r.data.labels;
                            window.utmRtChart.data.datasets[0].data = r.data.clicks;
                            window.utmRtChart.update();
                        }
                        if (window.utmVecChart) {
                            window.utmVecChart.data.labels = r.data.labels;
                            window.utmVecChart.data.datasets[0].data = r.data.v_today;
                            window.utmVecChart.data.datasets[1].data = r.data.u_today;
                            window.utmVecChart.data.datasets[2].data = r.data.v_yest;
                            window.utmVecChart.data.datasets[3].data = r.data.u_yest;
                            window.utmVecChart.update();
                        }
                        $('#utm-pulse-total-clicks').text(r.data.total_clicks + ' ' + txtClics);
                        $('#utm-vector-total-views').text(r.data.total_views + ' ' + txtVistas);
                    });
                }
                $('.btn-utm-pulse-prev').on('click', function(e) { e.preventDefault(); utmUpdatePulseCharts(-1); });
                $('.btn-utm-pulse-next').on('click', function(e) { e.preventDefault(); utmUpdatePulseCharts(1); });
                $('.btn-utm-pulse-reset').on('click', function(e) { e.preventDefault(); utmUpdatePulseCharts(0); });
            });
            </script>
            <?php
        }

        // ==========================================
        // TAB 2: CONTENT & CRO
        // ==========================================
        elseif ($active_tab === 'content') {

            // Hourly UTM traffic (pageviews = views, distinct sessions = uniques), current vs previous period — mirrors Analytics mainTrafficChart.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name + filter fragment from internal helpers.
            $utm_hourly_data = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT HOUR(created_at) as hr, SUM(clicks) as views, COUNT(DISTINCT session_id) as uniques FROM `{$table_stats}` WHERE anchor_text = '[Pageview]' AND utm_campaign != '' {$filter_sql_stats} AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY hr",
                    $start_date,
                    $end_date
                )
            );
            $utm_bar_views   = array_fill( 0, 24, 0 );
            $utm_bar_uniques = array_fill( 0, 24, 0 );
            foreach ( (array) $utm_hourly_data as $h ) {
                $utm_bar_views[ (int) $h->hr ]   = (int) $h->views;
                $utm_bar_uniques[ (int) $h->hr ] = (int) $h->uniques;
            }
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $utm_prev_hourly = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT HOUR(created_at) as hr, SUM(clicks) as views, COUNT(DISTINCT session_id) as uniques FROM `{$table_stats}` WHERE anchor_text = '[Pageview]' AND utm_campaign != '' {$filter_sql_stats} AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY hr",
                    $prev_start,
                    $prev_end
                )
            );
            $utm_prev_bar_views   = array_fill( 0, 24, 0 );
            $utm_prev_bar_uniques = array_fill( 0, 24, 0 );
            foreach ( (array) $utm_prev_hourly as $h ) {
                $utm_prev_bar_views[ (int) $h->hr ]   = (int) $h->views;
                $utm_prev_bar_uniques[ (int) $h->hr ] = (int) $h->uniques;
            }
            $utm_hour_labels = array_map(
                static function ( $i ) {
                    return str_pad( (string) $i, 2, '0', STR_PAD_LEFT ) . ':00';
                },
                range( 0, 23 )
            );

            echo '<div class="ois-box" style="background:#fff; border:1px solid #ccd0d4; padding:20px; border-radius:4px; margin-bottom:25px; width:100%; box-sizing:border-box;">';
            echo '<div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:8px;">';
            echo '<h3 class="ois-block-title" style="margin:0; flex:1; min-width:0;">📈 ' . esc_html__( 'UTM traffic by hour (views & uniques)', 'ois-conversion-suite' ) . '</h3>';
            echo '<div style="display:flex; align-items:center; gap:8px; flex-shrink:0;">';
            echo '<label for="oiscl-utm-filter-chart" style="font-weight:600; font-size:12px; color:#50575e; margin:0; white-space:nowrap;">' . esc_html__( 'Campaign filter', 'ois-conversion-suite' ) . '</label>';
            echo $this->oiscl_get_utm_tracker_filter_select_html( $selected_filter, $filter_hierarchy, __( 'All Companies & Campaigns', 'ois-conversion-suite' ), 'oiscl-utm-filter-chart' );
            echo '</div></div>';
            echo '<p style="margin:0 0 16px 0; font-size:12px; color:#64748b;">' . esc_html__( 'Solid lines: selected date range. Dashed lines: previous period of equal length. Click legend items to show or hide a series.', 'ois-conversion-suite' ) . '</p>';
            echo '<div style="height:260px; position:relative; max-width:100%;"><canvas id="oisclUtmContentHourlyChart"></canvas></div>';
            echo '</div>';

            // 🚨 CORRECCIÓN VITAL: Evitar el error de "Columna Ambigua" en el JOIN de MySQL
            $safe_filter_stats = str_replace('utm_campaign', 's.utm_campaign', $filter_sql_stats);

            // --- 1. CONSULTA DE AGRUPACIÓN POR LABEL (RESUMEN) ---
            $labels_summary = $wpdb->get_results($wpdb->prepare("
                SELECT 
                    r.label_name,
                    COUNT(DISTINCT s.utm_campaign) as count_campaigns,
                    COUNT(DISTINCT s.utm_term) as count_terms,
                    COUNT(s.id) as total_hits,
                    SUM(CASE WHEN s.anchor_text NOT IN ('[Pageview]', '[Bloque]', 'Reading') THEN 1 ELSE 0 END) as total_actions,
                    AVG(s.time_spent) as avg_time
                FROM $table_stats s
                JOIN $table_refs r ON s.utm_campaign = r.utm_campaign
                WHERE s.utm_campaign != '' $safe_filter_stats 
                AND DATE(s.created_at) >= %s AND DATE(s.created_at) <= %s
                GROUP BY r.label_name
                ORDER BY total_hits DESC
            ", $start_date, $end_date));

            $labels_summary_prev = $wpdb->get_results($wpdb->prepare("
                SELECT 
                    r.label_name,
                    COUNT(s.id) as total_hits,
                    SUM(CASE WHEN s.anchor_text NOT IN ('[Pageview]', '[Bloque]', 'Reading') THEN 1 ELSE 0 END) as total_actions,
                    AVG(s.time_spent) as avg_time
                FROM $table_stats s
                JOIN $table_refs r ON s.utm_campaign = r.utm_campaign
                WHERE s.utm_campaign != '' $safe_filter_stats 
                AND DATE(s.created_at) >= %s AND DATE(s.created_at) <= %s
                GROUP BY r.label_name
            ", $prev_start, $prev_end));

            $label_prev_map = array();
            foreach ( (array) $labels_summary_prev as $prev_row ) {
                $label_prev_map[ $prev_row->label_name ] = $prev_row;
            }

            $details_prev_all = $wpdb->get_results($wpdb->prepare("
                SELECT 
                    r.label_name,
                    s.utm_campaign, s.utm_term, s.origin_url,
                    COUNT(s.id) as v_hits,
                    SUM(CASE WHEN s.anchor_text NOT IN ('[Pageview]', '[Bloque]', 'Reading') THEN 1 ELSE 0 END) as v_actions,
                    AVG(s.time_spent) as v_time
                FROM $table_stats s
                JOIN $table_refs r ON s.utm_campaign = r.utm_campaign
                WHERE s.utm_campaign != '' $safe_filter_stats
                AND DATE(s.created_at) >= %s AND DATE(s.created_at) <= %s
                GROUP BY r.label_name, s.utm_campaign, s.utm_term, s.origin_url
            ", $prev_start, $prev_end));

            $detail_prev_map = array();
            foreach ( (array) $details_prev_all as $prev_detail ) {
                $detail_prev_map[ $prev_detail->label_name . "\0" . $prev_detail->utm_campaign . "\0" . $prev_detail->utm_term . "\0" . $prev_detail->origin_url ] = $prev_detail;
            }

            $hierarchy_delta = array( $this, 'oiscl_utm_table_mini_delta' );
            $hierarchy_metric = function( $display, $curr, $prev ) use ( $hierarchy_delta ) {
                return $display . call_user_func( $hierarchy_delta, $curr, $prev );
            };

            $summary_rows = [];
            if ($labels_summary) {
                foreach ($labels_summary as $sum) {
                    
                    // Cálculos del Padre
                    $t_hits = $sum->total_hits ?: 0;
                    $t_actions = $sum->total_actions ?: 0;
                    $t_ctr = $t_hits > 0 ? round(($t_actions / $t_hits) * 100, 1) : 0;
                    $t_time = $sum->avg_time ?: 0;

                    $prev_sum  = isset( $label_prev_map[ $sum->label_name ] ) ? $label_prev_map[ $sum->label_name ] : null;
                    $p_hits    = $prev_sum ? (int) $prev_sum->total_hits : 0;
                    $p_actions = $prev_sum ? (int) $prev_sum->total_actions : 0;
                    $p_ctr     = $p_hits > 0 ? round( ( $p_actions / $p_hits ) * 100, 1 ) : 0;
                    $p_time    = $prev_sum ? (float) $prev_sum->avg_time : 0;

                    // Consulta de detalles para el Acordeón
                    $details = $wpdb->get_results($wpdb->prepare("
                        SELECT 
                            utm_campaign, utm_term, origin_url,
                            COUNT(id) as v_hits,
                            SUM(CASE WHEN anchor_text NOT IN ('[Pageview]', '[Bloque]', 'Reading') THEN 1 ELSE 0 END) as v_actions,
                            AVG(time_spent) as v_time
                        FROM $table_stats
                        WHERE utm_campaign IN (SELECT utm_campaign FROM $table_refs WHERE label_name = %s)
                        $filter_sql_stats
                        AND DATE(created_at) >= %s AND DATE(created_at) <= %s
                        GROUP BY utm_campaign, utm_term, origin_url
                        ORDER BY v_hits DESC
                    ", $sum->label_name, $start_date, $end_date));

                    // HTML detalle para acordeón advanced_table
                    $details_html = '<div style="padding:12px 16px;background:#f8fafc;"><table style="table-layout:fixed; width:100%; border:none; margin:0; background:transparent;"><tbody>';
                    
                    if ($details) {
                        foreach ($details as $d) {
                            $path = parse_url($d->origin_url, PHP_URL_PATH) ?: '/';
                            $d_hits = $d->v_hits ?: 0;
                            $d_actions = $d->v_actions ?: 0;
                            $d_ctr = $d_hits > 0 ? round(($d_actions / $d_hits) * 100, 1) : 0;
                            $d_time = $d->v_time ?: 0;

                            $detail_key = $sum->label_name . "\0" . $d->utm_campaign . "\0" . $d->utm_term . "\0" . $d->origin_url;
                            $prev_d     = isset( $detail_prev_map[ $detail_key ] ) ? $detail_prev_map[ $detail_key ] : null;
                            $pd_hits    = $prev_d ? (int) $prev_d->v_hits : 0;
                            $pd_actions = $prev_d ? (int) $prev_d->v_actions : 0;
                            $pd_ctr     = $pd_hits > 0 ? round( ( $pd_actions / $pd_hits ) * 100, 1 ) : 0;
                            $pd_time    = $prev_d ? (float) $prev_d->v_time : 0;

                            $d_hits_cell    = $hierarchy_metric( '<span>' . number_format( $d_hits ) . '</span>', $d_hits, $pd_hits );
                            $d_actions_cell = $hierarchy_metric( '<span style="font-weight:bold;color:#166534;">' . number_format( $d_actions ) . '</span>', $d_actions, $pd_actions );
                            $d_ctr_cell     = $hierarchy_metric( '<span>' . $d_ctr . '%</span>', $d_ctr, $pd_ctr );
                            $d_time_cell    = $hierarchy_metric( '<span>' . round( $d_time ) . 's</span>', round( $d_time ), round( $pd_time ) );

                            $details_html .= "<tr style='border-top: 1px solid #e2e8f0;'>
                                <td style='width:15%; padding:8px 10px; color:#cbd5e1; text-align:right;'>↳</td>
                                <td style='width:10%; padding:8px 10px; text-align:center; color:#94a3b8;'>-</td>
                                <td style='width:12%; padding:8px 10px; text-align:center;'><code style='color:#1a73e8;'>{$d->utm_campaign}</code></td>
                                <td style='width:12%; padding:8px 10px; text-align:center;'><small style='font-weight:600;'>{$d->utm_term}</small></td>
                                <td style='width:15%; padding:8px 10px;'><a href='".esc_url($d->origin_url)."' target='_blank' style='font-size:11px; text-decoration:none;'>{$path} ↗</a></td>
                                <td style='width:10%; padding:8px 10px; text-align:center; white-space:nowrap;'>{$d_hits_cell}</td>
                                <td style='width:10%; padding:8px 10px; text-align:center; white-space:nowrap;'>{$d_actions_cell}</td>
                                <td style='width:8%; padding:8px 10px; text-align:center; white-space:nowrap;'>{$d_ctr_cell}</td>
                                <td style='width:8%; padding:8px 10px; text-align:center; white-space:nowrap;'>{$d_time_cell}</td>
                            </tr>";
                        }
                    } else {
                        $details_html .= "<tr><td colspan='9' style='text-align:center; padding:20px; color:#94a3b8;'>No detail records found for this timeframe.</td></tr>";
                    }
                    $details_html .= '</tbody></table></div>';

                    // Fila principal (acordeón unificado con advanced_table)
                    $summary_rows[] = [
                        'class'        => 'ois-row-accordion',
                        'details_html' => $details_html,
                        'cols' => [
                            '<span class="j-arrow" style="color:#0284c7;font-size:11px;display:inline-block;margin-right:4px;">▶</span><strong style="color:#1a73e8;">📂 ' . esc_html($sum->label_name) . '</strong>',
                            '<small style="color:#64748b;">' . $start_date . '</small>',
                            '<span class="badge" style="background:#e0f2fe; color:#0369a1; padding:2px 6px; border-radius:4px;">' . $sum->count_campaigns . '</span>',
                            '<span class="badge" style="background:#f0f9ff; color:#075985; padding:2px 6px; border-radius:4px;">' . $sum->count_terms . '</span>',
                            '<span style="color:#cbd5e1; text-align:center; display:block;">-</span>',
                            $hierarchy_metric( '<strong style="font-size:13px;">' . number_format( $t_hits ) . '</strong>', $t_hits, $p_hits ),
                            $hierarchy_metric( '<strong style="color:#166534; font-size:13px;">' . number_format( $t_actions ) . '</strong>', $t_actions, $p_actions ),
                            $hierarchy_metric( '<span>' . $t_ctr . '%</span>', $t_ctr, $p_ctr ),
                            $hierarchy_metric( '<span>' . round( $t_time ) . 's</span>', round( $t_time ), round( $p_time ) ),
                        ]
                    ];
                }
            }

            // --- TABLA 1: RESUMEN DE RENDIMIENTO ---
            $this->render_ois_component('advanced_table', [
                'id'       => 'table-performance-hierarchy',
                'title'    => __( 'Company Performance Hierarchy', 'ois-conversion-suite' ),
                'subtitle' => __( 'Click a company name to expand campaigns and URLs. Green/red badges compare each metric to the previous period of equal length.', 'ois-conversion-suite' ),
                'icon'     => '🏆',
                'headers'  => [
                    ['label' => 'Company / Label', 'width' => '15%', 'type' => 'string'],
                    ['label' => 'Date & Time', 'width' => '10%', 'type' => 'string', 'align' => 'center'],
                    ['label' => 'Campaigns ID', 'width' => '12%', 'type' => 'numeric', 'align' => 'center'],
                    ['label' => 'UTM Terms', 'width' => '12%', 'type' => 'numeric', 'align' => 'center'],
                    ['label' => 'URL Source', 'width' => '15%', 'type' => 'string'],
                    ['label' => 'Total Views', 'width' => '10%', 'type' => 'numeric', 'align' => 'center', 'tooltip' => __( 'vs previous period', 'ois-conversion-suite' )],
                    ['label' => 'Real Clicks', 'width' => '10%', 'type' => 'numeric', 'align' => 'center', 'tooltip' => __( 'vs previous period', 'ois-conversion-suite' )],
                    ['label' => 'CTR', 'width' => '8%', 'type' => 'numeric', 'align' => 'center', 'tooltip' => __( 'vs previous period', 'ois-conversion-suite' )],
                    ['label' => 'Avg Ret.', 'width' => '8%', 'type' => 'string', 'align' => 'center', 'tooltip' => __( 'vs previous period', 'ois-conversion-suite' )],
                ],
                'rows'     => $summary_rows
            ]);

            echo '<div style="margin-top:40px;"></div>';

            $content_saved_links = $wpdb->get_results( "SELECT * FROM $table_refs WHERE 1=1 $filter_sql_refs ORDER BY label_name ASC, created_at DESC" );
            $this->oiscl_render_utm_links_manager_block(
                array(
                    'mode'             => 'embed',
                    'saved_links'      => $content_saved_links,
                    'filter_sql_refs'  => $filter_sql_refs,
                    'filter_sql_stats' => $filter_sql_stats,
                    'start_date'       => $start_date,
                    'end_date'         => $end_date,
                    'table_id'         => 'utm-content-links-table',
                    'section_title'    => __( 'Campaign Links', 'ois-conversion-suite' ),
                    'hide_block_kpis'  => true,
                )
            );

            // --- JAVASCRIPT: gráfico horario UTM + acordeón + copiar URL ---
            ?>
            <script>
            jQuery(document).ready(function($) {
                var ctxUtmC = document.getElementById('oisclUtmContentHourlyChart');
                if (ctxUtmC && typeof Chart !== 'undefined') {
                    new Chart(ctxUtmC.getContext('2d'), {
                        type: 'line',
                        data: {
                            labels: <?php echo wp_json_encode( $utm_hour_labels ); ?>,
                            datasets: [
                                {
                                    label: <?php echo wp_json_encode( __( 'Views', 'ois-conversion-suite' ) ); ?>,
                                    data: <?php echo wp_json_encode( array_values( $utm_bar_views ) ); ?>,
                                    borderColor: '#1a73e8',
                                    backgroundColor: 'rgba(26, 115, 232, 0.1)',
                                    fill: true,
                                    tension: 0.4,
                                    pointRadius: 3
                                },
                                {
                                    label: <?php echo wp_json_encode( __( 'Uniques', 'ois-conversion-suite' ) ); ?>,
                                    data: <?php echo wp_json_encode( array_values( $utm_bar_uniques ) ); ?>,
                                    borderColor: '#d63638',
                                    backgroundColor: 'transparent',
                                    tension: 0.4,
                                    pointRadius: 3
                                },
                                {
                                    label: <?php echo wp_json_encode( __( 'Past views', 'ois-conversion-suite' ) ); ?>,
                                    data: <?php echo wp_json_encode( array_values( $utm_prev_bar_views ) ); ?>,
                                    borderColor: '#1a73e8',
                                    borderDash: [5, 5],
                                    backgroundColor: 'transparent',
                                    fill: false,
                                    tension: 0.4,
                                    pointRadius: 0
                                },
                                {
                                    label: <?php echo wp_json_encode( __( 'Past uniques', 'ois-conversion-suite' ) ); ?>,
                                    data: <?php echo wp_json_encode( array_values( $utm_prev_bar_uniques ) ); ?>,
                                    borderColor: '#d63638',
                                    borderDash: [5, 5],
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
                            interaction: { mode: 'index', intersect: false },
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                    labels: { padding: 16, usePointStyle: true }
                                },
                                tooltip: { mode: 'index', intersect: false }
                            },
                            scales: {
                                y: { beginAtZero: true, grid: { color: '#f0f0f1' } },
                                x: { grid: { display: false } }
                            }
                        }
                    });
                }

                // Acordeón journey: lo maneja advanced_table (ois-row-accordion + details_html).

                if (typeof window.oisclSetupAdvancedTable === 'function') {
                    ['table-performance-hierarchy', 'utm-content-links-table'].forEach(function(tid) {
                        if (document.getElementById(tid)) {
                            window.oisclSetupAdvancedTable(tid);
                        }
                    });
                }

            });
            </script>
            <?php
        }

        // ==========================================
        // TAB 3: AUDIENCE - OIS ANALYTICS EXACT CLONE
        // ==========================================
        elseif ($active_tab === 'audience') {

            $this->oiscl_render_utm_tab_kpi_row( $live_views, $utm_hits, $prev_utm_hits, $utm_users, $prev_utm_users, $utm_actions, $prev_utm_actions, $utm_ctr, $prev_utm_ctr );

            // 1. Consultas de agrupación (mismo rango de fechas que el resto del UTM Tracker: DATE + start/end).
            $devices_data  = $wpdb->get_results( $wpdb->prepare( "SELECT device as label, SUM(clicks) as total FROM $table_stats WHERE utm_campaign != '' $filter_sql_stats AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY device ORDER BY total DESC", $start_date, $end_date ) );
            $os_data       = $wpdb->get_results( $wpdb->prepare( "SELECT os as label, SUM(clicks) as total FROM $table_stats WHERE utm_campaign != '' $filter_sql_stats AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY os ORDER BY total DESC", $start_date, $end_date ) );
            $browsers_data = $wpdb->get_results( $wpdb->prepare( "SELECT browser as label, SUM(clicks) as total FROM $table_stats WHERE utm_campaign != '' $filter_sql_stats AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY browser ORDER BY total DESC", $start_date, $end_date ) );
            $res_data      = $wpdb->get_results( $wpdb->prepare( "SELECT screen_res as label, SUM(clicks) as total FROM $table_stats WHERE utm_campaign != '' $filter_sql_stats AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY screen_res ORDER BY total DESC", $start_date, $end_date ) );

            // 2. Listas top: tráfico, geo y desglose UTM (shared with CSV export).
            $traffic_data       = $this->oiscl_get_utm_audience_list_data( 'traffic', $table_stats, $start_date, $end_date, $filter_sql_stats );
            $countries_data     = $this->oiscl_get_utm_audience_list_data( 'countries', $table_stats, $start_date, $end_date, $filter_sql_stats );
            $cities_data        = $this->oiscl_get_utm_audience_list_data( 'cities', $table_stats, $start_date, $end_date, $filter_sql_stats );
            $utm_campaigns_data = $this->oiscl_get_utm_audience_list_data( 'utm_campaigns', $table_stats, $start_date, $end_date, $filter_sql_stats );
            $utm_terms_data     = $this->oiscl_get_utm_audience_list_data( 'utm_terms', $table_stats, $start_date, $end_date, $filter_sql_stats );
            $utm_landings_data  = $this->oiscl_get_utm_audience_list_data( 'utm_landings', $table_stats, $start_date, $end_date, $filter_sql_stats );
            $utm_sources_data   = $this->oiscl_get_utm_audience_list_data( 'utm_sources', $table_stats, $start_date, $end_date, $filter_sql_stats );
            $utm_mediums_data   = $this->oiscl_get_utm_audience_list_data( 'utm_mediums', $table_stats, $start_date, $end_date, $filter_sql_stats );

            $aud_export_base = array(
                'page'       => 'oiscl-utm-tracker',
                'export_csv' => 'utm_audience',
                'start_date' => $start_date,
                'end_date'   => $end_date,
            );
            if ( 'all' !== $selected_filter ) {
                $aud_export_base['utm_filter'] = $selected_filter;
            }
            $utm_admin = $this;
            // 3. Preparación de Datos para el "Top User Profile" (Barras Verticales)
            $top_profile_labels = ['Top Device', 'Top OS', 'Top Browser', 'Top Res'];
            $top_profile_values = [
                !empty($devices_data) ? (int)$devices_data[0]->total : 0,
                !empty($os_data) ? (int)$os_data[0]->total : 0,
                !empty($browsers_data) ? (int)$browsers_data[0]->total : 0,
                !empty($res_data) ? (int)$res_data[0]->total : 0
            ];
            $top_profile_names = [
                !empty($devices_data) ? $devices_data[0]->label : 'N/A',
                !empty($os_data) ? $os_data[0]->label : 'N/A',
                !empty($browsers_data) ? $browsers_data[0]->label : 'N/A',
                !empty($res_data) ? $res_data[0]->label : 'N/A'
            ];

            // --- FUNCIÓN: Renderizador de Tarjetas de Gráficos (Default: PIE) ---
            $render_chart_card = function($id, $title, $icon) {
                echo '<div class="ois-box" style="background:#fff; padding:20px; border:1px solid #ccd0d4; border-radius:4px; display:flex; flex-direction:column;">';
                    echo '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">';
                        echo '<h3 class="ois-block-title">'.$icon.' '.$title.'</h3>';
                        echo '<select class="oiscl-chart-selector" data-target="'.$id.'" style="font-size:11px; padding:0 4px; min-height:22px; cursor:pointer;">
                                <option value="pie" selected>Pie</option>
                                <option value="doughnut">Doughnut</option>
                                <option value="bar">Bar</option>
                              </select>';
                    echo '</div>';
                    echo '<div class="ois-canvas-container" style="flex-grow:1; position:relative; min-height:180px;">';
                        echo '<canvas id="'.$id.'"></canvas>';
                    echo '</div>';
                echo '</div>';
            };

            
            // --- FUNCIÓN: Renderizador de Listas de Rango con Export & Pagination ---
            $render_top_list = function( $list_id, $title, $icon, $data_array, $list_key ) use ( $utm_admin, $aud_export_base ) {
                echo '<div id="wrap-' . esc_attr( $list_id ) . '" class="ois-box" style="background:#fff; padding:20px; border:1px solid #ccd0d4; border-radius:4px; display:flex; flex-direction:column;">';
                    // Header con Botones Export (AHORA CON CLASE button-primary PARA FONDO AZUL Y LETRA BLANCA)
                    echo '<div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #f1f5f9; padding-bottom:10px; margin-bottom:15px;">';
                        echo '<h3 class="ois-block-title">' . $icon . ' ' . esc_html( $title ) . '</h3>';
                        $utm_admin->render_ois_component(
                            'export_menu',
                            array(
                                'id'                => 'exp-aud-list-' . sanitize_key( $list_key ),
                                'csv_url'           => admin_url( 'admin.php?' . http_build_query( array_merge( $aud_export_base, array( 'audience_list' => $list_key ) ) ) ),
                                'wrap_pdf_id'       => 'wrap-' . sanitize_html_class( $list_id ),
                                'wrap_pdf_filename' => sanitize_file_name( $title ) . '.pdf',
                            )
                        );
                    echo '</div>';
                    
                    if (empty($data_array)) {
                        echo '<div style="color:#94a3b8; font-size:12px; text-align:center; padding:20px 0; flex-grow:1;">No data available</div>';
                    } else {
                        echo '<div style="flex-grow:1;">';
                        echo '<ul id="'.$list_id.'" class="oiscl-paginated-list" data-current-page="1" style="margin:0; padding:0; list-style:none;">';
                        $max_val = $data_array[0]->total; 
                        foreach ($data_array as $index => $item) {
                            $pct = $max_val > 0 ? round(($item->total / $max_val) * 100) : 0;
                            $label = empty($item->label) ? 'Unknown' : esc_html($item->label);
                            // Ocultamos los que pasen de 6
                            $display = $index >= 6 ? 'display:none;' : '';
                            echo '<li class="oiscl-list-item" data-index="'.$index.'" style="margin-bottom:12px; '.$display.'">';
                                echo '<div style="display:flex; justify-content:space-between; font-size:12px; margin-bottom:4px;">';
                                    echo '<span style="color:#475569; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:70%;">'.($index + 1).'. '.$label.'</span>';
                                    echo '<strong style="color:#0f172a;">'.number_format($item->total).'</strong>';
                                echo '</div>';
                                echo '<div style="background:#f1f5f9; height:6px; border-radius:3px; overflow:hidden;">';
                                    echo '<div style="background:#1a73e8; height:100%; width:'.$pct.'%; border-radius:3px;"></div>';
                                echo '</div>';
                            echo '</li>';
                        }
                        echo '</ul>';
                        echo '</div>';

                        // Paginador (TAMBIÉN CON CLASE button-primary)
                        $total_items = count($data_array);
                        if ($total_items > 6) {
                            $total_pages = ceil($total_items / 6);
                            echo '<div style="display:flex; justify-content:space-between; align-items:center; margin-top:10px; border-top:1px solid #f1f5f9; padding-top:10px;">';
                                echo '<button class="button button-primary button-small oiscl-btn-prev" data-target="'.$list_id.'" disabled>« Prev</button>';
                                echo '<span style="font-size:11px; color:#64748b;"><span class="oiscl-page-current">1</span> of '.$total_pages.'</span>';
                                echo '<button class="button button-primary button-small oiscl-btn-next" data-target="'.$list_id.'" data-total="'.$total_pages.'">Next »</button>';
                            echo '</div>';
                        }
                    }
                echo '</div>';
            };

            // --- ROW 1: 4 COLUMNAS DE GRÁFICOS (Default: PIE) ---
            echo '<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:20px; margin-bottom:20px;">';
                $render_chart_card('chartDevices', 'Device Share', '📱');
                $render_chart_card('chartOS', 'OS Share', '💻');
                $render_chart_card('chartBrowsers', 'Browser Share', '🌐');
                $render_chart_card('chartRes', 'Screen Res.', '🖥️');
            echo '</div>';

            // --- ROW 2: 4 COLUMNAS DE MÉTRICAS TOP (Con Barras Verticales y Paginación) ---
            echo '<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:20px;">';
                
                // Tarjeta 1: Top User Profile (Ahora sí, con Barras Verticales)
                echo '<div class="ois-box" style="background:#fff; padding:20px; border:1px solid #ccd0d4; border-radius:4px; display:flex; flex-direction:column; border-top: 4px solid #f56e28;">';
                    echo '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">';
                        echo '<h3 class="ois-block-title ois-block-title--eyebrow">👑 Top User Profile</h3>';
                    echo '</div>';
                    echo '<div class="ois-canvas-container" style="flex-grow:1; position:relative; min-height:180px;">';
                        echo '<canvas id="chartTopProfile"></canvas>';
                    echo '</div>';
                echo '</div>';

                // Tarjetas 2, 3 y 4: Top Lists con CSV/PDF y Paginación
                $render_top_list('listTraffic', 'Top traffic & referrers', '🔗', $traffic_data, 'traffic');
                $render_top_list('listCountry', 'Top Countries', '🌎', $countries_data, 'countries');
                $render_top_list('listCity', 'Top Cities', '🏙️', $cities_data, 'cities');
                
            echo '</div>';

            // --- ROW 3: desglose UTM (campañas, términos, URLs de entrada) ---
            echo '<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:20px; margin-top:20px;">';
                $render_top_list('listUtmCampaigns', 'Top UTM campaigns', '🎯', $utm_campaigns_data, 'utm_campaigns');
                $render_top_list('listUtmTerms', 'Top UTM terms', '🏷️', $utm_terms_data, 'utm_terms');
                $render_top_list('listUtmLandings', 'Top landing pages (origin)', '📄', $utm_landings_data, 'utm_landings');
            echo '</div>';

            // --- ROW 4: UTM source / medium ---
            echo '<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:20px; margin-top:20px;">';
                $render_top_list('listUtmSources', 'Top UTM sources', '📡', $utm_sources_data, 'utm_sources');
                $render_top_list('listUtmMediums', 'Top UTM mediums', '📣', $utm_mediums_data, 'utm_mediums');
            echo '</div>';

            // --- JAVASCRIPT: MOTOR DE CHART.JS Y PAGINACIÓN ---
            ?>
            <script>
            jQuery(document).ready(function($) {
                const colors = ['#1a73e8', '#46b450', '#f56e28', '#722ed1', '#eb2f96', '#faad14', '#13c2c2', '#a0d911'];
                const chartInstances = {};

                function initChart(ctxId, type, rawData) {
                    const ctx = document.getElementById(ctxId);
                    if (!ctx) return;
                    
                    if(chartInstances[ctxId]) chartInstances[ctxId].destroy();

                    if (!rawData || rawData.length === 0) {
                        const parent = ctx.parentElement;
                        parent.innerHTML = '<div style="display:flex; height:100%; align-items:center; justify-content:center; color:#94a3b8; font-size:12px; font-weight:600;">No data available</div>';
                        return;
                    }

                    const labels = rawData.map(d => d.label || 'Unknown');
                    const dataVals = rawData.map(d => parseInt(d.total) || 0);

                    let options = {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: type !== 'bar', position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } } }
                    };

                    if (type === 'bar') {
                        options.scales = { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { grid: { display: false }, ticks: { display: false } } };
                    }

                    chartInstances[ctxId] = new Chart(ctx.getContext('2d'), {
                        type: type,
                        data: {
                            labels: labels,
                            datasets: [{ label: 'Visits', data: dataVals, backgroundColor: colors, borderWidth: 1 }]
                        },
                        options: options
                    });
                }

                // 1. Inicialización de los Gráficos Superiores (PIE por defecto)
                const dDevices = <?php echo json_encode($devices_data); ?>;
                const dOS      = <?php echo json_encode($os_data); ?>;
                const dBrowsers= <?php echo json_encode($browsers_data); ?>;
                const dRes     = <?php echo json_encode($res_data); ?>;

                initChart('chartDevices', 'pie', dDevices);
                initChart('chartOS', 'pie', dOS);
                initChart('chartBrowsers', 'pie', dBrowsers);
                initChart('chartRes', 'pie', dRes);

                // 2. Inicialización del Top User Profile (Barras Verticales)
                const topProfileLabels = <?php echo json_encode($top_profile_names); ?>;
                const topProfileVals = <?php echo json_encode($top_profile_values); ?>;
                
                const ctxProfile = document.getElementById('chartTopProfile');
                if (ctxProfile && topProfileVals.some(v => v > 0)) {
                    new Chart(ctxProfile.getContext('2d'), {
                        type: 'bar',
                        data: {
                            labels: topProfileLabels,
                            datasets: [{
                                label: 'Top Usage',
                                data: topProfileVals,
                                backgroundColor: ['#1a73e8', '#46b450', '#f56e28', '#722ed1'],
                                borderRadius: 4
                            }]
                        },
                        options: {
                            responsive: true, maintainAspectRatio: false,
                            plugins: { legend: { display: false } },
                            scales: { y: { beginAtZero: true }, x: { grid: { display: false } } }
                        }
                    });
                } else if(ctxProfile) {
                    ctxProfile.parentElement.innerHTML = '<div style="display:flex; height:100%; align-items:center; justify-content:center; color:#94a3b8; font-size:12px; font-weight:600;">No data available</div>';
                }

                // 3. Control de Selectores
                $('.oiscl-chart-selector').on('change', function() {
                    const targetId = $(this).data('target');
                    const newType = $(this).val();
                    let dataToUse = [];
                    if(targetId === 'chartDevices') dataToUse = dDevices;
                    if(targetId === 'chartOS') dataToUse = dOS;
                    if(targetId === 'chartBrowsers') dataToUse = dBrowsers;
                    if(targetId === 'chartRes') dataToUse = dRes;
                    initChart(targetId, newType, dataToUse);
                });

                // 4. Lógica de Paginación (6 por página)
                $('.oiscl-btn-next, .oiscl-btn-prev').on('click', function(e) {
                    e.preventDefault();
                    const listId = $(this).data('target');
                    const $list = $('#' + listId);
                    const totalPages = parseInt($(this).siblings('.oiscl-btn-next').data('total') || $(this).data('total'));
                    let currentPage = parseInt($list.attr('data-current-page'));

                    if ($(this).hasClass('oiscl-btn-next') && currentPage < totalPages) currentPage++;
                    else if ($(this).hasClass('oiscl-btn-prev') && currentPage > 1) currentPage--;

                    $list.attr('data-current-page', currentPage);
                    $(this).siblings('span').find('.oiscl-page-current').text(currentPage);

                    // Actualizar botones
                    $(this).parent().find('.oiscl-btn-prev').prop('disabled', currentPage === 1);
                    $(this).parent().find('.oiscl-btn-next').prop('disabled', currentPage === totalPages);

                    // Ocultar/Mostrar elementos
                    const start = (currentPage - 1) * 6;
                    const end = start + 6;
                    $list.find('.oiscl-list-item').each(function() {
                        const idx = parseInt($(this).data('index'));
                        if(idx >= start && idx < end) $(this).show();
                        else $(this).hide();
                    });
                });
            });
            </script>
            <?php
        }

        elseif ( $active_tab === 'funnel' ) {
            $this->oiscl_render_utm_funnel_tab(
                array(
                    'table_refs'       => $table_refs,
                    'table_stats'      => $table_stats,
                    'start_date'       => $start_date,
                    'end_date'         => $end_date,
                    'filter_sql_refs'  => $filter_sql_refs,
                    'filter_sql_stats' => $filter_sql_stats,
                    'selected_filter'  => $selected_filter,
                    'live_views'       => $live_views,
                    'utm_hits'         => $utm_hits,
                    'prev_utm_hits'    => $prev_utm_hits,
                    'utm_users'        => $utm_users,
                    'prev_utm_users'   => $prev_utm_users,
                    'utm_actions'      => $utm_actions,
                    'prev_utm_actions' => $prev_utm_actions,
                    'utm_ctr'          => $utm_ctr,
                    'prev_utm_ctr'     => $prev_utm_ctr,
                )
            );
        }

        elseif ( $active_tab === 'click_tracker' ) {
            $this->oiscl_render_utm_click_tracker_tab(
                array(
                    'table_stats'      => $table_stats,
                    'start_date'       => $start_date,
                    'end_date'         => $end_date,
                    'prev_start'       => $prev_start,
                    'prev_end'         => $prev_end,
                    'today'            => $today,
                    'filter_sql_stats' => $filter_sql_stats,
                    'selected_filter'  => $selected_filter,
                    'filter_hierarchy' => $filter_hierarchy,
                    'live_views'       => $live_views,
                    'utm_hits'         => $utm_hits,
                    'prev_utm_hits'    => $prev_utm_hits,
                    'utm_users'        => $utm_users,
                    'prev_utm_users'   => $prev_utm_users,
                    'utm_actions'      => $utm_actions,
                    'prev_utm_actions' => $prev_utm_actions,
                    'utm_ctr'          => $utm_ctr,
                    'prev_utm_ctr'     => $prev_utm_ctr,
                )
            );
        }

        // ==========================================
        // TAB 4: UTM JOURNEY
        // ==========================================
        elseif ($active_tab === 'journey') {

            $this->oiscl_render_utm_tab_kpi_row( $live_views, $utm_hits, $prev_utm_hits, $utm_users, $prev_utm_users, $utm_actions, $prev_utm_actions, $utm_ctr, $prev_utm_ctr );

            $utm_attr_mode = $this->oiscl_get_utm_journey_attribution_mode();

            $utm_journey_csv_args = array(
                'page'        => 'oiscl-utm-tracker',
                'export_csv'  => 'utm_journey',
                'start_date'  => $start_date,
                'end_date'    => $end_date,
                'utm_attr'    => $utm_attr_mode,
            );
            if ( 'all' !== $selected_filter ) {
                $utm_journey_csv_args['utm_filter'] = $selected_filter;
            }
            $utm_journey_csv_url      = admin_url( 'admin.php?' . http_build_query( $utm_journey_csv_args ) );
            $utm_journey_csv_full_url = admin_url( 'admin.php?' . http_build_query( array_merge( $utm_journey_csv_args, array( 'full' => '1' ) ) ) );

            $utm_journey_sessions = $this->get_oiscl_utm_journey_sessions( $start_date, $end_date, $filter_sql_stats, $utm_attr_mode );
            $utm_journey_rows     = $this->oiscl_build_journey_advanced_table_rows( $utm_journey_sessions, 'utm' );

            $utm_journey_toolbar = '<div style="display:flex;align-items:center;flex-wrap:wrap;gap:10px;">'
                . '<button type="button" class="button" onclick="jQuery(\'#wrap-table-utm-journey .ois-row-details\').hide(); jQuery(\'#wrap-table-utm-journey .j-arrow\').css(\'transform\', \'rotate(0deg)\');">' . esc_html__( 'Collapse All ▴', 'ois-conversion-suite' ) . '</button>'
                . '<label for="oiscl-utm-filter-journey" style="font-weight:600;font-size:13px;color:#50575e;margin:0;">' . esc_html__( 'Filter', 'ois-conversion-suite' ) . ':</label>'
                . $this->oiscl_get_utm_tracker_filter_select_html( $selected_filter, $filter_hierarchy, __( 'All', 'ois-conversion-suite' ), 'oiscl-utm-filter-journey' )
                . '<label for="oiscl-utm-attr-journey" style="font-weight:600;font-size:13px;color:#50575e;margin:0;">' . esc_html__( 'Attribution', 'ois-conversion-suite' ) . ':</label>'
                . $this->oiscl_get_utm_attr_select_html( $utm_attr_mode, 'oiscl-utm-attr-journey' )
                . '</div>';

            $this->render_ois_component(
                'advanced_table',
                array(
                    'id'        => 'table-utm-journey',
                    'title'     => __( 'UTM User Journey', 'ois-conversion-suite' ),
                    'subtitle'  => sprintf(
                        /* translators: %d: max table rows (journey session limit) */
                        __( 'Sessions with at least one UTM hit in the range. Use Attribution to switch first touch, last touch, or session landing (UTM on pageview). Table and default CSV list up to %d rows; Export → full census for all sessions.', 'ois-conversion-suite' ),
                        $this->oiscl_get_journey_session_limit()
                    ),
                    'icon'      => '🕵️‍♂️',
                    'toolbar'   => $utm_journey_toolbar,
                    'csv'       => $utm_journey_csv_url,
                    'csv_full_census_url' => $utm_journey_csv_full_url,
                    'pdf'       => '',
                    'headers'   => array(
                        array(
                            'label'   => __( 'Identity', 'ois-conversion-suite' ),
                            'width'   => '13%',
                            'type'    => 'string',
                            'tooltip' => __( 'Company or label from references, matched to the attributed campaign for the selected model.', 'ois-conversion-suite' ),
                        ),
                        array(
                            'label'   => __( 'Campaign ID', 'ois-conversion-suite' ),
                            'width'   => '10%',
                            'type'    => 'string',
                            'tooltip' => __( 'utm_campaign for the selected attribution model (first / last / session landing).', 'ois-conversion-suite' ),
                        ),
                        array(
                            'label'   => __( 'UTM term', 'ois-conversion-suite' ),
                            'width'   => '8%',
                            'type'    => 'string',
                            'tooltip' => __( 'utm_term stored on that same first UTM hit, when present.', 'ois-conversion-suite' ),
                        ),
                        array(
                            'label'   => __( 'Source / Medium', 'ois-conversion-suite' ),
                            'width'   => '10%',
                            'type'    => 'string',
                            'tooltip' => __( 'utm_source and utm_medium captured on the landing URL for the first UTM hit, when the tracker sent them.', 'ois-conversion-suite' ),
                        ),
                        array(
                            'label'   => __( 'Date', 'ois-conversion-suite' ),
                            'width'   => '7%',
                            'type'    => 'string',
                            'align'   => 'center',
                            'tooltip' => __( 'Calendar date of the session start (first metric row aggregated for this session).', 'ois-conversion-suite' ),
                        ),
                        array(
                            'label'   => __( 'Entry Time', 'ois-conversion-suite' ),
                            'width'   => '7%',
                            'type'    => 'string',
                            'align'   => 'center',
                            'tooltip' => __( 'Clock time when the first hit in the session was recorded.', 'ois-conversion-suite' ),
                        ),
                        array(
                            'label'   => __( 'Navigation Route', 'ois-conversion-suite' ),
                            'width'   => '14%',
                            'type'    => 'string',
                            'tooltip' => __( 'Short path built from page filenames in visit order (first three unique steps, then ellipsis if there are more).', 'ois-conversion-suite' ),
                        ),
                        array(
                            'label'   => __( 'Time', 'ois-conversion-suite' ),
                            'width'   => '6%',
                            'type'    => 'string',
                            'align'   => 'center',
                            'tooltip' => __( 'Approximate time on site: span from first to last tracked step in this session.', 'ois-conversion-suite' ),
                        ),
                        array(
                            'label'   => __( 'Clicks', 'ois-conversion-suite' ),
                            'width'   => '6%',
                            'type'    => 'numeric',
                            'align'   => 'center',
                            'tooltip' => __( 'Number of interaction rows counted as clicks (excludes synthetic pageview/block/404 rows in the session log).', 'ois-conversion-suite' ),
                        ),
                        array(
                            'label'   => __( 'Location', 'ois-conversion-suite' ),
                            'width'   => '9%',
                            'type'    => 'string',
                            'tooltip' => __( 'City and country from the latest values stored for this session in the range.', 'ois-conversion-suite' ),
                        ),
                        array(
                            'label'   => __( 'Resolution', 'ois-conversion-suite' ),
                            'width'   => '7%',
                            'type'    => 'string',
                            'align'   => 'center',
                            'tooltip' => __( 'Screen resolution when the tracker sent a non-empty value (best non-placeholder value in the session).', 'ois-conversion-suite' ),
                        ),
                        array(
                            'label'   => __( 'Tech', 'ois-conversion-suite' ),
                            'width'   => '9%',
                            'type'    => 'string',
                            'align'   => 'center',
                            'tooltip' => __( 'Device, operating system, browser (icons) and browser language code for the session.', 'ois-conversion-suite' ),
                        ),
                    ),
                    'rows'      => $utm_journey_rows,
                )
            );

            $raw_log_nonce = wp_create_nonce( 'oiscl_admin_nonce' );
            echo '<div id="oiscl-utm-raw-log-lazy-wrap" style="margin-top:28px;">';
            echo '<p style="color:#64748b; font-size:13px; margin:0 0 12px 0;">' . esc_html__( 'Forensic list: up to 300 most recent metric rows with UTM in this date range and filter. Not loaded until you request it (saves database work on every page load).', 'ois-conversion-suite' ) . '</p>';
            echo '<p style="margin:0 0 16px 0;"><button type="button" class="button button-primary" id="oiscl-btn-load-utm-raw-log" data-start="' . esc_attr( $start_date ) . '" data-end="' . esc_attr( $end_date ) . '" data-filter="' . esc_attr( $selected_filter ) . '" data-nonce="' . esc_attr( $raw_log_nonce ) . '">' . esc_html__( 'Load raw activity log', 'ois-conversion-suite' ) . '</button> <span id="oiscl-utm-raw-log-status" style="margin-left:10px;color:#64748b;font-size:12px;"></span></p>';
            echo '<div id="oiscl-utm-raw-log-mount"></div>';
            echo '</div>';
        }

        echo '</div>'; // Cierre wrap
        $this->render_ois_component('layout_end');
        ?>
        
        <script>
        jQuery(document).ready(function($) {
            // Reloj y Pulso Global
            function updateSync() {
                var now = new Date();
                $('#oiscl-clock, .oiscl-header-clock, .oiscl-live-clock').text(now.toLocaleDateString() + ' ' + now.toLocaleTimeString());
            }
            setInterval(updateSync, 1000); updateSync();

            // LIVE NOW: solo si existe el span del header (evita AJAX cada X s sin objetivo). Pausa con pestaña en segundo plano. Intervalo 30 s para no saturar DevTools / servidor.
            var $oisclOnlineTargets = $('#oiscl-online-users, .oiscl-online-count').filter(function() { return $(this).closest('.ois-kpi-card').length; });
            var oisclOnlineIntervalMs = 30000;
            function oisclUpdateOnline() {
                if (document.hidden || !$oisclOnlineTargets.length) {
                    return;
                }
                $.post(ajaxurl, { action: 'oiscl_get_pulse_data', nonce: '<?php echo wp_create_nonce( 'oiscl_admin_nonce' ); ?>' })
                    .done(function(response) {
                        if (response && response.success && typeof response.data.online_users !== 'undefined') {
                            $oisclOnlineTargets.text(response.data.online_users);
                        }
                    });
            }
            if ($oisclOnlineTargets.length) {
                setInterval(oisclUpdateOnline, oisclOnlineIntervalMs);
                oisclUpdateOnline();
                document.addEventListener('visibilitychange', function() {
                    if (!document.hidden) {
                        oisclUpdateOnline();
                    }
                });
            }

            // Filtro UTM: selects con clase oiscl-utm-filter-redirect (chart Content & CRO, Journey…).
            // UTM Click Tracker usa #oiscl-utm-ct-filter + script propio (tab + uct_tab + tp_page); no pisar href aquí.
            $(document).on('change', '.oiscl-utm-filter-redirect', function() {
                if (this.id === 'oiscl-utm-ct-filter') {
                    return;
                }
                var url = new URL(window.location.href);
                var v = $(this).val();
                if (v && v !== 'all') {
                    url.searchParams.set('utm_filter', v);
                } else {
                    url.searchParams.delete('utm_filter');
                }
                window.location.href = url.toString();
            });

            $(document).on('change', '.oiscl-utm-attr-redirect', function() {
                var url = new URL(window.location.href);
                url.searchParams.set('utm_attr', $(this).val());
                window.location.href = url.toString();
            });

            var $rawBtn = $('#oiscl-btn-load-utm-raw-log');
            if ($rawBtn.length) {
                var rawErr = <?php echo wp_json_encode( esc_html__( 'Could not load the log.', 'ois-conversion-suite' ) ); ?>;
                var rawReload = <?php echo wp_json_encode( esc_html__( 'Reload raw log', 'ois-conversion-suite' ) ); ?>;
                $rawBtn.on('click', function() {
                    var btn = $(this);
                    if (btn.prop('disabled')) return;
                    btn.prop('disabled', true);
                    $('#oiscl-utm-raw-log-status').text('…');
                    $.post(ajaxurl, {
                        action: 'oiscl_utm_raw_log',
                        nonce: btn.data('nonce'),
                        start_date: btn.data('start'),
                        end_date: btn.data('end'),
                        utm_filter: String(btn.data('filter') || 'all')
                    }).done(function(r) {
                        if (r.success && r.data && r.data.html) {
                            $('#oiscl-utm-raw-log-mount').html(r.data.html);
                            $('#oiscl-utm-raw-log-status').text('');
                            if (typeof window.oisclSetupAdvancedTable === 'function') {
                                window.oisclSetupAdvancedTable('table-utm-raw-log');
                            }
                            if (typeof window.oisclAttachThResizers === 'function') {
                                var tbl = document.getElementById('table-utm-raw-log');
                                if (tbl) { window.oisclAttachThResizers(tbl); }
                            }
                            btn.text(rawReload).prop('disabled', false);
                        } else {
                            $('#oiscl-utm-raw-log-status').text(rawErr);
                            btn.prop('disabled', false);
                        }
                    }).fail(function() {
                        $('#oiscl-utm-raw-log-status').text(rawErr);
                        btn.prop('disabled', false);
                    });
                });
            }
        });
        </script>
        <?php
    }

    /**
     * AJAX: HTML fragment for UTM raw activity log (advanced_table).
     */
    public function ajax_oiscl_utm_raw_log() {
        if ( ! check_ajax_referer( 'oiscl_admin_nonce', 'nonce', false ) || ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
        }
        $start  = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
        $end    = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';
        $filter = isset( $_POST['utm_filter'] ) ? sanitize_text_field( wp_unslash( $_POST['utm_filter'] ) ) : 'all';
        if ( '' === $start || '' === $end || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end ) ) {
            wp_send_json_error( array( 'message' => 'Invalid dates' ), 400 );
        }
        $html = $this->oiscl_get_utm_raw_log_advanced_table_html( $start, $end, $filter );
        wp_send_json_success( array( 'html' => $html ) );
    }

    /**
     * @param string $start_date Y-m-d
     * @param string $end_date   Y-m-d
     * @param string $selected_filter utm_filter value
     * @return string HTML (wrap-table-* included by advanced_table).
     */
    private function oiscl_get_utm_raw_log_advanced_table_html( $start_date, $end_date, $selected_filter ) {
        global $wpdb;
        $table_stats      = $wpdb->prefix . 'oiscl_block_metrics';
        $utm_filters      = $this->get_oiscl_utm_dashboard_filters( $selected_filter );
        $filter_sql_stats = $utm_filters['filter_sql_stats'];
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name + filter fragment from internal helpers.
        $sql  = $wpdb->prepare(
            "SELECT * FROM `{$table_stats}` WHERE utm_campaign != '' {$filter_sql_stats} AND DATE(created_at) >= %s AND DATE(created_at) <= %s ORDER BY created_at DESC LIMIT 300",
            $start_date,
            $end_date
        );
        $logs = $wpdb->get_results( $sql );
        $rows = $this->oiscl_build_utm_raw_log_table_rows( $logs );
        ob_start();
        $this->render_ois_component(
            'advanced_table',
            array(
                'id'                 => 'table-utm-raw-log',
                'title'              => __( 'Live Activity Feed (Raw Log)', 'ois-conversion-suite' ),
                'subtitle'           => __( 'Each row is one stored metric event with a non-empty utm_campaign. Expand a row for full URLs, UTM fields, device, resolution, and geo.', 'ois-conversion-suite' ),
                'icon'               => '📡',
                'toolbar'            => '',
                'csv'                => '',
                'pdf'                => '',
                'table_csv_target'   => 'table-utm-raw-log',
                'headers'            => array(
                    array(
                        'label'   => __( 'Date & time', 'ois-conversion-suite' ),
                        'width'   => '15%',
                        'type'    => 'string',
                        'tooltip' => __( 'Server timestamp when this metric row was stored.', 'ois-conversion-suite' ),
                    ),
                    array(
                        'label'   => __( 'Session', 'ois-conversion-suite' ),
                        'width'   => '14%',
                        'type'    => 'string',
                        'tooltip' => __( 'Human vs bot flag and a short preview of the session id. Full id is in the expanded panel.', 'ois-conversion-suite' ),
                    ),
                    array(
                        'label'   => __( 'Traffic source', 'ois-conversion-suite' ),
                        'width'   => '12%',
                        'type'    => 'string',
                        'tooltip' => __( 'Classifier written by the tracker (e.g. Direct, Google Ads) for this hit.', 'ois-conversion-suite' ),
                    ),
                    array(
                        'label'   => __( 'Action', 'ois-conversion-suite' ),
                        'width'   => '14%',
                        'type'    => 'string',
                        'tooltip' => __( 'Synthetic pageview/read vs a tracked click action.', 'ois-conversion-suite' ),
                    ),
                    array(
                        'label'   => __( 'Element / context', 'ois-conversion-suite' ),
                        'width'   => '22%',
                        'type'    => 'string',
                        'tooltip' => __( 'Anchor text (or synthetic label) plus short context from the tracker.', 'ois-conversion-suite' ),
                    ),
                    array(
                        'label'   => __( 'Origin page', 'ois-conversion-suite' ),
                        'width'   => '13%',
                        'type'    => 'string',
                        'tooltip' => __( 'Path of the page URL where the event was recorded (link opens full URL).', 'ois-conversion-suite' ),
                    ),
                ),
                'rows'               => $rows,
            )
        );
        return ob_get_clean();
    }

    /**
     * @param array<int, object>|null $logs DB rows.
     * @return array<int, array<string, mixed>>
     */
    private function oiscl_build_utm_raw_log_table_rows( $logs ) {
        $out = array();
        if ( empty( $logs ) || ! is_array( $logs ) ) {
            return $out;
        }
        foreach ( $logs as $log ) {
            $arrow_color = ! empty( $log->is_bot ) ? '#d63638' : '#46b450';
            $is_read     = $this->oiscl_utm_is_read_event( isset( $log->anchor_text ) ? (string) $log->anchor_text : '' );
            $action_lbl  = $is_read
                ? '<span style="color:#64748b;">👁️ ' . esc_html__( 'Pageview / read', 'ois-conversion-suite' ) . '</span>'
                : '<span style="color:#166534;font-weight:600;">🎯 ' . esc_html__( 'Click', 'ois-conversion-suite' ) . '</span>';
            $sid         = isset( $log->session_id ) ? (string) $log->session_id : '';
            $sid_preview = $sid !== '' ? esc_html( substr( $sid, 0, 14 ) ) . '…' : '—';
            $bot_human   = ! empty( $log->is_bot )
                ? '<span style="color:#ef4444;">🤖 ' . esc_html__( 'Bot', 'ois-conversion-suite' ) . '</span>'
                : '<span style="color:#10b981;">👤 ' . esc_html__( 'Human', 'ois-conversion-suite' ) . '</span>';
            $identity    = '<div style="display:flex;flex-wrap:wrap;align-items:center;gap:4px 8px;"><span style="color:' . esc_attr( $arrow_color ) . ';font-size:12px;" class="j-arrow">▶</span><span>' . $bot_human . '</span><code style="font-size:10px;background:#f8fafc;padding:2px 6px;">' . $sid_preview . '</code></div>';

            $element_display = $is_read
                ? '<span style="color:#64748b;font-size:12px;">' . esc_html( $this->oiscl_utm_format_event_label( isset( $log->anchor_text ) ? (string) $log->anchor_text : '', isset( $log->context_text ) ? (string) $log->context_text : '' ) ) . '</span>'
                : '<strong style="color:#0f172a;font-size:13px;">' . esc_html( isset( $log->anchor_text ) ? (string) $log->anchor_text : '' ) . '</strong><br><span style="color:#64748b;font-size:11px;">' . esc_html( isset( $log->context_text ) ? (string) $log->context_text : '' ) . '</span>';

            $parsed_url  = isset( $log->origin_url ) ? parse_url( $log->origin_url, PHP_URL_PATH ) : '';
            $display_url = $parsed_url ? $parsed_url : '/';
            $origin_cell = '<a href="' . esc_url( isset( $log->origin_url ) ? $log->origin_url : '' ) . '" target="_blank" rel="noopener noreferrer" style="color:#0284c7;font-weight:600;font-size:11px;">' . esc_html( $display_url ) . ' ↗</a>';

            $dt = isset( $log->created_at ) ? date_i18n( 'Y-m-d H:i:s', strtotime( $log->created_at ) ) : '—';

            $utm_s = property_exists( $log, 'utm_source' ) ? (string) $log->utm_source : '';
            $utm_m = property_exists( $log, 'utm_medium' ) ? (string) $log->utm_medium : '';
            $scr   = property_exists( $log, 'screen_res' ) ? (string) $log->screen_res : '';
            $dest  = isset( $log->destination_url ) ? (string) $log->destination_url : '';

            $details  = '<div style="padding:16px 18px;border-left:4px solid ' . esc_attr( $arrow_color ) . ';background:#f8fafc;">';
            $details .= '<div style="display:grid;grid-template-columns:160px 1fr;gap:8px 14px;font-size:12px;max-width:920px;">';
            $rows_kv = array(
                __( 'Session id', 'ois-conversion-suite' )         => $sid !== '' ? '<code style="font-size:11px;">' . esc_html( $sid ) . '</code>' : '—',
                __( 'Row id', 'ois-conversion-suite' )             => isset( $log->id ) ? (string) (int) $log->id : '—',
                __( 'utm_campaign', 'ois-conversion-suite' )       => '<code>' . esc_html( isset( $log->utm_campaign ) ? (string) $log->utm_campaign : '' ) . '</code>',
                __( 'utm_term', 'ois-conversion-suite' )           => '<code>' . esc_html( isset( $log->utm_term ) ? (string) $log->utm_term : '' ) . '</code>',
                __( 'utm_source / utm_medium', 'ois-conversion-suite' ) => '<code style="font-size:11px;">' . esc_html( $utm_s ) . '</code> <span style="color:#94a3b8;">/</span> <code style="font-size:11px;">' . esc_html( $utm_m ) . '</code>',
                __( 'Origin URL', 'ois-conversion-suite' )        => '<a href="' . esc_url( isset( $log->origin_url ) ? $log->origin_url : '' ) . '" target="_blank" rel="noopener noreferrer" style="word-break:break-all;">' . esc_html( isset( $log->origin_url ) ? (string) $log->origin_url : '' ) . '</a>',
                __( 'Destination URL', 'ois-conversion-suite' )   => $dest !== '' ? '<a href="' . esc_url( $dest ) . '" target="_blank" rel="noopener noreferrer" style="word-break:break-all;">' . esc_html( $dest ) . '</a>' : '<span style="color:#94a3b8;">—</span>',
                __( 'Country / city', 'ois-conversion-suite' )     => esc_html( ( isset( $log->country ) ? $log->country : '' ) . ' · ' . ( isset( $log->city ) ? $log->city : '' ) ),
                __( 'Device / OS / browser', 'ois-conversion-suite' ) => esc_html( ( isset( $log->device ) ? $log->device : '' ) . ' · ' . ( isset( $log->os ) ? $log->os : '' ) . ' · ' . ( isset( $log->browser ) ? $log->browser : '' ) ),
                __( 'Language', 'ois-conversion-suite' )          => esc_html( isset( $log->language ) ? (string) $log->language : '' ),
                __( 'Screen resolution', 'ois-conversion-suite' )  => esc_html( $scr !== '' ? $scr : '—' ),
                __( 'Time spent (s)', 'ois-conversion-suite' )    => isset( $log->time_spent ) ? (string) (int) $log->time_spent : '0',
                __( 'Clicks (row)', 'ois-conversion-suite' )     => isset( $log->clicks ) ? (string) (int) $log->clicks : '1',
            );
            foreach ( $rows_kv as $lk => $lv ) {
                $details .= '<div style="color:#64748b;font-weight:600;">' . esc_html( $lk ) . '</div><div>' . $lv . '</div>';
            }
            $details .= '</div></div>';

            $out[] = array(
                'class'        => 'ois-row-accordion',
                'details_html' => $details,
                'cols'         => array(
                    '<span style="color:#64748b;font-size:12px;">' . esc_html( $dt ) . '</span>',
                    $identity,
                    '<span style="font-size:11px;font-weight:600;color:#0284c7;">' . esc_html( isset( $log->traffic_source ) ? (string) $log->traffic_source : '' ) . '</span>',
                    $action_lbl,
                    $element_display,
                    $origin_cell,
                ),
            );
        }
        return $out;
    }

    /**
     * Mini delta badge for comparative data_table cells (matches Analytics overview).
     *
     * @param float|int $curr Current value.
     * @param float|int $prev Previous period value.
     * @return string
     */
    private function oiscl_utm_table_mini_delta( $curr, $prev ) {
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
    }

    /**
     * Overview tab: UTM Top Company / Campaigns / Terms (Analytics-style data_table grid).
     *
     * @param array $args start_date, end_date, prev_start, prev_end, filter_sql_stats.
     */
    private function oiscl_render_utm_overview_top_tables( $args ) {
        global $wpdb;

        $start_date       = $args['start_date'];
        $end_date         = $args['end_date'];
        $prev_start       = $args['prev_start'];
        $prev_end         = $args['prev_end'];
        $filter_sql_stats = $args['filter_sql_stats'];
        $table_stats      = $wpdb->prefix . 'oiscl_block_metrics';
        $table_refs       = $wpdb->prefix . 'oiscl_utm_references';
        $mini_delta       = array( $this, 'oiscl_utm_table_mini_delta' );

        $filter_sql_s = str_replace( 'utm_campaign', 's.utm_campaign', $filter_sql_stats );
        $utm_base     = "utm_campaign != '' {$filter_sql_stats}";
        $utm_base_s   = "s.utm_campaign != '' {$filter_sql_s}";

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $company_curr = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT r.label_name AS label,
                    COUNT(DISTINCT CASE WHEN s.anchor_text = '[Pageview]' THEN s.session_id END) AS uniques,
                    COALESCE(SUM(CASE WHEN s.anchor_text NOT IN ('[Pageview]', '[Bloque]', 'Reading') THEN s.clicks ELSE 0 END), 0) AS clicks
                FROM `{$table_stats}` s
                INNER JOIN `{$table_refs}` r ON r.utm_campaign = s.utm_campaign
                WHERE {$utm_base_s}
                AND DATE(s.created_at) >= %s AND DATE(s.created_at) <= %s
                GROUP BY r.label_name
                ORDER BY uniques DESC
                LIMIT 100",
                $start_date,
                $end_date
            )
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $company_prev_db = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT r.label_name AS label,
                    COUNT(DISTINCT CASE WHEN s.anchor_text = '[Pageview]' THEN s.session_id END) AS uniques,
                    COALESCE(SUM(CASE WHEN s.anchor_text NOT IN ('[Pageview]', '[Bloque]', 'Reading') THEN s.clicks ELSE 0 END), 0) AS clicks
                FROM `{$table_stats}` s
                INNER JOIN `{$table_refs}` r ON r.utm_campaign = s.utm_campaign
                WHERE {$utm_base_s}
                AND DATE(s.created_at) >= %s AND DATE(s.created_at) <= %s
                GROUP BY r.label_name",
                $prev_start,
                $prev_end
            )
        );
        $company_prev_map = array();
        foreach ( (array) $company_prev_db as $row ) {
            $company_prev_map[ $row->label ] = $row;
        }

        $pv_cond    = "anchor_text = '[Pageview]'";
        $click_cond = "anchor_text NOT IN ('[Pageview]', '[Bloque]', 'Reading')";

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $camp_curr = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT utm_campaign AS label,
                    COALESCE(SUM(CASE WHEN {$pv_cond} THEN clicks ELSE 0 END), 0) AS views,
                    COALESCE(SUM(CASE WHEN {$click_cond} THEN clicks ELSE 0 END), 0) AS clicks
                FROM `{$table_stats}`
                WHERE {$utm_base}
                AND DATE(created_at) >= %s AND DATE(created_at) <= %s
                GROUP BY utm_campaign
                ORDER BY views DESC
                LIMIT 100",
                $start_date,
                $end_date
            )
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $camp_prev_db = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT utm_campaign AS label,
                    COALESCE(SUM(CASE WHEN {$pv_cond} THEN clicks ELSE 0 END), 0) AS views,
                    COALESCE(SUM(CASE WHEN {$click_cond} THEN clicks ELSE 0 END), 0) AS clicks
                FROM `{$table_stats}`
                WHERE {$utm_base}
                AND DATE(created_at) >= %s AND DATE(created_at) <= %s
                GROUP BY utm_campaign",
                $prev_start,
                $prev_end
            )
        );
        $camp_prev_map = array();
        foreach ( (array) $camp_prev_db as $row ) {
            $camp_prev_map[ $row->label ] = $row;
        }

        $no_term_label = __( '(no term)', 'ois-conversion-suite' );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $term_curr = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT IFNULL(NULLIF(TRIM(utm_term), ''), %s) AS label,
                    COALESCE(SUM(CASE WHEN {$pv_cond} THEN clicks ELSE 0 END), 0) AS views,
                    COALESCE(SUM(CASE WHEN {$click_cond} THEN clicks ELSE 0 END), 0) AS clicks
                FROM `{$table_stats}`
                WHERE {$utm_base}
                AND DATE(created_at) >= %s AND DATE(created_at) <= %s
                GROUP BY utm_term
                ORDER BY views DESC
                LIMIT 100",
                $no_term_label,
                $start_date,
                $end_date
            )
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $term_prev_db = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT IFNULL(NULLIF(TRIM(utm_term), ''), %s) AS label,
                    COALESCE(SUM(CASE WHEN {$pv_cond} THEN clicks ELSE 0 END), 0) AS views,
                    COALESCE(SUM(CASE WHEN {$click_cond} THEN clicks ELSE 0 END), 0) AS clicks
                FROM `{$table_stats}`
                WHERE {$utm_base}
                AND DATE(created_at) >= %s AND DATE(created_at) <= %s
                GROUP BY utm_term",
                $no_term_label,
                $prev_start,
                $prev_end
            )
        );
        $term_prev_map = array();
        foreach ( (array) $term_prev_db as $row ) {
            $term_prev_map[ $row->label ] = $row;
        }

        $no_src_label = __( '(no source)', 'ois-conversion-suite' );
        $no_med_label = __( '(no medium)', 'ois-conversion-suite' );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $src_curr = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT IFNULL(NULLIF(TRIM(utm_source), ''), %s) AS label,
                    COALESCE(SUM(CASE WHEN {$pv_cond} THEN clicks ELSE 0 END), 0) AS views,
                    COALESCE(SUM(CASE WHEN {$click_cond} THEN clicks ELSE 0 END), 0) AS clicks
                FROM `{$table_stats}`
                WHERE {$utm_base}
                AND DATE(created_at) >= %s AND DATE(created_at) <= %s
                GROUP BY utm_source
                ORDER BY views DESC
                LIMIT 100",
                $no_src_label,
                $start_date,
                $end_date
            )
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $src_prev_db = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT IFNULL(NULLIF(TRIM(utm_source), ''), %s) AS label,
                    COALESCE(SUM(CASE WHEN {$pv_cond} THEN clicks ELSE 0 END), 0) AS views,
                    COALESCE(SUM(CASE WHEN {$click_cond} THEN clicks ELSE 0 END), 0) AS clicks
                FROM `{$table_stats}`
                WHERE {$utm_base}
                AND DATE(created_at) >= %s AND DATE(created_at) <= %s
                GROUP BY utm_source",
                $no_src_label,
                $prev_start,
                $prev_end
            )
        );
        $src_prev_map = array();
        foreach ( (array) $src_prev_db as $row ) {
            $src_prev_map[ $row->label ] = $row;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $med_curr = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT IFNULL(NULLIF(TRIM(utm_medium), ''), %s) AS label,
                    COALESCE(SUM(CASE WHEN {$pv_cond} THEN clicks ELSE 0 END), 0) AS views,
                    COALESCE(SUM(CASE WHEN {$click_cond} THEN clicks ELSE 0 END), 0) AS clicks
                FROM `{$table_stats}`
                WHERE {$utm_base}
                AND DATE(created_at) >= %s AND DATE(created_at) <= %s
                GROUP BY utm_medium
                ORDER BY views DESC
                LIMIT 100",
                $no_med_label,
                $start_date,
                $end_date
            )
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $med_prev_db = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT IFNULL(NULLIF(TRIM(utm_medium), ''), %s) AS label,
                    COALESCE(SUM(CASE WHEN {$pv_cond} THEN clicks ELSE 0 END), 0) AS views,
                    COALESCE(SUM(CASE WHEN {$click_cond} THEN clicks ELSE 0 END), 0) AS clicks
                FROM `{$table_stats}`
                WHERE {$utm_base}
                AND DATE(created_at) >= %s AND DATE(created_at) <= %s
                GROUP BY utm_medium",
                $no_med_label,
                $prev_start,
                $prev_end
            )
        );
        $med_prev_map = array();
        foreach ( (array) $med_prev_db as $row ) {
            $med_prev_map[ $row->label ] = $row;
        }

        $headers_company = array(
            array( 'label' => __( 'Company', 'ois-conversion-suite' ), 'sortable' => true ),
            array( 'label' => __( 'Uniques', 'ois-conversion-suite' ), 'align' => 'right', 'sortable' => true ),
            array( 'label' => __( 'vs Past', 'ois-conversion-suite' ), 'align' => 'right' ),
            array( 'label' => __( 'Clicks', 'ois-conversion-suite' ), 'align' => 'right', 'sortable' => true ),
            array( 'label' => __( 'vs Past', 'ois-conversion-suite' ), 'align' => 'right' ),
        );
        $headers_camp = array(
            array( 'label' => __( 'Campaign', 'ois-conversion-suite' ), 'sortable' => true ),
            array( 'label' => __( 'Views', 'ois-conversion-suite' ), 'align' => 'right', 'sortable' => true ),
            array( 'label' => __( 'vs Past', 'ois-conversion-suite' ), 'align' => 'right' ),
            array( 'label' => __( 'Clicks', 'ois-conversion-suite' ), 'align' => 'right', 'sortable' => true ),
            array( 'label' => __( 'vs Past', 'ois-conversion-suite' ), 'align' => 'right' ),
        );
        $headers_term = array(
            array( 'label' => __( 'Term', 'ois-conversion-suite' ), 'sortable' => true ),
            array( 'label' => __( 'Views', 'ois-conversion-suite' ), 'align' => 'right', 'sortable' => true ),
            array( 'label' => __( 'vs Past', 'ois-conversion-suite' ), 'align' => 'right' ),
            array( 'label' => __( 'Clicks', 'ois-conversion-suite' ), 'align' => 'right', 'sortable' => true ),
            array( 'label' => __( 'vs Past', 'ois-conversion-suite' ), 'align' => 'right' ),
        );
        $headers_src = array(
            array( 'label' => __( 'Source', 'ois-conversion-suite' ), 'sortable' => true ),
            array( 'label' => __( 'Views', 'ois-conversion-suite' ), 'align' => 'right', 'sortable' => true ),
            array( 'label' => __( 'vs Past', 'ois-conversion-suite' ), 'align' => 'right' ),
            array( 'label' => __( 'Clicks', 'ois-conversion-suite' ), 'align' => 'right', 'sortable' => true ),
            array( 'label' => __( 'vs Past', 'ois-conversion-suite' ), 'align' => 'right' ),
        );
        $headers_med = array(
            array( 'label' => __( 'Medium', 'ois-conversion-suite' ), 'sortable' => true ),
            array( 'label' => __( 'Views', 'ois-conversion-suite' ), 'align' => 'right', 'sortable' => true ),
            array( 'label' => __( 'vs Past', 'ois-conversion-suite' ), 'align' => 'right' ),
            array( 'label' => __( 'Clicks', 'ois-conversion-suite' ), 'align' => 'right', 'sortable' => true ),
            array( 'label' => __( 'vs Past', 'ois-conversion-suite' ), 'align' => 'right' ),
        );

        $co_rows = array();
        foreach ( (array) $company_curr as $row ) {
            $prev   = isset( $company_prev_map[ $row->label ] ) ? $company_prev_map[ $row->label ] : null;
            $p_u    = $prev ? (int) $prev->uniques : 0;
            $p_c    = $prev ? (int) $prev->clicks : 0;
            $co_rows[] = array(
                array( 'value' => '<strong>' . esc_html( $row->label ) . '</strong>' ),
                array( 'value' => number_format( (int) $row->uniques ), 'align' => 'right', 'bold' => true ),
                array( 'value' => call_user_func( $mini_delta, (int) $row->uniques, $p_u ), 'align' => 'right' ),
                array( 'value' => '<b>' . number_format( (int) $row->clicks ) . '</b>', 'align' => 'right' ),
                array( 'value' => call_user_func( $mini_delta, (int) $row->clicks, $p_c ), 'align' => 'right' ),
            );
        }

        $camp_rows = array();
        foreach ( (array) $camp_curr as $row ) {
            $prev = isset( $camp_prev_map[ $row->label ] ) ? $camp_prev_map[ $row->label ] : null;
            $p_v  = $prev ? (int) $prev->views : 0;
            $p_c  = $prev ? (int) $prev->clicks : 0;
            $camp_rows[] = array(
                array( 'value' => '<strong><code>' . esc_html( $row->label ) . '</code></strong>' ),
                array( 'value' => '<b>' . number_format( (int) $row->views ) . '</b>', 'align' => 'right' ),
                array( 'value' => call_user_func( $mini_delta, (int) $row->views, $p_v ), 'align' => 'right' ),
                array( 'value' => number_format( (int) $row->clicks ), 'align' => 'right', 'bold' => true ),
                array( 'value' => call_user_func( $mini_delta, (int) $row->clicks, $p_c ), 'align' => 'right' ),
            );
        }

        $term_rows = array();
        foreach ( (array) $term_curr as $row ) {
            $prev = isset( $term_prev_map[ $row->label ] ) ? $term_prev_map[ $row->label ] : null;
            $p_v  = $prev ? (int) $prev->views : 0;
            $p_c  = $prev ? (int) $prev->clicks : 0;
            $term_rows[] = array(
                array( 'value' => '<strong>' . esc_html( $row->label ) . '</strong>' ),
                array( 'value' => '<b>' . number_format( (int) $row->views ) . '</b>', 'align' => 'right' ),
                array( 'value' => call_user_func( $mini_delta, (int) $row->views, $p_v ), 'align' => 'right' ),
                array( 'value' => number_format( (int) $row->clicks ), 'align' => 'right', 'bold' => true ),
                array( 'value' => call_user_func( $mini_delta, (int) $row->clicks, $p_c ), 'align' => 'right' ),
            );
        }

        $src_rows = array();
        foreach ( (array) $src_curr as $row ) {
            $prev = isset( $src_prev_map[ $row->label ] ) ? $src_prev_map[ $row->label ] : null;
            $p_v  = $prev ? (int) $prev->views : 0;
            $p_c  = $prev ? (int) $prev->clicks : 0;
            $src_rows[] = array(
                array( 'value' => '<strong><code>' . esc_html( $row->label ) . '</code></strong>' ),
                array( 'value' => '<b>' . number_format( (int) $row->views ) . '</b>', 'align' => 'right' ),
                array( 'value' => call_user_func( $mini_delta, (int) $row->views, $p_v ), 'align' => 'right' ),
                array( 'value' => number_format( (int) $row->clicks ), 'align' => 'right', 'bold' => true ),
                array( 'value' => call_user_func( $mini_delta, (int) $row->clicks, $p_c ), 'align' => 'right' ),
            );
        }

        $med_rows = array();
        foreach ( (array) $med_curr as $row ) {
            $prev = isset( $med_prev_map[ $row->label ] ) ? $med_prev_map[ $row->label ] : null;
            $p_v  = $prev ? (int) $prev->views : 0;
            $p_c  = $prev ? (int) $prev->clicks : 0;
            $med_rows[] = array(
                array( 'value' => '<strong><code>' . esc_html( $row->label ) . '</code></strong>' ),
                array( 'value' => '<b>' . number_format( (int) $row->views ) . '</b>', 'align' => 'right' ),
                array( 'value' => call_user_func( $mini_delta, (int) $row->views, $p_v ), 'align' => 'right' ),
                array( 'value' => number_format( (int) $row->clicks ), 'align' => 'right', 'bold' => true ),
                array( 'value' => call_user_func( $mini_delta, (int) $row->clicks, $p_c ), 'align' => 'right' ),
            );
        }

        echo '<div class="ois-analytics-card-grid">';
        $this->render_ois_component(
            'data_table',
            array(
                'id'      => 'tbl-utm-co',
                'title'   => __( 'UTM Top Company', 'ois-conversion-suite' ),
                'icon'    => '🏢',
                'headers' => $headers_company,
                'rows'    => $co_rows,
            )
        );
        $this->render_ois_component(
            'data_table',
            array(
                'id'      => 'tbl-utm-camp',
                'title'   => __( 'UTM Top Campaigns', 'ois-conversion-suite' ),
                'icon'    => '🎯',
                'headers' => $headers_camp,
                'rows'    => $camp_rows,
            )
        );
        $this->render_ois_component(
            'data_table',
            array(
                'id'      => 'tbl-utm-term',
                'title'   => __( 'UTM Top Terms', 'ois-conversion-suite' ),
                'icon'    => '🏷️',
                'headers' => $headers_term,
                'rows'    => $term_rows,
            )
        );
        $this->render_ois_component(
            'data_table',
            array(
                'id'      => 'tbl-utm-src',
                'title'   => __( 'UTM Top Sources', 'ois-conversion-suite' ),
                'icon'    => '📣',
                'headers' => $headers_src,
                'rows'    => $src_rows,
            )
        );
        $this->render_ois_component(
            'data_table',
            array(
                'id'      => 'tbl-utm-med',
                'title'   => __( 'UTM Top Mediums', 'ois-conversion-suite' ),
                'icon'    => '📡',
                'headers' => $headers_med,
                'rows'    => $med_rows,
            )
        );
        echo '</div>';
    }

    /**
     * Five KPI cards: same layout and typography as the main page header KPIs (ois-kpi-card, LIVE pulse/glow).
     *
     * @param int   $live_views Distinct sessions in last ~5 minutes.
     * @param int   $utm_hits   Count of UTM-tagged rows in range.
     * @param float $utm_ctr    Percent 0–100.
     */
    private function oiscl_render_utm_tab_kpi_row( $live_views, $utm_hits, $prev_utm_hits, $utm_users, $prev_utm_users, $utm_actions, $prev_utm_actions, $utm_ctr, $prev_utm_ctr ) {
        $live_color = (int) $live_views > 0 ? '#46b450' : '#d63638';
        // Matches `header` case in render_ois_component: grid + ois-kpi-card + kpi-live-now + @keyframes pulse (already output by header on this screen).
        echo '<div class="oiscl-utm-tab-kpis" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:15px; margin-bottom:20px;">';
        $this->render_utm_kpi_card( __( 'LIVE NOW', 'ois-conversion-suite' ), (string) (int) $live_views, $live_color, '', '', true );
        $this->render_utm_kpi_card( __( 'UTM HITS', 'ois-conversion-suite' ), number_format( (int) $utm_hits ), '#1a73e8', '🎯', $this->format_kpi_delta( $utm_hits, $prev_utm_hits ), false );
        $this->render_utm_kpi_card( __( 'UTM USERS', 'ois-conversion-suite' ), number_format( (int) $utm_users ), '#46b450', '👥', $this->format_kpi_delta( $utm_users, $prev_utm_users ), false );
        $this->render_utm_kpi_card( __( 'REAL CONVERSIONS', 'ois-conversion-suite' ), number_format( (int) $utm_actions ), '#f56e28', '💰', $this->format_kpi_delta( $utm_actions, $prev_utm_actions ), false );
        $this->render_utm_kpi_card( __( 'UTM CTR', 'ois-conversion-suite' ), (string) $utm_ctr . '%', '#722ed1', '📈', $this->format_kpi_delta( $utm_ctr, $prev_utm_ctr ), false );
        echo '</div>';
    }

    /**
     * Single KPI tile matching the dashboard header card markup (see `header` in trait-oiscl-admin-component.php).
     *
     * @param string      $label   Shown in the card title (header uses uppercase English for LIVE NOW).
     * @param string      $value   Pre-formatted main number or text.
     * @param string      $color   Accent / live state color.
     * @param string      $icon    Emoji or empty for LIVE card (pulse dot only).
     * @param string      $delta   HTML from format_kpi_delta or ''.
     * @param bool        $is_live Same treatment as header LIVE NOW (pulse + colored value + subtitle).
     */
    private function render_utm_kpi_card( $label, $value, $color, $icon, $delta = '', $is_live = false ) {
        $color_esc = esc_attr( $color );
        $card_class = 'ois-kpi-card' . ( $is_live ? ' kpi-live-now' : '' );
        echo '<div class="' . esc_attr( $card_class ) . '" style="background:#fff; border:1px solid #ccd0d4; padding:20px; border-radius:4px; text-align:center; border-left:4px solid ' . $color_esc . '; box-sizing: border-box; display:flex; flex-direction:column; justify-content:center; flex:1 1 200px; max-width:100%;">';
        echo '<h4 style="margin:0 0 10px 0; color:#1d2327; font-size:14px; display:flex; justify-content:center; align-items:center; gap:6px;">';
        if ( $is_live ) {
            echo '<span style="width:8px; height:8px; background:' . $color_esc . '; color:' . $color_esc . '; border-radius:50%; display:inline-block; animation: pulse 2s infinite;"></span>';
        }
        echo esc_html( trim( $icon . ' ' . $label ) );
        echo '</h4>';
        echo '<span style="font-size:28px; font-weight:bold; color:' . ( $is_live ? $color_esc : '#1d2327' ) . ';">' . esc_html( $value ) . '</span>';
        if ( '' !== $delta ) {
            echo $delta; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- same trusted HTML as header KPI deltas.
        }
        if ( $is_live ) {
            echo '<div style="font-size:11px; color:#999; margin-top:8px;">' . esc_html__( 'Active Sessions', 'ois-conversion-suite' ) . '</div>';
        }
        echo '</div>';
    }

    /**
     * Normalize campaign / term slug: lowercase, spaces to hyphens, safe chars only.
     *
     * @param string $raw Raw input.
     * @return string
     */
    private function oiscl_normalize_utm_slug( $raw ) {
        $s = trim( (string) $raw );
        if ( '' === $s ) {
            return '';
        }
        $s = strtolower( $s );
        $s = preg_replace( '/\s+/', '-', $s );
        $s = preg_replace( '/[^a-z0-9\-_]/', '', $s );
        $s = preg_replace( '/-+/', '-', $s );
        return trim( $s, '-' );
    }

    /**
     * Read optional column from a saved UTM reference row.
     *
     * @param object $link    DB row.
     * @param string $field   Property name.
     * @param string $default Fallback.
     * @return string
     */
    private function oiscl_utm_ref_field( $link, $field, $default = '' ) {
        if ( is_object( $link ) && isset( $link->$field ) && '' !== (string) $link->$field ) {
            return (string) $link->$field;
        }
        return (string) $default;
    }

    /**
     * Full UTM query args for a saved link (Google Ads defaults: google / cpc).
     *
     * @param object $link Reference row.
     * @return array<string,string>
     */
    private function oiscl_utm_build_link_query_args( $link ) {
        $args = array(
            'utm_source'   => $this->oiscl_utm_ref_field( $link, 'utm_source', 'google' ),
            'utm_medium'   => $this->oiscl_utm_ref_field( $link, 'utm_medium', 'cpc' ),
            'utm_campaign' => (string) $link->utm_campaign,
        );
        if ( '' !== (string) $link->utm_term ) {
            $args['utm_term'] = (string) $link->utm_term;
        }
        return $args;
    }

    /**
     * SQL fragment restricting metrics rows to a saved link’s optional utm_term.
     * Empty term in Settings means “any utm_term” for that utm_campaign (do not filter term).
     *
     * @param object $link Reference row.
     * @return string
     */
    private function oiscl_utm_link_term_sql( $link ) {
        global $wpdb;
        $term = trim( (string) $this->oiscl_utm_ref_field( $link, 'utm_term', '' ) );
        if ( '' !== $term ) {
            return $wpdb->prepare( ' AND utm_term = %s', $term );
        }
        return '';
    }

    /**
     * Distinct sessions with a pageview for this link in range.
     */
    private function oiscl_utm_count_link_sessions( $link, $table_stats, $start_date, $end_date, $filter_sql_stats ) {
        global $wpdb;
        $term_sql    = $this->oiscl_utm_link_term_sql( $link );
        $term_safe   = $this->oiscl_utm_sql_fragment_for_prepare( $term_sql );
        $filter_safe = $this->oiscl_utm_sql_fragment_for_prepare( $filter_sql_stats );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) ( $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT session_id) FROM `{$table_stats}` WHERE utm_campaign = %s {$term_safe} {$filter_safe} AND anchor_text = %s AND DATE(created_at) >= %s AND DATE(created_at) <= %s",
            $link->utm_campaign,
            OISCL_Plan::EVENT_PAGEVIEW,
            $start_date,
            $end_date
        ) ) ?: 0 );
    }

    /**
     * Conversion sessions: clicks matching conv_anchor text (if configured).
     *
     * @return int|null Null when no conversion label configured.
     */
    private function oiscl_utm_count_link_conversions( $link, $table_stats, $start_date, $end_date, $filter_sql_stats ) {
        global $wpdb;
        $conv = trim( $this->oiscl_utm_ref_field( $link, 'conv_anchor' ) );
        if ( '' === $conv ) {
            return null;
        }
        $term_sql    = $this->oiscl_utm_link_term_sql( $link );
        $term_safe   = $this->oiscl_utm_sql_fragment_for_prepare( $term_sql );
        $filter_safe = $this->oiscl_utm_sql_fragment_for_prepare( $filter_sql_stats );
        $exclude     = OISCL_Plan::sql_exclude_actions_not_in();
        $like        = '%' . $wpdb->esc_like( $conv ) . '%';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) ( $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT session_id) FROM `{$table_stats}` WHERE utm_campaign = %s {$term_safe} {$filter_safe} AND anchor_text NOT IN ({$exclude}) AND DATE(created_at) >= %s AND DATE(created_at) <= %s AND (anchor_text LIKE %s OR context_text LIKE %s OR destination_url LIKE %s)",
            $link->utm_campaign,
            $start_date,
            $end_date,
            $like,
            $like,
            $like
        ) ) ?: 0 );
    }

    /**
     * Traffic quality KPIs for UTM Overview (pre-launch sanity checks).
     */
    private function oiscl_render_utm_traffic_quality_panel( $table_stats, $start_date, $end_date, $filter_sql_stats ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $utm_sessions = (int) ( $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT session_id) FROM `{$table_stats}` WHERE utm_campaign != '' {$filter_sql_stats} AND DATE(created_at) >= %s AND DATE(created_at) <= %s",
            $start_date,
            $end_date
        ) ) ?: 0 );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $bot_sessions = (int) ( $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT session_id) FROM `{$table_stats}` WHERE utm_campaign != '' {$filter_sql_stats} AND is_bot = 1 AND DATE(created_at) >= %s AND DATE(created_at) <= %s",
            $start_date,
            $end_date
        ) ) ?: 0 );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sessions_with_pv = (int) ( $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT session_id) FROM `{$table_stats}` WHERE utm_campaign != '' {$filter_sql_stats} AND anchor_text = %s AND DATE(created_at) >= %s AND DATE(created_at) <= %s",
            OISCL_Plan::EVENT_PAGEVIEW,
            $start_date,
            $end_date
        ) ) ?: 0 );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $paid_no_utm = (int) ( $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table_stats}` WHERE anchor_text = %s AND utm_campaign = '' AND traffic_source = %s AND DATE(created_at) >= %s AND DATE(created_at) <= %s",
            OISCL_Plan::EVENT_PAGEVIEW,
            'Google Ads',
            $start_date,
            $end_date
        ) ) ?: 0 );

        $bot_pct    = $utm_sessions > 0 ? round( ( $bot_sessions / $utm_sessions ) * 100, 1 ) : 0;
        $no_pv      = max( 0, $utm_sessions - $sessions_with_pv );
        $no_pv_pct  = $utm_sessions > 0 ? round( ( $no_pv / $utm_sessions ) * 100, 1 ) : 0;

        echo '<div class="oiscl-kpi-stroke-grid" style="margin-bottom:20px;">';
        $cards = array(
            array(
                'label' => __( 'UTM sessions', 'ois-conversion-suite' ),
                'value' => number_format_i18n( $utm_sessions ),
                'hint'  => __( 'Distinct sessions with at least one tagged hit.', 'ois-conversion-suite' ),
                'tone'  => 'blue',
            ),
            array(
                'label' => __( 'Bot share', 'ois-conversion-suite' ),
                'value' => $bot_pct . '%',
                'hint'  => sprintf(
                    /* translators: %s: bot session count */
                    __( '%s bot-flagged sessions in range.', 'ois-conversion-suite' ),
                    number_format_i18n( $bot_sessions )
                ),
                'tone'  => $bot_pct > 25 ? 'accent' : 'green',
            ),
            array(
                'label' => __( 'No pageview', 'ois-conversion-suite' ),
                'value' => $no_pv_pct . '%',
                'hint'  => sprintf(
                    /* translators: %s: session count */
                    __( '%s UTM sessions without a recorded pageview.', 'ois-conversion-suite' ),
                    number_format_i18n( $no_pv )
                ),
                'tone'  => $no_pv > 0 ? 'accent' : 'green',
            ),
            array(
                'label' => __( 'Paid w/o utm_campaign', 'ois-conversion-suite' ),
                'value' => number_format_i18n( $paid_no_utm ),
                'hint'  => __( 'Pageviews from Google Ads (gclid) missing utm_campaign — fix final URLs.', 'ois-conversion-suite' ),
                'tone'  => $paid_no_utm > 0 ? 'accent' : 'green',
            ),
        );
        foreach ( $cards as $card ) {
            echo '<div class="oiscl-kpi-stroke oiscl-kpi-stroke--' . esc_attr( $card['tone'] ) . '" title="' . esc_attr( $card['hint'] ) . '">';
            echo '<p class="oiscl-kpi-stroke__label">' . esc_html( $card['label'] ) . '</p>';
            echo '<p class="oiscl-kpi-stroke__value">' . esc_html( $card['value'] ) . '</p>';
            echo '</div>';
        }
        echo '</div>';
    }

    /**
     * Spend / CPA / efficiency table for campaigns with manual ad spend.
     */
    private function oiscl_render_utm_roas_panel( $table_refs, $table_stats, $start_date, $end_date, $filter_sql_refs, $filter_sql_stats ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $links = $wpdb->get_results( "SELECT * FROM `{$table_refs}` WHERE 1=1 {$filter_sql_refs} ORDER BY label_name ASC, utm_campaign ASC" );
        $rows  = array();

        foreach ( (array) $links as $link ) {
            $spend = (float) $this->oiscl_utm_ref_field( $link, 'spend', 0 );
            if ( $spend <= 0 ) {
                continue;
            }
            $sessions = $this->oiscl_utm_count_link_sessions( $link, $table_stats, $start_date, $end_date, $filter_sql_stats );
            $convs    = $this->oiscl_utm_count_link_conversions( $link, $table_stats, $start_date, $end_date, $filter_sql_stats );
            $conv_n   = ( null !== $convs ) ? (int) $convs : 0;
            $cpa      = $conv_n > 0 ? $spend / $conv_n : null;
            $per_100  = $spend > 0 ? round( ( $conv_n / $spend ) * 100, 2 ) : null;

            $cpa_cell = '<span style="color:#94a3b8;">—</span>';
            if ( null !== $cpa ) {
                $cpa_cell = '<strong>' . esc_html( number_format_i18n( $cpa, 2 ) ) . '</strong>';
            }
            $eff_cell = '<span style="color:#94a3b8;">—</span>';
            if ( null !== $per_100 ) {
                $eff_cell = esc_html( number_format_i18n( $per_100, 2 ) ) . ' <span style="color:#64748b;font-size:11px;">' . esc_html__( 'conv / $100', 'ois-conversion-suite' ) . '</span>';
            }

            $rows[] = array(
                'class' => 'ois-row',
                'cols'  => array(
                    esc_html( $link->label_name ),
                    '<code style="color:#0369a1;">' . esc_html( $link->utm_campaign ) . '</code>',
                    ( '' !== (string) $link->utm_term ) ? esc_html( $link->utm_term ) : '<span style="color:#94a3b8;">—</span>',
                    '<strong>' . esc_html( number_format_i18n( $spend, 2 ) ) . '</strong>',
                    esc_html( number_format_i18n( $sessions ) ),
                    ( null !== $convs ) ? '<strong style="color:#166534;">' . esc_html( number_format_i18n( $conv_n ) ) . '</strong>' : '<span style="color:#94a3b8;">—</span>',
                    $cpa_cell,
                    $eff_cell,
                ),
            );
        }

        if ( empty( $rows ) ) {
            return;
        }

        $this->render_ois_component(
            'advanced_table',
            array(
                'id'       => 'tbl-utm-roas',
                'title'    => __( 'Spend & CPA estimates', 'ois-conversion-suite' ),
                'subtitle' => __( 'Set ad spend per link in UTM Manager. CPA = spend ÷ conversions. Conv/$100 is a proxy efficiency metric (no revenue tracked).', 'ois-conversion-suite' ),
                'icon'     => '💰',
                'headers'  => array(
                    array( 'label' => __( 'Company', 'ois-conversion-suite' ), 'type' => 'string' ),
                    array( 'label' => __( 'Campaign', 'ois-conversion-suite' ), 'type' => 'string' ),
                    array( 'label' => __( 'Term', 'ois-conversion-suite' ), 'type' => 'string' ),
                    array( 'label' => __( 'Spend', 'ois-conversion-suite' ), 'type' => 'numeric', 'align' => 'right' ),
                    array( 'label' => __( 'Sessions', 'ois-conversion-suite' ), 'type' => 'numeric', 'align' => 'right' ),
                    array( 'label' => __( 'Conv.', 'ois-conversion-suite' ), 'type' => 'numeric', 'align' => 'right' ),
                    array( 'label' => __( 'CPA', 'ois-conversion-suite' ), 'type' => 'numeric', 'align' => 'right' ),
                    array( 'label' => __( 'Efficiency', 'ois-conversion-suite' ), 'type' => 'string', 'align' => 'right' ),
                ),
                'rows'     => $rows,
            )
        );
        echo '<div style="margin-bottom:24px;"></div>';
    }

    /**
     * UTM query string without leading question mark.
     *
     * @param string $utm_campaign Campaign id.
     * @param string $utm_term     Optional term.
     * @param string $utm_source   Optional source (default google).
     * @param string $utm_medium   Optional medium (default cpc).
     * @return string
     */
    private function oiscl_utm_query_string( $utm_campaign, $utm_term, $utm_source = 'google', $utm_medium = 'cpc' ) {
        $args = array(
            'utm_source'   => (string) $utm_source,
            'utm_medium'   => (string) $utm_medium,
            'utm_campaign' => (string) $utm_campaign,
        );
        if ( '' !== (string) $utm_term ) {
            $args['utm_term'] = (string) $utm_term;
        }
        return build_query( $args );
    }

    /**
     * Whether label + campaign + term already exists.
     *
     * @param string $label_name   Company label.
     * @param string $utm_campaign Campaign slug.
     * @param string $utm_term     Term slug.
     * @param int    $exclude_id   Row id to ignore (edit).
     * @return bool
     */
    private function oiscl_utm_ref_combo_exists( $label_name, $utm_campaign, $utm_term, $exclude_id = 0 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'oiscl_utm_references';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql    = "SELECT id FROM `{$table}` WHERE label_name = %s AND utm_campaign = %s AND utm_term = %s";
        $params = array( $label_name, $utm_campaign, (string) $utm_term );
        if ( $exclude_id > 0 ) {
            $sql     .= ' AND id != %d';
            $params[] = (int) $exclude_id;
        }
        $sql .= ' LIMIT 1';
        return (bool) $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) );
    }

    private function oiscl_utm_ref_combo_exists_excluding_ids( $label_name, $utm_campaign, $utm_term, $exclude_ids = array() ) {
        global $wpdb;
        $table       = $wpdb->prefix . 'oiscl_utm_references';
        $exclude_ids = array_values( array_filter( array_map( 'intval', (array) $exclude_ids ) ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql    = "SELECT id FROM `{$table}` WHERE label_name = %s AND utm_campaign = %s AND utm_term = %s";
        $params = array( $label_name, $utm_campaign, (string) $utm_term );
        if ( ! empty( $exclude_ids ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $exclude_ids ), '%d' ) );
            $sql         .= " AND id NOT IN ({$placeholders})";
            $params       = array_merge( $params, $exclude_ids );
        }
        $sql .= ' LIMIT 1';
        return (bool) $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) );
    }

    /**
     * Recent journey events HTML for links-table accordion row.
     *
     * @param array  $journey_events Rows from block_metrics.
     * @param string $utm_campaign   Campaign id for heading.
     * @return string
     */
    private function oiscl_build_utm_link_journey_details_html( $journey_events, $utm_campaign, $link_id = 0, $utm_term = '' ) {
        $table_id   = 'utm-journey-detail-' . max( 0, (int) $link_id );
        $event_rows = (array) $journey_events;
        $row_count  = count( $event_rows );
        $page_sz    = 6;
        $term_label = ( '' !== (string) $utm_term ) ? (string) $utm_term : __( '(no term)', 'ois-conversion-suite' );

        $html  = '<div class="oiscl-utm-journey-panel" data-oiscl-journey-ui="0.74.6" style="background:#fff; border:1px solid #cbd5e1; border-radius:8px; padding:15px; margin:10px 15px 15px;">';
        $html .= '<h4 style="margin:0 0 6px 0; color:#0f172a; font-size:13px;">🕵️ ' . esc_html__( 'Tracked events for this link', 'ois-conversion-suite' ) . '</h4>';
        $html .= '<p style="margin:0 0 12px 0; font-size:11px; color:#64748b; line-height:1.5;">';
        $html .= esc_html__(
            'Each row is one hit in block metrics where utm_campaign (and utm_term when set) match this saved link in the selected date range. Pageviews and block views show a friendly label; buttons, links, and forms show the clicked element name. Human vs bot is detected per hit.',
            'ois-conversion-suite'
        );
        $html .= ' <code style="font-size:10px;">' . esc_html( $utm_campaign ) . '</code>';
        if ( '' !== (string) $utm_term ) {
            $html .= ' · <code style="font-size:10px;">' . esc_html( $term_label ) . '</code>';
        }
        $html .= '</p>';
        $html .= '<table class="wp-list-table widefat striped ois-table-dashboard oiscl-utm-journey-events-table" id="' . esc_attr( $table_id ) . '" data-page-size="' . esc_attr( (string) $page_sz ) . '" data-current-page="1" style="border:none; margin:0;"><thead><tr>';
        $html .= '<th style="font-weight:bold; font-size:11px; padding:8px;">' . esc_html__( 'Date / Time', 'ois-conversion-suite' ) . '</th>';
        $html .= '<th style="font-weight:bold; font-size:11px; padding:8px;">' . esc_html__( 'Event', 'ois-conversion-suite' ) . '</th>';
        $html .= '<th style="font-weight:bold; font-size:11px; padding:8px; text-align:center;">' . esc_html__( 'Traffic', 'ois-conversion-suite' ) . '</th>';
        $html .= '</tr></thead><tbody>';

        if ( empty( $event_rows ) ) {
            $html .= '<tr><td colspan="3" style="text-align:center; padding:16px; color:#94a3b8;">' . esc_html__( 'No events for this link in the selected range.', 'ois-conversion-suite' ) . '</td></tr>';
        } else {
            foreach ( $event_rows as $idx => $event ) {
                $anchor_raw    = isset( $event->anchor_text ) ? (string) $event->anchor_text : '';
                $context_raw   = isset( $event->context_text ) ? (string) $event->context_text : '';
                $is_conversion = ! $this->oiscl_utm_is_read_event( $anchor_raw );
                $action_style  = $is_conversion ? 'color:#166534; font-weight:bold;' : 'color:#64748b;';
                $icon          = $is_conversion ? '🎯' : '👁️';
                $event_label   = esc_html( $this->oiscl_utm_format_event_label( $anchor_raw, $context_raw ) );
                $bot_label     = $event->is_bot ? '<span style="color:#ef4444;">🤖 ' . esc_html__( 'Bot', 'ois-conversion-suite' ) . '</span>' : '<span style="color:#10b981;">👤 ' . esc_html__( 'Human', 'ois-conversion-suite' ) . '</span>';
                $row_style     = ( $idx >= $page_sz ) ? 'display:none;' : '';
                $html         .= '<tr class="oiscl-journey-pag-row" style="' . esc_attr( $row_style ) . '">';
                $html         .= '<td style="font-size:11px; color:#64748b; padding:6px 8px;">' . esc_html( gmdate( 'Y-m-d H:i:s', strtotime( $event->created_at ) ) ) . '</td>';
                $html         .= '<td style="font-size:12px; ' . esc_attr( $action_style ) . ' padding:6px 8px;">' . $icon . ' ' . $event_label . '</td>';
                $html         .= '<td style="font-size:11px; text-align:center; padding:6px 8px;">' . $bot_label . '</td>';
                $html         .= '</tr>';
            }
        }

        $html .= '</tbody></table>';

        if ( $row_count > 0 ) {
            $pag_display = $row_count > $page_sz ? 'display:flex;' : 'display:none;';
            $html       .= '<div class="oiscl-utm-journey-pag-footer" style="display:flex; justify-content:flex-end; align-items:center; gap:15px; margin-top:12px; padding-top:10px; border-top:1px solid #e2e8f0;">';
            $html       .= '<span style="font-size:11px; color:#64748b;">' . esc_html( sprintf(
                _n( '%d event', '%d events', $row_count, 'ois-conversion-suite' ),
                $row_count
            ) ) . '</span>';
            $html       .= '<div style="font-size:11px; color:#666;">' . esc_html__( 'Listar:', 'ois-conversion-suite' ) . ' <select class="ois-row-selector" data-target="' . esc_attr( $table_id ) . '" style="font-size:11px; height:24px; min-height:24px;">';
            foreach ( array( 6, 20, 50, 100, 200 ) as $opt ) {
                $sel = ( $opt === $page_sz ) ? ' selected' : '';
                $html .= '<option value="' . esc_attr( (string) $opt ) . '"' . $sel . '>' . esc_html( (string) $opt ) . '</option>';
            }
            $html .= '</select></div>';
            $html .= '<div class="ois-pagination" id="pag-wrap-' . esc_attr( $table_id ) . '" style="' . esc_attr( $pag_display ) . ' align-items:center; gap:5px;">';
            $html .= '<button type="button" class="pag-prev button button-small" data-target="' . esc_attr( $table_id ) . '" disabled>&lt;</button> ';
            $html .= '<span class="pag-num" id="pag-cur-' . esc_attr( $table_id ) . '" style="font-size:11px; font-weight:bold; color:#1a73e8; padding:0 5px;">1</span> ';
            $html .= '<button type="button" class="pag-next button button-small" data-target="' . esc_attr( $table_id ) . '">&gt;</button>';
            $html .= '</div></div>';
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * Rows for advanced_table UTM links registry (Content embed + Settings).
     *
     * @param array $saved_links Reference rows.
     * @param array $args        mode.
     * @return array<int,array>
     */
    private function oiscl_build_utm_links_advanced_table_rows( $saved_links, $args ) {
        global $wpdb;
        $table_stats = $wpdb->prefix . 'oiscl_block_metrics';

        $mode             = isset( $args['mode'] ) ? $args['mode'] : 'embed';
        $filter_sql_stats = isset( $args['filter_sql_stats'] ) ? $args['filter_sql_stats'] : '';
        $start_date       = isset( $args['start_date'] ) ? $args['start_date'] : '';
        $end_date         = isset( $args['end_date'] ) ? $args['end_date'] : '';
        $scoped_dates     = ! empty( $args['scoped_dates'] );

        $rows       = array();
        $last_label = '';
        $links_by_label = array();
        foreach ( (array) $saved_links as $saved_link ) {
            $links_by_label[ $saved_link->label_name ][] = array(
                'id'           => (int) $saved_link->id,
                'target_url'   => (string) $saved_link->target_url,
                'utm_campaign' => (string) $saved_link->utm_campaign,
                'utm_term'     => (string) $saved_link->utm_term,
                'utm_source'   => isset( $saved_link->utm_source ) ? (string) $saved_link->utm_source : 'google',
                'utm_medium'   => isset( $saved_link->utm_medium ) ? (string) $saved_link->utm_medium : 'cpc',
                'conv_anchor'  => isset( $saved_link->conv_anchor ) ? (string) $saved_link->conv_anchor : '',
                'spend'        => isset( $saved_link->spend ) ? (float) $saved_link->spend : 0,
            );
        }

        foreach ( (array) $saved_links as $link ) {
            $is_new_group = ( $last_label !== $link->label_name );
            $label_cell   = '';
            if ( $is_new_group ) {
                $label_cell = '<strong style="color:#1e293b; font-size:14px;">' . esc_html( $link->label_name ) . '</strong>';
            }

            if ( $scoped_dates ) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $total_hits = (int) ( $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(id) FROM `{$table_stats}` WHERE utm_campaign = %s {$filter_sql_stats} AND DATE(created_at) >= %s AND DATE(created_at) <= %s",
                    $link->utm_campaign,
                    $start_date,
                    $end_date
                ) ) ?: 0 );
            } else {
                $total_hits = (int) ( $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(id) FROM `{$table_stats}` WHERE utm_campaign = %s",
                    $link->utm_campaign
                ) ) ?: 0 );
            }

            $query_args = $this->oiscl_utm_build_link_query_args( $link );
            $query_code = build_query( $query_args );
            $final_url  = add_query_arg( $query_args, $link->target_url );

            $camp_cell = '<code style="background:#e0f2fe; color:#0369a1; padding:3px 8px; border-radius:4px; font-weight:bold;">' . esc_html( $link->utm_campaign ) . '</code>';
            if ( $total_hits > 0 && 'manage' !== $mode ) {
                $camp_cell = '<span class="j-arrow" style="color:#0284c7; font-size:11px; display:inline-block; transition:0.3s; margin-right:4px;">▶</span>' . $camp_cell;
            }

            $link_cell  = '<div style="display:flex; align-items:flex-start; gap:6px; flex-wrap:wrap;">';
            $link_cell .= '<code style="font-size:11px; word-break:break-all; color:#334155; flex:1; min-width:160px; line-height:1.45;">' . esc_html( $final_url ) . '</code>';
            $link_cell .= '<span style="display:inline-flex; gap:4px; flex-shrink:0;">';
            $link_cell .= '<button type="button" class="oiscl-icon-btn oiscl-copy-text" data-copy="' . esc_attr( $final_url ) . '" title="' . esc_attr__( 'Copy full URL', 'ois-conversion-suite' ) . '" aria-label="' . esc_attr__( 'Copy full URL', 'ois-conversion-suite' ) . '"><span class="dashicons dashicons-admin-links"></span></button>';
            $link_cell .= '<button type="button" class="oiscl-icon-btn oiscl-copy-text" data-copy="' . esc_attr( $query_code ) . '" title="' . esc_attr__( 'Copy UTM code only', 'ois-conversion-suite' ) . '" aria-label="' . esc_attr__( 'Copy UTM code only', 'ois-conversion-suite' ) . '"><span class="dashicons dashicons-tag"></span></button>';
            $link_cell .= '</span></div>';

            $actions_cell = '';
            if ( 'manage' === $mode ) {
                $actions_cell  = '<div style="display:flex; gap:4px; justify-content:flex-end; white-space:nowrap; align-items:center;">';
                if ( $is_new_group ) {
                    $label_rows = isset( $links_by_label[ $link->label_name ] ) ? array_values( $links_by_label[ $link->label_name ] ) : array();
                    $label_rows_b64 = base64_encode( (string) wp_json_encode( $label_rows ) );
                    $actions_cell .= '<button type="button" class="oiscl-icon-btn edit-utm-label-trigger" data-label="' . esc_attr( $link->label_name ) . '" data-utm-rows-b64="' . esc_attr( $label_rows_b64 ) . '" title="' . esc_attr__( 'Edit label and links', 'ois-conversion-suite' ) . '" aria-label="' . esc_attr__( 'Edit label and links', 'ois-conversion-suite' ) . '"><span class="dashicons dashicons-edit"></span></button>';
                }
                $delete_url = wp_nonce_url(
                    admin_url( 'admin.php?page=oiscl-settings&tab=utmtracker&delete_utm=' . (int) $link->id ),
                    'oiscl_delete_utm_' . (int) $link->id
                );
                $actions_cell .= '<a href="' . esc_url( $delete_url ) . '" class="oiscl-icon-btn oiscl-icon-btn--danger" title="' . esc_attr__( 'Delete link', 'ois-conversion-suite' ) . '" aria-label="' . esc_attr__( 'Delete link', 'ois-conversion-suite' ) . '" onclick="event.stopPropagation(); return confirm(\'' . esc_js( __( 'Delete this link?', 'ois-conversion-suite' ) ) . '\')"><span class="dashicons dashicons-trash"></span></a>';
                $actions_cell .= '</div>';
            }

            $cols = array(
                $label_cell,
                $camp_cell,
                ( '' !== (string) $link->utm_term ) ? esc_html( $link->utm_term ) : '<span style="color:#94a3b8;">—</span>',
            );
            if ( 'embed' === $mode && $scoped_dates ) {
                $sessions = $this->oiscl_utm_count_link_sessions( $link, $table_stats, $start_date, $end_date, $filter_sql_stats );
                $convs    = $this->oiscl_utm_count_link_conversions( $link, $table_stats, $start_date, $end_date, $filter_sql_stats );
                $conv_cell = '<span style="color:#94a3b8;">—</span>';
                if ( null !== $convs ) {
                    $rate      = $sessions > 0 ? round( ( $convs / $sessions ) * 100, 1 ) : 0;
                    $conv_cell = '<strong style="color:#166534;">' . esc_html( number_format_i18n( $convs ) ) . '</strong> <span style="color:#64748b;font-size:11px;">(' . esc_html( $rate ) . '%)</span>';
                }
                $cols[] = '<span style="font-size:12px;">' . esc_html( number_format_i18n( $sessions ) ) . '</span>';
                $cols[] = $conv_cell;
                $spend  = (float) $this->oiscl_utm_ref_field( $link, 'spend', 0 );
                if ( $spend > 0 ) {
                    $cpa_txt = ( null !== $convs && $convs > 0 )
                        ? number_format_i18n( $spend / $convs, 2 )
                        : '—';
                    $cols[] = '<span style="font-size:12px;">' . esc_html( number_format_i18n( $spend, 2 ) ) . '</span>';
                    $cols[] = '<span style="font-size:12px;color:#64748b;">' . esc_html( $cpa_txt ) . '</span>';
                } else {
                    $cols[] = '<span style="color:#94a3b8;">—</span>';
                    $cols[] = '<span style="color:#94a3b8;">—</span>';
                }
            }
            $cols[] = $link_cell;
            if ( 'manage' === $mode ) {
                $cols[] = $actions_cell;
            }

            $row = array(
                'class' => ( $total_hits > 0 && 'manage' !== $mode ) ? 'ois-row-accordion' : 'ois-row',
                'cols'  => $cols,
            );

            if ( $total_hits > 0 && 'manage' !== $mode ) {
                $term_sql = $this->oiscl_utm_link_term_sql( $link );
                if ( $scoped_dates ) {
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $journey_events = $wpdb->get_results( $wpdb->prepare(
                        "SELECT anchor_text, context_text, created_at, is_bot FROM `{$table_stats}` WHERE utm_campaign = %s {$term_sql} {$filter_sql_stats} AND DATE(created_at) >= %s AND DATE(created_at) <= %s ORDER BY created_at DESC LIMIT 500",
                        $link->utm_campaign,
                        $start_date,
                        $end_date
                    ) );
                } else {
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $journey_events = $wpdb->get_results( $wpdb->prepare(
                        "SELECT anchor_text, context_text, created_at, is_bot FROM `{$table_stats}` WHERE utm_campaign = %s {$term_sql} ORDER BY created_at DESC LIMIT 500",
                        $link->utm_campaign
                    ) );
                }
                $row['details_html'] = $this->oiscl_build_utm_link_journey_details_html( $journey_events, $link->utm_campaign, (int) $link->id, (string) $link->utm_term );
            }

            $rows[]     = $row;

            $last_label = $link->label_name;
        }

        return $rows;
    }

    /**
     * KPI cards + grouped UTM links table (Settings UTM Tracker and Content & CRO embed).
     *
     * @param array $args {
     *     @type string $mode              'manage' (settings: edit/delete + new link) or 'embed' (dashboard).
     *     @type array  $saved_links       Rows from oiscl_utm_references.
     *     @type string $filter_sql_refs   SQL fragment for refs table.
     *     @type string $filter_sql_stats  SQL fragment for stats table.
     *     @type string $start_date        Optional Y-m-d for scoped metrics (embed).
     *     @type string $end_date          Optional Y-m-d for scoped metrics (embed).
     *     @type string $table_id          HTML table id.
     *     @type string $section_title     Optional heading above KPIs.
     * }
     */
    private function oiscl_render_utm_links_manager_block( $args ) {
        global $wpdb;
        $table_refs  = $wpdb->prefix . 'oiscl_utm_references';
        $table_stats = $wpdb->prefix . 'oiscl_block_metrics';

        $mode             = isset( $args['mode'] ) ? $args['mode'] : 'manage';
        $saved_links      = isset( $args['saved_links'] ) ? $args['saved_links'] : array();
        $filter_sql_refs  = isset( $args['filter_sql_refs'] ) ? $args['filter_sql_refs'] : '';
        $filter_sql_stats = isset( $args['filter_sql_stats'] ) ? $args['filter_sql_stats'] : '';
        $start_date       = isset( $args['start_date'] ) ? $args['start_date'] : '';
        $end_date         = isset( $args['end_date'] ) ? $args['end_date'] : '';
        $table_id         = isset( $args['table_id'] ) ? sanitize_html_class( $args['table_id'] ) : 'utm-manager-table';
        $section_title    = isset( $args['section_title'] ) ? $args['section_title'] : '';
        $hide_block_kpis  = ! empty( $args['hide_block_kpis'] );
        $scoped_dates     = ( '' !== $start_date && '' !== $end_date );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal filter fragment.
        $total_links = (int) ( $wpdb->get_var( "SELECT COUNT(id) FROM `{$table_refs}` WHERE 1=1 {$filter_sql_refs}" ) ?: 0 );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $total_labels = (int) ( $wpdb->get_var( "SELECT COUNT(DISTINCT label_name) FROM `{$table_refs}` WHERE 1=1 {$filter_sql_refs}" ) ?: 0 );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $total_campaigns = (int) ( $wpdb->get_var( "SELECT COUNT(DISTINCT utm_campaign) FROM `{$table_refs}` WHERE 1=1 {$filter_sql_refs}" ) ?: 0 );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $total_terms = (int) ( $wpdb->get_var( "SELECT COUNT(DISTINCT utm_term) FROM `{$table_refs}` WHERE utm_term != '' {$filter_sql_refs}" ) ?: 0 );
        if ( $scoped_dates ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $total_clicks = (int) ( $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(id) FROM `{$table_stats}` WHERE utm_campaign != '' {$filter_sql_stats} AND DATE(created_at) >= %s AND DATE(created_at) <= %s",
                $start_date,
                $end_date
            ) ) ?: 0 );
        } else {
            $total_clicks = (int) ( $wpdb->get_var( "SELECT COUNT(id) FROM `{$table_stats}` WHERE utm_campaign != ''" ) ?: 0 );
        }

        echo '<div style="display:flex; justify-content:' . ( $hide_block_kpis ? 'flex-end' : 'space-between' ) . '; align-items:flex-end; margin-bottom:25px; flex-wrap:wrap; gap:16px;">';
        if ( ! $hide_block_kpis ) {
        if ( 'manage' === $mode ) {
            echo '<div class="oiscl-kpi-stroke-grid" style="flex:1; min-width:280px;">';
            $kpis = array(
                array( 'label' => __( 'Total Links', 'ois-conversion-suite' ), 'value' => $total_links, 'tone' => 'blue' ),
                array( 'label' => __( 'Total Company Labels', 'ois-conversion-suite' ), 'value' => $total_labels, 'tone' => 'accent' ),
                array( 'label' => __( 'Total Campaign ID', 'ois-conversion-suite' ), 'value' => $total_campaigns, 'tone' => 'green' ),
                array( 'label' => __( 'Total UTM Term', 'ois-conversion-suite' ), 'value' => $total_terms, 'tone' => 'purple' ),
            );
            foreach ( $kpis as $kpi ) {
                echo '<div class="oiscl-kpi-stroke oiscl-kpi-stroke--' . esc_attr( $kpi['tone'] ) . '">';
                echo '<p class="oiscl-kpi-stroke__label">' . esc_html( $kpi['label'] ) . '</p>';
                echo '<p class="oiscl-kpi-stroke__value">' . esc_html( number_format_i18n( (int) $kpi['value'] ) ) . '</p>';
                echo '</div>';
            }
            echo '</div>';
        } else {
            echo '<div style="display:flex; gap:20px; flex-wrap:wrap;">';
            echo '<div class="oiscl-card" style="padding:15px 25px; margin:0;"><h3 class="oiscl-card-title" style="margin:0;">' . esc_html__( 'Total Links', 'ois-conversion-suite' ) . '</h3><p class="oiscl-card-value" style="font-size:24px;">' . esc_html( number_format_i18n( $total_links ) ) . '</p></div>';
            echo '<div class="oiscl-card" style="padding:15px 25px; margin:0;"><h3 class="oiscl-card-title" style="margin:0;">' . esc_html__( 'UTM Events', 'ois-conversion-suite' ) . '</h3><p class="oiscl-card-value" style="font-size:24px;">' . esc_html( number_format_i18n( $total_clicks ) ) . '</p></div>';
            echo '</div>';
        }
        }
        if ( 'manage' === $mode ) {
            echo '<button type="button" id="open-utm-modal" class="button button-large oiscl-btn oiscl-btn--primary" style="height:45px; padding:0 25px;">➕ ' . esc_html__( 'New Tracking Link', 'ois-conversion-suite' ) . '</button>';
        } else {
            echo '<a href="' . esc_url( admin_url( 'admin.php?page=oiscl-settings&tab=utmtracker' ) ) . '" class="button button-large oiscl-btn oiscl-btn--outline" style="height:45px; padding:0 25px; display:inline-flex; align-items:center;">⚙️ ' . esc_html__( 'Manage in Settings', 'ois-conversion-suite' ) . '</a>';
        }
        echo '</div>';

        $links_rows = $this->oiscl_build_utm_links_advanced_table_rows(
            $saved_links,
            array(
                'mode'             => $mode,
                'filter_sql_stats' => $filter_sql_stats,
                'start_date'       => $start_date,
                'end_date'         => $end_date,
                'scoped_dates'     => $scoped_dates,
            )
        );

        $table_title = ( '' !== $section_title )
            ? $section_title
            : ( 'manage' === $mode ? __( 'UTM Manager', 'ois-conversion-suite' ) : __( 'Campaign Links', 'ois-conversion-suite' ) );

        $table_subtitle = ( 'manage' === $mode )
            ? __( 'Registry of saved links grouped by company / label. Use ✏️ to edit the label, URLs, and campaigns.', 'ois-conversion-suite' )
            : ( $scoped_dates
            ? sprintf(
                /* translators: 1: start date, 2: end date */
                __( 'Saved UTM links from Settings. Rows with traffic in %1$s–%2$s show ▶ — expand to see each tracked hit (pageviews and clicks) for that campaign + term. Use Listar below for pagination.', 'ois-conversion-suite' ),
                $start_date,
                $end_date
            )
            : __( 'Saved UTM links. Rows with traffic expand to show tracked hits for that campaign + term.', 'ois-conversion-suite' ) );

        $table_headers = array(
            array(
                'label'   => __( 'Company / Label', 'ois-conversion-suite' ),
                'width'   => '16%',
                'type'    => 'string',
                'tooltip' => __( 'Company or label grouping. The first row of each group shows the name.', 'ois-conversion-suite' ),
            ),
            array(
                'label'   => __( 'Campaign ID', 'ois-conversion-suite' ),
                'width'   => '14%',
                'type'    => 'string',
                'tooltip' => __( 'utm_campaign slug. Unique per company together with UTM Term.', 'ois-conversion-suite' ),
            ),
            array(
                'label'   => __( 'UTM Term', 'ois-conversion-suite' ),
                'width'   => '12%',
                'type'    => 'string',
                'tooltip' => __( 'Optional utm_term slug (ad group / variant).', 'ois-conversion-suite' ),
            ),
        );
        if ( 'embed' === $mode ) {
            $table_headers[] = array(
                'label'   => __( 'Sessions', 'ois-conversion-suite' ),
                'width'   => '8%',
                'type'    => 'numeric',
                'align'   => 'right',
                'tooltip' => __( 'Distinct sessions with a pageview for this campaign + term in the date range.', 'ois-conversion-suite' ),
            );
            $table_headers[] = array(
                'label'   => __( 'Conv.', 'ois-conversion-suite' ),
                'width'   => '10%',
                'type'    => 'string',
                'align'   => 'right',
                'tooltip' => __( 'Sessions with a click matching the conversion label set in Settings (count and rate vs sessions).', 'ois-conversion-suite' ),
            );
            $table_headers[] = array(
                'label'   => __( 'Spend', 'ois-conversion-suite' ),
                'width'   => '8%',
                'type'    => 'numeric',
                'align'   => 'right',
                'tooltip' => __( 'Manual ad spend from UTM Manager.', 'ois-conversion-suite' ),
            );
            $table_headers[] = array(
                'label'   => __( 'CPA', 'ois-conversion-suite' ),
                'width'   => '8%',
                'type'    => 'numeric',
                'align'   => 'right',
                'tooltip' => __( 'Spend ÷ conversions when both are set.', 'ois-conversion-suite' ),
            );
        }
        $table_headers[] = array(
            'label'   => __( 'Full link', 'ois-conversion-suite' ),
            'type'    => 'string',
            'tooltip' => __( 'Complete URL with UTM (source, medium, campaign, term). Copy full link or UTM code with the buttons.', 'ois-conversion-suite' ),
        );
        if ( 'manage' === $mode ) {
            $table_headers[] = array(
                'label'   => '',
                'width'   => '8%',
                'type'    => 'string',
                'align'   => 'right',
                'tooltip' => __( 'Edit or delete this reference.', 'ois-conversion-suite' ),
            );
        }

        $this->render_ois_component(
            'advanced_table',
            array(
                'id'               => $table_id,
                'title'            => $table_title,
                'subtitle'         => $table_subtitle,
                'icon'             => '🔗',
                'table_csv_target' => $table_id,
                'headers'          => $table_headers,
                'rows'             => $links_rows,
            )
        );
        if ( 'embed' === $mode ) {
            $this->oiscl_print_campaign_links_journey_pagination_script();
        }
    }

    /**
     * Journey pagination is handled globally in layout_end (trait-oiscl-admin-component.php).
     */
    private function oiscl_print_campaign_links_journey_pagination_script() {
        // Handlers live in render_ois_component layout_end — no duplicate bindings here.
    }

    /**
     * Shared UTM campaign filter dropdown (Content chart, Journey toolbar). Uses class oiscl-utm-filter-redirect for JS.
     *
     * @param string              $selected_filter Raw utm_filter value.
     * @param array<string,array> $filter_hierarchy label_name => list of utm_campaign.
     * @param string              $all_option_label First option label (e.g. "All").
     * @param string              $select_id        HTML id (must be unique per instance).
     * @return string HTML (no outer wrapper).
     */
    /**
     * Whether anchor_text is a passive read / pageview event (not a conversion click).
     *
     * @param string $anchor_text Metric anchor_text.
     * @return bool
     */
    private function oiscl_utm_is_read_event( $anchor_text ) {
        $a = (string) $anchor_text;
        return in_array(
            $a,
            array(
                OISCL_Plan::EVENT_PAGEVIEW,
                OISCL_Plan::EVENT_BLOCK_LEGACY,
                OISCL_Plan::EVENT_BLOCK_VIEW,
                'Reading',
            ),
            true
        );
    }

    /**
     * Human-readable event label for UTM journey / raw log tables.
     *
     * @param string $anchor_text  anchor_text column.
     * @param string $context_text context_text column.
     * @return string Plain text label (escaped by caller).
     */
    private function oiscl_utm_format_event_label( $anchor_text, $context_text = '' ) {
        $anchor  = (string) $anchor_text;
        $context = trim( (string) $context_text );

        if ( OISCL_Plan::EVENT_PAGEVIEW === $anchor ) {
            return $context !== ''
                ? sprintf(
                    /* translators: %s: traffic source or visit context */
                    __( 'Pageview · %s', 'ois-conversion-suite' ),
                    $context
                )
                : __( 'Pageview', 'ois-conversion-suite' );
        }

        if ( in_array( $anchor, array( OISCL_Plan::EVENT_BLOCK_LEGACY, OISCL_Plan::EVENT_BLOCK_VIEW, 'Reading' ), true ) ) {
            $base = ( 'Reading' === $anchor || OISCL_Plan::EVENT_BLOCK_LEGACY === $anchor )
                ? __( 'Block view (legacy)', 'ois-conversion-suite' )
                : __( 'Block view', 'ois-conversion-suite' );
            return $context !== '' ? $base . ' · ' . $context : $base;
        }

        if ( '' === $anchor ) {
            return __( 'Unknown event', 'ois-conversion-suite' );
        }

        return $anchor;
    }

    /**
     * UTM Click Tracker KPI metrics (UTM campaign filter + same row rules as other UTM tabs; date range may follow Track Pro revision scope).
     *
     * @param string              $table_stats      Table name.
     * @param string              $start_date       Range start.
     * @param string              $end_date         Range end.
     * @param string              $prev_start       Previous period start.
     * @param string              $prev_end         Previous period end.
     * @param string              $filter_sql_stats UTM filter SQL fragment.
     * @return array<string,int|float>
     */
    private function oiscl_utm_fetch_scoped_tab_kpis( $table_stats, $start_date, $end_date, $prev_start, $prev_end, $filter_sql_stats ) {
        global $wpdb;

        $sql_exclude = OISCL_Plan::sql_exclude_actions_not_in();
        $ois_now     = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - 300 );
        $prep        = function( $sql, $base_args ) use ( $filter_sql_stats ) {
            return $this->oiscl_utm_click_prepare_sql( $sql, $base_args, $filter_sql_stats );
        };
        $get_var_prepared = function( $sql, array $base_args ) use ( $wpdb, $prep ) {
            $p = $prep( $sql, $base_args );
            if ( false === $p ) {
                return 0;
            }
            return (int) ( $wpdb->get_var( $p ) ?: 0 );
        };

        $live_views = $get_var_prepared( "SELECT COUNT(DISTINCT session_id) FROM `{$table_stats}` WHERE created_at >= %s", array( $ois_now ) );

        $utm_hits = $get_var_prepared(
            "SELECT COUNT(id) FROM `{$table_stats}` WHERE DATE(created_at) >= %s AND DATE(created_at) <= %s",
            array( $start_date, $end_date )
        );
        $prev_utm_hits = $get_var_prepared(
            "SELECT COUNT(id) FROM `{$table_stats}` WHERE DATE(created_at) >= %s AND DATE(created_at) <= %s",
            array( $prev_start, $prev_end )
        );

        $utm_users = $get_var_prepared(
            "SELECT COUNT(DISTINCT session_id) FROM `{$table_stats}` WHERE DATE(created_at) >= %s AND DATE(created_at) <= %s",
            array( $start_date, $end_date )
        );
        $prev_utm_users = $get_var_prepared(
            "SELECT COUNT(DISTINCT session_id) FROM `{$table_stats}` WHERE DATE(created_at) >= %s AND DATE(created_at) <= %s",
            array( $prev_start, $prev_end )
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $utm_actions = $get_var_prepared(
            "SELECT COUNT(id) FROM `{$table_stats}` WHERE anchor_text NOT IN ({$sql_exclude}) AND DATE(created_at) >= %s AND DATE(created_at) <= %s",
            array( $start_date, $end_date )
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $prev_utm_actions = $get_var_prepared(
            "SELECT COUNT(id) FROM `{$table_stats}` WHERE anchor_text NOT IN ({$sql_exclude}) AND DATE(created_at) >= %s AND DATE(created_at) <= %s",
            array( $prev_start, $prev_end )
        );

        $utm_ctr      = $utm_hits > 0 ? round( ( $utm_actions / $utm_hits ) * 100, 1 ) : 0;
        $prev_utm_ctr = $prev_utm_hits > 0 ? round( ( $prev_utm_actions / $prev_utm_hits ) * 100, 1 ) : 0;

        return array(
            'live_views'       => $live_views,
            'utm_hits'         => $utm_hits,
            'prev_utm_hits'    => $prev_utm_hits,
            'utm_users'        => $utm_users,
            'prev_utm_users'   => $prev_utm_users,
            'utm_actions'      => $utm_actions,
            'prev_utm_actions' => $prev_utm_actions,
            'utm_ctr'          => $utm_ctr,
            'prev_utm_ctr'     => $prev_utm_ctr,
        );
    }

    /**
     * Audience list datasets for UI + CSV export.
     *
     * @param string $list_key         Dataset key.
     * @param string $table_stats      Metrics table.
     * @param string $start_date       Y-m-d.
     * @param string $end_date         Y-m-d.
     * @param string $filter_sql_stats SQL fragment.
     * @return array<int, object{label:string,total:int}>
     */
    private function oiscl_get_utm_audience_list_data( $list_key, $table_stats, $start_date, $end_date, $filter_sql_stats ) {
        global $wpdb;

        $list_key = sanitize_key( $list_key );
        $no_term  = __( '(no term)', 'ois-conversion-suite' );
        $no_src   = __( '(no source)', 'ois-conversion-suite' );
        $no_med   = __( '(no medium)', 'ois-conversion-suite' );

        switch ( $list_key ) {
            case 'traffic':
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                return (array) $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT d.lbl AS label, d.cnt AS total FROM (
                            SELECT CASE
                                WHEN destination_url IS NOT NULL AND TRIM(destination_url) <> ''
                                    THEN SUBSTRING_INDEX(destination_url, '?', 1)
                                WHEN traffic_source IS NOT NULL AND TRIM(traffic_source) <> ''
                                    THEN TRIM(traffic_source)
                                ELSE 'Direct / Unknown'
                            END AS lbl,
                            SUM(clicks) AS cnt
                            FROM `{$table_stats}`
                            WHERE utm_campaign != '' {$filter_sql_stats}
                            AND DATE(created_at) >= %s AND DATE(created_at) <= %s
                            GROUP BY lbl
                        ) d ORDER BY total DESC LIMIT 40",
                        $start_date,
                        $end_date
                    )
                );
            case 'countries':
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                return (array) $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT country AS label, SUM(clicks) AS total FROM `{$table_stats}` WHERE utm_campaign != '' {$filter_sql_stats} AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY country ORDER BY total DESC LIMIT 40",
                        $start_date,
                        $end_date
                    )
                );
            case 'cities':
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                return (array) $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT CONCAT(IFNULL(NULLIF(TRIM(city), ''), '?'), ' — ', IFNULL(country, '')) AS label, SUM(clicks) AS total FROM `{$table_stats}` WHERE utm_campaign != '' {$filter_sql_stats} AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY city, country ORDER BY total DESC LIMIT 40",
                        $start_date,
                        $end_date
                    )
                );
            case 'utm_campaigns':
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                return (array) $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT utm_campaign AS label, SUM(clicks) AS total FROM `{$table_stats}` WHERE utm_campaign != '' {$filter_sql_stats} AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY utm_campaign ORDER BY total DESC LIMIT 40",
                        $start_date,
                        $end_date
                    )
                );
            case 'utm_terms':
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                return (array) $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT IFNULL(NULLIF(TRIM(utm_term), ''), %s) AS label, SUM(clicks) AS total FROM `{$table_stats}` WHERE utm_campaign != '' {$filter_sql_stats} AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY utm_term ORDER BY total DESC LIMIT 40",
                        $no_term,
                        $start_date,
                        $end_date
                    )
                );
            case 'utm_landings':
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                return (array) $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT SUBSTRING_INDEX(origin_url, '?', 1) AS label, SUM(clicks) AS total FROM `{$table_stats}` WHERE utm_campaign != '' {$filter_sql_stats} AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY SUBSTRING_INDEX(origin_url, '?', 1) ORDER BY total DESC LIMIT 40",
                        $start_date,
                        $end_date
                    )
                );
            case 'utm_sources':
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                return (array) $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT IFNULL(NULLIF(TRIM(utm_source), ''), %s) AS label, SUM(clicks) AS total FROM `{$table_stats}` WHERE utm_campaign != '' {$filter_sql_stats} AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY utm_source ORDER BY total DESC LIMIT 40",
                        $no_src,
                        $start_date,
                        $end_date
                    )
                );
            case 'utm_mediums':
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                return (array) $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT IFNULL(NULLIF(TRIM(utm_medium), ''), %s) AS label, SUM(clicks) AS total FROM `{$table_stats}` WHERE utm_campaign != '' {$filter_sql_stats} AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY utm_medium ORDER BY total DESC LIMIT 40",
                        $no_med,
                        $start_date,
                        $end_date
                    )
                );
            default:
                return array();
        }
    }

    /**
     * CSV export for UTM Audience top lists.
     *
     * @param string $start_date   Y-m-d.
     * @param string $end_date     Y-m-d.
     * @param string $utm_filter   Dashboard filter key.
     * @param string $list_key     Dataset key.
     */
    public function oiscl_export_utm_audience_csv( $start_date, $end_date, $utm_filter, $list_key ) {
        global $wpdb;

        $table_stats = $wpdb->prefix . 'oiscl_block_metrics';
        $filters     = $this->get_oiscl_utm_dashboard_filters( $utm_filter );
        $rows        = $this->oiscl_get_utm_audience_list_data( $list_key, $table_stats, $start_date, $end_date, $filters['filter_sql_stats'] );

        if ( ob_get_length() ) {
            ob_clean();
        }
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=UTM_Audience_' . sanitize_file_name( $list_key ) . '_' . $start_date . '_to_' . $end_date . '.csv' );
        $out = fopen( 'php://output', 'w' );
        fwrite( $out, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );
        fputcsv( $out, array( __( 'Label', 'ois-conversion-suite' ), __( 'Total hits', 'ois-conversion-suite' ) ) );
        foreach ( $rows as $row ) {
            fputcsv( $out, array( isset( $row->label ) ? (string) $row->label : '', isset( $row->total ) ? (int) $row->total : 0 ) );
        }
        fclose( $out );
        exit;
    }

    /**
     * CSV export for UTM Funnel tables (company rollup and/or per saved campaign link).
     *
     * @param string $start_date Y-m-d.
     * @param string $end_date   Y-m-d.
     * @param string $utm_filter Dashboard filter key (same as utm_filter query arg).
     * @param string $scope      company|campaign|both|global|complete.
     */
    public function oiscl_export_utm_funnel_csv( $start_date, $end_date, $utm_filter, $scope ) {
        global $wpdb;

        $table_refs       = $wpdb->prefix . 'oiscl_utm_references';
        $table_stats      = $wpdb->prefix . 'oiscl_block_metrics';
        $filters          = $this->get_oiscl_utm_dashboard_filters( $utm_filter );
        $filter_sql_refs  = $filters['filter_sql_refs'];
        $filter_sql_stats = $filters['filter_sql_stats'];

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $saved_links = $wpdb->get_results(
            "SELECT * FROM `{$table_refs}` WHERE 1=1 {$filter_sql_refs} ORDER BY label_name ASC, utm_campaign ASC, utm_term ASC"
        );

        $by_label = array();
        foreach ( (array) $saved_links as $link ) {
            $by_label[ $link->label_name ][] = $link;
        }

        $sections        = OISCL_Utm_Query_Helper::funnel_csv_sections( $scope );
        $need_global     = $sections['global'];
        $need_company    = $sections['company'];
        $need_campaign   = $sections['campaign'];

        $pct_plain = static function ( $num, $den ) {
            if ( $den <= 0 ) {
                return '—';
            }
            return (string) round( ( (int) $num / (int) $den ) * 100, 1 ) . '%';
        };

        if ( ob_get_length() ) {
            ob_clean();
        }
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header(
            'Content-Disposition: attachment; filename=UTM_Funnel_' . sanitize_file_name( $scope ) . '_' . $start_date . '_to_' . $end_date . '.csv'
        );

        $out = fopen( 'php://output', 'w' );
        fwrite( $out, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

        if ( $need_global ) {
            $global_session_rows = $this->oiscl_utm_fetch_funnel_session_rows_any_utm( $table_stats, $start_date, $end_date, $filter_sql_stats );
            $gs                  = $this->oiscl_utm_analyze_funnel_sessions( $global_session_rows, '' );
            $s1                  = (int) $gs['step1'];
            $s2                  = (int) $gs['step2'];
            $s3                  = (int) $gs['step3'];
            fputcsv(
                $out,
                array(
                    __( 'Company', 'ois-conversion-suite' ),
                    __( 'Landings', 'ois-conversion-suite' ),
                    __( 'Block views', 'ois-conversion-suite' ),
                    __( '1→2', 'ois-conversion-suite' ),
                    __( 'Conversions', 'ois-conversion-suite' ),
                    __( '2→3', 'ois-conversion-suite' ),
                    __( 'Overall', 'ois-conversion-suite' ),
                )
            );
            fputcsv(
                $out,
                array(
                    __( 'Global UTM funnel (any campaign)', 'ois-conversion-suite' ),
                    $s1,
                    $s2,
                    $pct_plain( $s2, $s1 ),
                    $s3,
                    $pct_plain( $s3, $s2 ),
                    $pct_plain( $s3, $s1 ),
                )
            );
        }

        if ( $need_global && ( $need_company || $need_campaign ) ) {
            fputcsv( $out, array() );
        }

        if ( $need_company ) {
            fputcsv(
                $out,
                array(
                    __( 'Company', 'ois-conversion-suite' ),
                    __( 'Landings', 'ois-conversion-suite' ),
                    __( 'Block views', 'ois-conversion-suite' ),
                    __( '1→2', 'ois-conversion-suite' ),
                    __( 'Conversions', 'ois-conversion-suite' ),
                    __( '2→3', 'ois-conversion-suite' ),
                    __( 'Overall', 'ois-conversion-suite' ),
                )
            );
            foreach ( $by_label as $label_name => $links ) {
                $stats = $this->oiscl_utm_analyze_company_funnel(
                    $label_name,
                    $links,
                    $table_stats,
                    $start_date,
                    $end_date,
                    $filter_sql_stats
                );
                $s1 = (int) $stats['step1'];
                $s2 = (int) $stats['step2'];
                $s3 = (int) $stats['step3'];
                fputcsv(
                    $out,
                    array(
                        (string) $label_name,
                        $s1,
                        $s2,
                        $pct_plain( $s2, $s1 ),
                        $s3,
                        $pct_plain( $s3, $s2 ),
                        $pct_plain( $s3, $s1 ),
                    )
                );
            }
        }

        if ( $need_company && $need_campaign ) {
            fputcsv( $out, array() );
        }

        if ( $need_campaign ) {
            fputcsv(
                $out,
                array(
                    __( 'Company', 'ois-conversion-suite' ),
                    __( 'Campaign', 'ois-conversion-suite' ),
                    __( 'Conv. target', 'ois-conversion-suite' ),
                    __( 'Landings', 'ois-conversion-suite' ),
                    __( 'Blocks', 'ois-conversion-suite' ),
                    __( '1→2', 'ois-conversion-suite' ),
                    __( 'Conv.', 'ois-conversion-suite' ),
                    __( '2→3', 'ois-conversion-suite' ),
                    __( 'Overall', 'ois-conversion-suite' ),
                )
            );
            foreach ( (array) $saved_links as $link ) {
                $stats = $this->oiscl_utm_analyze_link_funnel(
                    $link,
                    $table_stats,
                    $start_date,
                    $end_date,
                    $filter_sql_stats
                );
                $conv_label = '' !== $stats['conv_label']
                    ? $stats['conv_label']
                    : __( 'any click', 'ois-conversion-suite' );
                $camp       = (string) $link->utm_campaign;
                if ( '' !== (string) $link->utm_term ) {
                    $camp .= ' / ' . (string) $link->utm_term;
                }
                $s1 = (int) $stats['step1'];
                $s2 = (int) $stats['step2'];
                $s3 = (int) $stats['step3'];
                fputcsv(
                    $out,
                    array(
                        (string) $link->label_name,
                        $camp,
                        $conv_label,
                        $s1,
                        $s2,
                        $pct_plain( $s2, $s1 ),
                        $s3,
                        $pct_plain( $s3, $s2 ),
                        $pct_plain( $s3, $s1 ),
                    )
                );
            }
        }

        fclose( $out );
        exit;
    }

    /**
     * Escape literal % in SQL fragments that are concatenated into a later $wpdb->prepare() template.
     * Nested fragments (e.g. utm_term = '50% off') otherwise break vsprintf and prepare returns false → empty UI.
     *
     * @param string $fragment SQL literal fragment.
     * @return string
     */
    private function oiscl_utm_sql_fragment_for_prepare( $fragment ) {
        return str_replace( '%', '%%', (string) $fragment );
    }

    /**
     * Inject UTM row filter (utm_campaign non-empty + dashboard company/campaign filter) into WHERE, then prepare().
     *
     * @param string              $sql              SQL with placeholders.
     * @param array<int,mixed>    $base_args        Placeholder values for the outer query.
     * @param string              $filter_sql_stats UTM dashboard filter fragment.
     * @return string|false
     */
    private function oiscl_utm_click_prepare_sql( $sql, array $base_args, $filter_sql_stats ) {
        global $wpdb;
        $frag      = $this->oiscl_utm_sql_fragment_for_prepare( $filter_sql_stats );
        $utm_where = " AND utm_campaign != '' {$frag}";

        /*
         * Never append after ORDER BY — that yields invalid SQL ("ORDER BY x DESC AND utm_campaign != ''").
         * Logic shared with OISCL_Utm_Query_Helper (unit-tested).
         */
        $sql = OISCL_Utm_Query_Helper::inject_before_group_order_limit( $sql, $utm_where );

        return $wpdb->prepare( $sql, $base_args );
    }

    /**
     * Raw DB readout for admins: proves `oiscl_block_metrics` is readable and shows whether `utm_campaign` is stored.
     * HTML debug panel only — no extra MySQL table.
     *
     * Enable only from `wp-config.php`: `define( 'OISCL_UTM_DIAG', true );`
     * Remove the line or set false when finished testing.
     *
     * @param string $table_stats      Full table name (with prefix).
     * @param string $start_date       Dashboard range start Y-m-d.
     * @param string $end_date         Dashboard range end Y-m-d.
     * @param string $filter_sql_stats UTM company/campaign filter fragment.
     */
    private function oiscl_render_utm_block_metrics_diag( $table_stats, $start_date, $end_date, $filter_sql_stats ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( ! defined( 'OISCL_UTM_DIAG' ) || ! OISCL_UTM_DIAG ) {
            return;
        }

        global $wpdb;
        $frag = $this->oiscl_utm_sql_fragment_for_prepare( $filter_sql_stats );

        $cnt_range = (int) ( $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table_stats}` WHERE DATE(created_at) >= %s AND DATE(created_at) <= %s",
                $start_date,
                $end_date
            )
        ) ?: 0 );

        $cnt_utm_range = (int) ( $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table_stats}` WHERE utm_campaign != '' {$frag} AND DATE(created_at) >= %s AND DATE(created_at) <= %s",
                $start_date,
                $end_date
            )
        ) ?: 0 );

        $cnt_utm_all_time = (int) ( $wpdb->get_var( "SELECT COUNT(*) FROM `{$table_stats}` WHERE utm_campaign != ''" ) ?: 0 );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $recent = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, created_at, session_id, utm_campaign, utm_term, anchor_text,
                SUBSTRING( origin_url, 1, 120 ) AS origin_snip,
                SUBSTRING( destination_url, 1, 80 ) AS dest_snip
                FROM `{$table_stats}` ORDER BY id DESC LIMIT %d",
                50
            ),
            ARRAY_A
        );

        echo '<div class="ois-box" style="margin:24px 0;padding:16px;border:2px dashed #c00;background:#fff8f8;">';
        echo '<h3 style="margin:0 0 10px;">🔧 ' . esc_html__( 'UTM metrics diagnostic (raw DB read)', 'ois-conversion-suite' ) . '</h3>';
        echo '<p style="margin:0 0 12px;font-size:13px;color:#333;">' . esc_html__( 'Shown only when wp-config.php defines OISCL_UTM_DIAG as true. Remove it after testing.', 'ois-conversion-suite' ) . '</p>';
        echo '<ul style="margin:0 0 14px;font-size:13px;line-height:1.6;">';
        echo '<li><strong>' . esc_html__( 'Table', 'ois-conversion-suite' ) . ':</strong> <code>' . esc_html( $table_stats ) . '</code></li>';
        if ( function_exists( 'is_multisite' ) && is_multisite() ) {
            echo '<li><strong>' . esc_html__( 'Site / blog ID', 'ois-conversion-suite' ) . ':</strong> ' . (int) get_current_blog_id() . '</li>';
        }
        echo '<li><strong>' . esc_html__( 'Dashboard date range', 'ois-conversion-suite' ) . ':</strong> ' . esc_html( $start_date ) . ' — ' . esc_html( $end_date ) . '</li>';
        echo '<li><strong>' . esc_html__( 'Rows in range (any)', 'ois-conversion-suite' ) . ':</strong> ' . (int) $cnt_range . '</li>';
        echo '<li><strong>' . esc_html__( 'Rows in range with utm_campaign + current UTM filter', 'ois-conversion-suite' ) . ':</strong> ' . (int) $cnt_utm_range . '</li>';
        echo '<li><strong>' . esc_html__( 'Rows all-time with non-empty utm_campaign', 'ois-conversion-suite' ) . ':</strong> ' . (int) $cnt_utm_all_time . '</li>';
        echo '</ul>';

        if ( false === $recent ) {
            echo '<p class="notice notice-error" style="margin:0;">' . esc_html( $wpdb->last_error ? $wpdb->last_error : __( 'Query failed.', 'ois-conversion-suite' ) ) . '</p>';
            echo '</div>';
            return;
        }

        echo '<p style="margin:0 0 8px;font-weight:600;">' . esc_html__( 'Last 50 rows (newest first, any date)', 'ois-conversion-suite' ) . '</p>';
        echo '<div style="overflow:auto;max-height:420px;border:1px solid #ccd0d4;background:#fff;">';
        echo '<table class="widefat striped" style="font-size:11px;margin:0;"><thead><tr>';
        $cols = array( 'id', 'created_at', 'session_id', 'utm_campaign', 'utm_term', 'anchor_text', 'origin_snip', 'dest_snip' );
        foreach ( $cols as $c ) {
            echo '<th>' . esc_html( $c ) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ( (array) $recent as $r ) {
            echo '<tr>';
            foreach ( $cols as $c ) {
                $v = isset( $r[ $c ] ) ? (string) $r[ $c ] : '';
                echo '<td style="word-break:break-all;">' . esc_html( $v ) . '</td>';
            }
            echo '</tr>';
        }
        if ( empty( $recent ) ) {
            echo '<tr><td colspan="' . (int) count( $cols ) . '">' . esc_html__( 'No rows in this table.', 'ois-conversion-suite' ) . '</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    /**
     * UTM Click Tracker tab: Overview / Clicks / Reading Map (UTM traffic only + page scope).
     *
     * @param array<string,mixed> $args Context from display_campaigns_page().
     */
    private function oiscl_render_utm_click_tracker_tab( $args ) {
        global $wpdb;

        $table_stats      = $args['table_stats'];
        $start_date       = $args['start_date'];
        $end_date         = $args['end_date'];
        $prev_start       = $args['prev_start'];
        $prev_end         = $args['prev_end'];
        $today            = $args['today'];
        $filter_sql_stats = $args['filter_sql_stats'];
        $selected_filter  = $args['selected_filter'];
        $filter_hierarchy = $args['filter_hierarchy'];

        // Track Pro scope (tp_page / tp_revision): keep for UI + URL params only. Do NOT replace $start_date/$end_date
        // with resolve_report_scope()'s revision-window intersection — those dates can fall outside where the test
        // was recorded while every other UTM tab still uses the dashboard range, which looked like "only Click Tracker is empty".
        $report_scope = OISCL_Tracking::resolve_report_scope( $_GET, $start_date, $end_date, $today );
        $tp_page      = (int) $report_scope['post_id'];
        $tp_revision  = (int) $report_scope['revision'];
        $scope_qs     = array( 'tab' => 'click_tracker' );
        if ( 'all' !== $selected_filter ) {
            $scope_qs['utm_filter'] = $selected_filter;
        }
        if ( $tp_page > 0 ) {
            $scope_qs['tp_page'] = $tp_page;
        }
        if ( $tp_revision > 0 ) {
            $scope_qs['tp_revision'] = $tp_revision;
        }

        $scoped_kpis = $this->oiscl_utm_fetch_scoped_tab_kpis(
            $table_stats,
            $start_date,
            $end_date,
            $prev_start,
            $prev_end,
            $filter_sql_stats
        );

        $uct_tab = isset( $_GET['uct_tab'] ) ? sanitize_key( wp_unslash( $_GET['uct_tab'] ) ) : 'overview';
        if ( ! in_array( $uct_tab, array( 'overview', 'clicks', 'reading' ), true ) ) {
            $uct_tab = 'overview';
        }

        $sql_block   = OISCL_Plan::sql_block_view_anchor_in();
        // Charts + Clicks tab: include all UTM-tagged hits (pageviews, block views, reading rows, links).
        // sql_exclude_actions_not_in() matches OIS Click Tracker "conversion clicks" only — with UTM tests
        // often only pageview + blocks carry utm_campaign, so charts/tables stayed empty while KPIs did not.
        $sql_utm_ct_list_exclude = "'" . esc_sql( OISCL_Plan::EVENT_ERROR_404 ) . "'";
        $pv_esc                   = esc_sql( OISCL_Plan::EVENT_PAGEVIEW );
        $prep                      = function( $sql, $base_args ) use ( $filter_sql_stats ) {
            return $this->oiscl_utm_click_prepare_sql( $sql, $base_args, $filter_sql_stats );
        };

        $this->oiscl_render_utm_tab_kpi_row(
            $scoped_kpis['live_views'],
            $scoped_kpis['utm_hits'],
            $scoped_kpis['prev_utm_hits'],
            $scoped_kpis['utm_users'],
            $scoped_kpis['prev_utm_users'],
            $scoped_kpis['utm_actions'],
            $scoped_kpis['prev_utm_actions'],
            $scoped_kpis['utm_ctr'],
            $scoped_kpis['prev_utm_ctr']
        );

        $configured_pages   = OISCL_Tracking::get_configured_pages_for_reports();
        $revision_windows   = $tp_page > 0 ? OISCL_Tracking::get_revision_windows( $tp_page ) : array();
        $uct_tab_base       = add_query_arg(
            array_merge(
                array(
                    'page'       => 'oiscl-utm-tracker',
                    'start_date' => $start_date,
                    'end_date'   => $end_date,
                    'tab'        => 'click_tracker',
                ),
                $scope_qs
            ),
            admin_url( 'admin.php' )
        );
        $uct_tabs = array(
            'overview' => __( 'Overview', 'ois-conversion-suite' ),
            'clicks'   => __( 'Clicks', 'ois-conversion-suite' ),
            'reading'  => __( 'Reading Map', 'ois-conversion-suite' ),
        );

        echo '<style>.filter-dropdown-container{position:relative;}#btn-utm-ct-filter-main{background:#fff;border:1px solid #ccd0d4;padding:6px 12px;border-radius:4px;cursor:pointer;font-weight:600;display:flex;align-items:center;gap:8px;}#ois-utm-ct-filter-menu.filter-menu{position:absolute;top:110%;right:0;background:#fff;border:1px solid #ccd0d4;border-radius:4px;box-shadow:0 4px 12px rgba(0,0,0,0.15);z-index:999;width:220px;padding:10px;display:none;}#ois-utm-ct-filter-menu.filter-menu.active{display:block;}.badge-cat{font-size:9px;padding:2px 5px;border-radius:3px;text-transform:uppercase;font-weight:bold;margin-right:8px;display:inline-block;min-width:55px;text-align:center;}.cat-contact{background:#e6fffa;color:#047481;border:1px solid #b2f5ea;}.cat-forms{background:#ebf4ff;color:#2b6cb0;border:1px solid #bee3f8;}.cat-pages{background:#e9d8fd;color:#553c9a;border:1px solid #d6bcfa;}.cat-media{background:#fff5f5;color:#c53030;border:1px solid #feb2b2;}.cat-external{background:#fffaf0;color:#9c4221;border:1px solid #feebc8;}.cat-interface{background:#f7fafc;color:#4a5568;border:1px solid #edf2f7;}</style>';

        echo '<div class="ois-box" style="margin:0 0 16px 0; padding:14px 16px; display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end;">';
        echo '<div><label for="oiscl-utm-ct-filter" style="display:block; font-size:11px; color:#646970; margin-bottom:4px;">' . esc_html__( 'UTM filter', 'ois-conversion-suite' ) . '</label>';
        echo $this->oiscl_get_utm_tracker_filter_select_html( $selected_filter, $filter_hierarchy, __( 'All Companies & Campaigns', 'ois-conversion-suite' ), 'oiscl-utm-ct-filter' );
        echo '</div>';

        if ( ! empty( $configured_pages ) ) {
            $scope_base = array_merge(
                array(
                    'page'       => 'oiscl-utm-tracker',
                    'start_date' => $start_date,
                    'end_date'   => $end_date,
                    'tab'        => 'click_tracker',
                    'uct_tab'    => $uct_tab,
                ),
                $scope_qs
            );
            unset( $scope_base['tp_page'], $scope_base['tp_revision'] );

            echo '<div><label for="oiscl-utm-ct-page" style="display:block; font-size:11px; color:#646970; margin-bottom:4px;">' . esc_html__( 'Tracked page', 'ois-conversion-suite' ) . '</label>';
            echo '<select id="oiscl-utm-ct-page" class="oiscl-utm-ct-scope" style="min-width:220px;">';
            echo '<option value="">' . esc_html__( 'All pages', 'ois-conversion-suite' ) . '</option>';
            foreach ( $configured_pages as $pid => $title ) {
                echo '<option value="' . esc_attr( (string) $pid ) . '"' . selected( $tp_page, (int) $pid, false ) . '>' . esc_html( $title ) . '</option>';
            }
            echo '</select></div>';
            echo '<div><label for="oiscl-utm-ct-revision" style="display:block; font-size:11px; color:#646970; margin-bottom:4px;">' . esc_html__( 'Config version', 'ois-conversion-suite' ) . '</label>';
            echo '<select id="oiscl-utm-ct-revision" class="oiscl-utm-ct-scope" style="min-width:220px;"' . ( $tp_page <= 0 ? ' disabled' : '' ) . '>';
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
        }
        echo '</div>';

        if ( $tp_page > 0 ) {
            $scope_note = esc_html( $report_scope['page_title'] );
            if ( $tp_revision > 0 && ! empty( $report_scope['window'] ) ) {
                $w = $report_scope['window'];
                $scope_note .= ' · ' . esc_html( $report_scope['revision_label'] ) . ' · ' . esc_html( date_i18n( 'M j, Y', strtotime( $w['start_date'] ) ) . ' – ' . date_i18n( 'M j, Y', strtotime( $w['end_date'] ) ) );
            }
            echo '<p class="description" style="margin:-6px 0 16px; color:#50575e;">' . sprintf(
                /* translators: %s: tracked page title and optional revision label/window (informational) */
                esc_html__( '%s — Charts and tables use the same date range as the page header (other UTM tabs), not the revision calendar alone. Data is every hit with utm_campaign site-wide, not restricted to this URL. Use OIS Click Tracker (Track Pro) for strict URL-scoped click maps.', 'ois-conversion-suite' ),
                '<strong>' . $scope_note . '</strong>'
            ) . '</p>';
        } else {
            echo '<p class="description" style="margin:-6px 0 16px; color:#50575e;">' . esc_html__( 'Same filters as other UTM tabs: only rows with a stored utm_campaign. Use OIS Click Tracker (Track Pro) to narrow clicks to one tracked page by exact URL.', 'ois-conversion-suite' ) . '</p>';
        }

        echo '<div class="oiscl-uct-tabstrip oiscl-wp-tabstrip nav-tab-wrapper" style="margin:0 0 20px 0;">';
        foreach ( $uct_tabs as $slug => $label ) {
            $active = ( $uct_tab === $slug ) ? ' nav-tab-active' : '';
            $href   = esc_url( add_query_arg( 'uct_tab', $slug, $uct_tab_base ) );
            echo '<a href="' . $href . '" class="nav-tab' . esc_attr( $active ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</div>';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- literals from OISCL_Plan + esc_sql().
        $sql_clicks = $prep(
            "SELECT origin_url, anchor_text, destination_url, context_text, utm_campaign, SUM(clicks) as total_clicks, AVG(time_spent) as avg_time FROM `{$table_stats}` WHERE anchor_text NOT IN ({$sql_utm_ct_list_exclude}) AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY origin_url, anchor_text, destination_url, context_text, utm_campaign ORDER BY total_clicks DESC",
            array( $start_date, $end_date )
        );
        $clicks_data = ( false !== $sql_clicks ) ? $wpdb->get_results( $sql_clicks ) : array();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql_hourly = $prep(
            "SELECT HOUR(created_at) as hr, SUM(clicks) as total FROM `{$table_stats}` WHERE anchor_text NOT IN ({$sql_utm_ct_list_exclude}) AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY HOUR(created_at) ORDER BY HOUR(created_at) ASC",
            array( $start_date, $end_date )
        );
        $hourly_data = ( false !== $sql_hourly ) ? $wpdb->get_results( $sql_hourly ) : array();
        $hours_values = array_fill( 0, 24, 0 );
        foreach ( (array) $hourly_data as $h ) {
            $hours_values[ (int) $h->hr ] = (int) $h->total;
        }
        $total_overall_clicks = array_sum( $hours_values );

        $sevendays_ago = date( 'Y-m-d', strtotime( $today . ' -6 days' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql_daily = $prep(
            "SELECT DATE(created_at) as dt, SUM(CASE WHEN anchor_text='{$pv_esc}' THEN clicks ELSE 0 END) as views, SUM(CASE WHEN anchor_text!='{$pv_esc}' AND anchor_text NOT IN ({$sql_utm_ct_list_exclude}) THEN clicks ELSE 0 END) as actions FROM `{$table_stats}` WHERE DATE(created_at) >= %s GROUP BY dt ORDER BY dt ASC",
            array( $sevendays_ago )
        );
        $daily_traffic = ( false !== $sql_daily ) ? $wpdb->get_results( $sql_daily ) : array();
        $period_7d = new DatePeriod( new DateTime( $sevendays_ago ), new DateInterval( 'P1D' ), ( new DateTime( $today ) )->modify( '+1 day' ) );
        $d7_labels = array();
        $d7_views  = array();
        $d7_actions = array();
        foreach ( $period_7d as $dt ) {
            $d = $dt->format( 'Y-m-d' );
            $d7_labels[] = $dt->format( 'd M' );
            $d7_views[ $d ]    = 0;
            $d7_actions[ $d ]  = 0;
        }
        foreach ( (array) $daily_traffic as $r ) {
            if ( isset( $d7_views[ $r->dt ] ) ) {
                $d7_views[ $r->dt ]   = (int) $r->views;
                $d7_actions[ $r->dt ] = (int) $r->actions;
            }
        }
        $d7_v_arr = array_values( $d7_views );
        $d7_a_arr = array_values( $d7_actions );

        if ( 'overview' === $uct_tab ) {
            echo '<div style="background:#fff; border:1px solid #ccd0d4; padding:20px; border-radius:4px; margin-bottom:25px;">';
            echo '<h3 class="ois-block-title">📊 ' . esc_html__( 'UTM-tagged events by hour', 'ois-conversion-suite' ) . ' <span style="color:#1a73e8; font-weight:normal;">(' . esc_html( sprintf( /* translators: %s: event count */ __( 'Total: %s events', 'ois-conversion-suite' ), number_format( $total_overall_clicks ) ) ) . ')</span></h3>';
            echo '<div style="height:220px; position:relative;"><canvas id="oisclUtmCtHourlyChart"></canvas></div>';
            echo '<details style="margin-top:14px;" open><summary style="cursor:pointer;font-weight:600;font-size:13px;color:#1d2327;">' . esc_html__( 'Hour breakdown (server-rendered — shows even if the chart fails to load)', 'ois-conversion-suite' ) . '</summary>';
            echo '<table class="widefat striped" style="margin-top:10px;max-width:520px;font-size:12px;"><thead><tr><th>' . esc_html__( 'Hour', 'ois-conversion-suite' ) . '</th><th>' . esc_html__( 'Events', 'ois-conversion-suite' ) . '</th></tr></thead><tbody>';
            $any_hour = false;
            for ( $h = 0; $h < 24; $h++ ) {
                $cnt = isset( $hours_values[ $h ] ) ? (int) $hours_values[ $h ] : 0;
                if ( $cnt <= 0 ) {
                    continue;
                }
                $any_hour = true;
                echo '<tr><td>' . esc_html( sprintf( '%02d:00', $h ) ) . '</td><td>' . esc_html( number_format_i18n( $cnt ) ) . '</td></tr>';
            }
            if ( ! $any_hour ) {
                echo '<tr><td colspan="2">' . esc_html__( 'No hourly aggregates for this range (query may have failed — enable WP_DEBUG and check the PHP error log).', 'ois-conversion-suite' ) . '</td></tr>';
            }
            echo '</tbody></table></details></div>';

            echo '<div style="background:#fff; border:1px solid #ccd0d4; padding:20px; border-radius:4px; margin-bottom:25px;">';
            echo '<h3 class="ois-block-title">📅 ' . esc_html__( 'Last 7 days (UTM pageviews vs other tagged events)', 'ois-conversion-suite' ) . '</h3>';
            echo '<div style="height:250px; position:relative;"><canvas id="oisclUtmCt7DaysChart"></canvas></div>';
            echo '<details style="margin-top:14px;" open><summary style="cursor:pointer;font-weight:600;font-size:13px;color:#1d2327;">' . esc_html__( '7-day totals (server-rendered)', 'ois-conversion-suite' ) . '</summary>';
            echo '<table class="widefat striped" style="margin-top:10px;max-width:640px;font-size:12px;"><thead><tr><th>' . esc_html__( 'Date', 'ois-conversion-suite' ) . '</th><th>' . esc_html__( 'Pageviews', 'ois-conversion-suite' ) . '</th><th>' . esc_html__( 'Other UTM events', 'ois-conversion-suite' ) . '</th></tr></thead><tbody>';
            foreach ( $period_7d as $dt ) {
                $d = $dt->format( 'Y-m-d' );
                $v = isset( $d7_views[ $d ] ) ? (int) $d7_views[ $d ] : 0;
                $a = isset( $d7_actions[ $d ] ) ? (int) $d7_actions[ $d ] : 0;
                if ( $v > 0 || $a > 0 ) {
                    echo '<tr><td>' . esc_html( $d ) . '</td><td>' . esc_html( number_format_i18n( $v ) ) . '</td><td>' . esc_html( number_format_i18n( $a ) ) . '</td></tr>';
                }
            }
            echo '</tbody></table></details></div>';
        }

        $filter_toolbar = '<div class="filter-dropdown-container"><button type="button" id="btn-utm-ct-filter-main" class="button">📂 <span id="utm-ct-filter-text">' . esc_html__( 'Filter: All', 'ois-conversion-suite' ) . '</span> ▾</button><div class="filter-menu" id="ois-utm-ct-filter-menu"><label class="filter-item" style="border-bottom:1px solid #eee; margin-bottom:5px; font-weight:bold;"><input type="checkbox" id="ois-utm-ct-master-filter" checked> ' . esc_html__( 'Toggle All', 'ois-conversion-suite' ) . '</label><label class="filter-item"><input type="checkbox" class="oiscl-utm-ct-filter-trigger" data-cat="contact" checked> 📞 ' . esc_html__( 'Leads & Contact', 'ois-conversion-suite' ) . '</label><label class="filter-item"><input type="checkbox" class="oiscl-utm-ct-filter-trigger" data-cat="forms" checked> 📩 ' . esc_html__( 'Form Clicks', 'ois-conversion-suite' ) . '</label><label class="filter-item"><input type="checkbox" class="oiscl-utm-ct-filter-trigger" data-cat="pages" checked> 📄 ' . esc_html__( 'Internal Navigation', 'ois-conversion-suite' ) . '</label><label class="filter-item"><input type="checkbox" class="oiscl-utm-ct-filter-trigger" data-cat="media" checked> 🖼️ ' . esc_html__( 'Media & Downloads', 'ois-conversion-suite' ) . '</label><label class="filter-item"><input type="checkbox" class="oiscl-utm-ct-filter-trigger" data-cat="external" checked> 🔗 ' . esc_html__( 'External Links', 'ois-conversion-suite' ) . '</label><label class="filter-item"><input type="checkbox" class="oiscl-utm-ct-filter-trigger" data-cat="interface" checked> ⚙️ ' . esc_html__( 'Technical Noise', 'ois-conversion-suite' ) . '</label></div></div>';

        $click_rows_data = array();
        if ( ! empty( $clicks_data ) ) {
            $site_url = get_site_url();
            foreach ( $clicks_data as $row ) {
                $dest   = strtolower( (string) $row->destination_url );
                $anchor = strtolower( (string) $row->anchor_text );
                $is_noise = ( empty( $anchor ) || 'botón' === $anchor || strpos( $anchor, 'next' ) !== false || strpos( $anchor, 'prev' ) !== false || strpos( $anchor, 'gallery' ) !== false || strpos( $dest, 'gad_source' ) !== false || strpos( $dest, 'gclid' ) !== false || strpos( $dest, 'google' ) !== false || strpos( $dest, 'doubleclick' ) !== false );
                if ( $is_noise ) {
                    $cat = 'interface';
                    $label = 'Noise';
                } elseif ( strpos( $dest, 'tel:' ) !== false || strpos( $dest, 'wa.me' ) !== false || strpos( $dest, 'mailto:' ) !== false ) {
                    $cat = 'contact';
                    $label = 'Lead';
                } elseif ( preg_match( '/\.(pdf|jpg|jpeg|png|gif|mp4|webm|svg)$/i', $dest ) ) {
                    $cat = 'media';
                    $label = 'Media';
                } elseif ( strpos( $anchor, 'submit' ) !== false || strpos( $anchor, 'send' ) !== false || strpos( $dest, 'form' ) !== false ) {
                    $cat = 'forms';
                    $label = 'Form';
                } elseif ( strpos( $dest, $site_url ) !== false ) {
                    $cat = 'pages';
                    $label = 'Page';
                } else {
                    $cat = 'external';
                    $label = 'Link';
                }

                $full_url     = esc_html( $row->destination_url );
                $display_dest = ( strlen( $full_url ) > 40 )
                    ? "<details style='cursor:pointer;'><summary style='color:#722ed1; font-size:11px; outline:none; font-family:monospace;' title='" . esc_attr__( 'Click to expand', 'ois-conversion-suite' ) . "'>" . substr( $full_url, 0, 40 ) . "...</summary><div style='margin-top:5px; padding:6px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:4px; word-break:break-all; font-size:10px; color:#334155; font-family:monospace;'>{$full_url}</div></details>"
                    : "<code style='color:#722ed1; font-size:11px;'>{$full_url}</code>";

                $click_rows_data[] = array(
                    'category' => $cat,
                    'cols'     => array(
                        esc_html( basename( $row->origin_url ) ),
                        '<code style="font-size:10px;color:#0369a1;">' . esc_html( $row->utm_campaign ) . '</code>',
                        "<span class='badge-cat cat-{$cat}'>{$label}</span> " . esc_html( $row->anchor_text ?: '[Technical Hit]' ),
                        $display_dest,
                        "<strong style='color:#1a73e8; font-size:14px;'>" . intval( $row->total_clicks ) . '</strong>',
                        '<span style="color:#666;">' . ( $row->avg_time > 0 ? round( $row->avg_time, 1 ) . 's' : '—' ) . '</span>',
                        esc_html( $row->context_text ),
                    ),
                );
            }
        }

        if ( 'clicks' === $uct_tab ) {
            $this->render_ois_component(
                'advanced_table',
                array(
                    'id'       => 'utm-ct-table-clicks',
                    'title'    => __( 'UTM-tagged events (detail)', 'ois-conversion-suite' ),
                    'subtitle' => __( 'All rows with utm_campaign except 404: pageviews, block/reading signals, and link clicks. OIS Click Tracker “Clicks” tab still uses stricter conversion rules.', 'ois-conversion-suite' ),
                    'icon'     => '🖱️',
                    'toolbar'  => $filter_toolbar,
                    'pdf'      => 'UTM_Conversion_Clicks',
                    'headers'  => array(
                        array( 'label' => __( 'Source', 'ois-conversion-suite' ), 'width' => '12%', 'type' => 'string' ),
                        array( 'label' => __( 'Campaign', 'ois-conversion-suite' ), 'width' => '12%', 'type' => 'string' ),
                        array( 'label' => __( 'Anchor', 'ois-conversion-suite' ), 'width' => '22%', 'type' => 'string' ),
                        array( 'label' => __( 'Destination URL', 'ois-conversion-suite' ), 'width' => '26%', 'type' => 'string' ),
                        array( 'label' => __( 'Events', 'ois-conversion-suite' ), 'width' => '8%', 'type' => 'numeric', 'align' => 'right' ),
                        array( 'label' => __( 'Time (s)', 'ois-conversion-suite' ), 'width' => '8%', 'type' => 'numeric', 'align' => 'center' ),
                        array( 'label' => __( 'Context', 'ois-conversion-suite' ), 'width' => '12%', 'type' => 'string', 'align' => 'center' ),
                    ),
                    'rows'     => $click_rows_data,
                )
            );
        }

        $reading_sql = $prep(
            "SELECT origin_url, context_text, utm_campaign, SUM(clicks) as total_views, AVG(time_spent) as avg_read_time FROM `{$table_stats}` WHERE anchor_text IN ({$sql_block}) AND DATE(created_at) >= %s AND DATE(created_at) <= %s GROUP BY origin_url, context_text, utm_campaign ORDER BY total_views DESC",
            array( $start_date, $end_date )
        );
        $reading_data = ( false !== $reading_sql ) ? $wpdb->get_results( $reading_sql ) : array();
        $reading_rows_data = array();
        if ( $reading_data ) {
            foreach ( $reading_data as $row ) {
                $time_fmt = ( $row->avg_read_time >= 60 ) ? round( $row->avg_read_time / 60, 1 ) . 'm' : round( $row->avg_read_time ) . 's';
                $reading_rows_data[] = array(
                    'cols' => array(
                        esc_html( basename( $row->origin_url ) ),
                        '<code style="font-size:10px;color:#0369a1;">' . esc_html( $row->utm_campaign ) . '</code>',
                        '<code style="color:#666;">' . esc_html( $row->context_text ) . '</code>',
                        '<b>' . esc_html( (string) $row->total_views ) . '</b>',
                        '<strong style="color:#722ed1;">' . esc_html( $time_fmt ) . '</strong>',
                    ),
                );
            }
        }

        if ( 'reading' === $uct_tab ) {
            $this->render_ois_component(
                'advanced_table',
                array(
                    'id'      => 'utm-ct-table-reading',
                    'title'   => __( 'UTM reading map: dwell by block', 'ois-conversion-suite' ),
                    'icon'    => '⏱️',
                    'pdf'     => 'UTM_Reading_Map',
                    'headers' => array(
                        array( 'label' => __( 'Page path', 'ois-conversion-suite' ), 'width' => '22%', 'type' => 'string' ),
                        array( 'label' => __( 'Campaign', 'ois-conversion-suite' ), 'width' => '14%', 'type' => 'string' ),
                        array( 'label' => __( 'Block / section', 'ois-conversion-suite' ), 'width' => '34%', 'type' => 'string' ),
                        array( 'label' => __( 'Views', 'ois-conversion-suite' ), 'width' => '15%', 'type' => 'numeric', 'align' => 'center' ),
                        array( 'label' => __( 'Avg dwell', 'ois-conversion-suite' ), 'width' => '15%', 'type' => 'numeric', 'align' => 'center' ),
                    ),
                    'rows'    => $reading_rows_data,
                )
            );
        }

        $this->oiscl_render_utm_block_metrics_diag( $table_stats, $start_date, $end_date, $filter_sql_stats );

        $utm_ct_nav_js = array(
            'base'       => admin_url( 'admin.php' ),
            'uct_tab'    => $uct_tab,
            'start_date' => $start_date,
            'end_date'   => $end_date,
            'utm_filter' => ( 'all' !== $selected_filter ) ? $selected_filter : '',
        );
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var utmCtNav = <?php echo wp_json_encode( $utm_ct_nav_js ); ?>;

            /**
             * Build admin URL for UTM Click Tracker. Avoids parsing esc_url()/relative admin URLs with URL(),
             * which can drop query args (e.g. tab) on some hosts.
             */
            function oisclBuildUtmCtAdminUrl(extra) {
                extra = extra || {};
                var url = new URL(utmCtNav.base, window.location.href);
                url.searchParams.set('page', 'oiscl-utm-tracker');
                url.searchParams.set('tab', 'click_tracker');
                url.searchParams.set('uct_tab', utmCtNav.uct_tab);
                url.searchParams.set('start_date', utmCtNav.start_date);
                url.searchParams.set('end_date', utmCtNav.end_date);
                if (utmCtNav.utm_filter) {
                    url.searchParams.set('utm_filter', utmCtNav.utm_filter);
                } else {
                    url.searchParams.delete('utm_filter');
                }
                if (extra.utm_filter !== undefined) {
                    if (extra.utm_filter && extra.utm_filter !== 'all') {
                        url.searchParams.set('utm_filter', extra.utm_filter);
                    } else {
                        url.searchParams.delete('utm_filter');
                    }
                }
                if (Object.prototype.hasOwnProperty.call(extra, 'tp_page')) {
                    var pid = extra.tp_page;
                    if (pid) {
                        url.searchParams.set('tp_page', String(pid));
                        var rev = extra.tp_revision || '';
                        if (rev) {
                            url.searchParams.set('tp_revision', String(rev));
                        } else {
                            url.searchParams.delete('tp_revision');
                        }
                    } else {
                        url.searchParams.delete('tp_page');
                        url.searchParams.delete('tp_revision');
                    }
                }
                return url.toString();
            }

            var filterSel = document.getElementById('oiscl-utm-ct-filter');
            if (filterSel) {
                filterSel.addEventListener('change', function() {
                    var cur = new URL(window.location.href);
                    var tpp = cur.searchParams.get('tp_page');
                    var tpr = cur.searchParams.get('tp_revision');
                    var preset = cur.searchParams.get('preset');
                    var href = oisclBuildUtmCtAdminUrl({
                        utm_filter: this.value,
                        tp_page: tpp || '',
                        tp_revision: (tpp && tpr) ? tpr : ''
                    });
                    if (preset) {
                        var u = new URL(href, window.location.href);
                        u.searchParams.set('preset', preset);
                        href = u.toString();
                    }
                    window.location.href = href;
                });
            }
            var pageSel = document.getElementById('oiscl-utm-ct-page');
            var revSel = document.getElementById('oiscl-utm-ct-revision');
            if (pageSel) {
                function navigateUtmCtScope() {
                    var pid = pageSel.value || '';
                    var rev = (revSel && revSel.value && pid) ? revSel.value : '';
                    var href = oisclBuildUtmCtAdminUrl({
                        tp_page: pid,
                        tp_revision: rev
                    });
                    var cur = new URL(window.location.href);
                    var preset = cur.searchParams.get('preset');
                    if (preset) {
                        var u = new URL(href, window.location.href);
                        u.searchParams.set('preset', preset);
                        href = u.toString();
                    }
                    window.location.href = href;
                }
                pageSel.addEventListener('change', function() {
                    if (revSel) { revSel.disabled = !pageSel.value; if (!pageSel.value) revSel.value = ''; }
                    navigateUtmCtScope();
                });
                if (revSel) revSel.addEventListener('change', navigateUtmCtScope);
            }
            <?php if ( 'overview' === $uct_tab ) : ?>
            try {
                if (typeof Chart !== 'undefined') {
                    var hourlyEl = document.getElementById('oisclUtmCtHourlyChart');
                    if (hourlyEl) {
                        new Chart(hourlyEl.getContext('2d'), {
                            type: 'bar',
                            data: { labels: <?php echo wp_json_encode( array_map( static function ( $i ) { return str_pad( (string) $i, 2, '0', STR_PAD_LEFT ) . ':00'; }, range( 0, 23 ) ) ); ?>, datasets: [{ data: <?php echo wp_json_encode( array_values( $hours_values ) ); ?>, backgroundColor: 'rgba(26, 115, 232, 0.7)', borderRadius: 3 }] },
                            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
                        });
                    }
                    var mixEl = document.getElementById('oisclUtmCt7DaysChart');
                    if (mixEl) {
                        new Chart(mixEl.getContext('2d'), {
                            type: 'bar',
                            data: {
                                labels: <?php echo wp_json_encode( $d7_labels ); ?>,
                                datasets: [
                                    { type: 'line', label: <?php echo wp_json_encode( __( 'Non-pageview events', 'ois-conversion-suite' ) ); ?>, data: <?php echo wp_json_encode( $d7_a_arr ); ?>, borderColor: '#f56e28', tension: 0.4, pointRadius: 3, fill: false },
                                    { type: 'bar', label: <?php echo wp_json_encode( __( 'Views', 'ois-conversion-suite' ) ); ?>, data: <?php echo wp_json_encode( $d7_v_arr ); ?>, backgroundColor: 'rgba(26, 115, 232, 0.8)', borderRadius: 4 }
                                ]
                            },
                            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } }, scales: { y: { beginAtZero: true } } }
                        });
                    }
                }
            } catch (e) { console.warn('OISCL UTM Click Tracker charts:', e); }
            <?php endif; ?>
            <?php if ( 'clicks' === $uct_tab ) : ?>
            var btnFilter = document.getElementById('btn-utm-ct-filter-main');
            var filterMenu = document.getElementById('ois-utm-ct-filter-menu');
            var masterFilter = document.getElementById('ois-utm-ct-master-filter');
            var triggers = document.querySelectorAll('.oiscl-utm-ct-filter-trigger');
            if (btnFilter && filterMenu) {
                btnFilter.addEventListener('click', function(e) { e.stopPropagation(); filterMenu.classList.toggle('active'); });
                document.addEventListener('click', function(e) { if (!filterMenu.contains(e.target) && e.target !== btnFilter) filterMenu.classList.remove('active'); });
                function applyUtmCtFilters() {
                    var activeCount = 0;
                    triggers.forEach(function(t) { if (t.checked) activeCount++; });
                    var ft = document.getElementById('utm-ct-filter-text');
                    if (ft) ft.innerText = (activeCount === triggers.length) ? <?php echo wp_json_encode( __( 'Filter: All', 'ois-conversion-suite' ) ); ?> : <?php echo wp_json_encode( __( 'Filter: Custom', 'ois-conversion-suite' ) ); ?> + ' (' + activeCount + ')';
                    document.querySelectorAll('#utm-ct-table-clicks tbody tr.ois-row').forEach(function(row) {
                        var cat = row.dataset.category;
                        if (!cat) return;
                        var trigger = document.querySelector('.oiscl-utm-ct-filter-trigger[data-cat="' + cat + '"]');
                        if (trigger && trigger.checked) row.classList.remove('ois-filtered-out');
                        else row.classList.add('ois-filtered-out');
                    });
                    var $t = jQuery('#utm-ct-table-clicks');
                    if ($t.data('setPage')) { $t.data('setPage')(1); $t.data('drawFn')(); }
                }
                if (masterFilter) masterFilter.addEventListener('change', function() { triggers.forEach(function(t) { t.checked = masterFilter.checked; }); applyUtmCtFilters(); });
                triggers.forEach(function(cb) { cb.addEventListener('change', applyUtmCtFilters); });
            }
            <?php endif; ?>
        });
        </script>
        <?php
    }

    private function oiscl_utm_hit_matches_conv( $anchor, $context, $dest, $conv_label ) {
        $conv_label = (string) $conv_label;
        if ( '' === $conv_label ) {
            return false;
        }
        return ( false !== stripos( (string) $anchor, $conv_label ) )
            || ( false !== stripos( (string) $context, $conv_label ) )
            || ( false !== stripos( (string) $dest, $conv_label ) );
    }

    /**
     * Map saved-reference utm_campaign slugs to exact values stored in block_metrics (case/spacing differences).
     *
     * @param array<int,string> $campaigns        Wanted slugs from oiscl_utm_references.
     * @param string            $table_stats      Metrics table name.
     * @param string            $start_date       Y-m-d.
     * @param string            $end_date         Y-m-d.
     * @param string            $filter_sql_stats Dashboard filter fragment.
     * @return array<int,string>
     */
    private function oiscl_utm_resolve_funnel_campaigns_from_metrics( array $campaigns, $table_stats, $start_date, $end_date, $filter_sql_stats ) {
        global $wpdb;

        $campaigns = array_values(
            array_unique(
                array_filter(
                    array_map(
                        static function ( $c ) {
                            $t = trim( (string) $c );
                            return '' !== $t ? $t : null;
                        },
                        $campaigns
                    )
                )
            )
        );
        if ( empty( $campaigns ) ) {
            return array();
        }

        $want = array();
        foreach ( $campaigns as $c ) {
            $want[ strtolower( $c ) ] = true;
        }

        $filter_safe = $this->oiscl_utm_sql_fragment_for_prepare( $filter_sql_stats );
        $sql         = "SELECT DISTINCT utm_campaign FROM `{$table_stats}` WHERE utm_campaign != '' {$filter_safe} AND DATE(created_at) >= %s AND DATE(created_at) <= %s";
        $prepared    = $wpdb->prepare( $sql, $start_date, $end_date );
        if ( false === $prepared ) {
            return $campaigns;
        }

        $resolved = array();
        foreach ( (array) $wpdb->get_col( $prepared ) as $db_c ) {
            $k = strtolower( trim( (string) $db_c ) );
            if ( isset( $want[ $k ] ) ) {
                $resolved[] = (string) $db_c;
            }
        }
        $resolved = array_values( array_unique( $resolved ) );

        return ! empty( $resolved ) ? $resolved : $campaigns;
    }

    /**
     * Session rows for funnel analysis (pageview time, first block time, hit trail).
     *
     * @param array<int,string> $campaigns        utm_campaign slugs.
     * @param string            $term_sql         Optional term fragment from oiscl_utm_link_term_sql().
     * @param string            $table_stats      Metrics table.
     * @param string            $start_date       Y-m-d.
     * @param string            $end_date         Y-m-d.
     * @param string            $filter_sql_stats Dashboard filter SQL.
     * @return array<int,object>
     */
    private function oiscl_utm_fetch_funnel_session_rows( array $campaigns, $term_sql, $table_stats, $start_date, $end_date, $filter_sql_stats ) {
        global $wpdb;

        $campaigns = array_values(
            array_filter(
                array_map(
                    static function ( $c ) {
                        $t = trim( (string) $c );
                        return '' !== $t ? $t : null;
                    },
                    $campaigns
                )
            )
        );
        if ( empty( $campaigns ) ) {
            return array();
        }

        $campaigns = $this->oiscl_utm_resolve_funnel_campaigns_from_metrics( $campaigns, $table_stats, $start_date, $end_date, $filter_sql_stats );

        $pv       = OISCL_Plan::EVENT_PAGEVIEW;
        $block_in = OISCL_Plan::sql_block_view_anchor_in();
        $in_ph         = implode( ',', array_fill( 0, count( $campaigns ), '%s' ) );
        $term_safe     = $this->oiscl_utm_sql_fragment_for_prepare( $term_sql );
        $filter_safe   = $this->oiscl_utm_sql_fragment_for_prepare( $filter_sql_stats );
        $sql           = "SELECT session_id,
            MIN(CASE WHEN anchor_text = %s THEN created_at END) AS pv_at,
            MIN(CASE WHEN anchor_text IN ({$block_in}) THEN created_at END) AS block_at,
            GROUP_CONCAT(CONCAT(created_at, '|', anchor_text, '|', IFNULL(context_text, ''), '|', IFNULL(destination_url, '')) ORDER BY created_at ASC SEPARATOR '||') AS trail
            FROM `{$table_stats}`
            WHERE utm_campaign IN ({$in_ph}) {$term_safe} {$filter_safe}
            AND DATE(created_at) >= %s AND DATE(created_at) <= %s
            GROUP BY session_id
            HAVING pv_at IS NOT NULL";

        $prepare_args = array_merge( array( $pv ), $campaigns, array( $start_date, $end_date ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $prepared = $wpdb->prepare( $sql, $prepare_args );
        if ( false === $prepared ) {
            return array();
        }
        return (array) $wpdb->get_results( $prepared );
    }

    /**
     * Same session rollup as oiscl_utm_fetch_funnel_session_rows(), but any non-empty utm_campaign (not only saved links).
     * Used for the global funnel card so ad-hoc / test URLs appear without registering the slug in Settings.
     *
     * @param string $table_stats      Metrics table.
     * @param string $start_date       Y-m-d.
     * @param string $end_date         Y-m-d.
     * @param string $filter_sql_stats Dashboard filter SQL.
     * @return array<int,object>
     */
    private function oiscl_utm_fetch_funnel_session_rows_any_utm( $table_stats, $start_date, $end_date, $filter_sql_stats ) {
        global $wpdb;

        $pv          = OISCL_Plan::EVENT_PAGEVIEW;
        $block_in    = OISCL_Plan::sql_block_view_anchor_in();
        $filter_safe = $this->oiscl_utm_sql_fragment_for_prepare( $filter_sql_stats );
        $sql         = "SELECT session_id,
            MIN(CASE WHEN anchor_text = %s THEN created_at END) AS pv_at,
            MIN(CASE WHEN anchor_text IN ({$block_in}) THEN created_at END) AS block_at,
            GROUP_CONCAT(CONCAT(created_at, '|', anchor_text, '|', IFNULL(context_text, ''), '|', IFNULL(destination_url, '')) ORDER BY created_at ASC SEPARATOR '||') AS trail
            FROM `{$table_stats}`
            WHERE utm_campaign != '' {$filter_safe}
            AND DATE(created_at) >= %s AND DATE(created_at) <= %s
            GROUP BY session_id
            HAVING pv_at IS NOT NULL";

        $prepare_args = array( $pv, $start_date, $end_date );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (array) $wpdb->get_results( $wpdb->prepare( $sql, $prepare_args ) );
    }

    /**
     * Sequential funnel: pageview → block view (after PV) → conversion click (after block).
     *
     * @param array<int,object> $session_rows Rows from oiscl_utm_fetch_funnel_session_rows().
     * @param string            $conv_label   Conversion anchor text; empty = any real click after block.
     * @return array{step1:int,step2:int,step3:int}
     */
    private function oiscl_utm_analyze_funnel_sessions( $session_rows, $conv_label = '' ) {
        $system_clicks = array(
            OISCL_Plan::EVENT_PAGEVIEW,
            OISCL_Plan::EVENT_BLOCK_VIEW,
            OISCL_Plan::EVENT_BLOCK_LEGACY,
            'Reading',
            OISCL_Plan::EVENT_ERROR_404,
        );
        $s1 = $s2 = $s3 = 0;

        foreach ( (array) $session_rows as $row ) {
            $s1++;
            if ( empty( $row->block_at ) || strtotime( $row->block_at ) < strtotime( $row->pv_at ) ) {
                continue;
            }
            $s2++;
            $block_ts  = strtotime( $row->block_at );
            $converted = false;
            foreach ( explode( '||', (string) $row->trail ) as $hit ) {
                if ( '' === $hit ) {
                    continue;
                }
                $parts = explode( '|', $hit, 4 );
                $ts    = isset( $parts[0] ) ? strtotime( $parts[0] ) : false;
                if ( ! $ts || $ts < $block_ts ) {
                    continue;
                }
                $anchor  = isset( $parts[1] ) ? $parts[1] : '';
                $context = isset( $parts[2] ) ? $parts[2] : '';
                $dest    = isset( $parts[3] ) ? $parts[3] : '';
                if ( in_array( $anchor, $system_clicks, true ) ) {
                    continue;
                }
                if ( '' !== $conv_label ) {
                    if ( $this->oiscl_utm_hit_matches_conv( $anchor, $context, $dest, $conv_label ) ) {
                        $converted = true;
                        break;
                    }
                } else {
                    $converted = true;
                    break;
                }
            }
            if ( $converted ) {
                $s3++;
            }
        }

        return array(
            'step1' => $s1,
            'step2' => $s2,
            'step3' => $s3,
        );
    }

    /**
     * Funnel stats for one saved link reference.
     *
     * @param object $link Reference row.
     * @return array{step1:int,step2:int,step3:int,conv_label:string}
     */
    private function oiscl_utm_analyze_link_funnel( $link, $table_stats, $start_date, $end_date, $filter_sql_stats ) {
        $conv_label = trim( $this->oiscl_utm_ref_field( $link, 'conv_anchor' ) );
        $sessions   = $this->oiscl_utm_fetch_funnel_session_rows(
            array( (string) $link->utm_campaign ),
            $this->oiscl_utm_link_term_sql( $link ),
            $table_stats,
            $start_date,
            $end_date,
            $filter_sql_stats
        );
        $stats = $this->oiscl_utm_analyze_funnel_sessions( $sessions, $conv_label );
        $stats['conv_label'] = $conv_label;
        return $stats;
    }

    /**
     * @param string              $label_name Company label.
     * @param array<int,object>   $links      Reference rows for that label.
     * @return array{step1:int,step2:int,step3:int}
     */
    private function oiscl_utm_analyze_company_funnel( $label_name, $links, $table_stats, $start_date, $end_date, $filter_sql_stats ) {
        $campaigns = array();
        foreach ( (array) $links as $link ) {
            $campaigns[] = (string) $link->utm_campaign;
        }
        $campaigns = array_values( array_unique( $campaigns ) );
        $sessions  = $this->oiscl_utm_fetch_funnel_session_rows(
            $campaigns,
            '',
            $table_stats,
            $start_date,
            $end_date,
            $filter_sql_stats
        );
        return $this->oiscl_utm_analyze_funnel_sessions( $sessions, '' );
    }

    /**
     * @param int $num Numerator.
     * @param int $den Denominator.
     * @return string
     */
    private function oiscl_utm_funnel_pct( $num, $den ) {
        if ( $den <= 0 ) {
            return '—';
        }
        return round( ( $num / $den ) * 100, 1 ) . '%';
    }

    /**
     * Compact funnel bar for a step count vs step1.
     */
    private function oiscl_utm_funnel_step_bar( $count, $base, $color ) {
        $pct = ( $base > 0 ) ? min( 100, round( ( $count / $base ) * 100 ) ) : 0;
        return '<div style="background:#f1f5f9;height:8px;border-radius:4px;overflow:hidden;min-width:80px;">'
            . '<div style="width:' . (int) $pct . '%;background:' . esc_attr( $color ) . ';height:100%;border-radius:4px;"></div>'
            . '</div>';
    }

    /**
     * Inline guidance for the UTM Funnel tab (definition of steps + settings link).
     *
     * @param string $settings_url UTM Manager settings URL.
     */
    private function oiscl_render_utm_funnel_guidance_panel( $settings_url ) {
        echo '<div class="notice notice-info inline" style="margin:0 0 18px;padding:12px 14px;max-width:960px;">';
        echo '<p style="margin:0 0 10px;font-weight:600;">' . esc_html__( 'How this funnel is counted', 'ois-conversion-suite' ) . '</p>';
        echo '<ul style="margin:0 0 10px 1.15em;padding:0;font-size:13px;line-height:1.55;color:#1d2327;">';
        echo '<li><strong>' . esc_html__( 'Step 1 — Landing:', 'ois-conversion-suite' ) . '</strong> ';
        echo esc_html__( 'Sessions with at least one pageview row where utm_campaign is stored (non-empty) inside the selected date range and dashboard filters.', 'ois-conversion-suite' ) . '</li>';
        echo '<li><strong>' . esc_html__( 'Step 2 — Block view:', 'ois-conversion-suite' ) . '</strong> ';
        echo esc_html__( 'The same session must record a tracked block/section view after that pageview (Click Tracker block anchors — same signals as block dwell maps).', 'ois-conversion-suite' ) . '</li>';
        echo '<li><strong>' . esc_html__( 'Step 3 — Conversion click:', 'ois-conversion-suite' ) . '</strong> ';
        echo esc_html__( 'After the block view: a real interaction click (not pageview/block/reading). If you set a Conversion click label on the saved link, Step 3 only counts hits matching that text on anchor, context, or destination.', 'ois-conversion-suite' ) . '</li>';
        echo '</ul>';
        echo '<p style="margin:0;font-size:12px;color:#50575e;">';
        echo esc_html__( 'Saved links and conversion labels:', 'ois-conversion-suite' ) . ' ';
        echo '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings → UTM Manager', 'ois-conversion-suite' ) . '</a>. ';
        echo esc_html__( 'The global funnel uses any stored utm_campaign; company and campaign tables only include slugs from saved references (normalized to match live metrics).', 'ois-conversion-suite' );
        echo '</p></div>';
    }

    /**
     * Three-step funnel summary card.
     *
     * @param array{step1:int,step2:int,step3:int} $stats   Counts.
     * @param string                               $title  Heading.
     * @param string                               $conv_note Optional conversion note.
     */
    private function oiscl_utm_render_funnel_steps_visual( $stats, $title, $conv_note = '' ) {
        $s1 = (int) $stats['step1'];
        $s2 = (int) $stats['step2'];
        $s3 = (int) $stats['step3'];
        echo '<div class="ois-box" style="background:#fff;border:1px solid #ccd0d4;padding:20px;border-radius:4px;margin-bottom:20px;">';
        echo '<h3 class="ois-block-title" style="margin:0 0 8px;">🔽 ' . esc_html( $title ) . '</h3>';
        if ( $conv_note ) {
            echo '<p style="margin:0 0 16px;font-size:12px;color:#64748b;">' . esc_html( $conv_note ) . '</p>';
        }
        $steps = array(
            array(
                'label' => __( '1. Landing (pageview)', 'ois-conversion-suite' ),
                'count' => $s1,
                'color' => '#1a73e8',
                'hint'  => __( 'Sessions with a recorded pageview.', 'ois-conversion-suite' ),
            ),
            array(
                'label' => __( '2. Key block view', 'ois-conversion-suite' ),
                'count' => $s2,
                'color' => '#722ed1',
                'hint'  => __( 'Saw a tracked block after the pageview.', 'ois-conversion-suite' ),
            ),
            array(
                'label' => __( '3. Conversion click', 'ois-conversion-suite' ),
                'count' => $s3,
                'color' => '#166534',
                'hint'  => __( 'Clicked the conversion target after the block.', 'ois-conversion-suite' ),
            ),
        );
        echo '<div style="display:grid;gap:14px;">';
        foreach ( $steps as $step ) {
            echo '<div style="display:grid;grid-template-columns:minmax(140px,1.2fr) minmax(70px,auto) 1fr auto;gap:12px;align-items:center;">';
            echo '<span style="font-size:12px;color:#334155;" title="' . esc_attr( $step['hint'] ) . '">' . esc_html( $step['label'] ) . '</span>';
            echo '<strong style="font-size:14px;text-align:right;">' . esc_html( number_format_i18n( $step['count'] ) ) . '</strong>';
            echo $this->oiscl_utm_funnel_step_bar( $step['count'], max( 1, $s1 ), $step['color'] );
            if ( $step['count'] === $s1 ) {
                echo '<span style="font-size:11px;color:#94a3b8;">100%</span>';
            } elseif ( $s1 > 0 && $step['count'] === $s2 ) {
                echo '<span style="font-size:11px;color:#64748b;">' . esc_html( $this->oiscl_utm_funnel_pct( $s2, $s1 ) ) . '</span>';
            } else {
                echo '<span style="font-size:11px;color:#64748b;">' . esc_html( $this->oiscl_utm_funnel_pct( $s3, $s1 ) ) . '</span>';
            }
            echo '</div>';
        }
        echo '</div>';
        echo '<p style="margin:14px 0 0;font-size:11px;color:#64748b;border-top:1px solid #f1f5f9;padding-top:12px;">';
        echo esc_html__( 'Step 2→3:', 'ois-conversion-suite' ) . ' <strong>' . esc_html( $this->oiscl_utm_funnel_pct( $s3, $s2 ) ) . '</strong>';
        echo ' · ' . esc_html__( 'Overall (1→3):', 'ois-conversion-suite' ) . ' <strong>' . esc_html( $this->oiscl_utm_funnel_pct( $s3, $s1 ) ) . '</strong>';
        echo '</p></div>';
    }

    /**
     * UTM Funnel tab: company rollup + per-campaign 3-step funnel.
     *
     * @param array<string,mixed> $args Context.
     */
    private function oiscl_render_utm_funnel_tab( $args ) {
        global $wpdb;

        $table_refs       = $args['table_refs'];
        $table_stats      = $args['table_stats'];
        $start_date       = $args['start_date'];
        $end_date         = $args['end_date'];
        $filter_sql_refs  = $args['filter_sql_refs'];
        $filter_sql_stats = $args['filter_sql_stats'];
        $selected_filter  = isset( $args['selected_filter'] ) ? $args['selected_filter'] : 'all';

        $this->oiscl_render_utm_tab_kpi_row(
            $args['live_views'],
            $args['utm_hits'],
            $args['prev_utm_hits'],
            $args['utm_users'],
            $args['prev_utm_users'],
            $args['utm_actions'],
            $args['prev_utm_actions'],
            $args['utm_ctr'],
            $args['prev_utm_ctr']
        );

        $settings_url = admin_url( 'admin.php?page=oiscl-settings&tab=utmtracker' );
        $this->oiscl_render_utm_funnel_guidance_panel( $settings_url );

        $funnel_csv_base = array(
            'page'       => 'oiscl-utm-tracker',
            'tab'        => 'funnel',
            'export_csv' => 'utm_funnel',
            'start_date' => $start_date,
            'end_date'   => $end_date,
        );
        if ( 'all' !== $selected_filter ) {
            $funnel_csv_base['utm_filter'] = $selected_filter;
        }
        $funnel_csv_company = esc_url( admin_url( 'admin.php?' . http_build_query( array_merge( $funnel_csv_base, array( 'funnel_scope' => 'company' ) ) ) ) );
        $funnel_csv_camp    = esc_url( admin_url( 'admin.php?' . http_build_query( array_merge( $funnel_csv_base, array( 'funnel_scope' => 'campaign' ) ) ) ) );
        $funnel_csv_both    = esc_url( admin_url( 'admin.php?' . http_build_query( array_merge( $funnel_csv_base, array( 'funnel_scope' => 'both' ) ) ) ) );
        $funnel_csv_global  = esc_url( admin_url( 'admin.php?' . http_build_query( array_merge( $funnel_csv_base, array( 'funnel_scope' => 'global' ) ) ) ) );
        $funnel_csv_complete = esc_url( admin_url( 'admin.php?' . http_build_query( array_merge( $funnel_csv_base, array( 'funnel_scope' => 'complete' ) ) ) ) );

        echo '<div class="ois-box" style="background:#fff;border:1px solid #ccd0d4;padding:14px 18px;border-radius:4px;margin-bottom:18px;max-width:960px;">';
        echo '<div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">';
        echo '<span style="font-weight:600;font-size:13px;color:#1d2327;">' . esc_html__( 'Export funnel', 'ois-conversion-suite' ) . '</span>';
        echo '<a class="button button-small" href="' . $funnel_csv_company . '">' . esc_html__( 'CSV — By company', 'ois-conversion-suite' ) . '</a>';
        echo '<a class="button button-small" href="' . $funnel_csv_camp . '">' . esc_html__( 'CSV — By campaign link', 'ois-conversion-suite' ) . '</a>';
        echo '<a class="button button-small" href="' . $funnel_csv_both . '">' . esc_html__( 'CSV — Both sections', 'ois-conversion-suite' ) . '</a>';
        echo '<a class="button button-small" href="' . $funnel_csv_global . '">' . esc_html__( 'CSV — Global funnel', 'ois-conversion-suite' ) . '</a>';
        echo '<a class="button button-primary button-small" href="' . $funnel_csv_complete . '">' . esc_html__( 'CSV — Complete report', 'ois-conversion-suite' ) . '</a>';
        echo '</div>';
        echo '<p style="margin:10px 0 0;font-size:12px;color:#64748b;">' . esc_html__( 'Uses the same date range and UTM dashboard filter as this page.', 'ois-conversion-suite' ) . '</p>';
        echo '</div>';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $saved_links = $wpdb->get_results(
            "SELECT * FROM `{$table_refs}` WHERE 1=1 {$filter_sql_refs} ORDER BY label_name ASC, utm_campaign ASC, utm_term ASC"
        );

        $by_label = array();
        foreach ( (array) $saved_links as $link ) {
            $by_label[ $link->label_name ][] = $link;
        }

        // Global funnel: any row with utm_campaign (same idea as UTM Click Tracker). Old logic used only IN(saved slugs),
        // so test URLs never appeared unless the slug matched oiscl_utm_references exactly.
        $global_stats = $this->oiscl_utm_analyze_funnel_sessions(
            $this->oiscl_utm_fetch_funnel_session_rows_any_utm( $table_stats, $start_date, $end_date, $filter_sql_stats ),
            ''
        );

        if ( $global_stats['step1'] > 0 && 0 === (int) $global_stats['step2'] ) {
            echo '<div class="notice notice-warning inline" style="margin:0 0 16px;padding:12px 14px;max-width:960px;"><p style="margin:0;font-size:13px;line-height:1.5;">';
            echo esc_html__( 'Step 2 is zero while Step 1 has sessions: visitors reached the landing page with UTM data but no tracked block view was recorded afterward. Confirm Click Tracker block views fire on that landing template (tracked sections/blocks).', 'ois-conversion-suite' );
            echo '</p></div>';
        }

        if ( empty( $saved_links ) ) {
            echo '<div class="notice notice-info inline" style="margin:0 0 16px;"><p>';
            echo esc_html__( 'No saved UTM links yet. The funnel below still includes any traffic with a stored utm_campaign. Add links in Settings to unlock per-campaign tables.', 'ois-conversion-suite' );
            echo ' <a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Open UTM Settings', 'ois-conversion-suite' ) . '</a>';
            echo '</p></div>';
        }
        $this->oiscl_utm_render_funnel_steps_visual(
            $global_stats,
            __( 'Global UTM funnel (any campaign)', 'ois-conversion-suite' ),
            __( 'Step 3 here counts any qualifying interaction after a block view. Rows under Funnel by campaign link narrow Step 3 when you set a Conversion click label on that saved link; Funnel by company uses the same broad Step 3 rule as this card.', 'ois-conversion-suite' )
        );

        $co_rows = array();
        foreach ( $by_label as $label_name => $links ) {
            $stats = $this->oiscl_utm_analyze_company_funnel(
                $label_name,
                $links,
                $table_stats,
                $start_date,
                $end_date,
                $filter_sql_stats
            );
            $co_rows[] = array(
                'cols' => array(
                    '<strong>' . esc_html( $label_name ) . '</strong>',
                    '<strong>' . esc_html( number_format_i18n( $stats['step1'] ) ) . '</strong>',
                    esc_html( number_format_i18n( $stats['step2'] ) ),
                    esc_html( $this->oiscl_utm_funnel_pct( $stats['step2'], $stats['step1'] ) ),
                    esc_html( number_format_i18n( $stats['step3'] ) ),
                    esc_html( $this->oiscl_utm_funnel_pct( $stats['step3'], $stats['step2'] ) ),
                    '<strong>' . esc_html( $this->oiscl_utm_funnel_pct( $stats['step3'], $stats['step1'] ) ) . '</strong>',
                ),
            );
        }

        $camp_rows = array();
        foreach ( (array) $saved_links as $link ) {
            $stats = $this->oiscl_utm_analyze_link_funnel(
                $link,
                $table_stats,
                $start_date,
                $end_date,
                $filter_sql_stats
            );
            $conv_note = '' !== $stats['conv_label']
                ? '<code style="font-size:10px;">' . esc_html( $stats['conv_label'] ) . '</code>'
                : '<span style="color:#94a3b8;font-size:11px;">' . esc_html__( 'any click', 'ois-conversion-suite' ) . '</span>';
            $camp_rows[] = array(
                'cols' => array(
                    esc_html( $link->label_name ),
                    '<code>' . esc_html( $link->utm_campaign ) . '</code>' . ( '' !== (string) $link->utm_term ? ' <span style="color:#94a3b8;">/ ' . esc_html( $link->utm_term ) . '</span>' : '' ),
                    $conv_note,
                    '<strong>' . esc_html( number_format_i18n( $stats['step1'] ) ) . '</strong>',
                    esc_html( number_format_i18n( $stats['step2'] ) ),
                    esc_html( $this->oiscl_utm_funnel_pct( $stats['step2'], $stats['step1'] ) ),
                    esc_html( number_format_i18n( $stats['step3'] ) ),
                    esc_html( $this->oiscl_utm_funnel_pct( $stats['step3'], $stats['step2'] ) ),
                    '<strong style="color:#166534;">' . esc_html( $this->oiscl_utm_funnel_pct( $stats['step3'], $stats['step1'] ) ) . '</strong>',
                ),
            );
        }

        $funnel_headers = array(
            array( 'label' => __( 'Company', 'ois-conversion-suite' ), 'type' => 'string' ),
            array( 'label' => __( 'Landings', 'ois-conversion-suite' ), 'align' => 'right', 'type' => 'numeric', 'tooltip' => __( 'Step 1: sessions with pageview.', 'ois-conversion-suite' ) ),
            array( 'label' => __( 'Block views', 'ois-conversion-suite' ), 'align' => 'right', 'type' => 'numeric', 'tooltip' => __( 'Step 2: block view after pageview.', 'ois-conversion-suite' ) ),
            array( 'label' => __( '1→2', 'ois-conversion-suite' ), 'align' => 'right', 'type' => 'string' ),
            array( 'label' => __( 'Conversions', 'ois-conversion-suite' ), 'align' => 'right', 'type' => 'numeric', 'tooltip' => __( 'Step 3: conversion click after block.', 'ois-conversion-suite' ) ),
            array( 'label' => __( '2→3', 'ois-conversion-suite' ), 'align' => 'right', 'type' => 'string' ),
            array( 'label' => __( 'Overall', 'ois-conversion-suite' ), 'align' => 'right', 'type' => 'string', 'tooltip' => __( 'Step 1 to step 3 rate.', 'ois-conversion-suite' ) ),
        );

        $this->render_ois_component(
            'advanced_table',
            array(
                'id'       => 'tbl-utm-funnel-co',
                'title'    => __( 'Funnel by company', 'ois-conversion-suite' ),
                'subtitle' => __( 'Rollup across all campaigns under each company / label in the current filter.', 'ois-conversion-suite' ),
                'icon'     => '🏢',
                'headers'  => $funnel_headers,
                'rows'     => $co_rows,
            )
        );

        $camp_headers = array(
            array( 'label' => __( 'Company', 'ois-conversion-suite' ), 'type' => 'string' ),
            array( 'label' => __( 'Campaign', 'ois-conversion-suite' ), 'type' => 'string' ),
            array( 'label' => __( 'Conv. target', 'ois-conversion-suite' ), 'type' => 'string', 'tooltip' => __( 'Conversion click label from Settings; “any click” if empty.', 'ois-conversion-suite' ) ),
            array( 'label' => __( 'Landings', 'ois-conversion-suite' ), 'align' => 'right', 'type' => 'numeric' ),
            array( 'label' => __( 'Blocks', 'ois-conversion-suite' ), 'align' => 'right', 'type' => 'numeric' ),
            array( 'label' => __( '1→2', 'ois-conversion-suite' ), 'align' => 'right', 'type' => 'string' ),
            array( 'label' => __( 'Conv.', 'ois-conversion-suite' ), 'align' => 'right', 'type' => 'numeric' ),
            array( 'label' => __( '2→3', 'ois-conversion-suite' ), 'align' => 'right', 'type' => 'string' ),
            array( 'label' => __( 'Overall', 'ois-conversion-suite' ), 'align' => 'right', 'type' => 'string' ),
        );

        $this->render_ois_component(
            'advanced_table',
            array(
                'id'       => 'tbl-utm-funnel-camp',
                'title'    => __( 'Funnel by campaign link', 'ois-conversion-suite' ),
                'subtitle' => sprintf(
                    /* translators: 1: start date, 2: end date */
                    __( 'Sequential funnel per saved link (campaign + term) for %1$s–%2$s. Requires Click Tracker block views on the landing page.', 'ois-conversion-suite' ),
                    $start_date,
                    $end_date
                ),
                'icon'     => '🎯',
                'headers'  => $camp_headers,
                'rows'     => $camp_rows,
            )
        );

        $this->oiscl_render_utm_block_metrics_diag( $table_stats, $start_date, $end_date, $filter_sql_stats );

        echo '<p style="font-size:12px;color:#64748b;margin-top:8px;">';
        echo esc_html__( 'Tip: set Conversion click label in Settings → UTM Manager for step 3 to match WhatsApp, form submit, etc.', 'ois-conversion-suite' );
        echo ' <a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Open UTM Settings', 'ois-conversion-suite' ) . '</a>';
        echo '</p>';
    }

    private function oiscl_get_utm_attr_select_html( $selected_mode, $select_id = 'oiscl-utm-attr' ) {
        $selected_mode = $this->oiscl_sanitize_utm_attr_mode( $selected_mode );
        $options       = array(
            'first'   => __( 'First touch', 'ois-conversion-suite' ),
            'last'    => __( 'Last touch', 'ois-conversion-suite' ),
            'session' => __( 'Session landing', 'ois-conversion-suite' ),
        );
        $html = '<select id="' . esc_attr( $select_id ) . '" class="oiscl-utm-attr-redirect" style="min-width:160px;max-width:220px;">';
        foreach ( $options as $val => $label ) {
            $html .= '<option value="' . esc_attr( $val ) . '"' . selected( $val, $selected_mode, false ) . '>' . esc_html( $label ) . '</option>';
        }
        $html .= '</select>';
        return $html;
    }

    private function oiscl_get_utm_tracker_filter_select_html( $selected_filter, $filter_hierarchy, $all_option_label, $select_id = 'oiscl-utm-filter' ) {
        $html  = '<select id="' . esc_attr( $select_id ) . '" class="oiscl-utm-filter-redirect" style="min-width:200px;max-width:320px;">';
        $html .= '<option value="all"' . selected( 'all', $selected_filter, false ) . '>' . esc_html( $all_option_label ) . '</option>';
        foreach ( $filter_hierarchy as $label => $campaigns ) {
            $html .= '<optgroup label="' . esc_attr( '🏢 ' . $label ) . '">';
            $lbl_val = 'lbl_' . $label;
            $html .= '<option value="' . esc_attr( $lbl_val ) . '"' . selected( $lbl_val, $selected_filter, false ) . '>' . esc_html( sprintf( /* translators: %s: company / label name */ __( 'All · %s', 'ois-conversion-suite' ), $label ) ) . '</option>';
            foreach ( (array) $campaigns as $camp ) {
                $html .= '<option value="' . esc_attr( $camp ) . '"' . selected( (string) $camp, $selected_filter, false ) . '>' . esc_html( $camp ) . '</option>';
            }
            $html .= '</optgroup>';
        }
        $html .= '</select>';
        return $html;
    }

}
