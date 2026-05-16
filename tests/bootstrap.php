<?php
/**
 * PHPUnit bootstrap: stub ABSPATH so plugin includes can load outside WordPress.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

require_once dirname( __DIR__ ) . '/includes/class-oiscl-utm-alert-rules.php';
require_once dirname( __DIR__ ) . '/includes/class-oiscl-utm-query-helper.php';
