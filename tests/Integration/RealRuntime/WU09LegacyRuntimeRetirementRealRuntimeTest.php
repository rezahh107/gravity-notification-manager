<?php
/**
 * WU-09 real-runtime retirement assertions.
 *
 * @package GravityNotify
 */

declare(strict_types=1);

namespace GravityNotify\Tests\Integration\RealRuntime;

use GravityNotify\Migration\LegacyRuntimeGuard;
use GravityNotify\Migration\MigrationAdminController;
use GravityNotify\Migration\ProductionRuntime;
use WP_UnitTestCase;

/**
 * Proves only the greenfield notification runtime is registered after retirement.
 */
final class WU09LegacyRuntimeRetirementRealRuntimeTest extends WP_UnitTestCase {
	/**
	 * Retired sender classes and queue hooks are unavailable at runtime.
	 *
	 * @testdox WU09-RETIRE-REAL-01 legacy sender classes and notification queue hooks are absent
	 * @return void
	 */
	public function test_wu09_retire_real_01_legacy_sender_classes_and_hooks_are_absent(): void {
		foreach (
			array(
				'GFSMS\\Core\\Bootstrap',
				'GFSMS\\Plugin',
				'GFSMS\\Queue\\Event_Queue',
				'GFSMS\\Integration\\Listener',
				'GFSMS\\Integration\\Dispatcher',
				'GFSMS\\Integration\\GravityForms_Handler',
				'GFSMS\\Integration\\Sms_Sender',
				'GFSMS\\Infrastructure\\ProviderFactory',
				'GFSMS\\Services\\LockManager',
			) as $class
		) {
			self::assertFalse( class_exists( $class ), $class . ' must not be autoloadable.' );
		}

		self::assertFalse( has_action( 'gfsms_process_payload' ) );
		self::assertFalse( has_action( 'gfsms_retry_payload' ) );
	}

	/**
	 * Greenfield registration remains present while transition controllers stay dormant.
	 *
	 * @testdox WU09-RETIRE-REAL-02 greenfield boot remains registered without legacy transition controllers
	 * @return void
	 */
	public function test_wu09_retire_real_02_greenfield_boot_is_the_only_notification_bootstrap(): void {
		self::assertSame( 5, has_action( 'gform_loaded', array( ProductionRuntime::class, 'register' ) ) );
		self::assertFalse( has_action( 'gform_after_submission', array( LegacyRuntimeGuard::class, 'begin_direct' ) ) );
		self::assertFalse( has_action( 'gravityflow_step_complete', array( LegacyRuntimeGuard::class, 'begin_flow_step' ) ) );
		self::assertFalse( has_action( 'admin_post_' . MigrationAdminController::ROLLBACK_ACTION ) );
	}
}
