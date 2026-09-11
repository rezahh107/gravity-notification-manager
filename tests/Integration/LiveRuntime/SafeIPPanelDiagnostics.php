<?php
/**
 * Safe IPPanel diagnostics for the explicitly authorized live PR validation.
 *
 * @package GravityNotify
 */

declare(strict_types=1);

namespace GravityNotify\Tests\Integration\LiveRuntime;

use WP_Error;

/**
 * Records only allowlisted, non-sensitive request/response evidence.
 */
final class SafeIPPanelDiagnostics {

	/** Current documented Edge send endpoint. */
	private const SEND_ENDPOINT = 'https://edge.ippanel.com/v1/api/send';

	/** Current documented sender-number listing endpoint. */
	private const NUMBERS_ENDPOINT = 'https://edge.ippanel.com/v1/api/number/numbers';

	/** Register bounded diagnostics after WordPress has booted. */
	public static function boot(): void {
		self::run_numbers_preflight();
		add_action( 'http_api_debug', array( self::class, 'observe_http' ), 10, 5 );
	}

	/**
	 * Observe only the one production send request and print safe metadata.
	 *
	 * @param mixed  $response WordPress HTTP response or WP_Error.
	 * @param string $context  Debug context.
	 * @param string $class    Transport implementation class.
	 * @param array  $args     Request arguments.
	 * @param string $url      Request URL.
	 */
	public static function observe_http( $response, string $context, string $class, array $args, string $url ): void {
		unset( $class );

		if ( 'response' !== $context || self::SEND_ENDPOINT !== $url ) {
			return;
		}

		$request = self::request_evidence( $args );
		$result  = self::response_evidence( $response );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every dynamic field is reduced to bounded, non-sensitive metadata before output.
		printf(
			'GNM_LIVE_HTTP method=POST host=edge.ippanel.com path=/v1/api/send auth_header=%s content_type=%s payload_schema=%s sending_type=%s recipient_count=%d sender_matches_config=%s recipient_matches_config=%s request_body_length=%d request_body_sha256=%s http_status=%d transport_error=%s response_shape=%s response_content_type=%s response_body_length=%d response_body_sha256=%s safe_error_code=%s gateway_identifier=%s request_or_trace_id=%s retry_after=%s' . PHP_EOL,
			$request['auth_header'],
			$request['content_type'],
			$request['payload_schema'],
			$request['sending_type'],
			$request['recipient_count'],
			$request['sender_matches_config'],
			$request['recipient_matches_config'],
			$request['body_length'],
			$request['body_sha256'],
			$result['http_status'],
			$result['transport_error'],
			$result['response_shape'],
			$result['content_type'],
			$result['body_length'],
			$result['body_sha256'],
			$result['safe_error_code'],
			$result['gateway_identifier'],
			$result['request_or_trace_id'],
			$result['retry_after']
		);
	}

