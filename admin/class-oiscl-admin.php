<?php
/**
 * Admin bootstrap: composes area traits into OISCL_Admin.
 *
 * @package OIS_Conversion_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$oiscl_admin_dir = plugin_dir_path( __FILE__ );

require_once dirname( __DIR__ ) . '/includes/class-oiscl-utm-query-helper.php';

require_once $oiscl_admin_dir . 'traits/trait-oiscl-admin-ajax.php';
require_once $oiscl_admin_dir . 'traits/trait-oiscl-admin-analytics.php';
require_once $oiscl_admin_dir . 'traits/trait-oiscl-admin-component.php';
require_once $oiscl_admin_dir . 'traits/trait-oiscl-admin-core.php';
require_once $oiscl_admin_dir . 'traits/trait-oiscl-admin-custom-dashboards.php';
require_once $oiscl_admin_dir . 'traits/trait-oiscl-admin-custom-reports.php';
require_once $oiscl_admin_dir . 'traits/trait-oiscl-admin-dashboard.php';
require_once $oiscl_admin_dir . 'traits/trait-oiscl-admin-seo.php';
require_once $oiscl_admin_dir . 'traits/trait-oiscl-admin-settings.php';
require_once $oiscl_admin_dir . 'traits/trait-oiscl-admin-trackpro.php';
require_once $oiscl_admin_dir . 'traits/trait-oiscl-admin-ui-charts.php';
require_once $oiscl_admin_dir . 'traits/trait-oiscl-admin-utm.php';
require_once $oiscl_admin_dir . 'traits/trait-oiscl-admin-v2.php';

/**
 * Main admin class (modular traits).
 */
class OISCL_Admin {

	use OISCL_Admin_Ajax_Trait;
	use OISCL_Admin_Analytics_Trait;
	use OISCL_Admin_Component_Trait;
	use OISCL_Admin_Core_Trait;
	use OISCL_Admin_Custom_Dashboards_Trait;
	use OISCL_Admin_Custom_Reports_Trait;
	use OISCL_Admin_Seo_Trait;
	use OISCL_Admin_Settings_Trait;
	use OISCL_Admin_Trackpro_Trait;
	use OISCL_Admin_UI_Charts_Trait;
	use OISCL_Admin_Dashboard_Trait;
	use OISCL_Admin_Utm_Trait;
	use OISCL_Admin_V2_Trait;
}
