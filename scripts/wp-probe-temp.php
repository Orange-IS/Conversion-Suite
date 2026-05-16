<?php
/**
 * TEMPORARY — WordPress read-only probe for local debugging / agent-assisted inspection.
 *
 * Runs only under PHP CLI. Loads wp-load.php from --wp-root or by walking up from cwd.
 * Do NOT open this file in a browser; use SSH and `php scripts/wp-probe-temp.php ...`.
 * Does not modify the database. Remove this file when you no longer need it.
 *
 * Usage examples:
 *   php scripts/wp-probe-temp.php --wp-root="C:\laragon\www\misitio\wordpress" info
 *   php scripts/wp-probe-temp.php info
 *   php scripts/wp-probe-temp.php tables oiscl_
 *   php scripts/wp-probe-temp.php option blogname
 *   php scripts/wp-probe-temp.php rows oiscl_block_metrics
 *   php scripts/wp-probe-temp.php oiscl
 *
 * Optional env:
 *   OISCL_WP_ROOT=C:\path\to\wordpress   (same as --wp-root)
 *
 * @package OIS_Conversion_Suite
 */

if ( 'cli' !== php_sapi_name() ) {
	if ( ! headers_sent() ) {
		header( 'HTTP/1.1 403 Forbidden' );
	}
	exit;
}

/**
 * Find wp-load.php by walking up from a directory.
 *
 * @param string $start_dir Directory path.
 * @return string|null Absolute path to wp-load.php.
 */
function oiskcl_wp_probe_find_wp_load( $start_dir ) {
	$dir = realpath( $start_dir );
	for ( $i = 0; $i < 14 && $dir; $i++ ) {
		$candidate = $dir . DIRECTORY_SEPARATOR . 'wp-load.php';
		if ( file_exists( $candidate ) ) {
			return $candidate;
		}
		$parent = dirname( $dir );
		if ( $parent === $dir ) {
			break;
		}
		$dir = $parent;
	}
	return null;
}

$wp_root_opt = getenv( 'OISCL_WP_ROOT' );
$wp_root_opt = false !== $wp_root_opt ? trim( (string) $wp_root_opt ) : '';

$passthrough = array();
for ( $i = 1; $i < $argc; $i++ ) {
	$v = $argv[ $i ];
	if ( preg_match( '/^--wp-root=(.+)$/', $v, $m ) ) {
		$wp_root_opt = $m[1];
		continue;
	}
	if ( '--wp-root' === $v ) {
		$wp_root_opt = isset( $argv[ $i + 1 ] ) ? $argv[ ++$i ] : '';
		continue;
	}
	if ( '--help' === $v || '-h' === $v ) {
		$passthrough = array( 'help' );
		break;
	}
	$passthrough[] = $v;
}

$wp_load = null;
if ( '' !== $wp_root_opt ) {
	$wp_root_opt = rtrim( $wp_root_opt, '/\\' );
	$candidate   = $wp_root_opt . DIRECTORY_SEPARATOR . 'wp-load.php';
	if ( ! file_exists( $candidate ) ) {
		fwrite( STDERR, "wp-load.php not found under: {$wp_root_opt}\n" );
		exit( 1 );
	}
	$wp_load = $candidate;
} else {
	$candidate_dirs = array_unique(
		array_filter(
			array(
				getcwd(),
				dirname( __DIR__ ),
				realpath( dirname( __DIR__ ) . '/..' ),
			)
		)
	);
	foreach ( $candidate_dirs as $start ) {
		if ( ! $start ) {
			continue;
		}
		$found = oiskcl_wp_probe_find_wp_load( $start );
		if ( $found ) {
			$wp_load = $found;
			break;
		}
	}
}

if ( ! $wp_load ) {
	fwrite( STDERR, "Could not find wp-load.php on this computer.\n\n" );
	fwrite( STDERR, "You must pass the SERVER FILESYSTEM path to the folder that contains wp-load.php — not the website URL.\n" );
	fwrite( STDERR, "Example (wrong):  https://test.orangeinternetsolutions.com\n" );
	fwrite( STDERR, "Example (right):  /home/username/public_html   OR   C:\\Sites\\test.orange…\\public_html\n\n" );
	fwrite( STDERR, "Usage: php scripts/wp-probe-temp.php --wp-root=/full/path/to/wordpress info\n" );
	fwrite( STDERR, "Or run the same command over SSH on the host where WordPress files actually live.\n" );
	exit( 1 );
}

require_once $wp_load;

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "WordPress bootstrap failed.\n" );
	exit( 1 );
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';

global $wpdb;

