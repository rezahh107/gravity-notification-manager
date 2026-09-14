<?php
/**
 * Regression coverage for operational schema installation verification.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Unit\Observability;

use GravityNotify\Observability\OperationalLogInstaller;
use GravityNotify\Tests\Support\Observability\OperationalInstallerWpdbFake;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the installer treats its table name as a literal SQL LIKE pattern.
 */
final class OperationalLogInstallerTest extends TestCase {

	/** Load the isolated test doubles before exercising installation. */
	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/WordPress/DbDeltaStub.php';
		require_once dirname( __DIR__, 2 ) . '/Support/Observability/OperationalInstallerWpdbFake.php';
	}

	/** Prepare the minimal WordPress filesystem constant used by the installer. */
	protected function setUp(): void {
		if ( ! defined( 'ABSPATH' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core test environment constant.
			define( 'ABSPATH', dirname( __DIR__, 3 ) . '/' );
		}
	}

	/** Clear the isolated wpdb test double. */
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	/**
	 * Prove an earlier wildcard collision cannot mask the real operational table.
	 */
	public function test_install_escapes_like_pattern_and_ignores_wildcard_collision(): void {
		$expected  = 'wp_gravity_notify_operational_events';
		$collision = 'wpxgravity_notify_operational_events';
		$db        = new OperationalInstallerWpdbFake( array( $collision, $expected ) );

		$GLOBALS['wpdb'] = $db; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated wpdb test double.

		self::assertSame(
			$collision,
			$db->first_like_match( $expected ),
			'Raw underscores must demonstrate the wildcard collision this regression protects against.'
		);
		self::assertSame( $expected, $db->first_like_match( $db->esc_like( $expected ) ) );
		self::assertTrue( OperationalLogInstaller::install() );
		self::assertSame( $db->esc_like( $expected ), $db->last_prepared_like_pattern() );
	}
}
