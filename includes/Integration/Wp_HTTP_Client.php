<?php
/**
 * WordPress HTTP client adapter.
 *
 * Wraps `wp_remote_*` functions so that providers remain unaware of
 * WordPress internals. The implementation follows the non‑throwing
 * contract declared in {@see HTTP_Client_Interface}.
 *
 * **Important:** This client performs **no sanitisation or validation**
 * of incoming URL or arguments; that responsibility belongs to the
 * caller (provider or sender). The client only normalises the raw
 * WordPress response into a predictable array structure.
 *
 * @package GFSMS\Integration
 * @since   3.0.0
 */

declare( strict_types = 1 );

namespace GFSMS\Integration;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Class Wp_HTTP_Client
 *
 * WordPress HTTP client adapter implementing HTTP_Client_Interface.
 *
 * @since 3.0.0
 */
final class Wp_HTTP_Client implements HTTP_Client_Interface {

	/**
	 * Default timeout in seconds.
	 *
	 * @var int
	 */
	private const DEFAULT_TIMEOUT = 20;

	/**
	 * Send an HTTP request via WordPress’s HTTP layer.
	 *
	 * @param string $method HTTP method (GET, POST, etc.).
	 * @param string $url    Target URL.
	 * @param array  $args   Request arguments.
	 *
	 * @return array{
	 *     success: bool,
	 *     status_code: int,
	 *     body: string,
	 *     headers: array,
	 *     error_code: string|null,
	 *     error_message: string|null
	 * }
	 *
	 * @throws void
	 */
	public function request( string $method, string $url, array $args = array() ): array {
		$method = strtoupper( $method );

		$defaults = array(
			'method'    => $method,
			'timeout'   => self::DEFAULT_TIMEOUT,
			'sslverify' => true,
		);

		$merged_args = array_merge( $defaults, $args );
		$response    = wp_remote_request( $url, $merged_args );

		if ( is_wp_error( $response ) ) {
			return array(
				'success'       => false,
				'status_code'   => 0,
				'body'          => '',
				'headers'       => array(),
				'error_code'    => $response->get_error_code(),
				'error_message' => $response->get_error_message(),
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$raw_headers = wp_remote_retrieve_headers( $response );
		$headers     = is_array( $raw_headers ) ? (array) $raw_headers : array();

		return array(
			'success'       => true,
			'status_code'   => $status_code,
			'body'          => $body,
			'headers'       => $headers,
			'error_code'    => null,
			'error_message' => null,
		);
	}

	/**
	 * Convenience wrapper for a POST request.
	 *
	 * @param string $url  Target URL.
	 * @param array  $args Request arguments.
	 *
	 * @return array See request() for return format.
	 *
	 * @throws void
	 */
	public function post( string $url, array $args = array() ): array {
		return $this->request( 'POST', $url, $args );
	}
}
