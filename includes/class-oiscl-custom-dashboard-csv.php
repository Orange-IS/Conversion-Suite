<?php
/**
 * Build UTF-8 CSV / HTML snapshots for Custom Dashboard templates (tabular columns only).
 *
 * @package OIS_Conversion_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OISCL_Custom_Dashboard_CSV {

	/**
	 * @param mixed $val Raw DB value.
	 * @return string|string[]
	 */
	private static function format_export_cell( $col_key, $val ) {
		if ( 'is_bot' === $col_key ) {
			return ( (string) $val === '1' ) ? 'Bot' : __( 'Human', 'ois-conversion-suite' );
		}
		if ( 'avg_time' === $col_key && is_numeric( $val ) && (float) $val > 0 ) {
			return round( (float) $val, 1 ) . 's';
		}
		return $val;
	}

	/**
	 * Tabular export payload for CSV/HTML attachments (same columns as admin CSV).
	 *
	 * @param array<string,mixed> $dashboard Dashboard row from oiscl_custom_dashboards.
	 * @param string              $start_date Y-m-d.
	 * @param string              $end_date   Y-m-d.
	 * @return array{headers:string[],rows:string[][]}|null
	 */
	public static function get_tabular_export( array $dashboard, $start_date, $end_date ) {
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

		$headers = array();
		foreach ( $table_cols as $col_key ) {
			if ( isset( $dict['columns'][ $col_key ] ) ) {
				$headers[] = $dict['columns'][ $col_key ]['label'];
			}
		}

		$rows = array();
		if ( $results ) {
			foreach ( $results as $row ) {
				$csv_row = array();
				foreach ( $table_cols as $col_key ) {
					if ( ! isset( $dict['columns'][ $col_key ] ) ) {
						continue;
					}
					$val       = isset( $row[ $col_key ] ) ? $row[ $col_key ] : '';
					$csv_row[] = (string) self::format_export_cell( $col_key, $val );
				}
				$rows[] = $csv_row;
			}
		}

		return array(
			'headers' => $headers,
			'rows'    => $rows,
		);
	}

	/**
	 * @param array{headers:string[],rows:string[][]} $export From get_tabular_export().
	 */
	public static function write_temp_csv_from_export( array $export ) {
		$tmp = wp_tempnam( 'oiscl-sched-report-' );
		if ( ! $tmp ) {
			return null;
		}

		$out = fopen( $tmp, 'w' );
		if ( ! $out ) {
			return null;
		}

		fprintf( $out, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );
		fputcsv( $out, $export['headers'] );

		foreach ( $export['rows'] as $line ) {
			fputcsv( $out, $line );
		}

		fclose( $out );
		return $tmp;
	}

	/**
	 * Print-friendly HTML snapshot (may be saved as PDF via browser Print → Save as PDF).
	 *
	 * @param array{headers:string[],rows:string[][]} $export From get_tabular_export().
	 * @param string                                  $title Document title / H1.
	 * @param string                                  $subtitle Meta line (dates, site).
	 * @param bool                                    $pdf_hint Show “Save as PDF” note when delivery_format is pdf.
	 */
	public static function write_temp_html_from_export( array $export, $title, $subtitle, $pdf_hint ) {
		$tmp = wp_tempnam( 'oiscl-html-report-' );
		if ( ! $tmp ) {
			return null;
		}

		ob_start();
		echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
		echo '<title>' . esc_html( $title ) . '</title>';
		echo '<style>body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;margin:24px;color:#1d2327;}table{border-collapse:collapse;width:100%;margin-top:16px;}th,td{border:1px solid #ccd0d4;padding:8px;text-align:left;}th{background:#f1f5f9;}h1{font-size:20px;} .meta{color:#50575e;font-size:14px;} .hint{margin-top:24px;font-size:13px;color:#50575e;}</style>';
		echo '</head><body>';
		echo '<h1>' . esc_html( $title ) . '</h1>';
		if ( $subtitle ) {
			echo '<p class="meta">' . esc_html( $subtitle ) . '</p>';
		}
		echo '<table><thead><tr>';
		foreach ( $export['headers'] as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $export['rows'] as $line ) {
			echo '<tr>';
			foreach ( $line as $cell ) {
				echo '<td>' . esc_html( $cell ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
		if ( $pdf_hint ) {
			echo '<p class="hint">' . esc_html__( 'To save as PDF: open this file in a browser and use Print → Save as PDF.', 'ois-conversion-suite' ) . '</p>';
		}
		echo '</body></html>';
		$html = ob_get_clean();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $tmp, $html ) ) {
			unlink( $tmp );
			return null;
		}

		return $tmp;
	}

	/**
	 * Write dashboard table export to a temp file (same logical columns as admin CSV export).
	 *
	 * @param array<string,mixed> $dashboard Dashboard row from oiscl_custom_dashboards.
	 * @param string              $start_date Y-m-d.
	 * @param string              $end_date   Y-m-d.
	 * @return string|null Absolute path to temp file, or null when nothing to export.
	 */
	public static function write_temp_file( array $dashboard, $start_date, $end_date ) {
		$export = self::get_tabular_export( $dashboard, $start_date, $end_date );
		if ( null === $export ) {
			return null;
		}
		return self::write_temp_csv_from_export( $export );
	}
}
