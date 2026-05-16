<?php
/**
 * Resolve snapshot date ranges for scheduled reports (site timezone).
 *
 * @package OIS_Conversion_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OISCL_Report_Date_Ranges {

	/**
	 * Preset keys (mirror OISCL_Scheduled_Reports::PERIOD_*).
	 *
	 * @param string               $preset rolling_7|rolling_14|rolling_30|yesterday|today|prev_calendar_month|prev_month_1_15|prev_month_16_end
	 * @param int|null             $now_ts   Unix timestamp (default now).
	 * @return array{start_date:string,end_date:string}|null
	 */
	public static function resolve( $preset, $now_ts = null ) {
		$preset = (string) $preset;
		$now_ts = null !== $now_ts ? (int) $now_ts : time();

		try {
			$tz = wp_timezone();
		} catch ( Exception $e ) {
			$tz = new DateTimeZone( 'UTC' );
		}

		$today = ( new DateTimeImmutable( '@' . $now_ts ) )->setTimezone( $tz )->setTime( 0, 0, 0 );
		$yesterday = $today->modify( '-1 day' );

		switch ( $preset ) {
			case 'yesterday':
				$start = $yesterday;
				$end   = $yesterday;
				break;

			case 'today':
				$start = $today;
				$end   = $today;
				break;

			case 'rolling_7':
				$end = $yesterday;
				$start = $end->modify( '-6 days' );
				break;

			case 'rolling_14':
				$end = $yesterday;
				$start = $end->modify( '-13 days' );
				break;

			case 'rolling_30':
				$end = $yesterday;
				$start = $end->modify( '-29 days' );
				break;

			case 'prev_calendar_month':
				$first_this = $today->modify( 'first day of this month' );
				$last_prev   = $first_this->modify( '-1 day' );
				$first_prev  = $last_prev->modify( 'first day of this month' );
				$start       = $first_prev;
				$end         = $last_prev;
				break;

			case 'prev_month_1_15':
				$first_this = $today->modify( 'first day of this month' );
				$last_prev   = $first_this->modify( '-1 day' );
				$first_prev  = $last_prev->modify( 'first day of this month' );
				$start       = $first_prev;
				$end         = $first_prev->modify( '+14 days' );
				break;

			case 'prev_month_16_end':
				$first_this = $today->modify( 'first day of this month' );
				$last_prev   = $first_this->modify( '-1 day' );
				$first_prev  = $last_prev->modify( 'first day of this month' );
				$start       = $first_prev->modify( '+15 days' );
				$end         = $last_prev;
				break;

			default:
				return null;
		}

		return array(
			'start_date' => $start->format( 'Y-m-d' ),
			'end_date'   => $end->format( 'Y-m-d' ),
		);
	}
}
