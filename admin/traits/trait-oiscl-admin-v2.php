<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait OISCL_Admin_V2_Trait {

	/**
	 * Only allow HTTP(S) URLs whose host matches this site (mitigates SSRF on remote fetch).
	 *
	 * @param string $url Raw URL.
	 * @return bool
	 */
	private function oiscl_v2_is_same_site_url( $url ) {
		$url = esc_url_raw( (string) $url );
		if ( '' === $url ) {
			return false;
		}
		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return false;
		}
		if ( empty( $parts['host'] ) ) {
			return false;
		}
		$target = strtolower( $parts['host'] );
		$hosts  = array();
		foreach ( array( home_url(), site_url() ) as $base ) {
			$h = wp_parse_url( $base, PHP_URL_HOST );
			if ( $h ) {
				$hosts[ strtolower( $h ) ] = true;
			}
		}
		return isset( $hosts[ $target ] );
	}

	// ==========================================
	// V2: INSPECTOR DOM EN TIEMPO REAL (MEJORADO)
	// ==========================================
	public function ajax_v2_inspect_url() {
		check_ajax_referer( 'oiscl_v2_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ) );
		}
		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		if ( empty( $url ) ) {
			wp_send_json_error( array( 'message' => 'empty_url' ) );
		}
		if ( ! $this->oiscl_v2_is_same_site_url( $url ) ) {
			wp_send_json_error( array( 'message' => 'url_not_allowed' ) );
		}

		$start_time = microtime( true );
		$response   = wp_remote_get(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => 3,
			)
		);
		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ) );
		}

		$load_time_ms = round( ( microtime( true ) - $start_time ) * 1000 );
		$html         = wp_remote_retrieve_body( $response );
		$dom          = new DOMDocument();
		@$dom->loadHTML( mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' ) );
		$xpath = new DOMXPath( $dom );

		$seo_title = $dom->getElementsByTagName( 'title' )->length > 0 ? $dom->getElementsByTagName( 'title' )->item( 0 )->nodeValue : 'Sin Título';
		$meta_desc = $xpath->query( '//meta[@name="description"]/@content' );
		$seo_desc  = $meta_desc->length > 0 ? $meta_desc->item( 0 )->nodeValue : 'Sin Meta Descripción';

		$saved_rules = get_option( 'oiscl_rules_' . md5( $url ), array() );
		$rules_map   = array();
		foreach ( $saved_rules as $r ) {
			$rules_map[ $r['hash'] ] = $r;
		}

		$nodes       = $xpath->query( '//*[self::h1 or self::h2 or self::h3 or self::a or self::button]' );
		$elements    = array();
		$noise_words = array( 'english', 'español', 'spanish', 'settings', 'onetap', 'reset' );

		foreach ( $nodes as $node ) {
			$tag  = strtoupper( $node->tagName );
			$text = trim( preg_replace( '/\s+/', ' ', $node->textContent ) );
			if ( mb_strlen( $text ) < 2 ) {
				continue;
			}

			$is_noise = false;
			foreach ( $noise_words as $nw ) {
				if ( strpos( strtolower( $text ), $nw ) !== false ) {
					$is_noise = true;
					break;
				}
			}
			if ( $is_noise ) {
				continue;
			}

			$hash       = md5( $text . $tag );
			$elements[] = array(
				'tag'           => $tag,
				'text'          => mb_substr( $text, 0, 55 ) . ( mb_strlen( $text ) > 55 ? '...' : '' ),
				'original_text' => $text,
				'hash'          => $hash,
				'structure'     => $node->hasAttribute( 'class' ) ? '.' . str_replace( ' ', '.', $node->getAttribute( 'class' ) ) : '',
				'saved_alias'   => isset( $rules_map[ $hash ] ) ? $rules_map[ $hash ]['alias'] : '',
				'saved_track'   => isset( $rules_map[ $hash ] ) ? (int) $rules_map[ $hash ]['track'] : 0,
			);
		}

		wp_send_json_success(
			array(
				'elements'  => $elements,
				'seo'       => array(
					'title' => $seo_title,
					'desc'  => $seo_desc,
				),
				'load_time' => $load_time_ms . ' ms',
			)
		);
	}

	// ==========================================
	// V2: GUARDAR CONFIGURACIONES Y ALIAS
	// ==========================================
	public function ajax_v2_save_settings() {
		check_ajax_referer( 'oiscl_v2_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ) );
		}
		$url   = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$rules = isset( $_POST['rules'] ) ? wp_unslash( $_POST['rules'] ) : array();

		if ( empty( $url ) ) {
			wp_send_json_error( array( 'message' => 'invalid_url' ) );
		}
		if ( ! $this->oiscl_v2_is_same_site_url( $url ) ) {
			wp_send_json_error( array( 'message' => 'url_not_allowed' ) );
		}

		$option_key = 'oiscl_rules_' . md5( $url );
		update_option( $option_key, $rules );

		wp_send_json_success( array( 'message' => 'saved' ) );
	}

	public function ajax_v2_get_site_content() {
		check_ajax_referer( 'oiscl_v2_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ) );
		}
		$search = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';

		$args  = array(
			'post_type'      => array( 'page', 'post', 'product', 'portfolio' ),
			'post_status'    => 'publish',
			's'              => $search,
			'posts_per_page' => 10,
		);
		$posts = get_posts( $args );

		$results = array();
		foreach ( $posts as $p ) {
			$results[] = array(
				'id'    => get_permalink( $p->ID ),
				'title' => $p->post_title,
				'type'  => strtoupper( $p->post_type ),
			);
		}
		wp_send_json_success( $results );
	}

	public function ajax_v2_delete_page() {
		check_ajax_referer( 'oiscl_v2_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ) );
		}
		$hash = isset( $_POST['hash'] ) ? sanitize_text_field( wp_unslash( $_POST['hash'] ) ) : '';
		if ( $hash ) {
			delete_option( 'oiscl_rules_' . $hash );
			$settings = get_option( 'oiscl_settings', array() );
			if ( ! is_array( $settings ) ) {
				$settings = array();
			}
			if ( isset( $settings['v2_pages'][ $hash ] ) ) {
				unset( $settings['v2_pages'][ $hash ] );
				update_option( 'oiscl_settings', $settings );
			}
			wp_send_json_success();
		}
		wp_send_json_error( array( 'message' => 'missing_hash' ) );
	}

	public function ajax_v2_get_content_list() {
		check_ajax_referer( 'oiscl_v2_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ) );
		}

		$types  = array(
			'page'    => 'Páginas',
			'post'    => 'Entradas',
			'product' => 'Productos',
		);
		$output = array();

		foreach ( $types as $type => $label ) {
			$posts = get_posts(
				array(
					'post_type'      => $type,
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'orderby'        => 'title',
					'order'          => 'ASC',
				)
			);
			if ( ! empty( $posts ) ) {
				$items = array();
				foreach ( $posts as $p ) {
					$items[] = array(
						'id'    => get_permalink( $p->ID ),
						'title' => $p->post_title,
					);
				}
				$output[] = array(
					'label' => $label,
					'items' => $items,
				);
			}
		}
		wp_send_json_success( $output );
	}

	public function ajax_v2_save_page_to_list() {
		check_ajax_referer( 'oiscl_v2_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ) );
		}
		$url   = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		if ( empty( $url ) || ! $this->oiscl_v2_is_same_site_url( $url ) ) {
			wp_send_json_error( array( 'message' => 'url_not_allowed' ) );
		}
		$hash = md5( $url );

		$settings = get_option( 'oiscl_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		if ( ! isset( $settings['v2_pages'] ) ) {
			$settings['v2_pages'] = array();
		}

		$settings['v2_pages'][ $hash ] = array(
			'url'   => $url,
			'title' => $title,
		);
		update_option( 'oiscl_settings', $settings );

		wp_send_json_success( array( 'hash' => $hash ) );
	}
}