	/** Perform one authenticated, non-sending account/sender reachability check. */
	private static function run_numbers_preflight(): void {
		$url      = add_query_arg(
			array(
				'page'     => 1,
				'per_page' => 10,
			),
			self::NUMBERS_ENDPOINT
		);
		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => GRAVITY_NOTIFY_LIVE_IPPANEL_API_KEY,
					'Content-Type'  => 'application/json',
				),
				'timeout' => 15,
			)
		);
		$result   = self::response_evidence( $response );
		$sender   = 'not_testable';
		$meta     = 'unknown';

		if ( ! is_wp_error( $response ) ) {
			$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $decoded ) ) {
				$meta_status = $decoded['meta']['status'] ?? null;
				if ( true === $meta_status ) {
					$meta = 'true';
				} elseif ( false === $meta_status ) {
					$meta = 'false';
				}

				if ( 200 <= $result['http_status'] && 300 > $result['http_status'] && is_array( $decoded['data'] ?? null ) ) {
					$sender = 'no_on_page_1';
					foreach ( $decoded['data'] as $record ) {
						if ( is_array( $record ) && isset( $record['number'] ) && is_string( $record['number'] ) && hash_equals( GRAVITY_NOTIFY_LIVE_SMS_FROM, $record['number'] ) ) {
							$sender = 'yes';
							break;
						}
					}
				}
			}
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every dynamic field is reduced to bounded, non-sensitive metadata before output.
		printf(
			'GNM_LIVE_PREFLIGHT endpoint=numbers http_status=%d transport_error=%s response_shape=%s response_content_type=%s response_body_length=%d response_body_sha256=%s meta_status=%s safe_error_code=%s gateway_identifier=%s request_or_trace_id=%s retry_after=%s sender_observed=%s' . PHP_EOL,
			$result['http_status'],
			$result['transport_error'],
			$result['response_shape'],
			$result['content_type'],
			$result['body_length'],
			$result['body_sha256'],
			$meta,
			$result['safe_error_code'],
			$result['gateway_identifier'],
			$result['request_or_trace_id'],
			$result['retry_after'],
			$sender
		);
	}

	/**
	 * Derive structural request evidence without logging request values.
	 *
	 * @param array $args WordPress request arguments.
	 * @return array<string, int|string>
	 */
	private static function request_evidence( array $args ): array {
		$headers       = is_array( $args['headers'] ?? null ) ? $args['headers'] : array();
		$authorization = 'no';
		$content_type  = 'missing';
		foreach ( $headers as $name => $value ) {
			$name = strtolower( (string) $name );
			if ( 'authorization' === $name && is_scalar( $value ) && '' !== (string) $value ) {
				$authorization = 'yes';
			}
			if ( 'content-type' === $name && is_scalar( $value ) ) {
				$content_type = self::safe_token( (string) $value );
			}
		}

		$body            = is_string( $args['body'] ?? null ) ? $args['body'] : '';
		$schema          = 'invalid_json';
		$sending_type    = 'unknown';
		$recipient_count = 0;
		$sender_matches  = 'no';
		$recipient_match = 'no';
		$decoded         = json_decode( $body, true );
		if ( is_array( $decoded ) ) {
			$keys = array_map( 'strval', array_keys( $decoded ) );
			sort( $keys, SORT_STRING );
			$schema       = self::safe_token( implode( ',', $keys ), 128 );
			$sending_type = isset( $decoded['sending_type'] ) && is_string( $decoded['sending_type'] ) ? self::safe_token( $decoded['sending_type'] ) : 'missing';
			$sender       = $decoded['from_number'] ?? null;
			if ( is_string( $sender ) && hash_equals( GRAVITY_NOTIFY_LIVE_SMS_FROM, $sender ) ) {
				$sender_matches = 'yes';
			}
			$recipients = $decoded['params']['recipients'] ?? null;
			if ( is_array( $recipients ) ) {
				$recipient_count = count( $recipients );
				if ( 1 === $recipient_count && is_string( $recipients[0] ?? null ) && hash_equals( GRAVITY_NOTIFY_LIVE_SMS_TO, $recipients[0] ) ) {
					$recipient_match = 'yes';
				}
			}
		}

		return array(
			'auth_header'              => $authorization,
			'content_type'             => $content_type,
			'payload_schema'           => $schema,
			'sending_type'             => $sending_type,
			'recipient_count'          => $recipient_count,
			'sender_matches_config'    => $sender_matches,
			'recipient_matches_config' => $recipient_match,
			'body_length'              => strlen( $body ),
			'body_sha256'              => hash( 'sha256', $body ),
		);
	}

	/**
	 * Derive safe response evidence without printing raw response content.
	 *
	 * @param mixed $response WordPress HTTP response or WP_Error.
	 * @return array<string, int|string>
	 */
	private static function response_evidence( $response ): array {
		if ( $response instanceof WP_Error || is_wp_error( $response ) ) {
			return array(
				'http_status'         => 0,
				'transport_error'     => 'yes',
				'response_shape'      => 'transport_error',
				'content_type'        => 'none',
				'body_length'         => 0,
				'body_sha256'         => hash( 'sha256', '' ),
				'safe_error_code'     => 'none',
				'gateway_identifier'  => 'none',
				'request_or_trace_id' => 'none',
				'retry_after'         => 'none',
			);
		}

		$body         = wp_remote_retrieve_body( $response );
		$body         = is_string( $body ) ? $body : '';
		$content_type = self::header( $response, 'content-type' );
		$server       = self::header( $response, 'server' );
		$request_id   = 'none';
		foreach ( array( 'x-request-id', 'x-correlation-id', 'cf-ray', 'x-trace-id', 'trace-id' ) as $header ) {
			$value = self::header( $response, $header );
			if ( 'none' !== $value ) {
				$request_id = $value;
				break;
			}
		}
		$retry_after = self::header( $response, 'retry-after' );
		$shape       = self::body_shape( $body );
		$error_code  = 'none';
		$decoded     = json_decode( $body, true );
		if ( is_array( $decoded ) ) {
			$code = $decoded['meta']['message_code'] ?? null;
			if ( is_string( $code ) || is_int( $code ) ) {
				$error_code = self::safe_token( (string) $code );
			}
		}

		return array(
			'http_status'         => (int) wp_remote_retrieve_response_code( $response ),
			'transport_error'     => 'no',
			'response_shape'      => $shape,
			'content_type'        => $content_type,
			'body_length'         => strlen( $body ),
			'body_sha256'         => hash( 'sha256', $body ),
			'safe_error_code'     => $error_code,
			'gateway_identifier'  => $server,
			'request_or_trace_id' => $request_id,
			'retry_after'         => $retry_after,
		);
	}

	/**
	 * Read one allowlisted response header as a bounded token.
	 *
	 * @param mixed  $response WordPress HTTP response.
	 * @param string $name     Header name.
	 * @return string
	 */
	private static function header( $response, string $name ): string {
		$value = wp_remote_retrieve_header( $response, $name );
		if ( is_array( $value ) ) {
			$value = reset( $value );
		}
		if ( ! is_scalar( $value ) || '' === (string) $value ) {
			return 'none';
		}
		return self::safe_token( (string) $value, 128 );
	}

	/**
	 * Classify response body shape without exposing the body.
	 *
	 * @param string $body Response body.
	 * @return string
	 */
	private static function body_shape( string $body ): string {
		$trimmed = ltrim( $body );
		if ( '' === $trimmed ) {
			return 'empty';
		}
		$lower = strtolower( substr( $trimmed, 0, 64 ) );
		if ( str_starts_with( $lower, '<!doctype html' ) || str_starts_with( $lower, '<html' ) ) {
			return 'html_gateway_error';
		}
		$decoded = json_decode( $body, true );
		if ( is_array( $decoded ) ) {
			return 'json';
		}
		return 'text';
	}

	/**
	 * Reduce arbitrary non-secret metadata to a bounded log-safe token.
	 *
	 * @param string $value Value.
	 * @param int    $max   Maximum length.
	 * @return string
	 */
	private static function safe_token( string $value, int $max = 96 ): string {
		$value = preg_replace( '/[^A-Za-z0-9_.:,\/-]/', '_', $value ) ?? '';
		return substr( $value, 0, $max );
	}
}
