<?php
/**
 * HTTP client abstraction layer.
 *
 * Providers depend on this interface instead of WordPress‑specific HTTP
 * functions. The client is responsible for low‑level communication only;
 * **sanitisation, validation, and escaping** must be performed by the
 * caller (e.g. provider or Sms_Sender) before data is passed to the client
 * or after a response is received.
 *
 * @package GFSMS\Integration
 * @since   3.0.0
 */

declare( strict_types = 1 );

namespace GFSMS\Integration;

defined( 'ABSPATH' ) || exit;

/**
 * Interface HTTP_Client_Interface
 *
 * Defines methods for making HTTP requests.
 *
 * @since 3.0.0
 */
interface HTTP_Client_Interface {

	/**
	 * Send an HTTP request.
	 *
	 * @param string $method HTTP method (GET, POST, PUT, DELETE).
	 * @param string $url    Target URL.
	 * @param array  $args   Request arguments (headers, body, timeout, etc.).
	 *
	 * @return array{
	 *     success: bool,
	 *     status_code?: int,
	 *     body?: string,
	 *     headers?: array,
	 *     error_code?: string,
	 *     error_message?: string
	 * }
	 *
	 * @throws void All errors are captured and returned in the result array;
	 *               implementations must not throw exceptions.
	 */
	public function request( string $method, string $url, array $args = array() ): array;

	/**
	 * Convenience wrapper for a POST request.
	 *
	 * Returns the same structured array as {@see request()}.
	 *
	 * @param string $url  Target URL.
	 * @param array  $args Request arguments.
	 *
	 * @return array See request().
	 *
	 * @throws void Same non‑throwing contract as request().
	 */
	public function post( string $url, array $args = array() ): array;
}
