<?php
/**
 * Activator file.
 *
 * @package GFSMS\Lifecycle
 */

declare( strict_types = 1 );

namespace GFSMS\Lifecycle;

use GFSMS\Admin\Settings_Schema;
use GFSMS\Logging\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Handles plugin activation routines.
 */
final class Activator {

	/**
	 * Run activation hooks.
	 *
	 * @return void
	 */
	public static function activate(): void {
		Logger::create_table();

		// Ensure constant GFSMS_SETTINGS_OPTION is defined in your plugin core or constants file.
		if ( false === get_option( GFSMS_SETTINGS_OPTION ) ) {
			add_option( GFSMS_SETTINGS_OPTION, Settings_Schema::defaults(), '', false );
		}

		if ( false === wp_next_scheduled( 'gfsms_cleanup_logs' ) ) {
			wp_schedule_event( time(), 'daily', 'gfsms_cleanup_logs' );
		}
	}
}
