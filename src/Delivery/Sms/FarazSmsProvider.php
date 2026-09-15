<?php
/**
 * FarazSMS / IranPayamak SMS provider.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Delivery\Sms;

use GravityNotify\Delivery\AttemptResult;
use GravityNotify\Delivery\AttemptStatus;
use GravityNotify\Delivery\Http\HttpResponse;
use GravityNotify\Delivery\Http\HttpTransportInterface;
use JsonException;

/** Implements the current public FarazSMS simple and pattern contracts. */
final class FarazSmsProvider implements SmsProviderInterface {

	private const SIMPLE_ENDPOINT  = 'https://api.iranpayamak.com/ws/v1/sms/simple';
	private const PATTERN_ENDPOINT = 'https://api.iranpayamak.com/ws/v1/sms/pattern';

	/** Provider API key. */
	private string $api_key;
	/** Configured sender line. */
	private string $sender;
	/** HTTP transport. */
	private HttpTransportInterface $http;

	/**
	 * Build the provider adapter with its existing configuration.
	 *
	 * @param string                 $api_key API key.
	 * @param string                 $sender  Configured sender line.
	 * @param HttpTransportInterface $http    HTTP transport.
	 */
	public function __construct( string $api_key, string $sender, HttpTransportInterface $http ) {
		$this->api_key = $api_key;
		$this->sender  = $sender;
		$this->http    = $http;
	}

	/** Provider identifier. */
	public function identifier(): string {
		return 'farazsms';
	}

	/**
	 * Return supported SMS capabilities.
	 *
	 * @return array<int, string>
	 */
	public function capabilities(): array {
		return array(
			SmsCapability::PLAIN,
			SmsCapability::MULTI_RECIPIENT_PLAIN,
			SmsCapability::PATTERN,
		);
	}

	/** Send a plain or pattern message through the documented endpoint. */
	public function send( SmsRequest $request ): AttemptResult {
		if ( ! in_array( $request->capability(), $this->capabilities(), true ) ) {
			return $this->result( AttemptStatus::SKIPPED, $request, array(), 'unsupported_capability' );
		}
		if ( 1 !== preg_match( '/^[1-9][0-9]{2,31}$/D', $this->sender ) ) {
			return $this->result( AttemptStatus::FAILED, $request, array(), 'invalid_sender_format' );
		}

		$recipients = IranMobileNumber::local_recipients( $request->recipients() );
		if ( null === $recipients ) {
			return $this->result( AttemptStatus::FAILED, $request, array(), 'invalid_address_format' );
		}

		$payload = $this->payload( $request, $recipients );
		if ( null === $payload ) {
			return $this->result( AttemptStatus::FAILED, $request, array(), 'invalid_request_shape' );
		}

		try {
			$body = json_encode( $payload['body'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		} catch ( JsonException $exception ) {
			unset( $exception );
			return $this->result( AttemptStatus::FAILED, $request, array(), 'request_encoding_failed' );
		}

		$response = $this->http->post(
			$payload['endpoint'],
			array(
				'headers' => array(
					'Api-Key'      => $this->api_key,
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
				),
				'body'    => $body,
				'timeout' => 15,
			)
		);
		return $this->classify_response( $request, $response );
	}

	/**
	 * Build a provider-specific request body.
	 *
	 * @param SmsRequest         $request    Normalized request.
	 * @param array<int, string> $recipients Local Iranian recipients.
	 * @return array{endpoint:string,body:array<string,mixed>}|null
	 */
	private function payload( SmsRequest $request, array $recipients ): ?array {
		if ( SmsCapability::PLAIN === $request->capability() || SmsCapability::MULTI_RECIPIENT_PLAIN === $request->capability() ) {
			return array(
				'endpoint' => self::SIMPLE_ENDPOINT,
				'body'     => array(
					'text'          => $request->message(),
					'line_number'   => $this->sender,
					'recipients'    => $recipients,
					'number_format' => 'english',
				),
			);
		}

		if ( SmsCapability::PATTERN === $request->capability() && 1 === count( $recipients ) ) {
			return array(
				'endpoint' => self::PATTERN_ENDPOINT,
				'body'     => array(
					'code'          => $request->pattern_code(),
					'attributes'    => $request->pattern_parameters(),
					'recipient'     => $recipients[0],
					'line_number'   => $this->sender,
					'number_format' => 'english',
				),
			);
		}
		return null;
	}

	/**
	 * Classify the documented HTTP/status result without inventing a message reference.
	 *
	 * @param SmsRequest   $request  Normalized SMS request.
	 * @param HttpResponse $response Provider response.
	 * @return AttemptResult
	 */
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
		if ( ! is_array( $decoded ) || ! is_string( $decoded['status'] ?? null ) ) {
			return $this->result( AttemptStatus::AMBIGUOUS, $request, array(), 'malformed_response', $status );
		}

		$provider_status = strtolower( trim( $decoded['status'] ) );
		if ( 'success' === $provider_status ) {
			return $this->result( AttemptStatus::SUCCESS, $request, array(), 'accepted', $status );
		}
		if ( in_array( $provider_status, array( 'error', 'failed', 'failure' ), true ) ) {
			return $this->result( AttemptStatus::FAILED, $request, array(), 'provider_rejection', $status );
		}
		return $this->result( AttemptStatus::AMBIGUOUS, $request, array(), 'acceptance_unestablished', $status );
	}

	/**
	 * Build a normalized attempt with the actual configured sender.
	 *
	 * @param string            $status      Attempt status.
	 * @param SmsRequest        $request     Normalized SMS request.
	 * @param array<int, mixed> $references Safe provider references.
	 * @param string            $diagnostic  Safe diagnostic token.
	 * @param int|null          $http_status Observed HTTP status.
	 * @return AttemptResult
	 */
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
