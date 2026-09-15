<?php
/**
 * FarazSMS credential validation.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Provider\Connection;

use GravityNotify\Delivery\Http\HttpTransportInterface;
use JsonException;

/** Uses the documented account balance request with the normal Api-Key credential. */
final class FarazSmsConnection implements ProviderConnectionInterface {

	private const ENDPOINT = 'https://api.iranpayamak.com/ws/v1/account/balance';

	/**
	 * FarazSMS API key.
	 *
	 * @var string
	 */
	private string $api_key;
	/**
	 * HTTP transport.
	 *
	 * @var HttpTransportInterface
	 */
	private HttpTransportInterface $http;

	/**
	 * Build the explicit connection checker.
	 *
	 * @param string                 $api_key API key.
	 * @param HttpTransportInterface $http    HTTP transport.
	 */
	public function __construct( string $api_key, HttpTransportInterface $http ) {
		$this->api_key = $api_key;
		$this->http    = $http;
	}

	/** Validate the configured FarazSMS credentials through the verified contract. */
	public function check(): ProviderConnectionResult {
		$response = $this->http->get(
			self::ENDPOINT,
			array(
				'headers' => array(
					'Api-Key' => $this->api_key,
					'Accept'  => 'application/json',
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
		if ( ! is_array( $decoded ) || ! is_string( $decoded['status'] ?? null ) ) {
			return new ProviderConnectionResult( false, 'malformed_response', $status );
		}
		return 'success' === strtolower( trim( $decoded['status'] ) )
			? new ProviderConnectionResult( true, 'connection_validated', $status )
			: new ProviderConnectionResult( false, 'authentication_failed', $status );
	}
}
