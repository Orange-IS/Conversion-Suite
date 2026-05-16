<?php
/**
 * @package OIS_Conversion_Suite
 */

namespace OISCL\Tests;

use PHPUnit\Framework\TestCase;

final class UtmQueryHelperTest extends TestCase {

	public function test_injects_before_order_by(): void {
		$sql = 'SELECT * FROM t WHERE x = 1 ORDER BY y DESC';
		$got = \OISCL_Utm_Query_Helper::inject_before_group_order_limit( $sql, ' AND z = 2' );
		$this->assertSame( 'SELECT * FROM t WHERE x = 1 AND z = 2 ORDER BY y DESC', $got );
	}

	public function test_injects_before_group_by(): void {
		$sql = 'SELECT a, COUNT(*) c FROM t WHERE q = 1 GROUP BY a ORDER BY c';
		$got = \OISCL_Utm_Query_Helper::inject_before_group_order_limit( $sql, ' AND utm = 1' );
		$this->assertSame( 'SELECT a, COUNT(*) c FROM t WHERE q = 1 AND utm = 1 GROUP BY a ORDER BY c', $got );
	}

	public function test_injects_before_limit(): void {
		$sql = 'SELECT * FROM t WHERE id > 0 LIMIT 10';
		$got = \OISCL_Utm_Query_Helper::inject_before_group_order_limit( $sql, ' AND p = 3' );
		$this->assertSame( 'SELECT * FROM t WHERE id > 0 AND p = 3 LIMIT 10', $got );
	}

	public function test_appends_when_no_clause(): void {
		$sql = 'SELECT * FROM t WHERE u = 7';
		$got = \OISCL_Utm_Query_Helper::inject_before_group_order_limit( $sql, ' AND k = 9' );
		$this->assertSame( 'SELECT * FROM t WHERE u = 7 AND k = 9', $got );
	}

	public function test_empty_fragment_returns_sql_unchanged(): void {
		$sql = 'SELECT * FROM t ORDER BY id';
		$got = \OISCL_Utm_Query_Helper::inject_before_group_order_limit( $sql, '' );
		$this->assertSame( $sql, $got );
	}

	public function test_funnel_csv_sections_global_only(): void {
		$got = \OISCL_Utm_Query_Helper::funnel_csv_sections( 'global' );
		$this->assertSame(
			array(
				'global'   => true,
				'company'  => false,
				'campaign' => false,
			),
			$got
		);
	}

	public function test_funnel_csv_sections_complete_all_blocks(): void {
		$got = \OISCL_Utm_Query_Helper::funnel_csv_sections( 'COMPLETE' );
		$this->assertSame(
			array(
				'global'   => true,
				'company'  => true,
				'campaign' => true,
			),
			$got
		);
	}

	public function test_funnel_csv_sections_both_company_and_campaign(): void {
		$got = \OISCL_Utm_Query_Helper::funnel_csv_sections( 'both' );
		$this->assertSame(
			array(
				'global'   => false,
				'company'  => true,
				'campaign' => true,
			),
			$got
		);
	}

	public function test_funnel_csv_sections_company_only(): void {
		$got = \OISCL_Utm_Query_Helper::funnel_csv_sections( 'company' );
		$this->assertSame(
			array(
				'global'   => false,
				'company'  => true,
				'campaign' => false,
			),
			$got
		);
	}

	public function test_funnel_csv_sections_campaign_only(): void {
		$got = \OISCL_Utm_Query_Helper::funnel_csv_sections( 'campaign' );
		$this->assertSame(
			array(
				'global'   => false,
				'company'  => false,
				'campaign' => true,
			),
			$got
		);
	}

	public function test_funnel_csv_sections_unknown_scope(): void {
		$got = \OISCL_Utm_Query_Helper::funnel_csv_sections( 'invalid-scope' );
		$this->assertSame(
			array(
				'global'   => false,
				'company'  => false,
				'campaign' => false,
			),
			$got
		);
	}
}
