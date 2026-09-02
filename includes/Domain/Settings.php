<?php
/**
 * Settings accessor for the plugin.
 *
 * @package GFSMS\Domain
 */

declare( strict_types = 1 );

namespace GFSMS\Domain;

use GFSMS\Admin\Settings_Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Class Settings
 *
 * Provides a convenient interface to retrieve plugin settings.
 */
final class Settings {

	/**
	 * Settings data array.
	 *
	 * @var array
	 */
	private array $data;

	/**
	 * Constructor.
	 *
	 * @param array|null $data Optional settings data. If null, loads from database.
	 */
	public function __construct( ?array $data = null ) {
		if ( null === $data ) {
			$saved = get_option( GFSMS_SETTINGS_OPTION, array() );
			$data  = array_merge( Settings_Schema::defaults(), $saved );
		}
		$this->data = $data;
	}

	/**
	 * Get a setting value by key.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value if key not found.
	 *
	 * @return mixed
	 */
	public function get( string $key, mixed $default = null ): mixed {
		return array_key_exists( $key, $this->data ) ? $this->data[ $key ] : $default;
	}

	/**
	 * Check if SMS notifications are enabled.
	 *
	 * @return bool
	 */
	public function isEnabled(): bool {
		return (bool) ( $this->data['enabled'] ?? false );
	}

	/**
	 * Get the queue delay in seconds.
	 *
	 * @return int
	 */
	public function getQueueDelay(): int {
		return max( 0, (int) ( $this->data['queue_delay'] ?? 0 ) );
	}
}
