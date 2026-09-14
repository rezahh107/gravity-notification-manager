<?php
/**
 * Operational log admin/schema contract tests.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/** OperationalLogAdminContractTest implementation. */
final class OperationalLogAdminContractTest extends TestCase {

	/**
	 * Test log surface has separate sms bale views and llm copy action.
	 */
	public function test_log_surface_has_separate_sms_bale_views_and_llm_copy_action(): void {
		$root   = dirname( __DIR__, 3 );
		$source = file_get_contents( $root . '/src/Admin/OperationalLogAdmin.php' );
		self::assertIsString( $source );
		self::assertStringContainsString( "__( 'SMS log', 'gravity-notification-manager' )", $source );
		self::assertStringContainsString( "__( 'Bale log', 'gravity-notification-manager' )", $source );
		self::assertStringContainsString( "esc_html__( 'Copy LLM Debug Report', 'gravity-notification-manager' )", $source );
		self::assertStringContainsString( 'LlmDebugReport::build( $event )', $source );
		self::assertStringContainsString( 'WordPressOperationalEventStore::production()->latest(', $source );
	}

	/**
	 * Test log render path contains no provider or network send.
	 */
	public function test_log_render_path_contains_no_provider_or_network_send(): void {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/src/Admin/OperationalLogAdmin.php' );
		self::assertIsString( $source );
		self::assertStringNotContainsString( 'ProviderTestService', $source );
		self::assertStringNotContainsString( 'WordPressHttpTransport', $source );
		self::assertStringNotContainsString( 'wp_remote_', $source );
		self::assertStringNotContainsString( '->send(', $source );
	}

	/**
	 * Test greenfield schema is separate versioned and upgrade checked.
	 */
	public function test_greenfield_schema_is_separate_versioned_and_upgrade_checked(): void {
		$root      = dirname( __DIR__, 3 );
		$installer = file_get_contents( $root . '/src/Observability/OperationalLogInstaller.php' );
		$entry     = file_get_contents( $root . '/gravityflow-sms-ippanel.php' );
		self::assertIsString( $installer );
		self::assertIsString( $entry );
		self::assertStringContainsString( "'gravity_notify_operational_events'", $installer );
		self::assertStringContainsString( "'plugins_loaded'", $installer );
		self::assertStringContainsString( 'dbDelta( $sql )', $installer );
		self::assertStringContainsString( 'OperationalLogInstaller::boot()', $entry );
		self::assertStringNotContainsString( 'GFSMS\\Logging\\Logger', $entry );
		self::assertStringNotContainsString( 'gfsms_logs', $installer );
	}

	/**
	 * Test legacy logger has no production runtime consumer.
	 */
	public function test_legacy_logger_has_no_production_runtime_consumer(): void {
		$root       = dirname( __DIR__, 3 );
		$entrypoint = file_get_contents( $root . '/gravityflow-sms-ippanel.php' );
		$runtime    = file_get_contents( $root . '/src/Migration/ProductionRuntime.php' );
		self::assertIsString( $entrypoint );
		self::assertIsString( $runtime );
		self::assertStringNotContainsString( 'GFSMS\\Logging\\Logger', $entrypoint );
		self::assertStringNotContainsString( 'GFSMS\\Logging\\Logger', $runtime );
		self::assertStringContainsString( 'GravityNotify\\Observability\\OperationalLogger', $runtime );
	}
}
