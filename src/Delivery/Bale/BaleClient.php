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

/** Synchronous Bale sendMessage client using the injected HTTP seam. */
final class BaleClient implements BaleChannelInterface {

	/**
	 * Stored value.
	 *
	 * @var string
	 */
	private string $token;
	/**
	 * Stored value.
	 *
	 * @var HttpTransportInterface
	 */
	private HttpTransportInterface $http;

	/**
	 * Construct the object.
	 *
	 * @param string                 $token Value.
	 * @param HttpTransportInterface $http Value.
	 * @throws \InvalidArgumentException When supplied data is invalid.
	 */
	public function __construct( string $token, HttpTransportInterface $http ) {
		$this->token = $token;
		$this->http  = $http;
	}

	/**
	 * Send.
	 *
	 * @param BaleRequest $request Value.
	 * @return AttemptResult Return value.
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
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => $body,
				'timeout' => 15,
			)
		);
		return $this->classify_response( $response );
	}

	/**
	 * Classify response.
	 *
	 * @param HttpResponse $response Value.
	 * @return AttemptResult Return value.
	 */
	private function classify_response( HttpResponse $response ): AttemptResult {
		if ( $response->is_transport_error() ) {
			return $this->result( AttemptStatus::AMBIGUOUS, array(), 'transport_error' );
		}
		$status = $response->status_code();
		if ( 200 > $status || 300 <= $status ) {
			return $this->result( AttemptStatus::FAILED, array(), 'http_rejection', $status );
		}
		try {
			$decoded = json_decode( $response->body(), true, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException $exception ) {
			unset( $exception );
			return $this->result( AttemptStatus::AMBIGUOUS, array(), 'malformed_response', $status );
		}
		if ( ! is_array( $decoded ) ) {
			return $this->result( AttemptStatus::AMBIGUOUS, array(), 'malformed_response', $status );
		}
		if ( false === ( $decoded['ok'] ?? null ) && isset( $decoded['error_code'] ) ) {
			return $this->result( AttemptStatus::FAILED, array(), 'api_rejection', $status );
		}
		$result = $decoded['result'] ?? null;
		if ( true === ( $decoded['ok'] ?? null ) && is_array( $result ) && isset( $result['message_id'] ) && ( is_int( $result['message_id'] ) || is_string( $result['message_id'] ) ) ) {
			return $this->result( AttemptStatus::SUCCESS, array( (string) $result['message_id'] ), 'accepted', $status );
		}
		return $this->result( AttemptStatus::AMBIGUOUS, array(), 'acceptance_unestablished', $status );
	}

		/**
		 * Result.
		 *
		 * @param string   $status Value.
		 * @param array    $references Value.
		 * @param string   $diagnostic Value.
		 * @param int|null $http_status Value.
		 * @return AttemptResult Return value.
		 */
	private function result( string $status, array $references, string $diagnostic, ?int $http_status = null ): AttemptResult {
		return new AttemptResult(
			$status,
			'bale',
			null,
			null,
			$references,
			$diagnostic,
			$http_status
		);
	}
}
