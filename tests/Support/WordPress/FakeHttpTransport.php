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

	/**
	 * Queued deterministic HTTP responses.
	 *
	 * @var array<int, HttpResponse>
	 */
	private array $responses;

	/**
	 * Recorded deterministic requests.
	 *
	 * @var array<int, array{method:string,url:string,args:array}>
	 */
	private array $requests = array();

	/**
	 * Build the fake transport with queued responses.
	 *
	 * @param array<int, HttpResponse> $responses Queued responses.
	 */
	public function __construct( array $responses ) {
		$this->responses = array_values( $responses );
	}

	/**
	 * Record one deterministic POST.
	 *
	 * @param string               $url  Request URL.
	 * @param array<string, mixed> $args Request arguments.
	 * @return HttpResponse
	 */
	public function post( string $url, array $args ): HttpResponse {
		return $this->request( 'POST', $url, $args );
	}

	/**
	 * Record one deterministic GET.
	 *
	 * @param string               $url  Request URL.
	 * @param array<string, mixed> $args Request arguments.
	 * @return HttpResponse
	 */
	public function get( string $url, array $args ): HttpResponse {
		return $this->request( 'GET', $url, $args );
	}

	/**
	 * Return recorded deterministic requests.
	 *
	 * @return array<int, array{method:string,url:string,args:array}>
	 */
	public function requests(): array {
		return $this->requests;
	}

	/**
	 * Record a request and return the next queued response.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $url    Request URL.
	 * @param array<string, mixed> $args   Request arguments.
	 * @return HttpResponse
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
