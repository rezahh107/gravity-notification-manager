<?php
/**
 * Provider test admin endpoint and rendering contracts.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/**
 * Locks the explicit-POST-only and privacy/state boundaries around provider tests.
 */
final class ProviderTestAdminContractTest extends TestCase {

	/** SMS and Bale tests are reachable only through their explicit authenticated admin-post owners. */
	public function test_provider_tests_are_registered_as_explicit_post_actions(): void {
		$controller = $this->controller_source();
		$provider   = $this->provider_manager_source();

		self::assertStringContainsString( "admin_post_' . AdminDefinition::TEST_BALE_ACTION", $controller );
		self::assertStringContainsString( "admin_post_' . AdminDefinition::PROVIDER_TEST_SMS_ACTION", $provider );
		self::assertStringContainsString( 'self::bale_test_nonce_action()', $controller );
		self::assertStringContainsString( 'self::nonce_action()', $provider );
		self::assertStringContainsString( 'Real external send:', $controller );
		self::assertStringContainsString( 'Real external send:', $provider );
		self::assertStringNotContainsString( 'TEST_SMS_ACTION', $controller );
	}

	/** Capability and nonce checks precede both production provider-test executions. */
	public function test_capability_and_nonce_guard_precede_provider_test_execution(): void {
		$bale = $this->method_section(
			$this->controller_source(),
			'public static function handle_test_bale',
			'private static function render_bale_test_control'
		);
		$sms  = $this->method_section(
			$this->provider_manager_source(),
			'public static function handle_test_sms',
			'private static function render_test_control'
		);

		$this->assert_guard_order( $bale );
		$this->assert_guard_order( $sms );
	}

	/** GET/rendering Settings and normal Settings API saves contain no send invocation. */
	public function test_rendering_and_saving_settings_send_nothing(): void {
		$render   = $this->method_section( $this->controller_source(), 'public static function render_settings', 'public static function render_advisor' );
		$settings = file_get_contents( dirname( __DIR__, 3 ) . '/src/Admin/Settings.php' );

		self::assertIsString( $settings );
		self::assertStringNotContainsString( 'ProviderTestService::production()', $render );
		self::assertStringNotContainsString( '->test_sms(', $render );
		self::assertStringNotContainsString( '->test_bale(', $render );
		self::assertStringNotContainsString( 'wp_remote_post(', $render );
		self::assertStringNotContainsString( 'WordPressHttpTransport', $settings );
		self::assertStringNotContainsString( 'IPPanelProvider', $settings );
		self::assertStringNotContainsString( 'BaleClient', $settings );
	}

	/** Redirect notices persist only bounded status/diagnostic/reference facts. */
	public function test_result_notice_excludes_credentials_destinations_and_raw_provider_bodies(): void {
		$bale_store = $this->method_section(
			$this->controller_source(),
			'private static function store_bale_test_notice',
			'private static function bale_test_diagnostics'
		);
		$sms_store  = $this->method_section(
			$this->provider_manager_source(),
			'private static function store_test_notice',
			'private static function safe_diagnostics'
		);

		foreach ( array( $bale_store, $sms_store ) as $store ) {
			self::assertStringContainsString( '$result->provider_references()', $store );
			self::assertStringContainsString( '/^[A-Za-z0-9._:-]{1,128}$/D', $store );
			self::assertStringNotContainsString( 'ippanel_api_key', $store );
			self::assertStringNotContainsString( 'bale_bot_token', $store );
			self::assertStringNotContainsString( 'destination', $store );
			self::assertStringNotContainsString( 'body()', $store );
			self::assertStringNotContainsString( 'response', $store );
		}
	}

	/** Status notices accept only SUCCESS/FAILED/AMBIGUOUS and safe references. */
	public function test_user_visible_result_is_bounded_to_required_truth_states(): void {
		$source = $this->controller_source() . $this->provider_manager_source();

		self::assertStringContainsString( 'AttemptStatus::SUCCESS, AttemptStatus::FAILED, AttemptStatus::AMBIGUOUS', $source );
		self::assertStringContainsString( "__( 'Result: %s', 'gravity-notification-manager' )", $source );
		self::assertStringContainsString( "esc_html__( 'Provider reference:', 'gravity-notification-manager' )", $source );
	}

	/** Production reachability is entrypoint to the dedicated SMS owner and retained Bale owner. */
	public function test_provider_test_paths_are_reachable_from_production_entrypoint(): void {
		$root       = dirname( __DIR__, 3 );
		$entrypoint = file_get_contents( $root . '/gravityflow-sms-ippanel.php' );
		$controller = $this->controller_source();
		$provider   = $this->provider_manager_source();

		self::assertIsString( $entrypoint );
		self::assertStringContainsString( 'AdminController::boot()', $entrypoint );
		self::assertStringContainsString( 'ProviderManagerAdmin::boot()', $entrypoint );
		self::assertStringContainsString( 'AdminDefinition::TEST_BALE_ACTION', $controller );
		self::assertStringContainsString( 'AdminDefinition::PROVIDER_TEST_SMS_ACTION', $provider );
		self::assertStringContainsString( 'ProviderTestService::production()', $controller );
		self::assertStringContainsString( 'ProviderTestService::production()', $provider );
	}

	/**
	 * Assert capability -> nonce -> service ordering for one handler section.
	 *
	 * @param string $section Handler source section.
	 * @return void
	 */
	private function assert_guard_order( string $section ): void {
		$capability = strpos( $section, 'guard_capability();' );
		$nonce      = strpos( $section, 'check_admin_referer(' );
		$service    = strpos( $section, 'ProviderTestService::production()' );

		self::assertIsInt( $capability );
		self::assertIsInt( $nonce );
		self::assertIsInt( $service );
		self::assertLessThan( $nonce, $capability );
		self::assertLessThan( $service, $nonce );
	}

	/**
	 * Read the current admin controller source.
	 *
	 * @return string
	 */
	private function controller_source(): string {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/src/Admin/AdminController.php' );
		self::assertIsString( $source );
		return $source;
	}

	/**
	 * Read the current Provider Manager admin source.
	 *
	 * @return string
	 */
	private function provider_manager_source(): string {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/src/Admin/ProviderManagerAdmin.php' );
		self::assertIsString( $source );
		return $source;
	}

	/**
	 * Extract one source interval without evaluating WordPress globals.
	 *
	 * @param string $source       Source text.
	 * @param string $start_marker Start marker.
	 * @param string $end_marker   End marker.
	 * @return string
	 */
	private function method_section( string $source, string $start_marker, string $end_marker ): string {
		$start = strpos( $source, $start_marker );
		$end   = strpos( $source, $end_marker, false === $start ? 0 : $start );

		self::assertIsInt( $start );
		self::assertIsInt( $end );
		self::assertGreaterThan( $start, $end );
		return substr( $source, $start, $end - $start );
	}
}
