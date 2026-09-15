<?php
/**
 * SMS.ir API v2 SMS provider.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Delivery\Sms;

use GravityNotify\Delivery\AttemptResult;
use GravityNotify\Delivery\AttemptStatus;
use GravityNotify\Delivery\Http\HttpResponse;
use GravityNotify\Delivery\Http\HttpTransportInterface;
use JsonException;

/** Implements the first-party SMS.ir v2 bulk-send contract. */
final class SmsIrProvider implements SmsProviderInterface {

	private const ENDPOINT = 'https://api.sms.ir/v1/send/bulk';

	/**
	 * Provider API key.
	 *
	 * @var string
	 */
	private string $api_key;
	/**
	 * Configured sender line.
	 *
	 * @var string
	 */
	private string $sender;
	/**
	 * HTTP transport.
	 *
	 * @var HttpTransportInterface
	 */
	private HttpTransportInterface $http;

	/**
	 * Build the provider adapter with its existing configuration.
	 *
	 * @param string                 $api_key API key.
	 * @param string                 $sender  Configured line number.
	 * @param HttpTransportInterface $http    HTTP transport.
	 */
	public function __construct( string $api_key, string $sender, HttpTransportInterface $http ) {
		$this->api_key = $api_key;
		$this->sender  = $sender;
		$this->http    = $http;
	}

	/** Provider identifier. */
	public function identifier(): string {
		return 'smsir';
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
			SmsCapability::PROVIDER_MESSAGE_REFERENCE,
		);
	}

	/**
	 * Send through the documented bulk endpoint.
	 *
	 * @param SmsRequest $request Normalized SMS request.
	 * @return AttemptResult
	 */
	public function send( SmsRequest $request ): AttemptResult {
		if ( ! in_array( $request->capability(), $this->capabilities(), true ) ) {
			return $this->result( AttemptStatus::SKIPPED, $request, array(), 'unsupported_capability' );
		}
		if ( 1 !== preg_match( '/^[1-9][0-9]{2,31}$/D', $this->sender ) ) {
			return $this->result( AttemptStatus::FAILED, $request, array(), 'invalid_sender_format' );
		}
		$recipients = $request->recipients();
		foreach ( $recipients as $recipient ) {
			if ( ! is_string( $recipient ) || 1 !== preg_match( '/^\+[1-9][0-9]{1,14}$/D', $recipient ) ) {
				return $this->result( AttemptStatus::FAILED, $request, array(), 'invalid_address_format' );
			}
		}

		try {
			$body = json_encode(
				array(
					'lineNumber'   => (int) $this->sender,
					'MessageText'  => $request->message(),
					'Mobiles'      => $recipients,
					'SendDateTime' => null,
				),
				JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			);
		} catch ( JsonException $exception ) {
			unset( $exception );
			return $this->result( AttemptStatus::FAILED, $request, array(), 'request_encoding_failed' );
		}

		$response = $this->http->post(
			self::ENDPOINT,
			array(
				'headers' => array(
					'X-API-KEY'    => $this->api_key,
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
	 * Classify one current SMS.ir response.
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
		if ( ! is_array( $decoded ) ) {
			return $this->result( AttemptStatus::AMBIGUOUS, $request, array(), 'malformed_response', $status );
		}
		if ( self::explicit_failure( $decoded['status'] ?? null ) ) {
			return $this->result( AttemptStatus::FAILED, $request, array(), 'provider_rejection', $status );
		}

		$data = $decoded['data'] ?? null;
		if ( ! is_array( $data ) ) {
			return $this->result( AttemptStatus::AMBIGUOUS, $request, array(), 'acceptance_unestablished', $status );
		}

		$references = array();
		$pack_id    = $data['packId'] ?? null;
		if ( ( is_int( $pack_id ) || is_string( $pack_id ) ) && 1 === preg_match( '/^[A-Za-z0-9._:-]{1,128}$/D', (string) $pack_id ) ) {
			$references[] = (string) $pack_id;
		}
		$message_ids = $data['messageIds'] ?? null;
		if ( is_array( $message_ids ) ) {
			foreach ( $message_ids as $message_id ) {
				if ( is_int( $message_id ) || ( is_string( $message_id ) && ctype_digit( $message_id ) ) ) {
					$references[] = (string) $message_id;
				}
				if ( 3 <= count( $references ) ) {
					break;
				}
			}
		}

		$references = array_values( array_unique( $references ) );
		return array() !== $references
			? $this->result( AttemptStatus::SUCCESS, $request, $references, 'accepted', $status )
			: $this->result( AttemptStatus::AMBIGUOUS, $request, array(), 'acceptance_unestablished', $status );
	}

	/**
	 * Detect an explicit negative status without guessing positive encodings.
	 *
	 * @param mixed $status Provider status value.
	 * @return bool
	 */
	private static function explicit_failure( $status ): bool {
		if ( false === $status || 0 === $status || '0' === $status ) {
			return true;
		}
		return is_string( $status ) && in_array( strtolower( trim( $status ) ), array( 'error', 'failed', 'failure' ), true );
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
