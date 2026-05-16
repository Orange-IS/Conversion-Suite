<?php
/**
 * Per-page Click Tracker configuration (instances, auto-config, frontend payload).
 *
 * @package OIS_Conversion_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OISCL_Tracking {

	const CONFIG_VERSION = 2;

	const MAX_CONFIG_REVISIONS = 20;

	/**
	 * @return int[]
	 */
	public static function get_tracked_post_ids() {
		$settings = get_option( 'oiscl_settings', array() );
		$ids      = isset( $settings['target_urls'] ) && is_array( $settings['target_urls'] ) ? $settings['target_urls'] : array();
		$out      = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$out[] = $id;
			}
		}
		return array_values( array_unique( $out ) );
	}

	public static function is_post_tracked( $post_id ) {
		return in_array( (int) $post_id, self::get_tracked_post_ids(), true );
	}

	/**
	 * @param int $post_id Post ID.
	 * @return array|null
	 */
	public static function get_page_config( $post_id ) {
		global $wpdb;
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return null;
		}
		$row = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT active_tags FROM {$wpdb->prefix}oiscl_page_settings WHERE post_id = %d",
				$post_id
			)
		);
		if ( ! $row ) {
			return null;
		}
		$decoded = json_decode( $row, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}
		if ( isset( $decoded['instances'] ) && is_array( $decoded['instances'] ) ) {
			return $decoded;
		}
		return null;
	}

	/**
	 * @param int   $post_id Post ID.
	 * @param array $config  Full config array.
	 */
	public static function save_page_config( $post_id, array $config ) {
		global $wpdb;
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return;
		}
		self::ensure_config_meta( $config );
		$config['version'] = self::CONFIG_VERSION;
		$wpdb->replace(
			$wpdb->prefix . 'oiscl_page_settings',
			array(
				'post_id'     => $post_id,
				'active_tags' => wp_json_encode( $config ),
			),
			array( '%d', '%s' )
		);
	}

	/**
	 * Build instance id from tag + text + index.
	 *
	 * @param string $tag  Element tag.
	 * @param string $text Normalized text.
	 * @param int    $index Sibling index.
	 */
	public static function instance_hash( $tag, $text, $index = 0 ) {
		return substr( md5( strtoupper( $tag ) . '|' . self::normalize_text( $text ) . '|' . (int) $index ), 0, 16 );
	}

	public static function normalize_text( $text ) {
		$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $text ) ) );
		return mb_substr( $text, 0, 120 );
	}

	/**
	 * Noise tier for UI filters: none (clean CTA) → low → medium → high (obvious junk).
	 *
	 * @param string        $text Label.
	 * @param string        $dest href.
	 * @param DOMNode|null  $node Optional DOM node from scan.
	 * @return string none|low|medium|high
	 */
	public static function classify_noise_tier( $text, $dest = '', $node = null ) {
		$text  = self::normalize_text( $text );
		$lower = strtolower( $text );
		$dest  = strtolower( (string) $dest );

		if ( mb_strlen( $text ) < 2 ) {
			return 'high';
		}

		if ( self::is_definite_noise_label( $lower ) ) {
			return 'high';
		}

		if ( $dest && ( false !== strpos( $dest, 'translate.goog' ) || false !== strpos( $dest, 'gtranslate' ) ) ) {
			return 'medium';
		}

		if ( $node instanceof DOMElement && self::is_inside_noise_widget_container( $node ) ) {
			return 'medium';
		}

		if ( $node instanceof DOMElement ) {
			$role = strtolower( $node->getAttribute( 'role' ) );
			if ( in_array( $role, array( 'menuitem', 'presentation' ), true ) && mb_strlen( $text ) < 28 ) {
				return 'medium';
			}
			$blob = strtolower(
				$node->getAttribute( 'aria-label' ) . ' ' .
				$node->getAttribute( 'title' ) . ' ' .
				$node->getAttribute( 'class' ) . ' ' .
				$node->getAttribute( 'id' )
			);
			foreach ( array( 'accessibe', 'acsb', 'userway', 'onetap', 'uw-', 'a11y', 'wpml-ls', 'lang-item' ) as $needle ) {
				if ( false !== strpos( $blob, $needle ) ) {
					return 'medium';
				}
			}
		}

		if ( $dest && in_array( $dest, array( '#', '#0', 'javascript:void(0)', 'javascript:;', 'javascript:void(0);' ), true ) ) {
			return 'medium';
		}

		$low_exact = array( 'menu', 'close', 'toggle', 'hamburger', 'back to top', 'scroll to top', 'open', 'next', 'prev', 'previous' );
		if ( in_array( $lower, $low_exact, true ) ) {
			return 'low';
		}
		if ( preg_match( '/\b(menu|close|toggle|gallery)\b/i', $lower ) && mb_strlen( $text ) < 22 ) {
			return 'low';
		}

		return 'none';
	}

	/**
	 * UI filter presets: All | Low | Medium | High (stricter hides more tiers).
	 *
	 * @param array  $inst  Instance with optional noise_tier.
	 * @param string $level all|low|medium|high
	 */
	public static function instance_visible_for_filter( array $inst, $level ) {
		$level = sanitize_key( $level );
		if ( 'all' === $level || '' === $level ) {
			return true;
		}
		$tier = isset( $inst['noise_tier'] ) ? sanitize_key( $inst['noise_tier'] ) : 'none';
		if ( ! in_array( $tier, array( 'none', 'low', 'medium', 'high' ), true ) ) {
			$tier = 'none';
		}
		$rank = array( 'none' => 0, 'low' => 1, 'medium' => 2, 'high' => 3 );
		$hide_from = array(
			'low'    => 3,
			'medium' => 2,
			'high'   => 1,
		);
		if ( ! isset( $hide_from[ $level ] ) ) {
			return true;
		}
		return $rank[ $tier ] < $hide_from[ $level ];
	}

	/** @deprecated Use classify_noise_tier + instance_visible_for_filter. */
	public static function is_noise_click_candidate( $text, $dest = '', $node = null ) {
		return 'high' === self::classify_noise_tier( $text, $dest, $node );
	}

	/**
	 * Language names and accessibility-widget labels (not generic nav/CTA text).
	 *
	 * @param string $lower Already lowercased label.
	 */
	private static function is_definite_noise_label( $lower ) {
		if ( preg_match( '/^[a-z]{2,3}$/i', $lower ) ) {
			return true;
		}

		$noise_phrases = array(
			'english', 'deutsch', 'german', 'francais', 'français', 'french', 'espanol', 'español', 'spanish',
			'italiano', 'italian', 'portugues', 'português', 'portuguese', 'nederlands', 'dutch', 'polski', 'polish',
			'russian', 'chinese', 'japanese', 'korean', 'arabic', 'svenska', 'norsk', 'dansk', 'suomi',
			'turkce', 'türkçe', 'hrvatski', 'romana', 'română', 'magyar',
			'highlight content', 'stop animations', 'pause animations', 'hide images', 'larger text', 'readable font',
			'reading guide', 'screen reader', 'accessibility menu', 'accessibility options', 'open accessibility',
			'increase contrast', 'high contrast', 'adjust colors', 'keyboard navigation', 'page structure',
			'skip to content', 'skip to main', 'back to top', 'scroll to top',
			'accept all cookies', 'reject all', 'cookie settings', 'privacy settings',
		);

		foreach ( $noise_phrases as $phrase ) {
			if ( false !== strpos( $lower, $phrase ) ) {
				return true;
			}
		}

		// Whole-label widget / chrome controls (not "Contact" in a sentence).
		$exact = array(
			'menu', 'close', 'settings', 'reset', 'consent', 'cookies', 'cookie', 'privacy', 'a11y',
			'accessibility', 'accessible', 'hamburger',
		);
		return in_array( $lower, $exact, true );
	}

	/**
	 * Ancestor is a known language switcher, cookie bar, or a11y overlay — not the whole page.
	 *
	 * @param DOMElement $node Link or button.
	 */
	private static function is_inside_noise_widget_container( DOMElement $node ) {
		$patterns = array(
			'wpml', 'polylang', 'pll-', 'lang-switch', 'language-switcher', 'language-list', 'trp-', 'gtranslate',
			'accessibe', 'userway', 'acsb', 'acsb-trigger', 'a11y-', 'onetap', 'cmplz', 'cc-window', 'cookie-notice',
			'cookie-law', 'gdpr', 'consent-banner', 'pojo', 'equalweb', 'recite', 'uw-',
		);
		$parent   = $node->parentNode;
		$depth    = 0;
		while ( $parent instanceof DOMElement && $depth < 12 ) {
			$hay = strtolower( $parent->getAttribute( 'id' ) . ' ' . $parent->getAttribute( 'class' ) );
			foreach ( $patterns as $needle ) {
				if ( '' !== $hay && false !== strpos( $hay, $needle ) ) {
					return true;
				}
			}
			$parent = $parent->parentNode;
			++$depth;
		}
		return false;
	}

	/**
	 * @param array $inst Instance row from saved config.
	 */
	public static function resolve_instance_noise_tier( array $inst ) {
		if ( ! empty( $inst['noise_tier'] ) ) {
			$tier = sanitize_key( $inst['noise_tier'] );
			if ( in_array( $tier, array( 'none', 'low', 'medium', 'high' ), true ) ) {
				return $tier;
			}
		}
		if ( ! in_array( strtoupper( isset( $inst['tag'] ) ? $inst['tag'] : '' ), array( 'A', 'BUTTON' ), true ) ) {
			return 'none';
		}
		return self::classify_noise_tier(
			isset( $inst['text'] ) ? $inst['text'] : '',
			isset( $inst['dest'] ) ? $inst['dest'] : '',
			null
		);
	}

	/** @deprecated Use instance_visible_for_filter. */
	public static function is_noise_instance( array $inst ) {
		return ! self::instance_visible_for_filter( $inst, 'low' );
	}

	/**
	 * Aggressive auto-config from live DOM (scan) or audit arrays.
	 *
	 * @param DOMXPath $xpath DOM xpath.
	 * @return array{instances:array, auto_applied:bool, scanned_at:string}
	 */
	/**
	 * @param array  $instances Instances list (by ref).
	 * @param array  $counters  Per-tag counters (by ref).
	 * @param string $tag       Element tag.
	 * @param string $text      Visible label.
	 * @param string $dest      Destination or action hint.
	 * @param DOMNode|null $node DOM node when available.
	 * @param bool|null   $force_track_click Override auto noise disable.
	 * @param string      $source            dom|ninja_forms|gravity_forms|wpforms|contact_form_7
	 */
	private static function push_click_instance( array &$instances, array &$counters, $tag, $text, $dest = '', $node = null, $force_track_click = null, $source = 'dom' ) {
		$text = self::normalize_text( $text );
		if ( mb_strlen( $text ) < 2 ) {
			return;
		}
		$tag_key    = strtoupper( $tag );
		$noise_tier = self::classify_noise_tier( $text, $dest, $node );
		$track_click = true;
		if ( null !== $force_track_click ) {
			$track_click = (bool) $force_track_click;
		} elseif ( in_array( $noise_tier, array( 'medium', 'high' ), true ) ) {
			$track_click = false;
		}
		if ( ! isset( $counters[ $tag_key ] ) ) {
			$counters[ $tag_key ] = 0;
		}
		$index = $counters[ $tag_key ]++;
		$id    = self::instance_hash( $tag_key, $text, $index );
		$alias = $tag_key . ': ' . mb_substr( $text, 0, 48 );
		if ( 'A' === $tag_key ) {
			$alias = 'Link: ' . mb_substr( $text, 0, 40 );
		} elseif ( 'BUTTON' === $tag_key ) {
			$alias = 'Button: ' . mb_substr( $text, 0, 40 );
		} elseif ( 'INPUT' === $tag_key ) {
			$alias = 'Submit: ' . mb_substr( $text, 0, 40 );
		}
		$instances[] = array(
			'id'          => $id,
			'tag'         => $tag_key,
			'text'        => $text,
			'alias'       => $alias,
			'track_view'  => false,
			'track_click' => (bool) $track_click,
			'dest'        => substr( (string) $dest, 0, 200 ),
			'index'       => $index,
			'noise_tier'  => $noise_tier,
			'source'      => sanitize_key( $source ) ?: 'dom',
		);
	}

	/**
	 * Human label for plugin-sourced instances (JS-rendered forms).
	 *
	 * @param string $source Instance source key.
	 */
	public static function instance_source_badge_label( $source ) {
		$map = array(
			'ninja_forms'      => 'Ninja Forms',
			'gravity_forms'    => 'Gravity Forms',
			'wpforms'          => 'WPForms',
			'contact_form_7'   => 'Contact Form 7',
		);
		$source = sanitize_key( $source );
		return isset( $map[ $source ] ) ? $map[ $source ] : '';
	}

	/**
	 * Compare instance lists after a rescan.
	 *
	 * @param array $old_instances Previous instances.
	 * @param array $new_instances New instances.
	 * @return array{added:int,removed:int,added_labels:array,removed_labels:array}
	 */
	public static function compute_instance_diff( array $old_instances, array $new_instances ) {
		$old_map = array();
		foreach ( $old_instances as $inst ) {
			if ( ! empty( $inst['id'] ) ) {
				$old_map[ $inst['id'] ] = isset( $inst['alias'] ) ? $inst['alias'] : ( isset( $inst['text'] ) ? $inst['text'] : $inst['id'] );
			}
		}
		$new_map = array();
		foreach ( $new_instances as $inst ) {
			if ( ! empty( $inst['id'] ) ) {
				$new_map[ $inst['id'] ] = isset( $inst['alias'] ) ? $inst['alias'] : ( isset( $inst['text'] ) ? $inst['text'] : $inst['id'] );
			}
		}
		$added_ids   = array_diff( array_keys( $new_map ), array_keys( $old_map ) );
		$removed_ids = array_diff( array_keys( $old_map ), array_keys( $new_map ) );
		$added_labels = array();
		foreach ( $added_ids as $id ) {
			$added_labels[] = $new_map[ $id ];
		}
		$removed_labels = array();
		foreach ( $removed_ids as $id ) {
			$removed_labels[] = $old_map[ $id ];
		}
		return array(
			'added'          => count( $added_ids ),
			'removed'        => count( $removed_ids ),
			'added_labels'   => array_slice( $added_labels, 0, 8 ),
			'removed_labels' => array_slice( $removed_labels, 0, 8 ),
		);
	}

	/**
	 * @param array $instances Instances list.
	 * @return array{0:array,1:array} existing keys map and per-tag counters.
	 */
	private static function augment_state_from_instances( array $instances ) {
		$existing = array();
		$counters = array();
		foreach ( $instances as $inst ) {
			$tk = strtoupper( isset( $inst['tag'] ) ? $inst['tag'] : 'X' );
			if ( ! isset( $counters[ $tk ] ) ) {
				$counters[ $tk ] = 0;
			}
			$idx = isset( $inst['index'] ) ? (int) $inst['index'] : 0;
			if ( $idx >= $counters[ $tk ] ) {
				$counters[ $tk ] = $idx + 1;
			}
			$key = $tk . '|' . self::normalize_text( isset( $inst['text'] ) ? $inst['text'] : '' );
			$existing[ $key ] = true;
		}
		return array( $existing, $counters );
	}

	/**
	 * @param array  $instances By ref.
	 * @param array  $existing  By ref.
	 * @param array  $counters  By ref.
	 * @param string $tag       INPUT|BUTTON.
	 * @param string $label     Button label.
	 * @param string $source    Plugin source key.
	 */
	private static function augment_add_form_button( array &$instances, array &$existing, array &$counters, $tag, $label, $source ) {
		$label = self::normalize_text( $label );
		if ( mb_strlen( $label ) < 2 ) {
			$label = 'Submit';
		}
		$tag = strtoupper( $tag );
		$key = $tag . '|' . $label;
		if ( isset( $existing[ $key ] ) ) {
			return;
		}
		self::push_click_instance( $instances, $counters, $tag, $label, '[Form Submit]', null, true, $source );
		$existing[ $key ] = true;
	}

	public static function build_instances_from_xpath( DOMXPath $xpath ) {
		$instances = array();
		$counters  = array();

		$add = function ( $tag, $text, $track_view, $track_click, $dest = '', $node = null ) use ( &$instances, &$counters ) {
			$text = self::normalize_text( $text );
			if ( mb_strlen( $text ) < 2 ) {
				return;
			}
			$tag_key   = strtoupper( $tag );
			$noise_tier = 'none';
			if ( $track_click ) {
				$noise_tier = self::classify_noise_tier( $text, $dest, $node );
				// Auto-enable only clean / mild items; user can enable more via UI (All filter).
				if ( in_array( $noise_tier, array( 'medium', 'high' ), true ) ) {
					$track_click = false;
				}
			}
			if ( ! isset( $counters[ $tag_key ] ) ) {
				$counters[ $tag_key ] = 0;
			}
			$index = $counters[ $tag_key ]++;
			$id    = self::instance_hash( $tag_key, $text, $index );
			$alias = $tag_key . ': ' . mb_substr( $text, 0, 48 );
			if ( 'A' === $tag_key || 'BUTTON' === $tag_key ) {
				$alias = ( 'A' === $tag_key ? 'Link' : 'Button' ) . ': ' . mb_substr( $text, 0, 40 );
			}
			$instances[] = array(
				'id'          => $id,
				'tag'         => $tag_key,
				'text'        => $text,
				'alias'       => $alias,
				'track_view'  => (bool) $track_view,
				'track_click' => (bool) $track_click,
				'dest'        => substr( (string) $dest, 0, 200 ),
				'index'       => $index,
				'noise_tier'  => $noise_tier,
			);
		};

		foreach ( $xpath->query( '//h1' ) as $node ) {
			$add( 'H1', $node->textContent, true, false );
		}
		foreach ( $xpath->query( '//h2' ) as $node ) {
			$add( 'H2', $node->textContent, true, false );
		}
		foreach ( $xpath->query( '//h3' ) as $node ) {
			$add( 'H3', $node->textContent, true, false );
		}
		foreach ( $xpath->query( '//section' ) as $node ) {
			$snippet = self::normalize_text( $node->textContent );
			if ( mb_strlen( $snippet ) > 40 ) {
				$add( 'SECTION', mb_substr( $snippet, 0, 60 ), true, false );
			}
		}
		foreach ( $xpath->query( '//a | //button' ) as $node ) {
			$tag  = strtoupper( $node->tagName );
			$dest = ( 'A' === $tag && $node instanceof DOMElement ) ? $node->getAttribute( 'href' ) : '';
			$add( $tag, $node->textContent, false, true, $dest, $node );
		}
		foreach ( $xpath->query( '//input[@type="submit"] | //input[@type="button"]' ) as $node ) {
			if ( ! $node instanceof DOMElement ) {
				continue;
			}
			$label = $node->getAttribute( 'value' );
			if ( '' === trim( $label ) ) {
				$label = $node->getAttribute( 'aria-label' );
			}
			self::push_click_instance( $instances, $counters, 'INPUT', $label, '[Form Submit]', $node );
		}

		return array(
			'instances'    => $instances,
			'auto_applied' => true,
			'scanned_at'   => current_time( 'mysql' ),
			'dom_hash'     => substr( md5( wp_json_encode( $instances ) ), 0, 16 ),
		);
	}

	/**
	 * Ninja Forms and similar plugins render submit buttons via JavaScript — not in fetched HTML.
	 * When the form container or shortcode is present, read field labels from Ninja Forms API.
	 *
	 * @param string $html    Fetched front-end HTML.
	 * @param int    $post_id Scanned post ID.
	 * @param array  $instances Existing instances from XPath scan.
	 * @return array
	 */
	public static function augment_instances_from_page( $html, $post_id, array $instances ) {
		list( $existing, $counters ) = self::augment_state_from_instances( $instances );
		$post    = get_post( (int) $post_id );
		$content = ( $post && ! empty( $post->post_content ) ) ? (string) $post->post_content : '';

		$instances = self::augment_ninja_forms( (string) $html, $content, $instances, $existing, $counters );
		$instances = self::augment_gravity_forms( (string) $html, $content, $instances, $existing, $counters );
		$instances = self::augment_wpforms( (string) $html, $content, $instances, $existing, $counters );
		$instances = self::augment_contact_form_7( (string) $html, $content, $instances, $existing, $counters );

		return $instances;
	}

	private static function augment_ninja_forms( $html, $content, array $instances, array &$existing, array &$counters ) {
		$form_ids = array();
		if ( preg_match_all( '/\bnf-form-(\d+)-cont\b/', $html, $matches ) ) {
			$form_ids = array_map( 'intval', $matches[1] );
		}
		if ( preg_match_all( '/\[ninja_form[^\]]*id\s*=\s*["\']?(\d+)/i', $content, $sc ) ) {
			$form_ids = array_merge( $form_ids, array_map( 'intval', $sc[1] ) );
		}
		if ( preg_match_all( '/\[ninja_forms[^\]]*id\s*=\s*["\']?(\d+)/i', $content, $sc2 ) ) {
			$form_ids = array_merge( $form_ids, array_map( 'intval', $sc2[1] ) );
		}
		$form_ids = array_values( array_unique( array_filter( $form_ids ) ) );
		if ( empty( $form_ids ) || ! function_exists( 'Ninja_Forms' ) ) {
			return $instances;
		}
		foreach ( $form_ids as $form_id ) {
			try {
				$nf_form = Ninja_Forms()->form( (int) $form_id );
				if ( ! $nf_form ) {
					continue;
				}
				$fields = $nf_form->get_fields();
				if ( ! is_array( $fields ) ) {
					continue;
				}
				foreach ( $fields as $field ) {
					if ( ! is_object( $field ) || ! method_exists( $field, 'get_settings' ) ) {
						continue;
					}
					$settings = $field->get_settings();
					$type     = isset( $settings['type'] ) ? strtolower( (string) $settings['type'] ) : '';
					if ( ! in_array( $type, array( 'submit', 'button' ), true ) ) {
						continue;
					}
					$label = isset( $settings['label'] ) ? $settings['label'] : 'Submit';
					$tag   = ( 'submit' === $type ) ? 'INPUT' : 'BUTTON';
					self::augment_add_form_button( $instances, $existing, $counters, $tag, $label, 'ninja_forms' );
				}
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				continue;
			}
		}
		return $instances;
	}

	private static function augment_gravity_forms( $html, $content, array $instances, array &$existing, array &$counters ) {
		$form_ids = array();
		if ( preg_match_all( '/\bgform_wrapper_(\d+)\b/', $html, $matches ) ) {
			$form_ids = array_map( 'intval', $matches[1] );
		}
		if ( preg_match_all( '/\[gravityform[^\]]*id\s*=\s*["\']?(\d+)/i', $content, $sc ) ) {
			$form_ids = array_merge( $form_ids, array_map( 'intval', $sc[1] ) );
		}
		$form_ids = array_values( array_unique( array_filter( $form_ids ) ) );
		if ( empty( $form_ids ) || ! class_exists( 'GFAPI' ) ) {
			return $instances;
		}
		foreach ( $form_ids as $form_id ) {
			$form = GFAPI::get_form( (int) $form_id );
			if ( is_wp_error( $form ) || empty( $form['fields'] ) || ! is_array( $form['fields'] ) ) {
				continue;
			}
			foreach ( $form['fields'] as $field ) {
				if ( ! is_object( $field ) || empty( $field->type ) ) {
					continue;
				}
				if ( ! in_array( $field->type, array( 'submit', 'button' ), true ) ) {
					continue;
				}
				$label = ! empty( $field->label ) ? $field->label : ( ! empty( $field->defaultText ) ? $field->defaultText : 'Submit' );
				$tag   = ( 'submit' === $field->type ) ? 'INPUT' : 'BUTTON';
				self::augment_add_form_button( $instances, $existing, $counters, $tag, $label, 'gravity_forms' );
			}
		}
		return $instances;
	}

	private static function augment_wpforms( $html, $content, array $instances, array &$existing, array &$counters ) {
		$form_ids = array();
		if ( preg_match_all( '/\bwpforms-(\d+)\b/', $html, $matches ) ) {
			$form_ids = array_map( 'intval', $matches[1] );
		}
		if ( preg_match_all( '/\[wpforms[^\]]*id\s*=\s*["\']?(\d+)/i', $content, $sc ) ) {
			$form_ids = array_merge( $form_ids, array_map( 'intval', $sc[1] ) );
		}
		$form_ids = array_values( array_unique( array_filter( $form_ids ) ) );
		if ( empty( $form_ids ) || ! function_exists( 'wpforms' ) ) {
			return $instances;
		}
		foreach ( $form_ids as $form_id ) {
			$form_post = wpforms()->form->get( (int) $form_id );
			if ( ! $form_post || empty( $form_post->post_content ) ) {
				continue;
			}
			$decoded = json_decode( $form_post->post_content, true );
			if ( empty( $decoded['fields'] ) || ! is_array( $decoded['fields'] ) ) {
				continue;
			}
			foreach ( $decoded['fields'] as $field ) {
				$type = isset( $field['type'] ) ? $field['type'] : '';
				if ( ! in_array( $type, array( 'submit', 'payment-submit' ), true ) ) {
					continue;
				}
				$label = '';
				if ( ! empty( $field['label'] ) ) {
					$label = $field['label'];
				} elseif ( ! empty( $field['config']['label'] ) ) {
					$label = $field['config']['label'];
				}
				self::augment_add_form_button( $instances, $existing, $counters, 'INPUT', $label, 'wpforms' );
			}
		}
		return $instances;
	}

	private static function augment_contact_form_7( $html, $content, array $instances, array &$existing, array &$counters ) {
		$form_ids = array();
		if ( preg_match_all( '/\bwpcf7-f(\d+)-p\d+\b/', $html, $matches ) ) {
			$form_ids = array_map( 'intval', $matches[1] );
		}
		if ( preg_match_all( '/\[contact-form-7[^\]]*id\s*=\s*["\']?(\d+)/i', $content, $sc ) ) {
			$form_ids = array_merge( $form_ids, array_map( 'intval', $sc[1] ) );
		}
		if ( preg_match_all( '/\[contact-form[^\]]*id\s*=\s*["\']?(\d+)/i', $content, $sc2 ) ) {
			$form_ids = array_merge( $form_ids, array_map( 'intval', $sc2[1] ) );
		}
		$form_ids = array_values( array_unique( array_filter( $form_ids ) ) );
		if ( empty( $form_ids ) || ! class_exists( 'WPCF7_ContactForm' ) ) {
			return $instances;
		}
		foreach ( $form_ids as $form_id ) {
			$contact = WPCF7_ContactForm::get_instance( (int) $form_id );
			if ( ! $contact ) {
				continue;
			}
			$template = (string) $contact->prop( 'form' );
			if ( preg_match_all( '/\[submit(?:\s+[^\]]*)?\s+"([^"]+)"\]/i', $template, $labels ) ) {
				foreach ( $labels[1] as $label ) {
					self::augment_add_form_button( $instances, $existing, $counters, 'INPUT', $label, 'contact_form_7' );
				}
			}
			if ( preg_match_all( '/\[submit(?:\s+[^\]]*)?\s+\'([^\']+)\'\]/i', $template, $labels2 ) ) {
				foreach ( $labels2[1] as $label ) {
					self::augment_add_form_button( $instances, $existing, $counters, 'INPUT', $label, 'contact_form_7' );
				}
			}
		}
		return $instances;
	}

	public static function is_automatic_global_enabled() {
		$settings = get_option( 'oiscl_settings', array() );
		return ! empty( $settings['trackpro_enabled'] );
	}

	/**
	 * Site-wide DOM tags for pages in Automatic mode (defaults if unset).
	 */
	public static function get_global_automatic_tags() {
		$settings = get_option( 'oiscl_settings', array() );
		if ( ! empty( $settings['separator_tags'] ) && is_array( $settings['separator_tags'] ) ) {
			return implode( ',', $settings['separator_tags'] );
		}
		return 'h2,h3,section,article';
	}

	/**
	 * @param int $post_id Post ID.
	 */
	public static function get_page_tracking_mode( $post_id ) {
		$config = self::get_page_config( $post_id );
		if ( $config && ! empty( $config['tracking_mode'] ) && 'automatic' === $config['tracking_mode'] ) {
			return 'automatic';
		}
		return 'custom';
	}

	/**
	 * CSS selectors for automatic (non-scan) reading-map tracking on a page.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function get_page_auto_tags( $post_id ) {
		$config = self::get_page_config( $post_id );
		if ( $config && ! empty( $config['auto_tags'] ) ) {
			$tags = is_array( $config['auto_tags'] ) ? implode( ',', $config['auto_tags'] ) : (string) $config['auto_tags'];
			$tags = trim( $tags );
			if ( '' !== $tags ) {
				return $tags;
			}
		}
		return self::get_global_automatic_tags();
	}

	/**
	 * Whether a page uses its own automatic tags instead of global rules.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function page_has_auto_tags_override( $post_id ) {
		$config = self::get_page_config( $post_id );
		if ( ! $config || empty( $config['auto_tags'] ) ) {
			return false;
		}
		$tags = is_array( $config['auto_tags'] ) ? implode( ',', $config['auto_tags'] ) : trim( (string) $config['auto_tags'] );
		return '' !== $tags && $tags !== self::get_global_automatic_tags();
	}

	/**
	 * Display alias for reports / frontend (user label overrides scan alias).
	 *
	 * @param array $inst Instance row.
	 */
	public static function instance_display_alias( array $inst ) {
		if ( ! empty( $inst['custom_label'] ) ) {
			return (string) $inst['custom_label'];
		}
		return isset( $inst['alias'] ) ? (string) $inst['alias'] : ( isset( $inst['text'] ) ? (string) $inst['text'] : '' );
	}

	/**
	 * Payload for wp_localize_script on tracked pages.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function get_frontend_payload( $post_id ) {
		$config = self::get_page_config( $post_id );
		if ( ! $config ) {
			return array(
				'tracked'   => true,
				'instances' => array(),
				'mode'      => 'custom',
			);
		}
		$mode = self::get_page_tracking_mode( $post_id );
		if ( 'automatic' === $mode ) {
			return array(
				'tracked'   => true,
				'instances' => array(),
				'mode'      => 'automatic',
				'dom_hash'  => isset( $config['dom_hash'] ) ? $config['dom_hash'] : '',
			);
		}
		if ( empty( $config['instances'] ) ) {
			return array(
				'tracked'   => true,
				'instances' => array(),
				'mode'      => 'custom',
			);
		}
		$out = array();
		foreach ( $config['instances'] as $inst ) {
			if ( empty( $inst['id'] ) ) {
				continue;
			}
			$out[] = array(
				'id'          => $inst['id'],
				'tag'         => isset( $inst['tag'] ) ? $inst['tag'] : '',
				'text'        => isset( $inst['text'] ) ? $inst['text'] : '',
				'alias'       => self::instance_display_alias( $inst ),
				'track_view'  => ! empty( $inst['track_view'] ),
				'track_click' => ! empty( $inst['track_click'] ),
			);
		}
		return array(
			'tracked'   => true,
			'instances' => $out,
			'mode'      => 'custom',
			'dom_hash'  => isset( $config['dom_hash'] ) ? $config['dom_hash'] : '',
		);
	}

	/**
	 * Normalize legacy anchor on ingest.
	 *
	 * @param string $anchor Raw anchor.
	 */
	public static function normalize_anchor_for_storage( $anchor ) {
		if ( OISCL_Plan::EVENT_BLOCK_LEGACY === $anchor ) {
			return OISCL_Plan::EVENT_BLOCK_VIEW;
		}
		return $anchor;
	}

	/**
	 * @param array|null $config Page config.
	 */
	public static function get_config_revision( $config ) {
		if ( ! is_array( $config ) || empty( $config['config_revision'] ) ) {
			return 1;
		}
		return max( 1, (int) $config['config_revision'] );
	}

	/**
	 * @param array $config Config by ref.
	 */
	public static function ensure_config_meta( array &$config ) {
		if ( empty( $config['config_revision'] ) ) {
			$config['config_revision'] = 1;
		}
		if ( ! isset( $config['version_history'] ) || ! is_array( $config['version_history'] ) ) {
			$config['version_history'] = array();
		}
	}

	/**
	 * @param array $instances Instance rows.
	 * @return array<string,array>
	 */
	public static function instances_index_by_id( array $instances ) {
		$map = array();
		foreach ( $instances as $inst ) {
			if ( ! empty( $inst['id'] ) ) {
				$map[ $inst['id'] ] = $inst;
			}
		}
		return $map;
	}

	/**
	 * Carry track flags and labels from a previous scan into a new instance list.
	 *
	 * @param array $new_instances Fresh scan.
	 * @param array $old_instances Live config.
	 */
	public static function merge_scan_preferences( array $new_instances, array $old_instances ) {
		if ( empty( $old_instances ) ) {
			return $new_instances;
		}
		$prefs = array();
		foreach ( $old_instances as $old ) {
			if ( empty( $old['id'] ) ) {
				continue;
			}
			$prefs[ $old['id'] ] = array(
				'track_view'   => ! empty( $old['track_view'] ),
				'track_click'  => ! empty( $old['track_click'] ),
				'custom_label' => isset( $old['custom_label'] ) ? (string) $old['custom_label'] : '',
			);
		}
		foreach ( $new_instances as $idx => $inst ) {
			$iid = isset( $inst['id'] ) ? $inst['id'] : '';
			if ( ! $iid || ! isset( $prefs[ $iid ] ) ) {
				continue;
			}
			$new_instances[ $idx ]['track_view']  = $prefs[ $iid ]['track_view'];
			$new_instances[ $idx ]['track_click'] = $prefs[ $iid ]['track_click'];
			if ( '' !== $prefs[ $iid ]['custom_label'] ) {
				$new_instances[ $idx ]['custom_label'] = $prefs[ $iid ]['custom_label'];
			}
		}
		return $new_instances;
	}

	/**
	 * @param array $diff Output of compute_instance_diff.
	 */
	public static function has_structural_diff( array $diff ) {
		$added   = isset( $diff['added'] ) ? (int) $diff['added'] : 0;
		$removed = isset( $diff['removed'] ) ? (int) $diff['removed'] : 0;
		return $added > 0 || $removed > 0;
	}

	/**
	 * Rich diff for rescan review UI.
	 *
	 * @param array $old_instances Live instances.
	 * @param array $new_instances Scanned instances.
	 */
	public static function build_rescan_review_payload( array $old_instances, array $new_instances ) {
		$summary   = self::compute_instance_diff( $old_instances, $new_instances );
		$old_map   = self::instances_index_by_id( $old_instances );
		$new_map   = self::instances_index_by_id( $new_instances );
		$added     = array();
		$removed   = array();
		$unchanged = array();
		foreach ( $new_map as $id => $inst ) {
			if ( isset( $old_map[ $id ] ) ) {
				$unchanged[] = $inst;
			} else {
				$added[] = $inst;
			}
		}
		foreach ( $old_map as $id => $inst ) {
			if ( ! isset( $new_map[ $id ] ) ) {
				$removed[] = $inst;
			}
		}
		return array_merge(
			$summary,
			array(
				'unchanged'        => count( $unchanged ),
				'added_items'      => $added,
				'removed_items'    => $removed,
				'unchanged_items'  => $unchanged,
			)
		);
	}

	/**
	 * Snapshot current live config before promoting a new revision.
	 *
	 * @param array $config Config by ref.
	 */
	public static function archive_current_revision( array &$config ) {
		self::ensure_config_meta( $config );
		$revision = (int) $config['config_revision'];
		$config['version_history'][] = array(
			'revision'            => $revision,
			'archived_at'         => current_time( 'mysql' ),
			'revision_started_at' => isset( $config['revision_started_at'] ) ? $config['revision_started_at'] : ( isset( $config['scanned_at'] ) ? $config['scanned_at'] : '' ),
			'scanned_at'          => isset( $config['scanned_at'] ) ? $config['scanned_at'] : '',
			'dom_hash'            => isset( $config['dom_hash'] ) ? $config['dom_hash'] : '',
			'instances'           => isset( $config['instances'] ) && is_array( $config['instances'] ) ? $config['instances'] : array(),
		);
		if ( count( $config['version_history'] ) > self::MAX_CONFIG_REVISIONS ) {
			$config['version_history'] = array_slice( $config['version_history'], -1 * self::MAX_CONFIG_REVISIONS );
		}
	}

	/**
	 * Promote pending rescan to a new live revision.
	 *
	 * @param array $config        Config by ref.
	 * @param array $track_added   Map instance id => bool (track new items).
	 */
	public static function apply_pending_rescan( array &$config, array $track_added = array() ) {
		if ( empty( $config['pending_rescan'] ) || ! is_array( $config['pending_rescan'] ) ) {
			return false;
		}
		$pending = $config['pending_rescan'];
		$old_ids = array_keys( self::instances_index_by_id( isset( $config['instances'] ) ? $config['instances'] : array() ) );
		$new_ids = array_keys( self::instances_index_by_id( isset( $pending['instances'] ) ? $pending['instances'] : array() ) );
		$added_ids = array_diff( $new_ids, $old_ids );

		self::archive_current_revision( $config );

		$instances = isset( $pending['instances'] ) && is_array( $pending['instances'] ) ? $pending['instances'] : array();
		foreach ( $instances as $idx => $inst ) {
			$iid = isset( $inst['id'] ) ? $inst['id'] : '';
			if ( $iid && in_array( $iid, $added_ids, true ) ) {
				$track = ! isset( $track_added[ $iid ] ) || ! empty( $track_added[ $iid ] );
				$tag   = isset( $inst['tag'] ) ? strtoupper( $inst['tag'] ) : '';
				if ( in_array( $tag, array( 'H1', 'H2', 'H3', 'SECTION' ), true ) ) {
					$instances[ $idx ]['track_view']  = $track;
					$instances[ $idx ]['track_click'] = false;
				} else {
					$instances[ $idx ]['track_click'] = $track;
					$instances[ $idx ]['track_view']  = false;
				}
			}
		}

		$config['instances']            = $instances;
		$config['scanned_at']           = isset( $pending['scanned_at'] ) ? $pending['scanned_at'] : current_time( 'mysql' );
		$config['dom_hash']             = isset( $pending['dom_hash'] ) ? $pending['dom_hash'] : '';
		$config['config_revision']      = self::get_config_revision( $config ) + 1;
		$config['revision_started_at']  = $config['scanned_at'];
		unset( $config['pending_rescan'] );
		return true;
	}

	/**
	 * @param array $config Config by ref.
	 */
	public static function discard_pending_rescan( array &$config ) {
		unset( $config['pending_rescan'] );
	}

	/**
	 * Human label for an instance row in review lists.
	 *
	 * @param array $inst Instance.
	 */
	public static function instance_review_label( array $inst ) {
		$alias = self::instance_display_alias( $inst );
		$tag   = isset( $inst['tag'] ) ? strtoupper( $inst['tag'] ) : '';
		if ( $tag && $alias ) {
			return $tag . ': ' . $alias;
		}
		return $alias ? $alias : ( isset( $inst['id'] ) ? $inst['id'] : '' );
	}

	/**
	 * Alternate scheme + host forms of a URL so DB origin_url (from the browser) matches permalink-based scope.
	 *
	 * Metrics store `origin_url` without query string; browsers may use www/non-www or http/https differently
	 * than `get_permalink()`.
	 *
	 * @param string $url Full URL without query string (path may be slash-trailing or not).
	 * @return string[]
	 */
	private static function expand_origin_url_variants( $url ) {
		$url = (string) $url;
		if ( '' === $url ) {
			return array();
		}
		$parsed = wp_parse_url( $url );
		if ( empty( $parsed['host'] ) ) {
			return array( $url );
		}

		$host = (string) $parsed['host'];
		$hosts = array( $host );
		$alt   = ( 0 === stripos( $host, 'www.' ) ) ? (string) substr( $host, 4 ) : ( 'www.' . $host );
		if ( $alt !== $host ) {
			$hosts[] = $alt;
		}

		$schemes = array();
		if ( ! empty( $parsed['scheme'] ) ) {
			$base = strtolower( (string) $parsed['scheme'] ) . '://';
			$schemes[] = $base;
			if ( 'https://' === $base ) {
				$schemes[] = 'http://';
			} elseif ( 'http://' === $base ) {
				$schemes[] = 'https://';
			}
		} else {
			$schemes[] = '//';
		}
		$schemes = array_values( array_unique( $schemes ) );

		$userinfo = '';
		if ( ! empty( $parsed['user'] ) ) {
			$userinfo = rawurlencode( (string) $parsed['user'] );
			if ( isset( $parsed['pass'] ) && '' !== (string) $parsed['pass'] ) {
				$userinfo .= ':' . rawurlencode( (string) $parsed['pass'] );
			}
			$userinfo .= '@';
		}

		$port = isset( $parsed['port'] ) ? ':' . (int) $parsed['port'] : '';
		$path = isset( $parsed['path'] ) ? (string) $parsed['path'] : '';

		$out = array();
		foreach ( $schemes as $sch ) {
			foreach ( $hosts as $h ) {
				$out[] = $sch . $userinfo . $h . $port . $path;
			}
		}
		return array_values( array_unique( array_filter( $out ) ) );
	}

	/**
	 * URL variants stored in metrics.origin_url for a tracked page.
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	public static function get_origin_url_candidates( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return array();
		}
		$permalink = get_permalink( $post_id );
		if ( ! $permalink ) {
			return array();
		}
		$no_query = strtok( $permalink, '?' );
		$trimmed  = rtrim( $no_query, '/' );
		$variants = array_unique(
			array_filter(
				array(
					$no_query,
					$trimmed,
					trailingslashit( $trimmed ),
				)
			)
		);
		$expanded = array();
		foreach ( $variants as $u ) {
			foreach ( self::expand_origin_url_variants( $u ) as $v ) {
				$expanded[] = $v;
			}
		}
		return array_values( array_unique( array_filter( $expanded ) ) );
	}

	/**
	 * Active time windows per saved config revision (for report scoping).
	 *
	 * @param int $post_id Post ID.
	 * @return array<int,array{revision:int,start_date:string,end_date:string,is_current:bool,label:string}>
	 */
	public static function get_revision_windows( $post_id ) {
		$config = self::get_page_config( $post_id );
		if ( ! is_array( $config ) ) {
			return array();
		}
		self::ensure_config_meta( $config );
		$today   = current_time( 'Y-m-d' );
		$windows = array();
		$history = isset( $config['version_history'] ) && is_array( $config['version_history'] ) ? $config['version_history'] : array();
		usort(
			$history,
			function ( $a, $b ) {
				return (int) $a['revision'] <=> (int) $b['revision'];
			}
		);
		foreach ( $history as $entry ) {
			$rev = (int) $entry['revision'];
			$end = ! empty( $entry['archived_at'] ) ? substr( (string) $entry['archived_at'], 0, 10 ) : $today;
			$start_raw = '';
			if ( ! empty( $entry['revision_started_at'] ) ) {
				$start_raw = $entry['revision_started_at'];
			} elseif ( ! empty( $entry['scanned_at'] ) ) {
				$start_raw = $entry['scanned_at'];
			}
			$start = $start_raw ? substr( (string) $start_raw, 0, 10 ) : $end;
			$windows[] = array(
				'revision'   => $rev,
				'start_date' => $start,
				'end_date'   => $end,
				'is_current' => false,
				'label'      => sprintf(
					/* translators: %d: config revision number */
					__( 'Config v%d', 'ois-conversion-suite' ),
					$rev
				),
			);
		}
		$cur_rev     = self::get_config_revision( $config );
		$cur_start_raw = ! empty( $config['revision_started_at'] )
			? $config['revision_started_at']
			: ( isset( $config['scanned_at'] ) ? $config['scanned_at'] : '' );
		$cur_start   = $cur_start_raw ? substr( (string) $cur_start_raw, 0, 10 ) : $today;
		$windows[]   = array(
			'revision'   => $cur_rev,
			'start_date' => $cur_start,
			'end_date'   => $today,
			'is_current' => true,
			'label'      => sprintf(
				/* translators: %d: config revision number */
				__( 'Config v%d (current)', 'ois-conversion-suite' ),
				$cur_rev
			),
		);
		return $windows;
	}

	/**
	 * Pages with a saved scan/config (subset of target_urls).
	 *
	 * @return array<int,string> post_id => title
	 */
	public static function get_configured_pages_for_reports() {
		$settings    = get_option( 'oiscl_settings', array() );
		$target_urls = isset( $settings['target_urls'] ) && is_array( $settings['target_urls'] ) ? $settings['target_urls'] : array();
		$pages       = array();
		foreach ( $target_urls as $pid ) {
			$pid = (int) $pid;
			if ( $pid <= 0 ) {
				continue;
			}
			$config = self::get_page_config( $pid );
			if ( ! $config || empty( $config['instances'] ) || ! is_array( $config['instances'] ) ) {
				continue;
			}
			$title = get_the_title( $pid );
			$pages[ $pid ] = $title ? $title : '#' . $pid;
		}
		return $pages;
	}

	/**
	 * Narrow report dates and origin URLs from tp_page / tp_revision request args.
	 *
	 * @param array<string,mixed> $request    $_GET-like.
	 * @param string              $start_date Y-m-d.
	 * @param string              $end_date   Y-m-d.
	 * @param string              $today      Y-m-d.
	 * @return array<string,mixed>
	 */
	public static function resolve_report_scope( array $request, $start_date, $end_date, $today ) {
		$scope = array(
			'post_id'        => 0,
			'revision'       => 0,
			'origin_urls'    => array(),
			'start_date'     => $start_date,
			'end_date'       => $end_date,
			'revision_label' => '',
			'page_title'     => '',
			'window'         => null,
		);
		$post_id  = isset( $request['tp_page'] ) ? (int) $request['tp_page'] : 0;
		$revision = isset( $request['tp_revision'] ) ? (int) $request['tp_revision'] : 0;
		if ( $post_id <= 0 ) {
			return $scope;
		}
		$config = self::get_page_config( $post_id );
		if ( ! $config ) {
			return $scope;
		}
		$scope['post_id']     = $post_id;
		$scope['origin_urls'] = self::get_origin_url_candidates( $post_id );
		$scope['page_title']  = get_the_title( $post_id );
		if ( $revision <= 0 ) {
			return $scope;
		}
		$scope['revision'] = $revision;
		foreach ( self::get_revision_windows( $post_id ) as $window ) {
			if ( (int) $window['revision'] !== $revision ) {
				continue;
			}
			$scope['window']         = $window;
			$scope['revision_label'] = $window['label'];
			$w_start                 = $window['start_date'];
			$w_end                   = min( $window['end_date'], $today );
			if ( strtotime( $start_date ) < strtotime( $w_start ) ) {
				$start_date = $w_start;
			}
			if ( strtotime( $end_date ) > strtotime( $w_end ) ) {
				$end_date = $w_end;
			}
			if ( strtotime( $start_date ) > strtotime( $end_date ) ) {
				$end_date = $start_date;
			}
			$scope['start_date'] = $start_date;
			$scope['end_date']   = $end_date;
			break;
		}
		return $scope;
	}
}
