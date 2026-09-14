<?php
/**
 * Canonical uninstall regression coverage for operational observability artifacts.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Unit\Lifecycle;

use GFSMS\Lifecycle\Uninstaller;
use GravityNotify\Observability\OperationalLogInstaller;
use GravityNotify\Tests\Support\Lifecycle\OperationalUninstallWpdb;
use PHPUnit\Framework\TestCase;

/**
 * Proves the single canonical uninstall boundary removes both legacy and operational artifacts.
 */
final class OperationalUninstallCleanupTest extends TestCase {

		/**
		 * Stored value.
		 *
		 * @var OperationalUninstallWpdb
		 */
	private OperationalUninstallWpdb $db;

	/** Load the global WordPress option deletion stub before exercising uninstall code. */
	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/WordPress/DeleteOptionStub.php';
	}

	/** Prepare isolated legacy and operational uninstall state. */
	protected function setUp(): void {
		if ( ! defined( 'ABSPATH' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core test environment constant.
			define( 'ABSPATH', dirname( __DIR__, 3 ) . '/' );
		}
		if ( ! defined( 'GFSMS_SETTINGS_OPTION' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Existing legacy production constant.
			define( 'GFSMS_SETTINGS_OPTION', 'gfsms_settings' );
		}
		if ( ! defined( 'GFSMS_DB_TABLE_SUFFIX' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Existing legacy production constant.
			define( 'GFSMS_DB_TABLE_SUFFIX', 'gfsms_logs' );
		}

		$this->db        = new OperationalUninstallWpdb();
		$GLOBALS['wpdb'] = $this->db; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated wpdb test double.
		$GLOBALS['gravity_notify_uninstall_deleted_options'] = array();
		$GLOBALS['gravity_notify_uninstall_options']         = array(
			GFSMS_SETTINGS_OPTION                   => 'legacy-settings',
			'gfsms_cached_senders'                  => 'legacy-cache',
			'gfsms_version'                         => '3.3.0',
			OperationalLogInstaller::VERSION_OPTION => OperationalLogInstaller::SCHEMA_VERSION,
		);
	}

	/** Clear isolated uninstall state. */
	protected function tearDown(): void {
		unset(
			$GLOBALS['wpdb'],
			$GLOBALS['gravity_notify_uninstall_deleted_options'],
			$GLOBALS['gravity_notify_uninstall_options']
		);
	}

	/** Prove the canonical uninstall call removes operational and legacy persistent artifacts. */
	public function test_canonical_uninstall_removes_operational_and_legacy_artifacts(): void {
		$operational_table = OperationalLogInstaller::table_name( $this->db );
		$legacy_table      = $this->db->prefix . GFSMS_DB_TABLE_SUFFIX;

		$this->db->tables = array(
			$legacy_table      => true,
			$operational_table => true,
		);

		self::assertTrue( $this->db->table_exists( $legacy_table ) );
		self::assertTrue( $this->db->table_exists( $operational_table ) );
		self::assertArrayHasKey( OperationalLogInstaller::VERSION_OPTION, $GLOBALS['gravity_notify_uninstall_options'] );

		Uninstaller::uninstall();

		self::assertFalse( $this->db->table_exists( $operational_table ) );
		self::assertFalse( $this->db->table_exists( $legacy_table ) );
		self::assertArrayNotHasKey( OperationalLogInstaller::VERSION_OPTION, $GLOBALS['gravity_notify_uninstall_options'] );
		self::assertArrayNotHasKey( GFSMS_SETTINGS_OPTION, $GLOBALS['gravity_notify_uninstall_options'] );
		self::assertArrayNotHasKey( 'gfsms_cached_senders', $GLOBALS['gravity_notify_uninstall_options'] );
		self::assertArrayNotHasKey( 'gfsms_version', $GLOBALS['gravity_notify_uninstall_options'] );
		self::assertSame( 1, $this->db->drop_count( $operational_table ) );
		self::assertSame( 1, $this->db->drop_count( $legacy_table ) );
		self::assertSame(
			1,
			count( array_keys( $GLOBALS['gravity_notify_uninstall_deleted_options'], OperationalLogInstaller::VERSION_OPTION, true ) )
		);
	}

	/** Prove no parallel uninstall authority or duplicated operational identifiers were introduced. */
	public function test_canonical_uninstall_authority_remains_single_and_identifiers_are_not_duplicated(): void {
		$root         = dirname( __DIR__, 3 );
		$entrypoint   = file_get_contents( $root . '/gravityflow-sms-ippanel.php' );
		$uninstall    = file_get_contents( $root . '/uninstall.php' );
		$orchestrator = file_get_contents( $root . '/includes/Lifecycle/Uninstaller.php' );

		self::assertIsString( $entrypoint );
		self::assertIsString( $uninstall );
		self::assertIsString( $orchestrator );
		self::assertSame( 1, substr_count( $entrypoint, 'register_uninstall_hook(' ) );
		self::assertSame( 1, substr_count( $uninstall, '\\GFSMS\\Lifecycle\\Uninstaller::uninstall();' ) );
		self::assertStringContainsString( 'OperationalLogInstaller::uninstall();', $orchestrator );
		self::assertSame( 1, substr_count( $orchestrator, 'OperationalLogInstaller::uninstall();' ) );
		self::assertStringNotContainsString( OperationalLogInstaller::VERSION_OPTION, $orchestrator );
		self::assertStringNotContainsString( 'gravity_notify_operational_events', $orchestrator );
	}
}
