<?php
/**
 * Advisor admin registration and safety contracts.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Unit\Admin;

use GravityNotify\Admin\AdminController;
use GravityNotify\Admin\GravityFlowNavigation;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Proves Advisor is production-reachable, capability-protected and read-only.
 */
final class AdvisorAdminContractTest extends TestCase {

	/** Advisor is registered from the normal production admin path. */
	public function test_advisor_is_registered_and_reachable_from_plugin_entrypoint(): void {
		$root       = dirname( __DIR__, 3 );
		$entrypoint = file_get_contents( $root . '/gravityflow-sms-ippanel.php' );
		$controller = $this->controller_source();
		$definition = file_get_contents( $root . '/src/Admin/AdminDefinition.php' );

		self::assertIsString( $entrypoint );
		self::assertIsString( $definition );
		self::assertStringContainsString( 'AdminController::boot()', $entrypoint );
		self::assertStringContainsString( "AdminDefinition::ADVISOR_SLUG     => 'render_advisor'", $controller );
		self::assertMatchesRegularExpression(
			"/public\\s+const\\s+ADVISOR_SLUG\\s*=\\s*'gravity-notification-manager-advisor';/",
			$definition
		);
		self::assertStringContainsString( "__( 'Advisor', 'gravity-notification-manager' )", $definition );
	}

	/** Advisor render fails closed before reading current state when capability is unavailable. */
	public function test_advisor_enforces_existing_capability_boundary(): void {
		$this->expectException( RuntimeException::class );
		AdminController::render_advisor();
	}

	/** Advisor rendering contains reads/navigation only and no operational mutation path. */
	public function test_advisor_render_path_is_side_effect_free(): void {
		$render   = $this->method_section( 'public static function render_advisor', 'public static function render_diagnostics' );
		$renderer = $this->method_section( 'private static function render_advisor_card', 'private static function advisor_action_url' );
		$section  = $render . $renderer;

		self::assertStringContainsString( 'Settings::read()', $render );
		self::assertStringContainsString( 'Settings::readiness( $settings )', $render );
		self::assertStringContainsString( 'new PointInspector( new WordPressConfigurationSource() )', $render );
		self::assertStringContainsString( 'AdvisorModel::build(', $render );
		self::assertStringNotContainsString( '<form', $renderer );
		self::assertStringNotContainsString( 'admin-post.php', $renderer );
		self::assertStringNotContainsString( 'ProviderTestService', $section );
		self::assertStringNotContainsString( 'test_sms(', $section );
		self::assertStringNotContainsString( 'test_bale(', $section );
		self::assertStringNotContainsString( 'ManualRetryHandler', $section );
		self::assertStringNotContainsString( 'gform_update_meta', $section );
		self::assertStringNotContainsString( 'update_option', $section );
		self::assertStringNotContainsString( 'set_transient', $section );
		self::assertStringNotContainsString( 'wp_remote_post', $section );
		self::assertStringNotContainsString( 'add_step', $section );
		self::assertStringNotContainsString( 'update_step', $section );
		self::assertStringNotContainsString( 'delete_step', $section );
	}

	/** Exact Flow links continue to come from the existing bounded navigation helper. */
	public function test_advisor_flow_links_reuse_existing_navigation_helper(): void {
		self::assertSame(
			'admin.php?page=gf_edit_forms&view=settings&subview=gravityflow&id=7',
			GravityFlowNavigation::relative_path( 7, true )
		);
		self::assertNull( GravityFlowNavigation::relative_path( 7, false ) );

		$renderer = $this->method_section( 'private static function render_advisor_card', 'private static function advisor_action_url' );
		self::assertStringContainsString( 'GravityFlowNavigation::relative_path(', $renderer );
		self::assertStringNotContainsString( 'subview=gravityflow', $renderer );
	}

	/** Provider-test guidance navigates to Settings and never registers a new send endpoint. */
	public function test_advisor_provider_test_guidance_reuses_settings_surface(): void {
		$model = file_get_contents( dirname( __DIR__, 3 ) . '/src/Admin/AdvisorModel.php' );
		self::assertIsString( $model );
		self::assertStringContainsString( "'id'           => 'provider-test'", $model );
		self::assertStringContainsString( "'action'       => self::ACTION_SETTINGS", $model );
		self::assertStringNotContainsString( 'ProviderTestService', $model );
		self::assertStringNotContainsString( 'admin_post_', $model );
	}

	/** Retry guidance preserves Entry Detail/manual Retry ownership without creating a new route. */
	public function test_retry_guidance_matches_entry_detail_manual_retry_architecture(): void {
		$root  = dirname( __DIR__, 3 );
		$model = file_get_contents( $root . '/src/Admin/AdvisorModel.php' );
		$ops   = file_get_contents( $root . '/src/Presentation/OperationalPresentation.php' );
		self::assertIsString( $model );
		self::assertIsString( $ops );
		self::assertStringContainsString( 'Open the affected Gravity Forms Entry Detail', $model );
		self::assertStringContainsString( 'private function retry_form_html', $ops );
		self::assertStringContainsString( 'ManualRetryHandler::ACTION', $ops );
		self::assertStringContainsString( 'entry_detail_url', $ops );
	}

	/** Attention guidance preserves GravityView/Elementor central presentation plus Entry Detail fallback. */
	public function test_attention_guidance_preserves_existing_presentation_boundary(): void {
		$root  = dirname( __DIR__, 3 );
		$model = file_get_contents( $root . '/src/Admin/AdvisorModel.php' );
		$ops   = file_get_contents( $root . '/src/Presentation/OperationalPresentation.php' );
		self::assertIsString( $model );
		self::assertIsString( $ops );
		self::assertStringContainsString( 'GravityView / Elementor', $model );
		self::assertStringContainsString( 'Entry Detail', $model );
		self::assertStringContainsString( 'ATTENTION_VIEW_IDS_FILTER', $ops );
		self::assertStringContainsString( 'filter_gravityview_entries', $ops );
		self::assertStringContainsString( 'register_entry_detail_meta_box', $ops );
	}

	/**
	 * Read the production admin controller source.
	 *
	 * @return string
	 */
	private function controller_source(): string {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/src/Admin/AdminController.php' );
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
		$source = $this->controller_source();
		$start  = strpos( $source, $start_marker );
		$end    = strpos( $source, $end_marker, false === $start ? 0 : $start );

		self::assertIsInt( $start );
		self::assertIsInt( $end );
		self::assertGreaterThan( $start, $end );
		return substr( $source, $start, $end - $start );
	}
}
