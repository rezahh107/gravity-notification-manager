<?php
/**
 * Explicit operator-triggered provider connection validation.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Admin;

use GravityNotify\Delivery\Http\HttpTransportInterface;
use GravityNotify\Delivery\Http\WordPressHttpTransport;
use GravityNotify\Provider\Connection\ProviderConnectionResult;
use GravityNotify\Provider\SmsProviderManager;

/** Resolves provider-specific validation only after an explicit protected action. */
final class ProviderConnectionService {

	/**
	 * Current settings snapshot.
	 *
	 * @var array<string, mixed>
	 */
	private array $settings;
	/**
	 * HTTP transport used only by explicit actions.
	 *
	 * @var HttpTransportInterface
	 */
	private HttpTransportInterface $http;

	/**
	 * Build the explicit provider connection validation service.
	 *
	 * @param array<string, mixed>   $settings Settings snapshot.
	 * @param HttpTransportInterface $http     HTTP transport.
	 */
	public function __construct( array $settings, HttpTransportInterface $http ) {
		$this->settings = $settings;
		$this->http     = $http;
	}

	/** Build the production provider connection validation service. */
	public static function production(): self {
		return new self( Settings::read(), new WordPressHttpTransport() );
	}

	/**
	 * Validate credentials through the provider's explicit admin boundary.
	 *
	 * @param string $provider_id Provider identifier.
	 * @return ProviderConnectionResult
	 */
	public function check( string $provider_id ): ProviderConnectionResult {
		$checker = ( new SmsProviderManager( $this->settings ) )->connection_checker( $provider_id, $this->http );
		if ( null === $checker ) {
			return new ProviderConnectionResult( false, 'provider_not_configured' );
		}
		return $checker->check();
	}
}
