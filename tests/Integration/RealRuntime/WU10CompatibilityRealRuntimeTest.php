<?php
/**
 * WU-10 compatibility smoke against real WordPress, Gravity Forms, and Gravity Flow packages.
 *
 * @package GravityNotify
 */

declare(strict_types=1);

namespace GravityNotify\Tests\Integration\RealRuntime;

use GravityNotify\GravityFlow\NotificationFeedStep;
use GravityNotify\GravityForms\NotificationFeedAddOn;
use WP_UnitTestCase;

/**
 * Verifies exact compatibility-cell runtime identity and authentic dependency loading.
 */
final class WU10CompatibilityRealRuntimeTest extends WP_UnitTestCase {
	/**
	 * @testdox WU10-COMPAT-REAL-01 exact runtime identity and authentic GF/Flow integration
	 */
	public function test_wu10_compat_real_01_exact_runtime_identity_and_authentic_dependencies(): void {
		global $wp_version;

		$expected_wp  = getenv( 'GNM_EXPECTED_WP_VERSION' );
		$expected_php = getenv( 'GNM_EXPECTED_PHP_VERSION' );
		self::assertIsString( $expected_wp );
		self::assertNotSame( '', $expected_wp );
		self::assertIsString( $expected_php );
		self::assertNotSame( '', $expected_php );

		$actual_php_minor = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
		self::assertSame( $expected_wp, $wp_version );
		self::assertSame( $expected_php, $actual_php_minor );
		self::assertNotSame( '', \GFForms::$version );
		self::assertTrue( defined( 'GRAVITY_FLOW_VERSION' ) );
		self::assertFalse( is_a( 'GFFeedAddOn', 'GravityNotify\\Tests\\Support\\GravityForms\\GFFeedAddOnStub', true ) );
		self::assertFalse( is_a( 'Gravity_Flow_Step_Feed_Add_On', 'GravityNotify\\Tests\\Support\\GravityFlow\\GravityFlowStepFeedAddOnStub', true ) );

		$add_on = NotificationFeedAddOn::get_instance();
		self::assertInstanceOf( \GFFeedAddOn::class, $add_on );
		self::assertContains( NotificationFeedAddOn::class, \GFAddOn::get_registered_addons() );

		$steps = \Gravity_Flow_Steps::get_all();
		self::assertArrayHasKey( 'gravity_notification_manager', $steps );
		self::assertInstanceOf( NotificationFeedStep::class, $steps['gravity_notification_manager'] );

		printf(
			"WU10_RUNTIME_IDENTITY wordpress=%s php=%s gravity_forms=%s gravity_flow=%s mode=compatibility\n",
			$wp_version,
			PHP_VERSION,
			\GFForms::$version,
			(string) constant( 'GRAVITY_FLOW_VERSION' )
		);
	}
}
