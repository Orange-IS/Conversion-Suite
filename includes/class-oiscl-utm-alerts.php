<?php
/**
 * UTM campaign alerts (drop vs prior period, zero clicks).
 *
 * @package OIS_Conversion_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OISCL_Utm_Alerts {

	const OPTION_SETTINGS = 'oiscl_utm_alert_settings';
	const OPTION_ACTIVE   = 'oiscl_utm_active_alerts';
	const CRON_HOOK       = 'oiscl_utm_daily_alerts';

	/**
	 * Bootstrap hooks.
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_daily' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_schedule' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_admin_notices' ) );
		add_action( 'admin_post_oiscl_save_utm_alerts', array( __CLASS__, 'handle_save_settings' ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function get_settings() {
		$defaults = array(
			'enabled'      => 1,
			'email'        => get_option( 'admin_email' ),
			'drop_pct'     => 30,
			'zero_hours'   => 48,
			'compare_days' => 7,
		);
		return wp_parse_args( (array) get_option( self::OPTION_SETTINGS, array() ), $defaults );
	}

	/**
	 * Schedule daily event if missing.
	 */
	public static function maybe_schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Persist settings from Settings → UTM Manager alerts form.
	 */
	public static function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ois-conversion-suite' ) );
		}
		check_admin_referer( 'oiscl_save_utm_alerts', 'oiscl_utm_alerts_nonce' );

		$settings = array(
			'enabled'      => isset( $_POST['utm_alerts_enabled'] ) ? 1 : 0,
			'email'        => isset( $_POST['utm_alerts_email'] ) ? sanitize_email( wp_unslash( $_POST['utm_alerts_email'] ) ) : '',
			'drop_pct'     => isset( $_POST['utm_alerts_drop_pct'] ) ? max( 5, min( 90, (int) $_POST['utm_alerts_drop_pct'] ) ) : 30,
			'zero_hours'   => isset( $_POST['utm_alerts_zero_hours'] ) ? max( 12, min( 168, (int) $_POST['utm_alerts_zero_hours'] ) ) : 48,
			'compare_days' => isset( $_POST['utm_alerts_compare_days'] ) ? max( 3, min( 30, (int) $_POST['utm_alerts_compare_days'] ) ) : 7,
		);
		update_option( self::OPTION_SETTINGS, $settings, false );

		wp_safe_redirect( admin_url( 'admin.php?page=oiscl-settings&tab=utmtracker&oiscl_alerts_saved=1' ) );
		exit;
	}

	/**
	 * Cron: evaluate and store alerts; optional email.
	 */
	public static function run_daily() {
		$items = self::compute_alerts();
		update_option(
			self::OPTION_ACTIVE,
			array(
				'checked_at' => time(),
				'items'      => $items,
			),
			false
		);

		$settings = self::get_settings();
		if ( empty( $settings['enabled'] ) || empty( $items ) || ! is_email( $settings['email'] ) ) {
			return;
		}

		$lines = array( __( 'OIS UTM Manager — campaign alerts', 'ois-conversion-suite' ), '' );
		foreach ( $items as $item ) {
			$lines[] = '• ' . $item['message'];
		}
		$lines[] = '';
		$lines[] = admin_url( 'admin.php?page=oiscl-utm-tracker' );
		wp_mail( $settings['email'], __( 'OIS UTM campaign alerts', 'ois-conversion-suite' ), implode( "\n", $lines ) );
	}

	/**
	 * @return array<int,array{type:string,campaign:string,label:string,message:string}>
	 */
	public static function compute_alerts() {
		global $wpdb;

		$settings = self::get_settings();
		if ( empty( $settings['enabled'] ) ) {
			return array();
		}

		$table_stats = $wpdb->prefix . 'oiscl_block_metrics';
		$table_refs  = $wpdb->prefix . 'oiscl_utm_references';
		$today       = wp_date( 'Y-m-d' );
		$days        = (int) $settings['compare_days'];
		$end         = $today;
		$start       = wp_date( 'Y-m-d', strtotime( $end . ' -' . ( $days - 1 ) . ' days' ) );
		$prev_end    = wp_date( 'Y-m-d', strtotime( $start . ' -1 day' ) );
		$prev_start  = wp_date( 'Y-m-d', strtotime( $prev_end . ' -' . ( $days - 1 ) . ' days' ) );
		$zero_since  = gmdate( 'Y-m-d H:i:s', time() - ( (int) $settings['zero_hours'] * HOUR_IN_SECONDS ) );
		$drop_pct    = (int) $settings['drop_pct'];

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$refs = $wpdb->get_results( "SELECT label_name, utm_campaign FROM `{$table_refs}` GROUP BY utm_campaign, label_name" );
		$alerts = array();

		foreach ( (array) $refs as $ref ) {
			$camp  = (string) $ref->utm_campaign;
			$label = (string) $ref->label_name;
			if ( '' === $camp ) {
				continue;
			}

			$curr = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(id) FROM `{$table_stats}` WHERE utm_campaign = %s AND anchor_text NOT IN ('[Pageview]', '[Bloque]', 'Reading', '[Vista de Bloque]') AND DATE(created_at) >= %s AND DATE(created_at) <= %s",
					$camp,
					$start,
					$end
				)
			);
			$prev = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(id) FROM `{$table_stats}` WHERE utm_campaign = %s AND anchor_text NOT IN ('[Pageview]', '[Bloque]', 'Reading', '[Vista de Bloque]') AND DATE(created_at) >= %s AND DATE(created_at) <= %s",
					$camp,
					$prev_start,
					$prev_end
				)
			);

			if ( OISCL_Utm_Alert_Rules::should_alert_drop( $prev, $curr, $drop_pct ) ) {
				$pct_drop = $prev > 0 ? round( ( 1 - ( $curr / $prev ) ) * 100 ) : 0;
				$alerts[] = array(
					'type'     => 'drop',
					'campaign' => $camp,
					'label'    => $label,
					'message'  => sprintf(
						/* translators: 1: campaign, 2: company, 3: percent drop, 4: days */
						__( 'Campaign %1$s (%2$s) fell %3$d%% in clicks vs the previous %4$d days.', 'ois-conversion-suite' ),
						$camp,
						$label,
						$pct_drop,
						$days
					),
				);
			}

			$recent = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(id) FROM `{$table_stats}` WHERE utm_campaign = %s AND created_at >= %s",
					$camp,
					$zero_since
				)
			);
			$had_prior = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(id) FROM `{$table_stats}` WHERE utm_campaign = %s AND created_at < %s AND created_at >= %s",
					$camp,
					$zero_since,
					gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) )
				)
			);
			if ( OISCL_Utm_Alert_Rules::should_alert_zero_window( $recent, $had_prior ) ) {
				$alerts[] = array(
					'type'     => 'zero',
					'campaign' => $camp,
					'label'    => $label,
					'message'  => sprintf(
						/* translators: 1: campaign, 2: company, 3: hours */
						__( 'Campaign %1$s (%2$s): 0 hits in the last %3$d hours (had traffic before).', 'ois-conversion-suite' ),
						$camp,
						$label,
						(int) $settings['zero_hours']
					),
				);
			}
		}

		return $alerts;
	}

	/**
	 * Admin notice on OIS screens when alerts exist.
	 */
	public static function render_admin_notices() {
		if ( ! current_user_can( 'view_ois_analytics' ) ) {
			return;
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( ! in_array( $page, array( 'oiscl-utm-tracker', 'oiscl-settings', 'oiscl-intro' ), true ) ) {
			return;
		}

		$stored = get_option( self::OPTION_ACTIVE, array() );
		$items  = isset( $stored['items'] ) && is_array( $stored['items'] ) ? $stored['items'] : array();
		if ( empty( $items ) ) {
			$items = self::compute_alerts();
		}
		if ( empty( $items ) ) {
			return;
		}

		$show = array_slice( $items, 0, 5 );
		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'UTM campaign alerts', 'ois-conversion-suite' ) . '</strong></p><ul style="margin:0 0 8px 18px;">';
		foreach ( $show as $item ) {
			echo '<li>' . esc_html( isset( $item['message'] ) ? $item['message'] : '' ) . '</li>';
		}
		echo '</ul>';
		if ( count( $items ) > 5 ) {
			echo '<p style="margin:0;font-size:12px;color:#646970;">' . esc_html( sprintf( __( '+%d more alerts.', 'ois-conversion-suite' ), count( $items ) - 5 ) ) . '</p>';
		}
		echo '</div>';
	}
}
