<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait OISCL_Admin_Custom_Reports_Trait {

	/**
	 * Send Reports: scheduled snapshots from Custom Dashboard templates (CSV + HTML intro).
	 */
	public function display_custom_reports_page() {
		if ( ! current_user_can( 'manage_ois_marketing' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ois-conversion-suite' ) );
		}

		$dashboards = get_option( 'oiscl_custom_dashboards', array() );
		if ( ! is_array( $dashboards ) ) {
			$dashboards = array();
		}

		$box = OISCL_Scheduled_Reports::get_jobs_container();

		echo '<div class="wrap oiscl-layout-root">';
		echo '<h1 class="oiscl-admin-page-title">' . esc_html__( '📑 Send Reports', 'ois-conversion-suite' ) . '</h1>';

		if ( ! empty( $_GET['oiscl_sched_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Schedule saved. WordPress runs an hourly cron; the first send occurs at or after your preferred local time once the slot is due.', 'ois-conversion-suite' ) . '</p></div>';
		}
		if ( ! empty( $_GET['oiscl_sched_deleted'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Schedule removed.', 'ois-conversion-suite' ) . '</p></div>';
		}
		if ( ! empty( $_GET['oiscl_sched_paused'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Schedule paused.', 'ois-conversion-suite' ) . '</p></div>';
		}
		if ( ! empty( $_GET['oiscl_sched_resumed'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Schedule resumed.', 'ois-conversion-suite' ) . '</p></div>';
		}
		if ( ! empty( $_GET['oiscl_sched_sent_now'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Report sent now (check recipients).', 'ois-conversion-suite' ) . '</p></div>';
		}
		if ( isset( $_GET['oiscl_sched_err'] ) ) {
			$err = sanitize_key( wp_unslash( $_GET['oiscl_sched_err'] ) );
			$msg = 'dashboard' === $err
				? __( 'Choose a valid dashboard.', 'ois-conversion-suite' )
				: __( 'Enter at least one valid email address.', 'ois-conversion-suite' );
			echo '<div class="notice notice-error"><p>' . esc_html( $msg ) . '</p></div>';
		}

		echo '<div class="notice notice-info inline"><p>';
		echo esc_html__( 'Deliveries use one delivery format (email-only summary, CSV, HTML snapshot, or print-ready HTML for Save as PDF). Tabular attachments require column blocks in Custom Dashboards; chart blocks are never attached.', 'ois-conversion-suite' );
		echo '</p></div>';

		if ( empty( $dashboards ) ) {
			echo '<div class="notice notice-warning"><p>';
			echo esc_html__( 'There are no boards in Custom Dashboards yet. Create one before scheduling sends.', 'ois-conversion-suite' );
			echo ' <a href="' . esc_url( admin_url( 'admin.php?page=oiscl-custom-dashboards' ) ) . '">' . esc_html__( 'Open Custom Dashboards', 'ois-conversion-suite' ) . '</a>';
			echo '</p></div>';
		}

		$form_action = esc_url( admin_url( 'admin-post.php' ) );
		echo '<div style="background:#fff;border:1px solid #ccd0d4;padding:20px;border-radius:4px;max-width:920px;margin-bottom:24px;">';
		echo '<h2 class="title" style="margin-top:0;">' . esc_html__( 'New schedule', 'ois-conversion-suite' ) . '</h2>';
		echo '<form method="post" action="' . $form_action . '">';
		wp_nonce_field( 'oiscl_save_report_schedule', 'oiscl_report_sched_nonce' );
		echo '<input type="hidden" name="action" value="oiscl_save_report_schedule" />';

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="oiscl_sched_dash">' . esc_html__( 'Dashboard / template', 'ois-conversion-suite' ) . '</label></th><td>';
		echo '<select name="dashboard_id" id="oiscl_sched_dash" class="regular-text" required>';
		echo '<option value="">' . esc_html__( '— Select —', 'ois-conversion-suite' ) . '</option>';
		foreach ( $dashboards as $id => $row ) {
			$t = isset( $row['title'] ) ? (string) $row['title'] : $id;
			echo '<option value="' . esc_attr( (string) $id ) . '">' . esc_html( $t ) . '</option>';
		}
		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="oiscl_sched_emails">' . esc_html__( 'Recipients', 'ois-conversion-suite' ) . '</label></th><td>';
		echo '<textarea name="recipients" id="oiscl_sched_emails" class="large-text" rows="3" placeholder="client@example.com, team@example.com" required></textarea>';
		echo '<p class="description">' . esc_html__( 'Separate with commas or line breaks.', 'ois-conversion-suite' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Send every', 'ois-conversion-suite' ) . '</th><td>';
		echo '<select name="cadence" id="oiscl_sched_cadence">';
		foreach ( OISCL_Scheduled_Reports::allowed_cadences() as $cad_key ) {
			echo '<option value="' . esc_attr( $cad_key ) . '">' . esc_html( OISCL_Scheduled_Reports::cadence_label( $cad_key ) ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Interval between sends. Each send is targeted at your preferred local clock time below (site timezone). Choosing Daily suggests Yesterday as the data range (you can pick Today for intraday / emergency sends).', 'ois-conversion-suite' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Preferred send time', 'ois-conversion-suite' ) . '</th><td>';
		echo '<select name="send_hour" id="oiscl_sched_send_h" aria-label="' . esc_attr__( 'Hour', 'ois-conversion-suite' ) . '">';
		for ( $h = 0; $h <= 23; $h++ ) {
			echo '<option value="' . (int) $h . '"' . selected( $h, 8, false ) . '>' . esc_html( sprintf( '%02d', $h ) ) . '</option>';
		}
		echo '</select>';
		echo ' : ';
		echo '<select name="send_minute" id="oiscl_sched_send_m" aria-label="' . esc_attr__( 'Minute', 'ois-conversion-suite' ) . '">';
		for ( $m = 0; $m <= 59; $m++ ) {
			echo '<option value="' . (int) $m . '"' . selected( $m, 0, false ) . '>' . esc_html( sprintf( '%02d', $m ) ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Uses the WordPress site timezone. The hourly cron may deliver shortly after this time.', 'ois-conversion-suite' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Delivery format', 'ois-conversion-suite' ) . '</th><td>';
		echo '<select name="delivery_format">';
		foreach ( OISCL_Scheduled_Reports::allowed_delivery_formats() as $df_key ) {
			echo '<option value="' . esc_attr( $df_key ) . '"' . selected( $df_key, OISCL_Scheduled_Reports::DELIVERY_CSV, false ) . '>' . esc_html( OISCL_Scheduled_Reports::delivery_format_label( $df_key ) ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( '"PDF" uses a print-ready HTML attachment: open it in a browser, then Print → Save as PDF.', 'ois-conversion-suite' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Data range (snapshot)', 'ois-conversion-suite' ) . '</th><td>';
		echo '<select name="period" id="oiscl_sched_period">';
		foreach ( OISCL_Scheduled_Reports::allowed_periods() as $pkey ) {
			echo '<option value="' . esc_attr( $pkey ) . '"' . selected( $pkey, OISCL_Scheduled_Reports::PERIOD_YESTERDAY, false ) . '>' . esc_html( OISCL_Scheduled_Reports::period_label( $pkey ) ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Yesterday / rolling ranges normally end on the last full day before today. Today includes the current calendar day (partial data until midnight).', 'ois-conversion-suite' ) . '</p></td></tr>';

		echo '</tbody></table>';
		echo '<p class="submit">';
		echo '<button type="submit" name="oiscl_sched_submit" value="save" class="button button-primary">' . esc_html__( 'Save schedule', 'ois-conversion-suite' ) . '</button> ';
		echo '<button type="submit" name="oiscl_sched_submit" value="now" class="button">' . esc_html__( 'Send now', 'ois-conversion-suite' ) . '</button>';
		echo '</p>';
		echo '<script>(function(){var c=document.getElementById("oiscl_sched_cadence"),p=document.getElementById("oiscl_sched_period");if(!c||!p)return;function sync(){if(c.value==="daily"&&!p.getAttribute("data-touched")){var o=p.querySelector(\'option[value="yesterday"]\');if(o)o.selected=!0;}}c.addEventListener("change",sync);p.addEventListener("change",function(){p.setAttribute("data-touched","1");});sync();})();</script>';
		echo '</form>';
		echo '</div>';

		echo '<h2>' . esc_html__( 'Active schedules', 'ois-conversion-suite' ) . '</h2>';
		if ( empty( $box['jobs'] ) ) {
			echo '<p>' . esc_html__( 'No scheduled sends yet.', 'ois-conversion-suite' ) . '</p>';
		} else {
			echo '<table class="wp-list-table widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'Dashboard', 'ois-conversion-suite' ) . '</th>';
			echo '<th>' . esc_html__( 'Status', 'ois-conversion-suite' ) . '</th>';
			echo '<th>' . esc_html__( 'Recipients', 'ois-conversion-suite' ) . '</th>';
			echo '<th>' . esc_html__( 'Cadence', 'ois-conversion-suite' ) . '</th>';
			echo '<th>' . esc_html__( 'Send time', 'ois-conversion-suite' ) . '</th>';
			echo '<th>' . esc_html__( 'Range preset', 'ois-conversion-suite' ) . '</th>';
			echo '<th>' . esc_html__( 'Format', 'ois-conversion-suite' ) . '</th>';
			echo '<th>' . esc_html__( 'Next send', 'ois-conversion-suite' ) . '</th>';
			echo '<th>' . esc_html__( 'Last sent', 'ois-conversion-suite' ) . '</th>';
			echo '<th>' . esc_html__( 'Actions', 'ois-conversion-suite' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $box['jobs'] as $job ) {
				$jid       = isset( $job['id'] ) ? (string) $job['id'] : '';
				$title     = isset( $job['dashboard_title'] ) ? (string) $job['dashboard_title'] : '';
				$rec       = isset( $job['recipients'] ) && is_array( $job['recipients'] ) ? implode( ', ', $job['recipients'] ) : '';
				$cad       = isset( $job['cadence'] ) ? (string) $job['cadence'] : '';
				$cad_label = OISCL_Scheduled_Reports::cadence_label( $cad );
				$per       = isset( $job['period'] ) ? (string) $job['period'] : '';
				$per_label = OISCL_Scheduled_Reports::period_label( $per );
				$fmt_label = OISCL_Scheduled_Reports::delivery_format_label( OISCL_Scheduled_Reports::job_delivery_format( $job ) );
				$next      = isset( $job['next_run'] ) ? (int) $job['next_run'] : 0;
				$last      = isset( $job['last_sent'] ) ? (int) $job['last_sent'] : 0;
				$enabled   = ! empty( $job['enabled'] );
				list( $sh, $sm ) = OISCL_Scheduled_Reports::job_send_hour_minute( $job );
				$clock_label     = sprintf( '%02d:%02d', $sh, $sm );
				$status_label    = $enabled
					? __( 'Active', 'ois-conversion-suite' )
					: __( 'Paused', 'ois-conversion-suite' );
				$toggle_label = $enabled
					? __( 'Pause', 'ois-conversion-suite' )
					: __( 'Resume', 'ois-conversion-suite' );

				$sendnowurl = wp_nonce_url(
					add_query_arg(
						array(
							'action' => 'oiscl_send_report_job_now',
							'job_id' => rawurlencode( $jid ),
						),
						admin_url( 'admin-post.php' )
					),
					'oiscl_send_report_job_now_' . $jid
				);
				$toggleurl = wp_nonce_url(
					add_query_arg(
						array(
							'action' => 'oiscl_toggle_report_schedule',
							'job_id' => rawurlencode( $jid ),
						),
						admin_url( 'admin-post.php' )
					),
					'oiscl_toggle_report_schedule_' . $jid
				);
				$delurl = wp_nonce_url(
					add_query_arg(
						array(
							'action' => 'oiscl_delete_report_schedule',
							'job_id' => rawurlencode( $jid ),
						),
						admin_url( 'admin-post.php' )
					),
					'oiscl_delete_report_schedule_' . $jid
				);
				echo '<tr>';
				echo '<td>' . esc_html( $title ) . '</td>';
				echo '<td>' . esc_html( $status_label ) . '</td>';
				echo '<td>' . esc_html( $rec ) . '</td>';
				echo '<td>' . esc_html( $cad_label ) . '</td>';
				echo '<td>' . esc_html( $clock_label ) . '</td>';
				echo '<td>' . esc_html( $per_label ) . '</td>';
				echo '<td>' . esc_html( $fmt_label ) . '</td>';
				echo '<td>' . ( $next ? esc_html( wp_date( 'Y-m-d H:i', $next ) ) : '—' ) . '</td>';
				echo '<td>' . ( $last ? esc_html( wp_date( 'Y-m-d H:i', $last ) ) : '—' ) . '</td>';
				echo '<td>';
				echo '<a href="' . esc_url( $sendnowurl ) . '">' . esc_html__( 'Send now', 'ois-conversion-suite' ) . '</a>';
				echo ' <span class="sep">|</span> ';
				echo '<a href="' . esc_url( $toggleurl ) . '">' . esc_html( $toggle_label ) . '</a>';
				echo ' <span class="sep">|</span> ';
				echo '<a href="' . esc_url( $delurl ) . '" class="button-link-delete" onclick="return confirm(\'' . esc_js( __( 'Remove this schedule?', 'ois-conversion-suite' ) ) . '\');">' . esc_html__( 'Remove', 'ois-conversion-suite' ) . '</a>';
				echo '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		echo '</div>';
	}
}
