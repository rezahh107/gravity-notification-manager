<?php
/**
 * Plugin bootstrap – initialises all components.
 *
 * @package GFSMS\Core
 * @since   3.0.0
 */

declare( strict_types = 1 );

namespace GFSMS\Core;

use GFSMS\Admin\DoctorPage;
use GFSMS\Admin\Logs_Table;
use GFSMS\Admin\Settings_Page;
use GFSMS\Integration\Dispatcher;
use GFSMS\Integration\GravityForms_Handler;
use GFSMS\Integration\Ippanel_Connection_Test;
use GFSMS\Integration\Listener;
use GFSMS\Integration\Sms_Sender;
use GFSMS\Logging\Logger;
use GFSMS\Debug\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * Class Bootstrap
 *
 * Initialises the plugin components.
 *
 * @since 3.0.0
 */
final class Bootstrap {

	/**
	 * Whether the plugin has been initialised.
	 *
	 * @since 3.0.0
	 * @var bool
	 */
	private static bool $initialised = false;

	/**
	 * Initialises the plugin.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public static function init(): void {
		if ( true === self::$initialised ) {
			return;
		}

		// Ensure the log table exists.
		Logger::instance()->maybe_setup();

		// Load text domain.
		add_action( 'init', array( self::class, 'load_textdomain' ) );

		// Register hooks for all components.
		Listener::register_hooks();
		Settings_Page::register_hooks();
		Logs_Table::register_hooks();
		Dispatcher::instance()->register_hooks();
		Sms_Sender::instance()->register_hooks();
		GravityForms_Handler::instance()->register_hooks();
		Ippanel_Connection_Test::instance()->register_hooks();

		// Register doctor page hooks (non-static class).
		( new DoctorPage() )->register();

		// Initialise diagnostics only if the class is available (safety guard).
		if ( class_exists( Diagnostics::class ) ) {
			Diagnostics::init();
		}

		self::$initialised = true;
	}

	/**
	 * Load plugin translations.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public static function load_textdomain(): void {
		load_plugin_textdomain(
			GFSMS_TEXT_DOMAIN,
			false,
			dirname( GFSMS_PLUGIN_BASENAME ) . '/languages'
		);
	}
}