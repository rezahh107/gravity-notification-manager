<?php
/**
 * WU-09 legacy runtime retirement regression.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;

/**
 * Proves the production tree cannot boot the retired notification runtime.
 */
final class LegacyRuntimeRetirementTest extends TestCase {
	/**
	 * Retired runtime paths must be physically absent.
	 *
	 * @return void
	 */
	public function test_retired_runtime_paths_are_absent(): void {
		$root = dirname( __DIR__, 3 );
		foreach (
			array(
				'includes/Core/Bootstrap.php',
				'includes/Plugin.php',
				'includes/Integration',
				'includes/Domain',
				'includes/Infrastructure',
				'includes/Services',
				'includes/Admin/DoctorPage.php',
				'includes/Admin/Logs_Table.php',
				'includes/Admin/Settings_Fields.php',
				'includes/Admin/Settings_Page.php',
			) as $path
		) {
			self::assertFileDoesNotExist( $root . '/' . $path, $path . ' must be retired.' );
		}
	}

	/**
	 * The plugin entrypoint must boot greenfield runtime only.
	 *
	 * @return void
	 */
	public function test_plugin_entrypoint_has_no_legacy_runtime_bootstrap(): void {
		$entrypoint = file_get_contents( dirname( __DIR__, 3 ) . '/gravityflow-sms-ippanel.php' );
		self::assertIsString( $entrypoint );
		self::assertStringContainsString( 'ProductionRuntime::boot()', $entrypoint );
		self::assertStringNotContainsString( 'LegacyRuntimeGuard::boot()', $entrypoint );
		self::assertStringNotContainsString( 'MigrationAdminController::boot()', $entrypoint );
		self::assertStringNotContainsString( 'GFSMS\\Core\\Bootstrap::init()', $entrypoint );
	}
}
