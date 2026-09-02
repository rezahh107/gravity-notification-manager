<?php
/**
 * Centralised provider factory.
 *
 * Creates, caches, and invalidates IPPanel_Provider instances
 * based on the full configuration snapshot. Supports both
 * primary and fallback providers.
 *
 * @package GFSMS\Infrastructure
 */

declare( strict_types = 1 );

namespace GFSMS\Infrastructure;

use GFSMS\Domain\Settings;
use GFSMS\Integration\IPPanel_Provider;
use GFSMS\Integration\Wp_HTTP_Client;

defined( 'ABSPATH' ) || exit;

/**
 * Class ProviderFactory
 *
 * Creates and caches provider instances.
 */
final class ProviderFactory {

	/**
	 * Primary provider instance.
	 *
	 * @var IPPanel_Provider|null
	 */
	private ?IPPanel_Provider $primary = null;

	/**
	 * Fallback provider instance.
	 *
	 * @var IPPanel_Provider|null
	 */
	private ?IPPanel_Provider $fallback = null;

	/**
	 * Hash of the configuration used for the primary provider.
	 *
	 * @var string|null
	 */
	private ?string $primary_config_hash = null;

	/**
	 * Hash of the configuration used for the fallback provider.
	 *
	 * @var string|null
	 */
	private ?string $fallback_config_hash = null;

	/**
	 * Shared HTTP client instance.
	 *
	 * @var Wp_HTTP_Client|null
	 */
	private ?Wp_HTTP_Client $http = null;

	/**
	 * Cached configuration array.
	 *
	 * @var array|null
	 */
	private ?array $config = null;

	/**
	 * Settings instance.
	 *
	 * @var Settings
	 */
	private readonly Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Return the primary provider, cached until its configuration changes.
	 *
	 * @return IPPanel_Provider
	 */
	public function get_primary(): IPPanel_Provider {
		$config = $this->get_config();
		$hash   = $this->config_hash( $config );

		if ( null !== $this->primary && $this->primary_config_hash === $hash ) {
			return $this->primary;
		}

		$api_key = trim( (string) $this->settings->get( 'ippanel_api_key', '' ) );

		$this->primary = new IPPanel_Provider(
			$this->http_client(),
			$api_key,
			$config
		);

		$this->primary_config_hash = $hash;
		return $this->primary;
	}

	/**
	 * Return the fallback provider, or null if no secondary key is set.
	 *
	 * @return IPPanel_Provider|null
	 */
	public function get_fallback(): ?IPPanel_Provider {
		$api_key = trim( (string) $this->settings->get( 'secondary_api_key', '' ) );
		if ( '' === $api_key ) {
			return null;
		}

		$config = $this->get_config();
		$hash   = $this->config_hash( $config . $api_key ); // Include key in hash.

		if ( null !== $this->fallback && $this->fallback_config_hash === $hash ) {
			return $this->fallback;
		}

		$this->fallback = new IPPanel_Provider(
			$this->http_client(),
			$api_key,
			$config
		);

		$this->fallback_config_hash = $hash;
		return $this->fallback;
	}

	/**
	 * Lazy‑load the shared HTTP client.
	 *
	 * @return Wp_HTTP_Client
	 */
	private function http_client(): Wp_HTTP_Client {
		if ( null === $this->http ) {
			$this->http = new Wp_HTTP_Client();
		}
		return $this->http;
	}

	/**
	 * Return the current configuration array, cached until settings change.
	 *
	 * @return array
	 */
	private function get_config(): array {
		if ( null !== $this->config ) {
			return $this->config;
		}

		$mode = $this->settings->get( 'ippanel_mode', IPPanel_Provider::MODE_EDGE );
		if ( ! in_array( $mode, array( IPPanel_Provider::MODE_EDGE, IPPanel_Provider::MODE_LEGACY ), true ) ) {
			$mode = IPPanel_Provider::MODE_EDGE;
		}

		$this->config = array(
			'mode'                    => sanitize_key( $mode ),
			'auth_style'              => sanitize_key( $this->settings->get( 'ippanel_auth_style', IPPanel_Provider::AUTH_RAW ) ),
			'edge_base_url'           => esc_url_raw( $this->settings->get( 'ippanel_edge_base_url', 'https://edge.ippanel.com/v1' ) ),
			'edge_sms_endpoint'       => sanitize_text_field( $this->settings->get( 'ippanel_edge_sms_endpoint', '/api/send' ) ),
			'edge_pattern_endpoint'   => sanitize_text_field( $this->settings->get( 'ippanel_edge_pattern_endpoint', '/api/send' ) ),
			'legacy_base_url'         => esc_url_raw( $this->settings->get( 'ippanel_legacy_base_url', 'https://api2.ippanel.com' ) ),
			'legacy_sms_endpoint'     => sanitize_text_field( $this->settings->get( 'ippanel_legacy_sms_endpoint', '/api/v1/sms/send' ) ),
			'legacy_pattern_endpoint' => sanitize_text_field( $this->settings->get( 'ippanel_legacy_pattern_endpoint', '/api/v1/sms/pattern/normal/send' ) ),
		);

		return $this->config;
	}

	/**
	 * Stable hash of a configuration array or string.
	 *
	 * @param array|string $data Configuration data.
	 *
	 * @return string
	 */
	private function config_hash( array|string $data ): string {
		if ( is_array( $data ) ) {
			$data = wp_json_encode( $data );
		}
		return md5( (string) $data );
	}
}
