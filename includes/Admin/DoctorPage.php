<?php
/**
 * Self‑diagnostic page for IPPanel integration.
 *
 * @package GFSMS\Admin
 */

declare( strict_types = 1 );

namespace GFSMS\Admin;

use GFSMS\Integration\Wp_HTTP_Client;
use GFSMS\Integration\IPPanel_Provider;
use GFSMS\Infrastructure\ProviderFactory;
use GFSMS\Logging\Logger;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Self‑diagnostic page for IPPanel integration.
 *
 * Instantiates the provider (via ProviderFactory if available) and renders
 * the health‑check report. A JSON export is served early on `admin_init`
 * so that no HTML output precedes the headers.
 *
 * @since 3.0.0
 */
final class DoctorPage {

	/**
	 * Parent menu slug.
	 *
	 * @var string
	 */
	private const PARENT_SLUG = 'gravityflow-sms';

	/**
	 * Page slug for the Doctor submenu.
	 *
	 * @var string
	 */
	private const PAGE_SLUG = 'gfsms-doctor';

	/**
	 * Nonce action for JSON export.
	 *
	 * @var string
	 */
	private const EXPORT_NONCE_ACTION = 'gfsms_doctor_export';

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'maybe_handle_json_export' ) );
	}

	/**
	 * Add the Doctor sub‑menu page.
	 *
	 * @return void
	 */
	public function add_page(): void {
		add_submenu_page(
			self::PARENT_SLUG,
			esc_html__( 'IPPanel Doctor', 'gfsms' ),
			esc_html__( 'Doctor', 'gfsms' ),
			GFSMS_CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Render the diagnostic page.
	 *
	 * Checks capability and provider availability, then outputs the report.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( GFSMS_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'gfsms' ) );
		}

		$settings = self::get_settings();
		$api_key  = (string) ( $settings['ippanel_api_key'] ?? '' );

		if ( '' === $api_key ) {
			echo '<div class="notice notice-error"><p>';
			esc_html_e( 'API key not configured. Please set it in the plugin settings.', 'gfsms' );
			echo '</p></div>';
			return;
		}

		$provider = $this->get_provider( $api_key, $settings );
		if ( null === $provider ) {
			echo '<div class="notice notice-error"><p>';
			esc_html_e( 'Could not initialise the IPPanel provider.', 'gfsms' );
			echo '</p></div>';
			return;
		}

		$export_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'   => self::PAGE_SLUG,
					'export' => 'json',
				),
				admin_url( 'admin.php' )
			),
			self::EXPORT_NONCE_ACTION
		);

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'IPPanel Health Check', 'gfsms' ) . '</h1>';
		echo $provider->diagnose_html(); // Already properly escaped.
		echo '<hr>';
		echo '<p>';
		echo '<a href="' . esc_url( $export_url ) . '" class="button button-primary">';
		esc_html_e( 'Download Full Report (JSON)', 'gfsms' );
		echo '</a>';
		echo '</p>';
		echo '</div>';
	}

	/**
	 * Serve JSON export before any HTML is sent.
	 *
	 * Hooked to `admin_init`.
	 *
	 * @return void
	 */
	public function maybe_handle_json_export(): void {
		$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$export = isset( $_GET['export'] ) ? sanitize_key( wp_unslash( $_GET['export'] ) ) : '';

		if ( self::PAGE_SLUG !== $page || 'json' !== $export ) {
			return;
		}

		check_admin_referer( self::EXPORT_NONCE_ACTION );

		if ( ! current_user_can( GFSMS_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'gfsms' ) );
		}

		$settings = self::get_settings();
		$api_key  = (string) ( $settings['ippanel_api_key'] ?? '' );

		if ( '' === $api_key ) {
			wp_die( esc_html__( 'API key not configured.', 'gfsms' ) );
		}

		$provider = $this->get_provider( $api_key, $settings );
		if ( null === $provider ) {
			wp_die( esc_html__( 'Provider not available.', 'gfsms' ) );
		}

		header( 'Content-Type: application/json' );
		header( 'Content-Disposition: attachment; filename="ippanel-health-report.json"' );
		echo $provider->diagnose_json();
		exit;
	}

	/**
	 * Obtain the IPPanel_Provider instance, preferably via ProviderFactory.
	 *
	 * @param string $api_key  IPPanel API key.
	 * @param array  $settings Plugin settings.
	 *
	 * @return IPPanel_Provider|null
	 */
	private function get_provider( string $api_key, array $settings ): ?IPPanel_Provider {
		// Try the factory first (if available).
		if ( class_exists( ProviderFactory::class ) ) {
			try {
				$factory  = new ProviderFactory( $settings );
				$provider = $factory->get_primary();
				if ( $provider instanceof IPPanel_Provider ) {
					return $provider;
				}
			} catch ( Throwable $e ) {
				$this->log_error( 'ProviderFactory failed', $e );
			}
		}

		// Direct fallback.
		if ( ! class_exists( IPPanel_Provider::class ) || ! class_exists( Wp_HTTP_Client::class ) ) {
			return null;
		}

		try {
			return new IPPanel_Provider( new Wp_HTTP_Client(), $api_key, $settings );
		} catch ( Throwable $e ) {
			$this->log_error( 'IPPanel_Provider instantiation failed', $e );
			return null;
		}
	}

	/**
	 * Log an error through the plugin’s Logger.
	 *
	 * @param string    $message   Error description.
	 * @param Throwable $exception The caught exception.
	 *
	 * @return void
	 */
	private function log_error( string $message, Throwable $exception ): void {
		if ( class_exists( Logger::class ) ) {
			try {
				Logger::instance()->error(
					$message,
					array(
						'exception' => $exception->getMessage(),
						'source'    => 'doctor_page',
					)
				);
			} catch ( Throwable $e ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'GFSMS DoctorPage: ' . $message . ' | ' . $exception->getMessage() );
			}
		}
	}

	/**
	 * Retrieve plugin settings.
	 *
	 * @return array
	 */
	private static function get_settings(): array {
		$settings = get_option( GFSMS_SETTINGS_OPTION, array() );
		return is_array( $settings ) ? $settings : array();
	}
}
