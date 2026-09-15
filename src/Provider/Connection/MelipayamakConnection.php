<?php
/**
 * Melipayamak credential validation.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Provider\Connection;

use GravityNotify\Delivery\Http\HttpTransportInterface;
use JsonException;

/** Uses the first-party GetCredit REST operation as an explicit credential check. */
final class MelipayamakConnection implements ProviderConnectionInterface {

	private const ENDPOINT = 'https://rest.payamak-panel.com/api/SendSMS/GetCredit';

	/** Melipayamak username. */
	private string $username;
	/** Melipayamak password. */
	private string $password;
	/** HTTP transport. */
	private HttpTransportInterface $http;

	/**
	 * Build the explicit Melipayamak connection checker.
	 *
	 * @param string                 $username Account username.
	 * @param string                 $password Account password.
	 * @param HttpTransportInterface $http     HTTP transport.
	 */
	public function __construct( string $username, string $password, HttpTransportInterface $http ) {
		$this->username = $username;
		$this->password = $password;
		$this->http     = $http;
	}

	/** Validate the configured Melipayamak credentials through the verified contract. */
	public function check(): ProviderConnectionResult {
		$response = $this->http->post(
			self::ENDPOINT,
			array(
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8' ),
				'body'    => http_build_query(
					array(
						'username' => $this->username,
						'password' => $this->password,
					),
					'',
					'&',
					PHP_QUERY_RFC3986
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
		if ( ! is_array( $decoded ) || ! array_key_exists( 'RetStatus', $decoded ) ) {
			return new ProviderConnectionResult( false, 'malformed_response', $status );
		}
		return 1 === (int) $decoded['RetStatus']
			? new ProviderConnectionResult( true, 'connection_validated', $status )
			: new ProviderConnectionResult( false, 'authentication_failed', $status );
	}
}
