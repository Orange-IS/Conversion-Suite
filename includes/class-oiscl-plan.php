<?php
/**
 * Plan limits, addon registry, and license stubs (bundled addons later).
 *
 * @package OIS_Conversion_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OISCL_Plan {

	const EVENT_BLOCK_VIEW = '[Vista de Bloque]';
	const EVENT_BLOCK_LEGACY = '[Bloque]';
	const EVENT_PAGEVIEW   = '[Pageview]';
	const EVENT_ERROR_404  = '[Error 404]';

	/**
	 * Registered addons: slug => default active (core ships with click_tracker on).
	 *
	 * @return array<string, array{label:string,default:bool}>
	 */
	public static function get_addon_registry() {
		$registry = array(
			'click_tracker' => array(
				'label'   => __( 'OIS Click Tracker', 'ois-conversion-suite' ),
				'default' => true,
			),
			'utm_tracker'   => array(
				'label'   => __( 'OIS UTM Manager', 'ois-conversion-suite' ),
				'default' => true,
			),
			'analytics'     => array(
				'label'   => __( 'OIS Analytics', 'ois-conversion-suite' ),
				'default' => true,
			),
		);
		return apply_filters( 'oiscl_addon_registry', $registry );
	}

	/**
	 * @param string $slug Addon slug.
	 */
	public static function is_addon_active( $slug ) {
		$slug     = sanitize_key( $slug );
		$registry = self::get_addon_registry();
		if ( ! isset( $registry[ $slug ] ) ) {
			return false;
		}
		$enabled = get_option( 'oiscl_enabled_addons', null );
		if ( ! is_array( $enabled ) ) {
			$out = array();
			foreach ( $registry as $key => $meta ) {
				$out[ $key ] = ! empty( $meta['default'] );
			}
			return ! empty( $out[ $slug ] );
		}
		return ! empty( $enabled[ $slug ] );
	}

	public static function is_premium() {
		$lic = get_option( 'oiscl_general_settings', array() );
		$key = isset( $lic['api_key'] ) ? trim( (string) $lic['api_key'] ) : '';
		if ( '' === $key ) {
			return (bool) apply_filters( 'oiscl_is_premium', false );
		}
		$status = get_option( 'oiscl_license_status', '' );
		if ( 'valid' === $status ) {
			return true;
		}
		return (bool) apply_filters( 'oiscl_is_premium', false );
	}

	/**
	 * Lite: max days of metrics visible in reports (0 = unlimited on premium).
	 */
	const LITE_METRICS_RETENTION_DAYS = 60;

	public static function get_metrics_retention_days() {
		if ( self::is_premium() ) {
			return (int) apply_filters( 'oiscl_premium_metrics_retention_days', 0 );
		}
		return (int) apply_filters( 'oiscl_lite_metrics_retention_days', self::LITE_METRICS_RETENTION_DAYS );
	}

	/**
	 * Whether report queries should be capped to a rolling window.
	 */
	public static function has_metrics_retention_cap() {
		return self::get_metrics_retention_days() > 0;
	}

	/**
	 * Earliest Y-m-d allowed in Lite report ranges (inclusive).
	 *
	 * @param string|null $today Site date Y-m-d.
	 * @return string|null Null when unlimited.
	 */
	public static function get_metrics_earliest_date( $today = null ) {
		$days = self::get_metrics_retention_days();
		if ( $days <= 0 ) {
			return null;
		}
		if ( null === $today ) {
			$today = current_time( 'Y-m-d' );
		}
		return date( 'Y-m-d', strtotime( $today . ' - ' . ( $days - 1 ) . ' days' ) );
	}

	/**
	 * Clamp an inclusive report range to the active plan retention window.
	 *
	 * @param string $start_date Y-m-d.
	 * @param string $end_date   Y-m-d.
	 * @param string $today      Y-m-d.
	 * @return array{start_date:string,end_date:string,was_clamped:bool,earliest_date:string|null,retention_days:int}
	 */
	public static function clamp_report_dates( $start_date, $end_date, $today ) {
		$earliest = self::get_metrics_earliest_date( $today );
		$days     = self::get_metrics_retention_days();
		$clamped  = false;
		if ( $earliest && strtotime( $start_date ) < strtotime( $earliest ) ) {
			$start_date = $earliest;
			$clamped    = true;
		}
		if ( $earliest && strtotime( $end_date ) < strtotime( $earliest ) ) {
			$end_date = $earliest;
			$clamped  = true;
		}
		if ( strtotime( $start_date ) > strtotime( $end_date ) ) {
			$end_date = $start_date;
		}
		return array(
			'start_date'      => $start_date,
			'end_date'        => $end_date,
			'was_clamped'     => $clamped,
			'earliest_date'   => $earliest,
			'retention_days'  => $days,
		);
	}

	/**
	 * Apply retention cap fields to a resolve_user_report_dates() result.
	 *
	 * @param array<string,mixed> $date_ctx Date context.
	 * @param string              $today    Y-m-d.
	 * @return array<string,mixed>
	 */
	public static function apply_retention_to_date_ctx( array $date_ctx, $today ) {
		$cap = self::clamp_report_dates( $date_ctx['start_date'], $date_ctx['end_date'], $today );
		$date_ctx['start_date'] = $cap['start_date'];
		$date_ctx['end_date']   = $cap['end_date'];
		if ( ! empty( $cap['was_clamped'] ) ) {
			$date_ctx['retention_clamped']  = true;
			$date_ctx['retention_earliest'] = $cap['earliest_date'];
			$date_ctx['retention_days']     = $cap['retention_days'];
			if ( isset( $date_ctx['preset_label'] ) && ! empty( $cap['retention_days'] ) ) {
				$date_ctx['preset_label'] .= ' · ' . sprintf(
					/* translators: %d: retention day count */
					__( 'Lite %d-day cap', 'ois-conversion-suite' ),
					(int) $cap['retention_days']
				);
			}
		}
		return $date_ctx;
	}

	/**
	 * Lite recommendation: 3 tracked pages on free tier.
	 */
	public static function get_page_slot_limit() {
		if ( self::is_premium() ) {
			return (int) apply_filters( 'oiscl_premium_page_slot_limit', 50 );
		}
		return (int) apply_filters( 'oiscl_free_page_slot_limit', 3 );
	}

	/**
	 * Click Tracker report: free users only get overview tab content.
	 */
	public static function click_tracker_tab_allowed( $tab ) {
		$tab = sanitize_key( $tab );
		// QA: all tabs open while validating reports; re-enable free-tier gating before release.
		if ( apply_filters( 'oiscl_click_tracker_tab_gating_enabled', false ) ) {
			if ( self::is_premium() ) {
				return true;
			}
			return 'overview' === $tab;
		}
		return true;
	}

	/**
	 * SQL fragment: block view anchor literals (legacy + current).
	 *
	 * @return string e.g. '[Vista de Bloque]', '[Bloque]'
	 */
	public static function sql_block_view_anchor_in() {
		return "'" . esc_sql( self::EVENT_BLOCK_VIEW ) . "', '" . esc_sql( self::EVENT_BLOCK_LEGACY ) . "'";
	}

	/**
	 * Anchors excluded from "click/action" metrics (includes legacy block + Reading).
	 */
	public static function sql_exclude_actions_not_in() {
		return "'[Pageview]', " . self::sql_block_view_anchor_in() . ", 'Reading', '" . esc_sql( self::EVENT_ERROR_404 ) . "'";
	}

	/**
	 * Anchors used for dwell / retention averages.
	 */
	public static function sql_dwell_anchor_in() {
		return "'[Pageview]', " . self::sql_block_view_anchor_in();
	}
}
