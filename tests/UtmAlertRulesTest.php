<?php
/**
 * @package OIS_Conversion_Suite
 */

namespace OISCL\Tests;

use PHPUnit\Framework\TestCase;

final class UtmAlertRulesTest extends TestCase {

	public function test_drop_no_alert_when_prior_below_minimum(): void {
		$this->assertFalse( \OISCL_Utm_Alert_Rules::should_alert_drop( 4, 0, 30 ) );
		$this->assertFalse( \OISCL_Utm_Alert_Rules::should_alert_drop( 4, 10, 30 ) );
	}

	public function test_drop_alert_when_curr_below_threshold(): void {
		$this->assertTrue( \OISCL_Utm_Alert_Rules::should_alert_drop( 10, 6, 30 ) );
		$this->assertFalse( \OISCL_Utm_Alert_Rules::should_alert_drop( 10, 7, 30 ) );
	}

	public function test_drop_boundary_prev_exactly_five(): void {
		$this->assertTrue( \OISCL_Utm_Alert_Rules::should_alert_drop( 5, 3, 30 ) );
		$this->assertFalse( \OISCL_Utm_Alert_Rules::should_alert_drop( 5, 3, 40 ) );
	}

	public function test_zero_window(): void {
		$this->assertTrue( \OISCL_Utm_Alert_Rules::should_alert_zero_window( 0, 1 ) );
		$this->assertFalse( \OISCL_Utm_Alert_Rules::should_alert_zero_window( 1, 1 ) );
		$this->assertFalse( \OISCL_Utm_Alert_Rules::should_alert_zero_window( 0, 0 ) );
	}
}
