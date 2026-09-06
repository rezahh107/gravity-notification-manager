<?php
/**
 * Bale Bot API outbound channel client.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Delivery\Bale;

use GravityNotify\Delivery\AttemptResult;
use GravityNotify\Delivery\AttemptStatus;
use GravityNotify\Delivery\Http\HttpResponse;
use GravityNotify\Delivery\Http\HttpTransportInterface;
use JsonException;

/**
 * Synchronous Bale sendMessage client using the injected HTTP seam.
 */
final class BaleClient implements BaleChannelInterface {

	/**
	 * Bot token.
	 *
	 * @var string
	 */
	private string $token;

	/**
	 * Injected HTTP seam.
	 *
	 * @var HttpTransportInterface
	 */
	private HttpTransportInterface $http;

	/**
	 * Create the outbound client.
	 *
	 * @param string                 $token Bot token.
	 * @param HttpTransportInterface $http  Injected HTTP seam.
	 */
	public function __construct( string $token, HttpTransportInterface $http ) {
		$this->token = $token;
		$this->http  = $http;
	}

	/**
	 * Send one documented sendMessage request.
	 *
	 * @param BaleRequest $request Normalized Bale request.
	 * @return AttemptResult
	 */
	public function send( BaleRequest $request ): AttemptResult {
		try {
			$body = json_encode(
				array(
					'chat_id' => $request->chat_id(),
					'text'    => $request->text(),
				),
				JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			);
		} catch ( JsonException $exception ) {
			unset( $exception );
			return $this->result( AttemptStatus::FAILED, array(), 'request_encoding_failed' );
		}

		$response = $this->http->post(
			'https://tapi.bale.ai/bot' . $this->token . '/sendMessage',
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => $body,
				'timeout' => 15,
			)
		);

		return $this->classify_response( $response );
	}

	/**
	 * Classify using the documented ok/result/error_code contract.
	 *
	 * @param HttpResponse $response HTTP response.
	 * @return AttemptResult
	 */
	private function classify_response( HttpResponse $response ): AttemptResult {
		if ( $response->is_transport_error() ) {
			return $this->result( AttemptStatus::FAILED, array(), 'transport_error' );
		}

		if ( 200 > $response->status_code() || 300 <= $response->status_code() ) {
			return $this->result( AttemptStatus::FAILED, array(), 'http_rejection' );
		}

		try {
			$decoded = json_decode( $response->body(), true, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException $exception ) {
			unset( $exception );
			return $this->result( AttemptStatus::AMBIGUOUS, array(), 'malformed_response' );
		}

		if ( ! is_array( $decoded ) ) {
			return $this->result( AttemptStatus::AMBIGUOUS, array(), 'malformed_response' );
		}

		if ( false === ( $decoded['ok'] ?? null ) && isset( $decoded['error_code'] ) ) {
			return $this->result( AttemptStatus::FAILED, array(), 'api_rejection' );
		}

		$result = $decoded['result'] ?? null;

		if ( true === ( $decoded['ok'] ?? null ) && is_array( $result ) && isset( $result['message_id'] ) && ( is_int( $result['message_id'] ) || is_string( $result['message_id'] ) ) ) {
			return $this->result( AttemptStatus::SUCCESS, array( (string) $result['message_id'] ), 'accepted' );
		}

		return $this->result( AttemptStatus::AMBIGUOUS, array(), 'acceptance_unestablished' );
	}

	/**
	 * Create one safe Bale attempt result.
	 *
	 * @param string             $status     Attempt status.
	 * @param array<int, string> $references Safe documented message references.
	 * @param string             $diagnostic Safe diagnostic.
	 * @return AttemptResult
	 */
	private function result( string $status, array $references, string $diagnostic ): AttemptResult {
		return new AttemptResult(
			$status,
			'bale',
			null,
			null,
			$references,
			$diagnostic
		);
	}
}
