<?php
/**
 * SMS.ir credential validation.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Provider\Connection;

use GravityNotify\Delivery\Http\HttpTransportInterface;
use JsonException;

/** Uses the first-party GET /v1/credit operation as a credential check. */
final class SmsIrConnection implements ProviderConnectionInterface {

	private const ENDPOINT = 'https://api.sms.ir/v1/credit';

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
					'X-API-KEY'    => $this->api_key,
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
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
		if ( ! is_array( $decoded ) || ! array_key_exists( 'data', $decoded ) || ! is_numeric( $decoded['data'] ) ) {
			return new ProviderConnectionResult( false, 'malformed_response', $status );
		}
		return new ProviderConnectionResult( true, 'connection_validated', $status );
	}
}
