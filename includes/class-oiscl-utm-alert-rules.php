<?php
/**
 * Pure predicates for UTM campaign alerts (testable without WordPress DB).
 *
 * @package OIS_Conversion_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OISCL_Utm_Alert_Rules {

	const MIN_PRIOR_CLICKS_DROP = 5;

	/**
	 * Drop alert: prior window had enough baseline clicks and current fell below threshold vs drop_pct.
	 *
	 * Matches legacy condition: $prev >= $min_prior && $curr < ( $prev * ( 100 - $drop_pct ) / 100 ).
	 *
	 * @param int $prev     Prior-period interaction row count (same SQL definition as compute_alerts).
	 * @param int $curr     Current-period count.
	 * @param int $drop_pct Percent drop sensitivity (e.g. 30 means alert when current count is below 70% of prior).
	 * @param int $min_prior Minimum prior count before drop alerts apply (default 5).
	 */
	public static function should_alert_drop( int $prev, int $curr, int $drop_pct, int $min_prior = self::MIN_PRIOR_CLICKS_DROP ): bool {
		if ( $prev < $min_prior ) {
			return false;
		}
		$threshold = $prev * ( 100 - $drop_pct ) / 100;
		return $curr < $threshold;
	}

	/**
	 * Zero-traffic alert: no hits since window start but traffic existed just before (caller defines windows).
	 *
	 * @param int $recent_hits Rows in the recent window (inclusive boundary per caller SQL).
	 * @param int $prior_hits  Rows in the “had traffic before” probe (caller SQL).
	 */
	public static function should_alert_zero_window( int $recent_hits, int $prior_hits ): bool {
		return 0 === $recent_hits && $prior_hits > 0;
	}
}
