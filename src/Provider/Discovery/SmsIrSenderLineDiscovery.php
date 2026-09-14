<?php
/**
 * SMS.ir sender-line discovery.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Provider\Discovery;

use GravityNotify\Delivery\Http\HttpTransportInterface;
use JsonException;

/** Implements the documented GET /v1/line contract. */
final class SmsIrSenderLineDiscovery implements SenderLineDiscoveryInterface {

	private const ENDPOINT = 'https://api.sms.ir/v1/line';

	private string $api_key;
	private HttpTransportInterface $http;

	/** @param string $api_key API key. @param HttpTransportInterface $http HTTP transport. */
	public function __construct( string $api_key, HttpTransportInterface $http ) {
		$this->api_key = $api_key;
		$this->http    = $http;
	}

	/** Fetch account line numbers without persisting raw provider data. */
	public function discover(): SenderLineDiscoveryResult {
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
			return new SenderLineDiscoveryResult( false, array(), 'transport_error' );
		}
		$status = $response->status_code();
		if ( 200 > $status || 300 <= $status ) {
			return new SenderLineDiscoveryResult( false, array(), 'http_rejection', $status );
		}

		try {
			$decoded = json_decode( $response->body(), true, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException $exception ) {
			unset( $exception );
			return new SenderLineDiscoveryResult( false, array(), 'malformed_response', $status );
		}
		if ( ! is_array( $decoded ) || ! array_key_exists( 'data', $decoded ) || ! is_array( $decoded['data'] ) ) {
			return new SenderLineDiscoveryResult( false, array(), 'malformed_response', $status );
		}
		if ( self::explicit_failure( $decoded['status'] ?? null ) ) {
			return new SenderLineDiscoveryResult( false, array(), 'provider_rejection', $status );
		}

		$lines = array();
		foreach ( $decoded['data'] as $line ) {
			if ( is_int( $line ) ) {
				$line = (string) $line;
			}
			if ( ! is_string( $line ) || 1 !== preg_match( '/^[1-9][0-9]{2,31}$/D', $line ) ) {
				return new SenderLineDiscoveryResult( false, array(), 'malformed_response', $status );
			}
			$lines[] = $line;
			if ( 50 <= count( $lines ) ) {
				break;
			}
		}

		return new SenderLineDiscoveryResult( true, $lines, 'lines_refreshed', $status );
	}

	/** Detect explicit negative status encodings. */
	private static function explicit_failure( $status ): bool {
		if ( false === $status || 0 === $status || '0' === $status ) {
			return true;
		}
		return is_string( $status ) && in_array( strtolower( trim( $status ) ), array( 'error', 'failed', 'failure' ), true );
	}
}
