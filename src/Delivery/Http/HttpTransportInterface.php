<?php
/**
 * Narrow outbound HTTP seam.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Delivery\Http;

/** Supports deterministic injected synchronous HTTP transport. */
interface HttpTransportInterface {

	/**
	 * Perform one synchronous POST.
	 *
	 * @param string $url  Destination URL.
	 * @param array  $args WordPress-compatible request arguments.
	 * @return HttpResponse
	 */
	public function post( string $url, array $args ): HttpResponse;

	/**
	 * Perform one synchronous GET.
	 *
	 * @param string $url  Destination URL.
	 * @param array  $args WordPress-compatible request arguments.
	 * @return HttpResponse
	 */
	public function get( string $url, array $args ): HttpResponse;
}
