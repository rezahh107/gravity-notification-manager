<?php
/**
 * IPPanel Provider – Edge API Compliant + Self-Diagnostic Doctor (v2.1.6)
 *
 * This class combines multiple responsibilities (provider, diagnostics, CLI, cron)
 * and should eventually be split into separate, focused classes.
 *
 * @package GFSMS\Integration
 */

declare( strict_types = 1 );

namespace GFSMS\Integration;

use GFSMS\Logging\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Class IPPanel_Provider
 *
 * Implements SMS provider interface for IPPanel.
 */
final class IPPanel_Provider implements SMS_Provider_Interface {

	/**
	 * Edge API mode.
	 *
	 * @var string
	 */
	public const MODE_EDGE = 'edge';

	/**
	 * Legacy API mode.
	 *
	 * @var string
	 */
	public const MODE_LEGACY = 'legacy';

	/**
	 * Raw authentication style (API key directly as string).
	 *
	 * @var string
	 */
	public const AUTH_RAW = 'raw';

	/**
	 * Bearer token authentication style.
	 *
	 * @var string
	 */
	public const AUTH_BEARER = 'bearer';

	/**
	 * Basic diagnostic level.
	 *
	 * @var int
	 */
	public const LEVEL_BASIC = 1;

	/**
	 * Extended diagnostic level.
	 *
	 * @var int
	 */
	public const LEVEL_EXTENDED = 2;

	/**
	 * Stress diagnostic level.
	 *
	 * @var int
	 */
	public const LEVEL_STRESS = 3;

	/**
	 * Default timeout in seconds.
	 *
	 * @var int
	 */
	private const DEFAULT_TIMEOUT = 20;

	/**
	 * Option key for storing last health report.
	 *
	 * @var string
	 */
	private const HEALTH_OPTION_KEY = 'ippanel_last_health_report';

	/**
	 * Cron hook name for daily health check.
	 *
	 * @var string
	 */
	private const CRON_HOOK = 'ippanel_daily_health_check';

	/**
	 * HTTP client instance.
	 *
	 * @var HTTP_Client_Interface
	 */
	protected HTTP_Client_Interface $http_client;

	/**
	 * API key or token.
	 *
	 * @var string
	 */
	protected string $auth_key;

	/**
	 * API mode (edge or legacy).
	 *
	 * @var string
	 */
	protected string $mode;

	/**
	 * Authentication style (raw or bearer).
	 *
	 * @var string
	 */
	protected string $auth_style;

	/**
	 * Configuration array.
	 *
	 * @var array<string,mixed>
	 */
	protected array $config;

	/**
	 * Constructor.
	 *
	 * @param HTTP_Client_Interface $http_client HTTP client.
	 * @param string                $auth_key    API key or token.
	 * @param array                 $config      Configuration overrides.
	 */
	public function __construct(
		HTTP_Client_Interface $http_client,
		string $auth_key,
		array $config = array()
	) {
		$this->http_client = $http_client;
		$this->auth_key    = trim( $auth_key );

		$mode       = $config['mode'] ?? self::MODE_EDGE;
		$this->mode = in_array( $mode, array( self::MODE_EDGE, self::MODE_LEGACY ), true )
			? $mode
			: self::MODE_EDGE;

		$auth_style       = $config['auth_style'] ?? self::AUTH_RAW;
		$this->auth_style = in_array( $auth_style, array( self::AUTH_RAW, self::AUTH_BEARER ), true )
			? $auth_style
			: self::AUTH_RAW;

		$this->config = array_merge(
			array(
				'edge_base_url'           => 'https://edge.ippanel.com/v1',
				'edge_sms_endpoint'       => '/api/send',
				'edge_pattern_endpoint'   => '/api/send',
				'credit_endpoint'         => '/api/payment/credit/mine',

				'legacy_base_url'         => 'https://api2.ippanel.com',
				'legacy_sms_endpoint'     => '/api/v1/sms/send',
				'legacy_pattern_endpoint' => '/api/v1/sms/pattern/normal/send',

				'timeout'                 => self::DEFAULT_TIMEOUT,
				'log_requests'            => false,
				'log_file'                => '',
				'default_sender'          => '',
			),
			$config
		);

		// Restrict debug log file to wp-content directory.
		$this->sanitize_log_file_path();
	}

	/**
	 * Ensure the debug log file is stored under WP_CONTENT_DIR.
	 * If an invalid path is given, logging is disabled.
	 *
	 * @return void
	 */
	private function sanitize_log_file_path(): void {
		if ( ! $this->config['log_requests'] || empty( $this->config['log_file'] ) ) {
			$this->config['log_file'] = '';
			return;
		}

		$log_file    = wp_normalize_path( $this->config['log_file'] );
		$content_dir = wp_normalize_path( WP_CONTENT_DIR );

		if ( 0 !== strpos( $log_file, $content_dir ) ) {
			$this->config['log_file'] = ''; // Invalid location – disable logging.
			return;
		}

		$dir = dirname( $log_file );
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
	}

