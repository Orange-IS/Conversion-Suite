<?php
/**
 * Scheduled email reports: CSV snapshot from Custom Dashboard templates.
 *
 * @package OIS_Conversion_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OISCL_Scheduled_Reports {

	const OPTION    = 'oiscl_scheduled_report_jobs';
	const CRON_HOOK = 'oiscl_scheduled_reports_tick';

	const CADENCE_DAILY    = 'daily';
	const CADENCE_WEEKLY   = 'weekly';
	const CADENCE_BIWEEKLY = 'biweekly';
	const CADENCE_MONTHLY  = 'monthly';

	const PERIOD_ROLLING_7           = 'rolling_7';
	const PERIOD_ROLLING_14          = 'rolling_14';
	const PERIOD_ROLLING_30          = 'rolling_30';
	const PERIOD_PREV_CALENDAR_MONTH = 'prev_calendar_month';
	const PERIOD_PREV_MONTH_1_15     = 'prev_month_1_15';
	const PERIOD_PREV_MONTH_16_END   = 'prev_month_16_end';

	/**
	 * Bootstrap cron + admin POST handlers.
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_tick' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule_cron' ) );
		add_action( 'admin_post_oiscl_save_report_schedule', array( __CLASS__, 'handle_save_schedule' ) );
		add_action( 'admin_post_oiscl_delete_report_schedule', array( __CLASS__, 'handle_delete_schedule' ) );
		add_action( 'admin_post_oiscl_toggle_report_schedule', array( __CLASS__, 'handle_toggle_schedule' ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function get_jobs_container() {
		$raw = get_option( self::OPTION, array() );
		if ( ! is_array( $raw ) ) {
			return array( 'jobs' => array() );
		}
		if ( ! isset( $raw['jobs'] ) || ! is_array( $raw['jobs'] ) ) {
			return array( 'jobs' => array() );
		}
		return $raw;
	}

	/**
	 * @param array<string,mixed> $container
	 */
	public static function save_jobs_container( array $container ) {
		update_option( self::OPTION, $container, false );
	}

	public static function maybe_schedule_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	public static function clear_cron() {
		$t = wp_next_scheduled( self::CRON_HOOK );
		if ( $t ) {
			wp_unschedule_event( $t, self::CRON_HOOK );
		}
	}

	/**
	 * @return string[]
	 */
	public static function allowed_cadences() {
		return array(
			self::CADENCE_DAILY,
			self::CADENCE_WEEKLY,
			self::CADENCE_BIWEEKLY,
			self::CADENCE_MONTHLY,
		);
	}

	/**
	 * Human-readable cadence label (Send Reports UI).
	 *
	 * @param string $cadence Stored cadence key.
	 */
	public static function cadence_label( $cadence ) {
		switch ( (string) $cadence ) {
			case self::CADENCE_DAILY:
				return __( 'Daily', 'ois-conversion-suite' );
			case self::CADENCE_BIWEEKLY:
				return __( 'Every 14 days', 'ois-conversion-suite' );
			case self::CADENCE_MONTHLY:
				return __( 'About every 30 days', 'ois-conversion-suite' );
			case self::CADENCE_WEEKLY:
			default:
				return __( 'Every 7 days', 'ois-conversion-suite' );
		}
	}

	/**
	 * @return string[]
	 */
	public static function allowed_periods() {
		return array(
			self::PERIOD_ROLLING_7,
			self::PERIOD_ROLLING_14,
			self::PERIOD_ROLLING_30,
			self::PERIOD_PREV_CALENDAR_MONTH,
			self::PERIOD_PREV_MONTH_1_15,
			self::PERIOD_PREV_MONTH_16_END,
		);
	}

	/**
	 * Human-readable snapshot preset label (admin + email).
	 *
	 * @param string $period Period key.
	 */
	public static function period_label( $period ) {
		$labels = array(
			self::PERIOD_ROLLING_7           => __( 'Last 7 days (through yesterday)', 'ois-conversion-suite' ),
			self::PERIOD_ROLLING_14          => __( 'Last 14 days (through yesterday)', 'ois-conversion-suite' ),
			self::PERIOD_ROLLING_30          => __( 'Last 30 days (through yesterday)', 'ois-conversion-suite' ),
			self::PERIOD_PREV_CALENDAR_MONTH => __( 'Previous calendar month (full)', 'ois-conversion-suite' ),
			self::PERIOD_PREV_MONTH_1_15     => __( 'Previous month: day 1–15', 'ois-conversion-suite' ),
			self::PERIOD_PREV_MONTH_16_END   => __( 'Previous month: day 16–end', 'ois-conversion-suite' ),
		);
		$key = (string) $period;
		return isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
	}

	/**
	 * Send clock in site timezone (defaults for legacy jobs: 08:00).
	 *
	 * @param array<string,mixed> $job Job row.
	 * @return int[] { hour 0–23, minute 0–59 }
	 */
	public static function job_send_hour_minute( array $job ) {
		$h = isset( $job['send_hour'] ) ? (int) $job['send_hour'] : 8;
		$m = isset( $job['send_minute'] ) ? (int) $job['send_minute'] : 0;
		$h = max( 0, min( 23, $h ) );
		$m = max( 0, min( 59, $m ) );
		return array( $h, $m );
	}

	/**
	 * Next UNIX timestamp ≥ $after_ts matching cadence and local send time (site timezone).
	 *
	 * @param string $cadence   Cadence key.
	 * @param int    $hour      Local hour.
	 * @param int    $minute    Local minute.
	 * @param int    $after_ts  Epoch; schedule strictly after this instant unless same-second edge.
	 */
	public static function compute_next_run_after( $cadence, $hour, $minute, $after_ts ) {
		$after_ts = (int) $after_ts;
		$h        = (int) $hour;
		$m        = (int) $minute;

		try {
			$tz = wp_timezone();
		} catch ( \Exception $e ) {
			$tz = new \DateTimeZone( 'UTC' );
		}

		$local = ( new \DateTimeImmutable( '@' . $after_ts ) )->setTimezone( $tz );

		switch ( (string) $cadence ) {
			case self::CADENCE_DAILY:
				$candidate = $local->setTime( $h, $m, 0 );
				if ( $candidate->getTimestamp() <= $after_ts ) {
					$candidate = $candidate->modify( '+1 day' );
				}
				return $candidate->getTimestamp();

			case self::CADENCE_BIWEEKLY:
				$step = '+14 days';
				break;
			case self::CADENCE_MONTHLY:
				$step = '+30 days';
				break;
			case self::CADENCE_WEEKLY:
			default:
				$step = '+7 days';
				break;
		}

		$candidate = $local->setTime( $h, $m, 0 );
		while ( $candidate->getTimestamp() <= $after_ts ) {
			$candidate = $candidate->modify( $step );
		}
		return $candidate->getTimestamp();
	}

	/**
	 * @param array<string,mixed> $job
	 */
	public static function schedule_next_run_for_job( array $job, $after_ts ) {
		$cadence = isset( $job['cadence'] ) ? (string) $job['cadence'] : self::CADENCE_WEEKLY;
		if ( ! in_array( $cadence, self::allowed_cadences(), true ) ) {
			$cadence = self::CADENCE_WEEKLY;
		}
		list( $hour, $minute ) = self::job_send_hour_minute( $job );
		return self::compute_next_run_after( $cadence, $hour, $minute, (int) $after_ts );
	}

	public static function run_tick() {
		$box        = self::get_jobs_container();
		$dashboards = get_option( 'oiscl_custom_dashboards', array() );
		if ( ! is_array( $dashboards ) ) {
			$dashboards = array();
		}

		$now = time();
		foreach ( $box['jobs'] as $idx => &$job ) {
			if ( empty( $job['enabled'] ) ) {
				continue;
			}
			$next = isset( $job['next_run'] ) ? (int) $job['next_run'] : 0;
			if ( $now < $next ) {
				continue;
			}

			$dash_id = isset( $job['dashboard_id'] ) ? (string) $job['dashboard_id'] : '';
			if ( '' === $dash_id || ! isset( $dashboards[ $dash_id ] ) ) {
				$job['next_run'] = self::schedule_next_run_for_job( $job, $now );
				continue;
			}

			$period = isset( $job['period'] ) ? (string) $job['period'] : self::PERIOD_ROLLING_7;
			if ( ! in_array( $period, self::allowed_periods(), true ) ) {
				$period = self::PERIOD_ROLLING_7;
			}

			$range = OISCL_Report_Date_Ranges::resolve( $period, $now );
			if ( null === $range ) {
				$job['next_run'] = self::schedule_next_run_for_job( $job, $now );
				continue;
			}

			$dash       = $dashboards[ $dash_id ];
			$dash_title = isset( $dash['title'] ) ? (string) $dash['title'] : $dash_id;

			$attachments = array();
			$tmp         = OISCL_Custom_Dashboard_CSV::write_temp_file( $dash, $range['start_date'], $range['end_date'] );
			if ( $tmp && is_readable( $tmp ) ) {
				$attachments[] = $tmp;
			}

			$recipients = isset( $job['recipients'] ) && is_array( $job['recipients'] ) ? $job['recipients'] : array();
			$recipients = array_values(
				array_filter(
					array_map( 'sanitize_email', $recipients ),
					function ( $e ) {
						return '' !== $e && is_email( $e );
					}
				)
			);

			if ( empty( $recipients ) ) {
				$job['next_run'] = self::schedule_next_run_for_job( $job, $now );
				continue;
			}

			$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
			$subj = sprintf(
				/* translators: 1: site name, 2: dashboard title */
				__( '[%1$s] Report: %2$s', 'ois-conversion-suite' ),
				$site,
				$dash_title
			);

			$tz_try = function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : '';
			$tz     = $tz_try ? $tz_try : 'UTC';

			list( $send_h, $send_m ) = self::job_send_hour_minute( $job );
			$send_clock              = sprintf( '%02d:%02d', $send_h, $send_m );

			$cadence_label = self::cadence_label( isset( $job['cadence'] ) ? (string) $job['cadence'] : self::CADENCE_WEEKLY );
			$period_label  = self::period_label( $period );

			$csv_detail = __( 'No CSV — add tabular column blocks to this template in Custom Dashboards.', 'ois-conversion-suite' );
			if ( ! empty( $attachments ) && is_string( $attachments[0] ) && is_readable( $attachments[0] ) ) {
				$bytes = filesize( $attachments[0] );
				if ( false !== $bytes && function_exists( 'size_format' ) ) {
					$csv_detail = sprintf(
						/* translators: %s: formatted file size */
						__( 'CSV attached (%s)', 'ois-conversion-suite' ),
						size_format( $bytes )
					);
				} else {
					$csv_detail = __( 'CSV attached', 'ois-conversion-suite' );
				}
			}

			$dash_link  = esc_url( admin_url( 'admin.php?page=oiscl-custom-dashboards&tab=dashboards' ) ) . '#wrap-dash-' . rawurlencode( (string) $dash_id );
			$sched_link = admin_url( 'admin.php?page=oiscl-custom-reports' );

			/* translators: %s: recipient count */
			$recip_summary = sprintf( _n( '%s recipient', '%s recipients', count( $recipients ), 'ois-conversion-suite' ), number_format_i18n( count( $recipients ) ) );

			$body  = '<p>' . esc_html__( 'Scheduled report snapshot. Figures apply only to the date range below.', 'ois-conversion-suite' ) . '</p>';
			$body .= '<table cellpadding="6" cellspacing="0" style="border-collapse:collapse;border:1px solid #ccd0d4;margin:12px 0;"><tbody>';
			$body .= '<tr><td style="border:1px solid #ccd0d4;"><strong>' . esc_html__( 'Template / board', 'ois-conversion-suite' ) . '</strong></td><td style="border:1px solid #ccd0d4;">' . esc_html( $dash_title ) . '</td></tr>';
			$body .= '<tr><td style="border:1px solid #ccd0d4;"><strong>' . esc_html__( 'Cadence', 'ois-conversion-suite' ) . '</strong></td><td style="border:1px solid #ccd0d4;">' . esc_html( $cadence_label ) . '</td></tr>';
			$body .= '<tr><td style="border:1px solid #ccd0d4;"><strong>' . esc_html__( 'Preferred send time (site)', 'ois-conversion-suite' ) . '</strong></td><td style="border:1px solid #ccd0d4;">' . esc_html( $send_clock ) . '</td></tr>';
			$body .= '<tr><td style="border:1px solid #ccd0d4;"><strong>' . esc_html__( 'Date range (snapshot)', 'ois-conversion-suite' ) . '</strong></td><td style="border:1px solid #ccd0d4;">' . esc_html( $range['start_date'] . ' — ' . $range['end_date'] ) . '</td></tr>';
			$body .= '<tr><td style="border:1px solid #ccd0d4;"><strong>' . esc_html__( 'Preset', 'ois-conversion-suite' ) . '</strong></td><td style="border:1px solid #ccd0d4;">' . esc_html( $period_label ) . '</td></tr>';
			$body .= '<tr><td style="border:1px solid #ccd0d4;"><strong>' . esc_html__( 'Recipients', 'ois-conversion-suite' ) . '</strong></td><td style="border:1px solid #ccd0d4;">' . esc_html( $recip_summary ) . '</td></tr>';
			$body .= '<tr><td style="border:1px solid #ccd0d4;"><strong>' . esc_html__( 'Attachment', 'ois-conversion-suite' ) . '</strong></td><td style="border:1px solid #ccd0d4;">' . esc_html( $csv_detail ) . '</td></tr>';
			$body .= '<tr><td style="border:1px solid #ccd0d4;"><strong>' . esc_html__( 'Site timezone', 'ois-conversion-suite' ) . '</strong></td><td style="border:1px solid #ccd0d4;">' . esc_html( $tz ) . '</td></tr>';
			$body .= '</tbody></table>';
			$body .= '<p><a href="' . esc_url( $dash_link ) . '">' . esc_html__( 'Open this board in Custom Dashboards', 'ois-conversion-suite' ) . '</a> · ';
			$body .= '<a href="' . esc_url( $sched_link ) . '">' . esc_html__( 'Manage Send Reports', 'ois-conversion-suite' ) . '</a></p>';

			$headers = array( 'Content-Type: text/html; charset=UTF-8' );

			foreach ( $recipients as $to ) {
				wp_mail( $to, $subj, $body, $headers, $attachments );
			}

			foreach ( $attachments as $path ) {
				if ( is_string( $path ) && is_readable( $path ) ) {
					unlink( $path );
				}
			}

			$job['last_sent'] = $now;
			$job['next_run']  = self::schedule_next_run_for_job( $job, $now );
		}
		unset( $job );

		self::save_jobs_container( $box );
	}

	public static function handle_save_schedule() {
		if ( ! current_user_can( 'manage_ois_marketing' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ois-conversion-suite' ) );
		}
		check_admin_referer( 'oiscl_save_report_schedule', 'oiscl_report_sched_nonce' );

		$dashboard_id = isset( $_POST['dashboard_id'] ) ? sanitize_text_field( wp_unslash( $_POST['dashboard_id'] ) ) : '';
		$recipients_r = isset( $_POST['recipients'] ) ? wp_unslash( (string) $_POST['recipients'] ) : '';
		$cadence      = isset( $_POST['cadence'] ) ? sanitize_key( wp_unslash( $_POST['cadence'] ) ) : self::CADENCE_WEEKLY;
		$period       = isset( $_POST['period'] ) ? sanitize_key( wp_unslash( $_POST['period'] ) ) : self::PERIOD_ROLLING_7;

		$dashboards = get_option( 'oiscl_custom_dashboards', array() );
		if ( '' === $dashboard_id || ! is_array( $dashboards ) || ! isset( $dashboards[ $dashboard_id ] ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=oiscl-custom-reports&oiscl_sched_err=dashboard' ) );
			exit;
		}

		if ( ! in_array( $cadence, self::allowed_cadences(), true ) ) {
			$cadence = self::CADENCE_WEEKLY;
		}
		if ( ! in_array( $period, self::allowed_periods(), true ) ) {
			$period = self::PERIOD_ROLLING_7;
		}

		$raw_emails = preg_split( '/[\s,;]+/', $recipients_r, -1, PREG_SPLIT_NO_EMPTY );
		$emails     = array();
		foreach ( (array) $raw_emails as $e ) {
			$e = sanitize_email( trim( (string) $e ) );
			if ( $e && is_email( $e ) ) {
				$emails[] = $e;
			}
		}
		$emails = array_values( array_unique( $emails ) );

		if ( empty( $emails ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=oiscl-custom-reports&oiscl_sched_err=email' ) );
			exit;
		}

		$send_hour   = isset( $_POST['send_hour'] ) ? (int) wp_unslash( $_POST['send_hour'] ) : 8;
		$send_minute = isset( $_POST['send_minute'] ) ? (int) wp_unslash( $_POST['send_minute'] ) : 0;
		$send_hour   = max( 0, min( 23, $send_hour ) );
		$send_minute = max( 0, min( 59, $send_minute ) );

		$box = self::get_jobs_container();
		$id  = strtolower( wp_generate_password( 10, false, false ) );

		$new_job = array(
			'id'              => $id,
			'dashboard_id'    => $dashboard_id,
			'dashboard_title' => isset( $dashboards[ $dashboard_id ]['title'] ) ? (string) $dashboards[ $dashboard_id ]['title'] : '',
			'recipients'      => $emails,
			'cadence'         => $cadence,
			'period'          => $period,
			'send_hour'       => $send_hour,
			'send_minute'     => $send_minute,
			'enabled'         => 1,
			'created_at'      => time(),
			'last_sent'       => 0,
			'next_run'        => self::schedule_next_run_for_job(
				array(
					'cadence'     => $cadence,
					'send_hour'   => $send_hour,
					'send_minute' => $send_minute,
				),
				time()
			),
		);

		$box['jobs'][] = $new_job;

		self::save_jobs_container( $box );

		wp_safe_redirect( admin_url( 'admin.php?page=oiscl-custom-reports&oiscl_sched_saved=1' ) );
		exit;
	}

	public static function handle_delete_schedule() {
		if ( ! current_user_can( 'manage_ois_marketing' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ois-conversion-suite' ) );
		}

		$job_id = isset( $_GET['job_id'] ) ? sanitize_key( wp_unslash( $_GET['job_id'] ) ) : '';
		check_admin_referer( 'oiscl_delete_report_schedule_' . $job_id );

		if ( '' === $job_id ) {
			wp_safe_redirect( admin_url( 'admin.php?page=oiscl-custom-reports' ) );
			exit;
		}

		$box  = self::get_jobs_container();
		$jobs = array();
		foreach ( $box['jobs'] as $j ) {
			if ( isset( $j['id'] ) && (string) $j['id'] === $job_id ) {
				continue;
			}
			$jobs[] = $j;
		}
		$box['jobs'] = $jobs;
		self::save_jobs_container( $box );

		wp_safe_redirect( admin_url( 'admin.php?page=oiscl-custom-reports&oiscl_sched_deleted=1' ) );
		exit;
	}

	public static function handle_toggle_schedule() {
		if ( ! current_user_can( 'manage_ois_marketing' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ois-conversion-suite' ) );
		}

		$job_id = isset( $_GET['job_id'] ) ? sanitize_key( wp_unslash( $_GET['job_id'] ) ) : '';
		check_admin_referer( 'oiscl_toggle_report_schedule_' . $job_id );

		if ( '' === $job_id ) {
			wp_safe_redirect( admin_url( 'admin.php?page=oiscl-custom-reports' ) );
			exit;
		}

		$box          = self::get_jobs_container();
		$found        = false;
		$was_enabled  = false;
		foreach ( $box['jobs'] as &$j ) {
			if ( ! isset( $j['id'] ) || (string) $j['id'] !== $job_id ) {
				continue;
			}
			$was_enabled  = ! empty( $j['enabled'] );
			$j['enabled'] = $was_enabled ? 0 : 1;
			if ( ! $was_enabled ) {
				$j['next_run'] = self::schedule_next_run_for_job( $j, time() );
			}
			$found = true;
			break;
		}
		unset( $j );

		if ( ! $found ) {
			wp_safe_redirect( admin_url( 'admin.php?page=oiscl-custom-reports' ) );
			exit;
		}

		self::save_jobs_container( $box );

		$query = $was_enabled ? 'oiscl_sched_paused=1' : 'oiscl_sched_resumed=1';
		wp_safe_redirect( admin_url( 'admin.php?page=oiscl-custom-reports&' . $query ) );
		exit;
	}
}
