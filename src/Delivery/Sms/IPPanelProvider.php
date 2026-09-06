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
use JsonException;

/**
 * Implements only currently documented IPPanel Edge webservice/pattern sends.
 */
final class IPPanelProvider implements SmsProviderInterface {

	/**
	 * Current documented Edge send endpoint.
	 */
	private const ENDPOINT = 'https://edge.ippanel.com/v1/api/send';

	/**
	 * API access key/token.
	 *
	 * @var string
	 */
	private string $api_key;

	/**
	 * Injected WordPress-compatible transport seam.
	 *
	 * @var HttpTransportInterface
	 */
	private HttpTransportInterface $http;

	/**
	 * Create the provider.
	 *
	 * @param string                 $api_key API access key/token.
	 * @param HttpTransportInterface $http    Injected HTTP seam.
	 */
	public function __construct( string $api_key, HttpTransportInterface $http ) {
		$this->api_key = $api_key;
		$this->http    = $http;
	}

	/**
	 * Stable provider identifier.
	 *
	 * @return string
	 */
	public function identifier(): string {
		return 'ippanel';
	}

	/**
	 * Capabilities verified from the current official send contract.
	 *
	 * @return array<int, string>
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
	 * Send one normalized request synchronously.
	 *
	 * @param SmsRequest $request Normalized request.
	 * @return AttemptResult
	 */
	public function send( SmsRequest $request ): AttemptResult {
		if ( ! in_array( $request->capability(), $this->capabilities(), true ) ) {
			return $this->result( AttemptStatus::SKIPPED, $request, array(), 'unsupported_capability' );
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
			self::ENDPOINT,
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
	 * Build only documented request shapes.
	 *
	 * @param SmsRequest $request Normalized request.
	 * @return array<string, mixed>|null
	 */
	private function build_payload( SmsRequest $request ): ?array {
		if ( SmsCapability::PLAIN === $request->capability() || SmsCapability::MULTI_RECIPIENT_PLAIN === $request->capability() ) {
			return array(
				'sending_type' => 'webservice',
				'from_number'  => $request->from(),
				'message'      => $request->message(),
				'params'       => array(
					'recipients' => $request->recipients(),
				),
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
	 * Classify using only documented provider acceptance/rejection semantics.
	 *
	 * @param SmsRequest   $request  Original request.
	 * @param HttpResponse $response Transport response.
	 * @return AttemptResult
	 */
	private function classify_response( SmsRequest $request, HttpResponse $response ): AttemptResult {
		if ( $response->is_transport_error() ) {
			return $this->result( AttemptStatus::FAILED, $request, array(), 'transport_error' );
		}

		if ( 200 > $response->status_code() || 300 <= $response->status_code() ) {
			return $this->result( AttemptStatus::FAILED, $request, array(), 'http_rejection' );
		}

		try {
			$decoded = json_decode( $response->body(), true, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException $exception ) {
			unset( $exception );
			return $this->result( AttemptStatus::AMBIGUOUS, $request, array(), 'malformed_response' );
		}

		if ( ! is_array( $decoded ) ) {
			return $this->result( AttemptStatus::AMBIGUOUS, $request, array(), 'malformed_response' );
		}

		$meta = $decoded['meta'] ?? null;

		if ( is_array( $meta ) && false === ( $meta['status'] ?? null ) ) {
			return $this->result( AttemptStatus::FAILED, $request, array(), 'provider_rejection' );
		}

		$references = $this->documented_references( $decoded );

		if ( is_array( $meta ) && true === ( $meta['status'] ?? null ) && ! empty( $references ) ) {
			return $this->result( AttemptStatus::SUCCESS, $request, $references, 'accepted' );
		}

		return $this->result( AttemptStatus::AMBIGUOUS, $request, array(), 'acceptance_unestablished' );
	}

	/**
	 * Extract only documented message_outbox_ids.
	 *
	 * @param array<string, mixed> $decoded Decoded response.
	 * @return array<int, string>
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
	 * Create one safe attempt result.
	 *
	 * @param string             $status     Attempt status.
	 * @param SmsRequest         $request    Request.
	 * @param array<int, string> $references Safe provider references.
	 * @param string             $diagnostic Safe diagnostic.
	 * @return AttemptResult
	 */
	private function result( string $status, SmsRequest $request, array $references, string $diagnostic ): AttemptResult {
		return new AttemptResult(
			$status,
			'sms',
			$this->identifier(),
			$request->capability(),
			$references,
			$diagnostic
		);
	}
}
