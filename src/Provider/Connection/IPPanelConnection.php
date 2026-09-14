<?php
/**
 * IPPanel credential validation.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Provider\Connection;

use GravityNotify\Delivery\Http\HttpTransportInterface;
use JsonException;

/** Implements IPPanel's documented token validation request. */
final class IPPanelConnection implements ProviderConnectionInterface {

	private const ENDPOINT = 'https://edge.ippanel.com/v1/api/acl/auth/check_token';

	private string $api_key;
	private HttpTransportInterface $http;

	public function __construct( string $api_key, HttpTransportInterface $http ) {
		$this->api_key = $api_key;
		$this->http    = $http;
	}

	public function check(): ProviderConnectionResult {
		$response = $this->http->get(
			self::ENDPOINT,
			array(
				'headers' => array(
					'Authorization' => $this->api_key,
					'Accept'        => 'application/json',
				),
				'timeout' => 15,
			)
		);
		if ( $response->is_transport_error() ) {
			return new ProviderConnectionResult( false, 'transport_error' );
		}
		$status = $response->status_code();
		if ( 200 > $status || 300 <= $status ) {
			return new ProviderConnectionResult( false, 'authentication_failed', $status );
		}
		try {
			$decoded = json_decode( $response->body(), true, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException $exception ) {
			unset( $exception );
			return new ProviderConnectionResult( false, 'malformed_response', $status );
		}
		$meta = is_array( $decoded ) ? ( $decoded['meta'] ?? null ) : null;
		if ( is_array( $meta ) && true === ( $meta['status'] ?? null ) ) {
			return new ProviderConnectionResult( true, 'connection_validated', $status );
		}
		if ( is_array( $meta ) && false === ( $meta['status'] ?? null ) ) {
			return new ProviderConnectionResult( false, 'authentication_failed', $status );
		}
		return new ProviderConnectionResult( false, 'malformed_response', $status );
	}
}
