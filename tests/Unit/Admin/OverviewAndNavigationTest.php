<?php
/**
 * Overview, diagnostics, and Gravity Flow navigation contract tests.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Unit\Admin;

use GravityNotify\Admin\Environment;
use GravityNotify\Admin\GravityFlowNavigation;
use GravityNotify\Admin\OverviewSummary;
use GravityNotify\Admin\PointStatus;
use PHPUnit\Framework\TestCase;

/**
 * Proves bounded operational derivation without adding state or side effects.
 */
final class OverviewAndNavigationTest extends TestCase {

	/**
	 * Overview counts are derived only from inspected Point states.
	 *
	 * @return void
	 */
	public function test_overview_summary_derives_truthful_point_counts(): void {
		$summary = OverviewSummary::from_points(
			array(
				array( 'state' => PointStatus::CONFIGURED ),
				array( 'state' => PointStatus::NEEDS_SETUP ),
				array( 'state' => PointStatus::NOT_APPLICABLE ),
				array( 'state' => PointStatus::DISABLED ),
			)
		);

		self::assertSame( 4, $summary['total'] );
		self::assertSame( 1, $summary['configured'] );
		self::assertSame( 1, $summary['needs_setup'] );
		self::assertSame( 1, $summary['not_applicable'] );
		self::assertSame( 1, $summary['disabled'] );
	}

	/**
	 * Navigation resolves only when a positive Form ID and Flow availability exist.
	 *
	 * @return void
	 */
	public function test_gravity_flow_navigation_is_direct_when_resolvable_and_absent_otherwise(): void {
		self::assertSame(
			'admin.php?page=gf_edit_forms&view=settings&subview=gravityflow&id=4',
			GravityFlowNavigation::relative_path( 4, true )
		);
		self::assertNull( GravityFlowNavigation::relative_path( 4, false ) );
		self::assertNull( GravityFlowNavigation::relative_path( 0, true ) );
	}

	/**
	 * Missing optional products produce facts rather than fatal errors.
	 *
	 * @return void
	 */
	public function test_environment_facts_are_side_effect_free_when_optional_dependencies_are_missing(): void {
		$facts = Environment::facts();
		self::assertArrayHasKey( 'WordPress', $facts );
		self::assertArrayHasKey( 'PHP', $facts );
		self::assertArrayHasKey( 'Gravity Forms', $facts );
		self::assertArrayHasKey( 'Gravity Flow', $facts );
		self::assertArrayHasKey( 'GravityView', $facts );
		self::assertArrayHasKey( 'Feed/Flow', $facts );
		self::assertStringNotContainsString( 'token', strtolower( json_encode( $facts ) ) );
	}
}
