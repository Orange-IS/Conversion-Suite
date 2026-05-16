<?php
/**
 * Scheduled email reports: Custom Dashboard snapshots (HTML email + optional CSV/HTML/PDF-print attachments).
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

	const PERIOD_YESTERDAY           = 'yesterday';
	const PERIOD_TODAY               = 'today';
	const PERIOD_ROLLING_7           = 'rolling_7';
	const PERIOD_ROLLING_14          = 'rolling_14';
	const PERIOD_ROLLING_30          = 'rolling_30';
	const PERIOD_PREV_CALENDAR_MONTH = 'prev_calendar_month';
	const PERIOD_PREV_MONTH_1_15     = 'prev_month_1_15';
	const PERIOD_PREV_MONTH_16_END   = 'prev_month_16_end';

	const DELIVERY_EMAIL_ONLY = 'email_only';
	const DELIVERY_CSV        = 'csv';
	const DELIVERY_HTML       = 'html';
	const DELIVERY_PDF        = 'pdf';

	/**
	 * Bootstrap cron + admin POST handlers.
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_tick' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule_cron' ) );
		add_action( 'admin_post_oiscl_save_report_schedule', array( __CLASS__, 'handle_save_schedule' ) );
		add_action( 'admin_post_oiscl_delete_report_schedule', array( __CLASS__, 'handle_delete_schedule' ) );
		add_action( 'admin_post_oiscl_toggle_report_schedule', array( __CLASS__, 'handle_toggle_schedule' ) );
		add_action( 'admin_post_oiscl_send_report_job_now', array( __CLASS__, 'handle_send_report_job_now' ) );
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
			self::PERIOD_YESTERDAY,
			self::PERIOD_TODAY,
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
			self::PERIOD_YESTERDAY           => __( 'Yesterday (full calendar day)', 'ois-conversion-suite' ),
			self::PERIOD_TODAY               => __( 'Today (through today, intraday / emergency)', 'ois-conversion-suite' ),
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
	 * @return string[]
	 */
	public static function allowed_delivery_formats() {
		return array(
			self::DELIVERY_EMAIL_ONLY,
			self::DELIVERY_CSV,
			self::DELIVERY_HTML,
			self::DELIVERY_PDF,
		);
	}

	/**
	 * @param array<string,mixed> $job Job row.
	 */
	public static function job_delivery_format( array $job ) {
		$f = isset( $job['delivery_format'] ) ? sanitize_key( (string) $job['delivery_format'] ) : self::DELIVERY_CSV;
		if ( ! in_array( $f, self::allowed_delivery_formats(), true ) ) {
			return self::DELIVERY_CSV;
		}
		return $f;
	}

	/**
	 * @param string $format Delivery key.
	 */
	public static function delivery_format_label( $format ) {
		switch ( (string) $format ) {
			case self::DELIVERY_EMAIL_ONLY:
				return __( 'Email summary only (no file attachment)', 'ois-conversion-suite' );
			case self::DELIVERY_HTML:
				return __( 'HTML snapshot attachment', 'ois-conversion-suite' );
			case self::DELIVERY_PDF:
				return __( 'Print-ready HTML (open attachment → Print → Save as PDF)', 'ois-conversion-suite' );
			case self::DELIVERY_CSV:
			default:
				return __( 'CSV attachment', 'ois-conversion-suite' );
		}
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

	/**
	 * Build attachments and send one report run.
	 *
	 * When `$persist_schedule_updates` is false (Send now), the job row is not modified — including retry next_run.
	 *
	 * @param array<string,mixed> $job
	 * @param array<string,mixed> $dashboards
	 * @param int                 $now_ts                   Reference time (range resolve + last_sent).
	 * @param bool                $persist_schedule_updates Update next_run / last_sent and deferrals when true.
	 */
	public static function deliver_scheduled_job( array &$job, array $dashboards, $now_ts, $persist_schedule_updates ) {
		$dash_id = isset( $job['dashboard_id'] ) ? (string) $job['dashboard_id'] : '';
		if ( '' === $dash_id || ! isset( $dashboards[ $dash_id ] ) ) {
			if ( $persist_schedule_updates ) {
				$job['next_run'] = self::schedule_next_run_for_job( $job, $now_ts );
			}
			return;
		}

		$period = isset( $job['period'] ) ? (string) $job['period'] : self::PERIOD_ROLLING_7;
		if ( ! in_array( $period, self::allowed_periods(), true ) ) {
			$period = self::PERIOD_ROLLING_7;
		}

		$range = OISCL_Report_Date_Ranges::resolve( $period, $now_ts );
		if ( null === $range ) {
			if ( $persist_schedule_updates ) {
				$job['next_run'] = self::schedule_next_run_for_job( $job, $now_ts );
			}
			return;
		}

		$dash       = $dashboards[ $dash_id ];
		$dash_title = isset( $dash['title'] ) ? (string) $dash['title'] : $dash_id;

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
			if ( $persist_schedule_updates ) {
				$job['next_run'] = self::schedule_next_run_for_job( $job, $now_ts );
			}
			return;
		}

		$fmt    = self::job_delivery_format( $job );
		$export = OISCL_Custom_Dashboard_CSV::get_tabular_export( $dash, $range['start_date'], $range['end_date'] );

		$attach_base = self::attachment_export_base_name( $dash_title, $dash_id, $range['start_date'], $range['end_date'] );

		$attachments = array();
		if ( null !== $export ) {
			if ( self::DELIVERY_CSV === $fmt ) {
				$tmp = OISCL_Custom_Dashboard_CSV::write_temp_csv_from_export( $export );
				if ( $tmp && is_readable( $tmp ) ) {
					$attachments[] = self::finalize_attachment_path( $tmp, $attach_base, 'csv' );
				}
			} elseif ( self::DELIVERY_HTML === $fmt ) {
				$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
				$sub  = $site . ' — ' . $range['start_date'] . ' — ' . $range['end_date'];
				$tmp  = OISCL_Custom_Dashboard_CSV::write_temp_html_from_export( $export, $dash_title, $sub, false );
				if ( $tmp && is_readable( $tmp ) ) {
					$attachments[] = self::finalize_attachment_path( $tmp, $attach_base, 'html' );
				}
			} elseif ( self::DELIVERY_PDF === $fmt ) {
				$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
				$sub  = $site . ' — ' . $range['start_date'] . ' — ' . $range['end_date'];
				$tmp  = OISCL_Custom_Dashboard_CSV::write_temp_html_from_export( $export, $dash_title, $sub, true );
				if ( $tmp && is_readable( $tmp ) ) {
					$attachments[] = self::finalize_attachment_path( $tmp, $attach_base . '-print', 'html' );
				}
			}
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

		$cadence_label        = self::cadence_label( isset( $job['cadence'] ) ? (string) $job['cadence'] : self::CADENCE_WEEKLY );
		$period_label         = self::period_label( $period );
		$delivery_label       = self::delivery_format_label( $fmt );
		$attach_detail        = self::describe_delivery_attachments( $fmt, $attachments, null === $export );
		$dash_link            = esc_url( admin_url( 'admin.php?page=oiscl-custom-dashboards&tab=dashboards' ) ) . '#wrap-dash-' . rawurlencode( (string) $dash_id );
		$sched_link           = admin_url( 'admin.php?page=oiscl-custom-reports' );
		$recip_summary        = sprintf( _n( '%s recipient', '%s recipients', count( $recipients ), 'ois-conversion-suite' ), number_format_i18n( count( $recipients ) ) );

		$body  = '<p>' . esc_html__( 'Scheduled report snapshot. Figures apply only to the date range below.', 'ois-conversion-suite' ) . '</p>';
		$body .= '<table cellpadding="6" cellspacing="0" style="border-collapse:collapse;border:1px solid #ccd0d4;margin:12px 0;"><tbody>';
		$body .= '<tr><td style="border:1px solid #ccd0d4;"><strong>' . esc_html__( 'Template / board', 'ois-conversion-suite' ) . '</strong></td><td style="border:1px solid #ccd0d4;">' . esc_html( $dash_title ) . '</td></tr>';
		$body .= '<tr><td style="border:1px solid #ccd0d4;"><strong>' . esc_html__( 'Delivery format', 'ois-conversion-suite' ) . '</strong></td><td style="border:1px solid #ccd0d4;">' . esc_html( $delivery_label ) . '</td></tr>';
		$body .= '<tr><td style="border:1px solid #ccd0d4;"><strong>' . esc_html__( 'Cadence', 'ois-conversion-suite' ) . '</strong></td><td style="border:1px solid #ccd0d4;">' . esc_html( $cadence_label ) . '</td></tr>';
		$body .= '<tr><td style="border:1px solid #ccd0d4;"><strong>' . esc_html__( 'Preferred send time (site)', 'ois-conversion-suite' ) . '</strong></td><td style="border:1px solid #ccd0d4;">' . esc_html( $send_clock ) . '</td></tr>';
		$body .= '<tr><td style="border:1px solid #ccd0d4;"><strong>' . esc_html__( 'Date range (snapshot)', 'ois-conversion-suite' ) . '</strong></td><td style="border:1px solid #ccd0d4;">' . esc_html( $range['start_date'] . ' — ' . $range['end_date'] ) . '</td></tr>';
		$body .= '<tr><td style="border:1px solid #ccd0d4;"><strong>' . esc_html__( 'Preset', 'ois-conversion-suite' ) . '</strong></td><td style="border:1px solid #ccd0d4;">' . esc_html( $period_label ) . '</td></tr>';
		$body .= '<tr><td style="border:1px solid #ccd0d4;"><strong>' . esc_html__( 'Recipients', 'ois-conversion-suite' ) . '</strong></td><td style="border:1px solid #ccd0d4;">' . esc_html( $recip_summary ) . '</td></tr>';
		$body .= '<tr><td style="border:1px solid #ccd0d4;"><strong>' . esc_html__( 'Attachments', 'ois-conversion-suite' ) . '</strong></td><td style="border:1px solid #ccd0d4;">' . esc_html( $attach_detail ) . '</td></tr>';
		$body .= '<tr><td style="border:1px solid #ccd0d4;"><strong>' . esc_html__( 'Site timezone', 'ois-conversion-suite' ) . '</strong></td><td style="border:1px solid #ccd0d4;">' . esc_html( $tz ) . '</td></tr>';
		$body .= '</tbody></table>';

		if ( self::DELIVERY_PDF === $fmt ) {
			$body .= '<p>' . esc_html__( 'The attached HTML snapshot is optimized for printing: open it in a browser and use Print → Save as PDF.', 'ois-conversion-suite' ) . '</p>';
		}

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

		if ( $persist_schedule_updates ) {
			$job['last_sent'] = $now_ts;
			$job['next_run']  = self::schedule_next_run_for_job( $job, $now_ts );
		}
	}

	/**
	 * Build attachment basename fragment (no extension): dashboard slug + date span.
	 *
	 * @param string $dash_title Board title.
	 * @param string $dash_id    Board key.
	 * @param string $start_date Y-m-d.
	 * @param string $end_date   Y-m-d.
	 */
	private static function attachment_export_base_name( $dash_title, $dash_id, $start_date, $end_date ) {
		$slug = sanitize_file_name( $dash_title );
		if ( '' === $slug ) {
			$slug = sanitize_file_name( (string) $dash_id );
		}
		if ( '' === $slug ) {
			$slug = 'dashboard';
		}
		$slug = substr( $slug, 0, 60 );
		return sprintf( 'oiscl-report-%s-%s-to-%s', $slug, $start_date, $end_date );
	}

	/**
	 * Replace wp_tempnam `.tmp` paths so mail clients offer readable filenames (.csv / .html).
	 *
	 * @param string $tmp_path                 Absolute path.
	 * @param string $base_without_extension Base filename without extension.
	 * @param string $extension              Extension without dot (csv|html).
	 */
	private static function finalize_attachment_path( $tmp_path, $base_without_extension, $extension ) {
		if ( ! is_string( $tmp_path ) || '' === $tmp_path || ! is_readable( $tmp_path ) ) {
			return $tmp_path;
		}

		$ext = strtolower( preg_replace( '/[^a-z0-9]/', '', (string) $extension ) );
		if ( '' === $ext ) {
			$ext = 'dat';
		}

		$dir  = dirname( $tmp_path );
		$base = sanitize_file_name( $base_without_extension );
		if ( '' === $base ) {
			$base = 'oiscl-report';
		}

		$candidate   = $base . '.' . $ext;
		$unique_name = wp_unique_filename( $dir, $candidate );
		$new_path    = path_join( $dir, $unique_name );

		if ( $new_path === $tmp_path ) {
			return $tmp_path;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- rename may fail cross-volume; fall back to copy.
		if ( @rename( $tmp_path, $new_path ) ) {
			return $new_path;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( @copy( $tmp_path, $new_path ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $tmp_path );
			return $new_path;
		}

		return $tmp_path;
	}

	/**
	 * @param string               $fmt             Delivery format key.
	 * @param array<int,string>    $attachment_paths Temp files.
	 * @param bool                 $missing_export  No tabular data for this template/range.
	 */
	private static function describe_delivery_attachments( $fmt, array $attachment_paths, $missing_export ) {
		if ( self::DELIVERY_EMAIL_ONLY === $fmt ) {
			return __( 'None (summary in this email only).', 'ois-conversion-suite' );
		}
		if ( $missing_export ) {
			return __( 'No file — add tabular column blocks to this template to attach data.', 'ois-conversion-suite' );
		}
		if ( empty( $attachment_paths ) ) {
			return __( 'None', 'ois-conversion-suite' );
		}
		$parts = array();
		foreach ( $attachment_paths as $path ) {
			if ( ! is_string( $path ) || ! is_readable( $path ) ) {
				continue;
			}
			$bytes = filesize( $path );
			$fn    = basename( $path );
			if ( false !== $bytes && function_exists( 'size_format' ) ) {
				$parts[] = sprintf(
					/* translators: 1: filename, 2: formatted size */
					__( '%1$s (%2$s)', 'ois-conversion-suite' ),
					$fn,
					size_format( $bytes )
				);
			} else {
				$parts[] = $fn;
			}
		}
		return ! empty( $parts ) ? implode( ', ', $parts ) : __( 'None', 'ois-conversion-suite' );
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

			self::deliver_scheduled_job( $job, $dashboards, $now, true );
		}
		unset( $job );

		self::save_jobs_container( $box );
	}

	public static function handle_save_schedule() {
		if ( ! current_user_can( 'manage_ois_marketing' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ois-conversion-suite' ) );
		}
		check_admin_referer( 'oiscl_save_report_schedule', 'oiscl_report_sched_nonce' );

		$submit = isset( $_POST['oiscl_sched_submit'] ) ? sanitize_key( wp_unslash( $_POST['oiscl_sched_submit'] ) ) : 'save';

		$dashboard_id = isset( $_POST['dashboard_id'] ) ? sanitize_text_field( wp_unslash( $_POST['dashboard_id'] ) ) : '';
		$recipients_r = isset( $_POST['recipients'] ) ? wp_unslash( (string) $_POST['recipients'] ) : '';
		$cadence      = isset( $_POST['cadence'] ) ? sanitize_key( wp_unslash( $_POST['cadence'] ) ) : self::CADENCE_WEEKLY;
		$period       = isset( $_POST['period'] ) ? sanitize_key( wp_unslash( $_POST['period'] ) ) : self::PERIOD_YESTERDAY;
		$delivery_fmt = isset( $_POST['delivery_format'] ) ? sanitize_key( wp_unslash( $_POST['delivery_format'] ) ) : self::DELIVERY_CSV;
		if ( ! in_array( $delivery_fmt, self::allowed_delivery_formats(), true ) ) {
			$delivery_fmt = self::DELIVERY_CSV;
		}

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

		if ( 'now' === $submit ) {
			$draft = array(
				'dashboard_id'    => $dashboard_id,
				'dashboard_title' => isset( $dashboards[ $dashboard_id ]['title'] ) ? (string) $dashboards[ $dashboard_id ]['title'] : '',
				'recipients'      => $emails,
				'cadence'         => $cadence,
				'period'          => $period,
				'send_hour'       => $send_hour,
				'send_minute'     => $send_minute,
				'delivery_format' => $delivery_fmt,
				'enabled'         => 1,
			);
			self::deliver_scheduled_job( $draft, $dashboards, time(), false );
			wp_safe_redirect( admin_url( 'admin.php?page=oiscl-custom-reports&oiscl_sched_sent_now=1' ) );
			exit;
		}

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
			'delivery_format' => $delivery_fmt,
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

	public static function handle_send_report_job_now() {
		if ( ! current_user_can( 'manage_ois_marketing' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ois-conversion-suite' ) );
		}

		$job_id = isset( $_GET['job_id'] ) ? sanitize_key( wp_unslash( $_GET['job_id'] ) ) : '';
		check_admin_referer( 'oiscl_send_report_job_now_' . $job_id );

		if ( '' === $job_id ) {
			wp_safe_redirect( admin_url( 'admin.php?page=oiscl-custom-reports' ) );
			exit;
		}

		$box          = self::get_jobs_container();
		$dashboards   = get_option( 'oiscl_custom_dashboards', array() );
		if ( ! is_array( $dashboards ) ) {
			$dashboards = array();
		}

		$found = false;
		foreach ( $box['jobs'] as &$job ) {
			if ( ! isset( $job['id'] ) || (string) $job['id'] !== $job_id ) {
				continue;
			}
			self::deliver_scheduled_job( $job, $dashboards, time(), false );
			$found = true;
			break;
		}
		unset( $job );

		if ( ! $found ) {
			wp_safe_redirect( admin_url( 'admin.php?page=oiscl-custom-reports' ) );
			exit;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=oiscl-custom-reports&oiscl_sched_sent_now=1' ) );
		exit;
	}
}