	/**
	 * Get provider name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'ippanel';
	}

	/**
	 * Send a plain text SMS.
	 *
	 * @param string $from       Sender number.
	 * @param array  $recipients Array of recipient numbers.
	 * @param string $message    Message text.
	 * @param array  $args       Additional arguments.
	 *
	 * @return array
	 */
	public function send( string $from, array $recipients, string $message, array $args = array() ): array {
		$sender = $this->to_provider_format( $from );
		if ( ! $sender ) {
			return $this->validation_error( 'invalid_sender', __( 'Invalid sender number.', 'gfsms' ) );
		}
		$destinations = $this->normalize_recipients( $recipients );
		if ( empty( $destinations ) ) {
			return $this->validation_error( 'invalid_recipients', __( 'No valid recipients.', 'gfsms' ) );
		}
		list( $base_url, $endpoint ) = $this->resolve_sms_endpoint();
		$payload                     = $this->build_sms_payload( $sender, $destinations, $message, $args );
		return $this->perform_request(
			$base_url,
			$endpoint,
			$payload,
			array(
				'type' => 'sms',
				'mode' => $this->mode,
			)
		);
	}

	/**
	 * Send a pattern-based SMS.
	 *
	 * @param string $from         Sender number.
	 * @param array  $recipients   Array of recipient numbers.
	 * @param string $pattern_code Pattern code.
	 * @param array  $variables    Pattern variables.
	 * @param array  $args         Additional arguments.
	 *
	 * @return array
	 */
	public function send_pattern( string $from, array $recipients, string $pattern_code, array $variables = array(), array $args = array() ): array {
		$sender = $this->to_provider_format( $from );
		if ( ! $sender ) {
			return $this->validation_error( 'invalid_sender', __( 'Invalid sender number.', 'gfsms' ) );
		}
		$destinations = $this->normalize_recipients( $recipients );
		if ( empty( $destinations ) ) {
			return $this->validation_error( 'invalid_recipients', __( 'No valid recipients.', 'gfsms' ) );
		}
		if ( self::MODE_EDGE === $this->mode && 1 !== count( $destinations ) ) {
			return $this->validation_error( 'edge_pattern_single_recipient_required', __( 'Edge pattern API only supports one recipient.', 'gfsms' ) );
		}
		list( $base_url, $endpoint ) = $this->resolve_pattern_endpoint();
		$payload                     = $this->build_pattern_payload( $sender, $destinations, $pattern_code, $variables, $args );
		return $this->perform_request(
			$base_url,
			$endpoint,
			$payload,
			array(
				'type' => 'pattern',
				'mode' => $this->mode,
			)
		);
	}

	/**
	 * Classify error from API response.
	 *
	 * @param array $result API result.
	 *
	 * @return ProviderErrorStatus
	 */
	public function classify_error( array $result ): ProviderErrorStatus {
		if ( ! empty( $result['success'] ) ) {
			return ProviderErrorStatus::SUCCESS;
		}
		$status = (int) ( $result['http_status'] ?? 0 );
		$code   = strtolower( (string) ( $result['error_code'] ?? '' ) );
		$msg    = strtolower( (string) ( $result['error_message'] ?? '' ) );

		if ( 429 === $status || ( $status >= 500 && $status < 600 ) ) {
			return ProviderErrorStatus::RETRYABLE;
		}
		if ( str_contains( $code, 'timeout' ) || str_contains( $msg, 'timeout' ) || str_contains( $msg, 'curl error 28' ) ) {
			return ProviderErrorStatus::RETRYABLE;
		}
		if ( 401 === $status || 403 === $status || str_contains( $code, 'token' ) ) {
			return ProviderErrorStatus::PERMANENT;
		}
		return ProviderErrorStatus::PERMANENT;
	}

	/**
	 * Resolve SMS endpoint based on mode.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function resolve_sms_endpoint(): array {
		if ( self::MODE_LEGACY === $this->mode ) {
			return array(
				rtrim( (string) $this->config['legacy_base_url'], '/' ),
				(string) $this->config['legacy_sms_endpoint'],
			);
		}
		return array(
			rtrim( (string) $this->config['edge_base_url'], '/' ),
			(string) $this->config['edge_sms_endpoint'],
		);
	}

	/**
	 * Resolve pattern endpoint based on mode.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function resolve_pattern_endpoint(): array {
		if ( self::MODE_LEGACY === $this->mode ) {
			return array(
				rtrim( (string) $this->config['legacy_base_url'], '/' ),
				(string) $this->config['legacy_pattern_endpoint'],
			);
		}
		return array(
			rtrim( (string) $this->config['edge_base_url'], '/' ),
			(string) $this->config['edge_pattern_endpoint'],
		);
	}

	/**
	 * Build payload for plain text SMS.
	 *
	 * @param string $sender     Normalized sender.
	 * @param array  $recipients Normalized recipients.
	 * @param string $message    Message text.
	 * @param array  $args       Additional arguments.
	 *
	 * @return array<string,mixed>
	 */
	private function build_sms_payload( string $sender, array $recipients, string $message, array $args ): array {
		$send_time = $this->extract_send_time( $args );
		if ( self::MODE_EDGE === $this->mode ) {
			$payload = array(
				'sending_type' => 'webservice',
				'from_number'  => $sender,
				'message'      => $message,
				'params'       => array(
					'recipients' => $recipients,
				),
			);
			if ( null !== $send_time ) {
				$payload['send_time'] = $send_time;
			}
			return $payload;
		}
		$payload = array(
			'originator' => $sender,
			'recipients' => $recipients,
			'message'    => $message,
		);
		if ( null !== $send_time ) {
			$payload['time'] = $send_time;
		}
		return $payload;
	}

