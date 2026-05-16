<?php
/**
 * Pure PHP helpers for UTM SQL composition (safe for PHPUnit without WordPress).
 *
 * @package OIS_Conversion_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static helpers used by UTM admin SQL builders.
 */
class OISCL_Utm_Query_Helper {

	/**
	 * Insert a fragment immediately before the first GROUP BY, ORDER BY, or LIMIT (case-insensitive).
	 * If none are present, append the fragment at the end of the string.
	 *
	 * Used so WHERE predicates (e.g. utm_campaign) never land after ORDER BY.
	 *
	 * @param string $sql      SQL template.
	 * @param string $fragment Fragment to inject (typically starts with AND ...).
	 * @return string
	 */
	public static function inject_before_group_order_limit( $sql, $fragment ) {
		$sql      = (string) $sql;
		$fragment = (string) $fragment;
		if ( '' === $fragment ) {
			return $sql;
		}
		if ( preg_match( '/\s+(GROUP\s+BY|ORDER\s+BY|LIMIT)\b/i', $sql, $m, PREG_OFFSET_CAPTURE ) ) {
			$pos = (int) $m[0][1];
			return substr( $sql, 0, $pos ) . $fragment . substr( $sql, $pos );
		}
		return $sql . $fragment;
	}

	/**
	 * Which funnel CSV blocks to emit for a given export scope (admin funnel_scope query arg).
	 *
	 * @param string $scope company|campaign|both|global|complete (case-insensitive).
	 * @return array{global:bool,company:bool,campaign:bool}
	 */
	public static function funnel_csv_sections( $scope ) {
		$scope = strtolower( trim( (string) $scope ) );
		return array(
			'global'   => in_array( $scope, array( 'global', 'complete' ), true ),
			'company'  => in_array( $scope, array( 'company', 'both', 'complete' ), true ),
			'campaign' => in_array( $scope, array( 'campaign', 'both', 'complete' ), true ),
		);
	}
}
