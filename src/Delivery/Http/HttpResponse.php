<?php
/**
 * Safe HTTP response value for transport adapters.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Delivery\Http;

/**
 * Normalizes WordPress/network outcomes without carrying secret-bearing errors.
 */
final class HttpResponse {

	/**
	 * HTTP status code, or zero for network errors.
	 *
	 * @var int
	 */
	private int $status_code;

	/**
	 * Response body.
	 *
	 * @var string
	 */
	private string $body;

	/**
	 * Safe transport-error classification.
	 *
	 * @var string|null
	 */
	private ?string $transport_error;

	/**
	 * Create a response.
	 *
	 * @param int         $status_code     HTTP status code.
	 * @param string      $body            Response body.
	 * @param string|null $transport_error Safe transport-error classification.
	 */
	private function __construct( int $status_code, string $body, ?string $transport_error ) {
		$this->status_code     = $status_code;
		$this->body            = $body;
		$this->transport_error = $transport_error;
	}

	/**
	 * Build an HTTP response.
	 *
	 * @param int    $status_code HTTP status code.
	 * @param string $body        Response body.
	 * @return self
	 */
	public static function from_http( int $status_code, string $body ): self {
		return new self( $status_code, $body, null );
	}

	/**
	 * Build a network/WordPress error without retaining the raw error message.
	 *
	 * @param string $classification Safe error classification.
	 * @return self
	 */
	public static function from_transport_error( string $classification ): self {
		return new self( 0, '', $classification );
	}

	/**
	 * HTTP status code.
	 *
	 * @return int
	 */
	public function status_code(): int {
		return $this->status_code;
	}

	/**
	 * Raw response body.
	 *
	 * @return string
	 */
	public function body(): string {
		return $this->body;
	}

	/**
	 * Whether the transport failed before an HTTP response.
	 *
	 * @return bool
	 */
	public function is_transport_error(): bool {
		return null !== $this->transport_error;
	}

	/**
	 * Safe transport-error classification.
	 *
	 * @return string|null
	 */
	public function transport_error(): ?string {
		return $this->transport_error;
	}
}