/**
 * Output JSON and exit.
 *
 * @param mixed $data Serializable payload.
 */
function oiskcl_wp_probe_out( $data ) {
	echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
	exit( 0 );
}

$cmd = isset( $passthrough[0] ) ? $passthrough[0] : 'info';

if ( 'help' === $cmd ) {
	echo <<<TXT
OISCL wp-probe-temp (read-only)

  php scripts/wp-probe-temp.php [--wp-root=PATH] <command> [args]

Commands:
  info              Site URL, WP version, db prefix, active plugins, OISCL detection.
  tables [LIKE]     SHOW TABLES LIKE "{prefix}LIKE%" (default LIKE = oiscl_%).
  option NAME       get_option( NAME ) — scalar/array shown as JSON.
  rows TABLE        COUNT(*) for {prefix}TABLE — TABLE must match /^oiscl_[a-z0-9_]+$/i.
  oiscl             Shortcut: tables + row counts for known OISCL metric tables.
  plugins           Active plugins slugs/paths.
  users-count       Approximate user count (COUNT from users table).

Environment:
  OISCL_WP_ROOT     Same as --wp-root.

TXT;
	exit( 0 );
}

switch ( $cmd ) {
	case 'info':
		$oiscl_plugin = '';
		foreach ( (array) get_option( 'active_plugins', array() ) as $rel ) {
			if ( false !== strpos( $rel, 'ois-conversion-suite.php' ) || false !== strpos( $rel, 'ois-conversion-lab.php' ) ) {
				$oiscl_plugin = $rel;
				break;
			}
		}
		oiskcl_wp_probe_out(
			array(
				'wp_load'           => $wp_load,
				'siteurl'           => get_option( 'siteurl' ),
				'home'              => get_option( 'home' ),
				'wp_version'        => get_bloginfo( 'version' ),
				'is_multisite'      => is_multisite(),
				'db_prefix'         => $wpdb->prefix,
				'ois_plugin_relative' => $oiscl_plugin,
				'ois_plugin_active' => $oiscl_plugin ? is_plugin_active( $oiscl_plugin ) : false,
				'oiscl_version_constant' => defined( 'OISCL_VERSION' ) ? OISCL_VERSION : null,
			)
		);
		break;

	case 'tables':
		$like_arg = isset( $passthrough[1] ) ? $passthrough[1] : 'oiscl_';
		$pattern  = $wpdb->esc_like( $like_arg ) . '%';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- pattern built via esc_like.
		$sql = $wpdb->prepare( 'SHOW TABLES LIKE %s', $pattern );
		$list = $wpdb->get_col( $sql );
		oiskcl_wp_probe_out( array( 'like' => $like_arg, 'tables' => array_values( $list ) ) );
		break;

	case 'option':
		$name = isset( $passthrough[1] ) ? $passthrough[1] : '';
		if ( '' === $name ) {
			fwrite( STDERR, "usage: option <option_name>\n" );
			exit( 1 );
		}
		oiskcl_wp_probe_out( array( 'name' => $name, 'value' => get_option( $name ) ) );
		break;

	case 'rows':
		$table_suffix = isset( $passthrough[1] ) ? $passthrough[1] : '';
		if ( ! preg_match( '/^oiscl_[a-z0-9_]+$/i', $table_suffix ) ) {
			fwrite( STDERR, "Only tables matching oiscl_* are allowed.\n" );
			exit( 1 );
		}
		$table = $wpdb->prefix . $table_suffix;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- suffix validated.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			oiskcl_wp_probe_out( array( 'table' => $table, 'exists' => false, 'rows' => null ) );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
		oiskcl_wp_probe_out( array( 'table' => $table, 'exists' => true, 'rows' => $count ) );
		break;

	case 'oiscl':
		$suffixes = array(
			'oiscl_block_metrics',
			'oiscl_utm_references',
		);
		$out      = array();
		foreach ( $suffixes as $suffix ) {
			$table = $wpdb->prefix . $suffix;
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( ! $exists ) {
				$out[ $suffix ] = array( 'exists' => false, 'rows' => null );
				continue;
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$out[ $suffix ] = array(
				'exists' => true,
				'rows'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ),
			);
		}
		oiskcl_wp_probe_out( $out );
		break;

	case 'plugins':
		oiskcl_wp_probe_out(
			array(
				'active_plugins' => array_values( (array) get_option( 'active_plugins', array() ) ),
			)
		);
		break;

	case 'users-count':
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );
		oiskcl_wp_probe_out( array( 'users' => $n ) );
		break;

	default:
		fwrite( STDERR, "Unknown command: {$cmd}\nRun with --help.\n" );
		exit( 1 );
}