	/**
	 * Build payload for pattern SMS.
	 *
	 * @param string $sender       Normalized sender.
	 * @param array  $recipients   Normalized recipients.
	 * @param string $code         Pattern code.
	 * @param array  $variables    Pattern variables.
	 * @param array  $args         Additional arguments.
	 *
	 * @return array<string,mixed>
	 */
	private function build_pattern_payload( string $sender, array $recipients, string $code, array $variables, array $args ): array {
		$send_time = $this->extract_send_time( $args );
		if ( self::MODE_EDGE === $this->mode ) {
			$payload = array(
				'sending_type' => 'pattern',
				'from_number'  => $sender,
				'code'         => $code,
				'recipients'   => array( $recipients[0] ),
				'params'       => $variables,
			);
			if ( ! empty( $args['phonebook'] ) && is_array( $args['phonebook'] ) ) {
				$payload['phonebook'] = $args['phonebook'];
			}
			if ( null !== $send_time ) {
				$payload['send_time'] = $send_time;
			}
			return $payload;
		}
		$payload = array(
			'originator' => $sender,
			'code'       => $code,
			'recipients' => $recipients,
			'values'     => $variables,
		);
		if ( null !== $send_time ) {
			$payload['time'] = $send_time;
		}
		return $payload;
	}

	/**
	 * Normalize a datetime value to UTC formatted string.
	 *
	 * @param mixed $time DateTimeInterface, Unix timestamp, or date string.
	 *
	 * @return string|null
	 */
	private function normalize_datetime_utc( $time ): ?string {
		if ( $time instanceof \DateTimeInterface ) {
			$utc = ( clone $time )->setTimezone( new \DateTimeZone( 'UTC' ) );
			return $utc->format( 'Y-m-d H:i:s' );
		}
		if ( is_numeric( $time ) ) {
			return gmdate( 'Y-m-d H:i:s', (int) $time );
		}
		if ( is_string( $time ) && '' !== trim( $time ) ) {
			$ts = strtotime( $time );
			if ( false !== $ts ) {
				return gmdate( 'Y-m-d H:i:s', $ts );
			}
		}
		return null;
	}

	/**
	 * Extract send time from arguments.
	 *
	 * @param array $args Arguments.
	 *
	 * @return string|null
	 */
	private function extract_send_time( array $args ): ?string {
		if ( ! isset( $args['time'] ) ) {
			return null;
		}
		return $this->normalize_datetime_utc( $args['time'] );
	}

