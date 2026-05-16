<?php
/**
 * Plugin Name: OIS Conversion Suite
 * Description: Centralized conversion intelligence and SEO toolkit for WordPress.
 * Version: 0.77.0
 * Author: Orange Internet Solutions
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Text Domain: ois-conversion-suite
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'OISCL_PLUGIN_FILE', __FILE__ );
define( 'OISCL_VERSION', '0.77.0' );

// 1. ACTIVACIÓN (Crea las tablas si no existen)
register_activation_hook( __FILE__, function() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-oiscl-activator.php';
	OISCL_Activator::activate();
} );

// 2. INICIALIZACIÓN
add_action( 'plugins_loaded', function() {
	load_plugin_textdomain(
		'ois-conversion-suite',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages'
	);

	require_once plugin_dir_path( __FILE__ ) . 'includes/class-oiscl-activator.php';
	OISCL_Activator::maybe_upgrade_metrics_utm_sm_columns();
	OISCL_Activator::maybe_upgrade_metrics_screen_res_column();
	OISCL_Activator::maybe_upgrade_utm_refs_unique_key();
	OISCL_Activator::maybe_upgrade_metrics_instance_column();
	OISCL_Activator::maybe_upgrade_utm_refs_google_columns();
	OISCL_Activator::maybe_upgrade_utm_refs_spend_column();

	require_once plugin_dir_path( __FILE__ ) . 'includes/class-oiscl-utm-alert-rules.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-oiscl-utm-alerts.php';
	OISCL_Utm_Alerts::init();

	require_once plugin_dir_path( __FILE__ ) . 'includes/class-oiscl-plan.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-oiscl-tracking.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-oiscl-activity.php';

	if ( is_admin() ) {
		require_once plugin_dir_path( __FILE__ ) . 'admin/class-oiscl-admin.php';
		$admin = new OISCL_Admin();
		$admin->init();
	}
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-oiscl-core.php';
	$core = new OISCL_Core();
	$core->init();

	require_once plugin_dir_path( __FILE__ ) . 'includes/class-oiscl-metrics-ajax.php';
	$metrics_ajax = new OISCL_Metrics_Ajax();
	$metrics_ajax->init();
} );
