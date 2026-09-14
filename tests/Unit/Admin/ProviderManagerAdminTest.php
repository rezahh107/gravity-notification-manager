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

/** Proves explicit-only provider actions and side-effect-free render/save boundaries. */
final class ProviderManagerAdminTest extends TestCase {

	/** Provider Manager is production-reachable and directly discoverable under GNM. */
	public function test_provider_manager_is_booted_and_registers_direct_sms_providers_submenu(): void {
		$root       = dirname( __DIR__, 3 );
		$entrypoint = file_get_contents( $root . '/gravityflow-sms-ippanel.php' );
		$source     = $this->source();

		self::assertIsString( $entrypoint );
		self::assertStringContainsString( 'ProviderManagerAdmin::boot()', $entrypoint );
		self::assertStringContainsString( 'add_submenu_page(', $source );
		self::assertStringContainsString( 'AdminDefinition::PROVIDERS_SLUG', $source );
		self::assertStringContainsString( "esc_html__( 'SMS Providers', 'gravity-notification-manager' )", $source );
	}

	/** All approved providers and applicable explicit actions are represented. */
	public function test_surface_exposes_all_approved_provider_types_and_actions(): void {
		$manager = file_get_contents( dirname( __DIR__, 3 ) . '/src/Provider/SmsProviderManager.php' );
		$source  = $this->source();
		self::assertIsString( $manager );
		foreach ( array( 'IPPanel', 'Melipayamak', 'SMS.ir', 'FarazSMS' ) as $label ) {
			self::assertStringContainsString( "'label'", $manager );
			self::assertStringContainsString( $label, $manager );
		}
		self::assertStringContainsString( 'AdminDefinition::PROVIDER_CHECK_CONNECTION_ACTION', $source );
		self::assertStringContainsString( 'AdminDefinition::PROVIDER_DISCOVER_LINES_ACTION', $source );
		self::assertStringContainsString( 'AdminDefinition::PROVIDER_TEST_SMS_ACTION', $source );
		self::assertStringContainsString( "esc_html__( 'Real external send', 'gravity-notification-manager' )", $source );
	}

	/** Real tests exist only behind explicit admin-post action with capability and target nonce guards. */
	public function test_real_send_action_is_explicit_and_guarded_before_service_execution(): void {
		$section    = $this->method_section( 'public static function handle_test_sms', 'public static function handle_check_connection' );
		$capability = strpos( $section, 'self::guard_capability();' );
		$nonce      = strpos( $section, 'check_admin_referer(' );
		$service    = strpos( $section, 'ProviderTestService::production()' );
		self::assertIsInt( $capability );
		self::assertIsInt( $nonce );
		self::assertIsInt( $service );
		self::assertLessThan( $nonce, $capability );
		self::assertLessThan( $service, $nonce );
	}

	/** Connection and discovery are separate explicit protected actions. */
	public function test_connection_and_discovery_are_separate_explicit_actions(): void {
		$source = $this->source();
		self::assertStringContainsString( "admin_post_' . AdminDefinition::PROVIDER_CHECK_CONNECTION_ACTION", $source );
		self::assertStringContainsString( "admin_post_' . AdminDefinition::PROVIDER_DISCOVER_LINES_ACTION", $source );
		self::assertStringContainsString( 'ProviderConnectionService::production()->check(', $source );
		self::assertStringContainsString( 'ProviderDiscoveryService::production()->discover(', $source );
	}

	/** Unauthorized direct action fails before request/provider work. */
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
		self::assertStringNotContainsString( 'ProviderConnectionService::production()', $render );
		self::assertStringNotContainsString( 'ProviderDiscoveryService::production()', $render );
		self::assertStringNotContainsString( 'wp_remote_', $render );
		self::assertStringNotContainsString( 'WordPressHttpTransport', $settings );
		self::assertStringNotContainsString( 'wp_remote_', $settings );
		self::assertStringNotContainsString( '->send(', $manager );
	}

	/** Failed discovery cannot erase a previously valid sender because persistence is success-gated. */
	public function test_failed_discovery_does_not_persist_or_erase_sender(): void {
		$section = $this->method_section( 'public static function handle_discover_lines', 'private static function render_provider_settings' );
		self::assertStringContainsString( 'if ( $result->successful() )', $section );
		self::assertStringContainsString( 'Settings::persist_discovered_lines(', $section );
		$success = strpos( $section, 'if ( $result->successful() )' );
		$persist = strpos( $section, 'Settings::persist_discovered_lines(' );
		self::assertIsInt( $success );
		self::assertIsInt( $persist );
		self::assertLessThan( $persist, $success );
	}

	/** TEST/provider-management paths cannot write Feed/Retry/Entry Meta delivery history. */
	public function test_provider_manager_has_no_feed_retry_or_entry_meta_write_path(): void {
		$source = $this->source();
		self::assertStringNotContainsString( 'NotificationFeedProcessor', $source );
		self::assertStringNotContainsString( 'ManualRetryHandler', $source );
		self::assertStringNotContainsString( 'EntryMetaDeliveryStore', $source );
		self::assertStringNotContainsString( 'gform_update_meta', $source );
		self::assertStringNotContainsString( 'GFAPI', $source );
	}

	/** Result notices retain only safe status/diagnostic/reference/count evidence. */
	public function test_result_notice_excludes_credentials_destination_and_raw_response(): void {
		$store = $this->method_section( 'private static function store_test_notice', 'private static function store_connection_notice' );
		self::assertStringContainsString( '$result->provider_references()', $store );
		self::assertStringContainsString( '/^[A-Za-z0-9._:-]{1,128}$/D', $store );
		self::assertStringNotContainsString( 'api_key', $store );
		self::assertStringNotContainsString( 'destination', $store );
		self::assertStringNotContainsString( 'body()', $store );
	}

	private function source(): string {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/src/Admin/ProviderManagerAdmin.php' );
		self::assertIsString( $source );
		return $source;
	}

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
