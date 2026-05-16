<?php
/**
 * @package OIS_Conversion_Suite
 */

namespace OISCL\Tests;

use PHPUnit\Framework\TestCase;

final class TrackingNormalizeAnchorTest extends TestCase {

	public function test_maps_legacy_block_anchor_to_canonical(): void {
		$this->assertSame(
			\OISCL_Plan::EVENT_BLOCK_VIEW,
			\OISCL_Tracking::normalize_anchor_for_storage( \OISCL_Plan::EVENT_BLOCK_LEGACY )
		);
	}

	public function test_leaves_other_anchors_unchanged(): void {
		$this->assertSame( \OISCL_Plan::EVENT_PAGEVIEW, \OISCL_Tracking::normalize_anchor_for_storage( \OISCL_Plan::EVENT_PAGEVIEW ) );
		$this->assertSame( 'CTA Buy', \OISCL_Tracking::normalize_anchor_for_storage( 'CTA Buy' ) );
	}
}
