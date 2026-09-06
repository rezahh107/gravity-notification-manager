<?php
/**
 * Narrow outbound HTTP seam.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Delivery\Http;

/**
 * Supports deterministic injected POST transport.
 */
interface HttpTransportInterface {

	/**
	 * Perform one synchronous POST.
	 *
	 * @param string $url  Destination URL.
	 * @param array  $args WordPress-compatible request arguments.
	 * @return HttpResponse
	 */
	public function post( string $url, array $args ): HttpResponse;
}
