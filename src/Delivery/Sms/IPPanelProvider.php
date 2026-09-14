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
	/**
	 * Stored value.
	 *
	 * @var string
	 */
	private string $api_key;
	/**
	 * Stored value.
	 *
	 * @var HttpTransportInterface
	 */
	private HttpTransportInterface $http;
	/**
	 * Stored value.
	 *
	 * @var string
	 */
	private string $endpoint;

	/**
	 * Construct the object.
	 *
	 * @param string                 $api_key Value.
	 * @param HttpTransportInterface $http Value.
	 * @param string|null            $test_endpoint Value.
	 * @throws \InvalidArgumentException When supplied data is invalid.
	 */
	public function __construct( string $api_key, HttpTransportInterface $http, ?string $test_endpoint = null ) {
		$this->api_key  = $api_key;
		$this->http     = $http;
		$this->endpoint = self::ENDPOINT;
		if ( null !== $test_endpoint ) {
			NoSendGuard::assert_test_loopback_http_url( $test_endpoint );
			$this->endpoint = $test_endpoint;
		}
	}

	/**
	 * Identifier.
	 *
	 * @return string Return value.
	 */
	public function identifier(): string {
		return 'ippanel';
	}

		/**
		 * Capabilities.
		 *
		 * @return array Return value.
		 */
	public function capabilities(): array {
		return array(
			SmsCapability::PLAIN,
			SmsCapability::PATTERN,
			SmsCapability::MULTI_RECIPIENT_PLAIN,
			SmsCapability::PROVIDER_MESSAGE_REFERENCE,
		);
	}

	/**
	 * Send.
	 *
	 * @param SmsRequest $request Value.
	 * @return AttemptResult Return value.
	 */
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

	/**
	 * Has valid e164 addresses.
	 *
	 * @param SmsRequest $request Value.
	 * @return bool Return value.
	 */
	private function has_valid_e164_addresses( SmsRequest $request ): bool {
		if ( ! $this->is_e164( $request->from() ) ) {
			return false;
		}
		foreach ( $request->recipients() as $recipient ) {
			if ( ! is_string( $recipient ) || ! $this->is_e164( $recipient ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Is e164.
	 *
	 * @param string $value Value.
	 * @return bool Return value.
	 */
	private function is_e164( string $value ): bool {
		return 1 === preg_match( '/^\+[1-9][0-9]{1,14}$/D', $value );
	}

		/**
		 * Build payload.
		 *
		 * @param SmsRequest $request Value.
		 * @return array|null Return value.
		 */
	private function build_payload( SmsRequest $request ): ?array {
		if ( SmsCapability::PLAIN === $request->capability() || SmsCapability::MULTI_RECIPIENT_PLAIN === $request->capability() ) {
			return array(
				'sending_type' => 'webservice',
				'from_number'  => $request->from(),
				'message'      => $request->message(),
				'params'       => array( 'recipients' => $request->recipients() ),
			);
		}
		if ( SmsCapability::PATTERN === $request->capability() && 1 === count( $request->recipients() ) ) {
			return array(
				'sending_type' => 'pattern',
				'from_number'  => $request->from(),
				'code'         => $request->pattern_code(),
				'recipients'   => $request->recipients(),
				'params'       => $request->pattern_parameters(),
			);
		}
		return null;
	}

	/**
	 * Classify response.
	 *
	 * @param SmsRequest   $request Value.
	 * @param HttpResponse $response Value.
	 * @return AttemptResult Return value.
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
		$meta = $decoded['meta'] ?? null;
		if ( is_array( $meta ) && false === ( $meta['status'] ?? null ) ) {
			return $this->result( AttemptStatus::FAILED, $request, array(), 'provider_rejection', $status );
		}
		$references = $this->documented_references( $decoded );
		if ( is_array( $meta ) && true === ( $meta['status'] ?? null ) && ! empty( $references ) ) {
			return $this->result( AttemptStatus::SUCCESS, $request, $references, 'accepted', $status );
		}
		return $this->result( AttemptStatus::AMBIGUOUS, $request, array(), 'acceptance_unestablished', $status );
	}

		/**
		 * Documented references.
		 *
		 * @param array $decoded Value.
		 * @return array Return value.
		 */
	private function documented_references( array $decoded ): array {
		$data = $decoded['data'] ?? null;
		if ( ! is_array( $data ) || ! isset( $data['message_outbox_ids'] ) || ! is_array( $data['message_outbox_ids'] ) ) {
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

		/**
		 * Result.
		 *
		 * @param string     $status Value.
		 * @param SmsRequest $request Value.
		 * @param array      $references Value.
		 * @param string     $diagnostic Value.
		 * @param int|null   $http_status Value.
		 * @return AttemptResult Return value.
		 */
	private function result( string $status, SmsRequest $request, array $references, string $diagnostic, ?int $http_status = null ): AttemptResult {
		return new AttemptResult(
			$status,
			'sms',
			$this->identifier(),
			$request->capability(),
			$references,
			$diagnostic,
			$http_status
		);
	}
}
