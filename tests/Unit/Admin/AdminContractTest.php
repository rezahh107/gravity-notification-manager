<?php
/**
 * Admin registration/security/style contract tests.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Unit\Admin;

use GravityNotify\Admin\AdminController;
use GravityNotify\Admin\AdminDefinition;
use GravityNotify\Admin\ConfigurationSourceInterface;
use GravityNotify\Admin\ProviderManagerAdmin;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * Proves the current GNM admin architecture and privileged action guards.
 */
final class AdminContractTest extends TestCase {

	/** Provider Manager extends the established IA without changing the capability boundary. */
	public function test_information_architecture_includes_provider_manager_with_native_capability(): void {
		self::assertSame(
			array( 'Overview', 'Notification Points', 'SMS Providers / IPPanel', 'Settings', 'Advisor', 'Help & Diagnostics' ),
			array_column( AdminDefinition::navigation_surfaces(), 'title' )
		);
		self::assertSame( 'manage_options', AdminDefinition::CAPABILITY );
		self::assertTrue( is_callable( array( AdminController::class, 'render_overview' ) ) );
		self::assertTrue( is_callable( array( AdminController::class, 'render_points' ) ) );
		self::assertTrue( is_callable( array( ProviderManagerAdmin::class, 'render' ) ) );
		self::assertTrue( is_callable( array( AdminController::class, 'render_settings' ) ) );
		self::assertTrue( is_callable( array( AdminController::class, 'render_advisor' ) ) );
		self::assertTrue( is_callable( array( AdminController::class, 'render_diagnostics' ) ) );
	}

	/** Provider Manager is the sole production UI/action owner for IPPanel administration. */
	public function test_provider_manager_is_sole_ippanel_ui_and_action_owner(): void {
		$root       = dirname( __DIR__, 3 );
		$controller = $this->controller_source();
		$provider   = file_get_contents( $root . '/src/Admin/ProviderManagerAdmin.php' );
		$definition = file_get_contents( $root . '/src/Admin/AdminDefinition.php' );
		$settings   = $this->method_section( $controller, 'public static function render_settings', 'public static function render_advisor' );
		$boot       = $this->method_section( $controller, 'public static function boot', 'public static function register_menus' );

		self::assertIsString( $provider );
		self::assertIsString( $definition );
		self::assertStringContainsString( "esc_html__( 'IPPanel API key', 'gravity-notification-manager' )", $provider );
		self::assertStringContainsString( "esc_html__( 'SMS sender number (E.164)', 'gravity-notification-manager' )", $provider );
		self::assertStringContainsString( "admin_post_' . AdminDefinition::PROVIDER_TEST_SMS_ACTION", $provider );
		self::assertStringContainsString( 'ProviderTestService::production()->test_sms(', $provider );

		self::assertStringNotContainsString( 'IPPanel API key', $settings );
		self::assertStringNotContainsString( 'SMS sender number (E.164)', $settings );
		self::assertStringNotContainsString( 'IPPanel / SMS test', $settings );
		self::assertStringNotContainsString( 'Send Test SMS', $settings );
		self::assertStringNotContainsString( 'ippanel_api_key', $settings );
		self::assertStringNotContainsString( 'sms_from_number', $settings );
		self::assertStringNotContainsString( 'TEST_SMS_ACTION', $boot );
		self::assertStringNotContainsString( 'handle_test_sms', $controller );
		self::assertStringNotContainsString( 'TEST_SMS_ACTION', $definition );
	}

	/** Settings retains Bale configuration and its explicit test route after IPPanel cutover. */
	public function test_settings_retains_bale_configuration_and_test_action(): void {
		$controller = $this->controller_source();
		$settings   = $this->method_section( $controller, 'public static function render_settings', 'public static function render_advisor' );
		$boot       = $this->method_section( $controller, 'public static function boot', 'public static function register_menus' );

		self::assertStringContainsString( "secret_field( 'bale_bot_token'", $settings );
		self::assertStringContainsString( 'render_bale_test_notice()', $settings );
		self::assertStringContainsString( 'render_bale_test_control()', $settings );
		self::assertStringContainsString( 'AdminDefinition::TEST_BALE_ACTION', $boot );
		self::assertStringContainsString( 'ProviderTestService::production()->test_bale(', $controller );
		self::assertStringContainsString( "esc_html__( 'Bale test', 'gravity-notification-manager' )", $controller );
	}

	/** Point configuration has no topology mutation method available. */
	public function test_point_configuration_contract_exposes_reads_only(): void {
		$methods = array_map(
			static fn( $method ): string => $method->getName(),
			( new ReflectionClass( ConfigurationSourceInterface::class ) )->getMethods()
		);
		self::assertSame( array( 'forms', 'feeds', 'workflow_placements' ), $methods );
	}

	/** With no WordPress authorization primitive loaded, privileged Check Again fails closed. */
	public function test_check_again_rejects_unauthorized_execution_before_reading_request(): void {
		$this->expectException( RuntimeException::class );
		AdminController::handle_check_again();
	}

	/** Endpoint source binds authorization, target nonce, strict redirect, and no-send/no-mutation rules. */
	public function test_privileged_check_again_endpoint_contains_nonce_identifier_and_no_send_guards(): void {
		$source = $this->controller_source();
		self::assertStringContainsString( 'check_admin_referer(', $source );
		self::assertStringContainsString( "admin_post_' . AdminDefinition::CHECK_ACTION", $source );
		self::assertStringContainsString( 'wp_safe_redirect(', $source );
		self::assertStringContainsString( 'wp_unslash(', $source );
		self::assertStringNotContainsString( '->send(', $source );
		self::assertStringNotContainsString( 'update_step', $source );
		self::assertStringNotContainsString( 'add_step', $source );
		self::assertStringNotContainsString( 'delete_step', $source );
	}

	/** Asset/style contract is scoped, token-aware, RTL/LTR-safe and non-animated. */
	public function test_admin_assets_are_scoped_and_accessibility_rtl_contract_is_present(): void {
		$controller = $this->controller_source();
		$provider   = file_get_contents( dirname( __DIR__, 3 ) . '/src/Admin/ProviderManagerAdmin.php' );
		$css        = file_get_contents( dirname( __DIR__, 3 ) . '/assets/admin/gnm-admin.css' );
		self::assertIsString( $provider );
		self::assertIsString( $css );
		self::assertStringContainsString( 'in_array( $hook_suffix, self::$screen_hooks, true )', $controller );
		self::assertStringContainsString( 'self::$screen_hook !== $hook_suffix', $provider );
		self::assertStringContainsString( "wp_style_is( 'wp-theme', 'registered' )", $provider );
		self::assertStringContainsString( '--wpds-', $css );
		self::assertStringContainsString( '.gnm-ltr', $css );
		self::assertStringContainsString( 'unicode-bidi: isolate', $css );
		self::assertStringContainsString( ':focus-visible', $css );
		self::assertStringContainsString( '[dir="rtl"]', $css );
		self::assertStringNotContainsString( 'animation:', $css );
		self::assertStringNotContainsString( 'transition:', $css );
	}

	/** Read AdminController source. */
	private function controller_source(): string {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/src/Admin/AdminController.php' );
		self::assertIsString( $source );
		return $source;
	}

	/**
	 * Extract a bounded method interval from a source string.
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
