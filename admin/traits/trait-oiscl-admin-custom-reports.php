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
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Schedule saved. The first send may take up to one hour (hourly cron).', 'ois-conversion-suite' ) . '</p></div>';
		}
		if ( ! empty( $_GET['oiscl_sched_deleted'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Schedule removed.', 'ois-conversion-suite' ) . '</p></div>';
		}
		if ( isset( $_GET['oiscl_sched_err'] ) ) {
			$err = sanitize_key( wp_unslash( $_GET['oiscl_sched_err'] ) );
			$msg = 'dashboard' === $err
				? __( 'Choose a valid dashboard.', 'ois-conversion-suite' )
				: __( 'Enter at least one valid email address.', 'ois-conversion-suite' );
			echo '<div class="notice notice-error"><p>' . esc_html( $msg ) . '</p></div>';
		}

		echo '<div class="notice notice-info inline"><p>';
		echo esc_html__( 'Deliveries are snapshots: the CSV reflects the columns configured in Custom Dashboards for the selected date range (normally ending on «yesterday», per preset). Chart blocks are not included in the CSV; add tabular column blocks if you need more data in the attachment.', 'ois-conversion-suite' );
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
		echo '<select name="cadence">';
		foreach ( OISCL_Scheduled_Reports::allowed_cadences() as $cad_key ) {
			echo '<option value="' . esc_attr( $cad_key ) . '">' . esc_html( OISCL_Scheduled_Reports::cadence_label( $cad_key ) ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Approximate interval between sends (checked on the hourly cron).', 'ois-conversion-suite' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Data range (snapshot)', 'ois-conversion-suite' ) . '</th><td>';
		echo '<select name="period">';
		$presets = array(
			OISCL_Scheduled_Reports::PERIOD_ROLLING_7           => __( 'Last 7 days (through yesterday)', 'ois-conversion-suite' ),
			OISCL_Scheduled_Reports::PERIOD_ROLLING_14          => __( 'Last 14 days (through yesterday)', 'ois-conversion-suite' ),
			OISCL_Scheduled_Reports::PERIOD_ROLLING_30          => __( 'Last 30 days (through yesterday)', 'ois-conversion-suite' ),
			OISCL_Scheduled_Reports::PERIOD_PREV_CALENDAR_MONTH => __( 'Previous calendar month (full)', 'ois-conversion-suite' ),
			OISCL_Scheduled_Reports::PERIOD_PREV_MONTH_1_15     => __( 'Previous month: day 1–15', 'ois-conversion-suite' ),
			OISCL_Scheduled_Reports::PERIOD_PREV_MONTH_16_END   => __( 'Previous month: day 16–end', 'ois-conversion-suite' ),
		);
		foreach ( $presets as $val => $label ) {
			echo '<option value="' . esc_attr( $val ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Save schedule', 'ois-conversion-suite' ) );
		echo '</form>';
		echo '</div>';

		echo '<h2>' . esc_html__( 'Active schedules', 'ois-conversion-suite' ) . '</h2>';
		if ( empty( $box['jobs'] ) ) {
			echo '<p>' . esc_html__( 'No scheduled sends yet.', 'ois-conversion-suite' ) . '</p>';
		} else {
			echo '<table class="wp-list-table widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'Dashboard', 'ois-conversion-suite' ) . '</th>';
			echo '<th>' . esc_html__( 'Recipients', 'ois-conversion-suite' ) . '</th>';
			echo '<th>' . esc_html__( 'Cadence', 'ois-conversion-suite' ) . '</th>';
			echo '<th>' . esc_html__( 'Range preset', 'ois-conversion-suite' ) . '</th>';
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
				$next      = isset( $job['next_run'] ) ? (int) $job['next_run'] : 0;
				$last      = isset( $job['last_sent'] ) ? (int) $job['last_sent'] : 0;
				$delurl    = wp_nonce_url(
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
				echo '<td>' . esc_html( $rec ) . '</td>';
				echo '<td>' . esc_html( $cad_label ) . '</td>';
				echo '<td><code>' . esc_html( $per ) . '</code></td>';
				echo '<td>' . ( $next ? esc_html( wp_date( 'Y-m-d H:i', $next ) ) : '—' ) . '</td>';
				echo '<td>' . ( $last ? esc_html( wp_date( 'Y-m-d H:i', $last ) ) : '—' ) . '</td>';
				echo '<td><a href="' . esc_url( $delurl ) . '" class="button-link-delete" onclick="return confirm(\'' . esc_js( __( 'Remove this schedule?', 'ois-conversion-suite' ) ) . '\');">' . esc_html__( 'Remove', 'ois-conversion-suite' ) . '</a></td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		echo '</div>';
	}
}
