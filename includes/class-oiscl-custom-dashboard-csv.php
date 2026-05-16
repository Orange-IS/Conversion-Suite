<?php
/**
 * Build UTF-8 CSV snapshots for Custom Dashboard templates (tabular columns only).
 *
 * @package OIS_Conversion_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OISCL_Custom_Dashboard_CSV {

	/**
	 * Write dashboard table export to a temp file (same logical columns as admin CSV export).
	 *
	 * @param array<string,mixed> $dashboard Dashboard row from oiscl_custom_dashboards.
	 * @param string              $start_date Y-m-d.
	 * @param string              $end_date   Y-m-d.
	 * @return string|null Absolute path to temp file, or null when nothing to export.
	 */
	public static function write_temp_file( array $dashboard, $start_date, $end_date ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'oiscl_block_metrics';
		$dict       = OISCL_Dashboard_Dictionary::all();
		$table_cols = array();

		if ( empty( $dashboard['elements'] ) || ! is_array( $dashboard['elements'] ) ) {
			return null;
		}

		foreach ( $dashboard['elements'] as $el ) {
			if ( is_string( $el ) && strpos( $el, 'col_' ) === 0 ) {
				$table_cols[] = str_replace( 'col_', '', $el );
			}
		}

		if ( empty( $table_cols ) ) {
			return null;
		}

		$dimensions = array();
		$metrics    = array();
		$select_sql = array();

		foreach ( $table_cols as $col_key ) {
			if ( ! isset( $dict['columns'][ $col_key ] ) ) {
				continue;
			}
			if ( 'metric' === $dict['columns'][ $col_key ]['type'] ) {
				$metrics[]    = $dict['columns'][ $col_key ]['sql'];
				$select_sql[] = $dict['columns'][ $col_key ]['sql'];
			} else {
				$dimensions[] = $col_key;
				$select_sql[] = $col_key;
			}
		}

		if ( empty( $select_sql ) ) {
			return null;
		}

		$sql = 'SELECT ' . implode( ', ', $select_sql ) . " FROM `{$table_name}` WHERE DATE(created_at) >= %s AND DATE(created_at) <= %s";
		if ( ! empty( $dimensions ) ) {
			$sql .= ' GROUP BY ' . implode( ', ', $dimensions );
		}
		if ( ! empty( $metrics ) ) {
			$order_src = explode( ' as ', $metrics[0] )[0];
			$sql      .= ' ORDER BY ' . $order_src . ' DESC';
		} else {
			$sql .= ' ORDER BY id DESC';
		}
		$sql .= ' LIMIT 1000';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Dynamic SELECT/GROUP BY built from fixed dictionary keys only.
		$results = $wpdb->get_results( $wpdb->prepare( $sql, $start_date, $end_date ), ARRAY_A );

		$tmp = wp_tempnam( 'oiscl-sched-report-' );
		if ( ! $tmp ) {
			return null;
		}

		$out = fopen( $tmp, 'w' );
		if ( ! $out ) {
			return null;
		}

		fprintf( $out, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

		$headers = array();
		foreach ( $table_cols as $col_key ) {
			if ( isset( $dict['columns'][ $col_key ] ) ) {
				$headers[] = $dict['columns'][ $col_key ]['label'];
			}
		}
		fputcsv( $out, $headers );

		if ( $results ) {
			foreach ( $results as $row ) {
				$csv_row = array();
				foreach ( $table_cols as $col_key ) {
					if ( ! isset( $dict['columns'][ $col_key ] ) ) {
						continue;
					}
					$val = isset( $row[ $col_key ] ) ? $row[ $col_key ] : '';
					if ( 'is_bot' === $col_key ) {
						$val = ( (string) $val === '1' ) ? 'Bot' : 'Humano';
					}
					if ( 'avg_time' === $col_key && is_numeric( $val ) && (float) $val > 0 ) {
						$val = round( (float) $val, 1 ) . 's';
					}
					$csv_row[] = $val;
				}
				fputcsv( $out, $csv_row );
			}
		}

		fclose( $out );
		return $tmp;
	}
}
