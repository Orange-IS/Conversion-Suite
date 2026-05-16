<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX público: ingesta de clics/métricas y estadísticas de auditoría en vivo.
 */
class OISCL_Metrics_Ajax {

	/** @var bool|null */
	private static $table_has_utm_source_medium = null;

	/** @var bool|null */
	private static $table_has_screen_res = null;

	public function init() {
		add_action( 'wp_ajax_nopriv_oiscl_track_click', array( $this, 'handle_track_click' ) );
		add_action( 'wp_ajax_oiscl_track_click', array( $this, 'handle_track_click' ) );
		add_action( 'wp_ajax_oiscl_get_audit_stats', array( $this, 'handle_audit_stats' ) );
	}

	public function handle_track_click() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'oiscl_track_nonce' ) ) {
			wp_die();
		}

		global $wpdb;
		$table = $wpdb->prefix . 'oiscl_block_metrics';

		$ip         = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ua         = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$session_id = md5( $ip . $ua . date( 'Ymd' ) );

		$anchor  = isset( $_POST['anchor_text'] ) ? sanitize_text_field( wp_unslash( $_POST['anchor_text'] ) ) : '';
		$anchor  = OISCL_Tracking::normalize_anchor_for_storage( $anchor );
		$context = isset( $_POST['context_text'] ) ? sanitize_text_field( wp_unslash( $_POST['context_text'] ) ) : '';
		$country = 'Desconocido';
		$city    = 'Desconocido';

		if ( '[Pageview]' === $anchor || '[Error 404]' === $anchor ) {
			$geo_resp = wp_remote_get(
				'http://ip-api.com/json/' . rawurlencode( $ip ) . '?fields=status,country,regionName,city',
				array( 'timeout' => 2 )
			);
			if ( ! is_wp_error( $geo_resp ) ) {
				$geo = json_decode( wp_remote_retrieve_body( $geo_resp ) );
				if ( $geo && isset( $geo->status ) && 'success' === $geo->status ) {
					$country = $geo->country;
					$city    = $geo->city . ( ! empty( $geo->regionName ) ? ', ' . $geo->regionName : '' );
				}
			}
		}

		$os = 'Desconocido';
		if ( preg_match( '/windows/i', $ua ) ) {
			$os = 'Windows';
		} elseif ( preg_match( '/macintosh|mac os x/i', $ua ) ) {
			$os = 'Mac';
		} elseif ( preg_match( '/linux/i', $ua ) ) {
			$os = 'Linux';
		} elseif ( preg_match( '/android/i', $ua ) ) {
			$os = 'Android';
		} elseif ( preg_match( '/iphone|ipad|ipod/i', $ua ) ) {
			$os = 'iOS';
		}

		$browser = 'Desconocido';
		if ( preg_match( '/MSIE|Trident/i', $ua ) ) {
			$browser = 'Internet Explorer';
		} elseif ( preg_match( '/Edge/i', $ua ) ) {
			$browser = 'Edge';
		} elseif ( preg_match( '/Chrome/i', $ua ) ) {
			$browser = 'Chrome';
		} elseif ( preg_match( '/Safari/i', $ua ) ) {
			$browser = 'Safari';
		} elseif ( preg_match( '/Firefox/i', $ua ) ) {
			$browser = 'Firefox';
		} elseif ( preg_match( '/Opera/i', $ua ) ) {
			$browser = 'Opera';
		}

		$origin_url = isset( $_POST['origin_url'] ) ? esc_url_raw( wp_unslash( $_POST['origin_url'] ) ) : '';

		$parsed_url   = wp_parse_url( $origin_url );
		$query_params = array();
		if ( ! empty( $parsed_url['query'] ) ) {
			$raw = wp_parse_args( $parsed_url['query'] );
			foreach ( $raw as $qk => $qv ) {
				$query_params[ strtolower( (string) $qk ) ] = $qv;
			}
		}

		$final_utm_campaign = ! empty( $query_params['utm_campaign'] )
			? sanitize_text_field( $query_params['utm_campaign'] )
			: ( isset( $_POST['utm_campaign'] ) ? sanitize_text_field( wp_unslash( $_POST['utm_campaign'] ) ) : '' );
		$final_utm_term     = ! empty( $query_params['utm_term'] )
			? sanitize_text_field( $query_params['utm_term'] )
			: ( isset( $_POST['utm_term'] ) ? sanitize_text_field( wp_unslash( $_POST['utm_term'] ) ) : '' );
		$final_utm_source   = ! empty( $query_params['utm_source'] )
			? sanitize_text_field( $query_params['utm_source'] )
			: ( isset( $_POST['utm_source'] ) ? sanitize_text_field( wp_unslash( $_POST['utm_source'] ) ) : '' );
		$final_utm_medium   = ! empty( $query_params['utm_medium'] )
			? sanitize_text_field( $query_params['utm_medium'] )
			: ( isset( $_POST['utm_medium'] ) ? sanitize_text_field( wp_unslash( $_POST['utm_medium'] ) ) : '' );

		// DB columns are varchar(100); trim silently so inserts never fail on long tags.
		$final_utm_campaign = substr( $final_utm_campaign, 0, 100 );
		$final_utm_term     = substr( $final_utm_term, 0, 100 );
		$final_utm_source   = substr( $final_utm_source, 0, 100 );
		$final_utm_medium   = substr( $final_utm_medium, 0, 100 );

		$clean_url = strtok( $origin_url, '?' );
		$clean_url = rtrim( $clean_url, '/' );

		$data = array(
			'session_id'      => $session_id,
			'origin_url'      => $clean_url,
			'context_text'    => $context,
			'anchor_text'     => $anchor,
			'destination_url' => isset( $_POST['destination_url'] ) ? sanitize_text_field( wp_unslash( $_POST['destination_url'] ) ) : '',
			'instance_id'     => isset( $_POST['instance_id'] ) ? sanitize_text_field( wp_unslash( $_POST['instance_id'] ) ) : '',
			'time_spent'      => isset( $_POST['time_spent'] ) ? intval( $_POST['time_spent'] ) : 0,
			'country'         => $country,
			'city'            => $city,
			'os'              => $os,
			'browser'         => $browser,
			'device'          => isset( $_POST['device'] ) ? sanitize_text_field( wp_unslash( $_POST['device'] ) ) : 'Desktop',
			'language'        => isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : 'en',
			'is_bot'          => isset( $_POST['is_bot'] ) ? intval( $_POST['is_bot'] ) : 0,
			'traffic_source'  => isset( $_POST['traffic_source'] ) ? sanitize_text_field( wp_unslash( $_POST['traffic_source'] ) ) : 'Direct Traffic',
			'utm_campaign'    => $final_utm_campaign,
			'utm_term'        => $final_utm_term,
			'clicks'          => 1,
			'user_id'         => get_current_user_id(),
			'is_guest'        => is_user_logged_in() ? 0 : 1,
			'created_at'      => current_time( 'mysql' ),
		);

		if ( $this->metrics_table_has_screen_res( $table ) ) {
			$sr_raw = isset( $_POST['screen_res'] ) ? wp_unslash( (string) $_POST['screen_res'] ) : '';
			$sr     = sanitize_text_field( $sr_raw );
			if ( '' !== $sr && 'N/A' !== strtoupper( $sr ) ) {
				$data['screen_res'] = substr( $sr, 0, 48 );
			}
		}

		if ( $this->metrics_table_has_utm_source_medium( $table ) ) {
			$data['utm_source'] = $final_utm_source;
			$data['utm_medium'] = $final_utm_medium;
		}

		$wpdb->insert( $table, $data );
		wp_die();
	}

	public function handle_audit_stats() {
		check_ajax_referer( 'oiscl_track_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'No access' );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'oiscl_block_metrics';

		$origin_url = isset( $_POST['origin_url'] ) ? esc_url_raw( wp_unslash( $_POST['origin_url'] ) ) : '';
		$clean_url  = rtrim( strtok( $origin_url, '?' ), '/' );

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT anchor_text, context_text, COUNT(id) as total_hits
				FROM {$table}
				WHERE origin_url = %s
				GROUP BY anchor_text, context_text",
				$clean_url
			)
		);

		$stats = array();
		foreach ( $results as $row ) {
			$key           = md5( $row->anchor_text . $row->context_text );
			$stats[ $key ] = $row->total_hits;
		}

		wp_send_json_success( $stats );
	}

	/**
	 * @param string $table Full metrics table name including prefix.
	 */
	private function metrics_table_has_utm_source_medium( $table ) {
		if ( null !== self::$table_has_utm_source_medium ) {
			return self::$table_has_utm_source_medium;
		}
		global $wpdb;
		self::$table_has_utm_source_medium = ! empty( $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'utm_source'" ) );
		return self::$table_has_utm_source_medium;
	}

	/**
	 * @param string $table Full metrics table name including prefix.
	 */
	private function metrics_table_has_screen_res( $table ) {
		if ( null !== self::$table_has_screen_res ) {
			return self::$table_has_screen_res;
		}
		global $wpdb;
		self::$table_has_screen_res = ! empty( $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'screen_res'" ) );
		return self::$table_has_screen_res;
	}
}
