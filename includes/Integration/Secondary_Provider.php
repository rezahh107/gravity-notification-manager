<?php
/**
 * Secondary provider class for fallback SMS sending.
 *
 * @package GFSMS\Integration
 */

declare( strict_types = 1 );

namespace GFSMS\Integration;

defined( 'ABSPATH' ) || exit;

/**
 * Class Secondary_Provider
 *
 * Extends IPPanel_Provider with a different name for fallback logic.
 */
final class Secondary_Provider extends IPPanel_Provider {

	/**
	 * Get the provider name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'secondary';
	}
}
