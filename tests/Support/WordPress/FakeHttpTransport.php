<?php
/**
 * Deterministic no-network HTTP seam for WU-02 tests.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Support\WordPress;

use GravityNotify\Delivery\Http\HttpResponse;
use GravityNotify\Delivery\Http\HttpTransportInterface;
use RuntimeException;

/**
 * Queues responses and records requests without touching a network.
 */
final class FakeHttpTransport implements HttpTransportInterface {

	/**
	 * Queued deterministic responses.
	 *
	 * @var array<int, HttpResponse>
	 */
	private array $responses;

	/**
	 * Captured requests.
	 *
	 * @var array<int, array{url:string,args:array}>
	 */
	private array $requests = array();

	/**
	 * Create fake transport.
	 *
	 * @param array<int, HttpResponse> $responses Queued responses.
	 */
	public function __construct( array $responses ) {
		$this->responses = array_values( $responses );
	}

	/**
	 * Record request and return the next queued response.
	 *
	 * @param string $url  URL.
	 * @param array  $args Request arguments.
	 * @return HttpResponse
	 */
	public function post( string $url, array $args ): HttpResponse {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);

		if ( empty( $this->responses ) ) {
			throw new RuntimeException( 'No deterministic HTTP response was queued.' );
		}

		return array_shift( $this->responses );
	}

	/**
	 * Captured requests.
	 *
	 * @return array<int, array{url:string,args:array}>
	 */
	public function requests(): array {
		return $this->requests;
	}
}
