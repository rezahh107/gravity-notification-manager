<?php
/**
 * IPPanel Edge API SMS provider.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Delivery\Sms;

use GravityNotify\Delivery\AttemptResult;
use GravityNotify\Delivery\AttemptStatus;
use GravityNotify\Delivery\Http\HttpResponse;
use GravityNotify\Delivery\Http\HttpTransportInterface;
use GravityNotify\Support\NoSendGuard;
use JsonException;

/** Implements only currently documented IPPanel Edge webservice/pattern sends. */
final class IPPanelProvider implements SmsProviderInterface {

	private const ENDPOINT = 'https://edge.ippanel.com/v1/api/send';

	private string $api_key;
	private HttpTransportInterface $http;
	private string $endpoint;
	private string $sender;

	/**
	 * @param string                 $api_key       API key.
	 * @param HttpTransportInterface $http           HTTP transport.
	 * @param string|null            $test_endpoint Optional loopback test endpoint.
	 * @param string                 $sender         Provider-owned configured sender; empty preserves the historical request-sender seam.
	 */
	public function __construct( string $api_key, HttpTransportInterface $http, ?string $test_endpoint = null, string $sender = '' ) {
		$this->api_key  = $api_key;
		$this->http     = $http;
		$this->endpoint = self::ENDPOINT;
		$this->sender   = trim( $sender );
		if ( null !== $test_endpoint ) {
			NoSendGuard::assert_test_loopback_http_url( $test_endpoint );
			$this->endpoint = $test_endpoint;
		}
	}

	/** Provider identifier. */
	public function identifier(): string {
		return 'ippanel';
	}

	/** @return array<int, string> */
	public function capabilities(): array {
		return array(
			SmsCapability::PLAIN,
			SmsCapability::PATTERN,
			SmsCapability::MULTI_RECIPIENT_PLAIN,
			SmsCapability::PROVIDER_MESSAGE_REFERENCE,
		);
	}

	/** Send through the current Edge contract. */
	public function send( SmsRequest $request ): AttemptResult {
		if ( ! in_array( $request->capability(), $this->capabilities(), true ) ) {
			return $this->result( AttemptStatus::SKIPPED, $request, array(), 'unsupported_capability' );
		}
		if ( ! $this->has_valid_e164_addresses( $request ) ) {
			return $this->result( AttemptStatus::FAILED, $request, array(), 'invalid_address_format' );
		}

		$payload = $this->build_payload( $request );
		if ( null === $payload ) {
			return $this->result( AttemptStatus::FAILED, $request, array(), 'invalid_request_shape' );
		}
		try {
			$body = json_encode( $payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		} catch ( JsonException $exception ) {
			unset( $exception );
			return $this->result( AttemptStatus::FAILED, $request, array(), 'request_encoding_failed' );
		}

		$response = $this->http->post(
			$this->endpoint,
			array(
				'headers' => array(
					'Authorization' => $this->api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => $body,
				'timeout' => 15,
			)
		);
		return $this->classify_response( $request, $response );
	}

	/** Validate sender/recipient E.164 addresses. */
	private function has_valid_e164_addresses( SmsRequest $request ): bool {
		if ( ! $this->is_e164( $this->effective_sender( $request ) ) ) {
			return false;
		}
		foreach ( $request->recipients() as $recipient ) {
			if ( ! is_string( $recipient ) || ! $this->is_e164( $recipient ) ) {
				return false;
			}
		}
		return true;
	}

	/** Check one E.164 value. */
	private function is_e164( string $value ): bool {
		return 1 === preg_match( '/^\+[1-9][0-9]{1,14}$/D', $value );
	}

	/** Return the provider-owned sender or the historical request sender for isolated compatibility tests. */
	private function effective_sender( SmsRequest $request ): string {
		return '' !== $this->sender ? $this->sender : $request->from();
	}

	/** @return array<string, mixed>|null */
	private function build_payload( SmsRequest $request ): ?array {
		$sender = $this->effective_sender( $request );
		if ( SmsCapability::PLAIN === $request->capability() || SmsCapability::MULTI_RECIPIENT_PLAIN === $request->capability() ) {
			return array(
				'sending_type' => 'webservice',
				'from_number'  => $sender,
				'message'      => $request->message(),
				'params'       => array( 'recipients' => $request->recipients() ),
			);
		}
		if ( SmsCapability::PATTERN === $request->capability() && 1 === count( $request->recipients() ) ) {
			return array(
				'sending_type' => 'pattern',
				'from_number'  => $sender,
				'code'         => $request->pattern_code(),
				'recipients'   => $request->recipients(),
				'params'       => $request->pattern_parameters(),
			);
		}
		return null;
	}

	/** Classify one Edge response. */
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
		$meta = $decoded['meta'] ?? null;
		if ( is_array( $meta ) && false === ( $meta['status'] ?? null ) ) {
			return $this->result( AttemptStatus::FAILED, $request, array(), 'provider_rejection', $status );
		}
		$references = $this->documented_references( $decoded );
		if ( is_array( $meta ) && true === ( $meta['status'] ?? null ) && array() !== $references ) {
			return $this->result( AttemptStatus::SUCCESS, $request, $references, 'accepted', $status );
		}
		return $this->result( AttemptStatus::AMBIGUOUS, $request, array(), 'acceptance_unestablished', $status );
	}

	/** @return array<int, string> */
	private function documented_references( array $decoded ): array {
		$data = $decoded['data'] ?? null;
		if ( ! is_array( $data ) || ! is_array( $data['message_outbox_ids'] ?? null ) ) {
			return array();
		}
		$references = array();
		foreach ( $data['message_outbox_ids'] as $reference ) {
			if ( is_int( $reference ) || is_string( $reference ) ) {
				$references[] = (string) $reference;
			}
		}
		return $references;
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
			$this->effective_sender( $request )
		);
	}
}
