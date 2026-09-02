<?php
/**
 * Handles plugin deactivation routines.
 *
 * Clears scheduled hooks and performs light cleanup.
 *
 * @package GFSMS\Lifecycle
 * @since   3.0.0
 */

declare( strict_types = 1 );

namespace GFSMS\Lifecycle;

defined( 'ABSPATH' ) || exit;

/**
 * Handles plugin deactivation routines.
 *
 * @since 3.0.0
 */
final class Deactivator {

	/**
	 * Run deactivation hooks.
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'gfsms_cleanup_logs' );
	}
}
