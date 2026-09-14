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

	/** Provider tests are reachable only through explicit authenticated admin-post actions. */
	public function test_provider_tests_are_registered_as_explicit_post_actions(): void {
		$source = $this->controller_source();

		self::assertStringContainsString( "admin_post_' . AdminDefinition::TEST_SMS_ACTION", $source );
		self::assertStringContainsString( "admin_post_' . AdminDefinition::TEST_BALE_ACTION", $source );
		self::assertStringContainsString( "self::provider_test_nonce_action( 'sms' )", $source );
		self::assertStringContainsString( "self::provider_test_nonce_action( 'bale' )", $source );
		self::assertStringContainsString( 'Real external send:', $source );
	}

	/** Capability and action-specific nonce checks occur before the service can send. */
	public function test_capability_and_nonce_guard_precede_provider_test_execution(): void {
		$section = $this->method_section( 'private static function handle_provider_test', 'private static function render_provider_test_controls' );

		$capability = strpos( $section, 'self::guard_capability();' );
		$nonce      = strpos( $section, 'check_admin_referer(' );
		$service    = strpos( $section, 'ProviderTestService::production()' );

		self::assertIsInt( $capability );
		self::assertIsInt( $nonce );
		self::assertIsInt( $service );
		self::assertLessThan( $nonce, $capability );
		self::assertLessThan( $service, $nonce );
	}

	/** GET/rendering Settings and normal Settings API saves contain no send invocation. */
	public function test_rendering_and_saving_settings_send_nothing(): void {
		$render   = $this->method_section( 'public static function render_settings', 'public static function render_diagnostics' );
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
		$store = $this->method_section( 'private static function store_provider_test_notice', 'private static function provider_test_diagnostics' );

		self::assertStringContainsString( '$result->provider_references()', $store );
		self::assertStringContainsString( '/^[A-Za-z0-9._:-]{1,128}$/D', $store );
		self::assertStringNotContainsString( 'ippanel_api_key', $store );
		self::assertStringNotContainsString( 'bale_bot_token', $store );
		self::assertStringNotContainsString( 'destination', $store );
		self::assertStringNotContainsString( 'body()', $store );
		self::assertStringNotContainsString( 'response', $store );
	}

	/** Status notice accepts only SUCCESS/FAILED/AMBIGUOUS and safe references. */
	public function test_user_visible_result_is_bounded_to_required_truth_states(): void {
		$source = $this->controller_source();

		self::assertStringContainsString( 'AttemptStatus::SUCCESS, AttemptStatus::FAILED, AttemptStatus::AMBIGUOUS', $source );
		self::assertStringContainsString( "__( 'Result: %s', 'gravity-notification-manager' )", $source );
		self::assertStringContainsString( "esc_html__( 'Provider reference:', 'gravity-notification-manager' )", $source );
	}

	/** Production reachability remains plugin entrypoint -> AdminController -> provider test handlers/service. */
	public function test_provider_test_path_is_reachable_from_production_entrypoint(): void {
		$root       = dirname( __DIR__, 3 );
		$entrypoint = file_get_contents( $root . '/gravityflow-sms-ippanel.php' );
		$controller = $this->controller_source();

		self::assertIsString( $entrypoint );
		self::assertStringContainsString( 'AdminController::boot()', $entrypoint );
		self::assertStringContainsString( 'AdminDefinition::TEST_SMS_ACTION', $controller );
		self::assertStringContainsString( 'AdminDefinition::TEST_BALE_ACTION', $controller );
		self::assertStringContainsString( 'ProviderTestService::production()', $controller );
	}

	/** @return string */
	private function controller_source(): string {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/src/Admin/AdminController.php' );
		self::assertIsString( $source );
		return $source;
	}

	/** Extract one source interval without evaluating WordPress globals. */
	private function method_section( string $start_marker, string $end_marker ): string {
		$source = $this->controller_source();
		$start  = strpos( $source, $start_marker );
		$end    = strpos( $source, $end_marker, false === $start ? 0 : $start );

		self::assertIsInt( $start );
		self::assertIsInt( $end );
		self::assertGreaterThan( $start, $end );
		return substr( $source, $start, $end - $start );
	}
}
