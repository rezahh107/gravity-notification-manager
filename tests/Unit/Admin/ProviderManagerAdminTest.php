<?php
/**
 * Provider Manager admin surface/action contract tests.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Unit\Admin;

use GravityNotify\Admin\ProviderManagerAdmin;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Proves explicit-only provider actions and side-effect-free rendering/saving boundaries. */
final class ProviderManagerAdminTest extends TestCase {

	/** Provider Manager is production-reachable and directly discoverable under GNM. */
	public function test_provider_manager_is_booted_and_registers_a_direct_submenu(): void {
		$root       = dirname( __DIR__, 3 );
		$entrypoint = file_get_contents( $root . '/gravityflow-sms-ippanel.php' );
		$source     = $this->source();

		self::assertIsString( $entrypoint );
		self::assertStringContainsString( 'ProviderManagerAdmin::boot()', $entrypoint );
		self::assertStringContainsString( 'add_submenu_page(', $source );
		self::assertStringContainsString( 'AdminDefinition::PROVIDERS_SLUG', $source );
		self::assertStringContainsString( "esc_html__( 'SMS Providers / IPPanel', 'gravity-notification-manager' )", $source );
	}

	/** Provider Manager owns both IPPanel configuration controls and its production test action. */
	public function test_provider_manager_contains_canonical_ippanel_configuration_and_test_controls(): void {
		$source = $this->source();
		self::assertStringContainsString( "esc_html__( 'IPPanel API key', 'gravity-notification-manager' )", $source );
		self::assertStringContainsString( "esc_html__( 'SMS sender number (E.164)', 'gravity-notification-manager' )", $source );
		self::assertStringContainsString( 'AdminDefinition::PROVIDER_TEST_SMS_ACTION', $source );
		self::assertStringContainsString( "ProviderTestService::production()->test_sms(", $source );
	}

	/** Real SMS tests exist only behind an explicit admin-post action with capability and nonce guards. */
	public function test_real_send_action_is_explicit_and_guarded_before_service_execution(): void {
		$source  = $this->source();
		$section = $this->method_section( 'public static function handle_test_sms', 'private static function render_test_control' );

		self::assertStringContainsString( "admin_post_' . AdminDefinition::PROVIDER_TEST_SMS_ACTION", $source );
		self::assertStringContainsString( 'Real external send:', $source );
		$capability = strpos( $section, 'self::guard_capability();' );
		$nonce      = strpos( $section, 'check_admin_referer(' );
		$service    = strpos( $section, 'ProviderTestService::production()' );
		self::assertIsInt( $capability );
		self::assertIsInt( $nonce );
		self::assertIsInt( $service );
		self::assertLessThan( $nonce, $capability );
		self::assertLessThan( $service, $nonce );
	}

	/** Unauthorized direct action fails before reading the destination or touching a provider. */
	public function test_provider_test_action_fails_closed_without_wordpress_authorization(): void {
		$this->expectException( RuntimeException::class );
		ProviderManagerAdmin::handle_test_sms();
	}

	/** Rendering and ordinary Settings API save paths contain no provider/network operation. */
	public function test_render_and_save_paths_do_not_contact_provider(): void {
		$render   = $this->method_section( 'public static function render', 'public static function handle_test_sms' );
		$settings = file_get_contents( dirname( __DIR__, 3 ) . '/src/Admin/Settings.php' );
		$manager  = file_get_contents( dirname( __DIR__, 3 ) . '/src/Provider/SmsProviderManager.php' );

		self::assertIsString( $settings );
		self::assertIsString( $manager );
		self::assertStringNotContainsString( 'ProviderTestService::production()', $render );
		self::assertStringNotContainsString( '->test_sms(', $render );
		self::assertStringNotContainsString( 'wp_remote_', $render );
		self::assertStringNotContainsString( 'WordPressHttpTransport', $settings );
		self::assertStringNotContainsString( 'wp_remote_', $settings );
		self::assertStringNotContainsString( '->send(', $manager );
	}

	/** No speculative sender-line discovery endpoint or implicit refresh action is present. */
	public function test_sender_line_discovery_is_not_fabricated(): void {
		$source = $this->source();
		self::assertStringContainsString( 'Sender-line discovery is intentionally absent', $source );
		self::assertStringNotContainsString( 'fetch_lines', $source );
		self::assertStringNotContainsString( 'sender_lines', $source );
		self::assertStringNotContainsString( 'wp_remote_get(', $source );
	}

	/** Provider Manager test results cannot write Feed/Retry/Entry Meta delivery history. */
	public function test_provider_manager_has_no_feed_retry_or_entry_meta_write_path(): void {
		$source = $this->source();
		self::assertStringNotContainsString( 'NotificationFeedProcessor', $source );
		self::assertStringNotContainsString( 'ManualRetryHandler', $source );
		self::assertStringNotContainsString( 'EntryMetaDeliveryStore', $source );
		self::assertStringNotContainsString( 'gform_update_meta', $source );
		self::assertStringNotContainsString( 'GFAPI', $source );
	}

	/** Provider test notice persists only bounded status/diagnostic/reference facts. */
	public function test_result_notice_excludes_credentials_destination_and_raw_response(): void {
		$store = $this->method_section( 'private static function store_test_notice', 'private static function safe_diagnostics' );
		self::assertStringContainsString( '$result->provider_references()', $store );
		self::assertStringContainsString( '/^[A-Za-z0-9._:-]{1,128}$/D', $store );
		self::assertStringNotContainsString( 'api_key', $store );
		self::assertStringNotContainsString( 'destination', $store );
		self::assertStringNotContainsString( 'body()', $store );
	}

	/** Read Provider Manager source. */
	private function source(): string {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/src/Admin/ProviderManagerAdmin.php' );
		self::assertIsString( $source );
		return $source;
	}

	/**
	 * Extract one source interval without evaluating WordPress globals.
	 *
	 * @param string $start_marker Start marker.
	 * @param string $end_marker   End marker.
	 * @return string
	 */
	private function method_section( string $start_marker, string $end_marker ): string {
		$source = $this->source();
		$start  = strpos( $source, $start_marker );
		$end    = strpos( $source, $end_marker, false === $start ? 0 : $start );
		self::assertIsInt( $start );
		self::assertIsInt( $end );
		self::assertGreaterThan( $start, $end );
		return substr( $source, $start, $end - $start );
	}
}
