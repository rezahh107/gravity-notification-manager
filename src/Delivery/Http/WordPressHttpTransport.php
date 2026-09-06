<?php
/**
 * WordPress HTTP API transport adapter.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Delivery\Http;

use GravityNotify\Support\NoSendGuard;

/**
 * Production seam around supported WordPress HTTP APIs.
 */
final class WordPressHttpTransport implements HttpTransportInterface {

	/**
	 * Perform one synchronous POST using WordPress core.
	 *
	 * @param string $url  Destination URL.
	 * @param array  $args WordPress request arguments.
	 * @return HttpResponse
	 */
	public function post( string $url, array $args ): HttpResponse {
		NoSendGuard::assert_outbound_allowed( 'http' );

		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			return HttpResponse::from_transport_error( 'wordpress_http_error' );
		}

		return HttpResponse::from_http(
			(int) wp_remote_retrieve_response_code( $response ),
			wp_remote_retrieve_body( $response )
		);
	}
}