	/**
	 * Perform HTTP request to the provider.
	 *
	 * @param string $base_url Base URL.
	 * @param string $endpoint Endpoint.
	 * @param array  $payload  Request payload.
	 * @param array  $context  Context data for response.
	 *
	 * @return array<string,mixed>
	 */
	private function perform_request( string $base_url, string $endpoint, array $payload, array $context = array() ): array {
		$url  = rtrim( $base_url, '/' ) . '/' . ltrim( $endpoint, '/' );
		$body = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		if ( false === $body ) {
			return $this->format_error( false, 0, 'json_encode_failed', __( 'Failed to encode request payload.', 'gfsms' ), null, $context );
		}

		$response = $this->http_client->post(
			$url,
			array(
				'headers' => array(
					'Authorization' => $this->build_auth_header(),
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'    => $body,
				'timeout' => (int) $this->config['timeout'],
			)
		);

		// Validate response structure.
		if ( ! is_array( $response ) || ! isset( $response['success'] ) ) {
			return $this->format_error( false, 0, 'invalid_http_response', __( 'Invalid HTTP response format.', 'gfsms' ), null, $context );
		}

		if ( ! $response['success'] ) {
			$this->debug_log( 'ERROR: ' . ( $response['error_message'] ?? 'Network error' ) );
			return $this->format_error(
				false,
				0,
				$response['error_code'] ?? 'http_error',
				$response['error_message'] ?? __( 'HTTP request failed.', 'gfsms' ),
				null,
				$context
			);
		}

		$status   = (int) ( $response['status_code'] ?? 0 );
		$raw_body = (string) ( $response['body'] ?? '' );
		$data     = json_decode( $raw_body, true );

		if ( ! is_array( $data ) ) {
			$this->debug_log( sprintf( 'ERROR: Invalid JSON response (HTTP %d)', $status ) );
			return $this->format_error( false, $status, 'invalid_json_response', __( 'Invalid JSON response.', 'gfsms' ), array( 'body' => $raw_body ), $context );
		}

		if ( ! $this->is_response_successful( $status, $data ) ) {
			$this->debug_log( $this->extract_error_message( $data ) . ' (HTTP ' . $status . ')' );
			return $this->format_error(
				false,
				$status,
				$this->extract_error_code( $status, $data ),
				$this->extract_error_message( $data ),
				$data,
				$context
			);
		}

		return array_merge(
			array(
				'success'               => true,
				'http_status'           => $status,
				'provider_message_code' => (string) ( $data['meta']['message_code'] ?? '' ),
				'message_ids'           => $this->extract_message_ids( $data ),
				'error_code'            => '',
				'error_message'         => '',
				'raw'                   => $data,
			),
			$context
		);
	}

	/**
	 * Build the Authorization header value based on auth_style.
	 *
	 * @return string
	 */
	private function build_auth_header(): string {
		if ( self::AUTH_BEARER === $this->auth_style ) {
			return 'Bearer ' . $this->auth_key;
		}
		return $this->auth_key;
	}

	/**
	 * Determine whether the API response indicates success.
	 *
	 * @param int   $http_status HTTP status code.
	 * @param array $data        Decoded response data.
	 *
	 * @return bool
	 */
	private function is_response_successful( int $http_status, array $data ): bool {
		if ( $http_status < 200 || $http_status >= 300 ) {
			return false;
		}
		if ( isset( $data['meta']['status'] ) && true === $data['meta']['status'] ) {
			return true;
		}
		if ( isset( $data['status'] ) && ( true === $data['status'] || 1 === $data['status'] || 'success' === $data['status'] ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Extract an error code from the API response.
	 *
	 * @param int   $http_status HTTP status code.
	 * @param array $data        Decoded response data.
	 *
	 * @return string
	 */
	private function extract_error_code( int $http_status, array $data ): string {
		return (string) ( $data['meta']['message_code'] ?? $data['error']['code'] ?? $data['code'] ?? ( 'http_' . $http_status ) );
	}

	/**
	 * Extract a human-readable error message from the API response.
	 *
	 * @param array $data Decoded response data.
	 *
	 * @return string
	 */
	private function extract_error_message( array $data ): string {
		return (string) ( $data['meta']['message'] ?? $data['error']['message'] ?? $data['message'] ?? __( 'IPPanel request failed.', 'gfsms' ) );
	}

	/**
	 * Extract message IDs from the API response.
	 *
	 * @param array $data Decoded response data.
	 *
	 * @return array<int, string|int>
	 */
	private function extract_message_ids( array $data ): array {
		if ( isset( $data['data']['message_outbox_ids'] ) && is_array( $data['data']['message_outbox_ids'] ) ) {
			return $data['data']['message_outbox_ids'];
		}
		if ( isset( $data['data']['ids'] ) && is_array( $data['data']['ids'] ) ) {
			return $data['data']['ids'];
		}
		if ( isset( $data['data']['id'] ) ) {
			return array( $data['data']['id'] );
		}
		return array();
	}

	/**
	 * Create a validation error response.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 *
	 * @return array<string,mixed>
	 */
	private function validation_error( string $code, string $message ): array {
		return $this->format_error( false, 0, $code, $message, null );
	}

	/**
	 * Build a standardized error/success response array.
	 *
	 * @param bool                $success Success flag.
	 * @param int                 $http    HTTP status code.
	 * @param string              $code    Error code.
	 * @param string              $msg     Error message.
	 * @param array|null          $raw     Raw response data.
	 * @param array<string,mixed> $extra   Extra data.
	 *
	 * @return array<string,mixed>
	 */
	private function format_error( bool $success, int $http, string $code, string $msg, ?array $raw, array $extra = array() ): array {
		return array_merge(
			array(
				'success'               => $success,
				'http_status'           => $http,
				'provider_message_code' => $code,
				'message_ids'           => array(),
				'error_code'            => $code,
				'error_message'         => $msg,
				'raw'                   => $raw,
			),
			$extra
		);
	}

	/**
	 * Normalize a phone number to international format (+98...).
	 *
	 * @param string $number Raw input number.
	 *
	 * @return string|null
	 */
	public function normalize_number( string $number ): ?string {
		$number = trim( $number );
		if ( '' === $number ) {
			return null;
		}
		$has_plus = str_starts_with( $number, '+' );
		$digits   = preg_replace( '/\D+/', '', $number );
		if ( ! $digits ) {
			return null;
		}

		if ( str_starts_with( $digits, '0098' ) ) {
			$digits = '98' . substr( $digits, 4 );
		}

		if ( str_starts_with( $digits, '98' ) && 12 === strlen( $digits ) ) {
			$digits = $digits;
		} elseif ( str_starts_with( $digits, '09' ) && 11 === strlen( $digits ) ) {
			$digits = '98' . substr( $digits, 1 );
		} elseif ( str_starts_with( $digits, '9' ) && 10 === strlen( $digits ) ) {
			$digits = '98' . $digits;
		} else {
			// Keep digits as-is for other international formats.
		}

		// Validate final digit length (10–15 digits).
		if ( strlen( $digits ) < 10 || strlen( $digits ) > 15 ) {
			return null;
		}

		return '+' . $digits;
	}

	/**
	 * Format a number for the provider.
	 *
	 * @param string $number Raw number.
	 *
	 * @return string|null
	 */
	public function to_provider_format( string $number ): ?string {
		return $this->normalize_number( $number );
	}

	/**
	 * Normalize an array of recipient numbers, removing duplicates.
	 *
	 * @param array<int, string|int> $recipients Recipient list.
	 *
	 * @return array<int, string>
	 */
	private function normalize_recipients( array $recipients ): array {
		$normalized = array();
		foreach ( $recipients as $r ) {
			if ( ! is_string( $r ) && ! is_int( $r ) ) {
				continue;
			}
			$formatted = $this->to_provider_format( (string) $r );
			if ( null !== $formatted ) {
				$normalized[] = $formatted;
			}
		}
		return array_values( array_unique( $normalized ) );
	}

	/**
	 * Fetch approved sender numbers from IPPanel Edge API.
	 *
	 * Uses the official endpoint: GET {base_url}/api/number/numbers?page=1&per_page=100
	 *
	 * @return array{success: bool, senders?: array<int,string>, message?: string}
	 */
	public function fetch_senders(): array {
		$endpoint = '/api/number/numbers?page=1&per_page=100';
		$url      = rtrim( (string) $this->config['edge_base_url'], '/' ) . '/' . ltrim( $endpoint, '/' );

		$response = $this->http_client->request(
			'GET',
			$url,
			array(
				'timeout' => max( 15, (int) $this->config['timeout'] ),
				'headers' => array(
					'Authorization' => $this->build_auth_header(),
					'Accept'        => 'application/json',
				),
			)
		);

		if ( ! is_array( $response ) || empty( $response['success'] ) ) {
			$error = is_array( $response ) ? ( $response['error_message'] ?? __( 'Network error.', 'gfsms' ) ) : __( 'Invalid response.', 'gfsms' );
			$this->debug_log( 'Fetch senders failed: ' . $error );
			return array(
				'success' => false,
				'message' => $error,
			);
		}

		$code = (int) $response['status_code'];
		$body = json_decode( $response['body'] ?? '', true );

		if ( 200 !== $code || ! is_array( $body ) ) {
			$this->debug_log( 'Fetch senders failed: HTTP ' . $code );
			return array(
				'success' => false,
				'message' => sprintf( 'HTTP %d', $code ),
			);
		}

		// Response structure per docs: data may contain 'items' or be an array directly.
		$items = $body['data']['items'] ?? $body['data'] ?? array();

		if ( ! is_array( $items ) || empty( $items ) ) {
			$this->debug_log( 'Fetch senders: no numbers found in response' );
			return array(
				'success' => false,
				'message' => __( 'No sender numbers found.', 'gfsms' ),
			);
		}

		$normalized = array();
		foreach ( $items as $item ) {
			$num = $this->normalize_number( (string) ( $item['number'] ?? '' ) );
			if ( null !== $num ) {
				$normalized[] = $num;
			}
		}

		if ( empty( $normalized ) ) {
			return array(
				'success' => false,
				'message' => __( 'No valid senders found.', 'gfsms' ),
			);
		}

		return array(
			'success' => true,
			'senders' => $normalized,
		);
	}

	/**
	 * Run diagnostic tests against the IPPanel provider.
	 *
	 * @param int $level One of LEVEL_BASIC, LEVEL_EXTENDED, LEVEL_STRESS.
	 *
	 * @return array<string,mixed>
	 */
	public function diagnose( int $level = self::LEVEL_BASIC ): array {
		$report = array(
			'meta'         => array(
				'version'     => '2.1.6',
				'timestamp'   => current_time( 'mysql' ),
				'wp_version'  => get_bloginfo( 'version' ),
				'php_version' => PHP_VERSION,
				'mode'        => $this->mode,
				'auth_style'  => $this->auth_style,
				'base_url'    => $this->config['edge_base_url'],
			),
			'tests'        => array(),
			'health_score' => 0,
		);

		if ( $level >= self::LEVEL_BASIC ) {
			$report['tests']['connectivity']   = $this->test_connectivity();
			$report['tests']['authentication'] = $this->test_authentication();
			$report['tests']['environment']    = $this->test_environment();
		}

		if ( $level >= self::LEVEL_EXTENDED ) {
			$report['tests']['number_formatting'] = $this->test_number_formatting();
			$report['tests']['sender_number']     = $this->test_sender_number();
		}

		if ( $level >= self::LEVEL_STRESS ) {
			$report['tests']['response_time'] = $this->test_response_time();
		}

		$report['health_score'] = $this->calculate_health_score( $report['tests'] );
		update_option( self::HEALTH_OPTION_KEY, $report, false );

		if ( $this->config['log_requests'] ) {
			$this->debug_log( sprintf( 'Health Check (Level %d): Score %d/100', $level, $report['health_score'] ) );
		}

		return $report;
	}

	/**
	 * Test connectivity to IPPanel API.
	 *
	 * @return array{status: string, message: string, time?: string, fix: string}
	 */
	private function test_connectivity(): array {
		$endpoint = $this->config['credit_endpoint'] ?? '/api/payment/credit/mine';
		$url      = rtrim( (string) $this->config['edge_base_url'], '/' ) . '/' . ltrim( $endpoint, '/' );
		$start    = microtime( true );

		$response = $this->http_client->request(
			'GET',
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept' => 'application/json',
				),
			)
		);

		$time = round( microtime( true ) - $start, 2 );

		if ( ! is_array( $response ) || empty( $response['success'] ) ) {
			$error = is_array( $response ) ? ( $response['error_message'] ?? __( 'Unknown network error.', 'gfsms' ) ) : __( 'Invalid response.', 'gfsms' );
			return array(
				'status'  => 'fail',
				'message' => $error,
				'time'    => $time . 's',
				'fix'     => __( 'Network or DNS issue. Check firewall or increase timeout.', 'gfsms' ),
			);
		}

		$code      = (int) $response['status_code'];
		$reachable = $code >= 200 && $code < 500;

		return array(
			'status'  => $reachable ? 'pass' : 'fail',
			'message' => sprintf( 'HTTP %d', $code ),
			'time'    => $time . 's',
			'fix'     => $reachable ? '' : __( 'Unexpected server response. Verify the Base URL is correct.', 'gfsms' ),
		);
	}

	/**
	 * Test authentication with IPPanel API.
	 *
	 * @return array{status: string, message: string, fix: string}
	 */
	private function test_authentication(): array {
		$endpoint = $this->config['credit_endpoint'] ?? '/api/payment/credit/mine';
		$url      = rtrim( (string) $this->config['edge_base_url'], '/' ) . '/' . ltrim( $endpoint, '/' );

		$response = $this->http_client->request(
			'GET',
			$url,
			array(
				'timeout' => max( 15, (int) $this->config['timeout'] ),
				'headers' => array(
					'Authorization' => $this->build_auth_header(),
					'Accept'        => 'application/json',
				),
			)
		);

		if ( ! is_array( $response ) || empty( $response['success'] ) ) {
			$err = is_array( $response ) ? ( $response['error_message'] ?? __( 'Unknown error.', 'gfsms' ) ) : __( 'Invalid response.', 'gfsms' );
			if ( str_contains( $err, 'timed out' ) || str_contains( $err, 'cURL error 28' ) ) {
				return array(
					'status'  => 'fail',
					'message' => __( 'No response received (timeout).', 'gfsms' ),
					'fix'     => __( 'Server response is too slow. Increase timeout in settings or check network.', 'gfsms' ),
				);
			}
			return array(
				'status'  => 'fail',
				'message' => $err,
				'fix'     => __( 'Network error during authentication test.', 'gfsms' ),
			);
		}

		$code = (int) $response['status_code'];
		$body = json_decode( $response['body'] ?? '', true );

		if ( 401 === $code || 403 === $code ) {
			return array(
				'status'  => 'fail',
				/* translators: %d: HTTP status code */
				'message' => sprintf( __( 'HTTP %d – Unauthorized.', 'gfsms' ), $code ),
				'fix'     => __( 'API key is invalid or auth_style is set incorrectly (use raw or bearer).', 'gfsms' ),
			);
		}

		if ( 200 === $code && isset( $body['data']['credit'] ) ) {
			$credit = (float) $body['data']['credit'];
			return array(
				'status'  => 'pass',
				/* translators: %s: formatted credit amount */
				'message' => sprintf( __( 'Balance: %s IRR.', 'gfsms' ), number_format( $credit ) ),
				'fix'     => 0.0 === $credit ? __( 'Account balance is zero.', 'gfsms' ) : '',
			);
		}

		return array(
			'status'  => 'warning',
			'message' => __( 'Unexpected response from credit endpoint.', 'gfsms' ),
			'fix'     => __( 'Check the response structure or credit endpoint.', 'gfsms' ),
		);
	}

	/**
	 * Test PHP environment requirements.
	 *
	 * @return array{status: string, message: string, fix: string}
	 */
	private function test_environment(): array {
		$checks                    = array();
		$checks['curl']            = function_exists( 'curl_version' ) ? 'pass' : 'fail';
		$checks['openssl']         = extension_loaded( 'openssl' ) ? 'pass' : 'fail';
		$checks['allow_url_fopen'] = ini_get( 'allow_url_fopen' ) ? 'pass' : 'fail (optional)';

		$critical_fail = in_array( 'fail', array( $checks['curl'], $checks['openssl'] ), true );
		$status        = $critical_fail ? 'fail' : 'pass';

		$messages = array();
		foreach ( $checks as $k => $v ) {
			$messages[] = "$k: $v";
		}

		return array(
			'status'  => $status,
			'message' => implode( ', ', $messages ),
			'fix'     => $critical_fail ? __( 'cURL or OpenSSL is disabled. Contact your server administrator.', 'gfsms' ) : '',
		);
	}

	/**
	 * Test number formatting functionality.
	 *
	 * @return array{status: string, message: string, fix: string}
	 */
	private function test_number_formatting(): array {
		$samples = array( '09123456789', '+989123456789', '989123456789', '00989123456789', '12345' );
		$results = array();
		foreach ( $samples as $num ) {
			$normalized      = $this->normalize_number( $num );
			$results[ $num ] = $normalized ? $normalized : __( 'Invalid', 'gfsms' );
		}
		return array(
			'status'  => 'info',
			'message' => wp_json_encode( $results, JSON_UNESCAPED_UNICODE ),
			'fix'     => '',
		);
	}

	/**
	 * Test sender number validity.
	 *
	 * @return array{status: string, message: string, fix: string}
	 */
	private function test_sender_number(): array {
		$sender         = $this->config['default_sender'] ?: '+983000505';
		$fake_recipient = '+989000000000';
		$payload        = array(
			'sending_type' => 'pattern',
			'code'         => '000000',
			'from_number'  => $sender,
			'recipients'   => array( $fake_recipient ),
			'params'       => array( 'test' => '1' ),
		);

		$url  = rtrim( (string) $this->config['edge_base_url'], '/' ) . '/api/send';
		$body = wp_json_encode( $payload );

		$response = $this->http_client->post(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => $this->build_auth_header(),
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'    => $body,
			)
		);

		if ( ! is_array( $response ) || empty( $response['success'] ) ) {
			$error = is_array( $response ) ? ( $response['error_message'] ?? __( 'Network error.', 'gfsms' ) ) : __( 'Invalid response.', 'gfsms' );
			return array(
				'status'  => 'fail',
				'message' => $error,
				'fix'     => __( 'Network error during sender test.', 'gfsms' ),
			);
		}

		$code      = (int) $response['status_code'];
		$resp_body = json_decode( $response['body'] ?? '', true );
		$msg       = $resp_body['meta']['message'] ?? '';

		if ( 422 === $code && str_contains( $msg, 'sender' ) ) {
			return array(
				'status'  => 'fail',
				/* translators: %s: sender number */
				'message' => sprintf( __( "Sender number '%s' is invalid.", 'gfsms' ), $sender ),
				'fix'     => __( 'Verify the sender number in your IPPanel panel and update the plugin settings.', 'gfsms' ),
			);
		}

		return array(
			'status'  => 'pass',
			'message' => __( 'Sender number appears valid.', 'gfsms' ),
			'fix'     => '',
		);
	}

	/**
	 * Test response time of API.
	 *
	 * @return array{status: string, message: string, fix: string}
	 */
	private function test_response_time(): array {
		$url   = rtrim( (string) $this->config['edge_base_url'], '/' ) . '/';
		$start = microtime( true );
		$this->http_client->request( 'GET', $url, array( 'timeout' => $this->config['timeout'] ) );
		$time = round( microtime( true ) - $start, 2 );

		return array(
			'status'  => 'info',
			/* translators: 1: response time in seconds, 2: configured timeout */
			'message' => sprintf( __( 'Response time: %1$ss (configured timeout: %2$ss).', 'gfsms' ), $time, $this->config['timeout'] ),
			'fix'     => $time > ( (int) $this->config['timeout'] * 0.8 )
				? __( 'Timeout is close to the limit. Consider increasing it.', 'gfsms' )
				: '',
		);
	}

	/**
	 * Calculate a health score from test results.
	 *
	 * @param array<string, array{status: string}> $tests Test results.
	 *
	 * @return int
	 */
	private function calculate_health_score( array $tests ): int {
		$weights = array(
			'connectivity'   => 30,
			'authentication' => 40,
			'sender_number'  => 20,
			'environment'    => 10,
		);
		$score   = 0;
		foreach ( $weights as $test => $weight ) {
			if ( ! isset( $tests[ $test ] ) ) {
				continue;
			}
			if ( 'pass' === ( $tests[ $test ]['status'] ?? 'fail' ) ) {
				$score += $weight;
			}
		}
		return min( 100, $score );
	}

	/**
	 * Return diagnostic results as HTML.
	 *
	 * @return string
	 */
	public function diagnose_html(): string {
		$report = $this->diagnose( self::LEVEL_EXTENDED );
		$score  = $report['health_score'];
		$color  = $score >= 80 ? 'green' : ( $score >= 50 ? 'orange' : 'red' );

		$html  = '<div class="ippanel-doctor">';
		$html .= '<h3>' . esc_html__( 'IPPanel Doctor – Health Status:', 'gfsms' ) . ' <span style="color:' . esc_attr( $color ) . '">' . (int) $score . '/100</span></h3>';
		$html .= '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Test', 'gfsms' ) . '</th><th>' . esc_html__( 'Status', 'gfsms' ) . '</th><th>' . esc_html__( 'Details', 'gfsms' ) . '</th><th>' . esc_html__( 'Solution', 'gfsms' ) . '</th></tr></thead><tbody>';

		foreach ( $report['tests'] as $name => $test ) {
			$status_icon = match ( $test['status'] ) {
				'pass'    => '✅',
				'fail'    => '❌',
				'warning' => '⚠️',
				default   => 'ℹ️',
			};
			$html .= '<tr>';
			$html .= '<td>' . esc_html( $name ) . '</td>';
			$html .= '<td>' . $status_icon . ' ' . esc_html( $test['status'] ) . '</td>';
			$html .= '<td>' . esc_html( $test['message'] ?? '' ) . '</td>';
			$html .= '<td>' . esc_html( $test['fix'] ?? '' ) . '</td>';
			$html .= '<tr>';
		}
		$html .= '</tbody></table></div>';

		return $html;
	}

