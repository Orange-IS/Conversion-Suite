<?php
/**
 * Per-page activity periods (slot on/off, global pause).
 *
 * @package OIS_Conversion_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OISCL_Activity {

	const OPTION_KEY = 'oiscl_activity_periods';

	/**
	 * @return array<string, array<int, array<string, string|null>>>
	 */
	public static function get_all_periods() {
		$data = get_option( self::OPTION_KEY, array() );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * @param int $post_id Post ID.
	 * @return array<int, array<string, string|null>>
	 */
	public static function get_periods_for_post( $post_id ) {
		$all = self::get_all_periods();
		$key = (string) (int) $post_id;
		if ( ! isset( $all[ $key ] ) || ! is_array( $all[ $key ] ) ) {
			return array();
		}
		return $all[ $key ];
	}

	/**
	 * @param int   $post_id  Post ID.
	 * @param array $periods  Period list.
	 */
	private static function save_periods_for_post( $post_id, array $periods ) {
		$all           = self::get_all_periods();
		$all[ (string) (int) $post_id ] = array_values( $periods );
		update_option( self::OPTION_KEY, $all, false );
	}

	/**
	 * @return string
	 */
	private static function now_mysql() {
		return current_time( 'mysql' );
	}

	/**
	 * @param int    $post_id Post ID.
	 * @param string $reason  slot_on|global_resume|bootstrap.
	 */
	public static function open_period( $post_id, $reason = 'slot_on' ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return;
		}
		$periods = self::get_periods_for_post( $post_id );
		foreach ( $periods as $idx => $period ) {
			if ( empty( $period['ended_at'] ) ) {
				$periods[ $idx ]['ended_at']    = self::now_mysql();
				$periods[ $idx ]['end_reason'] = 'superseded';
			}
		}
		$periods[] = array(
			'started_at'   => self::now_mysql(),
			'ended_at'     => null,
			'start_reason' => sanitize_key( $reason ),
			'end_reason'   => null,
		);
		self::save_periods_for_post( $post_id, $periods );
	}

	/**
	 * @param int    $post_id Post ID.
	 * @param string $reason  slot_off|global_pause.
	 */
	public static function close_period( $post_id, $reason = 'slot_off' ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return;
		}
		$periods = self::get_periods_for_post( $post_id );
		for ( $i = count( $periods ) - 1; $i >= 0; $i-- ) {
			if ( empty( $periods[ $i ]['ended_at'] ) ) {
				$periods[ $i ]['ended_at']    = self::now_mysql();
				$periods[ $i ]['end_reason']  = sanitize_key( $reason );
				self::save_periods_for_post( $post_id, $periods );
				return;
			}
		}
	}

	/**
	 * @param array $old_ids Previous target_urls.
	 * @param array $new_ids New target_urls.
	 */
	public static function sync_slots_change( array $old_ids, array $new_ids ) {
		$old = array_map( 'intval', $old_ids );
		$new = array_map( 'intval', $new_ids );
		$removed = array_diff( $old, $new );
		$added   = array_diff( $new, $old );
		foreach ( $removed as $pid ) {
			self::close_period( (int) $pid, 'slot_off' );
		}
	}

	/**
	 * Whether turning global tracker off closes activity periods (default true).
	 */
	public static function should_pause_on_global_off() {
		$settings = get_option( 'oiscl_settings', array() );
		if ( ! is_array( $settings ) ) {
			return true;
		}
		if ( ! array_key_exists( 'activity_pause_on_global_off', $settings ) ) {
			return true;
		}
		return ! empty( $settings['activity_pause_on_global_off'] );
	}

	/**
	 * @param bool  $enabled    Global tracker on.
	 * @param array $target_ids Active slot post IDs.
	 */
	public static function sync_global_toggle( $enabled, array $target_ids ) {
		$target_ids = array_map( 'intval', $target_ids );
		if ( ! $enabled && self::should_pause_on_global_off() ) {
			foreach ( $target_ids as $pid ) {
				if ( $pid > 0 && ! self::is_page_collecting( $pid ) ) {
					self::close_period( $pid, 'global_pause' );
				}
			}
		}
		foreach ( $target_ids as $pid ) {
			if ( $pid > 0 ) {
				self::sync_page_config_state( $pid );
			}
		}
	}

	/**
	 * Last period row (open or most recently ended).
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, string|null>|null
	 */
	public static function get_last_active_period( $post_id ) {
		$periods = self::get_periods_for_post( $post_id );
		if ( empty( $periods ) ) {
			return null;
		}
		return $periods[ count( $periods ) - 1 ];
	}

	/**
	 * @param array<string, string|null>|null $period Period row.
	 */
	public static function format_period_label( $period ) {
		if ( ! is_array( $period ) || empty( $period['started_at'] ) ) {
			return '—';
		}
		$start = mysql2date( 'M j, Y', $period['started_at'] );
		if ( empty( $period['ended_at'] ) ) {
			return $start . ' → ' . __( 'Now', 'ois-conversion-suite' );
		}
		$end = mysql2date( 'M j, Y', $period['ended_at'] );
		return $start . ' → ' . $end;
	}

	/**
	 * @param int        $post_id      Post ID.
	 * @param array|null $target_urls  Active slots.
	 * @param bool|null  $global_on    Master toggle.
	 */
	public static function is_page_in_slots( $post_id, $target_urls = null ) {
		if ( null === $target_urls ) {
			$settings    = get_option( 'oiscl_settings', array() );
			$target_urls = isset( $settings['target_urls'] ) && is_array( $settings['target_urls'] ) ? $settings['target_urls'] : array();
		}
		return in_array( (int) $post_id, array_map( 'intval', $target_urls ), true );
	}

	/**
	 * Whether the front end should collect metrics for this page.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function is_page_collecting( $post_id ) {
		if ( ! self::is_page_in_slots( $post_id ) ) {
			return false;
		}
		if ( 'automatic' === OISCL_Tracking::get_page_tracking_mode( $post_id ) ) {
			return OISCL_Tracking::is_automatic_global_enabled();
		}
		return self::page_has_saved_config( $post_id );
	}

	/**
	 * paused | global | custom | inactive
	 *
	 * @param int        $post_id     Post ID.
	 * @param array|null $target_urls Slots.
	 * @param bool|null  $global_on   Master on.
	 */
	public static function get_tracking_state( $post_id, $target_urls = null, $global_on = null ) {
		if ( null === $target_urls ) {
			$settings    = get_option( 'oiscl_settings', array() );
			$target_urls = isset( $settings['target_urls'] ) && is_array( $settings['target_urls'] ) ? $settings['target_urls'] : array();
		}
		if ( null === $global_on ) {
			$global_on = OISCL_Tracking::is_automatic_global_enabled();
		}
		if ( ! self::is_page_in_slots( $post_id, $target_urls ) ) {
			return 'inactive';
		}
		if ( 'automatic' === OISCL_Tracking::get_page_tracking_mode( $post_id ) ) {
			return $global_on ? 'global' : 'paused';
		}
		if ( self::page_has_saved_config( $post_id ) ) {
			return 'custom';
		}
		return 'paused';
	}

	/**
	 * @param int        $post_id      Post ID.
	 * @param array|null $target_urls  Active slots.
	 * @param bool|null  $global_on    Master toggle.
	 */
	public static function is_page_tracking_live( $post_id, $target_urls = null, $global_on = null ) {
		return self::is_page_collecting( $post_id );
	}

	/**
	 * @param int $post_id Post ID.
	 */
	public static function page_has_saved_config( $post_id ) {
		$cfg = OISCL_Tracking::get_page_config( $post_id );
		if ( ! $cfg || ! is_array( $cfg ) ) {
			return false;
		}
		return ! empty( $cfg['instances'] ) || ! empty( $cfg['scanned_at'] );
	}

	/**
	 * Post IDs with saved config but not in active slots.
	 *
	 * @param array $target_urls Active slot IDs.
	 * @return int[]
	 */
	public static function get_configured_paused_ids( array $target_urls ) {
		global $wpdb;
		$table = $wpdb->prefix . 'oiscl_page_settings';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from prefix.
		$rows = $wpdb->get_col( "SELECT post_id FROM {$table}" );
		if ( ! $rows ) {
			return array();
		}
		$target = array_map( 'intval', $target_urls );
		$out      = array();
		foreach ( $rows as $pid ) {
			$pid = (int) $pid;
			if ( $pid > 0 && ! in_array( $pid, $target, true ) && self::page_has_saved_config( $pid ) ) {
				$out[] = $pid;
			}
		}
		sort( $out );
		return $out;
	}

	/**
	 * One-time open period for pages already in slots before activity log existed.
	 */
	public static function maybe_bootstrap_periods() {
		if ( get_option( 'oiscl_activity_bootstrapped' ) ) {
			return;
		}
		$settings = get_option( 'oiscl_settings', array() );
		$target   = isset( $settings['target_urls'] ) && is_array( $settings['target_urls'] ) ? $settings['target_urls'] : array();
		foreach ( $target as $pid ) {
			$pid = (int) $pid;
			if ( $pid > 0 && empty( self::get_periods_for_post( $pid ) ) && self::is_page_collecting( $pid ) ) {
				self::open_period( $pid, 'bootstrap' );
			}
		}
		update_option( 'oiscl_activity_bootstrapped', 1, false );
	}

	public static function sync_page_config_state( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return;
		}
		if ( ! self::is_page_collecting( $post_id ) ) {
			self::close_period( $post_id, 'slot_off' );
			return;
		}
		$has_open = false;
		foreach ( self::get_periods_for_post( $post_id ) as $period ) {
			if ( empty( $period['ended_at'] ) ) {
				$has_open = true;
				break;
			}
		}
		if ( ! $has_open ) {
			self::open_period( $post_id, 'slot_on' );
		}
	}

	/**
	 * Default report range from open activity windows or the latest closed period.
	 *
	 * @return array{start:string,end:string,label:string}|null
	 */
	public static function get_default_report_range() {
		$today   = current_time( 'Y-m-d' );
		$all     = self::get_all_periods();
		$open_ts = array();
		$latest  = null;

		foreach ( $all as $periods ) {
			if ( ! is_array( $periods ) ) {
				continue;
			}
			foreach ( $periods as $period ) {
				if ( empty( $period['started_at'] ) ) {
					continue;
				}
				$start_ts = strtotime( $period['started_at'] );
				if ( empty( $period['ended_at'] ) ) {
					if ( $start_ts ) {
						$open_ts[] = $start_ts;
					}
					continue;
				}
				$end_ts = strtotime( $period['ended_at'] );
				if ( ! $start_ts || ! $end_ts ) {
					continue;
				}
				if ( null === $latest || $end_ts >= $latest['end_ts'] ) {
					$latest = array(
						'start_ts' => $start_ts,
						'end_ts'   => $end_ts,
						'start'    => date( 'Y-m-d', $start_ts ),
						'end'      => date( 'Y-m-d', $end_ts ),
					);
				}
			}
		}

		if ( ! empty( $open_ts ) ) {
			$start = date( 'Y-m-d', min( $open_ts ) );
			$earliest = OISCL_Plan::get_metrics_earliest_date( $today );
			if ( $earliest && strtotime( $start ) < strtotime( $earliest ) ) {
				$start = $earliest;
			}
			return array(
				'start' => $start,
				'end'   => $today,
				'label' => __( 'Last activity', 'ois-conversion-suite' ),
			);
		}
		if ( $latest ) {
			$start = $latest['start'];
			$earliest = OISCL_Plan::get_metrics_earliest_date( $today );
			if ( $earliest && strtotime( $start ) < strtotime( $earliest ) ) {
				$start = $earliest;
			}
			$end = $latest['end'];
			if ( $earliest && strtotime( $end ) < strtotime( $earliest ) ) {
				$end = $earliest;
			}
			return array(
				'start' => $start,
				'end'   => $end,
				'label' => __( 'Last activity', 'ois-conversion-suite' ),
			);
		}
		return null;
	}

	/**
	 * Shared admin report date resolution (dashboard, Click Tracker report, etc.).
	 *
	 * @param int                  $user_id User ID.
	 * @param string               $today   Y-m-d site date.
	 * @param array<string,mixed>  $request $_GET-like array.
	 * @return array{start_date:string,end_date:string,preset_label:string,preset:string}
	 */
	public static function resolve_user_report_dates( $user_id, $today, array $request = array() ) {
		$user_id = (int) $user_id;
		if ( isset( $request['preset'] ) ) {
			$preset = sanitize_key( (string) $request['preset'] );
			switch ( $preset ) {
				case 'yesterday':
					$start_date   = date( 'Y-m-d', strtotime( $today . ' -1 day' ) );
					$end_date     = $start_date;
					$preset_label = __( 'Yesterday', 'ois-conversion-suite' );
					break;
				case '7days':
					$start_date   = date( 'Y-m-d', strtotime( $today . ' -6 days' ) );
					$end_date     = $today;
					$preset_label = __( 'Last 7 Days', 'ois-conversion-suite' );
					break;
				case '30days':
					$start_date   = date( 'Y-m-d', strtotime( $today . ' -29 days' ) );
					$end_date     = $today;
					$preset_label = __( 'Last 30 Days', 'ois-conversion-suite' );
					break;
				case 'activity':
					$bounds = self::get_default_report_range();
					if ( $bounds ) {
						$start_date   = $bounds['start'];
						$end_date     = $bounds['end'];
						$preset_label = $bounds['label'];
					} else {
						$start_date   = $today;
						$end_date     = $today;
						$preset_label = __( 'Today', 'ois-conversion-suite' );
					}
					break;
				default:
					$start_date   = $today;
					$end_date     = $today;
					$preset_label = __( 'Today', 'ois-conversion-suite' );
					$preset       = 'today';
					break;
			}
			$date_ctx = OISCL_Plan::apply_retention_to_date_ctx(
				array(
					'start_date'   => $start_date,
					'end_date'     => $end_date,
					'preset_label' => $preset_label,
					'preset'       => $preset,
				),
				$today
			);
			set_transient(
				'oiscl_pref_' . $user_id,
				array(
					'start'  => $date_ctx['start_date'],
					'end'    => $date_ctx['end_date'],
					'label'  => $date_ctx['preset_label'],
					'preset' => $date_ctx['preset'],
				),
				DAY_IN_SECONDS
			);
			return $date_ctx;
		}

		if ( ! empty( $request['start_date'] ) && ! empty( $request['end_date'] ) ) {
			$start_date   = sanitize_text_field( (string) $request['start_date'] );
			$end_date     = sanitize_text_field( (string) $request['end_date'] );
			$preset_label = ( $start_date === $end_date )
				? __( 'Selected Day', 'ois-conversion-suite' )
				: __( 'Custom Range', 'ois-conversion-suite' );
			$date_ctx = OISCL_Plan::apply_retention_to_date_ctx(
				array(
					'start_date'   => $start_date,
					'end_date'     => $end_date,
					'preset_label' => $preset_label,
					'preset'       => 'custom',
				),
				$today
			);
			set_transient(
				'oiscl_pref_' . $user_id,
				array(
					'start'  => $date_ctx['start_date'],
					'end'    => $date_ctx['end_date'],
					'label'  => $date_ctx['preset_label'],
					'preset' => 'custom',
				),
				DAY_IN_SECONDS
			);
			return $date_ctx;
		}

		$saved = get_transient( 'oiscl_pref_' . $user_id );
		if ( is_array( $saved ) && ! empty( $saved['start'] ) && ! empty( $saved['end'] ) ) {
			return OISCL_Plan::apply_retention_to_date_ctx(
				array(
					'start_date'   => $saved['start'],
					'end_date'     => $saved['end'],
					'preset_label' => isset( $saved['label'] ) ? $saved['label'] : __( 'Custom Range', 'ois-conversion-suite' ),
					'preset'       => isset( $saved['preset'] ) ? $saved['preset'] : 'custom',
				),
				$today
			);
		}

		$bounds = self::get_default_report_range();
		if ( $bounds ) {
			return OISCL_Plan::apply_retention_to_date_ctx(
				array(
					'start_date'   => $bounds['start'],
					'end_date'     => $bounds['end'],
					'preset_label' => $bounds['label'],
					'preset'       => 'activity',
				),
				$today
			);
		}

		return OISCL_Plan::apply_retention_to_date_ctx(
			array(
				'start_date'   => $today,
				'end_date'     => $today,
				'preset_label' => __( 'Today', 'ois-conversion-suite' ),
				'preset'       => 'today',
			),
			$today
		);
	}

	/**
	 * HTML badge for tracking state.
	 *
	 * @param int        $post_id Post ID.
	 * @param array|null $target_urls Slots.
	 * @param bool|null  $global_on Master on.
	 */
	public static function render_tracking_badge_html( $post_id, $target_urls = null, $global_on = null ) {
		$state = self::get_tracking_state( $post_id, $target_urls, $global_on );
		switch ( $state ) {
			case 'global':
				return '<span class="oiscl-badge oiscl-badge--global" style="display:inline-block;font-size:10px;font-weight:700;text-transform:uppercase;padding:2px 8px;border-radius:10px;background:#edfaef;color:#1e7e34;">' . esc_html__( 'Global', 'ois-conversion-suite' ) . '</span>';
			case 'custom':
				return '<span class="oiscl-badge oiscl-badge--custom" style="display:inline-block;font-size:10px;font-weight:700;text-transform:uppercase;padding:2px 8px;border-radius:10px;background:#e8f4fd;color:#135e96;">' . esc_html__( 'Custom', 'ois-conversion-suite' ) . '</span>';
			case 'inactive':
				return '<span class="oiscl-badge oiscl-badge--inactive" style="display:inline-block;font-size:10px;font-weight:700;text-transform:uppercase;padding:2px 8px;border-radius:10px;background:#f0f0f1;color:#646970;">' . esc_html__( 'Inactive', 'ois-conversion-suite' ) . '</span>';
			default:
				return '<span class="oiscl-badge oiscl-badge--paused" style="display:inline-block;font-size:10px;font-weight:700;text-transform:uppercase;padding:2px 8px;border-radius:10px;background:#fff3e0;color:#b45309;">' . esc_html__( 'Paused', 'ois-conversion-suite' ) . '</span>';
		}
	}
}
