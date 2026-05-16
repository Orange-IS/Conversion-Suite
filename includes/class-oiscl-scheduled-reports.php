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
	 * @param string $cadence weekly|biweekly|monthly
	 */
	public static function cadence_seconds( $cadence ) {
		switch ( $cadence ) {
			case self::CADENCE_BIWEEKLY:
				return 14 * DAY_IN_SECONDS;
			case self::CADENCE_MONTHLY:
				return 30 * DAY_IN_SECONDS;
			case self::CADENCE_WEEKLY:
			default:
				return 7 * DAY_IN_SECONDS;
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
				$job['next_run'] = $now + HOUR_IN_SECONDS;
				continue;
			}

			$period = isset( $job['period'] ) ? (string) $job['period'] : self::PERIOD_ROLLING_7;
			if ( ! in_array( $period, self::allowed_periods(), true ) ) {
				$period = self::PERIOD_ROLLING_7;
			}

			$range = OISCL_Report_Date_Ranges::resolve( $period, $now );
			if ( null === $range ) {
				$job['next_run'] = $now + HOUR_IN_SECONDS;
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
				$cadence_short      = isset( $job['cadence'] ) ? (string) $job['cadence'] : self::CADENCE_WEEKLY;
				$job['next_run']    = $now + self::cadence_seconds( $cadence_short );
				continue;
			}

			$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
			$subj = sprintf(
				/* translators: 1: site name, 2: dashboard title */
				__( '[%1$s] Reporte: %2$s', 'ois-conversion-suite' ),
				$site,
				$dash_title
			);

			$tz_try = function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : '';
			$tz     = $tz_try ? $tz_try : 'UTC';

			$body  = '<p>' . esc_html__( 'Informe programado (snapshot). Los datos corresponden únicamente al rango indicado.', 'ois-conversion-suite' ) . '</p>';
			$body .= '<p><strong>' . esc_html__( 'Plantilla / tablero:', 'ois-conversion-suite' ) . '</strong> ' . esc_html( $dash_title ) . '</p>';
			$body .= '<p><strong>' . esc_html__( 'Periodo:', 'ois-conversion-suite' ) . '</strong> ' . esc_html( $range['start_date'] . ' — ' . $range['end_date'] ) . '</p>';
			$body .= '<p><strong>' . esc_html__( 'Zona horaria del sitio:', 'ois-conversion-suite' ) . '</strong> ' . esc_html( $tz ) . '</p>';

			if ( empty( $attachments ) ) {
				$body .= '<p><em>' . esc_html__( 'No hay columnas tabulares en esta plantilla; el CSV solo se genera cuando el tablero incluye bloques de columnas en Custom Dashboards.', 'ois-conversion-suite' ) . '</em></p>';
			}

			$headers = array( 'Content-Type: text/html; charset=UTF-8' );

			foreach ( $recipients as $to ) {
				wp_mail( $to, $subj, $body, $headers, $attachments );
			}

			foreach ( $attachments as $path ) {
				if ( is_string( $path ) && is_readable( $path ) ) {
					unlink( $path );
				}
			}

			$cadence          = isset( $job['cadence'] ) ? (string) $job['cadence'] : self::CADENCE_WEEKLY;
			$job['last_sent'] = $now;
			$job['next_run']  = $now + self::cadence_seconds( $cadence );
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

		if ( ! in_array( $cadence, array( self::CADENCE_WEEKLY, self::CADENCE_BIWEEKLY, self::CADENCE_MONTHLY ), true ) ) {
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

		$box = self::get_jobs_container();
		$id  = strtolower( wp_generate_password( 10, false, false ) );

		$box['jobs'][] = array(
			'id'              => $id,
			'dashboard_id'    => $dashboard_id,
			'dashboard_title' => isset( $dashboards[ $dashboard_id ]['title'] ) ? (string) $dashboards[ $dashboard_id ]['title'] : '',
			'recipients'      => $emails,
			'cadence'         => $cadence,
			'period'          => $period,
			'enabled'         => 1,
			'created_at'      => time(),
			'last_sent'       => 0,
			'next_run'        => time() + MINUTE_IN_SECONDS,
		);

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
}
