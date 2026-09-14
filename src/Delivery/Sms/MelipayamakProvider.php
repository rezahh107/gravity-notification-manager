<?php
/**
 * Melipayamak REST SMS provider.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Delivery\Sms;

use GravityNotify\Delivery\AttemptResult;
use GravityNotify\Delivery\AttemptStatus;
use GravityNotify\Delivery\Http\HttpResponse;
use GravityNotify\Delivery\Http\HttpTransportInterface;
use JsonException;

/** Implements the first-party Melipayamak SendSMS REST contract. */
final class MelipayamakProvider implements SmsProviderInterface {

	private const ENDPOINT = 'https://rest.payamak-panel.com/api/SendSMS/SendSMS';

	private string $username;
	private string $password;
	private string $sender;
	private HttpTransportInterface $http;

	/**
	 * @param string                 $username Provider username.
	 * @param string                 $password Provider password.
	 * @param string                 $sender   Configured sender line.
	 * @param HttpTransportInterface $http     HTTP transport.
	 */
	public function __construct( string $username, string $password, string $sender, HttpTransportInterface $http ) {
		$this->username = $username;
		$this->password = $password;
		$this->sender   = $sender;
		$this->http     = $http;
	}

	/** Provider identifier. */
	public function identifier(): string {
		return 'melipayamak';
	}

	/** @return array<int, string> */
	public function capabilities(): array {
		return array(
			SmsCapability::PLAIN,
			SmsCapability::PROVIDER_MESSAGE_REFERENCE,
		);
	}

	/** Send one plain SMS through the documented REST endpoint. */
	public function send( SmsRequest $request ): AttemptResult {
		if ( ! in_array( $request->capability(), $this->capabilities(), true ) ) {
			return $this->result( AttemptStatus::SKIPPED, $request, array(), 'unsupported_capability' );
		}
		if ( 1 !== preg_match( '/^[1-9][0-9]{2,31}$/D', $this->sender ) ) {
			return $this->result( AttemptStatus::FAILED, $request, array(), 'invalid_sender_format' );
		}

		$recipients = IranMobileNumber::local_recipients( $request->recipients() );
		if ( null === $recipients || 1 !== count( $recipients ) ) {
			return $this->result( AttemptStatus::FAILED, $request, array(), 'invalid_address_format' );
		}

		$response = $this->http->post(
			self::ENDPOINT,
			array(
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8' ),
				'body'    => http_build_query(
					array(
						'username' => $this->username,
						'password' => $this->password,
						'to'       => $recipients[0],
						'from'     => $this->sender,
						'text'     => $request->message(),
						'isflash'  => 'false',
					),
					'',
					'&',
					PHP_QUERY_RFC3986
				),
				'timeout' => 15,
			)
		);

		return $this->classify_response( $request, $response );
	}

	/** Classify only documented acceptance evidence. */
	private function classify_response( SmsRequest $request, HttpResponse $response ): AttemptResult {
		if ( $response->is_transport_error() ) {
			return $this->result( AttemptStatus::AMBIGUOUS, $request, array(), 'transport_error' );
		}
		$status = $response->status_code();
		if ( 200 > $status || 300 <= $status ) {
			return $this->result( AttemptStatus::FAILED, $request, array(), 'http_rejection', $status );
		}

		try {
			$decoded = json_decode( $response->body(), true, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException $exception ) {
			unset( $exception );
			return $this->result( AttemptStatus::AMBIGUOUS, $request, array(), 'malformed_response', $status );
		}
		if ( ! is_array( $decoded ) || ! array_key_exists( 'RetStatus', $decoded ) ) {
			return $this->result( AttemptStatus::AMBIGUOUS, $request, array(), 'malformed_response', $status );
		}

		$ret_status = is_numeric( $decoded['RetStatus'] ) ? (int) $decoded['RetStatus'] : null;
		if ( 1 !== $ret_status ) {
			return $this->result( AttemptStatus::FAILED, $request, array(), 'provider_rejection', $status );
		}

		$reference = $decoded['Value'] ?? null;
		if ( ( is_int( $reference ) || is_string( $reference ) ) && 1 === preg_match( '/^[1-9][0-9]{0,127}$/D', (string) $reference ) ) {
			return $this->result( AttemptStatus::SUCCESS, $request, array( (string) $reference ), 'accepted', $status );
		}
		return $this->result( AttemptStatus::AMBIGUOUS, $request, array(), 'acceptance_unestablished', $status );
	}

	/** Build a normalized attempt with the actual configured sender. */
	private function result( string $status, SmsRequest $request, array $references, string $diagnostic, ?int $http_status = null ): AttemptResult {
		return new AttemptResult(
			$status,
			'sms',
			$this->identifier(),
			$request->capability(),
			$references,
			$diagnostic,
			$http_status,
			$this->sender
		);
	}
}
