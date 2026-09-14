<?php
/**
 * Canonical uninstall regression coverage for operational observability artifacts.
 *
 * @package GravityNotify
 */

namespace GFSMS\Lifecycle {
	/** Test double for the WordPress option deletion primitive used by the canonical uninstaller. */
	function delete_option( string $option ): bool { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Namespaced WordPress core test double.
		$GLOBALS['gravity_notify_uninstall_deleted_options'][] = $option;
		if ( array_key_exists( $option, $GLOBALS['gravity_notify_uninstall_options'] ) ) {
			unset( $GLOBALS['gravity_notify_uninstall_options'][ $option ] );
			return true;
		}
		return false;
	}
}

namespace GravityNotify\Observability {
	/** Test double for the WordPress option deletion primitive used by the operational schema owner. */
	function delete_option( string $option ): bool { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Namespaced WordPress core test double.
		$GLOBALS['gravity_notify_uninstall_deleted_options'][] = $option;
		if ( array_key_exists( $option, $GLOBALS['gravity_notify_uninstall_options'] ) ) {
			unset( $GLOBALS['gravity_notify_uninstall_options'][ $option ] );
			return true;
		}
		return false;
	}
}

namespace GravityNotify\Tests\Unit\Lifecycle {

	use GFSMS\Lifecycle\Uninstaller;
	use GravityNotify\Observability\OperationalLogInstaller;
	use PHPUnit\Framework\TestCase;

	/**
	 * Proves the single canonical uninstall boundary removes both legacy and operational artifacts.
	 */
	final class OperationalUninstallCleanupTest extends TestCase {

		private OperationalUninstallWpdb $db;

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

			$this->db = new OperationalUninstallWpdb();
			$GLOBALS['wpdb'] = $this->db; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated wpdb test double.
			$GLOBALS['gravity_notify_uninstall_deleted_options'] = array();
			$GLOBALS['gravity_notify_uninstall_options'] = array(
				GFSMS_SETTINGS_OPTION                    => 'legacy-settings',
				'gfsms_cached_senders'                  => 'legacy-cache',
				'gfsms_version'                         => '3.3.0',
				OperationalLogInstaller::VERSION_OPTION => OperationalLogInstaller::SCHEMA_VERSION,
			);
		}

		protected function tearDown(): void {
			unset(
				$GLOBALS['wpdb'],
				$GLOBALS['gravity_notify_uninstall_deleted_options'],
				$GLOBALS['gravity_notify_uninstall_options']
			);
		}

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

	/** Minimal wpdb-compatible uninstall test double with real table-presence state. */
	final class OperationalUninstallWpdb {

		public string $prefix = 'wp_';

		/** @var array<string, bool> */
		public array $tables = array();

		/** @var array<int, string> */
		private array $queries = array();

		public function prepare( string $query, string $identifier ): string {
			return str_replace( '%i', $identifier, $query );
		}

		public function query( string $query ): int {
			$this->queries[] = $query;
			if ( 1 === preg_match( '/^DROP TABLE IF EXISTS ([A-Za-z0-9_]+)$/', $query, $matches ) ) {
				unset( $this->tables[ $matches[1] ] );
				return 1;
			}
			return 0;
		}

		public function table_exists( string $table ): bool {
			return isset( $this->tables[ $table ] );
		}

		public function drop_count( string $table ): int {
			return count( array_filter(
				$this->queries,
				static fn( string $query ): bool => 'DROP TABLE IF EXISTS ' . $table === $query
			) );
		}
	}
}
