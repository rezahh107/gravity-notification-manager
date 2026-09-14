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

	/** @var array<string, mixed> */
	private array $settings;
	private HttpTransportInterface $http;

	/** @param array<string, mixed> $settings Settings snapshot. */
	public function __construct( array $settings, HttpTransportInterface $http ) {
		$this->settings = $settings;
		$this->http     = $http;
	}

	public static function production(): self {
		return new self( Settings::read(), new WordPressHttpTransport() );
	}

	/** Validate credentials through the provider's explicit admin boundary. */
	public function check( string $provider_id ): ProviderConnectionResult {
		$checker = ( new SmsProviderManager( $this->settings ) )->connection_checker( $provider_id, $this->http );
		if ( null === $checker ) {
			return new ProviderConnectionResult( false, 'provider_not_configured' );
		}
		return $checker->check();
	}
}
