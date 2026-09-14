<?php
/**
 * Deterministic no-network HTTP seam for provider tests.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Support\WordPress;

use GravityNotify\Delivery\Http\HttpResponse;
use GravityNotify\Delivery\Http\HttpTransportInterface;
use RuntimeException;

/** Queues responses and records requests without touching a network. */
final class FakeHttpTransport implements HttpTransportInterface {

	/** @var array<int, HttpResponse> */
	private array $responses;

	/** @var array<int, array{method:string,url:string,args:array}> */
	private array $requests = array();

	/** @param array<int, HttpResponse> $responses Queued responses. */
	public function __construct( array $responses ) {
		$this->responses = array_values( $responses );
	}

	/** Record one deterministic POST. */
	public function post( string $url, array $args ): HttpResponse {
		return $this->request( 'POST', $url, $args );
	}

	/** Record one deterministic GET. */
	public function get( string $url, array $args ): HttpResponse {
		return $this->request( 'GET', $url, $args );
	}

	/** @return array<int, array{method:string,url:string,args:array}> */
	public function requests(): array {
		return $this->requests;
	}

	/**
	 * Record a request and return the next queued response.
	 *
	 * @throws RuntimeException When no deterministic response is queued.
	 */
	private function request( string $method, string $url, array $args ): HttpResponse {
		$this->requests[] = array(
			'method' => $method,
			'url'    => $url,
			'args'   => $args,
		);
		if ( array() === $this->responses ) {
			throw new RuntimeException( 'No deterministic HTTP response was queued.' );
		}
		return array_shift( $this->responses );
	}
}
