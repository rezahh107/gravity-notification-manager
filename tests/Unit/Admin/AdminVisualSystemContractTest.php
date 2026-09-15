<?php
/**
 * Shared GNM admin visual-system contract.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Unit\Admin;

use GravityNotify\Delivery\AttemptStatus;
use GravityNotify\Provider\SmsProviderManager;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Proves every GNM surface renders status, panels and empty states through one shared layer.
 */
final class AdminVisualSystemContractTest extends TestCase {

	/** One stylesheet owns the semantic status vocabulary used by every surface. */
	public function test_shared_stylesheet_defines_one_semantic_status_vocabulary(): void {
		$css = $this->asset( 'gnm-admin.css' );

		foreach (
			array(
				'.gnm-status--configured',
				'.gnm-status--needs-setup',
				'.gnm-status--success',
				'.gnm-status--failed',
				'.gnm-status--ambiguous',
				'.gnm-status--skipped',
				'.gnm-status--disabled',
				'.gnm-status--not-applicable',
			) as $selector
		) {
			self::assertStringContainsString( $selector, $css );
		}

		self::assertStringContainsString( '--wpds-color-foreground-content-success', $css );
		self::assertStringContainsString( '--wpds-color-foreground-content-warning', $css );
		self::assertStringContainsString( '--wpds-color-foreground-content-error', $css );
		self::assertStringContainsString( '.gnm-empty', $css );
		self::assertStringContainsString( '.gnm-mode--unavailable', $css );
	}

	/** The Operational Log no longer carries a private semantic palette. */
	public function test_operational_log_stylesheet_holds_layout_only(): void {
		$css = $this->asset( 'gnm-operational-log.css' );

		self::assertStringContainsString( '.gnm-log-status', $css );
		self::assertStringNotContainsString( '.gnm-log-status--', $css );
		self::assertDoesNotMatchRegularExpression( '/color:\s*#[0-9a-fA-F]{3,8}/', $css );
	}

	/** Log outcomes reuse the shared status pill instead of a parallel one. */
	public function test_log_status_badges_use_the_shared_status_classes(): void {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/src/Admin/OperationalLogAdmin.php' );
		self::assertIsString( $source );
		self::assertStringContainsString( "'gnm-status gnm-status--'", $source );
		self::assertStringContainsString( 'gnm-log-status', $source );
		self::assertStringNotContainsString( "'gnm-log-status gnm-log-status--'", $source );
	}

	/** Log observability semantics, filters, Trace IDs and the debug export are unchanged. */
	public function test_operational_log_observability_semantics_remain_intact(): void {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/src/Admin/OperationalLogAdmin.php' );
		self::assertIsString( $source );

		foreach ( array( AttemptStatus::SUCCESS, AttemptStatus::FAILED, AttemptStatus::AMBIGUOUS, AttemptStatus::SKIPPED ) as $status ) {
			self::assertStringContainsString( 'AttemptStatus::' . $status, $source );
		}

		self::assertStringContainsString( "__( 'SMS log', 'gravity-notification-manager' )", $source );
		self::assertStringContainsString( "__( 'Bale log', 'gravity-notification-manager' )", $source );
		self::assertStringContainsString( "__( 'Trace ID', 'gravity-notification-manager' )", $source );
		self::assertStringContainsString( "esc_html__( 'Copy LLM Debug Report', 'gravity-notification-manager' )", $source );
		self::assertStringContainsString( 'name="execution_type"', $source );
		self::assertStringContainsString( 'gnm-empty', $source );
	}

	/** Every empty surface explains itself instead of rendering a blank panel. */
	public function test_empty_states_explain_why_nothing_is_shown(): void {
		$root       = dirname( __DIR__, 3 );
		$controller = file_get_contents( $root . '/src/Admin/AdminController.php' );
		$log        = file_get_contents( $root . '/src/Admin/OperationalLogAdmin.php' );
		self::assertIsString( $controller );
		self::assertIsString( $log );

		self::assertStringContainsString( 'gnm-panel gnm-empty"><h2>', $controller );
		self::assertStringContainsString( 'gnm-panel gnm-empty', $log );
		self::assertMatchesRegularExpression( '/gnm-empty">\';\R\s*echo \'<h2>/', $log );
	}

	/** Status values in fact lists render as semantic pills, not raw tokens. */
	public function test_fact_panels_render_status_tokens_as_semantic_badges(): void {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/src/Admin/AdminController.php' );
		self::assertIsString( $source );
		self::assertStringContainsString( 'self::is_status_token( $value )', $source );
		self::assertStringContainsString( 'PointStatus::CONFIGURED, PointStatus::NEEDS_SETUP, PointStatus::DISABLED, PointStatus::NOT_APPLICABLE', $source );
	}

	/** Focus is visible on every interactive primitive GNM renders. */
	public function test_focus_is_visible_on_every_rendered_control_type(): void {
		$css = $this->asset( 'gnm-admin.css' );
		foreach ( array( '.button', 'button', 'input', 'select', 'textarea', 'a' ) as $control ) {
			self::assertStringContainsString( '.gnm-admin ' . $control . ':focus-visible', $css );
		}
	}

