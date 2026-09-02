<?php
/**
 * Legacy PSR‑4 autoloader for the GFSMS namespace.
 *
 * This class is only retained for backward compatibility and must never be
 * automatically registered. The plugin now relies entirely on Composer’s
 * PSR‑4 autoloader (`vendor/autoload.php`), which should be preferred.
 *
 * @package GFSMS
 * @since   3.3.0
 * @deprecated 3.3.0 Use Composer's autoloader instead. This file will be
 *                   removed in a future version.
 */

declare( strict_types = 1 );

namespace GFSMS;

defined( 'ABSPATH' ) || exit;

/**
 * Legacy PSR‑4 autoloader for the GFSMS namespace.
 *
 * @deprecated 3.3.0
 */
final class Autoloader {

	/**
	 * Namespace prefix for the plugin.
	 *
	 * @var string
	 */
	private const PREFIX = 'GFSMS\\';

	/**
	 * Registers the legacy autoloader (not recommended).
	 *
	 * @deprecated 3.3.0 Legacy autoloader registration; use Composer.
	 *
	 * @return void
	 */
	public static function register(): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
		trigger_error(
			__( 'GFSMS Autoloader::register() is deprecated. Composer autoloader should be used.', 'gfsms' ),
			E_USER_DEPRECATED
		);
		spl_autoload_register( array( self::class, 'load' ), true, false );
	}

	/**
	 * Loads a class file if it exists and is within the plugin directory.
	 *
	 * @deprecated 3.3.0 No longer required; kept for legacy compatibility.
	 *
	 * @param string $class Fully‑qualified class name.
	 *
	 * @return void
	 */
	public static function load( string $class ): void {
		if ( ! str_starts_with( $class, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class, strlen( self::PREFIX ) );
		$relative = str_replace( '\\', DIRECTORY_SEPARATOR, $relative );

		$base = trailingslashit(
			defined( 'GFSMS_PLUGIN_DIR' ) ? GFSMS_PLUGIN_DIR : dirname( __DIR__ )
		);

		$path = $base . $relative . '.php';

		if ( file_exists( $path ) && is_readable( $path ) ) {
			// Prevent path traversal: ensure resolved path is within plugin directory.
			$real_path = realpath( $path );
			$real_base = realpath( $base );

			if ( $real_path && $real_base && 0 === strpos( $real_path, $real_base . DIRECTORY_SEPARATOR ) ) {
				require_once $path;
			}
		}
	}
}
