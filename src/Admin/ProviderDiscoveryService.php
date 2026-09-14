<?php
/**
 * Explicit operator-triggered sender discovery.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Admin;

use GravityNotify\Delivery\Http\HttpTransportInterface;
use GravityNotify\Delivery\Http\WordPressHttpTransport;
use GravityNotify\Provider\Discovery\SenderLineDiscoveryResult;
use GravityNotify\Provider\SmsProviderManager;

/** Resolves the optional provider discovery adapter only after an explicit protected action. */
final class ProviderDiscoveryService {

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

	/** Discover account sender lines only for verified discovery-capable providers. */
	public function discover( string $provider_id ): SenderLineDiscoveryResult {
		if ( ! SmsProviderManager::supports_discovery( $provider_id ) ) {
			return new SenderLineDiscoveryResult( false, array(), 'discovery_unavailable' );
		}
		$discovery = ( new SmsProviderManager( $this->settings ) )->sender_discovery( $provider_id, $this->http );
		if ( null === $discovery ) {
			return new SenderLineDiscoveryResult( false, array(), 'provider_not_configured' );
		}
		return $discovery->discover();
	}
}