	/** All four approved SMS providers stay individually manageable on one consolidated panel each. */
	public function test_provider_manager_keeps_all_four_providers_manageable(): void {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/src/Admin/ProviderManagerAdmin.php' );
		self::assertIsString( $source );

		self::assertSame(
			array( 'ippanel', 'melipayamak', 'smsir', 'farazsms' ),
			array_keys( SmsProviderManager::definitions() )
		);
		self::assertSame(
			array( 'IPPanel', 'Melipayamak', 'SMS.ir', 'FarazSMS' ),
			array_column( SmsProviderManager::definitions(), 'label' )
		);

		self::assertStringContainsString( 'foreach ( SmsProviderManager::definitions() as $identifier => $definition )', $source );
		self::assertStringContainsString( 'self::render_provider_settings( $identifier, $definition, $config );', $source );
		self::assertStringContainsString( 'self::render_provider_actions( $identifier );', $source );
		self::assertStringContainsString( 'gnm-panel__divider', $source );
		self::assertStringContainsString( "__( 'Check Connection', 'gravity-notification-manager' )", $source );
		self::assertStringContainsString( "__( 'Refresh Lines', 'gravity-notification-manager' )", $source );
		self::assertStringContainsString( "__( 'Send Test SMS', 'gravity-notification-manager' )", $source );
	}

	/** No GNM admin surface contacts a provider while rendering. */
	public function test_no_admin_render_path_contacts_a_provider(): void {
		$root = dirname( __DIR__, 3 );
		foreach (
			array(
				'/src/Admin/AdminController.php'       => array( 'render_overview', 'render_points', 'render_diagnostics' ),
				'/src/Admin/ProviderManagerAdmin.php'  => array( 'render_provider_settings', 'render_provider_actions' ),
				'/src/Admin/OperationalLogAdmin.php'   => array( 'render_table', 'render_filters' ),
			) as $file => $methods
		) {
			$source = file_get_contents( $root . $file );
			self::assertIsString( $source, $file );
			foreach ( $methods as $method ) {
				self::assertStringContainsString( 'function ' . $method, $source, $file );
			}
			self::assertStringNotContainsString( 'wp_remote_', $source, $file );
		}

		$settings = file_get_contents( $root . '/src/Admin/Settings.php' );
		self::assertIsString( $settings );
		self::assertStringNotContainsString( 'wp_remote_', $settings );
		self::assertStringNotContainsString( 'HttpTransport', $settings );
	}

	/** Every production admin asset is enqueued from the consolidated assets/admin path. */
	public function test_enqueued_admin_assets_come_only_from_the_admin_asset_path(): void {
		$root  = dirname( __DIR__, 3 );
		$paths = array();

		foreach ( array( 'AdminController', 'ProviderManagerAdmin', 'OperationalLogAdmin' ) as $class ) {
			$source = file_get_contents( $root . '/src/Admin/' . $class . '.php' );
			self::assertIsString( $source, $class );
			preg_match_all( "/GRAVITY_NOTIFY_PLUGIN_URL \. '([^']+)'/", $source, $matches );
			self::assertNotEmpty( $matches[1], $class );
			foreach ( $matches[1] as $path ) {
				$paths[] = $path;
			}
		}

		$paths = array_values( array_unique( $paths ) );
		sort( $paths );
		self::assertSame(
			array(
				'assets/admin/gnm-admin.css',
				'assets/admin/gnm-operational-log.css',
				'assets/admin/gnm-operational-log.js',
			),
			$paths
		);

		foreach ( $paths as $path ) {
			self::assertFileExists( $root . '/' . $path );
		}
	}

	/** Retired repository-era admin assets stay deleted and unreferenced. */
	public function test_retired_repository_era_admin_assets_stay_removed_and_unreferenced(): void {
		$root = dirname( __DIR__, 3 );

		foreach ( array( 'assets/css/admin.css', 'assets/css/admin-rtl.css', 'assets/js/admin.js' ) as $retired ) {
			self::assertFileDoesNotExist( $root . '/' . $retired, $retired );
		}
		self::assertDirectoryDoesNotExist( $root . '/assets/css' );
		self::assertDirectoryDoesNotExist( $root . '/assets/js' );

		foreach ( $this->reference_surfaces( $root ) as $file ) {
			if ( __FILE__ === $file ) {
				continue;
			}
			$source = file_get_contents( $file );
			self::assertIsString( $source, $file );
			self::assertStringNotContainsString( 'assets/css', $source, $file );
			self::assertStringNotContainsString( 'assets/js', $source, $file );
		}
	}

	/** Packaging stages exactly the consolidated admin asset directory into the production ZIP. */
	public function test_packaging_stages_only_the_consolidated_admin_asset_directory(): void {
		$script = file_get_contents( dirname( __DIR__, 3 ) . '/tools/build-production-package.sh' );
		self::assertIsString( $script );

		preg_match_all( '/cp -a assets\/(\S+)/', $script, $matches );
		self::assertSame( array( 'admin' ), array_values( array_unique( $matches[1] ) ) );
		self::assertStringContainsString( 'assets/admin/gnm-admin.css', $script );
	}

	/**
	 * List the source, test, tooling and documentation surfaces that could reference an asset.
	 *
	 * @param string $root Repository root.
	 * @return array<int, string>
	 */
	private function reference_surfaces( string $root ): array {
		$files = array(
			$root . '/gravityflow-sms-ippanel.php',
			$root . '/uninstall.php',
		);

		foreach ( array( '/src', '/tests', '/tools', '/docs', '/.github/workflows', '/assets' ) as $directory ) {
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . $directory ) );
			foreach ( $iterator as $entry ) {
				if ( $entry->isFile() ) {
					$files[] = $entry->getPathname();
				}
			}
		}

		self::assertGreaterThan( 100, count( $files ) );
		return $files;
	}

	/**
	 * Read one packaged admin stylesheet.
	 *
	 * @param string $name Stylesheet file name.
	 * @return string
	 */
	private function asset( string $name ): string {
		$css = file_get_contents( dirname( __DIR__, 3 ) . '/assets/admin/' . $name );
		self::assertIsString( $css, $name );
		return $css;
	}
}