	/**
	 * Return diagnostic results as JSON string.
	 *
	 * @return string
	 */
	public function diagnose_json(): string {
		return wp_json_encode( $this->diagnose( self::LEVEL_STRESS ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Schedule the daily health check if not already scheduled.
	 *
	 * @return void
	 */
	public static function schedule_health_check(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Initialize the cron hook for health checks.
	 *
	 * @return void
	 */
	public static function init_cron(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'perform_health_check_cron' ) );
		self::schedule_health_check();
	}

	/**
	 * Cron callback: run health check and email admin on degradation.
	 *
	 * @return void
	 */
	public static function perform_health_check_cron(): void {
		$instance = self::get_instance();
		if ( ! $instance ) {
			return;
		}
		$report     = $instance->diagnose( self::LEVEL_BASIC );
		$previous   = get_option( self::HEALTH_OPTION_KEY, array() );
		$prev_score = (int) ( $previous['health_score'] ?? 100 );
		$new_score  = (int) $report['health_score'];

		if ( $new_score < 80 && $new_score < $prev_score ) {
			$admin_email = get_option( 'admin_email' );
			$subject     = __( '⚠ IPPanel Health Alert', 'gfsms' );
			/* translators: 1: new health score, 2: admin URL */
			$message = sprintf( __( "SMS gateway health has declined: %1\$d/100\nPlease review the status.\n%2\$s", 'gfsms' ), $new_score, admin_url( 'admin.php?page=gfsms-doctor' ) );
			wp_mail( $admin_email, $subject, $message );
		}
	}

	/**
	 * Register WP-CLI commands for diagnostics.
	 *
	 * @return void
	 */
	public static function register_cli_commands(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command(
				'gfsms ippanel-diagnose',
				function ( $args, $assoc_args ) {
					$level = self::LEVEL_BASIC;
					if ( isset( $assoc_args['level'] ) ) {
						$level = match ( $assoc_args['level'] ) {
							'extended' => self::LEVEL_EXTENDED,
							'stress'   => self::LEVEL_STRESS,
							default    => self::LEVEL_BASIC,
						};
					}
					$instance = self::get_instance();
					if ( ! $instance ) {
						\WP_CLI::error( __( 'IPPanel settings not found.', 'gfsms' ) );
						return;
					}
					$report = $instance->diagnose( $level );
					\WP_CLI::log( sprintf( 'Health Score: %d/100', $report['health_score'] ) );
					foreach ( $report['tests'] as $name => $test ) {
						\WP_CLI::log( "$name: {$test['status']} - {$test['message']}" );
					}
				}
			);
		}
	}

	/**
	 * Get a configured IPPanel_Provider instance from plugin settings.
	 *
	 * @return self|null
	 */
	private static function get_instance(): ?self {
		$options = get_option( GFSMS_SETTINGS_OPTION, array() );
		$api_key = $options['ippanel_api_key'] ?? '';
		if ( '' === $api_key ) {
			return null;
		}
		$http_client = new Wp_HTTP_Client();
		return new self( $http_client, $api_key, $options );
	}

	/**
	 * Write a message to the plugin logger or debug log file if enabled.
	 *
	 * @param string $message Log message.
	 *
	 * @return void
	 */
	private function debug_log( string $message ): void {
		if ( ! $this->config['log_requests'] ) {
			return;
		}

		// Prefer centralized logger when available.
		if ( class_exists( Logger::class ) ) {
			try {
				Logger::instance()->debug( $message );
				return;
			} catch ( \Throwable $e ) {
				// Fall through to file-based logging.
			}
		}

		if ( ! empty( $this->config['log_file'] ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[' . current_time( 'mysql' ) . '] ' . $message . "\n", 3, $this->config['log_file'] );
		}
	}
}
