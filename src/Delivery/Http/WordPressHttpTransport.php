<?php
/**
 * WordPress HTTP API transport adapter.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Delivery\Http;

use GravityNotify\Support\NoSendGuard;

/** Production seam around supported WordPress HTTP APIs. */
final class WordPressHttpTransport implements HttpTransportInterface {

	/**
	 * Perform one synchronous POST using WordPress core.
	 *
	 * @param string               $url  Request URL.
	 * @param array<string, mixed> $args WordPress HTTP arguments.
	 * @return HttpResponse
	 */
	public function post( string $url, array $args ): HttpResponse {
		NoSendGuard::assert_http_url_allowed( $url );
		return $this->normalize( wp_remote_post( $url, $args ) );
	}

	/**
	 * Perform one synchronous GET using WordPress core.
	 *
	 * @param string               $url  Request URL.
	 * @param array<string, mixed> $args WordPress HTTP arguments.
	 * @return HttpResponse
	 */
	public function get( string $url, array $args ): HttpResponse {
		NoSendGuard::assert_http_url_allowed( $url );
		return $this->normalize( wp_remote_get( $url, $args ) );
	}

	/**
	 * Normalize one WordPress HTTP result without retaining raw WP_Error text.
	 *
	 * @param array|\WP_Error $response WordPress response.
	 * @return HttpResponse
	 */
	private function normalize( $response ): HttpResponse {
		if ( is_wp_error( $response ) ) {
			return HttpResponse::from_transport_error( 'wordpress_http_error' );
		}
		return HttpResponse::from_http(
			(int) wp_remote_retrieve_response_code( $response ),
			wp_remote_retrieve_body( $response )
		);
	}
}
