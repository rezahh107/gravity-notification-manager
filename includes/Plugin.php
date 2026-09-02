<?php
/**
 * Central plugin singleton (legacy).
 *
 * This class has been superseded by Bootstrap::init() in the new architecture.
 * It is retained for backward compatibility but should no longer be used
 * to boot the plugin.
 *
 * @package GFSMS
 * @deprecated 3.3.0 Use \GFSMS\Core\Bootstrap::init() instead.
 */

declare( strict_types = 1 );

namespace GFSMS;

use GFSMS\Admin\DoctorPage;
use GFSMS\Admin\Logs_Table;
use GFSMS\Admin\Settings_Page;
use GFSMS\Integration\Dispatcher;
use GFSMS\Integration\GravityForms_Handler;
use GFSMS\Integration\Ippanel_Connection_Test;
use GFSMS\Integration\Listener;
use GFSMS\Integration\Sms_Sender;
use GFSMS\Logging\Logger;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Central plugin singleton (legacy).
 *
 * @deprecated 3.3.0 Use \GFSMS\Core\Bootstrap::init() instead.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Whether the plugin has been booted.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Classes that are critical for plugin operation.
	 * If any of these are missing, booting is aborted.
	 *
	 * @var array<int, class-string>
	 */
	private const CRITICAL_CLASSES = array(
		Logger::class,
		Listener::class,
	);

	/**
	 * Classes that are non‑critical; the plugin can run with reduced functionality.
	 *
	 * @var array<int, class-string>
	 */
	private const NON_CRITICAL_CLASSES = array(
		Settings_Page::class,
		Logs_Table::class,
		DoctorPage::class,
		Dispatcher::class,
		Sms_Sender::class,
		GravityForms_Handler::class,
		Ippanel_Connection_Test::class,
	);

	/**
	 * Private constructor (singleton).
	 */
	private function __construct() {}

	/**
	 * Returns the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Boot the plugin.
	 *
	 * @deprecated 3.3.0 Call \GFSMS\Core\Bootstrap::init() instead.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( true === $this->booted ) {
			return;
		}

		// 1. Verify critical classes exist.
		$missing_critical = $this->find_missing_classes( self::CRITICAL_CLASSES );
		if ( ! empty( $missing_critical ) ) {
			$this->show_admin_error(
				sprintf(
					esc_html__( 'Critical classes missing: %s. Plugin cannot start.', 'gfsms' ),
					implode( ', ', $missing_critical )
				)
			);
			return;
		}

		// 2. Boot critical components.
		Logger::instance()->maybe_setup();
		add_action( 'init', array( $this, 'load_textdomain' ) );
		Listener::register_hooks();

		// 3. Boot non‑critical components with per‑component error handling.
		$this->boot_non_critical();

		$this->booted = true;
	}

	/**
	 * Return a list of classes that are not currently loadable.
	 *
	 * @param array<int, class-string> $classes List of class names to check.
	 *
	 * @return array<int, class-string>
	 */
	private function find_missing_classes( array $classes ): array {
		$missing = array();

		foreach ( $classes as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = $class;
			}
		}

		return $missing;
	}

	/**
	 * Attempt to boot all non‑critical classes, logging any missing ones.
	 *
	 * @return void
	 */
	private function boot_non_critical(): void {
		$missing = $this->find_missing_classes( self::NON_CRITICAL_CLASSES );

		if ( ! empty( $missing ) ) {
			$this->log_error(
				sprintf(
					'Non‑critical classes not found: %s. Plugin running with reduced functionality.',
					implode( ', ', $missing )
				)
			);
		}

		// Only boot classes that exist.
		if ( ! in_array( Settings_Page::class, $missing, true ) ) {
			Settings_Page::register_hooks();
		}

		if ( ! in_array( Logs_Table::class, $missing, true ) ) {
			Logs_Table::register_hooks();
		}

		if ( ! in_array( DoctorPage::class, $missing, true ) ) {
			( new DoctorPage() )->register();
		}

		if ( ! in_array( Dispatcher::class, $missing, true ) ) {
			Dispatcher::instance()->register_hooks();
		}

		if ( ! in_array( Sms_Sender::class, $missing, true ) ) {
			Sms_Sender::instance()->register_hooks();
		}

		if ( ! in_array( GravityForms_Handler::class, $missing, true ) ) {
			GravityForms_Handler::instance()->register_hooks();
		}

		if ( ! in_array( Ippanel_Connection_Test::class, $missing, true ) ) {
			Ippanel_Connection_Test::instance()->register_hooks();
		}
	}

	/**
	 * Display an admin error notice.
	 *
	 * The message must already be translated and escaped.
	 *
	 * @param string $message The message to show (already safe for HTML output).
	 *
	 * @return void
	 */
	private function show_admin_error( string $message ): void {
		if ( ! is_admin() ) {
			return;
		}

		add_action(
			'admin_notices',
			static function () use ( $message ) {
				printf(
					'<div class="notice notice-error"><p><strong>GFSMS:</strong> %s</p></div>',
					$message // Already escaped.
				);
			}
		);
	}

	/**
	 * Log an error to the plugin logger (or PHP error log as fallback).
	 *
	 * @param string $message Error message.
	 *
	 * @return void
	 */
	private function log_error( string $message ): void {
		if ( class_exists( Logger::class ) ) {
			try {
				Logger::instance()->error( $message );
				return;
			} catch ( \Throwable $e ) {
				// Fall through to error_log.
			}
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( 'GFSMS: ' . $message );
	}

	/**
	 * Load plugin translations.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			defined( 'GFSMS_TEXT_DOMAIN' ) ? GFSMS_TEXT_DOMAIN : 'gfsms',
			false,
			dirname( GFSMS_PLUGIN_BASENAME ) . '/languages'
		);
	}

	/**
	 * Check if the plugin has been booted.
	 *
	 * @return bool
	 */
	public function is_booted(): bool {
		return $this->booted;
	}

	/**
	 * Prevent cloning.
	 *
	 * @return void
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization.
	 *
	 * @return void
	 * @throws RuntimeException Always.
	 */
	public function __wakeup() {
		throw new RuntimeException( 'Cannot unserialize singleton.' );
	}
}
